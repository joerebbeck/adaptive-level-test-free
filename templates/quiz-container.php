<?php
/**
 * Template: Quiz Container
 * Description: Main wrapper for the adaptive test interface.
 * Location: templates/quiz-container.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;
// Template loaded via include from the shortcode handler. Variables are local to this
// template scope and not exposed as true globals; prefixing would harm readability without
// any safety benefit.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$bank_id       = get_query_var('adaptive_bank_id', 1);
$title         = get_option( 'adaptive_test_start_title',    __( 'Start Your Level Test', 'adaptive-level-test' ) );
$subtitle      = get_option( 'adaptive_test_start_subtitle', __( 'Enter your email address to begin the test.', 'adaptive-level-test' ) );
$body          = get_option( 'adaptive_test_start_body',     __( 'By starting the test, you agree for your results to be sent to the email address that you provide.', 'adaptive-level-test' ) );
$placeholder   = get_option( 'adaptive_test_start_email_placeholder', 'name@example.com' );
$gdpr2_on      = (bool) get_option( 'adaptive_test_start_gdpr2_enabled', 0 );
$gdpr2_default = __( "I'd like to receive information about English courses and relevant offers. I understand I can withdraw this consent at any time.", 'adaptive-level-test' );
$gdpr2_msg     = get_option( 'adaptive_test_start_gdpr2_message', '' ) ?: $gdpr2_default;
$btn_text      = get_option( 'adaptive_test_start_button_text',       __( 'Start Test', 'adaptive-level-test' ) );
$show_progress = get_option( 'adaptive_test_during_show_progress', 1 ) ? '1' : '0';
$show_counter  = get_option( 'adaptive_test_during_show_counter', 0 ) ? '1' : '0';
$counter_fmt   = get_option( 'adaptive_test_during_counter_format', 'Question %n% of %total%' );
$q_align       = get_option( 'adaptive_test_during_question_align', 'center' );
$opt_align     = get_option( 'adaptive_test_during_options_align', 'center' );
?>
<div class="adaptive-wrapper">
    <div class="adaptive-card esl-state-before" id="adaptive-app"
         data-bank-id="<?php echo esc_attr( $bank_id ); ?>"
         data-show-progress="<?php echo esc_attr( $show_progress ); ?>"
         data-show-counter="<?php echo esc_attr( $show_counter ); ?>"
         data-counter-format="<?php echo esc_attr( $counter_fmt ); ?>"
         data-question-align="<?php echo esc_attr( $q_align ); ?>"
         data-options-align="<?php echo esc_attr( $opt_align ); ?>">

        <?php if ( get_option( 'adaptive_test_during_dyslexic_enabled', 1 ) ) : ?>
        <button type="button" class="esl-dyslexic-toggle" id="esl-dyslexic-toggle" aria-pressed="false"><?php echo esc_html( get_option( 'adaptive_test_during_dyslexic_off', __( 'Change to dyslexia friendly font', 'adaptive-level-test' ) ) ); ?></button>
        <?php endif; ?>

        <!-- Email Capture Section -->
        <div id="esl-email-capture">
            <h2 class="adaptive-question"><?php echo wp_kses_post( $title ); ?></h2>
            <?php if ( $subtitle ) : ?>
                <p style="text-align:center; margin-bottom: 20px; color: var(--esl-text-muted);"><?php echo wp_kses_post( $subtitle ); ?></p>
            <?php endif; ?>
            <?php if ( $body ) : ?>
                <div style="margin-bottom: 20px; text-align: center;"><?php echo wp_kses_post( $body ); ?></div>
            <?php endif; ?>
            <div style="margin-bottom: 20px; text-align: center;">
                <input type="email" id="esl-test-email" placeholder="<?php echo esc_attr( $placeholder ); ?>" style="width: 100%; padding: 16px; border: 2px solid var(--esl-border-color); border-radius: 12px; font-size: 1rem; box-sizing: border-box; text-align: center;">
                <p id="esl-email-error" role="alert" style="display:none; color:#c0392b; margin-top:8px; font-size:0.9em;"></p>
            </div>
            <div style="position:absolute;left:-9999px;height:0;overflow:hidden;" aria-hidden="true">
                <input type="text" id="esl-hp-url" name="esl_hp_url" value="" tabindex="-1" autocomplete="off">
            </div>
            <?php if ( $gdpr2_on ) : ?>
                <div style="margin-bottom: 16px; display: flex; justify-content: center; align-items: flex-start; gap: 10px;">
                    <input type="checkbox" id="esl-gdpr2-checkbox" style="margin-top: 3px; flex-shrink: 0;">
                    <label for="esl-gdpr2-checkbox" style="font-size: 0.9em; color: var(--esl-text-muted); text-align: center;"><?php echo wp_kses_post( $gdpr2_msg ); ?></label>
                </div>
            <?php endif; ?>
            <button type="button" id="esl-start-btn" class="adaptive-option-btn" style="text-align: center; background-color: var(--esl-primary); color: #fff; border-color: var(--esl-primary); font-weight: 600;">
                <?php echo esc_html( $btn_text ); ?>
            </button>
        </div>

        <!-- Quiz Interface (Hidden by default) -->
        <div id="esl-quiz-interface" class="adaptive-hidden">
            <!-- Question Counter -->
            <div id="esl-counter" style="display:none; text-align:center; margin-bottom:8px; font-size:0.85em; color:var(--esl-text-muted);"></div>

            <!-- Question Area -->
            <div id="esl-question-area">
                <h2 class="adaptive-question" id="esl-question-text">
                    <!-- Question text will be injected here via JS -->
                    <?php esc_html_e('Loading question...', 'adaptive-level-test'); ?>
                </h2>

                <!-- Options Container -->
                <div class="adaptive-options" id="esl-options-container">
                    <!-- Options will be injected here via JS -->
                </div>
            </div>

            <!-- Progress Bar (moved to bottom) -->
            <div class="adaptive-progress-container" id="esl-progress-container">
                <div id="esl-progress-bar" class="adaptive-progress-bar" style="width: 0%;"></div>
            </div>
        </div>

        <!-- Analysing Overlay — covers the whole card, shown between batches -->
        <div id="esl-analysing-overlay" class="esl-analysing-overlay">
            <div class="esl-av-pincer">
                <div id="esl-cv2-l" class="esl-cv2-l"></div>
                <div class="esl-cv2-target"></div>
                <div id="esl-cv2-r" class="esl-cv2-r"></div>
            </div>
            <p id="esl-analysing-text" class="esl-analysing-text"></p>
        </div>

    </div>
</div>
