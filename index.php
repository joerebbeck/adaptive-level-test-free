<?php
/**
 * Plugin Name: Adaptive Level Test
 * Plugin URI:  https://idiomiq.com/adaptive-level-test
 * Description: An adaptive test for ESL students to determine their CEFR level (A2-C2).
 * Version:     1.3.0
 * Author:      IdiomIQ
 * Author URI:  https://idiomiq.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: adaptive-level-test
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ADAPTIVE_LEVEL_TEST_VERSION', '1.3.0' );
define( 'ADAPTIVE_LEVEL_TEST_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

require_once ADAPTIVE_LEVEL_TEST_PLUGIN_PATH . 'includes/cefr-colors.php';
require_once ADAPTIVE_LEVEL_TEST_PLUGIN_PATH . 'includes/db.php';
require_once ADAPTIVE_LEVEL_TEST_PLUGIN_PATH . 'includes/ajax.php';
require_once ADAPTIVE_LEVEL_TEST_PLUGIN_PATH . 'includes/admin-settings.php';

function adaptive_test_activate() {
    adaptive_test_create_questions_table();
    adaptive_test_insert_sample_questions();
}
register_activation_hook( __FILE__, 'adaptive_test_activate' );

function adaptive_test_deactivate() {
    wp_clear_scheduled_hook( 'adaptive_test_daily_log_cleanup' );
}
register_deactivation_hook( __FILE__, 'adaptive_test_deactivate' );

function adaptive_test_schedule_cleanup() {
    if ( ! wp_next_scheduled( 'adaptive_test_daily_log_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'adaptive_test_daily_log_cleanup' );
    }
}
add_action( 'wp_loaded', 'adaptive_test_schedule_cleanup' );

function adaptive_test_run_log_cleanup() {
    $days = absint( get_option( 'adaptive_test_log_retention_days', 90 ) );
    if ( $days < 1 ) {
        return;
    }
    global $wpdb;
    $logs_table = $wpdb->prefix . 'adaptive_attempt_logs';
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$logs_table} WHERE date < DATE_SUB(NOW(), INTERVAL %d DAY)", $days ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $logs_table is $wpdb->prefix . hardcoded literal, not user input.
}
add_action( 'adaptive_test_daily_log_cleanup', 'adaptive_test_run_log_cleanup' );

function adaptive_test_update_db_check() {
    if ( get_option( 'adaptive_test_db_version' ) !== '1.5.1' ) {
        adaptive_test_create_questions_table();
        update_option( 'adaptive_test_db_version', '1.5.1' );
    }
}
add_action( 'plugins_loaded', 'adaptive_test_update_db_check' );

function adaptive_level_test_shortcode( $atts ) {
    $atts = shortcode_atts( [ 'bank' => 1 ], $atts, 'adaptive_level_test' );
    ob_start();
    set_query_var( 'adaptive_bank_id', intval( $atts['bank'] ) );
    include ADAPTIVE_LEVEL_TEST_PLUGIN_PATH . 'templates/quiz-container.php';
    return ob_get_clean();
}
add_shortcode( 'adaptive_level_test', 'adaptive_level_test_shortcode' );

function adaptive_test_enqueue_scripts() {
    if ( ! is_a( get_post(), 'WP_Post' ) || ! has_shortcode( get_post()->post_content, 'adaptive_level_test' ) ) {
        return;
    }

    wp_enqueue_style(
        'adaptive-style',
        plugin_dir_url( __FILE__ ) . 'assets/css/adaptive-style.css',
        [],
        ADAPTIVE_LEVEL_TEST_VERSION
    );

    wp_enqueue_script(
        'adaptive-script',
        plugin_dir_url( __FILE__ ) . 'assets/js/adaptive-script.js',
        [],
        ADAPTIVE_LEVEL_TEST_VERSION,
        true
    );

    $data = [
        'ajax_url'         => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'adaptive_level_test_nonce' ),
        'target_error'     => max( 1, (int) get_option( 'adaptive_test_target_error', 8 ) ),
        'encouragement'    => false,
        'show_error_rate'  => (int) get_option( 'adaptive_test_show_error_rate', 1 ),
        'error_rate_label' => wp_kses_post( get_option( 'adaptive_test_error_rate_label', '' ) ),
        'error_rate'       => get_option( 'adaptive_test_error_rate', 5 ),
        'share_enabled'    => false,
        'i18n'             => [
            'valid_email'      => __( 'Please enter a valid email address.', 'adaptive-level-test' ),
            'error_loading'    => __( 'Error loading test.', 'adaptive-level-test' ),
            'connection_error' => __( 'Connection Error', 'adaptive-level-test' ),
            'analyzing'        => get_option( 'adaptive_test_during_analysing', __( 'Analysing your answers...', 'adaptive-level-test' ) ),
            'test_complete'    => wp_kses_post( get_option( 'adaptive_test_after_title',      __( 'Test Complete', 'adaptive-level-test' ) ) ),
            'estimated_level'  => wp_kses_post( get_option( 'adaptive_test_after_subheading', __( 'Your estimated English level is:', 'adaptive-level-test' ) ) ),
            'email_sent'       => wp_kses_post( get_option( 'adaptive_test_after_body',       __( 'A copy of your results has been sent to your email.', 'adaptive-level-test' ) ) ),
            'error_submitting' => __( 'Error submitting answers.', 'adaptive-level-test' ),
            'refresh_retry'    => __( 'Please refresh the page and try again.', 'adaptive-level-test' ),
            'retry'            => __( 'Please try again.', 'adaptive-level-test' ),
            'unknown_error'    => __( 'Unknown error occurred.', 'adaptive-level-test' ),
            'retake_test'      => __( 'Retake Test', 'adaptive-level-test' ),
            'loading'          => get_option( 'adaptive_test_during_loading', __( 'Loading question...', 'adaptive-level-test' ) ),
            'dyslexic_off'     => get_option( 'adaptive_test_during_dyslexic_off', __( 'Change to dyslexia friendly font', 'adaptive-level-test' ) ),
            'dyslexic_on'      => get_option( 'adaptive_test_during_dyslexic_on',  __( 'Change to regular font', 'adaptive-level-test' ) ),
        ],
    ];

    // Allow the pro plugin to add its own data (styling, share config, encouragement).
    $data = apply_filters( 'adaptive_test_script_data', $data );

    wp_localize_script( 'adaptive-script', 'adaptive_test_ajax', $data );
}
add_action( 'wp_enqueue_scripts', 'adaptive_test_enqueue_scripts' );

function adaptive_test_plugin_action_links( $links ) {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=adaptive-level-test' ) ) . '">' . esc_html__( 'Settings', 'adaptive-level-test' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'adaptive_test_plugin_action_links' );

// X-Frame-Options: SAMEORIGIN — clickjacking protection for the quiz.
// Disabled by default because it applies to every page on the site, not just
// pages that contain the shortcode. This can break legitimate iframes elsewhere
// (e.g. embedded video, third-party widgets). For clients who need this protection,
// un-comment the three lines below, or set the header at the server level (nginx/Apache)
// which is the more appropriate place for a site-wide policy.
//
// function adaptive_test_security_headers( $headers ) {
//     $headers['X-Frame-Options'] = 'SAMEORIGIN';
//     return $headers;
// }
// add_filter( 'wp_headers', 'adaptive_test_security_headers' );
