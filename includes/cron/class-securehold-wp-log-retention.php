<?php
/**
 * Bounded retention for the SecureHold log table.
 *
 * securehold_logs had no ceiling: every payment writes debug rows through
 * Layer 2 and Layer 3, and nothing ever removed them. This trims the table on a
 * daily cron, in small batches, so a busy store does not accumulate a table
 * nobody is watching.
 *
 * Only logs. securehold_holds is business data and is never touched here.
 *
 * @package SecureHold
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Log_Retention {

    const HOOK = 'securehold_maintenance_cron';

    /** Rows removed per DELETE. Small enough not to hold a lock worth noticing. */
    const BATCH_SIZE = 500;

    /** Batches per cron run, so one pass cannot turn into a long job. */
    const MAX_BATCHES = 5;

    /**
     * How long each severity is kept, in days.
     *
     * debug is the bulk of the table and has no value past the ticket it was
     * captured for. error is bounded rather than kept forever: a year outlives
     * any Stripe authorisation and any dispute that could rest on it, and an
     * unbounded table is a problem deferred, not avoided.
     *
     * @return array<string,int>
     */
    public static function policy() {
        $policy = array(
            'debug'   => 14,
            'info'    => 30,
            'warning' => 90,
            'error'   => 365,
        );

        /**
         * Filter the log retention window per severity, in days.
         *
         * Deliberately a filter and not a settings screen: this is a corrective
         * release, and the defaults suit every store the policy was derived
         * from. A site with a genuine reason to differ can say so in code.
         *
         * @since 3.4.4
         * @param array<string,int> $policy Severity => days retained.
         */
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'securehold_log_retention_days', $policy );
            if ( is_array( $filtered ) && ! empty( $filtered ) ) {
                $policy = $filtered;
            }
        }

        return $policy;
    }

    /**
     * Attach the cron callback. Called on every request, cheap.
     *
     * @return void
     */
    public static function register() {
        add_action( self::HOOK, array( __CLASS__, 'run' ) );
    }

    /**
     * Ensure the daily event exists, without ever creating a second one.
     *
     * Same safety-net shape as the auto-release cron: called on activation and
     * on admin page loads, so a site that lost its schedule recovers it.
     *
     * @return void
     */
    public static function schedule() {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK );
        }
    }

    /**
     * @return void
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    /**
     * Cron callback: remove expired log rows, a few batches at a time.
     *
     * Whatever is left over waits for tomorrow. That is the point: the table
     * shrinks steadily instead of one run trying to delete a year of backlog
     * and timing out or locking the table while a shopper is checking out.
     *
     * Works whether or not the 1.2.0 indexes are in place — it only needs the
     * severity and created_at columns, which the table has always had.
     *
     * @return void
     */
    public static function run() {
        global $wpdb;

        $table = $wpdb->prefix . 'securehold_logs';

        if ( ! class_exists( 'Securehold_DB_Migrator' ) ) {
            $migrator = dirname( __DIR__ ) . '/database/class-securehold-wp-migrator.php';
            if ( ! file_exists( $migrator ) ) {
                return;
            }
            require_once $migrator;
        }

        if ( ! Securehold_DB_Migrator::table_exists( $table ) ) {
            // Nothing to trim, and nowhere to log the fact either.
            return;
        }

        $budget  = self::MAX_BATCHES;
        $deleted = 0;

        foreach ( self::policy() as $severity => $days ) {
            if ( $budget <= 0 ) {
                break;
            }

            $days = (int) $days;
            if ( $days <= 0 ) {
                // A zero or negative window would mean "delete everything of
                // this severity". Refuse rather than act on a bad filter value.
                continue;
            }

            $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

            while ( $budget > 0 ) {
                $removed = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM `{$table}` WHERE severity = %s AND created_at < %s LIMIT %d",
                        $severity,
                        $cutoff,
                        self::BATCH_SIZE
                    )
                );

                $budget--;

                if ( $removed === false ) {
                    if ( function_exists( 'securehold_log' ) ) {
                        securehold_log( 'Log retention: delete failed', array(
                            'severity' => $severity,
                            'error'    => isset( $wpdb->last_error ) ? $wpdb->last_error : '',
                        ), 'error' );
                    }
                    return;
                }

                $deleted += (int) $removed;

                // A short batch means this severity is caught up.
                if ( (int) $removed < self::BATCH_SIZE ) {
                    break;
                }
            }
        }

        // A quiet run is the normal case and says nothing. Only an actual purge
        // is worth a line, and only at debug.
        if ( $deleted > 0 && function_exists( 'securehold_log' ) ) {
            securehold_log( 'Log retention: removed expired rows', array(
                'rows'      => $deleted,
                'exhausted' => $budget <= 0,
            ), 'debug' );
        }
    }
}
