<?php
/**
 * Plugin Name:       Bunny Sync for LiteSpeed
 * Description:       Clears Bunny when LiteSpeed Cache clears a page or the whole site.
 * Version:           1.0.1
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Tested up to:      7.1.3
 * Author:            Darkhorse
 * Author URI:        https://github.com/paramedicspecialist
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bunny-sync-for-litespeed
 *
 * @package BunnyPurgeLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

define( 'BPL_VERSION', '1.0.1' );
define( 'BPL_FILE', __FILE__ );
define( 'BPL_DIR', __DIR__ );

require_once BPL_DIR . '/includes/crypto.php';
require_once BPL_DIR . '/includes/log.php';
require_once BPL_DIR . '/includes/client.php';
require_once BPL_DIR . '/includes/headers.php';
require_once BPL_DIR . '/includes/purge.php';
require_once BPL_DIR . '/includes/settings.php';

/**
 * Load translations after WordPress is ready.
 *
 * WordPress 6.7 warns if translations load while the plugin file is included.
 *
 * @return void
 */
function bpl_load_textdomain() {
	load_plugin_textdomain(
		'bunny-sync-for-litespeed',
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
