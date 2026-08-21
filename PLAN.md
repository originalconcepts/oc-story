# OC Story — implementation plan

Shoppable video and stories for WooCommerce. Written before any code exists, so
that the decisions that are expensive to reverse are made on paper first.

Conventions follow OC Reviews: `OCS\` namespace, the same bootstrap and
autoloader shape, `Install` owning every table through `dbDelta`, extension
points as interfaces behind a filter, GitHub-release auto-updates.

---

## 1. What this is

One content model, one player, several display surfaces.

```
oc_story  →  Player  →  Circles bar
(video +      (progress    Slider / carousel
 tagged        segments,   Product page block
 products)     navigation) Floating bubble        (v2)
                           Grid wall              (v2)
```

The store owner uploads. There is no approval queue, no second role, no
influencer login. Influencer UGC arrives as a file over WhatsApp and is uploaded
like any other video.

**A story is a circle.** It holds one or more slides. Each slide is one video and
its tagged products. More than one product on a slide renders a draggable strip
of product cards over the video — it does **not** split into extra slides.
Splitting would cut the video mid-recommendation, which is the whole point of it.

---

## 2. File layout

```
oc-story.php               bootstrap, autoloader, HPOS declaration, updater
includes/
  Core/       Plugin, Install (schema), Settings, Features, Updater
  Model/      Story, Slide, Placement, Stats, Attribution
  Media/      VideoSourceInterface, LocalSource, ChunkedUpload, Poster, Probe
  Surfaces/   SurfaceInterface, AbstractSurface, Circles, Slider, ProductBlock,
              SurfaceManager
  Display/    Assets, Injector, Shortcode, Block, Elementor
  Rest/       Routes, StoriesController, UploadController, ProductsController,
              PlacementsController, EventsController, StatsController
  Admin/      Menu, Studio, PlacementsPage, SettingsPage, Dashboard
assets/
  css/        bar.css (inlined), player.css, studio.css
  js/         bar.js (initial), player.js (dynamic chunk), studio.js, encoder.js
templates/    surfaces/circles.php, surfaces/slider.php, surfaces/product.php
languages/    he_IL
tests/        logic-test.php — plain PHP, no WordPress, same harness as OC Reviews
```

Templates are theme-overridable via `oc-story/` in the theme, resolved by
`Surfaces\AbstractSurface::locate_template()`.

---

## 3. Data model

### Stories are a custom post type

`oc_story`, registered with `public => false`, `publicly_queryable => false`,
`show_ui => false`, `show_in_rest => false`. We render our own studio and our own
REST namespace; the WordPress list table would be actively worse than what we
build, and a public single view for a story has no meaning.

What we get for free anyway: post status (`publish` / `draft`), `menu_order` for
the ordering of circles in the bar, revisions off, and `WP_Query` priming meta in
one pass when the bar renders.

### Slides live in one meta key

`_ocs_slides` holds a JSON array. One `get_post_meta()` per story, no
`meta_query`, no join.

```jsonc
[
  {
    "id": "s_9f2a",              // stable, referenced by stats rows
    "source": "local",           // VideoSourceInterface id
    "ref": "3412",               // attachment id for local, external id otherwise
    "poster": 3413,              // attachment id
    "w": 720, "h": 1280,
    "duration": 14.4,            // seconds, used for the progress segment
    "products": [
      { "id": 812, "x": 0.42, "y": 0.68 },   // x/y are 0–1, null when untagged
      { "id": 977, "x": null, "y": null }
    ],
    "cta": { "text": "", "url": "" }         // optional, overrides product link
  }
]
```

Everything the player needs is in this blob. Product **names, prices and
thumbnails are resolved server-side at render time** and never stored here —
prices change, and a cached story must not show a stale price.

### Tables

| Table | Why it is not post meta |
|---|---|
| `ocs_slide_product` | the reverse index: "which stories mention product 812" |
| `ocs_stats_daily` | pre-aggregated; a raw event row per view would grow unbounded |
| `ocs_uploads` | chunked upload sessions with their own expiry and cleanup |

Placements are **not** a table. There are rarely more than a dozen and they are
needed on every page load, so they live in one non-autoloaded option
(`ocs_placements`) read once and cached in an object-cache group.

```sql
CREATE TABLE {prefix}ocs_slide_product (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  story_id    BIGINT UNSIGNED NOT NULL,
  slide_id    VARCHAR(16)     NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  sort        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY product_story (product_id, story_id),
  KEY story (story_id)
) {charset};

CREATE TABLE {prefix}ocs_stats_daily (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  day           DATE            NOT NULL,
  story_id      BIGINT UNSIGNED NOT NULL,
  slide_id      VARCHAR(16)     NOT NULL DEFAULT '',
  surface       VARCHAR(20)     NOT NULL DEFAULT '',
  device        VARCHAR(10)     NOT NULL DEFAULT '',
  impressions   INT UNSIGNED    NOT NULL DEFAULT 0,
  opens         INT UNSIGNED    NOT NULL DEFAULT 0,
  completions   INT UNSIGNED    NOT NULL DEFAULT 0,
  product_taps  INT UNSIGNED    NOT NULL DEFAULT 0,
  add_to_cart   INT UNSIGNED    NOT NULL DEFAULT 0,
  orders        INT UNSIGNED    NOT NULL DEFAULT 0,
  revenue       DECIMAL(18,4)   NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY bucket (day, story_id, slide_id, surface, device),
  KEY day_story (day, story_id)
) {charset};

CREATE TABLE {prefix}ocs_uploads (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session       CHAR(32)        NOT NULL,
  user_id       BIGINT UNSIGNED NOT NULL,
  filename      VARCHAR(255)    NOT NULL,
  mime          VARCHAR(80)     NOT NULL,
  size          BIGINT UNSIGNED NOT NULL,
  chunk_size    INT UNSIGNED    NOT NULL,
  chunks_total  INT UNSIGNED    NOT NULL,
  chunks_done   INT UNSIGNED    NOT NULL DEFAULT 0,
  tmp_path      VARCHAR(255)    NOT NULL,
  created_at    DATETIME        NOT NULL,
  expires_at    DATETIME        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY session (session),
  KEY expires (expires_at)
) {charset};
```

Rollup is written on the fly with `INSERT … ON DUPLICATE KEY UPDATE` against the
unique `bucket` key. There is no cron to fall behind and no raw table to prune.

### Revenue attribution

On a product tap the player writes `sessionStorage.ocs_attr` =
`{story, slide, product, ts}`. On `woocommerce_add_to_cart` the value rides along
in cart item data; on `woocommerce_checkout_order_processed` it is written to
order meta `_ocs_attr` and the line total is added to `ocs_stats_daily.revenue`.

Attribution window defaults to 7 days, filterable via `ocs_attribution_window`.
Nothing is written to a cookie, so full-page caching is untouched.

---

## 4. Video pipeline

### Client-side encode, then chunked upload

The browser does the transcode. This is the decision that makes the whole thing
viable on shared hosting, and it is the piece to build first because it is the
only one that can fail in a way we cannot work around.

`assets/js/encoder.js`, loaded only inside the studio:

1. `VideoDecoder` + `VideoEncoder` (WebCodecs) decode the source and re-encode to
   H.264 Baseline `avc1.42E01F` — no B-frames, so presentation order is decode
   order and the muxer never reorders. The longest edge is capped at 1280 and
   ~1.5 Mbps.
   Sources are H.264 **or HEVC**: an iPhone records `hvc1` unless its owner has
   gone looking for the setting, so HEVC input is the common case, not an exotic
   one. Output is always H.264, which plays everywhere.
2. An MP4 muxer writes the output with the **moov atom first** (`faststart`).
   Without it iOS Safari downloads the entire file before showing frame one, and
   `preload="none"` stops helping.
3. The audio track is **copied through, sample for sample** — phone audio is
   already AAC at a bitrate we would not improve on. No `AudioEncoder` exists in
   this pipeline at all.
4. First decoded frame goes to a canvas → WebP → the poster.
5. Result is uploaded in chunks.

Measured on desktop Chromium against three real files (see §11): 8 seconds of
1080p re-encodes in 0.8s — about 10× real time. A 9.2 MB portrait iPhone HEVC
clip came out at 0.87 MB, 720×1280, right way up, with its audio.

**Fallback chain** when `VideoEncoder` is unavailable (~5% of traffic):
server-side `ffmpeg` if `Media\Probe::has_ffmpeg()` finds it (own servers do),
otherwise upload as-is with a hard size ceiling and a plain-language warning.

### Chunked upload

Chunk size is `min( 2 MB, upload_max_filesize × 0.8, post_max_size × 0.8 )`,
computed server-side and returned by `/upload/init`. This sidesteps
`upload_max_filesize` entirely — the common 8 MB shared-host limit never sees a
request larger than 2 MB — and survives a dropped mobile connection, because a
failed chunk is retried alone.

Chunks append to a temp file outside the uploads directory. `/upload/complete`
verifies the byte count, runs the file through `wp_check_filetype_and_ext()`,
inserts the attachment, attaches the poster, and links the two with
`_ocs_poster_for`. Sessions older than 6 hours are swept by a daily cron.

### Storage abstraction

```php
interface VideoSourceInterface {
    public function get_id();
    public function get_label();
    public function is_configured();
    public function ingest( $file, array $meta );   // → ref
    public function get_playback_url( $ref );
    public function get_poster_url( $ref );
    public function delete( $ref );
}
```

Registered through `add_filter( 'ocs_video_sources', … )`, exactly as OC Reviews
registers channels and reward providers. **v1 ships `LocalSource` only.**
`BunnySource` and `CloudflareStreamSource` are v2 and become a drop-in because
the interface exists from day one.

---

## 5. The studio

One screen (`admin.php?page=oc-story`), rendered full-bleed with the WordPress
chrome suppressed on narrow viewports. Same code desktop and mobile — a grid of
cards above 900 px, a single column below. Vanilla JS, no build step, no React.

- **List** — cards showing poster, title, slide count, 7-day views. Drag to
  reorder (writes `menu_order`). Tap to edit.
- **New story** — pick or shoot a video, watch the encode progress, add products
  by typing two characters into a search that hits `/admin/products`, optionally
  drag a tag onto the frame, set the circle title, choose where it shows, publish.
- **Edit** — replace products, reorder slides, add a slide, hide without deleting.

Product search returns `{ id, name, price_html, thumb, url }` from a
`wc_get_products()` call limited to 20 and debounced at 250 ms.

The mobile experience is a stepper over the same three panels. Adding the page to
the home screen gives a standalone launcher; a web app manifest is served for
that route only.

---

## 6. The player and the surfaces

```php
interface SurfaceInterface {
    public function get_id();
    public function get_label();
    public function supports( $context );          // 'home', 'product', 'any'
    public function default_config();
    public function render( array $stories, array $config );
}
```

`v1`: `Circles`, `Slider`, `ProductBlock`. `v2`: `Bubble`, `Grid`. Registered via
`ocs_surfaces`.

Every surface emits the same contract: a container carrying
`data-ocs-open="{story_id}"` and one `<script type="application/json">` payload
per placement. **`bar.js` does nothing but listen for a tap on that attribute**
and `import()` the player chunk. The player is never on the critical path.

Player behaviour: segmented progress bar across the top, one segment per slide,
filling in real time from `timeupdate`; tap right/left or swipe to move; a filled
segment advances automatically; the last slide closes or moves to the next story;
`playsinline` and `muted` on first frame, unmuted by the opening tap (which is
the user gesture iOS requires); the product strip sits above the safe-area inset.

Preloading is strictly bounded: the current slide loads fully, the next gets
`preload="metadata"`, everything else nothing.

---

## 7. Placements

A placement is a row in the `ocs_placements` option:

```jsonc
{
  "id": "pl_1",
  "surface": "circles",
  "where": {
    "scope": "home",                      // site | home | pages | products | terms | tagged
    "ids": [],                            // page or term ids when scope needs them
    "exclude": []
  },
  "hook": "woocommerce_before_main_content",  // or "manual" for shortcode/block
  "priority": 15,
  "stories": { "mode": "all", "ids": [] },    // all | selected | tagged-to-this-product
  "desktop": { "show": true,  "size": 84, "labels": true, "align": "start", "max": 12 },
  "mobile":  { "show": true,  "size": 64, "labels": true, "align": "start", "max": 20 }
}
```

`Display\Injector` reads the option once on `wp`, evaluates `where` against the
current query, and adds exactly the matching hooks. Nothing is registered for a
placement that does not apply to this request.

Desktop and mobile are separate objects, not a single config with breakpoint
overrides, because that is how the setting is actually thought about — "smaller
circles on the phone, no captions" — and because the rendered HTML carries both
and lets CSS pick, so the same markup stays cacheable for every visitor.

Manual placement: `[oc_story]` shortcode, an `oc-story/stories` block, and an
Elementor widget that wraps the same renderer.

---

## 8. REST API — `oc-story/v1`

Public, no nonce, safe under full-page cache:

| Route | Notes |
|---|---|
| `POST /events` | batched from `sendBeacon`; rate-limited per IP; writes the rollup |
| `GET /stories` | fallback and block-editor preview only; the front end normally gets its data inlined |

Admin, `manage_woocommerce` + nonce:

| Route | Notes |
|---|---|
| `GET/POST/PATCH/DELETE /admin/stories[/{id}]` | |
| `POST /admin/stories/reorder` | writes `menu_order` in one query |
| `POST /admin/upload/init` | → `{ session, chunk_size, chunks_total }` |
| `POST /admin/upload/chunk` | raw body, `session` + `index` in the query string |
| `POST /admin/upload/complete` | → `{ attachment_id, poster_id, url, w, h, duration }` |
| `GET /admin/products?search=` | autocomplete, 20 results |
| `GET/PUT /admin/placements` | |
| `GET /admin/stats?range=30d` | dashboard series |

---

## 9. Extension points

**Filters** — `ocs_video_sources`, `ocs_surfaces`, `ocs_placement_matches`,
`ocs_story_query_args`, `ocs_slide_products`, `ocs_player_config`,
`ocs_video_target_height`, `ocs_video_bitrate`, `ocs_upload_chunk_size`,
`ocs_attribution_window`, `ocs_bar_html`, `ocs_is_licensed`, `ocs_has_feature`.

**Actions** — `ocs_loaded`, `ocs_story_published`, `ocs_story_updated`,
`ocs_slide_added`, `ocs_upload_complete`, `ocs_product_tapped`,
`ocs_order_attributed`.

---

## 10. Performance budget

Hard numbers. A build that misses one of these is not shippable.

Every number below measures the **built** file — the `.min` one a shop
actually downloads, written by `scripts/minify.py` and proved current by CI.
They were tightened when minification landed: a budget still carrying the old
headroom would be a budget that had stopped asking anything.

| Asset | Budget | How |
|---|---|---|
| Critical CSS | **≤ 2.0 KB** per surface, inlined | only the surfaces actually on the page |
| `bar.js` | **≤ 2.0 KB** gzip, `defer` | tap listener and `import()` only |
| `player.js` | **≤ 12 KB** gzip | dynamic chunk, first tap only |
| `player.css` | **≤ 4 KB** gzip | ships with the chunk |
| Circle poster | **≤ 20 KB**, WebP 320×320 q78 | `decoding=async`, `fetchpriority=low` |
| Slide poster | **≤ 45 KB**, WebP 540×960 | loaded with the player, not the page |
| Video per slide | **≤ 10 MB** for 30 s, 720×1280, faststart | client encode |
| Slide length | 60 s ceiling, 15 s recommended | studio enforces |

| Page metric | Budget |
|---|---|
| Bytes added before first interaction | **≤ 2 KB JS + posters**, no video |
| Blocking requests added | **0** |
| Contribution to LCP | **0 ms** — nothing render-blocking |
| CLS | **0** — `aspect-ratio` and explicit dimensions on every circle |
| DB queries per page for the bar | **≤ 1**, and **0** on object-cache hit |
| Time from tap to first painted frame | **< 200 ms** — poster paints instantly |

Rendering is server-side PHP into the page body. No AJAX on load, no
personalisation, nothing that defeats WP Rocket, LiteSpeed or Cloudflare APO.
A visitor who never taps a circle downloads not one byte of video.

---

## 11. Things that will bite you

**An iPhone records HEVC, not H.264.** `hvc1` in a QuickTime-branded `.mov` is
the default. A demuxer that only knows `avc1` rejects the single most common file
a shop owner will ever hand it.

**The AudioSpecificConfig is not where MP4 says it is.** An MP4 puts `esds`
straight after a 28-byte `mp4a` entry. An iPhone writes a version-1 QuickTime
entry, 44 bytes, with `esds` buried inside a `wave` atom. Looking in only the
first place loses the sound on every iPhone video, silently.

**Cap the longest edge, not the height.** Capping height leaves a 1920×1080 clip
completely untouched — 1080 is already under a 1280 target — and ships a full-size
landscape video through a pipeline built for phones.

**Never poll for codec backpressure.** Waiting on the queue with
`setTimeout` looks equivalent to waiting on the `dequeue` event and is not: timers
are throttled hard in a background tab, and the studio is exactly the screen
someone starts and switches away from. Measured cost of getting this wrong: 68.8 ms
per frame instead of 3 ms. The codecs were never the bottleneck; the waiting was.

**Autoplaying previews — the rule this reversed, and how.** This said no: six
cards in view is six video downloads before anyone asked for one. Shipped in
0.4.0 anyway, because a still grid of posters gets ignored and the whole point
of the surface is to catch an eye. What made it affordable is that the
objection was to *six*, not to *one*: a single video element exists on the
page, moves from card to card on a five-second turn, and only takes turns while
the row is on screen. Hovering hands it over immediately. Reduced motion, save
data and 2G opt out entirely; a hidden tab parks it. The logic is its own
chunk, loaded only where a card surface previews, and the setting can switch
the whole thing off.

**faststart is not optional.** An MP4 with the moov atom at the end forces mobile
Safari to fetch the whole file before the first frame. It looks fine on desktop
Chrome and is broken on the device that matters.

**Autoplay rules.** `muted` + `playsinline` for the poster-level preview; the
opening tap is the gesture that permits sound. Losing that gesture — by awaiting
anything before `play()` — silently breaks audio on iOS with no error.

**Lazy-load plugins rewrite `<video>`.** WP Rocket, Perfmatters and several
themes will swap `src` for `data-src` and break playback. Every `<video>` and
`<img>` we emit carries `data-no-lazy="1" class="skip-lazy" data-skip-lazy`.

**Inline JSON must be `<script type="application/json">`.** Minifiers leave that
type alone and mangle everything else.

**Never render the bar over AJAX.** It is above the fold on the home page, so it
would be the LCP element arriving one round trip late, and it would defeat page
caching for no gain.

**Video attachments have no thumbnails.** WordPress generates none, so the poster
is our own attachment linked by `_ocs_poster_for`. Deleting a story must delete
both or the media library fills with orphans.

**The events endpoint cannot require a nonce.** It is called from a fully cached
page where a printed nonce would be stale. Rate limiting per IP plus a payload
schema is the defence; the endpoint can only increment counters.

**`menu_order` on a CPT is not indexed by default** for the query we run. The bar
query is `post_type=oc_story, post_status=publish, orderby=menu_order,
posts_per_page=20` — cheap, but cache the rendered HTML anyway, keyed by
placement id and context.

---

## 12. Deliberate non-features

**No Instagram or TikTok import.** The Graph API needs a Business account and
Meta app review; Stories expire in 24 hours and media URLs expire with them; and
oEmbed returns an iframe we cannot overlay a product tag onto, which misses the
entire point. Scraping breaches the terms of both platforms. Uploading the file
directly takes three taps and works for every platform at once.

**Stories do not expire.** These are video recommendations that happen to look
like stories. An expiry timer would delete the shop's best-converting content on
a schedule.

**No server-side transcoding by default.** Shared hosts have no `ffmpeg`, and a
plugin that silently pegs a CPU for four minutes per upload gets uninstalled.
It is used only when detected, and only as a fallback.

**No jQuery, no React, no framework on the front end.** The budget in §10 does
not survive one.

**Uninstall does not delete videos.** Deactivating a plugin should not destroy a
business's content library.

---

## 13. Free / Pro

`Core\Features::PRO` lists the gated capabilities; `Features::has()` returns true
for everything through the `ocs_is_licensed` filter during 0.x, same as
OC Reviews. Intended split: analytics and revenue attribution, more than one
placement, the slider and product-page surfaces, and external video sources.

---

## 14. Build order

Sequenced so the riskiest thing is proven first.

| # | Milestone | Contents |
|---|---|---|
| 1 | Skeleton | bootstrap, autoloader, `Install` + schema, `Settings`, CPT, `Updater`, `bin/build-zip.sh`, CI and release workflows |
| 2 | Upload pipeline | `encoder.js`, `ChunkedUpload`, `Poster`, `Probe`, upload routes — **built and proven on a real phone before anything else** |
| 3 | Studio | list, editor, product search, slide management, publish |
| 4 | Player + circles | `bar.js`, `player.js`, `Circles` surface, progress segments, product strip |
| 5 | Placements | rules engine, `Injector`, shortcode, block, Elementor, desktop/mobile config |
| 6 | Surfaces | `Slider`, `ProductBlock` |
| 7 | Analytics | events endpoint, rollup, attribution, dashboard |
| 8 | Release | RTL pass, `he_IL`, accessibility, `tests/logic-test.php`, 0.1.0 tag |

Milestone 2 is the gate. If client-side encoding does not hold up on a real
mid-range Android, the storage decision in §4 changes and everything downstream
changes with it — so it gets built and tested on hardware before milestone 3
starts.
