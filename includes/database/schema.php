<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_DB_Schema {
    
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $table_holds = $wpdb->prefix . 'securehold_holds';
        
        $sql_holds = "CREATE TABLE $table_holds (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            customer_id varchar(255) NOT NULL,
            intent_id varchar(255) NOT NULL,
            payment_method_id varchar(255) NOT NULL,
            amount decimal(10,2) NOT NULL,
            captured_amount decimal(10,2) DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT 'usd',
            status varchar(50) NOT NULL DEFAULT 'pending',
            authorized_at datetime DEFAULT NULL,
            captured_at datetime DEFAULT NULL,
            released_at datetime DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY customer_id (customer_id),
            KEY intent_id (intent_id),
            KEY status (status)
        ) $charset_collate;";
        
        $table_logs = $wpdb->prefix . 'securehold_logs';
        
        $sql_logs = "CREATE TABLE $table_logs (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            hold_id bigint(20) UNSIGNED DEFAULT NULL,
            order_id bigint(20) UNSIGNED DEFAULT NULL,
            event_type varchar(100) NOT NULL,
            message text NOT NULL,
            data longtext DEFAULT NULL,
            severity varchar(20) NOT NULL DEFAULT 'info',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY hold_id (hold_id),
            KEY event_type (event_type)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_holds);
        dbDelta($sql_logs);
        
        update_option('securehold_db_version', '1.1.0');
    }
}