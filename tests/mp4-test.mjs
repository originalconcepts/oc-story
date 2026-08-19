/**
 * Round-trip test for the MP4 muxer and demuxer.
 *
 * Runs with no browser and no WebCodecs, under either runtime:
 *
 *   node tests/mp4-test.mjs
 *
 *   /System/Library/Frameworks/JavaScriptCore.framework/Versions/A/Helpers/jsc \
 *     --module-file=tests/mp4-test.mjs
 *
 * It cannot tell us whether a phone will decode the output — only the on-device
 * page can do that. What it does pin down is the part that is pure byte layout,
 * where a two-byte mistake produces a file that every player silently rejects:
 * the boxes are the right length, the sample table survives a round trip, and
 * `moov` really does come before `mdat`.
 */

// jsc calls it print, node calls it console.log.
if ( typeof globalThis.print !== 'function' ) {
	globalThis.print = ( ...args ) => console.log( ...args );
}

globalThis.TextEncoder = class {
	encode( input ) {
		const out = new Uint8Array( input.length );
		for ( let i = 0; i < input.length; i++ ) {
			out[ i ] = input.charCodeAt( i ) & 0xff;
		}
		return out;
	}
};

// Enough of Blob to concatenate parts and read them back.
globalThis.Blob = class {
	constructor( parts ) {
		let length = 0;
		for ( const part of parts ) {
			length += part.byteLength;
		}

		this.bytes = new Uint8Array( length );
		let offset = 0;
		for ( const part of parts ) {
			this.bytes.set( part, offset );
			offset += part.byteLength;
		}

		this.size = length;
	}

	arrayBuffer() {
		return this.bytes.buffer.slice( this.bytes.byteOffset, this.bytes.byteOffset + this.bytes.byteLength );
	}
};

const { demux, mux } = await import( '../assets/js/mp4.js' );

let pass = 0;
let fail = 0;

function check( label, condition ) {
	if ( condition ) {
		pass++;
		print( '  ok   ' + label );
	} else {
		fail++;
		print( '  FAIL ' + label );
	}
}

function fourccAt( bytes, offset ) {
	return String.fromCharCode( bytes[ offset ], bytes[ offset + 1 ], bytes[ offset + 2 ], bytes[ offset + 3 ] );
}

// A plausible avcC: version, profile, compatibility, level, lengthSize, then
// one SPS and one PPS. The bytes never reach a decoder here; what matters is
// that they come back out byte for byte.
const description = new Uint8Array( [
	0x01, 0x42, 0xe0, 0x1f, 0xff,
	0xe1, 0x00, 0x04, 0x67, 0x42, 0xe0, 0x1f,
	0x01, 0x00, 0x04, 0x68, 0xce, 0x38, 0x80,
] );

const FRAMES = 24;
const TIMESCALE = 1000000;
const FRAME_DURATION = Math.round( TIMESCALE / 30 );

const videoSamples = [];
for ( let i = 0; i < FRAMES; i++ ) {
	const size = 400 + i * 7;
	const data = new Uint8Array( size );
	data.fill( i + 1 );

	videoSamples.push( {
		data,
		size,
		dts: i * FRAME_DURATION,
		cts: i * FRAME_DURATION,
		duration: FRAME_DURATION,
		sync: i % 12 === 0,
	} );
}

const AUDIO_TIMESCALE = 44100;
const AUDIO_DURATION = 1024;
const audioConfig = new Uint8Array( [ 0x12, 0x10 ] );

const audioSamples = [];
for ( let i = 0; i < 30; i++ ) {
	const size = 180 + i;
	const data = new Uint8Array( size );
	data.fill( 128 + ( i % 64 ) );

	audioSamples.push( {
		data,
		size,
		dts: i * AUDIO_DURATION,
		cts: i * AUDIO_DURATION,
		duration: AUDIO_DURATION,
		sync: true,
	} );
}

print( '\nMux' );

const blob = mux( {
	video: {
		width: 720,
		height: 1280,
		timescale: TIMESCALE,
		description,
		samples: videoSamples,
	},
	audio: {
		timescale: AUDIO_TIMESCALE,
		channels: 1,
		sampleSize: 16,
		sampleRate: AUDIO_TIMESCALE,
		description: audioConfig,
		samples: audioSamples,
	},
} );

const bytes = blob.bytes;
const view = new DataView( bytes.buffer );

check( 'produces a file', bytes.byteLength > 0 );
check( 'starts with ftyp', fourccAt( bytes, 4 ) === 'ftyp' );

const ftypSize = view.getUint32( 0 );
check( 'moov comes second — faststart', fourccAt( bytes, ftypSize + 4 ) === 'moov' );

const moovSize = view.getUint32( ftypSize );
check( 'mdat comes last', fourccAt( bytes, ftypSize + moovSize + 4 ) === 'mdat' );

const declared = view.getUint32( ftypSize + moovSize );
check( 'mdat length matches the file', ftypSize + moovSize + declared === bytes.byteLength );

const payload = videoSamples.reduce( ( sum, s ) => sum + s.size, 0 )
	+ audioSamples.reduce( ( sum, s ) => sum + s.size, 0 );
check( 'mdat holds every sample', declared === payload + 8 );

print( '\nDemux' );

const parsed = demux( blob.arrayBuffer() );

check( 'finds the video track', !! parsed.video );
check( 'finds the audio track', !! parsed.audio );
check( 'reads the width', parsed.video.width === 720 );
check( 'reads the height', parsed.video.height === 1280 );
check( 'reads the timescale', parsed.video.timescale === TIMESCALE );
check( 'reads rotation from the unity matrix', parsed.video.rotation === 0 );
check( 'names the codec', parsed.video.codec === 'avc1.42e01f' );
check( 'names the codec family', parsed.video.family === 'avc' );
check( 'recovers every video sample', parsed.video.samples.length === FRAMES );
check( 'recovers every audio sample', parsed.audio.samples.length === 30 );
check( 'reads the audio sample rate', parsed.audio.sampleRate === AUDIO_TIMESCALE );
check( 'reads the audio channel count', parsed.audio.channels === 1 );

check(
	'avcC survives the round trip',
	parsed.video.description.length === description.length
		&& description.every( ( byte, i ) => parsed.video.description[ i ] === byte )
);

check(
	'AudioSpecificConfig survives the round trip',
	!! parsed.audio.description
		&& parsed.audio.description.length === audioConfig.length
		&& audioConfig.every( ( byte, i ) => parsed.audio.description[ i ] === byte )
);

let sizesMatch = true;
let timingMatch = true;
let dataMatch = true;

parsed.video.samples.forEach( ( sample, i ) => {
	if ( sample.size !== videoSamples[ i ].size ) {
		sizesMatch = false;
	}
	if ( sample.dts !== videoSamples[ i ].dts || sample.duration !== FRAME_DURATION ) {
		timingMatch = false;
	}
	for ( let b = 0; b < sample.size; b++ ) {
		if ( sample.data[ b ] !== videoSamples[ i ].data[ b ] ) {
			dataMatch = false;
			break;
		}
	}
} );

check( 'every sample size round trips', sizesMatch );
check( 'every sample timestamp round trips', timingMatch );
check( 'every sample byte round trips at the recorded offset', dataMatch );

const syncCount = parsed.video.samples.filter( ( s ) => s.sync ).length;
check( 'keyframes are marked in stss', syncCount === videoSamples.filter( ( s ) => s.sync ).length );

let audioDataMatch = true;
parsed.audio.samples.forEach( ( sample, i ) => {
	for ( let b = 0; b < sample.size; b++ ) {
		if ( sample.data[ b ] !== audioSamples[ i ].data[ b ] ) {
			audioDataMatch = false;
			break;
		}
	}
} );
check( 'audio bytes round trip', audioDataMatch );

print( '\nRejections' );

function throws( fn ) {
	try {
		fn();
		return false;
	} catch ( e ) {
		return true;
	}
}

check( 'rejects a file with no moov', throws( () => demux( new Uint8Array( [ 0, 0, 0, 8, 0x66, 0x74, 0x79, 0x70 ] ).buffer ) ) );
check( 'rejects an empty buffer', throws( () => demux( new ArrayBuffer( 0 ) ) ) );

print( '\nHEVC codec strings' );

const { hevcCodecString } = await import( '../assets/js/mp4.js' );

// The hvcC an iPhone actually writes: Main 10, level 4.0.
const iphoneHvcC = new Uint8Array( [
	0x01, 0x02, 0x20, 0x00, 0x00, 0x00, 0xb0, 0x00, 0x00, 0x00, 0x00, 0x00, 0x78,
] );
check(
	'derives the codec string an iPhone needs',
	hevcCodecString( 'hvc1', iphoneHvcC ) === 'hvc1.2.4.L120.B0'
);

const mainTier = new Uint8Array( [
	0x01, 0x21, 0x60, 0x00, 0x00, 0x00, 0x90, 0x00, 0x00, 0x00, 0x00, 0x00, 0x5d,
] );
check( 'reads the high tier flag', hevcCodecString( 'hvc1', mainTier ).indexOf( '.H93' ) > 0 );

print( '\nOutput sizing' );

const { outputSize } = await import( '../assets/js/encoder.js' );

const upright = outputSize( 1080, 1920, 0, 1280 );
check( 'scales an upright clip to the cap', upright.height === 1280 && upright.width === 720 );

const rotated = outputSize( 1920, 1080, 90, 1280 );
check( 'treats a rotated clip as portrait', rotated.width === 720 && rotated.height === 1280 );
check( 'flags the swap so the canvas can rotate', rotated.swapped === true );

const landscape = outputSize( 1920, 1080, 0, 1280 );
check( 'caps a landscape clip on its long edge', landscape.width === 1280 && landscape.height === 720 );

const square = outputSize( 1440, 1440, 0, 1280 );
check( 'caps a square clip', square.width === 1280 && square.height === 1280 );

const small = outputSize( 480, 854, 0, 1280 );
check( 'never upscales', small.height === 854 && small.width === 480 );

const odd = outputSize( 1081, 1921, 0, 1280 );
check( 'rounds to even dimensions', odd.width % 2 === 0 && odd.height % 2 === 0 );

print( '\n' + ( fail ? 'FAILED: ' + fail : 'All ' + pass + ' checks passed' ) );

if ( fail ) {
	if ( typeof process !== 'undefined' && process.exit ) {
		process.exit( 1 );
	}
	throw new Error( 'mp4 round trip failed' );
}
