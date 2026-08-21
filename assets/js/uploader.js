/**
 * Resumable chunked upload client.
 *
 * Two things this exists to survive. The first is `upload_max_filesize`: the
 * server tells us how big a request it will take, and we never send a bigger
 * one. The second is the phone: a mobile connection drops mid-upload, and
 * retrying two megabytes is recoverable where retrying the whole video is not.
 *
 * Studio only.
 */

const RETRIES = 4;
const CONCURRENCY = 3;

/**
 * Sleep.
 *
 * @param {number} ms Milliseconds.
 * @return {Promise<void>}
 */
function wait( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * One REST call.
 *
 * @param {Object} api  { root, nonce }
 * @param {string} path Route below the namespace.
 * @param {Object} init Fetch options.
 * @return {Promise<Object>}
 */
async function call( api, path, init = {} ) {
	// The same uploader serves two doors. The admin one is opened with a
	// nonce and a cookie; the share one with a token in the query and a
	// device secret in a header, and never a cookie. `api` carries whichever
	// of those this caller has, and nothing here needs to know which.
	const url = api.root.replace( /\/$/, '' ) + ( api.base || '/admin/upload' ) + path
		+ ( api.query ? ( path.includes( '?' ) ? '&' : '?' ) + api.query : '' );

	const response = await fetch( url, {
		credentials: api.nonce ? 'same-origin' : 'omit',
		...init,
		headers: {
			...( api.nonce ? { 'X-WP-Nonce': api.nonce } : {} ),
			...( api.headers || {} ),
			...( init.headers || {} ),
		},
	} );

	let body = null;
	try {
		body = await response.json();
	} catch ( e ) {
		body = null;
	}

	if ( ! response.ok ) {
		const error = new Error( ( body && body.message ) || 'ocs_request_failed' );
		error.code = body && body.code;
		error.status = response.status;
		error.data = body && body.data;
		throw error;
	}

	return body;
}

/**
 * What this server will accept, and what to encode to.
 *
 * @param {Object} api { root, nonce }
 * @return {Promise<Object>}
 */
export function limits( api ) {
	return call( api, '/limits' );
}

/**
 * Send one chunk, retrying a failure that is worth retrying.
 *
 * A 4xx means the chunk itself is wrong and sending it again will fail the same
 * way; anything else is the network, and the network is why we are here.
 *
 * @param {Object} api     { root, nonce }
 * @param {string} session Session id.
 * @param {number} index   Chunk index.
 * @param {Blob}   slice   Chunk bytes.
 * @return {Promise<Object>}
 */
async function sendChunk( api, session, index, slice ) {
	let last = null;

	for ( let attempt = 0; attempt < RETRIES; attempt++ ) {
		try {
			return await call(
				api,
				'/chunk?session=' + encodeURIComponent( session ) + '&index=' + index,
				{
					method: 'POST',
					headers: { 'Content-Type': 'application/octet-stream' },
					body: slice,
				}
			);
		} catch ( e ) {
			last = e;

			if ( e.status && e.status >= 400 && e.status < 500 && e.status !== 408 && e.status !== 429 ) {
				throw e;
			}

			await wait( 400 * Math.pow( 2, attempt ) );
		}
	}

	throw last;
}

/**
 * Upload a finished video and its poster.
 *
 * @param {Blob}   blob       The encoded video.
 * @param {Object} options    Upload options.
 * @param {Object} options.api        { root, nonce }
 * @param {string} options.filename   Name to store it under.
 * @param {string} options.poster     Poster as a data: URL.
 * @param {number} options.width      Encoded width.
 * @param {number} options.height     Encoded height.
 * @param {number} options.duration   Seconds.
 * @param {Function} options.onProgress Called with 0..1.
 * @return {Promise<Object>} The completed slide source.
 */
export async function upload( blob, options ) {
	const {
		api,
		filename = 'story.mp4',
		poster = '',
		width = 0,
		height = 0,
		duration = 0,
		onProgress = () => {},
	} = options;

	const session = await call( api, '/init', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( {
			filename,
			mime: blob.type || 'video/mp4',
			size: blob.size,
		} ),
	} );

	const { chunk_size: chunkSize, chunks_total: total } = session;

	let done = 0;
	let next = 0;
	let failure = null;

	const worker = async () => {
		while ( next < total && ! failure ) {
			const index = next++;
			const start = index * chunkSize;
			const slice = blob.slice( start, Math.min( start + chunkSize, blob.size ) );

			try {
				await sendChunk( api, session.session, index, slice );
				done++;
				onProgress( done / total );
			} catch ( e ) {
				failure = e;
			}
		}
	};

	await Promise.all(
		Array.from( { length: Math.min( CONCURRENCY, total ) }, worker )
	);

	if ( failure ) {
		// Best effort: a failed session expires on its own within six hours, so
		// a failed abort is not worth surfacing over the real error.
		try {
			await call( api, '/abort', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { session: session.session } ),
			} );
		} catch ( e ) {}

		throw failure;
	}

	const result = await call( api, '/complete', {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( {
			session: session.session,
			w: width,
			h: height,
			duration,
			poster,
		} ),
	} );

	onProgress( 1 );

	return result;
}
