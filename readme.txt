=== OC Story ===
Contributors: originalconcepts
Tags: woocommerce, video, stories, shoppable video, ugc
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.3.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shoppable video and stories for WooCommerce. Instagram-style circles, sliders and product-page video, with products tagged on every slide.

== Description ==

OC Story turns product video into a storefront people actually watch. Videos are
presented in the interface shoppers already understand — circles at the top of
the page, a swipeable slider, or a block on the product page — and every slide
can carry tagged products, so a tap goes straight to the product.

These are not stories that expire. They look like stories because that interface
needs no explanation; they stay because they are recommendations.

Built for speed. A visitor who never taps a circle downloads no video at all:
the bar is rendered server-side into the page as posters and about four kilobytes
of JavaScript, and the player itself only loads on the first tap.

= Highlights =

* Instagram-style circles with a segmented progress bar and swipe navigation
* Products tagged per slide; several products become a draggable strip
* Video slider and product-page video from the same library
* Separate desktop and mobile layout — size, captions, alignment, how many show
* Placement control: whole site, home page only, chosen pages, product pages, or
  automatically wherever a tagged product is
* A mobile studio: upload from the phone, tag products, publish
* Video is compressed on the device before upload, so a 90MB clip uploads as 7MB
* Views, completion, product taps and attributed revenue per story

= Requires =

WooCommerce 7.0 or newer.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/oc-story` or install the zip through
   Plugins → Add New → Upload Plugin.
2. Activate it.
3. Go to OC Story → Studio and add your first video.

== Changelog ==

= 0.3.2 =
* Critical fix: on pages using the automatic position's after-the-header
  injection, the first uncached load crashed the site (nested output
  buffering). The bar is now rendered before the page buffer and only spliced
  inside it.

= 0.3.1 =
* The studio speaks "video", not "story": the button is "Upload a video", and
  every label follows — a vertical product video is not a story, and the old
  word promised the wrong thing.
* The widgets screen opens as a table — one row per widget with its name,
  look, pages and content — and tapping a row opens that widget's settings,
  instead of every widget's full settings stacked open.
* "How it looks" is now chosen visually: four tiles, each a miniature of the
  surface, instead of a dropdown naming things nobody has seen.
* The desktop/phone fields speak the chosen look's language: a circles widget
  asks about circle size, a slider or wall asks about card width — and card
  width now actually is the card's width.

= 0.3.0 =
* Widgets, plural. The placements screen now speaks the language it always
  worked in: every widget is its own independent row of videos — its own
  name, look, pages, devices and content. Influencers on the home page,
  course videos on the courses page, a mobile-only story bar and a
  per-product row can all run side by side.
* Collections: give a story a collection name in the studio ("Influencers",
  "Courses") and point a widget at that collection. New stories in the
  collection appear in the widget automatically.
* New look: the video wall — the slider's cards in a wrapping grid, two
  columns on phones.

= 0.2.3 =
* Adding from a story updates the header cart count and the mini-cart drawer
  instantly. The add response now carries the same fragment payload
  WooCommerce's own AJAX add produces, and the player applies it — no page
  refresh, and no dependence on the wc-cart-fragments script being loaded.

= 0.2.2 =
* Scrolling the product cards or adding to the cart no longer pauses the
  video. The story keeps playing under the variations sheet, and waits to
  advance until the sheet closes.
* The sheet mirrors the theme's own attribute display: colour swatches where
  the product page shows swatches, buttons where it shows buttons, a dropdown
  where it shows a dropdown — with the chosen value named beside the label.
* When WooCommerce refuses an add — sold individually, not enough stock — its
  actual reason now appears, instead of a generic "unavailable".
* Guest carts survive: the session cookie is now set when a guest adds from a
  story, and the header mini-cart refreshes on the spot.

= 0.2.1 =
* The sound button works and is a real toggle: it sat underneath the tap zones,
  so touches turned the page instead of the sound. Now aligned under the close
  button, always available on video, showing the current state.
* Full-bleed media, the Instagram way: portrait fills the screen edge to edge;
  landscape keeps its shape over a blurred backdrop instead of black bars.
* One product takes the full width of the card row; several take 80% with the
  next card peeking in.
* An attribute with a single option pre-selects itself in the variations sheet
  — one colour should be one tap, not a quiz.
* The player script now carries a version, so phones stop running a stale
  cached copy after an update — the root cause behind "Unavailable" on a
  product that was available.

= 0.2.0 =
* Buying without leaving the story: the Buy button adds simple products to the
  cart on the spot, and opens a bottom sheet to pick variations — price updates
  per choice, out-of-stock combinations are disabled, and the sale is credited
  to the story.
* Product cards show the product's star rating and review count, powered by
  the shop's existing reviews.
* Photo slides: the studio now accepts images as well as video. A photo shows
  for five seconds with the same progress bar and gestures.
* Product pins now appear only on photo slides — on video the frame moves
  while a pin cannot — and tapping a pin highlights that product's card.
* The automatic placement injects the bar right below the site header on pages
  with no content anchors, instead of above the header.

= 0.1.9 =
* The product card over the video got the shoppable-video look: bigger
  thumbnail, product name, prominent price and a Buy button.
* With more than one product, the next card peeks into the frame so shoppers
  can see the row scrolls, and cards snap into place.

= 0.1.8 =
* The automatic position now covers a blog home with no posts (the bar sits at
  the top of the page) and carries a last-resort anchor before the footer for
  templates it cannot classify.

= 0.1.7 =
* New placements default to "where the page content starts" — a position that
  exists on every kind of page. The old default was a WooCommerce anchor that
  simply is not there on a home page or a regular page.
* Publishing, reordering or deleting a story now clears the site's page cache
  automatically (WP Rocket, LiteSpeed, W3TC, Super Cache, Fastest Cache,
  SiteGround, Cache Enabler, Breeze, Hummingbird, WP-Optimize), so the
  storefront shows the change immediately instead of the cached page from
  before.

= 0.1.6 =
* Closing the player left an invisible layer over the whole page that
  swallowed every tap — the story could not be reopened and the page under it
  was dead until reload. Closed now means gone.

= 0.1.5 =
* The publish button now saves and publishes in one tap. It used to only mark
  the story for publishing and wait for a separate save.

= 0.1.4 =
* Product cards now show the price as a clean number. Sale products were
  showing the full price markup — old price, new price and the screen-reader
  sentences — as raw text.

= 0.1.3 =
* Fixed the product search losing keyboard focus after the first letter, in
  the studio and on the placements screen.

= 0.1.2 =
* Fixed a fatal error when saving the poster image during the first upload
  from a phone.

= 0.1.1 =
* A video that is already compressed is no longer re-encoded. The first
  on-device test caught a 2.0MB clip growing to 2.3MB; an efficient H.264
  source is now copied into our fast-start container untouched, which is
  quicker and keeps its original quality.
* When re-encoding is needed, the target bitrate never exceeds what the
  source itself spends (with headroom for HEVC conversion), so output can
  no longer be larger than input.

= 0.1.0 =
* First release: story circles, video slider and product-page videos.
* Videos are compressed on the shopper's own device before upload, so the
  plugin works on ordinary shared hosting with no ffmpeg and no external
  services.
* Products tagged per slide, with optional pins on the frame; several products
  become a swipeable strip over the video.
* Placement control per surface: whole site, home page, chosen pages, product
  pages or categories — desktop and phone configured separately.
* Insights: opens, completion, product taps, carts, orders and attributed
  revenue per story.
* Full RTL and Hebrew translation.
