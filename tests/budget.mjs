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
	// Inlined into the page, so what counts is the raw size it adds to the
	// HTML. Over the wire it is compressed with everything else.
	{ file: 'assets/css/bar.css', raw: 2048, why: 'inlined critical CSS' },

	// The only script the storefront loads before an interaction.
	{ file: 'assets/js/bar.js', gzip: 4096, why: 'initial script' },

	// Imported on the first tap, never before.
	{ file: 'assets/js/player.js', gzip: 16384, why: 'player chunk' },
	{ file: 'assets/css/player.css', gzip: 5120, why: 'player stylesheet' },
];

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

if ( failed ) {
	console.log( `\nFAILED: ${ failed } asset(s) over budget. Either make it smaller or change the number in tests/budget.mjs deliberately.` );
	process.exit( 1 );
}

console.log( '\nAll assets within budget' );
