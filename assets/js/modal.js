/**
 * SecureHold Setup Modal JavaScript
 * Version 3.0.2 - FIXED
 */
(function($) {
    'use strict';
    
    $(document).ready(function() {
        var $modal = $('#securehold-setup-modal');
        
        // CORRECTION : Afficher le modal avec la classe 'active' et display:flex
        // au lieu d'utiliser fadeIn() qui ne gère que l'opacité
        setTimeout(function() {
            $modal.css('display', 'flex'); // Afficher d'abord le modal
            setTimeout(function() {
                $modal.addClass('active'); // Puis ajouter la classe pour l'animation
            }, 50); // Petit délai pour permettre l'animation CSS
        }, 500);
        
        /**
         * Fonction pour fermer le modal avec animation
         */
        function closeModal() {
            $modal.removeClass('active');
            setTimeout(function() {
                $modal.css('display', 'none');
            }, 300); // Attendre la fin de l'animation avant de masquer
        }
        
        /**
         * Fonction pour "sauter" le setup (dismiss)
         */
        function dismissSetup() {
            // Ask for confirmation
            if (confirm('Are you sure you want to skip the setup wizard? You can start it later from the SecureHold menu.')) {
                // Send AJAX request to dismiss
                $.ajax({
                    url: secureholdModal.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'securehold_dismiss_setup',
                        nonce: secureholdModal.nonce
                    },
                    success: function(response) {
                        closeModal();
                        
                        // AMÉLIORATION : Afficher un message de confirmation
                        if (response.success && response.data.message) {
                            // Créer une notification temporaire
                            var $notice = $('<div class="notice notice-info is-dismissible" style="margin: 1rem 0;"><p>' + response.data.message + '</p></div>');
                            $('.wrap').first().prepend($notice);
                            
                            // Auto-masquer après 5 secondes
                            setTimeout(function() {
                                $notice.fadeOut(300, function() {
                                    $(this).remove();
                                });
                            }, 5000);
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                    }
                });
            }
        }
        
        // Close modal on X button
        $('#securehold-modal-dismiss').on('click', function(e) {
            e.preventDefault();
            dismissSetup();
        });
        
        // Close on "Skip for now" button
        $('#securehold-skip-setup').on('click', function(e) {
            e.preventDefault();
            dismissSetup();
        });
        
        // Close on overlay click (cliquer en dehors du modal)
        $modal.on('click', function(e) {
            if (e.target === this) {
                dismissSetup();
            }
        });
        
        // Close on ESC key
        $(document).on('keyup', function(e) {
            if (e.key === 'Escape' && $modal.hasClass('active')) {
                dismissSetup();
            }
        });
        
        // AMÉLIORATION : Empêcher la fermeture accidentelle du modal
        // si l'utilisateur clique sur le contenu du modal
        $modal.find('.sh-modal').on('click', function(e) {
            e.stopPropagation();
        });
    });
    
})(jQuery);
