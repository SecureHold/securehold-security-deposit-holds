<?php
/**
 * Deposit Details Page
 * Comprehensive deposit/order view inspired by Stripe Dashboard
 */
if (!defined('ABSPATH')) exit;

// Security check
if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
    wp_die( esc_html__( 'You do not have permission to access this page.', 'securehold-security-deposit-holds' ) );
}

// Get deposit ID from URL
$deposit_id = isset($_GET['deposit_id']) ? absint($_GET['deposit_id']) : 0;
if (!$deposit_id) {
    echo '<div class="notice notice-error"><p>' . esc_html__('No deposit ID specified.', 'securehold-security-deposit-holds') . '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=securehold-deposits')) . '" class="sh-btn sh-btn-primary"><span class="dashicons dashicons-arrow-left-alt" style="font-size:16px;width:16px;height:16px;"></span> ' . esc_html__('Back to Deposits', 'securehold-security-deposit-holds') . '</a></p>';
    return;
}

global $wpdb;
$table_holds = $wpdb->prefix . 'securehold_holds';
$deposit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_holds WHERE id = %d", $deposit_id));

if (!$deposit) {
    echo '<div class="notice notice-error"><p>' . esc_html__('Deposit not found.', 'securehold-security-deposit-holds') . '</p></div>';
    echo '<p><a href="' . esc_url(admin_url('admin.php?page=securehold-deposits')) . '" class="sh-btn sh-btn-primary"><span class="dashicons dashicons-arrow-left-alt" style="font-size:16px;width:16px;height:16px;"></span> ' . esc_html__('Back to Deposits', 'securehold-security-deposit-holds') . '</a></p>';
    return;
}

// Load order
$order = wc_get_order($deposit->order_id);
$order_exists = !empty($order);

// Amounts
$total = floatval($deposit->amount);
$captured = isset($deposit->captured_amount) ? floatval($deposit->captured_amount) : 0;
$remaining = $total - $captured;
$currency = !empty($deposit->currency) ? strtoupper($deposit->currency) : 'USD';
$currency_symbol = get_woocommerce_currency_symbol( $currency );

// Stripe config (no secrets)
$stripe_mode = get_option('securehold_stripe_mode', 'test');

// ── Resolve configuration via centralized Config Resolver ──
// Priority: Product Rule > Category Rule > Global Settings
if ( ! class_exists( 'Securehold_Config_Resolver' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
    require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-config-resolver.php';
}

$auto_release_days = get_option( 'securehold_auto_release_days', '7' );

$applied_config = null;
if ( $order_exists ) {
    $applied_config = Securehold_Config_Resolver::resolve_final_deposit_configuration( $order );
} else {
    // No order — fall back to global
    $applied_config = array(
        'source'                  => 'global',
        'source_id'               => 0,
        'source_label'            => 'Global',
        'timing'                  => get_option( 'securehold_capture_timing', 'immediate' ),
        'deposit_amount'          => get_option( 'securehold_default_hold_amount', '300' ),
        'deposit_amount_resolved' => (float) get_option( 'securehold_default_hold_amount', '300' ),
        'delay_days'              => get_option( 'securehold_delay_days', '3' ),
        'date_field_key'          => get_option( 'securehold_date_field_key', '_checkout_date' ),
        'scheduled_days'          => get_option( 'securehold_scheduled_days_number', '' ),
        'scheduled_direction'     => get_option( 'securehold_scheduled_direction', '' ),
        'trigger_status'          => get_option( 'securehold_trigger_status', 'processing' ),
        'conflict_info'           => null,
        'fallbacks'               => array(),
        'policy'                  => get_option( 'securehold_resolution_policy', 'priority_chain' ),
        'explain'                 => null,
    );
}

$applied_timing          = $applied_config['timing'];
$applied_source          = $applied_config['source'];
$applied_deposit_amount  = $applied_config['deposit_amount'];
$applied_date_field_key  = $applied_config['date_field_key'];
$applied_sched_days      = $applied_config['scheduled_days'];
$applied_sched_dir       = $applied_config['scheduled_direction'];
$applied_delay_days      = $applied_config['delay_days'];
$applied_trigger_status  = $applied_config['trigger_status'];
$applied_conflict_info   = isset( $applied_config['conflict_info'] ) ? $applied_config['conflict_info'] : null;
$applied_policy          = isset( $applied_config['policy'] ) ? $applied_config['policy'] : 'priority_chain';
$applied_explain         = isset( $applied_config['explain'] ) ? $applied_config['explain'] : null;

// Keep legacy variable for any other code that references it
$capture_timing = $applied_timing;

// ── Read FULL frozen snapshot from order metas ──
// Every field displayed in "Applied Configuration" is loaded from frozen metas
// persisted at hold creation time. ZERO live get_option() calls for this block.
// For orders created before snapshot persistence, fallback to securehold_holds
// table values or show "—" when data is genuinely unavailable.
if ( $order_exists ) {
    $dd_aggregation_mode    = $order->get_meta( '_securehold_aggregation_mode', true );
    $dd_rule_policy         = $order->get_meta( '_securehold_rule_policy', true );
    $dd_timing_strategy     = $order->get_meta( '_securehold_timing_strategy', true );
    $dd_source              = $order->get_meta( '_securehold_source', true );
    $dd_source_id           = $order->get_meta( '_securehold_source_id', true );
    $dd_source_label        = $order->get_meta( '_securehold_source_label', true );
    $dd_deposit_amount      = $order->get_meta( '_securehold_deposit_amount', true );
    $dd_delay_days          = $order->get_meta( '_securehold_delay_days', true );
    $dd_date_field_key      = $order->get_meta( '_securehold_date_field_key', true );
    $dd_scheduled_days      = $order->get_meta( '_securehold_scheduled_days', true );
    $dd_scheduled_direction = $order->get_meta( '_securehold_scheduled_direction', true );
    $dd_trigger_status      = $order->get_meta( '_securehold_trigger_status', true );
    $dd_auto_release_days   = $order->get_meta( '_securehold_auto_release_days', true );
    $dd_stripe_mode         = $order->get_meta( '_securehold_stripe_mode', true );
    $dd_item_breakdown      = $order->get_meta( '_securehold_item_breakdown', true );
    $dd_winner_item         = $order->get_meta( '_securehold_winner_item', true );
} else {
    $dd_aggregation_mode    = '';
    $dd_rule_policy         = '';
    $dd_timing_strategy     = '';
    $dd_source              = '';
    $dd_source_id           = '';
    $dd_source_label        = '';
    $dd_deposit_amount      = '';
    $dd_delay_days          = '';
    $dd_date_field_key      = '';
    $dd_scheduled_days      = '';
    $dd_scheduled_direction = '';
    $dd_trigger_status      = '';
    $dd_auto_release_days   = '';
    $dd_stripe_mode         = '';
    $dd_item_breakdown      = '';
    $dd_winner_item         = '';
}

// ── Backward-compat fallbacks (securehold_holds table, then safe defaults) ──
// NEVER fall back to live get_option() — only use the holds table or constants.
if ( empty( $dd_aggregation_mode ) ) {
    $dd_aggregation_mode = 'per_order';
}
if ( empty( $dd_rule_policy ) ) {
    $dd_rule_policy = 'priority_chain';
}
if ( empty( $dd_timing_strategy ) ) {
    $dd_timing_strategy = 'immediate';
}
if ( empty( $dd_source ) ) {
    $dd_source = 'global';
}
if ( '' === $dd_source_label ) {
    $dd_source_label = '';
}
// Deposit amount: prefer frozen meta, fall back to holds table amount
if ( '' === $dd_deposit_amount || false === $dd_deposit_amount ) {
    $dd_deposit_amount = ( $total > 0 ) ? (string) $total : '';
}
// Auto-release: prefer frozen meta, fall back to holds table expires_at derivation
if ( '' === $dd_auto_release_days || false === $dd_auto_release_days ) {
    if ( ! empty( $deposit->expires_at ) && ! empty( $deposit->created_at ) ) {
        $created_ts = strtotime( $deposit->created_at );
        $expires_ts = strtotime( $deposit->expires_at );
        if ( $created_ts && $expires_ts && $expires_ts > $created_ts ) {
            $dd_auto_release_days = (string) round( ( $expires_ts - $created_ts ) / DAY_IN_SECONDS );
        }
    }
    if ( '' === $dd_auto_release_days || false === $dd_auto_release_days ) {
        $dd_auto_release_days = '—';
    }
}
// Stripe mode: if not stored, derive from intent_id prefix when possible
if ( '' === $dd_stripe_mode || false === $dd_stripe_mode ) {
    if ( ! empty( $deposit->intent_id ) && strpos( $deposit->intent_id, 'pi_' ) === 0 ) {
        // Real Stripe intent — check test mode via dashboard URL convention
        // Cannot determine with certainty; mark as not recorded
        $dd_stripe_mode = '—';
    } else {
        $dd_stripe_mode = '—';
    }
}

// ── Debug log: snapshot-only loading ──
if ( function_exists( 'securehold_log' ) ) {
    securehold_log( 'Applied Configuration loaded from snapshot only', array(
        'order_id'         => $deposit->order_id,
        'aggregation_mode' => $dd_aggregation_mode,
        'policy'           => $dd_rule_policy,
        'strategy'         => $dd_timing_strategy,
    ), 'debug' );
}

// ── Build unified Activity Timeline ──────────────────────────────
// Collects events from: deposit record, plugin logs, WooCommerce order
// notes & order status changes. De-duplicates then sorts chronologically.

$timeline_events   = array();
$seen_fingerprints = array(); // prevent duplicates

/**
 * Helper — push an event and de-duplicate by fingerprint.
 */
$_sh_add_event = function ( $ts, $type, $text, $detail = '', $amount = '', $meta = array(), $category = 'hold' ) use ( &$timeline_events, &$seen_fingerprints ) {
    $fp = md5( $ts . '|' . $text );
    if ( isset( $seen_fingerprints[ $fp ] ) ) {
        return;
    }
    $seen_fingerprints[ $fp ] = true;
    $timeline_events[] = array(
        'timestamp' => $ts,                       // Y-m-d H:i:s
        'type'      => $type,                     // success | info | warning | danger | scheduled
        'text'      => $text,                     // main label
        'detail'    => $detail,                   // secondary line (gray)
        'amount'    => $amount,                   // e.g. "$50.00"
        'meta'      => $meta,                     // optional keyed data
        'category'  => $category,                 // 'hold' = core deposit event | 'wc' = WooCommerce event
    );
};

// ── 1. Structural events from deposit record ─────────────────────
if ( ! empty( $deposit->created_at ) ) {
    $_sh_add_event(
        $deposit->created_at,
        'info',
        __( 'Deposit record created', 'securehold-security-deposit-holds' ),
        /* translators: %d is the order ID number */
        sprintf( __( 'Order #%d', 'securehold-security-deposit-holds' ), $deposit->order_id )
    );
}
if ( ! empty( $deposit->authorized_at ) ) {
    $_sh_add_event(
        $deposit->authorized_at,
        'success',
        __( 'Security deposit authorized', 'securehold-security-deposit-holds' ),
        ! empty( $deposit->intent_id ) ? 'PaymentIntent: ' . $deposit->intent_id : '',
        wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) )
    );
}
if ( $captured > 0 && ! empty( $deposit->captured_at ) ) {
    $capture_label = ( $captured < $total )
        ? __( 'Partial capture executed', 'securehold-security-deposit-holds' )
        : __( 'Full capture executed', 'securehold-security-deposit-holds' );
    $_sh_add_event(
        $deposit->captured_at,
        'success',
        $capture_label,
        '',
        wp_kses_post( wc_price( $captured, array( 'currency' => $currency ) ) )
    );
    if ( $captured < $total ) {
        $release_time = ! empty( $deposit->released_at ) ? $deposit->released_at : $deposit->captured_at;
        $_sh_add_event(
            $release_time,
            'warning',
            __( 'Remaining amount released', 'securehold-security-deposit-holds' ),
            '',
            wp_kses_post( wc_price( $remaining, array( 'currency' => $currency ) ) )
        );
    }
}
if ( ! empty( $deposit->released_at ) && $deposit->status === 'released' ) {
    $_sh_add_event(
        $deposit->released_at,
        'danger',
        __( 'Hold released', 'securehold-security-deposit-holds' ),
        '',
        wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) )
    );
}
if ( $deposit->status === 'failed' ) {
    $fail_time = ! empty( $deposit->updated_at ) ? $deposit->updated_at : $deposit->created_at;
    $_sh_add_event(
        $fail_time,
        'danger',
        __( 'Hold creation failed', 'securehold-security-deposit-holds' ),
        ! empty( $deposit->notes ) ? $deposit->notes : ''
    );
}
if ( $deposit->status === 'pending_manual' ) {
    $_sh_add_event(
        $deposit->created_at,
        'scheduled',
        __( 'Awaiting manual hold creation', 'securehold-security-deposit-holds' ),
        __( 'Manual strategy — admin action required', 'securehold-security-deposit-holds' )
    );
}
if ( $deposit->status === 'scheduled' ) {
    $sched_order_obj = wc_get_order( $deposit->order_id );
    $next_run = $sched_order_obj ? $sched_order_obj->get_meta( '_securehold_deposit_next_run', true ) : '';
    $next_run_label = ! empty( $next_run ) ? date_i18n( 'Y-m-d H:i', intval( $next_run ) ) : __( 'queued', 'securehold-security-deposit-holds' );
    // Determine the real timing strategy to use the correct timeline label.
    $timeline_timing_strategy = $sched_order_obj ? $sched_order_obj->get_meta( '_securehold_timing_strategy', true ) : '';
    if ( empty( $timeline_timing_strategy ) && ! empty( $deposit->notes ) && stripos( $deposit->notes, 'Delayed strategy' ) !== false ) {
        $timeline_timing_strategy = 'delayed';
    }
    $timeline_event_label = ( $timeline_timing_strategy === 'delayed' )
        ? __( 'Deposit delayed — awaiting cron execution', 'securehold-security-deposit-holds' )
        : __( 'Deposit scheduled — awaiting cron execution', 'securehold-security-deposit-holds' );
    $_sh_add_event(
        $deposit->created_at,
        'scheduled',
        $timeline_event_label,
        sprintf(
            /* translators: %s = next execution date/time */
            __( 'The hold will be created on %s.', 'securehold-security-deposit-holds' ),
            $next_run_label
        )
    );
}

// ── 2. Plugin logs — fetched once, used for Technical Logs block ──
$table_logs = $wpdb->prefix . 'securehold_logs';
$logs       = array();
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_logs ) ) ) === $table_logs ) {
    $logs = $wpdb->get_results( $wpdb->prepare(
        "SELECT message, severity, created_at, data FROM $table_logs WHERE order_id = %d ORDER BY created_at ASC LIMIT 50",
        $deposit->order_id
    ) );
    if ( empty( $logs ) ) {
        $search_id = $wpdb->esc_like( strval( $deposit->order_id ) );
        $logs = $wpdb->get_results( $wpdb->prepare(
            "SELECT message, severity, created_at, data FROM $table_logs WHERE message LIKE %s OR data LIKE %s ORDER BY created_at ASC LIMIT 50",
            '%' . $search_id . '%',
            '%' . $search_id . '%'
        ) );
    }
}

// ── Build technical logs array (separate from timeline) ──────────
// Keys that must never be displayed in technical logs.
$_sh_sensitive_keys = array( 'secret', 'secret_key', 'sk_live', 'sk_test', 'whsec_', 'password', 'token' );

$technical_logs = array();
foreach ( $logs as $log ) {
    // Classify severity → display type
    $log_severity = $log->severity;
    $msg_lower    = strtolower( $log->message );

    // Determine display type for the badge
    $display_type = 'info';
    if ( $log_severity === 'error' ) {
        $display_type = 'error';
    } elseif ( $log_severity === 'warning' ) {
        $display_type = 'warning';
    }
    // Refine by message content
    if ( strpos( $msg_lower, 'webhook' ) !== false ) {
        $display_type = 'webhook';
    } elseif ( strpos( $msg_lower, 'stripe' ) !== false && $display_type === 'info' ) {
        $display_type = 'stripe';
    }

    // Clean message
    $clean_msg = preg_replace( '/^[\x{2705}\x{274C}\x{26A0}\x{2139}]\s*/u', '', $log->message );

    // Extract safe metadata from data JSON
    $stripe_id  = '';
    $extra_info = '';
    if ( ! empty( $log->data ) ) {
        $ld = json_decode( $log->data, true );
        if ( is_array( $ld ) ) {
            // Filter out sensitive keys
            foreach ( $_sh_sensitive_keys as $sk ) {
                foreach ( array_keys( $ld ) as $k ) {
                    if ( stripos( $k, $sk ) !== false ) {
                        unset( $ld[ $k ] );
                    }
                }
            }
            // Filter out sensitive values (keys starting with sk_, rk_, whsec_)
            foreach ( $ld as $k => $v ) {
                if ( is_string( $v ) && preg_match( '/^(sk_|rk_|whsec_)/i', $v ) ) {
                    unset( $ld[ $k ] );
                }
            }
            if ( ! empty( $ld['intent_id'] ) ) {
                $stripe_id = $ld['intent_id'];
            }
            if ( ! empty( $ld['error'] ) ) {
                $extra_info = $ld['error'];
            }
        }
    }

    $technical_logs[] = array(
        'timestamp'    => $log->created_at,
        'display_type' => $display_type,
        'message'      => $clean_msg,
        'stripe_id'    => $stripe_id,
        'extra'        => $extra_info,
    );
}

// ── 3. WooCommerce order events ──────────────────────────────────
$order_notes = array();
if ( $order_exists ) {
    // Order creation
    $order_date = $order->get_date_created();
    if ( $order_date ) {
        $_sh_add_event(
            $order_date->date( 'Y-m-d H:i:s' ),
            'info',
            __( 'Order created', 'securehold-security-deposit-holds' ),
            /* translators: %s is the order status label */
            sprintf( __( 'Status: %s', 'securehold-security-deposit-holds' ), wc_get_order_status_name( $order->get_status() ) ),
            '', array(), 'wc'
        );
    }

    // Order paid
    $paid_date = $order->get_date_paid();
    if ( $paid_date ) {
        $_sh_add_event(
            $paid_date->date( 'Y-m-d H:i:s' ),
            'success',
            __( 'Order payment received', 'securehold-security-deposit-holds' ),
            /* translators: %s is the formatted order total */
            sprintf( __( 'Total: %s', 'securehold-security-deposit-holds' ), wp_strip_all_tags( $order->get_formatted_order_total() ) ),
            '', array(), 'wc'
        );
    }

    // Order completed
    $completed_date = $order->get_date_completed();
    if ( $completed_date ) {
        $_sh_add_event(
            $completed_date->date( 'Y-m-d H:i:s' ),
            'success',
            __( 'Order completed', 'securehold-security-deposit-holds' ),
            '', '', array(), 'wc'
        );
    }

    // All order notes — extract status changes and deposit-related notes
    $all_notes = wc_get_order_notes( array( 'order_id' => $deposit->order_id, 'limit' => 50 ) );
    foreach ( $all_notes as $note ) {
        $content = $note->content;
        $note_ts = $note->date_created->date( 'Y-m-d H:i:s' );

        // WooCommerce status change notes (e.g. "Order status changed from X to Y.")
        if ( preg_match( '/status changed from (\w[\w\s-]*) to (\w[\w\s-]*)/i', $content, $m ) ) {
            $from_status = trim( $m[1] );
            $to_status   = trim( $m[2] );
            $type = 'info';
            if ( in_array( strtolower( $to_status ), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
                $type = 'danger';
            } elseif ( in_array( strtolower( $to_status ), array( 'completed', 'processing' ), true ) ) {
                $type = 'success';
            }
            $_sh_add_event(
                $note_ts,
                $type,
                /* translators: %s is the new order status */
                sprintf( __( 'Order status changed to %s', 'securehold-security-deposit-holds' ), ucfirst( $to_status ) ),
                /* translators: %s is the previous order status */
                sprintf( __( 'Previous: %s', 'securehold-security-deposit-holds' ), ucfirst( $from_status ) ),
                '', array(), 'wc'
            );
            continue;
        }

        // SecureHold-related notes (not already covered by logs/deposit record)
        if ( stripos( $content, 'SecureHold' ) !== false || stripos( $content, 'securehold' ) !== false
            || stripos( $content, 'deposit' ) !== false || stripos( $content, 'hold' ) !== false ) {

            $n_type = 'info';
            if ( stripos( $content, 'error' ) !== false || stripos( $content, 'failed' ) !== false ) {
                $n_type = 'danger';
            } elseif ( stripos( $content, 'authorized' ) !== false || stripos( $content, 'captured' ) !== false ) {
                $n_type = 'success';
            } elseif ( stripos( $content, 'scheduled' ) !== false ) {
                $n_type = 'scheduled';
            } elseif ( stripos( $content, 'released' ) !== false ) {
                $n_type = 'warning';
            } elseif ( stripos( $content, 'manual' ) !== false || stripos( $content, 'awaiting' ) !== false ) {
                $n_type = 'scheduled';
            }

            $_sh_add_event( $note_ts, $n_type, wp_strip_all_tags( $content ), '', '', array(), 'wc' );
            continue;
        }

        // Refund notes
        if ( stripos( $content, 'refund' ) !== false ) {
            $_sh_add_event( $note_ts, 'danger', wp_strip_all_tags( $content ), '', '', array(), 'wc' );
        }
    }
}

// ── Sort chronologically (oldest first) ──────────────────────────
usort( $timeline_events, function ( $a, $b ) {
    return strcmp( $a['timestamp'], $b['timestamp'] );
} );

// Order meta keys relevant to deposits
$relevant_meta = array();
if ($order_exists) {
    $meta_keys_to_show = array(
        '_stripe_customer_id', '_stripe_payment_method', '_stripe_source_id',
        'stripe_caution_intent_id', 'empreinte_effectuee',
        '_securehold_attempt_made',
    );
    // Also try to find the scheduled date meta
    $date_field_key = get_option('securehold_date_field_key', '_checkout_date');
    if (!in_array($date_field_key, $meta_keys_to_show)) {
        $meta_keys_to_show[] = $date_field_key;
    }
    if (!empty($applied_date_field_key) && !in_array($applied_date_field_key, $meta_keys_to_show)) {
        $meta_keys_to_show[] = $applied_date_field_key;
    }

    foreach ($meta_keys_to_show as $mk) {
        $val = $order->get_meta($mk, true);
        if (!empty($val)) {
            $relevant_meta[$mk] = $val;
        }
    }
}

// Intent ID display (mask if live)
$intent_display = esc_html($deposit->intent_id);
$is_real_intent = (strpos($deposit->intent_id, 'pi_') === 0);

// ── FREE vs PRO feature gates for this page ──────────────────────
// Activity Timeline and Order Metadata are produced locally and shown in full
// in FREE (no filtering or simplified subset).
// $_sh_logs_pro        selects PRO's enriched Technical Logs dataset when available.
// $_sh_rule_engine_pro gates: Applied Configuration, Item Breakdown, Explain Rule modal
//                      (product/category rule engine — provided by the separate PRO plugin).
$_sh_logs_pro        = securehold_feature_enabled( 'logs' );
$_sh_rule_engine_pro = securehold_feature_enabled( 'rule_engine' );

?>
<div class="wrap securehold-wrapper">

    <?php
    $back_link = '<a href="' . esc_url(admin_url('admin.php?page=securehold-deposits')) . '" class="sh-btn sh-btn-ghost" style="background:rgba(255,255,255,0.15); color:white; border:1px solid rgba(255,255,255,0.3); font-size:0.8rem; padding:0.4rem 0.8rem;"><span class="dashicons dashicons-arrow-left-alt" style="font-size:16px; width:16px; height:16px; margin-right:4px; vertical-align:middle;"></span>' . esc_html__('All Deposits', 'securehold-security-deposit-holds') . '</a>';
    Securehold_Admin::render_page_header(
        /* translators: %d is the deposit ID number */
        sprintf(__('Deposit #%d', 'securehold-security-deposit-holds'), $deposit_id),
        /* translators: %d is the order ID number */
        sprintf(__('Order #%d — Detailed deposit information', 'securehold-security-deposit-holds'), $deposit->order_id),
        'dashicons-shield',
        $back_link
    );
    ?>

    <!-- Top Summary Bar -->
    <div class="sh-card" style="padding:0; overflow:hidden; margin-bottom:1.5rem;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); border-bottom:1px solid var(--sh-gray-200, #e5e7eb);">
            <!-- Status -->
            <div style="padding:1.25rem 1.5rem; border-right:1px solid var(--sh-gray-200, #e5e7eb);">
                <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--sh-gray-500, #6b7280); margin-bottom:0.5rem;"><?php esc_html_e('Status', 'securehold-security-deposit-holds'); ?></div>
                <?php Securehold_Admin::render_status_badge($deposit->status, true, $deposit->order_id, isset($deposit->notes) ? $deposit->notes : ''); ?>
            </div>
            <!-- Amount -->
            <div style="padding:1.25rem 1.5rem; border-right:1px solid var(--sh-gray-200, #e5e7eb);">
                <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--sh-gray-500, #6b7280); margin-bottom:0.5rem;"><?php esc_html_e('Amount', 'securehold-security-deposit-holds'); ?></div>
                <div style="font-size:1.25rem; font-weight:700; color:var(--sh-gray-900, #111);">
                    <?php if ($deposit->status === 'scheduled' && $total == 0): ?>
                        <span style="color:#7C3AED; font-size:0.9rem;"><?php esc_html_e('Pending', 'securehold-security-deposit-holds'); ?></span>
                    <?php else: ?>
                        <?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($captured > 0): ?>
            <!-- Captured -->
            <div style="padding:1.25rem 1.5rem; border-right:1px solid var(--sh-gray-200, #e5e7eb);">
                <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--sh-gray-500, #6b7280); margin-bottom:0.5rem;"><?php esc_html_e('Captured', 'securehold-security-deposit-holds'); ?></div>
                <div style="font-size:1.25rem; font-weight:700; color:#d97706;"><?php echo wp_kses_post( wc_price( $captured, array( 'currency' => $currency ) ) ); ?></div>
            </div>
            <?php endif; ?>
            <!-- Created -->
            <div style="padding:1.25rem 1.5rem; border-right:1px solid var(--sh-gray-200, #e5e7eb);">
                <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--sh-gray-500, #6b7280); margin-bottom:0.5rem;"><?php esc_html_e('Created', 'securehold-security-deposit-holds'); ?></div>
                <div style="font-size:0.9rem; font-weight:500; color:var(--sh-gray-800, #1f2937);"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($deposit->created_at))); ?></div>
            </div>
            <!-- Expires -->
            <div style="padding:1.25rem 1.5rem;">
                <div style="font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; color:var(--sh-gray-500, #6b7280); margin-bottom:0.5rem;"><?php esc_html_e('Expires', 'securehold-security-deposit-holds'); ?></div>
                <div style="font-size:0.9rem; font-weight:500; color:<?php echo (!empty($deposit->expires_at) && strtotime($deposit->expires_at) < time() && $deposit->status === 'authorized') ? '#dc2626' : 'var(--sh-gray-800, #1f2937)'; ?>;">
                    <?php
                    if ($deposit->status === 'scheduled') {
                        $detail_next_run = $order ? $order->get_meta('_securehold_deposit_next_run', true) : '';
                        if (!empty($detail_next_run)) {
                            echo '<span style="color:#7C3AED;">';
                            echo '<span class="dashicons dashicons-calendar-alt" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-right:3px;"></span>';
                            echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), intval($detail_next_run)));
                            echo '</span>';
                        } else {
                            echo '<span style="color:#7C3AED;">' . esc_html__('Queued', 'securehold-security-deposit-holds') . '</span>';
                        }
                    } elseif (!empty($deposit->expires_at)) {
                        echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($deposit->expires_at)));
                    } else {
                        echo '—';
                    }
                    ?>
                </div>
            </div>
        </div>

        <?php
        // Build actions for this deposit using centralized helper
        $detail_actions = Securehold_Admin::get_deposit_actions( $deposit, $total, $captured, $currency, $currency_symbol );

        // Filter out view_details (we're already on it) and view_order / view_stripe (shown as secondary links)
        $primary_actions   = array();
        $secondary_actions = array();
        foreach ( $detail_actions as $act ) {
            if ( $act['key'] === 'view_details' ) continue; // skip: already on this page
            if ( $act['key'] === 'diagnose' )    continue; // diagnostic info is shown inline on this page
            if ( in_array( $act['role'], array( 'primary', 'danger' ), true ) ) {
                $primary_actions[] = $act;
            } else {
                $secondary_actions[] = $act;
            }
        }

        if ( ! empty( $primary_actions ) || ! empty( $secondary_actions ) ) : ?>
        <!-- Action row (pill buttons) -->
        <div class="sh-detail-actions-bar">
            <?php
            // ── Primary / danger buttons ──
            foreach ( $primary_actions as $act ) {
                $data_attrs = '';
                foreach ( $act['data'] as $dk => $dv ) {
                    $data_attrs .= ' data-' . esc_attr( $dk ) . '="' . esc_attr( $dv ) . '"';
                }
                $btn_variant = ( $act['role'] === 'danger' ) ? 'sh-btn-danger' : 'sh-btn-primary';
            ?>
                <button type="button" class="sh-btn <?php echo esc_attr( $btn_variant . ' ' . $act['classes'] ); ?>"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- string built with esc_attr() per key/value in loop above ?>>
                    <span class="dashicons dashicons-<?php echo esc_attr( $act['icon'] ); ?>"></span>
                    <?php echo esc_html( $act['label'] ); ?>
                </button>
            <?php } ?>

            <?php if ( ! empty( $primary_actions ) && ! empty( $secondary_actions ) ) : ?>
                <span class="sh-detail-actions-sep"></span>
            <?php endif; ?>

            <?php
            // ── Secondary links (View Order, View in Stripe) ──
            foreach ( $secondary_actions as $act ) {
            ?>
                <a href="<?php echo esc_url( $act['url'] ); ?>" class="sh-btn sh-btn-ghost <?php echo esc_attr( $act['classes'] ); ?>"<?php if ( ! empty( $act['target'] ) ) : ?> target="<?php echo esc_attr( $act['target'] ); ?>" rel="noopener"<?php endif; ?>>
                    <span class="dashicons dashicons-<?php echo esc_attr( $act['icon'] ); ?>"></span>
                    <?php echo esc_html( $act['label'] ); ?>
                </a>
            <?php } ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Two-column layout -->
    <div class="sh-deposit-main-grid" style="display:grid; grid-template-columns:2fr 1fr; gap:1.5rem; align-items:start;">

        <!-- LEFT COLUMN -->
        <div>
            <?php
            // ── PM Reusability warning (from checkout diagnosis) ──
            $dd_pm_reusable = $order_exists ? $order->get_meta('_securehold_pm_reusable', true) : '';
            $dd_pi_diagnosis = $order_exists ? $order->get_meta('_securehold_pi_diagnosis_result', true) : '';
            $dd_sfu = $order_exists ? $order->get_meta('_securehold_pi_setup_future_usage', true) : '';
            $dd_failure_reason = $order_exists ? $order->get_meta('_securehold_hold_failure_reason', true) : '';

            if ($dd_pm_reusable === 'no' && $dd_pi_diagnosis !== 'reusable') :
            ?>
                <div style="background:#fef3cd; border-left:4px solid #ffc107; padding:12px 16px; margin-bottom:1rem; border-radius:6px; font-size:13px; line-height:1.6;">
                    <strong style="color:#856404;">&#9888;&#65039; <?php esc_html_e('Payment Method Warning', 'securehold-security-deposit-holds'); ?></strong><br>
                    <?php esc_html_e('The checkout payment did not enable future usage for this payment method. This card may be single-use and cannot be used for an off-session security deposit.', 'securehold-security-deposit-holds'); ?>
                    <br><small style="color:#856404;">setup_future_usage: <strong><?php echo esc_html(($dd_sfu && $dd_sfu !== '(not set)') ? $dd_sfu : __('not set', 'securehold-security-deposit-holds')); ?></strong></small>
                </div>
            <?php endif; ?>

            <?php if ($dd_failure_reason === 'pm_single_use') : ?>
                <div style="background:#f8d7da; border-left:4px solid #dc3545; padding:12px 16px; margin-bottom:1rem; border-radius:6px; font-size:13px; line-height:1.6;">
                    <strong style="color:#721c24;">&#10060; <?php esc_html_e('Hold Creation Failed — Non-Reusable Payment Method', 'securehold-security-deposit-holds'); ?></strong><br>
                    <?php esc_html_e('The security deposit could not be created because the payment method cannot be reused for an off-session hold. SecureHold WP automatically configures reusability via its checkout engine, but some payment method types (wallets, bank redirects) have additional constraints.', 'securehold-security-deposit-holds'); ?>
                    <br><small style="color:#721c24;"><?php esc_html_e('Run the checkout diagnostic in Tools > Diagnostics for a layer-by-layer analysis.', 'securehold-security-deposit-holds'); ?></small>
                </div>
            <?php endif; ?>

            <?php
            // 'account_mismatch' is the pre-3.4.4 code, kept so holds that failed
            // before the upgrade still explain themselves.
            $dd_mismatch_confirmed = in_array( $dd_failure_reason, array( 'stripe_context_mismatch_confirmed', 'account_mismatch' ), true );
            $dd_object_unreachable = in_array( $dd_failure_reason, array( 'stripe_context_mismatch_suspected', 'stripe_object_not_accessible' ), true );
            $dd_legacy_reference   = in_array( $dd_failure_reason, array( 'legacy_payment_method', 'invalid_payment_reference' ), true );
            ?>

            <?php if ( $dd_mismatch_confirmed ) : ?>
                <div style="background:#f8d7da; border-left:4px solid #dc3545; padding:12px 16px; margin-bottom:1rem; border-radius:6px; font-size:13px; line-height:1.6;">
                    <strong style="color:#721c24;">&#10060; <?php esc_html_e( 'Hold Creation Failed — Incompatible Stripe Contexts', 'securehold-security-deposit-holds' ); ?></strong><br>
                    <?php esc_html_e( 'SecureHold WP and the WooCommerce Stripe Gateway are using incompatible Stripe contexts. The payment does not exist in the Stripe account or environment SecureHold WP is configured with.', 'securehold-security-deposit-holds' ); ?>
                    <br><?php esc_html_e( 'Open the Stripe environment where this payment appears, and copy its API keys into SecureHold WP.', 'securehold-security-deposit-holds' ); ?>
                    <br><small style="color:#721c24;"><?php esc_html_e( 'The raw Stripe error is available in the order notes and in the Technical Logs below.', 'securehold-security-deposit-holds' ); ?></small>
                </div>
            <?php endif; ?>

            <?php if ( $dd_object_unreachable ) : ?>
                <div style="background:#fff4e5; border-left:4px solid #f59e0b; padding:12px 16px; margin-bottom:1rem; border-radius:6px; font-size:13px; line-height:1.6;">
                    <strong style="color:#92400e;">&#9888; <?php esc_html_e( 'Hold Creation Failed — Stripe Object Not Accessible', 'securehold-security-deposit-holds' ); ?></strong><br>
                    <?php esc_html_e( 'This Stripe object could not be accessed with the current SecureHold WP credentials. The object may belong to another Stripe environment, or the stored payment reference may no longer be valid.', 'securehold-security-deposit-holds' ); ?>
                    <br><?php esc_html_e( 'This has not been confirmed as an account mismatch. Run the Stripe context check in SecureHold WP > Health Check for a verdict.', 'securehold-security-deposit-holds' ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $dd_legacy_reference ) : ?>
                <div style="background:#fff4e5; border-left:4px solid #f59e0b; padding:12px 16px; margin-bottom:1rem; border-radius:6px; font-size:13px; line-height:1.6;">
                    <strong style="color:#92400e;">&#9888; <?php esc_html_e( 'Hold Creation Failed — Legacy Payment Reference', 'securehold-security-deposit-holds' ); ?></strong><br>
                    <?php esc_html_e( 'The payment reference stored on this order is not a reusable Stripe payment method. Orders taken through older versions of the WooCommerce Stripe Gateway can carry a legacy source or card reference, which cannot be used for an off-session security deposit.', 'securehold-security-deposit-holds' ); ?>
                    <br><?php esc_html_e( 'This does not indicate a problem with your Stripe configuration.', 'securehold-security-deposit-holds' ); ?>
                </div>
            <?php endif; ?>

            <!-- Payment Details -->
            <div class="sh-card" style="margin-bottom:1.5rem;">
                <div class="sh-card-header">
                    <h3 class="sh-card-title"><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e('Payment Details', 'securehold-security-deposit-holds'); ?></h3>
                    <?php if ($stripe_mode === 'test'): ?>
                        <span style="background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; padding:2px 10px; border-radius:4px; font-size:12px; font-weight:600;"><?php esc_html_e('TEST MODE', 'securehold-security-deposit-holds'); ?></span>
                    <?php else: ?>
                        <span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:2px 10px; border-radius:4px; font-size:12px; font-weight:600;"><?php esc_html_e('LIVE', 'securehold-security-deposit-holds'); ?></span>
                    <?php endif; ?>
                </div>
                <table class="sh-detail-table">
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('PaymentIntent ID', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><code><?php echo esc_html($deposit->intent_id); ?></code>
                            <?php if ($is_real_intent): ?>
                            <a href="https://dashboard.stripe.com/<?php echo $stripe_mode === 'test' ? 'test/' : ''; ?>payments/<?php echo esc_attr($deposit->intent_id); ?>" target="_blank" rel="noopener" style="margin-left:8px; font-size:12px; color:var(--sh-primary, #2563eb);">
                                <span class="dashicons dashicons-external" style="font-size:14px; width:14px; height:14px; vertical-align:middle;"></span> <?php esc_html_e('View in Stripe', 'securehold-security-deposit-holds'); ?>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Customer ID', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><code><?php echo esc_html(!empty($deposit->customer_id) ? $deposit->customer_id : '—'); ?></code></td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Payment Method', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><code><?php echo esc_html(!empty($deposit->payment_method_id) ? $deposit->payment_method_id : '—'); ?></code></td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Currency', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><?php echo esc_html($currency); ?></td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Authorized Amount', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value" style="font-weight:600;"><?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?></td>
                    </tr>
                    <?php if ($captured > 0): ?>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Captured Amount', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value" style="font-weight:600; color:#d97706;"><?php echo wp_kses_post( wc_price( $captured, array( 'currency' => $currency ) ) ); ?></td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Remaining', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><?php echo wp_kses_post( wc_price( $remaining, array( 'currency' => $currency ) ) ); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($deposit->released_at)): ?>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Released At', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($deposit->released_at))); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($deposit->captured_at)): ?>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Captured At', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($deposit->captured_at))); ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($deposit->notes)): ?>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Notes', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><em style="color:var(--sh-gray-500, #6b7280);"><?php echo esc_html($deposit->notes); ?></em></td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>

            <!-- Activity Timeline (unified) -->
            <div class="sh-card" style="margin-bottom:1.5rem;">
                <div class="sh-card-header">
                    <h3 class="sh-card-title">
                        <span class="dashicons dashicons-backup"></span>
                        <?php esc_html_e( 'Activity Timeline', 'securehold-security-deposit-holds' ); ?>
                    </h3>
                </div>
                <div class="sh-timeline">
                    <?php if ( ! empty( $timeline_events ) ) : ?>
                        <?php foreach ( $timeline_events as $evt ) :
                            $dot_class = 'info';
                            if ( in_array( $evt['type'], array( 'success', 'danger', 'warning', 'scheduled', 'info' ), true ) ) {
                                $dot_class = $evt['type'];
                            }
                        ?>
                        <div class="sh-timeline-item">
                            <div class="sh-timeline-dot <?php echo esc_attr( $dot_class ); ?>"></div>
                            <div class="sh-timeline-content">
                                <div class="sh-timeline-time"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $evt['timestamp'] ) ) ); ?></div>
                                <div class="sh-timeline-text">
                                    <?php echo esc_html( $evt['text'] ); ?>
                                    <?php if ( ! empty( $evt['amount'] ) ) : ?>
                                        <?php /* $evt['amount'] already carries wp_kses_post( wc_price(...) ) HTML from where the event was built — esc_html() here previously double-escaped it, printing raw markup instead of a formatted price. */ ?>
                                        <span class="sh-timeline-amount"><?php echo wp_kses_post( $evt['amount'] ); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( ! empty( $evt['detail'] ) ) : ?>
                                    <div class="sh-timeline-detail"><?php echo esc_html( $evt['detail'] ); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p style="color:var(--sh-gray-400, #9ca3af); font-style:italic; margin:0;"><?php esc_html_e( 'No activity recorded yet.', 'securehold-security-deposit-holds' ); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Technical Logs (collapsible) -->
            <?php
            // FREE: query the local logs table for entries tied to this order or hold.
            // PRO replaces this with its own enriched dataset via $technical_logs.
            if ( ! $_sh_logs_pro ) {
                global $wpdb;
                $sh_logs_table = $wpdb->prefix . 'securehold_logs';
                $sh_logs_rows  = array();
                if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $sh_logs_table ) ) ) === $sh_logs_table ) {
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
                    $sh_logs_rows = (array) $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, created_at, severity, event_type, message FROM {$sh_logs_table}
                         WHERE order_id = %d OR hold_id = %d
                         ORDER BY created_at DESC, id DESC LIMIT 50",
                        absint( $deposit->order_id ),
                        absint( $deposit_id )
                    ) );
                }
            }
            ?>
            <?php if ( ! $_sh_logs_pro ) : ?>
            <!-- FREE: simple log entries scoped to this deposit / order -->
            <div class="sh-card sh-card-collapsible" style="margin-bottom:1.5rem;">
                <button type="button" class="sh-card-header sh-collapse-toggle" aria-expanded="false" aria-controls="sh-technical-logs-body">
                    <h3 class="sh-card-title">
                        <span class="dashicons dashicons-media-code"></span>
                        <?php esc_html_e( 'Technical Logs', 'securehold-security-deposit-holds' ); ?>
                        <?php if ( ! empty( $sh_logs_rows ) ) : ?>
                            <span class="sh-logs-count"><?php echo esc_html( count( $sh_logs_rows ) ); ?></span>
                        <?php endif; ?>
                    </h3>
                    <span class="sh-collapse-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="sh-collapse-body" id="sh-technical-logs-body">
                    <?php if ( empty( $sh_logs_rows ) ) : ?>
                        <p style="color:var(--sh-gray-400, #9ca3af); font-style:italic; margin:0; padding:1rem 1.25rem;">
                            <?php esc_html_e( 'No log entries recorded for this deposit yet.', 'securehold-security-deposit-holds' ); ?>
                        </p>
                    <?php else : ?>
                        <div class="sh-techlog-scroll">
                            <table class="sh-techlog-table">
                                <thead>
                                    <tr>
                                        <th style="width:160px;"><?php esc_html_e( 'Time', 'securehold-security-deposit-holds' ); ?></th>
                                        <th style="width:80px;"><?php esc_html_e( 'Level', 'securehold-security-deposit-holds' ); ?></th>
                                        <th style="width:140px;"><?php esc_html_e( 'Event', 'securehold-security-deposit-holds' ); ?></th>
                                        <th><?php esc_html_e( 'Message', 'securehold-security-deposit-holds' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $sh_logs_rows as $sh_lr ) :
                                        $sh_lr_severity = isset( $sh_lr->severity )   ? (string) $sh_lr->severity   : 'info';
                                        $sh_lr_event    = isset( $sh_lr->event_type ) ? (string) $sh_lr->event_type : '';
                                        $sh_lr_msg      = isset( $sh_lr->message )    ? (string) $sh_lr->message    : '';
                                        $sh_lr_created  = isset( $sh_lr->created_at ) ? (string) $sh_lr->created_at : '';
                                    ?>
                                    <tr>
                                        <td class="sh-techlog-time"><?php echo esc_html( $sh_lr_created ? date_i18n( 'M j, H:i:s', strtotime( $sh_lr_created ) ) : '' ); ?></td>
                                        <td><code><?php echo esc_html( $sh_lr_severity ); ?></code></td>
                                        <td><?php echo esc_html( $sh_lr_event ); ?></td>
                                        <td class="sh-techlog-msg"><?php echo esc_html( $sh_lr_msg ); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="sh-techlog-footer">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-logs' ) ); ?>"><?php esc_html_e( 'View All Logs', 'securehold-security-deposit-holds' ); ?> &rarr;</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ( ! empty( $technical_logs ) ) : ?>
            <!-- PRO: full collapsible log table -->
            <div class="sh-card sh-card-collapsible" style="margin-bottom:1.5rem;">
                <button type="button" class="sh-card-header sh-collapse-toggle" aria-expanded="false" aria-controls="sh-technical-logs-body">
                    <h3 class="sh-card-title">
                        <span class="dashicons dashicons-media-code"></span>
                        <?php esc_html_e( 'Technical Logs', 'securehold-security-deposit-holds' ); ?>
                        <span class="sh-logs-count"><?php echo esc_html( count( $technical_logs ) ); ?></span>
                    </h3>
                    <span class="sh-collapse-icon dashicons dashicons-arrow-down-alt2"></span>
                </button>
                <div class="sh-collapse-body" id="sh-technical-logs-body">
                    <div class="sh-techlog-scroll">
                        <table class="sh-techlog-table">
                            <thead>
                                <tr>
                                    <th style="width:130px;"><?php esc_html_e( 'Time', 'securehold-security-deposit-holds' ); ?></th>
                                    <th style="width:70px;"><?php esc_html_e( 'Type', 'securehold-security-deposit-holds' ); ?></th>
                                    <th><?php esc_html_e( 'Message', 'securehold-security-deposit-holds' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $technical_logs as $tl ) : ?>
                                <tr>
                                    <td class="sh-techlog-time"><?php echo esc_html( date_i18n( 'M j, H:i:s', strtotime( $tl['timestamp'] ) ) ); ?></td>
                                    <td>
                                        <?php
                                        $badge_map = array(
                                            'error'   => 'sh-log-error',
                                            'warning' => 'sh-log-warning',
                                            'webhook' => 'sh-log-webhook',
                                            'stripe'  => 'sh-log-stripe',
                                            'info'    => 'sh-log-info',
                                        );
                                        $badge_class = isset( $badge_map[ $tl['display_type'] ] ) ? $badge_map[ $tl['display_type'] ] : 'sh-log-info';
                                        ?>
                                        <span class="sh-log-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $tl['display_type'] ); ?></span>
                                    </td>
                                    <td class="sh-techlog-msg">
                                        <?php echo esc_html( $tl['message'] ); ?>
                                        <?php if ( ! empty( $tl['stripe_id'] ) ) : ?>
                                            <code class="sh-techlog-id"><?php echo esc_html( $tl['stripe_id'] ); ?></code>
                                        <?php endif; ?>
                                        <?php if ( ! empty( $tl['extra'] ) ) : ?>
                                            <span class="sh-techlog-extra"><?php echo esc_html( $tl['extra'] ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="sh-techlog-footer">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-logs' ) ); ?>"><?php esc_html_e( 'View All Logs', 'securehold-security-deposit-holds' ); ?> &rarr;</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- RIGHT COLUMN -->
        <div>
            <!-- Customer Info -->
            <div class="sh-card" style="margin-bottom:1.5rem;">
                <div class="sh-card-header">
                    <h3 class="sh-card-title"><span class="dashicons dashicons-admin-users"></span> <?php esc_html_e('Customer', 'securehold-security-deposit-holds'); ?></h3>
                </div>
                <?php if ($order_exists): ?>
                <table class="sh-detail-table">
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Name', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Email', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><a href="mailto:<?php echo esc_attr($order->get_billing_email()); ?>"><?php echo esc_html($order->get_billing_email()); ?></a></td>
                    </tr>
                    <?php if ($order->get_billing_phone()): ?>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Phone', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value"><?php echo esc_html($order->get_billing_phone()); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <div style="padding-top:0.75rem; border-top:1px solid var(--sh-gray-200, #e5e7eb); margin-top:0.75rem;">
                    <a href="<?php echo esc_url(admin_url('post.php?post=' . $deposit->order_id . '&action=edit')); ?>" class="sh-btn sh-btn-ghost" style="width:100%; justify-content:center; font-size:0.8rem;">
                        <span class="dashicons dashicons-visibility" style="font-size:16px; width:16px; height:16px;"></span>
                        <?php esc_html_e('View Order', 'securehold-security-deposit-holds'); ?>
                    </a>
                </div>
                <?php else: ?>
                <p style="color:var(--sh-gray-400, #9ca3af); font-style:italic; margin:0;"><?php esc_html_e('Order has been deleted.', 'securehold-security-deposit-holds'); ?></p>
                <?php endif; ?>
            </div>

            <!-- Applied Configuration -->
            <div class="sh-card" style="margin-bottom:1.5rem;">
                <div class="sh-card-header">
                    <h3 class="sh-card-title">
                        <span class="dashicons dashicons-admin-generic"></span>
                        <?php esc_html_e( 'Applied Configuration', 'securehold-security-deposit-holds' ); ?>
                    </h3>
                    <div class="sh-card-header-badges">
                        <?php if ( 'per_item_aggregated' === $dd_aggregation_mode ) : ?>
                            <span class="sh-badge sh-badge-aggregation-mode" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;"><?php esc_html_e('Per Item', 'securehold-security-deposit-holds'); ?></span>
                        <?php else : ?>
                            <span class="sh-badge sh-badge-aggregation-mode" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;"><?php esc_html_e('Per Order', 'securehold-security-deposit-holds'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( ! $_sh_rule_engine_pro ) : ?>
                <!-- FREE: render the active global config. -->
                <table class="sh-detail-table">
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e( 'Aggregation Mode', 'securehold-security-deposit-holds' ); ?></td>
                        <td class="sh-dt-value">
                            <?php
                            $sh_dd_agg_free = get_option( 'securehold_aggregation_mode', 'per_order' );
                            if ( 'per_item_aggregated' === $sh_dd_agg_free ) {
                                echo '<span class="sh-badge" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;">' . esc_html__( 'Per Item Aggregated', 'securehold-security-deposit-holds' ) . '</span>';
                            } else {
                                echo '<span class="sh-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;">' . esc_html__( 'Per Order', 'securehold-security-deposit-holds' ) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e( 'Resolution Policy', 'securehold-security-deposit-holds' ); ?></td>
                        <td class="sh-dt-value">
                            <?php
                            $sh_dd_pol_free = get_option( 'securehold_resolution_policy', 'priority_chain' );
                            if ( 'highest_deposit_wins' === $sh_dd_pol_free ) {
                                echo '<span class="sh-badge sh-badge-global">' . esc_html__( 'Highest Deposit Wins', 'securehold-security-deposit-holds' ) . '</span>';
                            } else {
                                echo '<span class="sh-badge sh-badge-global">' . esc_html__( 'Priority Chain', 'securehold-security-deposit-holds' ) . '</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e( 'Default Hold Amount', 'securehold-security-deposit-holds' ); ?></td>
                        <td class="sh-dt-value">
                            <?php echo esc_html( (string) get_option( 'securehold_default_hold_amount', '' ) ); ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e( 'Auto-Release After', 'securehold-security-deposit-holds' ); ?></td>
                        <td class="sh-dt-value">
                            <?php
                            $sh_dd_days = (int) get_option( 'securehold_auto_release_days', 7 );
                            if ( $sh_dd_days < 1 ) { $sh_dd_days = 1; }
                            if ( $sh_dd_days > 7 ) { $sh_dd_days = 7; }
                            /* translators: %d = number of days. */
                            echo esc_html( sprintf( _n( '%d day', '%d days', $sh_dd_days, 'securehold-security-deposit-holds' ), $sh_dd_days ) );
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e( 'Capture Strategy', 'securehold-security-deposit-holds' ); ?></td>
                        <td class="sh-dt-value">
                            <?php
                            $sh_dd_timing = (string) get_option( 'securehold_capture_timing', 'immediate' );
                            $sh_dd_labels = array(
                                'immediate' => __( 'Immediate', 'securehold-security-deposit-holds' ),
                                'manual'    => __( 'Manual', 'securehold-security-deposit-holds' ),
                                'delayed'   => __( 'Delayed', 'securehold-security-deposit-holds' ),
                                'scheduled' => __( 'Scheduled', 'securehold-security-deposit-holds' ),
                                'status'    => __( 'By Status', 'securehold-security-deposit-holds' ),
                            );
                            echo esc_html( isset( $sh_dd_labels[ $sh_dd_timing ] ) ? $sh_dd_labels[ $sh_dd_timing ] : $sh_dd_timing );
                            ?>
                        </td>
                    </tr>
                </table>
                <?php else : ?>
                <table class="sh-detail-table">
                    <!-- Aggregation Mode -->
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Aggregation Mode', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value">
                            <?php if ( 'per_item_aggregated' === $dd_aggregation_mode ) : ?>
                                <span class="sh-badge" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;"><?php esc_html_e('Per Item Aggregated', 'securehold-security-deposit-holds'); ?></span>
                            <?php else : ?>
                                <span class="sh-badge" style="background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;"><?php esc_html_e('Per Order', 'securehold-security-deposit-holds'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <!-- Resolution Policy -->
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Resolution Policy', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value">
                            <?php if ( 'highest_deposit_wins' === $dd_rule_policy ) : ?>
                                <span class="sh-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;"><?php esc_html_e('Highest Deposit Wins', 'securehold-security-deposit-holds'); ?></span>
                            <?php else : ?>
                                <span class="sh-badge sh-badge-global"><?php esc_html_e('Priority Chain', 'securehold-security-deposit-holds'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <?php if ( 'per_item_aggregated' === $dd_aggregation_mode ) : ?>
                        <?php /* ── Per Item Aggregated: summary rows ── */ ?>

                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e('Total Aggregated Amount', 'securehold-security-deposit-holds'); ?></td>
                            <td class="sh-dt-value" style="font-weight:700;">
                                <?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?>
                            </td>
                        </tr>

                        <?php if ( is_array( $dd_winner_item ) && ! empty( $dd_winner_item['product_name'] ) ) : ?>
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e('Winner Item', 'securehold-security-deposit-holds'); ?></td>
                            <td class="sh-dt-value">
                                <?php echo esc_html( $dd_winner_item['product_name'] ); ?>
                                <?php if ( ! empty( $dd_winner_item['product_id'] ) ) : ?>
                                    <span class="sh-meta-id">#<?php echo esc_html( $dd_winner_item['product_id'] ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if ( is_array( $dd_winner_item ) && ! empty( $dd_winner_item['timing'] ) ) : ?>
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e('Winner Strategy', 'securehold-security-deposit-holds'); ?></td>
                            <td class="sh-dt-value" style="font-weight:600;">
                                <span class="sh-strategy-row">
                                    <span class="sh-strategy-label"><?php echo esc_html( ucfirst( $dd_winner_item['timing'] ) ); ?></span>
                                    <?php
                                    $winner_src = isset( $dd_winner_item['source'] ) ? $dd_winner_item['source'] : 'global';
                                    if ( 'product_rule' === $winner_src ) : ?>
                                        <span class="sh-badge sh-badge-product-rule"><?php esc_html_e('Product Rule', 'securehold-security-deposit-holds'); ?></span>
                                    <?php elseif ( 'category_rule' === $winner_src ) : ?>
                                        <span class="sh-badge sh-badge-category-rule"><?php esc_html_e('Category Rule', 'securehold-security-deposit-holds'); ?></span>
                                    <?php else : ?>
                                        <span class="sh-badge sh-badge-global"><?php esc_html_e('Global', 'securehold-security-deposit-holds'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php if ( is_array( $dd_item_breakdown ) && ! empty( $dd_item_breakdown ) ) : ?>
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e('Items Count', 'securehold-security-deposit-holds'); ?></td>
                            <td class="sh-dt-value"><?php echo esc_html( count( $dd_item_breakdown ) ); ?></td>
                        </tr>
                        <?php endif; ?>

                    <?php else : ?>
                        <?php /* ── Per Order: standard strategy display (snapshot only) ── */ ?>

                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e('Applied Strategy', 'securehold-security-deposit-holds'); ?></td>
                            <td class="sh-dt-value" style="font-weight:600;">
                                <span class="sh-strategy-row">
                                    <span class="sh-strategy-label"><?php echo esc_html( ucfirst( $dd_timing_strategy ) ); ?></span>
                                    <?php if ( 'product_rule' === $dd_source ) : ?>
                                        <span class="sh-badge sh-badge-product-rule"><?php esc_html_e('Product Rule', 'securehold-security-deposit-holds'); ?></span>
                                    <?php elseif ( 'category_rule' === $dd_source ) : ?>
                                        <span class="sh-badge sh-badge-category-rule"><?php esc_html_e('Category Rule', 'securehold-security-deposit-holds'); ?></span>
                                    <?php else : ?>
                                        <span class="sh-badge sh-badge-global"><?php esc_html_e('Global', 'securehold-security-deposit-holds'); ?></span>
                                    <?php endif; ?>
                                </span>
                            </td>
                        </tr>

                        <?php /* ── Strategy-specific details (all from frozen snapshot) ── */ ?>

                        <?php if ( $dd_timing_strategy === 'scheduled' ) : ?>
                            <?php if ( ! empty( $dd_date_field_key ) ) : ?>
                            <tr>
                                <td class="sh-dt-label"><?php esc_html_e('Date Meta Key', 'securehold-security-deposit-holds'); ?></td>
                                <td class="sh-dt-value"><code class="sh-code-inline"><?php echo esc_html( $dd_date_field_key ); ?></code></td>
                            </tr>
                            <?php endif; ?>
                            <?php if ( ! empty( $dd_scheduled_direction ) ) : ?>
                            <tr>
                                <td class="sh-dt-label"><?php esc_html_e('Timing', 'securehold-security-deposit-holds'); ?></td>
                                <td class="sh-dt-value">
                                    <?php
                                    if ( $dd_scheduled_direction === 'same_day' ) {
                                        esc_html_e( 'On the same day', 'securehold-security-deposit-holds' );
                                    } elseif ( ! empty( $dd_scheduled_days ) ) {
                                        echo esc_html( sprintf(
                                            /* translators: %1$s is the number of days, %2$s is the direction (before/after) */
                                            __( '%1$s day(s) %2$s the date', 'securehold-security-deposit-holds' ),
                                            $dd_scheduled_days,
                                            $dd_scheduled_direction
                                        ) );
                                    } else {
                                        echo esc_html( ucfirst( $dd_scheduled_direction ) );
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php elseif ( $dd_timing_strategy === 'immediate' ) : ?>
                            <tr>
                                <td class="sh-dt-label"><?php esc_html_e('Behavior', 'securehold-security-deposit-holds'); ?></td>
                                <td class="sh-dt-value" style="color:var(--sh-gray-500,#6b7280);"><?php esc_html_e('Hold created immediately at checkout', 'securehold-security-deposit-holds'); ?></td>
                            </tr>
                        <?php elseif ( $dd_timing_strategy === 'manual' ) : ?>
                            <tr>
                                <td class="sh-dt-label"><?php esc_html_e('Behavior', 'securehold-security-deposit-holds'); ?></td>
                                <td class="sh-dt-value" style="color:var(--sh-gray-500,#6b7280);"><?php esc_html_e('Requires manual "Create Hold" action', 'securehold-security-deposit-holds'); ?></td>
                            </tr>
                        <?php elseif ( $dd_timing_strategy === 'delayed' ) : ?>
                            <?php if ( ! empty( $dd_delay_days ) ) : ?>
                            <tr>
                                <td class="sh-dt-label"><?php esc_html_e('Delay', 'securehold-security-deposit-holds'); ?></td>
                                <td class="sh-dt-value">
                                    <?php echo esc_html( sprintf(
                                        /* translators: %s is the number of delay days */
                                        __( '%s day(s) after order', 'securehold-security-deposit-holds' ),
                                        $dd_delay_days
                                    ) ); ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php elseif ( $dd_timing_strategy === 'status' ) : ?>
                            <?php if ( ! empty( $dd_trigger_status ) ) : ?>
                            <tr>
                                <td class="sh-dt-label"><?php esc_html_e('Trigger Status', 'securehold-security-deposit-holds'); ?></td>
                                <td class="sh-dt-value">
                                    <code class="sh-code-inline"><?php echo esc_html( $dd_trigger_status ); ?></code>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php /* ── Deposit amount & source (from snapshot) ── */ ?>

                        <?php if ( ! empty( $dd_deposit_amount ) ) : ?>
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e('Deposit Amount', 'securehold-security-deposit-holds'); ?></td>
                            <td class="sh-dt-value"><?php echo esc_html( $dd_deposit_amount ); ?></td>
                        </tr>
                        <?php endif; ?>

                        <?php if ( 'global' !== $dd_source && ! empty( $dd_source_label ) ) : ?>
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e('Rule Source', 'securehold-security-deposit-holds'); ?></td>
                            <td class="sh-dt-value">
                                <?php echo esc_html( $dd_source_label ); ?>
                                <?php if ( ! empty( $dd_source_id ) && '0' !== $dd_source_id ) : ?>
                                    <span class="sh-meta-id">#<?php echo esc_html( $dd_source_id ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Common to both modes (all from frozen snapshot) -->
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Auto-Release', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value">
                            <?php
                            if ( '—' === $dd_auto_release_days ) {
                                echo esc_html( '—' );
                            } else {
                                echo esc_html( $dd_auto_release_days . ' ' . __( 'days', 'securehold-security-deposit-holds' ) );
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="sh-dt-label"><?php esc_html_e('Stripe Mode', 'securehold-security-deposit-holds'); ?></td>
                        <td class="sh-dt-value">
                            <?php
                            if ( '—' === $dd_stripe_mode ) {
                                echo esc_html( '—' );
                            } else {
                                echo esc_html( ucfirst( $dd_stripe_mode ) );
                            }
                            ?>
                        </td>
                    </tr>
                </table>

                <!-- Explain Applied Rule button -->
                <div class="sh-explain-rule-wrapper">
                    <button type="button" class="sh-btn sh-btn-ghost sh-btn-explain-rule" id="sh-btn-explain-rule">
                        <span class="dashicons dashicons-info-outline"></span>
                        <?php esc_html_e('Explain Applied Rule', 'securehold-security-deposit-holds'); ?>
                    </button>
                </div>
                <?php endif; // $_sh_rule_engine_pro ?>
            </div>

            <?php
            // ── Item Breakdown (per_item_aggregated mode) ──
            // Variables $dd_aggregation_mode, $dd_item_breakdown, $dd_winner_item
            // are already loaded above with backward-compat fallbacks.

            if ( $_sh_rule_engine_pro && 'per_item_aggregated' === $dd_aggregation_mode && is_array( $dd_item_breakdown ) && ! empty( $dd_item_breakdown ) ) :
            ?>
            <div class="sh-card" style="margin-bottom:1.5rem;">
                <div class="sh-card-header">
                    <h3 class="sh-card-title"><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Item Breakdown', 'securehold-security-deposit-holds' ); ?></h3>
                    <span class="sh-badge" style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;"><?php esc_html_e( 'Per Item Aggregated', 'securehold-security-deposit-holds' ); ?></span>
                </div>

                <?php if ( is_array( $dd_winner_item ) && ! empty( $dd_winner_item['timing'] ) ) : ?>
                <div class="sh-item-breakdown-summary">
                    <table class="sh-detail-table">
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e( 'Final Amount', 'securehold-security-deposit-holds' ); ?></td>
                            <td class="sh-dt-value" style="font-weight:700;">
                                <?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e( 'Winner Strategy', 'securehold-security-deposit-holds' ); ?></td>
                            <td class="sh-dt-value">
                                <span class="sh-strategy-label"><?php echo esc_html( ucfirst( $dd_winner_item['timing'] ) ); ?></span>
                                <?php
                                $winner_src = isset( $dd_winner_item['source'] ) ? $dd_winner_item['source'] : 'global';
                                if ( 'product_rule' === $winner_src ) :
                                ?>
                                    <span class="sh-badge sh-badge-product-rule"><?php esc_html_e( 'Product Rule', 'securehold-security-deposit-holds' ); ?></span>
                                <?php elseif ( 'category_rule' === $winner_src ) : ?>
                                    <span class="sh-badge sh-badge-category-rule"><?php esc_html_e( 'Category Rule', 'securehold-security-deposit-holds' ); ?></span>
                                <?php else : ?>
                                    <span class="sh-badge sh-badge-global"><?php esc_html_e( 'Global', 'securehold-security-deposit-holds' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="sh-dt-label"><?php esc_html_e( 'Winner Item', 'securehold-security-deposit-holds' ); ?></td>
                            <td class="sh-dt-value">
                                <?php echo esc_html( $dd_winner_item['product_name'] ); ?>
                                <span class="sh-meta-id">#<?php echo esc_html( $dd_winner_item['product_id'] ); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
                <?php endif; ?>

                <div class="sh-item-breakdown-table-wrap">
                    <table class="sh-techlog-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Product', 'securehold-security-deposit-holds' ); ?></th>
                                <th style="text-align:center;"><?php esc_html_e( 'Qty', 'securehold-security-deposit-holds' ); ?></th>
                                <th style="text-align:right;"><?php esc_html_e( 'Contribution', 'securehold-security-deposit-holds' ); ?></th>
                                <th><?php esc_html_e( 'Strategy', 'securehold-security-deposit-holds' ); ?></th>
                                <th><?php esc_html_e( 'Source', 'securehold-security-deposit-holds' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $dd_item_breakdown as $bi ) :
                                $bi_is_winner = is_array( $dd_winner_item )
                                    && isset( $dd_winner_item['product_id'] )
                                    && (int) $bi['product_id'] === (int) $dd_winner_item['product_id'];
                            ?>
                            <tr<?php echo $bi_is_winner ? ' style="font-weight:600;background:#fefce8;"' : ''; ?>>
                                <td>
                                    <?php echo esc_html( $bi['product_name'] ); ?>
                                    <?php if ( $bi_is_winner ) : ?>
                                        <span class="sh-badge sh-badge-success" style="margin-left:4px;font-size:10px;"><?php esc_html_e( 'Winner', 'securehold-security-deposit-holds' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;"><?php echo esc_html( $bi['qty'] ); ?></td>
                                <td style="text-align:right;"><?php echo wp_kses_post( wc_price( (float) $bi['contribution'], array( 'currency' => $currency ) ) ); ?></td>
                                <td><?php echo esc_html( ucfirst( $bi['timing'] ) ); ?></td>
                                <td>
                                    <?php
                                    $bi_src = isset( $bi['source'] ) ? $bi['source'] : 'global';
                                    if ( 'product_rule' === $bi_src ) :
                                    ?>
                                        <span class="sh-badge sh-badge-product-rule"><?php esc_html_e( 'Product', 'securehold-security-deposit-holds' ); ?></span>
                                    <?php elseif ( 'category_rule' === $bi_src ) : ?>
                                        <span class="sh-badge sh-badge-category-rule"><?php esc_html_e( 'Category', 'securehold-security-deposit-holds' ); ?></span>
                                    <?php else : ?>
                                        <span class="sh-badge sh-badge-global"><?php esc_html_e( 'Global', 'securehold-security-deposit-holds' ); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:700;border-top:2px solid var(--sh-gray-300,#d1d5db);">
                                <td><?php esc_html_e( 'Total', 'securehold-security-deposit-holds' ); ?></td>
                                <td style="text-align:center;">
                                    <?php
                                    $total_qty = 0;
                                    foreach ( $dd_item_breakdown as $bi ) {
                                        $total_qty += (int) $bi['qty'];
                                    }
                                    echo esc_html( $total_qty );
                                    ?>
                                </td>
                                <td style="text-align:right;"><?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Explain Applied Rule Modal (PRO only) -->
            <?php if ( $_sh_rule_engine_pro ) : ?>
            <div id="sh-explain-rule-modal" class="sh-modal-overlay" style="display:none;">
                <div class="sh-modal-box">
                    <div class="sh-modal-header">
                        <h3><span class="dashicons dashicons-networking"></span> <?php esc_html_e('Rule Engine — Resolution Explanation', 'securehold-security-deposit-holds'); ?></h3>
                        <button type="button" class="sh-modal-close" id="sh-explain-modal-close">&times;</button>
                    </div>
                    <div class="sh-modal-body">

                        <!-- Active Policy -->
                        <div class="sh-explain-section">
                            <h4><?php esc_html_e('Active Policy', 'securehold-security-deposit-holds'); ?></h4>
                            <p class="sh-explain-desc">
                                <?php if ( $applied_policy === 'highest_deposit_wins' ) : ?>
                                    <span class="sh-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;"><?php esc_html_e('Highest Deposit Wins', 'securehold-security-deposit-holds'); ?></span>
                                    &mdash; <?php esc_html_e('Order-level winner by highest resolved deposit amount. All applicable rules compete.', 'securehold-security-deposit-holds'); ?>
                                <?php else : ?>
                                    <span class="sh-badge sh-badge-global"><?php esc_html_e('Priority Chain', 'securehold-security-deposit-holds'); ?></span>
                                    &mdash; <?php esc_html_e('Product rule overrides category, overrides global. First match at the highest-priority level wins.', 'securehold-security-deposit-holds'); ?>
                                <?php endif; ?>
                            </p>
                        </div>

                        <?php if ( $applied_policy !== 'highest_deposit_wins' ) : ?>
                        <!-- Priority chain visualization (only for priority_chain) -->
                        <div class="sh-explain-section">
                            <h4><?php esc_html_e('Priority Chain', 'securehold-security-deposit-holds'); ?></h4>
                            <div class="sh-explain-priority-chain">
                                <span class="sh-explain-step <?php echo $applied_source === 'product_rule' ? 'sh-explain-step--active' : ''; ?>">
                                    <span class="sh-badge sh-badge-product-rule"><?php esc_html_e('Product Rule', 'securehold-security-deposit-holds'); ?></span>
                                    <?php echo $applied_source === 'product_rule' ? '<span class="sh-explain-check dashicons dashicons-yes-alt"></span>' : ''; ?>
                                </span>
                                <span class="sh-explain-arrow">&rarr;</span>
                                <span class="sh-explain-step <?php echo $applied_source === 'category_rule' ? 'sh-explain-step--active' : ''; ?>">
                                    <span class="sh-badge sh-badge-category-rule"><?php esc_html_e('Category Rule', 'securehold-security-deposit-holds'); ?></span>
                                    <?php echo $applied_source === 'category_rule' ? '<span class="sh-explain-check dashicons dashicons-yes-alt"></span>' : ''; ?>
                                </span>
                                <span class="sh-explain-arrow">&rarr;</span>
                                <span class="sh-explain-step <?php echo $applied_source === 'global' ? 'sh-explain-step--active' : ''; ?>">
                                    <span class="sh-badge sh-badge-global"><?php esc_html_e('Global', 'securehold-security-deposit-holds'); ?></span>
                                    <?php echo $applied_source === 'global' ? '<span class="sh-explain-check dashicons dashicons-yes-alt"></span>' : ''; ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Applied rule details -->
                        <div class="sh-explain-section">
                            <h4><?php esc_html_e('Applied Rule', 'securehold-security-deposit-holds'); ?></h4>
                            <table class="sh-detail-table">
                                <tr>
                                    <td class="sh-dt-label"><?php esc_html_e('Source', 'securehold-security-deposit-holds'); ?></td>
                                    <td class="sh-dt-value">
                                        <?php
                                        if ( $applied_source === 'product_rule' ) {
                                            echo '<span class="sh-badge sh-badge-product-rule">' . esc_html__('Product Rule', 'securehold-security-deposit-holds') . '</span> ';
                                        } elseif ( $applied_source === 'category_rule' ) {
                                            echo '<span class="sh-badge sh-badge-category-rule">' . esc_html__('Category Rule', 'securehold-security-deposit-holds') . '</span> ';
                                        } else {
                                            echo '<span class="sh-badge sh-badge-global">' . esc_html__('Global', 'securehold-security-deposit-holds') . '</span> ';
                                        }
                                        echo esc_html( $applied_config['source_label'] );
                                        if ( $applied_config['source_id'] > 0 ) {
                                            echo ' <span class="sh-meta-id">#' . esc_html( $applied_config['source_id'] ) . '</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <td class="sh-dt-label"><?php esc_html_e('Strategy', 'securehold-security-deposit-holds'); ?></td>
                                    <td class="sh-dt-value"><?php echo esc_html( ucfirst( $applied_timing ) ); ?></td>
                                </tr>
                                <tr>
                                    <td class="sh-dt-label"><?php esc_html_e('Deposit Amount', 'securehold-security-deposit-holds'); ?></td>
                                    <td class="sh-dt-value"><?php echo esc_html( $applied_deposit_amount ); ?></td>
                                </tr>
                            </table>
                        </div>

                        <?php /* ── All candidates (Highest Deposit Wins or multi-category conflict) ── */ ?>
                        <?php if ( $applied_policy === 'highest_deposit_wins' && ! empty( $applied_explain['candidates'] ) ) : ?>
                        <div class="sh-explain-section">
                            <h4><?php esc_html_e('All Candidates', 'securehold-security-deposit-holds'); ?></h4>
                            <p class="sh-explain-desc">
                                <?php echo esc_html( sprintf(
                                    /* translators: %d = number of candidates */
                                    __( '%d candidate(s) evaluated. Winner selected by highest resolved deposit amount.', 'securehold-security-deposit-holds' ),
                                    count( $applied_explain['candidates'] )
                                ) ); ?>
                            </p>
                            <table class="sh-detail-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Source', 'securehold-security-deposit-holds'); ?></th>
                                        <th><?php esc_html_e('Amount (raw)', 'securehold-security-deposit-holds'); ?></th>
                                        <th><?php esc_html_e('Resolved', 'securehold-security-deposit-holds'); ?></th>
                                        <th><?php esc_html_e('Strategy', 'securehold-security-deposit-holds'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $applied_explain['candidates'] as $ec ) :
                                    $ec_is_winner = ( $ec['type'] === $applied_source && (int) $ec['id'] === (int) $applied_config['source_id'] );
                                ?>
                                    <tr<?php echo $ec_is_winner ? ' style="font-weight:600;background:#fefce8;"' : ''; ?>>
                                        <td>
                                            <?php echo esc_html( $ec['label'] ); ?>
                                            <?php if ( $ec['id'] > 0 ) : ?>
                                                <span class="sh-meta-id">#<?php echo esc_html( $ec['id'] ); ?></span>
                                            <?php endif; ?>
                                            <?php if ( $ec_is_winner ) : ?>
                                                <span class="sh-badge sh-badge-success" style="margin-left:4px;font-size:10px;"><?php esc_html_e('Winner', 'securehold-security-deposit-holds'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( $ec['amount_raw'] ); ?></td>
                                        <td><?php echo wp_kses_post( wc_price( (float) $ec['amount_resolved'], array( 'currency' => $currency ) ) ); ?></td>
                                        <td><?php echo esc_html( ucfirst( $ec['timing'] ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if ( ! empty( $applied_explain['winner_reason'] ) ) : ?>
                            <p class="sh-explain-reason">
                                <strong><?php esc_html_e('Resolution:', 'securehold-security-deposit-holds'); ?></strong>
                                <?php echo esc_html( $applied_explain['winner_reason'] ); ?>
                            </p>
                            <?php endif; ?>
                            <?php if ( ! empty( $applied_explain['tie_breaks'] ) ) : ?>
                            <p class="sh-explain-reason" style="margin-top:0.25rem;">
                                <strong><?php esc_html_e('Tie-breaks:', 'securehold-security-deposit-holds'); ?></strong>
                                <?php echo esc_html( implode( ' | ', $applied_explain['tie_breaks'] ) ); ?>
                            </p>
                            <?php endif; ?>
                        </div>

                        <?php elseif ( ! empty( $applied_conflict_info ) ) : ?>
                        <div class="sh-explain-section">
                            <h4><?php esc_html_e('Category Conflict Resolution', 'securehold-security-deposit-holds'); ?></h4>
                            <p class="sh-explain-desc">
                                <?php echo esc_html( sprintf(
                                    /* translators: %d is the number of category rules that matched */
                                    __( '%d category rules matched this order. Conflicts were resolved automatically.', 'securehold-security-deposit-holds' ),
                                    $applied_conflict_info['candidates_count']
                                ) ); ?>
                            </p>
                            <table class="sh-detail-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Category', 'securehold-security-deposit-holds'); ?></th>
                                        <th><?php esc_html_e('Amount', 'securehold-security-deposit-holds'); ?></th>
                                        <th><?php esc_html_e('Strategy', 'securehold-security-deposit-holds'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $applied_conflict_info['candidates'] as $candidate ) :
                                    $is_winner = ! empty( $candidate['is_winner'] );
                                ?>
                                    <tr<?php echo $is_winner ? ' style="font-weight:600;background:#fefce8;"' : ''; ?>>
                                        <td>
                                            <?php echo esc_html( $candidate['name'] ); ?>
                                            <span class="sh-meta-id">#<?php echo esc_html( $candidate['term_id'] ); ?></span>
                                            <?php if ( $is_winner ) : ?>
                                                <span class="sh-badge sh-badge-success" style="margin-left:4px;font-size:10px;"><?php esc_html_e('Winner', 'securehold-security-deposit-holds'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html( $candidate['amount'] ); ?></td>
                                        <td><?php echo esc_html( ucfirst( $candidate['strategy'] ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <p class="sh-explain-reason">
                                <strong><?php esc_html_e('Resolution rule:', 'securehold-security-deposit-holds'); ?></strong>
                                <?php esc_html_e('Single winner — highest amount wins. If equal, highest strategy priority breaks the tie. All fields (amount, strategy, params) come from the winning category.', 'securehold-security-deposit-holds'); ?>
                            </p>
                        </div>
                        <?php endif; ?>

                        <!-- Why this rule -->
                        <div class="sh-explain-section">
                            <h4><?php esc_html_e('Why this rule?', 'securehold-security-deposit-holds'); ?></h4>
                            <p class="sh-explain-desc">
                                <?php
                                if ( $applied_policy === 'highest_deposit_wins' ) {
                                    esc_html_e('The "Highest Deposit Wins" policy was active. All applicable rules (product, category, global) were evaluated. The candidate with the highest resolved deposit amount won.', 'securehold-security-deposit-holds');
                                } elseif ( $applied_source === 'product_rule' ) {
                                    esc_html_e('A product in this order has a specific Product Rule configured. Product Rules always take the highest priority.', 'securehold-security-deposit-holds');
                                } elseif ( $applied_source === 'category_rule' ) {
                                    esc_html_e('No Product Rule was found, but one or more products belong to a category with a configured Category Rule.', 'securehold-security-deposit-holds');
                                } else {
                                    esc_html_e('No Product Rule or Category Rule matched this order. The global default configuration was applied.', 'securehold-security-deposit-holds');
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; // $_sh_rule_engine_pro — Explain Applied Rule Modal ?>

            <!-- Order Metadata -->
            <?php
            // Full order metadata collected above (order-level Stripe identifiers).
            $_sh_display_meta = $relevant_meta;
            ?>
            <?php if ( ! empty( $_sh_display_meta ) ) : ?>
            <div class="sh-card" style="margin-bottom:1.5rem;">
                <div class="sh-card-header">
                    <h3 class="sh-card-title">
                        <span class="dashicons dashicons-database"></span>
                        <?php esc_html_e( 'Order Metadata', 'securehold-security-deposit-holds' ); ?>
                    </h3>
                </div>
                <table class="sh-detail-table">
                    <?php foreach ( $_sh_display_meta as $mk => $mv ) : ?>
                    <tr>
                        <td class="sh-dt-label"><code style="font-size:11px;"><?php echo esc_html( $mk ); ?></code></td>
                        <td class="sh-dt-value" style="word-break:break-all;"><?php echo esc_html( $mv ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>

            <!-- Diagnostics (if failed) -->
            <?php if ($deposit->status === 'failed'): ?>
            <div class="sh-card" style="margin-bottom:1.5rem; border-left:4px solid var(--sh-danger, #ef4444);">
                <div class="sh-card-header">
                    <h3 class="sh-card-title" style="color:var(--sh-danger, #ef4444);"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('Failure Diagnostics', 'securehold-security-deposit-holds'); ?></h3>
                </div>
                <?php if (!empty($deposit->notes)): ?>
                <div style="padding:1rem; background:var(--sh-danger-light, #fee2e2); border-radius:6px; color:#7f1d1d; font-size:0.9rem; line-height:1.6;">
                    <?php echo esc_html($deposit->notes); ?>
                </div>
                <?php else: ?>
                <p style="color:var(--sh-gray-500, #6b7280); font-style:italic; margin:0;"><?php esc_html_e('No diagnostic information available.', 'securehold-security-deposit-holds'); ?></p>
                <?php endif; ?>
                <div style="margin-top:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-settings')); ?>" class="sh-btn sh-btn-ghost" style="font-size:0.8rem;">
                        <span class="dashicons dashicons-admin-generic" style="font-size:14px; width:14px; height:14px;"></span>
                        <?php esc_html_e('Settings', 'securehold-security-deposit-holds'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-health')); ?>" class="sh-btn sh-btn-ghost" style="font-size:0.8rem;">
                        <span class="dashicons dashicons-heart" style="font-size:14px; width:14px; height:14px;"></span>
                        <?php esc_html_e('Health Check', 'securehold-security-deposit-holds'); ?>
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-logs')); ?>" class="sh-btn sh-btn-ghost" style="font-size:0.8rem;">
                        <span class="dashicons dashicons-editor-code" style="font-size:14px; width:14px; height:14px;"></span>
                        <?php esc_html_e('Logs', 'securehold-security-deposit-holds'); ?>
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
/* ──────────────────────────────────────────────
   Capture Modal (shared markup — same as deposits-page.php)
   Only rendered when the Capture button can appear.
   ────────────────────────────────────────────── */
$detail_has_been_captured = ( $deposit->status === 'captured' || $captured > 0 || ! empty( $deposit->captured_at ) );
if ( $deposit->status === 'authorized' && ! $detail_has_been_captured && ! empty( $deposit->intent_id ) ) : ?>
<div id="sh-capture-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:white; padding:2rem; border-radius:8px; width:400px; max-width:90%; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
        <h2 style="margin-top:0; color:#d97706;"><?php esc_html_e('Capture Security Deposit', 'securehold-security-deposit-holds'); ?></h2>

        <form id="sh-capture-form">
            <input type="hidden" id="sh-capture-id" name="deposit_id">

            <div style="margin-bottom:1rem; background:#f9fafb; padding:10px; border-radius:4px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <span><?php esc_html_e('Total Authorized:', 'securehold-security-deposit-holds'); ?></span>
                    <strong id="sh-capture-total-display"></strong>
                </div>
                <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                    <span><?php esc_html_e('Already Captured:', 'securehold-security-deposit-holds'); ?></span>
                    <strong id="sh-capture-captured-display"></strong>
                </div>
                <div style="display:flex; justify-content:space-between; color:#d97706;">
                    <span><?php esc_html_e('Remaining:', 'securehold-security-deposit-holds'); ?></span>
                    <strong id="sh-capture-remaining-display"></strong>
                </div>
            </div>

            <div style="margin-bottom:1.5rem;">
                <label for="sh-capture-amount" style="display:block; font-weight:600; margin-bottom:0.5rem; color:#111;"><?php esc_html_e('Amount to Capture', 'securehold-security-deposit-holds'); ?></label>
                <div style="display:flex; align-items:center;">
                    <span id="sh-capture-currency-symbol" style="background:#eee; padding:0.5rem 1rem; border:1px solid #ddd; border-right:none; border-radius:4px 0 0 4px; line-height:1.5;"><?php echo esc_html( $currency_symbol ); ?></span>
                    <input type="number" id="sh-capture-amount" step="0.01" min="0.50" required style="width:100%; border-radius:0 4px 4px 0;">
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #eee; padding-top:1rem;">
                <button type="button" class="sh-btn sh-btn-ghost" id="sh-capture-cancel"><?php esc_html_e('Cancel', 'securehold-security-deposit-holds'); ?></button>
                <button type="submit" class="sh-btn sh-btn-primary" id="sh-capture-submit" style="background:#d97706; border-color:#d97706; box-shadow:0 2px 8px rgba(217,119,6,0.25);">
                    <span class="dashicons dashicons-money" style="font-size:16px; width:16px; height:16px;"></span>
                    <?php esc_html_e('Capture Now', 'securehold-security-deposit-holds'); ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif;
