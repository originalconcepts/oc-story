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
require OCS_PATH . 'includes/Model/Placement.php';
require OCS_PATH . 'includes/Media/Probe.php';
require OCS_PATH . 'includes/Media/ChunkedUpload.php';
require OCS_PATH . 'includes/Media/Poster.php';

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

$cta = $n( array( array( 'ref' => '10', 'cta' => array( 'text' => '<b>Shop</b>', 'url' => 'javascript:alert(1)' ) ) ) );
check( 'strips tags from cta text', $cta[0]['cta']['text'] === 'Shop' );
check( 'rejects a non-url cta link', $cta[0]['cta']['url'] === '' );

echo "\nPlacement sanitising\n";
$p = \OCS\Model\Placement::sanitize( array(
	'id'      => 'PL_ABC!!',
	'surface' => 'hologram',
	'where'   => array( 'scope' => 'moon', 'ids' => '4, 9, 9, x' ),
	'desktop' => array( 'size' => 400, 'labels' => 'yes' ),
	'mobile'  => array( 'size' => 10, 'align' => 'sideways' ),
	'stories' => array( 'mode' => 'random' ),
) );
check( 'lowercases and strips the id', $p['id'] === 'pl_abc' );
check( 'falls back to a known surface', $p['surface'] === 'circles' );
check( 'falls back to a known scope', $p['where']['scope'] === 'home' );
check( 'parses a comma-separated id list', $p['where']['ids'] === array( 4, 9 ) );
check( 'clamps an oversized circle', $p['desktop']['size'] === 160 );
check( 'clamps an undersized circle', $p['mobile']['size'] === 40 );
check( 'coerces a checkbox string', $p['desktop']['labels'] === true );
check( 'falls back to a known alignment', $p['mobile']['align'] === 'start' );
check( 'falls back to a known story mode', $p['stories']['mode'] === 'all' );

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
check( 'the position list is short enough to choose from', count( $hooks ) <= 12 );

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

echo "\n" . ( $fail ? "FAILED: $fail" : "All $pass checks passed" ) . "\n";
exit( $fail ? 1 : 0 );
