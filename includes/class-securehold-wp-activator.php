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
    }
}
