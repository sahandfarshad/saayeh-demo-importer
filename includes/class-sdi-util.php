<?php
/**
 * Small helpers shared by the importer and remover.
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static utility helpers.
 */
class SDI_Util {

	/**
	 * Find a post created by the importer for the given theme and key.
	 *
	 * @param string       $theme     Theme slug.
	 * @param string|array $post_type Post type(s).
	 * @param string       $key       Item key (slug, image id, coupon code).
	 * @return int Post ID or 0.
	 */
	public static function find_post( $theme, $post_type, $key ) {
		$ids = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'   => SDI_META_FLAG,
						'value' => $theme,
					),
					array(
						'key'   => SDI_META_KEY,
						'value' => (string) $key,
					),
				),
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Find a term created (or adopted) by the importer.
	 *
	 * @param string $theme    Theme slug.
	 * @param string $taxonomy Taxonomy.
	 * @param string $key      Item key.
	 * @return int Term ID or 0.
	 */
	public static function find_term( $theme, $taxonomy, $key ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return 0;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'   => SDI_META_FLAG,
						'value' => $theme,
					),
					array(
						'key'   => SDI_META_KEY,
						'value' => (string) $key,
					),
				),
			)
		);
		return ( ! is_wp_error( $terms ) && $terms ) ? (int) $terms[0] : 0;
	}

	/**
	 * Flag a post as demo content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $theme   Theme slug.
	 * @param string $key     Item key.
	 */
	public static function flag_post( $post_id, $theme, $key ) {
		update_post_meta( $post_id, SDI_META_FLAG, $theme );
		update_post_meta( $post_id, SDI_META_KEY, (string) $key );
	}

	/**
	 * Flag a term as demo content.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $theme   Theme slug.
	 * @param string $key     Item key.
	 */
	public static function flag_term( $term_id, $theme, $key ) {
		update_term_meta( $term_id, SDI_META_FLAG, $theme );
		update_term_meta( $term_id, SDI_META_KEY, (string) $key );
	}

	/**
	 * Count everything flagged for a theme (posts by type + terms).
	 *
	 * @param string $theme Theme slug.
	 * @return array
	 */
	public static function count_flagged( $theme ) {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.post_type, COUNT(*) AS c FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s GROUP BY p.post_type",
				SDI_META_FLAG,
				$theme
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->post_type ] = (int) $row->c;
		}
		$terms        = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
				SDI_META_FLAG,
				$theme
			)
		);
		$out['terms'] = (int) $terms;
		return $out;
	}

	/**
	 * Is the WooCommerce plugin active and loaded?
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_create_attribute' );
	}

	/**
	 * Whether WooCommerce is installed (but maybe inactive).
	 *
	 * @return bool
	 */
	public static function woocommerce_installed() {
		return file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' );
	}

	/**
	 * Recursively replace "@image:<id>" tokens with attachment IDs.
	 * A value that is exactly the token becomes an int; a token embedded in a
	 * longer string is replaced by the ID as text.
	 *
	 * @param mixed    $value    Value.
	 * @param callable $resolver Function( string $image_key ): int.
	 * @return mixed
	 */
	public static function replace_image_tokens( $value, $resolver ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::replace_image_tokens( $v, $resolver );
			}
			return $value;
		}
		if ( ! is_string( $value ) || false === strpos( $value, '@image:' ) ) {
			return $value;
		}
		if ( preg_match( '/^@image:([A-Za-z0-9_\-\.]+)$/', $value, $m ) ) {
			return (int) call_user_func( $resolver, $m[1] );
		}
		return preg_replace_callback(
			'/@image:([A-Za-z0-9_\-\.]+)/',
			function ( $m ) use ( $resolver ) {
				return (string) (int) call_user_func( $resolver, $m[1] );
			},
			$value
		);
	}

	/**
	 * Replace {{image_url:<id>}} tokens in content.
	 *
	 * @param string   $content  Content.
	 * @param callable $resolver Function( string $image_key ): string URL.
	 * @return string
	 */
	public static function replace_image_urls( $content, $resolver ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{{image_url:' ) ) {
			return (string) $content;
		}
		return preg_replace_callback(
			'/\{\{\s*image_url:([A-Za-z0-9_\-\.]+)\s*\}\}/',
			function ( $m ) use ( $resolver ) {
				return esc_url_raw( (string) call_user_func( $resolver, $m[1] ) );
			},
			$content
		);
	}
}
