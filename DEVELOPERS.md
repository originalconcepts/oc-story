# OC Story — developer notes

Written for whoever picks this up next. It covers the decisions that are not
obvious from reading the code, and the places where changing something innocuous
will break something else. PLAN.md is the original design; this is what was
actually built and why.

---

## Where things live

```
oc-story.php              bootstrap, autoloader, HPOS declaration, updater
includes/
  Core/       Plugin, Install (schema), Settings, Features, Updater
  Model/      Story, StoryHooks, Placement, Products, Stats, Attribution
  Media/      VideoSourceInterface, LocalSource, SourceManager, ChunkedUpload,
              Poster, Probe
  Surfaces/   SurfaceInterface, AbstractSurface, Circles, Slider, ProductBlock,
              SurfaceManager
  Display/    Assets, Injector, Shortcode, Block, Elementor, ElementorWidget
  Rest/       Routes, StoriesController, ProductsController, UploadController,
              PlacementsController, LookupController, EventsController
  Admin/      Menu, Studio, PlacementsPage, InsightsPage, SettingsPage
assets/
  css/        surface-*.css (inlined per active surface), player.css, studio.css
  js/         bar.js (initial), player.js (chunk), attr.js (product pages),
              encoder.js + mp4.js + uploader.js (studio only), studio.js,
              placements.js, block.js
templates/    surfaces/circles.php, surfaces/slider.php (slider + product block)
languages/    oc-story.pot, he_IL
tests/        logic-test.php (plain PHP), mp4-test.mjs (node/jsc),
              budget.mjs (CI-enforced byte budget), encoder-test.html (on-device)
```

Run the tests:

```
php tests/logic-test.php
node tests/mp4-test.mjs
node tests/budget.mjs
```

`tests/encoder-test.html` is the on-device gate — open it on a real phone over
HTTP and feed it a camera-roll video. It has passed on desktop Chromium against
real files (including an iPhone HEVC .mov); it has **not yet been run on a real
phone**, and that is the single most important open item in this repository.

---

## Data model

### Stories are an invisible CPT

`oc_story`: `public => false`, `show_ui => false`. The studio and our REST
namespace are the only interfaces. What the CPT buys: post status, `menu_order`
for bar ordering, and `WP_Query` priming meta for a whole bar in one pass.

Slides live in **one JSON meta key** (`_ocs_slides`). One `get_post_meta()`
renders a story. `Story::normalize_slides()` is the only gate through which
input becomes stored data — a slide with no `ref` is dropped, duplicate ids are
reassigned, coordinates clamp to 0..1, and `javascript:` CTAs die there.

**Product names, prices and thumbnails are never stored on a slide.** They are
resolved at render time by `Model\Products` (one batched query per bar, never
one per card). A cached story showing a stale price is a consumer-law problem
before it is a bug — which is also why `woocommerce_update_product` bumps the
cache version (below) for tagged products.

### Our tables

| Table | Why it is not post meta |
|---|---|
| `ocs_slide_product` | reverse index: "which stories tag product 812" |
| `ocs_stats_daily` | pre-aggregated counters; a row per view would grow unbounded |
| `ocs_uploads` | chunked-upload sessions with their own expiry and sweeper |

Placements are **an autoloaded option**, not a table: read on every request,
written a few times a year. `Install::maybe_upgrade()` v2 re-adds it because
`update_option()` cannot flip the autoload flag of an unchanged value.

### One version stamp expires everything

`ocs_stories_version` is bumped by: story create/update/delete, reorder,
placement save, settings save, and any change to a *tagged* product. Rendered
bars and REST payloads are cached in transients keyed by it. Working out which
bars a given story appears in is the unreliable version of this; do not.

---

## The video pipeline

The browser does the transcode (`encoder.js` + `mp4.js`, studio only). This is
the decision that makes the plugin viable on shared hosting. Facts you need
before touching it:

- **An already-efficient source is copied, not re-encoded.** The first
  on-device gate run caught a 2.0MB 720p H.264 clip coming out at 2.3MB —
  re-encoding an efficient source *up* to the target bitrate makes it bigger
  and worse at once. `copyDecision()` (pure, harness-covered) routes H.264
  sources that are upright, within the size cap and at-or-below our own
  bitrate into a passthrough remux: same samples, our fast-start container,
  poster via a throwaway `<video>` element. Everything else re-encodes, and
  the encode bitrate is capped at what the source spends.
- **An iPhone records HEVC** (`hvc1` in a QuickTime `.mov`) unless its owner
  changed a setting. The demuxer reads `avc1/avc3/hvc1/hev1` and derives the
  decoder string from `avcC`/`hvcC`. Output is always H.264 **Baseline** — no
  B-frames, so presentation order is decode order and the muxer never reorders.
- **The iPhone's AudioSpecificConfig is not where MP4 says.** A v1 QuickTime
  sound entry is 44 bytes with `esds` inside a `wave` atom. Look only in the
  MP4 place and every iPhone video silently loses its sound.
- **Audio is copied through, sample for sample.** There is no AudioEncoder in
  the pipeline at all. Do not add one to "improve" anything.
- **`moov` is written before `mdat`** (two-pass, fixed-width tables). An index
  at the end forces mobile Safari to download the whole file before frame one.
- **Rotation lives in the tkhd matrix.** Ignore it and every portrait clip
  ships lying on its side.
- **Backpressure is event-driven** (`dequeue`), never polled. Timers throttle
  in background tabs; the measured cost of polling was 68.8ms per frame
  against 3ms. The codecs were never the bottleneck.
- **Chunk size obeys the server.** `Probe` reads `upload_max_filesize` /
  `post_max_size` and `/upload/init` returns a chunk size that fits, floored at
  256KB, capped at 8MB. Chunks arrive in any order; arrival is a bitmap beside
  the temp file, not a DB write per chunk; `complete` refuses a byte-count
  mismatch.

`VideoSourceInterface` is the storage seam. 0.1 ships `LocalSource` only;
Bunny/Cloudflare Stream drop in behind `ocs_video_sources` without touching
anything upstream.

---

## The storefront budget

`tests/budget.mjs` fails CI when an asset outgrows its number. Raising a number
is allowed — silently is not. The architecture the budget encodes:

- Bars are **rendered server-side into the page**. No AJAX on load, nothing
  per-visitor in the markup, so full-page caches keep working.
- Only the stylesheets of surfaces **actually on the page** are inlined. A page
  with a shortcode gets all of them (which surface it wants is unknowable when
  the head prints) — that combined worst case has its own budget line.
- `bar.js` (~2.6KB gzip) waits for a tap and imports the player. It warms the
  chunk on `pointerdown`, ~100ms before the click. The import URL is resolved
  against the page — `import()` rejects anything that is not absolute or
  explicitly relative, and that failure must stay **loud** (one CDN rewrite of
  OCS_URL otherwise turns every circle into a dead button, silently).
- The player loads the current slide, gives the next `preload="metadata"`, and
  touches nothing else. Closing empties `src` — pausing alone leaves the rest
  of the file arriving on someone's mobile data.
- Every `<video>` and `<img>` we emit carries `data-no-lazy` / `skip-lazy` /
  `data-skip-lazy`, and the bar script tag carries `data-no-optimize`
  attributes. Optimisation plugins rewrite anything less defended.

---

## Analytics

Counters, never events: one upsert per bucket into `ocs_stats_daily`. The
beacon endpoint is public and nonce-free **on purpose** — it fires from cached
pages where a printed nonce would be stale. The entire defence is
`Stats::normalize_batch()` (pure, harness-covered), a per-IP rate limit, and a
204 for everything including garbage. **Money has no client event type.**
Revenue only enters through `Attribution`, where every hop revalidates: the
product must match the item actually added, and the window applies at
add-to-cart *and* at checkout, because carts sleep for weeks. Both classic and
Store API checkouts count, exactly once (`_ocs_attr_counted`).

Reach (story_id = 0 rows) is one IntersectionObserver event per bar per
pageview — a throttled background tab counts nothing, which is correct.

---

## Extension points

### Filters

| Filter | Use |
|---|---|
| `ocs_video_sources` | add Bunny / Cloudflare Stream storage |
| `ocs_surfaces`, `ocs_surface_ids` | add a display surface |
| `ocs_placement_hooks` | offer another position in the placements UI |
| `ocs_placement_matches` | override routing |
| `ocs_story_query_args` | change which stories a surface sees |
| `ocs_inline_payload_limit` | when to fetch instead of inline (default 8KB) |
| `ocs_product_block_heading` | the "See it in action" heading |
| `ocs_attribution_window` | seconds, default 7 days from settings |
| `ocs_upload_chunk_size` | override the probed chunk size |
| `ocs_allowed_video_mimes` | accept more upload types |
| `ocs_force_assets` | enqueue front-end assets unconditionally |
| `ocs_update_repo`, `ocs_update_token` | updater source |
| `ocs_is_licensed`, `ocs_has_feature` | licence gating |

### Actions

`ocs_loaded`, `ocs_story_published`, `ocs_story_updated`, `ocs_upload_complete`,
`ocs_order_attributed`.

---

## Things that will bite you

**`Display\ElementorWidget` must never be autoloaded.** It extends an Elementor
base class; the file is `require`d inside the `elementor/widgets/register` hook
after checking the class exists. Reaching it through the autoloader on a site
without Elementor is a fatal on every request.

**Pins are image-only, everywhere.** A pin on video stays put while the frame
moves under it, which reads as a bug — George called it within a day. The
studio only offers pins on photo slides and the player only renders them
there; keep both sides of that rule together. Tapping a pin highlights the
product's card rather than navigating: the card is where buying happens.

**The cart routes are nonce-free like WooCommerce's own add-to-cart links.**
`/product/{id}` and `/cart` run from cached pages. The risk profile matches
core's `?add-to-cart=` GET, the attribution claim is revalidated server-side,
and money still only moves at checkout. wc_load_cart() is what gives a custom
REST namespace a session.

**Every asset a phone loads must carry a version.** The player chunk shipped
unversioned and George's phone ran a stale copy through two rounds of fixes —
"Unavailable" on an available product was a cached player.js, not a server
bug. cfg.player and cfg.css both carry ?v=OCS_VERSION; anything new that the
storefront fetches by URL must too.

**Anything tappable over the stage sits above z-index 2.** The navigation
zones cover the whole stage at z2; the sound toggle sat under them and every
touch turned the page instead. If it can be tapped, give it z-index 5.

**Anything injected into `the_content` runs after wpautop, or it gets
mangled.** wpautop sits at priority 10 and turns the newlines between your own
tags into `<br>`; two of them inside a circle's ring added two line boxes and
made every circle an ellipse. The injector prepends at 20, and
`AbstractSurface::template()` collapses whitespace between tags so the markup
survives any autop it might still meet.

**Never render inside an output-buffer callback.** PHP forbids `ob_start()`
within a buffer handler, and the surface templates buffer — so the auto
placement's after-the-header injection must render at `template_redirect` and
splice only the finished string in its callback. The first version rendered in
the callback and took the whole site down with a white screen. It passed every
check first, which is the second lesson: **the render cache can validate a
broken renderer.** A warm transient returned a string and skipped templating
entirely; the fatal waited for the first cache miss after a version bump.
Verifying anything cached means bumping the version first and testing cold.

**Direction: logical for layout, physical for scrolling.** The player's tap
zones use `inset-inline-*`, so the forward zone lands left in Hebrew and right
in English — which means the handler must NOT also invert, or the two cancel
and forward becomes back. The product strip is the opposite case: `scrollLeft`
is physical everywhere (and negative in RTL), so its arrows are placed with
`left`/`right` and named for the direction they actually move.

**Do not "fix" the play triangle for RTL.** A play control points right in
every language; mirrored, it reads as rewind. Same for pin coordinates: the
video frame never mirrors, so x/y are physical, left-origin, everywhere.

**The studio stylesheet has no dark-mode block, deliberately.** wp-admin stays
light whatever the OS says; honouring `prefers-color-scheme` puts dark cards on
a light page for everyone working at night.

**Typing must never trigger a full render.** The admin screens redraw by
replacing the DOM, and replacing an input mid-word throws the phone keyboard
out after the first letter — George hit exactly this on day one. Search fields
therefore paint their results list in place through a re-registered painter
closure, and only selections re-render. Any new live-typed control must follow
the same rule.

**The status pill and the publish button are separate controls.** One control
that reads "Live" and toggles when pressed looks like a label until it is too
late.

**The events endpoint answers 204 to everything.** An error body invites
retries and tells a prober what the filter rejected.

**Settings are visited twice in an install's life** — that screen is a plain
options form on purpose. The studio and placements are applications because
they are used weekly and from a phone.

---

## Deliberate non-features

- **Stories do not expire.** They are recommendations that look like stories;
  a 24-hour timer would delete the shop's best-converting content on schedule.
- **No Instagram/TikTok import.** API terms, 24-hour media URLs, and oEmbed
  iframes you cannot tag a product onto. Uploading the file is three taps.
- **No autoplaying previews in the slider.** Six cards in view would be six
  video downloads nobody asked for. If ever added: off by default, own chunk.
- **No jQuery, no React, no build step.** The budget does not survive one.
- **Uninstall never deletes videos.** Even with data deletion on, attachments
  stay — a plugin should not be able to empty a media library on its way out.

---

## Free / Pro

`Core\Features::PRO` names every gated capability; everything is unlocked
through `ocs_is_licensed` during 0.x, same as OC Reviews. Intended split:
analytics + attribution, multiple placements, slider/product surfaces, external
video sources, scheduling, A/B, captions.

---

## Roadmap

In rough order of value: **the on-device gate on a real phone** (before
anything else ships to a client); Bunny/Cloudflare Stream sources; the floating
bubble and grid surfaces; JS translations for the block editor
(`wp_set_script_translations` — PHP strings are fully translated, block
inspector strings are not yet); captions; scheduled publish/retire; A/B on
posters and titles. Also worth knowing: ES-module imports inside the studio
(`studio.js` → `encoder.js` → `mp4.js`) carry no version query, so a browser
may serve a stale cached module after an update until it revalidates — an
import map or a version-stamped loader is the clean fix if it ever bites.
