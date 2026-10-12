<?php
/**
 * Bunny API calls, URL allowlist, and pull-zone flag parsing.
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keep the characters the official bunny.net plugin accepts in an API key.
 *
 * @param string $key Raw key.
 * @return string
 */
function bpl_sanitize_api_key( $key ) {
	$key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $key );
	if ( ! is_string( $key ) || strlen( $key ) < 20 ) {
		return '';
	}

	return $key;
}

/**
 * Normalize a CDN hostname to ASCII letters, digits, dots, and hyphens.
 *
 * @param string $hostname Raw hostname.
 * @return string
 */
function bpl_sanitize_hostname( $hostname ) {
	$hostname = strtolower( trim( (string) $hostname ) );
	if ( '' === $hostname || false !== strpos( $hostname, '/' ) || false !== strpos( $hostname, ':' ) || false !== strpos( $hostname, ' ' ) ) {
		return '';
	}
	$hostname = preg_replace( '/[^a-z0-9.-]/', '', $hostname );
	if ( ! is_string( $hostname ) || '' === $hostname || strlen( $hostname ) > 253 ) {
		return '';
	}

	if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $hostname ) ) {
		return '';
	}

	return $hostname;
}

/**
 * Turn a LiteSpeed page purge into the public site address Bunny cached.
 *
 * Query strings are removed. The host is always the site host, not a CDN hostname.
 *
 * @param string $url  Relative path or absolute URL from LiteSpeed.
 * @param string $home Canonical home URL, such as https://example.com.
 * @return string
 */
function bpl_site_page_url( $url, $home ) {
	$url        = trim( (string) $url );
	$home_parts = parse_url( (string) $home );
	if ( '' === $url || ! is_array( $home_parts ) || empty( $home_parts['host'] ) || ! is_string( $home_parts['host'] ) ) {
		return '';
	}

	$scheme = isset( $home_parts['scheme'] ) && is_string( $home_parts['scheme'] ) ? strtolower( $home_parts['scheme'] ) : 'https';
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		$scheme = 'https';
	}
	$port = isset( $home_parts['port'] ) ? (int) $home_parts['port'] : 0;
	$host = bpl_host_with_port( $home_parts['host'], $scheme, $port );

	if ( '/' === $url[0] ) {
		$path = $url;
	} else {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		if ( empty( $parts['host'] ) ) {
			$path = '/' . ltrim( $url, '/' );
		} else {
			$path = isset( $parts['path'] ) && is_string( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		}
	}

	$query = strpos( $path, '?' );
	if ( false !== $query ) {
		$path = substr( $path, 0, $query );
	}
	if ( ! is_string( $path ) || '' === $path ) {
		$path = '/';
	}
	if ( false !== strpos( $path, '..' ) || false !== strpos( $path, '\\' ) || false !== strpos( $path, "\n" ) || false !== strpos( $path, "\r" ) ) {
		return '';
	}
	if ( '/' !== $path[0] ) {
		$path = '/' . $path;
	}

	return $scheme . '://' . $host . $path;
}

/**
 * Host portion of a URL, or an empty string.
 *
 * @param string $url URL.
 * @return string
 */
function bpl_host_from_url( $url ) {
	$parts = parse_url( (string) $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
		return '';
	}

	return strtolower( $parts['host'] );
}

/**
 * Host, plus a non-default port, for a purge address.
 *
 * @param string $host   Hostname.
 * @param string $scheme http or https.
 * @param int    $port   Port from parse_url(), or 0.
 * @return string
 */
function bpl_host_with_port( $host, $scheme, $port ) {
	$host = strtolower( (string) $host );
	$port = (int) $port;
	if ( $port < 1 ) {
		return $host;
	}

	$default = ( 'https' === $scheme ) ? 443 : 80;
	if ( $port === $default ) {
		return $host;
	}

	return $host . ':' . $port;
}

/**
 * Bunny purge-URL endpoint for one prepared address.
 *
 * Exact page and file purges set exactPath so a trailing slash stays that
 * one address. Bunny otherwise treats a trailing slash as a path prefix.
 * The flag applies when the pull zone keeps query strings in the cache key.
 * When query strings are ignored, Bunny does not expand the slash.
 *
 * @param string $target Prepared public URL.
 * @param string $kind   url or prefix.
 * @return string
 */
function bpl_purge_request_url( $target, $kind ) {
	$request = 'https://api.bunny.net/purge?async=true&url=' . rawurlencode( (string) $target );
	if ( 'url' === $kind ) {
		$request .= '&exactPath=true';
	}

	return $request;
}

/**
 * Hosts this plugin is allowed to purge.
 *
 * @param string $home_url     Public home URL.
 * @param string $site_url     WordPress site URL.
 * @param string $cdn_hostname Optional CDN hostname.
 * @return string[]
 */
function bpl_allowed_hosts( $home_url, $site_url, $cdn_hostname ) {
	$hosts = array();
	foreach ( array( $home_url, $site_url ) as $url ) {
		$host = bpl_host_from_url( $url );
		if ( '' !== $host ) {
			$hosts[] = $host;
		}
	}

	$cdn_hostname = bpl_sanitize_hostname( $cdn_hostname );
	if ( '' !== $cdn_hostname ) {
		$hosts[] = $cdn_hostname;
	}

	return array_values( array_unique( $hosts ) );
}

/**
 * Whether a purge URL points at an allowed host.
 *
 * @param string   $url   Absolute URL.
 * @param string[] $hosts Allowed hosts.
 * @return bool
 */
function bpl_url_host_allowed( $url, array $hosts ) {
	$host = bpl_host_from_url( $url );
	if ( '' === $host ) {
		return false;
	}

	return in_array( $host, $hosts, true );
}

/**
 * Build the public file URL Bunny should drop.
 *
 * In rewrite mode the CDN hostname replaces the site host. When the zone
 * varies on the ver query parameter, a trailing * clears every version.
 *
 * @param string $url           Original file URL.
 * @param string $mode          acceleration or rewrite.
 * @param string $cdn_hostname  CDN hostname for rewrite mode.
 * @param bool   $vary_versions Whether query versions are separate cache keys.
 * @return string Empty when the URL is not safe to purge.
 */
function bpl_purge_file_target( $url, $mode, $cdn_hostname, $vary_versions ) {
	$parts = parse_url( (string) $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! is_string( $parts['host'] ) ) {
		return '';
	}

	$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
	if ( 'http' !== $scheme && 'https' !== $scheme ) {
		return '';
	}

	$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
	$host = strtolower( $parts['host'] );
	if ( 'rewrite' === $mode ) {
		$cdn_hostname = bpl_sanitize_hostname( $cdn_hostname );
		if ( '' !== $cdn_hostname ) {
			$host = $cdn_hostname;
			$port = 0;
		}
	}
	$host = bpl_host_with_port( $host, $scheme, $port );

	$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
	if ( false !== strpos( $path, '..' ) || false !== strpos( $path, '\\' ) || false !== strpos( $path, "\n" ) || false !== strpos( $path, "\r" ) ) {
		return '';
	}
	if ( '' === $path || '/' !== $path[0] ) {
		return '';
	}

	$wildcard = (bool) $vary_versions && '*' !== substr( $path, -1 );
	if ( $wildcard ) {
		$path .= '*';
	}

	return $scheme . '://' . $host . $path;
}

/**
 * Prefix purge for a directory on the public host.
 *
 * @param string $origin       Canonical origin, such as https://example.com.
 * @param string $path         Path beginning with a slash.
 * @param string $mode         acceleration or rewrite.
 * @param string $cdn_hostname CDN hostname for rewrite mode.
 * @return string
 */
function bpl_purge_prefix_target( $origin, $path, $mode, $cdn_hostname ) {
	$path = '/' . ltrim( (string) $path, '/' );
	if ( false !== strpos( $path, '..' ) ) {
		return '';
	}
	if ( '*' !== substr( $path, -1 ) ) {
		$path = rtrim( $path, '/' ) . '/*';
	}

	$origin = rtrim( (string) $origin, '/' );
	return bpl_purge_file_target( $origin . $path, $mode, $cdn_hostname, false );
}

/**
 * Collapse a long list of file URLs into one directory prefix.
 *
 * @param string[] $urls  Prepared purge URLs.
 * @param int      $limit Maximum exact URLs before collapsing.
 * @return string[]
 */
function bpl_collapse_urls( array $urls, $limit = 12 ) {
	$clean = array();
	foreach ( $urls as $url ) {
		$url = (string) $url;
		if ( '' !== $url ) {
			$clean[ $url ] = true;
		}
	}

	$limit  = (int) $limit;
	$limit  = $limit < 1 ? 1 : $limit;
	$groups = array();
	$out    = array();

	foreach ( array_keys( $clean ) as $url ) {
		$base = '*' === substr( $url, -1 ) && '/*' !== substr( $url, -2 ) ? substr( $url, 0, -1 ) : $url;
		if ( false !== strpos( $base, '*' ) ) {
			$out[] = $url;
			continue;
		}

		$parts = parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			$out[] = $url;
			continue;
		}

		$scheme    = isset( $parts['scheme'] ) && is_string( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$dir       = rtrim( str_replace( '\\', '/', dirname( (string) $parts['path'] ) ), '/' );
		$authority = bpl_host_with_port( (string) $parts['host'], $scheme, isset( $parts['port'] ) ? (int) $parts['port'] : 0 );
		$key       = $scheme . '://' . $authority . $dir;
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array();
		}
		$groups[ $key ][] = $url;
	}

	foreach ( $groups as $directory => $list ) {
		if ( count( $list ) > $limit ) {
			$out[] = $directory . '/*';
			continue;
		}
		foreach ( $list as $url ) {
			$out[] = $url;
		}
	}

	return array_values( array_unique( $out ) );
}

/**
 * Original attachment URL plus size variants, using only the file name.
 *
 * @param string   $original_url Full URL of the original file.
 * @param string[] $size_files   File names from attachment metadata.
 * @return string[]
 */
function bpl_attachment_variant_urls( $original_url, array $size_files ) {
	$parts = parse_url( (string) $original_url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
		return array();
	}

	$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';
	$host   = bpl_host_with_port( (string) $parts['host'], $scheme, isset( $parts['port'] ) ? (int) $parts['port'] : 0 );
	$dir    = rtrim( str_replace( '\\', '/', dirname( (string) $parts['path'] ) ), '/' );
	$urls   = array( $scheme . '://' . $host . $parts['path'] );

	foreach ( $size_files as $file ) {
		$file = str_replace( '\\', '/', (string) $file );
		if ( '' === $file || false !== strpos( $file, '/' ) || false !== strpos( $file, '..' ) ) {
			continue;
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+$/', $file ) ) {
			continue;
		}
		$urls[] = $scheme . '://' . $host . $dir . '/' . $file;
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Decide whether a batch fits the per-minute cap.
 *
 * @param int $used   Calls already made this minute.
 * @param int $needed Calls this batch wants.
 * @param int $cap    Maximum calls per minute.
 * @return string allow, collapse, block, or skip.
 */
function bpl_rate_decision( $used, $needed, $cap = 60 ) {
	$used   = (int) $used;
	$needed = (int) $needed;
	$cap    = (int) $cap;
	if ( $needed <= 0 ) {
		return 'skip';
	}
	if ( $used + $needed <= $cap ) {
		return 'allow';
	}
	if ( $needed > 1 && ( $used + 1 ) <= $cap ) {
		return 'collapse';
	}

	return 'block';
}

/**
 * Copy only the pull-zone flags this plugin warns about.
 *
 * The API payload can contain other account secrets, so nothing else is kept.
 *
 * @param array<string, mixed> $data Pull-zone response.
 * @return array<string, bool|int>
 */
function bpl_zone_flags_from_payload( array $data ) {
	$sensitive = false;
	if ( ! empty( $data['EnableCookieVary'] ) && isset( $data['CookieVaryParameters'] ) && is_array( $data['CookieVaryParameters'] ) ) {
		foreach ( $data['CookieVaryParameters'] as $name ) {
			$name = strtolower( (string) $name );
			if ( false !== strpos( $name, 'wordpress_logged_in' ) || false !== strpos( $name, 'wp-postpass' ) || false !== strpos( $name, 'comment_author' ) ) {
				$sensitive = true;
				break;
			}
		}
	}

	$perma   = isset( $data['PermaCacheStorageZoneId'] ) ? (int) $data['PermaCacheStorageZoneId'] : 0;
	$browser = isset( $data['CacheControlBrowserMaxAgeOverride'] ) ? (int) $data['CacheControlBrowserMaxAgeOverride'] : 0;

	return array(
		'perma_cache'           => $perma > 0,
		'cookie_vary_sensitive' => $sensitive,
		'ignore_query_strings'  => ! empty( $data['IgnoreQueryStrings'] ),
		'smart_cache'           => ! empty( $data['EnableSmartCache'] ),
		'browser_max_age'       => $browser,
	);
}

/**
 * JSON body for a tag purge. Bunny stores the whole CDN-Tag header as one string.
 *
 * @param string $tag Sanitized public tag.
 * @return string
 */
function bpl_cache_tag_body( $tag ) {
	$tag = bpl_sanitize_tag( $tag );
	if ( '' === $tag ) {
		return '';
	}

	$json = json_encode( array( 'CacheTag' => '* ' . $tag . ' *' ) );
	if ( ! is_string( $json ) ) {
		return '';
	}

	return $json;
}

/**
 * Path of a URL, for the log.
 *
 * @param string $url URL.
 * @return string
 */
function bpl_url_path( $url ) {
	$parts = parse_url( (string) $url );
	if ( ! is_array( $parts ) || empty( $parts['path'] ) || ! is_string( $parts['path'] ) ) {
		return '';
	}

	return $parts['path'];
}

/**
 * API key from wp-config.php, or the encrypted database copy.
 *
 * @return string
 */
function bpl_get_api_key() {
	$override = bpl_get_api_key_override();
	if ( '' !== $override ) {
		return $override;
	}

	if ( defined( 'BUNNY_LITESPEED_API_KEY' ) && is_string( BUNNY_LITESPEED_API_KEY ) && '' !== BUNNY_LITESPEED_API_KEY ) {
		return bpl_sanitize_api_key( BUNNY_LITESPEED_API_KEY );
	}

	if ( ! function_exists( 'get_option' ) ) {
		return '';
	}

	$stored = get_option( 'bunny_litespeed_api_key', '' );
	if ( ! is_string( $stored ) || '' === $stored ) {
		return '';
	}

	$secret = bpl_encryption_secret();
	$plain  = bpl_decrypt( $stored, $secret );
	if ( '' === $plain ) {
		if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'set_transient' ) ) {
			set_transient( 'bpl_key_unreadable', 1, DAY_IN_SECONDS );
		}
		return '';
	}

	return bpl_sanitize_api_key( $plain );
}

/**
 * Store an encrypted API key and its fingerprint.
 *
 * @param string $key Sanitized key.
 * @return bool
 */
function bpl_store_api_key( $key ) {
	$key = bpl_sanitize_api_key( $key );
	if ( '' === $key || ! bpl_encryption_available() ) {
		return false;
	}

	$payload = bpl_encrypt( $key, bpl_encryption_secret() );
	if ( '' === $payload ) {
		return false;
	}

	update_option( 'bunny_litespeed_api_key', $payload, false );
	update_option( 'bunny_litespeed_api_fingerprint', bpl_fingerprint( $key ), false );
	delete_transient( 'bpl_key_unreadable' );

	return true;
}

/**
 * Call the Bunny API. Redirects are disabled so the key cannot leave api.bunny.net.
 *
 * @param string      $method    HTTP method.
 * @param string      $url       Absolute API URL.
 * @param string|null $body      JSON body, or null.
 * @param int         $retries   Attempts for 429 and 5xx responses.
 * @param bool        $keep_body Whether to return the body. Callers must discard it after reading flags.
 * @return array{code: int, error: string, body: string}
 */
function bpl_api_request( $method, $url, $body = null, $retries = 3, $keep_body = false ) {
	$key = bpl_get_api_key();
	if ( '' === $key ) {
		return array(
			'code'  => 0,
			'error' => 'missing_key',
			'body'  => '',
		);
	}

	$wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '';
	$headers    = array(
		'AccessKey'  => $key,
		'Accept'     => 'application/json',
		'User-Agent' => 'purge-sync-for-bunny-litespeed/' . BPL_VERSION . ' WP/' . $wp_version . ' PHP/' . PHP_VERSION,
	);
	if ( is_string( $body ) ) {
		$headers['Content-Type'] = 'application/json';
	}

	$args = array(
		'method'      => $method,
		'timeout'     => 20,
		'redirection' => 0,
		'sslverify'   => true,
		'headers'     => $headers,
	);
	if ( is_string( $body ) ) {
		$args['body'] = $body;
	}

	$retries = max( 1, (int) $retries );
	$attempt = 0;
	$code    = 0;

	while ( $attempt < $retries ) {
		++$attempt;
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			$code = 0;
			if ( $attempt < $retries ) {
				sleep( $attempt );
				continue;
			}
			return array(
				'code'  => 0,
				'error' => 'transport',
				'body'  => '',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		if ( 401 === $code ) {
			set_transient( 'bpl_auth_failed', 1, DAY_IN_SECONDS );
			return array(
				'code'  => 401,
				'error' => 'unauthorized',
				'body'  => '',
			);
		}

		if ( $code >= 200 && $code < 300 ) {
			delete_transient( 'bpl_auth_failed' );
			return array(
				'code'  => $code,
				'error' => '',
				'body'  => $keep_body ? $raw : '',
			);
		}

		if ( ( 429 === $code || $code >= 500 ) && $attempt < $retries ) {
			sleep( $attempt );
			continue;
		}

		return array(
			'code'  => $code,
			'error' => 'http',
			'body'  => '',
		);
	}

	return array(
		'code'  => $code,
		'error' => 'http',
		'body'  => '',
	);
}

/**
 * Confirm the key can see this account and this pull zone.
 *
 * @param string $key         Sanitized key. Stored only after this succeeds.
 * @param int    $pullzone_id Pull zone ID.
 * @return array{ok: bool, flags: array<string, bool|int>, error: string}
 */
function bpl_validate_credentials( $key, $pullzone_id ) {
	$empty = array(
		'ok'    => false,
		'flags' => array(),
		'error' => 'invalid',
	);
	$key   = bpl_sanitize_api_key( $key );
	$pullzone_id = (int) $pullzone_id;
	if ( '' === $key || $pullzone_id < 1 ) {
		return $empty;
	}

	$previous = bpl_get_api_key();
	$override = false;
	if ( $key !== $previous ) {
		$GLOBALS['bpl_key_override'] = $key;
		$override                    = true;
	}

	$user = bpl_api_request( 'GET', 'https://api.bunny.net/user', null, 1, false );
	if ( 401 === $user['code'] || $user['code'] < 200 || $user['code'] >= 300 ) {
		if ( $override ) {
			unset( $GLOBALS['bpl_key_override'] );
		}
		$empty['error'] = 401 === $user['code'] ? 'unauthorized' : 'unavailable';
		return $empty;
	}

	$zone = bpl_api_request( 'GET', 'https://api.bunny.net/pullzone/' . $pullzone_id, null, 1, true );
	if ( $override ) {
		unset( $GLOBALS['bpl_key_override'] );
	}

	if ( $zone['code'] < 200 || $zone['code'] >= 300 ) {
		$empty['error'] = 401 === $zone['code'] ? 'unauthorized' : ( 404 === $zone['code'] ? 'pullzone' : 'unavailable' );
		return $empty;
	}

	$decoded = json_decode( $zone['body'], true );
	$zone['body'] = '';
	if ( ! is_array( $decoded ) ) {
		$empty['error'] = 'unavailable';
		return $empty;
	}

	$flags = bpl_zone_flags_from_payload( $decoded );
	unset( $decoded );

	return array(
		'ok'    => true,
		'flags' => $flags,
		'error' => '',
	);
}

/**
 * Use a candidate key for the next request during validation.
 *
 * bpl_get_api_key() checks this override before the database.
 * The function above sets it only for the duration of validation.
 *
 * @return string
 */
function bpl_get_api_key_override() {
	if ( isset( $GLOBALS['bpl_key_override'] ) && is_string( $GLOBALS['bpl_key_override'] ) ) {
		return bpl_sanitize_api_key( $GLOBALS['bpl_key_override'] );
	}

	return '';
}
