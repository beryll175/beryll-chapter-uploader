<?php
/**
 * Plugin Name: Beryll Chapter Uploader
 * Description: Import Fictioneer chapters from ZIP/DOCX or EPUB files, detect duplicates, publish immediately, and schedule by selected weekdays.
 * Version: 2.5.4
 * Author: Beryll TL
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Beryll_Chapter_Uploader {
    const VERSION = '2.5.4';
    const PAGE = 'beryll-chapter-uploader';
    const TRANSIENT_PREFIX = 'bcu_import_';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_post_bcu_preview', array( $this, 'handle_preview' ) );
        add_action( 'admin_post_bcu_import', array( $this, 'handle_import' ) );
        add_action( 'admin_post_bcu_save_settings', array( $this, 'save_settings' ) );
        add_action( 'admin_post_bcu_save_github_settings', array( $this, 'save_github_settings' ) );
        add_action( 'admin_post_bcu_test_github_relay', array( $this, 'test_github_relay' ) );
        add_action( 'wp_ajax_bcu_process_batch', array( $this, 'process_batch' ) );
    }

    public function menu() {
        add_menu_page( 'Beryll Chapter Uploader', 'Chapter Uploader', 'manage_options', self::PAGE, array( $this, 'render' ), 'dashicons-upload', 26 );
        add_submenu_page( self::PAGE, 'Patreon Release Scheduler', 'Patreon Scheduler', 'manage_options', 'bcu-patreon-scheduler', array( 'Beryll_Patreon_Release_Scheduler', 'render_page' ) );
    }

    private function notice( $message, $type = 'error' ) {
        echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . wp_kses_post( $message ) . '</p></div>';
    }

   public function render() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    echo '<div class="wrap"><h1>Beryll Chapter Uploader <small style="font-size:13px;color:#777">v' . esc_html( self::VERSION ) . '</small></h1>';

    /*
     * General notices.
     */
    if ( isset( $_GET['bcu_saved'] ) ) {
        $this->notice( 'Upload limit saved.', 'success' );
    }

    if ( isset( $_GET['bcu_github_saved'] ) ) {
        $this->notice( 'GitHub connection settings saved.', 'success' );
    }

    if ( isset( $_GET['bcu_github_removed'] ) ) {
        $this->notice( 'GitHub access token removed.', 'success' );
    }

    if ( isset( $_GET['bcu_error'] ) ) {
        $this->notice(
            sanitize_text_field( wp_unslash( $_GET['bcu_error'] ) )
        );
    }

    /*
     * GitHub relay test results.
     */
    if ( isset( $_GET['bcu_github_test'] ) ) {
        $test_result = sanitize_key( wp_unslash( $_GET['bcu_github_test'] ) );

        if ( 'success' === $test_result ) {
            $this->notice(
                'GitHub accepted the workflow dispatch. Check your Discord channel for the test notification.',
                'success'
            );
        } elseif ( 'no_token' === $test_result ) {
            $this->notice(
                'No GitHub token is saved. Please save your token first.',
                'error'
            );
        } elseif ( 'connection_error' === $test_result ) {
            $this->notice(
                'WordPress could not connect to GitHub. Check your hosting connection.',
                'error'
            );
        } elseif ( 'api_error' === $test_result ) {
            $this->notice(
                'GitHub rejected the workflow request. Check your repository, workflow filename, and token permissions.',
                'error'
            );
        }
    }

    /*
     * Import result.
     */
    if ( isset( $_GET['bcu_result'] ) ) {
        $encoded_result = sanitize_text_field( wp_unslash( $_GET['bcu_result'] ) );
        $decoded_result = base64_decode( $encoded_result, true );
        $r = false !== $decoded_result ? json_decode( $decoded_result, true ) : null;

        if ( is_array( $r ) ) {
            $this->notice(
                sprintf(
                    'Import finished. %1$d published now, %2$d scheduled, %3$d saved as drafts, %4$d replaced, %5$d skipped, %6$d failed.',
                    absint( $r['published'] ?? 0 ),
                    absint( $r['scheduled'] ?? 0 ),
                    absint( $r['drafts'] ?? 0 ),
                    absint( $r['replaced'] ?? 0 ),
                    absint( $r['skipped'] ?? 0 ),
                    absint( $r['failed'] ?? 0 )
                ),
                'success'
            );
        }
    }

    /*
     * GitHub connection settings.
     */
    $github_username = get_option( 'bcu_github_username', 'beryll175' );
    $github_repo     = get_option( 'bcu_github_repo', 'beryll-chapter-uploader' );
    $github_workflow = get_option( 'bcu_github_workflow', 'discord-notify.yml' );
    $github_token    = get_option( 'bcu_github_token', '' );

    echo '<hr><h2>GitHub Discord Notification Relay</h2>';
    echo '<p>Connect Beryll TL to GitHub Actions so your website can send Discord notifications without connecting directly to Discord.</p>';

    /*
     * Save GitHub settings form.
     */
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;padding:20px;max-width:650px;border:1px solid #ccd0d4">';

    echo '<input type="hidden" name="action" value="bcu_save_github_settings">';
    wp_nonce_field( 'bcu_save_github_settings' );

    echo '<p><label><strong>GitHub username</strong><br>';
    echo '<input type="text" name="bcu_github_username" value="' . esc_attr( $github_username ) . '" required style="width:100%;max-width:400px"></label></p>';

    echo '<p><label><strong>Repository name</strong><br>';
    echo '<input type="text" name="bcu_github_repo" value="' . esc_attr( $github_repo ) . '" required style="width:100%;max-width:400px"></label></p>';

    echo '<p><label><strong>Workflow filename</strong><br>';
    echo '<input type="text" name="bcu_github_workflow" value="' . esc_attr( $github_workflow ) . '" required style="width:100%;max-width:400px"></label></p>';

    echo '<p><label><strong>GitHub access token</strong><br>';
    echo '<input type="password" name="bcu_github_token" value="" autocomplete="new-password" placeholder="' . esc_attr( $github_token ? 'Token already saved. Leave blank to keep it.' : 'Paste your fine-grained token here' ) . '" style="width:100%;max-width:400px"></label></p>';

    echo '<p class="description">Your token is not displayed after saving. Leave the field empty to keep the existing token.</p>';

    echo '<p><button type="submit" class="button button-primary">Save GitHub Settings</button></p>';

    echo '</form>';

    /*
     * Token status and removal.
     */
    if ( $github_token ) {
        echo '<p><strong>Connection token:</strong> <span style="color:green">Saved</span></p>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px">';
        echo '<input type="hidden" name="action" value="bcu_save_github_settings">';
        echo '<input type="hidden" name="bcu_remove_github_token" value="1">';
        wp_nonce_field( 'bcu_save_github_settings' );

        echo '<button type="submit" class="button" onclick="return confirm(\'Remove the saved GitHub token?\')">Remove GitHub Token</button>';
        echo '</form>';

        /*
         * Test GitHub-to-Discord relay.
         */
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
        echo '<input type="hidden" name="action" value="bcu_test_github_relay">';
        wp_nonce_field( 'bcu_test_github_relay' );

        echo '<p><button type="submit" class="button button-secondary" onclick="return confirm(\'This will send a test notification to your Discord channel. Continue?\')">Send Discord Test Notification</button></p>';
        echo '</form>';

        echo '<p class="description">This sends a test notification through GitHub Actions. It does not publish or modify any chapters.</p>';

    } else {
        echo '<p><strong>Connection token:</strong> <span style="color:#b32d2e">Not configured</span></p>';
        echo '<p class="description">Save your GitHub access token before testing the connection.</p>';
    }

    /*
     * Existing import progress.
     */
    if ( isset( $_GET['bcu_job'] ) ) {
        $job_token = sanitize_text_field( wp_unslash( $_GET['bcu_job'] ) );
        $job = get_transient( 'bcu_job_' . $job_token );

        if ( $job && (int) $job['user_id'] === get_current_user_id() ) {
            $this->render_progress( $job_token, $job );
            echo '</div>';
            return;
        }

        $this->notice( 'The import session expired. Please check your chapter list before starting another import.' );
    }

    /*
     * Existing chapter preview.
     */
    if ( isset( $_GET['bcu_token'] ) ) {
        $token = sanitize_text_field( wp_unslash( $_GET['bcu_token'] ) );
        $data = get_transient( self::TRANSIENT_PREFIX . $token );

        if ( $data && (int) $data['user_id'] === get_current_user_id() ) {
            $this->render_preview( $token, absint( $data['story_id'] ), $data['chapters'] );
            echo '</div>';
            return;
        }

        $this->notice( 'Your preview expired. Please upload the file again.' );
    }

    /*
     * Check Fictioneer.
     */
    if ( ! post_type_exists( 'fcn_chapter' ) || ! post_type_exists( 'fcn_story' ) ) {
        $this->notice( 'Fictioneer Stories and Chapters were not detected. Activate the Fictioneer theme before using this uploader.' );
        echo '</div>';
        return;
    }

    /*
     * Check ZIP support.
     */
    if ( ! class_exists( 'ZipArchive' ) ) {
        $this->notice( 'Your hosting PHP does not have the ZipArchive extension enabled. ZIP/DOCX/EPUB imports need it.' );
        echo '</div>';
        return;
    }

    /*
     * Retrieve available novels.
     */
    $stories = get_posts( array(
        'post_type'   => 'fcn_story',
        'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
        'numberposts' => -1,
        'orderby'     => 'title',
        'order'       => 'ASC',
    ) );

    if ( empty( $stories ) ) {
        $this->notice( 'Create a Fictioneer Story first, then return here to import its chapters.', 'warning' );
        echo '</div>';
        return;
    }

    /*
     * Chapter upload form.
     */
    echo '<p>Upload a ZIP containing one DOCX per chapter, or an EPUB. The uploader previews the chapters before importing them.</p>';

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="background:#fff;padding:20px;max-width:760px;border:1px solid #ccd0d4">';

    echo '<input type="hidden" name="action" value="bcu_preview">';
    wp_nonce_field( 'bcu_preview' );

    echo '<p><label for="bcu_story"><strong>Novel</strong></label><br><select id="bcu_story" name="story_id" required style="min-width:100%;max-width:100%"><option value="">Select a novel</option>';

    foreach ( $stories as $story ) {
        echo '<option value="' . esc_attr( $story->ID ) . '">' . esc_html( $story->post_title ) . '</option>';
    }

    echo '</select></p>';

    echo '<p><label for="bcu_file"><strong>Upload ZIP or EPUB</strong></label><br><input id="bcu_file" type="file" name="bcu_file" accept=".zip,.epub" required></p>';

    echo '<p class="description">ZIP: one .docx file per chapter. Files inside folders and subfolders are supported. EPUB chapters are read in the book’s internal reading order.</p>';

    echo '<p><button class="button button-primary">Upload and Preview</button></p>';
    echo '</form>';

    /*
     * Upload limits.
     */
    echo '<hr><h2>Upload limits</h2>';

    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="background:#fff;padding:14px;max-width:500px;border:1px solid #ccd0d4">';

    echo '<input type="hidden" name="action" value="bcu_save_settings">';
    wp_nonce_field( 'bcu_save_settings' );

    $max_chapters = $this->max_chapters();

    echo '<label><strong>Maximum chapters per upload</strong><br><input type="number" name="bcu_max_chapters" min="1" max="200" value="' . esc_attr( $max_chapters ) . '" style="width:100px"> <span class="description">Choose between 1 and 200. Default: 100.</span></label>';

    echo '<p class="description">Imports are processed automatically in groups of 5 chapters to reduce PHP timeouts. Maximum file size: 15 MB.</p>';

    echo '<button class="button" type="submit">Save upload limit</button>';
    echo '</form>';

    /*
     * Uploader features.
     */
    echo '<hr><h2>What this uploader does</h2><ul style="list-style:disc;padding-left:22px">';

    echo '<li>Preview and edit chapter titles before importing.</li>';
    echo '<li>Choose how many chapters publish immediately; schedule the rest by weekday.</li>';
    echo '<li>Detect duplicate chapter numbers within the same volume/group and replace or skip duplicates.</li>';
    echo '<li>Optionally import the whole selected batch as drafts.</li>';
    echo '<li>Process imports automatically in groups of five with a progress indicator.</li>';

    echo '</ul>';

    echo '<p><strong>Formatting:</strong> paragraphs, bold, italic, underline, headings, links, lists and blockquotes are preserved where available. Embedded images are not imported.</p>';

    echo '</div>';
}

    public function handle_preview() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Permission denied.' ); }
        check_admin_referer( 'bcu_preview' );
        $story_id = isset( $_POST['story_id'] ) ? absint( $_POST['story_id'] ) : 0;
        if ( get_post_type( $story_id ) !== 'fcn_story' ) { $this->redirect_error( 'Please select a valid novel.' ); }
        if ( empty( $_FILES['bcu_file']['tmp_name'] ) || ! empty( $_FILES['bcu_file']['error'] ) ) { $this->redirect_error( 'The upload failed. Check the file size limit and try again.' ); }
        if ( ! empty( $_FILES['bcu_file']['size'] ) && (int) $_FILES['bcu_file']['size'] > 15 * MB_IN_BYTES ) { $this->redirect_error( 'Please keep each ZIP or EPUB upload under 15 MB on this free hosting plan.' ); }
        $name = sanitize_file_name( $_FILES['bcu_file']['name'] ); $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, array( 'zip', 'epub' ), true ) ) { $this->redirect_error( 'Please upload a .zip or .epub file.' ); }
        $chapters = ( 'zip' === $ext ) ? $this->parse_docx_zip( $_FILES['bcu_file']['tmp_name'] ) : $this->parse_epub( $_FILES['bcu_file']['tmp_name'] );
        if ( is_wp_error( $chapters ) ) { $this->redirect_error( $chapters->get_error_message() ); }
        if ( empty( $chapters ) ) { $this->redirect_error( 'No chapters were detected in that file.' ); }
        if ( count( $chapters ) > $this->max_chapters() ) { $this->redirect_error( 'This file contains ' . count( $chapters ) . ' detected chapters. Your current upload limit is ' . $this->max_chapters() . '. Increase the limit (up to 200) or split the file into smaller uploads.' ); }
        $token = wp_generate_password( 24, false, false );
        set_transient( self::TRANSIENT_PREFIX . $token, array( 'story_id' => $story_id, 'chapters' => $chapters, 'user_id' => get_current_user_id() ), HOUR_IN_SECONDS );
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'bcu_token' => $token ), admin_url( 'admin.php' ) ) ); exit;
    }

    private function render_preview( $token, $story_id, $chapters ) {
       $story = get_post( $story_id );
       $existing = $this->existing_chapter_map( $story_id );

       echo '<h2>Review chapters</h2>';
       echo '<p><strong>Novel:</strong> ' . esc_html( $story ? $story->post_title : '' ) . '</p>';
       echo '<p>' . count( $chapters ) . ' chapter(s) detected. Select the chapters to import and review any duplicate warnings.</p>';

      echo '<form id="bcu-import-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
      echo '<input type="hidden" name="action" value="bcu_import">';
      echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
       wp_nonce_field( 'bcu_import_' . $token );

       echo '<p><button type="button" class="button" id="bcu-select-all">Select all</button> ';
       echo '<button type="button" class="button" id="bcu-deselect-all">Deselect all</button></p>';

       echo '<div style="overflow-x:auto"><table class="widefat striped">';
       echo '<thead><tr><th>Import</th><th>#</th><th>Chapter title</th><th>Words</th><th>Existing chapter</th><th>Content preview</th></tr></thead><tbody>';

       foreach ( $chapters as $i => $chapter ) {
        $plain = wp_strip_all_tags( $chapter['content'] );
        $words = str_word_count( $plain );
        $number = $this->chapter_number( $chapter['title'], $i + 1 );

        // Volume grouping is no longer used.
        $group_key = $this->duplicate_key( '', $number );
        $matches = isset( $existing[ $group_key ] ) ? $existing[ $group_key ] : array();

        $duplicate_text = empty( $matches )
            ? '<span style="color:#16803c">No match</span>'
            : '<strong style="color:#b32d2e">Duplicate: ' . count( $matches ) . ' existing</strong><br><small>' . esc_html( $matches[0]->post_title ) . '</small>';

        echo '<tr>';
        echo '<td><input class="bcu-include" type="checkbox" name="include[]" value="' . esc_attr( $i ) . '" checked></td>';
        echo '<td>' . esc_html( $number ) . '</td>';
        echo '<td><input type="text" name="titles[' . esc_attr( $i ) . ']" value="' . esc_attr( $chapter['title'] ) . '" required style="width:100%;min-width:150px"></td>';
        echo '<td>' . number_format_i18n( $words ) . '</td>';
        echo '<td>' . $duplicate_text . '</td>';
        echo '<td>' . esc_html( wp_trim_words( $plain, 20 ) ) . '</td>';
        echo '</tr>';
      }

     echo '</tbody></table></div>';

     echo '<h2>Existing chapter handling</h2>';
      echo '<p>If matching chapter numbers already exist, choose what to do:</p>';

      echo '<label style="display:block;margin:6px 0"><input type="radio" name="duplicate_policy" value="replace" checked> <strong>Replace existing chapters</strong> (update their content while keeping their existing URLs)</label>';
      echo '<label style="display:block;margin:6px 0"><input type="radio" name="duplicate_policy" value="skip"> <strong>Skip existing chapters</strong> (do not upload those chapters)</label>';
     echo '<label style="display:block;margin:6px 0"><input type="radio" name="duplicate_policy" value="cancel"> <strong>Cancel import if duplicates are found</strong></label>';

     echo '<hr><h2>Release mode</h2>';

     echo '<label style="display:block;margin:8px 0"><input type="radio" name="release_mode" value="normal" checked> <strong>Normal scheduling</strong> — publish chapters immediately or schedule their public release.</label>';

      echo '<label style="display:block;margin:8px 0"><input type="radio" name="release_mode" value="patreon"> <strong>Patreon scheduling</strong> — organise chapters into Free, Treasure, and Vault groups, with automatic promotions.</label>';

      echo '<label style="display:block;margin:8px 0"><input type="checkbox" id="bcu-all-drafts" name="all_drafts" value="1"> <strong>Import all selected chapters as drafts</strong></label>';

      echo '<p><label><strong>Number of chapters to publish immediately</strong><br>';
     echo '<input type="number" id="bcu-immediate-count" name="immediate_count" min="0" max="250" value="0" style="width:100px">';
      echo ' <span class="description">Remaining normal chapters will be scheduled.</span></label></p>';

      echo '<div id="bcu-patreon-allocation" style="display:none">';
       echo '<hr><h2>Patreon chapter distribution</h2>';
       echo '<p>Chapters are allocated in the following order: Free first, Treasure second, and Vault last. All Patreon chapters are published immediately but remain protected according to their assigned tier.</p>';

       echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:14px;max-width:700px">';

      echo '<p><label><strong>Free / Public</strong><br>';
       echo '<input type="number" name="public_count" min="0" max="250" value="0" style="width:90px"></label></p>';

       echo '<p><label><strong>Treasure + Vault</strong><br>';
       echo '<input type="number" name="treasure_count" min="0" max="250" value="0" style="width:90px"></label></p>';

      echo '<p><label><strong>Vault exclusive</strong><br>';
       echo '<input type="number" name="vault_count" min="0" max="250" value="0" style="width:90px"></label></p>';

       echo '<p class="description">The selected chapters are allocated in the order shown above. Remaining chapters continue through the normal immediate or scheduled public-release settings below. Configure recurring Patreon release days and time under Chapter Uploader → Patreon Scheduler.</p>';

       echo '</div></div>';

       echo '<div id="bcu-schedule-settings" style="padding:14px;background:#fff;border:1px solid #ccd0d4;max-width:700px">';
       echo '<h3>Weekly schedule</h3><p><strong>Publishing days</strong></p>';

       $days = array(
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
      );

      foreach ( $days as $n => $label ) {
        echo '<label style="display:inline-block;margin:0 12px 8px 0">';
        echo '<input class="bcu-day" type="checkbox" name="days[]" value="' . esc_attr( $n ) . '" ' . checked( in_array( $n, array( 2, 4, 6 ), true ), true, false ) . '> ';
        echo esc_html( $label );
        echo '</label>';
      }

      echo '<p><label>Chapters per publishing day<br>';
      echo '<input type="number" id="bcu-per-day" name="per_day" min="1" max="50" value="1" style="width:90px"></label></p>';

      echo '<p><label>First publication date<br>';
      echo '<input type="date" id="bcu-start-date" name="start_date" value="' . esc_attr( wp_date( 'Y-m-d', current_datetime()->getTimestamp() + DAY_IN_SECONDS ) ) . '"></label> &nbsp; ';

      echo '<label>Publishing time<br>';
      echo '<input type="time" id="bcu-publish-time" name="publish_time" value="19:00"></label></p>';

      echo '<p class="description">The scheduler starts on the first selected weekday on or after the date above. Times use your WordPress site timezone.</p>';
     echo '</div>';

     echo '<h3>Schedule preview</h3>';
     echo '<div id="bcu-schedule-preview" style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:700px">Select chapters and publishing settings to preview the schedule.</div>';

      echo '<p><button class="button button-primary" type="submit" onclick="return confirm(\'Import these chapters with the settings shown?\')">Import chapters</button> ';
      echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Cancel</a></p>';

      echo '</form>';
      ?>

      <script>
      (function(){
        const form = document.getElementById('bcu-import-form');
        if (!form) return;

        const boxes = () => Array.from(form.querySelectorAll('.bcu-include:checked'));
        const allDrafts = document.getElementById('bcu-all-drafts');
        const immediate = document.getElementById('bcu-immediate-count');
        const settings = document.getElementById('bcu-schedule-settings');
        const patreonBox = document.getElementById('bcu-patreon-allocation');
        const preview = document.getElementById('bcu-schedule-preview');

        const wpNow = '<?php echo esc_js( current_datetime()->format( 'Y-m-d H:i' ) ); ?>';

        const escapeHtml = s => String(s).replace(/[&<>"']/g, c => ({
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#039;'
        }[c]));

        document.getElementById('bcu-select-all').addEventListener('click', () => {
            form.querySelectorAll('.bcu-include').forEach(x => x.checked = true);
            update();
        });

        document.getElementById('bcu-deselect-all').addEventListener('click', () => {
            form.querySelectorAll('.bcu-include').forEach(x => x.checked = false);
            update();
        });

        function update() {
            const mode = form.querySelector('input[name="release_mode"]:checked').value;

            patreonBox.style.display = mode === 'patreon' ? 'block' : 'none';

            settings.style.opacity = allDrafts.checked ? '0.45' : '1';
            settings.querySelectorAll('input').forEach(x => {
                x.disabled = allDrafts.checked;
            });

            const selected = boxes();
            const policy = form.querySelector('input[name="duplicate_policy"]:checked').value;

            const chapters = selected
                .filter(x => !(policy === 'skip' && x.closest('tr').querySelector('td:nth-child(5) strong')))
                .map(x => ({
                    title: x.closest('tr').querySelector('input[type="text"]').value,
                    number: x.closest('tr').children[1].textContent
                }));

            const count = chapters.length;

            const free = mode === 'patreon' ? Math.max(0, +form.querySelector('[name="public_count"]').value || 0) : 0;
            const treasure = mode === 'patreon' ? Math.max(0, +form.querySelector('[name="treasure_count"]').value || 0) : 0;
            const vault = mode === 'patreon' ? Math.max(0, +form.querySelector('[name="vault_count"]').value || 0) : 0;

            const distributionTotal = free + treasure + vault;

            form.querySelectorAll('[name="public_count"],[name="treasure_count"],[name="vault_count"]').forEach(x => {
                x.disabled = allDrafts.checked;
            });

            if (allDrafts.checked) {
                immediate.disabled = true;
                preview.textContent = count + ' selected chapter(s) will be imported as drafts. Access distribution is ignored.';
                return;
            }

            immediate.disabled = false;

            if (distributionTotal > count) {
                preview.textContent = 'The Patreon allocation totals ' + distributionTotal + ', but only ' + count + ' chapter(s) are selected. Reduce the counts.';
                return;
            }

            const remaining = chapters.slice(distributionTotal);

            immediate.max = remaining.length;

            if (+immediate.value > remaining.length) {
                immediate.value = remaining.length;
            }

            const publish = Math.min(Math.max(0, +immediate.value || 0), remaining.length);
            const toSchedule = remaining.length - publish;

            const freeNames = chapters.slice(0, free);
            const treasureNames = chapters.slice(free, free + treasure);
            const vaultNames = chapters.slice(free + treasure, distributionTotal);

            const immediateNames = remaining.slice(0, publish);

            const days = Array.from(form.querySelectorAll('.bcu-day:checked'))
                .map(x => +x.value)
                .sort((a,b) => a-b);

            const per = Math.max(1, +document.getElementById('bcu-per-day').value || 1);
            const date = document.getElementById('bcu-start-date').value;
            const time = document.getElementById('bcu-publish-time').value || '19:00';

            const summary =
                'Free (' + free + '): ' + freeNames.map(c => '#' + c.number + ' ' + c.title).join('; ') +
                ' | Treasure (' + treasure + '): ' + treasureNames.map(c => '#' + c.number + ' ' + c.title).join('; ') +
                ' | Vault (' + vault + '): ' + vaultNames.map(c => '#' + c.number + ' ' + c.title).join('; ');

            if (mode !== 'patreon' && !toSchedule) {
                preview.textContent = publish + ' normal chapters will publish immediately.';
                return;
            }

            if (!toSchedule) {
                preview.textContent = summary + ' | ' + publish + ' remaining normal chapters will publish immediately.';
                return;
            }

            if (!days.length || !date) {
                preview.textContent = summary + ' | ' + publish + ' normal chapters publish immediately. ' + toSchedule + ' remaining chapters need scheduling. Select at least one weekday and a start date.';
                return;
            }

            const cursor = new Date(date + 'T12:00:00');
            const rows = [];
            let position = 0;
            let guard = 0;

            while (position < toSchedule && guard < 500) {
                const dow = cursor.getDay() === 0 ? 7 : cursor.getDay();

                const slotDate =
                    cursor.getFullYear() + '-' +
                    String(cursor.getMonth()+1).padStart(2,'0') + '-' +
                    String(cursor.getDate()).padStart(2,'0') + ' ' + time;

                if (days.includes(dow) && slotDate > wpNow) {
                    const group = [];

                    for (let j = 0; j < per && position < toSchedule; j++, position++) {
                        const chapter = remaining[publish + position];
                        group.push('#' + chapter.number + ' ' + escapeHtml(chapter.title));
                    }

                    rows.push(
                        '<tr><td>' +
                        cursor.toLocaleDateString(undefined, {
                            weekday:'short',
                            year:'numeric',
                            month:'short',
                            day:'numeric'
                        }) +
                        ' ' + time + '</td><td>' + group.join('<br>') + '</td></tr>'
                    );
                }

                cursor.setDate(cursor.getDate()+1);
                guard++;
            }

            preview.innerHTML =
                '<p><strong>' + escapeHtml(summary) + '</strong><br>' +
                publish + ' normal chapters publish immediately.<br>' +
                toSchedule + ' normal chapters scheduled.</p>' +
                '<div style="overflow-x:auto"><table class="widefat striped">' +
                '<thead><tr><th>Publication</th><th>Chapters</th></tr></thead>' +
                '<tbody>' + rows.join('') + '</tbody></table></div>';
        }

        form.addEventListener('change', update);
        form.addEventListener('input', update);

        update();
      })();
       </script>

      <?php
    }

    public function handle_import() {  
     if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.' );
      }

      $token = isset( $_POST['token'] )
        ? sanitize_text_field( wp_unslash( $_POST['token'] ) )
        : '';

      check_admin_referer( 'bcu_import_' . $token );

      $data = get_transient( self::TRANSIENT_PREFIX . $token );

       if ( ! $data || (int) $data['user_id'] !== get_current_user_id() ) {
        $this->redirect_error( 'Your preview expired. Please upload the file again.' );
      }

      $story_id = absint( $data['story_id'] );

      if ( get_post_type( $story_id ) !== 'fcn_story' ) {
        $this->redirect_error( 'The selected novel no longer exists.' );
      }

       $titles = isset( $_POST['titles'] ) && is_array( $_POST['titles'] )
        ? array_map( 'sanitize_text_field', wp_unslash( $_POST['titles'] ) )
        : array();

     $included = isset( $_POST['include'] ) && is_array( $_POST['include'] )
        ? array_values( array_unique( array_map( 'absint', wp_unslash( $_POST['include'] ) ) ) )
        : array();

      $included = array_values( array_intersect( $included, array_keys( $data['chapters'] ) ) );

      if ( empty( $included ) ) {
        $this->redirect_error( 'Select at least one chapter to import.' );
     }

      $policy = isset( $_POST['duplicate_policy'] )
        ? sanitize_key( $_POST['duplicate_policy'] )
        : 'replace';

      if ( ! in_array( $policy, array( 'replace', 'skip', 'cancel' ), true ) ) {
        $policy = 'replace';
      }

      $existing_map = $this->existing_chapter_map( $story_id );

      $work = array();
      $duplicate_count = 0;

      foreach ( $included as $i ) {
        $title = isset( $titles[$i] ) && '' !== trim( $titles[$i] )
            ? $titles[$i]
            : $data['chapters'][$i]['title'];

        $number = $this->chapter_number( $title, $i + 1 );

        // Volume grouping has been removed.
        $duplicate_key = $this->duplicate_key( '', $number );

        $matches = isset( $existing_map[$duplicate_key] )
            ? $existing_map[$duplicate_key]
            : array();

        if ( ! empty( $matches ) ) {
            $duplicate_count++;

            if ( 'skip' === $policy || 'cancel' === $policy ) {
                continue;
            }
        }

        $work[] = array(
            'index'      => $i,
            'title'      => $title,
            'number'     => $number,
            'group'      => '',
            'existing_id' => ( ! empty( $matches ) && 'replace' === $policy )
                ? (int) $matches[0]->ID
                : 0
        );
      }

        if ( 'cancel' === $policy && $duplicate_count ) {
        $this->redirect_error( 'Duplicates were found. Nothing was imported. Choose Replace or Skip.' );
     }

     if ( empty( $work ) ) {
        $this->redirect_error( 'All selected chapters were skipped because they already exist.' );
     }

     $all_drafts = ! empty( $_POST['all_drafts'] );

      $release_mode = isset( $_POST['release_mode'] )
        ? sanitize_key( $_POST['release_mode'] )
        : 'normal';

     if ( ! in_array( $release_mode, array( 'normal', 'patreon' ), true ) ) {
        $release_mode = 'normal';
     }

      // Patreon allocation order: Free, Treasure, Vault.
      $public_count = ( $all_drafts || 'patreon' !== $release_mode )
        ? 0
        : max( 0, absint( $_POST['public_count'] ?? 0 ) );

      $treasure_count = ( $all_drafts || 'patreon' !== $release_mode )
        ? 0
        : max( 0, absint( $_POST['treasure_count'] ?? 0 ) );

     $vault_count = ( $all_drafts || 'patreon' !== $release_mode )
        ? 0
        : max( 0, absint( $_POST['vault_count'] ?? 0 ) );

      $patreon_count = $public_count + $treasure_count + $vault_count;

      if ( $patreon_count > count( $work ) ) {
        $this->redirect_error( 'The Free, Treasure, and Vault counts cannot exceed the number of selected chapters.' );
      }

     // Chapters not allocated to a Patreon group continue through normal publishing.
     $remaining_count = count( $work ) - $patreon_count;

     $immediate_count = $all_drafts
        ? 0
        : max(
            0,
            min(
                $remaining_count,
                isset( $_POST['immediate_count'] )
                    ? absint( $_POST['immediate_count'] )
                    : 0
            )
        );

     $scheduled_count = $all_drafts
        ? 0
        : $remaining_count - $immediate_count;

     $days = isset( $_POST['days'] ) && is_array( $_POST['days'] )
        ? array_values( array_unique( array_map( 'absint', wp_unslash( $_POST['days'] ) ) ) )
        : array();

     $days = array_values( array_intersect( $days, array( 1, 2, 3, 4, 5, 6, 7 ) ) );
        sort( $days );

     $per_day = isset( $_POST['per_day'] )
        ? max( 1, min( 50, absint( $_POST['per_day'] ) ) )
        : 1;

     $schedule_slots = array();

      if ( $scheduled_count > 0 ) {
        if ( empty( $days ) ) {
            $this->redirect_error( 'Select at least one publishing weekday, or increase the immediate publishing quantity.' );
        }

        $start_date = isset( $_POST['start_date'] )
            ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) )
            : '';

        $publish_time = isset( $_POST['publish_time'] )
            ? sanitize_text_field( wp_unslash( $_POST['publish_time'] ) )
            : '19:00';

        if (
            ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ||
            ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $publish_time )
        ) {
            $this->redirect_error( 'Enter a valid first publication date and time.' );
        }

        $cursor = DateTimeImmutable::createFromFormat( '!Y-m-d', $start_date, wp_timezone() );
        $date_errors = DateTimeImmutable::getLastErrors();

        if (
            ! $cursor ||
            ( $date_errors && ( $date_errors['warning_count'] || $date_errors['error_count'] ) ) ||
            $cursor->format( 'Y-m-d' ) !== $start_date
        ) {
            $this->redirect_error( 'The first publication date is invalid.' );
        }

        $hour_minute = explode( ':', $publish_time );

        $cursor = $cursor->setTime(
            (int) $hour_minute[0],
            (int) $hour_minute[1],
            0
        );

        $now = current_datetime();
        $guard = 0;

        while ( count( $schedule_slots ) < $scheduled_count && $guard < 4000 ) {
            $weekday = (int) $cursor->format( 'N' );

            if (
                in_array( $weekday, $days, true ) &&
                $cursor->getTimestamp() > $now->getTimestamp()
            ) {
                for ( $n = 0; $n < $per_day && count( $schedule_slots ) < $scheduled_count; $n++ ) {
                    $schedule_slots[] = $cursor->format( 'Y-m-d H:i:s' );
                }
            }

            $cursor = $cursor->modify( '+1 day' );
            $guard++;
        }

        if ( count( $schedule_slots ) !== $scheduled_count ) {
            $this->redirect_error( 'The schedule could not be generated. Check your publishing days and start date.' );
        }
     }

     $job_token = wp_generate_password( 28, false, false );

     $job = array(
        'user_id'         => get_current_user_id(),
        'story_id'        => $story_id,
        'chapters'        => $data['chapters'],
        'work'            => $work,
        'all_drafts'      => $all_drafts,
        'release_mode'    => $release_mode,

        'public_count'    => $public_count,
        'treasure_count'  => $treasure_count,
        'vault_count'     => $vault_count,

        'immediate_count' => $immediate_count,
        'schedule_slots'  => $schedule_slots,

        'cursor'          => 0,

        'result' => array(
            'published' => 0,
            'scheduled' => 0,
            'drafts'    => 0,
            'replaced'  => 0,
            'skipped'   => count( $included ) - count( $work ),
            'failed'    => 0
        ),

        'visible_ids'  => array(),
        'preview_token' => $token,
        'job_token'     => $job_token,
        'complete'      => false
      );

      set_transient( 'bcu_job_' . $job_token, $job, 2 * HOUR_IN_SECONDS );

      wp_safe_redirect(
        add_query_arg(
            array(
                'page'    => self::PAGE,
                'bcu_job' => $job_token
            ),
            admin_url( 'admin.php' )
        )
      );

      exit;
    }

    private function render_progress( $job_token, $job ) {
        $total = count( $job['work'] ); $done = (int) $job['cursor']; $percent = $total ? (int) floor( 100 * $done / $total ) : 100;
        echo '<h2>Importing chapters</h2><p>Your chapters are being imported in small groups of five. Keep this page open until the import finishes.</p>';
        echo '<div style="max-width:650px;background:#fff;border:1px solid #ccd0d4;padding:18px"><div style="height:20px;background:#e5e5e5;border-radius:3px;overflow:hidden"><div id="bcu-progress-bar" style="height:20px;width:' . esc_attr( $percent ) . '%;background:#2271b1"></div></div><p id="bcu-progress-text"><strong>' . esc_html( $done ) . ' of ' . esc_html( $total ) . ' chapters processed (' . esc_html( $percent ) . '%)</strong></p><div id="bcu-progress-details">Published: ' . absint( $job['result']['published'] ) . ' | Scheduled: ' . absint( $job['result']['scheduled'] ) . ' | Drafts: ' . absint( $job['result']['drafts'] ) . ' | Replaced: ' . absint( $job['result']['replaced'] ) . ' | Failed: ' . absint( $job['result']['failed'] ) . '</div><p id="bcu-progress-status">Starting…</p></div>';
        ?>
        <script>
        (function(){
          const ajaxUrl=<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
          const job=<?php echo wp_json_encode( $job_token ); ?>;
          const nonce=<?php echo wp_json_encode( wp_create_nonce( 'bcu_batch_' . $job_token ) ); ?>;
          const bar=document.getElementById('bcu-progress-bar'), txt=document.getElementById('bcu-progress-text'), details=document.getElementById('bcu-progress-details'), status=document.getElementById('bcu-progress-status');
          async function next(){
            status.textContent='Processing next group…';
            const body=new URLSearchParams({action:'bcu_process_batch',job:job,_ajax_nonce:nonce});
            try{
              const response=await fetch(ajaxUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
              const payload=await response.json();
              if(!payload.success) throw new Error(payload.data&&payload.data.message?payload.data.message:'The server could not process this group.');
              const d=payload.data; bar.style.width=d.percent+'%'; txt.innerHTML='<strong>'+d.done+' of '+d.total+' chapters processed ('+d.percent+'%)</strong>';
              details.textContent='Published: '+d.result.published+' | Scheduled: '+d.result.scheduled+' | Drafts: '+d.result.drafts+' | Replaced: '+d.result.replaced+' | Skipped: '+d.result.skipped+' | Failed: '+d.result.failed;
              if(d.complete){status.textContent='Import complete. Opening the results…';window.location.href=d.redirect;return;}
              status.textContent='Completed this group. Continuing automatically…';setTimeout(next,350);
            }catch(e){status.textContent='Import paused: '+e.message+' Reload this page to resume from the last completed group.';}
          }
          next();
        })();
        </script>
        <?php
    }

    public function process_batch() {

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error(
            array( 'message' => 'Permission denied.' ),
            403
        );
    }

    $job_token = isset( $_POST['job'] )
        ? sanitize_text_field( wp_unslash( $_POST['job'] ) )
        : '';

    check_ajax_referer( 'bcu_batch_' . $job_token );

    $key = 'bcu_job_' . $job_token;
    $job = get_transient( $key );

    if (
        ! $job ||
        (int) $job['user_id'] !== get_current_user_id()
    ) {
        wp_send_json_error(
            array(
                'message' => 'The import session expired. Reload the uploader and start again.'
            ),
            400
        );
    }

    if ( ! empty( $job['complete'] ) ) {
        wp_send_json_error(
            array(
                'message' => 'This import has already finished.'
            ),
            400
        );
    }

    $total = count( $job['work'] );
    $start = (int) $job['cursor'];
    $end   = min( $total, $start + 5 );

    for ( $position = $start; $position < $end; $position++ ) {

        $item    = $job['work'][ $position ];
        $chapter = $job['chapters'][ $item['index'] ];

        $free_end = (int) ( $job['public_count'] ?? 0 );

        $treasure_end = $free_end +
            (int) ( $job['treasure_count'] ?? 0 );

        $vault_end = $treasure_end +
            (int) ( $job['vault_count'] ?? 0 );

        /*
         * Determine chapter access and visible group.
         */

        if ( ! empty( $job['all_drafts'] ) ) {

            $status        = 'draft';
            $access        = 'draft';
            $chapter_group = '';

        } elseif (
            'patreon' === $job['release_mode'] &&
            $position < $free_end
        ) {

            $status        = 'publish';
            $access        = 'public';
            $chapter_group = 'Free';

        } elseif (
            'patreon' === $job['release_mode'] &&
            $position < $treasure_end
        ) {

            $status        = 'publish';
            $access        = 'treasure';
            $chapter_group = 'Treasure';

        } elseif (
            'patreon' === $job['release_mode'] &&
            $position < $vault_end
        ) {

            $status        = 'publish';
            $access        = 'vault';
            $chapter_group = 'Vault';

        } else {

            $normal_position = $position - $vault_end;

            if (
                $normal_position <
                (int) $job['immediate_count']
            ) {

                $status = 'publish';
                $access = 'public';

            } else {

                $status = 'future';
                $access = 'scheduled';
            }

            $chapter_group = (
                'patreon' === $job['release_mode']
            ) ? 'Free' : '';
        }

        /*
         * Build the chapter post.
         *
         * Always explicitly clear the old password first.
         * Patreon chapters receive a new password below.
         */

        $postarr = array(
            'post_type'    => 'fcn_chapter',
            'post_status'  => $status,
            'post_title'   => $item['title'],
            'post_content' => wp_kses_post( $chapter['content'] ),
            'post_author'  => (int) $job['user_id'],
            'post_parent'  => (int) $job['story_id'],
            'post_password' => '',
        );

        /*
         * Generate passwords for Patreon chapters.
         */

        if (
            in_array(
                $access,
                array( 'vault', 'treasure' ),
                true
            )
        ) {

            $postarr['post_password'] =
                wp_generate_password( 24, false, false );
        }

        /*
         * Assign scheduled dates.
         */

        if ( 'future' === $status ) {

            $normal_position = $position - $vault_end;

            $slot_index = $normal_position -
                (int) $job['immediate_count'];

            if ( ! isset( $job['schedule_slots'][ $slot_index ] ) ) {

                $job['result']['failed']++;
                $job['cursor'] = $position + 1;

                set_transient(
                    $key,
                    $job,
                    2 * HOUR_IN_SECONDS
                );

                continue;
            }

            $slot = $job['schedule_slots'][ $slot_index ];

            $postarr['post_date'] = $slot;

            $postarr['post_date_gmt'] =
                get_gmt_from_date( $slot );

        } elseif ( 'publish' === $status ) {

            $postarr['post_date'] = current_time( 'mysql' );

            $postarr['post_date_gmt'] =
                current_time( 'mysql', true );
        }

        /*
         * Identify this import position.
         */

        $import_ref = $job['job_token'] . '-' . $position;

        /*
         * Update an existing chapter or recover an interrupted import.
         */

        if ( ! empty( $item['existing_id'] ) ) {

            $postarr['ID'] = (int) $item['existing_id'];

            $post_id = wp_update_post(
                $postarr,
                true
            );

        } else {

            $prior = get_posts(
                array(
                    'post_type'   => 'fcn_chapter',
                    'post_status' => 'any',
                    'numberposts' => 1,
                    'fields'      => 'ids',
                    'meta_key'    => '_bcu_import_ref',
                    'meta_value'  => $import_ref,
                )
            );

            if ( ! empty( $prior ) ) {

                $postarr['ID'] = (int) $prior[0];

                $post_id = wp_update_post(
                    $postarr,
                    true
                );

            } else {

                $postarr['meta_input'] = array(
                    '_bcu_import_ref' => $import_ref,
                );

                $post_id = wp_insert_post(
                    $postarr,
                    true
                );
            }
        }

        /*
         * Handle failed imports.
         */

        if ( is_wp_error( $post_id ) || ! $post_id ) {

            $job['result']['failed']++;
            $job['cursor'] = $position + 1;

            set_transient(
                $key,
                $job,
                2 * HOUR_IN_SECONDS
            );

            continue;
        }

        /*
         * Connect chapter to its Fictioneer novel.
         */

        update_post_meta(
            $post_id,
            'fictioneer_chapter_story',
            (string) $job['story_id']
        );

        if ( function_exists( 'fictioneer_set_chapter_story_parent' ) ) {

            fictioneer_set_chapter_story_parent(
                $post_id,
                $job['story_id']
            );
        }

        /*
         * Assign the visible chapter-list group.
         */

        if ( '' !== $chapter_group ) {

            update_post_meta(
                $post_id,
                'fictioneer_chapter_group',
                $chapter_group
            );

        } else {

            delete_post_meta(
                $post_id,
                'fictioneer_chapter_group'
            );
        }

        /*
         * Clear old Patreon restrictions before applying
         * the chapter's new access configuration.
         *
         * This prevents replaced chapters from retaining
         * restrictions belonging to their previous tier.
         */

        delete_post_meta(
            $post_id,
            'fictioneer_patreon_lock_tiers'
        );

        delete_post_meta(
            $post_id,
            'fictioneer_patreon_lock_amount'
        );

        delete_post_meta(
            $post_id,
            'fictioneer_chapter_password_note'
        );

        delete_post_meta(
            $post_id,
            'fictioneer_post_password_expiration_date'
        );

        delete_post_meta(
            $post_id,
            '_bcu_password_set'
        );

        /*
         * Configure Patreon access.
         */

        if (
            'vault' === $access ||
            'treasure' === $access
        ) {

            $tier_ids = ( 'vault' === $access )
                ? array( '30102477' )
                : array( '30102461', '30102477' );

            update_post_meta(
                $post_id,
                'fictioneer_patreon_override',
                '1'
            );

            update_post_meta(
                $post_id,
                'fictioneer_patreon_lock_tiers',
                $tier_ids
            );

            update_post_meta(
                $post_id,
                'fictioneer_patreon_lock_amount',
                0
            );

            update_post_meta(
                $post_id,
                'fictioneer_chapter_password_note',
                ( 'vault' === $access )
                    ? 'This is an advance chapter available exclusively to Beryll Vault members. Check back when it moves to Treasure or becomes publicly available.'
                    : 'This is an advance chapter available to Beryll Treasure and Beryll Vault members. Check back when it becomes publicly available.'
            );

            update_post_meta(
                $post_id,
                '_bcu_access_level',
                $access
            );

            update_post_meta(
                $post_id,
                '_bcu_password_set',
                '1'
            );

            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_pending'
            );

            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_notified'
            );

        } elseif ( 'public' === $access ) {

            update_post_meta(
                $post_id,
                'fictioneer_patreon_override',
                '1'
            );

            /*
             * Public chapters explicitly receive public access.
             */
            update_post_meta(
                $post_id,
                '_bcu_access_level',
                'public'
            );

            /*
             * Do not announce during import.
             * Importing an already-public chapter is not a
             * Treasure → Free promotion.
             */
            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_pending'
            );

            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_notified'
            );

        } elseif ( 'scheduled' === $access ) {

            /*
             * Future chapters are ordinary public chapters
             * waiting for their WordPress publication date.
             */
            delete_post_meta(
                $post_id,
                'fictioneer_patreon_override'
            );

            delete_post_meta(
                $post_id,
                '_bcu_access_level'
            );

            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_pending'
            );

            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_notified'
            );

        } else {

            /*
             * Drafts should not retain Patreon restrictions.
             */
            delete_post_meta(
                $post_id,
                'fictioneer_patreon_override'
            );

            delete_post_meta(
                $post_id,
                '_bcu_access_level'
            );

            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_pending'
            );

            delete_post_meta(
                $post_id,
                '_bcu_discord_public_release_notified'
            );
        }

        /*
         * Update import results.
         */

        if ( ! empty( $item['existing_id'] ) ) {
            $job['result']['replaced']++;
        }

        if ( 'publish' === $status ) {

            $job['result']['published']++;

            $job['visible_ids'][] = (string) $post_id;

        } elseif ( 'future' === $status ) {

            $job['result']['scheduled']++;

            $job['visible_ids'][] = (string) $post_id;

        } else {

            $job['result']['drafts']++;
        }

        $job['cursor'] = $position + 1;

        set_transient(
            $key,
            $job,
            2 * HOUR_IN_SECONDS
        );
    }

    /*
     * Complete this batch.
     */

    $job['cursor'] = $end;

    $complete = ( $end >= $total );

    if ( $complete ) {

        if ( ! empty( $job['visible_ids'] ) ) {

            $story_id = (int) $job['story_id'];

            $story_chapters = get_post_meta(
                $story_id,
                'fictioneer_story_chapters',
                true
            );

            $story_chapters = is_array( $story_chapters )
                ? array_map( 'strval', $story_chapters )
                : array();

            update_post_meta(
                $story_id,
                'fictioneer_story_chapters',
                array_values(
                    array_unique(
                        array_merge(
                            $story_chapters,
                            $job['visible_ids']
                        )
                    )
                )
            );

            update_post_meta(
                $story_id,
                'fictioneer_chapters_modified',
                current_time( 'mysql', true )
            );

            delete_post_meta(
                $story_id,
                'fictioneer_story_data_collection'
            );

            delete_post_meta(
                $story_id,
                'fictioneer_story_chapter_index_html'
            );

            wp_update_post(
                array(
                    'ID' => $story_id,
                )
            );
        }

        $job['complete'] = true;

        delete_transient(
            self::TRANSIENT_PREFIX . $job['preview_token']
        );
    }

    set_transient(
        $key,
        $job,
        2 * HOUR_IN_SECONDS
    );

    $response = array(
        'done'     => $job['cursor'],
        'total'    => $total,
        'percent'  => $total
            ? (int) floor(
                100 * $job['cursor'] / $total
            )
            : 100,
        'result'   => $job['result'],
        'complete' => $complete,
    );

    if ( $complete ) {

        $encoded = base64_encode(
            wp_json_encode( $job['result'] )
        );

        $response['redirect'] = add_query_arg(
            'bcu_result',
            rawurlencode( $encoded ),
            admin_url(
                'admin.php?page=' . self::PAGE
            )
        );

        delete_transient( $key );
    }

    wp_send_json_success( $response );
}

    public function save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Permission denied.' ); }
        check_admin_referer( 'bcu_save_settings' );
        $limit = isset( $_POST['bcu_max_chapters'] ) ? absint( $_POST['bcu_max_chapters'] ) : 100;
        $limit = max( 1, min( 200, $limit ) ); update_option( 'bcu_max_chapters', $limit, false );
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'bcu_saved' => '1' ), admin_url( 'admin.php' ) ) ); exit;
    }

    public function save_github_settings() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Permission denied.' );
    }

    check_admin_referer( 'bcu_save_github_settings' );

    /*
     * Remove token if explicitly requested.
     */
    if (
        isset( $_POST['bcu_remove_github_token'] ) &&
        '1' === $_POST['bcu_remove_github_token']
    ) {
        delete_option( 'bcu_github_token' );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'             => self::PAGE,
                    'bcu_github_removed' => '1'
                ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    $username = isset( $_POST['bcu_github_username'] )
        ? sanitize_text_field( wp_unslash( $_POST['bcu_github_username'] ) )
        : '';

    $repository = isset( $_POST['bcu_github_repo'] )
        ? sanitize_text_field( wp_unslash( $_POST['bcu_github_repo'] ) )
        : '';

    $workflow = isset( $_POST['bcu_github_workflow'] )
        ? sanitize_file_name( wp_unslash( $_POST['bcu_github_workflow'] ) )
        : '';

    $token = isset( $_POST['bcu_github_token'] )
        ? trim( wp_unslash( $_POST['bcu_github_token'] ) )
        : '';

    if (
        ! preg_match( '/^[a-zA-Z0-9-]+$/', $username ) ||
        ! preg_match( '/^[a-zA-Z0-9._-]+$/', $repository ) ||
        ! preg_match( '/^[a-zA-Z0-9._-]+\.yml$/', $workflow )
    ) {
        wp_die( 'Invalid GitHub connection details.' );
    }

    update_option( 'bcu_github_username', $username, false );
    update_option( 'bcu_github_repo', $repository, false );
    update_option( 'bcu_github_workflow', $workflow, false );

    /*
     * A blank token means keep the existing token.
     */
    if ( '' !== $token ) {
        if ( ! preg_match( '/^github_pat_[A-Za-z0-9_]+$/', $token ) ) {
            wp_die( 'The token does not appear to be a valid fine-grained GitHub token.' );
        }

        update_option( 'bcu_github_token', $token, false );
    }

    wp_safe_redirect(
        add_query_arg(
            array(
                'page'               => self::PAGE,
                'bcu_github_saved'   => '1'
            ),
            admin_url( 'admin.php' )
        )
    );
    exit;
    }

    public function test_github_relay() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to perform this action.' );
    }

    check_admin_referer( 'bcu_test_github_relay' );

    $token    = get_option( 'bcu_github_token', '' );
    $username = get_option( 'bcu_github_username', 'beryll175' );
    $repo     = get_option( 'bcu_github_repo', 'beryll-chapter-uploader' );
    $workflow = get_option( 'bcu_github_workflow', 'discord-notify.yml' );

    if ( empty( $token ) ) {
        wp_safe_redirect( add_query_arg( 'bcu_github_test', 'no_token', admin_url( 'admin.php?page=beryll-chapter-uploader' ) ) );
        exit;
    }

    $url = sprintf(
        'https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches',
        rawurlencode( $username ),
        rawurlencode( $repo ),
        rawurlencode( $workflow )
    );

    $body = array(
        'ref' => 'main',
        'inputs' => array(
            'type'        => 'chapter',
            'title'       => 'WordPress Discord Relay Test',
            'url'         => 'https://berylltl.com',
            'story'       => 'Beryll TL',
            'author'      => 'Beryll TL',
            'description' => 'This is a test notification sent from the Beryll Chapter Uploader.',
            'image_url'   => '',
        ),
    );

    $response = wp_remote_post( $url, array(
        'timeout' => 20,
        'headers' => array(
            'Accept'               => 'application/vnd.github+json',
            'Authorization'        => 'Bearer ' . $token,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'Beryll-TL-WordPress-Relay',
            'Content-Type'         => 'application/json',
        ),
        'body' => wp_json_encode( $body ),
    ) );

    if ( is_wp_error( $response ) ) {
        wp_safe_redirect( add_query_arg( 'bcu_github_test', 'connection_error', admin_url( 'admin.php?page=beryll-chapter-uploader' ) ) );
        exit;
    }

    $status = wp_remote_retrieve_response_code( $response );

    if ( 204 === $status ) {
        $result = 'success';
    } else {
        $result = 'api_error';
    }

    wp_safe_redirect( add_query_arg( 'bcu_github_test', $result, admin_url( 'admin.php?page=beryll-chapter-uploader' ) ) );
    exit;
}

    private function max_chapters() {
        return max( 1, min( 200, absint( get_option( 'bcu_max_chapters', 100 ) ) ) );
    }

    private function chapter_number( $title, $fallback = 0 ) {
        $title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
        if ( preg_match( '/(?:chapter|chap\.?|ch\.?|第)\s*0*(\d+)/iu', $title, $m ) ) { return (int) $m[1]; }
        if ( preg_match( '/^\s*0*(\d+)(?:\D|$)/u', $title, $m ) ) { return (int) $m[1]; }
        return (int) $fallback;
    }

    private function duplicate_key( $group, $number ) {
        // Fictioneer group names are case-sensitive. Empty group means ungrouped.
        return (string) $group . "\x1F" . (int) $number;
    }

    private function existing_chapter_map( $story_id ) {
      $story_id = absint( $story_id );

      if ( ! $story_id ) {
        return array();
      }

      // Find chapters connected through the WordPress parent relationship.
       $posts = get_posts( array(
        'post_type'   => 'fcn_chapter',
        'post_status' => 'any',
        'numberposts' => -1,
        'post_parent' => $story_id,
        'orderby'     => 'date',
        'order'       => 'DESC'
     ) );

      // Also find chapters connected through Fictioneer's story metadata.
       $meta_posts = get_posts( array(
        'post_type'   => 'fcn_chapter',
        'post_status' => 'any',
        'numberposts' => -1,
        'meta_key'    => 'fictioneer_chapter_story',
        'meta_value'  => (string) $story_id,
        'orderby'     => 'date',
        'order'       => 'DESC'
      ) );

      // Merge both results without including the same chapter twice.
     $all = array();

     foreach ( array_merge( $posts, $meta_posts ) as $post ) {
        $all[ $post->ID ] = $post;
      }

      $map = array();

      foreach ( $all as $post ) {
        $number = $this->chapter_number( $post->post_title, 0 );

        if ( ! $number ) {
            $number = $this->chapter_number( $post->post_name, 0 );
        }

        if ( $number > 0 ) {
            // Volume and access groups are no longer part of duplicate matching.
            $key = $this->duplicate_key( '', $number );

            if ( ! isset( $map[ $key ] ) ) {
                $map[ $key ] = array();
            }

            $map[ $key ][] = $post;
        }
      }

      return $map;
    }

    private function redirect_error( $message ) {
        wp_safe_redirect( add_query_arg( 'bcu_error', rawurlencode( $message ), admin_url( 'admin.php?page=' . self::PAGE ) ) ); exit;
    }

    private function parse_docx_zip( $file ) {
        $zip = new ZipArchive();
        if ( true !== $zip->open( $file ) ) { return new WP_Error( 'bcu_zip', 'The ZIP file could not be opened.' ); }
        $docs = array();
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $stat = $zip->statIndex( $i );
            $entry = $stat['name'];
            $normal_entry = str_replace( '\\', '/', $entry );
            if ( substr( $normal_entry, -1 ) === '/' || strpos( $normal_entry, '__MACOSX/' ) === 0 || strpos( $normal_entry, '/__MACOSX/' ) !== false || basename( $normal_entry ) === '' || strpos( basename( $normal_entry ), '~$' ) === 0 || strpos( basename( $normal_entry ), '.' ) === 0 ) { continue; }
            if ( strtolower( pathinfo( $normal_entry, PATHINFO_EXTENSION ) ) === 'docx' ) { $docs[] = array( 'name' => $normal_entry, 'index' => $i ); }
        }
        usort( $docs, function( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } );
        if ( count( $docs ) > $this->max_chapters() ) { $zip->close(); return new WP_Error( 'bcu_limit', 'This ZIP contains ' . count( $docs ) . ' DOCX files, exceeding your current limit of ' . $this->max_chapters() . '. Increase the limit (up to 200) or split the upload.' ); }
        $chapters = array();
        foreach ( $docs as $doc ) {
            $raw = $zip->getFromIndex( $doc['index'] );
            if ( false === $raw ) { continue; }
            $parsed = $this->docx_xml_to_html( $raw );
            if ( is_wp_error( $parsed ) ) { continue; }
            $title = trim( pathinfo( $doc['name'], PATHINFO_FILENAME ) );
            $group = $this->detect_volume_group( dirname( $doc['name'] ) );
            $chapters[] = array( 'title' => $title, 'content' => $parsed, 'group' => $group );
        }
        $zip->close();
        if ( empty( $chapters ) ) { return new WP_Error( 'bcu_no_docx', 'No DOCX files were found in the ZIP. Make sure it contains one .docx file per chapter; files inside folders and subfolders are supported.' ); }
        return $chapters;
    }

    private function docx_xml_to_html( $docx_file_contents ) {
        $zip = new ZipArchive();
        $tmp = wp_tempnam( 'bcu-docx' );
        if ( ! $tmp ) { return new WP_Error( 'bcu_tmp', 'Could not create a temporary file.' ); }
        file_put_contents( $tmp, $docx_file_contents );
        if ( true !== $zip->open( $tmp ) ) { @unlink( $tmp ); return new WP_Error( 'bcu_docx', 'A DOCX file could not be read.' ); }
        $xml = $zip->getFromName( 'word/document.xml' );
        $rels_xml = $zip->getFromName( 'word/_rels/document.xml.rels' );
        $zip->close(); @unlink( $tmp );
        if ( ! $xml || ! class_exists( 'DOMDocument' ) ) { return new WP_Error( 'bcu_xml', 'The PHP DOM/XML extension is required to read DOCX files.' ); }
        $dom = new DOMDocument(); $previous = libxml_use_internal_errors( true );
        if ( ! $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOBLANKS ) ) { libxml_clear_errors(); libxml_use_internal_errors( $previous ); return new WP_Error( 'bcu_xml', 'A DOCX document contains invalid XML.' ); }
        libxml_clear_errors(); libxml_use_internal_errors( $previous );
        $xp = new DOMXPath( $dom ); $xp->registerNamespace( 'w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main' ); $xp->registerNamespace( 'r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
        $rels = array();
        if ( $rels_xml ) { $rd = new DOMDocument(); if ( $rd->loadXML( $rels_xml, LIBXML_NONET ) ) { foreach ( $rd->getElementsByTagName( 'Relationship' ) as $rel ) { $rels[$rel->getAttribute( 'Id' )] = $rel->getAttribute( 'Target' ); } } }
        $html = '';
        foreach ( $xp->query( '//w:body/w:p' ) as $p ) {
            $style = ''; $style_node = $xp->query( './w:pPr/w:pStyle', $p )->item( 0 ); if ( $style_node ) { $style = strtolower( $style_node->getAttributeNS( 'http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val' ) ); }
            $tag = ( strpos( $style, 'title' ) !== false || strpos( $style, 'heading1' ) !== false ) ? 'h2' : ( strpos( $style, 'heading2' ) !== false ? 'h3' : 'p' );
            $text = '';
            foreach ( $p->childNodes as $child ) {
                if ( $child->localName === 'hyperlink' ) {
                    $rid = $child->getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' );
                    $inside = $this->docx_runs_html( $xp, $child ); $url = isset( $rels[$rid] ) ? esc_url( $rels[$rid] ) : '';
                    $text .= $url ? '<a href="' . $url . '">' . $inside . '</a>' : $inside;
                } elseif ( $child->localName === 'r' ) { $text .= $this->docx_run_html( $xp, $child ); }
            }
            if ( trim( wp_strip_all_tags( $text ) ) !== '' ) { $html .= '<' . $tag . '>' . $text . '</' . $tag . ">\n"; }
        }
        return $html;
    }

    private function docx_runs_html( $xp, $node ) { $out = ''; foreach ( $xp->query( './/w:r', $node ) as $run ) { $out .= $this->docx_run_html( $xp, $run ); } return $out; }
    private function docx_run_html( $xp, $run ) {
        $text = ''; foreach ( $xp->query( './/w:t | .//w:tab | .//w:br', $run ) as $node ) { if ( $node->localName === 't' ) { $text .= esc_html( $node->textContent ); } elseif ( $node->localName === 'tab' ) { $text .= '&emsp;'; } else { $text .= '<br>'; } }
        if ( $xp->query( './w:rPr/w:b', $run )->length ) { $text = '<strong>' . $text . '</strong>'; }
        if ( $xp->query( './w:rPr/w:i', $run )->length ) { $text = '<em>' . $text . '</em>'; }
        if ( $xp->query( './w:rPr/w:u', $run )->length ) { $text = '<u>' . $text . '</u>'; }
        if ( $xp->query( './w:rPr/w:strike', $run )->length ) { $text = '<del>' . $text . '</del>'; }
        return $text;
    }

    private function parse_epub( $file ) {
        $zip = new ZipArchive(); if ( true !== $zip->open( $file ) ) { return new WP_Error( 'bcu_epub', 'The EPUB file could not be opened.' ); }
        $container = $zip->getFromName( 'META-INF/container.xml' );
        if ( ! $container || ! class_exists( 'DOMDocument' ) ) { $zip->close(); return new WP_Error( 'bcu_epub_xml', 'This EPUB could not be read. PHP DOM/XML support is required.' ); }
        $cd = new DOMDocument(); if ( ! @$cd->loadXML( $container, LIBXML_NONET ) ) { $zip->close(); return new WP_Error( 'bcu_container', 'The EPUB container file is invalid.' ); }
        $rootfile = $cd->getElementsByTagName( 'rootfile' )->item( 0 ); if ( ! $rootfile ) { $zip->close(); return new WP_Error( 'bcu_rootfile', 'The EPUB package file could not be located.' ); }
        $opf_path = $rootfile->getAttribute( 'full-path' ); $opf = $zip->getFromName( $opf_path ); if ( ! $opf ) { $zip->close(); return new WP_Error( 'bcu_opf', 'The EPUB package document is missing.' ); }
        $od = new DOMDocument(); if ( ! @$od->loadXML( $opf, LIBXML_NONET ) ) { $zip->close(); return new WP_Error( 'bcu_opf_xml', 'The EPUB package document is invalid.' ); }
        $ox = new DOMXPath( $od ); $ox->registerNamespace( 'opf', 'http://www.idpf.org/2007/opf' );
        $manifest = array(); foreach ( $ox->query( '//*[local-name()="manifest"]/*[local-name()="item"]' ) as $item ) { $manifest[$item->getAttribute( 'id' )] = $item->getAttribute( 'href' ); }
        $base = dirname( $opf_path ); $base = ( $base === '.' ) ? '' : $base . '/';
        $nav_titles = $this->epub_nav_titles( $zip, $manifest, $base );
        $nav_groups = $this->epub_nav_groups( $zip, $manifest, $base );
        $chapters = array();
        foreach ( $ox->query( '//*[local-name()="spine"]/*[local-name()="itemref"]' ) as $ref ) {
            $id = $ref->getAttribute( 'idref' ); if ( empty( $manifest[$id] ) ) { continue; }
            $href = rawurldecode( $manifest[$id] ); if ( preg_match( '/(^|\/)(nav|toc|cover|titlepage|copyright|colophon)([-_.\/]|$)/i', $href ) ) { continue; }
            $path = $this->normalise_zip_path( $base . strtok( $href, '#' ) ); $xhtml = $zip->getFromName( $path );
            if ( ! $xhtml ) { continue; }
            $xd = new DOMDocument(); if ( ! @$xd->loadXML( $xhtml, LIBXML_NONET ) ) { continue; }
            $xx = new DOMXPath( $xd ); $xx->registerNamespace( 'h', 'http://www.w3.org/1999/xhtml' );
            $title_node = $xx->query( '//h:h1 | //h:h2 | //h:title | //h:head/title' )->item( 0 );
            $title = $title_node ? trim( $title_node->textContent ) : pathinfo( $href, PATHINFO_FILENAME );
            $href_basename = basename( strtok( $href, '#' ) );
            if ( isset( $nav_titles[$href_basename] ) ) { $title = $nav_titles[$href_basename]; }
            $body = $xx->query( '//h:body' )->item( 0 ); if ( ! $body ) { continue; }
            $html = ''; foreach ( $body->childNodes as $child ) { $html .= $xd->saveHTML( $child ); }
            $html = preg_replace( '/<script\b[^>]*>.*?<\/script>/is', '', $html );
            if ( trim( wp_strip_all_tags( $html ) ) === '' ) { continue; }
            $group = isset( $nav_groups[$href_basename] ) ? $nav_groups[$href_basename] : $this->detect_volume_group( dirname( $path ) );
            $chapters[] = array( 'title' => $title, 'content' => $html, 'group' => $group );
            if ( count( $chapters ) > $this->max_chapters() ) { $zip->close(); return new WP_Error( 'bcu_limit', 'This EPUB contains more than your current limit of ' . $this->max_chapters() . ' readable chapters. Increase the limit (up to 200) or split the upload.' ); }
        }
        $zip->close();
        if ( count( $chapters ) < 1 ) { return new WP_Error( 'bcu_epub_empty', 'No readable chapter documents were found in the EPUB spine.' ); }
        return $chapters;
    }

    private function epub_nav_titles( $zip, $manifest, $base ) {
        foreach ( $manifest as $href ) {
            if ( stripos( $href, 'nav' ) === false && stripos( $href, 'toc' ) === false ) { continue; }
            $path = $this->normalise_zip_path( $base . strtok( rawurldecode( $href ), '#' ) ); $raw = $zip->getFromName( $path ); if ( ! $raw ) { continue; }
            $d = new DOMDocument(); if ( ! @$d->loadXML( $raw, LIBXML_NONET ) ) { continue; }
            $x = new DOMXPath( $d ); $x->registerNamespace( 'h', 'http://www.w3.org/1999/xhtml' ); $map = array();
            foreach ( $x->query( '//h:nav//h:a[@href]' ) as $a ) { $target = basename( strtok( $a->getAttribute( 'href' ), '#' ) ); if ( $target ) { $map[$target] = trim( $a->textContent ); } }
            return $map;
        }
        return array();
    }

    private function detect_volume_group( $path ) {
        $parts = explode( '/', str_replace( '\\', '/', (string) $path ) );
        foreach ( $parts as $part ) {
            $part = trim( preg_replace( '/\s+/', ' ', $part ) );
            if ( preg_match( '/^(?:volume|vol\.?|book)\s*[-_ ]*\d+(?:\s*[-_:].*)?$/iu', $part ) ) {
                $part = preg_replace( '/[_]+/', ' ', $part );
                $part = preg_replace( '/^(?:vol\.?)/iu', 'Volume', $part );
                return trim( $part );
            }
        }
        return '';
    }

    private function epub_nav_groups( $zip, $manifest, $base ) {
        foreach ( $manifest as $href ) {
            if ( stripos( $href, 'nav' ) === false && stripos( $href, 'toc' ) === false ) { continue; }
            $path = $this->normalise_zip_path( $base . strtok( rawurldecode( $href ), '#' ) );
            $raw = $zip->getFromName( $path );
            if ( ! $raw ) { continue; }
            $d = new DOMDocument(); if ( ! @$d->loadXML( $raw, LIBXML_NONET ) ) { continue; }
            $x = new DOMXPath( $d ); $x->registerNamespace( 'h', 'http://www.w3.org/1999/xhtml' ); $map = array();
            foreach ( $x->query( '//h:nav//h:li' ) as $li ) {
                $direct = $x->query( './h:a[@href]', $li )->item( 0 );
                if ( ! $direct ) { continue; }
                $label = trim( $direct->textContent );
                if ( ! preg_match( '/^(?:volume|vol\.?|book)\s*[-_ ]*\d+/iu', $label ) ) { continue; }
                $group = preg_replace( '/^(?:vol\.?)/iu', 'Volume', $label );
                foreach ( $x->query( './/h:li//h:a[@href]', $li ) as $child_link ) {
                    $target = basename( strtok( $child_link->getAttribute( 'href' ), '#' ) );
                    if ( $target ) { $map[$target] = trim( $group ); }
                }
            }
            return $map;
        }
        return array();
    }

    private function normalise_zip_path( $path ) {
        $parts = array(); foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $part ) { if ( $part === '' || $part === '.' ) { continue; } if ( $part === '..' ) { array_pop( $parts ); } else { $parts[] = $part; } } return implode( '/', $parts );
    }
}
new Beryll_Chapter_Uploader();

/**
 * Patreon advance-release queue.
 *
 * Uses the WordPress site timezone and promotes both access queues
 * in the same release cycle.
 *
 * Public-release notifications are sent through GitHub Actions
 * because the hosting provider blocks direct Discord webhook requests.
 */
class Beryll_Patreon_Release_Scheduler {

    const HOOK = 'bcu_patreon_release_tick';

    const VAULT_TIER = '30102477';
    const TREASURE_TIER = '30102461';

    const OPTION_PREFIX = 'bcu_patreon_story_';

    const LOCK_TTL = 20 * MINUTE_IN_SECONDS;

    public function __construct() {

        add_action( 'init', array( $this, 'ensure_cron' ) );

        add_action( self::HOOK, array( $this, 'tick' ) );

        add_action(
            'admin_post_bcu_save_patreon_schedule',
            array( $this, 'save' )
        );
    }


    /**
     * Ensure the hourly scheduler event exists.
     */
    public function ensure_cron() {

        if ( ! wp_next_scheduled( self::HOOK ) ) {

            wp_schedule_event(
                time() + 300,
                'hourly',
                self::HOOK
            );
        }
    }


    /**
     * Retrieve the shared scheduler instance.
     */
    private static function instance() {

        if (
            isset( $GLOBALS['bcu_patreon_release_scheduler'] ) &&
            $GLOBALS['bcu_patreon_release_scheduler'] instanceof self
        ) {
            return $GLOBALS['bcu_patreon_release_scheduler'];
        }

        return null;
    }


    /**
     * Render the Patreon scheduler administration page.
     */
    public static function render_page() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $self = self::instance();

        if ( ! $self ) {
            echo '<div class="notice notice-error"><p>The Patreon scheduler has not been initialized.</p></div>';
            return;
        }

        $stories = get_posts( array(
            'post_type'   => 'fcn_story',
            'post_status' => array(
                'publish',
                'draft',
                'private',
                'pending',
            ),
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
        ) );

        $story_id = isset( $_GET['story_id'] )
            ? absint( $_GET['story_id'] )
            : ( ! empty( $stories ) ? (int) $stories[0]->ID : 0 );

        if (
            $story_id &&
            get_post_type( $story_id ) !== 'fcn_story'
        ) {
            $story_id = 0;
        }

        $s = $story_id
            ? $self->settings( $story_id )
            : array();

        $days = isset( $s['days'] )
            ? array_map( 'absint', (array) $s['days'] )
            : array( 1, 3 );

        $per = isset( $s['per_release'] )
            ? absint( $s['per_release'] )
            : 1;

        $time = isset( $s['time'] )
            ? $s['time']
            : '19:00';

        $enabled = ! empty( $s['enabled'] );

        $next = $self->next_release( $s );

        echo '<div class="wrap">';
        echo '<h1>Patreon Release Scheduler</h1>';

        echo '<p>Schedules are independent for each novel. At each selected release time, the oldest Vault-exclusive chapters move to Treasure + Vault, while the oldest Treasure + Vault chapters move to Public at the same time.</p>';

        echo '<div class="notice notice-warning"><p><strong>WP-Cron note:</strong> WordPress runs scheduled tasks when the site receives visits. Releases can happen later than the selected time on a low-traffic site.</p></div>';

        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>Patreon release schedule saved.</p></div>';
        }

        if ( empty( $stories ) ) {

            echo '<p>Create a Fictioneer novel first.</p>';
            echo '</div>';

            return;
        }

        echo '<form method="get" style="margin:16px 0">';

        echo '<input type="hidden" name="page" value="bcu-patreon-scheduler">';

        echo '<label><strong>Novel: </strong><select name="story_id" onchange="this.form.submit()">';

        foreach ( $stories as $story ) {

            echo '<option value="' . absint( $story->ID ) . '" ' .
                selected( $story_id, $story->ID, false ) .
                '>' . esc_html( $story->post_title ) . '</option>';
        }

        echo '</select></label> ';

        echo '<button class="button">Open novel schedule</button>';

        echo '</form>';

        if ( ! $story_id ) {

            echo '</div>';

            return;
        }

        /*
         * Schedule settings form.
         */
        echo '<form method="post" action="' .
            esc_url( admin_url( 'admin-post.php' ) ) .
            '" style="max-width:700px;background:#fff;padding:18px;border:1px solid #ccd0d4">';

        echo '<input type="hidden" name="action" value="bcu_save_patreon_schedule">';

        echo '<input type="hidden" name="story_id" value="' .
            absint( $story_id ) . '">';

        wp_nonce_field( 'bcu_save_patreon_schedule_' . $story_id );

        echo '<p><label><input type="checkbox" name="enabled" value="1" ' .
            checked( $enabled, true, false ) .
            '> <strong>Enable automatic Patreon releases for this novel</strong></label></p>';

        echo '<p><strong>Release days</strong><br>';

        $labels = array(
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        );

        foreach ( $labels as $n => $label ) {

            echo '<label style="display:inline-block;margin:0 12px 8px 0">';

            echo '<input type="checkbox" name="days[]" value="' .
                $n . '" ' .
                checked( in_array( $n, $days, true ), true, false ) .
                '> ' . esc_html( $label );

            echo '</label>';
        }

        echo '</p>';

        echo '<p><label>Chapters per tier, per release<br>';

        echo '<input type="number" name="per_release" min="1" max="50" value="' .
            esc_attr( max( 1, min( 50, $per ) ) ) .
            '" style="width:90px"></label></p>';

        echo '<p><label>First release date<br>';

        echo '<input type="date" name="start_date" value="' .
            esc_attr(
                $s['start_date'] ??
                wp_date(
                    'Y-m-d',
                    current_time( 'timestamp' ) + DAY_IN_SECONDS
                )
            ) .
            '"></label></p>';

        echo '<p><label>Release time (WordPress site timezone)<br>';

        echo '<input type="time" name="time" value="' .
            esc_attr( $time ) .
            '"></label></p>';

        echo '<p><strong>Next release:</strong> ' .
            (
                $next
                    ? esc_html( $next->format( 'D, j M Y H:i' ) )
                    : 'Not scheduled'
            ) .
            '</p>';

        echo '<p><button class="button button-primary">Save this novel’s schedule</button></p>';

        echo '</form>';

        /*
         * Queue counts.
         */
        echo '<h2>Current queues for this novel</h2>';

        echo '<p>Vault exclusive: <strong>' .
            absint( $self->queue_count( $story_id, 'vault' ) ) .
            '</strong> chapters &nbsp; | &nbsp; Treasure + Vault: <strong>' .
            absint( $self->queue_count( $story_id, 'treasure' ) ) .
            '</strong> chapters</p>';

        echo '<p>Chapters are grouped automatically under Free, Treasure, and Vault. Their access level and visible group change together as they are promoted.</p>';

        echo '</div>';
    }


    /**
     * Retrieve a novel's schedule settings.
     */
    private function settings( $story_id ) {

        $s = get_option(
            self::OPTION_PREFIX . absint( $story_id ),
            array()
        );

        return is_array( $s ) ? $s : array();
    }


    /**
     * Validate a calendar date strictly.
     */
    private function valid_date( $date ) {

        if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            wp_timezone()
        );

        $errors = DateTimeImmutable::getLastErrors();

        if ( false === $parsed ) {
            return false;
        }

        if (
            is_array( $errors ) &&
            ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 )
        ) {
            return false;
        }

        return $parsed->format( 'Y-m-d' ) === $date;
    }


    /**
     * Save a novel's schedule.
     */
    public function save() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.' );
        }

        $story_id = isset( $_POST['story_id'] )
            ? absint( $_POST['story_id'] )
            : 0;

        check_admin_referer(
            'bcu_save_patreon_schedule_' . $story_id
        );

        if ( get_post_type( $story_id ) !== 'fcn_story' ) {
            wp_die( 'Invalid novel.' );
        }

        $days = isset( $_POST['days'] ) && is_array( $_POST['days'] )
            ? array_map(
                'absint',
                wp_unslash( $_POST['days'] )
            )
            : array();

        $days = array_values(
            array_unique(
                array_intersect(
                    $days,
                    array( 1, 2, 3, 4, 5, 6, 7 )
                )
            )
        );

        sort( $days );

        $time = isset( $_POST['time'] )
            ? sanitize_text_field( wp_unslash( $_POST['time'] ) )
            : '19:00';

        if ( ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
            $time = '19:00';
        }

        $start_date = isset( $_POST['start_date'] )
            ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) )
            : wp_date( 'Y-m-d', current_time( 'timestamp' ) );

        if ( ! $this->valid_date( $start_date ) ) {
            $start_date = wp_date( 'Y-m-d', current_time( 'timestamp' ) );
        }

        $s = array(
            'enabled'      => ! empty( $_POST['enabled'] ),
            'days'         => $days,
            'per_release'  => max(
                1,
                min( 50, absint( $_POST['per_release'] ?? 1 ) )
            ),
            'time'         => $time,
            'start_date'   => $start_date,
        );

        update_option(
            self::OPTION_PREFIX . $story_id,
            $s,
            false
        );

        wp_safe_redirect(
            add_query_arg(
                array(
                    'page'     => 'bcu-patreon-scheduler',
                    'story_id' => $story_id,
                    'saved'    => '1',
                ),
                admin_url( 'admin.php' )
            )
        );

        exit;
    }


    /**
     * Calculate the next scheduled release.
     */
    private function next_release( $s ) {

        if ( empty( $s['enabled'] ) || empty( $s['days'] ) ) {
            return false;
        }

        $now = current_datetime();

        $time = $s['time'] ?? '19:00';

        if (
            ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time )
        ) {
            $time = '19:00';
        }

        if (
            ! empty( $s['start_date'] ) &&
            $this->valid_date( $s['start_date'] ) &&
            $s['start_date'] > $now->format( 'Y-m-d' )
        ) {

            $start = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $s['start_date'],
                wp_timezone()
            );

            if ( $start ) {
                $now = $start;
            }
        }

        for ( $i = 0; $i < 8; $i++ ) {

            $d = $now->modify( '+' . $i . ' days' );

            if (
                in_array(
                    (int) $d->format( 'N' ),
                    array_map( 'absint', (array) $s['days'] ),
                    true
                )
            ) {

                $parts = explode( ':', $time );

                $candidate = $d->setTime(
                    (int) $parts[0],
                    (int) $parts[1],
                    0
                );

                if ( $candidate > $now ) {
                    return $candidate;
                }
            }
        }

        return false;
    }


    /**
     * Acquire an atomic database lock.
     */
    private function acquire_lock( $key, $ttl ) {

        $token = wp_generate_uuid4() . '|' . time();

        if ( add_option( $key, $token, '', false ) ) {
            return $token;
        }

        $existing = get_option( $key, '' );

        $parts = explode( '|', (string) $existing );

        $locked_at = isset( $parts[1] )
            ? absint( $parts[1] )
            : 0;

        if ( ! $locked_at || $locked_at >= time() - $ttl ) {
            return false;
        }

        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                WHERE option_name = %s
                AND option_value = %s",
                $key,
                maybe_serialize( $existing )
            )
        );

        if ( ! $deleted ) {
            return false;
        }

        if ( add_option( $key, $token, '', false ) ) {
            return $token;
        }

        return false;
    }


    /**
     * Release a lock only if this request owns it.
     */
    private function release_lock( $key, $token ) {

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                WHERE option_name = %s
                AND option_value = %s",
                $key,
                maybe_serialize( $token )
            )
        );

        wp_cache_delete( $key, 'options' );
    }


    /**
     * Run scheduled Patreon promotions.
     */
    public function tick() {

        /*
         * Retry previously failed public notifications.
         */
        $this->process_pending_notifications();

        /*
         * Prevent overlapping scheduler executions.
         */
        $lock_key = 'bcu_patreon_release_lock';

        $lock_token = $this->acquire_lock(
            $lock_key,
            self::LOCK_TTL
        );

        if ( ! $lock_token ) {
            return;
        }

        try {

            $stories = get_posts( array(
                'post_type'   => 'fcn_story',
                'post_status' => array(
                    'publish',
                    'draft',
                    'private',
                ),
                'numberposts' => -1,
                'fields'      => 'ids',
            ) );

            foreach ( $stories as $story_id ) {

                $s = $this->settings( $story_id );

                if (
                    empty( $s['enabled'] ) ||
                    empty( $s['days'] )
                ) {
                    continue;
                }

                $now = current_datetime();

                $date = $now->format( 'Y-m-d' );

                if (
                    $date < ( $s['start_date'] ?? '1970-01-01' )
                ) {
                    continue;
                }

                if (
                    ! in_array(
                        (int) $now->format( 'N' ),
                        array_map( 'absint', (array) $s['days'] ),
                        true
                    )
                ) {
                    continue;
                }

                if (
                    $now->format( 'H:i' ) <
                    ( $s['time'] ?? '19:00' )
                ) {
                    continue;
                }

                $last = get_option(
                    'bcu_patreon_last_release_' . $story_id,
                    ''
                );

                if ( $last === $date ) {
                    continue;
                }

                $limit = max(
                    1,
                    min( 50, absint( $s['per_release'] ?? 1 ) )
                );

                /*
                 * Snapshot both queues before changing either.
                 *
                 * This ensures Vault → Treasure and
                 * Treasure → Public happen simultaneously.
                 */
                $vault = $this->queue_ids(
                    $story_id,
                    'vault'
                );

                $treasure = $this->queue_ids(
                    $story_id,
                    'treasure'
                );

                /*
                 * Vault-exclusive chapters become
                 * Treasure + Vault.
                 */
                foreach ( array_slice( $vault, 0, $limit ) as $id ) {

                    $this->promote_to_treasure( $id );
                }

                /*
                 * Treasure + Vault chapters become public.
                 */
                foreach ( array_slice( $treasure, 0, $limit ) as $id ) {

                    $this->promote_to_public( $id );
                }

                update_option(
                    'bcu_patreon_last_release_' . $story_id,
                    $date,
                    false
                );
            }

        } finally {

            $this->release_lock(
                $lock_key,
                $lock_token
            );
        }
    }


    /**
    * Retrieve chapters in a queue in reading order.
    *
    * Queue identification uses access level only.
    * Chapter grouping is strictly for frontend display.
    */
    private function queue_ids( $story_id, $level ) {

        $posts = get_posts( array(
        'post_type'   => 'fcn_chapter',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',

        'meta_query' => array(
            'relation' => 'AND',

            array(
                'key'   => 'fictioneer_chapter_story',
                'value' => (string) absint( $story_id ),
            ),

            array(
                'key'   => '_bcu_access_level',
                'value' => sanitize_key( $level ),
            ),
        ),
      ) );

     usort(
        $posts,
        function( $a, $b ) {

            $pa = get_post( $a );
            $pb = get_post( $b );

            $na = $this->chapter_number(
                $pa ? $pa->post_title : '',
                0
            );

            $nb = $this->chapter_number(
                $pb ? $pb->post_title : '',
                0
            );

            if ( $na === $nb ) {
                return $a - $b;
            }

            return $na <=> $nb;
        }
     );

     return array_map( 'absint', $posts );
    }


    /**
     * Extract a chapter number from a title.
     */
    private function chapter_number( $title, $fallback = 0 ) {

        $title = html_entity_decode(
            wp_strip_all_tags( (string) $title ),
            ENT_QUOTES,
            'UTF-8'
        );

        if (
            preg_match(
                '/(?:chapter|chap\.?|ch\.?|第)\s*0*(\d+)/iu',
                $title,
                $m
            )
        ) {
            return (int) $m[1];
        }

        if (
            preg_match(
                '/^\s*0*(\d+)(?:\D|$)/u',
                $title,
                $m
            )
        ) {
            return (int) $m[1];
        }

        return (int) $fallback;
    }


    /**
     * Count chapters in a particular queue.
     */
    private function queue_count( $story_id, $level ) {

        $q = new WP_Query( array(
            'post_type'      => 'fcn_chapter',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',

                array(
                    'key'   => 'fictioneer_chapter_story',
                    'value' => (string) $story_id,
                ),

                array(
                    'key'   => '_bcu_access_level',
                    'value' => $level,
                ),
            ),
        ) );

        return (int) $q->found_posts;
    }


    /**
     * Promote a Vault chapter to Treasure + Vault.
     */
    private function promote_to_treasure( $id ) {

        $id = absint( $id );

        if ( ! $id ) {
        return;
        }

       update_post_meta(
        $id,
        'fictioneer_patreon_override',
        '1'
      );

       update_post_meta(
        $id,
        '_bcu_access_level',
        'treasure'
      );

      update_post_meta(
        $id,
        'fictioneer_chapter_group',
        'Treasure'
      );

      update_post_meta(
        $id,
        'fictioneer_patreon_lock_tiers',
        array(
            self::TREASURE_TIER,
            self::VAULT_TIER,
        )
      );

      update_post_meta(
        $id,
        'fictioneer_chapter_password_note',
        'This is an advance chapter available to Beryll Treasure and Beryll Vault members. Join either tier to read it, or check back when it is released publicly.'
     );

     /*
       * Refresh Fictioneer's associated story and chapter caches.
       */
      if ( function_exists( 'fictioneer_refresh_post_caches' ) ) {
        fictioneer_refresh_post_caches( $id );
      }
    }


    /**
    * Promote a Treasure + Vault chapter to Public.
    */
    private function promote_to_public( $id ) {

     $id = absint( $id );

     if ( ! $id ) {
        return;
     }

     /*
       * Remove the chapter password.
       */
     wp_update_post( array(
        'ID'            => $id,
        'post_password' => '',
     ) );

     /*
      * Keep chapter-level access independent of the novel.
      */
     update_post_meta(
        $id,
        'fictioneer_patreon_override',
        '1'
     );

     /*
      * Remove Patreon restrictions.
      */
     delete_post_meta(
        $id,
        'fictioneer_patreon_lock_tiers'
     );

     delete_post_meta(
        $id,
        'fictioneer_patreon_lock_amount'
     );

     delete_post_meta(
        $id,
        'fictioneer_chapter_password_note'
      );

      delete_post_meta(
        $id,
        'fictioneer_post_password_expiration_date'
     );

     delete_post_meta(
        $id,
        '_bcu_password_set'
     );

      /*
       * Update access level and visible group.
      */
     update_post_meta(
        $id,
        '_bcu_access_level',
        'public'
     );

     update_post_meta(
        $id,
        'fictioneer_chapter_group',
        'Free'
     );

     /*
      * Refresh Fictioneer's associated story and chapter caches.
       */
     if ( function_exists( 'fictioneer_refresh_post_caches' ) ) {
        fictioneer_refresh_post_caches( $id );
      }

      /*
       * Queue public-release notification.
       */
      update_post_meta(
        $id,
        '_bcu_discord_public_release_pending',
        '1'
      );

      /*
       * Attempt notification immediately.
       */
      $this->notify_public_release( $id );
    }

        /**
     * Send one grouped chapter notification through GitHub Actions.
     *
     * $chapter_ids: chapter IDs belonging to one novel.
     * $release_mode: either 'free' or 'early_access'.
     */
    private function notify_chapter_batch( $chapter_ids, $release_mode ) {

        $chapter_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', (array) $chapter_ids )
                )
            )
        );

        if (
            empty( $chapter_ids ) ||
            ! in_array( $release_mode, array( 'free', 'early_access' ), true )
        ) {
            return false;
        }

        /*
         * GitHub relay configuration.
         */
        $token = get_option( 'bcu_github_token', '' );
        $username = get_option( 'bcu_github_username', 'beryll175' );
        $repo = get_option( 'bcu_github_repo', 'beryll-chapter-uploader' );
        $workflow = get_option( 'bcu_github_workflow', 'discord-notify.yml' );

        if ( empty( $token ) || empty( $username ) || empty( $repo ) || empty( $workflow ) ) {
            return false;
        }

        $chapters = array();
        $story_id = 0;

        /*
         * Validate chapters and collect their links.
         */
        foreach ( $chapter_ids as $id ) {

            $post = get_post( $id );

            if (
                ! $post ||
                'fcn_chapter' !== $post->post_type ||
                'publish' !== $post->post_status ||
                ! empty( $post->post_password )
            ) {
                continue;
            }

            $chapter_story_id = function_exists( 'fictioneer_get_chapter_story_id' )
                ? absint( fictioneer_get_chapter_story_id( $id ) )
                : absint( get_post_meta( $id, 'fictioneer_chapter_story', true ) );

            if ( ! $chapter_story_id ) {
                continue;
            }

            if ( ! $story_id ) {
                $story_id = $chapter_story_id;
            }

            /*
             * Never combine chapters from different novels.
             */
            if ( $story_id !== $chapter_story_id ) {
                continue;
            }

            $access_level = get_post_meta( $id, '_bcu_access_level', true );

            if ( 'free' === $release_mode ) {

                if ( 'public' !== $access_level ) {
                    continue;
                }

                if ( get_post_meta( $id, '_bcu_discord_public_release_notified', true ) ) {
                    continue;
                }

                $label = get_the_title( $id );

            } else {

                if ( ! in_array( $access_level, array( 'treasure', 'vault' ), true ) ) {
                    continue;
                }

                if ( get_post_meta( $id, '_bcu_discord_early_access_notified', true ) ) {
                    continue;
                }

                $label = get_the_title( $id );
            }

            $chapters[] = array(
                'id'    => $id,
                'label' => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
                'url'   => get_permalink( $id ),
            );
        }

        if ( empty( $chapters ) || ! $story_id ) {
            return false;
        }

        /*
         * Build the novel's chapter links.
         */
        $chapter_links = array();

        foreach ( $chapters as $chapter ) {

            $chapter_links[] = sprintf(
                '[%s](%s)',
                $chapter['label'],
                $chapter['url']
            );
        }

        $story_title = html_entity_decode(
            get_the_title( $story_id ),
            ENT_QUOTES,
            'UTF-8'
        );

        $first_chapter_url = $chapters[0]['url'];

        /*
         * Use the novel cover.
         */
        $image_url = get_the_post_thumbnail_url( $story_id, 'thumbnail' );

        /*
         * Determine when early-access chapters become free.
         */
        $free_date = '';

        if ( 'early_access' === $release_mode ) {

            $schedule = $this->settings( $story_id );
            $next_release = $this->next_release( $schedule );

            $free_date = $next_release
                ? $next_release->format( 'D j M Y \a\t H:i' )
                : 'the next scheduled release';
        }

        /*
         * Send the grouped notification.
         */
        $body = array(
            'ref' => 'main',
            'inputs' => array(
                'type'        => 'chapter',
                'title'       => $story_title,
                'url'         => $first_chapter_url,
                'story'       => $story_title,
                'author'      => 'Beryll TL',
                'description' => '',
                'image_url'   => $image_url ? $image_url : '',
                'release_mode' => $release_mode,
                'chapters'     => implode( "\n", $chapter_links ),
                'free_date'    => $free_date,
            ),
        );

        $dispatch_url = sprintf(
            'https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches',
            rawurlencode( $username ),
            rawurlencode( $repo ),
            rawurlencode( $workflow )
        );

        $response = wp_remote_post(
            $dispatch_url,
            array(
                'timeout'     => 20,
                'redirection' => 0,
                'blocking'    => true,
                'headers'     => array(
                    'Accept'               => 'application/vnd.github+json',
                    'Authorization'        => 'Bearer ' . $token,
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'Beryll-TL-WordPress-Relay',
                    'Content-Type'         => 'application/json',
                ),
                'body' => wp_json_encode( $body ),
            )
        );

        if (
            is_wp_error( $response ) ||
            204 !== (int) wp_remote_retrieve_response_code( $response )
        ) {
            return false;
        }

        /*
         * Mark every chapter only after GitHub accepts the dispatch.
         */
        foreach ( $chapters as $chapter ) {

            if ( 'free' === $release_mode ) {

                update_post_meta(
                    $chapter['id'],
                    '_bcu_discord_public_release_notified',
                    current_time( 'mysql' )
                );

                delete_post_meta(
                    $chapter['id'],
                    '_bcu_discord_public_release_pending'
                );

            } else {

                update_post_meta(
                    $chapter['id'],
                    '_bcu_discord_early_access_notified',
                    current_time( 'mysql' )
                );
            }
        }

        return true;
    }

    /**
     * Send a public-release notification through GitHub Actions.
     *
     * This does not contact Discord directly.
     */
    private function notify_public_release( $id ) {

        $id = absint( $id );

        if ( ! $id ) {
            return;
        }

        /*
         * Already announced?
         */
        if (
            get_post_meta(
                $id,
                '_bcu_discord_public_release_notified',
                true
            )
        ) {

            delete_post_meta(
                $id,
                '_bcu_discord_public_release_pending'
            );

            return;
        }

        /*
         * Verify that the chapter is actually public.
         */
        $post = get_post( $id );

        if (
            ! $post ||
            $post->post_type !== 'fcn_chapter' ||
            $post->post_status !== 'publish' ||
            ! empty( $post->post_password )
        ) {
            return;
        }

        /*
         * Ensure it is not still in a Patreon queue.
         */
        $access_level = get_post_meta(
            $id,
            '_bcu_access_level',
            true
        );

        if ( 'public' !== $access_level ) {
            return;
        }

        /*
         * Acquire a per-chapter notification lock.
         */
        $lock_key = '_bcu_discord_notify_lock_' . $id;

        $lock_token = $this->acquire_lock(
            $lock_key,
            5 * MINUTE_IN_SECONDS
        );

        if ( ! $lock_token ) {
            return;
        }

        try {

            /*
             * Check again after acquiring the lock.
             */
            if (
                get_post_meta(
                    $id,
                    '_bcu_discord_public_release_notified',
                    true
                )
            ) {

                delete_post_meta(
                    $id,
                    '_bcu_discord_public_release_pending'
                );

                return;
            }

            /*
             * GitHub relay configuration.
             */
            $token = get_option(
                'bcu_github_token',
                ''
            );

            $username = get_option(
                'bcu_github_username',
                'beryll175'
            );

            $repo = get_option(
                'bcu_github_repo',
                'beryll-chapter-uploader'
            );

            $workflow = get_option(
                'bcu_github_workflow',
                'discord-notify.yml'
            );

            /*
             * If GitHub is not configured, leave the
             * notification pending for a later retry.
             */
            if (
                empty( $token ) ||
                empty( $username ) ||
                empty( $repo ) ||
                empty( $workflow )
            ) {
                return;
            }

            /*
             * Retrieve novel information.
             */
            $story_id = function_exists( 'fictioneer_get_chapter_story_id' )
                ? absint( fictioneer_get_chapter_story_id( $id ) )
                : absint(
                    get_post_meta(
                        $id,
                        'fictioneer_chapter_story',
                        true
                    )
                );

            $title = html_entity_decode(
                get_the_title( $id ),
                ENT_QUOTES,
                'UTF-8'
            );

            $story_title = $story_id
                ? html_entity_decode(
                    get_the_title( $story_id ),
                    ENT_QUOTES,
                    'UTF-8'
                )
                : 'Beryll TL';

            $chapter_url = get_permalink( $id );

            $author = get_the_author_meta(
                'display_name',
                $post->post_author
            );

            if ( empty( $author ) ) {
                $author = 'Beryll TL';
            }

            /*
             * Retrieve the chapter or novel cover.
             */
            $image_url = get_the_post_thumbnail_url(
                $id,
                'thumbnail'
            );

            if ( empty( $image_url ) && $story_id ) {
                $image_url = get_the_post_thumbnail_url(
                    $story_id,
                    'thumbnail'
                );
            }

            /*
             * Build the GitHub Actions dispatch.
             *
             * These input names must match discord-notify.yml.
             */
            $body = array(
                'ref' => 'main',

                'inputs' => array(
                    'type'        => 'chapter',
                    'title'       => $title,
                    'url'         => $chapter_url,
                    'story'       => $story_title,
                    'author'      => $author,
                    'description' => 'A new chapter is now available to read for free!',
                    'image_url'   => $image_url ? $image_url : '',
                ),
            );

            $dispatch_url = sprintf(
                'https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches',
                rawurlencode( $username ),
                rawurlencode( $repo ),
                rawurlencode( $workflow )
            );

            /*
             * Send request to GitHub Actions.
             */
            $response = wp_remote_post(
                $dispatch_url,
                array(
                    'timeout'     => 20,
                    'redirection' => 0,
                    'blocking'    => true,

                    'headers' => array(
                        'Accept'               => 'application/vnd.github+json',
                        'Authorization'        => 'Bearer ' . $token,
                        'X-GitHub-Api-Version' => '2022-11-28',
                        'User-Agent'           => 'Beryll-TL-WordPress-Relay',
                        'Content-Type'         => 'application/json',
                    ),

                    'body' => wp_json_encode( $body ),
                )
            );

            /*
             * A successful workflow dispatch returns HTTP 204.
             */
            if ( is_wp_error( $response ) ) {
                return;
            }

            $status_code = wp_remote_retrieve_response_code(
                $response
            );

            if ( 204 !== $status_code ) {
                return;
            }

            /*
             * Mark notification as sent only after GitHub
             * confirms that it accepted the workflow dispatch.
             */
            update_post_meta(
                $id,
                '_bcu_discord_public_release_notified',
                current_time( 'mysql' )
            );

            delete_post_meta(
                $id,
                '_bcu_discord_public_release_pending'
            );

        } finally {

            $this->release_lock(
                $lock_key,
                $lock_token
            );
        }
    }


    /**
     * Retry previously failed public-release notifications.
     */
    private function process_pending_notifications() {

        $pending = get_posts( array(
            'post_type'   => 'fcn_chapter',
            'post_status' => 'publish',
            'numberposts' => 50,
            'fields'      => 'ids',

            'meta_query' => array(
                array(
                    'key'   => '_bcu_discord_public_release_pending',
                    'value' => '1',
                ),
            ),
        ) );

        foreach ( $pending as $id ) {

            $this->notify_public_release( $id );
        }
    }

}


/*
 * Initialize one shared scheduler instance.
 */
$GLOBALS['bcu_patreon_release_scheduler'] =
    new Beryll_Patreon_Release_Scheduler();

/**
 * Lightweight GitHub-based updater for Beryll Chapter Uploader.
 * The public repository exposes update.json and the plugin source on its main branch.
 */
class Beryll_Chapter_Uploader_Updater {
    const REPOSITORY = 'beryll175/beryll-chapter-uploader';
    const MANIFEST_URL = 'https://raw.githubusercontent.com/beryll175/beryll-chapter-uploader/main/update.json';
    const PLUGIN_FILE = 'beryll-chapter-uploader/beryll-chapter-uploader.php';
    const CACHE_KEY = 'bcu_github_update_manifest';

    public function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_information' ), 20, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_package_directory' ), 10, 4 );
    }

    private function manifest() {
        $cached = get_site_transient( self::CACHE_KEY );
        if ( false !== $cached && is_object( $cached ) ) { return $cached; }
        $response = wp_remote_get( self::MANIFEST_URL, array( 'timeout' => 8, 'redirection' => 3, 'headers' => array( 'Accept' => 'application/json' ) ) );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) { return false; }
        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( ! is_object( $data ) || empty( $data->version ) || empty( $data->package ) ) { return false; }
        if ( ! preg_match( '/^\d+\.\d+(?:\.\d+)?(?:[-+][a-zA-Z0-9.-]+)?$/', (string) $data->version ) ) { return false; }
        set_site_transient( self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS );
        return $data;
    }

    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) || empty( $transient->checked ) ) { return $transient; }
        $manifest = $this->manifest();
        if ( ! $manifest || version_compare( $manifest->version, Beryll_Chapter_Uploader::VERSION, '<=' ) ) { return $transient; }
        $plugin = plugin_basename( dirname( __FILE__ ) . '/beryll-chapter-uploader.php' );
        $item = (object) array(
            'id' => 'https://wordpress.org/plugins/beryll-chapter-uploader/',
            'slug' => 'beryll-chapter-uploader',
            'plugin' => $plugin,
            'new_version' => sanitize_text_field( $manifest->version ),
            'url' => 'https://github.com/' . self::REPOSITORY,
            'package' => esc_url_raw( $manifest->package ),
            'tested' => isset( $manifest->tested ) ? sanitize_text_field( $manifest->tested ) : '',
            'requires' => isset( $manifest->requires ) ? sanitize_text_field( $manifest->requires ) : '6.0',
            'requires_php' => isset( $manifest->requires_php ) ? sanitize_text_field( $manifest->requires_php ) : '7.4',
        );
        $transient->response[$plugin] = $item;
        return $transient;
    }

    public function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'beryll-chapter-uploader' !== $args->slug ) { return $result; }
        $manifest = $this->manifest();
        if ( ! $manifest ) { return $result; }
        $info = new stdClass();
        $info->name = 'Beryll Chapter Uploader';
        $info->slug = 'beryll-chapter-uploader';
        $info->version = sanitize_text_field( $manifest->version );
        $info->author = '<a href="https://github.com/beryll175">Beryll TL</a>';
        $info->homepage = 'https://github.com/' . self::REPOSITORY;
        $info->download_link = esc_url_raw( $manifest->package );
        $info->requires = isset( $manifest->requires ) ? sanitize_text_field( $manifest->requires ) : '6.0';
        $info->requires_php = isset( $manifest->requires_php ) ? sanitize_text_field( $manifest->requires_php ) : '7.4';
        $info->tested = isset( $manifest->tested ) ? sanitize_text_field( $manifest->tested ) : '';
        $info->sections = array(
            'description' => 'Import Fictioneer chapters from ZIP/DOCX or EPUB files, detect volumes and duplicates, publish immediately, and schedule chapters by selected weekdays.',
            'changelog' => isset( $manifest->changelog ) ? wp_kses_post( $manifest->changelog ) : 'See the GitHub repository for release notes.',
        );
        return $info;
    }

    public function fix_package_directory( $source, $remote_source, $upgrader, $hook_extra = null ) {
        if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) { return $source; }
        $target_plugin = plugin_basename( dirname( __FILE__ ) . '/beryll-chapter-uploader.php' );
        if ( $target_plugin !== $hook_extra['plugin'] || ! is_dir( $source ) ) { return $source; }
        $expected = trailingslashit( $remote_source ) . 'beryll-chapter-uploader';
        if ( basename( untrailingslashit( $source ) ) === 'beryll-chapter-uploader' ) { return $source; }
        if ( file_exists( $expected ) ) { return new WP_Error( 'bcu_update_directory', 'Could not prepare the Beryll Chapter Uploader update because its temporary folder already exists.' ); }
        if ( @rename( untrailingslashit( $source ), $expected ) ) { return trailingslashit( $expected ); }
        return new WP_Error( 'bcu_update_directory', 'Could not prepare the Beryll Chapter Uploader update package.' );
    }
}

new Beryll_Chapter_Uploader_Updater();
