<?php
/**
 * A link that lets one phone add videos to one gallery.
 *
 * @package OC_Story
 */

namespace OCS\Model;

defined( 'ABSPATH' ) || exit;

/**
 * The shop owner's own videos are on their phone, and the admin is not where
 * anybody wants to be while holding a phone. So: a link they send themselves,
 * open once, and keep.
 *
 * A link in a message is a key anyone who sees the message can copy, so the
 * key alone is not what opens the door:
 *
 *   It is claimable for thirty minutes. Long enough to send it to yourself
 *   and open it; too short for a screenshot in somebody's camera roll to
 *   still matter next week.
 *
 *   The first device to open it is bound to it. From then on a request needs
 *   the link AND a secret that lives only in that phone, so the address on
 *   its own is inert — and anyone who tries is told it is already claimed,
 *   which is how the owner finds out.
 *
 *   It expires on idle. A link nobody has used for its chosen span dies by
 *   itself, which is what protects an old phone in a drawer.
 *
 *   It can only add. Not delete, not edit another gallery, not read a
 *   setting or an order. The worst thing a stolen link can do is give its
 *   owner something to remove.
 *
 * Both secrets are stored hashed, so a database anybody reads hands out
 * nothing that works.
 */
class ShareLink {

	const OPTION = 'ocs_share_links';

	/**
	 * How long a fresh link may be claimed by the first device to open it.
	 */
	const CLAIM_WINDOW = 1800;

	/**
	 * Idle spans a link may be given, in days.
	 *
	 * @var int[]
	 */
	const SPANS = array( 14, 30, 90 );

	/**
	 * Every link, keyed by the gallery it belongs to.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * The link for one gallery, or null.
	 *
	 * @param string $placement_id Gallery id.
	 * @return array|null
	 */
	public static function for_gallery( $placement_id ) {
		$all = self::all();

		return isset( $all[ $placement_id ] ) ? $all[ $placement_id ] : null;
	}

	/**
	 * Make a link, replacing any the gallery already had.
	 *
	 * The plaintext token is returned here and never again — it exists in the
	 * database only as a hash, the way a password does.
	 *
	 * @param string $placement_id Gallery id.
	 * @param int    $days         Idle span in days.
	 * @param bool   $hold         Whether uploads wait for approval.
	 * @return array { token, url, link }
	 */
	public static function create( $placement_id, $days = 30, $hold = false ) {
		$days = in_array( (int) $days, self::SPANS, true ) ? (int) $days : 30;

		$token = str_replace( array( '+', '/', '=' ), '', base64_encode( random_bytes( 24 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$token = substr( $token, 0, 32 );

		$link = array(
			'hash'        => wp_hash( $token ),
			'device'      => '',
			'created'     => time(),
			'claim_until' => time() + self::CLAIM_WINDOW,
			'last_used'   => 0,
			'idle'        => $days * DAY_IN_SECONDS,
			'uses'        => 0,
			'hold'        => (bool) $hold,
		);

		$all                  = self::all();
		$all[ $placement_id ] = $link;

		update_option( self::OPTION, $all, false );

		return array(
			'token' => $token,
			'url'   => self::url( $token ),
			'link'  => self::public_view( $placement_id, $link ),
		);
	}

	/**
	 * Kill a gallery's link.
	 *
	 * @param string $placement_id Gallery id.
	 */
	public static function revoke( $placement_id ) {
		$all = self::all();

		unset( $all[ $placement_id ] );

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Where a link points.
	 *
	 * A query argument on the shop's own address rather than a pretty URL,
	 * because a rewrite rule that needs flushing is a rewrite rule that is
	 * one day missing on somebody's install.
	 *
	 * @param string $token Plaintext token.
	 * @return string
	 */
	public static function url( $token ) {
		return add_query_arg( 'ocs_upload', rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * Find the gallery a token opens, if it still opens one.
	 *
	 * @param string $token  Plaintext token.
	 * @param string $device The device secret this request carries.
	 * @return array|\WP_Error { id, link }
	 */
	public static function resolve( $token, $device = '' ) {
		$token = (string) $token;

		if ( 32 !== strlen( $token ) ) {
			return self::refuse( 'ocs_bad_link' );
		}

		$hash = wp_hash( $token );

		foreach ( self::all() as $placement_id => $link ) {
			if ( ! hash_equals( (string) $link['hash'], $hash ) ) {
				continue;
			}

			if ( self::is_dead( $link ) ) {
				return self::refuse( 'ocs_link_expired', __( 'This link has expired. Make a new one from the galleries screen.', 'oc-story' ) );
			}

			// Claimed by somebody: only that somebody may go on.
			if ( '' !== $link['device'] ) {
				if ( '' === $device || ! hash_equals( (string) $link['device'], wp_hash( (string) $device ) ) ) {
					return self::refuse(
						'ocs_link_claimed',
						__( 'This link is already in use on another device. If that was not you, make a new one — this one stops working the moment you do.', 'oc-story' )
					);
				}
			}

			return array(
				'id'   => (string) $placement_id,
				'link' => $link,
			);
		}

		return self::refuse( 'ocs_bad_link' );
	}

	/**
	 * Bind an unclaimed link to the device asking.
	 *
	 * @param string $token Plaintext token.
	 * @return string|\WP_Error The device secret, to be kept by that device.
	 */
	public static function claim( $token ) {
		$found = self::resolve( $token );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$link = $found['link'];

		if ( '' !== $link['device'] ) {
			return self::refuse( 'ocs_link_claimed' );
		}

		if ( time() > (int) $link['claim_until'] ) {
			return self::refuse(
				'ocs_claim_closed',
				__( 'This link was not opened in time. Make a new one — it takes a moment.', 'oc-story' )
			);
		}

		$secret = str_replace( array( '+', '/', '=' ), '', base64_encode( random_bytes( 24 ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$secret = substr( $secret, 0, 32 );

		$all = self::all();

		$all[ $found['id'] ]['device']    = wp_hash( $secret );
		$all[ $found['id'] ]['last_used'] = time();

		update_option( self::OPTION, $all, false );

		return $secret;
	}

	/**
	 * Record that a link was used, and for what.
	 *
	 * @param string $placement_id Gallery id.
	 * @param bool   $added        Whether a video was added.
	 */
	public static function touch( $placement_id, $added = false ) {
		$all = self::all();

		if ( ! isset( $all[ $placement_id ] ) ) {
			return;
		}

		$all[ $placement_id ]['last_used'] = time();

		if ( $added ) {
			++$all[ $placement_id ]['uses'];
		}

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Whether a link has run out of time.
	 *
	 * The idle clock starts at the last use, or at creation for one that has
	 * never been used — otherwise a link made and forgotten would live for
	 * ever.
	 *
	 * @param array $link Link.
	 * @return bool
	 */
	public static function is_dead( array $link ) {
		$since = (int) $link['last_used'] ? (int) $link['last_used'] : (int) $link['created'];

		return time() > $since + (int) $link['idle'];
	}

	/**
	 * What the galleries screen may know about a link.
	 *
	 * Never the token, and never either hash.
	 *
	 * @param string $placement_id Gallery id.
	 * @param array  $link         Link.
	 * @return array
	 */
	public static function public_view( $placement_id, array $link ) {
		$since = (int) $link['last_used'] ? (int) $link['last_used'] : (int) $link['created'];

		return array(
			'gallery'   => (string) $placement_id,
			'created'   => (int) $link['created'],
			'lastUsed'  => (int) $link['last_used'],
			'claimed'   => '' !== $link['device'],
			'claimable' => '' === $link['device'] && time() <= (int) $link['claim_until'],
			'expires'   => $since + (int) $link['idle'],
			'days'      => (int) round( (int) $link['idle'] / DAY_IN_SECONDS ),
			'uses'      => (int) $link['uses'],
			'hold'      => ! empty( $link['hold'] ),
		);
	}

	/**
	 * One shape for every refusal.
	 *
	 * Every reason answers 403 and none of them say which link exists, so a
	 * wrong token learns nothing from being wrong.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message, or '' for the plain one.
	 * @return \WP_Error
	 */
	protected static function refuse( $code, $message = '' ) {
		return new \WP_Error(
			$code,
			'' !== $message ? $message : __( 'This link does not work any more.', 'oc-story' ),
			array( 'status' => 403 )
		);
	}
}
