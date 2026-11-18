<?php
/**
 * End-to-end smoke test. Runs inside WordPress through WP-CLI:
 *
 *   wp theme activate sdi-fixture-theme
 *   wp eval-file wp-content/plugins/saayeh-demo-importer/tests/smoke-test.php
 *
 * It drives the real AJAX handlers (nonce + capability checks included),
 * verifies everything the fixture demo.json describes, re-runs the import to
 * prove idempotency, tests resume, then removes the demo and verifies the
 * site is restored. Exits non-zero on failure.
 *
 * @package SaayehDemoImporter
 */

// phpcs:disable

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

$GLOBALS['sdi_fail'] = 0;
$GLOBALS['sdi_pass'] = 0;

function sdi_t( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['sdi_pass']++;
		WP_CLI::log( "  ✔ $label" );
	} else {
		$GLOBALS['sdi_fail']++;
		WP_CLI::warning( "✘ $label" );
	}
}

class SDI_Test_Die extends Exception {}

add_filter( 'wp_doing_ajax', '__return_true' );
add_filter(
	'wp_die_ajax_handler',
	function () {
		return function () {
			throw new SDI_Test_Die();
		};
	}
);

/** Call an AJAX action and return the decoded JSON. */
function sdi_ajax( $action, $post = array(), $nonce = null ) {
	$_POST = $_REQUEST = array_merge(
		array(
			'action' => $action,
			'nonce'  => null === $nonce ? wp_create_nonce( 'sdi_admin' ) : $nonce,
		),
		$post
	);
	ob_start();
	try {
		do_action( 'wp_ajax_' . $action );
	} catch ( SDI_Test_Die $e ) {
		// Expected: wp_send_json ends with wp_die().
	}
	$out = ob_get_clean();
	return json_decode( $out, true );
}

function sdi_run_import( $theme, $groups, $max = 300 ) {
	$res = sdi_ajax( 'sdi_import_start', array( 'theme' => $theme, 'groups' => $groups ) );
	if ( empty( $res['success'] ) ) {
		return array( 'ok' => false, 'res' => $res, 'log' => array() );
	}
	$log = $res['data']['log'];
	for ( $i = 0; $i < $max; $i++ ) {
		$step = sdi_ajax( 'sdi_import_step' );
		if ( empty( $step['success'] ) ) {
			return array( 'ok' => false, 'res' => $step, 'log' => $log );
		}
		$log = array_merge( $log, $step['data']['log'] );
		if ( ! empty( $step['data']['done'] ) ) {
			return array( 'ok' => true, 'requests' => $i + 1, 'log' => $log );
		}
	}
	return array( 'ok' => false, 'res' => 'too many requests', 'log' => $log );
}

function sdi_count_flagged_total( $theme ) {
	return array_sum( SDI_Util::count_flagged( $theme ) );
}

$theme  = 'sdi-fixture-theme';
$admin  = get_user_by( 'login', 'admin' );
$groups = array_keys( SDI_Importer::option_groups() );

// Pre-state for restore checks.
update_option( 'show_on_front', 'posts' );
$before = array(
	'currency'    => get_option( 'woocommerce_currency' ),
	'cart_page'   => (int) get_option( 'woocommerce_cart_page_id' ),
	'admin_email' => get_option( 'admin_email' ),
	'zones'       => count( WC_Shipping_Zones::get_zones() ),
	'attributes'  => count( wc_get_attribute_taxonomies() ),
);

WP_CLI::log( '== Detection' );
wp_set_current_user( $admin->ID );
$all = SDI_Themes::get_all();
sdi_t( isset( $all[ $theme ] ) && 'active' === $all[ $theme ]['status'], 'fixture theme detected as active' );
sdi_t( isset( $all['saayeh'] ), 'known theme "saayeh" listed (status: ' . ( isset( $all['saayeh'] ) ? $all['saayeh']['status'] : '-' ) . ')' );
$pkg = SDI_Themes::load_package( $all[ $theme ] );
sdi_t( $pkg instanceof SDI_Package, 'package loads' );
$sum = $pkg->summary();
sdi_t( 3 === $sum['products'] && 7 === $sum['pages'] && 2 === $sum['posts'] && 3 === $sum['images'], 'summary counts' );
sdi_t( false === $pkg->resolve( '../../../../../../etc/passwd' ), 'path traversal rejected by resolve()' );
sdi_t( $pkg->requirements_met(), 'requirements met (WooCommerce active)' );

WP_CLI::log( '== Security' );
$bad = sdi_ajax( 'sdi_import_start', array( 'theme' => $theme, 'groups' => $groups ), 'bad-nonce' );
sdi_t( isset( $bad['success'] ) && false === $bad['success'], 'bad nonce refused' );
$sub = wp_insert_user( array( 'user_login' => 'sdi_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
$denied = sdi_ajax( 'sdi_import_start', array( 'theme' => $theme, 'groups' => $groups ) );
sdi_t( isset( $denied['success'] ) && false === $denied['success'], 'subscriber refused' );
wp_set_current_user( $admin->ID );
wp_delete_user( $sub );
$inactive = sdi_ajax( 'sdi_import_start', array( 'theme' => 'twentytwentyfive', 'groups' => $groups ) );
sdi_t( isset( $inactive['success'] ) && false === $inactive['success'], 'non-package/inactive theme refused' );

WP_CLI::log( '== Resume (partial run then resume)' );
$start = sdi_ajax( 'sdi_import_start', array( 'theme' => $theme, 'groups' => $groups ) );
sdi_t( ! empty( $start['success'] ) && count( $start['data']['steps'] ) === 11, 'start returns 11 steps' );
sdi_ajax( 'sdi_import_step' );
$state = SDI_State::get_run();
sdi_t( $state && 'running' === $state['status'], 'state persisted between requests' );
$resume = sdi_ajax( 'sdi_import_start', array( 'theme' => $theme, 'resume' => 1 ) );
sdi_t( ! empty( $resume['success'] ) && $resume['data']['index'] === $state['index'] && $resume['data']['cursor'] === $state['cursor'], 'resume keeps position' );

WP_CLI::log( '== Import (batch size forced to 1 to exercise batching)' );
add_filter( 'sdi_batch_size', function () { return 1; } );
$t0  = microtime( true );
$run = sdi_run_import( $theme, $groups );
sdi_t( $run['ok'], 'import finished (' . ( isset( $run['requests'] ) ? $run['requests'] : '?' ) . ' step requests, ' . round( microtime( true ) - $t0, 1 ) . 's)' );
if ( ! $run['ok'] ) {
	WP_CLI::log( print_r( $run['res'], true ) );
}
$errors = array_filter( $run['log'], function ( $l ) { return 'error' === $l['level']; } );
$warns  = array_map( function ( $l ) { return $l['message']; }, array_filter( $run['log'], function ( $l ) { return 'warning' === $l['level']; } ) );
sdi_t( ! $errors, 'no error log lines' . ( $errors ? ': ' . implode( ' | ', wp_list_pluck( $errors, 'message' ) ) : '' ) );
WP_CLI::log( '  warnings (expected: evil image, missing menu ref, protected option): ' . implode( ' | ', $warns ) );
sdi_t( count( $warns ) === 3, 'exactly 3 expected warnings' );

$img_hero = SDI_Util::find_post( $theme, 'attachment', 'hero' );
$img_coat = SDI_Util::find_post( $theme, 'attachment', 'coat' );
sdi_t( $img_hero && $img_coat && ! SDI_Util::find_post( $theme, 'attachment', 'evil' ), 'images imported, traversal image rejected' );
sdi_t( 'تصویر اصلی' === get_post_meta( $img_hero, '_wp_attachment_image_alt', true ), 'image alt' );
$hero_file = get_attached_file( $img_hero );
sdi_t( $hero_file && file_exists( $hero_file ), 'attachment file exists in uploads' );

$size_attr = wc_attribute_taxonomy_id_by_name( 'size' );
sdi_t( $size_attr > 0 && wc_attribute_taxonomy_id_by_name( 'color' ) > 0, 'global attributes created' );
$caramel = get_term_by( 'slug', 'caramel', 'pa_color' );
sdi_t( $caramel && '#b07a4f' === strtolower( get_term_meta( $caramel->term_id, 'saayeh_color_hex', true ) ), 'color hex term meta' );

$coats = get_term_by( 'slug', 'coats', 'product_cat' );
$wcoats = get_term_by( 'slug', 'winter-coats', 'product_cat' );
sdi_t( $coats && (int) get_term_meta( $coats->term_id, 'thumbnail_id', true ) === $img_coat, 'category thumbnail_id' );
sdi_t( $wcoats && (int) $wcoats->parent === (int) $coats->term_id, 'category parent' );

$coat_id = SDI_Util::find_post( $theme, 'product', 'long-wool-coat' );
$coat    = wc_get_product( $coat_id );
sdi_t( $coat && $coat->is_type( 'variable' ), 'variable product created' );
$children = $coat ? $coat->get_children() : array();
sdi_t( 8 === count( $children ), '8 variations (4 sizes × 2 colors), got ' . count( $children ) );
$oos = 0;
foreach ( $children as $cid ) {
	$v = wc_get_product( $cid );
	if ( 'outofstock' === $v->get_stock_status() ) {
		$oos++;
	}
	$attrs = $v->get_attributes();
	$should = ( 'xl' === $attrs['pa_size'] ) || ( 's' === $attrs['pa_size'] && 'black' === $attrs['pa_color'] );
	if ( $should !== ( 'outofstock' === $v->get_stock_status() ) ) {
		sdi_t( false, 'stock status wrong for ' . wp_json_encode( $attrs ) );
	}
}
sdi_t( 3 === $oos, '3 out-of-stock variations (XL×2 + S/black)' );
$v0 = wc_get_product( $children[0] );
sdi_t( '3990000' === (string) $v0->get_sale_price() && '4850000' === (string) $v0->get_regular_price(), 'variation regular/sale price' );
sdi_t( $coat->is_featured(), 'featured' );
sdi_t( 'جدید' === get_post_meta( $coat_id, '_saayeh_badge', true ), '_saayeh_badge meta' );
sdi_t( (int) $coat->get_image_id() === $img_coat && array( $img_hero ) === array_map( 'intval', $coat->get_gallery_image_ids() ), 'image + gallery' );
sdi_t( 2 === count( $coat->get_category_ids() ) && 2 === count( $coat->get_tag_ids() ), 'categories + tags' );
sdi_t( false !== strpos( $coat->get_description(), wp_get_attachment_url( $img_hero ) ), '{{image_url}} replaced in product description' );
sdi_t( 'instock' === $coat->get_stock_status(), 'parent stock status synced' );

$scarf = wc_get_product( SDI_Util::find_post( $theme, 'product', 'basic-scarf' ) );
sdi_t( $scarf && $scarf->is_type( 'simple' ) && '650000' === (string) $scarf->get_price() && '' === (string) $scarf->get_sale_price(), 'simple product + null sale price' );
$gloves = wc_get_product( SDI_Util::find_post( $theme, 'product', 'sold-out-gloves' ) );
sdi_t( $gloves && 'outofstock' === $gloves->get_stock_status(), 'simple product out_of_stock:true' );

$home = SDI_Util::find_post( $theme, 'page', 'home' );
sdi_t( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $home, 'front page set' );
sdi_t( (int) get_option( 'page_for_posts' ) === SDI_Util::find_post( $theme, 'page', 'journal' ), 'blog page set' );
$cart = SDI_Util::find_post( $theme, 'page', 'cart' );
sdi_t( (int) get_option( 'woocommerce_cart_page_id' ) === $cart && false !== strpos( get_post_field( 'post_content', $cart ), '[woocommerce_cart]' ), 'cart page + shortcode' );
sdi_t( (int) get_option( 'woocommerce_shop_page_id' ) === SDI_Util::find_post( $theme, 'page', 'shop' ), 'shop page option' );
sdi_t( (int) get_option( 'woocommerce_myaccount_page_id' ) === SDI_Util::find_post( $theme, 'page', 'my-account' ), 'myaccount page option' );
sdi_t( false !== strpos( get_post_field( 'post_content', $home ), wp_get_attachment_url( $img_hero ) ), '{{image_url}} replaced in page content' );

$post_id = SDI_Util::find_post( $theme, 'post', 'winter-style' );
sdi_t( $post_id && get_post_thumbnail_id( $post_id ) === $img_hero && has_category( 'سبک زندگی', $post_id ), 'post + thumbnail + category' );

$locations = get_theme_mod( 'nav_menu_locations' );
$primary   = isset( $locations['primary'] ) ? wp_get_nav_menu_items( $locations['primary'] ) : array();
$footer    = isset( $locations['footer'] ) ? wp_get_nav_menu_items( $locations['footer'] ) : array();
sdi_t( 6 === count( $primary ), 'primary menu: 6 items (1 nested, 1 missing ref skipped), got ' . count( $primary ) );
sdi_t( 2 === count( $footer ), 'footer menu: 2 items' );
$nested = array_filter( $primary, function ( $i ) { return (int) $i->menu_item_parent > 0; } );
sdi_t( 1 === count( $nested ), 'nested menu item' );

sdi_t( wc_get_coupon_id_by_code( 'sayeh10' ) && wc_get_coupon_id_by_code( 'fix50' ), 'coupons created' );
$c = new WC_Coupon( 'fix50' );
sdi_t( 'fixed_cart' === $c->get_discount_type() && 500000.0 === (float) $c->get_amount(), 'coupon type/amount' );

$zones = SDI_State::registry( $theme, 'shipping_zones' );
$zone  = $zones ? WC_Shipping_Zones::get_zone( $zones[0] ) : null;
$methods = $zone ? array_values( $zone->get_shipping_methods() ) : array();
sdi_t( $zone && 'ایران' === $zone->get_zone_name() && 2 === count( $methods ), 'shipping zone + 2 methods' );
if ( 2 === count( $methods ) ) {
	sdi_t( '90000' === (string) $methods[0]->get_option( 'cost' ) && 'پست پیشتاز' === $methods[0]->get_title(), 'flat rate cost/title' );
	sdi_t( 'min_amount' === $methods[1]->get_option( 'requires' ) && '3000000' === (string) $methods[1]->get_option( 'min_amount' ), 'free shipping min amount' );
}

sdi_t( (int) get_theme_mod( 'saayeh_hero_image' ) === $img_hero, '@image token in theme_mod → attachment id' );
sdi_t( array( $img_hero, $img_coat ) === get_theme_mod( 'saayeh_gallery' ), '@image tokens in array theme_mod' );
sdi_t( '<img data-id="' . $img_coat . '">' === get_theme_mod( 'saayeh_hero_html' ), 'embedded @image token' );
sdi_t( 'سادگی، امضای توست' === get_theme_mod( 'saayeh_hero_title' ), 'text theme_mod' );
sdi_t( 'IRT' === get_option( 'woocommerce_currency' ) && (int) get_option( 'sdi_fixture_logo_id' ) === $img_coat, 'options + @image in option' );
sdi_t( $before['admin_email'] === get_option( 'admin_email' ), 'protected option admin_email untouched' );

$rec = SDI_State::get_imported( $theme );
sdi_t( $rec && $rec['time'] > 0, 'import record stored' );
sdi_t( null === SDI_State::get_run(), 'run state cleared after completion' );

sdi_t( $run['requests'] > 15, 'batching split work across requests' );
remove_all_filters( 'sdi_batch_size' );
$flagged_before = sdi_count_flagged_total( $theme );
WP_CLI::log( '== Idempotency (second import)' );
$run2 = sdi_run_import( $theme, $groups );
sdi_t( $run2['ok'], 'second import finished' );
sdi_t( sdi_count_flagged_total( $theme ) === $flagged_before, "no duplicates (flagged objects stay at $flagged_before)" );
sdi_t( 1 === count( SDI_State::registry( $theme, 'shipping_zones' ) ), 'no duplicate shipping zone' );
sdi_t( 8 === count( wc_get_product( $coat_id )->get_children() ), 'no duplicate variations' );

WP_CLI::log( '== Admin page renders' );
set_current_screen( 'appearance_page_saayeh-demo-importer' );
ob_start();
SDI_Admin::render();
$html = ob_get_clean();
sdi_t( false !== strpos( $html, 'sdi-card' ) && false !== strpos( $html, 'دمو درون‌ریزی شده است' ), 'admin page HTML contains card + imported status' );
sdi_t( false !== strpos( $html, 'حذف محتوای دمو' ), 'remove button shown' );

WP_CLI::log( '== Removal' );
$requests = 0;
$rlog     = array();
do {
	$rem = sdi_ajax( 'sdi_remove', array( 'theme' => $theme ) );
	$requests++;
	if ( empty( $rem['success'] ) ) {
		sdi_t( false, 'remove request failed: ' . wp_json_encode( $rem ) );
		break;
	}
	$rlog = array_merge( $rlog, $rem['data']['log'] );
} while ( empty( $rem['data']['done'] ) && $requests < 100 );
sdi_t( ! empty( $rem['data']['done'] ), "removal finished in $requests requests" );
sdi_t( 0 === sdi_count_flagged_total( $theme ), 'no flagged objects remain' );
sdi_t( ! get_post( $coat_id ) && ! get_post( $children[0] ), 'product + variations deleted' );
sdi_t( ! file_exists( $hero_file ), 'attachment file deleted from disk' );
sdi_t( ! get_term_by( 'slug', 'coats', 'product_cat' ), 'product category deleted' );
sdi_t( count( wc_get_attribute_taxonomies() ) === $before['attributes'], 'created attributes deleted' );
sdi_t( count( WC_Shipping_Zones::get_zones() ) === $before['zones'], 'shipping zone deleted' );
sdi_t( ! wc_get_coupon_id_by_code( 'sayeh10' ), 'coupons deleted' );
sdi_t( ! wp_get_nav_menu_object( 'منوی اصلی' ), 'menus deleted' );
sdi_t( 'posts' === get_option( 'show_on_front' ), 'show_on_front restored' );
sdi_t( $before['currency'] === get_option( 'woocommerce_currency' ), 'currency restored (' . get_option( 'woocommerce_currency' ) . ')' );
sdi_t( (int) get_option( 'woocommerce_cart_page_id' ) === $before['cart_page'], 'woocommerce cart page id restored' );
sdi_t( false === get_option( 'sdi_fixture_logo_id' ), 'new option removed' );
sdi_t( false === get_theme_mod( 'saayeh_hero_image' ), 'theme_mod removed' );
sdi_t( null === SDI_State::get_imported( $theme ) && null === SDI_State::get_backup( $theme ), 'import record + backup cleared' );

WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Passed: %d  Failed: %d', $GLOBALS['sdi_pass'], $GLOBALS['sdi_fail'] ) );
if ( $GLOBALS['sdi_fail'] ) {
	WP_CLI::halt( 1 );
}
WP_CLI::success( 'Smoke test passed.' );
