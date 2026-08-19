/**
 * Minimal MP4 demuxer and muxer.
 *
 * WebCodecs works in samples, not files: VideoDecoder needs demuxed access
 * units and an avcC description, and VideoEncoder hands back raw chunks that
 * something has to write into a container. That something is this file.
 *
 * Two decisions shape it:
 *
 * 1. The audio track is copied through, sample for sample. Phone video is
 *    already AAC at a bitrate we would not improve on, so there is no
 *    AudioDecoder and no AudioEncoder here at all — the original samples are
 *    re-written into the output with their original timing. That removes the
 *    largest and least predictable part of the pipeline.
 *
 * 2. `moov` is written before `mdat` — faststart. An MP4 with the index at the
 *    end forces mobile Safari to download the whole file before it shows a
 *    frame, which quietly undoes `preload="none"` and every byte we saved.
 *    Writing it first means measuring the index twice, which is what the
 *    two-pass build in `mux()` is doing.
 *
 * No dependencies, no build step. Studio only — this never loads on a storefront.
 */

const TEXT = new TextEncoder();

function fourcc( s ) {
	return TEXT.encode( s );
}

/* ------------------------------------------------------------------ demux */

/**
 * Walk the top-level boxes of a buffer.
 *
 * @param {DataView} view   Whole file.
 * @param {number}   start  Offset to start at.
 * @param {number}   end    Offset to stop at.
 * @param {Function} visit  Called with (type, payloadStart, payloadEnd).
 */
function walk( view, start, end, visit ) {
	let offset = start;

	while ( offset + 8 <= end ) {
		let size = view.getUint32( offset );
		const type = String.fromCharCode(
			view.getUint8( offset + 4 ),
			view.getUint8( offset + 5 ),
			view.getUint8( offset + 6 ),
			view.getUint8( offset + 7 )
		);

		let header = 8;

		if ( size === 1 ) {
			// 64-bit size. We never write these, but cameras do.
			const high = view.getUint32( offset + 8 );
			const low = view.getUint32( offset + 12 );
			size = high * 4294967296 + low;
			header = 16;
		} else if ( size === 0 ) {
			size = end - offset;
		}

		if ( size < header || offset + size > end ) {
			break;
		}

		visit( type, offset + header, offset + size );
		offset += size;
	}
}

/**
 * Find the first *direct child* box of a type.
 *
 * Not recursive. A table box holds arbitrary numbers that would parse as
 * plausible box headers, so descending has to be explicit.
 *
 * @param {DataView} view  Whole file.
 * @param {number}   start Search start.
 * @param {number}   end   Search end.
 * @param {string}   type  Box type.
 * @return {?{start: number, end: number}} Payload range.
 */
function findBox( view, start, end, type ) {
	let found = null;

	walk( view, start, end, ( boxType, payloadStart, payloadEnd ) => {
		if ( found ) {
			return;
		}
		if ( boxType === type ) {
			found = { start: payloadStart, end: payloadEnd };
		}
	} );

	return found;
}

/**
 * Read a variable-length descriptor length, as used inside esds.
 *
 * @param {DataView} view   File.
 * @param {number}   offset Offset of the first length byte.
 * @return {{value: number, next: number}} Length and the offset after it.
 */
function readDescriptorLength( view, offset ) {
	let value = 0;
	let next = offset;

	for ( let i = 0; i < 4; i++ ) {
		const byte = view.getUint8( next++ );
		value = ( value << 7 ) | ( byte & 0x7f );
		if ( ! ( byte & 0x80 ) ) {
			break;
		}
	}

	return { value, next };
}

/**
 * Pull the AudioSpecificConfig out of an esds box.
 *
 * @param {DataView} view  File.
 * @param {number}   start esds payload start.
 * @param {number}   end   esds payload end.
 * @return {?Uint8Array} The decoder-specific info.
 */
function parseEsds( view, start, end ) {
	// Skip the full-box version and flags.
	let offset = start + 4;

	while ( offset < end ) {
		const tag = view.getUint8( offset++ );
		const length = readDescriptorLength( view, offset );
		offset = length.next;

		if ( tag === 0x03 ) {
			// ES_Descriptor: id (2) + flags (1), then nested descriptors.
			const flags = view.getUint8( offset + 2 );
			offset += 3;
			if ( flags & 0x80 ) {
				offset += 2;
			}
			if ( flags & 0x40 ) {
				offset += 1 + view.getUint8( offset );
			}
			if ( flags & 0x20 ) {
				offset += 2;
			}
			continue;
		}

		if ( tag === 0x04 ) {
			// DecoderConfigDescriptor: 13 bytes, then DecoderSpecificInfo.
			offset += 13;
			continue;
		}

		if ( tag === 0x05 ) {
			return new Uint8Array( view.buffer, view.byteOffset + offset, length.value );
		}

		offset += length.value;
	}

	return null;
}

/**
 * Read the display rotation out of a tkhd matrix.
 *
 * A phone records landscape frames and marks the track "rotate 90". Ignore this
 * and every portrait video comes out on its side — the single most visible way
 * to get a re-encode wrong.
 *
 * @param {DataView} view File.
 * @param {Object}   tkhd Payload range of the tkhd box.
 * @return {number} Degrees: 0, 90, 180 or 270.
 */
function readRotation( view, tkhd ) {
	const version = view.getUint8( tkhd.start );

	// v1 widens creation, modification and duration from 32 to 64 bits.
	const matrix = tkhd.start + ( version === 1 ? 52 : 40 );

	if ( matrix + 8 > tkhd.end ) {
		return 0;
	}

	// 16.16 fixed point.
	const a = view.getInt32( matrix ) / 65536;
	const b = view.getInt32( matrix + 4 ) / 65536;

	const degrees = Math.round( ( Math.atan2( b, a ) * 180 ) / Math.PI );

	return ( ( degrees % 360 ) + 360 ) % 360;
}

/**
 * Build the sample table for one track.
 *
 * @param {DataView} view      File.
 * @param {Object}   stbl      Payload range of the stbl box.
 * @param {number}   timescale Track timescale.
 * @return {Array<Object>} Samples with offset, size, dts, cts, duration, sync.
 */
function readSamples( view, stbl, timescale ) {
	const stts = findBox( view, stbl.start, stbl.end, 'stts' );
	const stsc = findBox( view, stbl.start, stbl.end, 'stsc' );
	const stsz = findBox( view, stbl.start, stbl.end, 'stsz' );
	const stco = findBox( view, stbl.start, stbl.end, 'stco' );
	const co64 = findBox( view, stbl.start, stbl.end, 'co64' );
	const stss = findBox( view, stbl.start, stbl.end, 'stss' );
	const ctts = findBox( view, stbl.start, stbl.end, 'ctts' );

	if ( ! stts || ! stsc || ! stsz || ( ! stco && ! co64 ) ) {
		throw new Error( 'ocs_mp4_incomplete_sample_table' );
	}

	// Sizes.
	const sizes = [];
	const uniform = view.getUint32( stsz.start + 4 );
	const sampleCount = view.getUint32( stsz.start + 8 );

	for ( let i = 0; i < sampleCount; i++ ) {
		sizes.push( uniform || view.getUint32( stsz.start + 12 + i * 4 ) );
	}

	// Decode timings.
	const deltas = [];
	const sttsCount = view.getUint32( stts.start + 4 );
	for ( let i = 0; i < sttsCount; i++ ) {
		const count = view.getUint32( stts.start + 8 + i * 8 );
		const delta = view.getUint32( stts.start + 12 + i * 8 );
		for ( let j = 0; j < count; j++ ) {
			deltas.push( delta );
		}
	}

	// Composition offsets, when the source has B-frames.
	const offsets = [];
	if ( ctts ) {
		const cttsCount = view.getUint32( ctts.start + 4 );
		for ( let i = 0; i < cttsCount; i++ ) {
			const count = view.getUint32( ctts.start + 8 + i * 8 );
			const offset = view.getInt32( ctts.start + 12 + i * 8 );
			for ( let j = 0; j < count; j++ ) {
				offsets.push( offset );
			}
		}
	}

	// Sync samples. No stss means every sample is a keyframe.
	let sync = null;
	if ( stss ) {
		sync = new Set();
		const stssCount = view.getUint32( stss.start + 4 );
		for ( let i = 0; i < stssCount; i++ ) {
			sync.add( view.getUint32( stss.start + 8 + i * 4 ) );
		}
	}

	// Chunk offsets.
	const chunkOffsets = [];
	if ( stco ) {
		const count = view.getUint32( stco.start + 4 );
		for ( let i = 0; i < count; i++ ) {
			chunkOffsets.push( view.getUint32( stco.start + 8 + i * 4 ) );
		}
	} else {
		const count = view.getUint32( co64.start + 4 );
		for ( let i = 0; i < count; i++ ) {
			const high = view.getUint32( co64.start + 8 + i * 8 );
			const low = view.getUint32( co64.start + 12 + i * 8 );
			chunkOffsets.push( high * 4294967296 + low );
		}
	}

	// Samples per chunk.
	const runs = [];
	const stscCount = view.getUint32( stsc.start + 4 );
	for ( let i = 0; i < stscCount; i++ ) {
		runs.push( {
			first: view.getUint32( stsc.start + 8 + i * 12 ),
			perChunk: view.getUint32( stsc.start + 12 + i * 12 ),
		} );
	}

	const samples = [];
	let sampleIndex = 0;
	let dts = 0;

	for ( let chunk = 0; chunk < chunkOffsets.length && sampleIndex < sampleCount; chunk++ ) {
		let perChunk = runs[ runs.length - 1 ].perChunk;
		for ( let r = 0; r < runs.length; r++ ) {
			const next = runs[ r + 1 ];
			if ( chunk + 1 >= runs[ r ].first && ( ! next || chunk + 1 < next.first ) ) {
				perChunk = runs[ r ].perChunk;
				break;
			}
		}

		let offset = chunkOffsets[ chunk ];

		for ( let n = 0; n < perChunk && sampleIndex < sampleCount; n++ ) {
			const size = sizes[ sampleIndex ];
			const delta = deltas[ sampleIndex ] || 0;
			const composition = offsets[ sampleIndex ] || 0;

			samples.push( {
				offset,
				size,
				dts,
				cts: dts + composition,
				duration: delta,
				sync: sync ? sync.has( sampleIndex + 1 ) : true,
				timescale,
				// A view, not a copy: the audio track is re-written verbatim and
				// there is no reason to duplicate megabytes to do it.
				data: new Uint8Array( view.buffer, offset, size ),
			} );

			offset += size;
			dts += delta;
			sampleIndex++;
		}
	}

	return samples;
}

/**
 * Demux an MP4 or MOV into tracks WebCodecs can consume.
 *
 * @param {ArrayBuffer} buffer Whole file.
 * @return {{video: ?Object, audio: ?Object}}
 */
export function demux( buffer ) {
	const view = new DataView( buffer );
	const end = buffer.byteLength;

	const moov = findBox( view, 0, end, 'moov' );
	if ( ! moov ) {
		// A fragmented file has its index in moof boxes we do not read.
		throw new Error( findBox( view, 0, end, 'moof' ) ? 'ocs_mp4_fragmented' : 'ocs_mp4_no_moov' );
	}

	const result = { video: null, audio: null };

	walk( view, moov.start, moov.end, ( type, start, trakEnd ) => {
		if ( type !== 'trak' ) {
			return;
		}

		const mdia = findBox( view, start, trakEnd, 'mdia' );
		if ( ! mdia ) {
			return;
		}

		const tkhd = findBox( view, start, trakEnd, 'tkhd' );

		const mdhd = findBox( view, mdia.start, mdia.end, 'mdhd' );
		const hdlr = findBox( view, mdia.start, mdia.end, 'hdlr' );

		// findBox only looks at direct children, deliberately: descending blindly
		// would read the contents of a table box as if they were boxes. So the
		// path to the sample table is walked one level at a time.
		const minf = findBox( view, mdia.start, mdia.end, 'minf' );
		const stbl = minf ? findBox( view, minf.start, minf.end, 'stbl' ) : null;

		if ( ! mdhd || ! hdlr || ! stbl ) {
			return;
		}

		const version = view.getUint8( mdhd.start );
		const timescale = version === 1 ? view.getUint32( mdhd.start + 20 ) : view.getUint32( mdhd.start + 12 );

		const handler = String.fromCharCode(
			view.getUint8( hdlr.start + 8 ),
			view.getUint8( hdlr.start + 9 ),
			view.getUint8( hdlr.start + 10 ),
			view.getUint8( hdlr.start + 11 )
		);

		const stsd = findBox( view, stbl.start, stbl.end, 'stsd' );
		if ( ! stsd ) {
			return;
		}

		const samples = readSamples( view, stbl, timescale );

		if ( handler === 'vide' && ! result.video ) {
			const avc1 = findBox( view, stsd.start + 8, stsd.end, 'avc1' )
				|| findBox( view, stsd.start + 8, stsd.end, 'avc3' );
			if ( ! avc1 ) {
				throw new Error( 'ocs_mp4_not_h264' );
			}

			// VisualSampleEntry is 78 bytes before the extension boxes begin.
			const avcC = findBox( view, avc1.start + 78, avc1.end, 'avcC' );
			if ( ! avcC ) {
				throw new Error( 'ocs_mp4_no_avcc' );
			}

			result.video = {
				timescale,
				rotation: tkhd ? readRotation( view, tkhd ) : 0,
				width: view.getUint16( avc1.start + 24 ),
				height: view.getUint16( avc1.start + 26 ),
				description: new Uint8Array( buffer, avcC.start, avcC.end - avcC.start ),
				samples,
			};
			return;
		}

		if ( handler === 'soun' && ! result.audio ) {
			const mp4a = findBox( view, stsd.start + 8, stsd.end, 'mp4a' );
			if ( ! mp4a ) {
				// Anything that is not AAC is dropped rather than guessed at.
				return;
			}

			// AudioSampleEntry is 28 bytes before the extension boxes begin.
			const esds = findBox( view, mp4a.start + 28, mp4a.end, 'esds' );

			result.audio = {
				timescale,
				channels: view.getUint16( mp4a.start + 16 ),
				sampleSize: view.getUint16( mp4a.start + 18 ),
				sampleRate: view.getUint32( mp4a.start + 24 ) >>> 16,
				description: esds ? parseEsds( view, esds.start, esds.end ) : null,
				samples,
			};
		}
	} );

	if ( ! result.video ) {
		throw new Error( 'ocs_mp4_no_video' );
	}

	return result;
}

/* -------------------------------------------------------------------- mux */

/**
 * Assemble one box.
 *
 * @param {string} type  Four-character type.
 * @param {...(Uint8Array|Array)} parts Payload pieces.
 * @return {Uint8Array}
 */
function box( type, ...parts ) {
	const payload = [];
	let length = 0;

	const push = ( part ) => {
		if ( Array.isArray( part ) ) {
			part.forEach( push );
			return;
		}
		payload.push( part );
		length += part.byteLength;
	};

	parts.forEach( push );

	const out = new Uint8Array( 8 + length );
	new DataView( out.buffer ).setUint32( 0, out.byteLength );
	out.set( fourcc( type ), 4 );

	let offset = 8;
	for ( const part of payload ) {
		out.set( part, offset );
		offset += part.byteLength;
	}

	return out;
}

/**
 * A full box: version and flags, then the payload.
 *
 * @param {string} type    Four-character type.
 * @param {number} version Version byte.
 * @param {number} flags   24-bit flags.
 * @param {...(Uint8Array|Array)} parts Payload pieces.
 * @return {Uint8Array}
 */
function fullBox( type, version, flags, ...parts ) {
	return box( type, u8( version, ( flags >> 16 ) & 0xff, ( flags >> 8 ) & 0xff, flags & 0xff ), ...parts );
}

function u8( ...bytes ) {
	return new Uint8Array( bytes );
}

function u16( value ) {
	const out = new Uint8Array( 2 );
	new DataView( out.buffer ).setUint16( 0, value );
	return out;
}

function u32( value ) {
	const out = new Uint8Array( 4 );
	new DataView( out.buffer ).setUint32( 0, value >>> 0 );
	return out;
}

function i32( value ) {
	const out = new Uint8Array( 4 );
	new DataView( out.buffer ).setInt32( 0, value );
	return out;
}

function i16( value ) {
	const out = new Uint8Array( 2 );
	new DataView( out.buffer ).setInt16( 0, value );
	return out;
}

/**
 * A table box: entry count then the rows.
 *
 * @param {string} type    Box type.
 * @param {Array<Uint8Array>} rows Encoded rows.
 * @return {Uint8Array}
 */
function table( type, rows ) {
	return fullBox( type, 0, 0, u32( rows.length ), rows );
}

/**
 * Encode the descriptor length used inside esds.
 *
 * @param {number} value Length.
 * @return {Uint8Array}
 */
function descriptorLength( value ) {
	const bytes = [];
	do {
		bytes.unshift( value & 0x7f );
		value >>= 7;
	} while ( value > 0 );

	for ( let i = 0; i < bytes.length - 1; i++ ) {
		bytes[ i ] |= 0x80;
	}

	return new Uint8Array( bytes );
}

/**
 * Rebuild an esds box around a decoder-specific info blob.
 *
 * @param {Uint8Array} config AudioSpecificConfig.
 * @return {Uint8Array}
 */
function esds( config ) {
	const dsiLength = descriptorLength( config.byteLength );
	const dsi = [ u8( 0x05 ), dsiLength, config ];
	const dsiBytes = 1 + dsiLength.byteLength + config.byteLength;

	// SLConfigDescriptor: predefined = MP4.
	const sl = [ u8( 0x06 ), descriptorLength( 1 ), u8( 0x02 ) ];
	const slBytes = 3;

	const dcdBody = 13 + dsiBytes;
	const dcd = [
		u8( 0x04 ),
		descriptorLength( dcdBody ),
		u8( 0x40 ),                     // Object type: AAC.
		u8( 0x15 ),                     // Stream type: audio.
		u8( 0x00, 0x00, 0x00 ),         // Buffer size.
		u32( 0 ),                       // Max bitrate.
		u32( 0 ),                       // Average bitrate.
		...dsi,
	];
	const dcdBytes = 1 + descriptorLength( dcdBody ).byteLength + dcdBody;

	return fullBox(
		'esds',
		0,
		0,
		u8( 0x03 ),
		descriptorLength( 3 + dcdBytes + slBytes ),
		u16( 1 ),                       // ES id.
		u8( 0x00 ),                     // Flags.
		...dcd,
		...sl
	);
}

/**
 * Compress a per-sample list into run-length rows, as stts and ctts want.
 *
 * @param {Array<number>} values One value per sample.
 * @return {Array<{count: number, value: number}>}
 */
function runs( values ) {
	const out = [];

	for ( const value of values ) {
		const last = out[ out.length - 1 ];
		if ( last && last.value === value ) {
			last.count++;
		} else {
			out.push( { count: 1, value } );
		}
	}

	return out;
}

/**
 * Build one trak box.
 *
 * @param {Object} track Track description.
 * @param {number} index 1-based track id.
 * @param {number} movieTimescale Movie timescale.
 * @return {Uint8Array}
 */
function trak( track, index, movieTimescale ) {
	const total = track.samples.reduce( ( sum, sample ) => sum + sample.duration, 0 );
	const movieDuration = Math.round( ( total / track.timescale ) * movieTimescale );

	const tkhd = fullBox(
		'tkhd',
		0,
		7, // Enabled, in movie, in preview.
		u32( 0 ),
		u32( 0 ),
		u32( index ),
		u32( 0 ),
		u32( movieDuration ),
		u32( 0 ),
		u32( 0 ),
		u16( 0 ), // Layer.
		u16( 0 ), // Alternate group.
		u16( track.kind === 'audio' ? 0x0100 : 0 ), // Volume.
		u16( 0 ), // Reserved.
		// Unity matrix.
		u32( 0x00010000 ), u32( 0 ), u32( 0 ),
		u32( 0 ), u32( 0x00010000 ), u32( 0 ),
		u32( 0 ), u32( 0 ), u32( 0x40000000 ),
		u32( track.kind === 'video' ? track.width * 65536 : 0 ),
		u32( track.kind === 'video' ? track.height * 65536 : 0 )
	);

	const mdhd = fullBox(
		'mdhd',
		0,
		0,
		u32( 0 ),
		u32( 0 ),
		u32( track.timescale ),
		u32( total ),
		u16( 0x55c4 ), // Undetermined language.
		u16( 0 )
	);

	const hdlr = fullBox(
		'hdlr',
		0,
		0,
		u32( 0 ),
		fourcc( track.kind === 'video' ? 'vide' : 'soun' ),
		u32( 0 ), u32( 0 ), u32( 0 ),
		u8( 0 )
	);

	const stsd = track.kind === 'video'
		? fullBox(
			'stsd',
			0,
			0,
			u32( 1 ),
			box(
				'avc1',
				u8( 0, 0, 0, 0, 0, 0 ),
				u16( 1 ),
				u16( 0 ), u16( 0 ),
				u32( 0 ), u32( 0 ), u32( 0 ),
				u16( track.width ),
				u16( track.height ),
				u32( 0x00480000 ), u32( 0x00480000 ),
				u32( 0 ),
				u16( 1 ),
				new Uint8Array( 32 ),
				u16( 0x0018 ),
				i16( -1 ),
				box( 'avcC', track.description )
			)
		)
		: fullBox(
			'stsd',
			0,
			0,
			u32( 1 ),
			box(
				'mp4a',
				u8( 0, 0, 0, 0, 0, 0 ),
				u16( 1 ),
				u32( 0 ), u32( 0 ),
				u16( track.channels ),
				u16( track.sampleSize || 16 ),
				u16( 0 ),
				u16( 0 ),
				u32( track.sampleRate * 65536 ),
				track.description ? esds( track.description ) : new Uint8Array( 0 )
			)
		);

	const stts = table(
		'stts',
		runs( track.samples.map( ( sample ) => sample.duration ) ).map(
			( run ) => new Uint8Array( [ ...u32( run.count ), ...u32( run.value ) ] )
		)
	);

	const compositions = track.samples.map( ( sample ) => sample.cts - sample.dts );
	const needsCtts = compositions.some( ( value ) => value !== 0 );
	const ctts = needsCtts
		? table(
			'ctts',
			runs( compositions ).map(
				( run ) => new Uint8Array( [ ...u32( run.count ), ...i32( run.value ) ] )
			)
		)
		: null;

	const syncIndexes = track.samples
		.map( ( sample, i ) => ( sample.sync ? i + 1 : 0 ) )
		.filter( Boolean );
	const stss = track.kind === 'video' && syncIndexes.length !== track.samples.length
		? table( 'stss', syncIndexes.map( ( value ) => u32( value ) ) )
		: null;

	// One sample per chunk keeps stsc to a single row and the offset maths
	// trivial. The extra bytes in stco are worth not getting this wrong.
	const stsc = table( 'stsc', [ new Uint8Array( [ ...u32( 1 ), ...u32( 1 ), ...u32( 1 ) ] ) ] );
	const stsz = fullBox( 'stsz', 0, 0, u32( 0 ), u32( track.samples.length ), track.samples.map( ( sample ) => u32( sample.size ) ) );
	const stco = table( 'stco', track.samples.map( ( sample ) => u32( sample.outOffset || 0 ) ) );

	const stbl = box( 'stbl', stsd, stts, ctts || [], stss || [], stsc, stsz, stco );

	const minf = box(
		'minf',
		track.kind === 'video'
			? box( 'vmhd', u8( 0, 0, 0, 1 ), u16( 0 ), u16( 0 ), u16( 0 ), u16( 0 ) )
			: box( 'smhd', u8( 0, 0, 0, 0 ), u16( 0 ), u16( 0 ) ),
		box( 'dinf', fullBox( 'dref', 0, 0, u32( 1 ), fullBox( 'url ', 0, 1 ) ) ),
		stbl
	);

	return box( 'trak', tkhd, box( 'mdia', mdhd, hdlr, minf ) );
}

/**
 * Write a progressive MP4 with the index first.
 *
 * @param {Object} options            Tracks and their samples.
 * @param {Object} options.video      { width, height, timescale, description, samples }
 * @param {?Object} options.audio     Same shape, or null.
 * @return {Blob} The finished file.
 */
export function mux( { video, audio } ) {
	const movieTimescale = 1000;
	const tracks = [ { ...video, kind: 'video' } ];
	if ( audio && audio.samples.length ) {
		tracks.push( { ...audio, kind: 'audio' } );
	}

	// Interleave by presentation time so playback can start before the file is
	// fully buffered. Without this the reader has to seek back and forth.
	const timeline = [];
	tracks.forEach( ( track, trackIndex ) => {
		track.samples.forEach( ( sample, sampleIndex ) => {
			timeline.push( {
				trackIndex,
				sampleIndex,
				time: sample.dts / track.timescale,
			} );
		} );
	} );
	timeline.sort( ( a, b ) => a.time - b.time || a.trackIndex - b.trackIndex );

	const build = () => {
		const longest = Math.max(
			...tracks.map( ( track ) => track.samples.reduce( ( sum, s ) => sum + s.duration, 0 ) / track.timescale )
		);

		const mvhd = fullBox(
			'mvhd',
			0,
			0,
			u32( 0 ),
			u32( 0 ),
			u32( movieTimescale ),
			u32( Math.round( longest * movieTimescale ) ),
			u32( 0x00010000 ),
			u16( 0x0100 ),
			u16( 0 ),
			u32( 0 ), u32( 0 ),
			u32( 0x00010000 ), u32( 0 ), u32( 0 ),
			u32( 0 ), u32( 0x00010000 ), u32( 0 ),
			u32( 0 ), u32( 0 ), u32( 0x40000000 ),
			u32( 0 ), u32( 0 ), u32( 0 ), u32( 0 ), u32( 0 ), u32( 0 ),
			u32( tracks.length + 1 )
		);

		return box( 'moov', mvhd, ...tracks.map( ( track, i ) => trak( track, i + 1, movieTimescale ) ) );
	};

	const ftyp = box( 'ftyp', fourcc( 'isom' ), u32( 0x200 ), fourcc( 'isom' ), fourcc( 'iso2' ), fourcc( 'avc1' ), fourcc( 'mp41' ) );

	// Pass one: build the index with placeholder offsets, purely to learn how
	// long it is. The entry count cannot change between passes, so neither can
	// its length — which is what makes the second pass exact.
	const provisional = build();
	const mdatStart = ftyp.byteLength + provisional.byteLength + 8;

	let running = 0;
	for ( const entry of timeline ) {
		const sample = tracks[ entry.trackIndex ].samples[ entry.sampleIndex ];
		sample.outOffset = mdatStart + running;
		running += sample.size;
	}

	const moov = build();

	if ( moov.byteLength !== provisional.byteLength ) {
		// Would mean the offsets changed the index length, which the fixed-width
		// tables rule out. Fail loudly rather than write a broken file.
		throw new Error( 'ocs_mp4_index_unstable' );
	}

	const mdatHeader = new Uint8Array( 8 );
	new DataView( mdatHeader.buffer ).setUint32( 0, running + 8 );
	mdatHeader.set( fourcc( 'mdat' ), 4 );

	const parts = [ ftyp, moov, mdatHeader ];
	for ( const entry of timeline ) {
		parts.push( tracks[ entry.trackIndex ].samples[ entry.sampleIndex ].data );
	}

	return new Blob( parts, { type: 'video/mp4' } );
}
