<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Activator {

    public static function activate() {
        self::create_tables();
        self::schedule_cron();
        self::set_defaults();
        flush_rewrite_rules();
        update_option('securehold_version', SECUREHOLD_VERSION);
    }

    private static function create_tables() {
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/database/schema.php';
        Securehold_DB_Schema::create_tables();
    }

    /**
     * Schedule auto-release cron if the feature is enabled
     */
    private static function schedule_cron() {
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/cron/class-securehold-wp-scheduler.php';
        Securehold_Scheduler::schedule_auto_release_cron();

        // Log maintenance is not tied to any feature toggle: the table grows
        // whatever the store has switched on.
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/cron/class-securehold-wp-log-retention.php';
        Securehold_Log_Retention::schedule();
    }

    private static function set_defaults() {
        $defaults = array(
            'securehold_stripe_mode' => 'test',
            'securehold_default_hold_amount' => 300,
            'securehold_hold_duration_days' => 30,
            'securehold_auto_release' => 'no',
            'securehold_require_3ds' => 'yes',
            'securehold_enable_logging' => 'no',
        );

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Written once, on the first activation this install ever runs — never
        // overwritten by a later deactivate/reactivate. This is the only
        // reliable "when did this site start using SecureHold" timestamp; a
        // site upgrading from an older version never passes through here, so
        // opt-in telemetry sends no activated_at for those rather than
        // guessing one from "now".
        if ( get_option( 'securehold_activated_at' ) === false ) {
            add_option( 'securehold_activated_at', current_time( 'mysql' ), '', false );
        }
    }
}
