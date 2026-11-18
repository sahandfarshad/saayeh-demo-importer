<?php
/**
 * Plugin Name:       درون‌ریز دمو سایه
 * Plugin URI:        https://github.com/sahandfarshad
 * Description:       درون‌ریزی یک‌کلیکی محتوای دمو (محصولات، برگه‌ها، نوشته‌ها، منوها، تنظیمات قالب، کوپن و حمل‌ونقل) برای پوسته‌های سایه. Saayeh Demo Importer.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Saayeh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       saayeh-demo-importer
 * Domain Path:       /languages
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

define( 'SDI_VERSION', '1.0.0' );
define( 'SDI_FILE', __FILE__ );
define( 'SDI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SDI_URL', plugin_dir_url( __FILE__ ) );
define( 'SDI_TEXT_DOMAIN', 'saayeh-demo-importer' );

/** Theme header that marks a theme as carrying a Saayeh demo package. */
define( 'SDI_THEME_HEADER', 'Saayeh Demo Package' );

/** Meta key put on every object created by the importer (value: theme slug). */
define( 'SDI_META_FLAG', '_sdi_demo' );

/** Meta key holding the item's key inside demo.json (slug / image id / code). */
define( 'SDI_META_KEY', '_sdi_key' );

require_once SDI_DIR . 'includes/class-sdi-util.php';
require_once SDI_DIR . 'includes/class-sdi-themes.php';
require_once SDI_DIR . 'includes/class-sdi-package.php';
require_once SDI_DIR . 'includes/class-sdi-state.php';
require_once SDI_DIR . 'includes/class-sdi-importer.php';
require_once SDI_DIR . 'includes/class-sdi-remover.php';
require_once SDI_DIR . 'includes/class-sdi-ajax.php';
require_once SDI_DIR . 'includes/class-sdi-admin.php';

/**
 * The extra header must be registered as early as possible so that
 * wp_get_themes() caches include it.
 */
add_filter( 'extra_theme_headers', array( 'SDI_Themes', 'register_header' ) );

add_action( 'plugins_loaded', 'sdi_bootstrap' );

/**
 * Boot the plugin.
 */
function sdi_bootstrap() {
	add_action( 'init', 'sdi_load_textdomain' );
	SDI_Ajax::init();
	if ( is_admin() ) {
		SDI_Admin::init();
	}
}

/**
 * Load translations.
 */
function sdi_load_textdomain() {
	load_plugin_textdomain( 'saayeh-demo-importer', false, dirname( plugin_basename( SDI_FILE ) ) . '/languages' );
}
