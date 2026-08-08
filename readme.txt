=== Adaptive Level Test ===
Contributors: joerebbeck
Tags: quiz, english, esl, cefr, adaptive
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adaptive English level test for ESL students. Determines CEFR level (A2–C2) using Bayesian IRT — fewer questions, more accurate results.

== Description ==

Adaptive Level Test delivers a personalised English placement test that dynamically adjusts to each student's ability level. Rather than presenting every question in a fixed order, the algorithm selects the next question based on the student's previous answers, reaching an accurate CEFR estimate with far fewer questions than a traditional fixed-length test.

**How it works**

* Each question is answered by the student.
* A Bayesian EAP estimator continuously refines the ability estimate (theta) and its standard error.
* The quiz stops automatically once the standard error falls below your configured target confidence, or after the maximum number of question batches.
* The estimated CEFR level (A2 through C2) is displayed on a results screen and emailed to the student.

**Key features**

* Adaptive algorithm: Bayesian EAP with Item Response Theory (IRT) 3PL model
* CEFR levels: A2, B1, B2, C1, C2
* Question banks: up to 3 banks, up to 150 questions each
* CSV import: bulk upload questions with level, stem, and four answer options
* Email results: configurable student and admin notification emails
* Attempt log: searchable, sortable log with CSV export
* Dyslexia-friendly font toggle (OpenDyslexic, SIL OFL 1.1)
* GDPR follow-up consent checkbox
* Honeypot spam protection
* Rate limiting to prevent abuse
* Configurable log retention with daily automated cleanup
* Fully built on the WordPress Settings API — no custom admin framework

**Shortcode**

Place the test on any page or post:

`[adaptive_level_test]`

To use a specific question bank:

`[adaptive_level_test bank="2"]`

**Pro add-on**

The Adaptive Level Test Pro add-on extends the free plugin with visual customisation panels (colours, fonts, borders, shadows), social sharing on the results screen, an animated encouragement overlay, per-student detailed reports, and unlimited question banks and questions per bank. The free plugin is fully functional without it.

**Source code**

Development takes place on [GitHub](https://github.com/joerebbeck/adaptive-level-test-free).

== Installation ==

1. Upload the `adaptive-level-test` folder to `/wp-content/plugins/`, or install directly from the WordPress plugin directory.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Settings → Adaptive Level Test** to configure question banks, email templates, and quiz behaviour.
4. Add `[adaptive_level_test]` to any page or post where you want the test to appear.
5. Import questions via **Questions → Import CSV**, or add them individually through the admin interface.

== Frequently Asked Questions ==

= What is CEFR? =

The Common European Framework of Reference for Languages (CEFR) is an international standard for describing language ability. This plugin assesses levels A2 (Elementary) through C2 (Mastery).

= How does the adaptive algorithm work? =

The plugin uses a Bayesian EAP (Expected A Posteriori) estimator combined with a 3-Parameter Logistic Item Response Theory (IRT) model. After each answer, it updates the probability distribution over the student's ability level and selects the question that provides the most information given the current estimate. The test stops when the standard error falls below your configured target or the maximum number of question batches is reached.

= What CSV format is required for importing questions? =

Columns in order: `question_stem`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_answer`, `cefr_level`. The `correct_answer` field should be the letter A, B, C, or D. The `cefr_level` field must be one of: A2, B1, B2, C1, C2.

= Can I run multiple tests on the same site? =

Yes. Create additional question banks under **Questions → Manage Banks** and use the `bank` shortcode attribute to target each one: `[adaptive_level_test bank="2"]`.

= Does the free plugin require the pro add-on? =

No. The free plugin is fully self-contained and functional without it.

== Privacy Policy ==

This plugin collects and stores personal data as described below. All data is held in your site's own database. The free plugin does not transmit personal data to any third party.

**Data collected per test attempt**

* Student email address — entered voluntarily before the test begins; used to deliver results by email and stored in the attempt log.
* Test outcome: CEFR level, ability estimate (theta), standard error, and test duration — stored alongside the email to allow administrators to review attempt history.
* Date and time of the attempt.

**Storage location:** The `{prefix}adaptive_attempt_logs` database table on your own server.

**Retention period:** Configurable under **Settings → Adaptive Level Test → General Settings → Log Retention**. The default is 90 days; a daily scheduled event automatically removes records older than this threshold. Administrators can also delete individual records or the entire log from the Attempt Logs admin tab.

**Data deletion on uninstall:** If **Delete data on uninstall** is enabled in General Settings, all plugin database tables and stored options are permanently removed when the plugin is uninstalled via the WordPress admin.

**Rate limiting**

To prevent automated abuse, a hashed representation of the visitor's IP address (MD5 hash — the raw address is not stored) is written to a WordPress transient at the start and submission of each test attempt. These transients expire automatically after one hour and are not retained as long-term records.

**Recommended site owner disclosure**

Site owners should include the following information in their own Privacy Policy:

*"When you take the English level test, we collect your email address and store your result (CEFR level and score) in our database. This information is used to send you your results and to allow our team to review outcomes. Records are automatically deleted after [your configured retention period] days. Your IP address is temporarily hashed and stored for up to one hour for abuse-prevention purposes only."*

== Screenshots ==

1. Quiz start screen — students enter their email address to begin.
2. Adaptive question screen with progress bar and question counter.
3. Results screen — CEFR level with scale indicator.
4. Admin: Question Banks management.
5. Admin: Attempt Logs — searchable and sortable.
6. Admin: General Settings.

== Changelog ==

= 1.3.0 =
* Initial public release as a standalone free plugin.
* Core adaptive quiz with Bayesian EAP / IRT 3PL algorithm.
* Up to 3 question banks, 150 questions per bank.
* CSV import with free-tier limit enforcement via filterable hooks.
* Student and admin result notification emails.
* Attempt log with search, sort, per-column filtering, and CSV export.
* Dyslexia-friendly OpenDyslexic font toggle.
* GDPR follow-up consent checkbox and honeypot spam protection.
* Rate limiting via hashed IP transients (1-hour TTL).
* Configurable log retention with daily scheduled cleanup.
* Option to delete all plugin data on uninstall.

== Upgrade Notice ==

= 1.3.0 =
Initial release.
