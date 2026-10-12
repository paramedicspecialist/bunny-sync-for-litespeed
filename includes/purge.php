<?php
/**
 * Listen for LiteSpeed, Elementor, media, Customizer, and update purges.
 *
 * @package PurgeSyncBunnyLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * A LiteSpeed purge tag that means the whole public cache.
 *
 * @param string $tag Tag from the litespeed_purge_tags filter, before the LS prefix.
 * @return bool
 */
function bpl_is_sitewide_purge_tag( $tag ) {
	if ( '*' === $tag ) {
		return true;
	}

	return 1 === preg_match( '/^[0-9]*_$/', (string) $tag );
}

/**
 * Turn collected tags and file URLs into Bunny calls.
 *
 * A full-zone purge replaces tag and file calls so the stricter limit is spent once.
 *
 * @param string[] $raw_tags   Tags as LiteSpeed passed them.
 * @param string[] $file_urls  Prepared file or prefix URLs.
 * @param bool     $force_zone Force a full-zone purge.
 * @param bool     $html_tags  False in rewrite mode, where HTML is not on Bunny.
 * @param string   $ls_prefix  LSWCP_TAG_PREFIX, or empty when LiteSpeed is absent.
 * @param int      $collapse_at Tag count that becomes a full-zone purge.
 * @return array{zone: bool, tags: string[], urls: string[]}
 */
function bpl_build_purge_plan( array $raw_tags, array $file_urls, $force_zone, $html_tags, $ls_prefix, $collapse_at = 40 ) {
	$zone   = (bool) $force_zone;
	$reason = $force_zone ? 'purge-all-action' : '';
	$tags   = array();

	foreach ( $raw_tags as $raw ) {
		$raw = (string) $raw;
		if ( bpl_is_sitewide_purge_tag( $raw ) ) {
			$zone   = true;
			$reason = ( '*' === $raw ) ? 'purge-all' : 'site-tag';
			continue;
		}
		if ( ! $html_tags ) {
			continue;
		}
		$full = bpl_sanitize_tag( (string) $ls_prefix . $raw );
		if ( '' === $full ) {
			continue;
		}
		$tags[ $full ] = true;
	}

	if ( $html_tags && '' === (string) $ls_prefix && array() !== $tags ) {
		$zone = true;
		if ( '' === $reason ) {
			$reason = 'missing-prefix';
		}
	}

	$tags = array_keys( $tags );
	if ( count( $tags ) > (int) $collapse_at ) {
		$zone = true;
		if ( '' === $reason ) {
			$reason = 'too-many-tags';
		}
	}

	if ( $zone ) {
		return array(
			'zone'   => true,
			'reason' => $reason,
			'tags'   => array(),
			'urls'   => array(),
		);
	}

	$urls = array();
	foreach ( $file_urls as $url ) {
		$url = (string) $url;
		if ( '' !== $url ) {
			$urls[ $url ] = true;
		}
	}

	return array(
		'zone'   => false,
		'reason' => '',
		'tags'   => $tags,
		'urls'   => array_keys( $urls ),
	);
}

/**
 * Short log line for a full-zone purge.
 *
 * @param string $reason Why the batch became a full-zone purge.
 * @return string
 */
function bpl_zone_detail( $reason ) {
	$details = array(
		'purge-all'        => 'Full Pull Zone. LiteSpeed sent a purge-all tag.',
		'site-tag'         => 'Full Pull Zone. LiteSpeed sent the site-wide tag.',
		'missing-prefix'   => 'Full Pull Zone. The LiteSpeed tag prefix was missing.',
		'too-many-tags'    => 'Full Pull Zone. Too many page tags for one edit.',
		'purge-all-action' => 'Full Pull Zone. LiteSpeed purged all caches.',
		'rate'             => 'Full Pull Zone. The per-minute purge cap was reached.',
	);

	if ( isset( $details[ $reason ] ) ) {
		return $details[ $reason ];
	}

	return 'Full Pull Zone.';
}

/**
 * What an Elementor save should do.
 *
 * @param string $post_type     Post type.
 * @param string $document_name Elementor document name.
 * @param string $post_status   Post status.
 * @param bool   $is_autosave   Autosave request.
 * @param bool   $is_revision   Revision.
 * @return string ignore, zone, or css.
 */
function bpl_elementor_save_decision( $post_type, $document_name, $post_status, $is_autosave, $is_revision ) {
	if ( $is_autosave || $is_revision || 'publish' !== $post_status ) {
		return 'ignore';
	}

	$sitewide = array(
		'header',
		'footer',
		'single',
		'single-page',
		'single-post',
		'archive',
		'archive-post',
		'popup',
		'loop',
		'loop-item',
		'kit',
		'error-404',
		'search-results',
		'section',
		'widget',
		'floating-buttons',
	);

	if ( 'elementor_library' === $post_type || in_array( (string) $document_name, $sitewide, true ) ) {
		return 'zone';
	}

	return 'css';
}

/**
 * Request-local purge queue.
 *
 * @return array{tags: array<string, bool>, urls: array<string, bool>, prefixes: string[], zone: bool, trigger: string}
 */
function &bpl_queue() {
	static $state = array(
		'tags'     => array(),
		'urls'     => array(),
		'pages'    => array(),
		'prefixes' => array(),
		'zone'     => false,
		'trigger'  => '',
	);

	return $state;
}

/**
 * Mark this request for a full-zone purge.
 *
 * @param string $trigger Trigger name.
 * @return void
 */
function bpl_mark_zone( $trigger ) {
	$queue            = &bpl_queue();
	$queue['zone']    = true;
	$queue['trigger'] = (string) $trigger;
}

/**
 * Queue a file URL.
 *
 * @param string $url     Absolute URL on the origin.
 * @param string $trigger Trigger name.
 * @return void
 */
function bpl_queue_file( $url, $trigger ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return;
	}

	$queue = &bpl_queue();
	$queue['urls'][ $url ] = true;
	if ( '' === $queue['trigger'] ) {
		$queue['trigger'] = (string) $trigger;
	}
}

/**
 * Calls already counted this minute for one Bunny purge type.
 *
 * Exact covers a tag purge and an exact URL. Prefix covers a wildcard URL and a full pull zone.
 *
 * @param string $bucket exact or prefix.
 * @return int
 */
function bpl_rate_bucket_used( $bucket ) {
	$bucket = ( 'prefix' === $bucket ) ? 'prefix' : 'exact';
	$used   = get_transient( 'bpl_rate_' . $bucket . '_' . gmdate( 'YmdHi' ) );
	return (int) $used;
}

/**
 * Record calls against one Bunny purge type.
 *
 * @param string $bucket exact or prefix.
 * @param int    $count  Calls about to be made.
 * @return void
 */
function bpl_rate_commit_bucket( $bucket, $count ) {
	$count = (int) $count;
	if ( $count < 1 || ! function_exists( 'set_transient' ) ) {
		return;
	}

	$bucket = ( 'prefix' === $bucket ) ? 'prefix' : 'exact';
	$key    = 'bpl_rate_' . $bucket . '_' . gmdate( 'YmdHi' );
	set_transient( $key, bpl_rate_bucket_used( $bucket ) + $count, 2 * MINUTE_IN_SECONDS );
}

/**
 * Split a purge plan into Bunny's exact and prefix call counts.
 *
 * @param array{zone?: bool, tags?: string[], urls?: string[]} $plan Purge plan.
 * @return array{exact: int, prefix: int}
 */
function bpl_purge_call_budget( array $plan ) {
	$exact  = isset( $plan['tags'] ) && is_array( $plan['tags'] ) ? count( $plan['tags'] ) : 0;
	$prefix = ! empty( $plan['zone'] ) ? 1 : 0;
	if ( isset( $plan['urls'] ) && is_array( $plan['urls'] ) ) {
		foreach ( $plan['urls'] as $url ) {
			if ( false !== strpos( (string) $url, '*' ) ) {
				++$prefix;
				continue;
			}
			++$exact;
		}
	}

	return array(
		'exact'  => $exact,
		'prefix' => $prefix,
	);
}

/**
 * Decide whether a batch fits Bunny's exact and prefix limits.
 *
 * Exact burst is 120. Prefix burst, including a full pull zone, is 20.
 * A batch that does not fit becomes one full-zone purge when one prefix call remains.
 *
 * @param int $exact_used    Exact calls already made this minute.
 * @param int $prefix_used   Prefix calls already made this minute.
 * @param int $exact_needed  Exact calls this batch wants.
 * @param int $prefix_needed Prefix calls this batch wants.
 * @param int $exact_cap     Exact burst.
 * @param int $prefix_cap    Prefix burst.
 * @return string allow, collapse, block, or skip.
 */
function bpl_rate_batch_decision( $exact_used, $prefix_used, $exact_needed, $prefix_needed, $exact_cap = 120, $prefix_cap = 20 ) {
	$exact_used    = (int) $exact_used;
	$prefix_used   = (int) $prefix_used;
	$exact_needed  = (int) $exact_needed;
	$prefix_needed = (int) $prefix_needed;
	$exact_cap     = (int) $exact_cap;
	$prefix_cap    = (int) $prefix_cap;
	if ( ( $exact_needed + $prefix_needed ) <= 0 ) {
		return 'skip';
	}
	if ( ( $exact_used + $exact_needed ) <= $exact_cap && ( $prefix_used + $prefix_needed ) <= $prefix_cap ) {
		return 'allow';
	}
	if ( ( $exact_needed + $prefix_needed ) > 1 && ( $prefix_used + 1 ) <= $prefix_cap ) {
		return 'collapse';
	}

	return 'block';
}

/**
 * Status to fold into the admin notice.
 *
 * Zero from a finished request means the connection failed. An empty purge
 * group also uses zero, and that skip is handled by the caller.
 *
 * @param int $code HTTP status from bpl_api_request().
 * @return int
 */
function bpl_observed_status( $code ) {
	$code = (int) $code;
	if ( 0 === $code ) {
		return 1;
	}

	return $code;
}

/**
 * Whether file purges must cover every ?ver= copy.
 *
 * Until a zone check says query strings are ignored, assume they vary.
 *
 * @return bool
 */
function bpl_should_vary_file_versions() {
	$flags = get_option( 'bunny_litespeed_zone_flags', array() );
	if ( ! is_array( $flags ) || ! array_key_exists( 'ignore_query_strings', $flags ) ) {
		return true;
	}

	return empty( $flags['ignore_query_strings'] );
}

/**
 * Hosts allowed for this site.
 *
 * @return string[]
 */
function bpl_current_allowed_hosts() {
	return bpl_allowed_hosts( home_url(), site_url(), bpl_cdn_hostname() );
}

/**
 * Register listeners.
 *
 * @return void
 */
function bpl_purge_boot() {
	add_filter( 'litespeed_purge_tags', 'bpl_on_purge_tags', 10, 2 );
	add_action( 'litespeed_purged_all', 'bpl_on_purged_all' );
	add_action( 'litespeed_purged_all_cssjs', 'bpl_on_purged_cssjs' );
	add_action( 'litespeed_purged_link', 'bpl_on_purged_link' );
	add_action( 'litespeed_purged_front', 'bpl_on_purged_link' );
	add_action( 'litespeed_purged_single', 'bpl_on_purged_single' );
	add_action( 'delete_attachment', 'bpl_on_delete_attachment' );
	add_action( 'add_attachment', 'bpl_on_add_attachment' );
	add_filter( 'wp_update_attachment_metadata', 'bpl_on_attachment_metadata', 10, 2 );
	add_action( 'elementor/document/after_save', 'bpl_on_elementor_save', 10, 2 );
	add_action( 'customize_save_after', 'bpl_on_customizer' );
	add_action( 'upgrader_process_complete', 'bpl_on_upgrade', 10, 2 );
	add_action( 'shutdown', 'bpl_flush_purge', 999 );
}

/**
 * Collect public purge tags. The list is returned unchanged.
 *
 * @param mixed $tags       Tags LiteSpeed is about to send.
 * @param bool  $is_private Private purge list.
 * @return mixed
 */
function bpl_on_purge_tags( $tags, $is_private = false ) {
	if ( $is_private || ! is_array( $tags ) ) {
		return $tags;
	}

	$queue = &bpl_queue();
	foreach ( $tags as $tag ) {
		$tag = (string) $tag;
		if ( bpl_is_sitewide_purge_tag( $tag ) ) {
			$queue['zone'] = true;
			if ( '' === $queue['trigger'] ) {
				$queue['trigger'] = 'litespeed_purge_tags';
			}
			continue;
		}

		$clean = bpl_sanitize_tag( $tag );
		if ( '' !== $clean ) {
			$queue['tags'][ $clean ] = true;
			if ( '' === $queue['trigger'] ) {
				$queue['trigger'] = 'litespeed_purge_tags';
			}
		}
	}

	return $tags;
}

/**
 * LiteSpeed finished a purge-all.
 *
 * @return void
 */
function bpl_on_purged_all() {
	$queue         = &bpl_queue();
	$queue['zone'] = true;
	if ( '' === $queue['trigger'] || 'litespeed_purge_tags' === $queue['trigger'] ) {
		$queue['trigger'] = 'litespeed_purged_all';
	}
}

/**
 * LiteSpeed purged a specific public URL. Bunny cached that same site address.
 *
 * @param mixed $url URL or path from LiteSpeed.
 * @return void
 */
function bpl_on_purged_link( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return;
	}

	bpl_queue_page( $url, 'litespeed_purged_link' );
}

/**
 * Admin-bar “purge this page”. The current request is that page.
 *
 * @return void
 */
function bpl_on_purged_single() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	if ( ! is_string( $uri ) || '' === $uri ) {
		return;
	}

	bpl_queue_page( $uri, 'litespeed_purged_single' );
}

/**
 * Queue an exact page URL on the public site. No wildcard is added.
 *
 * @param string $url     Path or absolute URL.
 * @param string $trigger Trigger name.
 * @return void
 */
function bpl_queue_page( $url, $trigger ) {
	$url = (string) $url;
	if ( '' === $url ) {
		return;
	}

	$queue                   = &bpl_queue();
	$queue['pages'][ $url ]  = true;
	if ( '' === $queue['trigger'] ) {
		$queue['trigger'] = (string) $trigger;
	}
}

function bpl_on_purged_cssjs() {
	$queue                 = &bpl_queue();
	$queue['prefixes'][]   = '/wp-content/litespeed/';
	if ( '' === $queue['trigger'] ) {
		$queue['trigger'] = 'litespeed_purged_all_cssjs';
	}
}

/**
 * Queue every public URL for an attachment.
 *
 * @param int $post_id Attachment ID.
 * @return void
 */
function bpl_on_delete_attachment( $post_id ) {
	$urls = bpl_attachment_urls_for_post( (int) $post_id );
	foreach ( $urls as $url ) {
		bpl_queue_file( $url, 'delete_attachment' );
	}
}

/**
 * Attachments created during this request.
 *
 * @param int $post_id Attachment ID to add, or 0 to read.
 * @return array<int, bool>
 */
function bpl_new_attachments( $post_id = 0 ) {
	static $ids = array();
	if ( $post_id > 0 ) {
		$ids[ $post_id ] = true;
	}

	return $ids;
}

/**
 * Remember a new upload so its first metadata writes are not purged.
 *
 * @param int $post_id Attachment ID.
 * @return void
 */
function bpl_on_add_attachment( $post_id ) {
	bpl_new_attachments( (int) $post_id );
}

/**
 * Queue a replaced attachment and its sizes.
 *
 * @param mixed $data    Attachment metadata.
 * @param int   $post_id Attachment ID.
 * @return mixed Unchanged metadata.
 */
function bpl_on_attachment_metadata( $data, $post_id ) {
	$new = bpl_new_attachments();
	if ( isset( $new[ (int) $post_id ] ) ) {
		return $data;
	}

	$urls = bpl_attachment_urls_for_post( (int) $post_id );
	if ( is_array( $data ) && ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
		$url = wp_get_attachment_url( (int) $post_id );
		if ( is_string( $url ) && '' !== $url ) {
			$files = array();
			foreach ( $data['sizes'] as $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) ) {
					$files[] = (string) $size['file'];
				}
			}
			$urls = bpl_attachment_variant_urls( $url, $files );
		}
	}

	foreach ( $urls as $url ) {
		bpl_queue_file( $url, 'wp_update_attachment_metadata' );
	}

	return $data;
}

/**
 * URLs currently stored for an attachment.
 *
 * @param int $post_id Attachment ID.
 * @return string[]
 */
function bpl_attachment_urls_for_post( $post_id ) {
	$url = wp_get_attachment_url( $post_id );
	if ( ! is_string( $url ) || '' === $url ) {
		return array();
	}

	$meta  = wp_get_attachment_metadata( $post_id );
	$files = array();
	if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
		foreach ( $meta['sizes'] as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$files[] = (string) $size['file'];
			}
		}
	}

	return bpl_attachment_variant_urls( $url, $files );
}

/**
 * Elementor saved a document.
 *
 * @param mixed $document Elementor document.
 * @param mixed $data     Unused save payload.
 * @return void
 */
function bpl_on_elementor_save( $document, $data = null ) {
	unset( $data );

	$post_id   = 0;
	$post_type = '';
	$status    = '';
	$name      = '';

	if ( is_object( $document ) ) {
		if ( method_exists( $document, 'get_post' ) ) {
			$post = $document->get_post();
			if ( $post instanceof WP_Post ) {
				$post_id   = (int) $post->ID;
				$post_type = (string) $post->post_type;
				$status    = (string) $post->post_status;
			}
		}
		if ( method_exists( $document, 'get_name' ) ) {
			$name = (string) $document->get_name();
		}
	}

	$autosave = ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( $post_id && function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) );
	$revision = $post_id && function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id );
	$decision = bpl_elementor_save_decision( $post_type, $name, $status, (bool) $autosave, (bool) $revision );

	if ( 'ignore' === $decision ) {
		return;
	}

	if ( 'zone' === $decision ) {
		$settings = bpl_get_settings();
		if ( empty( $settings['purge_elementor_library'] ) ) {
			return;
		}
		bpl_mark_zone( 'elementor_library' );
		do_action( 'litespeed_purge_all', 'Purge Sync for Bunny and LiteSpeed - Elementor template' );
		return;
	}

	if ( $post_id < 1 ) {
		return;
	}

	$uploads = wp_get_upload_dir();
	if ( empty( $uploads['baseurl'] ) || ! is_string( $uploads['baseurl'] ) ) {
		return;
	}

	bpl_queue_file( $uploads['baseurl'] . '/elementor/css/post-' . $post_id . '.css', 'elementor_css' );
}

/**
 * The Customizer saved. LiteSpeed does not purge on this hook by default.
 *
 * @return void
 */
function bpl_on_customizer() {
	$settings = bpl_get_settings();
	if ( empty( $settings['purge_customizer'] ) ) {
		return;
	}

	bpl_mark_zone( 'customize_save_after' );
	do_action( 'litespeed_purge_all', 'Purge Sync for Bunny and LiteSpeed - Customizer' );
}

/**
 * A real plugin or theme update finished. Update checks are ignored.
 *
 * @param mixed $upgrader   Upgrader instance, unused.
 * @param mixed $hook_extra Upgrade context.
 * @return void
 */
function bpl_on_upgrade( $upgrader, $hook_extra ) {
	unset( $upgrader );

	$settings = bpl_get_settings();
	if ( empty( $settings['purge_upgrades'] ) || ! is_array( $hook_extra ) ) {
		return;
	}

	$action = isset( $hook_extra['action'] ) ? (string) $hook_extra['action'] : '';
	$type   = isset( $hook_extra['type'] ) ? (string) $hook_extra['type'] : '';
	if ( 'update' !== $action || ( 'plugin' !== $type && 'theme' !== $type ) ) {
		return;
	}

	$list = array();
	if ( 'plugin' === $type && isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
		$list = $hook_extra['plugins'];
	}
	if ( 'theme' === $type && isset( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
		$list = $hook_extra['themes'];
	}
	if ( array() === $list ) {
		return;
	}

	bpl_mark_zone( 'upgrader_process_complete' );
	do_action( 'litespeed_purge_all', 'Purge Sync for Bunny and LiteSpeed - update' );
}

/**
 * Send the queued purge after LiteSpeed has emitted its own purge header.
 *
 * @return void
 */
function bpl_flush_purge() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$queue = &bpl_queue();
	if ( ! $queue['zone'] && array() === $queue['tags'] && array() === $queue['urls'] && array() === $queue['pages'] && array() === $queue['prefixes'] ) {
		return;
	}

	if ( function_exists( 'fastcgi_finish_request' ) && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		fastcgi_finish_request();
	}

	$settings = bpl_get_settings();
	$pullzone = (int) $settings['pullzone_id'];
	if ( $pullzone < 1 || '' === bpl_get_api_key() ) {
		return;
	}

	$mode      = bpl_delivery_mode();
	$html_tags = ( 'acceleration' === $mode );
	$cdn       = bpl_cdn_hostname();
	$vary      = bpl_should_vary_file_versions();
	$allowed   = bpl_current_allowed_hosts();
	$prefix    = defined( 'LSWCP_TAG_PREFIX' ) ? (string) LSWCP_TAG_PREFIX : '';
	$trigger   = '' !== $queue['trigger'] ? $queue['trigger'] : 'shutdown';

	$file_urls = array();
	foreach ( array_keys( $queue['urls'] ) as $url ) {
		$target = bpl_purge_file_target( $url, $mode, $cdn, $vary );
		if ( '' !== $target && bpl_url_host_allowed( $target, $allowed ) ) {
			$file_urls[] = $target;
		}
	}
	foreach ( array_keys( $queue['pages'] ) as $page ) {
		$target = bpl_site_page_url( $page, home_url() );
		if ( '' !== $target && bpl_url_host_allowed( $target, $allowed ) ) {
			$file_urls[] = $target;
		}
	}
	foreach ( $queue['prefixes'] as $path ) {
		$target = bpl_purge_prefix_target( home_url(), $path, $mode, $cdn );
		if ( '' !== $target && bpl_url_host_allowed( $target, $allowed ) ) {
			$file_urls[] = $target;
		}
	}
	$file_urls = bpl_collapse_urls( $file_urls );

	$plan  = bpl_build_purge_plan( array_keys( $queue['tags'] ), $file_urls, $queue['zone'], $html_tags, $prefix );
	$calls = ( $plan['zone'] ? 1 : 0 ) + count( $plan['tags'] ) + count( $plan['urls'] );
	if ( $calls < 1 ) {
		return;
	}

	$budget = bpl_purge_call_budget( $plan );
	$rate   = bpl_rate_batch_decision(
		bpl_rate_bucket_used( 'exact' ),
		bpl_rate_bucket_used( 'prefix' ),
		$budget['exact'],
		$budget['prefix']
	);
	if ( 'collapse' === $rate ) {
		$plan  = array(
			'zone'   => true,
			'reason' => 'rate',
			'tags'   => array(),
			'urls'   => array(),
		);
		$calls = 1;
		$rate  = 'allow';
	}
	if ( 'allow' !== $rate ) {
		bpl_log_add( bpl_log_entry( 'zone', $trigger, 0, 429, 'Skipped. The per-minute purge cap was reached.' ) );
		bpl_remember_purge_notice( 'capped' );
		return;
	}

	$budget = bpl_purge_call_budget( $plan );
	bpl_rate_commit_bucket( 'exact', $budget['exact'] );
	bpl_rate_commit_bucket( 'prefix', $budget['prefix'] );

	$did_zone  = false;
	$did_tags  = false;
	$did_files = false;
	$worst     = 200;

	if ( $plan['zone'] ) {
		$did_zone = true;
		$result   = bpl_api_request( 'POST', 'https://api.bunny.net/pullzone/' . $pullzone . '/purgeCache', '{}', 3, false );
		$worst    = bpl_worse_status( $worst, bpl_observed_status( $result['code'] ) );
		bpl_log_add( bpl_log_entry( 'zone', $trigger, 1, $result['code'], bpl_zone_detail( isset( $plan['reason'] ) ? (string) $plan['reason'] : '' ) ) );
		if ( 401 === $result['code'] ) {
			bpl_remember_purge_notice( 'unauthorized' );
			return;
		}
	}

	if ( array() !== $plan['tags'] ) {
		$did_tags  = true;
		$tag_worst = 200;
		foreach ( $plan['tags'] as $tag ) {
			$body      = bpl_cache_tag_body( $tag );
			$result    = bpl_api_request( 'POST', 'https://api.bunny.net/pullzone/' . $pullzone . '/purgeCache', $body, 3, false );
			$tag_worst = bpl_worse_status( $tag_worst, bpl_observed_status( $result['code'] ) );
			if ( 401 === $result['code'] ) {
				bpl_log_add( bpl_log_entry( 'tag', $trigger, count( $plan['tags'] ), 401, count( $plan['tags'] ) . ' tags' ) );
				bpl_remember_purge_notice( 'unauthorized' );
				return;
			}
		}
		$worst = bpl_worse_status( $worst, $tag_worst );
		bpl_log_add( bpl_log_entry( 'tag', $trigger, count( $plan['tags'] ), $tag_worst, count( $plan['tags'] ) . ' tags' ) );
	}

	if ( array() !== $plan['urls'] ) {
		$did_files   = true;
		$prefix_urls = array();
		$exact_urls  = array();
		foreach ( $plan['urls'] as $url ) {
			if ( false !== strpos( $url, '*' ) ) {
				$prefix_urls[] = $url;
			} else {
				$exact_urls[] = $url;
			}
		}
		foreach ( array( 'url' => $exact_urls, 'prefix' => $prefix_urls ) as $kind => $group ) {
			$status = bpl_flush_url_group( $group, $kind, $trigger );
			$worst  = bpl_worse_status( $worst, $status );
			if ( 401 === $status ) {
				bpl_remember_purge_notice( 'unauthorized' );
				return;
			}
		}
	}

	$notice_code = bpl_purge_notice_code( $did_zone, $did_tags, $did_files, $worst );
	bpl_remember_purge_notice( $notice_code );
}

/**
 * Purge a group of URLs and write one log row.
 *
 * @param string[] $urls    Prepared URLs.
 * @param string   $kind    url or prefix.
 * @param string   $trigger Trigger name.
 * @return int Worst HTTP status, or 0 when there was nothing to purge.
 */
function bpl_flush_url_group( array $urls, $kind, $trigger ) {
	if ( array() === $urls ) {
		return 0;
	}

	$worst = 200;
	$paths = array();
	foreach ( $urls as $url ) {
		$request_url = bpl_purge_request_url( $url, $kind );
		$result      = bpl_api_request( 'POST', $request_url, null, 3, false );
		$paths[] = bpl_url_path( $url );
		if ( $result['code'] < 200 || $result['code'] >= 300 ) {
			$worst = bpl_observed_status( $result['code'] );
		}
		if ( 401 === $result['code'] ) {
			bpl_log_add( bpl_log_entry( $kind, $trigger, count( $urls ), 401, implode( ', ', array_slice( $paths, 0, 3 ) ) ) );
			return 401;
		}
	}

	bpl_log_add( bpl_log_entry( $kind, $trigger, count( $urls ), $worst, implode( ', ', array_slice( $paths, 0, 3 ) ) ) );

	return $worst;
}

/**
 * Pick the status that should drive the admin banner.
 *
 * @param int $current Current worst status.
 * @param int $next    Newly observed status. Zero means the call was skipped.
 * @return int
 */
function bpl_worse_status( $current, $next ) {
	$next = (int) $next;
	if ( $next < 1 ) {
		return (int) $current;
	}
	if ( $next >= 200 && $next < 300 ) {
		return (int) $current;
	}

	return $next;
}

/**
 * Banner code for one purge batch.
 *
 * @param bool $did_zone  A full-zone purge ran.
 * @param bool $did_tags  Tag purges ran.
 * @param bool $did_files File or prefix purges ran.
 * @param int  $worst     Worst HTTP status.
 * @return string
 */
function bpl_purge_notice_code( $did_zone, $did_tags, $did_files, $worst ) {
	$worst = (int) $worst;
	if ( 401 === $worst ) {
		return 'unauthorized';
	}
	if ( $worst < 200 || $worst >= 300 ) {
		return 'failed';
	}

	$kinds = ( $did_zone ? 1 : 0 ) + ( $did_tags ? 1 : 0 ) + ( $did_files ? 1 : 0 );
	if ( $kinds > 1 ) {
		return 'cleared';
	}
	if ( $did_zone ) {
		return 'cleared_zone';
	}
	if ( $did_files ) {
		return 'cleared_files';
	}

	return 'cleared_pages';
}

/**
 * Remember a one-time banner for the person who made the edit.
 *
 * The purge runs after the response is sent, so the banner appears on the
 * next admin screen, the same place LiteSpeed shows its own purge message.
 *
 * @param string $code Whitelisted notice code.
 * @return void
 */
function bpl_remember_purge_notice( $code ) {
	$allowed = array( 'cleared', 'cleared_zone', 'cleared_pages', 'cleared_files', 'failed', 'unauthorized', 'capped' );
	if ( ! in_array( $code, $allowed, true ) || ! function_exists( 'set_transient' ) || ! function_exists( 'get_current_user_id' ) ) {
		return;
	}
	if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
		return;
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return;
	}

	$user_id = (int) get_current_user_id();
	if ( $user_id < 1 || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	set_transient( 'bpl_purge_notice_' . $user_id, $code, 2 * MINUTE_IN_SECONDS );
}

/**
 * Purge the whole pull zone now. Used by the settings screen.
 *
 * @param string $trigger Trigger name for the log.
 * @return int HTTP status.
 */
function bpl_purge_zone_now( $trigger ) {
	$settings = bpl_get_settings();
	$pullzone = (int) $settings['pullzone_id'];
	if ( $pullzone < 1 ) {
		return 0;
	}

	$result = bpl_api_request( 'POST', 'https://api.bunny.net/pullzone/' . $pullzone . '/purgeCache', '{}', 3, false );
	$detail = 'Full Pull Zone. Manual purge.';
	if ( 'first_save' === $trigger ) {
		$detail = 'Full Pull Zone. First save.';
	} elseif ( 'import' === $trigger ) {
		$detail = 'Full Pull Zone. Imported key.';
	}
	bpl_log_add( bpl_log_entry( 'zone', $trigger, 1, $result['code'], $detail ) );

	return $result['code'];
}
