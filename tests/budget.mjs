/**
 * The front-end performance budget, enforced.
 *
 * PLAN.md §10 sets numbers for what the storefront is allowed to download. A
 * budget nobody checks is a paragraph, so this fails the build the moment one
 * is exceeded. Raising a number here is a decision, which is the point.
 *
 *   node tests/budget.mjs
 */

import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );

const BUDGET = [
	// Inlined into the page, so what counts is the raw size added to the HTML.
	// Over the wire it is compressed with everything else. Only the surfaces
	// actually on a page are inlined, so these are budgeted separately rather
	// than as one lump nobody pays in full.
	{ file: 'assets/css/surface-circles.css', raw: 2048, why: 'circles, inlined' },
	{ file: 'assets/css/surface-slider.css', raw: 2560, why: 'slider, inlined' },
	{ file: 'assets/css/surface-product.css', raw: 1024, why: 'product block, inlined' },

	// The only script the storefront loads before an interaction.
	{ file: 'assets/js/bar.js', gzip: 4096, why: 'initial script' },

	// Product pages only, and only when attribution is on.
	{ file: 'assets/js/attr.js', gzip: 1024, why: 'attribution helper' },

	// Imported on the first tap, never before.
	{ file: 'assets/js/player.js', gzip: 16384, why: 'player chunk' },
	{ file: 'assets/css/player.css', gzip: 5120, why: 'player stylesheet' },
];

// A page carrying a shortcode gets every surface, because which one it will
// ask for is not knowable when the head is printed. That worst case has to
// stay small too.
const WORST_CASE = { files: [
	'assets/css/surface-circles.css',
	'assets/css/surface-slider.css',
	'assets/css/surface-product.css',
], raw: 6144, why: 'every surface at once (a page with a shortcode)' };

let failed = 0;

for ( const item of BUDGET ) {
	const bytes = readFileSync( join( root, item.file ) );
	const size = item.gzip ? gzipSync( bytes, { level: 9 } ).length : bytes.length;
	const limit = item.gzip || item.raw;
	const unit = item.gzip ? 'gzip' : 'raw ';
	const ok = size <= limit;

	if ( ! ok ) {
		failed++;
	}

	const share = Math.round( ( size / limit ) * 100 );
	console.log(
		`  ${ ok ? 'ok  ' : 'OVER' }  ${ item.file.padEnd( 26 ) } ${ unit } ${ String( size ).padStart( 6 ) } / ${ limit }  (${ share }%)  — ${ item.why }`
	);
}

const worst = WORST_CASE.files.reduce( ( total, file ) => total + readFileSync( join( root, file ) ).length, 0 );
const worstOk = worst <= WORST_CASE.raw;

if ( ! worstOk ) {
	failed++;
}

console.log(
	`  ${ worstOk ? 'ok  ' : 'OVER' }  ${ 'combined'.padEnd( 26 ) } raw  ${ String( worst ).padStart( 6 ) } / ${ WORST_CASE.raw }  (${ Math.round( ( worst / WORST_CASE.raw ) * 100 ) }%)  — ${ WORST_CASE.why }`
);

if ( failed ) {
	console.log( `\nFAILED: ${ failed } asset(s) over budget. Either make it smaller or change the number in tests/budget.mjs deliberately.` );
	process.exit( 1 );
}

console.log( '\nAll assets within budget' );
