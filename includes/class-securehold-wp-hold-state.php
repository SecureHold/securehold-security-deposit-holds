<?php
/**
 * The one place a deposit changes state.
 *
 * Before this existed, six paths wrote the holds table and the order meta
 * independently, and two of them — the admin capture and release handlers —
 * wrote the table without ever touching the meta. Staging showed the result:
 * a deposit captured at Stripe, captured in the table, and still reported as
 * authorized on the order screen. A webhook arriving after an admin action
 * then overwrote the terminal timestamp, duplicated the order note and sent
 * the customer a second email.
 *
 * securehold_holds stays the business truth. _securehold_deposit_status is a
 * projection of it, written here and nowhere else on the terminal paths.
 *
 * Notes and emails stay with the callers: this decides whether a transition
 * applies and reports it, so a caller emits its own side effects only when
 * 'applied' comes back true. A state helper has no business sending mail.
 *
 * @package SecureHold
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Hold_State {

    /** Statuses no ordinary transition may leave. */
    const TERMINAL = array( 'captured', 'released' );

    /**
     * Move a deposit to a new state, or explain why it stays put.
     *
     * @param object|int $hold    Hold row, or an order id to look one up.
     * @param string     $to      Target status.
     * @param array      $context {
     *     @type float  $captured_amount Amount captured, for 'captured'.
     *     @type int    $occurred_at     Unix time the transition really happened.
     *                                   A delayed webhook must never turn its own
     *                                   delivery time into the business time.
     *     @type string $source          'admin' | 'webhook' | 'scheduler'.
     * }
     * @return array{applied: bool, from: string, to: string, reason: string}
     */
    public static function transition( $hold, $to, array $context = array() ) {
        $hold = self::resolve( $hold );

        if ( ! $hold ) {
            return self::result( false, '', $to, 'hold_not_found' );
        }

        $from   = (string) $hold->status;
        $source = isset( $context['source'] ) ? $context['source'] : 'unknown';

        // ── Same terminal state twice: the defining idempotent case ──
        // A webhook repeating what the admin already did lands here.
        if ( $from === $to && in_array( $to, self::TERMINAL, true ) ) {
            self::log( "Hold state: skipped, already {$to}", $hold, $from, $to, $source, 'debug' );
            return self::result( false, $from, $to, 'already_final' );
        }

        // ── Stepping back out of a terminal state ──
        // Nothing legitimate does this; it is noise, not a contradiction.
        if ( in_array( $from, self::TERMINAL, true ) && ! in_array( $to, self::TERMINAL, true ) ) {
            self::log( "Hold state: ignored {$from} -> {$to}", $hold, $from, $to, $source, 'debug' );
            return self::result( false, $from, $to, 'ignored' );
        }

        // ── captured <-> released ──
        // Stripe should never produce this. If it does, the table and Stripe
        // disagree about money and somebody needs to look. No state is invented
        // to absorb the contradiction, and nothing is written.
        if ( in_array( $from, self::TERMINAL, true ) && in_array( $to, self::TERMINAL, true ) && $from !== $to ) {
            self::log( "Hold state: incompatible {$from} -> {$to}", $hold, $from, $to, $source, 'warning' );
            return self::result( false, $from, $to, 'incompatible' );
        }

        // ── Apply ──
        $fields = array( 'status' => $to );
        $stamp  = self::stamp( $context );

        // A terminal timestamp is written once. This is what stopped a webhook
        // arriving a second later from rewriting released_at.
        if ( $to === 'captured' && empty( $hold->captured_at ) ) {
            $fields['captured_at'] = $stamp;
        }
        if ( $to === 'released' && empty( $hold->released_at ) ) {
            $fields['released_at'] = $stamp;
        }

        // Routing the authorization webhook through this helper dropped two
        // writes the old handler did itself: authorized_at, and the expiry the
        // auto-release cron reads. Both are restored here, and both are written
        // once. A replayed webhook must never push an existing deadline further
        // out — that would keep a hold alive past the window the customer was
        // told about.
        // Tracked ahead of the DB write below, before $hold's own fields are
        // touched: this is the one point that reliably answers "has this
        // site ever had a hold actually authorized", for the opt-in
        // telemetry flag (Securehold_Wp_Telemetry::mark_first_hold_created).
        // No hold/order identifier travels with the action — just the fact.
        $is_first_authorization = ( $to === 'authorized' && empty( $hold->authorized_at ) );

        if ( $to === 'authorized' ) {
            if ( empty( $hold->authorized_at ) ) {
                $fields['authorized_at'] = $stamp;
            }
            if ( empty( $hold->expires_at ) && class_exists( 'SecureHold_DB' ) ) {
                $fields['expires_at'] = SecureHold_DB::authorization_expiry();
            }
        }

        // Partial captures may grow the amount; they may never shrink it.
        if ( isset( $context['captured_amount'] ) ) {
            $amount = (float) $context['captured_amount'];
            if ( $amount > (float) $hold->captured_amount ) {
                $fields['captured_amount'] = $amount;
            }
        }

        if ( class_exists( 'SecureHold_DB' ) ) {
            SecureHold_DB::update_hold( $hold->id, $fields );
        }

        self::project( $hold->order_id, $to, isset( $fields['captured_amount'] ) ? $fields['captured_amount'] : null );

        self::log( "Hold state: {$from} -> {$to}", $hold, $from, $to, $source, 'info' );

        if ( $is_first_authorization ) {
            do_action( 'securehold_hold_first_authorized' );
        }

        return self::result( true, $from, $to, 'applied' );
    }

    /**
     * Mirror the status onto the order.
     *
     * The order meta is read by the support bundle, the PRO export and the PRO
     * diagnostics. None of them compare its value in a query — PRO's meta_query
     * tests EXISTS — so this is a display projection, and correcting it changes
     * no reader's behaviour.
     *
     * @param int        $order_id
     * @param string     $status
     * @param float|null $captured_amount
     * @return void
     */
    private static function project( $order_id, $status, $captured_amount = null ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $order->update_meta_data( '_securehold_deposit_status', $status );

        if ( $captured_amount !== null ) {
            $order->update_meta_data( '_securehold_captured_amount', $captured_amount );
        }

        $order->save();
    }

    /**
     * When the transition actually happened.
     *
     * Callers pass the moment Stripe reports — a PaymentIntent's canceled_at,
     * an event's created — so a webhook delayed by a retry does not stamp the
     * deposit with its own arrival time. Only without that does the clock win.
     *
     * @param array $context
     * @return string MySQL datetime.
     */
    private static function stamp( array $context ) {
        if ( ! empty( $context['occurred_at'] ) && is_numeric( $context['occurred_at'] ) ) {
            return gmdate( 'Y-m-d H:i:s', (int) $context['occurred_at'] );
        }

        return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * @param object|int $hold
     * @return object|null
     */
    private static function resolve( $hold ) {
        if ( is_object( $hold ) ) {
            return $hold;
        }

        if ( class_exists( 'SecureHold_DB' ) ) {
            $found = SecureHold_DB::get_deposit( (int) $hold );
            return $found ?: null;
        }

        return null;
    }

    private static function log( $message, $hold, $from, $to, $source, $severity ) {
        if ( ! function_exists( 'securehold_log' ) ) {
            return;
        }

        securehold_log( $message, array(
            'order_id' => isset( $hold->order_id ) ? $hold->order_id : null,
            'hold_id'  => isset( $hold->id ) ? $hold->id : null,
            'from'     => $from,
            'to'       => $to,
            'source'   => $source,
        ), $severity );
    }

    private static function result( $applied, $from, $to, $reason ) {
        return array( 'applied' => (bool) $applied, 'from' => $from, 'to' => $to, 'reason' => $reason );
    }
}
