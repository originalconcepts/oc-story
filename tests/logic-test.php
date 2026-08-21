<?php
/**
 * Minimal harness: exercises the pure logic without a WordPress install.
 *
 * What is worth pinning down at this stage is the two places where bad input
 * becomes stored data — slide normalisation and placement sanitising — plus the
 * placement routing rules, which decide what renders on every page of the shop.
 */
define( 'ABSPATH', '/tmp/' );
define( 'OCS_PATH', dirname( __DIR__ ) . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'MB_IN_BYTES', 1048576 );

$GLOBALS['ocs_options'] = array();

function __( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return $t; }
function esc_html__( $t, $d = '' ) { return $t; }
function apply_filters( $tag, $value = null, ...$rest ) { return $value; }
function do_action( $tag, ...$rest ) {}
function add_filter() {}
function add_action() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_option( $k, $d = false ) { return $GLOBALS['ocs_options'][ $k ] ?? $d; }
function add_option( $k, $v, $a = '', $b = '' ) { $GLOBALS['ocs_options'][ $k ] = $v; return true; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ocs_options'][ $k ] = $v; return true; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_strip_all_tags( $t ) { return trim( strip_tags( (string) $t ) ); }
function sanitize_text_field( $t ) { return trim( strip_tags( (string) $t ) ); }
function esc_url_raw( $u ) {
	$u = (string) $u;
	if ( '' === $u ) { return ''; }
	$scheme = strtolower( (string) parse_url( $u, PHP_URL_SCHEME ) );
	if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https', 'mailto', 'tel' ), true ) ) { return ''; }
	return $u;
}

require OCS_PATH . 'includes/Core/Settings.php';
require OCS_PATH . 'includes/Core/Features.php';
require OCS_PATH . 'includes/Model/Story.php';
require OCS_PATH . 'includes/Model/Positions.php';
require OCS_PATH . 'includes/Model/Placement.php';
require OCS_PATH . 'includes/Media/Probe.php';
require OCS_PATH . 'includes/Media/ChunkedUpload.php';
require OCS_PATH . 'includes/Media/Poster.php';
require OCS_PATH . 'includes/Surfaces/SurfaceInterface.php';
require OCS_PATH . 'includes/Surfaces/AbstractSurface.php';
require OCS_PATH . 'includes/Surfaces/Circles.php';
require OCS_PATH . 'includes/Surfaces/Slider.php';
require OCS_PATH . 'includes/Surfaces/Grid.php';
require OCS_PATH . 'includes/Surfaces/Floating.php';
require OCS_PATH . 'includes/Surfaces/ProductBlock.php';
require OCS_PATH . 'includes/Surfaces/SurfaceManager.php';
require OCS_PATH . 'includes/Model/Stats.php';
require OCS_PATH . 'includes/Model/Attribution.php';

$pass = 0; $fail = 0;
function check( $label, $condition ) {
	global $pass, $fail;
	if ( $condition ) { $pass++; echo "  ok   $label\n"; }
	else { $fail++; echo "  FAIL $label\n"; }
}

echo "\nSettings\n";
check( 'boolean helper', \OCS\Core\Settings::is( 'analytics_enabled' ) === true );
check( 'integer helper', \OCS\Core\Settings::int( 'max_long_side' ) === 1280 );
check( 'integer helper clamps', \OCS\Core\Settings::int( 'max_long_side', 0, 720 ) === 720 );
\OCS\Core\Settings::update( array( 'max_long_side' => 1920 ) );
check( 'update persists', \OCS\Core\Settings::int( 'max_long_side' ) === 1920 );
\OCS\Core\Settings::update( array( 'not_a_setting' => 'x' ) );
check( 'unknown keys are not stored', \OCS\Core\Settings::get( 'not_a_setting' ) === null );
check( 'unknown key returns the default', \OCS\Core\Settings::get( 'nope', 'fallback' ) === 'fallback' );
\OCS\Core\Settings::update( array( 'max_long_side' => 1280 ) );

echo "\nFeatures\n";
check( 'ungated capability is always available', \OCS\Core\Features::has( 'circles' ) === true );
check( 'gated capability is unlocked during 0.x', \OCS\Core\Features::has( 'attribution' ) === true );
check( 'every pro key has a label', count( array_filter( \OCS\Core\Features::PRO, function ( $k ) {
	return \OCS\Core\Features::label( $k ) !== $k;
} ) ) === count( \OCS\Core\Features::PRO ) );

echo "\nSlide normalisation\n";
$n = '\OCS\Model\Story::normalize_slides';

$slides = $n( array(
	array( 'ref' => '10', 'duration' => 8.456, 'w' => 720, 'h' => 1280 ),
	array( 'poster' => 5 ),                              // No ref — unplayable.
	array( 'ref' => '11', 'duration' => -3 ),
) );
check( 'keeps only playable slides', count( $slides ) === 2 );
check( 'assigns a slide id', isset( $slides[0]['id'] ) && 0 === strpos( $slides[0]['id'], 's_' ) );
check( 'slide ids are unique', $slides[0]['id'] !== $slides[1]['id'] );
check( 'rounds duration', abs( $slides[0]['duration'] - 8.46 ) < 0.0001 );
check( 'floors a negative duration', $slides[1]['duration'] === 0.0 );
check( 'defaults the source to local', $slides[0]['source'] === 'local' );

$dupes = $n( array(
	array( 'id' => 's_aaaa1111', 'ref' => '10' ),
	array( 'id' => 's_aaaa1111', 'ref' => '11' ),
) );
check( 'reassigns a duplicated slide id', $dupes[0]['id'] !== $dupes[1]['id'] );

$tagged = $n( array(
	array(
		'ref'      => '10',
		'products' => array(
			array( 'id' => 812, 'x' => 0.42, 'y' => 1.9 ),
			array( 'id' => 812, 'x' => 0.1 ),              // Duplicate.
			array( 'id' => 0 ),                            // Not a product.
			977,                                           // Bare id.
			array( 'id' => 55, 'x' => -4, 'y' => 'abc' ),
		),
	),
) );
$products = $tagged[0]['products'];
check( 'drops duplicate and empty products', count( $products ) === 3 );
check( 'keeps product order', $products[0]['id'] === 812 && $products[1]['id'] === 977 );
check( 'accepts a bare product id', $products[1]['x'] === null );
check( 'clamps a coordinate above one', $products[0]['y'] === 1.0 );
check( 'clamps a coordinate below zero', $products[2]['x'] === 0.0 );
check( 'nulls a non-numeric coordinate', $products[2]['y'] === null );

$typed = $n( array(
	array( 'ref' => '10', 'type' => 'image' ),
	array( 'ref' => '11', 'type' => 'hologram' ),
	array( 'ref' => '12' ),
) );
check( 'keeps an image slide', 'image' === $typed[0]['type'] );
check( 'an unknown type falls back to video', 'video' === $typed[1]['type'] );
check( 'a missing type is video', 'video' === $typed[2]['type'] );

$payload = \OCS\Model\Story::to_payload( array( array(
	'id'     => 5,
	'title'  => 'x',
	'slides' => array(
		array( 'id' => 's_aaaa1111', 'type' => 'image', 'url' => 'https://x/a.jpg', 'poster_url' => 'https://x/p.jpg', 'w' => 720, 'h' => 1280, 'duration' => 5, 'products' => array(), 'cta' => array( 'text' => '', 'url' => '' ) ),
		array( 'id' => 's_bbbb2222', 'type' => 'video', 'url' => 'https://x/a.mp4', 'poster_url' => '', 'w' => 720, 'h' => 1280, 'duration' => 9, 'products' => array(), 'cta' => array( 'text' => '', 'url' => '' ) ),
	),
) ) );
check( 'payload marks an image slide', 'i' === $payload[0]['s'][0]['ty'] );
check( 'payload marks a video slide', 'v' === $payload[0]['s'][1]['ty'] );

$cta = $n( array( array( 'ref' => '10', 'cta' => array( 'text' => '<b>Shop</b>', 'url' => 'javascript:alert(1)' ) ) ) );
check( 'strips tags from cta text', $cta[0]['cta']['text'] === 'Shop' );
check( 'rejects a non-url cta link', $cta[0]['cta']['url'] === '' );

echo "\nThe wizard's answers, resolved\n";
$w = function ( array $raw ) {
	return \OCS\Model\Placement::sanitize( array_merge( array( 'id' => 'pl_1' ), $raw ) );
};

$auto = $w( array( 'surface' => 'circles', 'target' => 'product', 'position' => 'before_cart' ) );
check( 'a product gallery with no products chosen is the tagged scope', 'tagged' === $auto['where']['scope'] );
check( 'before-cart resolves to the summary hook', 'woocommerce_single_product_summary' === $auto['hook'] );
check( 'before-cart sits above the button', 25 === $auto['priority'] );

$named = $w( array( 'surface' => 'circles', 'target' => 'product', 'position' => 'after_cart', 'where' => array( 'ids' => array( 4, 9 ) ) ) );
check( 'naming products narrows the scope', 'products' === $named['where']['scope'] );
check( 'after-cart sits below the button', 35 === $named['priority'] );

$cat = $w( array( 'surface' => 'slider', 'target' => 'category', 'position' => 'below_products', 'where' => array( 'ids' => array( 12 ) ) ) );
check( 'a category gallery uses the term scope', 'terms' === $cat['where']['scope'] );
check( 'below-products resolves to the loop hook', 'woocommerce_after_shop_loop' === $cat['hook'] );

$every = $w( array( 'surface' => 'circles', 'target' => 'site', 'position' => 'above_content' ) );
check( 'every page of the shop is the site scope', 'site' === $every['where']['scope'] );
check( 'above content is the auto ladder', 'auto' === $every['hook'] );

$mine = $w( array( 'surface' => 'slider', 'target' => 'custom', 'position' => 'custom' ) );
check( 'placing it myself hooks nothing', 'manual' === $mine['hook'] );

$gone = $w( array( 'surface' => 'circles', 'target' => 'home', 'position' => 'below_products' ) );
check( 'a position this branch does not offer falls to one it does', 'above_content' === $gone['position'] );

// Every widget made before the wizard existed still has to route exactly as
// it did — this is the whole reason the position is optional.
$legacy = $w( array( 'surface' => 'circles', 'where' => array( 'scope' => 'site' ), 'hook' => 'wp_body_open', 'priority' => 7 ) );
check( 'a widget from before the wizard keeps its hook', 'wp_body_open' === $legacy['hook'] );
check( 'and its priority', 7 === $legacy['priority'] );
check( 'and its scope', 'site' === $legacy['where']['scope'] );
check( 'and is shown as the target it always was', 'site' === $legacy['target'] );

$old_product = $w( array( 'surface' => 'circles', 'where' => array( 'scope' => 'tagged' ), 'hook' => 'auto' ) );
check( 'a tagged widget reads back as a product gallery', 'product' === $old_product['target'] );

// Both of the galleries on the live demo route through hook 'auto' at 15 and
// carry no position. Without reading one back, they open in the wizard with
// step two unanswered and step three locked.
$pre = $w( array( 'surface' => 'circles', 'where' => array( 'scope' => 'site' ), 'hook' => 'auto', 'priority' => 15 ) );
check( 'a pre-wizard widget is shown the spot it already uses', 'above_content' === $pre['position'] );
check( 'and routing is untouched by saying so', 'auto' === $pre['hook'] && 15 === $pre['priority'] );

$odd = $w( array( 'surface' => 'circles', 'where' => array( 'scope' => 'site' ), 'hook' => 'wp_body_open', 'priority' => 7 ) );
check( 'a hook no position names keeps an empty position', '' === $odd['position'] );
check( 'and keeps its own hook', 'wp_body_open' === $odd['hook'] && 7 === $odd['priority'] );

$cards_pre = $w( array( 'surface' => 'slider', 'where' => array( 'scope' => 'pages', 'ids' => array( 597 ) ), 'hook' => 'auto', 'priority' => 15 ) );
check( 'and a slider on one page reads back the same way', 'above_content' === $cards_pre['position'] );

check( 'circles are a story', 'story' === \OCS\Model\Positions::type_of( 'circles' ) );
check( 'a wall is cards', 'cards' === \OCS\Model\Positions::type_of( 'grid' ) );
check( 'the product branch offers four spots', 4 === count( \OCS\Model\Positions::offered( 'story', 'product' ) ) );
check( 'every branch ends in the shortcode', isset( \OCS\Model\Positions::offered( 'cards', 'category' )['custom'] ) );
check( 'the summary hooks are flagged as theme-dependent', \OCS\Model\Positions::needs_theme_support( 'before_cart' ) );
check( 'the auto ladder is not', ! \OCS\Model\Positions::needs_theme_support( 'above_content' ) );

// The surface list and the surface registry have to agree, and a mismatch is
// silent: an unknown surface is rewritten to circles on save, which would
// route a corner video into the top of the page.
check( 'the corner is a surface a placement may name', in_array( 'floating', \OCS\Model\Placement::surfaces(), true ) );
$corner = $w( array( 'surface' => 'floating', 'target' => 'product', 'position' => 'side_end' ) );
check( 'a corner keeps the surface it was given', 'floating' === $corner['surface'] );
check( 'a floating video is its own type', 'floating' === \OCS\Model\Positions::type_of( 'floating' ) );
check( 'and prints itself late rather than into the document', 'ocs_floating' === $corner['hook'] );
check( 'a corner offers only two sides', 2 === count( \OCS\Model\Positions::offered( 'floating', 'home' ) ) );
check( 'a corner clears a phone cart bar by default', 86 === $corner['mobile']['offset'] );
check( 'and sits close to the edge on a desktop', 24 === $corner['desktop']['offset'] );

// The wizard's own reading of "which videos", checked where it is stored
// rather than where it is typed: automatic is a rule, everything else a list.
$auto_mode = $w( array( 'surface' => 'circles', 'target' => 'product', 'position' => 'before_cart', 'stories' => array( 'mode' => 'tagged', 'ids' => array( 7, 8 ) ) ) );
check( 'an automatic gallery keeps the tagged rule', 'tagged' === $auto_mode['stories']['mode'] );
check( 'and still remembers which videos are its own', array( 7, 8 ) === $auto_mode['stories']['ids'] );

// The three places a gallery can be placed by hand all read the same field
// off the same object, and all three used to show a draft.
$draft_pl = $w( array( 'surface' => 'circles', 'target' => 'custom', 'position' => 'custom', 'enabled' => false ) );
check( 'a hand-placed gallery can be a draft', false === $draft_pl['enabled'] );
check( 'and a hand-placed gallery hooks nothing', 'manual' === $draft_pl['hook'] );

echo "\nPlacement sanitising\n";
$p = \OCS\Model\Placement::sanitize( array(
	'id'      => 'PL_ABC!!',
	'surface' => 'hologram',
	'where'   => array( 'scope' => 'moon', 'ids' => '4, 9, 9, x' ),
	'desktop' => array( 'size' => 900, 'labels' => 'yes' ),
	'mobile'  => array( 'size' => 10, 'align' => 'sideways' ),
	'stories' => array( 'mode' => 'random' ),
) );
check( 'lowercases and strips the id', $p['id'] === 'pl_abc' );
check( 'falls back to a known surface', $p['surface'] === 'circles' );
check( 'falls back to a known scope', $p['where']['scope'] === 'home' );
check( 'parses a comma-separated id list', $p['where']['ids'] === array( 4, 9 ) );
check( 'clamps an oversized size', $p['desktop']['size'] === 400 );
check( 'clamps an undersized circle', $p['mobile']['size'] === 40 );
check( 'coerces a checkbox string', $p['desktop']['labels'] === true );
check( 'falls back to a known alignment', $p['mobile']['align'] === 'start' );
check( 'falls back to a known story mode', $p['stories']['mode'] === 'all' );

$pc = \OCS\Model\Placement::sanitize( array(
	'id'      => 'pl_c',
	'stories' => array( 'mode' => 'collection', 'collection' => '<b>משפיענים</b>' ),
) );
check( 'keeps the collection mode', 'collection' === $pc['stories']['mode'] );
check( 'strips tags from the collection name', 'משפיענים' === $pc['stories']['collection'] );
check( 'grid is an accepted surface', 'grid' === \OCS\Model\Placement::sanitize( array( 'id' => 'pl_g', 'surface' => 'grid' ) )['surface'] );

echo "\nTemplate whitespace\n";
// The exact shape a template emits, and what wpautop would have done to it.
$markup = "<div class=\"ocs-bar\">\n\t<span class=\"ring\">\n\t\t<img src=\"a.webp\" />\n\t</span>\n\t<span class=\"label\">שם עם רווח</span>\n</div>";
$collapsed = preg_replace( '/>\s+</', '><', $markup );
check( 'no newline survives between tags', false === strpos( $collapsed, ">\n" ) );
check( 'nothing is left for wpautop to turn into a br', 0 === preg_match( '/>\s+</', $collapsed ) );
check( 'text inside a tag keeps its spaces', false !== strpos( $collapsed, 'שם עם רווח' ) );
check( 'every tag survives', substr_count( $collapsed, '<' ) === substr_count( $markup, '<' ) );

echo "\nSurfaces\n";
$surfaces = \OCS\Surfaces\SurfaceManager::all();
check( 'every surface the plugin ships is registered', count( $surfaces ) === count( \OCS\Model\Placement::SURFACES ) );

$named = true;
foreach ( $surfaces as $id => $surface ) {
	if ( $id !== $surface->get_id() || '' === $surface->get_label() ) { $named = false; }
}
check( 'every surface knows its own id and has a label', $named );

check(
	'the product block only offers itself on a product page',
	false === $surfaces['product']->supports( array( 'is_product' => false ) )
		&& true === $surfaces['product']->supports( array( 'is_product' => true ) )
);
check( 'the slider goes anywhere', true === $surfaces['slider']->supports( array() ) );
check(
	'placement validation accepts exactly the registered surfaces',
	\OCS\Model\Placement::SURFACES === \OCS\Surfaces\SurfaceManager::ids()
);

echo "\nPlacement choices\n";
$scopes = \OCS\Model\Placement::scopes();
$labelled = true;
foreach ( \OCS\Model\Placement::SCOPES as $scope ) {
	if ( empty( $scopes[ $scope ] ) ) { $labelled = false; }
}
check( 'every scope is offered in words', $labelled );
check( 'no scope is offered that the engine cannot route', count( $scopes ) === count( \OCS\Model\Placement::SCOPES ) );

$hooks = \OCS\Model\Placement::hooks();
check( 'manual placement is offered', isset( $hooks['manual'] ) );
check( 'automatic placement is offered', isset( $hooks['auto'] ) );
check( 'automatic placement is offered first', 'auto' === array_key_first( $hooks ) );
check( 'a new placement defaults to automatic', 'auto' === \OCS\Model\Placement::defaults()['hook'] );
check( 'sanitising keeps the automatic position', 'auto' === \OCS\Model\Placement::sanitize( array( 'id' => 'pl_x', 'hook' => 'auto' ) )['hook'] );
check( 'the position list is short enough to choose from', count( $hooks ) <= 13 );

echo "\nPlacement routing\n";
$m = '\OCS\Model\Placement::matches';
$home    = array( 'is_front' => true, 'post_id' => 2 );
$product = array( 'is_product' => true, 'post_id' => 812, 'product_id' => 812, 'term_ids' => array( 31, 44 ) );
$page    = array( 'post_id' => 99 );

check( 'site scope matches everywhere', $m( array( 'scope' => 'site' ), $page ) === true );
check( 'home scope matches the front page', $m( array( 'scope' => 'home' ), $home ) === true );
check( 'home scope skips other pages', $m( array( 'scope' => 'home' ), $page ) === false );
check( 'pages scope matches a listed page', $m( array( 'scope' => 'pages', 'ids' => array( 99 ) ), $page ) === true );
check( 'pages scope skips an unlisted page', $m( array( 'scope' => 'pages', 'ids' => array( 7 ) ), $page ) === false );
check( 'products scope matches any product', $m( array( 'scope' => 'products' ), $product ) === true );
check( 'products scope skips a non-product', $m( array( 'scope' => 'products' ), $page ) === false );
check( 'products scope honours an id list', $m( array( 'scope' => 'products', 'ids' => array( 5 ) ), $product ) === false );
check( 'terms scope matches an intersecting term', $m( array( 'scope' => 'terms', 'ids' => array( 44 ) ), $product ) === true );
check( 'terms scope skips a non-intersecting term', $m( array( 'scope' => 'terms', 'ids' => array( 12 ) ), $product ) === false );
check( 'tagged scope needs a product page', $m( array( 'scope' => 'tagged' ), $product ) === true );
check( 'tagged scope skips a page', $m( array( 'scope' => 'tagged' ), $page ) === false );
check( 'exclusion beats a site-wide scope', $m( array( 'scope' => 'site', 'exclude' => array( 99 ) ), $page ) === false );
check( 'exclusion beats a product match', $m( array( 'scope' => 'products', 'exclude' => array( 812 ) ), $product ) === false );
check( 'an unknown scope matches nothing', $m( array( 'scope' => 'sideways' ), $page ) === false );

echo "\nStory fields\n";
check( 'strips tags from a title', \OCS\Model\Story::clean_title( '<b>קצביית דוד</b>' ) === 'קצביית דוד' );
check( 'caps a title at 60 characters', mb_strlen( \OCS\Model\Story::clean_title( str_repeat( 'a', 200 ) ) ) === 60 );
check( 'publish stays publish', \OCS\Model\Story::clean_status( 'publish' ) === 'publish' );
check( 'anything else is a draft', \OCS\Model\Story::clean_status( 'pending' ) === 'draft' );
check( 'a missing status is a draft', \OCS\Model\Story::clean_status( '' ) === 'draft' );

echo "\nServer limits\n";
$b = '\OCS\Media\Probe::ini_bytes';
check( 'megabytes', $b( '8M' ) === 8388608 );
check( 'kilobytes', $b( '512K' ) === 524288 );
check( 'gigabytes', $b( '2G' ) === 2147483648 );
check( 'bare bytes', $b( '128' ) === 128 );
check( 'unlimited reads as no opinion', $b( '-1' ) === 0 );
check( 'empty reads as no opinion', $b( '' ) === 0 );
check( 'nonsense reads as no opinion', $b( 'lots' ) === 0 );

echo "\nChunk sizing\n";
$c = '\OCS\Media\Probe::chunk_size_for';
check( 'a generous server keeps the preferred size', $c( 8388608, 2097152 ) === 2097152 );
check( 'a tight server drops below its own limit', $c( 2097152, 2097152 ) === 1677721 );
check( 'a very tight server drops further', $c( 1048576, 2097152 ) === 838860 );
check( 'an unlimited server keeps the preferred size', $c( 0, 2097152 ) === 2097152 );
check( 'never goes below 256KB', $c( 262144, 2097152 ) === 262144 );
check( 'never goes above 8MB', $c( 0, 99999999 ) === 8388608 );
check( 'a missing preference falls back', $c( 0, 0 ) === 2097152 );

echo "\nChunk accounting\n";
$e = '\OCS\Media\ChunkedUpload::expected_chunk_length';
check( 'a full chunk', $e( 0, 1000, 2500 ) === 1000 );
check( 'the remainder in the last chunk', $e( 2, 1000, 2500 ) === 500 );
check( 'an exact fit has no short chunk', $e( 0, 1000, 1000 ) === 1000 );
check( 'past the end is nothing', $e( 3, 1000, 2500 ) === 0 );
check( 'a negative index is nothing', $e( -1, 1000, 2500 ) === 0 );
check( 'a zero chunk size is nothing', $e( 0, 0, 2500 ) === 0 );

$m = '\OCS\Media\ChunkedUpload::missing';
check( 'finds the hole', $m( '1101' ) === array( 2 ) );
check( 'a complete map has no holes', $m( '1111' ) === array() );
check( 'an empty map has no holes', $m( '' ) === array() );

echo "\nPoster data URLs\n";
$d = '\OCS\Media\Poster::decode_data_url';
$good = $d( 'data:image/webp;base64,' . base64_encode( 'RIFF' ) );
check( 'decodes a data url', is_array( $good ) && 'RIFF' === $good['bytes'] );
check( 'reads the mime type', is_array( $good ) && 'image/webp' === $good['mime'] );
check( 'rejects a plain url', null === $d( 'https://example.com/a.webp' ) );
check( 'rejects an empty payload', null === $d( 'data:image/webp;base64,' ) );
check( 'rejects invalid base64', null === $d( 'data:image/webp;base64,!!!!' ) );
check( 'rejects an empty string', null === $d( '' ) );

echo "\nEvent beacon normalisation\n";
$surfaces = array( 'circles', 'slider', 'product' );
$n = static function ( $batch ) use ( $surfaces ) {
	return \OCS\Model\Stats::normalize_batch( $batch, $surfaces );
};

$rows = $n( array(
	array( 't' => 'o', 's' => 11, 'f' => 'circles', 'd' => 'm' ),
	array( 't' => 'o', 's' => 11, 'f' => 'circles', 'd' => 'm' ),
	array( 't' => 'p', 's' => 11, 'l' => 's_9f2a3b4c', 'f' => 'circles', 'd' => 'm' ),
) );
check( 'aggregates duplicate events into one row', 2 === count( $rows ) );
$first = array_values( $rows )[0];
check( 'counts them', 2 === $first['counts']['opens'] );

check( 'rejects a non-array body', array() === $n( 'DROP TABLE' ) );
check( 'rejects an unknown event type', array() === $n( array( array( 't' => 'x', 's' => 1 ) ) ) );
check( 'accepts a spark', 1 === count( $n( array( array( 't' => 'k', 's' => 7, 'l' => 's_9f2a3b4c' ) ) ) ) );
check( 'a spark lands in its own counter', 1 === array_values( $n( array( array( 't' => 'k', 's' => 7 ) ) ) )[0]['counts']['sparks'] );
check( 'rejects an unknown surface', array() === $n( array( array( 't' => 'o', 's' => 1, 'f' => 'evil' ) ) ) );
check( 'rejects a malformed slide id', array() === $n( array( array( 't' => 'p', 's' => 1, 'l' => '../../etc' ) ) ) );
check( 'rejects a negative story', array() === $n( array( array( 't' => 'o', 's' => -5 ) ) ) );
check( 'reach may use story zero', 1 === count( $n( array( array( 't' => 'i', 's' => 0, 'f' => 'circles' ) ) ) ) );
check( 'nothing else may use story zero', array() === $n( array( array( 't' => 'o', 's' => 0 ) ) ) );
check( 'an unknown device falls back to desktop', 'd' === array_values( $n( array( array( 't' => 'o', 's' => 1, 'd' => 'tv' ) ) ) )[0]['device'] );

$big = array_fill( 0, 200, array( 't' => 'o', 's' => 7 ) );
check( 'caps a batch at fifty', 50 === array_values( $n( $big ) )[0]['counts']['opens'] );

echo "\nAttribution claims\n";
$now  = 1700000000;
$week = 7 * DAY_IN_SECONDS;
$v = '\OCS\Model\Attribution::validate_claim';
$good = json_encode( array( 'story' => 11, 'slide' => 's_9f2a3b4c', 'product' => 812, 'ts' => ( $now - 3600 ) * 1000 ) );

$claim = $v( $good, 812, 0, $now, $week );
check( 'accepts a fresh matching claim', is_array( $claim ) && 11 === $claim['story'] );
check( 'keeps the timestamp in seconds', is_array( $claim ) && $claim['ts'] === $now - 3600 );
check( 'accepts a variation match', is_array( $v( $good, 999, 812, $now, $week ) ) );
check( 'rejects a claim for a different product', null === $v( $good, 55, 0, $now, $week ) );
check( 'rejects a stale claim', null === $v( $good, 812, 0, $now + $week + 7200, $week ) );
check( 'rejects a claim from the future', null === $v( json_encode( array( 'story' => 11, 'product' => 812, 'ts' => ( $now + 3600 ) * 1000 ) ), 812, 0, $now, $week ) );
check( 'rejects garbage', null === $v( 'not json', 812, 0, $now, $week ) );
check( 'rejects an oversized payload', null === $v( str_repeat( 'a', 300 ), 812, 0, $now, $week ) );
check( 'rejects a malformed slide id', null === $v( json_encode( array( 'story' => 11, 'slide' => 'x', 'product' => 812, 'ts' => ( $now - 60 ) * 1000 ) ), 812, 0, $now, $week ) );
check( 'rejects a missing story', null === $v( json_encode( array( 'product' => 812, 'ts' => ( $now - 60 ) * 1000 ) ), 812, 0, $now, $week ) );

echo "\n" . ( $fail ? "FAILED: $fail" : "All $pass checks passed" ) . "\n";
exit( $fail ? 1 : 0 );
