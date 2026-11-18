<?php
/**
 * Persistent plugin state: the running import, backups, import records and a
 * registry of non-post objects created (attributes, shipping zones).
 *
 * @package SaayehDemoImporter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option-backed state store.
 */
class SDI_State {

	const OPT_STATE    = 'sdi_state';
	const OPT_BACKUP   = 'sdi_backup';
	const OPT_IMPORTED = 'sdi_imported';
	const OPT_REGISTRY = 'sdi_registry';
	const LOCK         = 'sdi_lock';

	/** Max log lines kept in state. */
	const LOG_MAX = 300;

	/**
	 * All plugin option names (used by uninstall.php as well).
	 *
	 * @return array
	 */
	public static function option_names() {
		return array( self::OPT_STATE, self::OPT_BACKUP, self::OPT_IMPORTED, self::OPT_REGISTRY );
	}

	/* ---------------------------------------------------------------- state */

	/**
	 * Current run state or null.
	 *
	 * @return array|null
	 */
	public static function get_run() {
		$state = get_option( self::OPT_STATE, null );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Save run state.
	 *
	 * @param array $state State.
	 */
	public static function save_run( array $state ) {
		$state['updated'] = time();
		if ( isset( $state['log'] ) && count( $state['log'] ) > self::LOG_MAX ) {
			$state['log'] = array_slice( $state['log'], -self::LOG_MAX );
		}
		update_option( self::OPT_STATE, $state, false );
	}

	/**
	 * Clear the run.
	 */
	public static function clear_run() {
		delete_option( self::OPT_STATE );
	}

	/* --------------------------------------------------------------- backup */

	/**
	 * Backup for a theme.
	 *
	 * @param string $theme Theme slug.
	 * @return array|null
	 */
	public static function get_backup( $theme ) {
		$all = get_option( self::OPT_BACKUP, array() );
		return ( is_array( $all ) && isset( $all[ $theme ] ) ) ? $all[ $theme ] : null;
	}

	/**
	 * Store backup for a theme.
	 *
	 * @param string $theme  Theme slug.
	 * @param array  $backup Backup.
	 */
	public static function set_backup( $theme, $backup ) {
		$all = get_option( self::OPT_BACKUP, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $backup ) {
			unset( $all[ $theme ] );
		} else {
			$all[ $theme ] = $backup;
		}
		update_option( self::OPT_BACKUP, $all, false );
	}

	/* -------------------------------------------------------------- imported */

	/**
	 * Import record for a theme.
	 *
	 * @param string $theme Theme slug.
	 * @return array|null
	 */
	public static function get_imported( $theme ) {
		$all = get_option( self::OPT_IMPORTED, array() );
		return ( is_array( $all ) && isset( $all[ $theme ] ) ) ? $all[ $theme ] : null;
	}

	/**
	 * Store import record.
	 *
	 * @param string     $theme  Theme slug.
	 * @param array|null $record Record or null to delete.
	 */
	public static function set_imported( $theme, $record ) {
		$all = get_option( self::OPT_IMPORTED, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $record ) {
			unset( $all[ $theme ] );
		} else {
			$all[ $theme ] = $record;
		}
		update_option( self::OPT_IMPORTED, $all, false );
	}

	/* -------------------------------------------------------------- registry */

	/**
	 * Registry entries of a kind for a theme.
	 *
	 * @param string $theme Theme slug.
	 * @param string $kind  Kind (attributes, shipping_zones, ...).
	 * @return array
	 */
	public static function registry( $theme, $kind ) {
		$all = get_option( self::OPT_REGISTRY, array() );
		return ( is_array( $all ) && isset( $all[ $theme ][ $kind ] ) ) ? (array) $all[ $theme ][ $kind ] : array();
	}

	/**
	 * Add a registry entry.
	 *
	 * @param string $theme Theme slug.
	 * @param string $kind  Kind.
	 * @param mixed  $value Value.
	 */
	public static function registry_add( $theme, $kind, $value ) {
		$all = get_option( self::OPT_REGISTRY, array() );
		$all = is_array( $all ) ? $all : array();
		if ( ! isset( $all[ $theme ][ $kind ] ) ) {
			$all[ $theme ][ $kind ] = array();
		}
		if ( ! in_array( $value, $all[ $theme ][ $kind ], true ) ) {
			$all[ $theme ][ $kind ][] = $value;
		}
		update_option( self::OPT_REGISTRY, $all, false );
	}

	/**
	 * Clear registry for a theme.
	 *
	 * @param string $theme Theme slug.
	 */
	public static function registry_clear( $theme ) {
		$all = get_option( self::OPT_REGISTRY, array() );
		$all = is_array( $all ) ? $all : array();
		unset( $all[ $theme ] );
		update_option( self::OPT_REGISTRY, $all, false );
	}

	/* ----------------------------------------------------------------- lock */

	/**
	 * Acquire the short-lived lock that prevents two step requests running at
	 * once.
	 *
	 * @return bool
	 */
	public static function lock() {
		if ( get_transient( self::LOCK ) ) {
			return false;
		}
		set_transient( self::LOCK, time(), 120 );
		return true;
	}

	/**
	 * Release the lock.
	 */
	public static function unlock() {
		delete_transient( self::LOCK );
	}
}
