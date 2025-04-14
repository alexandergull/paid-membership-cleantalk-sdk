<?php

namespace ProfilePress\Core\Integrations\CleanTalk;

use CleanTalkPPressSDK\CleanTalkSDK;

require_once 'cleantalk_wordpress_sdk.php';

class CleanTalkService
{

    use Singleton;

    /**
     * @var CleanTalkSDK
     */
    public $sdk;
    private static $vendor_name = 'profile_press_paid_membership_' . PPRESS_VERSION_NUMBER;

    /**
     * CleanTalkService Singleton constructor.
     * @return void
     */
    public function init()
    {
        $this->sdk = new CleanTalkSDK(static::$vendor_name, null, false);
        add_filter('ppress_cleantalk_settings_page', array($this, 'hookDrawSettingsPage'));
        add_action('ppress_before_registration', array($this, 'hookPPressBeforeRegistration'), 10, 2);
        add_action('wp_cspa_validate_data', array($this, 'hookSaveSettingsPage'), 10, 1);
        add_action('ppress_registration_form', array($this, 'hookRenderRegistrationForm'), 10, 1);
        $pp_cleantalk_key = static::getInstance()::getKeyFromPPressStorage();
        if ($pp_cleantalk_key) {
            static::getInstance()->sdk->setAccessKey($pp_cleantalk_key);
        }
        if (isset($_POST['action']) && $_POST['action'] === 'pp_ajax_signup') {
            remove_action('plugins_loaded', 'ct_ajax_hook');
        }
    }

    /**
     * Hook for ProfilePress settings page.
     * @param array $args
     *
     * @return void
     */
    public function hookSaveSettingsPage(array $args)
    {
        $new_access_key = isset($args['cleantalk_access_key']) ? $args['cleantalk_access_key'] : '';
        $sync_result = static::getInstance()->sdk->sync($new_access_key, true);
        $is_valid = $sync_result['success'] === true;
        if ($sync_result['success'] !== true) {
            add_action('wp_cspa_after_persist_settings', array($this, 'hookSetCleanTalkDisabled'));
        } else {
            add_action('wp_cspa_after_persist_settings', array($this, 'hookSetCleanTalkKeyValid'));
        }
    }

    /**
     * Hook for ProfilePress before registration.
     * Die with formatted JSON if CleanTalk blocks the registration, pass the flow otherwise
     * @param string $form_id
     * @param array $user_data
     *
     * @return void
     */
    public function hookPPressBeforeRegistration(string $form_id, array $user_data): void
    {
        if (!static::getInstance()::isCleanTalkEnabled()) {
            return;
        }

        $custom_cleantalk_message = static::getInstance()->sdk->gatherMessage($_POST, static::getKeyFromPPressStorage());

        $nickname = !empty($user_data['nickname']) ? $user_data['nickname'] : '';
        if (!empty($user_data['first_name']) && is_string($user_data['first_name'])) {
            $nickname .= ' ' . $user_data['first_name'];
        }
        if (!empty($user_data['last_name']) && is_string($user_data['last_name'])) {
            $nickname .= ' ' . $user_data['last_name'];
        }
        $custom_cleantalk_message->sender_nickname = trim($nickname);

        $custom_cleantalk_message->sender_message = isset($user_data['display_name'])
            ? $user_data['display_name']
            : '';

        $response = static::getInstance()->sdk->getCleanTalkResponse($_POST, $custom_cleantalk_message);

        if ($response->allow == 0) {
            wp_send_json(static::preparePPFormResponse($response->comment));
        }
    }

    /**
     * Hook for ProfilePress settings page.
     * @param array $args
     *
     * @return array
     */
    public static function hookDrawSettingsPage(array $args)
    {
        $enabled = static::isCleanTalkEnabled();
        $key_is_valid = static::isCleanTalkKeyValid();
        $current_key = static::getKeyFromPPressStorage();
        $description = __('CleanTalk access key is valid, ready to protect.');
        if (!$key_is_valid) {
            $description = __('CleanTalk access key is invalid');
        }
        if (!$enabled) {
            $description = __('CleanTalk Integration is disabled.');
        }
        if (empty($current_key)) {
            $description = __('CleanTalk access key is empty.');
        }
        $args['cleantalk_access_key']['description'] = $description;
        return $args;
    }

    /**
     * Hook for ProfilePress registration form.
     * Add CleanTalk JS script tag to the form to detect bots.
     * @param string $args
     *
     * @return string
     */
    public function hookRenderRegistrationForm(string $args)
    {
        if (!static::getInstance()->sdk::isCleantalkPluginActive()) {
            return $args . static::getInstance()->sdk::getBotDetectorScriptTag();
        }
        return $args;
    }

    /**
     * Set CleanTalk Service disabled
     * @return void
     */
    public static function hookSetCleanTalkDisabled(): void
    {
        $pp_options = get_option('ppress_settings_data');
        $pp_options['cleantalk_enabled'] = 'no';
        update_option('ppress_settings_data', $pp_options);
        static::hookSetCleanTalkKeyValid(false);
    }

    /**
     * Get CleanTalk access key from ProfilePress storage
     * @return false|string
     */
    private static function getKeyFromPPressStorage()
    {
        $pp_options = get_option('ppress_settings_data');
        return !empty($pp_options['cleantalk_access_key']) && is_string($pp_options['cleantalk_access_key'])
            ? $pp_options['cleantalk_access_key']
            : false;
    }

    /**
     * Check if CleanTalk Service is enabled
     * @return bool
     */
    private static function isCleanTalkEnabled(): bool
    {
        $pp_options = get_option('ppress_settings_data');
        return !empty($pp_options['cleantalk_enabled']) && $pp_options['cleantalk_enabled'] === 'yes';
    }

    /**
     * Set CleanTalk key state.
     * This is used to set the state of the CleanTalk key in the ProfilePress settings.
     * If called with a non-boolean value (hook provides array by default), it will default to true.
     * @param mixed $state
     * @return void
     */
    public static function hookSetCleanTalkKeyValid($state): void
    {
        if (!is_bool($state)) {
            $state = true;
        }
        $pp_options = get_option('ppress_settings_data');
        $pp_options['cleantalk_key_valid'] = $state;
        update_option('ppress_settings_data', $pp_options);
    }

    /**
     * Get CleanTalk key state
     * @return bool
     */
    private static function isCleanTalkKeyValid(): bool
    {
        $pp_options = get_option('ppress_settings_data');
        return isset($pp_options['cleantalk_key_valid']) && $pp_options['cleantalk_key_valid'] === true;
    }

    /**
     * Prepare the response for ProfilePress registration form
     * @param string $cleantalk_comment
     *
     * @return array
     */
    private static function preparePPFormResponse(string $cleantalk_comment): array
    {
        $comment = sprintf('<div class="profilepress-reg-status">%s</div>', $cleantalk_comment);
        return array('message' => $comment);
    }
}
