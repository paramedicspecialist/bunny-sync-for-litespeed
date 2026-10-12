<?php
/**
 * Remove stored secrets and records when the plugin is deleted.
 *
 * @package BunnyPurgeLiteSpeed
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete this plugin's options for the current site.
 *
 * @return void
 */
function bpl_uninstall_site() {
	$options = array(
		'bunny_litespeed_api_key',
		'bunny_litespeed_api_fingerprint',
		'bunny_litespeed_settings',
		'bunny_litespeed_zone_flags',
		'bunny_litespeed_purge_log',
		'bunny_litespeed_warmed_pullzone',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'bpl_auth_failed' );
	delete_transient( 'bpl_key_unreadable' );
	bpl_delete_plugin_transients();
}

/**
 * Delete rate-limit and purge-notice transients for this site.
 *
 * @return void
 */
function bpl_delete_plugin_transients() {
	global $wpdb;

	$transient_like = $wpdb->esc_like( '_transient_bpl_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_bpl_' ) . '%';
	$names          = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
			$transient_like,
			$timeout_like
		)
	);

	if ( ! is_array( $names ) ) {
		return;
	}

	foreach ( $names as $name ) {
		$key = substr( (string) $name, strlen( '_transient_' ) );
		if ( '' !== $key ) {
			delete_transient( $key );
		}
	}
}

if ( is_multisite() ) {
	$bpl_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $bpl_site_ids as $bpl_site_id ) {
		switch_to_blog( (int) $bpl_site_id );
		bpl_uninstall_site();
		restore_current_blog();
	}
} else {
	bpl_uninstall_site();
}
