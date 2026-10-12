<?php
/**
 * Encrypt the Bunny API key so a database copy is not the key itself.
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether AES-256-GCM can be used on this host.
 *
 * @return bool
 */
function bpl_encryption_available() {
	if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) || ! function_exists( 'openssl_get_cipher_methods' ) ) {
		return false;
	}

	$ciphers = openssl_get_cipher_methods();
	if ( ! is_array( $ciphers ) ) {
		return false;
	}

	return in_array( 'aes-256-gcm', $ciphers, true );
}

/**
 * Secret used to encrypt the API key. It stays outside the database.
 *
 * @return string
 */
function bpl_encryption_secret() {
	if ( defined( 'BUNNY_LITESPEED_SECRET' ) && is_string( BUNNY_LITESPEED_SECRET ) && '' !== BUNNY_LITESPEED_SECRET ) {
		return BUNNY_LITESPEED_SECRET;
	}

	if ( function_exists( 'wp_salt' ) ) {
		return (string) wp_salt( 'auth' );
	}

	return '';
}

/**
 * Encrypt a secret with AES-256-GCM.
 *
 * @param string $plaintext Value to store.
 * @param string $secret    Encryption secret.
 * @return string Base64 payload, or an empty string when encryption fails.
 */
function bpl_encrypt( $plaintext, $secret ) {
	if ( ! is_string( $plaintext ) || '' === $plaintext || ! is_string( $secret ) || '' === $secret || ! bpl_encryption_available() ) {
		return '';
	}

	$key    = hash( 'sha256', $secret, true );
	$iv     = random_bytes( 12 );
	$tag    = '';
	$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

	if ( ! is_string( $cipher ) || ! is_string( $tag ) || 16 !== strlen( $tag ) ) {
		return '';
	}

	return base64_encode( $iv . $tag . $cipher );
}

/**
 * Decrypt a payload produced by bpl_encrypt().
 *
 * @param string $stored Stored payload.
 * @param string $secret Encryption secret.
 * @return string Plaintext, or an empty string when the payload cannot be read.
 */
function bpl_decrypt( $stored, $secret ) {
	if ( ! is_string( $stored ) || '' === $stored || ! is_string( $secret ) || '' === $secret || ! bpl_encryption_available() ) {
		return '';
	}

	$raw = base64_decode( $stored, true );
	if ( ! is_string( $raw ) || strlen( $raw ) < 29 ) {
		return '';
	}

	$iv     = substr( $raw, 0, 12 );
	$tag    = substr( $raw, 12, 16 );
	$cipher = substr( $raw, 28 );
	$key    = hash( 'sha256', $secret, true );
	$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

	if ( ! is_string( $plain ) || '' === $plain ) {
		return '';
	}

	return $plain;
}

/**
 * Short display fingerprint. This is not a credential.
 *
 * @param string $key API key.
 * @return string
 */
function bpl_fingerprint( $key ) {
	if ( ! is_string( $key ) || '' === $key ) {
		return '';
	}

	return substr( hash( 'sha256', $key ), 0, 12 );
}
