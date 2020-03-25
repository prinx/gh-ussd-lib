<?php
/*
 * (c) Nuna Akpaglo <princedorcis@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Prinx\USSD;

require_once 'constants.php';
require_once 'Utils.php';
require_once 'Validator.php';
require_once 'Session.php';

// if (ENV === 'dev') {
header('Access-Control-Allow-Origin: *');
// }

class USSD
{
    protected $session;

    protected $session_data = [];

    protected $validator;

    protected $menu_manager;
    protected $menus;
    protected $menu_ask_user_before_reload_last_session = [
        ASK_USER_BEFORE_RELOAD_LAST_SESSION => [
            'message' => 'Do you want to continue from where you left?',
            'actions' => [
                '1' => [
                    ITEM_MSG => 'Continue last session',
                    ITEM_ACTION => USSD_CONTINUE_LAST_SESSION,
                ],
                '2' => [
                    ITEM_MSG => 'Restart',
                    ITEM_ACTION => USSD_WELCOME,
                ],
            ],
        ],
    ];

    protected $ussd_params = [];
    protected $custom_ussd_request_type;

    protected $app_params = [
        'id' => '',
        'environment' => DEV,
        'back_action_thrower' => '0',
        'back_action_display' => 'Back',
        'splitted_menu_next_thrower' => '99',
        'splitted_menu_display' => 'More',
        'default_end_msg' => 'Goodbye',

        /**
         * Use by the Session instance to know if it must start a new
         * session or use the user previous session, if any.
         */
        'always_start_new_session' => true,

        /**
         * This property has no effect when "always_start_new_session" is false
         */
        'ask_user_before_reload_last_session' => false,
        'always_send_sms' => false,
        'sms_sender_name' => '',
        'sms_endpoint' => '',
        'default_error_msg' => 'Invalid input',
    ];

    protected $error = '';

    protected $current_menu_splitted = false;
    protected $current_menu_split_index = 0;
    protected $current_menu_split_start = false;
    protected $current_menu_split_end = false;

    protected $max_ussd_page_content = 147;
    protected $max_ussd_page_lines = 10;

    public function __construct()
    {
        $this->validator = Validator::class;
    }

    public function run($menu_manager)
    {
        $this->validator::validate_ussd_params($_POST);
        $this->validator::validate_menu_manager($menu_manager);

        $this->hydrate($menu_manager, $_POST);

        $this->session = new Session($this);
        $this->session_data = $this->session->data();

        if (
            $this->ussd_request_type() === USSD_REQUEST_INIT &&
            $this->session->is_previous()
        ) {
            $this->prepare_to_launch_from_previous_session_state();
        }

        $this->process_user_request();
    }

    protected function hydrate($menu_manager, $ussd_params)
    {
        $this->menu_manager = $menu_manager;
        $this->hydrate_menus($menu_manager->menus());
        $this->hydrate_app_params($menu_manager->app_params());
        $this->hydrate_ussd_params($ussd_params);
    }

    public function prepare_to_launch_from_previous_session_state()
    {
        if (
            $this->app_params['ask_user_before_reload_last_session'] &&
            !empty($this->session_data) &&
            $this->session_data['current_menu_id'] !== WELCOME_MENU_NAME
        ) {
            $this->set_custom_ussd_request_type(USSD_REQUEST_ASK_USER_BEFORE_RELOAD_LAST_SESSION);
        } else {
            $this->set_custom_ussd_request_type(USSD_REQUEST_RELOAD_LAST_SESSION_DIRECTLY);
        }
    }

    protected function process_user_request()
    {
        switch ($this->ussd_request_type()) {
            case USSD_REQUEST_INIT:
                $this->run_welcome_state();
                break;

            case USSD_REQUEST_USER_SENT_RESPONSE:
                if ($this->ussd_has_switched()) {
                    $this->process_from_remote_ussd();
                } else {
                    $this->process_response($this->current_menu_id());
                }

                break;

            case USSD_REQUEST_ASK_USER_BEFORE_RELOAD_LAST_SESSION:
                $this->run_ask_user_before_reload_last_session_state();
                break;

            case USSD_REQUEST_RELOAD_LAST_SESSION_DIRECTLY:
                $this->run_last_session_state();
                break;

            case USSD_REQUEST_CANCELLED:
                $this->session->delete();
                $this->hard_end('REQUEST CANCELLED');
                break;

            default:
                $this->hard_end('UNKNOWN USSD SERVICE OPERATOR');
                break;
        }
    }

    public function hydrate_ussd_params($ussd_params)
    {
        foreach (USSD_PARAMS_NAMES as $param_name) {
            $this->ussd_params[$param_name] = $this->sanitize_postvar($ussd_params[$param_name]);
        }
    }

    public function hydrate_menus($menus)
    {
        $this->menus = array_merge(
            $menus,
            $this->menu_ask_user_before_reload_last_session
        );
    }

    public function hydrate_app_params($sent_params)
    {
        $this->app_params = array_merge($this->app_params, $sent_params);
    }

    public function sanitize_postvar($var)
    {
        return htmlspecialchars(addslashes(urldecode($var)));
    }

    protected function run_last_session_state()
    {
        // current_menu_id has been retrieved from the last state
        $this->run_state($this->current_menu_id());
    }

    public function current_menu_id()
    {
        if (!isset($this->session_data['current_menu_id'])) {
            return '';
        }

        return $this->session_data['current_menu_id'];
    }

    protected function set_current_menu_id($id)
    {
        $this->session_data['current_menu_id'] = $id;

        return $this;
    }

    protected function run_ask_user_before_reload_last_session_state()
    {
        $this->run_state(ASK_USER_BEFORE_RELOAD_LAST_SESSION);
    }

    protected function process_response($page_id)
    {
        /**
         * Do not use empty() to check the user response. The expected response
         * can for e.g. be 0 (zero), which empty() sees like empty.
         */
        if ($this->user_response() === '') {
            $this->run_invalid_input_state('Empty response not allowed');
            return;
        }

        $user_response = $this->user_response();

        $particular_item_action_defined_by_developer =
        isset($this->menus[$page_id][ACTIONS][$user_response]) &&
        isset($this->menus[$page_id][ACTIONS][$user_response][ITEM_ACTION]);

        $next_menu_id = $this->get_next_menu_id(
            $user_response,
            $page_id,
            $particular_item_action_defined_by_developer
        );

        if ($next_menu_id === false || !$this->menu_state_exists($next_menu_id)) {
            $this->run_invalid_input_state('Action not defined');
            return;
        }

        $user_response_validated = $this->validate_user_response(
            $user_response,
            $page_id,
            $next_menu_id,
            $particular_item_action_defined_by_developer
        );

        if (!$user_response_validated) {
            $this->run_invalid_input_state();
            return;
        }

        $this->save_user_response($user_response);

        $this->run_after_menu_function(
            $user_response,
            $page_id,
            $next_menu_id,
            $particular_item_action_defined_by_developer
        );

        /**
         * If the next_menu_id is an url then we switch to that USSD
         * application.
         * For this application to retake control, consider switching back
         * from the remote application to this application.
         * But it is only possible if the remote application is using
         * this ussd library or implements a method of switching to another
         * ussd.
         * For the "switching back" ability to work properly both the parameters
         * "always_start_new_session" and "ask_user_before_reload_last_session"
         * have to be set to false.
         */
        if (URLUtils::is_url($next_menu_id)) {
            $this->session_data['switched_ussd_endpoint'] = $next_menu_id;
            $this->session_data['ussd_has_switched'] = true;

            $this->session->save($this->session_data);

            $this->set_ussd_request_type(USSD_REQUEST_INIT);

            return $this->process_from_remote_ussd($next_menu_id);
        }

        switch ($next_menu_id) {
            case USSD_WELCOME:
                $this->run_welcome_state();
                break;

            case USSD_CONTINUE_LAST_SESSION:
                $this->set_current_menu_id($this->back_history_pop());
                $this->run_last_session_state();
                break;

            case USSD_BACK:
                $this->run_previous_state();
                break;

            case USSD_SPLITTED_MENU_NEXT:
                $this->run_same_state_next_page();
                break;

            case USSD_SAME:
                $this->run_same_state();
                break;

            case USSD_END:
                $this->hard_end();
                break;

            default:
                $this->run_state($next_menu_id);
                break;
        }
    }

    protected function save_user_response($user_response)
    {
        $id = $this->current_menu_id();
        $to_save = $user_response;

        if (isset($this->menus[$id][ACTIONS][$user_response][SAVE_RESPONSE_AS])) {
            $to_save = $this->menus[$id][ACTIONS][$user_response][SAVE_RESPONSE_AS];
        }

        $this->user_previous_responses_add($to_save);
    }

    protected function get_next_menu_id(
        $user_response,
        $page_id,
        $particular_item_action_defined_by_developer
    ) {
        if ($particular_item_action_defined_by_developer) {
            return $this->menus[$page_id][ACTIONS][$user_response][ITEM_ACTION];

        } elseif (
            isset($this->session_data['current_menu_splitted']) &&
            $this->session_data['current_menu_splitted'] &&
            isset($this->session_data['current_menu_split_end']) &&
            !$this->session_data['current_menu_split_end'] &&
            $user_response === $this->app_params['splitted_menu_next_thrower']
        ) {
            return USSD_SPLITTED_MENU_NEXT;

        } elseif (
            isset($this->session_data['current_menu_splitted']) &&
            $this->session_data['current_menu_splitted'] &&
            isset($this->session_data['current_menu_split_start']) &&
            !$this->session_data['current_menu_split_start'] &&
            $user_response === $this->app_params['back_action_thrower']
        ) {
            return USSD_BACK;

        } elseif (isset($this->menus[$page_id][ACTIONS][DEFAULT_MENU_ACTION])) {
            return $this->menus[$page_id][ACTIONS][DEFAULT_MENU_ACTION];

        } else {
            return false;
        }
    }

    protected function validate_user_response(
        $user_response,
        $page_id,
        $next_menu_id,
        $particular_item_action_defined_by_developer
    ) {
        $validate_function = 'validate_' . $page_id;

        if (
            method_exists($this->menu_manager, $validate_function) &&
            !(
                $particular_item_action_defined_by_developer &&
                in_array($next_menu_id, USSD_APP_ACTIONS, true)
            )
        ) {

            $validation = call_user_func(
                [$this->menu_manager, $validate_function],
                $user_response, $this->user_previous_responses()
            );

            if (!is_bool($validation)) {
                exit('The function `' . $validate_function . '` must return a boolean.');
            }

            return $validation;
        }

        return true;
    }

    protected function run_after_menu_function(
        $user_response,
        $page_id,
        $next_menu_id,
        $particular_item_action_defined_by_developer
    ) {

        /**
         * The "after_" method does not have to be called if the response
         * expected has been defined by the developper and is an app action
         * (e.g. in the case of the USSD_BACK action, the response defined by
         * developper could be 98. If the user provide 98 we don't need to call
         * the "after_" method). This is to allow the developer to use the
         * "after_" method just for checking the user's response that leads to
         * his (the developer) other menu. The library takes care of the app
         * actions.
         */
        $call_after = 'after_' . $page_id;
        if (
            method_exists($this->menu_manager, $call_after) &&
            !(
                $particular_item_action_defined_by_developer &&
                in_array($next_menu_id, USSD_APP_ACTIONS, true)
            )
        ) {
            call_user_func(
                [$this->menu_manager, $call_after],
                $user_response, $this->user_previous_responses()
            );
        }
    }

    public function menu_state_exists($id)
    {
        return $id !== '' && (isset($this->menus[$id]) || in_array($id, USSD_APP_ACTIONS, true));
    }

    protected function process_from_remote_ussd($endpoint = '')
    {
        $endpoint = $endpoint ? $endpoint : $this->switched_ussd_endpoint();

        $resJSON = HTTPUtils::post($this->ussd_params, $endpoint);

        $this->send_remote_response($resJSON);
    }

    protected function get_splitted_menu_action_next()
    {
        return $this->app_params['splitted_menu_next_thrower'] . ". " .
        $this->app_params['splitted_menu_display'];
    }

    protected function get_splitted_menu_action_back()
    {
        return $this->app_params['back_action_thrower'] . ". " .
        $this->app_params['back_action_display'];
    }

    protected function get_split_menu_string_next()
    {
        $index = $this->session_data['current_menu_split_index'] + 1;
        return $this->get_split_menu_string_at($index);
    }

    protected function get_split_menu_string_back()
    {
        $index = $this->session_data['current_menu_split_index'] - 1;
        return $this->get_split_menu_string_at($index);
    }

    protected function get_split_menu_string_at($index)
    {
        if ($index < 0) {
            exit('Error: Splitted menu does not have page back page. This might not normally happen! Review the code.');
        } elseif (!isset($this->session_data['current_menu_chunks'][$index])) {
            exit('Splitted menu does not have any next page.');
        }

        $begining = 0;
        $end = count($this->session_data['current_menu_chunks']) - 1;

        switch ($index) {
            case $begining:
                $this->session_data['current_menu_split_start'] = true;
                $this->session_data['current_menu_split_end'] = false;
                break;

            case $end:
                $this->session_data['current_menu_split_start'] = false;
                $this->session_data['current_menu_split_end'] = true;
                break;

            default:
                $this->session_data['current_menu_split_start'] = false;
                $this->session_data['current_menu_split_end'] = false;
                break;
        }

        $this->session_data['current_menu_split_index'] = $index;

        return $this->session_data['current_menu_chunks'][$index];
    }

    protected function get_menu_string($menu_array, $menu_msg = '', $has_back_action = false)
    {
        $menu_string = $this->menu_to_string($menu_array, $menu_msg);

        $menu_string_chunks = explode("\n", $menu_string);

        $lines_count = count($menu_string_chunks);

        if (
            strlen($menu_string) > $this->max_ussd_page_content ||
            $lines_count > $this->max_ussd_page_lines
        ) {
            $menu_chunks = [];
            $first = 0;
            $last = $lines_count - 1;

            $current_string_without_split_menu = '';

            $splitted_menu_next = $this->get_splitted_menu_action_next();
            $splitted_menu_back = $this->get_splitted_menu_action_back();

            foreach (
                $menu_string_chunks as $menu_item_number => $menu_item_str
            ) {
                $split_menu = '';

                if ($menu_item_number === $first || !isset($menu_chunks[0])) {
                    $split_menu = $splitted_menu_next;

                    if ($has_back_action) {
                        $split_menu .= "\n" . $splitted_menu_back;
                    }
                } elseif ($menu_item_number === $last && !$has_back_action) {
                    $split_menu = $splitted_menu_back;
                } elseif ($menu_item_number !== $last) {
                    $split_menu = $splitted_menu_next . "\n" . $splitted_menu_back;
                }

                $new_line = $menu_item_str;
                $new_line_with_split_menu = $menu_item_str . "\n" . $split_menu;
                if (
                    strlen($new_line_with_split_menu) > $this->max_ussd_page_content ||
                    count(explode("\n", $new_line_with_split_menu)) > $this->max_ussd_page_lines
                ) {
                    $max = $this->max_ussd_page_content - strlen("\n" . $splitted_menu_next . "\n" . $splitted_menu_back);
                    exit('The text "' . $menu_item_str . '" is too large to be displayed. Consider breaking it in pieces with the newline character (\n). Each piece must not exceed ' . $max . ' characters.');
                }

                /**
                 * The order is important here. (setting
                 * current_string_with_split_menu before
                 * current_string_without_split_menu)
                 */
                $current_string_with_split_menu = $current_string_without_split_menu . "\n" . $new_line_with_split_menu;

                $current_string_without_split_menu .= "\n" . $new_line;

                $next = $menu_item_number + 1;
                $next_string_with_split_menu = '';

                if ($next < $last) {
                    $next_line = "\n" . $menu_string_chunks[$next];

                    if (!isset($menu_chunks[0])) {
                        $split_menu = "\n" . $splitted_menu_next;
                    } else {
                        $split_menu = "\n" . $splitted_menu_next . "\n" . $splitted_menu_back;
                    }

                    $next_string_with_split_menu = $current_string_without_split_menu . $next_line . $split_menu;
                } else {
                    $next_line = "\n" . $menu_string_chunks[$last];
                    $split_menu = $has_back_action ? '' : "\n" . $splitted_menu_back;

                    $next_string_with_split_menu = $current_string_without_split_menu . $next_line . $split_menu;
                }

                if (
                    strlen($next_string_with_split_menu) >= $this->max_ussd_page_content ||
                    count(explode("\n", $next_string_with_split_menu)) >= $this->max_ussd_page_lines ||
                    $menu_item_number === $last
                ) {
                    $menu_chunks[] = trim($current_string_with_split_menu);
                    $current_string_with_split_menu = '';
                    $current_string_without_split_menu = '';
                }
            }

            $this->session_data['current_menu_splitted'] = true;
            $this->session_data['current_menu_split_index'] = 0;
            $this->session_data['current_menu_split_start'] = true;
            $this->session_data['current_menu_split_end'] = false;

            $this->session_data['current_menu_chunks'] = $menu_chunks;
            $this->session_data['current_menu_has_back_action'] = $has_back_action;

            $menu_string = $menu_chunks[0];
        } else {
            $this->session_data['current_menu_splitted'] = false;
        }

        return $menu_string;
    }

    protected function menu_to_string($menu_array, $menu_msg = '')
    {
        $menu_string = $menu_msg . "\n\n";

        foreach ($menu_array as $menu_item_number => $menu_item_str) {
            $menu_string .= "$menu_item_number. $menu_item_str\n";
        }

        return trim($menu_string);
    }

    protected function format_response($message, $request_type)
    {
        $fields = array(
            'message' => trim($message),
            'ussdServiceOp' => $request_type,
            'sessionID' => $this->session_id(),
        );

        return json_encode($fields);
    }

    protected function send_response($message, $ussd_request_type = USSD_REQUEST_ASK_USER_RESPONSE, $hard = false)
    {
        /**
         * Sometimes, we need to send the response to the user and do
         * another staff before ending the script. Those times, we just
         * need to echo the response. That is the soft response snding.
         * Sometimes we need to terminate the script immediately when sending
         * the response; for exemple when the developer himself will call the
         * end function from his code.
         */
        if ($hard) {
            exit($this->format_response($message, $ussd_request_type));
        } else {
            echo $this->format_response($message, $ussd_request_type);
        }
    }

    protected function send_final_response($message, $hard = false)
    {
        $this->send_response($message, USSD_REQUEST_END, $hard);
    }

    public function hard_end($message = '')
    {
        $this->end($message);
    }

    public function soft_end($message = '')
    {
        $this->end($message, false);
    }

    protected function send_remote_response($resJSON)
    {
        $response = json_decode($resJSON, true);

        /**
         * Important! To notify the developer that the error occured at
         * the remote ussd side and not at this ussd switch side.
         */
        if (!is_array($response)) {
            echo "ERROR OCCURED AT THE REMOTE USSD SIDE:  " . $resJSON;
            return;
        }

        echo $resJSON;
    }

    public function switched_ussd_endpoint()
    {
        if (isset($this->session_data['switched_ussd_endpoint'])) {
            return $this->session_data['switched_ussd_endpoint'];
        }

        return '';
    }

    public function ussd_has_switched()
    {
        return isset($this->session_data['ussd_has_switched']) ? $this->session_data['ussd_has_switched'] : false;
    }

    protected function run_same_state_next_page()
    {
        $this->user_previous_responses_pop($this->current_menu_id());

        $this->run_next_state(USSD_SPLITTED_MENU_NEXT);
    }

    protected function get_error_if_exists($menu_id)
    {
        $error = '';

        if ($this->error() && $menu_id === $this->current_menu_id()) {
            $error = $this->error();
        }

        return $error;
    }

    protected function call_before_hook($menu_id)
    {
        $result_call_before = '';

        $call_before = 'before_' . $menu_id;

        if (method_exists($this->menu_manager, $call_before)) {
            $result_call_before = call_user_func(
                [$this->menu_manager, $call_before],
                $this->user_previous_responses()
            );
        }

        if (isset($this->menus[$menu_id][MSG])) {
            if (
                !is_string($result_call_before) &&
                !is_array($result_call_before)
            ) {
                exit("STRING OR ARRAY EXPECTED.\nThe function '" . $call_before . "' must return either a string or an associative array. If it returns a string, the string will be appended to the message of the menu. If it return an array, the library will parse the menu message and replace all words that are in the form :indexofthearray: by the value associated in the array. Check the documentation to learn more on how to use 'before_' functions.");
            }
        } else {
            if (!is_string($result_call_before)) {
                exit("STRING EXPECTED.\nThe function '" . $call_before . "' must return a string if the menu itself does not have any message. Check the documentation to learn more on how to use 'before_' functions.");
            }
        }

        return $result_call_before;
    }

    protected function run_state($next_menu_id)
    {
        $msg = $this->get_error_if_exists($next_menu_id);

        $result_call_before = $this->call_before_hook($next_menu_id);

        if (isset($this->menus[$next_menu_id][MSG])) {
            $menu_msg = $this->menus[$next_menu_id][MSG];

            if (is_string($result_call_before)) {
                if (empty($menu_msg)) {
                    $menu_msg = $result_call_before;
                } else {
                    $menu_msg = $result_call_before ? $result_call_before . "\n" . $menu_msg : $menu_msg;
                }
            } elseif (is_array($result_call_before)) {
                foreach ($result_call_before as $pattern_name => $value) {
                    $pattern = '/' . MENU_MSG_PLACEHOLDER . $pattern_name . MENU_MSG_PLACEHOLDER . '/';
                    $menu_msg = preg_replace($pattern, $value, $menu_msg);
                }
            }

            $msg .= $msg ? "\n" . $menu_msg : $menu_msg;
        } else {
            if (empty($msg)) {
                $msg = $result_call_before;

            } else {
                $msg = $result_call_before ? $result_call_before . "\n" . $msg : $msg;
            }
        }

        /**
         * This is used only in the case of a splitted menu,
         * to know if we have to add a back action or not.
         */
        $has_back_action = false;

        $menu_array = [];
        if (isset($this->menus[$next_menu_id][ACTIONS])) {
            $menu = $this->menus[$next_menu_id][ACTIONS];

            foreach ($menu as $index => $value) {
                if ($index !== DEFAULT_MENU_ACTION) {
                    $menu_array[$index] = $value[ITEM_MSG];

                    if (
                        !$has_back_action &&
                        isset($value[ITEM_ACTION]) &&
                        $value[ITEM_ACTION] === USSD_BACK
                    ) {
                        $has_back_action = true;
                    }
                }
            }

            $this->run_next_state($next_menu_id, $msg, $menu_array, $has_back_action);
        } else {
            $this->run_last_state($msg, $menu_array);
        }
    }

    protected function run_welcome_state()
    {
        $this->run_state(WELCOME_MENU_NAME);
    }

    protected function run_next_state(
        $next_menu_id, $msg = '',
        $menu_array = [],
        $has_back_action = false
    ) {
        $menu_string = '';

        if ($next_menu_id === USSD_SPLITTED_MENU_NEXT) {
            $menu_string = $this->get_split_menu_string_next();
            $has_back_action = $this->session_data['current_menu_has_back_action'];

        } elseif ($next_menu_id === USSD_SPLITTED_MENU_BACK) {
            $menu_string = $this->get_split_menu_string_back();
            $has_back_action = $this->session_data['current_menu_has_back_action'];

        } else {
            // $menu_string = $this->menu_to_string($menu_array, $msg, $has_back_action);
            $menu_string = $this->get_menu_string($menu_array, $msg, $has_back_action);
        }

        $this->send_response($menu_string);

        if (
            $next_menu_id !== USSD_SPLITTED_MENU_NEXT &&
            $next_menu_id !== USSD_SPLITTED_MENU_BACK
        ) {
            if (
                $this->current_menu_id() &&
                $this->current_menu_id() !== WELCOME_MENU_NAME &&
                $next_menu_id !== ASK_USER_BEFORE_RELOAD_LAST_SESSION &&
                !empty($this->back_history()) &&
                $next_menu_id === $this->get_previous_menu_id()
            ) {
                $this->back_history_pop();

            } elseif ($this->current_menu_id() &&
                $next_menu_id !== $this->current_menu_id() &&
                $this->current_menu_id() !== ASK_USER_BEFORE_RELOAD_LAST_SESSION) {
                $this->back_history_push($this->current_menu_id());
            }

            $this->set_current_menu_id($next_menu_id);
        }

        $this->session->save($this->session_data);
    }

    protected function run_last_state($msg = '')
    {
        if ($this->app_params['always_send_sms']) {
            $this->send_sms($msg);
        }

        $this->soft_end($msg);
        // $this->session->delete();
    }

    protected function run_previous_state()
    {
        $this->user_previous_responses_pop($this->current_menu_id());

        if (
            isset($this->session_data['current_menu_splitted']) &&
            $this->session_data['current_menu_splitted'] &&
            isset($this->session_data['current_menu_split_index']) &&
            $this->session_data['current_menu_split_index'] > 0
        ) {
            $this->run_next_state(USSD_SPLITTED_MENU_BACK);
        } else {
            $previous_menu_id = $this->get_previous_menu_id();
            $this->user_previous_responses_pop($previous_menu_id);

            $this->run_state($previous_menu_id);
        }
    }

    protected function user_previous_responses_pop($menu_id)
    {
        if ($this->user_previous_responses()) {
            if (
                isset($this->session_data['user_previous_responses'][$menu_id]) &&
                is_array($this->session_data['user_previous_responses'][$menu_id])
            ) {
                return array_pop($this->session_data['user_previous_responses'][$menu_id]);
            }
        }

        return null;
    }

    protected function user_previous_responses_add($response)
    {
        $id = $this->current_menu_id();

        if (
            !isset($this->user_previous_responses()[$id]) ||
            !is_array($this->user_previous_responses()[$id])
        ) {
            $this->session_data['user_previous_responses'][$id] = [];
        }

        $this->session_data['user_previous_responses'][$id][] = $response;
    }

    public function user_previous_responses()
    {
        if (!isset($this->session_data['user_previous_responses'])) {
            return [];
        }

        return $this->session_data['user_previous_responses'];
    }

    protected function run_same_state()
    {
        $this->user_previous_responses_pop($this->current_menu_id());
        $this->run_state($this->current_menu_id());
    }

    protected function run_invalid_input_state($error = '')
    {
        if ($error) {
            $this->set_error($error);
        } else {
            $error = empty($this->error()) ? $this->app_params['default_error_msg'] : $this->error();
            $this->set_error($error);
        }

        $this->run_state($this->current_menu_id());
    }

    protected function end($sentMsg = '', $hard = true)
    {
        $msg = $sentMsg === '' ? $this->app_params['default_end_msg'] : $sentMsg;
        $this->send_final_response($msg, $hard);
    }

    protected function get_previous_menu_id()
    {
        $length = count($this->back_history());

        if (!$length) {
            exit("Can't get a previous menu. 'back_history' is empty.");
        }

        return $this->back_history()[$length - 1];
    }

    public function back_history()
    {
        if (!isset($this->session_data['back_history'])) {
            $this->session_data['back_history'] = [];
        }

        return $this->session_data['back_history'];
    }

    protected function back_history_push($page_id)
    {
        if (!isset($this->session_data['back_history'])) {
            $this->session_data['back_history'] = [];
        }

        return array_push($this->session_data['back_history'], $page_id);
    }

    protected function back_history_pop()
    {
        if (!isset($this->session_data['back_history'])) {
            $this->session_data['back_history'] = [];
        }

        return array_pop($this->session_data['back_history']);
    }

    public function set_error(string $error = '')
    {
        $this->error = $error;

        return $this;
    }

    public function menu_manager()
    {
        return $this->menu_manager;
    }

    public function app_params()
    {
        return $this->app_params;
    }

    public function id()
    {
        return $this->app_params['id'];
    }

    public function error()
    {
        return $this->error;
    }

    public function msisdn()
    {
        return $this->ussd_params['msisdn'];
    }

    public function network()
    {
        return $this->ussd_params['network'];
    }

    public function session_id()
    {
        return $this->ussd_params['sessionID'];
    }

    public function user_response()
    {
        return $this->ussd_params['ussdString'];
    }

    public function ussd_request_type()
    {
        if ($this->custom_ussd_request_type !== null) {
            return $this->custom_ussd_request_type;
        }

        return $this->ussd_params['ussdServiceOp'];
    }

    protected function set_ussd_request_type($request_type)
    {
        $possible_types = [
            USSD_REQUEST_INIT,
            USSD_REQUEST_END,
            USSD_REQUEST_CANCELLED,
            USSD_REQUEST_ASK_USER_RESPONSE,
            USSD_REQUEST_USER_SENT_RESPONSE,
        ];

        if (!in_array($request_type, $possible_types)) {
            $msg = 'Trying to set a request type but the value provided "' . $request_type . '" is invalid.';
            throw new \Exception($msg);
        }

        $this->ussd_params['ussdServiceOp'] = $request_type;

        return $this;
    }

    protected function set_custom_ussd_request_type($request_type)
    {
        $this->custom_ussd_request_type = $request_type;
    }

    public function send_sms(string $msg)
    {
        SMSUtils::send_sms([
            'message' => $msg,
            'recipient' => $this->msisdn(),
            'sender' => $this->app_params['sms_sender_name'],
            'endpoint' => $this->app_params['sms_endpoint'],
        ]);
    }
}
