<?php
/**
 * Purge log capped at 50 rows.
 *
 * @package BunnyPurgeLiteSpeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build one log row. The detail never includes the API key.
 *
 * @param string $kind    tag, url, zone, or prefix.
 * @param string $trigger Hook or button that queued the purge.
 * @param int    $count   How many items this row covers.
 * @param int    $status  HTTP status, or 0 when the request did not finish.
 * @param string $detail  Short path or count. No response body.
 * @return array<string, int|string>
 */
function bpl_log_entry( $kind, $trigger, $count, $status, $detail ) {
	$kinds = array( 'tag', 'url', 'zone', 'prefix' );
	if ( ! in_array( $kind, $kinds, true ) ) {
		$kind = 'url';
	}

	$detail = preg_replace( '/[[:cntrl:]]/', '', (string) $detail );
	if ( ! is_string( $detail ) ) {
		$detail = '';
	}
	if ( strlen( $detail ) > 180 ) {
		$detail = substr( $detail, 0, 180 );
	}

	$trigger = preg_replace( '/[^A-Za-z0-9_:-]/', '', (string) $trigger );
	if ( ! is_string( $trigger ) ) {
		$trigger = '';
	}

	return array(
		'time'    => gmdate( 'c' ),
		'kind'    => $kind,
		'trigger' => substr( $trigger, 0, 80 ),
		'count'   => (int) $count,
		'status'  => (int) $status,
		'detail'  => $detail,
	);
}

/**
 * Keep the newest rows, up to the cap.
 *
 * @param array<int, array<string, mixed>> $entries Existing rows, newest first.
 * @param array<string, mixed>             $entry   Row to add.
 * @param int                              $max     Maximum rows.
 * @return array<int, array<string, mixed>>
 */
function bpl_log_push( array $entries, array $entry, $max = 50 ) {
	array_unshift( $entries, $entry );
	$max = (int) $max;
	if ( $max < 1 ) {
		$max = 1;
	}
	if ( count( $entries ) > $max ) {
		$entries = array_slice( $entries, 0, $max );
	}

	return $entries;
}

/**
 * Append a row and prune the stored log to 50 entries.
 *
 * @param array<string, int|string> $entry Log row.
 * @return void
 */
function bpl_log_add( array $entry ) {
	if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
		return;
	}

	$stored = get_option( 'bunny_litespeed_purge_log', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	update_option( 'bunny_litespeed_purge_log', bpl_log_push( $stored, $entry, 50 ), false );
}

/**
 * Read the log for the settings screen.
 *
 * @return array<int, array<string, mixed>>
 */
function bpl_log_entries() {
	if ( ! function_exists( 'get_option' ) ) {
		return array();
	}

	$stored = get_option( 'bunny_litespeed_purge_log', array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	return array_slice( $stored, 0, 50 );
}
