<?php
/*
 * (c) Nuna Akpaglo <princedorcis@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Prinx\USSD;

define('USSD_REQUEST_INIT', '1');
define('USSD_REQUEST_END', '17');
define('USSD_REQUEST_CANCELLED', '30');
define('USSD_REQUEST_ASK_USER_RESPONSE', '2');
define('USSD_REQUEST_USER_SENT_RESPONSE', '18');
define('USSD_REQUEST_ASK_USER_BEFORE_RELOAD_LAST_SESSION', '__CUSTOM_REQUEST_TYPE1');
define('USSD_REQUEST_RELOAD_LAST_SESSION_DIRECTLY', '__CUSTOM_REQUEST_TYPE2');

define('MSG', 'message');
define('ACTIONS', 'actions');
define('ITEM_MSG', 'display');
define('ITEM_ACTION', 'next_menu');
define('DEFAULT_MENU_ACTION', 'default_next_menu');
define('SAVE_RESPONSE_AS', 'save_as');

define('USSD_WELCOME', '__welcome');
define('USSD_BACK', '__back');
define('USSD_SAME', '__same');
define('USSD_END', '__end');
define('USSD_SPLITTED_MENU_NEXT', '__split_next');
define('USSD_SPLITTED_MENU_BACK', '__split_back');
define('USSD_CONTINUE_LAST_SESSION', '__continue_last_session');

define('WELCOME_MENU_NAME', 'welcome');
define('MENU_MSG_PLACEHOLDER', ':');

define('PROD', 'prod');
define('DEV', 'dev');

define('USSD_PARAMS_NAMES', ['msisdn', 'network', 'sessionID', 'ussdString', 'ussdServiceOp']);

header('Access-Control-Allow-Origin: *');

/*
Actions refer to a certain type of special menu that the app can manage automatically:

USSD_WELCOME: throw the welcome menu
USSD_BACK: throw the previous menu
USSD_SAME: re-throw the current menu
USSD_END: throw a goodbye menu
USSD_CONTINUE_LAST_SESSION: throw the menu on which the user was before request timed out or was cancelled
 */
define('USSD_APP_ACTIONS', [USSD_WELCOME, USSD_END, USSD_BACK, USSD_SAME, USSD_CONTINUE_LAST_SESSION, USSD_SPLITTED_MENU_NEXT, USSD_SPLITTED_MENU_BACK]);

define('ASK_USER_BEFORE_RELOAD_LAST_SESSION', '__ask_user_before_reload_last_session');

class USSD
{
    protected $db;

    protected $db_params = [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => '3306',
        'dbname' => '',
        'username' => 'root',
        'password' => '',
    ];

    protected $ussd_params = [];

    /*
    protected $msisdn;
    protected $network;
    protected $session_id;
     */

    protected $custom_ussd_request_type;

    protected $ussd_session_table_name;

    protected $menu_manager;

    protected $menus;
    protected $menu_ask_user_before_reload_last_session = [
        ASK_USER_BEFORE_RELOAD_LAST_SESSION => [
            'message' => 'Do you want to continue from where you left?',
            'actions' => [
                '1' => ['Continue last session', USSD_CONTINUE_LAST_SESSION],
                '2' => ['Restart', USSD_WELCOME],
            ],
        ],
    ];

    protected $params = [];

    protected $id = '';
    protected $environment = DEV;

    protected $back_action_thrower = '0';
    protected $back_action_display = 'Back';
    protected $splitted_menu_next_thrower = '99';
    protected $splitted_menu_display = 'More';

    protected $default_end_msg = 'Goodbye';

    protected $always_start_new_session = true;

    // This property has no effect when "always_start_new_session" is false
    protected $ask_user_before_reload_last_session = false;

    protected $always_send_sms = false;
    protected $sms_sender_name = '';
    protected $sms_endpoint = '';

    protected $error = '';
    protected $default_error_msg = 'Invalid input';

    protected $session_data = [];
    /*
    protected $back_history = []; // A LIFO (Last In First Out) stack
    protected $current_menu_id = '';
    protected $user_previous_responses = [];
     */

    protected $current_menu_splitted = false;
    protected $current_menu_split_index = 0;
    protected $current_menu_split_start = false;
    protected $current_menu_split_end = false;

    protected $max_ussd_page_content = 147;
    protected $max_ussd_page_lines = 10;
    protected $max_sms_content = 139;

    public function run($menu_manager)
    {
        $this->validate_menu_manager($menu_manager);

        $this->validate_ussd_params($_POST);
        $this->hydrate($menu_manager, $_POST);

        $this->load_DB();

        if ($this->environment !== PROD) {
            $this->create_session_table_if_not_exists();
        }

        $this->begin_session();
        $this->process_ussd();
    }

    protected function load_DB()
    {
        $dsn = $this->db_params['driver'];
        $dsn .= ':host=' . $this->db_params['host'];
        $dsn .= ';port=' . $this->db_params['port'];
        $dsn .= ';dbname=' . $this->db_params['dbname'];

        $user = $this->db_params['username'];
        $pass = $this->db_params['password'];

        // echo $dsn;
        try {
            $this->db = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_PERSISTENT => true,
            ]);
        } catch (\PDOException $e) {
            exit('Unable to connect to the database. Check if the server is ON and the parameters are correct.<br/><br/>Error: ' . $e->getMessage());
        }
    }

    protected function validate_menu_manager($menu_manager)
    {
        (!method_exists($menu_manager, 'params') or
            (method_exists($menu_manager, 'params') &&
                !is_array($menu_manager->params()))
        ) and
        exit('The menu manager object sent must have a "params" method that return an array containing parameters.');

        (!method_exists($menu_manager, 'db_params') or
            (method_exists($menu_manager, 'db_params') &&
                !is_array($menu_manager->db_params()))
        ) and
        exit('The menu manager object sent must have a "db_params" method that return an array containing the connections settings of the database where the USSD sessions will be stored.');

        $params = $menu_manager->params();
        !isset($params['id']) and
        exit('The params must contain an "id" which value will be the id of the app.');

        isset($params['environment']) and
        !is_string($params['environment']) and
        exit("'environment' must be a string.");

        /*
        if (isset($params['environment']) && ($params['environment'] === PROD || $this->environment === PROD)) {
        return;
        }
         */

        $this->validate_string_param($params['id']);

        isset($params['splitted_menu_display']) and
        !is_string($params['splitted_menu_display']) and
        exit("'splitted_menu_display' must be a string.");

        isset($params['splitted_menu_next_thrower']) and
        !is_string($params['splitted_menu_next_thrower']) and
        exit("'splitted_menu_next_thrower' must be a string.");

        isset($params['back_action_display']) and
        !is_string($params['back_action_display']) and
        exit("'back_action_display' must be a string.");

        isset($params['back_action_thrower']) and
        !is_string($params['back_action_thrower']) and
        exit("'back_action_thrower' must be a string.");

        isset($params['default_end_msg']) and
        !is_string($params['default_end_msg']) and
        exit("'default_end_msg' must be a string.");

        isset($params['default_end_msg']) and
        strlen($params['default_end_msg']) > $this->max_ussd_page_content and
        exit("'default_end_msg' must not be longer than " . $this->max_ussd_page_content . " characters.");

        isset($params['default_error_msg']) and
        !is_string($params['default_error_msg']) and
        exit("'default_error_msg' must be a string.");

        isset($params['always_start_new_session']) and
        !is_bool($params['always_start_new_session']) and
        exit("'always_start_new_session' must be a boolean.");

        isset($params['always_start_new_session']) and
        !is_bool($params['always_start_new_session']) and
        exit("'always_start_new_session' must be a boolean.");

        isset($params['ask_user_before_reload_last_session']) and
        !is_bool($params['ask_user_before_reload_last_session']) and
        exit("'ask_user_before_reload_last_session' must be a boolean.");

        isset($params['always_send_sms']) and
        !is_bool($params['always_send_sms']) and
        exit("'always_send_sms' must be a boolean.");

        isset($params['sms_sender_name']) and
        $this->validate_string_param(
            $params['sms_sender_name'],
            '/[a-z][a-z0-9+#$_@-]+/i',
            10
        );

        isset($params['sms_endpoint']) and
        !is_string($params['sms_endpoint']) and
        exit("'sms_endpoint' must be a valid URL.");
    }

    protected function validate_ussd_params($ussd_params)
    {
        if (!is_array($ussd_params)) {
            exit('Invalid USSD parameters received.');
        }

        foreach (USSD_PARAMS_NAMES as $value) {
            if (!isset($ussd_params[$value])) {
                exit("'" . $value . "' is missing in the USSD parameters.");
            }
        }
    }

    protected function hydrate($menu_manager, $ussd_params)
    {
        // DATABASE CONNECTIONS SETTIINGS
        $this->db_params = array_merge($this->db_params, $menu_manager->db_params());

        // USSD PARAMETERS
        foreach (USSD_PARAMS_NAMES as $param_name) {
            /*
            if ($param_name === 'ussdString') {
            $param_name = $this->sanitize_postvar($ussd_params[$param_name]);
            }
             */

            $this->ussd_params[$param_name] = $this->sanitize_postvar($ussd_params[$param_name]);
            // echo $param_name . '  = ' . $this->ussd_params[$param_name];
        }

        // MENU MANAGER
        $this->menu_manager = $menu_manager;
        $this->menus = array_merge(
            $menu_manager->menus(),
            $this->menu_ask_user_before_reload_last_session
        );

        foreach ($menu_manager->params() as $param => $value) {
            if (property_exists($this, $param)) {
                // Yes, $this->$param
                // It is not a mistake!
                $this->$param = $value;
            }
        }

        $this->ussd_session_table_name = strtolower($this->id) . '_ussd_sessions';
    }

    public function sanitize_postvar($var)
    {
        return htmlspecialchars(stripslashes($var));
    }

    protected function begin_session()
    {
        switch ($this->ussd_request_type()) {
            case USSD_REQUEST_INIT:
                if ($this->always_start_new_session) {
                    $this->clear_last_session();
                } elseif ($this->retrieve_last_session()) {
                    if (
                        $this->ask_user_before_reload_last_session &&
                        !empty($this->session_data) &&
                        $this->session_data['current_menu_id'] !== WELCOME_MENU_NAME
                    ) {
                        $this->set_custom_ussd_request_type(USSD_REQUEST_ASK_USER_BEFORE_RELOAD_LAST_SESSION);
                    } else {
                        $this->set_custom_ussd_request_type(USSD_REQUEST_RELOAD_LAST_SESSION_DIRECTLY);
                    }
                }

                break;

            case USSD_REQUEST_USER_SENT_RESPONSE:
                $this->retrieve_last_session();
                break;
        }
    }

    protected function run_last_session_state()
    {
        // current_menu_id has been retrieved from the last state
        $this->run_state($this->current_menu_id());
    }

    protected function update_session_id()
    {
        $req = $this->db
            ->prepare("UPDATE $this->ussd_session_table_name SET session_id = :session_id WHERE msisdn = :msisdn");

        $req->execute([
            'session_id' => $this->session_id(),
            'msisdn' => $this->msisdn(),
        ]);

        return $req->closeCursor();
    }

    protected function retrieve_last_session()
    {
        $this->session_data = $this->retrieve_session_data();

        if (!empty($this->session_data)) {
            $this->update_session_id();

            // $this->user_previous_responses = $this->session_data['user_previous_responses'];
            // $this->back_history = $this->session_data['back_history'];
            // $this->current_menu_id = $this->session_data['current_menu_id'];

            return true;
        }

        return false;
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

    protected function validate_string_param(
        $name_id,
        $pattern = '/[a-z][a-z0-9]+/i',
        $max_length = 126,
        $min_length = 1
    ) {
        if (!is_string($name_id)) {
            exit('"' . $name_id . '" option must be a string.');
        }

        if (strlen($name_id) < $min_length) {
            exit('"' . $name_id . '" option is too short.' . $max_length . '.');
        }

        if (strlen($name_id) > $max_length) {
            exit('"' . $name_id . '" option is too long. The max length is ' . $max_length . '.');
        }

        if (!preg_match($pattern, $name_id) === 1) {
            exit('"' . $name_id . '" option contains unsual character(s).');
        }

        return true;
    }

    protected function create_session_table_if_not_exists()
    {
        // echo $this->ussd_session_table_name;
        $sql = "CREATE TABLE IF NOT EXISTS `$this->ussd_session_table_name`(
                  `id` INT(11) NOT NULL AUTO_INCREMENT,
                  `msisdn` VARCHAR(20) NOT NULL,
                  `session_id` VARCHAR(50) NOT NULL,
                  `ddate` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                  `session_data` TEXT,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `NewIndex1` (`msisdn`),
                  UNIQUE KEY `NewIndex2` (`session_id`)
                ) ENGINE=INNODB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;";

        $result = $this->db->query($sql);
        $result->closeCursor();
    }

    protected function clear_last_session()
    {
        $sql = "DELETE FROM $this->ussd_session_table_name WHERE msisdn = :msisdn";

        $result = $this->db->prepare($sql);

        $result->execute(['msisdn' => $this->msisdn()]);

        $result->closeCursor();
    }

    protected function reset_session_data()
    {
        $sql = "UPDATE $this->ussd_session_table_name SET session_data=null WHERE msisdn = :msisdn";

        $result = $this->db->prepare($sql);
        $result->execute(['msisdn' => $this->msisdn()]);

        $result->closeCursor();
    }

    protected function retrieve_session_data()
    {
        $sql = "SELECT (session_data) FROM $this->ussd_session_table_name WHERE msisdn = :msisdn";

        $req = $this->db->prepare($sql);
        $req->execute(['msisdn' => $this->msisdn()]);

        $result = $req->fetchAll(\PDO::FETCH_ASSOC);
        $req->closeCursor();

        if (empty($result)) {
            return [];
        }

        $session_data = $result[0]['session_data'];

        $data = ($session_data !== '') ? json_decode($session_data, true) : [];

        return $data;
    }

    protected function save_session_data($data = [])
    {
        $sql = "SELECT COUNT(*) FROM $this->ussd_session_table_name WHERE msisdn = :msisdn";

        $result = $this->db->prepare($sql);
        $result->execute(['msisdn' => $this->msisdn()]);

        $nb_rows = (int) $result->fetchColumn();

        $result->closeCursor();

        if ($nb_rows <= 0) {
            $sql = "INSERT INTO $this->ussd_session_table_name (session_data, msisdn, session_id) VALUES (:session_data, :msisdn, :session_id)";

            $result = $this->db->prepare($sql);
            $result->execute([
                'session_data' => json_encode($data),
                'msisdn' => $this->msisdn(),
                'session_id' => $this->session_id(),
            ]);

            return $result->closeCursor();
        }

        $sql = "UPDATE $this->ussd_session_table_name SET session_data = :session_data WHERE msisdn = :msisdn";

        $result = $this->db->prepare($sql);

        $result->execute([
            'session_data' => json_encode($data),
            'msisdn' => $this->msisdn(),
        ]);

        return $result->closeCursor();

        //var_dump($req);
    }

    protected function process_ussd()
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
                $this->hard_end('REQUEST CANCELLED');
                break;

            default:
                $this->hard_end('UNKNOWN USSD SERVICE OPERATOR');
                break;
        }
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

        // var_dump($page_id);
        $user_response = $this->user_response();

        $particular_item_action_defined_by_developer =
        isset($this->menus[$page_id][ACTIONS][$user_response]) &&
        isset($this->menus[$page_id][ACTIONS][$user_response][ITEM_ACTION]);

        $next_menu_id = $this->get_next_menu_id(
            $user_response,
            $page_id,
            $particular_item_action_defined_by_developer
        );

        // echo $next_menu_id;
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

        /*
        If the next_menu_id is an url then we switch to that USSD application.
        It will actually be good to find a way to return back to this application.
        Currently it can be done by switching back from the remote application to this application. But it can be done only if the remote application is using this ussd library or if it implements a method of switching to another ussd.
         */
        if ($this->is_url($next_menu_id)) {
            $this->session_data['switched_ussd_endpoint'] = $next_menu_id;
            $this->session_data['ussd_has_switched'] = true;
            $this->save_session_data($this->session_data);

            $this->set_ussd_request_type(USSD_REQUEST_INIT);

            return $this->process_from_remote_ussd($next_menu_id);
        }

        switch ($next_menu_id) {
            case USSD_WELCOME:
                $this->run_welcome_state();
                break;

            case USSD_CONTINUE_LAST_SESSION:
                // $this->current_menu_id = $this->back_history_pop();
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
        $response_to_save = $user_response;

        if (isset($this->menus[$id][ACTIONS][$user_response][SAVE_RESPONSE_AS])) {
            $response_to_save = $this->menus[$id][ACTIONS][$user_response][SAVE_RESPONSE_AS];
        }

        $this->user_previous_responses_add($response_to_save);
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
            $user_response === $this->splitted_menu_next_thrower
        ) {
            return USSD_SPLITTED_MENU_NEXT;

        } elseif (
            isset($this->session_data['current_menu_splitted']) &&
            $this->session_data['current_menu_splitted'] &&
            isset($this->session_data['current_menu_split_start']) &&
            !$this->session_data['current_menu_split_start'] &&
            $user_response === $this->back_action_thrower
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
                $user_response, $this->user_previous_responses
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

        /* The "after_" method does not have to be called if the response
        expected has been defined by the developper and is an app action
        (e.g. in the case of the USSD_BACK action, the response defined by
        developper could be 98. If the user provide 98 we don't need to call
        the "after_" method). This is to allow the developer to use the
        "after_" method just for checking the user's response that leads to
        his (the developer) other menu. The library takes care of the app
        actions. */
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

        $resJSON = $this->http_post($this->ussd_params, $endpoint);

        $this->send_remote_response($resJSON);
    }

    protected function get_splitted_menu_action_next()
    {
        return $this->splitted_menu_next_thrower . ". " . $this->splitted_menu_display;
    }

    protected function get_splitted_menu_action_back()
    {
        return $this->back_action_thrower . ". " . $this->back_action_display;
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
                /*
                if (!$menu_item_str) {
                continue;
                }
                 */

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

                /* The order is important here. (setting
                current_string_with_split_menu before
                current_string_without_split_menu) */
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
                    // $next_string_without_split_menu .= "\n" . $new_line;
                } else {
                    $next_line = "\n" . $menu_string_chunks[$last];
                    $split_menu = $has_back_action ? '' : "\n" . $splitted_menu_back;

                    $next_string_with_split_menu = $current_string_without_split_menu . $next_line . $split_menu;
                    // $next_string_without_split_menu .= "\n" . $new_line;
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
            // 'session_id' => $this->session_id(),
        );

        return json_encode($fields);
    }

    protected function send_response($message, $ussd_request_type = USSD_REQUEST_ASK_USER_RESPONSE, $hard = false)
    {
        // Sometimes, we need to send the response to the user and do another staff before ending the script. Those times, we just need to echo the response. That is the soft response snding.
        // Sometimes we need to terminate the script immediately when sending the response; for exemple when the developer himself will call the end function from his code.
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

        // Important! To notify the developer that the error occured at
        // the remote ussd side and not at this ussd switch side.
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

        // This is used only in the case of a splitted menu,
        // to know if we have to add a back action or not
        $has_back_action = false;

        $menu_array = [];
        if (isset($this->menus[$next_menu_id][ACTIONS])) {
            $menu = $this->menus[$next_menu_id][ACTIONS];

            foreach ($menu as $index => $value) {
                if ($index !== DEFAULT_MENU_ACTION) {
                    $menu_array[$index] = $value[ITEM_MSG];

                    if (!$has_back_action && isset($value[ITEM_ACTION]) && $value[ITEM_ACTION] === USSD_BACK) {
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

    protected function run_next_state($next_menu_id, $msg = '', $menu_array = [], $has_back_action = false)
    {
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

            // $this->current_menu_id = $next_menu_id;
            $this->set_current_menu_id($next_menu_id);
        }

        // $this->session_data['back_history'] = $this->back_history;
        // $this->session_data['current_menu_id'] = $this->current_menu_id;
        // $this->session_data['user_previous_responses'] = $this->user_previous_responses;

        $this->save_session_data($this->session_data);
    }

    protected function run_last_state($msg = '')
    {
        if ($this->always_send_sms) {
            $this->send_sms($msg);
        }

        $this->soft_end($msg);
        $this->clear_last_session();
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
            // Remove the previous response (if there is)
            $previous_menu_id = $this->get_previous_menu_id();
            $this->user_previous_responses_pop($previous_menu_id);

            $this->run_state($previous_menu_id);
        }
    }

    protected function user_previous_responses_pop($menu_id)
    {
        if ($this->user_previous_responses()) {
            if (
                isset($this->session_data['user_previous_responses'][$menu_id]) && is_array($this->session_data['user_previous_responses'][$menu_id])
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
            $error = empty($this->error()) ? $this->default_error_msg : $this->error();
            $this->set_error($error);
        }

        $this->run_state($this->current_menu_id());
    }

    protected function end($sentMsg = '', $hard = true)
    {
        $msg = $sentMsg === '' ? $this->default_end_msg : $sentMsg;
        $this->send_final_response($msg, $hard);
    }

    protected function get_previous_menu_id()
    {
        $length = count($this->back_history());

        if (!$length) {
            exit('No previous menu available.');
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
            exit('TRYING TO SET A REQUEST TYPE BUT THE VALUE PROVIDED: "' . $request_type . '" IS INVALID.');
        }

        $this->ussd_params['ussdServiceOp'] = $request_type;

        return $this;
    }

    protected function set_custom_ussd_request_type($request_type)
    {
        $this->custom_ussd_request_type = $request_type;
    }

    public function check_menu($json_menu)
    {
        $all_menus = json_decode($json_menu, true, 512, JSON_THROW_ON_ERROR);

        $result = ['SUCCESS' => true, 'response' => []];

        if (!isset($all_menus[WELCOME_MENU_NAME])) {
            $result['SUCCESS'] = false;
            $result['response'][WELCOME_MENU_NAME]['errors'] = "There must be a menu named " . WELCOME_MENU_NAME . " that will be the welcome menu of the application";
        }

        foreach ($all_menus as $menu_id => $menu) {
            $infos = [];
            $errors = [];
            $warnings = [];

            if (!preg_match('/[a-z][a-z0-9_]+/i', $menu_id) !== 1) {
                $errors['about_menu_name'] = $menu_id . ' is an invalid menu name. Only letters, numbers and underscores are allowed.';
            }

            if (!isset($menu[MSG])) {
                $infos['about_message'] = "This menu does not have a message. It means will be generating a message from the 'before_" . $menu_id . "' function in your application, unless you don't want anything to be displayed above your menu items.";
            } elseif (isset($menu[MSG]) && !is_string($menu[MSG])) {
                $errors['about_message'] = 'The message of this menu must be a string.';
            }

            $actions_errors = [];

            if (!isset($menu[ACTIONS])) {
                $infos['about_actions'] = 'This menu does not have any following action. It will then be a final response.';
            } elseif (isset($menu[ACTIONS]) && !is_array($menu[ACTIONS])) {
                $actions_errors = 'The actions of this menu must be an array.';
            } else {
                foreach ($menu[ACTIONS] as $key => $value) {
                    if (!preg_match('/[a-z0-9_]+/i', $key) !== 1) {
                        $actions_errors[] = 'The key ' . $key . ' has an invalid format. Only letters, numbers and underscore are allowed.';
                    }

                    $next_menu = '';

                    if (is_array($value)) {
                        $next_menu = $value[ITEM_ACTION];
                    } elseif (is_string($value)) {
                        $next_menu = $value;
                    }

                    if (
                        empty($next_menu) ||
                        (!isset($all_menus[$next_menu]) &&
                            !in_array($next_menu, USSD_APP_ACTIONS, true))
                    ) {
                        $actions_errors[$next_menu] = 'The menu "' . $next_menu . '" has been associated as following menu to this menu but it has not yet been implemented.';
                    }
                }
            }

            if (!empty($actions_errors)) {
                $errors['about_actions'] = $actions_errors;
            }

            if (!isset($menu[MSG]) && !isset($menu[ACTIONS])) {
                $warnings = "This menu does not have any message and any menu. Make sure you are returning a menu message in the 'before_" . $menu_id . "' function.";
            }
            // END OF VERIFICATION

            if (!empty($errors) || !empty($warnings) || !empty($infos)) {
                $result['response'][$menu_id] = [];
            }

            if (!empty($errors)) {
                $result['response']['SUCCESS'] = false;
                $result['response'][$menu_id]['errors'] = $errors;
            }

            if (!empty($warnings)) {
                $result['response'][$menu_id]['warnings'] = $warnings;
            }

            if (!empty($infos)) {
                $result['response'][$menu_id]['infos'] = $infos;
            }
        }

        return $result;
    }

    public function prepare_msg_for_sms($msg)
    {
        if (strlen($msg) > $this->max_sms_content) {
            $continued = '...';
            $message_chunks = str_split($msg, $this->max_sms_content - strlen($continued));

            $last = count($message_chunks) - 1;

            foreach ($message_chunks as $index => $chunk) {
                if ($index !== $last) {
                    $message_chunks[$index] = $chunk . $continued;
                }
            }

            return $message_chunks;
        }

        return [$msg];
    }

    public function send_sms(string $msg)
    {
        $sms_data = [
            'message' => '',
            'recipient' => $this->msisdn(),
            'sender' => $this->sms_sender_name,
        ];

        $msg_chunks = $this->prepare_msg_for_sms($msg);

        foreach ($msg_chunks as $message) {
            $sms_data['message'] = $message;
            $this->http_post($sms_data, $this->sms_endpoint);
        }
    }

    public function is_url($url)
    {
        /*
        The function `filter_var` to validate URL, has some limitations...
        Among all the limitations, tt doesn't parse url with non-latin caracters.
        I'm not really felling comfortable using it here.

        But let's stick to it for the meantime.

        TO IMPROVE this `is_url` function, do not remove completly the filter_var function unless you have a better function.
        Instead, use filter_var to validate and check the cases that filter_var does not support.
         */
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    protected function http_post($postvars, $endpoint)
    {
        $curl_handle = curl_init();
        curl_setopt($curl_handle, CURLOPT_URL, $endpoint);
        curl_setopt($curl_handle, CURLOPT_POST, 1);
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $postvars);
        curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);

        $result = curl_exec($curl_handle);
        $err = curl_error($curl_handle);

        curl_close($curl_handle);

        if ($err) {
            echo 'Curl Error: ' . $err;
        } else {
            return $result;
        }
    }
}
