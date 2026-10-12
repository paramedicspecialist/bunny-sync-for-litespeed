<?php
/**
 * Load the plugin libraries without WordPress.
 *
 * @package BunnyPurgeLiteSpeed
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'BPL_VERSION' ) ) {
	define( 'BPL_VERSION', '1.0.0' );
}

$bpl_root = dirname( __DIR__ );
require_once $bpl_root . '/includes/crypto.php';
require_once $bpl_root . '/includes/log.php';
require_once $bpl_root . '/includes/client.php';
require_once $bpl_root . '/includes/headers.php';
require_once $bpl_root . '/includes/purge.php';
