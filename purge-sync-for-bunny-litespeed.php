<?php
/**
 * Plugin Name:       Purge Sync for Bunny and LiteSpeed
 * Description:       Clears Bunny when LiteSpeed Cache clears a page or the whole site.
 * Version:           1.1.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Tested up to:      7.1.3
 * Author:            Darkhorse
 * Author URI:        https://github.com/paramedicspecialist
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       purge-sync-for-bunny-litespeed
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/legacy.php';
bpl_legacy_boot();

// The 1.0.x plugin loaded first and declares the same functions; it is retired on admin_init.
// Functions must not be declared in this file: PHP binds them before this return runs.
if ( defined( 'BPL_VERSION' ) ) {
	return;
}

add_filter( 'option_active_plugins', 'bpl_hide_legacy_plugin' );

define( 'BPL_VERSION', '1.1.0' );
define( 'BPL_FILE', __FILE__ );
define( 'BPL_DIR', __DIR__ );

require_once BPL_DIR . '/includes/crypto.php';
require_once BPL_DIR . '/includes/log.php';
require_once BPL_DIR . '/includes/client.php';
require_once BPL_DIR . '/includes/headers.php';
require_once BPL_DIR . '/includes/purge.php';
require_once BPL_DIR . '/includes/settings.php';
require_once BPL_DIR . '/includes/boot.php';
