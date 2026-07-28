/**
 * Setup Wizard — Step Bar Mobile Centering
 *
 * On mobile (< 783px) the progress bar is horizontally scrollable.
 * This script scrolls the bar so the current/active step is centred
 * in the visible area when the page loads.
 *
 * Uses getBoundingClientRect() because .securehold-wizard-progress is
 * not positioned (position: static), so offsetLeft of child elements
 * is relative to the nearest positioned ancestor — not the scroll
 * container. getBoundingClientRect() is viewport-relative and accurate
 * regardless of outer positioned elements.
 *
 * No dependencies. Scoped strictly to the Setup Wizard.
 */
( function () {
    'use strict';

    function centerCurrentStep() {
        if ( window.innerWidth >= 783 ) {
            return;
        }

        var track   = document.querySelector( '.securehold-wizard-progress' );
        var current = document.querySelector( '.wizard-step.current' );

        if ( ! track || ! current ) {
            return;
        }

        var trackRect   = track.getBoundingClientRect();
        var currentRect = current.getBoundingClientRect();

        // Shift scrollLeft so the current step's centre aligns with the track's centre.
        track.scrollLeft +=
            ( currentRect.left + currentRect.width  / 2 ) -
            ( trackRect.left   + trackRect.width   / 2 );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', centerCurrentStep );
    } else {
        centerCurrentStep();
    }
}() );
