<?php
/**
 * The recovery queue. A five-minute WP-Cron job promotes idle carts to
 * "abandoned", sends the due sequence step, schedules the next, and runs the
 * daily retention purge.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron worker.
 */
class CRW_Scheduler {

	/**
	 * Hook up.
	 */
	public function register() {
		// The cron_schedules filter is registered at plugin-load (main file) so
		// the interval exists during activation too.
		add_action( CRW_Install::CRON_HOOK, array( $this, 'run' ) );
		// Self-heal the schedule if it was lost.
		if ( ! wp_next_scheduled( CRW_Install::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'crw_five_minutes', CRW_Install::CRON_HOOK );
		}
	}

	/**
	 * Process one batch.
	 */
	public function run() {
		// Observe anonymous guest carts for the lost-revenue estimate (hourly,
		// cheap, PII-free) — even when capture is toggled off.
		if ( class_exists( 'CRW_Estimates' ) ) {
			CRW_Estimates::maybe_scan();
		}
		if ( ! CRW_Options::get( 'capture.enabled', true ) ) {
			return;
		}
		$after = (int) CRW_Options::get( 'capture.abandon_after_minutes', 30 );

		// 1) Route each newly-idle cart into the first matching sequence (by
		//    segment), scheduling its first step — or mark 'lost' if none match.
		foreach ( CRW_Carts_Store::newly_idle( $after, 100 ) as $row ) {
			$seq_id = CRW_Segments::first_matching( $row );
			$seq    = '' !== $seq_id ? CRW_Options::sequence( $seq_id ) : null;
			$steps  = $seq ? array_values( (array) ( $seq['steps'] ?? array() ) ) : array();
			if ( empty( $steps ) ) {
				CRW_Carts_Store::assign_sequence( (int) $row['id'], '', null );
				continue;
			}
			$next = gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $steps[0]['delay_minutes'] ) * MINUTE_IN_SECONDS );
			CRW_Carts_Store::assign_sequence( (int) $row['id'], $seq_id, $next );
		}

		// 2) Send whatever is due. Claim each row (push its next action out)
		//    before sending so an overlapping cron run can't double-send, and
		//    only advance on success (failures retry, capped).
		$max_retries = 3;
		foreach ( CRW_Carts_Store::due( 50 ) as $row ) {
			$id    = (int) $row['id'];
			$step  = (int) $row['step'];
			$seq   = CRW_Options::sequence( (string) $row['sequence_id'] );
			$steps = $seq ? array_values( (array) ( $seq['steps'] ?? array() ) ) : array();
			if ( ! isset( $steps[ $step ] ) ) {
				CRW_Carts_Store::advance( $id, $step, null, 'lost' );
				continue;
			}

			// Atomic claim — skip if an overlapping run already grabbed this row.
			if ( ! CRW_Carts_Store::claim( $id, (string) $row['next_action_at'], gmdate( 'Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS ) ) ) {
				continue;
			}

			$sent = CRW_Mailer::send_step( $row, $seq, $step );

			if ( $sent ) {
				$next = $step + 1;
				if ( isset( $steps[ $next ] ) ) {
					$gap    = max( 1, (int) $steps[ $next ]['delay_minutes'] - (int) $steps[ $step ]['delay_minutes'] );
					$nextat = gmdate( 'Y-m-d H:i:s', time() + $gap * MINUTE_IN_SECONDS );
					CRW_Carts_Store::advance( $id, $next, $nextat, 'abandoned' );
				} else {
					CRW_Carts_Store::advance( $id, $next, null, 'lost' );
				}
				if ( class_exists( 'CRW_Audiences' ) ) {
					CRW_Audiences::maybe_sync( $row );
				}
				// Second channel: also push to the browser if it opted in.
				if ( class_exists( 'CRW_Push' ) && CRW_Push::ready() ) {
					CRW_Push::push_cart( $row );
				}
			} else {
				$attempts = (int) $row['send_attempts'] + 1;
				if ( $attempts >= $max_retries ) {
					CRW_Carts_Store::advance( $id, $step, null, 'lost' );
				} else {
					CRW_Carts_Store::bump_attempts( $id, $attempts );
				}
			}
		}

		// 3) Daily retention + data-minimization purge.
		if ( ! get_transient( 'crw_purged_today' ) ) {
			CRW_Carts_Store::purge( (int) CRW_Options::get( 'retention_days', 60 ) );
			set_transient( 'crw_purged_today', 1, DAY_IN_SECONDS );
		}
	}
}
