<?php
/**
 * Live panels for the dashboard: what ZipLogger's read interface says about this site.
 *
 * @package ZipLogger
 */

namespace ZipLogger\WordPress\Dashboard;

use ZipLogger\WordPress\Endpoint;
use ZipLogger\WordPress\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Each panel is one or two read-only queries, scoped to THIS site's source label, turned into small,
 * fully escaped, accessible HTML (tables with captions, text summaries next to every chart). Everything
 * that comes back from the service is data: it is escaped, truncated and never executed or trusted as
 * markup, and every failure is shown as an ordinary message (unavailable, not authorized, no data), never
 * as a broken screen.
 *
 * What exists here is what the read interface can answer: logs (Loki-style queries), request metrics
 * (Prometheus-style) and traces (Tempo-style). It has no product-analytics or replay query, so the
 * Analytics, Session replay and WooCommerce tabs show local facts and links into ZipLogger instead of
 * numbers this plugin could not verify.
 */
final class Panels {

	const IDS = array( 'errors', 'error_trend', 'browser_errors', 'browser_failed', 'vitals', 'tracing_stats', 'traces' );

	/**
	 * Web Vitals thresholds (good, poor) from the Web Vitals programme.
	 */
	const VITALS = array(
		'LCP'  => array( 2500, 4000, 'ms' ),
		'INP'  => array( 200, 500, 'ms' ),
		'CLS'  => array( 0.1, 0.25, '' ),
		'FCP'  => array( 1800, 3000, 'ms' ),
		'TTFB' => array( 800, 1800, 'ms' ),
	);

	/**
	 * Print the container of a panel. The content is fetched by the admin script (with a nonce, as an
	 * administrator) so the page itself loads instantly and never waits for ZipLogger.
	 *
	 * @param string $id    Panel id.
	 * @param string $title Heading.
	 * @return void
	 */
	public static function slot( $id, $title ) {
		$reason = Remote::unavailable_reason();
		if ( '' !== $reason ) {
			// Nothing to fetch, so nothing to enhance: no nonce, no endpoint.
			echo '<div class="ziplogger-live"><h3>' . esc_html( $title ) . '</h3><p class="description">' . esc_html( $reason ) . '</p></div>';
			return;
		}
		echo '<div class="ziplogger-live" data-ziplogger-panel="' . esc_attr( $id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'ziplogger_panel' ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
		echo '<h3>' . esc_html( $title ) . '</h3>';
		echo '<div class="ziplogger-live-body" aria-live="polite"><p class="description">' . esc_html__( 'Loading...', 'ziplogger-error-monitoring-session-replay' ) . '</p></div>';
		echo '<noscript><p class="description">' . esc_html__( 'Live data needs JavaScript in this screen. Everything else works without it.', 'ziplogger-error-monitoring-session-replay' ) . '</p></noscript>';
		echo '<p><button type="button" class="button-link ziplogger-live-refresh">' . esc_html__( 'Refresh', 'ziplogger-error-monitoring-session-replay' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Build a panel.
	 *
	 * @param string $id      Panel id (one of IDS).
	 * @param bool   $refresh Bypass the cache.
	 * @return array{ok:bool,html:string,error:string}
	 */
	public static function render( $id, $refresh = false ) {
		if ( ! in_array( $id, self::IDS, true ) ) {
			return self::error( __( 'Unknown panel.', 'ziplogger-error-monitoring-session-replay' ) );
		}
		$reason = Remote::unavailable_reason();
		if ( '' !== $reason ) {
			return self::error( $reason );
		}
		$s = Settings::get();
		try {
			switch ( $id ) {
				case 'errors':
					return self::log_list( '{service="' . $s['source'] . '", severity=~"error|fatal"}', __( 'Recent server errors', 'ziplogger-error-monitoring-session-replay' ), $refresh );
				case 'error_trend':
					return self::trend( 'count_over_time({service="' . $s['source'] . '", severity=~"error|fatal"}[1h])', __( 'Server errors per hour, last 24 hours', 'ziplogger-error-monitoring-session-replay' ), $refresh );
				case 'browser_errors':
					return self::log_list( '{service="' . $s['source'] . '", field_eventType="js_error"}', __( 'Recent JavaScript errors', 'ziplogger-error-monitoring-session-replay' ), $refresh );
				case 'browser_failed':
					return self::trend( 'count_over_time({service="' . $s['source'] . '", field_eventType="request_failed"}[1h])', __( 'Failed browser requests per hour, last 24 hours', 'ziplogger-error-monitoring-session-replay' ), $refresh );
				case 'vitals':
					return self::vitals( $s['source'], $refresh );
				case 'tracing_stats':
					return self::tracing_stats( self::service_name( $s ), $refresh );
				default:
					return self::traces( self::service_name( $s ), $refresh );
			}
		} catch ( \Throwable $e ) {
			return self::error( __( 'This panel could not be built.', 'ziplogger-error-monitoring-session-replay' ) );
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Panels.
	// ---------------------------------------------------------------------------------------------

	/**
	 * A list of recent log lines.
	 *
	 * @param string $selector LogQL stream selector.
	 * @param string $caption  Table caption.
	 * @param bool   $refresh  Bypass the cache.
	 * @return array
	 */
	private static function log_list( $selector, $caption, $refresh ) {
		$r = Remote::get(
			'/grafana/loki/api/v1/query_range',
			self::window(
				array(
					'query' => $selector,
					'limit' => 8,
				)
			),
			$refresh
		);
		if ( ! $r['ok'] ) {
			return self::error( $r['error'] );
		}
		$lines = self::parse_streams( $r['data'] );
		if ( ! $lines ) {
			return self::ok( '<p class="description">' . esc_html__( 'Nothing in the last 24 hours.', 'ziplogger-error-monitoring-session-replay' ) . '</p>' );
		}
		$html = '<table class="widefat striped ziplogger-live-table"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'When', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( 'Message', 'ziplogger-error-monitoring-session-replay' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $lines, 0, 8 ) as $line ) {
			$message = self::first_part( $line['line'] );
			$html   .= '<tr><td><time datetime="' . esc_attr( gmdate( 'c', $line['time'] ) ) . '">' . esc_html( wp_date( 'M j, H:i', $line['time'] ) ) . '</time></td><td>' . esc_html( self::truncate( $message, 180 ) ) . '</td></tr>';
		}
		return self::ok( $html . '</tbody></table>' . self::app_link( '/', __( 'Search these in ZipLogger', 'ziplogger-error-monitoring-session-replay' ) ) );
	}

	/**
	 * A per-hour count as a small chart with a text summary.
	 *
	 * @param string $query   LogQL metric query.
	 * @param string $caption Caption.
	 * @param bool   $refresh Bypass the cache.
	 * @return array
	 */
	private static function trend( $query, $caption, $refresh ) {
		$r = Remote::get( '/grafana/loki/api/v1/query_range', self::window( array( 'query' => $query ) ), $refresh );
		if ( ! $r['ok'] ) {
			return self::error( $r['error'] );
		}
		$series = self::parse_matrix( $r['data'] );
		if ( ! $series ) {
			return self::ok( '<p class="description">' . esc_html__( 'Nothing in the last 24 hours.', 'ziplogger-error-monitoring-session-replay' ) . '</p>' );
		}
		return self::ok( self::sparkline( $series, $caption ) );
	}

	/**
	 * Core Web Vitals: the 75th percentile of recent measurements per metric, with the rating text.
	 *
	 * @param string $source Source label.
	 * @param bool   $refresh Bypass the cache.
	 * @return array
	 */
	private static function vitals( $source, $refresh ) {
		$r = Remote::get(
			'/grafana/loki/api/v1/query_range',
			self::window(
				array(
					'query' => '{service="' . $source . '", field_eventType="web_vital"}',
					'limit' => 500,
				)
			),
			$refresh
		);
		if ( ! $r['ok'] ) {
			return self::error( $r['error'] );
		}
		$by = array();
		foreach ( self::parse_streams( $r['data'] ) as $entry ) {
			$f = self::logfmt( $entry['line'] );
			if ( isset( $f['metric'], $f['value'] ) && isset( self::VITALS[ $f['metric'] ] ) && is_numeric( $f['value'] ) ) {
				$by[ $f['metric'] ][] = (float) $f['value'];
			}
		}
		if ( ! $by ) {
			return self::ok( '<p class="description">' . esc_html__( 'No measurements in the last 24 hours. They are recorded for a sample of page views once "Core Web Vitals" is enabled.', 'ziplogger-error-monitoring-session-replay' ) . '</p>' );
		}
		$html = '<table class="widefat striped ziplogger-live-table"><caption class="screen-reader-text">' . esc_html__( 'Core Web Vitals, 75th percentile of recent measurements', 'ziplogger-error-monitoring-session-replay' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Metric', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( '75th percentile', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( 'Rating', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( 'Measurements', 'ziplogger-error-monitoring-session-replay' ) . '</th></tr></thead><tbody>';
		foreach ( self::VITALS as $metric => $spec ) {
			if ( empty( $by[ $metric ] ) ) {
				continue;
			}
			$p75    = self::percentile( $by[ $metric ], 75 );
			$rating = $p75 <= $spec[0] ? __( 'Good', 'ziplogger-error-monitoring-session-replay' ) : ( $p75 <= $spec[1] ? __( 'Needs improvement', 'ziplogger-error-monitoring-session-replay' ) : __( 'Poor', 'ziplogger-error-monitoring-session-replay' ) );
			$html  .= '<tr><th scope="row">' . esc_html( $metric ) . '</th><td>' . esc_html( ( 'ms' === $spec[2] ? number_format_i18n( $p75, 0 ) . ' ms' : number_format_i18n( $p75, 3 ) ) ) . '</td><td>' . esc_html( $rating ) . '</td><td>' . esc_html( number_format_i18n( count( $by[ $metric ] ) ) ) . '</td></tr>';
		}
		return self::ok( $html . '</tbody></table>' );
	}

	/**
	 * Request rate, errors and latency of the site's server spans.
	 *
	 * @param string $service Service name.
	 * @param bool   $refresh Bypass the cache.
	 * @return array
	 */
	private static function tracing_stats( $service, $refresh ) {
		$totals = array();
		foreach ( array(
			'ziplogger_request_count'       => 'requests',
			'ziplogger_request_error_count' => 'errors',
			'ziplogger_request_p95_ms'      => 'p95',
		) as $metric => $key ) {
			$r = Remote::get(
				'/grafana/prometheus/api/v1/query_range',
				self::window(
					array(
						'query' => $metric . '{service="' . $service . '"}',
						'step'  => '1h',
					)
				),
				$refresh
			);
			if ( ! $r['ok'] ) {
				return self::error( $r['error'] );
			}
			$series         = self::parse_matrix( $r['data'] );
			$totals[ $key ] = $series;
		}
		if ( ! $totals['requests'] ) {
			return self::ok( '<p class="description">' . esc_html__( 'No request spans in the last 24 hours. They appear once tracing is on and requests have been sampled.', 'ziplogger-error-monitoring-session-replay' ) . '</p>' );
		}
		$requests = array_sum( array_column( $totals['requests'], 1 ) );
		$errors   = array_sum( array_column( $totals['errors'], 1 ) );
		$p95      = $totals['p95'] ? end( $totals['p95'] )[1] : null;
		$html     = '<table class="widefat striped ziplogger-live-table"><caption class="screen-reader-text">' . esc_html__( 'Server request spans, last 24 hours', 'ziplogger-error-monitoring-session-replay' ) . '</caption><tbody>';
		$html    .= '<tr><th scope="row">' . esc_html__( 'Sampled requests', 'ziplogger-error-monitoring-session-replay' ) . '</th><td>' . esc_html( number_format_i18n( (int) $requests ) ) . '</td></tr>';
		$html    .= '<tr><th scope="row">' . esc_html__( 'With an error status', 'ziplogger-error-monitoring-session-replay' ) . '</th><td>' . esc_html( number_format_i18n( (int) $errors ) ) . '</td></tr>';
		if ( null !== $p95 ) {
			$html .= '<tr><th scope="row">' . esc_html__( 'Latest hourly p95 latency', 'ziplogger-error-monitoring-session-replay' ) . '</th><td>' . esc_html( number_format_i18n( (float) $p95, 0 ) ) . ' ms</td></tr>';
		}
		$html .= '</tbody></table><p class="description">' . esc_html__( 'Counts cover the requests that were sampled, not every request.', 'ziplogger-error-monitoring-session-replay' ) . '</p>';
		return self::ok( $html . self::sparkline( $totals['requests'], __( 'Sampled requests per hour, last 24 hours', 'ziplogger-error-monitoring-session-replay' ) ) );
	}

	/**
	 * Recent traces, and recent failed ones, with links into ZipLogger.
	 *
	 * @param string $service Service name.
	 * @param bool   $refresh Bypass the cache.
	 * @return array
	 */
	private static function traces( $service, $refresh ) {
		$html = '';
		foreach ( array(
			'errors' => array( __( 'Recent failed requests', 'ziplogger-error-monitoring-session-replay' ), array( 'status' => 'error' ) ),
			'recent' => array( __( 'Recent requests', 'ziplogger-error-monitoring-session-replay' ), array() ),
		) as $spec ) {
			$r = Remote::get(
				'/grafana/tempo/api/search',
				self::window(
					array_merge(
						array(
							'service.name' => $service,
							'limit'        => 5,
						),
						$spec[1]
					)
				),
				$refresh
			);
			if ( ! $r['ok'] ) {
				return self::error( $r['error'] );
			}
			$traces = isset( $r['data']['traces'] ) && is_array( $r['data']['traces'] ) ? $r['data']['traces'] : array();
			$html  .= '<h4>' . esc_html( $spec[0] ) . '</h4>';
			if ( ! $traces ) {
				$html .= '<p class="description">' . esc_html__( 'None in the last 24 hours.', 'ziplogger-error-monitoring-session-replay' ) . '</p>';
				continue;
			}
			$html .= '<ul class="ziplogger-traces">';
			foreach ( array_slice( $traces, 0, 5 ) as $t ) {
				$id = isset( $t['traceID'] ) && is_string( $t['traceID'] ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $t['traceID'] ) ? $t['traceID'] : '';
				if ( '' === $id ) {
					continue;
				}
				$name  = isset( $t['rootTraceName'] ) && is_string( $t['rootTraceName'] ) ? self::truncate( $t['rootTraceName'], 80 ) : '';
				$ms    = isset( $t['durationMs'] ) && is_numeric( $t['durationMs'] ) ? number_format_i18n( (float) $t['durationMs'], 0 ) . ' ms' : '';
				$html .= '<li><a href="' . esc_url( self::app( '/traces/' . $id ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( '' !== $name ? $name : $id ) . '</a> <span class="description">' . esc_html( $ms ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		return self::ok( $html );
	}

	// ---------------------------------------------------------------------------------------------
	// Parsing (pure).
	// ---------------------------------------------------------------------------------------------

	/**
	 * Lines from a Loki "streams" result, newest first.
	 *
	 * @param array $data Decoded response.
	 * @return array<int,array{time:int,line:string}>
	 */
	public static function parse_streams( array $data ) {
		$out    = array();
		$result = isset( $data['data']['result'] ) && is_array( $data['data']['result'] ) ? $data['data']['result'] : array();
		foreach ( $result as $stream ) {
			if ( ! isset( $stream['values'] ) || ! is_array( $stream['values'] ) ) {
				continue;
			}
			foreach ( $stream['values'] as $pair ) {
				if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && is_scalar( $pair[0] ) && is_string( $pair[1] ) && 1 === preg_match( '/^\d{10,19}$/', (string) $pair[0] ) ) {
					$out[] = array(
						'time' => (int) floor( (float) $pair[0] / 1000000000 ),
						'line' => $pair[1],
					);
				}
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);
		return $out;
	}

	/**
	 * Points from a matrix result (Loki metric query or Prometheus range query), summed across series.
	 *
	 * @param array $data Decoded response.
	 * @return array<int,array{0:int,1:float}> [time, value] pairs, oldest first.
	 */
	public static function parse_matrix( array $data ) {
		$sum    = array();
		$result = isset( $data['data']['result'] ) && is_array( $data['data']['result'] ) ? $data['data']['result'] : array();
		foreach ( $result as $series ) {
			if ( ! isset( $series['values'] ) || ! is_array( $series['values'] ) ) {
				continue;
			}
			foreach ( $series['values'] as $pair ) {
				if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && is_numeric( $pair[0] ) && is_numeric( $pair[1] ) ) {
					$t         = (int) floor( (float) $pair[0] );
					$sum[ $t ] = ( isset( $sum[ $t ] ) ? $sum[ $t ] : 0.0 ) + (float) $pair[1];
				}
			}
		}
		ksort( $sum );
		$out = array();
		foreach ( $sum as $t => $v ) {
			$out[] = array( $t, $v );
		}
		return $out;
	}

	/**
	 * Key/value pairs from a logfmt tail ("message key=value key2=\"a b\"").
	 *
	 * @param string $line Line.
	 * @return array<string,string>
	 */
	public static function logfmt( $line ) {
		$out = array();
		if ( preg_match_all( '/(?:^|\s)([A-Za-z_][A-Za-z0-9_]*)=("(?:[^"\\\\]|\\\\.)*"|\S*)/', (string) $line, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $pair ) {
				$value = $pair[2];
				if ( '"' === substr( $value, 0, 1 ) && '"' === substr( $value, -1 ) && strlen( $value ) >= 2 ) {
					$value = stripcslashes( substr( $value, 1, -1 ) );
				}
				$out[ $pair[1] ] = $value;
			}
		}
		return $out;
	}

	/**
	 * A percentile (nearest rank) of a list of numbers.
	 *
	 * @param float[] $values  Values.
	 * @param int     $percent 0-100.
	 * @return float
	 */
	public static function percentile( array $values, $percent ) {
		sort( $values );
		$n = count( $values );
		if ( 0 === $n ) {
			return 0.0;
		}
		$rank = (int) ceil( ( $percent / 100 ) * $n );
		return (float) $values[ max( 0, min( $n - 1, $rank - 1 ) ) ];
	}

	// ---------------------------------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------------------------------

	/**
	 * The last 24 hours as query parameters.
	 *
	 * @param array $query Query.
	 * @return array
	 */
	private static function window( array $query ) {
		// The next whole minute, so that the newest events are included (rounding down would hide the last minute,
		// which is when an administrator looks for what just happened) and identical requests share a cache entry.
		$end = (int) ceil( time() / 60 ) * 60;
		return array_merge(
			$query,
			array(
				'start' => $end - DAY_IN_SECONDS,
				'end'   => $end,
			)
		);
	}

	/**
	 * The message part of a logfmt line.
	 *
	 * ZipLogger writes the message as it is, then the structured fields as "key=value". A message may itself
	 * contain "token=[redacted]", so the line is cut at the first field this plugin always sends (every event has
	 * an eventType); only a line without any of them is cut at its first "key=value".
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function first_part( $line ) {
		$known = '/\s(?:stack_trace|eventType|environment|siteHost|requestId|messageTemplate|wordpressVersion|pluginVersion|phpVersion|traceId|spanId)=/';
		if ( preg_match( $known, $line, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1];
		} else {
			$pos = preg_match( '/\s[A-Za-z_][A-Za-z0-9_]*=/', $line, $m, PREG_OFFSET_CAPTURE ) ? $m[0][1] : strlen( $line );
		}
		return trim( substr( $line, 0, $pos ) );
	}

	/**
	 * Truncate at a character boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function truncate( $text, $max ) {
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/**
	 * The service name spans carry: the tracing setting, else the source label.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function service_name( array $s ) {
		return '' !== $s['tracing']['service_name'] ? $s['tracing']['service_name'] : $s['source'];
	}

	/**
	 * A small line chart as inline SVG, with a text summary and the numbers in a table.
	 *
	 * @param array  $series  [time, value] pairs.
	 * @param string $caption Caption.
	 * @return string
	 */
	private static function sparkline( array $series, $caption ) {
		$values = array_column( $series, 1 );
		$total  = array_sum( $values );
		$max    = max( $values );
		$w      = 240;
		$h      = 48;
		$n      = count( $series );
		$points = array();
		foreach ( $series as $i => $pair ) {
			$x        = $n > 1 ? round( $i * ( $w / ( $n - 1 ) ), 1 ) : $w / 2;
			$y        = $max > 0 ? round( $h - 4 - ( $pair[1] / $max ) * ( $h - 8 ), 1 ) : $h - 4;
			$points[] = $x . ',' . $y;
		}
		$summary = sprintf(
			/* translators: 1: total count, 2: highest single hour */
			__( '%1$s in total, at most %2$s in one hour.', 'ziplogger-error-monitoring-session-replay' ),
			number_format_i18n( (int) $total ),
			number_format_i18n( (int) $max )
		);
		$html  = '<figure class="ziplogger-spark"><svg viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="' . (int) $w . '" height="' . (int) $h . '" role="img" aria-label="' . esc_attr( $caption . '. ' . $summary ) . '"><polyline fill="none" stroke="currentColor" stroke-width="2" points="' . esc_attr( implode( ' ', $points ) ) . '" /></svg>';
		$html .= '<figcaption>' . esc_html( $caption ) . '<br /><span class="description">' . esc_html( $summary ) . '</span></figcaption></figure>';
		$html .= '<details><summary>' . esc_html__( 'Show the numbers', 'ziplogger-error-monitoring-session-replay' ) . '</summary><table class="widefat striped"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Hour', 'ziplogger-error-monitoring-session-replay' ) . '</th><th scope="col">' . esc_html__( 'Count', 'ziplogger-error-monitoring-session-replay' ) . '</th></tr></thead><tbody>';
		foreach ( $series as $pair ) {
			$html .= '<tr><td>' . esc_html( wp_date( 'M j, H:i', $pair[0] ) ) . '</td><td>' . esc_html( number_format_i18n( (float) $pair[1], 0 ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></details>';
	}

	/**
	 * An "open in ZipLogger" link.
	 *
	 * @param string $path  App path.
	 * @param string $label Link text.
	 * @return string
	 */
	private static function app_link( $path, $label ) {
		return '<p><a href="' . esc_url( self::app( $path ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * A URL in the ZipLogger application.
	 *
	 * @param string $path Path starting with "/".
	 * @return string
	 */
	public static function app( $path ) {
		$base = Settings::endpoint_base();
		return rtrim( Endpoint::app_url( '' === $base ? '' : $base ), '/' ) . $path;
	}

	/**
	 * A successful panel.
	 *
	 * @param string $html Escaped HTML.
	 * @return array
	 */
	private static function ok( $html ) {
		return array(
			'ok'    => true,
			'html'  => $html,
			'error' => '',
		);
	}

	/**
	 * A failed panel.
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private static function error( $message ) {
		return array(
			'ok'    => false,
			'html'  => '',
			'error' => $message,
		);
	}
}
