<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Returns the official Cambridge English qualification brand colours, keyed by CEFR level.
 * Use these any time a colour is needed to represent a CEFR level in the UI or reports.
 *
 * A2  KEY          #2BADA6  teal
 * B1  Preliminary  #D4213D  red
 * B2  First        #84BD00  lime green
 * C1  Advanced     #00A3D7  sky blue
 * C2  Proficiency  #1B3F7A  navy
 */
function adaptive_test_cefr_colors() {
    return [
        'A2' => '#2BADA6',
        'B1' => '#D4213D',
        'B2' => '#84BD00',
        'C1' => '#00A3D7',
        'C2' => '#1B3F7A',
    ];
}

/**
 * Returns a lighter tint (20% opacity equivalent) of each brand colour,
 * suitable for chart band backgrounds and filled areas.
 */
function adaptive_test_cefr_tints() {
    return [
        'A2' => '#d0edec',
        'B1' => '#f7d0d5',
        'B2' => '#e8f5cc',
        'C1' => '#ccecf8',
        'C2' => '#d0d7e8',
    ];
}
