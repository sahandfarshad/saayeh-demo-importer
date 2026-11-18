<?php
/**
 * Admin screen: نمایش → درون‌ریزی دمو.
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class SDI_Admin {

	const PAGE = 'saayeh-demo-importer';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected static $hook = '';

	/**
	 * Hook admin UI.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SDI_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'after_switch_theme', array( __CLASS__, 'flag_theme_switch' ) );
		add_action( 'admin_notices', array( __CLASS__, 'theme_switch_notice' ) );
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'themes.php?page=' . self::PAGE );
	}

	/**
	 * Register the page under Appearance.
	 */
	public static function menu() {
		self::$hook = add_theme_page(
			__( 'درون‌ریزی دمو', 'saayeh-demo-importer' ),
			__( 'درون‌ریزی دمو', 'saayeh-demo-importer' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Plugins list link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'درون‌ریزی دمو', 'saayeh-demo-importer' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Remember that the theme was switched so we can show a one-time hint.
	 */
	public static function flag_theme_switch() {
		set_transient( 'sdi_theme_switched', get_stylesheet(), DAY_IN_SECONDS );
	}

	/**
	 * One-time notice after activating a theme with a demo package.
	 */
	public static function theme_switch_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$switched = get_transient( 'sdi_theme_switched' );
		if ( ! $switched ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && self::$hook === $screen->id ) {
			delete_transient( 'sdi_theme_switched' );
			return;
		}
		foreach ( SDI_Themes::get_all() as $entry ) {
			if ( $entry['active'] && $entry['installed'] && ! SDI_State::get_imported( $entry['slug'] ) ) {
				delete_transient( 'sdi_theme_switched' );
				printf(
					'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
					/* translators: %s: theme name */
					esc_html( sprintf( __( 'پوسته‌ی «%s» دمو آماده دارد.', 'saayeh-demo-importer' ), $entry['name'] ) ),
					esc_url( self::url() ),
					esc_html__( 'درون‌ریزی دمو', 'saayeh-demo-importer' )
				);
				return;
			}
		}
	}

	/**
	 * Enqueue assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'sdi-admin', SDI_URL . 'assets/css/admin.css', array(), SDI_VERSION );
		wp_enqueue_script( 'sdi-admin', SDI_URL . 'assets/js/admin.js', array( 'jquery' ), SDI_VERSION, true );

		$run = SDI_State::get_run();
		wp_localize_script(
			'sdi-admin',
			'SDI',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( SDI_Ajax::NONCE ),
				'run'     => $run ? SDI_Ajax::public_state( $run, array_slice( $run['log'], -50 ) ) : null,
				'i18n'    => array(
					'confirmTitle'   => __( 'درون‌ریزی دمو', 'saayeh-demo-importer' ),
					'confirmText'    => __( 'محتوای دمو به سایت افزوده می‌شود و برگه‌ی نخست، منوها و برخی تنظیمات ووکامرس تغییر می‌کنند. از تنظیمات فعلی پشتیبان گرفته می‌شود و بعداً می‌توانید همه‌چیز را حذف کنید. ادامه می‌دهید؟', 'saayeh-demo-importer' ),
					'confirmYes'     => __( 'بله، درون‌ریزی کن', 'saayeh-demo-importer' ),
					'removeTitle'    => __( 'حذف محتوای دمو', 'saayeh-demo-importer' ),
					'removeText'     => __( 'همه‌ی محصولات، برگه‌ها، نوشته‌ها، تصاویر، منوها، کوپن‌ها و منطقه‌ی حمل‌ونقلی که درون‌ریز ساخته است برای همیشه حذف و تنظیمات قبلی بازگردانی می‌شود. محتوایی که خودتان ساخته‌اید دست نمی‌خورد. ادامه می‌دهید؟', 'saayeh-demo-importer' ),
					'removeYes'      => __( 'بله، حذف کن', 'saayeh-demo-importer' ),
					'cancel'         => __( 'انصراف', 'saayeh-demo-importer' ),
					'importing'      => __( 'در حال درون‌ریزی…', 'saayeh-demo-importer' ),
					'removing'       => __( 'در حال حذف…', 'saayeh-demo-importer' ),
					'done'           => __( 'درون‌ریزی کامل شد.', 'saayeh-demo-importer' ),
					'importedNow'    => __( '✓ دمو همین حالا درون‌ریزی شد.', 'saayeh-demo-importer' ),
					'removed'        => __( 'محتوای دمو حذف شد.', 'saayeh-demo-importer' ),
					'error'          => __( 'خطا رخ داد.', 'saayeh-demo-importer' ),
					'network'        => __( 'ارتباط با سرور برقرار نشد یا پاسخ نامعتبر بود.', 'saayeh-demo-importer' ),
					'retry'          => __( 'تلاش دوباره', 'saayeh-demo-importer' ),
					'stop'           => __( 'توقف', 'saayeh-demo-importer' ),
					'stopped'        => __( 'درون‌ریزی متوقف شد. می‌توانید بعداً ادامه دهید.', 'saayeh-demo-importer' ),
					'retrying'       => __( 'تلاش دوباره…', 'saayeh-demo-importer' ),
					'noGroups'       => __( 'دست‌کم یک گزینه را انتخاب کنید.', 'saayeh-demo-importer' ),
					'viewSite'       => __( 'مشاهده‌ی سایت', 'saayeh-demo-importer' ),
					'step'           => __( 'مرحله', 'saayeh-demo-importer' ),
					'of'             => __( 'از', 'saayeh-demo-importer' ),
					'remaining'      => __( 'مورد باقی‌مانده', 'saayeh-demo-importer' ),
				),
				'homeUrl' => home_url( '/' ),
			)
		);
	}

	/**
	 * Format a number with Persian digits.
	 *
	 * @param int $n Number.
	 * @return string
	 */
	public static function num( $n ) {
		$s = number_format( (int) $n );
		return strtr( $s, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬' ) );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'شما اجازه‌ی دسترسی به این صفحه را ندارید.', 'saayeh-demo-importer' ) );
		}
		$themes = SDI_Themes::get_all();
		$groups = SDI_Importer::option_groups();
		$woo    = SDI_Util::woocommerce_active();
		?>
		<div class="wrap sdi-wrap" dir="rtl">
			<header class="sdi-header">
				<div>
					<h1 class="sdi-title"><?php esc_html_e( 'درون‌ریزی دمو', 'saayeh-demo-importer' ); ?></h1>
					<p class="sdi-subtitle"><?php esc_html_e( 'محتوای نمایشی پوسته را با یک کلیک درون‌ریزی کنید و هر زمان خواستید کامل حذفش کنید.', 'saayeh-demo-importer' ); ?></p>
				</div>
			</header>
			<hr class="wp-header-end">

			<?php if ( ! $woo ) : ?>
				<div class="notice notice-warning sdi-notice">
					<p>
						<strong><?php esc_html_e( 'ووکامرس فعال نیست.', 'saayeh-demo-importer' ); ?></strong>
						<?php esc_html_e( 'برای درون‌ریزی محصولات، کوپن‌ها و حمل‌ونقل، افزونه‌ی ووکامرس باید نصب و فعال باشد.', 'saayeh-demo-importer' ); ?>
						<?php if ( current_user_can( 'install_plugins' ) ) : ?>
							<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>"><?php echo esc_html( SDI_Util::woocommerce_installed() ? __( 'فعال‌سازی ووکامرس', 'saayeh-demo-importer' ) : __( 'نصب ووکامرس', 'saayeh-demo-importer' ) ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="sdi-resume notice notice-info" hidden>
				<p>
					<?php esc_html_e( 'یک درون‌ریزی نیمه‌کاره پیدا شد.', 'saayeh-demo-importer' ); ?>
					<button type="button" class="button button-primary sdi-resume-btn"><?php esc_html_e( 'ادامه‌ی درون‌ریزی', 'saayeh-demo-importer' ); ?></button>
					<button type="button" class="button sdi-discard-btn"><?php esc_html_e( 'کنار گذاشتن', 'saayeh-demo-importer' ); ?></button>
				</p>
			</div>

			<?php if ( ! $themes ) : ?>
				<div class="sdi-empty"><?php esc_html_e( 'هیچ پوسته‌ای با بسته‌ی دمو پیدا نشد.', 'saayeh-demo-importer' ); ?></div>
			<?php endif; ?>

			<div class="sdi-grid">
				<?php
				foreach ( $themes as $entry ) {
					self::render_card( $entry, $groups );
				}
				?>
			</div>

			<div class="sdi-modal" hidden role="dialog" aria-modal="true" aria-labelledby="sdi-modal-title">
				<div class="sdi-modal__backdrop" data-sdi-close></div>
				<div class="sdi-modal__box">
					<h2 id="sdi-modal-title" class="sdi-modal__title"></h2>
					<p class="sdi-modal__text"></p>
					<div class="sdi-modal__actions">
						<button type="button" class="button button-primary sdi-modal__ok"></button>
						<button type="button" class="button sdi-modal__cancel" data-sdi-close><?php esc_html_e( 'انصراف', 'saayeh-demo-importer' ); ?></button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one theme card.
	 *
	 * @param array $entry  Theme entry.
	 * @param array $groups Option groups.
	 */
	protected static function render_card( $entry, $groups ) {
		$package  = null;
		$error    = '';
		$summary  = array();
		$reqs     = array();
		$imported = SDI_State::get_imported( $entry['slug'] );
		$flagged  = $entry['installed'] ? array_sum( SDI_Util::count_flagged( $entry['slug'] ) ) : 0;

		if ( $entry['installed'] && $entry['theme'] ) {
			$package = SDI_Themes::load_package( $entry );
			if ( is_wp_error( $package ) ) {
				$error   = $package->get_error_message();
				$package = null;
			} else {
				$summary = $package->summary();
				$reqs    = $package->requirements();
			}
		}
		$reqs_met   = $package ? $package->requirements_met() : false;
		$can_import = $entry['active'] && $package && $reqs_met;
		$status_cls = 'sdi-badge--' . str_replace( '_', '-', $entry['status'] );
		?>
		<section class="sdi-card<?php echo $entry['active'] ? ' is-active' : ''; ?>" data-theme="<?php echo esc_attr( $entry['slug'] ); ?>">
			<div class="sdi-card__media">
				<?php if ( $entry['screenshot'] ) : ?>
					<img src="<?php echo esc_url( $entry['screenshot'] ); ?>" alt="<?php echo esc_attr( $entry['name'] ); ?>" loading="lazy">
				<?php else : ?>
					<div class="sdi-card__placeholder" aria-hidden="true"><span class="dashicons dashicons-admin-appearance"></span></div>
				<?php endif; ?>
				<span class="sdi-badge <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( SDI_Themes::status_label( $entry['status'] ) ); ?></span>
			</div>

			<div class="sdi-card__body">
				<div class="sdi-card__head">
					<h2 class="sdi-card__name"><?php echo esc_html( $entry['name'] ); ?></h2>
					<?php if ( $entry['version'] ) : ?>
						<span class="sdi-version">
							<?php
							/* translators: %s: version */
							echo esc_html( sprintf( __( 'نسخه %s', 'saayeh-demo-importer' ), $entry['version'] ) );
							?>
						</span>
					<?php endif; ?>
				</div>

				<?php if ( $entry['active_via'] ) : ?>
					<p class="sdi-muted">
						<?php
						/* translators: %s: child theme name */
						echo esc_html( sprintf( __( 'فعال از طریق پوسته‌ی فرزند «%s»', 'saayeh-demo-importer' ), $entry['active_via_name'] ) );
						?>
					</p>
				<?php endif; ?>

				<?php if ( $summary ) : ?>
					<ul class="sdi-stats">
						<li><strong><?php echo esc_html( self::num( $summary['products'] ) ); ?></strong><span><?php esc_html_e( 'محصول', 'saayeh-demo-importer' ); ?></span></li>
						<li><strong><?php echo esc_html( self::num( $summary['pages'] ) ); ?></strong><span><?php esc_html_e( 'برگه', 'saayeh-demo-importer' ); ?></span></li>
						<li><strong><?php echo esc_html( self::num( $summary['posts'] ) ); ?></strong><span><?php esc_html_e( 'نوشته', 'saayeh-demo-importer' ); ?></span></li>
						<li><strong><?php echo esc_html( self::num( $summary['images'] ) ); ?></strong><span><?php esc_html_e( 'تصویر', 'saayeh-demo-importer' ); ?></span></li>
					</ul>
				<?php endif; ?>

				<?php if ( $error ) : ?>
					<div class="sdi-alert sdi-alert--error"><?php echo esc_html( $error ); ?></div>
				<?php endif; ?>

				<?php if ( SDI_Themes::STATUS_NOT_INSTALLED === $entry['status'] ) : ?>
					<p class="sdi-muted">
						<?php
						echo esc_html(
							$entry['installed']
								? __( 'این پوسته نصب است اما بسته‌ی دمو اعلام نکرده است؛ آن را به‌روزرسانی کنید.', 'saayeh-demo-importer' )
								: __( 'برای درون‌ریزی دمو، ابتدا فایل zip پوسته را بارگذاری و فعال کنید.', 'saayeh-demo-importer' )
						);
						?>
					</p>
					<?php if ( ! $entry['installed'] && current_user_can( 'install_themes' ) ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'theme-install.php?upload' ) ); ?>"><?php esc_html_e( 'بارگذاری پوسته', 'saayeh-demo-importer' ); ?></a>
					<?php endif; ?>
				<?php elseif ( ! $entry['active'] ) : ?>
					<p class="sdi-muted"><?php esc_html_e( 'درون‌ریزی فقط برای پوسته‌ی فعال ممکن است.', 'saayeh-demo-importer' ); ?></p>
					<?php if ( current_user_can( 'switch_themes' ) ) : ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'themes.php?action=activate&stylesheet=' . rawurlencode( $entry['slug'] ) ), 'switch-theme_' . $entry['slug'] ) ); ?>"><?php esc_html_e( 'فعال‌سازی پوسته', 'saayeh-demo-importer' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( $reqs ) : ?>
					<ul class="sdi-reqs">
						<?php foreach ( $reqs as $r ) : ?>
							<li class="<?php echo $r['met'] ? 'is-met' : 'is-missing'; ?>">
								<span class="dashicons <?php echo $r['met'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
								<?php
								echo esc_html(
									$r['met']
										/* translators: %s: requirement */
										? sprintf( __( '%s فعال است', 'saayeh-demo-importer' ), $r['label'] )
										/* translators: %s: requirement */
										: sprintf( __( '%s لازم است', 'saayeh-demo-importer' ), $r['label'] )
								);
								?>
								<?php if ( ! $r['met'] && 'woocommerce' === $r['slug'] && current_user_can( 'install_plugins' ) ) : ?>
									— <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>"><?php esc_html_e( 'نصب/فعال‌سازی', 'saayeh-demo-importer' ); ?></a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( $entry['installed'] && $package ) : ?>
					<div class="sdi-status <?php echo $imported ? 'is-imported' : ''; ?>">
						<?php if ( $imported ) : ?>
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php
							/* translators: %s: date */
							echo esc_html( sprintf( __( 'دمو درون‌ریزی شده است — %s', 'saayeh-demo-importer' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $imported['time'] ) ) );
							?>
						<?php elseif ( $flagged ) : ?>
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							<?php esc_html_e( 'بخشی از دمو درون‌ریزی شده است (نیمه‌کاره).', 'saayeh-demo-importer' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-minus" aria-hidden="true"></span>
							<?php esc_html_e( 'دمو هنوز درون‌ریزی نشده است.', 'saayeh-demo-importer' ); ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( $entry['active'] && $package ) : ?>
					<fieldset class="sdi-options" <?php disabled( ! $can_import ); ?>>
						<legend><?php esc_html_e( 'چه چیزهایی درون‌ریزی شود؟', 'saayeh-demo-importer' ); ?></legend>
						<?php foreach ( $groups as $key => $label ) : ?>
							<label class="sdi-check">
								<input type="checkbox" name="sdi_groups[]" value="<?php echo esc_attr( $key ); ?>" checked>
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>

				<div class="sdi-actions">
					<?php if ( $entry['installed'] && $package ) : ?>
						<button type="button" class="button button-primary button-hero sdi-import-btn" <?php disabled( ! $can_import ); ?>>
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php esc_html_e( 'درون‌ریزی دمو', 'saayeh-demo-importer' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( $imported || $flagged ) : ?>
						<button type="button" class="button button-link-delete sdi-remove-btn">
							<?php esc_html_e( 'حذف محتوای دمو', 'saayeh-demo-importer' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="sdi-progress" hidden>
					<div class="sdi-progress__meta">
						<span class="sdi-progress__label"></span>
						<span class="sdi-progress__pct">0%</span>
					</div>
					<div class="sdi-progress__track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
						<div class="sdi-progress__bar"></div>
					</div>
					<div class="sdi-progress__controls">
						<button type="button" class="button sdi-retry-btn" hidden><?php esc_html_e( 'تلاش دوباره', 'saayeh-demo-importer' ); ?></button>
						<button type="button" class="button sdi-stop-btn" hidden><?php esc_html_e( 'توقف', 'saayeh-demo-importer' ); ?></button>
						<a class="button sdi-view-btn" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener" hidden><?php esc_html_e( 'مشاهده‌ی سایت', 'saayeh-demo-importer' ); ?></a>
					</div>
					<ol class="sdi-log" aria-live="polite"></ol>
				</div>
			</div>
		</section>
		<?php
	}
}
