<?php
/**
 * The step-by-step demo importer.
 *
 * Each step is a callable receiving ( SDI_Importer $importer, int $cursor )
 * and returning array( 'done' => bool, 'cursor' => int, 'total' => int ).
 * Batched steps process a few items per request and return done=false with
 * an advanced cursor until finished.
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Demo importer.
 */
class SDI_Importer {

	/**
	 * Package.
	 *
	 * @var SDI_Package
	 */
	public $package;

	/**
	 * Theme slug used as the flag value.
	 *
	 * @var string
	 */
	public $theme;

	/**
	 * Log lines produced during this request.
	 *
	 * @var array
	 */
	public $log = array();

	/**
	 * Per-request cache of image key => attachment id.
	 *
	 * @var array
	 */
	protected $image_cache = array();

	/**
	 * Constructor.
	 *
	 * @param SDI_Package $package Package.
	 */
	public function __construct( SDI_Package $package ) {
		$this->package = $package;
		$this->theme   = $package->theme_slug;
	}

	/* ===================================================== configuration */

	/**
	 * Option groups the user can toggle (all on by default).
	 *
	 * @return array group => label
	 */
	public static function option_groups() {
		return (array) apply_filters(
			'sdi_option_groups',
			array(
				'products'       => __( 'محصولات', 'saayeh-demo-importer' ),
				'pages'          => __( 'برگه‌ها', 'saayeh-demo-importer' ),
				'posts'          => __( 'نوشته‌ها', 'saayeh-demo-importer' ),
				'menus'          => __( 'منوها', 'saayeh-demo-importer' ),
				'theme_settings' => __( 'تنظیمات قالب', 'saayeh-demo-importer' ),
				'commerce'       => __( 'کوپن و حمل‌ونقل', 'saayeh-demo-importer' ),
			)
		);
	}

	/**
	 * All import steps in order. Filter `sdi_import_steps` lets themes add,
	 * remove or reorder steps. A step's `group` ties it to an option checkbox;
	 * null means it always runs.
	 *
	 * @return array step_id => array( label, callback, group )
	 */
	public function get_steps() {
		$steps = array(
			'images'         => array(
				'label'    => __( 'تصاویر', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_images' ),
				'group'    => null,
			),
			'attributes'     => array(
				'label'    => __( 'ویژگی‌های محصول', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_attributes' ),
				'group'    => 'products',
			),
			'categories'     => array(
				'label'    => __( 'دسته‌بندی محصولات', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_categories' ),
				'group'    => 'products',
			),
			'products'       => array(
				'label'    => __( 'محصولات و متغیرها', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_products' ),
				'group'    => 'products',
			),
			'pages'          => array(
				'label'    => __( 'برگه‌ها', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_pages' ),
				'group'    => 'pages',
			),
			'posts'          => array(
				'label'    => __( 'نوشته‌ها', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_posts' ),
				'group'    => 'posts',
			),
			'menus'          => array(
				'label'    => __( 'منوها', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_menus' ),
				'group'    => 'menus',
			),
			'coupons'        => array(
				'label'    => __( 'کوپن‌ها', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_coupons' ),
				'group'    => 'commerce',
			),
			'shipping'       => array(
				'label'    => __( 'حمل‌ونقل', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_shipping' ),
				'group'    => 'commerce',
			),
			'theme_settings' => array(
				'label'    => __( 'تنظیمات قالب و گزینه‌ها', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_theme_settings' ),
				'group'    => 'theme_settings',
			),
			'finalize'       => array(
				'label'    => __( 'نهایی‌سازی', 'saayeh-demo-importer' ),
				'callback' => array( $this, 'step_finalize' ),
				'group'    => null,
			),
		);
		$steps = (array) apply_filters( 'sdi_import_steps', $steps, $this );
		foreach ( $steps as $id => $step ) {
			if ( ! is_array( $step ) || empty( $step['callback'] ) || ! is_callable( $step['callback'] ) ) {
				unset( $steps[ $id ] );
				continue;
			}
			$steps[ $id ] = wp_parse_args(
				$step,
				array(
					'label' => (string) $id,
					'group' => null,
				)
			);
		}
		return $steps;
	}

	/**
	 * Step ids to run for the selected option groups.
	 *
	 * @param array $groups Selected groups.
	 * @return array
	 */
	public function steps_for_groups( array $groups ) {
		$ids = array();
		foreach ( $this->get_steps() as $id => $step ) {
			if ( null === $step['group'] || in_array( $step['group'], $groups, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Run one unit of a step.
	 *
	 * @param string $step_id Step id.
	 * @param int    $cursor  Cursor.
	 * @return array|WP_Error
	 */
	public function run_step( $step_id, $cursor ) {
		$steps = $this->get_steps();
		if ( ! isset( $steps[ $step_id ] ) ) {
			return new WP_Error(
				'sdi_unknown_step',
				/* translators: %s: step id */
				sprintf( __( 'مرحله‌ی ناشناخته: %s', 'saayeh-demo-importer' ), $step_id )
			);
		}
		do_action( 'sdi_before_step', $step_id, $this, $cursor );
		$result = call_user_func( $steps[ $step_id ]['callback'], $this, (int) $cursor );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = wp_parse_args(
			is_array( $result ) ? $result : array(),
			array(
				'done'   => true,
				'cursor' => 0,
				'total'  => 0,
			)
		);
		do_action( 'sdi_after_step', $step_id, $result, $this );
		return $result;
	}

	/**
	 * Batch size for a step.
	 *
	 * @param string $step_id Step.
	 * @param int    $default Default.
	 * @return int
	 */
	protected function batch( $step_id, $default ) {
		return max( 1, (int) apply_filters( 'sdi_batch_size', $default, $step_id, $this ) );
	}

	/**
	 * Slice a list for the current batch and build the step result.
	 *
	 * @param array    $items   Items.
	 * @param int      $cursor  Cursor.
	 * @param int      $size    Batch size.
	 * @param callable $handler Called with each item.
	 * @return array
	 */
	protected function batched( array $items, $cursor, $size, $handler ) {
		$total = count( $items );
		$slice = array_slice( $items, $cursor, $size );
		foreach ( $slice as $item ) {
			if ( is_array( $item ) ) {
				call_user_func( $handler, $item );
			}
		}
		$next = $cursor + count( $slice );
		return array(
			'done'   => $next >= $total,
			'cursor' => $next,
			'total'  => $total,
		);
	}

	/**
	 * Add a log line.
	 *
	 * @param string $message Message.
	 * @param string $level   info|success|warning|error.
	 */
	public function log( $message, $level = 'info' ) {
		$this->log[] = array(
			'level'   => $level,
			'message' => wp_strip_all_tags( (string) $message ),
			'time'    => time(),
		);
	}

	/* ========================================================== helpers */

	/**
	 * Attachment id for a demo image key.
	 *
	 * @param string $key Image key.
	 * @return int
	 */
	public function image_id( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return 0;
		}
		if ( ! isset( $this->image_cache[ $key ] ) ) {
			$this->image_cache[ $key ] = SDI_Util::find_post( $this->theme, 'attachment', $key );
		}
		return $this->image_cache[ $key ];
	}

	/**
	 * Attachment URL for a demo image key.
	 *
	 * @param string $key Image key.
	 * @return string
	 */
	public function image_url( $key ) {
		$id = $this->image_id( $key );
		if ( ! $id ) {
			return '';
		}
		$url = wp_get_attachment_url( $id );
		return $url ? $url : '';
	}

	/**
	 * Replace image tokens in content.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function prepare_content( $content ) {
		return SDI_Util::replace_image_urls( (string) $content, array( $this, 'image_url' ) );
	}

	/**
	 * Ensure an attribute taxonomy is registered for this request.
	 *
	 * @param string $slug Attribute slug (without pa_).
	 * @return string Taxonomy name.
	 */
	public function ensure_attribute_taxonomy( $slug ) {
		$taxonomy = wc_attribute_taxonomy_name( $slug );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
					'labels'       => array( 'name' => $slug ),
				)
			);
		}
		return $taxonomy;
	}

	/**
	 * Resolve an attribute value (term name or slug) to a term.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $value    Name or slug.
	 * @return WP_Term|null
	 */
	public function resolve_attribute_term( $taxonomy, $value ) {
		$value = (string) $value;
		$term  = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'name', $value, $taxonomy );
		}
		return $term instanceof WP_Term ? $term : null;
	}

	/**
	 * Find or create a term, flagging it when created by us.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Name.
	 * @param string $slug     Slug ('' to derive from name).
	 * @param array  $args     Extra wp_insert_term args.
	 * @return int Term id or 0.
	 */
	public function get_or_create_term( $taxonomy, $name, $slug = '', $args = array() ) {
		$name = trim( (string) $name );
		$slug = $slug ? sanitize_title( $slug ) : sanitize_title( $name );
		if ( '' === $name ) {
			return 0;
		}
		$ours = SDI_Util::find_term( $this->theme, $taxonomy, $slug );
		if ( $ours ) {
			return $ours;
		}
		$existing = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $existing ) {
			$existing = get_term_by( 'name', $name, $taxonomy );
		}
		if ( $existing instanceof WP_Term ) {
			return (int) $existing->term_id; // Adopt an existing term without flagging it.
		}
		$res = wp_insert_term( $name, $taxonomy, array_merge( $args, array( 'slug' => $slug ) ) );
		if ( is_wp_error( $res ) ) {
			if ( 'term_exists' === $res->get_error_code() ) {
				return (int) $res->get_error_data();
			}
			$this->log(
				/* translators: 1: term, 2: error */
				sprintf( __( 'ایجاد «%1$s» ناموفق بود: %2$s', 'saayeh-demo-importer' ), $name, $res->get_error_message() ),
				'warning'
			);
			return 0;
		}
		SDI_Util::flag_term( $res['term_id'], $this->theme, $slug );
		return (int) $res['term_id'];
	}

	/**
	 * Load media functions.
	 */
	protected function require_media() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/* ============================================================ steps */

	/**
	 * Step: images. Sideloads each image from the theme folder via a temp copy.
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_images( $imp, $cursor ) {
		$this->require_media();
		return $this->batched(
			$this->package->get( 'images' ),
			$cursor,
			$this->batch( 'images', 3 ),
			function ( $img ) {
				$key = isset( $img['id'] ) ? sanitize_key( $img['id'] ) : '';
				if ( '' === $key || empty( $img['file'] ) ) {
					$this->log( __( 'یک تصویر بدون شناسه یا فایل نادیده گرفته شد.', 'saayeh-demo-importer' ), 'warning' );
					return;
				}
				if ( $this->image_id( $img['id'] ) ) {
					/* translators: %s: image id */
					$this->log( sprintf( __( 'تصویر «%s» از قبل وجود دارد.', 'saayeh-demo-importer' ), $img['id'] ) );
					return;
				}
				$path = $this->package->resolve_image( $img['file'] );
				if ( ! $path ) {
					/* translators: %s: file */
					$this->log( sprintf( __( 'فایل تصویر «%s» پیدا نشد یا مجاز نیست.', 'saayeh-demo-importer' ), $img['file'] ), 'warning' );
					return;
				}
				$tmp = wp_tempnam( basename( $path ) );
				if ( ! $tmp || ! copy( $path, $tmp ) ) {
					$this->log( __( 'ساخت فایل موقت برای تصویر ممکن نشد.', 'saayeh-demo-importer' ), 'error' );
					return;
				}
				$title = isset( $img['title'] ) ? sanitize_text_field( $img['title'] ) : '';
				$file  = array(
					'name'     => sanitize_file_name( $this->theme . '-' . basename( $path ) ),
					'tmp_name' => $tmp,
				);
				$id    = media_handle_sideload( $file, 0, $title );
				if ( is_wp_error( $id ) ) {
					if ( file_exists( $tmp ) ) {
						wp_delete_file( $tmp );
					}
					/* translators: 1: image id, 2: error */
					$this->log( sprintf( __( 'بارگذاری تصویر «%1$s» ناموفق بود: %2$s', 'saayeh-demo-importer' ), $img['id'], $id->get_error_message() ), 'error' );
					return;
				}
				SDI_Util::flag_post( $id, $this->theme, (string) $img['id'] );
				if ( ! empty( $img['alt'] ) ) {
					update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $img['alt'] ) );
				}
				$this->image_cache[ (string) $img['id'] ] = (int) $id;
				/* translators: %s: image id */
				$this->log( sprintf( __( 'تصویر «%s» بارگذاری شد.', 'saayeh-demo-importer' ), $img['id'] ), 'success' );
			}
		);
	}

	/**
	 * Step: global product attributes and their terms.
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_attributes( $imp, $cursor ) {
		return $this->batched(
			$this->package->get( 'product_attributes' ),
			$cursor,
			$this->batch( 'attributes', 5 ),
			function ( $attr ) {
				$slug = isset( $attr['slug'] ) ? wc_sanitize_taxonomy_name( $attr['slug'] ) : '';
				if ( '' === $slug ) {
					return;
				}
				$slug = substr( $slug, 0, 28 );
				$name = isset( $attr['name'] ) ? sanitize_text_field( $attr['name'] ) : $slug;
				$id   = wc_attribute_taxonomy_id_by_name( $slug );
				if ( ! $id ) {
					$id = wc_create_attribute(
						array(
							'name'         => $name,
							'slug'         => $slug,
							'type'         => 'select',
							'order_by'     => 'menu_order',
							'has_archives' => false,
						)
					);
					if ( is_wp_error( $id ) ) {
						/* translators: 1: attribute, 2: error */
						$this->log( sprintf( __( 'ساخت ویژگی «%1$s» ناموفق بود: %2$s', 'saayeh-demo-importer' ), $name, $id->get_error_message() ), 'error' );
						return;
					}
					SDI_State::registry_add( $this->theme, 'attributes', (int) $id );
					/* translators: %s: attribute */
					$this->log( sprintf( __( 'ویژگی «%s» ساخته شد.', 'saayeh-demo-importer' ), $name ), 'success' );
				} else {
					/* translators: %s: attribute */
					$this->log( sprintf( __( 'ویژگی «%s» از قبل وجود دارد.', 'saayeh-demo-importer' ), $name ) );
				}
				$taxonomy = $this->ensure_attribute_taxonomy( $slug );
				$order    = 0;
				foreach ( isset( $attr['terms'] ) ? (array) $attr['terms'] : array() as $term ) {
					if ( is_array( $term ) ) {
						$t_name = isset( $term['name'] ) ? sanitize_text_field( $term['name'] ) : '';
						$t_slug = isset( $term['slug'] ) ? (string) $term['slug'] : '';
						$hex    = isset( $term['hex'] ) ? sanitize_hex_color( $term['hex'] ) : '';
					} else {
						$t_name = sanitize_text_field( (string) $term );
						$t_slug = '';
						$hex    = '';
					}
					$term_id = $this->get_or_create_term( $taxonomy, $t_name, $t_slug );
					if ( ! $term_id ) {
						continue;
					}
					update_term_meta( $term_id, 'order', $order++ );
					if ( $hex ) {
						update_term_meta( $term_id, 'saayeh_color_hex', $hex );
					}
				}
			}
		);
	}

	/**
	 * Step: product categories (with thumbnails and optional parents).
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_categories( $imp, $cursor ) {
		return $this->batched(
			$this->package->get( 'product_categories' ),
			$cursor,
			$this->batch( 'categories', 10 ),
			function ( $cat ) {
				if ( empty( $cat['name'] ) ) {
					return;
				}
				$args = array();
				if ( ! empty( $cat['description'] ) ) {
					$args['description'] = wp_kses_post( $cat['description'] );
				}
				if ( ! empty( $cat['parent'] ) ) {
					$parent = SDI_Util::find_term( $this->theme, 'product_cat', sanitize_title( $cat['parent'] ) );
					if ( ! $parent ) {
						$p      = get_term_by( 'slug', sanitize_title( $cat['parent'] ), 'product_cat' );
						$parent = $p ? $p->term_id : 0;
					}
					$args['parent'] = (int) $parent;
				}
				$slug    = ! empty( $cat['slug'] ) ? $cat['slug'] : '';
				$term_id = $this->get_or_create_term( 'product_cat', sanitize_text_field( $cat['name'] ), $slug, $args );
				if ( ! $term_id ) {
					return;
				}
				if ( ! empty( $cat['image'] ) ) {
					$img = $this->image_id( $cat['image'] );
					if ( $img ) {
						update_term_meta( $term_id, 'thumbnail_id', $img );
					}
				}
				/* translators: %s: category */
				$this->log( sprintf( __( 'دسته‌ی «%s» آماده شد.', 'saayeh-demo-importer' ), $cat['name'] ), 'success' );
			}
		);
	}

	/**
	 * Step: products and variations (batched).
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_products( $imp, $cursor ) {
		// Make sure attribute taxonomies exist in this request.
		foreach ( $this->package->get( 'product_attributes' ) as $attr ) {
			if ( ! empty( $attr['slug'] ) ) {
				$this->ensure_attribute_taxonomy( substr( wc_sanitize_taxonomy_name( $attr['slug'] ), 0, 28 ) );
			}
		}
		return $this->batched(
			$this->package->get( 'products' ),
			$cursor,
			$this->batch( 'products', 3 ),
			array( $this, 'import_product' )
		);
	}

	/**
	 * Import a single product.
	 *
	 * @param array $p Product data.
	 */
	public function import_product( $p ) {
		$slug = ! empty( $p['slug'] ) ? sanitize_title( $p['slug'] ) : sanitize_title( isset( $p['name'] ) ? $p['name'] : '' );
		if ( '' === $slug || empty( $p['name'] ) ) {
			$this->log( __( 'محصولی بدون نام نادیده گرفته شد.', 'saayeh-demo-importer' ), 'warning' );
			return;
		}
		if ( SDI_Util::find_post( $this->theme, 'product', $slug ) ) {
			/* translators: %s: product */
			$this->log( sprintf( __( 'محصول «%s» از قبل وجود دارد.', 'saayeh-demo-importer' ), $p['name'] ) );
			return;
		}

		$type     = ( isset( $p['type'] ) && 'variable' === $p['type'] ) ? 'variable' : 'simple';
		$product  = 'variable' === $type ? new WC_Product_Variable() : new WC_Product_Simple();
		$regular  = isset( $p['regular_price'] ) && '' !== $p['regular_price'] && null !== $p['regular_price'] ? wc_format_decimal( $p['regular_price'] ) : '';
		$sale     = isset( $p['sale_price'] ) && '' !== $p['sale_price'] && null !== $p['sale_price'] ? wc_format_decimal( $p['sale_price'] ) : '';
		$oos_rule = isset( $p['out_of_stock'] ) ? $p['out_of_stock'] : array();

		$product->set_name( sanitize_text_field( $p['name'] ) );
		$product->set_slug( $slug );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( wp_kses_post( $this->prepare_content( isset( $p['description'] ) ? $p['description'] : '' ) ) );
		$product->set_short_description( wp_kses_post( $this->prepare_content( isset( $p['short_description'] ) ? $p['short_description'] : '' ) ) );
		$product->set_featured( ! empty( $p['featured'] ) );
		if ( ! empty( $p['sku'] ) ) {
			try {
				$product->set_sku( sanitize_text_field( $p['sku'] ) );
			} catch ( Exception $e ) {
				$this->log( $e->getMessage(), 'warning' );
			}
		}

		// Categories.
		$cat_ids = array();
		foreach ( isset( $p['categories'] ) ? (array) $p['categories'] : array() as $cat_slug ) {
			$tid = SDI_Util::find_term( $this->theme, 'product_cat', sanitize_title( $cat_slug ) );
			if ( ! $tid ) {
				$t   = get_term_by( 'slug', sanitize_title( $cat_slug ), 'product_cat' );
				$tid = $t ? (int) $t->term_id : 0;
			}
			if ( $tid ) {
				$cat_ids[] = $tid;
			}
		}
		if ( $cat_ids ) {
			$product->set_category_ids( $cat_ids );
		}

		// Tags (by name).
		$tag_ids = array();
		foreach ( isset( $p['tags'] ) ? (array) $p['tags'] : array() as $tag ) {
			$tid = $this->get_or_create_term( 'product_tag', sanitize_text_field( $tag ) );
			if ( $tid ) {
				$tag_ids[] = $tid;
			}
		}
		if ( $tag_ids ) {
			$product->set_tag_ids( $tag_ids );
		}

		// Images.
		if ( ! empty( $p['image'] ) ) {
			$product->set_image_id( $this->image_id( $p['image'] ) );
		}
		$gallery = array();
		foreach ( isset( $p['gallery'] ) ? (array) $p['gallery'] : array() as $g ) {
			$gid = $this->image_id( $g );
			if ( $gid ) {
				$gallery[] = $gid;
			}
		}
		if ( $gallery ) {
			$product->set_gallery_image_ids( $gallery );
		}

		// Attributes: map of attribute slug => list of term names/slugs.
		$wc_attributes = array();
		$var_terms     = array(); // taxonomy => list of term slugs (for variations).
		$position      = 0;
		foreach ( isset( $p['attributes'] ) && is_array( $p['attributes'] ) ? $p['attributes'] : array() as $attr_slug => $values ) {
			$attr_slug = substr( wc_sanitize_taxonomy_name( $attr_slug ), 0, 28 );
			$attr_id   = wc_attribute_taxonomy_id_by_name( $attr_slug );
			$taxonomy  = $this->ensure_attribute_taxonomy( $attr_slug );
			$term_ids  = array();
			$slugs     = array();
			foreach ( (array) $values as $value ) {
				$term = $this->resolve_attribute_term( $taxonomy, $value );
				if ( ! $term ) {
					$tid  = $this->get_or_create_term( $taxonomy, sanitize_text_field( $value ) );
					$term = $tid ? get_term( $tid, $taxonomy ) : null;
				}
				if ( $term instanceof WP_Term ) {
					$term_ids[] = (int) $term->term_id;
					$slugs[]    = $term->slug;
				}
			}
			if ( ! $attr_id || ! $term_ids ) {
				/* translators: %s: attribute */
				$this->log( sprintf( __( 'ویژگی «%s» برای این محصول پیدا نشد.', 'saayeh-demo-importer' ), $attr_slug ), 'warning' );
				continue;
			}
			$a = new WC_Product_Attribute();
			$a->set_id( $attr_id );
			$a->set_name( $taxonomy );
			$a->set_options( $term_ids );
			$a->set_position( $position++ );
			$a->set_visible( true );
			$a->set_variation( 'variable' === $type );
			$wc_attributes[]        = $a;
			$var_terms[ $taxonomy ] = $slugs;
		}
		if ( $wc_attributes ) {
			$product->set_attributes( $wc_attributes );
		}

		if ( 'simple' === $type ) {
			$product->set_regular_price( $regular );
			$product->set_sale_price( $sale );
			$product->set_manage_stock( false );
			$product->set_stock_status( ( true === $oos_rule || ( isset( $p['stock_status'] ) && 'outofstock' === $p['stock_status'] ) ) ? 'outofstock' : 'instock' );
		}

		$product_id = $product->save();
		if ( ! $product_id ) {
			/* translators: %s: product */
			$this->log( sprintf( __( 'ذخیره‌ی محصول «%s» ناموفق بود.', 'saayeh-demo-importer' ), $p['name'] ), 'error' );
			return;
		}
		SDI_Util::flag_post( $product_id, $this->theme, $slug );
		if ( ! empty( $p['badge'] ) ) {
			update_post_meta( $product_id, '_saayeh_badge', sanitize_text_field( $p['badge'] ) );
		}

		$variations = 0;
		if ( 'variable' === $type && $var_terms ) {
			$oos_rules = $this->normalize_oos_rules( $oos_rule );
			foreach ( $this->cartesian( $var_terms ) as $combo ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );
				$variation->set_attributes( $combo );
				$variation->set_regular_price( $regular );
				$variation->set_sale_price( $sale );
				$variation->set_manage_stock( false );
				$variation->set_status( 'publish' );
				$variation->set_stock_status( ( true === $oos_rule || $this->combo_matches( $combo, $oos_rules ) ) ? 'outofstock' : 'instock' );
				$vid = $variation->save();
				if ( $vid ) {
					SDI_Util::flag_post( $vid, $this->theme, $slug . ':' . implode( '-', $combo ) );
					++$variations;
				}
			}
			WC_Product_Variable::sync( $product_id );
			wc_delete_product_transients( $product_id );
		}

		$this->log(
			$variations
				/* translators: 1: product, 2: variation count */
				? sprintf( __( 'محصول «%1$s» با %2$d متغیر ساخته شد.', 'saayeh-demo-importer' ), $p['name'], $variations )
				/* translators: %s: product */
				: sprintf( __( 'محصول «%s» ساخته شد.', 'saayeh-demo-importer' ), $p['name'] ),
			'success'
		);
	}

	/**
	 * Normalize out_of_stock rules to taxonomy => term slug maps.
	 *
	 * @param mixed $rules Rules.
	 * @return array
	 */
	protected function normalize_oos_rules( $rules ) {
		$out = array();
		if ( ! is_array( $rules ) ) {
			return $out;
		}
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ! $rule ) {
				continue;
			}
			$norm = array();
			foreach ( $rule as $attr_slug => $value ) {
				$taxonomy = wc_attribute_taxonomy_name( substr( wc_sanitize_taxonomy_name( $attr_slug ), 0, 28 ) );
				$term     = $this->resolve_attribute_term( $taxonomy, $value );
				$norm[ $taxonomy ] = $term ? $term->slug : sanitize_title( $value );
			}
			$out[] = $norm;
		}
		return $out;
	}

	/**
	 * Whether a variation combo matches any out-of-stock rule (all keys of a
	 * rule must match).
	 *
	 * @param array $combo Combo taxonomy => slug.
	 * @param array $rules Normalized rules.
	 * @return bool
	 */
	protected function combo_matches( $combo, $rules ) {
		foreach ( $rules as $rule ) {
			$match = true;
			foreach ( $rule as $taxonomy => $slug ) {
				if ( ! isset( $combo[ $taxonomy ] ) || $combo[ $taxonomy ] !== $slug ) {
					$match = false;
					break;
				}
			}
			if ( $match ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cartesian product of taxonomy => values.
	 *
	 * @param array $sets Sets.
	 * @return array List of taxonomy => value maps.
	 */
	protected function cartesian( $sets ) {
		$result = array( array() );
		foreach ( $sets as $key => $values ) {
			$next = array();
			foreach ( $result as $partial ) {
				foreach ( $values as $v ) {
					$next[] = array_merge( $partial, array( $key => $v ) );
				}
			}
			$result = $next;
		}
		return $result;
	}

	/**
	 * Default content for WooCommerce role pages.
	 *
	 * @param string $role Role.
	 * @return string
	 */
	protected function role_default_content( $role ) {
		$codes = array(
			'cart'      => '[woocommerce_cart]',
			'checkout'  => '[woocommerce_checkout]',
			'myaccount' => '[woocommerce_my_account]',
		);
		if ( ! isset( $codes[ $role ] ) ) {
			return '';
		}
		return '<!-- wp:shortcode -->' . $codes[ $role ] . '<!-- /wp:shortcode -->';
	}

	/**
	 * Step: pages (and their roles).
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_pages( $imp, $cursor ) {
		return $this->batched(
			$this->package->get( 'pages' ),
			$cursor,
			$this->batch( 'pages', 10 ),
			function ( $page ) {
				$slug = ! empty( $page['slug'] ) ? sanitize_title( $page['slug'] ) : '';
				if ( '' === $slug ) {
					return;
				}
				$role  = isset( $page['role'] ) ? sanitize_key( $page['role'] ) : '';
				$title = isset( $page['title'] ) ? sanitize_text_field( $page['title'] ) : $slug;
				$id    = SDI_Util::find_post( $this->theme, 'page', $slug );
				// Reuse WooCommerce's own Shop/Cart/Checkout/My Account pages instead of duplicating them.
				if ( ! $id && in_array( $role, array( 'shop', 'cart', 'checkout', 'myaccount' ), true ) ) {
					$existing = (int) get_option( 'woocommerce_' . $role . '_page_id' );
					if ( $existing > 0 && 'page' === get_post_type( $existing ) && 'trash' !== get_post_status( $existing ) ) {
						/* translators: %s: page */
						$this->log( sprintf( __( 'برگه‌ی ووکامرس «%s» از قبل وجود دارد و همان استفاده شد.', 'saayeh-demo-importer' ), get_the_title( $existing ) ) );
						return; // Not flagged, so removal leaves it alone; role option already points to it.
					}
				}
				if ( $id ) {
					/* translators: %s: page */
					$this->log( sprintf( __( 'برگه‌ی «%s» از قبل وجود دارد.', 'saayeh-demo-importer' ), $title ) );
				} else {
					$content = isset( $page['content'] ) ? (string) $page['content'] : '';
					if ( '' === trim( $content ) ) {
						$content = $this->role_default_content( $role );
					}
					$parent = 0;
					if ( ! empty( $page['parent'] ) ) {
						$parent = SDI_Util::find_post( $this->theme, 'page', sanitize_title( $page['parent'] ) );
					}
					$id = wp_insert_post(
						wp_slash(
							array(
								'post_type'    => 'page',
								'post_status'  => 'publish',
								'post_title'   => $title,
								'post_name'    => $slug,
								'post_content' => $this->prepare_content( $content ),
								'post_parent'  => $parent,
								'menu_order'   => isset( $page['menu_order'] ) ? (int) $page['menu_order'] : 0,
							)
						),
						true
					);
					if ( is_wp_error( $id ) ) {
						/* translators: 1: page, 2: error */
						$this->log( sprintf( __( 'ساخت برگه‌ی «%1$s» ناموفق بود: %2$s', 'saayeh-demo-importer' ), $title, $id->get_error_message() ), 'error' );
						return;
					}
					SDI_Util::flag_post( $id, $this->theme, $slug );
					if ( ! empty( $page['template'] ) ) {
						update_post_meta( $id, '_wp_page_template', sanitize_text_field( $page['template'] ) );
					}
					if ( ! empty( $page['image'] ) && $this->image_id( $page['image'] ) ) {
						set_post_thumbnail( $id, $this->image_id( $page['image'] ) );
					}
					/* translators: %s: page */
					$this->log( sprintf( __( 'برگه‌ی «%s» ساخته شد.', 'saayeh-demo-importer' ), $title ), 'success' );
				}
				$this->apply_page_role( $id, $role );
			}
		);
	}

	/**
	 * Apply a page role.
	 *
	 * @param int    $id   Page id.
	 * @param string $role Role.
	 */
	protected function apply_page_role( $id, $role ) {
		switch ( $role ) {
			case 'front':
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', (int) $id );
				$this->log( __( 'برگه‌ی نخست تنظیم شد.', 'saayeh-demo-importer' ) );
				break;
			case 'blog':
				update_option( 'page_for_posts', (int) $id );
				$this->log( __( 'برگه‌ی نوشته‌ها تنظیم شد.', 'saayeh-demo-importer' ) );
				break;
			case 'shop':
			case 'cart':
			case 'checkout':
			case 'myaccount':
			case 'terms':
				update_option( 'woocommerce_' . $role . '_page_id', (int) $id );
				break;
		}
		do_action( 'sdi_page_role', $id, $role, $this );
	}

	/**
	 * Step: blog posts.
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_posts( $imp, $cursor ) {
		return $this->batched(
			$this->package->get( 'posts' ),
			$cursor,
			$this->batch( 'posts', 5 ),
			function ( $post ) {
				$title = isset( $post['title'] ) ? sanitize_text_field( $post['title'] ) : '';
				$slug  = ! empty( $post['slug'] ) ? sanitize_title( $post['slug'] ) : sanitize_title( $title );
				if ( '' === $slug ) {
					return;
				}
				if ( SDI_Util::find_post( $this->theme, 'post', $slug ) ) {
					/* translators: %s: post */
					$this->log( sprintf( __( 'نوشته‌ی «%s» از قبل وجود دارد.', 'saayeh-demo-importer' ), $title ) );
					return;
				}
				$cats = array();
				foreach ( isset( $post['categories'] ) ? (array) $post['categories'] : array() as $cat ) {
					$tid = $this->get_or_create_term( 'category', sanitize_text_field( $cat ) );
					if ( $tid ) {
						$cats[] = $tid;
					}
				}
				$tags = array();
				foreach ( isset( $post['tags'] ) ? (array) $post['tags'] : array() as $tag ) {
					$tid = $this->get_or_create_term( 'post_tag', sanitize_text_field( $tag ) );
					if ( $tid ) {
						$tags[] = $tid;
					}
				}
				$args = array(
					'post_type'     => 'post',
					'post_status'   => 'publish',
					'post_title'    => $title,
					'post_name'     => $slug,
					'post_excerpt'  => isset( $post['excerpt'] ) ? sanitize_textarea_field( $post['excerpt'] ) : '',
					'post_content'  => $this->prepare_content( isset( $post['content'] ) ? $post['content'] : '' ),
					'post_category' => $cats,
				);
				if ( ! empty( $post['date'] ) && strtotime( $post['date'] ) ) {
					$args['post_date'] = gmdate( 'Y-m-d H:i:s', strtotime( $post['date'] ) );
				}
				$id = wp_insert_post( wp_slash( $args ), true );
				if ( is_wp_error( $id ) ) {
					/* translators: 1: post, 2: error */
					$this->log( sprintf( __( 'ساخت نوشته‌ی «%1$s» ناموفق بود: %2$s', 'saayeh-demo-importer' ), $title, $id->get_error_message() ), 'error' );
					return;
				}
				SDI_Util::flag_post( $id, $this->theme, $slug );
				if ( $tags ) {
					wp_set_post_terms( $id, $tags, 'post_tag' );
				}
				if ( ! empty( $post['image'] ) && $this->image_id( $post['image'] ) ) {
					set_post_thumbnail( $id, $this->image_id( $post['image'] ) );
				}
				/* translators: %s: post */
				$this->log( sprintf( __( 'نوشته‌ی «%s» ساخته شد.', 'saayeh-demo-importer' ), $title ), 'success' );
			}
		);
	}

	/**
	 * Step: navigation menus.
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_menus( $imp, $cursor ) {
		return $this->batched(
			$this->package->get( 'menus' ),
			$cursor,
			$this->batch( 'menus', 3 ),
			function ( $menu ) {
				$location = isset( $menu['location'] ) ? sanitize_key( $menu['location'] ) : '';
				$name     = isset( $menu['name'] ) ? sanitize_text_field( $menu['name'] ) : $location;
				$key      = 'menu:' . ( $location ? $location : sanitize_title( $name ) );
				$menu_id  = SDI_Util::find_term( $this->theme, 'nav_menu', $key );
				if ( $menu_id ) {
					/* translators: %s: menu */
					$this->log( sprintf( __( 'منوی «%s» از قبل وجود دارد.', 'saayeh-demo-importer' ), $name ) );
				} else {
					$menu_name = $name;
					if ( wp_get_nav_menu_object( $menu_name ) ) {
						$menu_name .= ' ' . __( '(دمو)', 'saayeh-demo-importer' );
					}
					$menu_id = wp_create_nav_menu( $menu_name );
					if ( is_wp_error( $menu_id ) ) {
						/* translators: 1: menu, 2: error */
						$this->log( sprintf( __( 'ساخت منوی «%1$s» ناموفق بود: %2$s', 'saayeh-demo-importer' ), $name, $menu_id->get_error_message() ), 'error' );
						return;
					}
					SDI_Util::flag_term( $menu_id, $this->theme, $key );
					$count = $this->add_menu_items( $menu_id, isset( $menu['items'] ) ? (array) $menu['items'] : array(), 0 );
					/* translators: 1: menu, 2: count */
					$this->log( sprintf( __( 'منوی «%1$s» با %2$d آیتم ساخته شد.', 'saayeh-demo-importer' ), $name, $count ), 'success' );
				}
				if ( $location ) {
					$locations              = get_theme_mod( 'nav_menu_locations' );
					$locations              = is_array( $locations ) ? $locations : array();
					$locations[ $location ] = (int) $menu_id;
					set_theme_mod( 'nav_menu_locations', $locations );
				}
			}
		);
	}

	/**
	 * Add menu items recursively.
	 *
	 * @param int   $menu_id Menu id.
	 * @param array $items   Items.
	 * @param int   $parent  Parent menu item id.
	 * @return int Count added.
	 */
	protected function add_menu_items( $menu_id, $items, $parent ) {
		$count = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['type'] ) ) {
				continue;
			}
			$args = $this->menu_item_args( $item );
			if ( ! $args ) {
				/* translators: %s: item reference */
				$this->log( sprintf( __( 'آیتم منو «%s» پیدا نشد و نادیده گرفته شد.', 'saayeh-demo-importer' ), isset( $item['ref'] ) ? $item['ref'] : $item['type'] ), 'warning' );
				continue;
			}
			$args['menu-item-status']    = 'publish';
			$args['menu-item-parent-id'] = (int) $parent;
			if ( ! empty( $item['title'] ) ) {
				$args['menu-item-title'] = sanitize_text_field( $item['title'] );
			}
			$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( is_wp_error( $item_id ) ) {
				$this->log( $item_id->get_error_message(), 'warning' );
				continue;
			}
			SDI_Util::flag_post( $item_id, $this->theme, 'menu-item' );
			++$count;
			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$count += $this->add_menu_items( $menu_id, $item['children'], $item_id );
			}
		}
		return $count;
	}

	/**
	 * Build wp_update_nav_menu_item args for an item.
	 *
	 * @param array $item Item.
	 * @return array|null
	 */
	protected function menu_item_args( $item ) {
		$ref = isset( $item['ref'] ) ? (string) $item['ref'] : '';
		switch ( $item['type'] ) {
			case 'page':
			case 'post':
			case 'product':
				$id = SDI_Util::find_post( $this->theme, $item['type'], sanitize_title( $ref ) );
				if ( ! $id ) {
					$found = get_page_by_path( $ref, OBJECT, $item['type'] );
					$id    = $found ? $found->ID : 0;
				}
				return $id ? array(
					'menu-item-object-id' => $id,
					'menu-item-object'    => $item['type'],
					'menu-item-type'      => 'post_type',
				) : null;
			case 'shop':
				$shop = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
				if ( $shop > 0 && get_post( $shop ) ) {
					return array(
						'menu-item-object-id' => $shop,
						'menu-item-object'    => 'page',
						'menu-item-type'      => 'post_type',
					);
				}
				$url = get_post_type_archive_link( 'product' );
				return $url ? array(
					'menu-item-title' => __( 'فروشگاه', 'saayeh-demo-importer' ),
					'menu-item-url'   => $url,
					'menu-item-type'  => 'custom',
				) : null;
			case 'product_cat':
			case 'category':
			case 'product_tag':
				$tid = SDI_Util::find_term( $this->theme, $item['type'], sanitize_title( $ref ) );
				if ( ! $tid ) {
					$t = get_term_by( 'slug', sanitize_title( $ref ), $item['type'] );
					if ( ! $t ) {
						$t = get_term_by( 'name', $ref, $item['type'] );
					}
					$tid = $t ? (int) $t->term_id : 0;
				}
				return $tid ? array(
					'menu-item-object-id' => $tid,
					'menu-item-object'    => $item['type'],
					'menu-item-type'      => 'taxonomy',
				) : null;
			case 'home':
				return array(
					'menu-item-title' => ! empty( $item['title'] ) ? sanitize_text_field( $item['title'] ) : __( 'خانه', 'saayeh-demo-importer' ),
					'menu-item-url'   => home_url( '/' ),
					'menu-item-type'  => 'custom',
				);
			case 'custom':
				$url = isset( $item['url'] ) ? (string) $item['url'] : '#';
				if ( 0 === strpos( $url, '/' ) ) {
					$url = home_url( $url );
				}
				return array(
					'menu-item-title' => isset( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
					'menu-item-url'   => '#' === $url ? '#' : esc_url_raw( $url ),
					'menu-item-type'  => 'custom',
				);
		}
		return apply_filters( 'sdi_menu_item_args', null, $item, $this );
	}

	/**
	 * Step: coupons.
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_coupons( $imp, $cursor ) {
		return $this->batched(
			$this->package->get( 'coupons' ),
			$cursor,
			$this->batch( 'coupons', 10 ),
			function ( $c ) {
				$code = isset( $c['code'] ) ? wc_format_coupon_code( $c['code'] ) : '';
				if ( '' === $code ) {
					return;
				}
				if ( SDI_Util::find_post( $this->theme, 'shop_coupon', $code ) || wc_get_coupon_id_by_code( $code ) ) {
					/* translators: %s: coupon */
					$this->log( sprintf( __( 'کوپن «%s» از قبل وجود دارد.', 'saayeh-demo-importer' ), $code ) );
					return;
				}
				$types = array_keys( wc_get_coupon_types() );
				$type  = isset( $c['discount_type'] ) && in_array( $c['discount_type'], $types, true ) ? $c['discount_type'] : 'percent';
				try {
					$coupon = new WC_Coupon();
					$coupon->set_code( $code );
					$coupon->set_discount_type( $type );
					$coupon->set_amount( isset( $c['amount'] ) ? wc_format_decimal( $c['amount'] ) : 0 );
					$coupon->set_description( isset( $c['description'] ) ? sanitize_text_field( $c['description'] ) : '' );
					if ( isset( $c['minimum_amount'] ) ) {
						$coupon->set_minimum_amount( wc_format_decimal( $c['minimum_amount'] ) );
					}
					if ( isset( $c['maximum_amount'] ) ) {
						$coupon->set_maximum_amount( wc_format_decimal( $c['maximum_amount'] ) );
					}
					if ( isset( $c['usage_limit'] ) ) {
						$coupon->set_usage_limit( absint( $c['usage_limit'] ) );
					}
					if ( ! empty( $c['free_shipping'] ) ) {
						$coupon->set_free_shipping( true );
					}
					if ( ! empty( $c['individual_use'] ) ) {
						$coupon->set_individual_use( true );
					}
					if ( ! empty( $c['date_expires'] ) ) {
						$coupon->set_date_expires( sanitize_text_field( $c['date_expires'] ) );
					}
					$id = $coupon->save();
				} catch ( Exception $e ) {
					$this->log( $e->getMessage(), 'error' );
					return;
				}
				if ( $id ) {
					SDI_Util::flag_post( $id, $this->theme, $code );
					/* translators: %s: coupon */
					$this->log( sprintf( __( 'کوپن «%s» ساخته شد.', 'saayeh-demo-importer' ), $code ), 'success' );
				}
			}
		);
	}

	/**
	 * Step: shipping zone and methods.
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_shipping( $imp, $cursor ) {
		$shipping = $this->package->get( 'shipping' );
		if ( empty( $shipping['zone'] ) ) {
			return array( 'done' => true );
		}
		foreach ( SDI_State::registry( $this->theme, 'shipping_zones' ) as $zone_id ) {
			$existing = WC_Shipping_Zones::get_zone( (int) $zone_id );
			if ( $existing && $existing->get_id() ) {
				$this->log( __( 'منطقه‌ی حمل‌ونقل از قبل وجود دارد.', 'saayeh-demo-importer' ) );
				return array( 'done' => true );
			}
		}

		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( sanitize_text_field( $shipping['zone'] ) );
		$locations = ! empty( $shipping['locations'] ) ? (array) $shipping['locations'] : array();
		if ( ! $locations ) {
			$options  = $this->package->get( 'options' );
			$country  = ! empty( $options['woocommerce_default_country'] ) ? $options['woocommerce_default_country'] : 'IR';
			$locations = array( strtoupper( substr( (string) $country, 0, 2 ) ) );
		}
		foreach ( $locations as $code ) {
			$zone->add_location( strtoupper( sanitize_text_field( $code ) ), 'country' );
		}
		$zone_id = $zone->save();
		SDI_State::registry_add( $this->theme, 'shipping_zones', (int) $zone_id );

		$available = WC()->shipping() ? array_keys( WC()->shipping()->get_shipping_method_class_names() ) : array();
		foreach ( isset( $shipping['methods'] ) ? (array) $shipping['methods'] : array() as $m ) {
			$type = isset( $m['type'] ) ? sanitize_key( $m['type'] ) : '';
			if ( ! in_array( $type, $available, true ) ) {
				/* translators: %s: method */
				$this->log( sprintf( __( 'روش حمل «%s» پشتیبانی نمی‌شود.', 'saayeh-demo-importer' ), $type ), 'warning' );
				continue;
			}
			$instance_id = $zone->add_shipping_method( $type );
			if ( ! $instance_id ) {
				continue;
			}
			$methods = $zone->get_shipping_methods( false );
			if ( ! isset( $methods[ $instance_id ] ) ) {
				continue;
			}
			$method   = $methods[ $instance_id ];
			$settings = array(
				'title'      => isset( $m['title'] ) ? sanitize_text_field( $m['title'] ) : $method->get_method_title(),
				'tax_status' => 'none',
			);
			if ( 'flat_rate' === $type ) {
				$settings['cost'] = isset( $m['cost'] ) ? wc_format_decimal( $m['cost'] ) : '0';
			} elseif ( 'free_shipping' === $type ) {
				$settings['requires']         = isset( $m['min_amount'] ) ? 'min_amount' : ( isset( $m['requires'] ) ? sanitize_key( $m['requires'] ) : '' );
				$settings['min_amount']       = isset( $m['min_amount'] ) ? wc_format_decimal( $m['min_amount'] ) : '0';
				$settings['ignore_discounts'] = 'no';
			} elseif ( isset( $m['cost'] ) ) {
				$settings['cost'] = wc_format_decimal( $m['cost'] );
			}
			$settings = apply_filters( 'sdi_shipping_method_settings', $settings, $type, $m, $this );
			update_option( $method->get_instance_option_key(), array_merge( (array) get_option( $method->get_instance_option_key(), array() ), $settings ), 'yes' );
			/* translators: %s: method */
			$this->log( sprintf( __( 'روش حمل «%s» افزوده شد.', 'saayeh-demo-importer' ), $settings['title'] ), 'success' );
		}
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		/* translators: %s: zone */
		$this->log( sprintf( __( 'منطقه‌ی حمل‌ونقل «%s» ساخته شد.', 'saayeh-demo-importer' ), $shipping['zone'] ), 'success' );
		return array( 'done' => true );
	}

	/**
	 * Options that a demo package may never change.
	 *
	 * @return array
	 */
	public static function protected_options() {
		global $wpdb;
		return (array) apply_filters(
			'sdi_protected_options',
			array(
				'siteurl',
				'home',
				'admin_email',
				'new_admin_email',
				'users_can_register',
				'default_role',
				'active_plugins',
				'active_sitewide_plugins',
				'template',
				'stylesheet',
				'current_theme',
				$wpdb->prefix . 'user_roles',
				'db_version',
				'initial_db_version',
				'cron',
				'upload_path',
				'upload_url_path',
				'secret',
				'auth_key',
				'auth_salt',
				'logged_in_key',
				'logged_in_salt',
				'nonce_key',
				'nonce_salt',
				'recently_activated',
				'uninstall_plugins',
				'blog_public',
				'permalink_structure',
				SDI_State::OPT_STATE,
				SDI_State::OPT_BACKUP,
				SDI_State::OPT_IMPORTED,
				SDI_State::OPT_REGISTRY,
			)
		);
	}

	/**
	 * Step: theme mods and options (with @image token replacement).
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_theme_settings( $imp, $cursor ) {
		$resolver = array( $this, 'image_id' );
		$mods     = 0;
		foreach ( $this->package->get( 'theme_mods' ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || 'nav_menu_locations' === $key ) {
				continue;
			}
			set_theme_mod( $key, SDI_Util::replace_image_tokens( $value, $resolver ) );
			++$mods;
		}
		$protected = self::protected_options();
		$opts      = 0;
		foreach ( $this->package->get( 'options' ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key ) {
				continue;
			}
			if ( in_array( $key, $protected, true ) ) {
				/* translators: %s: option name */
				$this->log( sprintf( __( 'گزینه‌ی محافظت‌شده «%s» تغییر داده نشد.', 'saayeh-demo-importer' ), $key ), 'warning' );
				continue;
			}
			update_option( $key, SDI_Util::replace_image_tokens( $value, $resolver ) );
			++$opts;
		}
		/* translators: 1: mods count, 2: options count */
		$this->log( sprintf( __( '%1$d تنظیم قالب و %2$d گزینه اعمال شد.', 'saayeh-demo-importer' ), $mods, $opts ), 'success' );
		return array( 'done' => true );
	}

	/**
	 * Step: finalize.
	 *
	 * @param SDI_Importer $imp    Importer.
	 * @param int          $cursor Cursor.
	 * @return array
	 */
	public function step_finalize( $imp, $cursor ) {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		delete_transient( 'wc_term_counts' );
		delete_transient( 'wc_attribute_taxonomies' );
		if ( function_exists( '_wc_term_recount' ) && taxonomy_exists( 'product_cat' ) ) {
			$cats = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'id=>parent',
				)
			);
			if ( ! is_wp_error( $cats ) ) {
				_wc_term_recount( $cats, get_taxonomy( 'product_cat' ), true, false );
			}
		}
		flush_rewrite_rules( false );
		update_option( 'woocommerce_queue_flush_rewrite_rules', 'yes' );
		$this->log( __( 'پیوندهای یکتا بازسازی شد.', 'saayeh-demo-importer' ), 'success' );
		return array( 'done' => true );
	}

	/* =========================================================== backup */

	/**
	 * Take a backup of settings the import changes (only once per theme, so
	 * re-imports never overwrite the original values).
	 *
	 * @param SDI_Package $package Package.
	 */
	public static function maybe_backup( SDI_Package $package ) {
		$theme = $package->theme_slug;
		if ( SDI_State::get_backup( $theme ) ) {
			return;
		}
		$option_keys = array(
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'woocommerce_shop_page_id',
			'woocommerce_cart_page_id',
			'woocommerce_checkout_page_id',
			'woocommerce_myaccount_page_id',
			'woocommerce_terms_page_id',
		);
		foreach ( array_keys( $package->get( 'options' ) ) as $k ) {
			$option_keys[] = sanitize_key( $k );
		}
		$options = array();
		foreach ( array_unique( $option_keys ) as $key ) {
			$missing         = new stdClass();
			$value           = get_option( $key, $missing );
			$options[ $key ] = $value === $missing ? array( 'exists' => false ) : array(
				'exists' => true,
				'value'  => $value,
			);
		}
		$mod_keys = array_merge( array( 'nav_menu_locations' ), array_map( 'sanitize_key', array_keys( $package->get( 'theme_mods' ) ) ) );
		$all_mods = get_theme_mods();
		$all_mods = is_array( $all_mods ) ? $all_mods : array();
		$mods     = array();
		foreach ( array_unique( $mod_keys ) as $key ) {
			$mods[ $key ] = array_key_exists( $key, $all_mods ) ? array(
				'exists' => true,
				'value'  => $all_mods[ $key ],
			) : array( 'exists' => false );
		}
		SDI_State::set_backup(
			$theme,
			array(
				'time'       => time(),
				'stylesheet' => get_stylesheet(),
				'options'    => $options,
				'theme_mods' => $mods,
			)
		);
	}
}
