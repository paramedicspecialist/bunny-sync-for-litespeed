<?php
/**
 * CDN-Tag header built from LiteSpeed's public cache tags.
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reduce a tag to characters that are safe in a response header.
 *
 * @param string $tag Raw tag.
 * @return string
 */
function bpl_sanitize_tag( $tag ) {
	$tag = (string) $tag;
	if ( false !== strpos( $tag, "\n" ) || false !== strpos( $tag, "\r" ) ) {
		return '';
	}

	$tag = preg_replace( '/[^A-Za-z0-9_.:-]/', '', $tag );
	if ( ! is_string( $tag ) ) {
		return '';
	}

	return $tag;
}

/**
 * Higher ranks are kept when the CDN-Tag header would pass 1024 bytes.
 *
 * @param string $tag Sanitized tag.
 * @return int
 */
function bpl_tag_rank( $tag ) {
	if ( 1 === preg_match( '/^[A-Za-z0-9]*_$/', $tag ) ) {
		return 100;
	}
	if ( false !== strpos( $tag, 'Po.' ) || 1 === preg_match( '/_P[0-9]/', $tag ) ) {
		return 90;
	}
	if ( false !== strpos( $tag, 'URL.' ) ) {
		return 80;
	}
	if ( 1 === preg_match( '/_(F|H)$/', $tag ) ) {
		return 70;
	}
	if ( false !== strpos( $tag, 'T.' ) || false !== strpos( $tag, 'PT.' ) || false !== strpos( $tag, 'A.' ) ) {
		return 60;
	}
	if ( false !== strpos( $tag, 'W.' ) || false !== strpos( $tag, 'ESI.' ) || false !== strpos( $tag, 'AJAX.' ) ) {
		return 10;
	}

	return 40;
}

/**
 * Space-separated CDN-Tag value wrapped so a later wildcard purge can match one tag.
 *
 * @param string[] $tags Public tags.
 * @return string Header value without the CDN-Tag name, or an empty string.
 */
function bpl_format_cdn_tag_header( array $tags ) {
	$ranked = array();
	foreach ( $tags as $tag ) {
		$tag = bpl_sanitize_tag( (string) $tag );
		if ( '' === $tag || '*' === $tag ) {
			continue;
		}
		$ranked[ $tag ] = bpl_tag_rank( $tag );
	}

	if ( array() === $ranked ) {
		return '';
	}

	arsort( $ranked, SORT_NUMERIC );
	$chosen = array();
	foreach ( array_keys( $ranked ) as $tag ) {
		$candidate   = $chosen;
		$candidate[] = $tag;
		$value       = ': ' . implode( ' ', $candidate ) . ' :';
		if ( strlen( $value ) > 1024 ) {
			continue;
		}
		$chosen[] = $tag;
	}

	if ( array() === $chosen ) {
		return '';
	}

	return ': ' . implode( ' ', $chosen ) . ' :';
}

/**
 * Tags from an X-LiteSpeed-Tag header value.
 *
 * @param string $header_value Header value.
 * @return string[]
 */
function bpl_tags_from_litespeed_header( $header_value ) {
	$tags = array();
	foreach ( explode( ',', (string) $header_value ) as $part ) {
		$part = trim( $part );
		if ( 0 === strpos( $part, 'public:' ) ) {
			$part = substr( $part, 7 );
		}
		$tag = bpl_sanitize_tag( $part );
		if ( '' !== $tag ) {
			$tags[] = $tag;
		}
	}

	return $tags;
}

/**
 * Whether LiteSpeed marked this response as public cache.
 *
 * @param string $header_value X-LiteSpeed-Cache-Control value.
 * @return bool
 */
function bpl_cache_control_is_public( $header_value ) {
	$public = false;
	foreach ( explode( ',', strtolower( (string) $header_value ) ) as $token ) {
		$token = trim( $token );
		if ( 'private' === $token || 0 === strpos( $token, 'private' ) ) {
			return false;
		}
		if ( 'no-cache' === $token || 0 === strpos( $token, 'no-cache' ) ) {
			return false;
		}
		if ( 'public' === $token ) {
			$public = true;
		}
	}

	return $public;
}

/**
 * Whether this response should carry CDN-Tag.
 *
 * @param bool   $is_logged_in Logged-in visitor.
 * @param bool   $is_admin     Admin request.
 * @param bool   $is_preview   Preview request.
 * @param bool   $donotcache   DONOTCACHEPAGE is set.
 * @param string $mode         acceleration or rewrite.
 * @param string $method       HTTP method.
 * @param string $cache_control LiteSpeed cache-control header value.
 * @return bool
 */
function bpl_should_emit_cdn_tag( $is_logged_in, $is_admin, $is_preview, $donotcache, $mode, $method, $cache_control ) {
	if ( $is_logged_in || $is_admin || $is_preview || $donotcache ) {
		return false;
	}
	if ( 'acceleration' !== $mode ) {
		return false;
	}
	if ( 'GET' !== strtoupper( (string) $method ) ) {
		return false;
	}

	return bpl_cache_control_is_public( $cache_control );
}

/**
 * Register the header callback.
 *
 * @return void
 */
function bpl_headers_boot() {
	if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}

	// Must start before LiteSpeed's own buffer (after_setup_theme) so this callback runs after LiteSpeed sets its headers.
	ob_start( 'bpl_cdn_tag_buffer' );
}

/**
 * Output buffer callback that adds CDN-Tag once the page is complete.
 *
 * @param string $buffer Page output.
 * @param int    $phase  Output handler phase flags.
 * @return string Unchanged output.
 */
function bpl_cdn_tag_buffer( $buffer, $phase = 0 ) {
	if ( $phase & PHP_OUTPUT_HANDLER_FINAL ) {
		bpl_send_cdn_tag();
	}

	return $buffer;
}

/**
 * Copy LiteSpeed's public tags onto CDN-Tag for anonymous cacheable pages.
 *
 * @return void
 */
function bpl_send_cdn_tag() {
	if ( headers_sent() ) {
		return;
	}

	$raw_method = isset( $_SERVER['REQUEST_METHOD'] ) ? wp_unslash( $_SERVER['REQUEST_METHOD'] ) : 'GET';
	$method     = is_string( $raw_method ) ? strtoupper( $raw_method ) : 'GET';
	$method     = preg_replace( '/[^A-Z]/', '', $method );
	if ( ! is_string( $method ) || '' === $method ) {
		$method = 'GET';
	}

	$cache_control = '';
	$tag_header    = '';
	foreach ( headers_list() as $line ) {
		if ( 0 === stripos( $line, 'X-LiteSpeed-Cache-Control:' ) ) {
			$cache_control = trim( substr( $line, strlen( 'X-LiteSpeed-Cache-Control:' ) ) );
		}
		if ( 0 === stripos( $line, 'X-LiteSpeed-Tag:' ) ) {
			$tag_header = trim( substr( $line, strlen( 'X-LiteSpeed-Tag:' ) ) );
		}
	}

	$donotcache = defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;
	if ( ! bpl_should_emit_cdn_tag( is_user_logged_in(), is_admin(), is_preview(), (bool) $donotcache, bpl_delivery_mode(), $method, $cache_control ) ) {
		return;
	}

	$value = bpl_format_cdn_tag_header( bpl_tags_from_litespeed_header( $tag_header ) );
	if ( '' === $value ) {
		return;
	}

	header( 'CDN-Tag: ' . $value, true );
}
