=== OC Story ===
Contributors: originalconcepts
Tags: woocommerce, video, stories, shoppable video, ugc
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
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
