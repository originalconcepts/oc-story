/**
 * On-device video encoding.
 *
 * The store owner picks a clip straight off the camera roll. It is 4K, it is
 * 90MB, and it is going to be watched in a circle 64 pixels across. Re-encoding
 * it in the browser before it is uploaded is what makes the whole plugin work on
 * ordinary hosting: no ffmpeg, no queue, no CPU spike on a shared server, and an
 * upload that finishes on a mobile connection.
 *
 * The audio track is never touched — it is demuxed and written straight back
 * out. Phone audio is already AAC at a bitrate we would not improve on, and not
 * re-encoding it removes the most failure-prone half of the pipeline.
 *
 * Studio only. Nothing here ever loads on a storefront.
 */

import { demux, mux } from './mp4.js';

const DEFAULTS = {
	height: 1280,
	bitrate: 1500000,
	fps: 30,
	maxSeconds: 60,
	keyframeSeconds: 2,
	posterQuality: 0.78,
	posterType: 'image/webp',
};

// Deep enough to keep the hardware encoder busy, shallow enough that a long
// video does not hold every decoded frame in memory at once. A 4K frame is
// ~12MB; at a queue of 8 that is the difference between working and a tab crash.
const QUEUE_DEPTH = 8;

/**
 * Can this browser re-encode at all?
 *
 * @return {boolean}
 */
export function isSupported() {
	return (
		typeof VideoEncoder !== 'undefined' &&
		typeof VideoDecoder !== 'undefined' &&
		typeof VideoFrame !== 'undefined' &&
		typeof EncodedVideoChunk !== 'undefined'
	);
}

/**
 * Pick an H.264 codec string this browser will actually accept.
 *
 * Baseline first, and on purpose. Baseline has no B-frames, which means the
 * encoder's presentation timestamps are also its decode timestamps and the
 * muxer never has to reorder anything. It is a few percent less efficient than
 * Main and it plays on every device a shop's customers own.
 *
 * @param {number} width   Output width.
 * @param {number} height  Output height.
 * @param {number} bitrate Target bitrate.
 * @param {number} fps     Target frame rate.
 * @return {Promise<?Object>} A supported config, or null.
 */
async function pickConfig( width, height, bitrate, fps ) {
	const candidates = [ 'avc1.42E01F', 'avc1.42001F', 'avc1.42E028', 'avc1.4D401F' ];

	for ( const codec of candidates ) {
		const config = {
			codec,
			width,
			height,
			bitrate,
			framerate: fps,
			latencyMode: 'quality',
			avc: { format: 'avc' },
		};

		try {
			const support = await VideoEncoder.isConfigSupported( config );
			if ( support && support.supported ) {
				return support.config || config;
			}
		} catch ( e ) {
			// Try the next one.
		}
	}

	return null;
}

/**
 * Output dimensions for a source, honouring its rotation.
 *
 * Rounded to even numbers because H.264 chroma is subsampled and an odd
 * dimension is rejected outright by some encoders.
 *
 * @param {number} width    Source width.
 * @param {number} height   Source height.
 * @param {number} rotation Degrees.
 * @param {number} target   Target height for the short-side-up orientation.
 * @return {{width: number, height: number, swapped: boolean}}
 */
export function outputSize( width, height, rotation, target ) {
	const swapped = rotation === 90 || rotation === 270;

	const displayWidth = swapped ? height : width;
	const displayHeight = swapped ? width : height;

	// Never upscale. A 480p clip stays 480p rather than being inflated into a
	// bigger file that looks exactly the same.
	const scale = Math.min( 1, target / displayHeight );

	const even = ( value ) => Math.max( 2, Math.round( value * scale / 2 ) * 2 );

	return {
		width: even( displayWidth ),
		height: even( displayHeight ),
		swapped,
	};
}

/**
 * Wait until a codec queue has drained enough to accept more work.
 *
 * @param {Object} codec Encoder or decoder.
 * @param {string} key   Queue size property.
 */
async function drain( codec, key ) {
	while ( codec[ key ] > QUEUE_DEPTH ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 4 ) );
	}
}

/**
 * A canvas of the right kind for this browser.
 *
 * @param {number} width  Width.
 * @param {number} height Height.
 * @return {Object} Canvas and 2D context.
 */
function makeCanvas( width, height ) {
	const canvas = typeof OffscreenCanvas !== 'undefined'
		? new OffscreenCanvas( width, height )
		: Object.assign( document.createElement( 'canvas' ), { width, height } );

	return { canvas, ctx: canvas.getContext( '2d', { alpha: false } ) };
}

/**
 * Export a canvas as a data URL.
 *
 * @param {Object} canvas  Canvas.
 * @param {string} type    Mime type.
 * @param {number} quality Quality 0..1.
 * @return {Promise<string>}
 */
async function canvasToDataUrl( canvas, type, quality ) {
	if ( canvas.convertToBlob ) {
		const blob = await canvas.convertToBlob( { type, quality } );
		return await new Promise( ( resolve ) => {
			const reader = new FileReader();
			reader.onload = () => resolve( reader.result );
			reader.readAsDataURL( blob );
		} );
	}

	return canvas.toDataURL( type, quality );
}

/**
 * Re-encode a video file for the storefront.
 *
 * @param {File|Blob} file       Source file.
 * @param {Object}    options    Overrides for DEFAULTS.
 * @param {Function}  onProgress Called with 0..1.
 * @return {Promise<Object>} { blob, width, height, duration, poster, sourceBytes }
 */
export async function encode( file, options = {}, onProgress = () => {} ) {
	if ( ! isSupported() ) {
		throw new Error( 'ocs_no_webcodecs' );
	}

	const settings = { ...DEFAULTS, ...options };
	const buffer = await file.arrayBuffer();
	const source = demux( buffer );

	const rotation = source.video.rotation || 0;
	const size = outputSize( source.video.width, source.video.height, rotation, settings.height );

	const config = await pickConfig( size.width, size.height, settings.bitrate, settings.fps );
	if ( ! config ) {
		throw new Error( 'ocs_no_h264_encoder' );
	}

	const { canvas, ctx } = makeCanvas( size.width, size.height );

	const chunks = [];
	let description = null;
	let encodeError = null;

	const encoder = new VideoEncoder( {
		output: ( chunk, meta ) => {
			if ( meta && meta.decoderConfig && meta.decoderConfig.description && ! description ) {
				description = new Uint8Array( meta.decoderConfig.description );
			}

			const data = new Uint8Array( chunk.byteLength );
			chunk.copyTo( data );

			chunks.push( {
				data,
				size: chunk.byteLength,
				timestamp: chunk.timestamp,
				sync: chunk.type === 'key',
			} );
		},
		error: ( e ) => {
			encodeError = e;
		},
	} );

	encoder.configure( config );

	// Only samples up to the cap are decoded at all, so trimming a long clip
	// costs nothing rather than costing a full decode we then throw away.
	const limit = settings.maxSeconds * source.video.timescale;
	const wanted = source.video.samples.filter( ( sample ) => sample.dts < limit );

	const keyframeInterval = Math.max( 1, Math.round( settings.keyframeSeconds * settings.fps ) );

	let poster = null;
	let emitted = 0;
	let decodeError = null;

	const decoder = new VideoDecoder( {
		output: async ( frame ) => {
			try {
				ctx.save();

				// Rotate about the centre of the output, then draw the source
				// frame in its own orientation.
				ctx.translate( size.width / 2, size.height / 2 );
				ctx.rotate( ( rotation * Math.PI ) / 180 );

				const drawWidth = size.swapped ? size.height : size.width;
				const drawHeight = size.swapped ? size.width : size.height;

				ctx.drawImage( frame, -drawWidth / 2, -drawHeight / 2, drawWidth, drawHeight );
				ctx.restore();

				if ( ! poster ) {
					poster = await canvasToDataUrl( canvas, settings.posterType, settings.posterQuality );
				}

				const output = new VideoFrame( canvas, {
					timestamp: frame.timestamp,
					duration: frame.duration || Math.round( 1000000 / settings.fps ),
				} );

				encoder.encode( output, { keyFrame: emitted % keyframeInterval === 0 } );
				output.close();
				emitted++;

				onProgress( Math.min( 0.95, emitted / Math.max( 1, wanted.length ) ) );
			} catch ( e ) {
				decodeError = e;
			} finally {
				frame.close();
			}
		},
		error: ( e ) => {
			decodeError = e;
		},
	} );

	decoder.configure( {
		codec: 'avc1.' + [ ...source.video.description.slice( 1, 4 ) ]
			.map( ( b ) => b.toString( 16 ).padStart( 2, '0' ) )
			.join( '' ),
		description: source.video.description,
		codedWidth: source.video.width,
		codedHeight: source.video.height,
	} );

	for ( const sample of wanted ) {
		if ( decodeError || encodeError ) {
			break;
		}

		// Both queues, not just the decoder's: the output callback hands frames
		// straight to the encoder, so a slow encoder backs up behind a fast
		// decoder and the decoded frames pile up in memory.
		await drain( decoder, 'decodeQueueSize' );
		await drain( encoder, 'encodeQueueSize' );

		decoder.decode(
			new EncodedVideoChunk( {
				type: sample.sync ? 'key' : 'delta',
				timestamp: Math.round( ( sample.cts / source.video.timescale ) * 1000000 ),
				duration: Math.round( ( sample.duration / source.video.timescale ) * 1000000 ),
				data: sample.data,
			} )
		);
	}

	await decoder.flush();
	decoder.close();

	await encoder.flush();
	encoder.close();

	if ( decodeError ) {
		throw decodeError;
	}
	if ( encodeError ) {
		throw encodeError;
	}
	if ( ! chunks.length || ! description ) {
		throw new Error( 'ocs_encode_empty' );
	}

	// The encoder emits in decode order, which for Baseline is also presentation
	// order — but sorting costs nothing and removes the assumption.
	chunks.sort( ( a, b ) => a.timestamp - b.timestamp );

	const samples = chunks.map( ( chunk, i ) => {
		const next = chunks[ i + 1 ];
		const duration = next
			? next.timestamp - chunk.timestamp
			: Math.round( 1000000 / settings.fps );

		return {
			data: chunk.data,
			size: chunk.size,
			dts: chunk.timestamp,
			cts: chunk.timestamp,
			duration: Math.max( 1, duration ),
			sync: chunk.sync,
		};
	} );

	// Audio is copied through untouched, trimmed to the same cap.
	let audio = null;
	if ( source.audio && source.audio.samples.length && source.audio.description ) {
		const audioLimit = settings.maxSeconds * source.audio.timescale;
		const kept = source.audio.samples.filter( ( sample ) => sample.dts < audioLimit );

		if ( kept.length ) {
			audio = { ...source.audio, samples: kept };
		}
	}

	const blob = mux( {
		video: {
			width: size.width,
			height: size.height,
			timescale: 1000000,
			description,
			samples,
		},
		audio,
	} );

	onProgress( 1 );

	const duration = samples.reduce( ( total, sample ) => total + sample.duration, 0 ) / 1000000;

	return {
		blob,
		poster,
		width: size.width,
		height: size.height,
		duration: Math.round( duration * 100 ) / 100,
		sourceBytes: file.size,
		bytes: blob.size,
	};
}
