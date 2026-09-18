<?php
/**
 * Deposit Computation Service
 *
 * Centralizes deposit amount and strategy resolution for both aggregation modes:
 *   - per_order (default): delegates to Config Resolver (existing behavior).
 *   - per_item_aggregated: resolves each line item individually, sums contributions,
 *     picks the most restrictive strategy (single winner), and returns a unified result.
 *
 * IMPORTANT: Always produces ONE hold per order. No multi-hold.
 *
 * @since 5.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Securehold_Deposit_Computation_Service {

    /**
     * Get the canonical strategy priority table from Config Resolver.
     *
     * @since 5.2.0 Replaced local $strategy_restrictiveness with shared table.
     * @return array
     */
    private static function get_strategy_priority() {
        self::load_dependencies();
        return Securehold_Config_Resolver::get_strategy_priority();
    }

    /**
     * Check whether a product is excluded from deposits.
     *
     * A product is excluded if:
     *   1. Its ID is in the `securehold_excluded_products` list, OR
     *   2. Any of its product_cat terms is in `securehold_excluded_categories`.
     *
     * Results are cached per-request via a static array to avoid repeated
     * option lookups and taxonomy queries for the same product.
     *
     * @since 5.5.0
     * @param int $product_id
     * @return bool
     */
    public static function is_product_excluded( $product_id ) {
        static $cache = array();
        if ( isset( $cache[ $product_id ] ) ) {
            return $cache[ $product_id ];
        }

        // Excluded products list.
        $excluded_products = get_option( 'securehold_excluded_products', array() );
        if ( ! is_array( $excluded_products ) ) {
            $excluded_products = array();
        }
        if ( in_array( (int) $product_id, array_map( 'intval', $excluded_products ), true ) ) {
            $cache[ $product_id ] = true;
            return true;
        }

        // Excluded categories list.
        $excluded_categories = get_option( 'securehold_excluded_categories', array() );
        if ( ! is_array( $excluded_categories ) ) {
            $excluded_categories = array();
        }
        if ( ! empty( $excluded_categories ) ) {
            $terms = wp_get_object_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $excluded_cat_ints = array_map( 'intval', $excluded_categories );
                foreach ( $terms as $tid ) {
                    if ( in_array( (int) $tid, $excluded_cat_ints, true ) ) {
                        $cache[ $product_id ] = true;
                        return true;
                    }
                }
            }
        }

        $cache[ $product_id ] = false;
        return false;
    }

    /**
     * Filter cart items removing any excluded products.
     *
     * @since 5.5.0
     * @param array $cart_items
     * @return array Filtered cart items.
     */
    private static function filter_excluded_items( $cart_items ) {
        $filtered = array();
        foreach ( $cart_items as $key => $cart_item ) {
            $product_id = isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0;
            if ( ! self::is_product_excluded( $product_id ) ) {
                $filtered[ $key ] = $cart_item;
            }
        }
        return $filtered;
    }

    /**
     * Compute the final deposit configuration for an order.
     *
     * If mode = per_order, returns the Config Resolver result unchanged.
     * If mode = per_item_aggregated, computes per-item contributions and aggregates.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return array Config Resolver-compatible result, enriched with:
     *   - 'aggregation_mode'    => 'per_order' | 'per_item_aggregated'
     *   - 'item_breakdown'      => array (only for per_item_aggregated)
     *   - 'winner_item'         => array (only for per_item_aggregated)
     */
    public static function compute( $order ) {
        self::load_dependencies();

        $mode = get_option( 'securehold_aggregation_mode', 'per_order' );

        if ( 'per_item_aggregated' !== $mode ) {
            // Per Order: delegate entirely to existing Config Resolver.
            $result = Securehold_Config_Resolver::resolve_final_deposit_configuration( $order );
            $result['aggregation_mode'] = 'per_order';
        } else {
            $result = self::compute_per_item_aggregated( $order );
        }

        /**
         * Allow PRO to modify or replace the computed deposit result.
         *
         * PRO uses this to inject per-item aggregated computation when the
         * per_item_aggregated mode requires product/category rule data only
         * available in PRO.
         *
         * @since 4.5.0
         * @param array    $result  Computation result (amount, timing, source, etc.).
         * @param WC_Order $order   The WooCommerce order.
         * @param string   $mode    Aggregation mode: 'per_order' | 'per_item_aggregated'.
         */
        $result = apply_filters( 'securehold_computation_aggregate', $result, $order, $mode );

        return $result;
    }

    /**
     * Compute the final deposit for cart display (no order context).
     *
     * Single source of truth for ALL cart-level deposit preview, regardless of
     * aggregation mode (per_order or per_item_aggregated).
     *
     * In cart context, percentage amounts cannot be resolved (no order total),
     * so face values are used for display.
     *
     * @since 5.2.0 Handles per_item_aggregated.
     * @since 5.3.0 Also handles per_order (single winner) — Frontend Manager delegates here.
     * @param array $cart_items Cart items from WC()->cart->get_cart().
     * @return array {
     *   aggregation_mode: string,
     *   total_amount:     float,
     *   has_hold:         bool,
     *   source:           string  (per_order only)
     *   source_label:     string  (per_order only)
     *   timing:           string  (per_order only)
     *   item_breakdown:   array   (per_item_aggregated only)
     * }
     */
    public static function compute_for_cart( $cart_items ) {
        self::load_dependencies();

        $no_hold = array(
            'aggregation_mode' => get_option( 'securehold_aggregation_mode', 'per_order' ),
            'total_amount'     => 0,
            'has_hold'         => false,
            'source'           => '',
            'source_label'     => '',
            'timing'           => '',
        );

        // ── Minimum Cart Amount threshold ──
        $min_cart = floatval( get_option( 'securehold_min_cart_amount', '' ) );
        if ( $min_cart > 0 && function_exists( 'WC' ) && WC()->cart ) {
            $cart_total = floatval( WC()->cart->get_total( 'edit' ) );
            if ( $cart_total < $min_cart ) {
                return $no_hold;
            }
        }

        // ── Exclusion filter ──
        $cart_items = self::filter_excluded_items( $cart_items );
        if ( empty( $cart_items ) ) {
            return $no_hold;
        }

        $mode = get_option( 'securehold_aggregation_mode', 'per_order' );

        if ( 'per_item_aggregated' === $mode ) {
            return self::compute_for_cart_per_item( $cart_items );
        }

        return self::compute_for_cart_per_order( $cart_items );
    }

    /**
     * Per Order cart preview: single winning rule determines the deposit.
     *
     * @since 5.3.0
     * @param array $cart_items
     * @return array
     */
    private static function compute_for_cart_per_order( $cart_items ) {
        $policy           = Securehold_Config_Resolver::get_active_policy();
        $engine           = Securehold_Config_Resolver::get_engine_version();
        $strategy_priority = Securehold_Config_Resolver::get_strategy_priority();
        $type_priority     = Securehold_Config_Resolver::get_type_priority();

        $empty = array(
            'aggregation_mode' => 'per_order',
            'total_amount'     => 0,
            'has_hold'         => false,
            'source'           => '',
            'source_label'     => '',
            'timing'           => '',
        );

        if ( empty( $cart_items ) ) {
            return $empty;
        }

        // ── Highest Deposit Wins: collect all candidates, pick highest ──
        if ( 'highest_deposit_wins' === $policy ) {
            return self::compute_for_cart_hdw( $cart_items, $strategy_priority, $type_priority );
        }

        // ── Priority Chain ──
        return self::compute_for_cart_priority_chain( $cart_items, $engine, $strategy_priority );
    }

    /**
     * Priority Chain resolution for cart context.
     *
     * V2: deterministic — collect all product rule candidates, pick best.
     * Legacy: first product rule match wins.
     *
     * @since 5.3.0
     */
    private static function compute_for_cart_priority_chain( $cart_items, $engine, $strategy_priority ) {
        // Pass 1: Product Rules — premium (PRO Rule Engine) only. Skip entirely
        // when the Rule Engine is not active — even if legacy rule data still
        // exists in the database (e.g. PRO deactivated).
        if ( securehold_rule_engine_enabled() ) {
            if ( 'v2' === $engine ) {
                $product_candidates = array();
                foreach ( $cart_items as $cart_item ) {
                    $product_id = $cart_item['product_id'];
                    if ( get_post_meta( $product_id, '_securehold_enabled', true ) !== 'yes' ) {
                        continue;
                    }
                    $product_timing = get_post_meta( $product_id, '_securehold_capture_timing', true );
                    if ( empty( $product_timing ) ) {
                        continue;
                    }
                    $config = Securehold_Config_Resolver::resolve_for_product( $product_id );
                    $amount = self::extract_display_amount( $config );
                    $prio   = isset( $strategy_priority[ $config['timing'] ] ) ? $strategy_priority[ $config['timing'] ] : 99;
                    $product_candidates[] = array(
                        'amount'       => $amount,
                        'prio'         => $prio,
                        'product_id'   => $product_id,
                        'timing'       => isset( $config['timing'] ) ? $config['timing'] : 'immediate',
                        'source_label' => isset( $config['source_label'] ) ? $config['source_label'] : '',
                    );
                }

                if ( ! empty( $product_candidates ) ) {
                    // Pick best: amount DESC → strategy ASC → id ASC.
                    $best = $product_candidates[0];
                    for ( $i = 1; $i < count( $product_candidates ); $i++ ) {
                        $c = $product_candidates[ $i ];
                        if ( $c['amount'] > $best['amount']
                            || ( $c['amount'] === $best['amount'] && $c['prio'] < $best['prio'] )
                            || ( $c['amount'] === $best['amount'] && $c['prio'] === $best['prio'] && $c['product_id'] < $best['product_id'] )
                        ) {
                            $best = $c;
                        }
                    }
                    if ( $best['amount'] > 0 ) {
                        return array(
                            'aggregation_mode' => 'per_order',
                            'total_amount'     => $best['amount'],
                            'has_hold'         => true,
                            'source'           => 'product_rule',
                            'source_label'     => $best['source_label'],
                            'timing'           => $best['timing'],
                        );
                    }
                }
            } else {
                // Legacy: first product rule match wins.
                foreach ( $cart_items as $cart_item ) {
                    $product_id = $cart_item['product_id'];
                    if ( get_post_meta( $product_id, '_securehold_enabled', true ) !== 'yes' ) {
                        continue;
                    }
                    $product_timing = get_post_meta( $product_id, '_securehold_capture_timing', true );
                    if ( empty( $product_timing ) ) {
                        continue;
                    }
                    $config = Securehold_Config_Resolver::resolve_for_product( $product_id );
                    $amount = self::extract_display_amount( $config );
                    if ( $amount > 0 ) {
                        return array(
                            'aggregation_mode' => 'per_order',
                            'total_amount'     => $amount,
                            'has_hold'         => true,
                            'source'           => 'product_rule',
                            'source_label'     => isset( $config['source_label'] ) ? $config['source_label'] : '',
                            'timing'           => isset( $config['timing'] ) ? $config['timing'] : 'immediate',
                        );
                    }
                }
            }
        }

        // Pass 1.5: MagePeople deposits (FREE, opt-in bridge) — independent of
        // the PRO Rule Engine, gated only by its own
        // securehold_magepeople_deposit_enabled option (checked inside
        // resolve_for_product() / get_magepeople_settings()). Only reached
        // when no product rule matched above.
        if ( 'v2' === $engine ) {
            // v2 deterministic winner selection.
            $mp_candidates = array();
            foreach ( $cart_items as $cart_item ) {
                $product_id = $cart_item['product_id'];
                $config     = Securehold_Config_Resolver::resolve_for_product( $product_id );
                if ( ! isset( $config['source'] ) || 'magepeople_deposit' !== $config['source'] ) {
                    continue;
                }
                $amount = self::extract_display_amount( $config );
                $timing = isset( $config['timing'] ) ? $config['timing'] : 'immediate';
                $mp_candidates[] = array(
                    'amount'       => $amount,
                    'prio'         => isset( $strategy_priority[ $timing ] ) ? $strategy_priority[ $timing ] : 99,
                    'product_id'   => $product_id,
                    'timing'       => $timing,
                    'source_label' => isset( $config['source_label'] ) ? $config['source_label'] : '',
                );
            }
            if ( ! empty( $mp_candidates ) ) {
                $best = $mp_candidates[0];
                for ( $i = 1; $i < count( $mp_candidates ); $i++ ) {
                    $c = $mp_candidates[ $i ];
                    if ( $c['amount'] > $best['amount']
                        || ( $c['amount'] === $best['amount'] && $c['prio'] < $best['prio'] )
                        || ( $c['amount'] === $best['amount'] && $c['prio'] === $best['prio'] && $c['product_id'] < $best['product_id'] )
                    ) {
                        $best = $c;
                    }
                }
                if ( $best['amount'] > 0 ) {
                    return array(
                        'aggregation_mode' => 'per_order',
                        'total_amount'     => $best['amount'],
                        'has_hold'         => true,
                        'source'           => 'magepeople_deposit',
                        'source_label'     => $best['source_label'],
                        'timing'           => $best['timing'],
                    );
                }
            }
        } else {
            // Legacy: first match wins, mirroring the legacy product-rule
            // loop above.
            foreach ( $cart_items as $cart_item ) {
                $product_id = $cart_item['product_id'];
                $config     = Securehold_Config_Resolver::resolve_for_product( $product_id );
                if ( ! isset( $config['source'] ) || 'magepeople_deposit' !== $config['source'] ) {
                    continue;
                }
                $amount = self::extract_display_amount( $config );
                if ( $amount > 0 ) {
                    return array(
                        'aggregation_mode' => 'per_order',
                        'total_amount'     => $amount,
                        'has_hold'         => true,
                        'source'           => 'magepeople_deposit',
                        'source_label'     => isset( $config['source_label'] ) ? $config['source_label'] : '',
                        'timing'           => isset( $config['timing'] ) ? $config['timing'] : 'immediate',
                    );
                }
            }
        }

        // Pass 2: Category Rules — premium (PRO Rule Engine) only. Collect all
        // matching, pick best.
        if ( securehold_rule_engine_enabled() ) {
        $all_category_rules = get_option( 'securehold_category_rules', array() );
        if ( is_array( $all_category_rules ) && ! empty( $all_category_rules ) ) {
            $seen_term_ids = array();
            $candidates    = array();

            foreach ( $cart_items as $cart_item ) {
                $product_id = $cart_item['product_id'];
                $terms      = wp_get_object_terms( $product_id, 'product_cat', array(
                    'fields'  => 'ids',
                    'orderby' => 'term_id',
                    'order'   => 'ASC',
                ) );
                if ( is_wp_error( $terms ) || empty( $terms ) ) {
                    continue;
                }
                foreach ( $terms as $tid ) {
                    if ( isset( $seen_term_ids[ $tid ] ) ) {
                        continue;
                    }
                    $seen_term_ids[ $tid ] = true;
                    if ( isset( $all_category_rules[ $tid ] ) && ! empty( $all_category_rules[ $tid ]['capture_timing'] ) ) {
                        $candidates[] = array(
                            'tid'      => $tid,
                            'settings' => $all_category_rules[ $tid ],
                        );
                    }
                }
            }

            if ( ! empty( $candidates ) ) {
                $winner_settings = null;
                $winner_amount   = -1;
                $winner_prio     = PHP_INT_MAX;
                $winner_tid      = 0;

                foreach ( $candidates as $c ) {
                    $raw     = isset( $c['settings']['deposit_amount'] ) ? $c['settings']['deposit_amount'] : '';
                    $numeric = self::parse_amount_for_comparison( $raw );
                    $strat   = isset( $c['settings']['capture_timing'] ) ? $c['settings']['capture_timing'] : 'manual';
                    $prio    = isset( $strategy_priority[ $strat ] ) ? $strategy_priority[ $strat ] : 99;

                    if ( $numeric > $winner_amount || ( $numeric === $winner_amount && $prio < $winner_prio ) ) {
                        $winner_settings = $c['settings'];
                        $winner_amount   = $numeric;
                        $winner_prio     = $prio;
                        $winner_tid      = $c['tid'];
                    }
                }

                if ( null !== $winner_settings ) {
                    $amount = self::parse_amount_for_comparison(
                        isset( $winner_settings['deposit_amount'] ) ? $winner_settings['deposit_amount'] : ''
                    );
                    if ( $amount > 0 ) {
                        $term  = get_term( $winner_tid, 'product_cat' );
                        $label = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $winner_tid;
                        return array(
                            'aggregation_mode' => 'per_order',
                            'total_amount'     => $amount,
                            'has_hold'         => true,
                            'source'           => 'category_rule',
                            'source_label'     => $label,
                            'timing'           => isset( $winner_settings['capture_timing'] ) ? $winner_settings['capture_timing'] : 'immediate',
                        );
                    }
                }
            }
        }
        } // end securehold_rule_engine_enabled() — Category Rules

        // Pass 3: Global.
        $global_raw    = get_option( 'securehold_default_hold_amount', '300' );
        $global_amount = self::parse_amount_for_comparison( $global_raw );

        if ( $global_amount > 0 ) {
            return array(
                'aggregation_mode' => 'per_order',
                'total_amount'     => $global_amount,
                'has_hold'         => true,
                'source'           => 'global',
                'source_label'     => 'Global',
                'timing'           => get_option( 'securehold_capture_timing', 'immediate' ),
            );
        }

        return array(
            'aggregation_mode' => 'per_order',
            'total_amount'     => 0,
            'has_hold'         => false,
            'source'           => '',
            'source_label'     => '',
            'timing'           => '',
        );
    }

    /**
     * Highest Deposit Wins resolution for cart context.
     *
     * Collects all candidates (product + category + global) and picks the
     * one with the highest face-value amount.
     *
     * @since 5.3.0
     */
    private static function compute_for_cart_hdw( $cart_items, $strategy_priority, $type_priority ) {
        $best_amount = -1;
        $best_prio   = PHP_INT_MAX;
        $best_type   = PHP_INT_MAX;
        $best_id     = PHP_INT_MAX;
        $best_source = '';
        $best_label  = '';
        $best_timing = '';

        // Product/Category rules are premium (PRO Rule Engine). Skip collecting
        // them entirely when the Rule Engine is not active — even if legacy rule
        // data still exists in the database (e.g. PRO deactivated).
        if ( securehold_rule_engine_enabled() ) {
            // Product rules.
            foreach ( $cart_items as $cart_item ) {
                $product_id = $cart_item['product_id'];
                if ( get_post_meta( $product_id, '_securehold_enabled', true ) !== 'yes' ) {
                    continue;
                }
                $product_timing = get_post_meta( $product_id, '_securehold_capture_timing', true );
                if ( empty( $product_timing ) ) {
                    continue;
                }
                $config = Securehold_Config_Resolver::resolve_for_product( $product_id );
                $amount = self::extract_display_amount( $config );
                $strat  = isset( $config['timing'] ) ? $config['timing'] : 'manual';
                $prio   = isset( $strategy_priority[ $strat ] ) ? $strategy_priority[ $strat ] : 99;
                $tp     = $type_priority['product_rule'];

                if ( self::hdw_is_better( $amount, $prio, $tp, $product_id, $best_amount, $best_prio, $best_type, $best_id ) ) {
                    $best_amount = $amount;
                    $best_prio   = $prio;
                    $best_type   = $tp;
                    $best_id     = $product_id;
                    $best_source = 'product_rule';
                    $best_label  = isset( $config['source_label'] ) ? $config['source_label'] : '';
                    $best_timing = $strat;
                }
            }

            // Category rules.
            $all_category_rules = get_option( 'securehold_category_rules', array() );
            if ( is_array( $all_category_rules ) && ! empty( $all_category_rules ) ) {
                $seen_term_ids = array();
                foreach ( $cart_items as $cart_item ) {
                    $product_id = $cart_item['product_id'];
                    $terms      = wp_get_object_terms( $product_id, 'product_cat', array(
                        'fields'  => 'ids',
                        'orderby' => 'term_id',
                        'order'   => 'ASC',
                    ) );
                    if ( is_wp_error( $terms ) || empty( $terms ) ) {
                        continue;
                    }
                    foreach ( $terms as $tid ) {
                        if ( isset( $seen_term_ids[ $tid ] ) ) {
                            continue;
                        }
                        $seen_term_ids[ $tid ] = true;
                        if ( ! isset( $all_category_rules[ $tid ] ) || empty( $all_category_rules[ $tid ]['capture_timing'] ) ) {
                            continue;
                        }
                        $raw    = isset( $all_category_rules[ $tid ]['deposit_amount'] ) ? $all_category_rules[ $tid ]['deposit_amount'] : '';
                        $amount = self::parse_amount_for_comparison( $raw );
                        $strat  = $all_category_rules[ $tid ]['capture_timing'];
                        $prio   = isset( $strategy_priority[ $strat ] ) ? $strategy_priority[ $strat ] : 99;
                        $tp     = $type_priority['category_rule'];

                        if ( self::hdw_is_better( $amount, $prio, $tp, (int) $tid, $best_amount, $best_prio, $best_type, $best_id ) ) {
                            $best_amount = $amount;
                            $best_prio   = $prio;
                            $best_type   = $tp;
                            $best_id     = (int) $tid;
                            $best_source = 'category_rule';
                            $term        = get_term( $tid, 'product_cat' );
                            $best_label  = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $tid;
                            $best_timing = $strat;
                        }
                    }
                }
            }
        }

        // Global.
        $global_raw    = get_option( 'securehold_default_hold_amount', '300' );
        $global_amount = self::parse_amount_for_comparison( $global_raw );
        $global_strat  = get_option( 'securehold_capture_timing', 'immediate' );
        $global_prio   = isset( $strategy_priority[ $global_strat ] ) ? $strategy_priority[ $global_strat ] : 99;
        $global_tp     = $type_priority['global'];

        if ( self::hdw_is_better( $global_amount, $global_prio, $global_tp, 0, $best_amount, $best_prio, $best_type, $best_id ) ) {
            $best_amount = $global_amount;
            $best_source = 'global';
            $best_label  = 'Global';
            $best_timing = $global_strat;
        }

        if ( $best_amount > 0 ) {
            return array(
                'aggregation_mode' => 'per_order',
                'total_amount'     => $best_amount,
                'has_hold'         => true,
                'source'           => $best_source,
                'source_label'     => $best_label,
                'timing'           => $best_timing,
            );
        }

        return array(
            'aggregation_mode' => 'per_order',
            'total_amount'     => 0,
            'has_hold'         => false,
            'source'           => '',
            'source_label'     => '',
            'timing'           => '',
        );
    }

    /**
     * Determine if a candidate is better than the current best (HDW cart context).
     * amount DESC → strategy_prio ASC → type_prio ASC → id ASC.
     *
     * @since 5.3.0
     */
    private static function hdw_is_better( $amount, $prio, $type_prio, $id, $best_amount, $best_prio, $best_type, $best_id ) {
        if ( $amount > $best_amount ) return true;
        if ( $amount < $best_amount ) return false;
        if ( $prio < $best_prio )     return true;
        if ( $prio > $best_prio )     return false;
        if ( $type_prio < $best_type ) return true;
        if ( $type_prio > $best_type ) return false;
        return $id < $best_id;
    }

    /**
     * Per Item Aggregated cart preview.
     *
     * Uses the same policy-aware resolve_item_config() as the order engine,
     * so the checkout message matches the actual hold amount.
     *
     * With null $order, percentages resolve to face value via extract_display_amount().
     *
     * @since 5.2.0
     * @since 5.3.1 Fixed: now respects active policy (HDW) per item — was always Priority Chain.
     * @param array $cart_items
     * @return array
     */
    private static function compute_for_cart_per_item( $cart_items ) {
        $policy = Securehold_Config_Resolver::get_active_policy();
        $engine = Securehold_Config_Resolver::get_engine_version();

        $total          = 0;
        $item_breakdown = array();
        $has_hold       = false;

        foreach ( $cart_items as $cart_item ) {
            $product_id = $cart_item['product_id'];
            $qty        = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 1;
            $product    = wc_get_product( $product_id );
            $name       = $product ? $product->get_name() : '#' . $product_id;

            // Policy-aware resolution — same path as the order engine.
            // null order → percentages fall back to face value via extract_display_amount().
            $item_config  = self::resolve_item_config( $product_id, null, $policy, $engine );
            $item_amount  = self::extract_display_amount( $item_config );
            $contribution = $item_amount * $qty;

            if ( $item_amount > 0 ) {
                $has_hold = true;
            }

            $total += $contribution;

            $item_breakdown[] = array(
                'product_id'   => $product_id,
                'product_name' => $name,
                'qty'          => $qty,
                'unit_amount'  => $item_amount,
                'contribution' => $contribution,
                'timing'       => isset( $item_config['timing'] ) ? $item_config['timing'] : 'immediate',
                'source'       => isset( $item_config['source'] ) ? $item_config['source'] : 'global',
                'source_label' => isset( $item_config['source_label'] ) ? $item_config['source_label'] : 'Global',
            );
        }

        return array(
            'aggregation_mode' => 'per_item_aggregated',
            'total_amount'     => $total,
            'item_breakdown'   => $item_breakdown,
            'has_hold'         => $has_hold,
        );
    }

    /**
     * Per-Item Aggregated computation with full order context.
     *
     * For each line item:
     *   1. Resolve via Config Resolver (Product > Category > Global)
     *   2. Calculate contribution = resolved_amount * qty
     *   3. Track strategy restrictiveness
     *
     * Final strategy = most restrictive across all items.
     * Final strategy parameters = from the "winner item" (most restrictive).
     *
     * @param WC_Order $order
     * @return array Config Resolver-compatible result.
     */
    private static function compute_per_item_aggregated( $order ) {
        $items            = $order->get_items();
        $item_breakdown   = array();
        $total_amount     = 0;
        $winner_item      = null;
        $winner_prio      = PHP_INT_MAX;
        $strategy_table   = self::get_strategy_priority();
        $policy           = Securehold_Config_Resolver::get_active_policy();
        $engine           = Securehold_Config_Resolver::get_engine_version();

        // ── Exclusion counters for debug note ──
        $included_count = 0;
        $excluded_count = 0;

        foreach ( $items as $item ) {
            $product_id = $item->get_product_id();

            // ── Skip excluded products/categories (mirrors compute_for_cart behaviour) ──
            if ( self::is_product_excluded( $product_id ) ) {
                $excluded_count++;
                continue;
            }
            $included_count++;

            $qty        = $item->get_quantity();
            $product    = wc_get_product( $product_id );
            $name       = $product ? $product->get_name() : '#' . $product_id;

            // V2: resolve respecting the active policy per item.
            // Legacy: always uses Product > Category > Global chain.
            $item_config    = self::resolve_item_config( $product_id, $order, $policy, $engine );
            $unit_amount    = (float) $item_config['deposit_amount_resolved'];
            $contribution   = $unit_amount * $qty;
            $item_timing    = $item_config['timing'];
            $item_prio      = isset( $strategy_table[ $item_timing ] )
                ? $strategy_table[ $item_timing ]
                : 99;

            $total_amount += $contribution;

            $breakdown_entry = array(
                'product_id'   => $product_id,
                'product_name' => $name,
                'qty'          => $qty,
                'unit_amount'  => $unit_amount,
                'contribution' => $contribution,
                'timing'       => $item_timing,
                'source'       => $item_config['source'],
                'source_id'    => $item_config['source_id'],
                'source_label' => $item_config['source_label'],
            );
            $item_breakdown[] = $breakdown_entry;

            // Track the most restrictive strategy (lowest priority number wins).
            if ( $item_prio < $winner_prio ) {
                $winner_prio = $item_prio;
                $winner_item = array(
                    'config'    => $item_config,
                    'breakdown' => $breakdown_entry,
                );
            }
        }

        // If no items resolved, fallback to global.
        if ( null === $winner_item ) {
            $config = Securehold_Config_Resolver::resolve_final_deposit_configuration( $order );
            $config['aggregation_mode'] = 'per_item_aggregated';
            $config['item_breakdown']   = $item_breakdown;
            $config['winner_item']      = null;
            return $config;
        }

        // Build the final result using the winner item's config as the base
        // (all strategy parameters come from the winner item).
        $winner_config = $winner_item['config'];

        $result = array(
            'source'                  => $winner_config['source'],
            'source_id'               => $winner_config['source_id'],
            'source_label'            => $winner_config['source_label'],
            'timing'                  => $winner_config['timing'],
            'deposit_amount'          => (string) round( $total_amount, 2 ),
            'deposit_amount_resolved' => round( $total_amount, 2 ),
            'delay_days'              => $winner_config['delay_days'],
            'date_field_key'          => $winner_config['date_field_key'],
            'scheduled_days'          => $winner_config['scheduled_days'],
            'scheduled_direction'     => $winner_config['scheduled_direction'],
            'trigger_status'          => $winner_config['trigger_status'],
            'fallbacks'               => isset( $winner_config['fallbacks'] ) ? $winner_config['fallbacks'] : array(),
            'conflict_info'           => null,
            'policy'                  => $policy,
            'engine_version'          => $engine,
            'explain'                 => null,
            'aggregation_mode'        => 'per_item_aggregated',
            'item_breakdown'          => $item_breakdown,
            'winner_item'             => $winner_item['breakdown'],
        );

        // Debug log
        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Aggregation mode: per_item_aggregated', array(
                'order_id'        => $order->get_id(),
                'total'           => $total_amount,
                'winner_strategy' => $winner_config['timing'],
                'items_count'     => count( $item_breakdown ),
                'included_items'  => $included_count,
                'excluded_items'  => $excluded_count,
            ), 'debug' );
        }

        // WP_DEBUG order note: included/excluded breakdown and final amount.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $excluded_count > 0 ) {
            $order->add_order_note( sprintf(
                'SecureHold DEBUG (per_item_aggregated): included_items=%d excluded_items=%d final_amount=%s',
                $included_count,
                $excluded_count,
                round( $total_amount, 2 )
            ) );
        }

        return $result;
    }

    /**
     * Resolve configuration for a single product item within an order.
     *
     * Legacy: always uses Product > Category > Global chain (Priority Chain).
     * V2: respects the active policy per item:
     *   - priority_chain: Product > Category > Global (deterministic tie-break).
     *   - highest_deposit_wins: all candidates compete on amount.
     *
     * @since 5.2.0 Added $policy and $engine parameters for V2 support.
     * @param int      $product_id
     * @param WC_Order $order
     * @param string   $policy 'priority_chain' | 'highest_deposit_wins'
     * @param string   $engine 'legacy' | 'v2'
     * @return array Config Resolver result structure.
     */
    private static function resolve_item_config( $product_id, $order, $policy = 'priority_chain', $engine = 'legacy' ) {
        $strategy_table = self::get_strategy_priority();
        $type_priority  = Securehold_Config_Resolver::get_type_priority();

        if ( ! class_exists( 'Securehold_Product_Settings' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-product-settings.php';
        }

        // Product Rule and Category Rule are premium (PRO Rule Engine) candidates.
        // When the Rule Engine is not active, skip straight to Global — even if
        // legacy rule data still exists in the database (e.g. PRO deactivated).
        if ( ! securehold_rule_engine_enabled() ) {
            return self::build_item_result_global( $order );
        }

        // ── V2 + Highest Deposit Wins: collect ALL candidates for this item ──
        if ( 'v2' === $engine && 'highest_deposit_wins' === $policy ) {
            $candidates = array();

            // Product rule candidate.
            $product_enabled = get_post_meta( $product_id, '_securehold_enabled', true );
            if ( $product_enabled === 'yes' ) {
                $product_timing = get_post_meta( $product_id, '_securehold_capture_timing', true );
                if ( ! empty( $product_timing ) ) {
                    $settings = Securehold_Product_Settings::get_settings( $product_id );
                    $raw      = isset( $settings['deposit_amount'] ) ? $settings['deposit_amount'] : '';
                    $candidates[] = array(
                        'type'            => 'product_rule',
                        'id'              => $product_id,
                        'label'           => wc_get_product( $product_id ) ? wc_get_product( $product_id )->get_name() : '#' . $product_id,
                        'settings'        => $settings,
                        'amount_resolved' => self::resolve_amount( $raw, $order ),
                        'strategy_prio'   => isset( $strategy_table[ $product_timing ] ) ? $strategy_table[ $product_timing ] : 99,
                        'type_prio'       => $type_priority['product_rule'],
                    );
                }
            }

            // Category rule candidates.
            $all_rules = get_option( 'securehold_category_rules', array() );
            if ( is_array( $all_rules ) && ! empty( $all_rules ) ) {
                $terms = wp_get_object_terms( $product_id, 'product_cat', array(
                    'fields'  => 'ids',
                    'orderby' => 'term_id',
                    'order'   => 'ASC',
                ) );
                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    foreach ( $terms as $tid ) {
                        if ( ! isset( $all_rules[ $tid ] ) || empty( $all_rules[ $tid ]['capture_timing'] ) ) {
                            continue;
                        }
                        $settings = $all_rules[ $tid ];
                        $raw      = isset( $settings['deposit_amount'] ) ? $settings['deposit_amount'] : '';
                        $strat    = $settings['capture_timing'];
                        $term     = get_term( $tid, 'product_cat' );
                        $name     = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $tid;
                        $candidates[] = array(
                            'type'            => 'category_rule',
                            'id'              => (int) $tid,
                            'label'           => $name,
                            'settings'        => $settings,
                            'amount_resolved' => self::resolve_amount( $raw, $order ),
                            'strategy_prio'   => isset( $strategy_table[ $strat ] ) ? $strategy_table[ $strat ] : 99,
                            'type_prio'       => $type_priority['category_rule'],
                        );
                    }
                }
            }

            // Global candidate.
            $global = self::get_global_values();
            $candidates[] = array(
                'type'            => 'global',
                'id'              => 0,
                'label'           => 'Global',
                'settings'        => $global,
                'amount_resolved' => self::resolve_amount( $global['deposit_amount'], $order ),
                'strategy_prio'   => isset( $strategy_table[ $global['capture_timing'] ] ) ? $strategy_table[ $global['capture_timing'] ] : 99,
                'type_prio'       => $type_priority['global'],
            );

            // Pick winner: highest amount → strategy prio → type prio → id.
            $winner = $candidates[0];
            for ( $i = 1; $i < count( $candidates ); $i++ ) {
                $c = $candidates[ $i ];
                if ( self::compare_hdw_item( $winner, $c ) < 0 ) {
                    $winner = $c;
                }
            }

            if ( 'global' === $winner['type'] ) {
                return self::build_item_result_global( $order );
            }
            return self::build_item_result( $winner['type'], $winner['id'], $winner['label'], $winner['settings'], $order );
        }

        // ── Priority Chain (legacy + V2): Product > Category > Global ──
        $product_enabled = get_post_meta( $product_id, '_securehold_enabled', true );
        if ( $product_enabled === 'yes' ) {
            $product_timing = get_post_meta( $product_id, '_securehold_capture_timing', true );
            if ( ! empty( $product_timing ) ) {
                $settings = Securehold_Product_Settings::get_settings( $product_id );
                return self::build_item_result( 'product_rule', $product_id, wc_get_product( $product_id ) ? wc_get_product( $product_id )->get_name() : '#' . $product_id, $settings, $order );
            }
        }

        // Check category rules.
        $all_rules = get_option( 'securehold_category_rules', array() );
        if ( is_array( $all_rules ) && ! empty( $all_rules ) ) {
            $terms = wp_get_object_terms( $product_id, 'product_cat', array(
                'fields'  => 'ids',
                'orderby' => 'term_id',
                'order'   => 'ASC',
            ) );

            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $best_candidate = null;
                $best_amount    = -1;
                $best_prio      = PHP_INT_MAX;

                foreach ( $terms as $tid ) {
                    if ( ! isset( $all_rules[ $tid ] ) || empty( $all_rules[ $tid ]['capture_timing'] ) ) {
                        continue;
                    }
                    $settings = $all_rules[ $tid ];
                    $raw      = isset( $settings['deposit_amount'] ) ? $settings['deposit_amount'] : '';
                    $numeric  = self::parse_amount_for_comparison( $raw );
                    $strat    = $settings['capture_timing'];
                    $prio     = isset( $strategy_table[ $strat ] ) ? $strategy_table[ $strat ] : 99;

                    if ( $numeric > $best_amount || ( $numeric === $best_amount && $prio < $best_prio ) ) {
                        $best_candidate = array( 'tid' => $tid, 'settings' => $settings );
                        $best_amount    = $numeric;
                        $best_prio      = $prio;
                    }
                }

                if ( null !== $best_candidate ) {
                    $term = get_term( $best_candidate['tid'], 'product_cat' );
                    $name = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $best_candidate['tid'];
                    return self::build_item_result( 'category_rule', $best_candidate['tid'], $name, $best_candidate['settings'], $order );
                }
            }
        }

        // Global fallback.
        return self::build_item_result_global( $order );
    }

    /**
     * Compare two candidates for HDW item-level resolution.
     * Returns < 0 if $b should replace $a.
     *
     * @since 5.2.0
     */
    private static function compare_hdw_item( $a, $b ) {
        // 1. Highest amount.
        $diff = (float) $a['amount_resolved'] - (float) $b['amount_resolved'];
        if ( abs( $diff ) > 0.001 ) {
            return ( $diff > 0 ) ? 1 : -1;
        }
        // 2. Strategy priority.
        $sp = $a['strategy_prio'] - $b['strategy_prio'];
        if ( 0 !== $sp ) {
            return ( $sp < 0 ) ? 1 : -1;
        }
        // 3. Type priority.
        $tp = $a['type_prio'] - $b['type_prio'];
        if ( 0 !== $tp ) {
            return ( $tp < 0 ) ? 1 : -1;
        }
        // 4. ID ascending.
        $id = $a['id'] - $b['id'];
        if ( 0 !== $id ) {
            return ( $id < 0 ) ? 1 : -1;
        }
        return 1;
    }

    /**
     * Build a result array from a winning source's settings (item-level).
     * Mirrors Config Resolver::build_result() but for per-item context.
     */
    private static function build_item_result( $source, $source_id, $source_label, $settings, $order ) {
        $global = self::get_global_values();

        $timing = ! empty( $settings['capture_timing'] ) ? $settings['capture_timing'] : $global['capture_timing'];

        $deposit_amount = ( isset( $settings['deposit_amount'] ) && $settings['deposit_amount'] !== '' )
            ? $settings['deposit_amount']
            : $global['deposit_amount'];

        $delay_days = ( isset( $settings['delay_days'] ) && $settings['delay_days'] !== '' )
            ? $settings['delay_days']
            : $global['delay_days'];

        $date_field_key = ( isset( $settings['date_field_key'] ) && $settings['date_field_key'] !== '' )
            ? $settings['date_field_key']
            : $global['date_field_key'];

        $scheduled_days = ( isset( $settings['scheduled_days'] ) && $settings['scheduled_days'] !== '' )
            ? $settings['scheduled_days']
            : $global['scheduled_days'];

        $scheduled_direction = ( isset( $settings['scheduled_direction'] ) && $settings['scheduled_direction'] !== '' )
            ? $settings['scheduled_direction']
            : $global['scheduled_direction'];

        $trigger_status = ( isset( $settings['trigger_status'] ) && $settings['trigger_status'] !== '' )
            ? $settings['trigger_status']
            : $global['trigger_status'];

        return array(
            'source'                  => $source,
            'source_id'               => (int) $source_id,
            'source_label'            => $source_label,
            'timing'                  => $timing,
            'deposit_amount'          => $deposit_amount,
            'deposit_amount_resolved' => self::resolve_amount( $deposit_amount, $order ),
            'delay_days'              => $delay_days,
            'date_field_key'          => $date_field_key,
            'scheduled_days'          => $scheduled_days,
            'scheduled_direction'     => $scheduled_direction,
            'trigger_status'          => $trigger_status,
            'fallbacks'               => array(),
        );
    }

    /**
     * Build a global fallback result (item-level).
     */
    private static function build_item_result_global( $order ) {
        $global = self::get_global_values();
        return array(
            'source'                  => 'global',
            'source_id'               => 0,
            'source_label'            => 'Global',
            'timing'                  => $global['capture_timing'],
            'deposit_amount'          => $global['deposit_amount'],
            'deposit_amount_resolved' => self::resolve_amount( $global['deposit_amount'], $order ),
            'delay_days'              => $global['delay_days'],
            'date_field_key'          => $global['date_field_key'],
            'scheduled_days'          => $global['scheduled_days'],
            'scheduled_direction'     => $global['scheduled_direction'],
            'trigger_status'          => $global['trigger_status'],
            'fallbacks'               => array(),
        );
    }

    /**
     * Get global settings values.
     */
    private static function get_global_values() {
        $sched_days = get_option( 'securehold_scheduled_days_number', '' );
        $sched_dir  = get_option( 'securehold_scheduled_direction', '' );

        if ( empty( $sched_days ) && empty( $sched_dir ) ) {
            $old_days_before = (int) get_option( 'securehold_days_before_date', 1 );
            $sched_days      = (string) $old_days_before;
            $sched_dir       = 'before';
        }

        return array(
            'capture_timing'      => get_option( 'securehold_capture_timing', 'immediate' ),
            'deposit_amount'      => get_option( 'securehold_default_hold_amount', '300' ),
            'delay_days'          => get_option( 'securehold_delay_days', '3' ),
            'date_field_key'      => get_option( 'securehold_date_field_key', '_checkout_date' ),
            'scheduled_days'      => $sched_days,
            'scheduled_direction' => $sched_dir,
            'trigger_status'      => get_option( 'securehold_trigger_status', 'processing' ),
        );
    }

    /**
     * Resolve a raw deposit amount string into a numeric value.
     */
    private static function resolve_amount( $raw_amount, $order ) {
        if ( empty( $raw_amount ) ) {
            return 0;
        }
        if ( strpos( $raw_amount, '%' ) !== false ) {
            $percent     = (float) str_replace( '%', '', trim( $raw_amount ) );
            $order_total = $order ? (float) $order->get_total() : 0;
            return ( $order_total * $percent ) / 100;
        }
        return (float) $raw_amount;
    }

    /**
     * Extract display amount from a resolver config (cart context, no order).
     */
    private static function extract_display_amount( $config ) {
        $amount = isset( $config['deposit_amount_resolved'] ) ? (float) $config['deposit_amount_resolved'] : 0;
        if ( $amount <= 0 && ! empty( $config['deposit_amount'] ) ) {
            $cleaned = str_replace( '%', '', trim( $config['deposit_amount'] ) );
            $amount  = is_numeric( $cleaned ) ? (float) $cleaned : 0;
        }
        return $amount;
    }

    /**
     * Parse amount for comparison (face value).
     */
    private static function parse_amount_for_comparison( $raw ) {
        if ( empty( $raw ) ) {
            return 0;
        }
        $cleaned = str_replace( '%', '', trim( $raw ) );
        return is_numeric( $cleaned ) ? (float) $cleaned : 0;
    }

    /**
     * Load dependency classes.
     */
    private static function load_dependencies() {
        if ( ! class_exists( 'Securehold_Config_Resolver' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-config-resolver.php';
        }
        if ( ! class_exists( 'Securehold_Product_Settings' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-product-settings.php';
        }
    }
}
