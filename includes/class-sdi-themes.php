<?php
/**
 * Theme detection: finds installed themes carrying a Saayeh demo package and
 * known-but-missing themes from the built-in registry.
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Theme registry / detection.
 */
class SDI_Themes {

	const STATUS_NOT_INSTALLED = 'not_installed';
	const STATUS_INACTIVE      = 'inactive';
	const STATUS_ACTIVE        = 'active';

	/**
	 * Register our custom theme header.
	 *
	 * @param array $headers Extra headers.
	 * @return array
	 */
	public static function register_header( $headers ) {
		$headers[] = SDI_THEME_HEADER;
		return $headers;
	}

	/**
	 * Known theme slugs => display names. Filterable so future themes can be
	 * listed as «نصب نشده» before they are installed.
	 *
	 * @return array
	 */
	public static function known_themes() {
		$known = array(
			'saayeh' => __( 'سایه', 'saayeh-demo-importer' ),
		);
		return (array) apply_filters( 'sdi_known_themes', $known );
	}

	/**
	 * Read the demo package header straight from a theme's style.css (does not
	 * depend on the theme header cache having been built with our filter).
	 *
	 * @param WP_Theme $theme Theme.
	 * @return string Relative path or ''.
	 */
	public static function own_package_header( WP_Theme $theme ) {
		$value = (string) $theme->get( SDI_THEME_HEADER );
		if ( '' === $value ) {
			$file = $theme->get_stylesheet_directory() . '/style.css';
			if ( is_readable( $file ) ) {
				$data  = get_file_data( $file, array( 'pkg' => SDI_THEME_HEADER ), 'theme' );
				$value = isset( $data['pkg'] ) ? (string) $data['pkg'] : '';
			}
		}
		return trim( $value );
	}

	/**
	 * All themes relevant to the importer, keyed by slug.
	 *
	 * Each entry: slug, name, status, installed, active, active_via, version,
	 * screenshot, theme (WP_Theme|null), package_rel, known.
	 *
	 * @return array
	 */
	public static function get_all() {
		$active_stylesheet = get_stylesheet();
		$active_template   = get_template();
		$active_theme      = wp_get_theme();
		$active_has_own    = $active_theme->exists() && '' !== self::own_package_header( $active_theme );

		$out = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$rel = self::own_package_header( $theme );
			if ( '' === $rel ) {
				continue; // Child themes without own header are handled through their parent.
			}

			$active     = false;
			$active_via = '';
			if ( $slug === $active_stylesheet ) {
				$active = true;
			} elseif ( $slug === $active_template && ! $active_has_own ) {
				// Active child theme whose parent carries the package.
				$active     = true;
				$active_via = $active_stylesheet;
			}

			$out[ $slug ] = array(
				'slug'        => $slug,
				'name'        => $theme->display( 'Name', false, true ),
				'status'      => $active ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
				'installed'   => true,
				'active'      => $active,
				'active_via'  => $active_via,
				'active_via_name' => $active_via ? $active_theme->display( 'Name', false, true ) : '',
				'version'     => (string) $theme->get( 'Version' ),
				'screenshot'  => (string) $theme->get_screenshot(),
				'theme'       => $theme,
				'package_rel' => $rel,
				'known'       => false,
			);
		}

		foreach ( self::known_themes() as $slug => $name ) {
			$slug = sanitize_key( $slug );
			if ( isset( $out[ $slug ] ) ) {
				$out[ $slug ]['known'] = true;
				continue;
			}
			$installed = wp_get_theme( $slug );
			$out[ $slug ] = array(
				'slug'        => $slug,
				'name'        => $installed->exists() ? $installed->display( 'Name', false, true ) : $name,
				'status'      => self::STATUS_NOT_INSTALLED,
				'installed'   => $installed->exists(),
				'active'      => false,
				'active_via'  => '',
				'active_via_name' => '',
				'version'     => $installed->exists() ? (string) $installed->get( 'Version' ) : '',
				'screenshot'  => $installed->exists() ? (string) $installed->get_screenshot() : '',
				'theme'       => null, // Installed without a package header (or missing): nothing to import.
				'package_rel' => '',
				'known'       => true,
			);
		}

		// Active theme first, then installed, then missing.
		uasort(
			$out,
			function ( $a, $b ) {
				$rank = array(
					self::STATUS_ACTIVE        => 0,
					self::STATUS_INACTIVE      => 1,
					self::STATUS_NOT_INSTALLED => 2,
				);
				return $rank[ $a['status'] ] <=> $rank[ $b['status'] ];
			}
		);

		return $out;
	}

	/**
	 * Get one theme entry.
	 *
	 * @param string $slug Theme slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$all = self::get_all();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * Load the demo package for an installed theme entry.
	 *
	 * @param array $entry Theme entry from get_all().
	 * @return SDI_Package|WP_Error
	 */
	public static function load_package( $entry ) {
		if ( empty( $entry['theme'] ) || ! $entry['theme'] instanceof WP_Theme ) {
			return new WP_Error( 'sdi_no_theme', __( 'پوسته نصب نشده است یا بسته‌ی دمو ندارد.', 'saayeh-demo-importer' ) );
		}
		return SDI_Package::load( $entry['theme'], $entry['package_rel'] );
	}

	/**
	 * Human label for a status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		switch ( $status ) {
			case self::STATUS_ACTIVE:
				return __( 'فعال', 'saayeh-demo-importer' );
			case self::STATUS_INACTIVE:
				return __( 'نصب‌شده، غیرفعال', 'saayeh-demo-importer' );
			default:
				return __( 'نصب نشده', 'saayeh-demo-importer' );
		}
	}
}
