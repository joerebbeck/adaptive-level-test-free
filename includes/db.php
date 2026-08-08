<?php
if (!defined('ABSPATH')) {
    exit;
}
// All queries in this file target the plugin's own tables ($wpdb->prefix . 'adaptive_*').
// Table names come from $wpdb->prefix (site-owner-controlled, not user input) so interpolation
// is safe. dbDelta() manages schema creation; direct queries are the only API for these operations.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Create the database table for questions.
 * This should be triggered on plugin activation.
 */
function adaptive_test_create_questions_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'adaptive_questions';
    $banks_table = $wpdb->prefix . 'adaptive_question_banks';
    $logs_table = $wpdb->prefix . 'adaptive_attempt_logs';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        bank_id mediumint(9) NOT NULL DEFAULT 1,
        question_text text NOT NULL,
        options text NOT NULL,
        answer text NOT NULL,
        level varchar(5) NOT NULL,
        difficulty float DEFAULT NULL,
        type varchar(20) NOT NULL DEFAULT 'multiple_choice',
        PRIMARY KEY  (id)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $banks_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(255) NOT NULL,
        is_default tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $logs_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        email varchar(255) NOT NULL,
        level varchar(10) NOT NULL,
        bank_name varchar(255) NOT NULL DEFAULT '',
        score_data text NOT NULL,
        theta float DEFAULT NULL,
        se float DEFAULT NULL,
        sub_level varchar(20) NOT NULL DEFAULT '',
        duration_seconds int DEFAULT NULL,
        date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;" );

    // Compound index used by every question-fetch query (WHERE bank_id = ? AND level = ? ORDER BY RAND()).
    // IF NOT EXISTS keeps this idempotent on repeated calls (e.g. db-version upgrades).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query( "CREATE INDEX IF NOT EXISTS idx_bank_level ON {$table_name} (bank_id, level)" );

    // Fix for existing questions: Ensure they belong to the default bank (ID 1)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query( "UPDATE {$table_name} SET bank_id = 1 WHERE bank_id = 0" );

    // Ensure default bank exists
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
    $default_bank = $wpdb->get_row( "SELECT * FROM {$banks_table} WHERE is_default = 1" );
    if (!$default_bank) {
        $wpdb->insert($banks_table, [
            'name' => 'Default Question Bank',
            'is_default' => 1
        ]);
    }
}

/**
 * Retrieve a batch of random questions for a specific level.
 *
 * @param string $level The CEFR level (e.g., 'B1').
 * @param int $limit Number of questions to fetch.
 * @param int $bank_id The question bank ID.
 * @return array Array of question objects.
 */
function adaptive_test_get_questions( $level, $limit = 5, $bank_id = 1, $excluded_ids = [] ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'adaptive_questions';

    if ( ! empty( $excluded_ids ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $excluded_ids ), '%d' ) );
        $args         = array_merge( [ $level, $bank_id ], $excluded_ids, [ $limit ] );
        $query        = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- variadic spread; placeholder count matches $args at runtime.
            "SELECT id, question_text, options, level, type FROM {$table_name} WHERE level = %s AND bank_id = %d AND id NOT IN ({$placeholders}) ORDER BY RAND() LIMIT %d",
            ...$args
        );
        $results = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! empty( $results ) ) {
            return $results;
        }
        // All questions at this level exhausted — fall back to full pool and allow repeats
    }

    $query = $wpdb->prepare(
        "SELECT id, question_text, options, level, type FROM {$table_name} WHERE level = %s AND bank_id = %d ORDER BY RAND() LIMIT %d",
        $level, $bank_id, $limit
    );
    return $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

/**
 * Seed the database with sample questions for testing.
 * Focuses on B1 level as that is the entry point.
 */
function adaptive_test_insert_sample_questions() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'adaptive_questions';

    // Check if questions already exist
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
    if ( $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) > 5 ) {
        return;
    }

    $questions = [
        [
            'bank_id'       => 1,
            'question_text' => 'I ________ to the cinema yesterday.',
            'options'       => json_encode(['go', 'went', 'gone', 'have gone']),
            'answer'        => 'went',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She ________ usually drink coffee.',
            'options'       => json_encode(['doesn\'t', 'don\'t', 'isn\'t', 'not']),
            'answer'        => 'doesn\'t',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If I ________ you, I would study harder.',
            'options'       => json_encode(['was', 'am', 'were', 'be']),
            'answer'        => 'were',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'By the time we arrived, the film ________.',
            'options'       => json_encode(['finished', 'had finished', 'has finished', 'was finished']),
            'answer'        => 'had finished',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She has been working here ________ 2010.',
            'options'       => json_encode(['since', 'for', 'from', 'until']),
            'answer'        => 'since',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The government is considering ________ taxes.',
            'options'       => json_encode(['raising', 'rising', 'to raise', 'to rise']),
            'answer'        => 'raising',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I look forward to ________ from you.',
            'options'       => json_encode(['hear', 'heard', 'hearing', 'hears']),
            'answer'        => 'hearing',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Scarcely had I entered the room ________ the phone rang.',
            'options'       => json_encode(['when', 'than', 'then', 'after']),
            'answer'        => 'when',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The book ________ was written by J.K. Rowling is famous.',
            'options'       => json_encode(['who', 'which', 'where', 'whose']),
            'answer'        => 'which',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I am interested ________ learning Spanish.',
            'options'       => json_encode(['on', 'in', 'at', 'for']),
            'answer'        => 'in',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Despite ________ tired, he continued working.',
            'options'       => json_encode(['he was', 'of being', 'being', 'to be']),
            'answer'        => 'being',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ]
    ];

    foreach ($questions as $q) {
        $wpdb->insert($table_name, $q);
    }
}

/**
 * Log a completed test result.
 */
function adaptive_test_log_result( $email, $level, $bank_id, $score_data = '', $theta = null, $se = null, $sub_level = '', $duration_seconds = null ) {
    global $wpdb;
    $logs_table  = $wpdb->prefix . 'adaptive_attempt_logs';
    $banks_table = $wpdb->prefix . 'adaptive_question_banks';

    $bank_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $banks_table WHERE id = %d", $bank_id ) );

    $wpdb->insert( $logs_table, [
        'email'            => $email,
        'level'            => $level,
        'bank_name'        => $bank_name ? $bank_name : 'Unknown Bank',
        'score_data'       => $score_data,
        'theta'            => $theta,
        'se'               => $se,
        'sub_level'        => $sub_level,
        'duration_seconds' => $duration_seconds,
    ] );
}