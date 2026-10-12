<?php
/**
 * Pure behaviour tests. No network and no WordPress.
 *
 * @package BunnyPurgeLiteSpeed
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers encryption, tags, URLs, the purge plan, and the log cap.
 */
class PluginTest extends TestCase {

	/**
	 * Encryption round trip, fingerprint, and tamper rejection.
	 *
	 * @return void
	 */
	public function test_encryption_round_trip() {
		$this->assertTrue( bpl_encryption_available() );

		$secret = 'test-secret-value';
		$key    = 'abcdefghijklmnopqrstuvwxyz123456';
		$stored = bpl_encrypt( $key, $secret );

		$this->assertNotSame( '', $stored );
		$this->assertStringNotContainsString( $key, $stored );
		$this->assertSame( $key, bpl_decrypt( $stored, $secret ) );
		$this->assertSame( '', bpl_decrypt( $stored, 'other-secret' ) );
		$this->assertSame( '', bpl_decrypt( substr( $stored, 0, -2 ) . 'xx', $secret ) );
		$this->assertSame( 12, strlen( bpl_fingerprint( $key ) ) );
		$this->assertSame( '', bpl_encrypt( '', $secret ) );
	}

	/**
	 * API keys keep the official character set and reject short values.
	 *
	 * @return void
	 */
	public function test_api_key_is_stripped() {
		$this->assertSame(
			'abcDEF123_-keyvalue000script',
			bpl_sanitize_api_key( " abcDEF123_-keyvalue000 \n<script>" )
		);
		$this->assertSame( '', bpl_sanitize_api_key( 'short-key' ) );
		$this->assertSame( '', bpl_sanitize_api_key( 'spaces only !!!!!' ) );
	}

	/**
	 * CDN-Tag stays within 1024 bytes and drops header injection.
	 *
	 * @return void
	 */
	public function test_cdn_tag_header_limit_and_safety() {
		$this->assertSame( '', bpl_sanitize_tag( "bad\r\ntag" ) );
		$this->assertSame( 'Po.12extra', bpl_sanitize_tag( 'Po.12 extra' ) );

		$tags   = array( 'Po.99', 'W.1' );
		$header = bpl_format_cdn_tag_header( $tags );
		$this->assertSame( ': Po.99 W.1 :', $header );
		$this->assertLessThanOrEqual( 1024, strlen( $header ) );

		$many = array( 'Po.1' );
		for ( $i = 0; $i < 200; $i++ ) {
			$many[] = 'W.' . $i;
		}
		$trimmed = bpl_format_cdn_tag_header( $many );
		$this->assertLessThanOrEqual( 1024, strlen( $trimmed ) );
		$this->assertStringContainsString( 'Po.1', $trimmed );
		$this->assertStringNotContainsString( "\n", $trimmed );

		$huge = bpl_format_cdn_tag_header( array( str_repeat( 'a', 1100 ) ) );
		$this->assertSame( '', $huge );
	}

	/**
	 * Public LiteSpeed cache control is required.
	 *
	 * @return void
	 */
	public function test_public_cache_control_gate() {
		$this->assertTrue( bpl_cache_control_is_public( 'public,max-age=3600' ) );
		$this->assertFalse( bpl_cache_control_is_public( 'private,max-age=3600' ) );
		$this->assertFalse( bpl_cache_control_is_public( 'no-cache' ) );
		$this->assertFalse(
			bpl_should_emit_cdn_tag( true, false, false, false, 'acceleration', 'GET', 'public' )
		);
		$this->assertFalse(
			bpl_should_emit_cdn_tag( false, false, false, false, 'rewrite', 'GET', 'public' )
		);
		$this->assertTrue(
			bpl_should_emit_cdn_tag( false, false, false, false, 'acceleration', 'GET', 'public,max-age=60' )
		);

		$parsed = bpl_tags_from_litespeed_header( 'public:abc_Po.5, abc_W.1' );
		$this->assertSame( array( 'abc_Po.5', 'abc_W.1' ), $parsed );
	}

	/**
	 * File purge hosts follow rewrite versus acceleration.
	 *
	 * @return void
	 */
	public function test_site_page_url_uses_the_public_host() {
		$this->assertSame(
			'https://example.com/about/',
			bpl_site_page_url( 'https://cdn.example.com/about/?LSCWP_CTRL=purge', 'https://example.com' )
		);
		$this->assertSame( 'https://example.com/about/', bpl_site_page_url( '/about/', 'https://example.com' ) );
		$this->assertSame( '', bpl_site_page_url( '/../secret', 'https://example.com' ) );
	}

	public function test_url_host_and_versions() {
		$allowed = bpl_allowed_hosts( 'https://example.com', 'https://example.com', 'cdn.example.com' );
		$this->assertContains( 'example.com', $allowed );
		$this->assertContains( 'cdn.example.com', $allowed );

		$rewrite = bpl_purge_file_target( 'https://example.com/wp-content/uploads/a.jpg', 'rewrite', 'cdn.example.com', true );
		$this->assertSame( 'https://cdn.example.com/wp-content/uploads/a.jpg*', $rewrite );
		$this->assertTrue( bpl_url_host_allowed( $rewrite, $allowed ) );

		$accel = bpl_purge_file_target( 'https://example.com/wp-content/uploads/a.jpg', 'acceleration', 'cdn.example.com', false );
		$this->assertSame( 'https://example.com/wp-content/uploads/a.jpg', $accel );

		$this->assertSame( '', bpl_purge_file_target( 'https://example.com/wp-content/../secret.jpg', 'acceleration', '', false ) );
		$this->assertFalse( bpl_url_host_allowed( 'https://evil.example/a.jpg', $allowed ) );
		$this->assertSame( '', bpl_sanitize_hostname( 'https://evil.example/a.jpg' ) );
	}

	/**
	 * Many files in one directory become one prefix purge.
	 *
	 * @return void
	 */
	public function test_url_collapse_is_per_directory() {
		$urls = array();
		for ( $i = 0; $i < 13; $i++ ) {
			$urls[] = 'https://example.com/wp-content/uploads/2026/10/file-' . $i . '.jpg';
		}
		$urls[] = 'https://example.com/wp-content/uploads/2026/11/other.jpg';

		$collapsed = bpl_collapse_urls( $urls, 12 );
		$this->assertContains( 'https://example.com/wp-content/uploads/2026/10/*', $collapsed );
		$this->assertContains( 'https://example.com/wp-content/uploads/2026/11/other.jpg', $collapsed );
		$this->assertCount( 2, $collapsed );
	}

	/**
	 * Version wildcards still collapse; directory prefixes are left alone.
	 *
	 * @return void
	 */
	public function test_url_collapse_includes_version_wildcards() {
		$urls = array( 'https://example.com/wp-content/litespeed/*' );
		for ( $i = 0; $i < 13; $i++ ) {
			$urls[] = 'https://example.com/wp-content/uploads/2026/10/file-' . $i . '.jpg*';
		}

		$collapsed = bpl_collapse_urls( $urls, 12 );
		$this->assertContains( 'https://example.com/wp-content/uploads/2026/10/*', $collapsed );
		$this->assertContains( 'https://example.com/wp-content/litespeed/*', $collapsed );
		$this->assertCount( 2, $collapsed );
	}

	/**
	 * Attachment sizes stay beside the original file.
	 *
	 * @return void
	 */
	public function test_attachment_variants_ignore_paths() {
		$urls = bpl_attachment_variant_urls(
			'https://example.com/wp-content/uploads/2026/10/photo.jpg',
			array( 'photo-150x150.jpg', '../secret.jpg', 'photo 2.jpg' )
		);

		$this->assertSame(
			array(
				'https://example.com/wp-content/uploads/2026/10/photo.jpg',
				'https://example.com/wp-content/uploads/2026/10/photo-150x150.jpg',
			),
			$urls
		);
	}

	/**
	 * Full-zone escalation and rewrite mode.
	 *
	 * @return void
	 */
	public function test_purge_plan_escalates() {
		$sitewide = bpl_build_purge_plan( array( '_', '_Po.5' ), array( 'https://example.com/a.jpg' ), false, true, 'abc' );
		$this->assertTrue( $sitewide['zone'] );
		$this->assertSame( array(), $sitewide['tags'] );
		$this->assertSame( array(), $sitewide['urls'] );

		$tags = array();
		for ( $i = 1; $i <= 41; $i++ ) {
			$tags[] = '_Po.' . $i;
		}
		$collapsed = bpl_build_purge_plan( $tags, array(), false, true, 'abc', 40 );
		$this->assertTrue( $collapsed['zone'] );

		$one = bpl_build_purge_plan( array( '_Po.5' ), array(), false, true, 'abc' );
		$this->assertFalse( $one['zone'] );
		$this->assertSame( array( 'abc_Po.5' ), $one['tags'] );

		$rewrite = bpl_build_purge_plan( array( '_Po.5' ), array( 'https://cdn.example.com/a.css*' ), false, false, 'abc' );
		$this->assertFalse( $rewrite['zone'] );
		$this->assertSame( array(), $rewrite['tags'] );
		$this->assertSame( array( 'https://cdn.example.com/a.css*' ), $rewrite['urls'] );

		$this->assertSame( 'site-tag', $sitewide['reason'] );
		$star = bpl_build_purge_plan( array( '*' ), array(), false, true, 'abc' );
		$this->assertSame( 'purge-all', $star['reason'] );
		$this->assertSame( 'Full Pull Zone. LiteSpeed sent a purge-all tag.', bpl_zone_detail( 'purge-all' ) );
		$this->assertTrue( bpl_is_sitewide_purge_tag( '*' ) );
		$this->assertTrue( bpl_is_sitewide_purge_tag( '2_' ) );
		$this->assertFalse( bpl_is_sitewide_purge_tag( '_Po.5' ) );
	}

	/**
	 * Elementor autosaves are ignored. Templates purge the zone.
	 *
	 * @return void
	 */
	public function test_elementor_save_decision() {
		$this->assertSame( 'ignore', bpl_elementor_save_decision( 'page', 'wp-page', 'publish', true, false ) );
		$this->assertSame( 'ignore', bpl_elementor_save_decision( 'page', 'wp-page', 'draft', false, false ) );
		$this->assertSame( 'css', bpl_elementor_save_decision( 'page', 'wp-page', 'publish', false, false ) );
		$this->assertSame( 'zone', bpl_elementor_save_decision( 'elementor_library', 'header', 'publish', false, false ) );
		$this->assertSame( 'zone', bpl_elementor_save_decision( 'page', 'kit', 'publish', false, false ) );
		$this->assertSame( 'ignore', bpl_elementor_save_decision( 'elementor_library', 'header', 'publish', false, true ) );
	}

	/**
	 * The log keeps the newest 50 rows.
	 *
	 * @return void
	 */
	public function test_log_prunes_at_50() {
		$entries = array();
		for ( $i = 1; $i <= 55; $i++ ) {
			$entries = bpl_log_push(
				$entries,
				bpl_log_entry( 'zone', 'manual', $i, 204, 'row ' . $i ),
				50
			);
		}

		$this->assertCount( 50, $entries );
		$this->assertSame( 'row 55', $entries[0]['detail'] );
		$this->assertSame( 'row 6', $entries[49]['detail'] );
		$this->assertArrayNotHasKey( 'body', $entries[0] );

		$clean = bpl_log_entry( 'nope', "bad\ntrigger", 1, 200, "secret\r\nline" );
		$this->assertSame( 'url', $clean['kind'] );
		$this->assertSame( 'badtrigger', $clean['trigger'] );
		$this->assertStringNotContainsString( "\n", $clean['detail'] );
	}

	/**
	 * Rate limiting collapses a burst and blocks when the cap is spent.
	 *
	 * @return void
	 */
	public function test_rate_decision() {
		$this->assertSame( 'allow', bpl_rate_decision( 10, 5, 60 ) );
		$this->assertSame( 'collapse', bpl_rate_decision( 59, 2, 60 ) );
		$this->assertSame( 'block', bpl_rate_decision( 60, 1, 60 ) );
		$this->assertSame( 'skip', bpl_rate_decision( 0, 0, 60 ) );
	}

	/**
	 * Zone warnings keep only safe flags.
	 *
	 * @return void
	 */
	public function test_zone_flags_drop_secrets() {
		$flags = bpl_zone_flags_from_payload(
			array(
				'PermaCacheStorageZoneId'          => 9,
				'EnableCookieVary'                 => true,
				'CookieVaryParameters'             => array( 'wordpress_logged_in_abc' ),
				'IgnoreQueryStrings'               => false,
				'EnableSmartCache'                 => true,
				'CacheControlBrowserMaxAgeOverride' => 3600,
				'AWSSigningSecret'                 => 'do-not-keep',
				'ZoneSecurityKey'                  => 'do-not-keep',
			)
		);

		$this->assertTrue( $flags['perma_cache'] );
		$this->assertTrue( $flags['cookie_vary_sensitive'] );
		$this->assertFalse( $flags['ignore_query_strings'] );
		$this->assertTrue( $flags['smart_cache'] );
		$this->assertSame( 3600, $flags['browser_max_age'] );
		$this->assertArrayNotHasKey( 'AWSSigningSecret', $flags );
		$this->assertArrayNotHasKey( 'ZoneSecurityKey', $flags );
	}

	/**
	 * Tag purge body matches Bunny's single-string tag storage.
	 *
	 * @return void
	 */
	public function test_cache_tag_body() {
		$this->assertSame( '{"CacheTag":"* abc_Po.5 *"}', bpl_cache_tag_body( 'abc_Po.5' ) );
		$this->assertSame( '', bpl_cache_tag_body( "bad tag\n" ) );
	}
}
