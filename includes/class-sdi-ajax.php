<?php
/**
 * AJAX endpoints and the run controller behind them.
 *
 * Endpoints (all POST, nonce `sdi_admin`, capability `manage_options`):
 *  - sdi_import_start   theme, groups[], resume
 *  - sdi_import_step    (runs one unit of the current step)
 *  - sdi_import_cancel
 *  - sdi_remove         theme (one batch per request)
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX controller.
 */
class SDI_Ajax {

	const NONCE = 'sdi_admin';

	/**
	 * Hook endpoints.
	 */
	public static function init() {
		add_action( 'wp_ajax_sdi_import_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_sdi_import_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_sdi_import_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_sdi_remove', array( __CLASS__, 'ajax_remove' ) );
	}

	/**
	 * Verify nonce + capability or die with JSON.
	 */
	protected static function guard() {
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست شما منقضی شده است؛ صفحه را تازه کنید.', 'saayeh-demo-importer' ) ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'شما اجازه‌ی این کار را ندارید.', 'saayeh-demo-importer' ) ), 403 );
		}
	}

	/**
	 * Read a POSTed theme slug.
	 *
	 * @return string
	 */
	protected static function posted_theme() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		return isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';
	}

	/**
	 * Send a result (array or WP_Error).
	 *
	 * @param array|WP_Error $result Result.
	 */
	protected static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			wp_send_json_error(
				array_merge(
					is_array( $data ) ? $data : array(),
					array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
					)
				)
			);
		}
		wp_send_json_success( $result );
	}

	/** AJAX: start. */
	public static function ajax_start() {
		self::guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$groups = isset( $_POST['groups'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['groups'] ) ) : array();
		$resume = ! empty( $_POST['resume'] );
		// phpcs:enable
		self::respond( self::start( self::posted_theme(), $groups, $resume ) );
	}

	/** AJAX: step. */
	public static function ajax_step() {
		self::guard();
		self::respond( self::step() );
	}

	/** AJAX: cancel. */
	public static function ajax_cancel() {
		self::guard();
		SDI_State::clear_run();
		SDI_State::unlock();
		self::respond( array( 'cancelled' => true ) );
	}

	/** AJAX: remove one batch. */
	public static function ajax_remove() {
		self::guard();
		self::respond( self::remove_batch( self::posted_theme() ) );
	}

	/* ========================================================= controller */

	/**
	 * Resolve an importable theme: installed, active, package valid, requirements met.
	 *
	 * @param string $slug Theme slug.
	 * @return SDI_Package|WP_Error
	 */
	public static function importable_package( $slug ) {
		$entry = SDI_Themes::get( $slug );
		if ( ! $entry || ! $entry['installed'] || empty( $entry['theme'] ) ) {
			return new WP_Error( 'sdi_theme', __( 'این پوسته نصب نیست یا بسته‌ی دمو ندارد.', 'saayeh-demo-importer' ) );
		}
		if ( ! $entry['active'] ) {
			return new WP_Error( 'sdi_inactive', __( 'درون‌ریزی فقط برای پوسته‌ی فعال ممکن است. ابتدا پوسته را فعال کنید.', 'saayeh-demo-importer' ) );
		}
		$package = SDI_Themes::load_package( $entry );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		if ( ! $package->requirements_met() ) {
			return new WP_Error( 'sdi_requirements', __( 'پیش‌نیازهای دمو (مانند ووکامرس) فعال نیستند.', 'saayeh-demo-importer' ) );
		}
		return $package;
	}

	/**
	 * Start (or resume) an import run.
	 *
	 * @param string $slug   Theme slug.
	 * @param array  $groups Selected option groups.
	 * @param bool   $resume Resume an existing run when possible.
	 * @return array|WP_Error
	 */
	public static function start( $slug, array $groups, $resume = false ) {
		$package = self::importable_package( $slug );
		if ( is_wp_error( $package ) ) {
			return $package;
		}
		$run = SDI_State::get_run();
		if ( $resume && $run && $run['theme'] === $package->theme_slug ) {
			$run['status'] = 'running';
			SDI_State::save_run( $run );
			return self::public_state( $run, array() );
		}

		$groups = array_values( array_intersect( $groups, array_keys( SDI_Importer::option_groups() ) ) );
		if ( ! $groups ) {
			return new WP_Error( 'sdi_groups', __( 'دست‌کم یک گزینه برای درون‌ریزی انتخاب کنید.', 'saayeh-demo-importer' ) );
		}

		SDI_State::unlock();
		SDI_Importer::maybe_backup( $package );
		$importer = new SDI_Importer( $package );
		$steps    = $importer->steps_for_groups( $groups );
		$all      = $importer->get_steps();
		$labels   = array();
		foreach ( $steps as $id ) {
			$labels[ $id ] = $all[ $id ]['label'];
		}
		$run = array(
			'theme'   => $package->theme_slug,
			'groups'  => $groups,
			'steps'   => $steps,
			'labels'  => $labels,
			'index'   => 0,
			'cursor'  => 0,
			'total'   => 0,
			'status'  => 'running',
			'error'   => '',
			'started' => time(),
			'log'     => array(),
		);
		$log = array(
			array(
				'level'   => 'info',
				'message' => __( 'درون‌ریزی آغاز شد؛ از تنظیمات فعلی پشتیبان گرفته شد.', 'saayeh-demo-importer' ),
				'time'    => time(),
			),
		);
		$run['log'] = $log;
		SDI_State::save_run( $run );
		do_action( 'sdi_import_started', $package->theme_slug, $groups );
		return self::public_state( $run, $log );
	}

	/**
	 * Run one unit of work.
	 *
	 * @return array|WP_Error
	 */
	public static function step() {
		$run = SDI_State::get_run();
		if ( ! $run ) {
			return new WP_Error( 'sdi_no_run', __( 'هیچ درون‌ریزی در جریان نیست.', 'saayeh-demo-importer' ) );
		}
		if ( ! SDI_State::lock() ) {
			return new WP_Error( 'sdi_locked', __( 'مرحله‌ی دیگری در حال اجراست؛ چند لحظه بعد دوباره امتحان می‌شود.', 'saayeh-demo-importer' ), array( 'retry' => true ) );
		}

		$package = self::importable_package( $run['theme'] );
		if ( is_wp_error( $package ) ) {
			SDI_State::unlock();
			return $package;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );
		wp_defer_term_counting( true );

		$importer = new SDI_Importer( $package );
		$count    = count( $run['steps'] );
		$log      = array();

		if ( $run['index'] < $count ) {
			$step_id = $run['steps'][ $run['index'] ];
			$label   = isset( $run['labels'][ $step_id ] ) ? $run['labels'][ $step_id ] : $step_id;
			if ( 0 === (int) $run['cursor'] ) {
				/* translators: %s: step label */
				$importer->log( sprintf( __( 'مرحله: %s', 'saayeh-demo-importer' ), $label ), 'step' );
			}
			try {
				$result = $importer->run_step( $step_id, (int) $run['cursor'] );
			} catch ( Throwable $e ) {
				$result = new WP_Error( 'sdi_exception', $e->getMessage() );
			}
			wp_defer_term_counting( false );
			if ( is_wp_error( $result ) ) {
				$importer->log(
					/* translators: 1: step, 2: error */
					sprintf( __( 'خطا در مرحله‌ی «%1$s»: %2$s', 'saayeh-demo-importer' ), $label, $result->get_error_message() ),
					'error'
				);
				$run['status'] = 'error';
				$run['error']  = $result->get_error_message();
				$run['log']    = array_merge( $run['log'], $importer->log );
				SDI_State::save_run( $run );
				SDI_State::unlock();
				return new WP_Error(
					'sdi_step_failed',
					$run['error'],
					array_merge( self::public_state( $run, $importer->log ), array( 'retry' => true ) )
				);
			}

			$run['total'] = (int) $result['total'];
			if ( $result['done'] ) {
				++$run['index'];
				$run['cursor'] = 0;
				$run['total']  = 0;
			} else {
				$run['cursor'] = (int) $result['cursor'];
			}
		}
		wp_defer_term_counting( false );
		$log = $importer->log;

		$run['status'] = 'running';
		$run['error']  = '';
		if ( $run['index'] >= $count ) {
			$run['status'] = 'done';
			$record        = array(
				'time'    => time(),
				'groups'  => $run['groups'],
				'version' => SDI_VERSION,
				'counts'  => SDI_Util::count_flagged( $run['theme'] ),
			);
			SDI_State::set_imported( $run['theme'], $record );
			$log[] = array(
				'level'   => 'success',
				'message' => __( 'درون‌ریزی دمو با موفقیت به پایان رسید.', 'saayeh-demo-importer' ),
				'time'    => time(),
			);
			do_action( 'sdi_import_completed', $run['theme'], $record );
			$state = self::public_state( $run, $log );
			SDI_State::clear_run();
			SDI_State::unlock();
			return $state;
		}

		$run['log'] = array_merge( $run['log'], $log );
		SDI_State::save_run( $run );
		SDI_State::unlock();
		return self::public_state( $run, $log );
	}

	/**
	 * Remove one batch of demo content.
	 *
	 * @param string $slug Theme slug.
	 * @return array|WP_Error
	 */
	public static function remove_batch( $slug ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'sdi_theme', __( 'پوسته مشخص نشده است.', 'saayeh-demo-importer' ) );
		}
		$run = SDI_State::get_run();
		if ( $run && $run['theme'] === $slug && 'running' === $run['status'] && get_transient( SDI_State::LOCK ) ) {
			return new WP_Error( 'sdi_busy', __( 'درون‌ریزی در حال اجراست؛ ابتدا آن را متوقف کنید.', 'saayeh-demo-importer' ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );
		$remover = new SDI_Remover( $slug );
		try {
			$result = $remover->run_batch();
		} catch ( Throwable $e ) {
			return new WP_Error( 'sdi_remove_failed', $e->getMessage(), array( 'retry' => true ) );
		}
		$result['log'] = $remover->log;
		return $result;
	}

	/**
	 * Public view of a run for the UI.
	 *
	 * @param array $run Run.
	 * @param array $log New log lines.
	 * @return array
	 */
	public static function public_state( $run, $log ) {
		$count    = max( 1, count( $run['steps'] ) );
		$fraction = ( ! empty( $run['total'] ) ) ? min( 1, $run['cursor'] / $run['total'] ) : 0;
		$percent  = 'done' === $run['status'] ? 100 : (int) floor( ( ( $run['index'] + $fraction ) / $count ) * 100 );
		$current  = isset( $run['steps'][ $run['index'] ] ) ? $run['steps'][ $run['index'] ] : '';
		return array(
			'theme'    => $run['theme'],
			'status'   => $run['status'],
			'percent'  => $percent,
			'index'    => (int) $run['index'],
			'steps'    => $run['steps'],
			'labels'   => $run['labels'],
			'current'  => $current,
			'label'    => $current && isset( $run['labels'][ $current ] ) ? $run['labels'][ $current ] : '',
			'cursor'   => (int) $run['cursor'],
			'total'    => (int) $run['total'],
			'done'     => 'done' === $run['status'],
			'log'      => array_values( $log ),
		);
	}
}
