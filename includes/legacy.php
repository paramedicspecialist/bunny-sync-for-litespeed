<?php
/**
 * Retire the 1.0.x plugin, which was installed as bunny-sync-for-litespeed.
 *
 * Both versions share option names, so the old copy must never run its uninstall.php.
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

const BPL_LEGACY_PLUGIN = 'bunny-sync-for-litespeed/bunny-sync-for-litespeed.php';

/**
 * Register the cleanup hooks. Runs whether or not the old plugin is loaded.
 *
 * @return void
 */
function bpl_legacy_boot() {
	add_action( 'admin_init', 'bpl_retire_legacy_plugin' );
	add_action( 'pre_uninstall_plugin', 'bpl_legacy_before_uninstall' );
	add_action( 'delete_plugin', 'bpl_legacy_after_uninstall' );
	add_action( 'admin_notices', 'bpl_legacy_notice' );
	add_action( 'network_admin_notices', 'bpl_legacy_notice' );
}

/**
 * Keep the old plugin from loading after this one, which would redeclare every function.
 *
 * @param mixed $plugins Active plugin basenames.
 * @return mixed
 */
function bpl_hide_legacy_plugin( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		return $plugins;
	}

	return array_values( array_diff( $plugins, array( BPL_LEGACY_PLUGIN ) ) );
}

/**
 * Deactivate the old plugin and remove its folder without running its uninstall.php.
 *
 * @return void
 */
function bpl_retire_legacy_plugin() {
	if ( ! current_user_can( 'activate_plugins' ) || ! file_exists( WP_PLUGIN_DIR . '/' . BPL_LEGACY_PLUGIN ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	remove_filter( 'option_active_plugins', 'bpl_hide_legacy_plugin' );

	$network = is_multisite() && is_plugin_active_for_network( BPL_LEGACY_PLUGIN );
	if ( $network && ! current_user_can( 'manage_network_plugins' ) ) {
		return;
	}

	$was_active = is_plugin_active( BPL_LEGACY_PLUGIN );
	if ( $was_active ) {
		deactivate_plugins( BPL_LEGACY_PLUGIN, true, $network );
	}

	$removed = bpl_remove_legacy_folder();
	set_transient( 'bpl_legacy_notice', $removed ? 'removed' : 'deactivated', 5 * MINUTE_IN_SECONDS );
}

/**
 * Delete the old plugin folder when WordPress can write files directly.
 *
 * @return bool True when the folder is gone.
 */
function bpl_remove_legacy_folder() {
	global $wp_filesystem;

	if ( ! current_user_can( 'delete_plugins' ) ) {
		return false;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( 'direct' !== get_filesystem_method( array(), WP_PLUGIN_DIR ) || ! WP_Filesystem() ) {
		return false;
	}

	$removed = (bool) $wp_filesystem->delete( trailingslashit( WP_PLUGIN_DIR ) . dirname( BPL_LEGACY_PLUGIN ), true, 'd' );
	wp_clean_plugins_cache( false );

	return $removed;
}

/**
 * Options shared by both versions.
 *
 * @return string[]
 */
function bpl_legacy_option_names() {
	return array(
		'bunny_litespeed_api_key',
		'bunny_litespeed_api_fingerprint',
		'bunny_litespeed_settings',
		'bunny_litespeed_zone_flags',
		'bunny_litespeed_purge_log',
		'bunny_litespeed_warmed_pullzone',
	);
}

/**
 * Site IDs whose options the old uninstall.php would delete.
 *
 * @return int[]
 */
function bpl_legacy_site_ids() {
	if ( ! is_multisite() ) {
		return array( get_current_blog_id() );
	}

	return array_map(
		'intval',
		get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		)
	);
}

/**
 * Save the shared options before the old uninstall.php deletes them.
 *
 * @param string $plugin Plugin basename being uninstalled.
 * @return void
 */
function bpl_legacy_before_uninstall( $plugin ) {
	if ( BPL_LEGACY_PLUGIN !== $plugin ) {
		return;
	}

	$snapshot = array();
	foreach ( bpl_legacy_site_ids() as $site_id ) {
		if ( is_multisite() ) {
			switch_to_blog( $site_id );
		}
		foreach ( bpl_legacy_option_names() as $name ) {
			$value = get_option( $name, null );
			if ( null !== $value ) {
				$snapshot[ $site_id ][ $name ] = $value;
			}
		}
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}

	$GLOBALS['bpl_legacy_snapshot'] = $snapshot;
}

/**
 * Put the shared options back once the old uninstall.php has run.
 *
 * @param string $plugin Plugin basename being deleted.
 * @return void
 */
function bpl_legacy_after_uninstall( $plugin ) {
	if ( BPL_LEGACY_PLUGIN !== $plugin || empty( $GLOBALS['bpl_legacy_snapshot'] ) || ! is_array( $GLOBALS['bpl_legacy_snapshot'] ) ) {
		return;
	}

	foreach ( $GLOBALS['bpl_legacy_snapshot'] as $site_id => $options ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $site_id );
		}
		foreach ( $options as $name => $value ) {
			update_option( $name, $value, false );
		}
		if ( is_multisite() ) {
			restore_current_blog();
		}
	}

	unset( $GLOBALS['bpl_legacy_snapshot'] );
}

/**
 * Report what happened to the old plugin.
 *
 * @return void
 */
function bpl_legacy_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$state = get_transient( 'bpl_legacy_notice' );
	if ( ! $state ) {
		return;
	}
	delete_transient( 'bpl_legacy_notice' );

	if ( 'removed' === $state ) {
		$message = __( 'Removed the old Bunny Sync for LiteSpeed plugin. Your settings, API key, and purge log were kept.', 'purge-sync-for-bunny-litespeed' );
	} else {
		$message = __( 'Deactivated the old Bunny Sync for LiteSpeed plugin. Delete it from the Plugins screen; your settings, API key, and purge log will be kept.', 'purge-sync-for-bunny-litespeed' );
	}

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
