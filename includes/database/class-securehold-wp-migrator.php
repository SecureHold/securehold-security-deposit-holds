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
    const TARGET_VERSION = '1.2.0';

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
