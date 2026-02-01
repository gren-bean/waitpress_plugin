<?php

if (!defined('ABSPATH')) {
    exit;
}

class Waitpress_Plugin {
    private static $instance;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->register_hooks();
    }

    private function register_hooks() {
        register_activation_hook(WAITPRESS_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WAITPRESS_PLUGIN_FILE, array($this, 'deactivate'));

        add_action('init', array($this, 'register_shortcodes'));
        add_action('init', array($this, 'handle_form_submissions'));
        add_action('template_redirect', array($this, 'handle_public_actions'));

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('cron_schedules', array($this, 'register_cron_schedules'));

        add_action('admin_post_waitpress_offer_next', array($this, 'handle_offer_next'));
        add_action('admin_post_waitpress_remove_applicant', array($this, 'handle_remove_applicant'));

        add_action('waitpress_daily_offer_expiry', array($this, 'run_daily_offer_expiry'));
        add_action('waitpress_monthly_status_email', array($this, 'send_monthly_status_emails'));
    }

    public function activate() {
        $start = microtime(true);
        $this->log_activation_step('Activation started');

        $this->time_activation_step('create_tables', array($this, 'create_tables'));
        $this->time_activation_step('seed_defaults', array($this, 'seed_defaults'));
        $this->time_activation_step('ensure_pages', array($this, 'ensure_pages'));
        $this->time_activation_step('schedule_events', array($this, 'schedule_events'));

        $elapsed = microtime(true) - $start;
        $this->log_activation_step(sprintf('Activation completed in %.3f seconds', $elapsed));
    }

    public function deactivate() {
        $this->clear_scheduled_events();
    }

    public function register_shortcodes() {
        add_shortcode('waitpress_apply_form', array($this, 'render_apply_form'));
        add_shortcode('waitpress_status', array($this, 'render_status_page'));
    }

    public function register_admin_menu() {
        add_menu_page(
            __('Garden Waitlist', 'waitpress'),
            __('Garden Waitlist', 'waitpress'),
            'manage_options',
            'waitpress',
            array($this, 'render_admin_dashboard'),
            'dashicons-list-view',
            56
        );

        add_submenu_page(
            'waitpress',
            __('Settings', 'waitpress'),
            __('Settings', 'waitpress'),
            'manage_options',
            'waitpress-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('waitpress_settings', 'waitpress_settings', array(
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));

        add_settings_section(
            'waitpress_general',
            __('General Settings', 'waitpress'),
            '__return_false',
            'waitpress-settings'
        );

        add_settings_field(
            'offer_expiration_days',
            __('Offer expiration (days)', 'waitpress'),
            array($this, 'render_offer_expiration_field'),
            'waitpress-settings',
            'waitpress_general'
        );

        add_settings_field(
            'monthly_email_day',
            __('Monthly email day', 'waitpress'),
            array($this, 'render_monthly_day_field'),
            'waitpress-settings',
            'waitpress_general'
        );

        add_settings_field(
            'monthly_email_time',
            __('Monthly email time', 'waitpress'),
            array($this, 'render_monthly_time_field'),
            'waitpress-settings',
            'waitpress_general'
        );

        add_settings_field(
            'template_applicant_confirmation',
            __('Applicant confirmation email', 'waitpress'),
            array($this, 'render_template_confirmation_field'),
            'waitpress-settings',
            'waitpress_general'
        );

        add_settings_field(
            'template_monthly_status',
            __('Monthly status email', 'waitpress'),
            array($this, 'render_template_monthly_field'),
            'waitpress-settings',
            'waitpress_general'
        );

        add_settings_field(
            'notification_join_recipients',
            __('Additional recipients: waitlist joins', 'waitpress'),
            array($this, 'render_join_recipients_field'),
            'waitpress-settings',
            'waitpress_general'
        );

        add_settings_field(
            'notification_leave_recipients',
            __('Additional recipients: waitlist leaves', 'waitpress'),
            array($this, 'render_leave_recipients_field'),
            'waitpress-settings',
            'waitpress_general'
        );

        add_settings_field(
            'notification_accept_recipients',
            __('Additional recipients: offer accepted', 'waitpress'),
            array($this, 'render_accept_recipients_field'),
            'waitpress-settings',
            'waitpress_general'
        );
    }

    public function render_apply_form() {
        $messages = $this->get_flash_messages();
        $hide_form = $this->get_flash_flag('hide_apply_form');
        $html = '';

        if ($messages) {
            foreach ($messages as $message) {
                $html .= '<div class="waitpress-message">' . esc_html($message) . '</div>';
            }
        }

        if ($hide_form) {
            return $html;
        }

        $html .= '<form class="waitpress-apply-form" method="post">';
        $html .= wp_nonce_field('waitpress_apply', '_waitpress_nonce', true, false);
        $html .= '<input type="hidden" name="waitpress_action" value="apply">';
        $html .= '<p><label>' . esc_html__('First name', 'waitpress') . '<br><input type="text" name="waitpress_first_name" required></label></p>';
        $html .= '<p><label>' . esc_html__('Last name', 'waitpress') . '<br><input type="text" name="waitpress_last_name" required></label></p>';
        $html .= '<p><label>' . esc_html__('Email', 'waitpress') . '<br><input type="email" name="waitpress_email" required></label></p>';
        $html .= '<p><label>' . esc_html__('Phone', 'waitpress') . '<br><input type="tel" name="waitpress_phone" required></label></p>';
        $html .= '<p><label>' . esc_html__('Street address', 'waitpress') . '<br><input type="text" name="waitpress_address" required></label></p>';
        $html .= '<p><label>' . esc_html__('City', 'waitpress') . '<br><input type="text" name="waitpress_city" required></label></p>';
        $html .= '<p><label>' . esc_html__('State', 'waitpress') . '<br><input type="text" name="waitpress_state" required></label></p>';
        $html .= '<p><label>' . esc_html__('Zip code', 'waitpress') . '<br><input type="text" name="waitpress_zip" required></label></p>';
        $html .= '<p><label>' . esc_html__('Plot number (if assigned)', 'waitpress') . '<br><input type="text" name="waitpress_plot_number"></label></p>';
        $html .= '<p><label>' . esc_html__('Emergency contact name', 'waitpress') . '<br><input type="text" name="waitpress_emergency_name"></label></p>';
        $html .= '<p><label>' . esc_html__('Emergency contact phone', 'waitpress') . '<br><input type="tel" name="waitpress_emergency_phone"></label></p>';
        $html .= '<p><label>' . esc_html__('Additional notes', 'waitpress') . '<br><textarea name="waitpress_comments"></textarea></label></p>';
        $html .= '<p><button type="submit">' . esc_html__('Join Waitlist', 'waitpress') . '</button></p>';
        $html .= '</form>';

        return $html;
    }

    public function render_status_page() {
        $messages = $this->get_flash_messages();
        $html = '';

        if ($messages) {
            foreach ($messages as $message) {
                $html .= '<div class="waitpress-message">' . esc_html($message) . '</div>';
            }
        }

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $applicant = $token ? $this->get_applicant_by_token($token) : null;

        if ($applicant) {
            $status_label = $this->format_status_label($applicant->status);
            $html .= '<div class="waitpress-status-details">';
            $html .= '<p>' . sprintf(esc_html__('Status: %s', 'waitpress'), esc_html($status_label)) . '</p>';
            $html .= '<p>' . sprintf(esc_html__('Last updated: %s', 'waitpress'), esc_html(mysql2date(get_option('date_format'), $applicant->updated_at))) . '</p>';

            if ($applicant->status === 'removed') {
                $html .= '<p>' . esc_html__('You have been removed from the waitlist by an administrator.', 'waitpress') . '</p>';
                $html .= '</div>';
                return $html;
            }

            if ($applicant->status === 'waiting') {
                $position = $this->get_waitlist_position($applicant);
                $html .= '<p>' . sprintf(esc_html__('Current waitlist position: %d', 'waitpress'), $position) . '</p>';
            }

            if ($applicant->status === 'offered') {
                $offer = $this->get_active_offer($applicant->id);
                if ($offer) {
                    $accept_url = add_query_arg(array(
                        'waitpress_action' => 'offer_accept',
                        'token' => $offer->offer_token,
                    ), home_url('/'));
                    $decline_url = add_query_arg(array(
                        'waitpress_action' => 'offer_decline',
                        'token' => $offer->offer_token,
                    ), home_url('/'));

                    $html .= '<p>' . esc_html__('You have a pending offer.', 'waitpress') . '</p>';
                    $html .= '<p><a href="' . esc_url($accept_url) . '">' . esc_html__('Accept Offer', 'waitpress') . '</a></p>';
                    $html .= '<p><a href="' . esc_url($decline_url) . '">' . esc_html__('Decline and leave waitlist', 'waitpress') . '</a></p>';
                }
            }

            if ($applicant->status !== 'left_waitlist') {
                $html .= '<form method="post">';
                $html .= wp_nonce_field('waitpress_leave', '_waitpress_nonce', true, false);
                $html .= '<input type="hidden" name="waitpress_action" value="leave">';
                $html .= '<input type="hidden" name="waitpress_token" value="' . esc_attr($token) . '">';
                $html .= '<p><button type="submit">' . esc_html__('Leave Waitlist', 'waitpress') . '</button></p>';
                $html .= '</form>';
            }
            $html .= '</div>';

            return $html;
        }

        $html .= '<form class="waitpress-status-request" method="post">';
        $html .= wp_nonce_field('waitpress_request_link', '_waitpress_nonce', true, false);
        $html .= '<input type="hidden" name="waitpress_action" value="request_link">';
        $html .= '<p><label>' . esc_html__('Email', 'waitpress') . '<br><input type="email" name="waitpress_email" required></label></p>';
        $html .= '<p><button type="submit">' . esc_html__('Email me my status link', 'waitpress') . '</button></p>';
        $html .= '</form>';

        if ($token) {
            $html .= '<p>' . esc_html__('Invalid or expired link.', 'waitpress') . '</p>';
        }

        return $html;
    }

    public function render_admin_dashboard() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $applicants = $this->get_applicants();
        $offer_url = admin_url('admin-post.php');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Garden Waitlist', 'waitpress') . '</h1>';
        echo '<form method="post" action="' . esc_url($offer_url) . '">';
        echo wp_nonce_field('waitpress_offer_next', '_waitpress_nonce', true, false);
        echo '<input type="hidden" name="action" value="waitpress_offer_next">';
        echo '<p><button class="button button-primary" type="submit">' . esc_html__('Offer next eligible', 'waitpress') . '</button></p>';
        echo '</form>';

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Name', 'waitpress') . '</th>';
        echo '<th>' . esc_html__('Email', 'waitpress') . '</th>';
        echo '<th>' . esc_html__('Status', 'waitpress') . '</th>';
        echo '<th>' . esc_html__('Joined', 'waitpress') . '</th>';
        echo '<th>' . esc_html__('Actions', 'waitpress') . '</th>';
        echo '</tr></thead><tbody>';

        if ($applicants) {
            foreach ($applicants as $applicant) {
                echo '<tr>';
                echo '<td>' . esc_html($applicant->name) . '</td>';
                echo '<td>' . esc_html($applicant->email) . '</td>';
                echo '<td>' . esc_html($this->format_status_label($applicant->status)) . '</td>';
                echo '<td>' . esc_html(mysql2date(get_option('date_format'), $applicant->joined_at)) . '</td>';
                echo '<td>';
                if (!in_array($applicant->status, array('removed', 'left_waitlist'), true)) {
                    echo '<form method="post" action="' . esc_url($offer_url) . '">';
                    echo wp_nonce_field('waitpress_remove_applicant', '_waitpress_nonce', true, false);
                    echo '<input type="hidden" name="action" value="waitpress_remove_applicant">';
                    echo '<input type="hidden" name="applicant_id" value="' . esc_attr($applicant->id) . '">';
                    echo '<button class="button button-secondary" type="submit" onclick="return confirm(\'' . esc_js(__('Remove this applicant from the waitlist?', 'waitpress')) . '\');">' . esc_html__('Remove', 'waitpress') . '</button>';
                    echo '</form>';
                } else {
                    echo '&mdash;';
                }
                echo '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">' . esc_html__('No applicants yet.', 'waitpress') . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Waitpress Settings', 'waitpress') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('waitpress_settings');
        do_settings_sections('waitpress-settings');
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    public function handle_form_submissions() {
        if (!isset($_POST['waitpress_action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['waitpress_action']));

        if ($action === 'apply') {
            $this->handle_apply_submission();
        }

        if ($action === 'request_link') {
            $this->handle_request_link();
        }

        if ($action === 'leave') {
            $this->handle_leave_waitlist();
        }
    }

    public function handle_public_actions() {
        if (!isset($_GET['waitpress_action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_GET['waitpress_action']));
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';

        if (!$token) {
            return;
        }

        if ($action === 'offer_accept') {
            $this->process_offer_response($token, 'accepted');
        }

        if ($action === 'offer_decline') {
            $this->process_offer_response($token, 'declined');
        }
    }

    public function handle_offer_next() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'waitpress'));
        }

        check_admin_referer('waitpress_offer_next', '_waitpress_nonce');
        $this->offer_next_applicant();

        wp_safe_redirect(admin_url('admin.php?page=waitpress'));
        exit;
    }

    public function handle_remove_applicant() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'waitpress'));
        }

        check_admin_referer('waitpress_remove_applicant', '_waitpress_nonce');

        $applicant_id = isset($_POST['applicant_id']) ? (int) $_POST['applicant_id'] : 0;
        $applicant = $applicant_id ? $this->get_applicant($applicant_id) : null;

        if (!$applicant) {
            $this->set_flash_message(__('Applicant not found.', 'waitpress'));
            wp_safe_redirect(admin_url('admin.php?page=waitpress'));
            exit;
        }

        if ($applicant->status === 'removed') {
            $this->set_flash_message(__('Applicant is already removed.', 'waitpress'));
            wp_safe_redirect(admin_url('admin.php?page=waitpress'));
            exit;
        }

        if ($applicant->status === 'left_waitlist') {
            $this->set_flash_message(__('Applicant has already left the waitlist.', 'waitpress'));
            wp_safe_redirect(admin_url('admin.php?page=waitpress'));
            exit;
        }

        $this->update_applicant($applicant->id, array(
            'status' => 'removed',
            'removed_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ));

        $active_offer = $this->get_active_offer($applicant->id);
        if ($active_offer) {
            $this->update_offer($active_offer->id, array('status' => 'declined', 'updated_at' => current_time('mysql')));
            $this->offer_next_applicant($active_offer->plot_id);
        }

        $this->send_email($applicant->email, __('Waitlist removal confirmation', 'waitpress'), __('You have been removed from the waitlist.', 'waitpress'));
        $this->send_email(
            $this->get_notification_recipients('notification_leave_recipients'),
            __('Waitlist removal', 'waitpress'),
            sprintf(__('Applicant %s was removed from the waitlist.', 'waitpress'), $applicant->name)
        );
        $this->set_flash_message(__('Applicant removed from the waitlist.', 'waitpress'));

        wp_safe_redirect(admin_url('admin.php?page=waitpress'));
        exit;
    }

    public function run_daily_offer_expiry() {
        $this->expire_offers();
    }

    public function send_monthly_status_emails() {
        $this->send_monthly_emails();
    }

    public function register_cron_schedules($schedules) {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display' => __('Once Monthly', 'waitpress'),
            );
        }

        return $schedules;
    }

    public function sanitize_settings($settings) {
        $settings['offer_expiration_days'] = isset($settings['offer_expiration_days']) ? absint($settings['offer_expiration_days']) : 5;
        $settings['monthly_email_day'] = isset($settings['monthly_email_day']) ? absint($settings['monthly_email_day']) : 1;
        $settings['monthly_email_time'] = isset($settings['monthly_email_time']) ? sanitize_text_field($settings['monthly_email_time']) : '09:00';
        $settings['template_applicant_confirmation'] = isset($settings['template_applicant_confirmation']) ? sanitize_textarea_field($settings['template_applicant_confirmation']) : '';
        $settings['template_monthly_status'] = isset($settings['template_monthly_status']) ? sanitize_textarea_field($settings['template_monthly_status']) : '';
        $settings['notification_join_recipients'] = isset($settings['notification_join_recipients']) ? sanitize_textarea_field($settings['notification_join_recipients']) : '';
        $settings['notification_leave_recipients'] = isset($settings['notification_leave_recipients']) ? sanitize_textarea_field($settings['notification_leave_recipients']) : '';
        $settings['notification_accept_recipients'] = isset($settings['notification_accept_recipients']) ? sanitize_textarea_field($settings['notification_accept_recipients']) : '';

        return $settings;
    }

    public function render_offer_expiration_field() {
        $settings = $this->get_settings();
        printf(
            '<input type="number" name="waitpress_settings[offer_expiration_days]" value="%d" min="1" />',
            esc_attr($settings['offer_expiration_days'])
        );
    }

    public function render_monthly_day_field() {
        $settings = $this->get_settings();
        printf(
            '<input type="number" name="waitpress_settings[monthly_email_day]" value="%d" min="1" max="28" />',
            esc_attr($settings['monthly_email_day'])
        );
    }

    public function render_monthly_time_field() {
        $settings = $this->get_settings();
        printf(
            '<input type="time" name="waitpress_settings[monthly_email_time]" value="%s" />',
            esc_attr($settings['monthly_email_time'])
        );
    }

    public function render_template_confirmation_field() {
        $settings = $this->get_settings();
        printf(
            '<textarea name="waitpress_settings[template_applicant_confirmation]" rows="4" cols="50">%s</textarea>',
            esc_textarea($settings['template_applicant_confirmation'])
        );
    }

    public function render_template_monthly_field() {
        $settings = $this->get_settings();
        printf(
            '<textarea name="waitpress_settings[template_monthly_status]" rows="4" cols="50">%s</textarea>',
            esc_textarea($settings['template_monthly_status'])
        );
    }

    private function render_notification_recipients_field($setting_key) {
        $settings = $this->get_settings();
        printf(
            '<textarea name="waitpress_settings[%1$s]" rows="3" cols="50">%2$s</textarea><p class="description">%3$s</p>',
            esc_attr($setting_key),
            esc_textarea($settings[$setting_key]),
            esc_html__('Enter comma-separated email addresses.', 'waitpress')
        );
    }

    public function render_join_recipients_field() {
        $this->render_notification_recipients_field('notification_join_recipients');
    }

    public function render_leave_recipients_field() {
        $this->render_notification_recipients_field('notification_leave_recipients');
    }

    public function render_accept_recipients_field() {
        $this->render_notification_recipients_field('notification_accept_recipients');
    }

    private function handle_apply_submission() {
        if (!isset($_POST['_waitpress_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_waitpress_nonce'])), 'waitpress_apply')) {
            return;
        }

        $first_name = sanitize_text_field(wp_unslash($_POST['waitpress_first_name'] ?? ''));
        $last_name = sanitize_text_field(wp_unslash($_POST['waitpress_last_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['waitpress_email'] ?? ''));
        $phone = sanitize_text_field(wp_unslash($_POST['waitpress_phone'] ?? ''));
        $address = sanitize_text_field(wp_unslash($_POST['waitpress_address'] ?? ''));
        $city = sanitize_text_field(wp_unslash($_POST['waitpress_city'] ?? ''));
        $state = sanitize_text_field(wp_unslash($_POST['waitpress_state'] ?? ''));
        $zip = sanitize_text_field(wp_unslash($_POST['waitpress_zip'] ?? ''));
        $plot_number = sanitize_text_field(wp_unslash($_POST['waitpress_plot_number'] ?? ''));
        $emergency_name = sanitize_text_field(wp_unslash($_POST['waitpress_emergency_name'] ?? ''));
        $emergency_phone = sanitize_text_field(wp_unslash($_POST['waitpress_emergency_phone'] ?? ''));
        $comments = sanitize_textarea_field(wp_unslash($_POST['waitpress_comments'] ?? ''));

        if (!$first_name || !$last_name || !$email || !$phone || !$address || !$city || !$state || !$zip) {
            $this->set_flash_message(__('Please complete all required fields.', 'waitpress'));
            return;
        }

        $name = trim($first_name . ' ' . $last_name);
        $address_lines = array_filter(array($address, trim($city . ', ' . $state . ' ' . $zip)));
        $address = implode("\n", $address_lines);

        $extra_notes = array();
        if ($plot_number) {
            $extra_notes[] = sprintf('Plot number: %s', $plot_number);
        }
        if ($emergency_name || $emergency_phone) {
            $emergency_details = trim($emergency_name . ' ' . $emergency_phone);
            $extra_notes[] = sprintf('Emergency contact: %s', $emergency_details);
        }
        if ($extra_notes) {
            $comments = trim(implode("\n", $extra_notes) . "\n" . $comments);
        }

        $token = $this->generate_token();
        $now = current_time('mysql');

        $this->insert_applicant(array(
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'comments' => $comments,
            'status' => 'waiting',
            'joined_at' => $now,
            'updated_at' => $now,
            'magic_token' => $token,
            'magic_token_expires' => gmdate('Y-m-d H:i:s', strtotime('+30 days')),
        ));

        $status_url = add_query_arg('token', $token, $this->get_status_page_url());
        $subject = __('Waitlist confirmation', 'waitpress');
        $body = $this->interpolate_template(
            $this->get_settings()['template_applicant_confirmation'],
            array(
                'status_link' => $status_url,
                'applicant_name' => $name,
            )
        );

        $this->send_email($email, $subject, $body);
        $this->send_email(
            $this->get_notification_recipients('notification_join_recipients'),
            __('New waitlist application', 'waitpress'),
            sprintf(__('New applicant: %s', 'waitpress'), $name)
        );

        $this->set_flash_message(__('You are on the list! Check your email for your status link.', 'waitpress'));
        $this->set_flash_flag('hide_apply_form');
        wp_safe_redirect($this->get_current_url());
        exit;
    }

    private function handle_request_link() {
        if (!isset($_POST['_waitpress_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_waitpress_nonce'])), 'waitpress_request_link')) {
            return;
        }

        $email = sanitize_email(wp_unslash($_POST['waitpress_email'] ?? ''));
        if (!$email) {
            $this->set_flash_message(__('Please provide an email address.', 'waitpress'));
            return;
        }

        $applicant = $this->get_applicant_by_email($email);
        if (!$applicant) {
            $this->set_flash_message(__('No applicant found with that email.', 'waitpress'));
            wp_safe_redirect($this->get_current_url());
            exit;
        }

        $token = $this->generate_token();
        $this->update_applicant($applicant->id, array(
            'magic_token' => $token,
            'magic_token_expires' => gmdate('Y-m-d H:i:s', strtotime('+7 days')),
            'updated_at' => current_time('mysql'),
        ));

        $status_url = add_query_arg('token', $token, $this->get_status_page_url());
        $subject = __('Your waitlist status link', 'waitpress');
        $body = sprintf(__('Use this link to view your status: %s', 'waitpress'), esc_url($status_url));

        $this->send_email($email, $subject, $body);
        $this->set_flash_message(__('We emailed you a one-time status link.', 'waitpress'));
        wp_safe_redirect($this->get_current_url());
        exit;
    }

    private function handle_leave_waitlist() {
        if (!isset($_POST['_waitpress_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_waitpress_nonce'])), 'waitpress_leave')) {
            return;
        }

        $token = sanitize_text_field(wp_unslash($_POST['waitpress_token'] ?? ''));
        $applicant = $this->get_applicant_by_token($token);
        if (!$applicant) {
            $this->set_flash_message(__('Invalid or expired link.', 'waitpress'));
            return;
        }

        if ($applicant->status === 'removed') {
            $this->set_flash_message(__('You have already been removed from the waitlist by an administrator.', 'waitpress'));
            return;
        }

        if ($applicant->status === 'left_waitlist') {
            $this->set_flash_message(__('You have already left the waitlist.', 'waitpress'));
            return;
        }

        $this->update_applicant($applicant->id, array(
            'status' => 'left_waitlist',
            'removed_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ));

        $this->send_email($applicant->email, __('Waitlist departure confirmation', 'waitpress'), __('You have left the waitlist.', 'waitpress'));
        $this->send_email(
            $this->get_notification_recipients('notification_leave_recipients'),
            __('Waitlist departure', 'waitpress'),
            sprintf(__('Applicant %s left the waitlist.', 'waitpress'), $applicant->name)
        );
        $this->set_flash_message(__('You have left the waitlist.', 'waitpress'));
        wp_safe_redirect($this->get_current_url());
        exit;
    }

    private function process_offer_response($token, $decision) {
        $offer = $this->get_offer_by_token($token);
        if (!$offer || $offer->status !== 'pending') {
            $this->set_flash_message(__('Offer not found or no longer available.', 'waitpress'));
            wp_safe_redirect($this->get_status_page_url());
            exit;
        }

        $applicant = $this->get_applicant($offer->applicant_id);
        if (!$applicant) {
            return;
        }

        if ($decision === 'accepted') {
            $this->update_offer($offer->id, array('status' => 'accepted', 'updated_at' => current_time('mysql')));
            $this->update_applicant($applicant->id, array('status' => 'accepted', 'updated_at' => current_time('mysql')));
            $this->send_email($applicant->email, __('Offer accepted', 'waitpress'), __('Thank you for accepting the offer.', 'waitpress'));
            $this->send_email(
                $this->get_notification_recipients('notification_accept_recipients'),
                __('Offer accepted', 'waitpress'),
                sprintf(__('Applicant %s accepted the offer.', 'waitpress'), $applicant->name)
            );
        }

        if ($decision === 'declined') {
            $this->update_offer($offer->id, array('status' => 'declined', 'updated_at' => current_time('mysql')));
            $this->update_applicant($applicant->id, array('status' => 'left_waitlist', 'removed_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
            $this->send_email($applicant->email, __('Offer declined', 'waitpress'), __('You have declined the offer and left the waitlist.', 'waitpress'));
            $this->offer_next_applicant($offer->plot_id);
        }

        $this->set_flash_message(__('Your response has been recorded.', 'waitpress'));
        wp_safe_redirect($this->get_status_page_url());
        exit;
    }

    private function create_tables() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();
        $applicants = $this->get_table_name('applicants');
        $offers = $this->get_table_name('offers');
        $plots = $this->get_table_name('plots');

        $sql = "
        CREATE TABLE {$applicants} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(50) NOT NULL,
            address VARCHAR(255) NOT NULL,
            comments TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'waiting',
            joined_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            removed_at DATETIME NULL,
            magic_token VARCHAR(190) NULL,
            magic_token_expires DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY email (email)
        ) {$charset};

        CREATE TABLE {$offers} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            applicant_id BIGINT UNSIGNED NOT NULL,
            offer_token VARCHAR(190) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            plot_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY applicant_id (applicant_id),
            KEY status (status)
        ) {$charset};

        CREATE TABLE {$plots} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            available TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id)
        ) {$charset};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private function seed_defaults() {
        if (!get_option('waitpress_settings')) {
            add_option('waitpress_settings', array(
                'offer_expiration_days' => 5,
                'monthly_email_day' => 1,
                'monthly_email_time' => '09:00',
                'template_applicant_confirmation' => 'Thanks for joining the waitlist. Your status link is {{status_link}}.',
                'template_monthly_status' => 'Your current waitlist position is {{position}}. Visit {{status_link}} to review your status.',
                'notification_join_recipients' => '',
                'notification_leave_recipients' => '',
                'notification_accept_recipients' => '',
            ));
        }
    }

    private function ensure_pages() {
        $apply_id = $this->ensure_page('waitpress-apply', __('Apply to the Waitlist', 'waitpress'), '[waitpress_apply_form]');
        $status_id = $this->ensure_page('waitpress-status', __('Waitlist Status', 'waitpress'), '[waitpress_status]');

        update_option('waitpress_apply_page_id', $apply_id);
        update_option('waitpress_status_page_id', $status_id);
    }

    private function schedule_events() {
        if (!wp_next_scheduled('waitpress_daily_offer_expiry')) {
            wp_schedule_event(time(), 'daily', 'waitpress_daily_offer_expiry');
        }

        if (!wp_next_scheduled('waitpress_monthly_status_email')) {
            wp_schedule_event(time(), 'monthly', 'waitpress_monthly_status_email');
        }
    }

    private function clear_scheduled_events() {
        $daily = wp_next_scheduled('waitpress_daily_offer_expiry');
        if ($daily) {
            wp_unschedule_event($daily, 'waitpress_daily_offer_expiry');
        }

        $monthly = wp_next_scheduled('waitpress_monthly_status_email');
        if ($monthly) {
            wp_unschedule_event($monthly, 'waitpress_monthly_status_email');
        }
    }

    private function offer_next_applicant($plot_id = null) {
        $next = $this->get_next_waiting_applicant();
        if (!$next) {
            return;
        }

        $settings = $this->get_settings();
        $expires = gmdate('Y-m-d H:i:s', strtotime('+' . absint($settings['offer_expiration_days']) . ' days'));
        $token = $this->generate_token();
        $now = current_time('mysql');

        $offer_id = $this->insert_offer(array(
            'applicant_id' => $next->id,
            'offer_token' => $token,
            'status' => 'pending',
            'expires_at' => $expires,
            'plot_id' => $plot_id,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        if (!$offer_id) {
            return;
        }

        $this->update_applicant($next->id, array(
            'status' => 'offered',
            'updated_at' => $now,
        ));

        $accept_url = add_query_arg(array(
            'waitpress_action' => 'offer_accept',
            'token' => $token,
        ), home_url('/'));
        $decline_url = add_query_arg(array(
            'waitpress_action' => 'offer_decline',
            'token' => $token,
        ), home_url('/'));

        $body = sprintf(
            __('You have been offered a plot. Accept: %s Decline: %s', 'waitpress'),
            esc_url($accept_url),
            esc_url($decline_url)
        );

        $this->send_email($next->email, __('Waitlist offer', 'waitpress'), $body);
    }

    private function expire_offers() {
        $offers = $this->get_expired_offers();
        if (!$offers) {
            return;
        }

        foreach ($offers as $offer) {
            $this->update_offer($offer->id, array('status' => 'expired', 'updated_at' => current_time('mysql')));
            $this->update_applicant($offer->applicant_id, array(
                'status' => 'waiting',
                'joined_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ));
            $this->offer_next_applicant($offer->plot_id);
        }
    }

    private function send_monthly_emails() {
        $applicants = $this->get_waiting_applicants();
        if (!$applicants) {
            return;
        }

        $settings = $this->get_settings();
        foreach ($applicants as $applicant) {
            $position = $this->get_waitlist_position($applicant);
            $body = $this->interpolate_template(
                $settings['template_monthly_status'],
                array(
                    'position' => $position,
                    'status_link' => $this->get_status_page_url(),
                )
            );
            $this->send_email($applicant->email, __('Waitlist status update', 'waitpress'), $body);
        }
    }

    private function get_settings() {
        $defaults = array(
            'offer_expiration_days' => 5,
            'monthly_email_day' => 1,
            'monthly_email_time' => '09:00',
            'template_applicant_confirmation' => '',
            'template_monthly_status' => '',
            'notification_join_recipients' => '',
            'notification_leave_recipients' => '',
            'notification_accept_recipients' => '',
        );

        return wp_parse_args(get_option('waitpress_settings', array()), $defaults);
    }

    private function get_table_name($suffix) {
        global $wpdb;

        return $wpdb->prefix . 'waitpress_' . $suffix;
    }

    private function insert_applicant($data) {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        $inserted = $wpdb->insert($table, $data);

        return $inserted ? $wpdb->insert_id : 0;
    }

    private function insert_offer($data) {
        global $wpdb;
        $table = $this->get_table_name('offers');

        $inserted = $wpdb->insert($table, $data);

        return $inserted ? $wpdb->insert_id : 0;
    }

    private function update_applicant($id, $data) {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        return $wpdb->update($table, $data, array('id' => $id));
    }

    private function update_offer($id, $data) {
        global $wpdb;
        $table = $this->get_table_name('offers');

        return $wpdb->update($table, $data, array('id' => $id));
    }

    private function get_applicant_by_email($email) {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE email = %s ORDER BY joined_at DESC", $email));
    }

    private function get_applicant_by_token($token) {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE magic_token = %s AND (magic_token_expires IS NULL OR magic_token_expires > %s)", $token, current_time('mysql')));
    }

    private function get_applicant($id) {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    }

    private function get_offer_by_token($token) {
        global $wpdb;
        $table = $this->get_table_name('offers');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE offer_token = %s", $token));
    }

    private function get_active_offer($applicant_id) {
        global $wpdb;
        $table = $this->get_table_name('offers');

        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE applicant_id = %d AND status = 'pending'", $applicant_id));
    }

    private function get_next_waiting_applicant() {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        return $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'waiting' ORDER BY joined_at ASC, id ASC LIMIT 1");
    }

    private function get_expired_offers() {
        global $wpdb;
        $table = $this->get_table_name('offers');

        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = 'pending' AND expires_at < %s", current_time('mysql')));
    }

    private function get_waiting_applicants() {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        return $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'waiting' ORDER BY joined_at ASC, id ASC");
    }

    private function get_applicants() {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY joined_at DESC LIMIT 100");
    }

    private function get_waitlist_position($applicant) {
        global $wpdb;
        $table = $this->get_table_name('applicants');

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'waiting' AND (joined_at < %s OR (joined_at = %s AND id <= %d))",
            $applicant->joined_at,
            $applicant->joined_at,
            $applicant->id
        ));

        return max(1, (int) $count);
    }

    private function send_email($to, $subject, $body) {
        if (empty($to)) {
            return;
        }

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($to, $subject, nl2br($body), $headers);
    }

    private function get_notification_recipients($setting_key) {
        $settings = $this->get_settings();
        $recipients = $this->parse_recipient_list($settings[$setting_key] ?? '');

        $recipients = array_filter(array_unique(array_map('sanitize_email', $recipients)));

        return $recipients;
    }

    private function parse_recipient_list($value) {
        $entries = preg_split('/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!$entries) {
            return array();
        }

        return array_values(array_filter($entries));
    }

    private function generate_token() {
        return wp_generate_password(32, false, false);
    }

    private function set_flash_message($message) {
        if (!session_id()) {
            session_start();
        }

        $_SESSION['waitpress_messages'][] = $message;
    }

    private function get_flash_messages() {
        if (!session_id()) {
            session_start();
        }

        $messages = $_SESSION['waitpress_messages'] ?? array();
        unset($_SESSION['waitpress_messages']);

        return $messages;
    }

    private function set_flash_flag($flag) {
        if (!session_id()) {
            session_start();
        }

        $_SESSION['waitpress_flags'][$flag] = true;
    }

    private function get_flash_flag($flag) {
        if (!session_id()) {
            session_start();
        }

        $flags = $_SESSION['waitpress_flags'] ?? array();
        $value = !empty($flags[$flag]);
        unset($flags[$flag]);
        $_SESSION['waitpress_flags'] = $flags;

        return $value;
    }

    private function get_status_page_url() {
        $page_id = (int) get_option('waitpress_status_page_id');
        if ($page_id) {
            $url = get_permalink($page_id);
            if ($url) {
                return $url;
            }
        }

        return home_url('/');
    }

    private function get_current_url() {
        return esc_url_raw((is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    }

    private function interpolate_template($template, $data) {
        foreach ($data as $key => $value) {
            $template = str_replace('{{' . $key . '}}', $value, $template);
        }

        return $template;
    }

    private function ensure_page($slug, $title, $content) {
        $page = get_page_by_path($slug);
        if ($page) {
            return $page->ID;
        }

        return wp_insert_post(array(
            'post_title' => $title,
            'post_name' => $slug,
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => $content,
        ));
    }

    private function format_status_label($status) {
        $labels = array(
            'waiting' => __('Waiting', 'waitpress'),
            'offered' => __('Offered', 'waitpress'),
            'accepted' => __('Accepted', 'waitpress'),
            'removed' => __('Removed', 'waitpress'),
            'left_waitlist' => __('Left waitlist', 'waitpress'),
            'assigned' => __('Assigned', 'waitpress'),
            'declined' => __('Declined', 'waitpress'),
            'expired' => __('Expired', 'waitpress'),
        );

        return $labels[$status] ?? $status;
    }

    private function time_activation_step($label, $callback) {
        $step_start = microtime(true);
        $this->log_activation_step(sprintf('%s started', $label));

        call_user_func($callback);

        $elapsed = microtime(true) - $step_start;
        $this->log_activation_step(sprintf('%s completed in %.3f seconds', $label, $elapsed));
    }

    private function log_activation_step($message) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log(sprintf('[waitpress][activation] %s', $message));
    }
}
