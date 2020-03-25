<?php
define('USSD_REQUEST_INIT', '1');
define('USSD_REQUEST_END', '17');
define('USSD_REQUEST_CANCELLED', '30');
define('USSD_REQUEST_ASK_USER_RESPONSE', '2');
define('USSD_REQUEST_USER_SENT_RESPONSE', '18');
define(
    'USSD_REQUEST_ASK_USER_BEFORE_RELOAD_LAST_SESSION',
    '__CUSTOM_REQUEST_TYPE1'
);
define(
    'USSD_REQUEST_RELOAD_LAST_SESSION_DIRECTLY',
    '__CUSTOM_REQUEST_TYPE2'
);

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

define(
    'USSD_PARAMS_NAMES',
    [
        'msisdn',
        'network',
        'sessionID',
        'ussdString',
        'ussdServiceOp',
    ]
);

/**
 * Actions refer to a certain type of special menu that the app can manage
 * automatically:
 *
 * USSD_WELCOME: throw the welcome menu
 * USSD_BACK: throw the previous menu
 * USSD_SAME: re-throw the current menu
 * USSD_END: throw a goodbye menu
 * USSD_CONTINUE_LAST_SESSION: throw the menu on which the user was before
 * request timed out or was cancelled
 */
define(
    'USSD_APP_ACTIONS',
    [
        USSD_WELCOME,
        USSD_END,
        USSD_BACK,
        USSD_SAME,
        USSD_CONTINUE_LAST_SESSION,
        USSD_SPLITTED_MENU_NEXT,
        USSD_SPLITTED_MENU_BACK,
    ]
);

define(
    'ASK_USER_BEFORE_RELOAD_LAST_SESSION',
    '__ask_user_before_reload_last_session'
);
