<?php
/**
 * Removes demo content flagged with _sdi_demo and restores the backup.
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Demo content remover (batched).
 */
class SDI_Remover {

	/**
	 * Theme slug.
	 *
	 * @var string
	 */
	protected $theme;

	/**
	 * Log lines.
	 *
	 * @var array
	 */
	public $log = array();

	/**
	 * Constructor.
	 *
	 * @param string $theme Theme slug.
	 */
	public function __construct( $theme ) {
		$this->theme = $theme;
	}

	/**
	 * Log helper.
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 */
	protected function log( $message, $level = 'info' ) {
		$this->log[] = array(
			'level'   => $level,
			'message' => wp_strip_all_tags( (string) $message ),
			'time'    => time(),
		);
	}

	/**
	 * Flagged post IDs (products first, attachments last).
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	protected function flagged_posts( $limit ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
					WHERE m.meta_key = %s AND m.meta_value = %s
					ORDER BY CASE p.post_type WHEN 'product' THEN 0 WHEN 'product_variation' THEN 1 WHEN 'attachment' THEN 3 ELSE 2 END, p.ID
					LIMIT %d",
					SDI_META_FLAG,
					$this->theme,
					$limit
				)
			)
		);
	}

	/**
	 * Count remaining flagged posts.
	 *
	 * @return int
	 */
	public function remaining() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s AND m.meta_value = %s",
				SDI_META_FLAG,
				$this->theme
			)
		);
	}

	/**
	 * Run one batch. Returns done=true once everything, including terms,
	 * attributes, shipping zones and the backup restore, is handled.
	 *
	 * @return array
	 */
	public function run_batch() {
		$size    = max( 1, (int) apply_filters( 'sdi_remove_batch_size', 15 ) );
		$ids     = $this->flagged_posts( $size );
		$removed = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				delete_post_meta( $id, SDI_META_FLAG );
				continue;
			}
			$ok = false;
			if ( 'attachment' === $post->post_type ) {
				$ok = (bool) wp_delete_attachment( $id, true );
			} elseif ( in_array( $post->post_type, array( 'product', 'product_variation' ), true ) && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					if ( $product->is_type( 'variable' ) ) {
						foreach ( $product->get_children() as $child_id ) {
							$child = wc_get_product( $child_id );
							if ( $child ) {
								$child->delete( true );
							}
						}
					}
					$ok = (bool) $product->delete( true );
				}
				if ( ! $ok ) {
					$ok = (bool) wp_delete_post( $id, true );
				}
			} else {
				$ok = (bool) wp_delete_post( $id, true );
			}
			if ( ! $ok ) {
				// Make sure a stuck item never blocks the loop forever.
				delete_post_meta( $id, SDI_META_FLAG );
				/* translators: %d: post id */
				$this->log( sprintf( __( 'حذف مورد #%d ممکن نشد؛ پرچم دمو از آن برداشته شد.', 'saayeh-demo-importer' ), $id ), 'warning' );
				continue;
			}
			++$removed;
		}
		if ( $removed ) {
			/* translators: %d: count */
			$this->log( sprintf( __( '%d مورد حذف شد.', 'saayeh-demo-importer' ), $removed ), 'success' );
		}

		$remaining = $this->remaining();
		if ( $remaining > 0 ) {
			return array(
				'done'      => false,
				'removed'   => $removed,
				'remaining' => $remaining,
			);
		}

		$this->finish();
		return array(
			'done'      => true,
			'removed'   => $removed,
			'remaining' => 0,
		);
	}

	/**
	 * Remove terms, menus, attributes, shipping zones; restore backup.
	 */
	protected function finish() {
		global $wpdb;

		do_action( 'sdi_before_remove_finish', $this->theme, $this );

		// Attribute taxonomies must be registered to delete their terms.
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $tax ) {
				$name = wc_attribute_taxonomy_name( $tax->attribute_name );
				if ( ! taxonomy_exists( $name ) ) {
					register_taxonomy( $name, array( 'product' ), array( 'hierarchical' => false, 'show_ui' => false, 'rewrite' => false ) );
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$term_ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s",
					SDI_META_FLAG,
					$this->theme
				)
			)
		);
		$terms = 0;
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id );
			if ( ! $term || is_wp_error( $term ) ) {
				delete_term_meta( $term_id, SDI_META_FLAG );
				continue;
			}
			if ( 'nav_menu' === $term->taxonomy ) {
				$res = wp_delete_nav_menu( $term_id );
			} else {
				$res = wp_delete_term( $term_id, $term->taxonomy );
			}
			if ( $res && ! is_wp_error( $res ) ) {
				++$terms;
			} else {
				delete_term_meta( $term_id, SDI_META_FLAG );
			}
		}
		if ( $terms ) {
			/* translators: %d: count */
			$this->log( sprintf( __( '%d دسته/برچسب/منو حذف شد.', 'saayeh-demo-importer' ), $terms ), 'success' );
		}

		// Global attributes created by us (only if they hold no other terms).
		if ( function_exists( 'wc_delete_attribute' ) ) {
			foreach ( SDI_State::registry( $this->theme, 'attributes' ) as $attr_id ) {
				$attr = wc_get_attribute( (int) $attr_id );
				if ( ! $attr ) {
					continue;
				}
				$left = taxonomy_exists( $attr->slug ) ? wp_count_terms( array( 'taxonomy' => $attr->slug, 'hide_empty' => false ) ) : 0;
				if ( ! is_wp_error( $left ) && (int) $left > 0 ) {
					/* translators: %s: attribute */
					$this->log( sprintf( __( 'ویژگی «%s» مقادیر غیردمو دارد و نگه داشته شد.', 'saayeh-demo-importer' ), $attr->name ), 'warning' );
					continue;
				}
				wc_delete_attribute( (int) $attr_id );
			}
		}

		// Shipping zones.
		if ( class_exists( 'WC_Shipping_Zone' ) ) {
			foreach ( SDI_State::registry( $this->theme, 'shipping_zones' ) as $zone_id ) {
				$zone = WC_Shipping_Zones::get_zone( (int) $zone_id );
				if ( $zone && $zone->get_id() ) {
					$zone->delete( true );
					$this->log( __( 'منطقه‌ی حمل‌ونقل دمو حذف شد.', 'saayeh-demo-importer' ), 'success' );
				}
			}
		}

		$this->restore_backup();

		// Restore WooCommerce pages whose block content was swapped for shortcodes during import.
		$converted = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_sdi_converted_by', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $this->theme, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $converted as $pid ) {
			$original = (string) get_post_meta( $pid, '_sdi_original_content', true );
			if ( '' !== $original ) {
				wp_update_post( wp_slash( array( 'ID' => $pid, 'post_content' => $original ) ) );
			}
			delete_post_meta( $pid, '_sdi_original_content' );
			delete_post_meta( $pid, '_sdi_converted_by' );
		}

		SDI_State::registry_clear( $this->theme );
		SDI_State::set_imported( $this->theme, null );
		$run = SDI_State::get_run();
		if ( $run && isset( $run['theme'] ) && $run['theme'] === $this->theme ) {
			SDI_State::clear_run();
		}
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		delete_transient( 'wc_attribute_taxonomies' );
		flush_rewrite_rules( false );

		do_action( 'sdi_after_remove', $this->theme, $this );
		$this->log( __( 'محتوای دمو کاملاً حذف و تنظیمات قبلی بازگردانی شد.', 'saayeh-demo-importer' ), 'success' );
	}

	/**
	 * Restore the pre-import backup.
	 */
	protected function restore_backup() {
		$backup = SDI_State::get_backup( $this->theme );
		if ( ! $backup ) {
			return;
		}
		$protected = SDI_Importer::protected_options();
		foreach ( (array) $backup['options'] as $key => $entry ) {
			if ( in_array( $key, $protected, true ) ) {
				continue;
			}
			if ( ! empty( $entry['exists'] ) ) {
				update_option( $key, $entry['value'] );
			} else {
				delete_option( $key );
			}
		}
		$stylesheet = ! empty( $backup['stylesheet'] ) ? $backup['stylesheet'] : get_stylesheet();
		$option     = 'theme_mods_' . $stylesheet;
		$mods       = get_option( $option, array() );
		$mods       = is_array( $mods ) ? $mods : array();
		foreach ( (array) $backup['theme_mods'] as $key => $entry ) {
			if ( ! empty( $entry['exists'] ) ) {
				$mods[ $key ] = $entry['value'];
			} else {
				unset( $mods[ $key ] );
			}
		}
		update_option( $option, $mods );
		SDI_State::set_backup( $this->theme, null );
		$this->log( __( 'تنظیمات برگه‌ی نخست، برگه‌های ووکامرس، منوها و گزینه‌ها بازگردانی شد.', 'saayeh-demo-importer' ) );
	}
}
