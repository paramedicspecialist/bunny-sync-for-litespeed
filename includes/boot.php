<?php
/**
 * Request bootstrap.
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Load translations after WordPress is ready.
 *
 * WordPress 6.7 warns if translations load while the plugin file is included.
 *
 * @return void
 */
function bpl_load_textdomain() {
	load_plugin_textdomain(
		'purge-sync-for-bunny-litespeed',
		false,
		dirname( plugin_basename( BPL_FILE ) ) . '/languages'
	);
}

/**
 * Register admin and purge hooks for this request.
 *
 * @return void
 */
function bpl_boot() {
	if ( is_admin() ) {
		bpl_settings_boot();
	}

	if ( ! bpl_is_configured() ) {
		return;
	}

	bpl_headers_boot();
	bpl_purge_boot();
}

add_action( 'init', 'bpl_load_textdomain' );
add_action( 'plugins_loaded', 'bpl_boot' );
