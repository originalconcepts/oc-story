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

// Loaded at whatever version this file was asked for. A bare relative import
// drops the query string, and plugin assets are served with a very long
// cache — so a stale mp4.js would outlive several releases.
const container = import( new URL( 'mp4.js' + new URL( import.meta.url ).search, import.meta.url ).href );

const DEFAULTS = {
	// A cap on the longest edge, whichever way the clip is oriented.
	maxSide: 1280,
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
 * How long and how heavy the source is.
 *
 * @param {Object} video Demuxed video track.
 * @param {number} fileBytes Whole-file size.
 * @return {{duration: number, rate: number}} Seconds and bits per second.
 */
export function sourceStats( video, fileBytes ) {
	const samples = video.samples || [];

	if ( ! samples.length || ! video.timescale ) {
		return { duration: 0, rate: Infinity };
	}

	const last = samples[ samples.length - 1 ];
	const duration = ( last.dts + last.duration ) / video.timescale;

	return {
		duration,
		rate: duration > 0 ? ( fileBytes * 8 ) / duration : Infinity,
	};
}

/**
 * Should this file be copied through instead of re-encoded?
 *
 * The gate test that found this: a 2.0MB H.264 clip, already 720p at 870kbps,
 * came out of the encoder at 2.3MB — re-encoding an efficient source *up* to
 * the target bitrate makes it bigger and worse at the same time. When the
 * source is already what we would have produced, the only thing it actually
 * needs is the container: our MP4 with the index first. So the samples are
 * copied verbatim, exactly like the audio always was.
 *
 * Copy only when every condition holds; anything else re-encodes:
 * H.264 (HEVC must be converted to play everywhere), no rotation (our muxer
 * writes an upright matrix), within the size cap, and no heavier than what
 * the encoder itself would emit — with headroom for the audio track.
 *
 * @param {Object} video     Demuxed video track.
 * @param {number} fileBytes Whole-file size.
 * @param {Object} settings  { maxSide, bitrate }.
 * @return {boolean}
 */
export function copyDecision( video, fileBytes, settings ) {
	if ( 'avc' !== video.family ) {
		return false;
	}

	if ( ( video.rotation || 0 ) !== 0 ) {
		return false;
	}

	if ( Math.max( video.width, video.height ) > settings.maxSide ) {
		return false;
	}

	const stats = sourceStats( video, fileBytes );

	if ( ! stats.duration ) {
		return false;
	}

	return stats.rate <= settings.bitrate * 1.3 + 160000;
}

/**
 * A poster frame via the browser's own decoder.
 *
 * The copy path never decodes anything, so the poster comes from a throwaway
 * <video> element instead. Best effort: a failed poster is a fixable problem,
 * a failed upload is not.
 *
 * @param {File|Blob} file    Source file.
 * @param {string}    type    Image mime type.
 * @param {number}    quality Quality 0..1.
 * @return {Promise<string>} Data URL, or ''.
 */
function posterFromFile( file, type, quality ) {
	return new Promise( ( resolve ) => {
		const url = URL.createObjectURL( file );
		const video = document.createElement( 'video' );

		const done = ( poster ) => {
			URL.revokeObjectURL( url );
			video.removeAttribute( 'src' );
			resolve( poster );
		};

		const timer = setTimeout( () => done( '' ), 8000 );

		video.muted = true;
		video.playsInline = true;
		video.preload = 'auto';

		video.addEventListener( 'loadeddata', () => {
			video.currentTime = Math.min( 0.1, video.duration || 0.1 );
		} );

		video.addEventListener( 'seeked', async () => {
			clearTimeout( timer );
			try {
				const { canvas, ctx } = makeCanvas( video.videoWidth, video.videoHeight );
				ctx.drawImage( video, 0, 0 );
				done( await canvasToDataUrl( canvas, type, quality ) );
			} catch ( e ) {
				done( '' );
			}
		} );

		video.addEventListener( 'error', () => {
			clearTimeout( timer );
			done( '' );
		} );

		video.src = url;
	} );
}

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
 * The cap is on the **longest side**, not on the height. Capping the height
 * would leave a 1920x1080 clip completely untouched — 1080 is already under a
 * 1280 target — and quietly ship a landscape video at full size through a
 * pipeline built for phones. Whichever way the clip is oriented, the long edge
 * comes down to the target and the short edge follows.
 *
 * Rounded to even numbers because H.264 chroma is subsampled and an odd
 * dimension is rejected outright by some encoders.
 *
 * @param {number} width    Source coded width.
 * @param {number} height   Source coded height.
 * @param {number} rotation Degrees.
 * @param {number} target   Cap for the longest side.
 * @return {{width: number, height: number, swapped: boolean}}
 */
export function outputSize( width, height, rotation, target ) {
	const swapped = rotation === 90 || rotation === 270;

	const displayWidth = swapped ? height : width;
	const displayHeight = swapped ? width : height;

	// Never upscale. A 480p clip stays 480p rather than being inflated into a
	// bigger file that looks exactly the same.
	const scale = Math.min( 1, target / Math.max( displayWidth, displayHeight ) );

	const even = ( value ) => Math.max( 2, Math.round( ( value * scale ) / 2 ) * 2 );

	return {
		width: even( displayWidth ),
		height: even( displayHeight ),
		swapped,
	};
}

/**
 * Wait until a codec queue has drained enough to accept more work.
 *
 * Event-driven, not polled. A polled version looks equivalent and is not: timers
 * are throttled hard in a background tab, and the studio is exactly the kind of
 * screen someone starts and then switches away from. Waiting on `dequeue` is
 * immune to that, and adds no latency of its own.
 *
 * @param {Object} codec Encoder or decoder.
 * @param {string} key   Queue size property.
 */
async function drain( codec, key ) {
	while ( codec[ key ] > QUEUE_DEPTH ) {
		await new Promise( ( resolve ) => {
			if ( typeof codec.addEventListener === 'function' ) {
				codec.addEventListener( 'dequeue', resolve, { once: true } );
				// A codec that never fires the event must not deadlock the loop.
				setTimeout( resolve, 250 );
				return;
			}
			setTimeout( resolve, 4 );
		} );
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
 * What a video is, without decoding a frame of it in anger.
 *
 * A `<video>` element can tell us the size and the length of anything the
 * device can play, and can hand back one frame as a poster — no WebCodecs
 * needed. That matters because a phone without WebCodecs was uploading with
 * no poster and no dimensions at all: the shop then drew an empty circle,
 * which is indistinguishable from the video never having arrived.
 *
 * @param {File|Blob} file    Source file.
 * @param {string}    type    Poster mime.
 * @param {number}    quality Poster quality.
 * @return {Promise<Object>} { poster, width, height, duration }
 */
export function probe( file, type = DEFAULTS.posterType, quality = DEFAULTS.posterQuality ) {
	return new Promise( ( resolve ) => {
		const url = URL.createObjectURL( file );
		const video = document.createElement( 'video' );
		const empty = { poster: '', width: 0, height: 0, duration: 0 };

		const done = ( result ) => {
			URL.revokeObjectURL( url );
			video.removeAttribute( 'src' );
			resolve( result );
		};

		const timer = setTimeout( () => done( empty ), 8000 );

		video.muted = true;
		video.playsInline = true;
		video.preload = 'auto';

		video.addEventListener( 'loadeddata', () => {
			video.currentTime = Math.min( 0.1, video.duration || 0.1 );
		} );

		video.addEventListener( 'seeked', async () => {
			clearTimeout( timer );

			const facts = {
				poster: '',
				width: video.videoWidth || 0,
				height: video.videoHeight || 0,
				duration: isFinite( video.duration ) ? video.duration : 0,
			};

			try {
				const { canvas, ctx } = makeCanvas( facts.width, facts.height );
				ctx.drawImage( video, 0, 0 );
				facts.poster = await canvasToDataUrl( canvas, type, quality );
			} catch ( e ) {}

			done( facts );
		} );

		video.addEventListener( 'error', () => {
			clearTimeout( timer );
			done( empty );
		} );

		video.src = url;
	} );
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
	const source = ( await container ).demux( buffer );

	if ( copyDecision( source.video, file.size, settings ) ) {
		return copyThrough( file, source, settings, onProgress );
	}

	const rotation = source.video.rotation || 0;
	const size = outputSize( source.video.width, source.video.height, rotation, settings.maxSide );

	// When nothing is being downscaled, spending more bits than the source
	// does cannot add quality that is not there. HEVC gets headroom because
	// H.264 needs more bits for the same picture.
	const stats = sourceStats( source.video, file.size );
	const downscaling = size.width < ( size.swapped ? source.video.height : source.video.width )
		|| size.height < ( size.swapped ? source.video.width : source.video.height );

	let bitrate = settings.bitrate;
	if ( ! downscaling && isFinite( stats.rate ) ) {
		bitrate = Math.min(
			bitrate,
			Math.max( 600000, Math.round( stats.rate * ( 'hevc' === source.video.family ? 1.6 : 1.05 ) ) )
		);
	}

	const config = await pickConfig( size.width, size.height, bitrate, settings.fps );
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

	const decoderConfig = {
		codec: source.video.codec,
		description: source.video.description,
		codedWidth: source.video.width,
		codedHeight: source.video.height,
	};

	// An iPhone records HEVC by default and a device without hardware HEVC
	// decode cannot open it at all. Finding that out here gives the studio
	// something true to say, instead of an empty output and no explanation.
	try {
		const support = await VideoDecoder.isConfigSupported( decoderConfig );
		if ( ! support || ! support.supported ) {
			const error = new Error( 'ocs_no_decoder' );
			error.codec = source.video.codec;
			throw error;
		}
	} catch ( e ) {
		if ( 'ocs_no_decoder' === e.message ) {
			throw e;
		}
		// isConfigSupported itself failing is the same answer.
		const error = new Error( 'ocs_no_decoder' );
		error.codec = source.video.codec;
		throw error;
	}

	decoder.configure( decoderConfig );

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

	const blob = ( await container ).mux( {
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
		mode: 'encode',
		width: size.width,
		height: size.height,
		duration: Math.round( duration * 100 ) / 100,
		sourceBytes: file.size,
		bytes: blob.size,
		sourceCodec: source.video.codec,
		sourceWidth: source.video.width,
		sourceHeight: source.video.height,
		rotation,
	};
}

/**
 * The copy path: same samples, our container.
 *
 * The video is already what the encoder would have produced, so the only work
 * left is the part the source container got wrong — the index goes first, and
 * anything past the length cap is trimmed. No codec is opened at all.
 *
 * @param {File|Blob} file       Source file.
 * @param {Object}    source     Demuxed tracks.
 * @param {Object}    settings   Encoder settings.
 * @param {Function}  onProgress Called with 0..1.
 * @return {Promise<Object>} Same shape as encode().
 */
async function copyThrough( file, source, settings, onProgress ) {
	onProgress( 0.2 );

	const limit = settings.maxSeconds * source.video.timescale;
	const samples = source.video.samples.filter( ( sample ) => sample.dts < limit );

	if ( ! samples.length ) {
		throw new Error( 'ocs_encode_empty' );
	}

	let audio = null;
	if ( source.audio && source.audio.samples.length && source.audio.description ) {
		const audioLimit = settings.maxSeconds * source.audio.timescale;
		const kept = source.audio.samples.filter( ( sample ) => sample.dts < audioLimit );

		if ( kept.length ) {
			audio = { ...source.audio, samples: kept };
		}
	}

	const poster = await posterFromFile( file, settings.posterType, settings.posterQuality );
	onProgress( 0.6 );

	const blob = ( await container ).mux( {
		video: {
			width: source.video.width,
			height: source.video.height,
			timescale: source.video.timescale,
			description: source.video.description,
			samples,
		},
		audio,
	} );

	onProgress( 1 );

	const last = samples[ samples.length - 1 ];
	const duration = ( last.dts + last.duration ) / source.video.timescale;

	return {
		blob,
		poster,
		mode: 'copy',
		width: source.video.width,
		height: source.video.height,
		duration: Math.round( duration * 100 ) / 100,
		sourceBytes: file.size,
		bytes: blob.size,
		sourceCodec: source.video.codec,
		sourceWidth: source.video.width,
		sourceHeight: source.video.height,
		rotation: 0,
	};
}
