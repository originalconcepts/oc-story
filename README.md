# OC Story

Shoppable video and stories for WooCommerce. Instagram-style circles, video
sliders and product-page video — with products tagged on each slide, so a shopper
who taps a recommendation lands on the product.
By [Original Concepts](https://originalconcepts.co.il).

> These are not stories that vanish after 24 hours. They look like stories
> because that is the interface people already know how to use; they stay because
> they are video recommendations, and a shop's best-converting content should not
> delete itself on a timer.

---

## Status

**0.1.0 — in development.** Milestone 1 of 8 is in place: the plugin skeleton,
schema, settings, feature map, the story post type and the placement rules
engine. See [PLAN.md](PLAN.md) for the full design and the build order.

| # | Milestone | State |
|---|---|---|
| 1 | Skeleton — bootstrap, schema, settings, post type, placements | done |
| 2 | Upload pipeline — on-device encoding, chunked upload | done, gate passed on desktop |
| 3 | Studio — the upload and tagging screen | done |
| 4 | Player and the circles bar | done |
| 5 | Placement injection — the placements screen, block, Elementor | done |
| 6 | Slider and product-page surfaces | done |
| 6.5 | Hardening — price invalidation, sized posters, settings screen, frame pins | done |
| 7 | Analytics and revenue attribution | next |
| 8 | RTL, translations, accessibility, release |  |

### The gate

Milestone 2 was the gate: if on-device encoding did not hold up, the storage
decision changed and everything downstream changed with it.

It has been run against three real files in desktop Chromium — a 1080p stock
clip, a WhatsApp video, and a portrait iPhone `.mov`. All three re-encode, keep
their audio, come out the right way up, and play back:

| source | in | out | dimensions | speed |
|---|---|---|---|---|
| 1080p landscape H.264 | 7.1 MB | 1.28 MB | 1280×720 | 10× real time |
| WhatsApp portrait H.264 | 5.97 MB | 1.66 MB | 636×848 | 10× real time |
| iPhone HEVC, rotated 90° | 9.17 MB | 0.87 MB | 720×1280 | 9× real time |

Still untested: a real phone. Desktop Chromium is not iOS Safari and is not a
mid-range Android, and both matter. Run the page below on a handset before
trusting the numbers on one.

### The front-end budget

`tests/budget.mjs` enforces PLAN.md §10 in CI. Current headroom:

| asset | size | budget |
|---|---|---|
| `surface-circles.css` (inlined) | 1,595 raw | 2,048 |
| `surface-slider.css` (inlined) | 2,238 raw | 2,560 |
| `surface-product.css` (inlined) | 272 raw | 1,024 |
| `bar.js` (initial) | 2,039 gzip | 4,096 |
| `player.js` (on first tap) | 3,636 gzip | 16,384 |
| `player.css` (on first tap) | 1,159 gzip | 5,120 |

A visitor who never taps a circle downloads the posters, two kilobytes of
JavaScript, and no video at all.

### Running the gate

`tests/encoder-test.html` is that test. Open it on a phone, pick a video from the
camera roll, and read the numbers: compression ratio, encode time against clip
length, and — the one that matters — whether the browser plays back the file our
own muxer wrote.

It has to be served over HTTP, because ES modules do not load from `file://`.
On a dev site with the plugin checked out from git:

```
https://your-dev-site.test/wp-content/plugins/oc-story/tests/encoder-test.html
```

The `tests` directory is excluded from the distributed zip, so this never ships
to a client site.

## Installing on a client site

1. Download `oc-story.zip` from the [latest release](https://github.com/originalconcepts/oc-story/releases/latest).
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Activate.
3. WooCommerce must be active.

## Automatic updates

The plugin checks GitHub releases and offers updates in **Plugins → Updates**
just like a wordpress.org plugin. Nothing else to install.

- **Public repo:** works with no configuration.
- **Private repo:** each site needs a read-only token so it can see releases and
  download the package. Add this to the site's `wp-config.php` (above
  `/* That's all, stop editing! */`):

  ```php
  define( 'OCS_UPDATE_TOKEN', 'ghp_yourtokenhere' );
  ```

  The shared `OC_UPDATE_TOKEN` constant is honoured too, so a site already
  running other Original Concepts plugins needs nothing extra.

## Releasing

```bash
bash bin/build-zip.sh
```

builds `build/oc-story.zip` locally. Tagging does it properly:

```bash
git tag v0.1.0 && git push --tags
```

The release workflow checks that the tag matches the `Version:` header, builds
the zip and publishes it as the release asset the updater looks for.

## Development

```bash
php tests/logic-test.php
```

Runs under plain PHP with no WordPress install. It covers the pieces that are
pure logic and therefore worth pinning down: slide normalisation, placement
sanitising and the placement routing rules.

CI lints every PHP file on 7.4 through 8.3 and runs that harness on each.
