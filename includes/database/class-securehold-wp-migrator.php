<?php
/**
 * Schema migrations for the SecureHold tables.
 *
 * Until 3.4.4 the plugin wrote securehold_db_version once, at activation, and
 * never read it back. Nothing ran on upgrade either: create_tables() is reached
 * only through register_activation_hook, so a site updated through WordPress
 * kept whatever schema it was installed with. This class is the missing half.
 *
 * @package SecureHold
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_DB_Migrator {

    /**
     * Schema version, tracked separately from the plugin's release number.
     *
     * The repository only ever wrote '1.1.0', so that is the floor. No earlier
     * migration is invented here to fill a history that does not exist.
     */
    const TARGET_VERSION = '1.4.0';

    const VERSION_OPTION = 'securehold_db_version';
    const FAILURE_OPTION = 'securehold_db_migration_failed';

    /** Guards against a migration re-entering itself through a hook. */
    private static $running = false;

    /**
     * Set once the logs table is known to be unusable, so a failure being
     * reported never tries to write a log row that would fail the same way.
     */
    private static $logging_unsafe = false;

    /**
     * Run any migration the site has not had yet.
     *
     * Safe to call on every admin page load: it costs one option read when the
     * schema is already current. Never called on the front end, so a shopper
     * checking out never waits on an ALTER TABLE.
     *
     * @return bool True when the schema is at the target version afterwards.
     */
    public static function maybe_migrate() {
        if ( self::$running ) {
            return false;
        }

        $installed = self::installed_version();

        if ( version_compare( $installed, self::TARGET_VERSION, '>=' ) ) {
            return true;
        }

        self::$running = true;

        try {
            $ok = self::run_migrations_from( $installed );
        } catch ( Exception $e ) {
            // A migration must never take the site down with it.
            $ok = false;
            self::report( 'Database migration threw an exception', array(
                'from'  => $installed,
                'to'    => self::TARGET_VERSION,
                'error' => $e->getMessage(),
            ) );
        }

        self::$running = false;

        if ( $ok ) {
            update_option( self::VERSION_OPTION, self::TARGET_VERSION );
            delete_option( self::FAILURE_OPTION );
            return true;
        }

        // The version stays where it was, so the next admin page load tries
        // again. Recording the attempt lets the notice explain itself.
        update_option( self::FAILURE_OPTION, self::TARGET_VERSION );

        return false;
    }

    /**
     * The schema version this site is on.
     *
     * A site with tables but no option predates the option being written, or
     * had it cleared. Treating that as the original schema is correct: every
     * migration is guarded by its own existence checks, so re-running one that
     * was already applied is a no-op rather than a duplicate.
     *
     * @return string
     */
    public static function installed_version() {
        $stored = get_option( self::VERSION_OPTION, '' );

        if ( is_string( $stored ) && $stored !== '' ) {
            return $stored;
        }

        return '1.1.0';
    }

    /**
     * @param string $from Installed version.
     * @return bool True when every pending migration succeeded.
     */
    private static function run_migrations_from( $from ) {
        if ( version_compare( $from, '1.2.0', '<' ) ) {
            if ( ! self::migrate_to_120() ) {
                return false;
            }
        }

        if ( version_compare( $from, '1.3.0', '<' ) ) {
            if ( ! self::migrate_to_130() ) {
                return false;
            }
        }

        if ( version_compare( $from, '1.4.0', '<' ) ) {
            if ( ! self::migrate_to_140() ) {
                return false;
            }
        }

        return true;
    }

    /**
     * 1.2.0 — index securehold_logs for the queries that actually run.
     *
     * The table shipped with keys on hold_id and event_type. securehold_log()
     * never writes hold_id, and every row it writes has event_type 'system', so
     * one key indexes nothing but NULLs and the other has a single value. The
     * support bundle and the admin screens meanwhile filter on order_id and
     * severity and sort by created_at, none of which were indexed.
     *
     * New keys go on before the dead ones come off, so a failure part-way
     * through leaves the table with more index coverage than it started with,
     * never less. No row is read, written or deleted here.
     *
     * @return bool
     */
    private static function migrate_to_120() {
        global $wpdb;

        $table = $wpdb->prefix . 'securehold_logs';

        if ( ! self::table_exists( $table ) ) {
            // Nothing to migrate against. This is an incomplete install, not a
            // failure to record: the activation path creates the table with the
            // current schema, and the version advances then.
            self::$logging_unsafe = true;
            self::report( 'Skipping log index migration: table absent', array( 'table' => $table ) );
            return true;
        }

        $add = array(
            'order_id_created' => '(order_id, created_at, id)',
            'severity_id'      => '(severity, id)',
            'created_at_id'    => '(created_at, id)',
        );

        foreach ( $add as $name => $columns ) {
            if ( self::index_exists( $table, $name ) ) {
                continue;
            }

            $result = $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}" );

            if ( $result === false ) {
                self::report( 'Could not add log index', array(
                    'table' => $table,
                    'index' => $name,
                    'error' => self::last_error(),
                ) );
                return false;
            }
        }

        // Only now that the useful keys are in place. Losing a dead index is
        // not worth a failure, so this half never blocks the migration.
        foreach ( array( 'hold_id', 'event_type' ) as $name ) {
            if ( ! self::index_exists( $table, $name ) ) {
                continue;
            }

            $result = $wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `{$name}`" );

            if ( $result === false ) {
                self::report( 'Could not drop unused log index; leaving it in place', array(
                    'table' => $table,
                    'index' => $name,
                    'error' => self::last_error(),
                ) );
            }
        }

        return true;
    }

    /**
     * 1.3.0 — Multi-Hold engine, Phase A: carry a Hold Group identity on
     * securehold_holds without touching a single existing row.
     *
     * Adds two nullable columns:
     *   - group_key     opaque identifier of a logical hold within an order.
     *                   NULL means "the implicit default group" — exactly what
     *                   every historical row and every Single-Hold-per-Order
     *                   commande already is, so no backfill is needed or run.
     *   - scheduled_for when a specific group is due to fire, for a scheduler
     *                   that can look up one group's event without scanning
     *                   every order (Phase D, not built here).
     *
     * Plus an (order_id, group_key) index so "all holds of this order" and
     * "the hold for this specific group" both stay index-only lookups once
     * an order can have more than one row. order_id keeps its own single-column
     * index too — nothing that filters by order_id alone regresses.
     *
     * Additive only: no row is read, written or deleted, no existing column or
     * index is touched, and a failure here downgrades log/diagnostic UIs, not
     * deposit creation, capture or release, none of which read these columns
     * yet.
     *
     * @return bool
     */
    private static function migrate_to_130() {
        global $wpdb;

        $table = $wpdb->prefix . 'securehold_holds';

        if ( ! self::table_exists( $table ) ) {
            self::report( 'Skipping hold-group migration: table absent', array( 'table' => $table ) );
            return true;
        }

        if ( ! self::column_exists( $table, 'group_key' ) ) {
            $result = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `group_key` VARCHAR(191) NULL DEFAULT NULL AFTER `order_id`" );

            if ( $result === false ) {
                self::report( 'Could not add group_key column', array(
                    'table' => $table,
                    'error' => self::last_error(),
                ) );
                return false;
            }
        }

        if ( ! self::column_exists( $table, 'scheduled_for' ) ) {
            $result = $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `scheduled_for` DATETIME NULL DEFAULT NULL AFTER `expires_at`" );

            if ( $result === false ) {
                self::report( 'Could not add scheduled_for column', array(
                    'table' => $table,
                    'error' => self::last_error(),
                ) );
                return false;
            }
        }

        if ( ! self::index_exists( $table, 'order_id_group' ) ) {
            $result = $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `order_id_group` (`order_id`, `group_key`)" );

            if ( $result === false ) {
                self::report( 'Could not add order_id_group index', array(
                    'table' => $table,
                    'error' => self::last_error(),
                ) );
                return false;
            }
        }

        return true;
    }

    /**
     * 1.4.0 — Multi-Hold engine, logs increment: re-index hold_id on
     * securehold_logs now that securehold_log() actually writes it.
     *
     * 1.2.0 dropped the hold_id index because nothing ever populated the
     * column. securehold_log() now does (helpers.php), so a row can be found
     * by the one hold it belongs to without a full-table scan — exactly what
     * Deposit Details needs to show Hold A's log lines without Hold B's.
     *
     * A row logged before this migration keeps hold_id = NULL; nothing here
     * back-fills it; it stays reachable by order_id and created_at as before.
     * Additive only: one index added, nothing read, written or deleted.
     *
     * @return bool
     */
    private static function migrate_to_140() {
        global $wpdb;

        $table = $wpdb->prefix . 'securehold_logs';

        if ( ! self::table_exists( $table ) ) {
            self::$logging_unsafe = true;
            self::report( 'Skipping log hold_id index migration: table absent', array( 'table' => $table ) );
            return true;
        }

        if ( ! self::index_exists( $table, 'hold_id_created' ) ) {
            $result = $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `hold_id_created` (`hold_id`, `created_at`, `id`)" );

            if ( $result === false ) {
                self::report( 'Could not add log hold_id index', array(
                    'table' => $table,
                    'error' => self::last_error(),
                ) );
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $table  Fully prefixed table name.
     * @param string $column Column name.
     * @return bool
     */
    public static function column_exists( $table, $column ) {
        global $wpdb;

        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            $table,
            $column
        ) );

        return (int) $found > 0;
    }

    /**
     * @param string $table Fully prefixed table name.
     * @return bool
     */
    public static function table_exists( $table ) {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        ) === $table;
    }

    /**
     * @param string $table Fully prefixed table name.
     * @param string $index Index name.
     * @return bool
     */
    public static function index_exists( $table, $index ) {
        global $wpdb;

        $rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`" );

        if ( ! is_array( $rows ) ) {
            return false;
        }

        foreach ( $rows as $row ) {
            $key = is_object( $row ) ? ( isset( $row->Key_name ) ? $row->Key_name : '' )
                                     : ( isset( $row['Key_name'] ) ? $row['Key_name'] : '' );
            if ( $key === $index ) {
                return true;
            }
        }

        return false;
    }

    /** @return string */
    private static function last_error() {
        global $wpdb;
        return isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
    }

    /**
     * Report a migration problem without depending on the thing being migrated.
     *
     * securehold_log() writes to securehold_logs, which is exactly the table a
     * schema migration may find missing, incomplete or unwritable. Reaching for
     * it here could fail the same way that caused the report, or loop. So the
     * log row is attempted only when the table is known to be usable, and
     * error_log() carries the message either way.
     *
     * @param string $message Human-readable summary.
     * @param array  $data    Context. Never credentials, never customer rows.
     * @return void
     */
    private static function report( $message, $data = array() ) {
        // Always reaches the server log, whatever state the database is in.
        if ( function_exists( 'error_log' ) ) {
            error_log( '[SecureHold] ' . $message . ' ' . wp_json_encode( $data ) );
        }

        if ( self::$logging_unsafe || ! function_exists( 'securehold_log' ) ) {
            return;
        }

        global $wpdb;
        if ( ! self::table_exists( $wpdb->prefix . 'securehold_logs' ) ) {
            self::$logging_unsafe = true;
            return;
        }

        securehold_log( $message, $data, 'error' );
    }

    /**
     * Tell the administrator, once, that the schema is behind. Deliberately a
     * notice and nothing more: an index that failed to build slows queries, it
     * does not make deposits wrong, and it must never stop a checkout.
     *
     * @return void
     */
    public static function maybe_show_failure_notice() {
        if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        if ( get_option( self::FAILURE_OPTION, '' ) === '' ) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__(
            'SecureHold could not finish a database update. Deposits keep working; the admin log screens may just be slower. The update is retried automatically on each admin page load.',
            'securehold-security-deposit-holds'
        );
        echo '</p></div>';
    }
}
