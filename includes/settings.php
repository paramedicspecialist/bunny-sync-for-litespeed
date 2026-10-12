<?php
/**
 * Settings screen, credential storage, and zone warnings.
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default settings. Editorial purges start enabled; the master switch does not.
 *
 * @return array<string, int|string>
 */
function bpl_default_settings() {
	return array(
		'enabled'                 => 0,
		'pullzone_id'             => 0,
		'cdn_hostname'            => '',
		'purge_elementor_library' => 1,
		'purge_customizer'        => 1,
		'purge_upgrades'          => 1,
	);
}

/**
 * Saved settings, filled with defaults.
 *
 * @param bool $reset Drop the in-request cache after a save.
 * @return array<string, int|string>
 */
function bpl_get_settings( $reset = false ) {
	static $settings = null;
	if ( $reset ) {
		$settings = null;
	}
	if ( null !== $settings ) {
		return $settings;
	}

	$stored = get_option( 'bunny_litespeed_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$merged   = array_merge( bpl_default_settings(), $stored );
	$settings = array(
		'enabled'                 => empty( $merged['enabled'] ) ? 0 : 1,
		'pullzone_id'             => absint( $merged['pullzone_id'] ),
		'cdn_hostname'            => bpl_sanitize_hostname( (string) $merged['cdn_hostname'] ),
		'purge_elementor_library' => empty( $merged['purge_elementor_library'] ) ? 0 : 1,
		'purge_customizer'        => empty( $merged['purge_customizer'] ) ? 0 : 1,
		'purge_upgrades'          => empty( $merged['purge_upgrades'] ) ? 0 : 1,
	);

	return $settings;
}

/**
 * True when purging can run. The API key is not decrypted here.
 *
 * @return bool
 */
function bpl_is_configured() {
	$settings = bpl_get_settings();
	if ( empty( $settings['enabled'] ) || empty( $settings['pullzone_id'] ) ) {
		return false;
	}

	if ( defined( 'BUNNY_LITESPEED_API_KEY' ) && is_string( BUNNY_LITESPEED_API_KEY ) && '' !== BUNNY_LITESPEED_API_KEY ) {
		return true;
	}

	$stored = get_option( 'bunny_litespeed_api_key', '' );
	return is_string( $stored ) && '' !== $stored;
}

/**
 * No separate CDN hostname. Bunny caches the site address itself.
 *
 * @return string
 */
function bpl_cdn_hostname() {
	return '';
}

/**
 * Bunny sits in front of the public site, the same way a reverse proxy does.
 *
 * Page and file purges use that site address. A hostname such as cdn.example.com is not used.
 *
 * @return string
 */
function bpl_delivery_mode() {
	return 'acceleration';
}

/**
 * Pull zone id saved by the official bunny.net plugin.
 *
 * @return int
 */
function bpl_suggested_pullzone_id() {
	$zone = get_option( 'bunnycdn_cdn_pullzone', array() );
	if ( is_array( $zone ) && isset( $zone['id'] ) ) {
		return absint( $zone['id'] );
	}

	return 0;
}

/**
 * Register the settings page and its handlers.
 *
 * @return void
 */
function bpl_settings_boot() {
	add_action( 'admin_menu', 'bpl_register_menu' );
	add_action( 'admin_init', 'bpl_handle_settings_post' );
	add_action( 'admin_notices', 'bpl_admin_notices' );
}

/**
 * Add the page under Settings.
 *
 * @return void
 */
function bpl_register_menu() {
	add_options_page(
		__( 'Purge Sync for Bunny and LiteSpeed', 'purge-sync-for-bunny-litespeed' ),
		__( 'Purge Sync', 'purge-sync-for-bunny-litespeed' ),
		'manage_options',
		'purge-sync-for-bunny-litespeed',
		'bpl_render_settings_page'
	);
}

/**
 * Settings page URL.
 *
 * @return string
 */
function bpl_settings_url() {
	return admin_url( 'options-general.php?page=purge-sync-for-bunny-litespeed' );
}

/**
 * Handle a settings form post, then redirect.
 *
 * @return void
 */
function bpl_handle_settings_post() {
	if ( ! current_user_can( 'manage_options' ) || empty( $_POST['bpl_action'] ) ) {
		return;
	}

	check_admin_referer( 'bpl_settings' );

	$action = sanitize_key( wp_unslash( $_POST['bpl_action'] ) );
	$notice = 'saved';

	if ( 'clear_log' === $action ) {
		update_option( 'bunny_litespeed_purge_log', array(), false );
		$notice = 'cleared';
	} elseif ( 'remove_key' === $action ) {
		delete_option( 'bunny_litespeed_api_key' );
		delete_option( 'bunny_litespeed_api_fingerprint' );
		delete_option( 'bunny_litespeed_warmed_pullzone' );
		$notice = 'removed';
	} elseif ( 'import' === $action ) {
		$notice = bpl_import_official_key();
	} elseif ( 'purge' === $action || 'test' === $action ) {
		$notice = bpl_save_settings_from_post( true );
		if ( ! bpl_notice_is_error( $notice ) ) {
			if ( 'purge' === $action ) {
				$code   = bpl_purge_zone_now( 'manual' );
				$notice = ( $code >= 200 && $code < 300 ) ? 'purged' : 'purge_failed';
			} else {
				$tested = bpl_test_connection();
				$notice = bpl_notice_is_error( $tested ) ? $tested : 'tested';
			}
		}
	} else {
		$notice = bpl_save_settings_from_post( true );
	}

	wp_safe_redirect( add_query_arg( 'bpl_notice', $notice, bpl_settings_url() ) );
	exit;
}

/**
 * Save the non-secret fields, and the key when requested.
 *
 * @param bool $store_key Whether a pasted key should be validated and stored.
 * @return string Notice code.
 */
function bpl_save_settings_from_post( $store_key ) {
	$current  = bpl_get_settings();
	$raw_zone = isset( $_POST['bpl_pullzone_id'] ) ? wp_unslash( $_POST['bpl_pullzone_id'] ) : 0;
	$raw_host = isset( $_POST['bpl_cdn_hostname'] ) ? wp_unslash( $_POST['bpl_cdn_hostname'] ) : '';
	$posted   = array(
		'enabled'                 => empty( $_POST['bpl_enabled'] ) ? 0 : 1,
		'pullzone_id'             => is_scalar( $raw_zone ) ? absint( $raw_zone ) : 0,
		'cdn_hostname'            => is_string( $raw_host ) ? bpl_sanitize_hostname( $raw_host ) : '',
		'purge_elementor_library' => empty( $_POST['bpl_purge_elementor_library'] ) ? 0 : 1,
		'purge_customizer'        => empty( $_POST['bpl_purge_customizer'] ) ? 0 : 1,
		'purge_upgrades'          => empty( $_POST['bpl_purge_upgrades'] ) ? 0 : 1,
	);

	if ( $posted['pullzone_id'] < 1 ) {
		$posted['pullzone_id'] = (int) $current['pullzone_id'];
	}

	$notice   = 'saved';
	$raw_key  = isset( $_POST['bpl_api_key'] ) ? wp_unslash( $_POST['bpl_api_key'] ) : '';
	$pasted   = is_string( $raw_key ) ? bpl_sanitize_api_key( $raw_key ) : '';
	$key_lock = defined( 'BUNNY_LITESPEED_API_KEY' ) && is_string( BUNNY_LITESPEED_API_KEY ) && '' !== BUNNY_LITESPEED_API_KEY;

	if ( $store_key && ! $key_lock && '' !== $pasted ) {
		if ( ! bpl_encryption_available() ) {
			return 'openssl';
		}
		if ( $posted['pullzone_id'] < 1 ) {
			return 'need_zone';
		}
		$check = bpl_validate_credentials( $pasted, (int) $posted['pullzone_id'] );
		if ( ! $check['ok'] ) {
			return 'key_' . $check['error'];
		}
		if ( ! bpl_store_api_key( $pasted ) ) {
			return 'openssl';
		}
		bpl_store_zone_flags( $check['flags'] );
	} elseif ( $posted['pullzone_id'] !== (int) $current['pullzone_id'] && $posted['pullzone_id'] > 0 && '' !== bpl_get_api_key() ) {
		$check = bpl_validate_credentials( bpl_get_api_key(), (int) $posted['pullzone_id'] );
		if ( ! $check['ok'] ) {
			$posted['pullzone_id'] = (int) $current['pullzone_id'];
			$notice                = 'key_' . $check['error'];
		} else {
			bpl_store_zone_flags( $check['flags'] );
		}
	}

	update_option( 'bunny_litespeed_settings', $posted, false );
	bpl_get_settings( true );

	$warmed = (int) get_option( 'bunny_litespeed_warmed_pullzone', 0 );
	if ( $store_key && ! bpl_notice_is_error( $notice ) && bpl_is_configured() && $warmed !== (int) $posted['pullzone_id'] ) {
		$code = bpl_purge_zone_now( 'first_save' );
		if ( $code >= 200 && $code < 300 ) {
			update_option( 'bunny_litespeed_warmed_pullzone', (int) $posted['pullzone_id'], false );
			if ( 'saved' === $notice ) {
				$notice = 'warmed';
			}
		}
	}

	return $notice;
}

/**
 * Remember zone flags without the rest of the API payload.
 *
 * @param array<string, bool|int> $flags Flags from bpl_zone_flags_from_payload().
 * @return void
 */
function bpl_store_zone_flags( array $flags ) {
	$flags['checked_at'] = time();
	update_option( 'bunny_litespeed_zone_flags', $flags, false );
}

/**
 * Import the official plugin's plaintext key into our encrypted option.
 *
 * @return string Notice code.
 */
function bpl_import_official_key() {
	if ( defined( 'BUNNY_LITESPEED_API_KEY' ) && is_string( BUNNY_LITESPEED_API_KEY ) && '' !== BUNNY_LITESPEED_API_KEY ) {
		return 'key_locked';
	}
	if ( ! bpl_encryption_available() ) {
		return 'openssl';
	}

	$existing = get_option( 'bunny_litespeed_api_key', '' );
	if ( is_string( $existing ) && '' !== $existing ) {
		return 'saved';
	}

	$key = bpl_sanitize_api_key( (string) get_option( 'bunnycdn_api_key', '' ) );
	if ( '' === $key ) {
		return 'import_missing';
	}

	bpl_save_settings_from_post( false );
	$settings = bpl_get_settings();
	$zone     = (int) $settings['pullzone_id'];
	if ( $zone < 1 ) {
		$zone = bpl_suggested_pullzone_id();
	}
	if ( $zone < 1 ) {
		return 'need_zone';
	}

	$check = bpl_validate_credentials( $key, $zone );
	if ( ! $check['ok'] ) {
		return 'key_' . $check['error'];
	}
	if ( ! bpl_store_api_key( $key ) ) {
		return 'openssl';
	}

	$settings['enabled']     = 1;
	$settings['pullzone_id'] = $zone;
	update_option( 'bunny_litespeed_settings', $settings, false );
	bpl_get_settings( true );
	bpl_store_zone_flags( $check['flags'] );

	if ( bpl_is_configured() ) {
		$code = bpl_purge_zone_now( 'import' );
		if ( $code >= 200 && $code < 300 ) {
			update_option( 'bunny_litespeed_warmed_pullzone', $zone, false );
		}
	}

	return 'imported';
}

/**
 * Refresh zone flags with the saved key.
 *
 * @return string Notice code.
 */
function bpl_test_connection() {
	$settings = bpl_get_settings();
	$key      = bpl_get_api_key();
	if ( '' === $key || (int) $settings['pullzone_id'] < 1 ) {
		return 'need_zone';
	}

	$check = bpl_validate_credentials( $key, (int) $settings['pullzone_id'] );
	if ( ! $check['ok'] ) {
		return 'key_' . $check['error'];
	}

	bpl_store_zone_flags( $check['flags'] );
	return 'tested';
}

/**
 * One-time banner after a purge started from an edit or a LiteSpeed button.
 *
 * @return void
 */
function bpl_render_purge_banner() {
	$user_id = (int) get_current_user_id();
	if ( $user_id < 1 ) {
		return;
	}

	$key  = 'bpl_purge_notice_' . $user_id;
	$code = get_transient( $key );
	if ( ! is_string( $code ) || '' === $code ) {
		return;
	}
	delete_transient( $key );

	$messages = array(
		'cleared'       => __( 'Bunny cleared its cache for this edit.', 'purge-sync-for-bunny-litespeed' ),
		'cleared_zone'  => __( 'Bunny cleared the whole pull zone.', 'purge-sync-for-bunny-litespeed' ),
		'cleared_pages' => __( 'Bunny cleared the cached pages for this edit.', 'purge-sync-for-bunny-litespeed' ),
		'cleared_files' => __( 'Bunny cleared the updated files.', 'purge-sync-for-bunny-litespeed' ),
		'failed'        => __( 'Bunny could not clear its cache.', 'purge-sync-for-bunny-litespeed' ),
		'unauthorized'  => __( 'Bunny refused the API key, so the cache was not cleared.', 'purge-sync-for-bunny-litespeed' ),
		'capped'        => __( 'Bunny was not cleared. The per-minute purge cap was reached.', 'purge-sync-for-bunny-litespeed' ),
	);
	if ( ! isset( $messages[ $code ] ) ) {
		return;
	}

	$class = 'notice-success';
	if ( 'capped' === $code || 'failed' === $code ) {
		$class = 'notice-warning';
	} elseif ( 'unauthorized' === $code ) {
		$class = 'notice-error';
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ] );
	if ( current_user_can( 'manage_options' ) && in_array( $code, array( 'failed', 'unauthorized', 'capped' ), true ) ) {
		echo ' <a href="' . esc_url( bpl_settings_url() ) . '">' . esc_html__( 'View the Log', 'purge-sync-for-bunny-litespeed' ) . '</a>';
	}
	echo '</p></div>';
}

/**
 * Admin-wide notices that are not tied to a single form post.
 *
 * @return void
 */
function bpl_admin_notices() {
	if ( current_user_can( 'edit_posts' ) ) {
		bpl_render_purge_banner();
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( get_transient( 'bpl_auth_failed' ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Bunny refused the API key. Purges are paused until the key is accepted.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}

	if ( get_transient( 'bpl_key_unreadable' ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'The saved key cannot be read. Enter it again. This happens when the WordPress salts change.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'settings_page_purge-sync-for-bunny-litespeed' !== $screen->id ) {
		return;
	}

	if ( ! bpl_encryption_available() && ! ( defined( 'BUNNY_LITESPEED_API_KEY' ) && is_string( BUNNY_LITESPEED_API_KEY ) && '' !== BUNNY_LITESPEED_API_KEY ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'OpenSSL with AES-256-GCM is required before a key can be stored.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}

	if ( bpl_is_configured() && ! class_exists( '\LiteSpeed\Core' ) && ! class_exists( '\LiteSpeed\Purge' ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'LiteSpeed Cache is not active. Automatic purging waits until it is. The button on this screen can still clear Bunny.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}
}

/**
 * Whether a notice code means the action failed.
 *
 * @param string $code Notice code.
 * @return bool
 */
function bpl_notice_is_error( $code ) {
	return in_array(
		$code,
		array(
			'purge_failed',
			'openssl',
			'need_zone',
			'import_missing',
			'key_locked',
			'key_invalid',
			'key_unauthorized',
			'key_pullzone',
			'key_unavailable',
		),
		true
	);
}

/**
 * Map a notice code to a message.
 *
 * @param string $code Whitelisted code.
 * @return string
 */
function bpl_notice_message( $code ) {
	$messages = array(
		'saved'              => __( 'Settings saved.', 'purge-sync-for-bunny-litespeed' ),
		'warmed'             => __( 'Settings saved, and the pull zone was purged so the next pages can be tagged.', 'purge-sync-for-bunny-litespeed' ),
		'tested'             => __( 'Connection succeeded.', 'purge-sync-for-bunny-litespeed' ),
		'purged'             => __( 'The pull zone cache was purged.', 'purge-sync-for-bunny-litespeed' ),
		'purge_failed'       => __( 'The purge did not finish. See the log for the status.', 'purge-sync-for-bunny-litespeed' ),
		'cleared'            => __( 'The log was cleared.', 'purge-sync-for-bunny-litespeed' ),
		'removed'            => __( 'The key was removed from this plugin.', 'purge-sync-for-bunny-litespeed' ),
		'imported'           => __( 'The key was imported and encrypted. The bunny.net plugin still has its own copy.', 'purge-sync-for-bunny-litespeed' ),
		'import_missing'     => __( 'The bunny.net plugin does not have a key to import.', 'purge-sync-for-bunny-litespeed' ),
		'openssl'            => __( 'OpenSSL with AES-256-GCM is required before a key can be stored.', 'purge-sync-for-bunny-litespeed' ),
		'need_zone'          => __( 'Enter the pull zone ID and an API key first.', 'purge-sync-for-bunny-litespeed' ),
		'key_locked'         => __( 'The key is set in wp-config.php.', 'purge-sync-for-bunny-litespeed' ),
		'key_invalid'        => __( 'That key was not accepted.', 'purge-sync-for-bunny-litespeed' ),
		'key_unauthorized'   => __( 'Bunny refused the API key.', 'purge-sync-for-bunny-litespeed' ),
		'key_pullzone'       => __( 'That key cannot see this pull zone.', 'purge-sync-for-bunny-litespeed' ),
		'key_unavailable'    => __( 'Bunny could not be reached. Nothing secret was changed.', 'purge-sync-for-bunny-litespeed' ),
	);

	if ( ! isset( $messages[ $code ] ) ) {
		return '';
	}

	return $messages[ $code ];
}

/**
 * Render the settings screen.
 *
 * @return void
 */
function bpl_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$settings    = bpl_get_settings();
	$key_locked  = defined( 'BUNNY_LITESPEED_API_KEY' ) && is_string( BUNNY_LITESPEED_API_KEY ) && '' !== BUNNY_LITESPEED_API_KEY;
	$stored_key  = get_option( 'bunny_litespeed_api_key', '' );
	$has_key     = $key_locked || ( is_string( $stored_key ) && '' !== $stored_key );
	$fingerprint = (string) get_option( 'bunny_litespeed_api_fingerprint', '' );
	$official    = bpl_sanitize_api_key( (string) get_option( 'bunnycdn_api_key', '' ) );
	$zone_id     = (int) $settings['pullzone_id'];
	if ( $zone_id < 1 ) {
		$zone_id = bpl_suggested_pullzone_id();
	}
	$notice_code = isset( $_GET['bpl_notice'] ) ? sanitize_key( wp_unslash( $_GET['bpl_notice'] ) ) : '';
	$message     = bpl_notice_message( $notice_code );
	if ( '' !== $message ) {
		$class = bpl_notice_is_error( $notice_code ) ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Purge Sync for Bunny and LiteSpeed', 'purge-sync-for-bunny-litespeed' ) . '</h1>';
	echo '<p>' . esc_html__( 'Clears Bunny when LiteSpeed Cache clears a page or the whole site.', 'purge-sync-for-bunny-litespeed' ) . '</p>';

	bpl_render_mode_description();
	bpl_render_zone_warnings();

	if ( 'agency' === (string) get_option( 'bunnycdn_wizard_mode', '' ) && ! $has_key ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Agency mode in the bunny.net plugin does not store an API key. Paste one here.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}

	echo '<form method="post" action="' . esc_url( bpl_settings_url() ) . '" autocomplete="off">';
	wp_nonce_field( 'bpl_settings' );
	echo '<table class="form-table" role="presentation"><tbody>';

	echo '<tr><th scope="row">' . esc_html__( 'Enable Bunny Purging', 'purge-sync-for-bunny-litespeed' ) . '</th><td>';
	echo '<label><input type="checkbox" name="bpl_enabled" value="1" ' . checked( 1, (int) $settings['enabled'], false ) . ' /> ';
	echo esc_html__( 'Purge Bunny When LiteSpeed Clears Cache', 'purge-sync-for-bunny-litespeed' ) . '</label></td></tr>';

	echo '<tr><th scope="row"><label for="bpl_pullzone_id">' . esc_html__( 'Pull Zone ID', 'purge-sync-for-bunny-litespeed' ) . '</label></th><td>';
	echo '<input name="bpl_pullzone_id" id="bpl_pullzone_id" type="number" min="1" step="1" class="regular-text" value="' . esc_attr( (string) $zone_id ) . '" />';
	if ( bpl_suggested_pullzone_id() > 0 ) {
		echo '<p class="description">' . esc_html__( 'Suggested from the bunny.net plugin when this field was empty.', 'purge-sync-for-bunny-litespeed' ) . '</p>';
	}
	echo '</td></tr>';

	echo '<tr><th scope="row"><label for="bpl_api_key">' . esc_html__( 'API Key', 'purge-sync-for-bunny-litespeed' ) . '</label></th><td>';
	if ( $key_locked ) {
		echo '<input type="password" class="regular-text" value="" disabled="disabled" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'The key is set in wp-config.php.', 'purge-sync-for-bunny-litespeed' ) . '</p>';
	} else {
		echo '<input name="bpl_api_key" id="bpl_api_key" type="password" class="regular-text" value="" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Leave the field blank to keep the saved key. The key is encrypted before it is stored.', 'purge-sync-for-bunny-litespeed' ) . '</p>';
	}
	if ( $has_key ) {
		echo '<p>' . esc_html__( 'A key is saved.', 'purge-sync-for-bunny-litespeed' );
		if ( '' !== $fingerprint ) {
			echo ' ' . esc_html__( 'Fingerprint:', 'purge-sync-for-bunny-litespeed' ) . ' <code>' . esc_html( $fingerprint ) . '</code>';
		}
		echo '</p>';
	}
	echo '</td></tr>';

	echo '<tr><th scope="row">' . esc_html__( 'Also Purge the Whole Zone', 'purge-sync-for-bunny-litespeed' ) . '</th><td>';
	echo '<label><input type="checkbox" name="bpl_purge_elementor_library" value="1" ' . checked( 1, (int) $settings['purge_elementor_library'], false ) . ' /> ';
	echo esc_html__( 'When a Published Elementor Header, Footer, Kit, or Other Sitewide Template Is Saved', 'purge-sync-for-bunny-litespeed' ) . '</label><br />';
	echo '<label><input type="checkbox" name="bpl_purge_customizer" value="1" ' . checked( 1, (int) $settings['purge_customizer'], false ) . ' /> ';
	echo esc_html__( 'When the Customizer Is Saved', 'purge-sync-for-bunny-litespeed' ) . '</label><br />';
	echo '<label><input type="checkbox" name="bpl_purge_upgrades" value="1" ' . checked( 1, (int) $settings['purge_upgrades'], false ) . ' /> ';
	echo esc_html__( 'When a Plugin or Theme Update Finishes', 'purge-sync-for-bunny-litespeed' ) . '</label>';
	echo '</td></tr>';

	echo '</tbody></table>';
	echo '<p>';
	echo '<button type="submit" name="bpl_action" value="save" class="button button-primary">' . esc_html__( 'Save Settings', 'purge-sync-for-bunny-litespeed' ) . '</button> ';
	echo '<button type="submit" name="bpl_action" value="test" class="button">' . esc_html__( 'Test Connection', 'purge-sync-for-bunny-litespeed' ) . '</button> ';
	echo '<button type="submit" name="bpl_action" value="purge" class="button">' . esc_html__( 'Purge Bunny Now', 'purge-sync-for-bunny-litespeed' ) . '</button>';
	echo '</p>';

	if ( ! $has_key && '' !== $official && ! $key_locked ) {
		echo '<p><button type="submit" name="bpl_action" value="import" class="button">' . esc_html__( 'Import Key from bunny.net', 'purge-sync-for-bunny-litespeed' ) . '</button></p>';
	}
	if ( $has_key && ! $key_locked ) {
		echo '<p><button type="submit" name="bpl_action" value="remove_key" class="button">' . esc_html__( 'Remove Saved Key', 'purge-sync-for-bunny-litespeed' ) . '</button></p>';
	}

	echo '<h2>' . esc_html__( 'Purge Log', 'purge-sync-for-bunny-litespeed' ) . '</h2>';
	echo '<p>' . esc_html__( 'Newest 50 purge records. Older rows are dropped automatically.', 'purge-sync-for-bunny-litespeed' ) . '</p>';
	bpl_render_log();
	echo '<p><button type="submit" name="bpl_action" value="clear_log" class="button">' . esc_html__( 'Clear Log', 'purge-sync-for-bunny-litespeed' ) . '</button></p>';
	echo '</form></div>';
}

/**
 * Explain the detected Bunny mode.
 *
 * @return void
 */
function bpl_render_mode_description() {
	echo '<p>' . esc_html__( 'Bunny sits in front of this site’s own address, the same way a reverse proxy does. A LiteSpeed purge of a page or the whole site clears that same address at Bunny.', 'purge-sync-for-bunny-litespeed' ) . '</p>';
}

/**
 * Warnings from the last read-only zone check.
 *
 * @return void
 */
function bpl_render_zone_warnings() {
	$flags = get_option( 'bunny_litespeed_zone_flags', array() );
	if ( ! is_array( $flags ) || empty( $flags['checked_at'] ) ) {
		return;
	}

	if ( ! empty( $flags['perma_cache'] ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Perma-Cache is turned on. Tag purges do not apply, and a full purge can leave the previous Perma-Cache directory in place.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}
	if ( ! empty( $flags['cookie_vary_sensitive'] ) ) {
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'This pull zone varies the cache by a login or comment cookie. Personalized pages can be stored at the edge. Bypass those cookies in the Bunny dashboard.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}
	if ( empty( $flags['ignore_query_strings'] ) ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Query strings are part of the cache key. File purges clear every version of the file. Those purges count against Bunny\'s stricter limit.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}
	if ( ! empty( $flags['smart_cache'] ) ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Smart Cache is on. Bunny skips HTML unless an edge rule caches it. Tags still apply to HTML that is cached.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}
	if ( isset( $flags['browser_max_age'] ) && (int) $flags['browser_max_age'] > 300 ) {
		echo '<div class="notice notice-info"><p>' . esc_html__( 'The browser cache time is long enough that a visitor can keep a page after both caches are cleared.', 'purge-sync-for-bunny-litespeed' ) . '</p></div>';
	}
}

/**
 * Render the log table.
 *
 * @return void
 */
function bpl_render_log() {
	$entries = bpl_log_entries();
	if ( array() === $entries ) {
		echo '<p>' . esc_html__( 'No purge records yet.', 'purge-sync-for-bunny-litespeed' ) . '</p>';
		return;
	}

	echo '<table class="widefat striped"><thead><tr>';
	$columns = array(
		__( 'Time (UTC)', 'purge-sync-for-bunny-litespeed' ),
		__( 'Kind', 'purge-sync-for-bunny-litespeed' ),
		__( 'Trigger', 'purge-sync-for-bunny-litespeed' ),
		__( 'Count', 'purge-sync-for-bunny-litespeed' ),
		__( 'Status', 'purge-sync-for-bunny-litespeed' ),
		__( 'Detail', 'purge-sync-for-bunny-litespeed' ),
	);
	foreach ( $columns as $column ) {
		echo '<th scope="col">' . esc_html( $column ) . '</th>';
	}
	echo '</tr></thead><tbody>';

	foreach ( $entries as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}
		echo '<tr>';
		echo '<td>' . esc_html( isset( $entry['time'] ) ? (string) $entry['time'] : '' ) . '</td>';
		$kind = isset( $entry['kind'] ) ? (string) $entry['kind'] : '';
		$kind_labels = array(
			'tag'    => __( 'Tag', 'purge-sync-for-bunny-litespeed' ),
			'url'    => __( 'URL', 'purge-sync-for-bunny-litespeed' ),
			'zone'   => __( 'Zone', 'purge-sync-for-bunny-litespeed' ),
			'prefix' => __( 'Prefix', 'purge-sync-for-bunny-litespeed' ),
		);
		echo '<td>' . esc_html( isset( $kind_labels[ $kind ] ) ? $kind_labels[ $kind ] : $kind ) . '</td>';
		echo '<td>' . esc_html( isset( $entry['trigger'] ) ? (string) $entry['trigger'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $entry['count'] ) ? (string) $entry['count'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $entry['status'] ) ? (string) $entry['status'] : '' ) . '</td>';
		echo '<td>' . esc_html( isset( $entry['detail'] ) ? (string) $entry['detail'] : '' ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}
