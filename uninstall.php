<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

if ( ! get_option( 'adaptive_test_delete_on_uninstall' ) ) {
    return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}adaptive_attempt_logs" );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall is a one-time destructive operation; caching is irrelevant.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}adaptive_questions" );       // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall is a one-time destructive operation; caching is irrelevant.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}adaptive_question_banks" );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall is a one-time destructive operation; caching is irrelevant.

$adaptive_test_options = [
    'adaptive_test_delete_on_uninstall',
    'adaptive_test_rate_limit',
    'adaptive_test_max_batches',
    'adaptive_test_log_retention_days',
    'adaptive_test_primary_color',
    'adaptive_test_target_error',
    'adaptive_test_strong_label',
    'adaptive_test_borderline_label',
    'adaptive_test_email_subject',
    'adaptive_test_email_body',
    'adaptive_test_admin_email',
    'adaptive_test_admin_email_subject',
    'adaptive_test_admin_email_body',
    'adaptive_test_email_footer',
    'adaptive_test_start_title',
    'adaptive_test_start_subtitle',
    'adaptive_test_start_body',
    'adaptive_test_start_email_placeholder',
    'adaptive_test_start_button_text',
    'adaptive_test_start_gdpr2',
    'adaptive_test_start_gdpr2_message',
    'adaptive_test_during_progress',
    'adaptive_test_during_question',
    'adaptive_test_during_counter',
    'adaptive_test_during_options',
    'adaptive_test_during_selected',
    'adaptive_test_during_dyslexic',
    'adaptive_test_during_loading',
    'adaptive_test_during_analysing',
    'adaptive_test_during_dyslexic_off',
    'adaptive_test_during_dyslexic_on',
    'adaptive_test_show_error_rate',
    'adaptive_test_error_rate_label',
    'adaptive_test_error_rate',
    'adaptive_test_after_title',
    'adaptive_test_after_subheading',
    'adaptive_test_after_body',
    'adaptive_test_after_retake',
    'adaptive_test_db_version',
];

foreach ( $adaptive_test_options as $adaptive_test_option ) {
    delete_option( $adaptive_test_option );
}

wp_clear_scheduled_hook( 'adaptive_test_daily_log_cleanup' );
