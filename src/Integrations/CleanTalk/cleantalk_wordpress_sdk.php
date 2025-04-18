<?php

/**
 * DO NOT FORGET TO USE YOUR OWN NAMESPACE
 */
namespace CleanTalkPPressSDK;

defined('ABSPATH') || exit;

/**
 * CleanTalk WordPress SDK for spam protection
 *
 * Provides integration with CleanTalk's anti-spam services including:
 * - API key management
 * - Spam checking
 * - Bot detection
 */
class CleanTalkSDK
{
    /**
     * SDK identifier name
     */
    const SDK_NAME = 'cleantalk_wordpress_sdk';

    /**
     * Current SDK version
     */
    const SDK_VERSION = '0.1.1';

    /**
     * WordPress option name for storing the access key
     */
    const STORAGE_ACCES_KEY_NAME = 'cleantalk_wordpress_sdk_key';

    /**
     * URL for CleanTalk's bot detector script
     */
    const BOT_DETECTOR_SCRIPT_URL = 'https://moderate.cleantalk.org/ct-bot-detector-wrapper.js';

    /**
     * CleanTalk API access key
     * @var string
     */
    private $access_key = '';

    /**
     * Message data transfer object
     * @var CleantalkMessageSDK
     */
    public $cleantalk_message;

    /**
     * Response data transfer object
     * @var CleantalkResponseSDK
     */
    public $cleantalk_response;

    /**
     * Vendor agent
     * @var string
     */
    protected $vendor_agent;

    /**
     * Constructor
     *
     * @param string|null $access_key CleanTalk API access key
     * @param bool $load_scripts Whether to load public scripts
     */
    public function __construct($vendor_agent_prefix, $access_key = null, $load_scripts = true)
    {
        add_action('wp_ajax_apbct_sdk_key_form', array($this, 'sync'));
        $load_scripts && $this->wpLoadPublicScripts();
        $this->cleantalk_response = new CleantalkResponseSDK();
        $vendor_agent_prefix      = is_string($vendor_agent_prefix) ? $vendor_agent_prefix : 'unknown_vendor';
        $this->vendor_agent       = $vendor_agent_prefix . '_sdk';
        $this->setAccessKey($access_key, ! empty($access_key));
    }

    /**
     * Load public scripts if conditions are met
     */
    public function wpLoadPublicScripts()
    {
        if ( static::storageGetAccessKey() && ! static::isCleantalkPluginActive() ) {
            add_action('wp_head', array($this, 'printPublicScriptBotDetector'));
        }
    }

    /**
     * Set the access key for this instance, if second arg is true, this will save the key to storage
     *
     * @param string|null $access_key CleanTalk API access key
     * @param bool $save_key_to_storage
     */
    public function setAccessKey($access_key, $save_key_to_storage = true)
    {
        if ( is_string($access_key) && ! empty($access_key) ) {
            $this->access_key = $access_key;
            $save_key_to_storage && self::storageUpdateAccessKey($access_key);
        }
    }

    /**
     * Synchronize and validate the access key with CleanTalk servers
     *
     * @param string|null $input_key Key to validate
     * @param bool $direct_call Whether this is a direct call (not AJAX)
     * @param bool $save_key_to_storage Do save key
     *
     * @return array|void Returns array if direct_call, otherwise sends JSON response
     */
    public function sync($input_key = null, $direct_call = false, $save_key_to_storage = true)
    {
        if ( empty($input_key) ) {
            $key = isset($_POST[static::STORAGE_ACCES_KEY_NAME]) && is_string($_POST[static::STORAGE_ACCES_KEY_NAME])
                ? sanitize_text_field($_POST[static::STORAGE_ACCES_KEY_NAME])
                : '';
        } else {
            $key = $input_key;
        }

        $result = [
            'success' => false,
            'message' => '',
        ];

        if ( empty($key) ) {
            $save_key_to_storage && self::storageUpdateAccessKey('');
            $result['success'] = ! $direct_call;
            $result['message'] = 'key is empty';

            return self::formatSyncResult($result, $direct_call);
        }

        try {
            if (
                isset($_POST['apbct_sdk_key_form_nonce'])
                && is_string($_POST['apbct_sdk_key_form_nonce'])
                && ! wp_verify_nonce($_POST['apbct_sdk_key_form_nonce'], 'apbct_sdk_key_form')
            ) {
                throw new \Exception('nonce is not valid');
            }

            $response = wp_remote_post('https://api.cleantalk.org/', array(
                'body' => array(
                    'method_name' => 'notice_paid_till',
                    'auth_key'    => $key,
                ),
            ));

            if ( is_wp_error($response) || ! $response ) {
                throw new \Exception(wp_remote_retrieve_response_message($response));
            }

            $body = wp_remote_retrieve_body($response);
            if ( empty($body) ) {
                throw new \Exception('content not found');
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ( $response_code >= 400 ) {
                $msg = '';
                if ( $response instanceof \WP_Error ) {
                    $msg = $response->get_error_message();
                } elseif ( is_object($response) && isset($response->detail) ) {
                    $msg = $response->detail;
                }
                throw new \Exception('response code error: ' . $response_code . ' - ' . $msg);
            }

            $response = json_decode($body);
            if ( is_null($response) ) {
                throw new \Exception('decoded response null');
            }

            if ( isset($response->data->valid) && $response->data->valid == 0 ) {
                throw new \Exception('key is not valid');
            }
        } catch (\Exception $e) {
            $result['message'] = $e->getMessage();

            return self::formatSyncResult($result, $direct_call);
        }

        $save_key_to_storage && self::storageUpdateAccessKey($key);

        $result['success'] = true;

        return self::formatSyncResult($result, $direct_call);
    }

    /**
     * Format the synchronization result for response
     *
     * @param array $result Result array with success and message
     * @param bool $direct_call Whether this is a direct call
     *
     * @return array|void Returns array or sends JSON response
     */
    private static function formatSyncResult($result, $direct_call)
    {
        if ( $direct_call ) {
            return $result;
        }
        wp_send_json($result);
    }

    /**
     * Render the API key form HTML
     *
     * @return string HTML for the key form
     */
    protected function renderKeyForm()
    {
        $key            = (string)static::storageGetAccessKey();
        $agitation      = 'CleanTalk is cloud Anti-Spam service which focuses on a background scoring for websites visitors to highlight legitimate visitors and filter spambots.<br>Click here to get your key and start filter spam bots! <a href="https://cleantalk.org/register" target="_blank">https://cleantalk.org/register</a>';
        $agitation      = wp_kses(
            $agitation,
            array('a' => array('href' => array(), 'target' => array()), 'br' => array())
        );
        $key_is_ok_desc = 'Anti-Spam is active, use <a href="https://cleantalk.org/my" target="_blank">Dashboard</a> to tune the service.';
        $key_is_ok_desc = wp_kses($key_is_ok_desc, array('a' => array('href' => array(), 'target' => array())));

        $message = $key ? $key_is_ok_desc : $agitation;

        return '<p><span class="apbct_sdk_description">' . $message . '</span>
        . <form id="apbct_sdk-key-form" method="post">'
               . '<input type="text" name="' . CleanTalkSDK::STORAGE_ACCES_KEY_NAME . '" value="' . $key . '" placeholder="API key"> <input type="submit" value="Save" class="apbct_sdk_submit">'
               . wp_nonce_field(
                   'apbct_sdk_key_form',
                   'apbct_sdk_key_form_nonce'
               ) . '<input type="hidden" name="action" value="apbct_sdk_key_form">'
               . '</form></p>'
               . '<script> jQuery(document).ready(function($) {
            $("form#apbct_sdk-key-form").submit(function(e) {
                e.preventDefault();
                $(".apbct_sdk_submit").attr("disabled","disabled").css("cursor", "wait");
                $.post("' . admin_url('admin-ajax.php') . '", $(this).serialize(), function(response) {
                    $(".apbct_sdk-error").remove();
                    $(".apbct_sdk_submit").removeAttr("disabled").removeAttr("style");
                    if (response.success) {
                        const message = response.message === "key is empty" ? "' . addslashes($agitation) . '" : "' . addslashes($key_is_ok_desc) . '";
                        $("input[name=\'apbct_sdk_description\']").html(message);
                    } else {
                        $("input[name=\'apbct_sdk_description\']").html("' . addslashes($agitation) . '");
                        $("input[name=\'' . static::STORAGE_ACCES_KEY_NAME . '\']").parent().after("<div class=\'error apbct_sdk-error\'>" + response.message + "</div>");
                    }
                });
            });
        });
        </script>';
    }

    /**
     * Print the bot detector script tag
     */
    public static function printPublicScriptBotDetector()
    {
        echo static::getBotDetectorScriptTag();
    }

    /**
     * Get the bot detector script tag HTML
     *
     * @return string Script tag HTML
     */
    public static function getBotDetectorScriptTag()
    {
        return sprintf(
            '<script src="%s" id="ct_bot_detector-js"></script>',
            static::getBotDetectorScriptURL()
        );
    }

    /**
     * Get the URL for the bot detector script
     *
     * @return string Script URL
     */
    public static function getBotDetectorScriptURL()
    {
        return static::BOT_DETECTOR_SCRIPT_URL;
    }

    /**
     * Enqueue the bot detector script in WordPress
     * @psalm-suppress InvalidArgument
     */
    public static function wpEnqueuePublicScriptBotDetector()
    {
        wp_enqueue_script(
            self::SDK_NAME . '--bot-detector',
            static::getBotDetectorScriptURL(),
            [],
            self::SDK_VERSION,
            array(
                'in_footer' => false,
                'strategy'  => 'defer'
            )
        );
    }

    /**
     * Check if data contains spam
     *
     * @param array $data_container Data to check
     *
     * @return bool True if spam detected, false otherwise
     */
    public function isSpam($data_container)
    {
        $response = $this->getCleanTalkResponse($data_container);
        if ( isset($response->allow) && $response->allow == 0 ) {
            return true;
        }

        return false;
    }

    /**
     * Get response from CleanTalk servers for data check
     *
     * @param array $data_container Data to check
     * @param CleantalkMessageSDK|null $custom_ct_message Data to check
     *
     * @return CleantalkResponseSDK Response object
     */
    public function getCleanTalkResponse($data_container, $custom_ct_message = null)
    {
        global $cleantalk_executed;

        try {
            $work_key = ! empty($this->access_key) ? $this->access_key : static::storageGetAccessKey();

            if ( ! $work_key ) {
                $this->cleantalk_response->skip_reason = 'no access key';
                throw new \Exception('Request skipped');
            }

            $skip_reason = apply_filters('cleantalk_sdk_skip_request', '');

            if ( ! empty($skip_reason) ) {
                $this->cleantalk_response->skip_reason = $skip_reason;
                throw new \Exception('Request skipped');
            }

            if ( $cleantalk_executed ) {
                $this->cleantalk_response->skip_reason = 'cleantalk already executed';
                throw new \Exception('Request skipped');
            }

            $params = $custom_ct_message && $custom_ct_message instanceof CleantalkMessageSDK
                ? $custom_ct_message
                : $this->gatherMessage($data_container, $work_key);


            if ( empty($params) ) {
                $this->cleantalk_response->skip_reason = 'empty message';
                throw new \Exception('Bad params');
            }

            if ( empty($params->event_token) ) {
                $this->cleantalk_response->skip_reason = 'event token is empty';
                throw new \Exception('Bad params');
            }

            $response = wp_remote_post('https://moderate.cleantalk.org/api2.0', array(
                'body' => $params->getJson(),
            ));

            if ( is_wp_error($response) ) {
                $this->cleantalk_response->skip_reason = 'wp error occured';
                throw new \Exception('Request failed');
            }

            $body = wp_remote_retrieve_body($response);
            if ( empty($body) ) {
                $this->cleantalk_response->skip_reason = 'empty response body';
                throw new \Exception('Request failed');
            }

            $this->cleantalk_response->data = $body;

            $response_code = wp_remote_retrieve_response_code($response);
            if ( $response_code >= 400 ) {
                $this->cleantalk_response->skip_reason = 'response code >400';
                throw new \Exception('Request failed');
            }

            $response = json_decode($body);
            if ( is_null($response) ) {
                $this->cleantalk_response->skip_reason = 'cannot decode response JSON';
                throw new \Exception('Request failed');
            }

            if ( ! (isset($response->allow, $response->comment)) ) {
                $this->cleantalk_response->skip_reason = 'unknown response format';
                throw new \Exception('Request failed');
            }

            $cleantalk_executed                = true;
            $this->cleantalk_response->allow   = $response->allow;
            $this->cleantalk_response->comment = $response->comment;
            $this->cleantalk_response->success = true;
        } catch (\Exception $e) {
            $this->cleantalk_response->skip_reason = $e->getMessage() . ': ' . $this->cleantalk_response->skip_reason;
        }

        return $this->cleantalk_response;
    }

    /**
     * Gather parameters for CleanTalk API request
     *
     * @param array $data Input data
     * @param string|null $work_key Optional API key
     *
     * @return CleantalkMessageSDK Parameters for API request in JSON format
     */
    public function gatherMessage($data, $work_key)
    {
        $email_pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        $email         = null;

        array_walk_recursive($data, function ($value) use (&$email, $email_pattern) {
            if ( is_string($value) && preg_match($email_pattern, $value, $matches) ) {
                $email = $matches[0];
            }
        });

        if ( function_exists('apache_request_headers') ) {
            $all_headers = array_filter(
                apache_request_headers(),
                function ($value, $key) {
                    return strtolower($key) !== 'cookie';
                },
                ARRAY_FILTER_USE_BOTH
            );
        }

        //message details
        $message = [];

        $message['sender_ip'] = isset($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';

        $message['x_forwarded_for'] = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? $_SERVER['HTTP_X_FORWARDED_FOR']
            : null;

        $message['x_real_ip'] = isset($_SERVER['HTTP_X_REAL_IP'])
            ? $_SERVER['HTTP_X_REAL_IP']
            : null;

        $message['auth_key'] = ! empty($work_key)
            ? $work_key
            : '';

        $message['agent'] = empty($this->vendor_agent)
            ? self::SDK_NAME . '_' . self::SDK_VERSION
            : $this->vendor_agent;

        $message['sender_email'] = $email;

        $message['event_token'] = ! empty($data['ct_bot_detector_event_token'])
            ? $data['ct_bot_detector_event_token']
            : '';

        $message['all_headers'] = ! empty($all_headers)
            ? json_encode($all_headers)
            : '';

        //sender info
        $message['referrer'] = isset($_SERVER['HTTP_REFERER'])
            ? $_SERVER['HTTP_REFERER']
            : '';
        $message['user_agent'] = isset($_SERVER['HTTP_USER_AGENT'])
            ? $_SERVER['HTTP_USER_AGENT']
            : '';
        $message['sdk_version'] = self::SDK_VERSION;

        $this->cleantalk_message = new CleantalkMessageSDK($message);

        return $this->cleantalk_message;
    }

    /**
     * Check if CleanTalk plugin is active
     *
     * @return bool True if plugin active, false otherwise
     */
    public static function isCleantalkPluginActive()
    {
        return defined('APBCT_VERSION');
    }

    /**
     * Update the stored access key
     *
     * @param string $access_key API key to store
     */
    protected static function storageUpdateAccessKey($access_key)
    {
        update_option(static::STORAGE_ACCES_KEY_NAME, $access_key);
    }

    /**
     * Get the stored access key
     *
     * @return string|false The stored key or false if not set
     */
    protected static function storageGetAccessKey()
    {
        $key = get_option(static::STORAGE_ACCES_KEY_NAME, '');
        if ( is_string($key) && ! empty($key) ) {
            return $key;
        }

        return false;
    }
}

/**
 * Data Transfer Object for messages to CleanTalk
 */
class CleantalkMessageSDK
{
    /**
     * Sender IP address
     * @var string
     */
    public $sender_ip = '';
    /**
     * X-Forwarded-For header value
     * @var string
     */
    public $x_forwarded_for = '';
    /**
     * X-Real-IP header value
     * @var string
     */
    public $x_real_ip = '';
    /**
     * API authentication key
     * @var string
     */
    public $auth_key = '';
    /**
     * User agent string
     * @var string
     */
    public $agent = '';
    /**
     * Sender email address
     * @var string
     */
    public $sender_email = '';
    /**
     * Sender nickname
     * @var string
     */
    public $sender_nickname = '';
    /**
     * Event token for bot detection
     * @var string
     */
    public $event_token = '';
    /**
     * All request headers as JSON
     * @var string
     */
    public $all_headers = '';
    /**
     * Sender info. Referrer.
     * @var string
     */
    public $referrer = '';
    /**
     * Sender info. UA.
     * @var string
     */
    public $user_agent = '';
    /**
     * Sender info. Visitor message.
     * @var string
     */
    public $sender_message = '';
    /**
     * JSON encoded string of sender info, including referrer, user agent, and message.
     * @var string
     */
    private $sender_info = '';
    /**
     * @var string SDK version
     */
    private $sdk_version = '';

    /**
     * Constructor - initializes sender_info
     */
    public function __construct($params)
    {
        foreach ( $params as $param_name => $param ) {
            if ( property_exists(static::class, $param_name) ) {
                $type              = gettype($this->$param_name);
                $this->$param_name = $param;
                settype($this->$param_name, $type);
            }
        }
        $this->prepareSenderInfo();
    }

    public function prepareSenderInfo()
    {
        $this->sender_info = json_encode(
            [
                'REFFERRER'  => $this->referrer,
                'user_agent' => $this->user_agent,
                'message' => $this->sender_message,
                'sdk_version' => $this->sdk_version,
            ]
        );
    }

    /**
     * Get all properties as array
     *
     * @return array Message data as associative array
     */
    public function getArray()
    {
        $this->prepareSenderInfo();
        return get_object_vars($this);
    }

    public function getJson()
    {
        $json = json_encode($this->getArray());

        return false !== $json ? $json : '';
    }
}

/**
 * Data Transfer Object for CleanTalk response
 */
class CleantalkResponseSDK
{
    /**
     * Whether to allow the request (1 = allow, 0 = block)
     * @var int
     */
    public $allow = 1;

    /**
     * Response comment/message
     * @var string
     */
    public $comment = 'Not spam';

    /**
     * Raw response data
     * @var string
     */
    public $data = '';

    /**
     * Reason for skipping check
     * @var string
     */
    public $skip_reason = '';

    /**
     * Whether request succeeded
     * @var bool
     */
    public $success = false;
}
