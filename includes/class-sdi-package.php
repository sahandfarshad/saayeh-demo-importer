<?php
/**
 * A theme's demo package (demo.json + bundled images).
 *
 * All file access is confined to the theme directory: every path is resolved
 * with realpath() and must live under the theme root.
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Demo package reader.
 */
class SDI_Package {

	/** Supported schema version. */
	const SCHEMA_VERSION = 1;

	/** List sections of the schema. */
	const LIST_SECTIONS = array( 'requires', 'images', 'product_attributes', 'product_categories', 'products', 'pages', 'posts', 'menus', 'coupons' );

	/** Map sections of the schema. */
	const MAP_SECTIONS = array( 'theme_mods', 'options', 'shipping' );

	/**
	 * Theme slug (stylesheet directory name of the theme owning the package).
	 *
	 * @var string
	 */
	public $theme_slug = '';

	/**
	 * Real path of the theme root.
	 *
	 * @var string
	 */
	public $root = '';

	/**
	 * Real path of demo.json.
	 *
	 * @var string
	 */
	public $file = '';

	/**
	 * Decoded data.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Load a package for a theme.
	 *
	 * @param WP_Theme $theme Theme carrying the header.
	 * @param string   $rel   Relative path from the header.
	 * @return SDI_Package|WP_Error
	 */
	public static function load( WP_Theme $theme, $rel ) {
		$pkg             = new self();
		$pkg->theme_slug = $theme->get_stylesheet();
		$root            = realpath( $theme->get_stylesheet_directory() );
		if ( ! $root ) {
			return new WP_Error( 'sdi_root', __( 'پوشه‌ی پوسته پیدا نشد.', 'saayeh-demo-importer' ) );
		}
		$pkg->root = $root;

		$file = $pkg->resolve( $rel );
		if ( ! $file || 'json' !== strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error(
				'sdi_missing_json',
				/* translators: %s: relative path */
				sprintf( __( 'فایل بسته‌ی دمو «%s» در پوشه‌ی پوسته پیدا نشد.', 'saayeh-demo-importer' ), $rel )
			);
		}
		$pkg->file = $file;

		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new WP_Error( 'sdi_read', __( 'خواندن فایل demo.json ممکن نشد.', 'saayeh-demo-importer' ) );
		}
		// Strip a UTF-8 BOM if present.
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'sdi_json',
				/* translators: %s: JSON error */
				sprintf( __( 'فایل demo.json معتبر نیست: %s', 'saayeh-demo-importer' ), json_last_error_msg() )
			);
		}
		$version = isset( $data['version'] ) ? (int) $data['version'] : 0;
		if ( self::SCHEMA_VERSION !== $version ) {
			return new WP_Error(
				'sdi_version',
				/* translators: %d: schema version */
				sprintf( __( 'نسخه‌ی طرح demo.json (%d) پشتیبانی نمی‌شود.', 'saayeh-demo-importer' ), $version )
			);
		}
		foreach ( self::LIST_SECTIONS as $section ) {
			$data[ $section ] = ( isset( $data[ $section ] ) && is_array( $data[ $section ] ) ) ? array_values( $data[ $section ] ) : array();
		}
		foreach ( self::MAP_SECTIONS as $section ) {
			$data[ $section ] = ( isset( $data[ $section ] ) && is_array( $data[ $section ] ) ) ? $data[ $section ] : array();
		}
		$pkg->data = $data;
		return $pkg;
	}

	/**
	 * Resolve a relative path inside the theme root. Returns false for paths
	 * escaping the root or not existing.
	 *
	 * @param string $rel  Relative path.
	 * @param string $base Optional base dir (must itself be inside the root).
	 * @return string|false
	 */
	public function resolve( $rel, $base = '' ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		$rel = ltrim( $rel, '/' );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) ) {
			return false;
		}
		$base = $base ? $base : $this->root;
		$real = realpath( $base . DIRECTORY_SEPARATOR . $rel );
		if ( ! $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			return false;
		}
		$root = rtrim( $this->root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real, $root ) ) {
			return false;
		}
		return $real;
	}

	/**
	 * Resolve an image file: relative to demo.json's folder first, then to the
	 * theme root.
	 *
	 * @param string $rel Relative path.
	 * @return string|false
	 */
	public function resolve_image( $rel ) {
		$path = $this->resolve( $rel, dirname( $this->file ) );
		if ( ! $path ) {
			$path = $this->resolve( $rel );
		}
		if ( ! $path ) {
			return false;
		}
		$type = wp_check_filetype( $path );
		if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
			return false;
		}
		return $path;
	}

	/**
	 * A section of the data.
	 *
	 * @param string $section Section name.
	 * @return array
	 */
	public function get( $section ) {
		return isset( $this->data[ $section ] ) && is_array( $this->data[ $section ] ) ? $this->data[ $section ] : array();
	}

	/**
	 * Counts for the admin summary.
	 *
	 * @return array
	 */
	public function summary() {
		return array(
			'products' => count( $this->get( 'products' ) ),
			'pages'    => count( $this->get( 'pages' ) ),
			'posts'    => count( $this->get( 'posts' ) ),
			'images'   => count( $this->get( 'images' ) ),
			'menus'    => count( $this->get( 'menus' ) ),
			'coupons'  => count( $this->get( 'coupons' ) ),
		);
	}

	/**
	 * Requirements with their status.
	 *
	 * @return array List of [slug, label, met].
	 */
	public function requirements() {
		$out = array();
		foreach ( $this->get( 'requires' ) as $req ) {
			$req = sanitize_key( (string) $req );
			if ( '' === $req ) {
				continue;
			}
			if ( 'woocommerce' === $req ) {
				$met   = SDI_Util::woocommerce_active();
				$label = __( 'ووکامرس', 'saayeh-demo-importer' );
			} else {
				if ( ! function_exists( 'is_plugin_active' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$met   = is_plugin_active( $req . '/' . $req . '.php' );
				$label = $req;
			}
			$out[] = array(
				'slug'  => $req,
				'label' => $label,
				'met'   => (bool) apply_filters( 'sdi_requirement_met', $met, $req, $this ),
			);
		}
		return $out;
	}

	/**
	 * Whether all requirements are met.
	 *
	 * @return bool
	 */
	public function requirements_met() {
		foreach ( $this->requirements() as $r ) {
			if ( ! $r['met'] ) {
				return false;
			}
		}
		return true;
	}
}
