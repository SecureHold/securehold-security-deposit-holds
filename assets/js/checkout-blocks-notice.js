/**
 * Shows the SecureHold deposit notice on the WooCommerce Cart & Checkout
 * Blocks checkout, via the official ExperimentalOrderMeta slot.
 *
 * The classic checkout gets this same message through
 * Securehold_Frontend_Manager::display_checkout_message(), hooked on the
 * classic-template hooks woocommerce_review_order_before/after_payment —
 * hooks the block-based checkout never fires. This file is only enqueued
 * (see enqueue_blocks_checkout_notice() in class-securehold-wp-frontend-manager.php)
 * when the Checkout page actually uses the woocommerce/checkout block, so it
 * never runs alongside the classic notice.
 *
 * No build step: everything is read off the window globals WooCommerce and
 * WordPress already expose for exactly this use case.
 */
( function () {
	if ( ! window.wp || ! window.wp.element || ! window.wp.plugins || ! window.wc || ! window.wc.blocksCheckout ) {
		return;
	}

	var el = window.wp.element.createElement;
	var registerPlugin = window.wp.plugins.registerPlugin;
	var ExperimentalOrderMeta = window.wc.blocksCheckout.ExperimentalOrderMeta;
	var data = window.secureholdCheckoutNotice || null;

	if ( ! data || ! data.message ) {
		return;
	}

	var render = function () {
		return el(
			ExperimentalOrderMeta,
			null,
			el( 'div', {
				className: 'securehold-checkout-notice-block',
				style: {
					background: data.bg,
					borderLeft: '4px solid ' + data.border,
					padding: '1.25rem 1.5rem',
					margin: '1.5rem 0',
					borderRadius: '6px',
					fontSize: '0.9375rem',
					lineHeight: '1.6',
					color: data.text,
				},
				dangerouslySetInnerHTML: { __html: data.message },
			} )
		);
	};

	registerPlugin( 'securehold-checkout-blocks-notice', {
		render: render,
		scope: 'woocommerce-checkout',
	} );
} )();
