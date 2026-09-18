/**
 * SecureHold — Opt-in telemetry notice handler.
 *
 * Enqueued on SecureHold's own admin pages only (see enqueue_admin_assets()
 * in class-securehold-wp-admin.php), where maybe_show_optin_notice() renders
 * the prompt. Self-contained: each button reads its own nonce from its
 * data-nonce attribute; ajaxurl is the WordPress admin global.
 *
 * Handle: securehold-admin-telemetry-notice
 */
(function ($) {
	'use strict';

	$(function () {
		var $notice = $('#securehold-telemetry-notice');

		function post(action, nonce, onDone) {
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: { action: action, nonce: nonce },
				success: function (response) {
					if (response.success) {
						onDone();
					}
				}
			});
		}

		$(document).on('click', '.securehold-telemetry-optin', function (e) {
			e.preventDefault();
			var nonce = $(this).data('nonce');
			post('securehold_telemetry_optin', nonce, function () {
				$notice.slideUp(200, function () { $(this).remove(); });
			});
		});

		$(document).on('click', '.securehold-telemetry-dismiss', function (e) {
			e.preventDefault();
			var nonce = $(this).data('nonce');
			post('securehold_telemetry_dismiss', nonce, function () {
				$notice.slideUp(200, function () { $(this).remove(); });
			});
		});
	});
})(jQuery);
