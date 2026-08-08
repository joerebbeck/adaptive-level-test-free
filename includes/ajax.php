<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
// AJAX handlers query the plugin's own tables ($wpdb->prefix . 'adaptive_*'). Table names are
// built from $wpdb->prefix (site-owner-controlled, not user input) so interpolation is safe.
// All user-supplied values (IDs, answers) use $wpdb->prepare(); the table name is the only flag.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// --- IRT helpers ---

// Map a CEFR level name to its logit-scale difficulty midpoint.
// A2=-2, B1=-1, B2=0, C1=1, C2=2 — one logit apart, centred at B2.
function adaptive_test_level_difficulty( $level ) {
    static $map = [ 'A2' => -2.0, 'B1' => -1.0, 'B2' => 0.0, 'C1' => 1.0, 'C2' => 2.0 ];
    return isset( $map[ $level ] ) ? $map[ $level ] : 0.0;
}

/**
 * Bayesian Expected A Posteriori (EAP) ability estimate.
 *
 * Uses a discretised grid over the logit scale with a Normal(0, 1.5) prior
 * centred at B2. Each item follows the Rasch model: P(correct) = 1/(1+e^-(θ-b)).
 *
 * @param array $items  Each element: ['difficulty' => float, 'correct' => 0|1]
 * @return array        ['theta' => float, 'se' => float]
 */
function adaptive_test_irt_estimate( array $items ) {
    if ( empty( $items ) ) {
        // No data yet — return prior parameters
        return [ 'theta' => 0.0, 'se' => 1.5 ];
    }

    // Grid: -4.0 to +4.0 in steps of 0.1 (81 points)
    $grid = [];
    for ( $i = -40; $i <= 40; $i++ ) {
        $grid[] = $i / 10.0;
    }

    $prior_mean = 0.0; // centred at B2
    $prior_sd   = 1.5; // broad enough to span A2–C2 without strong pull

    // Compute log-posterior at every grid point
    $log_post = [];
    foreach ( $grid as $theta ) {
        $lp = -0.5 * ( ( $theta - $prior_mean ) / $prior_sd ) * ( ( $theta - $prior_mean ) / $prior_sd );
        foreach ( $items as $item ) {
            $p   = 1.0 / ( 1.0 + exp( - ( $theta - $item['difficulty'] ) ) );
            $p   = max( 1e-10, min( 1.0 - 1e-10, $p ) ); // guard against log(0)
            $lp += $item['correct'] ? log( $p ) : log( 1.0 - $p );
        }
        $log_post[] = $lp;
    }

    // Subtract max before exp() for numerical stability
    $max_lp = max( $log_post );
    $unnorm  = [];
    foreach ( $log_post as $lp ) {
        $unnorm[] = exp( $lp - $max_lp );
    }
    $sum = array_sum( $unnorm );

    // EAP = weighted mean
    $theta_hat = 0.0;
    foreach ( $grid as $k => $theta ) {
        $theta_hat += $theta * $unnorm[ $k ];
    }
    $theta_hat /= $sum;

    // SE = weighted standard deviation
    $var = 0.0;
    foreach ( $grid as $k => $theta ) {
        $var += ( $theta - $theta_hat ) * ( $theta - $theta_hat ) * $unnorm[ $k ];
    }
    $var /= $sum;

    return [
        'theta' => round( $theta_hat, 4 ),
        'se'    => round( sqrt( $var ), 4 ),
    ];
}

/**
 * Classify a theta estimate as 'borderline', 'mid', or 'strong' within its level segment.
 * Each level segment spans ±0.5 logits around the level midpoint; divided into equal thirds.
 */
function adaptive_test_sub_level( $theta, $level ) {
    $b         = adaptive_test_level_difficulty( $level );
    $threshold = 1.0 / 6.0; // one third of the 0.5-logit half-segment ≈ 0.167
    if ( $theta > $b + $threshold ) {
        return 'strong';
    }
    if ( $theta < $b - $threshold ) {
        return 'borderline';
    }
    return 'mid';
}

// --- AJAX handlers ---

add_action('wp_ajax_adaptive_test_start_test', 'adaptive_test_start_test');
add_action('wp_ajax_nopriv_adaptive_test_start_test', 'adaptive_test_start_test');

function adaptive_test_start_test() {
    if ( ! check_ajax_referer( 'adaptive_level_test_nonce', 'nonce', false ) ) {
        wp_send_json_error( __( 'Invalid nonce.', 'adaptive-level-test' ) );
    }

    // Honeypot: legitimate users leave this blank; bots typically fill it in
    if ( ! empty( $_POST['esl_hp'] ) ) {
        wp_send_json_success( [ 'questions' => [] ] ); // Silent discard
    }

    // Rate limiting: configurable via Settings → General
    $rate_limit = max( 1, (int) get_option( 'adaptive_test_rate_limit', 5 ) );
    $ip         = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $rate_key   = 'esl_rate_start_' . md5( $ip );
    $count      = (int) get_transient( $rate_key );
    if ( $count >= $rate_limit ) {
        wp_send_json_error( __( 'Too many requests. Please try again later.', 'adaptive-level-test' ) );
    }
    set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

    $bank_id = isset( $_POST['bank_id'] ) ? absint( wp_unslash( $_POST['bank_id'] ) ) : 1;

    // Validate bank exists
    global $wpdb;
    $banks_table = $wpdb->prefix . 'adaptive_question_banks';
    $bank_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$banks_table} WHERE id = %d", $bank_id ) );
    if ( ! $bank_exists ) {
        wp_send_json_error( __( 'Invalid question bank.', 'adaptive-level-test' ) );
    }

    // Start at B1 level as per project instructions
    // We fetch a batch of 5 questions to allow the frontend to iterate through them
    $questions = adaptive_test_get_questions('B1', 5, $bank_id);

    if (empty($questions)) {
        wp_send_json_error(__('No questions found for the starting level (B1).', 'adaptive-level-test'));
    }

    wp_send_json_success(['questions' => $questions]);
}

add_action('wp_ajax_adaptive_test_submit_answers', 'adaptive_test_submit_answers');
add_action('wp_ajax_nopriv_adaptive_test_submit_answers', 'adaptive_test_submit_answers');

function adaptive_test_submit_answers() {
    if ( ! check_ajax_referer( 'adaptive_level_test_nonce', 'nonce', false ) ) {
        wp_send_json_error( __( 'Invalid nonce.', 'adaptive-level-test' ) );
    }

    $max_batches = max( 1, (int) get_option( 'adaptive_test_max_batches', 10 ) );

    // Rate limiting: allow up to max_batches submissions per test start per IP
    $rate_limit = max( 1, (int) get_option( 'adaptive_test_rate_limit', 5 ) );
    $ip         = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
    $rate_key   = 'esl_rate_submit_' . md5( $ip );
    $count      = (int) get_transient( $rate_key );
    if ( $count >= $rate_limit * $max_batches ) {
        wp_send_json_error( __( 'Too many requests. Please try again later.', 'adaptive-level-test' ) );
    }
    set_transient( $rate_key, $count + 1, HOUR_IN_SECONDS );

    $bank_id      = isset( $_POST['bank_id'] )      ? absint( wp_unslash( $_POST['bank_id'] ) )      : 1;
    $batch_number = isset( $_POST['batch_number'] )  ? absint( wp_unslash( $_POST['batch_number'] ) ) : 1;

    // Validate bank exists
    global $wpdb;
    $banks_table = $wpdb->prefix . 'adaptive_question_banks';
    $bank_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$banks_table} WHERE id = %d", $bank_id ) );
    if ( ! $bank_exists ) {
        wp_send_json_error( __( 'Invalid question bank.', 'adaptive-level-test' ) );
    }

    // Collect current-batch answers from POST (question_{id} keys)
    $answers = [];
    foreach ( $_POST as $key => $value ) {
        if ( strpos( $key, 'question_' ) === 0 ) {
            $question_id             = absint( substr( $key, 9 ) );
            $answers[ $question_id ] = sanitize_text_field( wp_unslash( $value ) );
        }
    }

    if ( empty( $answers ) ) {
        wp_send_json_error( __( 'No answers submitted.', 'adaptive-level-test' ) );
    }

    $table_name = $wpdb->prefix . 'adaptive_questions';

    // The JS accumulates every answer across all batches into answer_log and sends it every time.
    // We use this full history for the IRT estimate, while the current batch alone drives up/down/stay.
    $answer_log = json_decode( wp_unslash( $_POST['answer_log'] ?? '[]' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; individual values are sanitized after decoding.
    if ( ! is_array( $answer_log ) ) {
        $answer_log = [];
    }
    // Cap at max_batches × 5 entries — the most a legitimate test can ever produce.
    // A hard ceiling prevents a crafted POST from exhausting memory in the IRT grid loop.
    $answer_log = array_slice( $answer_log, 0, $max_batches * 5 );
    $duration_seconds = isset( $_POST['duration_seconds'] ) ? absint( wp_unslash( $_POST['duration_seconds'] ) ) : null;

    // One DB query covering both the current batch and the full history
    $current_ids = array_keys( $answers );
    $log_ids     = ! empty( $answer_log ) ? array_map( 'absint', array_column( $answer_log, 'id' ) ) : [];
    $all_ids     = array_values( array_unique( array_merge( $current_ids, $log_ids ) ) );

    $placeholders = implode( ',', array_fill( 0, count( $all_ids ), '%d' ) );
    // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    $all_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, answer, level, difficulty FROM {$table_name} WHERE id IN ({$placeholders}) AND bank_id = %d",
            array_merge( $all_ids, [ $bank_id ] )
        )
    );
    $row_map = [];
    foreach ( $all_rows as $r ) {
        $row_map[ (int) $r->id ] = $r;
    }

    // Score current batch for the up/down/stay decision (thresholds unchanged: ≥4 up, ≤2 down)
    $score         = 0;
    $current_level = '';
    foreach ( $current_ids as $q_id ) {
        if ( ! isset( $row_map[ $q_id ] ) ) continue;
        $row = $row_map[ $q_id ];
        if ( strtolower( $answers[ $q_id ] ) === strtolower( $row->answer ) ) {
            $score++;
        }
        $current_level = $row->level;
    }

    $levels              = [ 'A2', 'B1', 'B2', 'C1', 'C2' ];
    $current_level_index = array_search( $current_level, $levels );

    if ( false === $current_level_index ) {
        wp_send_json_error( __( 'Could not determine current level.', 'adaptive-level-test' ) );
    }

    if ( $score >= 4 ) {
        $next_level_index = $current_level_index + 1;
    } elseif ( $score <= 2 ) {
        $next_level_index = $current_level_index - 1;
    } else {
        $next_level_index = $current_level_index;
    }

    if ( $next_level_index < 0 ) {
        $next_level_index = 0;
    }

    // Build IRT item set from the full answer log and run the Bayesian EAP estimator.
    // Uses per-question difficulty if set, otherwise falls back to the level midpoint.
    $irt_items  = [];
    $score_data = [];
    foreach ( $answer_log as $entry ) {
        $q_id = (int) ( $entry['id'] ?? 0 );
        if ( ! isset( $row_map[ $q_id ] ) ) continue;
        $row    = $row_map[ $q_id ];
        $chosen = sanitize_text_field( $entry['chosen'] ?? '' );
        $is_correct = (int) ( strtolower( $chosen ) === strtolower( $row->answer ) );
        $b          = ( null !== $row->difficulty ) ? (float) $row->difficulty : adaptive_test_level_difficulty( $row->level );
        $irt_items[]  = [ 'difficulty' => $b, 'correct' => $is_correct ];
        $score_data[] = [ 'n' => (int) ( $entry['n'] ?? 0 ), 'id' => $q_id, 'chosen' => $chosen, 'correct' => $is_correct, 'ms' => (int) ( $entry['ms'] ?? 0 ) ];
    }

    $irt   = adaptive_test_irt_estimate( $irt_items );
    $theta = $irt['theta'];
    $se    = $irt['se'];

    // SE as a percentage of the 4-logit CEFR scale (A2 centre −2 to C2 centre +2)
    $error_pct = round( $se / 4.0 * 100.0, 1 );

    // Stopping conditions (in priority order):
    //   1. Ceiling: algorithm wants to go above C2
    //   2. Convergence: SE has fallen below the admin-configured target
    //   3. Maximum batches reached
    $target_error = max( 1.0, (float) get_option( 'adaptive_test_target_error', 8.0 ) );
    $se_threshold = $target_error / 100.0 * 4.0;

    $ceiling_hit = $next_level_index >= count( $levels );
    $converged   = $se <= $se_threshold;
    $max_reached = $batch_number >= $max_batches;

    if ( $ceiling_hit || $converged || $max_reached ) {
        $final_level = $levels[ min( $next_level_index, count( $levels ) - 1 ) ] ?? $levels[ $current_level_index ];
        $sub_level   = adaptive_test_sub_level( $theta, $final_level );

        // Student email
        $email          = '';
        $footer_default = "<hr style=\"margin: 30px 0; border: none; border-top: 1px solid #e5e7eb;\">\n<p style=\"font-size: 0.85em; color: #6b7280;\">You are receiving this email because you completed a level test on our website.</p>";
        $footer         = get_option( 'adaptive_test_email_footer' ) ?: $footer_default;

        if ( isset( $_POST['email'] ) && is_email( wp_unslash( $_POST['email'] ) ) ) {
            $to            = sanitize_email( wp_unslash( $_POST['email'] ) );
            $email         = $to;
            $subject       = get_option( 'adaptive_test_email_subject' ) ?: __( 'Your English Level Test Results', 'adaptive-level-test' );
            $body_template = get_option( 'adaptive_test_email_body' ) ?: "<p>Dear Student,</p>\n<p>Thank you for completing our English level test.</p>\n<p>Your estimated CEFR level is: <strong>%s</strong></p>\n<p>A member of our team will be in touch shortly to discuss your results and recommend the right course for you.</p>";
            $body          = sprintf( $body_template, $final_level ) . $footer;
            $headers       = [ 'Content-Type: text/html; charset=UTF-8' ];
            wp_mail( $to, $subject, $body, $headers );
        }

        // Admin notification
        $admin_emails_raw = get_option( 'adaptive_test_admin_email', get_option( 'admin_email' ) );
        if ( ! empty( $admin_emails_raw ) ) {
            $admin_emails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $admin_emails_raw ) ) ) );
            if ( ! empty( $admin_emails ) ) {
                $bank_name           = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $banks_table WHERE id = %d", $bank_id ) );
                $admin_subject       = get_option( 'adaptive_test_admin_email_subject' ) ?: __( 'New Level Test Completed', 'adaptive-level-test' );
                $admin_body_template = get_option( 'adaptive_test_admin_email_body' ) ?: "<p>A student has completed the English level test.</p>\n<ul>\n<li><strong>Email:</strong> %email%</li>\n<li><strong>Result:</strong> %level%</li>\n<li><strong>Question Bank:</strong> %bank%</li>\n</ul>";
                $admin_body          = str_replace( [ '%email%', '%level%', '%bank%' ], [ $email, $final_level, $bank_name ], $admin_body_template ) . $footer;
                $headers             = [ 'Content-Type: text/html; charset=UTF-8' ];
                foreach ( $admin_emails as $admin_email ) {
                    wp_mail( $admin_email, $admin_subject, $admin_body, $headers );
                }
            }
        }

        adaptive_test_log_result( $email, $final_level, $bank_id, wp_json_encode( $score_data ), $theta, $se, $sub_level, $duration_seconds );

        wp_send_json_success( [
            'finished'   => true,
            'level'      => $final_level,
            'theta'      => $theta,
            'error_rate' => $error_pct,
            'sub_level'  => $sub_level,
        ] );
        return;
    }

    // Continue to next batch
    $shown_ids     = array_map( 'absint', json_decode( wp_unslash( $_POST['shown_ids'] ?? '[]' ), true ) ?: [] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON payload; every element cast through absint.
    $next_level    = $levels[ $next_level_index ];
    $new_questions = adaptive_test_get_questions( $next_level, 5, $bank_id, $shown_ids );

    // Detect whether the pool was reset (returned IDs overlap with shown IDs)
    $returned_ids = array_map( 'intval', array_column( $new_questions, 'id' ) );
    $pool_reset   = ! empty( array_intersect( $returned_ids, $shown_ids ) );

    wp_send_json_success( [ 'questions' => $new_questions, 'finished' => false, 'pool_reset' => $pool_reset, 'error_rate' => $error_pct ] );
}
