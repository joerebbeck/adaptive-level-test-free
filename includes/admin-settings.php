<?php
if (!defined('ABSPATH')) {
    exit;
}
// Plugin uses its own custom tables ($wpdb->prefix . 'adaptive_*'). Direct queries are
// unavoidable: WP_Filesystem has no equivalent for streaming CSV output or batch inserts.
// Table names are built from $wpdb->prefix (site-owner-controlled, not user input), so
// interpolation is safe. NoCaching is suppressed because these are admin-only write paths
// and one-off reads where a cache would add complexity without benefit.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// ── SAVE CHANGE TRACKING ──────────────────────────────────────────────────────

function adaptive_test_save_tracking_init() {
    if ( ! isset( $_POST['option_page'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- hook fires on load-options.php; WP Settings API verifies the nonce before that action runs
    $group = sanitize_key( wp_unslash( $_POST['option_page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- same: nonce already verified upstream by WP Settings API

    $labels = [
        'adaptive_test_options' => 'General settings',
        'adaptive_test_msg_options'        => 'Message settings',
        'adaptive_test_before_options'     => 'Before the Quiz settings',
        'adaptive_test_before_pro_options' => 'Before the Quiz customisation',
        'adaptive_test_during_options'     => 'During the Quiz settings',
        'adaptive_test_during_pro_options' => 'During the Quiz customisation',
        'adaptive_test_after_options'      => 'After the Quiz settings',
        'adaptive_test_after_pro_options'  => 'After the Quiz customisation',
    ];

    if ( ! array_key_exists( $group, $labels ) ) return;

    $uid   = get_current_user_id();
    $key   = 'adaptive_test_save_' . $uid;
    $label = $labels[ $group ];

    set_transient( $key, [ 'label' => $label, 'changed' => false ], 60 );

    add_action( 'updated_option', function() use ( $key, $label ) {
        set_transient( $key, [ 'label' => $label, 'changed' => true ], 60 );
    } );
}
add_action( 'load-options.php', 'adaptive_test_save_tracking_init' );

function adaptive_test_suppress_default_settings_notice() {
    if ( ! isset( $_GET['settings-updated'], $_GET['page'] ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: only deletes a transient to suppress WP's generic "Settings saved" notice; no data is written or mutated
    if ( 'adaptive-level-test' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same: read-only display check
    // Delete the transient before admin-header.php reads it, so WordPress never
    // adds its generic "Settings saved." to admin_notices.
    delete_transient( 'settings_errors' );
}
add_action( 'admin_init', 'adaptive_test_suppress_default_settings_notice' );

/**
 * Handle Question Form Submissions (Add/Edit/Delete)
 */
function adaptive_test_handle_question_actions() {
    if (!current_user_can('manage_options')) return;

    if ( isset( $_POST['adaptive_test_action'] ) && 'save_question' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_save_question_nonce' );
        global $wpdb;
        $table_name = $wpdb->prefix . 'adaptive_questions';

        $data = [
            'question_text' => sanitize_text_field( wp_unslash( $_POST['question_text'] ?? '' ) ),
            'bank_id'       => absint( wp_unslash( $_POST['bank_id'] ?? 1 ) ),
            'options'       => json_encode( array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_POST['options'] ?? '' ) ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element is passed through sanitize_text_field() via array_map(); checker cannot trace sanitisation applied indirectly through array_map
            'answer'        => sanitize_text_field( wp_unslash( $_POST['answer'] ?? '' ) ),
            'level'         => sanitize_text_field( wp_unslash( $_POST['level'] ?? '' ) ),
            'type'          => 'multiple_choice',
        ];

        if ( ! in_array( $data['level'], [ 'A2', 'B1', 'B2', 'C1', 'C2' ], true ) ) {
            wp_safe_redirect( add_query_arg( [ 'message' => 'invalid_level', 'tab' => 'questions', 'bank_id' => $data['bank_id'] ], remove_query_arg( [ 'adaptive_test_action' ] ) ) );
            exit;
        }

        if ( ! empty( $_POST['question_id'] ) ) {
            $wpdb->update( $table_name, $data, [ 'id' => absint( wp_unslash( $_POST['question_id'] ) ) ] );
        } else {
            $wpdb->insert( $table_name, $data );
        }
        
        wp_safe_redirect(add_query_arg('message', 'saved', remove_query_arg(['action', 'id'])));
        exit;
    }

    if ( isset( $_GET['action'] ) && 'delete_question' === $_GET['action'] && isset( $_GET['id'] ) ) {
        check_admin_referer( 'adaptive_test_delete_question_nonce' );
        global $wpdb;
        $banks_table = $wpdb->prefix . 'adaptive_question_banks';
        $table_name  = $wpdb->prefix . 'adaptive_questions';

        $question = $wpdb->get_row( $wpdb->prepare( "SELECT bank_id FROM {$table_name} WHERE id = %d", absint( wp_unslash( $_GET['id'] ) ) ) );
        if ( $question ) {
            $is_default_bank = $wpdb->get_var( $wpdb->prepare( "SELECT is_default FROM {$banks_table} WHERE id = %d", $question->bank_id ) );
            if ( $is_default_bank ) {
                wp_die( esc_html__( 'Questions in the Default Question Bank cannot be deleted. You can edit them or duplicate the bank to create a custom set.', 'adaptive-level-test' ) );
            }
        }

        $wpdb->delete( $table_name, [ 'id' => absint( wp_unslash( $_GET['id'] ) ) ] );
        
        wp_safe_redirect(add_query_arg('message', 'deleted', remove_query_arg(['action', 'id', '_wpnonce'])));
        exit;
    }

    // Handle Bank Actions
    if ( isset( $_POST['adaptive_test_action'] ) && 'save_bank' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_save_bank_nonce' );

        global $wpdb;
        $banks_table = $wpdb->prefix . 'adaptive_question_banks';

        $wpdb->insert( $banks_table, [ 'name' => sanitize_text_field( wp_unslash( $_POST['bank_name'] ?? '' ) ) ] );

        wp_safe_redirect( add_query_arg( [ 'message' => 'bank_saved', 'tab' => 'questions' ], remove_query_arg( [ 'adaptive_test_action' ] ) ) );
        exit;
    }

    if ( isset( $_GET['action'] ) && 'delete_bank' === $_GET['action'] && isset( $_GET['id'] ) ) {
        check_admin_referer( 'adaptive_test_delete_bank_nonce' );
        global $wpdb;
        $banks_table = $wpdb->prefix . 'adaptive_question_banks';
        $id          = absint( wp_unslash( $_GET['id'] ) );

        // Check if default
        $is_default = $wpdb->get_var($wpdb->prepare("SELECT is_default FROM $banks_table WHERE id = %d", $id));
        if ($is_default) {
            wp_die( esc_html__( 'Cannot delete the default question bank.', 'adaptive-level-test' ) );
        }

        // Check usage in posts (simple check)
        $shortcode_str = '[adaptive_level_test bank="' . $id . '"';
        $in_use = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_content LIKE %s AND post_status = 'publish' LIMIT 1", '%' . $wpdb->esc_like($shortcode_str) . '%'));
        
        if ($in_use) {
            // translators: %d is the WordPress post ID that uses this question bank.
            wp_die( esc_html( sprintf( __( 'Cannot delete this bank. It is currently used on post ID %d.', 'adaptive-level-test' ), absint( $in_use ) ) ) );
        }

        $wpdb->delete($banks_table, ['id' => $id]);
        wp_safe_redirect(add_query_arg(['message' => 'bank_deleted', 'tab' => 'questions'], remove_query_arg(['action', 'id', '_wpnonce'])));
        exit;
    }

    // Handle Rename Bank
    if ( isset( $_POST['adaptive_test_action'] ) && 'rename_bank' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_rename_bank_nonce' );
        global $wpdb;
        $banks_table = $wpdb->prefix . 'adaptive_question_banks';
        $id   = absint( wp_unslash( $_POST['bank_id'] ?? 0 ) );
        $name = sanitize_text_field( wp_unslash( $_POST['bank_name'] ?? '' ) );

        if ( $id && $name ) {
            $wpdb->update( $banks_table, [ 'name' => $name ], [ 'id' => $id ] );
        }

        wp_safe_redirect( add_query_arg( [ 'message' => 'bank_renamed', 'tab' => 'questions' ], remove_query_arg( [ 'adaptive_test_action' ] ) ) );
        exit;
    }

    // Handle Duplicate Bank
    if ( isset( $_GET['action'] ) && 'duplicate_bank' === $_GET['action'] && isset( $_GET['id'] ) ) {
        check_admin_referer( 'adaptive_test_duplicate_bank_nonce' );
        global $wpdb;
        $banks_table     = $wpdb->prefix . 'adaptive_question_banks';
        $questions_table = $wpdb->prefix . 'adaptive_questions';
        $source_id       = absint( wp_unslash( $_GET['id'] ) );

        // 1. Get Source Bank
        $source_bank = $wpdb->get_row($wpdb->prepare("SELECT * FROM $banks_table WHERE id = %d", $source_id));
        if (!$source_bank) wp_die('Bank not found');

        $wpdb->query('START TRANSACTION');

        // 2. Create New Bank
        $new_name = $source_bank->name . ' (Copy)';
        $inserted = $wpdb->insert($banks_table, [
            'name' => $new_name,
            'is_default' => 0
        ]);
        if ( false === $inserted ) {
            $wpdb->query('ROLLBACK');
            wp_die('Failed to create bank copy');
        }
        $new_bank_id = $wpdb->insert_id;

        // 3. Copy Questions
        $questions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $questions_table WHERE bank_id = %d", $source_id));
        foreach ($questions as $q) {
            $row_inserted = $wpdb->insert($questions_table, [
                'bank_id' => $new_bank_id,
                'question_text' => $q->question_text,
                'options' => $q->options,
                'answer' => $q->answer,
                'level' => $q->level,
                'type' => $q->type
            ]);
            if ( false === $row_inserted ) {
                $wpdb->query('ROLLBACK');
                wp_die('Failed to copy questions');
            }
        }

        $wpdb->query('COMMIT');

        wp_safe_redirect(add_query_arg(['message' => 'bank_duplicated', 'tab' => 'questions'], remove_query_arg(['action', 'id', '_wpnonce'])));
        exit;
    }
}
add_action('admin_init', 'adaptive_test_handle_question_actions');

/**
 * Handle Tool Actions (Import/Export/Reseed)
 */
function adaptive_test_handle_tool_actions() {
    if (!current_user_can('manage_options')) return;

    // Export CSV
    if ( isset( $_POST['adaptive_test_action'] ) && 'export_csv' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_tool_action_nonce' );
        $bank_id = absint( wp_unslash( $_POST['export_bank_id'] ?? 1 ) );
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'adaptive_questions';
        $query = $wpdb->prepare("SELECT * FROM $table_name WHERE bank_id = %d", $bank_id);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $questions = $wpdb->get_results($query, ARRAY_A);

        header('Content-Type: text/csv');
        header( 'Content-Disposition: attachment; filename="esl-questions-export-' . wp_date( 'Y-m-d' ) . '.csv"' );
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        // Header row
        fputcsv($output, ['id', 'question_text', 'options', 'answer', 'level']);

        foreach ($questions as $q) {
            $opts = json_decode( $q['options'], true );
            $pipe_options = is_array( $opts ) ? implode( '|', $opts ) : $q['options'];
            fputcsv($output, [
                $q['id'],
                $q['question_text'],
                $pipe_options,
                $q['answer'],
                $q['level']
            ]);
        }
        
        fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    // Import CSV
    if ( isset( $_POST['adaptive_test_action'] ) && 'import_csv' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_tool_action_nonce' );
        $bank_id = absint( wp_unslash( $_POST['import_bank_id'] ?? 1 ) );

        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $file_check = wp_check_filetype( sanitize_file_name( $_FILES['csv_file']['name'] ), [ 'csv' => 'text/csv' ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- ['name'] is always present when ['tmp_name'] is non-empty; the enclosing if(!empty($_FILES['csv_file']['tmp_name'])) confirms the upload exists
            if ( empty( $file_check['ext'] ) ) {
                wp_die( esc_html__( 'Only CSV files are allowed.', 'adaptive-level-test' ) );
            }

            global $wpdb;
            $table_name = $wpdb->prefix . 'adaptive_questions';
            
            $handle = fopen( $_FILES['csv_file']['tmp_name'], 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WP_Filesystem cannot read uploaded tmp files; tmp_name is a server-generated path, not user-supplied content
            $header = fgetcsv($handle); // Skip header

            $imported = 0;
            $skipped  = 0;
            $allowed_levels = [ 'A2', 'B1', 'B2', 'C1', 'C2' ];

            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($row) < 5) continue;

                // Reject rows whose level is not a recognised CEFR level — invalid levels
                // cause undefined behaviour in the IRT estimator and adaptive algorithm.
                $level = sanitize_text_field( $row[4] );
                if ( ! in_array( $level, $allowed_levels, true ) ) {
                    $skipped++;
                    continue;
                }

                // Parse pipe-separated options (e.g. "go|goes|going|gone")
                $clean_options = array_map( 'sanitize_text_field', explode( '|', $row[2] ) );

                // We ignore the ID (column 0) and insert as new to avoid conflicts
                $data = [
                    'question_text' => sanitize_text_field($row[1]),
                    'bank_id'       => $bank_id,
                    'options'       => json_encode($clean_options),
                    'answer'        => sanitize_text_field($row[3]),
                    'level'         => $level,
                    'type'          => 'multiple_choice'
                ];

                $wpdb->insert($table_name, $data);
                $imported++;
            }
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

            $redirect_args = [ 'page' => 'adaptive-level-test', 'tab' => 'questions', 'message' => 'imported', 'count' => $imported ];
            if ( $skipped > 0 ) {
                $redirect_args['skipped'] = $skipped;
            }
            wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'options-general.php' ) ) );
            exit;
        }
    }

    // Re-seed Questions
    if ( isset( $_POST['adaptive_test_action'] ) && 'reseed_questions' === $_POST['adaptive_test_action'] ) {
        check_admin_referer('adaptive_test_tool_action_nonce');
        
        global $wpdb;
        $banks_table = $wpdb->prefix . 'adaptive_question_banks';
        $table_name = $wpdb->prefix . 'adaptive_questions';
        
        // Truncate both tables and re-insert the default bank atomically.
        $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "TRUNCATE TABLE {$table_name}" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "TRUNCATE TABLE {$banks_table}" );
        $wpdb->insert( $banks_table, [ 'name' => 'English A2-C2 - Bank 150', 'is_default' => 1 ] );
        $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
        
        // Seed
        if (function_exists('adaptive_test_insert_sample_questions')) {
            adaptive_test_insert_sample_questions();
        }

        wp_safe_redirect(add_query_arg(['page' => 'adaptive-level-test', 'tab' => 'questions', 'message' => 'reseeded'], admin_url('options-general.php')));
        exit;
    }

    // Export Logs CSV
    if ( isset( $_POST['adaptive_test_action'] ) && 'export_logs_csv' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_export_logs_nonce' );
        global $wpdb;
        $logs_table  = $wpdb->prefix . 'adaptive_attempt_logs';
        $bank_filter = isset( $_POST['bank_filter'] ) ? sanitize_text_field( wp_unslash( $_POST['bank_filter'] ) ) : '';
        if ( $bank_filter ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $logs = $wpdb->get_results(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                    "SELECT * FROM {$logs_table} WHERE bank_name = %s ORDER BY date DESC",
                    $bank_filter
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $logs = $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY date DESC", ARRAY_A );
        }

        header( 'Content-Type: text/csv' );
        header( 'Content-Disposition: attachment; filename="esl-attempt-logs-' . wp_date( 'Y-m-d' ) . '.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        fputcsv( $output, [ 'id', 'date', 'email', 'level', 'sub_level', 'theta', 'se', 'bank_name' ] );
        foreach ( $logs as $log ) {
            fputcsv( $output, [ $log['id'], $log['date'], $log['email'], $log['level'], $log['sub_level'] ?? '', $log['theta'] ?? '', $log['se'] ?? '', $log['bank_name'] ] );
        }
        fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    // Delete Logs
    if ( isset( $_POST['adaptive_test_action'] ) && 'delete_logs' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_delete_logs_nonce' );
        $days = absint( wp_unslash( $_POST['log_days'] ?? 30 ) );
        global $wpdb;
        $logs_table = $wpdb->prefix . 'adaptive_attempt_logs';
        $wpdb->query($wpdb->prepare("DELETE FROM $logs_table WHERE date < DATE_SUB(NOW(), INTERVAL %d DAY)", $days));
        wp_safe_redirect(add_query_arg(['page' => 'adaptive-level-test', 'tab' => 'logs', 'message' => 'logs_deleted'], admin_url('options-general.php')));
        exit;
    }

    // Delete individual log entry
    if ( isset( $_GET['action'] ) && 'delete_log' === $_GET['action'] && isset( $_GET['id'] ) ) {
        check_admin_referer( 'adaptive_test_delete_log_nonce' );
        global $wpdb;
        $logs_table = $wpdb->prefix . 'adaptive_attempt_logs';
        $wpdb->delete( $logs_table, [ 'id' => absint( wp_unslash( $_GET['id'] ) ) ] );
        wp_safe_redirect( add_query_arg( [ 'page' => 'adaptive-level-test', 'tab' => 'logs', 'message' => 'log_deleted' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    // Bulk delete log entries
    if ( isset( $_POST['adaptive_test_action'] ) && 'bulk_delete_logs' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_bulk_delete_logs_nonce' );
        $ids = isset( $_POST['log_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['log_ids'] ) ) : [];
        if ( ! empty( $ids ) ) {
            global $wpdb;
            $logs_table   = $wpdb->prefix . 'adaptive_attempt_logs';
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$logs_table} WHERE id IN ({$placeholders})", $ids ) );
        }
        wp_safe_redirect( add_query_arg( [ 'page' => 'adaptive-level-test', 'tab' => 'logs', 'message' => 'logs_deleted' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    // Reset Start Screen Defaults
    if ( isset( $_POST['adaptive_test_action'] ) && 'reset_start_screen' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_reset_start_screen_nonce' );
        $before_opts = [ 'start_title', 'start_subtitle', 'start_body', 'start_email_placeholder', 'start_gdpr2_enabled', 'start_gdpr2_message', 'start_button_text',
            'before_title_color', 'before_title_size', 'before_title_weight',
            'before_subtitle_color', 'before_subtitle_size', 'before_subtitle_weight',
            'before_body_color', 'before_body_size', 'before_body_weight',
            'before_input_placeholder_color', 'before_input_placeholder_size',
            'before_input_border_width', 'before_input_border_radius', 'before_input_border_color',
            'before_consent_color', 'before_consent_size', 'before_consent_weight',
            'before_btn_color', 'before_btn_text_color', 'before_btn_size', 'before_btn_weight',
            'before_btn_border_color', 'before_btn_border_width', 'before_btn_border_radius',
            'before_box_bg', 'before_box_text_color', 'before_box_text_size', 'before_box_text_weight',
            'before_box_border_width', 'before_box_border_radius', 'before_box_border_color', 'before_box_shadow',
        ];
        foreach ( $before_opts as $key ) { delete_option( 'adaptive_test_' . $key ); }
        wp_safe_redirect( add_query_arg( [ 'page' => 'adaptive-level-test', 'tab' => 'quiz', 'sub' => 'before', 'message' => 'start_screen_reset' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    // Reset During the Quiz Defaults
    if ( isset( $_POST['adaptive_test_action'] ) && 'reset_during' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_reset_during_nonce' );
        $during_opts = [ 'during_loading', 'during_analysing', 'during_show_progress', 'during_show_counter', 'during_counter_format', 'during_question_align', 'during_options_align',
            'during_progress_color',
            'during_question_color', 'during_question_size', 'during_question_weight',
            'during_counter_color', 'during_counter_size', 'during_counter_weight',
            'during_option_color', 'during_option_size', 'during_option_weight',
            'during_option_border_width', 'during_option_border_radius', 'during_option_border_color',
            'during_option_selected_color', 'during_option_selected_text',
            'during_option_selected_size', 'during_option_selected_weight',
            'during_option_selected_border_color', 'during_option_selected_border_width', 'during_option_selected_border_radius',
            'during_dyslexic_color', 'during_dyslexic_bg', 'during_dyslexic_size',
            'during_dyslexic_border_color', 'during_dyslexic_border_width', 'during_dyslexic_border_radius',
            'during_box_bg', 'during_box_text_color', 'during_box_text_size', 'during_box_text_weight',
            'during_box_border_width', 'during_box_border_radius', 'during_box_border_color', 'during_box_shadow',
        ];
        foreach ( $during_opts as $key ) { delete_option( 'adaptive_test_' . $key ); }
        wp_safe_redirect( add_query_arg( [ 'page' => 'adaptive-level-test', 'tab' => 'quiz', 'sub' => 'during', 'message' => 'during_reset' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    // Reset After the Quiz Defaults
    if ( isset( $_POST['adaptive_test_action'] ) && 'reset_after' === $_POST['adaptive_test_action'] ) {
        check_admin_referer( 'adaptive_test_reset_after_nonce' );
        $after_opts = [ 'show_error_rate', 'error_rate_label', 'error_rate',
            'after_title', 'after_subheading', 'after_body',
            'after_title_color', 'after_title_size', 'after_title_weight',
            'after_subheading_color', 'after_subheading_size', 'after_subheading_weight',
            'after_body_color', 'after_body_size', 'after_body_weight',
            'after_result_color', 'after_result_size', 'after_result_weight',
            'after_retake_color', 'after_retake_text_color', 'after_retake_size', 'after_retake_weight',
            'after_retake_border_color', 'after_retake_border_width', 'after_retake_border_radius',
            'after_box_bg', 'after_box_text_color', 'after_box_text_size', 'after_box_text_weight',
            'after_box_border_width', 'after_box_border_radius', 'after_box_border_color', 'after_box_shadow',
        ];
        foreach ( $after_opts as $key ) { delete_option( 'adaptive_test_' . $key ); }
        wp_safe_redirect( add_query_arg( [ 'page' => 'adaptive-level-test', 'tab' => 'quiz', 'sub' => 'after', 'message' => 'after_reset' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    // Reset Email Templates
    if ( isset( $_POST['adaptive_test_action'] ) && 'reset_email_templates' === $_POST['adaptive_test_action'] ) {
        check_admin_referer('adaptive_test_reset_email_nonce');
        
        delete_option('adaptive_test_email_subject');
        delete_option('adaptive_test_email_body');
        delete_option('adaptive_test_admin_email');
        delete_option('adaptive_test_admin_email_subject');
        delete_option('adaptive_test_admin_email_body');
        delete_option('adaptive_test_email_footer');

        wp_safe_redirect(add_query_arg(['page' => 'adaptive-level-test', 'tab' => 'messages', 'message' => 'emails_reset'], admin_url('options-general.php')));
        exit;
    }
}
add_action('admin_init', 'adaptive_test_handle_tool_actions');

/**
 * Register settings and fields.
 */
function adaptive_test_register_settings() {
    $int = [ 'sanitize_callback' => 'absint' ];
    $str = [ 'sanitize_callback' => 'sanitize_text_field' ];
    $wt  = [ 'sanitize_callback' => 'adaptive_test_sanitize_font_weight' ];


    register_setting( 'adaptive_test_options', 'adaptive_test_delete_on_uninstall',   $int );
    register_setting( 'adaptive_test_options', 'adaptive_test_rate_limit',            $int );
    register_setting( 'adaptive_test_options', 'adaptive_test_max_batches',           $int );
    register_setting( 'adaptive_test_options', 'adaptive_test_log_retention_days',   $int );
    register_setting( 'adaptive_test_options', 'adaptive_test_primary_color',       [ 'sanitize_callback' => 'sanitize_hex_color' ] );
    register_setting( 'adaptive_test_options', 'adaptive_test_target_error',        [ 'sanitize_callback' => 'floatval' ] );
    register_setting( 'adaptive_test_options', 'adaptive_test_strong_label',        $str );
    register_setting( 'adaptive_test_options', 'adaptive_test_borderline_label',    $str );

    // Messages (own group so saving General never clears these)
    register_setting( 'adaptive_test_msg_options', 'adaptive_test_email_subject',        [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'adaptive_test_msg_options', 'adaptive_test_email_body',            [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    register_setting( 'adaptive_test_msg_options', 'adaptive_test_admin_email',           [ 'sanitize_callback' => 'adaptive_test_sanitize_admin_emails' ] );
    register_setting( 'adaptive_test_msg_options', 'adaptive_test_admin_email_subject',   $str );
    register_setting( 'adaptive_test_msg_options', 'adaptive_test_admin_email_body',      [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    register_setting( 'adaptive_test_msg_options', 'adaptive_test_email_footer',          [ 'sanitize_callback' => 'wp_kses_post' ] );

    // Before — content
    register_setting( 'adaptive_test_before_options', 'adaptive_test_start_title',             [ 'sanitize_callback' => 'wp_kses_post' ] );
    register_setting( 'adaptive_test_before_options', 'adaptive_test_start_subtitle',          [ 'sanitize_callback' => 'wp_kses_post' ] );
    register_setting( 'adaptive_test_before_options', 'adaptive_test_start_body',              [ 'sanitize_callback' => 'wp_kses_post' ] );
    register_setting( 'adaptive_test_before_options', 'adaptive_test_start_email_placeholder', $str );
    register_setting( 'adaptive_test_before_options', 'adaptive_test_start_button_text',       $str );
    register_setting( 'adaptive_test_before_options', 'adaptive_test_start_gdpr2_enabled',     $int );
    register_setting( 'adaptive_test_before_options', 'adaptive_test_start_gdpr2_message',     [ 'sanitize_callback' => 'wp_kses_post' ] );

    // During — content/layout
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_loading',        $str );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_analysing',      $str );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_show_progress',  $int );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_show_counter',   $int );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_counter_format', $str );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_question_align', $str );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_options_align',  $str );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_dyslexic_enabled', $int );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_dyslexic_off',     $str );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_during_dyslexic_on',      $str );
    register_setting( 'adaptive_test_during_options', 'adaptive_test_encouragement_enabled',  $int );

    // After — content
    register_setting( 'adaptive_test_after_options', 'adaptive_test_show_error_rate',  [ 'default' => 1, 'sanitize_callback' => 'absint' ] );
    register_setting( 'adaptive_test_after_options', 'adaptive_test_error_rate_label', [ 'sanitize_callback' => 'wp_kses_post' ] );
    register_setting( 'adaptive_test_after_options', 'adaptive_test_error_rate',       [ 'default' => 5, 'sanitize_callback' => 'absint' ] );
    register_setting( 'adaptive_test_after_options', 'adaptive_test_after_title',      [ 'sanitize_callback' => 'wp_kses_post' ] );
    register_setting( 'adaptive_test_after_options', 'adaptive_test_after_subheading', [ 'sanitize_callback' => 'wp_kses_post' ] );
    register_setting( 'adaptive_test_after_options', 'adaptive_test_after_body',       [ 'sanitize_callback' => 'wp_kses_post' ] );

    // ── SECTIONS & FIELDS ──────────────────────────────────────────────────────

    // Before tab — content
    add_settings_section( 'adaptive_test_before_section', '', null, 'adaptive-level-test-before' );
    add_settings_field( 'adaptive_test_start_title',             __( 'Title', 'adaptive-level-test' ),                   'adaptive_test_start_title_cb',             'adaptive-level-test-before', 'adaptive_test_before_section' );
    add_settings_field( 'adaptive_test_start_subtitle',          __( 'Subtitle', 'adaptive-level-test' ),                'adaptive_test_start_subtitle_cb',          'adaptive-level-test-before', 'adaptive_test_before_section' );
    add_settings_field( 'adaptive_test_start_body',              __( 'Body', 'adaptive-level-test' ),                    'adaptive_test_start_body_cb',              'adaptive-level-test-before', 'adaptive_test_before_section' );
    add_settings_field( 'adaptive_test_start_email_placeholder', __( 'Email Placeholder', 'adaptive-level-test' ),       'adaptive_test_start_email_placeholder_cb', 'adaptive-level-test-before', 'adaptive_test_before_section' );
    add_settings_field( 'adaptive_test_start_gdpr2_enabled',     __( 'Follow-up Consent', 'adaptive-level-test' ),       'adaptive_test_start_gdpr2_enabled_cb',     'adaptive-level-test-before', 'adaptive_test_before_section' );
    add_settings_field( 'adaptive_test_start_gdpr2_message',     __( 'Follow-up Consent Message', 'adaptive-level-test' ),'adaptive_test_start_gdpr2_message_cb',    'adaptive-level-test-before', 'adaptive_test_before_section' );
    add_settings_field( 'adaptive_test_start_button_text',       __( 'Button Text', 'adaptive-level-test' ),             'adaptive_test_start_button_text_cb',       'adaptive-level-test-before', 'adaptive_test_before_section' );


    // During tab — content/layout
    add_settings_section( 'adaptive_test_during_section', '', null, 'adaptive-level-test-during' );
    add_settings_field( 'adaptive_test_during_loading',        __( 'Loading Text',      'adaptive-level-test' ), 'adaptive_test_during_loading_cb',        'adaptive-level-test-during', 'adaptive_test_during_section' );
    add_settings_field( 'adaptive_test_during_analysing',      __( 'Analysing Text',    'adaptive-level-test' ), 'adaptive_test_during_analysing_cb',      'adaptive-level-test-during', 'adaptive_test_during_section' );
    add_settings_field( 'adaptive_test_during_show_progress',  __( 'Progress Bar',      'adaptive-level-test' ), 'adaptive_test_during_show_progress_cb',  'adaptive-level-test-during', 'adaptive_test_during_section' );
    add_settings_field( 'adaptive_test_during_show_counter',   __( 'Question Counter',  'adaptive-level-test' ), 'adaptive_test_during_show_counter_cb',   'adaptive-level-test-during', 'adaptive_test_during_section' );
    add_settings_field( 'adaptive_test_during_counter_format', __( 'Counter Format',    'adaptive-level-test' ), 'adaptive_test_during_counter_format_cb', 'adaptive-level-test-during', 'adaptive_test_during_section' );
    add_settings_field( 'adaptive_test_during_question_align', __( 'Question Alignment','adaptive-level-test' ), 'adaptive_test_during_question_align_cb', 'adaptive-level-test-during', 'adaptive_test_during_section' );
    add_settings_field( 'adaptive_test_during_options_align',  __( 'Options Alignment', 'adaptive-level-test' ), 'adaptive_test_during_options_align_cb',  'adaptive-level-test-during', 'adaptive_test_during_section' );
    add_settings_field( 'adaptive_test_during_dyslexic',    __( 'Dyslexia Toggle', 'adaptive-level-test' ), 'adaptive_test_during_dyslexic_cb',    'adaptive-level-test-during', 'adaptive_test_during_section' );



    // After tab — content
    add_settings_section( 'adaptive_test_after_section', '', null, 'adaptive-level-test-after' );
    add_settings_field( 'adaptive_test_after_title',      __( 'Title',          'adaptive-level-test' ), 'adaptive_test_after_title_cb',      'adaptive-level-test-after', 'adaptive_test_after_section' );
    add_settings_field( 'adaptive_test_after_subheading', __( 'Subheading',     'adaptive-level-test' ), 'adaptive_test_after_subheading_cb', 'adaptive-level-test-after', 'adaptive_test_after_section' );
    add_settings_field( 'adaptive_test_after_body',       __( 'Body',           'adaptive-level-test' ), 'adaptive_test_after_body_cb',       'adaptive-level-test-after', 'adaptive_test_after_section' );
    add_settings_field( 'adaptive_test_show_error_rate',  __( 'Error Rate Display', 'adaptive-level-test' ), 'adaptive_test_show_error_rate_cb',  'adaptive-level-test-after', 'adaptive_test_after_section' );
    add_settings_field( 'adaptive_test_error_rate',       __( 'Error Rate (%)', 'adaptive-level-test' ), 'adaptive_test_error_rate_cb',       'adaptive-level-test-after', 'adaptive_test_after_section' );



    add_settings_section( 'adaptive_test_general_section',    '',                                              null, 'adaptive-level-test-general-settings' );
    add_settings_field( 'adaptive_test_max_batches',         __( 'Max Batches',         'adaptive-level-test' ), 'adaptive_test_max_batches_cb',         'adaptive-level-test-general-settings', 'adaptive_test_general_section' );
    add_settings_field( 'adaptive_test_target_error',        __( 'Target Confidence',   'adaptive-level-test' ), 'adaptive_test_target_error_cb',        'adaptive-level-test-general-settings', 'adaptive_test_general_section' );
    add_settings_field( 'adaptive_test_strong_label',        __( 'Strong Label',        'adaptive-level-test' ), 'adaptive_test_strong_label_cb',        'adaptive-level-test-general-settings', 'adaptive_test_general_section' );
    add_settings_field( 'adaptive_test_borderline_label',    __( 'Borderline Label',    'adaptive-level-test' ), 'adaptive_test_borderline_label_cb',    'adaptive-level-test-general-settings', 'adaptive_test_general_section' );
    add_settings_field( 'adaptive_test_primary_color',       __( 'Primary Colour',      'adaptive-level-test' ), 'adaptive_test_primary_color_cb',       'adaptive-level-test-general-settings', 'adaptive_test_general_section' );
    add_settings_section( 'adaptive_test_general_misc_section', '', null, 'adaptive-level-test-general-settings' );
    add_settings_field( 'adaptive_test_log_retention_days',  __( 'Log Retention',       'adaptive-level-test' ), 'adaptive_test_log_retention_days_cb',  'adaptive-level-test-general-settings', 'adaptive_test_general_misc_section' );
    add_settings_field( 'adaptive_test_rate_limit',          __( 'Rate Limit',          'adaptive-level-test' ), 'adaptive_test_rate_limit_cb',          'adaptive-level-test-general-settings', 'adaptive_test_general_misc_section' );
    add_settings_field( 'adaptive_test_delete_on_uninstall', __( 'Uninstall',           'adaptive-level-test' ), 'adaptive_test_delete_on_uninstall_cb', 'adaptive-level-test-general-settings', 'adaptive_test_general_misc_section' );

    // Messages tab
    add_settings_section( 'adaptive_test_msg_student_section', '', null, 'adaptive-level-test-msg-student' );
    add_settings_field( 'adaptive_test_email_subject', __( 'Subject', 'adaptive-level-test' ), 'adaptive_test_email_subject_cb', 'adaptive-level-test-msg-student', 'adaptive_test_msg_student_section' );
    add_settings_field( 'adaptive_test_email_body',    __( 'Body',    'adaptive-level-test' ), 'adaptive_test_email_body_cb',    'adaptive-level-test-msg-student', 'adaptive_test_msg_student_section' );

    add_settings_section( 'adaptive_test_msg_admin_section', '', null, 'adaptive-level-test-msg-admin' );
    add_settings_field( 'adaptive_test_admin_email',         __( 'Recipients', 'adaptive-level-test' ), 'adaptive_test_admin_email_cb',         'adaptive-level-test-msg-admin', 'adaptive_test_msg_admin_section' );
    add_settings_field( 'adaptive_test_admin_email_subject', __( 'Subject',    'adaptive-level-test' ), 'adaptive_test_admin_email_subject_cb', 'adaptive-level-test-msg-admin', 'adaptive_test_msg_admin_section' );
    add_settings_field( 'adaptive_test_admin_email_body',    __( 'Body',       'adaptive-level-test' ), 'adaptive_test_admin_email_body_cb',    'adaptive-level-test-msg-admin', 'adaptive_test_msg_admin_section' );

    add_settings_section( 'adaptive_test_msg_footer_section', '', null, 'adaptive-level-test-msg-footer' );
    add_settings_field( 'adaptive_test_email_footer', __( 'Footer HTML', 'adaptive-level-test' ), 'adaptive_test_email_footer_cb', 'adaptive-level-test-msg-footer', 'adaptive_test_msg_footer_section' );
}
add_action('admin_init', 'adaptive_test_register_settings');

function adaptive_test_email_subject_cb() {
    $default = __( 'Your English Level Test Results', 'adaptive-level-test' );
    $subject = get_option( 'adaptive_test_email_subject' ) ?: $default;
    echo '<input type="text" name="adaptive_test_email_subject" value="' . esc_attr( $subject ) . '" class="large-text">';
}

function adaptive_test_html_textarea( $name, $value, $rows, $field_id ) {
    $has_content    = '' !== trim( $value );
    $textarea_style = $has_content ? 'display:none;' : '';
    $preview_style  = $has_content ? '' : 'display:none;';
    $btn_label      = $has_content ? __( 'Edit HTML', 'adaptive-level-test' ) : __( 'Preview', 'adaptive-level-test' );
    echo '<div style="display:flex; align-items:flex-start; gap:8px;">';
    echo '<div style="flex:1;">';
    echo '<textarea id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '" rows="' . esc_attr( $rows ) . '" cols="50" class="large-text" style="width:100%; ' . esc_attr( $textarea_style ) . '">' . esc_textarea( $value ) . '</textarea>';
    echo '<div id="' . esc_attr( $field_id ) . '-preview" style="' . esc_attr( $preview_style ) . 'padding:12px; background:#fff; border:1px solid #ddd; border-radius:3px; min-height:60px; width:100%; box-sizing:border-box;">' . wp_kses_post( $value ) . '</div>';
    echo '</div>';
    echo '<button type="button" class="button" id="' . esc_attr( $field_id ) . '-btn" onclick="eslTogglePreview(\'' . esc_js( $field_id ) . '\')" style="flex-shrink:0;">' . esc_html( $btn_label ) . '</button>';
    echo '</div>';
}

function adaptive_test_weight_select( $name, $val ) {
    $weights = [ '400' => 'Normal', '500' => 'Medium', '600' => 'Semi Bold', '700' => 'Bold' ];
    echo '<select name="' . esc_attr( $name ) . '" style="width:auto;">';
    foreach ( $weights as $w => $label ) {
        echo '<option value="' . esc_attr( $w ) . '" ' . selected( $val, $w, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
}

function adaptive_test_color_size_weight( $cn, $cv, $sn, $sv, $wn, $wv ) {
    echo '<input type="color" name="' . esc_attr( $cn ) . '" value="' . esc_attr( $cv ) . '" style="vertical-align:middle;">';
    echo ' <input type="number" name="' . esc_attr( $sn ) . '" value="' . esc_attr( $sv ) . '" min="8" max="100" style="width:65px; margin-left:8px;"> px ';
    adaptive_test_weight_select( $wn, $wv );
}

function adaptive_test_border_row( $wn, $wv, $rn, $rv, $cn, $cv ) {
    echo '<input type="color" name="' . esc_attr( $cn ) . '" value="' . esc_attr( $cv ) . '" style="vertical-align:middle;">';
    echo ' <input type="number" name="' . esc_attr( $wn ) . '" value="' . esc_attr( $wv ) . '" min="0" max="20" style="width:60px; margin-left:8px;"> px width ';
    echo '<input type="number" name="' . esc_attr( $rn ) . '" value="' . esc_attr( $rv ) . '" min="0" max="100" style="width:60px; margin-left:8px;"> px radius';
}



function adaptive_test_email_body_cb() {
    $default = "<p>Dear Student,</p>\n<p>Thank you for completing our English level test.</p>\n<p>Your estimated CEFR level is: <strong>%s</strong></p>\n<p>A member of our team will be in touch shortly to discuss your results and recommend the right course for you.</p>";
    $body    = get_option( 'adaptive_test_email_body' ) ?: $default;
    adaptive_test_html_textarea( 'adaptive_test_email_body', $body, 8, 'esl-email-body' );
    // translators: %s is a CEFR level code such as B1 or C2.
    echo '<p class="description">' . esc_html__( 'Use %s as a placeholder for the student\'s CEFR level (e.g. B1). HTML is supported.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_sort_link( $column, $label, $current_orderby, $current_order, $bank_filter, $email_filter = '' ) {
    $new_order = ( $current_orderby === $column && $current_order === 'DESC' ) ? 'asc' : 'desc';
    $indicator = '';
    if ( $current_orderby === $column ) {
        $indicator = 'DESC' === $current_order ? ' &#9660;' : ' &#9650;';
    }
    $url = add_query_arg( [
        'page'         => 'adaptive-level-test',
        'tab'          => 'logs',
        'orderby'      => $column,
        'order'        => $new_order,
        'bank_filter'  => $bank_filter,
        'email_filter' => $email_filter,
    ], admin_url( 'options-general.php' ) );
    return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $indicator . '</a>';
}

function adaptive_test_sanitize_font_weight( $value ) {
    $allowed = [ '400', '500', '600', '700' ];
    return in_array( $value, $allowed, true ) ? $value : '400';
}

function adaptive_test_sanitize_admin_emails( $value ) {
    $value = trim( $value );
    if ( '' === $value ) {
        return '';
    }
    $emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $value ) ) ) );
    return implode( ', ', $emails );
}

function adaptive_test_admin_email_cb() {
    $default = get_option( 'admin_email' );
    $stored  = get_option( 'adaptive_test_admin_email' );
    $value   = ( false === $stored ) ? $default : $stored;
    echo '<input type="text" name="adaptive_test_admin_email" value="' . esc_attr( $value ) . '" class="large-text">';
    // translators: %s is the site admin email address.
    echo '<p class="description">' . sprintf( esc_html__( 'Comma-separated list of notification recipients. Defaults to the site admin (%s). Clear to disable all notifications.', 'adaptive-level-test' ), esc_html( $default ) ) . '</p>';
}

function adaptive_test_admin_email_subject_cb() {
    $default = __( 'New Level Test Completed', 'adaptive-level-test' );
    $subject = get_option( 'adaptive_test_admin_email_subject' ) ?: $default;
    echo '<input type="text" name="adaptive_test_admin_email_subject" value="' . esc_attr( $subject ) . '" class="large-text">';
}

function adaptive_test_admin_email_body_cb() {
    $default = "<p>A student has completed the English level test.</p>\n<ul>\n<li><strong>Email:</strong> %email%</li>\n<li><strong>Result:</strong> %level%</li>\n<li><strong>Question Bank:</strong> %bank%</li>\n</ul>";
    $body    = get_option( 'adaptive_test_admin_email_body' ) ?: $default;
    adaptive_test_html_textarea( 'adaptive_test_admin_email_body', $body, 8, 'esl-admin-email-body' );
    // translators: %email%, %level%, %bank% are literal template tokens users type in the email body, not printf format specifiers.
    echo '<p class="description">' . esc_html__( 'Placeholders: %email%, %level%, %bank%. HTML is supported.', 'adaptive-level-test' ) . '</p>'; // phpcs:ignore WordPress.WP.I18n.UnorderedPlaceholdersText
}

function adaptive_test_email_footer_cb() {
    $default = "<hr style=\"margin: 30px 0; border: none; border-top: 1px solid #e5e7eb;\">\n<p style=\"font-size: 0.85em; color: #6b7280;\">You are receiving this email because you completed a level test on our website.</p>";
    $footer  = get_option( 'adaptive_test_email_footer' ) ?: $default;
    adaptive_test_html_textarea( 'adaptive_test_email_footer', $footer, 5, 'esl-email-footer' );
    echo '<p class="description">' . esc_html__( 'Appended to all emails (student and admin). Accepts plain text or HTML. Clear to disable.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_start_title_cb() {
    $value = get_option( 'adaptive_test_start_title', __( 'Start Your Level Test', 'adaptive-level-test' ) );
    adaptive_test_html_textarea( 'adaptive_test_start_title', $value, 2, 'esl-start-title' );
}

function adaptive_test_start_subtitle_cb() {
    $value = get_option( 'adaptive_test_start_subtitle', __( 'Enter your email address to begin the test.', 'adaptive-level-test' ) );
    adaptive_test_html_textarea( 'adaptive_test_start_subtitle', $value, 2, 'esl-start-subtitle' );
}

function adaptive_test_start_body_cb() {
    $default = __( 'By starting the test, you agree for your results to be sent to the email address that you provide.', 'adaptive-level-test' );
    $value   = get_option( 'adaptive_test_start_body', $default );
    adaptive_test_html_textarea( 'adaptive_test_start_body', $value, 5, 'esl-start-body' );
    echo '<p class="description">' . esc_html__( 'Optional. Displayed above the email input. HTML is supported.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_start_email_placeholder_cb() {
    $value = get_option( 'adaptive_test_start_email_placeholder', 'name@example.com' );
    echo '<input type="text" name="adaptive_test_start_email_placeholder" value="' . esc_attr( $value ) . '" class="regular-text">';
}

function adaptive_test_start_gdpr2_enabled_cb() {
    $enabled = get_option( 'adaptive_test_start_gdpr2_enabled', 0 );
    echo '<label><input type="checkbox" id="esl-gdpr2-cb" name="adaptive_test_start_gdpr2_enabled" value="1" ' . checked( 1, $enabled, false ) . '> ' . esc_html__( 'Show a follow-up contact consent checkbox (optional for the user)', 'adaptive-level-test' ) . '</label>';
}

function adaptive_test_start_gdpr2_message_cb() {
    $default = __( "I'd like to receive information about English courses and relevant offers. I understand I can withdraw this consent at any time.", 'adaptive-level-test' );
    $value   = get_option( 'adaptive_test_start_gdpr2_message', $default );
    echo '<div id="esl-gdpr2-msg-cell">';
    adaptive_test_html_textarea( 'adaptive_test_start_gdpr2_message', $value, 3, 'esl-start-gdpr2' );
    echo '<p class="description">' . esc_html__( 'Optional — user can tick or skip.', 'adaptive-level-test' ) . '</p>';
    echo '</div>';
}

function adaptive_test_start_button_text_cb() {
    $value = get_option( 'adaptive_test_start_button_text', __( 'Start Test', 'adaptive-level-test' ) );
    echo '<input type="text" name="adaptive_test_start_button_text" value="' . esc_attr( $value ) . '" class="regular-text">';
}

function adaptive_test_during_loading_cb() {
    $value = get_option( 'adaptive_test_during_loading', __( 'Loading question...', 'adaptive-level-test' ) );
    echo '<input type="text" name="adaptive_test_during_loading" value="' . esc_attr( $value ) . '" class="regular-text">';
}

function adaptive_test_during_analysing_cb() {
    $value = get_option( 'adaptive_test_during_analysing', __( 'Analysing your answers...', 'adaptive-level-test' ) );
    echo '<input type="text" name="adaptive_test_during_analysing" value="' . esc_attr( $value ) . '" class="regular-text">';
}

function adaptive_test_during_show_progress_cb() {
    $value = get_option( 'adaptive_test_during_show_progress', 1 );
    echo '<label><input type="checkbox" name="adaptive_test_during_show_progress" value="1" ' . checked( 1, $value, false ) . '> ' . esc_html__( 'Show progress bar during the quiz', 'adaptive-level-test' ) . '</label>';
}

function adaptive_test_during_show_counter_cb() {
    $value = get_option( 'adaptive_test_during_show_counter', 0 );
    echo '<label><input type="checkbox" id="esl-counter-cb" name="adaptive_test_during_show_counter" value="1" ' . checked( 1, $value, false ) . '> ' . esc_html__( 'Show question counter (e.g. Question 3 of 5)', 'adaptive-level-test' ) . '</label>';
}

function adaptive_test_during_counter_format_cb() {
    $value = get_option( 'adaptive_test_during_counter_format', 'Question %n% of %total%' );
    echo '<div id="esl-counter-format-row">';
    echo '<input type="text" name="adaptive_test_during_counter_format" value="' . esc_attr( $value ) . '" class="regular-text">';
    echo '<p class="description">' . esc_html__( 'Placeholders: %n% = current question number, %total% = questions per batch (5).', 'adaptive-level-test' ) . '</p>';
    echo '</div>';
}

function adaptive_test_during_question_align_cb() {
    $value   = get_option( 'adaptive_test_during_question_align', 'center' );
    $options = [ 'left' => __( 'Left', 'adaptive-level-test' ), 'center' => __( 'Center', 'adaptive-level-test' ), 'right' => __( 'Right', 'adaptive-level-test' ) ];
    echo '<select name="adaptive_test_during_question_align">';
    foreach ( $options as $val => $label ) {
        echo '<option value="' . esc_attr( $val ) . '" ' . selected( $value, $val, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
}

function adaptive_test_during_options_align_cb() {
    $value   = get_option( 'adaptive_test_during_options_align', 'center' );
    $options = [ 'left' => __( 'Left', 'adaptive-level-test' ), 'center' => __( 'Center', 'adaptive-level-test' ), 'right' => __( 'Right', 'adaptive-level-test' ) ];
    echo '<select name="adaptive_test_during_options_align">';
    foreach ( $options as $val => $label ) {
        echo '<option value="' . esc_attr( $val ) . '" ' . selected( $value, $val, false ) . '>' . esc_html( $label ) . '</option>';
    }
    echo '</select>';
}

function adaptive_test_show_error_rate_cb() {
    $enabled = get_option( 'adaptive_test_show_error_rate', 1 );
    echo '<label><input type="checkbox" id="esl-show-error-rate-cb" name="adaptive_test_show_error_rate" value="1" ' . checked( 1, $enabled, false ) . '> ' . esc_html__( 'Show error rate margin on the results scale', 'adaptive-level-test' ) . '</label>';
}

function adaptive_test_error_rate_cb() {
    $default_label = __( 'Margin of Error: ±{rate}%', 'adaptive-level-test' );
    $label = get_option( 'adaptive_test_error_rate_label', $default_label );
    $rate  = get_option( 'adaptive_test_error_rate', 5 );
    echo '<div id="esl-error-rate-cell">';
    adaptive_test_html_textarea( 'adaptive_test_error_rate_label', $label, 2, 'esl-error-rate-label' );
    echo '<p class="description" style="margin-bottom:10px;">' . esc_html__( 'Use {rate} for the percentage.', 'adaptive-level-test' ) . '</p>';
    echo '<input type="number" name="adaptive_test_error_rate" value="' . esc_attr( $rate ) . '" class="small-text" min="0" max="100"> %';
    echo '<p class="description">' . esc_html__( 'Margin of error displayed on the results scale.', 'adaptive-level-test' ) . '</p>';
    echo '</div>';
}

function adaptive_test_after_title_cb() {
    $value = get_option( 'adaptive_test_after_title', __( 'Test Complete', 'adaptive-level-test' ) );
    adaptive_test_html_textarea( 'adaptive_test_after_title', $value, 2, 'esl-after-title' );
}

function adaptive_test_after_subheading_cb() {
    $value = get_option( 'adaptive_test_after_subheading', __( 'Your estimated level is:', 'adaptive-level-test' ) );
    adaptive_test_html_textarea( 'adaptive_test_after_subheading', $value, 2, 'esl-after-subheading' );
}

function adaptive_test_after_body_cb() {
    $default = __( 'A copy of your result has been emailed to you.', 'adaptive-level-test' );
    $value   = get_option( 'adaptive_test_after_body', $default );
    adaptive_test_html_textarea( 'adaptive_test_after_body', $value, 3, 'esl-after-body' );
    echo '<p class="description">' . esc_html__( 'Displayed below the scale and above the retake button. HTML is supported.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_during_dyslexic_cb() {
    $enabled = get_option( 'adaptive_test_during_dyslexic_enabled', 1 );
    $off     = get_option( 'adaptive_test_during_dyslexic_off', __( 'Change to dyslexia friendly font', 'adaptive-level-test' ) );
    $on      = get_option( 'adaptive_test_during_dyslexic_on',  __( 'Change to regular font',           'adaptive-level-test' ) );
    echo '<p><label><input type="checkbox" id="esl-dyslexic-cb" name="adaptive_test_during_dyslexic_enabled" value="1" ' . checked( 1, $enabled, false ) . '> ' . esc_html__( 'Show dyslexia-friendly font toggle during the test', 'adaptive-level-test' ) . '</label></p>';
    echo '<div id="esl-dyslexic-details" style="' . ( $enabled ? '' : 'display:none;' ) . '">';
    echo '<p style="margin-top:8px;"><label>' . esc_html__( 'Button label (off):', 'adaptive-level-test' ) . ' <input type="text" name="adaptive_test_during_dyslexic_off" value="' . esc_attr( $off ) . '" class="regular-text"></label></p>';
    echo '<p><label>' . esc_html__( 'Button label (on):',  'adaptive-level-test' ) . ' <input type="text" name="adaptive_test_during_dyslexic_on"  value="' . esc_attr( $on  ) . '" class="regular-text"></label></p>';
    echo '<p class="description">' . esc_html__( 'Text shown on the font-toggle button before and after switching to the dyslexia-friendly font.', 'adaptive-level-test' ) . '</p>';
    echo '</div>';
}


function adaptive_test_primary_color_cb() {
    $value = get_option( 'adaptive_test_primary_color', '' ) ?: '#2563eb';
    echo '<input type="color" name="adaptive_test_primary_color" value="' . esc_attr( $value ) . '">';
    echo '<p class="description">' . esc_html__( 'Sets the main accent colour used for buttons, progress bars, and highlights. Individual colour overrides in the Customise tab take precedence.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_log_retention_days_cb() {
    $value = absint( get_option( 'adaptive_test_log_retention_days', 90 ) );
    echo '<input type="number" name="adaptive_test_log_retention_days" value="' . esc_attr( $value ) . '" min="0" max="3650" style="width:80px;"> ';
    echo '<span>' . esc_html__( 'days', 'adaptive-level-test' ) . '</span>';
    echo '<p class="description">' . esc_html__( 'Attempt logs older than this are automatically deleted each day. Set to 0 to keep logs indefinitely.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_rate_limit_cb() {
    $value = (int) get_option( 'adaptive_test_rate_limit', 5 );
    echo '<input type="number" name="adaptive_test_rate_limit" value="' . esc_attr( $value ) . '" min="1" max="100" style="width:80px;"> ';
    echo '<span>' . esc_html__( 'test starts per IP address per hour', 'adaptive-level-test' ) . '</span>';
    echo '<p class="description">' . esc_html__( 'Limits how many times the same IP address can start the test within one hour. Helps prevent automated abuse. Raise this if you have multiple students sharing the same network connection.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_delete_on_uninstall_cb() {
    $checked = (bool) get_option( 'adaptive_test_delete_on_uninstall', 0 );
    echo '<label>';
    echo '<input type="checkbox" name="adaptive_test_delete_on_uninstall" value="1"' . checked( $checked, true, false ) . '>';
    echo ' ' . esc_html__( 'Remove all plugin data when the plugin is deleted', 'adaptive-level-test' );
    echo '</label>';
    echo '<p class="description" style="color:#b32d2e;">' . esc_html__( 'If checked, deleting this plugin will permanently drop all database tables and settings. This cannot be undone.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_max_batches_cb() {
    $value = max( 1, (int) get_option( 'adaptive_test_max_batches', 10 ) );
    echo '<input type="number" name="adaptive_test_max_batches" value="' . esc_attr( $value ) . '" min="1" max="20" style="width:70px;"> ';
    echo '<span>' . esc_html__( 'batches of 5 questions', 'adaptive-level-test' ) . '</span>';
    echo '<p class="description">' . esc_html__( 'The test stops early when the confidence threshold is reached; this is the hard ceiling. Each batch is 5 questions, so 10 batches = up to 50 questions.', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_target_error_cb() {
    $value = max( 1, (int) get_option( 'adaptive_test_target_error', 8 ) );
    echo '<input type="number" name="adaptive_test_target_error" value="' . esc_attr( $value ) . '" min="1" max="50" style="width:70px;"> %';
    echo '<p class="description">' . esc_html__( 'The test stops as soon as the ability estimate is accurate to within this margin. Lower values give more precision but a longer test. 8% corresponds to the DIALANG standard (SE ≤ 0.32 logits).', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_strong_label_cb() {
    $value = get_option( 'adaptive_test_strong_label', __( 'Strong', 'adaptive-level-test' ) );
    echo '<input type="text" name="adaptive_test_strong_label" value="' . esc_attr( $value ) . '" class="regular-text">';
    echo '<p class="description">' . esc_html__( 'Prepended to the level in the results log when the student\'s ability is in the upper third of the level segment. e.g. "Strong B2".', 'adaptive-level-test' ) . '</p>';
}

function adaptive_test_borderline_label_cb() {
    $value = get_option( 'adaptive_test_borderline_label', __( 'Borderline', 'adaptive-level-test' ) );
    echo '<input type="text" name="adaptive_test_borderline_label" value="' . esc_attr( $value ) . '" class="regular-text">';
    echo '<p class="description">' . esc_html__( 'Prepended to the level in the results log when the student\'s ability is in the lower third of the level segment. e.g. "Borderline B2".', 'adaptive-level-test' ) . '</p>';
}

/**
 * Add the settings page to the admin menu.
 */
function adaptive_test_add_admin_menu() {
    add_options_page(
        __('Adaptive Level Test Administration', 'adaptive-level-test'),
        __('Adaptive Level Test', 'adaptive-level-test'),
        'edit_others_posts',
        'adaptive-level-test',
        'adaptive_test_settings_page_html'
    );
}
add_action('admin_menu', 'adaptive_test_add_admin_menu');

function adaptive_test_admin_enqueue() {
    $screen = get_current_screen();
    if ( ! $screen || 'settings_page_adaptive-level-test' !== $screen->id ) return;

    // ── CSS ──────────────────────────────────────────────────────────────────
    wp_register_style( 'adaptive-test-admin', false, [], ADAPTIVE_LEVEL_TEST_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version constant used above
    wp_enqueue_style( 'adaptive-test-admin' );

    $font_url   = esc_url( plugins_url( '../assets/fonts/OpenDyslexic-Regular.woff2', __FILE__ ) );
    $inline_css  = "@font-face{font-family:'OpenDyslexic';src:url('{$font_url}')format('woff2');font-weight:normal;font-style:normal;font-display:swap;}";
    $inline_css .= "#esl-prev-card.esl-dyslexic #esl-prev-question,#esl-prev-card.esl-dyslexic .esl-prev-option{font-family:'OpenDyslexic',sans-serif;}";
    $inline_css .= '#esl-unsaved-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:99999;align-items:center;justify-content:center;}';
    $inline_css .= '#esl-unsaved-overlay.esl-visible{display:flex;}';
    $inline_css .= '#esl-unsaved-dialog{background:#fff;border-radius:4px;padding:24px 28px;max-width:420px;width:90%;box-shadow:0 4px 24px rgba(0,0,0,.18);}';
    $inline_css .= '#esl-unsaved-dialog h3{margin:0 0 8px;font-size:1rem;color:#1d2327;}';
    $inline_css .= '#esl-unsaved-dialog p{margin:0 0 16px;color:#50575e;font-size:0.9rem;line-height:1.5;}';
    $inline_css .= '#esl-unsaved-dialog ul{margin:0 0 20px;padding-left:20px;color:#50575e;font-size:0.9rem;}';
    $inline_css .= '.esl-unsaved-actions{display:flex;gap:10px;justify-content:flex-end;}';
    $inline_css .= '#esl-unsaved-leave{background:none;border:1px solid #c3c4c7;color:#50575e;padding:6px 14px;border-radius:3px;cursor:pointer;font-size:0.875rem;}';
    $inline_css .= '#esl-unsaved-stay{background:#2271b1;border:none;color:#fff;padding:6px 14px;border-radius:3px;cursor:pointer;font-size:0.875rem;font-weight:600;}';
    $inline_css .= '#esl-unsaved-stay:focus{outline:2px solid #2271b1;outline-offset:2px;}';
    $inline_css .= '.esl-subnav a{display:inline-block;padding:5px 10px;margin-right:4px;margin-bottom:-1px;border:1px solid #c3c4c7;border-bottom:none;border-radius:0;text-decoration:none;color:#50575e;background:#dcdcde;font-size:14px;font-weight:600;line-height:1.71428571;}';
    $inline_css .= '.esl-subnav a:hover{background:#fff;color:#1d2327;}';
    $inline_css .= '.esl-subnav a.active{background:#f0f0f1;color:#1d2327;border-bottom:1px solid #f0f0f1;}';
    wp_add_inline_style( 'adaptive-test-admin', $inline_css );

    // ── JS ───────────────────────────────────────────────────────────────────
    wp_enqueue_script(
        'adaptive-test-admin-preview',
        plugins_url( '../assets/js/admin-preview.js', __FILE__ ),
        [],
        ADAPTIVE_LEVEL_TEST_VERSION,
        true
    );
    wp_localize_script( 'adaptive-test-admin-preview', 'eslAdminData', [
        'primaryColor'  => get_option( 'adaptive_test_primary_color', '' ) ?: '#2563eb',
        'labelEditHtml' => __( 'Edit HTML', 'adaptive-level-test' ),
        'labelPreview'  => __( 'Preview', 'adaptive-level-test' ),
    ] );

    wp_enqueue_script(
        'adaptive-test-admin-unsaved',
        plugins_url( '../assets/js/admin-unsaved.js', __FILE__ ),
        [],
        ADAPTIVE_LEVEL_TEST_VERSION,
        true
    );

    // Inline select-all for the logs bulk form (guarded: element only exists on logs tab).
    wp_register_script( 'adaptive-test-admin-inline', false, [ 'adaptive-test-admin-unsaved' ], ADAPTIVE_LEVEL_TEST_VERSION, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version constant used above
    wp_enqueue_script( 'adaptive-test-admin-inline' );
    wp_add_inline_script(
        'adaptive-test-admin-inline',
        '(function(){var el=document.getElementById("esl-select-all");if(el){el.addEventListener("change",function(){document.querySelectorAll("#esl-logs-bulk-form input[name=\'log_ids[]\']").forEach(function(cb){cb.checked=this.checked;},this);});}})()'
    );

}
add_action( 'admin_enqueue_scripts', 'adaptive_test_admin_enqueue' );



function adaptive_test_unsaved_changes_js() {
    $screen = get_current_screen();
    if ( ! $screen || 'settings_page_adaptive-level-test' !== $screen->id ) return;
    ?>
    <div id="esl-unsaved-overlay" role="dialog" aria-modal="true" aria-labelledby="esl-unsaved-title">
        <div id="esl-unsaved-dialog">
            <h3 id="esl-unsaved-title">Unsaved changes</h3>
            <p>You have unsaved changes to:</p>
            <ul id="esl-unsaved-list"></ul>
            <div class="esl-unsaved-actions">
                <button id="esl-unsaved-leave" type="button">Leave without saving</button>
                <button id="esl-unsaved-stay"  type="button">Stay on page</button>
            </div>
        </div>
    </div>
    <?php
}
add_action( 'admin_footer', 'adaptive_test_unsaved_changes_js' );

function adaptive_test_settings_page_html() {
    if ( ! current_user_can( 'edit_others_posts' ) ) {
        return;
    }
    $is_admin = current_user_can( 'manage_options' );

    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- all GET reads in this function are display-only (tab, sub-tab, filter, sort, message params); all data-mutating paths are handled by adaptive_test_handle_question_actions() which calls check_admin_referer()
    $allowed_tabs = [ 'general', 'quiz', 'messages', 'questions', 'logs' ];
    $active_tab   = ( isset( $_GET['tab'] ) && in_array( $_GET['tab'], $allowed_tabs, true ) )
        ? sanitize_key( wp_unslash( $_GET['tab'] ) )
        : 'general';
    if ( ! $is_admin ) {
        $active_tab = 'logs';
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <?php if ( isset( $_GET['message'] ) ) : ?>
            <?php if ( 'imported' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php
                    // translators: %d is the number of questions imported.
                    printf( esc_html__( '%d questions imported successfully.', 'adaptive-level-test' ), absint( wp_unslash( $_GET['count'] ?? 0 ) ) ); ?></p></div>
                <?php if ( ! empty( $_GET['skipped'] ) ) : ?>
                <div class="notice notice-warning is-dismissible"><p><?php
                    // translators: %d is the number of questions skipped due to an unrecognised CEFR level.
                    printf( esc_html__( '%d questions were not imported — they contained an unrecognised CEFR level and were skipped.', 'adaptive-level-test' ), absint( wp_unslash( $_GET['skipped'] ) ) ); ?>
                </p></div>
                <?php endif; ?>
            <?php elseif ( 'reseeded' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Database reset and re-seeded with sample questions.', 'adaptive-level-test' ); ?></p></div>
            <?php elseif ( 'bank_renamed' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Question bank renamed successfully.', 'adaptive-level-test' ); ?></p></div>
            <?php elseif ( 'bank_duplicated' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Question bank duplicated successfully.', 'adaptive-level-test' ); ?></p></div>
            <?php elseif ( 'start_screen_reset' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Quiz start screen reset to defaults.', 'adaptive-level-test' ); ?></p></div>
            <?php elseif ( 'during_reset' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'During the Quiz settings reset to defaults.', 'adaptive-level-test' ); ?></p></div>
            <?php elseif ( 'after_reset' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'After the Quiz settings reset to defaults.', 'adaptive-level-test' ); ?></p></div>
            <?php elseif ( 'emails_reset' === $_GET['message'] ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Email templates reset to defaults.', 'adaptive-level-test' ); ?></p></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ( isset( $_GET['settings-updated'] ) ) :
            $adaptive_test_save_info = get_transient( 'adaptive_test_save_' . get_current_user_id() );
            delete_transient( 'adaptive_test_save_' . get_current_user_id() );
            if ( $adaptive_test_save_info ) :
                if ( $adaptive_test_save_info['changed'] ) : ?>
                    <div class="notice notice-success is-dismissible"><p><strong><?php echo esc_html( $adaptive_test_save_info['label'] ); ?></strong> <?php esc_html_e( 'saved.', 'adaptive-level-test' ); ?></p></div>
                <?php else : ?>
                    <div class="notice notice-info is-dismissible"><p><strong><?php echo esc_html( $adaptive_test_save_info['label'] ); ?></strong> — <?php esc_html_e( 'no changes detected.', 'adaptive-level-test' ); ?></p></div>
                <?php endif;
            endif;
        endif; ?>

        <?php if ( $is_admin ) : ?>
        <nav class="nav-tab-wrapper">
            <a href="?page=adaptive-level-test&tab=general" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General Settings', 'adaptive-level-test' ); ?></a>
            <a href="?page=adaptive-level-test&tab=quiz" class="nav-tab <?php echo 'quiz' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Quiz Settings', 'adaptive-level-test' ); ?></a>
            <a href="?page=adaptive-level-test&tab=messages" class="nav-tab <?php echo 'messages' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Messages', 'adaptive-level-test' ); ?></a>
            <a href="?page=adaptive-level-test&tab=questions" class="nav-tab <?php echo 'questions' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Questions', 'adaptive-level-test' ); ?></a>
            <a href="?page=adaptive-level-test&tab=logs" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Attempt Logs', 'adaptive-level-test' ); ?></a>
        </nav>
        <?php endif; ?>

        <?php if ($active_tab == 'general'): ?>
            <form action="options.php" method="post" style="margin-top: 20px;">
                <?php settings_fields('adaptive_test_options'); ?>


                <div style="background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px; margin-bottom:20px;">
                    <h2 style="margin-top:0;"><?php esc_html_e( 'Plugin Settings', 'adaptive-level-test' ); ?></h2>
                    <?php do_settings_sections('adaptive-level-test-general-settings'); ?>
                </div>

                <?php submit_button(__('Save Settings', 'adaptive-level-test')); ?>
            </form>
        <?php elseif ($active_tab == 'quiz'):
            $allowed_subs = [ 'before', 'during', 'after' ];
            $active_sub   = ( isset( $_GET['sub'] ) && in_array( $_GET['sub'], $allowed_subs, true ) )
                ? sanitize_key( wp_unslash( $_GET['sub'] ) ) : 'before';
            $sub_nav = [
                'before' => __( '1 — Before the Quiz', 'adaptive-level-test' ),
                'during' => __( '2 — During the Quiz', 'adaptive-level-test' ),
                'after'  => __( '3 — After the Quiz',  'adaptive-level-test' ),
            ];
            ?>
            <div class="esl-subnav" style="margin: 16px 0 0; border-bottom: 1px solid #c3c4c7;">
                <?php foreach ( $sub_nav as $key => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'adaptive-level-test', 'tab' => 'quiz', 'sub' => $key ], admin_url( 'options-general.php' ) ) ); ?>" <?php echo $active_sub === $key ? 'class="active"' : ''; ?>>
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div style="display:flex; gap:30px; margin-top:20px;">

                <!-- Settings column -->
                <div style="flex:0 0 60%; min-width:0; box-sizing:border-box;">
                    <?php
                    $sub_panel_titles = [
                        'before' => __( 'Before the Quiz', 'adaptive-level-test' ),
                        'during' => __( 'During the Quiz', 'adaptive-level-test' ),
                        'after'  => __( 'After the Quiz',  'adaptive-level-test' ),
                    ];
                    ?>
                    <!-- Content form — free + pro -->
                    <form action="options.php" method="post">
                        <?php settings_fields( 'adaptive_test_' . $active_sub . '_options' ); ?>
                        <div style="background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px; margin-bottom:20px;">
                            <h2 style="margin-top:0;"><?php echo esc_html( $sub_panel_titles[ $active_sub ] ); ?></h2>
                            <?php do_settings_sections( 'adaptive-level-test-' . $active_sub ); ?>
                        </div>
                        <?php submit_button( __( 'Save Settings', 'adaptive-level-test' ) ); ?>
                    </form>

                    <?php do_action( 'adaptive_test_after_' . $active_sub . '_settings' ); ?>
                    <?php if ( 'before' === $active_sub ) : ?>
                        <hr>
                        <h3><?php esc_html_e( 'Reset Start Screen', 'adaptive-level-test' ); ?></h3>
                        <form method="post" action="">
                            <input type="hidden" name="adaptive_test_action" value="reset_start_screen">
                            <?php wp_nonce_field( 'adaptive_test_reset_start_screen_nonce' ); ?>
                            <?php submit_button( __( 'Reset to Defaults', 'adaptive-level-test' ), 'secondary', 'submit', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Reset all Quiz Start Screen settings to their defaults?', 'adaptive-level-test' ) ) . "');" ] ); ?>
                        </form>
                    <?php elseif ( 'during' === $active_sub ) : ?>
                        <hr>
                        <h3><?php esc_html_e( 'Reset During the Quiz', 'adaptive-level-test' ); ?></h3>
                        <form method="post" action="">
                            <input type="hidden" name="adaptive_test_action" value="reset_during">
                            <?php wp_nonce_field( 'adaptive_test_reset_during_nonce' ); ?>
                            <?php submit_button( __( 'Reset to Defaults', 'adaptive-level-test' ), 'secondary', 'submit', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Reset all During the Quiz settings to their defaults?', 'adaptive-level-test' ) ) . "');" ] ); ?>
                        </form>
                    <?php elseif ( 'after' === $active_sub ) : ?>
                        <hr>
                        <h3><?php esc_html_e( 'Reset After the Quiz', 'adaptive-level-test' ); ?></h3>
                        <form method="post" action="">
                            <input type="hidden" name="adaptive_test_action" value="reset_after">
                            <?php wp_nonce_field( 'adaptive_test_reset_after_nonce' ); ?>
                            <?php submit_button( __( 'Reset to Defaults', 'adaptive-level-test' ), 'secondary', 'submit', false, [ 'onclick' => "return confirm('" . esc_js( __( 'Reset all After the Quiz settings to their defaults?', 'adaptive-level-test' ) ) . "');" ] ); ?>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Preview column -->
                <div style="flex:0 0 calc(40% - 30px); min-width:0; box-sizing:border-box;">
                    <div style="position:sticky; top:32px; background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px;">
                        <h2 style="margin-top:0; text-align:center; font-size:0.8em; letter-spacing:.12em; text-transform:uppercase; color:#6b7280; border-bottom:1px solid #ddd; padding-bottom:12px; margin-bottom:16px;">Preview</h2>

                        <?php
                        $pv_primary = get_option( 'adaptive_test_primary_color', '' ) ?: '#2563eb';
                        if ( 'before' === $active_sub ) :
                            $pv_title        = get_option( 'adaptive_test_start_title',    __( 'Start Your Level Test', 'adaptive-level-test' ) );
                            $pv_subtitle     = get_option( 'adaptive_test_start_subtitle', __( 'Enter your email address to begin the test.', 'adaptive-level-test' ) );
                            $pv_body         = get_option( 'adaptive_test_start_body', '' );
                            $pv_ph           = get_option( 'adaptive_test_start_email_placeholder', 'name@example.com' );
                            $pv_btn          = get_option( 'adaptive_test_start_button_text', __( 'Start Test', 'adaptive-level-test' ) );
                            $pv_gdpr2_on     = (bool) get_option( 'adaptive_test_start_gdpr2_enabled', 0 );
                            $pv_gdpr2        = get_option( 'adaptive_test_start_gdpr2_message', __( "I'd like to receive information about English courses and relevant offers. I understand I can withdraw this consent at any time.", 'adaptive-level-test' ) );
                            $pv_btn_color    = get_option( 'adaptive_test_before_btn_color',         $pv_primary ) ?: $pv_primary;
                            $pv_btn_txt      = get_option( 'adaptive_test_before_btn_text_color',    '#ffffff' ) ?: '#ffffff';
                            $pv_btn_bdc      = get_option( 'adaptive_test_before_btn_border_color',  $pv_primary ) ?: $pv_primary;
                            $pv_btn_bdw      = absint( get_option( 'adaptive_test_before_btn_border_width',  2 ) );
                            $pv_btn_bdr      = absint( get_option( 'adaptive_test_before_btn_border_radius', 12 ) );
                            $pv_bg           = get_option( 'adaptive_test_before_box_bg',            '#ffffff' ) ?: '#ffffff';
                            $pv_radius       = absint( get_option( 'adaptive_test_before_box_border_radius', 16 ) );
                            $pv_bdr_w        = absint( get_option( 'adaptive_test_before_box_border_width', 0 ) );
                            $pv_bdr_c        = get_option( 'adaptive_test_before_box_border_color', '#e5e7eb' ) ?: '#e5e7eb';
                            $pv_shadow       = get_option( 'adaptive_test_before_box_shadow', 1 ) ? '0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06)' : 'none';
                            $pv_card_border  = $pv_bdr_w ? $pv_bdr_w . 'px solid ' . $pv_bdr_c : 'none';
                        ?>
                        <div style="background:#f3f4f6; padding:16px; border-radius:8px;">
                            <div id="esl-prev-card" style="background:<?php echo esc_attr( $pv_bg ); ?>; border-radius:<?php echo absint( $pv_radius ); ?>px; border:<?php echo esc_attr( $pv_card_border ); ?>; box-shadow:<?php echo esc_attr( $pv_shadow ); ?>; padding:28px; box-sizing:border-box;">
                                <div id="esl-prev-title" style="font-size:1.1em; font-weight:700; text-align:center; margin:0 0 10px; line-height:1.3;"><?php echo wp_kses_post( $pv_title ); ?></div>
                                <div id="esl-prev-subtitle" style="text-align:center; color:#6b7280; margin:0 0 10px; font-size:0.85em;"><?php echo wp_kses_post( $pv_subtitle ); ?></div>
                                <div id="esl-prev-body" style="<?php echo esc_attr( $pv_body ? '' : 'display:none;' ); ?> text-align:center; margin:0 0 10px; font-size:0.78em; color:#6b7280;"><?php echo wp_kses_post( $pv_body ); ?></div>
                                <input type="text" id="esl-prev-email" disabled placeholder="<?php echo esc_attr( $pv_ph ); ?>" style="width:100%; text-align:center; border:2px solid #e5e7eb; border-radius:10px; padding:10px; box-sizing:border-box; font-size:0.82em; color:#9ca3af; background:#fff; margin-bottom:10px;">
                                <div id="esl-prev-gdpr" style="<?php echo $pv_gdpr2_on ? 'display:flex;' : 'display:none;'; ?> align-items:flex-start; gap:6px; margin:6px 0 10px; font-size:0.78em; color:#6b7280;">
                                    <span>☐</span><span id="esl-prev-gdpr-msg"><?php echo wp_kses_post( $pv_gdpr2 ); ?></span>
                                </div>
                                <button type="button" id="esl-prev-btn" disabled style="width:100%; background:<?php echo esc_attr( $pv_btn_color ); ?>; color:<?php echo esc_attr( $pv_btn_txt ); ?>; border:<?php echo absint( $pv_btn_bdw ); ?>px solid <?php echo esc_attr( $pv_btn_bdc ); ?>; border-radius:<?php echo absint( $pv_btn_bdr ); ?>px; padding:11px; font-weight:600; font-size:0.88em; cursor:default;"><?php echo esc_html( $pv_btn ); ?></button>
                            </div>
                        </div>

                        <?php elseif ( 'during' === $active_sub ) :
                            $pv_show_prog      = (bool) get_option( 'adaptive_test_during_show_progress', 1 );
                            $pv_show_ctr       = (bool) get_option( 'adaptive_test_during_show_counter', 0 );
                            $pv_ctr_fmt        = get_option( 'adaptive_test_during_counter_format', 'Question %n% of %total%' );
                            $pv_q_align        = get_option( 'adaptive_test_during_question_align', 'center' );
                            $pv_opt_align      = get_option( 'adaptive_test_during_options_align', 'center' );
                            $pv_bg             = get_option( 'adaptive_test_during_box_bg',            '#ffffff' ) ?: '#ffffff';
                            $pv_text           = get_option( 'adaptive_test_during_box_text_color',    '#1f2937' ) ?: '#1f2937';
                            $pv_radius         = absint( get_option( 'adaptive_test_during_box_border_radius', 16 ) );
                            $pv_bdr_w          = absint( get_option( 'adaptive_test_during_box_border_width', 0 ) );
                            $pv_bdr_c          = get_option( 'adaptive_test_during_box_border_color', '#e5e7eb' ) ?: '#e5e7eb';
                            $pv_shadow         = get_option( 'adaptive_test_during_box_shadow', 1 ) ? '0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06)' : 'none';
                            $pv_card_bdr       = $pv_bdr_w ? $pv_bdr_w . 'px solid ' . $pv_bdr_c : 'none';
                            $pv_ctr_color      = get_option( 'adaptive_test_during_counter_color',        '#6b7280' ) ?: '#6b7280';
                            $pv_prog_color     = get_option( 'adaptive_test_during_progress_color',       $pv_primary ) ?: $pv_primary;
                            $pv_sel_color      = get_option( 'adaptive_test_during_option_selected_color', $pv_primary ) ?: $pv_primary;
                            $pv_sel_text       = get_option( 'adaptive_test_during_option_selected_text', '#ffffff' ) ?: '#ffffff';
                            $pv_opt_bw         = absint( get_option( 'adaptive_test_during_option_border_width',  2 ) );
                            $pv_opt_rad        = absint( get_option( 'adaptive_test_during_option_border_radius', 12 ) );
                            $pv_opt_bc         = get_option( 'adaptive_test_during_option_border_color', '#e5e7eb' ) ?: '#e5e7eb';
                            $pv_ctr_text       = str_replace( [ '%n%', '%total%' ], [ '2', '5' ], $pv_ctr_fmt );
                            $pv_dyslexic_on     = (bool) get_option( 'adaptive_test_during_dyslexic_enabled', 1 );
                            $pv_dyslexic_label  = get_option( 'adaptive_test_during_dyslexic_off', __( 'Change to dyslexia friendly font', 'adaptive-level-test' ) );
                            $pv_dys_color       = get_option( 'adaptive_test_during_dyslexic_color',  '#6b7280' ) ?: '#6b7280';
                            $pv_dys_bg          = get_option( 'adaptive_test_during_dyslexic_bg',     '#ffffff' ) ?: '#ffffff';
                            $pv_dys_size        = absint( get_option( 'adaptive_test_during_dyslexic_size', 11 ) );
                            $pv_dys_bdw         = absint( get_option( 'adaptive_test_during_dyslexic_border_width',  1 ) );
                            $pv_dys_bdc         = get_option( 'adaptive_test_during_dyslexic_border_color', '#e5e7eb' ) ?: '#e5e7eb';
                            $pv_dys_bdr         = absint( get_option( 'adaptive_test_during_dyslexic_border_radius', 20 ) );
                            $pv_dys_border      = $pv_dys_bdw ? $pv_dys_bdw . 'px solid ' . esc_attr( $pv_dys_bdc ) : 'none';
                            // Random preview question from default bank
                            global $wpdb;
                            $pv_rq = $wpdb->get_row( "SELECT question_text, options, answer FROM {$wpdb->prefix}adaptive_questions WHERE bank_id = 1 ORDER BY RAND() LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
                            $pv_question_text  = $pv_rq ? $pv_rq['question_text'] : 'She ________ usually drink coffee.';
                            $pv_options_raw    = $pv_rq ? json_decode( $pv_rq['options'], true ) : null;
                            $pv_q_options      = is_array( $pv_options_raw ) ? $pv_options_raw : ["doesn't", "don't", "isn't", "not"];
                            $pv_q_answer       = $pv_rq ? $pv_rq['answer'] : "doesn't";
                            shuffle( $pv_q_options );
                        ?>
                        <div style="background:#f3f4f6; padding:16px; border-radius:8px;">
                            <div id="esl-prev-card" style="position:relative; overflow:hidden; background:<?php echo esc_attr( $pv_bg ); ?>; border-radius:<?php echo absint( $pv_radius ); ?>px; border:<?php echo esc_attr( $pv_card_bdr ); ?>; box-shadow:<?php echo esc_attr( $pv_shadow ); ?>; padding:28px; box-sizing:border-box; color:<?php echo esc_attr( $pv_text ); ?>;">
                                <button type="button" id="esl-prev-dyslexic-toggle" style="<?php echo $pv_dyslexic_on ? '' : 'display:none;'; ?> position:absolute; top:6px; left:6px; background:<?php echo esc_attr( $pv_dys_bg ); ?>; border:<?php echo esc_attr( $pv_dys_border ); ?>; border-radius:<?php echo absint( $pv_dys_bdr ); ?>px; padding:8px 14px; font-size:<?php echo absint( $pv_dys_size ); ?>px; color:<?php echo esc_attr( $pv_dys_color ); ?>; cursor:pointer; line-height:1.6;"><?php echo esc_html( $pv_dyslexic_label ); ?></button>
                                <div id="esl-prev-progress-wrap" style="<?php echo $pv_show_prog ? '' : 'display:none;'; ?> background:#e5e7eb; height:6px; border-radius:10px; margin-top:<?php echo $pv_dyslexic_on ? '28' : '0'; ?>px; margin-bottom:20px; overflow:hidden;">
                                    <div id="esl-prev-progress-bar" style="background:<?php echo esc_attr( $pv_prog_color ); ?>; width:40%; height:100%;"></div>
                                </div>
                                <div id="esl-prev-counter" style="<?php echo $pv_show_ctr ? '' : 'display:none;'; ?> text-align:center; font-size:0.8em; color:<?php echo esc_attr( $pv_ctr_color ); ?>; margin-bottom:10px;"><?php echo esc_html( $pv_ctr_text ); ?></div>
                                <p id="esl-prev-question" style="font-size:0.95em; font-weight:700; text-align:<?php echo esc_attr( $pv_q_align ); ?>; margin:0 0 16px; line-height:1.4; color:inherit;"><?php echo esc_html( $pv_question_text ); ?></p>
                                <div style="display:flex; flex-direction:column; gap:8px;">
                                    <?php foreach ( $pv_q_options as $pv_opt ) :
                                        $is_selected = ( strtolower( $pv_opt ) === strtolower( $pv_q_answer ) );
                                        if ( $is_selected ) : ?>
                                    <div class="esl-prev-option esl-prev-selected" style="border:<?php echo absint( $pv_opt_bw ); ?>px solid <?php echo esc_attr( $pv_sel_color ); ?>; border-radius:<?php echo absint( $pv_opt_rad ); ?>px; padding:9px 12px; font-size:0.82em; background:<?php echo esc_attr( $pv_sel_color ); ?>; color:<?php echo esc_attr( $pv_sel_text ); ?>; text-align:<?php echo esc_attr( $pv_opt_align ); ?>;"><?php echo esc_html( $pv_opt ); ?></div>
                                        <?php else : ?>
                                    <div class="esl-prev-option" style="border:<?php echo absint( $pv_opt_bw ); ?>px solid <?php echo esc_attr( $pv_opt_bc ); ?>; border-radius:<?php echo absint( $pv_opt_rad ); ?>px; padding:9px 12px; font-size:0.82em; color:inherit; text-align:<?php echo esc_attr( $pv_opt_align ); ?>;"><?php echo esc_html( $pv_opt ); ?></div>
                                        <?php endif;
                                    endforeach; ?>
                                </div>
                            </div>
                            <?php do_action( 'adaptive_test_during_preview_footer' ); ?>
                        </div>

                        <?php elseif ( 'after' === $active_sub ) :
                            $pv_bg               = get_option( 'adaptive_test_after_box_bg',            '#ffffff' ) ?: '#ffffff';
                            $pv_text             = get_option( 'adaptive_test_after_box_text_color',    '#1f2937' ) ?: '#1f2937';
                            $pv_radius           = absint( get_option( 'adaptive_test_after_box_border_radius', 16 ) );
                            $pv_bdr_w            = absint( get_option( 'adaptive_test_after_box_border_width', 0 ) );
                            $pv_bdr_c            = get_option( 'adaptive_test_after_box_border_color', '#e5e7eb' ) ?: '#e5e7eb';
                            $pv_shadow           = get_option( 'adaptive_test_after_box_shadow', 1 ) ? '0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -1px rgba(0,0,0,.06)' : 'none';
                            $pv_card_bdr         = $pv_bdr_w ? $pv_bdr_w . 'px solid ' . $pv_bdr_c : 'none';
                            $pv_result_color     = get_option( 'adaptive_test_after_result_color',      $pv_primary ) ?: $pv_primary;
                            $pv_retake_color     = get_option( 'adaptive_test_after_retake_color',         $pv_primary ) ?: $pv_primary;
                            $pv_retake_text      = get_option( 'adaptive_test_after_retake_text_color',    '#ffffff' ) ?: '#ffffff';
                            $pv_retake_bdc       = get_option( 'adaptive_test_after_retake_border_color',  $pv_primary ) ?: $pv_primary;
                            $pv_retake_bdw       = absint( get_option( 'adaptive_test_after_retake_border_width',  2 ) );
                            $pv_retake_bdr_val   = absint( get_option( 'adaptive_test_after_retake_border_radius', 8 ) );
                            $pv_after_title      = get_option( 'adaptive_test_after_title',      __( 'Test Complete', 'adaptive-level-test' ) );
                            $pv_after_subheading = get_option( 'adaptive_test_after_subheading', __( 'Your estimated level is:', 'adaptive-level-test' ) );
                            $pv_after_body       = get_option( 'adaptive_test_after_body',       __( 'A copy of your result has been emailed to you.', 'adaptive-level-test' ) );
                            $pv_title_color      = get_option( 'adaptive_test_after_title_color',      '#1f2937' ) ?: '#1f2937';
                            $pv_title_size       = absint( get_option( 'adaptive_test_after_title_size',      24 ) );
                            $pv_title_weight     = get_option( 'adaptive_test_after_title_weight',     '700' ) ?: '700';
                            $pv_sub_color        = get_option( 'adaptive_test_after_subheading_color', '#6b7280' ) ?: '#6b7280';
                            $pv_sub_size         = absint( get_option( 'adaptive_test_after_subheading_size',  16 ) );
                            $pv_sub_weight       = get_option( 'adaptive_test_after_subheading_weight','400' ) ?: '400';
                            $pv_body_color         = get_option( 'adaptive_test_after_body_color',       '#6b7280' ) ?: '#6b7280';
                            $pv_body_size          = absint( get_option( 'adaptive_test_after_body_size',        14 ) );
                            $pv_body_weight        = get_option( 'adaptive_test_after_body_weight',      '400' ) ?: '400';
                            $pv_share_enabled      = false;
                            $pv_share_heading      = get_option( 'adaptive_test_after_share_heading',  __( 'Share your result!', 'adaptive-level-test' ) );
                            $pv_share_body         = get_option( 'adaptive_test_after_share_body',     __( 'Let your friends and colleagues know your English level.', 'adaptive-level-test' ) );
                            $pv_share_whatsapp     = get_option( 'adaptive_test_after_share_whatsapp', 'WhatsApp' );
                            $pv_share_facebook     = get_option( 'adaptive_test_after_share_facebook', 'Facebook' );
                            $pv_share_native       = get_option( 'adaptive_test_after_share_native',   __( 'TikTok / Instagram', 'adaptive-level-test' ) );
                            $pv_share_copy         = get_option( 'adaptive_test_after_share_copy',     __( 'Copy Link', 'adaptive-level-test' ) );
                            $pv_share_head_color   = get_option( 'adaptive_test_after_share_heading_color',  '#1f2937' ) ?: '#1f2937';
                            $pv_share_head_size    = absint( get_option( 'adaptive_test_after_share_heading_size',  16 ) );
                            $pv_share_head_weight  = get_option( 'adaptive_test_after_share_heading_weight', '600' ) ?: '600';
                            $pv_share_body_color   = get_option( 'adaptive_test_after_share_body_color',  '#6b7280' ) ?: '#6b7280';
                            $pv_share_body_size    = absint( get_option( 'adaptive_test_after_share_body_size',  13 ) );
                            $pv_share_body_weight  = get_option( 'adaptive_test_after_share_body_weight', '400' ) ?: '400';
                            $pv_share_copy_bg      = get_option( 'adaptive_test_after_share_copy_bg',    '#f3f4f6' ) ?: '#f3f4f6';
                            $pv_share_copy_color   = get_option( 'adaptive_test_after_share_copy_color', '#374151' ) ?: '#374151';
                            $pv_share_native_bg    = get_option( 'adaptive_test_after_share_native_bg',  '#000000' ) ?: '#000000';
                            $pv_share_native_color = get_option( 'adaptive_test_after_share_native_color', '#ffffff' ) ?: '#ffffff';
                        ?>
                        <div style="background:#f3f4f6; padding:16px; border-radius:8px;">
                            <div id="esl-prev-card" style="background:<?php echo esc_attr( $pv_bg ); ?>; border-radius:<?php echo absint( $pv_radius ); ?>px; border:<?php echo esc_attr( $pv_card_bdr ); ?>; box-shadow:<?php echo esc_attr( $pv_shadow ); ?>; padding:28px; box-sizing:border-box; text-align:center; color:<?php echo esc_attr( $pv_text ); ?>;">
                                <p id="esl-prev-after-title" style="color:<?php echo esc_attr( $pv_title_color ); ?>; font-size:<?php echo absint( $pv_title_size ); ?>px; font-weight:<?php echo esc_attr( $pv_title_weight ); ?>; margin:0 0 6px;"><?php echo wp_kses_post( $pv_after_title ); ?></p>
                                <p id="esl-prev-after-subheading" style="color:<?php echo esc_attr( $pv_sub_color ); ?>; font-size:<?php echo absint( $pv_sub_size ); ?>px; font-weight:<?php echo esc_attr( $pv_sub_weight ); ?>; margin:0 0 4px;"><?php echo wp_kses_post( $pv_after_subheading ); ?></p>
                                <p id="esl-prev-result-level" style="font-size:2.8em; font-weight:700; color:<?php echo esc_attr( $pv_result_color ); ?>; margin:4px 0 16px; line-height:1;">B2</p>
                                <div style="background:#e5e7eb; height:10px; border-radius:6px; position:relative; margin-bottom:28px;">
                                    <?php
                                    $pv_show_err  = (bool) get_option( 'adaptive_test_show_error_rate', 1 );
                                    $pv_err_rate  = absint( get_option( 'adaptive_test_error_rate', 5 ) );
                                    $pv_ind_width = max( 5, $pv_err_rate * 2 );
                                    $pv_left_pos  = 50 - ( $pv_ind_width / 2 );
                                    ?>
                                    <div id="esl-prev-scale-bar" style="position:absolute; top:-5px; left:<?php echo intval( $pv_left_pos ); ?>%; width:<?php echo absint( $pv_ind_width ); ?>%; height:20px; background:<?php echo esc_attr( $pv_result_color ); ?>; border-radius:10px; opacity:.8;<?php echo $pv_show_err ? '' : 'display:none;'; ?>"></div>
                                </div>
                                <div style="display:flex; justify-content:space-around; margin-top:-22px; margin-bottom:20px; font-size:0.78em; font-weight:600; color:#6b7280;">
                                    <span>A2</span><span>B1</span><span id="esl-prev-active-label" style="color:<?php echo esc_attr( $pv_result_color ); ?>; font-weight:800;">B2</span><span>C1</span><span>C2</span>
                                </div>
                                <?php
                                $pv_err_label = get_option( 'adaptive_test_error_rate_label', __( 'Margin of Error: ±{rate}%', 'adaptive-level-test' ) );
                                $pv_err_label_text = $pv_err_label ?: __( 'Margin of Error: ±{rate}%', 'adaptive-level-test' );
                                $pv_err_label_text = str_replace( '{rate}', $pv_err_rate, $pv_err_label_text );
                                ?>
                                <p id="esl-prev-error-margin" style="font-size:0.85em; color:#6b7280; margin:0 0 14px;<?php echo $pv_show_err ? '' : 'display:none;'; ?>"><?php echo wp_kses_post( $pv_err_label_text ); ?></p>
                                <p id="esl-prev-after-body" style="color:<?php echo esc_attr( $pv_body_color ); ?>; font-size:<?php echo absint( $pv_body_size ); ?>px; font-weight:<?php echo esc_attr( $pv_body_weight ); ?>; margin:0 0 16px;"><?php echo wp_kses_post( $pv_after_body ); ?></p>
                                <div id="esl-prev-share-section" style="<?php echo $pv_share_enabled ? '' : 'display:none;'; ?> margin: 16px 0;">
                                    <p id="esl-prev-share-heading" style="color:<?php echo esc_attr( $pv_share_head_color ); ?>; font-size:<?php echo absint( $pv_share_head_size ); ?>px; font-weight:<?php echo esc_attr( $pv_share_head_weight ); ?>; margin:0 0 4px;"><?php echo esc_html( $pv_share_heading ); ?></p>
                                    <p id="esl-prev-share-body" style="color:<?php echo esc_attr( $pv_share_body_color ); ?>; font-size:<?php echo absint( $pv_share_body_size ); ?>px; font-weight:<?php echo esc_attr( $pv_share_body_weight ); ?>; margin:0 0 10px;"><?php echo esc_html( $pv_share_body ); ?></p>
                                    <div style="display:flex; gap:5px; justify-content:center; flex-wrap:wrap;">
                                        <span style="background:#25D366; color:#fff; padding:5px 10px; border-radius:6px; font-size:0.75em; font-weight:600;" id="esl-prev-share-wa"><?php echo esc_html( $pv_share_whatsapp ); ?></span>
                                        <span style="background:#1877F2; color:#fff; padding:5px 10px; border-radius:6px; font-size:0.75em; font-weight:600;" id="esl-prev-share-fb"><?php echo esc_html( $pv_share_facebook ); ?></span>
                                        <span style="background:<?php echo esc_attr( $pv_share_native_bg ); ?>; color:<?php echo esc_attr( $pv_share_native_color ); ?>; padding:5px 10px; border-radius:6px; font-size:0.75em; font-weight:600;" id="esl-prev-share-native"><?php echo esc_html( $pv_share_native ); ?></span>
                                        <span style="background:<?php echo esc_attr( $pv_share_copy_bg ); ?>; color:<?php echo esc_attr( $pv_share_copy_color ); ?>; border:1px solid #e5e7eb; padding:5px 10px; border-radius:6px; font-size:0.75em; font-weight:600;" id="esl-prev-share-copy"><?php echo esc_html( $pv_share_copy ); ?></span>
                                    </div>
                                </div>
                                <button type="button" id="esl-prev-retake" disabled style="background:<?php echo esc_attr( $pv_retake_color ); ?>; color:<?php echo esc_attr( $pv_retake_text ); ?>; border:<?php echo absint( $pv_retake_bdw ); ?>px solid <?php echo esc_attr( $pv_retake_bdc ); ?>; border-radius:<?php echo absint( $pv_retake_bdr_val ); ?>px; padding:10px 22px; font-weight:600; cursor:default; font-size:0.85em;">Retake Test</button>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>

            </div><?php // end flex container ?>
        <?php elseif ($active_tab == 'messages'): ?>
            <form action="options.php" method="post" style="margin-top:20px;">
                <?php settings_fields('adaptive_test_msg_options'); ?>

                <div style="background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px; margin-bottom:20px;">
                    <h2><?php esc_html_e( 'Student Email', 'adaptive-level-test' ); ?></h2>
                    <?php do_settings_sections('adaptive-level-test-msg-student'); ?>
                </div>

                <div style="background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px; margin-bottom:20px;">
                    <h2><?php esc_html_e( 'Admin Notification Email', 'adaptive-level-test' ); ?></h2>
                    <?php do_settings_sections('adaptive-level-test-msg-admin'); ?>
                </div>

                <div style="background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px; margin-bottom:20px;">
                    <h2><?php esc_html_e( 'Email Footer', 'adaptive-level-test' ); ?></h2>
                    <?php do_settings_sections('adaptive-level-test-msg-footer'); ?>
                </div>

                <?php submit_button(__('Save Settings', 'adaptive-level-test')); ?>

                <hr>
                <h3><?php esc_html_e( 'Reset Templates', 'adaptive-level-test' ); ?></h3>
                <form method="post" action="">
                    <input type="hidden" name="adaptive_test_action" value="reset_email_templates">
                    <?php wp_nonce_field('adaptive_test_reset_email_nonce'); ?>
                    <?php submit_button(__('Reset to Defaults', 'adaptive-level-test'), 'secondary', 'submit', false, ['onclick' => "return confirm('" . esc_js(__('Are you sure you want to reset all email templates to default?', 'adaptive-level-test')) . "');"]); ?>
                </form>
            </form>
        <?php elseif ($active_tab == 'questions'): ?>
            <?php
            global $wpdb;
            $table_name = $wpdb->prefix . 'adaptive_questions';
            $banks_table = $wpdb->prefix . 'adaptive_question_banks';
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $banks = $wpdb->get_results( "SELECT * FROM {$banks_table}" );
            $current_bank_id = isset( $_GET['bank_id'] ) ? absint( wp_unslash( $_GET['bank_id'] ) ) : 1;
            
            // Determine if current bank is default
            $current_bank_is_default = false;
            foreach($banks as $b) {
                if ($b->id == $current_bank_id && $b->is_default) {
                    $current_bank_is_default = true;
                    break;
                }
            }
            
            // Detect rename mode
            $rename_bank = null;
            if ( isset( $_GET['action'] ) && 'rename_bank' === $_GET['action'] && isset( $_GET['id'] ) ) {
                $rename_bank = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$banks_table} WHERE id = %d", absint( wp_unslash( $_GET['id'] ) ) ) );
            }

            // Fetch question for editing if ID is present
            $edit_question = null;
            if ( isset( $_GET['action'] ) && 'edit_question' === $_GET['action'] && isset( $_GET['id'] ) ) {
                $edit_question = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE id = %d", absint( wp_unslash( $_GET['id'] ) ) ) );
                if ($edit_question) $current_bank_id = $edit_question->bank_id;
            }
            ?>
            <div style="display:flex; gap:30px; align-items:flex-start; margin-top:20px;">
            <div style="flex:0 0 60%; min-width:0; box-sizing:border-box; background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px;">
            <h2><?php esc_html_e( 'Question Banks', 'adaptive-level-test' ); ?></h2>
            
            <!-- Bank Management -->
            <div style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <?php if ( $rename_bank ) : ?>
                    <form method="post" action="" style="display:flex; gap:10px; align-items:center;">
                        <input type="hidden" name="adaptive_test_action" value="rename_bank">
                        <input type="hidden" name="bank_id" value="<?php echo absint( $rename_bank->id ); ?>">
                        <?php wp_nonce_field( 'adaptive_test_rename_bank_nonce' ); ?>
                        <input type="text" name="bank_name" value="<?php echo esc_attr( $rename_bank->name ); ?>" required class="regular-text">
                        <?php submit_button( __( 'Save Name', 'adaptive-level-test' ), 'primary', 'submit', false ); ?>
                        <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'adaptive-level-test' ); ?></a>
                    </form>
                <?php else : ?>
                    <form method="post" action="" style="display:flex; gap:10px; align-items:center;">
                        <input type="hidden" name="adaptive_test_action" value="save_bank">
                        <?php wp_nonce_field('adaptive_test_save_bank_nonce'); ?>
                        <input type="text" name="bank_name" placeholder="<?php esc_attr_e( 'New Bank Name', 'adaptive-level-test' ); ?>" required class="regular-text">
                        <?php submit_button( __( 'Create Bank', 'adaptive-level-test' ), 'secondary', 'submit', false ); ?>
                    </form>
                <?php endif; ?>
            </div>

            <table class="wp-list-table widefat fixed striped" style="margin-bottom: 30px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'adaptive-level-test' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'adaptive-level-test' ); ?></th>
                        <th><?php esc_html_e( 'Shortcode', 'adaptive-level-test' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'adaptive-level-test' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $banks as $b ) : ?>
                        <tr>
                            <td><?php echo absint( $b->id ); ?></td>
                            <td><?php echo esc_html( $b->name ); ?><?php if ( $b->is_default ) echo ' <strong>' . esc_html__( '(Default)', 'adaptive-level-test' ) . '</strong>'; ?></td>
                            <td><code>[adaptive_level_test bank="<?php echo absint( $b->id ); ?>"]</code></td>
                            <td>
                                <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'rename_bank', 'id' => $b->id ] ) ); ?>"><?php esc_html_e( 'Rename', 'adaptive-level-test' ); ?></a> |
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'duplicate_bank', 'id' => $b->id ] ), 'adaptive_test_duplicate_bank_nonce' ) ); ?>"><?php esc_html_e( 'Duplicate', 'adaptive-level-test' ); ?></a>
                                <?php if ( ! $b->is_default ) : ?>
                                    | <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'delete_bank', 'id' => $b->id ] ), 'adaptive_test_delete_bank_nonce' ) ); ?>" style="color: #b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Delete this bank?', 'adaptive-level-test' ) ); ?>');"><?php esc_html_e( 'Delete', 'adaptive-level-test' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Manage Questions', 'adaptive-level-test' ); ?></h2>
            <div style="margin-bottom: 15px;">
                <label><?php esc_html_e( 'Select Bank:', 'adaptive-level-test' ); ?></label>
                <select onchange="window.location.href='?page=adaptive-level-test&tab=questions&bank_id='+this.value">
                    <?php foreach ( $banks as $b ) : ?>
                        <option value="<?php echo absint( $b->id ); ?>" <?php selected( $current_bank_id, $b->id ); ?>><?php echo esc_html( $b->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        
        <!-- Add/Edit Form -->
        <div class="card" style="max-width: 100%; padding: 20px; margin-bottom: 20px;">
            <h3><?php echo $edit_question ? esc_html__( 'Edit Question', 'adaptive-level-test' ) : esc_html__( 'Add New Question', 'adaptive-level-test' ); ?></h3>
            <form method="post" action="">
                <input type="hidden" name="adaptive_test_action" value="save_question">
                <?php if ($edit_question): ?>
                    <input type="hidden" name="question_id" value="<?php echo esc_attr($edit_question->id); ?>">
                <?php endif; ?>
                <?php wp_nonce_field('adaptive_test_save_question_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <input type="hidden" name="bank_id" value="<?php echo absint( $current_bank_id ); ?>">
                        <th><label for="question_text"><?php esc_html_e( 'Question', 'adaptive-level-test' ); ?></label></th>
                        <td><textarea name="question_text" id="question_text" class="large-text" required><?php echo $edit_question ? esc_textarea( $edit_question->question_text ) : ''; ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="options"><?php esc_html_e( 'Options (comma separated)', 'adaptive-level-test' ); ?></label></th>
                        <td>
                            <?php $opts = $edit_question ? implode( ',', json_decode( $edit_question->options ) ) : ''; ?>
                            <input type="text" name="options" id="options" class="large-text" value="<?php echo esc_attr( $opts ); ?>" required>
                            <p class="description"><?php esc_html_e( 'Example: go,went,gone,going', 'adaptive-level-test' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="answer"><?php esc_html_e( 'Correct Answer', 'adaptive-level-test' ); ?></label></th>
                        <td><input type="text" name="answer" id="answer" class="regular-text" value="<?php echo $edit_question ? esc_attr( $edit_question->answer ) : ''; ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="level"><?php esc_html_e( 'Level', 'adaptive-level-test' ); ?></label></th>
                        <td>
                            <select name="level" id="level">
                                <?php foreach ( [ 'A2', 'B1', 'B2', 'C1', 'C2' ] as $lvl ) : ?>
                                    <option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $edit_question ? $edit_question->level : '', $lvl ); ?>><?php echo esc_html( $lvl ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( $edit_question ? __( 'Update Question', 'adaptive-level-test' ) : __( 'Add Question', 'adaptive-level-test' ), 'primary', 'submit_question' ); ?>
                <?php if ( $edit_question ) : ?>
                    <a href="<?php echo esc_url( remove_query_arg( [ 'action', 'id' ] ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'adaptive-level-test' ); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Questions List -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'adaptive-level-test' ); ?></th>
                    <th><?php esc_html_e( 'Question', 'adaptive-level-test' ); ?></th>
                    <th><?php esc_html_e( 'Level', 'adaptive-level-test' ); ?></th>
                    <th><?php esc_html_e( 'Options', 'adaptive-level-test' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'adaptive-level-test' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $questions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE bank_id = %d ORDER BY level ASC, id ASC", $current_bank_id));
                foreach ( $questions as $q ) : ?>
                    <tr>
                        <td><?php echo absint( $q->id ); ?></td>
                        <td><?php echo esc_html( $q->question_text ); ?></td>
                        <td><span class="badge"><?php echo esc_html( $q->level ); ?></span></td>
                        <td><?php
                            $opts = json_decode( $q->options, true );
                            if ( is_array( $opts ) ) {
                                $parts = array_map( function( $opt ) use ( $q ) {
                                    return $opt === $q->answer
                                        ? '<u><strong>' . esc_html( $opt ) . '</strong></u>'
                                        : esc_html( $opt );
                                }, $opts );
                                echo implode( ', ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each option is esc_html'd above; <u> and <strong> are safe literals
                            } else {
                                echo esc_html( $q->answer );
                            }
                        ?></td>
                        <td>
                            <a href="<?php echo esc_url( add_query_arg( [ 'action' => 'edit_question', 'id' => $q->id ] ) ); ?>"><?php esc_html_e( 'Edit', 'adaptive-level-test' ); ?></a> |
                            <?php if ( $current_bank_is_default ) : ?>
                                <span style="color: #999; cursor: not-allowed;" title="<?php esc_attr_e( 'Cannot delete questions from default bank', 'adaptive-level-test' ); ?>"><?php esc_html_e( 'Delete', 'adaptive-level-test' ); ?></span>
                            <?php else : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'delete_question', 'id' => $q->id ] ), 'adaptive_test_delete_question_nonce' ) ); ?>" style="color: #b32d2e;" onclick="return confirm('<?php echo esc_js( __( 'Are you sure?', 'adaptive-level-test' ) ); ?>');"><?php esc_html_e( 'Delete', 'adaptive-level-test' ); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

            </div><!-- end left column -->
            <div style="flex:0 0 calc(40% - 30px); min-width:0; box-sizing:border-box; background:#fff; border:1px solid #ccd0d4; box-shadow:0 1px 1px rgba(0,0,0,.04); padding:20px;">
            <h2><?php esc_html_e( 'Tools', 'adaptive-level-test' ); ?></h2>
            <hr>
            <div style="margin-bottom: 20px;">
                <h3><?php esc_html_e( 'Export Questions', 'adaptive-level-test' ); ?></h3>
                <p><?php esc_html_e( 'Download all questions as a CSV file.', 'adaptive-level-test' ); ?></p>
                <form method="post" action="">
                    <input type="hidden" name="adaptive_test_action" value="export_csv">
                    <?php wp_nonce_field( 'adaptive_test_tool_action_nonce' ); ?>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <select name="export_bank_id">
                            <?php foreach ( $banks as $b ) : ?>
                                <option value="<?php echo absint( $b->id ); ?>"><?php echo esc_html( $b->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php submit_button( __( 'Export CSV', 'adaptive-level-test' ), 'secondary', 'submit', false ); ?>
                    </div>
                </form>
            </div>
            <hr>
            <div style="margin-bottom: 20px;">
                <h3><?php esc_html_e( 'Import Questions', 'adaptive-level-test' ); ?></h3>
                <p><?php esc_html_e( 'Upload a CSV file to add questions. The CSV should have headers: id, question_text, options, answer, level.', 'adaptive-level-test' ); ?></p>
                <p class="description"><?php esc_html_e( 'Note: The "options" column should be pipe-separated (e.g., Option A|Option B|Option C|Option D).', 'adaptive-level-test' ); ?></p>
                <form method="post" action="" enctype="multipart/form-data">
                    <input type="hidden" name="adaptive_test_action" value="import_csv">
                    <?php wp_nonce_field( 'adaptive_test_tool_action_nonce' ); ?>
                    <p><input type="file" name="csv_file" accept=".csv" required></p>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <select name="import_bank_id">
                            <?php foreach ( $banks as $b ) : ?>
                                <option value="<?php echo absint( $b->id ); ?>"><?php echo esc_html( $b->name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php submit_button( __( 'Import CSV', 'adaptive-level-test' ), 'primary', 'submit', false ); ?>
                    </div>
                </form>
            </div>
            <hr>
            <div style="margin-bottom: 20px; border-left: 4px solid #d63638; padding-left: 12px;">
                <h3><?php esc_html_e( 'Reset Database', 'adaptive-level-test' ); ?></h3>
                <p><?php esc_html_e( 'Warning: This will delete ALL existing questions and re-insert the default sample questions.', 'adaptive-level-test' ); ?></p>
                <form method="post" action="">
                    <input type="hidden" name="adaptive_test_action" value="reseed_questions">
                    <?php wp_nonce_field('adaptive_test_tool_action_nonce'); ?>
                    <?php submit_button(__('Re-seed Questions', 'adaptive-level-test'), 'delete', 'submit', true, ['onclick' => "return confirm('" . esc_js(__('Are you sure you want to delete all questions and reset the database?', 'adaptive-level-test')) . "');"]); ?>
                </form>
            </div>
            </div><!-- end right column -->
            </div><!-- end flex wrapper -->
        <?php elseif ($active_tab == 'logs'): ?>
            <?php
            global $wpdb;
            $logs_table = $wpdb->prefix . 'adaptive_attempt_logs';

            // Validate sort params
            $allowed_orderby = [ 'date', 'email', 'level', 'bank_name' ];
            $orderby      = isset( $_GET['orderby'] ) && in_array( sanitize_key( $_GET['orderby'] ), $allowed_orderby, true )
                ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date';
            $order        = ( isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ) ? 'ASC' : 'DESC';
            $bank_filter  = isset( $_GET['bank_filter'] )  ? sanitize_text_field( wp_unslash( $_GET['bank_filter'] ) )  : '';
            $email_filter = isset( $_GET['email_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['email_filter'] ) ) : '';

            // Distinct banks present in logs for the filter dropdown
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            $log_banks = $wpdb->get_col( "SELECT DISTINCT bank_name FROM {$logs_table} WHERE bank_name != '' ORDER BY bank_name ASC" );

            // Build WHERE clause from active filters
            $where_parts = [];
            $where_args  = [];
            if ( $bank_filter ) {
                $where_parts[] = 'bank_name = %s';
                $where_args[]  = $bank_filter;
            }
            if ( $email_filter ) {
                $where_parts[] = 'email LIKE %s';
                $where_args[]  = '%' . $wpdb->esc_like( $email_filter ) . '%';
            }
            $where_sql = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

            if ( ! empty( $where_args ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
                $logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$logs_table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT 100", ...$where_args ) );
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
                $logs = $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY {$orderby} {$order} LIMIT 100" );
            }
            ?>
            <h2><?php esc_html_e( 'Attempt Logs', 'adaptive-level-test' ); ?></h2>

            <!-- Toolbar -->
            <div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <!-- Filter form (email + bank) -->
                    <form method="get" action="" style="display:flex; gap:6px; align-items:center;">
                        <input type="hidden" name="page" value="adaptive-level-test">
                        <input type="hidden" name="tab" value="logs">
                        <input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
                        <input type="hidden" name="order" value="<?php echo esc_attr( strtolower( $order ) ); ?>">
                        <input type="text" name="email_filter" value="<?php echo esc_attr( $email_filter ); ?>" placeholder="<?php esc_attr_e( 'All users', 'adaptive-level-test' ); ?>" style="width:180px;">
                        <select name="bank_filter">
                            <option value=""><?php esc_html_e( 'All Banks', 'adaptive-level-test' ); ?></option>
                            <?php foreach ( $log_banks as $bank ) : ?>
                                <option value="<?php echo esc_attr( $bank ); ?>" <?php selected( $bank_filter, $bank ); ?>><?php echo esc_html( $bank ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php submit_button( __( 'Filter', 'adaptive-level-test' ), 'secondary', 'submit', false ); ?>
                        <?php if ( $email_filter || $bank_filter ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=adaptive-level-test&tab=logs' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Clear', 'adaptive-level-test' ); ?></a>
                        <?php endif; ?>
                    </form>
                    <?php if ( $is_admin ) : ?>
                    <!-- Export -->
                    <form method="post" action="">
                        <input type="hidden" name="adaptive_test_action" value="export_logs_csv">
                        <input type="hidden" name="bank_filter" value="<?php echo esc_attr( $bank_filter ); ?>">
                        <?php wp_nonce_field( 'adaptive_test_export_logs_nonce' ); ?>
                        <?php submit_button( __( 'Export CSV', 'adaptive-level-test' ), 'secondary', 'submit', false ); ?>
                    </form>
                    <?php endif; ?>
                </div>
                <?php if ( $is_admin ) : ?>
                <!-- Delete old -->
                <form method="post" action="">
                    <input type="hidden" name="adaptive_test_action" value="delete_logs">
                    <?php wp_nonce_field( 'adaptive_test_delete_logs_nonce' ); ?>
                    <label><?php esc_html_e( 'Delete attempts older than:', 'adaptive-level-test' ); ?></label>
                    <input type="number" name="log_days" value="30" style="width:60px;"> <?php esc_html_e( 'days', 'adaptive-level-test' ); ?>
                    <?php submit_button( __( 'Delete Old Attempts', 'adaptive-level-test' ), 'delete', 'submit', false ); ?>
                </form>
                <?php endif; ?>
            </div>

            <!-- Bulk-action form wraps the table -->
            <form method="post" action="" id="esl-logs-bulk-form">
                <?php if ( $is_admin ) : ?>
                <input type="hidden" name="adaptive_test_action" value="bulk_delete_logs">
                <?php wp_nonce_field( 'adaptive_test_bulk_delete_logs_nonce' ); ?>
                <?php endif; ?>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <?php if ( $is_admin ) : ?><th style="width:32px;"><input type="checkbox" id="esl-select-all" title="<?php esc_attr_e( 'Select all', 'adaptive-level-test' ); ?>"></th><?php endif; ?>
                            <th><?php echo adaptive_test_sort_link( 'date',      __( 'Date', 'adaptive-level-test' ),          $orderby, $order, $bank_filter, $email_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
                            <th><?php echo adaptive_test_sort_link( 'email',     __( 'Email', 'adaptive-level-test' ),         $orderby, $order, $bank_filter, $email_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
                            <th><?php echo adaptive_test_sort_link( 'bank_name', __( 'Question Bank', 'adaptive-level-test' ), $orderby, $order, $bank_filter, $email_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
                            <th><?php echo adaptive_test_sort_link( 'level',     __( 'Result', 'adaptive-level-test' ),        $orderby, $order, $bank_filter, $email_filter ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
                            <th><?php esc_html_e( 'Confidence', 'adaptive-level-test' ); ?></th>
                            <th><?php esc_html_e( 'Time Taken', 'adaptive-level-test' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $strong_label      = get_option( 'adaptive_test_strong_label',      __( 'Strong',      'adaptive-level-test' ) );
                        $borderline_label  = get_option( 'adaptive_test_borderline_label',  __( 'Borderline',  'adaptive-level-test' ) );
                        $level_centres     = [ 'A2' => -2.0, 'B1' => -1.0, 'B2' => 0.0, 'C1' => 1.0, 'C2' => 2.0 ];
                        foreach ( $logs as $log ) :
                            $sub   = $log->sub_level ?? '';
                            $label = '';
                            if ( 'strong' === $sub ) {
                                $label = $strong_label . ' ';
                            } elseif ( 'borderline' === $sub ) {
                                $label = $borderline_label . ' ';
                            }
                            $theta_val  = isset( $log->theta ) && '' !== $log->theta ? (float) $log->theta : null;
                            $within_pct = null;
                            if ( null !== $theta_val && isset( $level_centres[ $log->level ] ) ) {
                                $centre     = $level_centres[ $log->level ];
                                $within_pct = (int) round( max( 0, min( 100, ( $theta_val - ( $centre - 0.5 ) ) * 100 ) ) );
                            }
                            $result_display = $label . $log->level . ( null !== $within_pct ? ' (' . $within_pct . '%)' : '' );
                            $se_val         = isset( $log->se ) && '' !== $log->se ? (float) $log->se : null;
                            $confidence     = ( null !== $se_val ) ? '±' . round( $se_val / 4.0 * 100.0, 1 ) . '%' : '—';
                            $dur_raw        = isset( $log->duration_seconds ) && $log->duration_seconds > 0 ? (int) $log->duration_seconds : null;
                            $duration_fmt   = null !== $dur_raw ? ( $dur_raw >= 60 ? floor( $dur_raw / 60 ) . 'm ' . ( $dur_raw % 60 ) . 's' : $dur_raw . 's' ) : '—';
                            $delete_url = wp_nonce_url(
                                admin_url( 'options-general.php?page=adaptive-level-test&tab=logs&action=delete_log&id=' . $log->id ),
                                'adaptive_test_delete_log_nonce'
                            );
                        ?>
                            <tr>
                                <?php if ( $is_admin ) : ?><td><input type="checkbox" name="log_ids[]" value="<?php echo esc_attr( (string) $log->id ); ?>"></td><?php endif; ?>
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->date ) ) ); ?></td>
                                <td><?php echo esc_html( $log->email ); ?></td>
                                <td><?php echo esc_html( $log->bank_name ); ?></td>
                                <td><span class="badge"><?php echo esc_html( $result_display ); ?></span></td>
                                <td><?php echo esc_html( $confidence ); ?></td>
                                <td><?php echo esc_html( $duration_fmt ); ?></td>
                                <td style="white-space:nowrap;">
                                    <?php do_action( 'adaptive_test_log_row_actions', $log ); ?>
                                    <?php if ( $is_admin ) : ?>
                                    <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small" style="color:#dc2626;" onclick="return confirm('<?php echo esc_js( __( 'Delete this attempt?', 'adaptive-level-test' ) ); ?>')"><?php esc_html_e( 'Delete', 'adaptive-level-test' ); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $is_admin ) : ?>
                <div style="margin-top:8px;">
                    <button type="submit" class="button button-secondary"
                        onclick="return document.querySelectorAll('#esl-logs-bulk-form input[name=\'log_ids[]\']:checked').length > 0 || (alert('<?php echo esc_js( __( 'Please select at least one attempt.', 'adaptive-level-test' ) ); ?>'), false);"
                    ><?php esc_html_e( 'Delete Selected', 'adaptive-level-test' ); ?></button>
                </div>
                <?php endif; ?>
            </form>

        <?php endif; ?>
    </div>
    <?php
}
// phpcs:enable WordPress.Security.NonceVerification.Recommended

/**
 * Dashboard Widget: Recent Test Stats
 */
function adaptive_test_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'adaptive_level_test_stats',
        __('Adaptive Test Statistics', 'adaptive-level-test'),
        'adaptive_test_dashboard_widget_callback'
    );
}
add_action('wp_dashboard_setup', 'adaptive_test_add_dashboard_widget');

function adaptive_test_dashboard_widget_callback() {
    if ( ! current_user_can( 'edit_others_posts' ) ) {
        return;
    }
    global $wpdb;
    $logs_table = $wpdb->prefix . 'adaptive_attempt_logs';

    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $logs_table ) ) ) !== $logs_table ) {
        echo '<p>' . esc_html__( 'No test data available yet.', 'adaptive-level-test' ) . '</p>';
        return;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
    $total_tests  = $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
    $recent_tests = $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY date DESC LIMIT 5" );

    echo '<div class="main">';
    echo '<p><strong>' . esc_html__( 'Total Tests Completed:', 'adaptive-level-test' ) . '</strong> ' . absint( $total_tests ) . '</p>';

    if ( $recent_tests ) {
        echo '<h4>' . esc_html__( 'Recent Results:', 'adaptive-level-test' ) . '</h4>';
        echo '<ul>';
        foreach ( $recent_tests as $test ) {
            echo '<li>';
            echo '<strong>' . esc_html( $test->level ) . '</strong> - ';
            echo esc_html( $test->email ) . ' ';
            echo '<span style="color:#666; font-size:0.9em;">(' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $test->date ) ) ) . ')</span>';
            echo '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p>' . esc_html__( 'No recent tests found.', 'adaptive-level-test' ) . '</p>';
    }
    echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=adaptive-level-test&tab=logs' ) ) . '" class="button button-primary">' . esc_html__( 'View All Attempts', 'adaptive-level-test' ) . '</a></p>';
    echo '</div>';
}