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
            'name' => 'English A2-C2 - Bank 150',
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
    if ( $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" ) > 10 ) {
        return;
    }

        $questions = [
        [
            'bank_id'       => 1,
            'question_text' => 'They ___.',
            'options'       => json_encode(['have been married since twenty years', 'are married for twenty years', 'have been married for twenty years', 'were married for twenty years']),
            'answer'        => 'have been married for twenty years',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'They don\'t like coffee, ___?',
            'options'       => json_encode(['don\'t they', 'do they', 'aren\'t they', 'didn\'t they']),
            'answer'        => 'do they',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Look at those black clouds. It ___.',
            'options'       => json_encode(['will rain', 'rains', 'rained', 'is going to rain']),
            'answer'        => 'is going to rain',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She doesn\'t like ___ early in the morning.',
            'options'       => json_encode(['get up', 'to get up', 'gets up', 'getting up']),
            'answer'        => 'getting up',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She spoke very ___ because he was in the library.',
            'options'       => json_encode(['quiet', 'quietful', 'quietly', 'quieten']),
            'answer'        => 'quietly',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'This is our car. ___.',
            'options'       => json_encode(['It\'s their', 'It\'s our', 'It\'s ours', 'It\'s we']),
            'answer'        => 'It\'s ours',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'This is the house ___ my grandparents built over sixty years ago.',
            'options'       => json_encode(['who', 'what', 'where', 'which']),
            'answer'        => 'which',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'He told me he ___ spicy food.',
            'options'       => json_encode(['doesn\'t like', 'didn\'t like', 'hasn\'t liked', 'won\'t like']),
            'answer'        => 'didn\'t like',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I think I ___ the pasta, please.',
            'options'       => json_encode(['am going to have', 'will have', 'have', 'am having']),
            'answer'        => 'will have',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'This is ___ exam I have ever taken.',
            'options'       => json_encode(['the more difficult', 'difficult more', 'the most difficult', 'the most difficul']),
            'answer'        => 'the most difficult',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If you press that red button, the alarm ___.',
            'options'       => json_encode(['is going off', 'went off', 'goes off', 'go off']),
            'answer'        => 'goes off',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ swim? Yes, she\'s an excellent swimmer.',
            'options'       => json_encode(['Can she', 'She can', 'Does she can', 'Can she']),
            'answer'        => 'Can she',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I ___ ten years old.',
            'options'       => json_encode(['is', 'am', 'are', 'be']),
            'answer'        => 'am',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If the weather is nice tomorrow, we ___ to the beach.',
            'options'       => json_encode(['would go', 'went', 'will go', 'are going']),
            'answer'        => 'will go',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'You ___ a uniform at school if it\'s a free day.',
            'options'       => json_encode(['must to wear', 'have to wore', 'don\'t have to wear', 'haven\'t to wear']),
            'answer'        => 'don\'t have to wear',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ a famous person in real life?',
            'options'       => json_encode(['Did you ever meet', 'Do you ever meet', 'Have you ever met', 'Are you ever meeting']),
            'answer'        => 'Have you ever met',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'My school is ___ than my friend\'s school.',
            'options'       => json_encode(['more big', 'bigger than', 'bigger', 'more bigger']),
            'answer'        => 'bigger',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ a supermarket near your house?',
            'options'       => json_encode(['Is there', 'Are there', 'There is', 'Is there']),
            'answer'        => 'Is there',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I ___.',
            'options'       => json_encode(['can\'t to swim', 'can to swim', 'can swim', 'cans swim']),
            'answer'        => 'can swim',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'When I ___ at the party, everyone was already dancing.',
            'options'       => json_encode(['was arriving', 'arrive', 'arrived', 'arriving']),
            'answer'        => 'arrived',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She\'s going to the concert tonight, ___?',
            'options'       => json_encode(['hasn\'t she', 'won\'t she', 'isn\'t she', 'doesn\'t she']),
            'answer'        => 'isn\'t she',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I ___ breakfast before school.',
            'options'       => json_encode(['always am eating', 'always eat', 'eat always', 'does always eat']),
            'answer'        => 'always eat',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I promise I ___ you as soon as I land.',
            'options'       => json_encode(['am phoning', 'phoned', 'phone', 'will phone']),
            'answer'        => 'will phone',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Look! The children ___ football in the garden right now.',
            'options'       => json_encode(['play', 'played', 'plays', 'are playing']),
            'answer'        => 'are playing',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'He ___ in the evening before going to bed.',
            'options'       => json_encode(['sometimes watches', 'watches sometimes', 'sometimes watch', 'sometimes watches TV']),
            'answer'        => 'sometimes watches TV',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'They ___ good students and they work very hard in class.',
            'options'       => json_encode(['is', 'am', 'are', 'be']),
            'answer'        => 'are',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'He can play the guitar, ___?',
            'options'       => json_encode(['can he', 'doesn\'t he', 'isn\'t he', 'can\'t he']),
            'answer'        => 'can\'t he',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'We ___ a new project next month — everything is already planned.',
            'options'       => json_encode(['will start', 'are going to start', 'start', 'started']),
            'answer'        => 'are going to start',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I ___ sushi before. Is it difficult to eat with chopsticks?',
            'options'       => json_encode(['never tried', 'tried never', 'have never tried', 'haven\'t never tried']),
            'answer'        => 'have never tried',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'They ___ at school yesterday because they were both feeling ill.',
            'options'       => json_encode(['wasn\'t', 'be not', 'weren\'t', 'didn\'t were']),
            'answer'        => 'weren\'t',
            'level'         => 'A2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Rita said that she ___ the play at the theatre last Tuesday.',
            'options'       => json_encode(['had enjoyed', 'enjoys', 'is enjoying', 'would enjoy']),
            'answer'        => 'had enjoyed',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She is gradually ___ in front of large audiences, which is essential for her new management role.',
            'options'       => json_encode(['used to speak', 'get used to speaking', 'used to speaking', 'getting used to speaking']),
            'answer'        => 'getting used to speaking',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'It took her several months to ___ alone after moving out of her family home.',
            'options'       => json_encode(['get used living', 'get used to live', 'used to living', 'get used to living']),
            'answer'        => 'get used to living',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I never go ___ without my phone.',
            'options'       => json_encode(['anywhere', 'nowhere', 'somewhere', 'everywhere']),
            'answer'        => 'anywhere',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Tom couldn’t buy the laptop because it was ___',
            'options'       => json_encode(['too expensive', 'expensive enough', 'not enough money', 'too money']),
            'answer'        => 'too expensive',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'People ___ mobile phones, and somehow they still managed to stay in touch with everyone they needed to.',
            'options'       => json_encode(['used to not having', 'didn\'t used to have', 'didn\'t use to have', 'not used to have']),
            'answer'        => 'didn\'t use to have',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I left my phone on the table but now it\'s gone. Someone ___ it by mistake.',
            'options'       => json_encode(['can\'t take', 'must have taken', 'should have taken', 'might be taking']),
            'answer'        => 'must have taken',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'My sister ___ in a concert tomorrow.',
            'options'       => json_encode(['is singing', 'does singing', 'sung', 'singing']),
            'answer'        => 'is singing',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'We ___ very tired after that long walk yesterday.',
            'options'       => json_encode(['felt', 'feeling', 'fell', 'fallen']),
            'answer'        => 'felt',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The children asked ___ to the park after school to play football with their friends.',
            'options'       => json_encode(['going', 'go', 'to going', 'to go']),
            'answer'        => 'to go',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'My cousins and I often see ___ at the weekend.',
            'options'       => json_encode(['each other', 'themselves', 'yourselves', 'ourselves']),
            'answer'        => 'each other',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The woman ___ interviewed me was extremely professional and put me at ease immediately.',
            'options'       => json_encode(['which', 'whose', 'who', 'that she']),
            'answer'        => 'who',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'You ___ look at all the paintings in the exhibition, it\'s not obligatory.',
            'options'       => json_encode(['don’t have to', 'shouldn’t', 'oughtn’t to', 'mustn’t']),
            'answer'        => 'don’t have to',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'They ___ on the same project {1:MCS:~during~for~=since~from} the beginning of the year.',
            'options'       => json_encode(['work', 'worked', 'are working', 'have been working']),
            'answer'        => 'have been working',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Are you ___ on the left yet? It can take visitors a while to feel comfortable with it.',
            'options'       => json_encode(['used to drive', 'getting used driving', 'getting used to driving', 'used to driving']),
            'answer'        => 'getting used to driving',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'That’s not true! ___ you!',
            'options'       => json_encode(['I don’t believe', 'I believe', 'I’m not believing', 'I’m believing']),
            'answer'        => 'I don’t believe',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'We ___ to the beach yesterday because it was too cold.',
            'options'       => json_encode(['didn’t go', 'don’t went', 'didn’t going', 'don’t gone']),
            'answer'        => 'didn’t go',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The manager ___ department won the award was visibly moved when her name was read out.',
            'options'       => json_encode(['which', 'who', 'whose', 'that his']),
            'answer'        => 'whose',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If ___ a lot of money, I’d live in a castle!',
            'options'       => json_encode(['I had', 'I have', 'I would have', 'I’ll have']),
            'answer'        => 'I had',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She\'s the doctor ___ successfully treated my father after his operation.',
            'options'       => json_encode(['which', 'whose', 'where', 'who']),
            'answer'        => 'who',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The teacher asked us to ___ some ideas for a class trip.',
            'options'       => json_encode(['suggest', 'announce', 'insist', 'demand']),
            'answer'        => 'suggest',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The hotel, ___ rooftop pool is heated all year round, has been fully booked since summer.',
            'options'       => json_encode(['which its', 'who', 'whose', 'that the']),
            'answer'        => 'whose',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Is there ___ good café near here?',
            'options'       => json_encode(['a', 'the', '–', 'an']),
            'answer'        => 'a',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The new office is much larger. ___, the commute is twice as long, which makes it less appealing for many employees.',
            'options'       => json_encode(['Also', 'Despite', 'Although', 'Nevertheless']),
            'answer'        => 'Nevertheless',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Heather ___ me about the party.',
            'options'       => json_encode(['told', 'announced', 'said', 'explained']),
            'answer'        => 'told',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'He told me he ___ my brother at the station earlier that morning.',
            'options'       => json_encode(['has seen', 'saw', 'had seen', 'was seeing']),
            'answer'        => 'had seen',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Don’t worry, we ___ start cooking until six o’clock.',
            'options'       => json_encode(['don’t have to', 'oughtn’t to', 'shouldn’t', 'mustn’t']),
            'answer'        => 'don’t have to',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I ___ for a reply to my email {1:MCS:~for~during~=since~from} last Tuesday.',
            'options'       => json_encode(['waited', 'am waiting', 'wait', 'have been waiting']),
            'answer'        => 'have been waiting',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She said she ___ the project by the end of the following week.',
            'options'       => json_encode(['will finish', 'finishes', 'finish', 'would finish']),
            'answer'        => 'would finish',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I always ask my teacher ___ something.',
            'options'       => json_encode(['if I don’t understand', 'unless I understand', 'if I understand', 'unless I don’t understand']),
            'answer'        => 'if I don’t understand',
            'level'         => 'B1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'When they finally found the child he ___ with cold.',
            'options'       => json_encode(['was trembling', 'trembled', 'had trembled', 'trembling']),
            'answer'        => 'was trembling',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'A number of studies ___ led us to this conclusion.',
            'options'       => json_encode(['have', 'has', 'are', 'might']),
            'answer'        => 'have',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I wasn’t at home last night so it ___ been me!',
            'options'       => json_encode(['couldn’t have', 'can’t', 'couldn’t', 'can’t had']),
            'answer'        => 'couldn’t have',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I wish I ___ another language fluently — it would open up so many professional opportunities.',
            'options'       => json_encode(['can speak', 'would speak', 'could speak', 'am speaking']),
            'answer'        => 'could speak',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'How long ___ French for?',
            'options'       => json_encode(['have you been studying', 'you have studied', 'have you studying', 'are studying']),
            'answer'        => 'have you been studying',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'It ___ John that bought that book for you but I\'m not sure.',
            'options'       => json_encode(['might have been', 'must have been', 'can’t have been', 'must not have been']),
            'answer'        => 'might have been',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I think you ___ go to the doctor and get that cut looked at.',
            'options'       => json_encode(['had better', 'ought', 'don’t have to', 'need']),
            'answer'        => 'had better',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'By the time you read this letter, I ___ the country and started a completely new chapter of my life.',
            'options'       => json_encode(['will leave', 'am leaving', 'will have left', 'would have left']),
            'answer'        => 'will have left',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Yesterday we ___ to sweep the floors and tidy up the classrooms before we went home.',
            'options'       => json_encode(['were told', 'told', 'are told', 'tell']),
            'answer'        => 'were told',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I wish I ___ more foreign languages.',
            'options'       => json_encode(['spoke', 'speak', 'spoken', 'speaked']),
            'answer'        => 'spoke',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I ___ for the technician to call back all morning — it is now nearly two o\'clock.',
            'options'       => json_encode(['wait', 'am waiting', 'have been waiting', 'was waiting']),
            'answer'        => 'have been waiting',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If he ___ harder, he might have passed the professional examination on his first attempt.',
            'options'       => json_encode(['had studied harder', 'would have studied harder', 'studied harder', 'had studied']),
            'answer'        => 'had studied',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The hotel was expensive. ___, the service and location more than made up for the cost.',
            'options'       => json_encode(['While', 'Whether', 'Despite', 'Even so']),
            'answer'        => 'Even so',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I think that DVD players ___ by 2030.',
            'options'       => json_encode(['will have disappeared', 'will be disappeared', 'will disappear', 'is disappearing']),
            'answer'        => 'will have disappeared',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Make the most of your free time because ___ soon.',
            'options'       => json_encode(['you’ll be working', 'you work', 'you’ll work', 'you’d work']),
            'answer'        => 'you’ll be working',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ the first candidate was highly qualified, the panel ultimately chose someone with more practical experience.',
            'options'       => json_encode(['Although that', 'In spite that', 'Even that', 'Even though']),
            'answer'        => 'Even though',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'There ___ a gig at the Greek’s Head pub tomorrow, do you want to come?',
            'options'       => json_encode(['is', 'can be', 'is being', 'can’t be']),
            'answer'        => 'is',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'We ___ watching a film, so shall we go and get some food?',
            'options'       => json_encode(['’ve just finished', '’ve just finish', 'finished', 'had finished']),
            'answer'        => '’ve just finished',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If I weren’t kept up to date with the news, I ___ that story.',
            'options'       => json_encode(['might have believed', 'have believed', 'might believed', 'had believed']),
            'answer'        => 'might have believed',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'He ___ well for a few days, I think he should see the doctor.',
            'options'       => json_encode(['hasn’t been feeling', 'isn’t feeling', 'didn’t feel', 'felt']),
            'answer'        => 'hasn’t been feeling',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'You really ___ buy me a present, but thank you anyway.',
            'options'       => json_encode(['didn’t need to', 'needn’t have', 'didn’t need', 'need not have']),
            'answer'        => 'didn’t need to',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Did you know the bus ___ in ten minutes?',
            'options'       => json_encode(['leaves', 'will leave', 'is going to leave', 'can leave']),
            'answer'        => 'leaves',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Unless the budget ___ by the finance committee, we will have to scale back the renovation plans significantly.',
            'options'       => json_encode(['approves', 'was approved', 'isn\'t approved', 'is approved']),
            'answer'        => 'is approved',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She acts as if she ___ everything about the subject, yet she has only been studying it for six months.',
            'options'       => json_encode(['will know', 'knew', 'has known', 'would know']),
            'answer'        => 'knew',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Our school is recycling ___ the council figures suggest.',
            'options'       => json_encode(['far more than', 'almost as more than', 'the more than', 'quite as more as']),
            'answer'        => 'far more than',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The old town hall ___ to make way for a new cultural centre.',
            'options'       => json_encode(['demolished', 'will demolish', 'is being demolished', 'is demolished by']),
            'answer'        => 'is being demolished',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I promise I ___ you as soon as I have any news about the application.',
            'options'       => json_encode(['am calling', 'will have called', 'will call', 'shall have called']),
            'answer'        => 'will call',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'She ___ at the salon on Thursday — she\'s been planning the new style for weeks.',
            'options'       => json_encode(['is going to cut', 'is cutting', 'is having her hair cut', 'has her hair cut']),
            'answer'        => 'is having her hair cut',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Look at those clouds. It ___ — we should probably head indoors before the storm arrives.',
            'options'       => json_encode(['is going to rain', 'will be raining', 'looks like it\'s going to rain', 'seems it rains']),
            'answer'        => 'looks like it\'s going to rain',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I thought I ___ to catch the 3.30 train but in the end I made it.',
            'options'       => json_encode(['might not be able', 'might not', 'might be able', 'might not can']),
            'answer'        => 'might not be able',
            'level'         => 'B2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The exhibition attracted ___ many visitors as had been predicted when the venue was first announced.',
            'options'       => json_encode(['nowhere near as', 'nothing near as', 'not as much as', 'nowhere near']),
            'answer'        => 'nowhere near as',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___, the surgeon completed the final procedure before finally allowing a colleague to relieve him.',
            'options'       => json_encode(['As exhausted as', 'Though exhausted as', 'Exhausted as was he', 'Exhausted as he was']),
            'answer'        => 'Exhausted as he was',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'He agreed ___ me with planning my presentation.',
            'options'       => json_encode(['to help', 'helping', 'to helping', 'on help']),
            'answer'        => 'to help',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'It ___ heavily by the time we reach the mountain pass, so we should set off as early as possible.',
            'options'       => json_encode(['is to snow', 'will be snowing', 'will have snowed', 'will have been snowing']),
            'answer'        => 'will be snowing',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ been so bored by a film before.',
            'options'       => json_encode(['Never have I', 'Never I have', 'Never was I', 'Never did I']),
            'answer'        => 'Never have I',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The results were far more conclusive than ___.',
            'options'       => json_encode(['anyone was anticipated', 'be anticipated', 'had anyone anticipated', 'anyone had anticipated']),
            'answer'        => 'anyone had anticipated',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'All passengers ___ valid identification before boarding the vessel.',
            'options'       => json_encode(['are required to show', 'are requesting to show', 'must showing', 'should showed']),
            'answer'        => 'are required to show',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'We were hoping to go on holiday but our plans ___ because we didn’t have enough money saved up.',
            'options'       => json_encode(['fell it through', 'fell through', 'fell through it', 'fell through themselves']),
            'answer'        => 'fell through',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Unfortunately, you will ___ attend a seminar on Saturday.',
            'options'       => json_encode(['have to', 'must', 'be allowed to', 'can']),
            'answer'        => 'have to',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I have to insist ___ informed about any changes that affect my department.',
            'options'       => json_encode(['on being', 'being', 'about being', 'in being']),
            'answer'        => 'on being',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Such ___ that it required the coordinated involvement of four separate government departments.',
            'options'       => json_encode(['was the scale of the operation', 'the scale of the operation was', 'is the operation scale', 'were the scale of the operation']),
            'answer'        => 'was the scale of the operation',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The woman finally succeeded ___ her driving license test.',
            'options'       => json_encode(['to pass', 'pass', 'in passing', 'passing']),
            'answer'        => 'in passing',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'In the end, I ___ my car after driving around for two hours.',
            'options'       => json_encode(['was unable to park', 'could park', 'can’t have parked', 'might not have parked']),
            'answer'        => 'was unable to park',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The scheme was ___ — within the first year it had exceeded every performance indicator set by the steering group.',
            'options'       => json_encode(['nothing of a success', 'not at all success', 'nothing short of a success', 'not short of success']),
            'answer'        => 'nothing short of a success',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ the party doesn’t finish too late, I will be home before midnight.',
            'options'       => json_encode(['Unless', 'Supposing', 'Whether', 'Even if']),
            'answer'        => 'Supposing',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If only my brother ___ borrowing my things without asking.',
            'options'       => json_encode(['would stop', 'stops', 'can stop', 'will stop']),
            'answer'        => 'would stop',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The customer was convinced that she was ___ receive a refund but she was proven wrong.',
            'options'       => json_encode(['entitled to', 'required to', 'free to', 'managed to']),
            'answer'        => 'entitled to',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I\'m frustrated because my neighbour ___ loud music late at night, even on weekdays.',
            'options'       => json_encode(['would always play', 'is always playing', 'always plays', 'always played']),
            'answer'        => 'is always playing',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ stepped through the door when her phone rang with news of the court\'s decision.',
            'options'       => json_encode(['Scarcely had she', 'She has scarcely', 'Scarcely she had', 'Scarcely she has']),
            'answer'        => 'Scarcely had she',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If only ___ that to her at the party — I could see it really upset her.',
            'options'       => json_encode(['I didn’t say', 'I hadn’t said', 'I haven’t said', 'I wouldn’t say']),
            'answer'        => 'I hadn’t said',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I can go pick up Jim at the airport ___ they have my car fixed on time.',
            'options'       => json_encode(['providing that', 'whether', 'assumed that', 'supposed']),
            'answer'        => 'providing that',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The architect\'s vision, ___, proved impossible to realise within the constraints of the original budget.',
            'options'       => json_encode(['ambitious it was', 'however was ambitious', 'ambitious as it was', 'as ambitious it was']),
            'answer'        => 'ambitious as it was',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The plot of the film was so confusing that we went out ___ what it meant.',
            'options'       => json_encode(['wondering', 'wondered', 'having wondered', 'we wonder']),
            'answer'        => 'wondering',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The city ___ the novel is set no longer exists in anything like the form described by the author.',
            'options'       => json_encode(['about which', 'which', 'when', 'in which']),
            'answer'        => 'in which',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Given the dreadful weather, that was as comfortable a journey ___ we could have expected.',
            'options'       => json_encode(['than', 'how', 'that', 'as']),
            'answer'        => 'as',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'We ___ this book a few days before we were asked to use it in class.',
            'options'       => json_encode(['had bought', 'have bought', 'didn’t buy', 'buy']),
            'answer'        => 'had bought',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ is one of my greatest fears.',
            'options'       => json_encode(['Flying', 'To fly', 'Having flown', 'The flight']),
            'answer'        => 'Flying',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => '___ outcome of a negotiation predictable when both sides enter the room with fixed demands and no intention of compromising.',
            'options'       => json_encode(['Seldom is the', 'The seldom', 'Seldom the', 'Was seldom the']),
            'answer'        => 'Seldom is the',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'I hope ___ your stay at our hotel.',
            'options'       => json_encode(['You are enjoying', 'you are to enjoy', 'to enjoy', 'you would enjoy']),
            'answer'        => 'You are enjoying',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The delegates left the summit ___ whether any of the commitments made would actually be honoured.',
            'options'       => json_encode(['wondering', 'wondered', 'having wondered', 'they wonder']),
            'answer'        => 'wondering',
            'level'         => 'C1',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'My phone was as dead as a ___after I accidentally dropped it into a puddle.',
            'options'       => json_encode(['doornail', 'brick', 'raindrop', 'stone']),
            'answer'        => 'doornail',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The criminal managed to lead the detectives on a wild goose ___which gave him time to escape.',
            'options'       => json_encode(['chase', 'hunt', 'pursuit', 'quest']),
            'answer'        => 'chase',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Urban expansion is ___air pollution in cities across the country.',
            'options'       => json_encode(['exacerbating', 'tackling', 'adressing', 'alleviating']),
            'answer'        => 'exacerbating',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'There is no easy way out to this problem. We are stuck between a ___ and a hard place.',
            'options'       => json_encode(['rock', 'stone', 'wall', 'brick']),
            'answer'        => 'rock',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If you want to ___ a point across clearly in a meeting, you should avoid using jargon that not everyone present will be familiar with.',
            'options'       => json_encode(['get', 'put', 'take', 'bring']),
            'answer'        => 'get',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The journalist\'s report was based entirely on ___ and could not be verified by a single named source, leading the editorial board to question its publication.',
            'options'       => json_encode(['hearsay', 'evidence', 'testimony', 'documentation']),
            'answer'        => 'hearsay',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The board unanimously decided to ___ the project after the feasibility report revealed it would cost three times the original estimate.',
            'options'       => json_encode(['shelve', 'scrap', 'abandon', 'withdraw']),
            'answer'        => 'shelve',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Thieves ___ the security guard into letting them into the building by disguising themselves as cleaning staff.',
            'options'       => json_encode(['conned', 'blackmailed', 'double-crossed', 'scammed']),
            'answer'        => 'conned',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The government’s attempts to do something about online fraud seem to come in fits and ___.',
            'options'       => json_encode(['starts', 'outs', 'bits', 'fiddles']),
            'answer'        => 'starts',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Mentors are experienced individuals who ___ knowledge, expertise and wisdom to less experienced individuals.',
            'options'       => json_encode(['impart', 'cultivate', 'instil', 'instruct']),
            'answer'        => 'impart',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The defence minister\'s claim that the operation had been ___ at the highest level of government was directly contradicted by the prime minister\'s office.',
            'options'       => json_encode(['sanctioned', 'condemned', 'rejected', 'prohibited']),
            'answer'        => 'sanctioned',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The whistleblower\'s revelations ___ the extent to which senior management had been aware of the fraudulent accounting practices for several years.',
            'options'       => json_encode(['exposed', 'clarified', 'justified', 'reinforced']),
            'answer'        => 'exposed',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The minister ___ all criticism of the scheme by pointing out that independent analysts had consistently praised its long-term economic projections.',
            'options'       => json_encode(['deflected', 'absorbed', 'welcomed', 'embraced']),
            'answer'        => 'deflected',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The government\'s policy of blanket ___ had a devastating effect on public services, with libraries, leisure centres, and local councils all facing severe budget cuts.',
            'options'       => json_encode(['austerity', 'prosperity', 'generosity', 'abundance']),
            'answer'        => 'austerity',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The charity\'s fundraising campaign had an ___ response from the public, raising more than twice the target figure within the first 48 hours.',
            'options'       => json_encode(['unprecedented', 'predictable', 'modest', 'expected']),
            'answer'        => 'unprecedented',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The aim of the project was to ___ the area back to its former glory.',
            'options'       => json_encode(['revert', 'resign', 'revolt', 'reassure']),
            'answer'        => 'revert',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'If you want to ___ in your career you must be ready to dedicate time to professional training programmes.',
            'options'       => json_encode(['excel', 'deliver', 'accomplish', 'culminate']),
            'answer'        => 'excel',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The new regulations have made it considerably harder for small businesses to ___ with their tax obligations without the help of a professional accountant.',
            'options'       => json_encode(['comply', 'concede', 'consent', 'cooperate']),
            'answer'        => 'comply',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Mentors are experienced individuals who ___ knowledge, expertise and wisdom to less experienced individuals.',
            'options'       => json_encode(['impart', 'cultivate', 'instil', 'instruct']),
            'answer'        => 'impart',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The government’s attempts to do something about online fraud seem to come in fits and ___.',
            'options'       => json_encode(['starts', 'outs', 'bits', 'fiddles']),
            'answer'        => 'starts',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Urban expansion is ___ air pollution in cities across the country.',
            'options'       => json_encode(['exacerbating', 'tackling', 'adressing', 'alleviating']),
            'answer'        => 'exacerbating',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The criminal managed to lead the detectives on a wild goose ___ which gave him time to escape.',
            'options'       => json_encode(['chase', 'hunt', 'pursuit', 'quest']),
            'answer'        => 'chase',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'My phone was as dead as a ___ after I accidentally dropped it into a puddle.',
            'options'       => json_encode(['doornail', 'brick', 'raindrop', 'stone']),
            'answer'        => 'doornail',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'The aim of the project was to ___ the area back to its former glory.',
            'options'       => json_encode(['revert', 'resign', 'revolt', 'reassure']),
            'answer'        => 'revert',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'There is no easy way out to this problem. We are stuck between a ___ and a hard place.',
            'options'       => json_encode(['rock', 'stone', 'wall', 'brick']),
            'answer'        => 'rock',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Choose the best word to complete the sentence. This comedian is famous for her self-___ humour, all her jokes revolving around her own perceived failings.',
            'options'       => json_encode(['deprecating', 'indulgent', 'respecting', 'aggrandizing']),
            'answer'        => 'deprecating',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Choose the best word to complete the sentence. Despite the heinous crimes he committed, the only worry the murderer had was whether or not he had ___ the good name of his family.',
            'options'       => json_encode(['besmirched', 'bequeathed', 'bewildered', 'berated']),
            'answer'        => 'besmirched',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Which of the options does NOT normally collocate with the given word? a(n) ___ visit.',
            'options'       => json_encode(['speedy', 'fleeting', 'flying', 'impromptu']),
            'answer'        => 'speedy',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Choose the best answer to complete the sentence. That new piece of propaganda worked exactly as the government intended and was undoubtedly a ___ of genius.',
            'options'       => json_encode(['stroke', 'burst', 'touch', 'flash']),
            'answer'        => 'stroke',
            'level'         => 'C2',
            'type'          => 'multiple_choice'
        ],
        [
            'bank_id'       => 1,
            'question_text' => 'Choose the best answer to complete the sentence. Realizing their families would never approve of their union, the young couple decided to ___ under the cover of darkness, leaving nothing behind but a brief note of explanation.',
            'options'       => json_encode(['elope', 'abscond', 'decamp', 'slip']),
            'answer'        => 'elope',
            'level'         => 'C2',
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