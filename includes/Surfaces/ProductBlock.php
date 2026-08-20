<?php
/**
 * Videos on the product page.
 *
 * @package OC_Story
 */

namespace OCS\Surfaces;

defined( 'ABSPATH' ) || exit;

/**
 * "Videos with this product" — the surface that does the actual selling.
 *
 * A shopper on a product page has already decided what they are interested in;
 * a thirty-second clip of someone using it is the last thing between them and
 * the button. It renders nothing at all when no video tags the product, rather
 * than an empty heading, because an empty section reads as a broken shop.
 */
class ProductBlock extends AbstractSurface {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'product';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function get_label() {
		return __( 'Videos on the product page', 'oc-story' );
	}

	/**
	 * Product pages only.
	 *
	 * @param array $context Request context.
	 * @return bool
	 */
	public function supports( array $context ) {
		return ! empty( $context['is_product'] );
	}

	/**
	 * Render.
	 *
	 * @param array $stories   Stories.
	 * @param array $placement Placement.
	 * @return string
	 */
	public function render( array $stories, array $placement ) {
		$visible = $this->playable( $stories );

		if ( ! $visible ) {
			return '';
		}

		$inline = $this->payload_tag( $visible, $placement['id'] );

		return $this->template(
			'slider.php',
			array(
				'stories'   => $visible,
				'placement' => $placement,
				'inline'    => $inline,
				'src'       => '' === $inline ? $this->payload_src( $visible ) : '',
				'style'     => $this->card_vars( $placement ),
				'autoplay'  => \OCS\Core\Settings::is( 'card_autoplay' ),
				'heading'   => (string) apply_filters( 'ocs_product_block_heading', __( 'See it in action', 'oc-story' ) ),
			)
		);
	}
}
