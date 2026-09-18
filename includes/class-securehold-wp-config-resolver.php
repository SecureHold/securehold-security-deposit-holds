<?php
/**
 * Centralized deposit configuration resolver.
 *
 * Supports two resolution policies (configurable via Settings > Rule Engine):
 *
 *   1. **Priority Chain** (default, backward-compatible):
 *      Product Rule > Category Rule > Global Settings.
 *      First match at the highest-priority level wins. Within categories,
 *      highest amount wins (tie-break: strategy priority).
 *
 *   2. **Highest Deposit Wins** (order-level):
 *      ALL applicable candidates (product rules, category rules, global)
 *      are collected and compared on deposit_amount_resolved. The single
 *      candidate with the highest resolved amount wins the order.
 *      Tie-break: strategy priority > type priority > source_id.
 *
 * CRITICAL DESIGN RULE — Single Source Wins:
 *   Once a winning source is determined, ALL fields (amount, strategy, params)
 *   come from that SAME source. There is NEVER field-level mixing between sources.
 *   If a field is empty in the winning source, it falls back to global
 *   BUT this is explicitly tracked via the 'fallbacks' key.
 *
 * @since 3.11.0
 * @since 4.0.0  Added multi-category conflict resolution.
 * @since 4.1.0  Fixed single-source-wins (no more field-level mixing).
 * @since 4.4.0  Refactored to candidate-based architecture with resolution policies.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Config_Resolver {

    /**
     * Canonical strategy priority for conflict resolution (lower = higher priority).
     * This is the SINGLE source of truth — used by Computation Service, Simulator,
     * and Frontend Manager via get_strategy_priority().
     *
     * @since 5.2.0 Promoted to canonical shared table.
     */
    private static $strategy_priority = array(
        'immediate' => 1,
        'scheduled' => 2,
        'status'    => 3,
        'delayed'   => 4,
        'manual'    => 5,
    );

    /**
     * Type priority for cross-level tie-breaking (lower = higher priority).
     */
    private static $type_priority = array(
        'product_rule'       => 1,
        'magepeople_deposit' => 2,
        'category_rule'      => 3,
        'global'             => 4,
    );

    /**
     * Valid resolution policy identifiers.
     */
    private static $valid_policies = array( 'priority_chain', 'highest_deposit_wins' );

    /**
     * Valid engine version identifiers.
     */
    private static $valid_engine_versions = array( 'legacy', 'v2' );

    /* ================================================================
     *  PUBLIC API
     * ================================================================ */

    /**
     * Resolve the final deposit configuration for an order.
     *
     * @param WC_Order $order The WooCommerce order.
     * @return array Unified result payload (see build_final_payload()).
     */
    public static function resolve_final_deposit_configuration( $order ) {
        self::load_dependencies();

        $policy     = self::get_active_policy();
        $engine     = self::get_engine_version();
        $items      = $order->get_items();
        $candidates = self::collect_all_candidates( $items, $order );

        // V2: sort candidates deterministically so priority_chain does not
        // depend on the order items were added to the cart/order.
        if ( 'v2' === $engine ) {
            usort( $candidates, array( __CLASS__, 'compare_candidates_deterministic' ) );
        }

        // Apply the selected resolution policy.
        if ( 'highest_deposit_wins' === $policy ) {
            $result = self::apply_highest_deposit_wins( $candidates, $order );
        } else {
            $result = self::apply_priority_chain( $candidates, $order );
        }

        // Enrich with policy and explain metadata.
        $result = self::build_final_payload( $result, $policy, $candidates, $order );

        // ── Logging ──
        if ( function_exists( 'securehold_log' ) ) {
            // INFO: compact one-liner (always logged).
            securehold_log(
                sprintf(
                    'Rule Engine winner: %s %s/%s amount=%s timing=%s',
                    $policy,
                    $result['source'],
                    $result['source_id'],
                    $result['deposit_amount_resolved'],
                    $result['timing']
                ),
                array(
                    'order_id'        => $order->get_id(),
                    'policy'          => $policy,
                    'source'          => $result['source'],
                    'source_id'       => $result['source_id'],
                    'amount_resolved' => $result['deposit_amount_resolved'],
                    'timing'          => $result['timing'],
                    'fallbacks_count' => count( $result['fallbacks'] ),
                ),
                'info'
            );

            // DEBUG: full candidate list + reason.
            securehold_log( 'Config Resolver: Full resolution detail', array(
                'order_id'   => $order->get_id(),
                'policy'     => $policy,
                'candidates' => isset( $result['explain']['candidates'] ) ? $result['explain']['candidates'] : array(),
                'winner'     => $result['source'] . '/' . $result['source_id'],
                'reason'     => isset( $result['explain']['winner_reason'] ) ? $result['explain']['winner_reason'] : '',
            ), 'debug' );
        }

        /**
         * Allow PRO to modify or completely replace the resolved deposit configuration.
         *
         * PRO uses this to inject product-rule and category-rule resolution on top
         * of the FREE global fallback. The filter receives the fully-built payload
         * and must return an array in the same structure.
         *
         * @since 4.5.0
         * @param array    $result  Resolved config payload (source, amount, timing, etc.).
         * @param WC_Order $order   The WooCommerce order being resolved.
         */
        $result = apply_filters( 'securehold_resolve_config', $result, $order );

        return $result;
    }

    /**
     * Resolve configuration for a single product (cart / single-product context).
     * No order needed — checks product_id directly.
     * Always uses Priority Chain (cart context = display only, no policy override).
     *
     * @param int $product_id
     * @return array Same structure as resolve_final_deposit_configuration().
     */
    public static function resolve_for_product( $product_id ) {
        self::load_dependencies();

        // Product Rule is a premium (PRO Rule Engine) candidate. When the Rule
        // Engine is not active, skip it — even if legacy _securehold_enabled /
        // _securehold_capture_timing data still exists in the database (e.g.
        // PRO was deactivated).
        if ( securehold_rule_engine_enabled() ) {
            $product_enabled = get_post_meta( $product_id, '_securehold_enabled', true );
            if ( $product_enabled === 'yes' ) {
                $product_timing = get_post_meta( $product_id, '_securehold_capture_timing', true );
                if ( ! empty( $product_timing ) ) {
                    $settings = Securehold_Product_Settings::get_settings( $product_id );
                    $product  = wc_get_product( $product_id );
                    $label    = $product ? $product->get_formatted_name() : '#' . $product_id;
                    $result   = self::build_result( 'product_rule', $product_id, $label, $settings, null );
                    $result['conflict_info'] = null;
                    $result['policy']        = 'priority_chain';
                    $result['explain']       = null;
                    return $result;
                }
            }
        }

        // MagePeople "Booking and Rental Manager" (FREE, opt-in bridge) — gated
        // only by its own securehold_magepeople_deposit_enabled option, never by
        // the PRO Rule Engine. Only reached when no product_rule matched above.
        $mp_settings = self::get_magepeople_settings( $product_id );
        if ( null !== $mp_settings ) {
            $product = wc_get_product( $product_id );
            $label   = $product ? $product->get_formatted_name() : '#' . $product_id;
            $result  = self::build_result( 'magepeople_deposit', $product_id, $label, $mp_settings, null );
            $result['conflict_info'] = null;
            $result['policy']        = 'priority_chain';
            $result['explain']       = null;
            return $result;
        }

        // Category Rule is a premium (PRO Rule Engine) candidate (may have
        // multi-category conflict for a single product).
        if ( securehold_rule_engine_enabled() ) {
            $all_rules = get_option( 'securehold_category_rules', array() );
            if ( is_array( $all_rules ) && ! empty( $all_rules ) ) {
                $terms = wp_get_object_terms( $product_id, 'product_cat', array(
                    'fields'  => 'ids',
                    'orderby' => 'term_id',
                    'order'   => 'ASC',
                ) );

                if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                    $candidates = array();
                    foreach ( $terms as $tid ) {
                        if ( isset( $all_rules[ $tid ] ) && ! empty( $all_rules[ $tid ]['capture_timing'] ) ) {
                            $term         = get_term( $tid, 'product_cat' );
                            $candidates[] = array(
                                'term_id'  => (int) $tid,
                                'name'     => ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $tid,
                                'settings' => $all_rules[ $tid ],
                            );
                        }
                    }

                    if ( count( $candidates ) === 1 ) {
                        $c      = $candidates[0];
                        $result = self::build_result( 'category_rule', $c['term_id'], $c['name'], $c['settings'], null );
                        $result['conflict_info'] = null;
                        $result['policy']        = 'priority_chain';
                        $result['explain']       = null;
                        return $result;
                    }

                    if ( count( $candidates ) > 1 ) {
                        $result = self::resolve_category_conflicts( $candidates, null );
                        $result['policy']  = 'priority_chain';
                        $result['explain'] = null;
                        return $result;
                    }
                }
            }
        }

        // Global.
        $result = self::build_global_result( null );
        $result['conflict_info'] = null;
        $result['policy']        = 'priority_chain';
        $result['explain']       = null;
        return $result;
    }

    /**
     * Resolve configuration for a simulated cart (admin simulator).
     *
     * Unlike resolve_for_product(), this respects the active resolution policy
     * and collects candidates across ALL items, matching order-level behavior.
     * Items must implement get_product_id() — e.g. Securehold_Sim_Cart_Item.
     *
     * Percentage amounts resolve to 0 (no real order total available).
     *
     * @since 4.6.0
     * @param array $mock_items Array of objects with get_product_id() method.
     * @return array Same structure as resolve_final_deposit_configuration().
     */
    public static function resolve_for_simulated_cart( $mock_items ) {
        self::load_dependencies();

        // When premium Rule Engine is not active, return global config only.
        if ( ! securehold_rule_engine_enabled() ) {
            return self::build_global_result( null );
        }

        $policy     = self::get_active_policy();
        $candidates = self::collect_all_candidates( $mock_items, null );

        // Sort candidates deterministically so the result is independent
        // of cart item order. Without this, priority_chain's "first product
        // rule wins" depends on the order the user added products, causing
        // non-deterministic results in the simulator.
        usort( $candidates, array( __CLASS__, 'compare_candidates_deterministic' ) );

        if ( 'highest_deposit_wins' === $policy ) {
            $result = self::apply_highest_deposit_wins( $candidates, null );
        } else {
            $result = self::apply_priority_chain( $candidates, null );
        }

        return self::build_final_payload( $result, $policy, $candidates, null );
    }

    /**
     * Get the currently active resolution policy.
     *
     * @return string 'priority_chain' | 'highest_deposit_wins'
     */
    public static function get_active_policy() {
        $policy = get_option( 'securehold_resolution_policy', 'priority_chain' );
        if ( ! in_array( $policy, self::$valid_policies, true ) ) {
            return 'priority_chain';
        }
        return $policy;
    }

    /**
     * Get the canonical strategy priority table.
     *
     * This is the SINGLE source of truth for strategy ordering across the entire
     * plugin: runtime, simulator, frontend, and computation service.
     *
     * @since 5.2.0
     * @return array Map of strategy_name => priority_int (lower = more restrictive).
     */
    public static function get_strategy_priority() {
        return self::$strategy_priority;
    }

    /**
     * Get the type priority table.
     *
     * @since 5.2.0
     * @return array Map of type_name => priority_int (lower = higher priority).
     */
    public static function get_type_priority() {
        return self::$type_priority;
    }

    /**
     * Get the active resolution engine version.
     *
     * @since 5.2.0
     * @return string 'legacy' | 'v2'
     */
    public static function get_engine_version() {
        $version = get_option( 'securehold_engine_version', 'v2' );
        if ( ! in_array( $version, self::$valid_engine_versions, true ) ) {
            return 'v2';
        }
        return $version;
    }

    /* ================================================================
     *  CANDIDATE COLLECTION
     * ================================================================ */

    /**
     * Collect ALL applicable candidates across all order items.
     *
     * Returns a normalized array of candidates:
     *   - All matching product rules (one per enabled product with timing).
     *   - All matching category rules (deduplicated by term_id).
     *   - Global settings (always present as the last candidate).
     *
     * Each candidate is a lightweight array suitable for comparison:
     *   array(
     *     'type'            => 'product_rule'|'category_rule'|'global',
     *     'id'              => int|0,
     *     'label'           => string,
     *     'settings'        => array (raw rule settings),
     *     'amount_resolved' => float (resolved against order total),
     *     'amount_raw'      => string,
     *     'timing'          => string,
     *     'strategy_prio'   => int,
     *     'type_prio'       => int,
     *   )
     *
     * @param array         $items WC_Order_Item array.
     * @param WC_Order|null $order Order for percentage resolution.
     * @return array
     */
    private static function collect_all_candidates( $items, $order ) {
        $candidates = array();

        // Product Rule candidates are premium (PRO Rule Engine). Skip collecting
        // them entirely when the Rule Engine is not active — even if legacy rule
        // data still exists in the database (e.g. PRO deactivated).
        if ( securehold_rule_engine_enabled() ) {
            foreach ( $items as $item ) {
                $pid = $item->get_product_id();
                if ( get_post_meta( $pid, '_securehold_enabled', true ) !== 'yes' ) {
                    continue;
                }
                $product_timing = get_post_meta( $pid, '_securehold_capture_timing', true );
                if ( empty( $product_timing ) ) {
                    continue;
                }

                $settings  = Securehold_Product_Settings::get_settings( $pid );
                $product   = wc_get_product( $pid );
                $label     = $product ? $product->get_formatted_name() : '#' . $pid;
                $raw       = isset( $settings['deposit_amount'] ) ? $settings['deposit_amount'] : '';
                $timing    = ! empty( $settings['capture_timing'] ) ? $settings['capture_timing'] : 'immediate';

                $candidates[] = array(
                    'type'            => 'product_rule',
                    'id'              => (int) $pid,
                    'label'           => $label,
                    'settings'        => $settings,
                    'amount_resolved' => self::resolve_amount( $raw, $order ),
                    'amount_raw'      => $raw,
                    'timing'          => $timing,
                    'strategy_prio'   => isset( self::$strategy_priority[ $timing ] ) ? self::$strategy_priority[ $timing ] : 99,
                    'type_prio'       => self::$type_priority['product_rule'],
                );
            }
        }

        // ── MagePeople "Booking and Rental Manager" candidates (FREE, opt-in) ──
        // Gated only by its own securehold_magepeople_deposit_enabled option,
        // never by the PRO Rule Engine. Skipped only for products that
        // actually got a product_rule candidate above (Rule Engine active AND
        // _securehold_enabled = yes) — an explicit, LIVE SecureHold Product
        // Rule always wins, so there is no need to even build the competing
        // candidate. When the Rule Engine is off, _securehold_enabled is inert
        // leftover data (e.g. PRO deactivated) and must NOT suppress the
        // MagePeople candidate.
        foreach ( $items as $item ) {
            $pid = $item->get_product_id();
            if ( securehold_rule_engine_enabled() && get_post_meta( $pid, '_securehold_enabled', true ) === 'yes' ) {
                continue;
            }

            $mp_settings = self::get_magepeople_settings( $pid );
            if ( null === $mp_settings ) {
                continue;
            }

            $product = wc_get_product( $pid );
            $label   = $product ? $product->get_formatted_name() : '#' . $pid;
            $raw     = $mp_settings['deposit_amount'];
            $timing  = 'immediate'; // No MagePeople timing signal; used for strategy_prio sort only — build_result() falls back to Global's real timing.

            $candidates[] = array(
                'type'            => 'magepeople_deposit',
                'id'              => (int) $pid,
                'label'           => $label,
                'settings'        => $mp_settings,
                'amount_resolved' => self::resolve_amount( $raw, $order ),
                'amount_raw'      => $raw,
                'timing'          => $timing,
                'strategy_prio'   => isset( self::$strategy_priority[ $timing ] ) ? self::$strategy_priority[ $timing ] : 99,
                'type_prio'       => self::$type_priority['magepeople_deposit'],
            );
        }

        // Category Rule candidates are premium (PRO Rule Engine). Skip
        // collecting them entirely when the Rule Engine is not active.
        if ( securehold_rule_engine_enabled() ) {
            // ── Category Rule candidates (deduplicated by term_id) ──
            $all_rules = get_option( 'securehold_category_rules', array() );
            if ( is_array( $all_rules ) && ! empty( $all_rules ) ) {
                $seen_terms = array();

                foreach ( $items as $item ) {
                    $pid   = $item->get_product_id();
                    $terms = wp_get_object_terms( $pid, 'product_cat', array(
                        'fields'  => 'ids',
                        'orderby' => 'term_id',
                        'order'   => 'ASC',
                    ) );

                    if ( is_wp_error( $terms ) || empty( $terms ) ) {
                        continue;
                    }

                    foreach ( $terms as $tid ) {
                        if ( isset( $seen_terms[ $tid ] ) ) {
                            continue;
                        }
                        $seen_terms[ $tid ] = true;

                        if ( ! isset( $all_rules[ $tid ] ) || empty( $all_rules[ $tid ]['capture_timing'] ) ) {
                            continue;
                        }

                        $settings = $all_rules[ $tid ];
                        $term     = get_term( $tid, 'product_cat' );
                        $name     = ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $tid;
                        $raw      = isset( $settings['deposit_amount'] ) ? $settings['deposit_amount'] : '';
                        $timing   = ! empty( $settings['capture_timing'] ) ? $settings['capture_timing'] : 'manual';

                        $candidates[] = array(
                            'type'            => 'category_rule',
                            'id'              => (int) $tid,
                            'label'           => $name,
                            'settings'        => $settings,
                            'amount_resolved' => self::resolve_amount( $raw, $order ),
                            'amount_raw'      => $raw,
                            'timing'          => $timing,
                            'strategy_prio'   => isset( self::$strategy_priority[ $timing ] ) ? self::$strategy_priority[ $timing ] : 99,
                            'type_prio'       => self::$type_priority['category_rule'],
                        );
                    }
                }
            }
        }

        // ── Global candidate (always present) ──
        $global  = self::get_global_values();
        $raw     = $global['deposit_amount'];
        $timing  = $global['capture_timing'];

        $candidates[] = array(
            'type'            => 'global',
            'id'              => 0,
            'label'           => 'Global',
            'settings'        => $global,
            'amount_resolved' => self::resolve_amount( $raw, $order ),
            'amount_raw'      => $raw,
            'timing'          => $timing,
            'strategy_prio'   => isset( self::$strategy_priority[ $timing ] ) ? self::$strategy_priority[ $timing ] : 99,
            'type_prio'       => self::$type_priority['global'],
        );

        return $candidates;
    }

    /* ================================================================
     *  RESOLUTION POLICIES
     * ================================================================ */

    /**
     * Priority Chain policy.
     *
     * Legacy:
     *   1. First matching product rule wins (early return — item-order dependent).
     *   2. If no product rule: category winner (highest amount, tie-break strategy).
     *   3. If no category rule: global.
     *
     * V2 (deterministic):
     *   1. If product rules exist: pick the best via stable tie-break
     *      (amount_resolved DESC → strategy_priority ASC → source_id ASC).
     *   2. If no product rule: category winner (highest amount, tie-break strategy, id).
     *   3. If no category rule: global.
     *
     * @since 5.2.0 V2 deterministic product rule selection.
     * @param array         $candidates All candidates from collect_all_candidates().
     * @param WC_Order|null $order      Order for percentage resolution.
     * @return array build_result() output.
     */
    private static function apply_priority_chain( $candidates, $order ) {
        $engine = self::get_engine_version();

        // Pass 1: product rules.
        $product_candidates = array();
        foreach ( $candidates as $c ) {
            if ( 'product_rule' === $c['type'] ) {
                $product_candidates[] = $c;
            }
        }

        if ( ! empty( $product_candidates ) ) {
            if ( 'v2' === $engine && count( $product_candidates ) > 1 ) {
                // V2: deterministic — pick best product rule via stable tie-break.
                $winner     = $product_candidates[0];
                $tie_breaks = array();

                for ( $i = 1; $i < count( $product_candidates ); $i++ ) {
                    $challenger = $product_candidates[ $i ];
                    $cmp = self::compare_candidates_priority_chain_v2( $winner, $challenger );
                    if ( $cmp < 0 ) {
                        $tie_breaks[] = sprintf(
                            'product_rule/%d beat product_rule/%d via %s',
                            $challenger['id'], $winner['id'],
                            self::describe_v2_tie_break( $winner, $challenger )
                        );
                        $winner = $challenger;
                    }
                }

                $result = self::build_result( 'product_rule', $winner['id'], $winner['label'], $winner['settings'], $order );
                $result['conflict_info']  = null;
                $result['_winner_reason'] = sprintf(
                    'Product rule %d/%s won (v2 deterministic: %d candidates, tie-break: amount DESC → strategy ASC → id ASC).',
                    $winner['id'], $winner['label'], count( $product_candidates )
                );
                $result['_tie_breaks'] = $tie_breaks;
                return $result;
            }

            // Legacy or single product rule: first match wins.
            $c = $product_candidates[0];
            $result = self::build_result( 'product_rule', $c['id'], $c['label'], $c['settings'], $order );
            $result['conflict_info']  = null;
            $result['_winner_reason'] = 'Product rule matched (priority chain — first product rule wins).';
            $result['_tie_breaks']    = array();
            return $result;
        }

        // Pass 1.5: MagePeople deposits (opt-in bridge). First match wins,
        // same simple rule as product rules — an explicit SecureHold Product
        // Rule always wins because it was already returned in Pass 1 above,
        // and collect_all_candidates() never emits both for the same product.
        $mp_candidates = array();
        foreach ( $candidates as $c ) {
            if ( 'magepeople_deposit' === $c['type'] ) {
                $mp_candidates[] = $c;
            }
        }

        if ( ! empty( $mp_candidates ) ) {
            $c      = $mp_candidates[0];
            $result = self::build_result( 'magepeople_deposit', $c['id'], $c['label'], $c['settings'], $order );
            $result['conflict_info']  = null;
            $result['_winner_reason'] = 'MagePeople security deposit matched (priority chain — first match wins).';
            $result['_tie_breaks']    = array();
            return $result;
        }

        // Pass 2: category rules.
        $cat_candidates = array();
        foreach ( $candidates as $c ) {
            if ( 'category_rule' === $c['type'] ) {
                $cat_candidates[] = array(
                    'term_id'  => $c['id'],
                    'name'     => $c['label'],
                    'settings' => $c['settings'],
                );
            }
        }

        if ( ! empty( $cat_candidates ) ) {
            $result = self::resolve_category_conflicts( $cat_candidates, $order );
            $result['_winner_reason'] = ( count( $cat_candidates ) > 1 )
                ? 'Category rule won after multi-category conflict resolution (highest amount, then strategy priority).'
                : 'Single category rule matched.';
            $result['_tie_breaks']    = array();
            return $result;
        }

        // Pass 3: global.
        $result = self::build_global_result( $order );
        $result['conflict_info']  = null;
        $result['_winner_reason'] = 'No product or category rule matched. Global settings applied.';
        $result['_tie_breaks']    = array();
        return $result;
    }

    /**
     * V2 deterministic comparison for product rules in Priority Chain.
     *
     * Returns < 0 if $b should replace $a (i.e. $b wins).
     * Tie-break: amount_resolved DESC → strategy_priority ASC → source_id ASC.
     *
     * @since 5.2.0
     */
    private static function compare_candidates_priority_chain_v2( $a, $b ) {
        // 1. Highest amount wins.
        $diff = (float) $a['amount_resolved'] - (float) $b['amount_resolved'];
        if ( abs( $diff ) > 0.001 ) {
            return ( $diff > 0 ) ? 1 : -1;
        }

        // 2. Strategy priority (lower = more restrictive = wins).
        $sp_diff = $a['strategy_prio'] - $b['strategy_prio'];
        if ( 0 !== $sp_diff ) {
            return ( $sp_diff < 0 ) ? 1 : -1;
        }

        // 3. Source ID ascending (lowest ID wins).
        $id_diff = $a['id'] - $b['id'];
        if ( 0 !== $id_diff ) {
            return ( $id_diff < 0 ) ? 1 : -1;
        }

        return 1; // Identical — keep current.
    }

    /**
     * Describe V2 tie-break reason between two product rule candidates.
     *
     * @since 5.2.0
     */
    private static function describe_v2_tie_break( $loser, $winner ) {
        if ( abs( (float) $winner['amount_resolved'] - (float) $loser['amount_resolved'] ) > 0.001 ) {
            return 'higher amount (' . $winner['amount_resolved'] . ' > ' . $loser['amount_resolved'] . ')';
        }
        if ( $winner['strategy_prio'] < $loser['strategy_prio'] ) {
            return 'strategy priority (' . $winner['timing'] . ' > ' . $loser['timing'] . ')';
        }
        if ( $winner['id'] < $loser['id'] ) {
            return 'source ID (' . $winner['id'] . ' < ' . $loser['id'] . ')';
        }
        return 'stable ordering';
    }

    /**
     * Highest Deposit Wins policy (order-level).
     *
     * All candidates compete on deposit_amount_resolved (resolved against order total).
     * Tie-break (stable, deterministic):
     *   1. Strategy priority (immediate > scheduled > status > delayed > manual).
     *   2. Type priority (product_rule > category_rule > global).
     *   3. Source ID ascending (lowest ID wins — stable sort for identical rules).
     *
     * @param array         $candidates All candidates from collect_all_candidates().
     * @param WC_Order|null $order      Order for percentage resolution.
     * @return array build_result() output.
     */
    private static function apply_highest_deposit_wins( $candidates, $order ) {
        if ( empty( $candidates ) ) {
            $result = self::build_global_result( $order );
            $result['conflict_info']  = null;
            $result['_winner_reason'] = 'No candidates found. Global settings applied.';
            $result['_tie_breaks']    = array();
            return $result;
        }

        $winner      = null;
        $tie_breaks  = array();

        foreach ( $candidates as $c ) {
            if ( null === $winner ) {
                $winner = $c;
                continue;
            }

            $cmp = self::compare_candidates_hdw( $winner, $c );

            if ( $cmp < 0 ) {
                // $c beats $winner.
                if ( (float) $c['amount_resolved'] === (float) $winner['amount_resolved'] ) {
                    $tie_breaks[] = sprintf(
                        'Tie at %s: %s/%d beat %s/%d via %s',
                        $c['amount_resolved'],
                        $c['type'], $c['id'],
                        $winner['type'], $winner['id'],
                        self::describe_tie_break_reason( $winner, $c )
                    );
                }
                $winner = $c;
            }
        }

        // Build result from winner.
        if ( 'global' === $winner['type'] ) {
            $result = self::build_global_result( $order );
            $result['conflict_info'] = null;
        } else {
            $result = self::build_result( $winner['type'], $winner['id'], $winner['label'], $winner['settings'], $order );
            // Build conflict_info if there were multiple category candidates.
            $cat_count = 0;
            foreach ( $candidates as $c ) {
                if ( 'category_rule' === $c['type'] ) {
                    $cat_count++;
                }
            }
            if ( 'category_rule' === $winner['type'] && $cat_count > 1 ) {
                $conflict_info = array(
                    'candidates_count' => $cat_count,
                    'candidates'       => array(),
                    'winner_term_id'   => $winner['id'],
                    'winner_name'      => $winner['label'],
                    'resolution_rule'  => 'highest_deposit_wins',
                );
                foreach ( $candidates as $c ) {
                    if ( 'category_rule' === $c['type'] ) {
                        $conflict_info['candidates'][] = array(
                            'term_id'   => $c['id'],
                            'name'      => $c['label'],
                            'amount'    => $c['amount_raw'],
                            'strategy'  => $c['timing'],
                            'is_winner' => ( $c['id'] === $winner['id'] ),
                        );
                    }
                }
                $result['conflict_info'] = $conflict_info;
            } else {
                $result['conflict_info'] = null;
            }
        }

        $result['_winner_reason'] = sprintf(
            'Highest resolved amount: %s from %s/%d.',
            $winner['amount_resolved'],
            $winner['type'],
            $winner['id']
        );
        $result['_tie_breaks'] = $tie_breaks;

        return $result;
    }

    /**
     * Compare two candidates for Highest Deposit Wins ordering.
     * Returns < 0 if $b should replace $a (i.e. $b wins).
     * Returns >= 0 if $a remains the winner.
     *
     * @param array $a Current winner.
     * @param array $b Challenger.
     * @return int
     */
    private static function compare_candidates_hdw( $a, $b ) {
        // 1. Highest amount wins.
        $diff = (float) $a['amount_resolved'] - (float) $b['amount_resolved'];
        if ( abs( $diff ) > 0.001 ) {
            return ( $diff > 0 ) ? 1 : -1;
        }

        // 2. Strategy priority (lower = higher priority).
        $sp_diff = $a['strategy_prio'] - $b['strategy_prio'];
        if ( 0 !== $sp_diff ) {
            return ( $sp_diff < 0 ) ? 1 : -1;
        }

        // 3. Type priority (lower = higher priority).
        $tp_diff = $a['type_prio'] - $b['type_prio'];
        if ( 0 !== $tp_diff ) {
            return ( $tp_diff < 0 ) ? 1 : -1;
        }

        // 4. Source ID ascending (lowest ID wins).
        $id_diff = $a['id'] - $b['id'];
        if ( 0 !== $id_diff ) {
            return ( $id_diff < 0 ) ? 1 : -1;
        }

        // Identical — keep current.
        return 1;
    }

    /**
     * Deterministic candidate comparison for the simulator.
     *
     * Sorts by: type_prio ASC → amount_resolved DESC → strategy_prio ASC → id ASC.
     * This ensures apply_priority_chain() picks the same "first" product rule
     * regardless of the order items were added to the simulated cart.
     *
     * @since 4.7.0
     * @param array $a Candidate A.
     * @param array $b Candidate B.
     * @return int
     */
    private static function compare_candidates_deterministic( $a, $b ) {
        // 1. Type priority: product_rule(1) < category_rule(2) < global(3).
        if ( $a['type_prio'] !== $b['type_prio'] ) {
            return $a['type_prio'] - $b['type_prio'];
        }

        // 2. Within same type: highest resolved amount first.
        $amount_diff = (float) $b['amount_resolved'] - (float) $a['amount_resolved'];
        if ( abs( $amount_diff ) > 0.001 ) {
            return ( $amount_diff > 0 ) ? 1 : -1;
        }

        // 3. Strategy priority: lower number = more restrictive (first).
        if ( $a['strategy_prio'] !== $b['strategy_prio'] ) {
            return $a['strategy_prio'] - $b['strategy_prio'];
        }

        // 4. Source ID ascending (stable).
        return $a['id'] - $b['id'];
    }

    /**
     * Describe why a tie was broken between two candidates (for explain UI).
     *
     * @param array $loser  The candidate that lost.
     * @param array $winner The candidate that won.
     * @return string
     */
    private static function describe_tie_break_reason( $loser, $winner ) {
        if ( $winner['strategy_prio'] < $loser['strategy_prio'] ) {
            return 'strategy priority (' . $winner['timing'] . ' > ' . $loser['timing'] . ')';
        }
        if ( $winner['type_prio'] < $loser['type_prio'] ) {
            return 'type priority (' . $winner['type'] . ' > ' . $loser['type'] . ')';
        }
        if ( $winner['id'] < $loser['id'] ) {
            return 'source ID (' . $winner['id'] . ' < ' . $loser['id'] . ')';
        }
        return 'stable ordering';
    }

    /* ================================================================
     *  PAYLOAD BUILDER
     * ================================================================ */

    /**
     * Enrich a build_result() output with policy and explain metadata.
     *
     * The final payload is backward-compatible with all existing consumers:
     * it preserves every key from the original resolve_final_deposit_configuration()
     * and adds: policy, explain.
     *
     * @param array         $result     Output from build_result() or build_global_result().
     * @param string        $policy     Active policy identifier.
     * @param array         $candidates All candidates from collect_all_candidates().
     * @param WC_Order|null $order      Order for context.
     * @return array
     */
    private static function build_final_payload( $result, $policy, $candidates, $order ) {
        // Add policy and engine version.
        $result['policy']         = $policy;
        $result['engine_version'] = self::get_engine_version();

        // Build explain (safe for UI — no secrets, no full settings).
        $explain_candidates = array();
        foreach ( $candidates as $c ) {
            $explain_candidates[] = array(
                'type'            => $c['type'],
                'id'              => $c['id'],
                'label'           => $c['label'],
                'amount_raw'      => $c['amount_raw'],
                'amount_resolved' => $c['amount_resolved'],
                'timing'          => $c['timing'],
            );
        }

        $result['explain'] = array(
            'candidates'    => $explain_candidates,
            'winner_reason' => isset( $result['_winner_reason'] ) ? $result['_winner_reason'] : '',
            'tie_breaks'    => isset( $result['_tie_breaks'] ) ? $result['_tie_breaks'] : array(),
        );

        // Clean internal keys.
        unset( $result['_winner_reason'], $result['_tie_breaks'] );

        return $result;
    }

    /* ================================================================
     *  LEGACY INTERNAL METHODS (preserved exactly)
     * ================================================================ */

    /**
     * Collect all matching category rule candidates across all order items.
     * De-duplicates by term_id.
     *
     * @param array $items WC_Order_Item array.
     * @return array Array of { term_id, name, settings }.
     */
    private static function collect_category_candidates( $items ) {
        $all_rules = get_option( 'securehold_category_rules', array() );
        if ( ! is_array( $all_rules ) || empty( $all_rules ) ) {
            return array();
        }

        $seen       = array();
        $candidates = array();

        foreach ( $items as $item ) {
            $pid   = $item->get_product_id();
            $terms = wp_get_object_terms( $pid, 'product_cat', array(
                'fields'  => 'ids',
                'orderby' => 'term_id',
                'order'   => 'ASC',
            ) );

            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }

            foreach ( $terms as $tid ) {
                if ( isset( $seen[ $tid ] ) ) {
                    continue;
                }
                $seen[ $tid ] = true;

                if ( isset( $all_rules[ $tid ] ) && ! empty( $all_rules[ $tid ]['capture_timing'] ) ) {
                    $term         = get_term( $tid, 'product_cat' );
                    $candidates[] = array(
                        'term_id'  => (int) $tid,
                        'name'     => ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $tid,
                        'settings' => $all_rules[ $tid ],
                    );
                }
            }
        }

        return $candidates;
    }

    /**
     * Resolve conflicts when multiple category rules match.
     *
     * SINGLE WINNER APPROACH:
     *   1. Highest amount wins.
     *   2. If amounts are equal, highest strategy priority breaks the tie.
     *   3. ALL fields come from the single winning category rule.
     *
     * @param array         $candidates Array of { term_id, name, settings }.
     * @param WC_Order|null $order      Order for logging context and % resolution (nullable).
     * @return array Resolved configuration array.
     */
    private static function resolve_category_conflicts( $candidates, $order ) {
        // Single candidate: no conflict.
        if ( count( $candidates ) === 1 ) {
            $c      = $candidates[0];
            $result = self::build_result( 'category_rule', $c['term_id'], $c['name'], $c['settings'], $order );
            $result['conflict_info'] = null;
            return $result;
        }

        // Determine SINGLE WINNER among candidates.
        $winner        = null;
        $winner_amount = -1;
        $winner_prio   = PHP_INT_MAX;

        foreach ( $candidates as $c ) {
            $raw      = isset( $c['settings']['deposit_amount'] ) ? $c['settings']['deposit_amount'] : '';
            $numeric  = self::parse_amount_for_comparison( $raw );
            $strategy = isset( $c['settings']['capture_timing'] ) ? $c['settings']['capture_timing'] : 'manual';
            $prio     = isset( self::$strategy_priority[ $strategy ] ) ? self::$strategy_priority[ $strategy ] : 99;

            if ( $numeric > $winner_amount || ( $numeric === $winner_amount && $prio < $winner_prio ) ) {
                $winner        = $c;
                $winner_amount = $numeric;
                $winner_prio   = $prio;
            }
        }

        // Build conflict info for logging and UI.
        $conflict_info = array(
            'candidates_count' => count( $candidates ),
            'candidates'       => array(),
            'winner_term_id'   => $winner['term_id'],
            'winner_name'      => $winner['name'],
            'resolution_rule'  => 'highest_amount_then_strategy_priority',
        );

        foreach ( $candidates as $c ) {
            $conflict_info['candidates'][] = array(
                'term_id'   => $c['term_id'],
                'name'      => $c['name'],
                'amount'    => isset( $c['settings']['deposit_amount'] ) ? $c['settings']['deposit_amount'] : '',
                'strategy'  => isset( $c['settings']['capture_timing'] ) ? $c['settings']['capture_timing'] : '',
                'is_winner' => ( $c['term_id'] === $winner['term_id'] ),
            );
        }

        // Log the conflict resolution.
        if ( function_exists( 'securehold_log' ) ) {
            $log_data = array(
                'candidates'      => $conflict_info['candidates'],
                'winner_term_id'  => $winner['term_id'],
                'winner_strategy' => isset( $winner['settings']['capture_timing'] ) ? $winner['settings']['capture_timing'] : '',
                'winner_amount'   => isset( $winner['settings']['deposit_amount'] ) ? $winner['settings']['deposit_amount'] : '',
                'resolution_rule' => 'highest_amount_then_strategy_priority',
            );
            if ( $order ) {
                $log_data['order_id'] = $order->get_id();
            }
            securehold_log( 'Config Resolver: Category conflict resolved (single winner)', $log_data, 'debug' );
        }

        // Build result entirely from the single winner — no field mixing.
        $result = self::build_result( 'category_rule', $winner['term_id'], $winner['name'], $winner['settings'], $order );
        $result['conflict_info'] = $conflict_info;
        return $result;
    }

    /**
     * Parse an amount string for numeric comparison.
     * Percentages are compared at face value (20% = 20).
     *
     * @param string $raw
     * @return float
     */
    private static function parse_amount_for_comparison( $raw ) {
        if ( empty( $raw ) ) {
            return 0;
        }
        $cleaned = str_replace( '%', '', trim( $raw ) );
        return is_numeric( $cleaned ) ? (float) $cleaned : 0;
    }

    /**
     * Resolve a raw deposit amount string into a numeric currency value.
     * Handles both fixed amounts ("300") and percentages ("20%").
     *
     * @param string        $raw_amount Raw amount string.
     * @param WC_Order|null $order      Order for percentage resolution.
     * @return float Resolved numeric amount.
     */
    /**
     * MagePeople "Booking and Rental Manager" compatibility bridge (opt-in).
     *
     * Reads the Rent Item's own security-deposit meta directly — never calls
     * a MagePeople function — so this is a no-op with zero risk when the
     * plugin is absent, inactive, or the Rent Item has never used the field.
     *
     * The WooCommerce product_id SecureHold receives is NOT always the Rent
     * Item itself. MagePeople supports two setups:
     *   - Standalone: the Rent Item post IS the sellable WC product (same ID).
     *   - Linked: a separate WC product carries 'link_rbfw_id' postmeta
     *     pointing at the real Rent Item that holds the deposit config
     *     (mirrors MagePeople's own resolution in
     *     RBFW_Woocommerse::rbfw_add_info_to_cart_item()).
     * Both are resolved here so a linked setup is never silently missed.
     *
     * V1 scope: fixed amounts only. Percentage-type MagePeople deposits are
     * intentionally skipped — this resolver has no reliable per-item subtotal
     * at this point (only the full order/no order), so a percentage here
     * would silently use the wrong base. Skipping is safe: it simply falls
     * through to Category Rule / Global, exactly like "no MagePeople deposit
     * configured" would.
     *
     * @param  int $product_id WooCommerce product_id as seen by the resolver.
     * @return array|null  array('deposit_amount' => string) or null (no candidate).
     */
    public static function get_magepeople_settings( $product_id ) {
        if ( get_option( 'securehold_magepeople_deposit_enabled', 'no' ) !== 'yes' ) {
            return null;
        }

        $rent_item_id = $product_id;
        $linked_id    = get_post_meta( $product_id, 'link_rbfw_id', true );
        if ( ! empty( $linked_id ) ) {
            $rent_item_id = (int) $linked_id;
        }

        if ( get_post_meta( $rent_item_id, 'rbfw_enable_security_deposit', true ) !== 'yes' ) {
            return null;
        }

        $type = get_post_meta( $rent_item_id, 'rbfw_security_deposit_type', true );
        if ( 'percentage' === $type ) {
            return null; // Unsupported in this first integration — see docblock.
        }

        $amount = get_post_meta( $rent_item_id, 'rbfw_security_deposit_amount', true );
        if ( ! is_numeric( $amount ) ) {
            return null;
        }

        $amount = (float) $amount;
        if ( $amount <= 0 ) {
            return null;
        }

        return array(
            'deposit_amount' => (string) $amount,
            // No capture_timing from MagePeople — build_result() falls back
            // to Global's timing and tracks it in 'fallbacks'.
            'capture_timing' => null,
        );
    }

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
     * Build result array from a single winning source's settings.
     *
     * SINGLE SOURCE WINS: All fields come from $settings.
     * If a field is empty in $settings, it falls back to global BUT
     * this fallback is explicitly tracked in the 'fallbacks' array.
     *
     * @param string        $source       'product_rule' | 'category_rule'
     * @param int           $source_id    product_id or term_id.
     * @param string        $source_label Human-readable label.
     * @param array         $settings     Override settings array from the winning source.
     * @param WC_Order|null $order        Order for percentage resolution (nullable).
     * @return array
     */
    private static function build_result( $source, $source_id, $source_label, $settings, $order ) {
        $fallbacks = array();
        $global    = self::get_global_values();

        $timing = ! empty( $settings['capture_timing'] ) ? $settings['capture_timing'] : null;
        if ( null === $timing ) {
            $timing      = $global['capture_timing'];
            $fallbacks[] = 'timing';
        }

        $deposit_amount = ( isset( $settings['deposit_amount'] ) && $settings['deposit_amount'] !== '' )
            ? $settings['deposit_amount']
            : null;
        if ( null === $deposit_amount ) {
            $deposit_amount = $global['deposit_amount'];
            $fallbacks[]    = 'deposit_amount';
        }

        $delay_days = ( isset( $settings['delay_days'] ) && $settings['delay_days'] !== '' )
            ? $settings['delay_days']
            : null;
        if ( null === $delay_days ) {
            $delay_days  = $global['delay_days'];
            $fallbacks[] = 'delay_days';
        }

        $date_field_key = ( isset( $settings['date_field_key'] ) && $settings['date_field_key'] !== '' )
            ? $settings['date_field_key']
            : null;
        if ( null === $date_field_key ) {
            $date_field_key = $global['date_field_key'];
            $fallbacks[]    = 'date_field_key';
        }

        $scheduled_days = ( isset( $settings['scheduled_days'] ) && $settings['scheduled_days'] !== '' )
            ? $settings['scheduled_days']
            : null;
        if ( null === $scheduled_days ) {
            $scheduled_days = $global['scheduled_days'];
            $fallbacks[]    = 'scheduled_days';
        }

        $scheduled_direction = ( isset( $settings['scheduled_direction'] ) && $settings['scheduled_direction'] !== '' )
            ? $settings['scheduled_direction']
            : null;
        if ( null === $scheduled_direction ) {
            $scheduled_direction = $global['scheduled_direction'];
            $fallbacks[]         = 'scheduled_direction';
        }

        $trigger_status = ( isset( $settings['trigger_status'] ) && $settings['trigger_status'] !== '' )
            ? $settings['trigger_status']
            : null;
        if ( null === $trigger_status ) {
            $trigger_status = $global['trigger_status'];
            $fallbacks[]    = 'trigger_status';
        }

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
            'fallbacks'               => $fallbacks,
        );
    }

    /**
     * Build result from pure global settings.
     *
     * @param WC_Order|null $order Order for percentage resolution (nullable).
     * @return array
     */
    private static function build_global_result( $order ) {
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
     * Get all global settings values as a single array.
     * Handles backward compatibility for scheduled fields.
     *
     * @return array
     */
    private static function get_global_values() {
        $sched_days = get_option( 'securehold_scheduled_days_number', '' );
        $sched_dir  = get_option( 'securehold_scheduled_direction', '' );

        // Backward compat: old single field → new direction system.
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
     * Load dependency classes if not already loaded.
     */
    private static function load_dependencies() {
        if ( ! class_exists( 'Securehold_Product_Settings' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-product-settings.php';
        }
        if ( ! class_exists( 'Securehold_Category_Settings' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-category-settings.php';
        }
    }
}
