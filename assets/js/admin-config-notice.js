/**
 * SecureHold — Dashboard configuration notice dismiss handler.
 *
 * Enqueued on the WordPress dashboard only (where show_configuration_notice()
 * renders the setup prompt). Self-contained: the nonce is read from the
 * button's data-nonce attribute and ajaxurl is the WordPress admin global,
 * so no PHP-side data localization is needed.
 *
 * Handle: securehold-admin-config-notice
 */
(function ($) {
	'use strict';

	$(function () {
		$(document).on('click', '.securehold-dismiss-notice-forever', function (e) {
			e.preventDefault();
			var $notice = $('#securehold-config-notice');
			var nonce = $(this).data('nonce');
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: { action: 'securehold_dismiss_config_notice', nonce: nonce },
				success: function (response) {
					if (response.success) {
						$notice.slideUp(200, function () { $(this).remove(); });
					}
				}
			});
		});

		$('#securehold-config-notice').on('click', '.notice-dismiss', function () {
			$('#securehold-config-notice').slideUp(200);
		});
	});
})(jQuery);
