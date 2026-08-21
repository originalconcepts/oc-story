#!/usr/bin/env python3
"""Write the .min build of every asset the storefront downloads.

The plugin ships what a shop's visitors actually fetch, and it ships it
minified — the comments in these files are for whoever maintains them, not
for a phone on mobile data. Run this after touching any of the sources:

    python3 scripts/minify.py

CI runs it too and fails if the result differs from what is committed, so a
forgotten run is caught before it can ship a stale build rather than being
guarded against at runtime. That matters here because this plugin is
deployed by `git pull`, and git does not preserve modification times: a
"which file is newer" check would be a coin toss on every deploy.
"""
import pathlib
import sys

try:
    import rcssmin
    import rjsmin
except ImportError:  # pragma: no cover
    sys.exit( "pip install rjsmin rcssmin" )

ROOT = pathlib.Path( __file__ ).resolve().parent.parent / "assets"

# Everything a shopper's browser fetches. The admin's own screens are not
# here: they are loaded by one person a few times a week, over a connection
# that is not the shop's problem, and their comments are worth more there.
TARGETS = (
    "js/bar.js",
    "js/attr.js",
    "js/preview.js",
    "js/float.js",
    "js/player.js",
    "css/player.css",
    "css/surface-circles.css",
    "css/surface-slider.css",
    "css/surface-grid.css",
    "css/surface-product.css",
    "css/surface-floating.css",
)

total_before = 0
total_after = 0

for relative in TARGETS:
    src = ROOT / relative
    out = src.with_suffix( ".min" + src.suffix )

    text = src.read_text( encoding="utf-8" )
    small = rjsmin.jsmin( text ) if src.suffix == ".js" else rcssmin.cssmin( text )

    out.write_text( small, encoding="utf-8" )

    total_before += len( text )
    total_after += len( small )

    print( f"{relative:32} {len(text):>7} -> {len(small):>7}" )

saved = 100 - round( total_after / total_before * 100 )
print( f"\n{'total':32} {total_before:>7} -> {total_after:>7}  ({saved}% smaller)" )
