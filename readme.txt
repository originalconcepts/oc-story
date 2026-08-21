=== OC Story ===
Contributors: originalconcepts
Tags: woocommerce, video, stories, shoppable video, ugc
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.9.1
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

= 0.9.1 =
* Uploading through a phone link failed with "cookie check failed". A phone
  that had ever used the studio was holding a years-old copy of one internal
  file, because a relative import does not carry the version the rest of the
  plugin does. Every module now loads its neighbours at its own version.

= 0.9.0 =
* Upload from your phone without signing in. Every gallery can make a link you
  send to yourself; open it on your phone and you can add videos to that
  gallery, tag the products in them, and — for a story gallery — add to a
  story you already have or start a new one.
* The link is safe to send yourself and useless to anyone else: it can only be
  claimed within 30 minutes of being made, the first phone to open it is the
  only one it ever works on, it stops working after a span you choose without
  being used, and everything it can do is add. It cannot delete, edit another
  gallery, or read a setting, an order or a customer.

= 0.8.0 =
* A video can be set to stay up for 24 hours instead of until you take it
  down. When its day is over it becomes a draft — nothing is deleted, and
  publishing it again gives it another 24 hours.
* A gallery aimed at every page of the shop now stays off the cart, the
  checkout and the thank-you page. It is a checkbox, on by default, because a
  shopper on those pages is paying and anything that pulls them away costs the
  sale.

= 0.7.2 =
* Pressing a product card or its Buy button inside an open video did nothing.
  Both work again.
* Going back at the start of a video restarts that video. A second press is
  the one that goes to the video before it.
* The button on a product card can be a word of your choosing or a round
  plus, in a colour you set — OC Story → Settings.

= 0.7.1 =
* Tapping anything inside an open video — a product, Buy, a reaction —
  closed the player instead of doing what it said. Fixed.

= 0.7.0 =
* One screen instead of two. The studio and the widgets screen are now a
  single list of galleries, and making one is three questions: what kind it
  is, which pages it goes on and where, and the videos in it. Nobody has to
  know that a gallery and its placement were ever separate things.
* Every choice is a picture of the page with the gallery in the spot being
  offered, rather than a list of hook names.
* After publishing, the shop checks its own work: it looks at a real page of
  the right kind and says whether the gallery is actually there. Half the
  spots on a product or category page only exist while the theme still uses
  WooCommerce's own templates, and those are marked before you choose them.
* Publishing a gallery publishes its videos.
* A new kind of gallery: a floating video in the corner of the page. It waits
  for the page to finish loading, pauses when it is off screen or the tab is
  hidden, can be dismissed, and then stays dismissed for a week. On a phone it
  sits above where a sticky add-to-cart bar lives.
* Galleries can be sent to every page of the shop, the home page, product
  pages, category pages, one specific page, or nowhere automatic at all —
  with a shortcode for placing them by hand.
* Widgets made before this release keep working exactly as they were.

= 0.6.1 =
* Tapping the video to move through a gallery no longer sparks. The spark is
  the spark button, and only that — a reaction that fires by accident says
  nothing about the video.

= 0.6.0 =
* Two reactions under the video instead of one, and they belong to the clip
  you are watching rather than to the whole gallery: the heart everyone
  already knows, and the spark beside it, each with its own count. The first
  time a player opens on a device it says what the spark is for.
* The gold burst is twice the handful it was, with a flash behind it.
* Dragging the product row with a mouse now works. The cards are links around
  images, and the browser was starting its own drag over ours.
* The story waits while the pointer is in the product row, and a long press
  anywhere on the video pauses it until you let go.
* Moving between galleries on a desktop can now be arrows, thumbnails of the
  other galleries, or nothing at all — OC Story → Settings.
* The page can show through behind the player, dimmed, instead of solid black.
* Video cards are a fifth larger, and the seconds badge is gone.

= 0.5.0 =
* Right-to-left shops: tapping the left of the video moves forward and the
  right goes back, as Hebrew reads. Left-to-right shops are unchanged.
* The tagged products can be moved through: drag the row with a mouse, or use
  the arrows that appear at its edges on hover.
* Clicking the black around the video closes it.
* Arrows beside the video, up and down, move between galleries — and the move
  turns like the face of a cube.
* A spark: tap the mark beside the video and it bursts. Sparks are counted
  in Insights.
* A story is now called a gallery throughout the admin.

= 0.4.0 =
* Video cards now play themselves, silently. One video plays at a time
  anywhere on the page: five seconds in one card, then the next, and so on
  round the row — and hovering a card hands it the spotlight at once. No play
  button, no product-count pill: the card is already moving.
* Only one video is ever downloaded, nothing loads until a card's turn comes
  round, and turns only happen while the row is on screen. A hidden tab stops
  it; reduced-motion, data-saver and 2G connections never start it. It can be
  switched off under Settings → Video cards.

= 0.3.3 =
* Circles came out as ellipses inside page content: WordPress's paragraph
  formatter was adding line breaks inside the markup. The bar is now added
  after that step, and the markup is emitted without the gaps it feeds on.

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
