<?php
define('ENV', 'development');

require_once "lib/ussd.lib.php";

class App
{
    protected $params = [];
    protected $ussd;
    
    /*
    Put messages into double quotes rather than single quotes if you wanna
    use "\n" inside the message.
    Using single quotes will display the character '\n' in the message instead of displying a newline.
     */
    protected $menus = [
        // the menu named 'welcome' is required
        'welcome' => [
            'message' => "Welcome.\nSelect an option",
            'actions' => [
                '1' => [
                    'display' => 'Am I working ?',
                    'next_menu' => 'verify_working',
                ],
                '2' => [
                    'display' => 'What is the date?',
                    'next_menu' => 'get_time',
                ],
                '3' => [
                    'display' => 'Say Goodbye',
                    'next_menu' => 'say_goodbye',
                ],
            ],
        ],

        'verify_working' => [
            'message' => "Of course, I'm working!",
            'actions' => [
                '0' => [
                    'display' => 'Back',
                    'next_menu' => '__back',
                ],
            ],
        ],

        'get_time' => [
            'message' => "Today is :date:!",
            'actions' => [
                '0' => [
                    'display' => 'Back',
                    'next_menu' => '__back',
                ],
            ],
        ],

        'say_goodbye' => [
            'message' => "Goodbye",
        ],
    ];

    public function __construct()
    {
        $this->params['id'] = 'basic_app';
        $this->params['environment'] = 'dev';

        $this->ussd = new USSDLib();
        $this->ussd->run($this);
    }

    public function before_get_time($user_responses)
    {
        return [
            'date' => date('d-m-Y'),
        ];
    }

    public function db_params()
    {
        $config = [
            'driver' => 'mysql',
            'username' => 'root',
            'password' => '',
        ];

        if (ENV !== 'production') {
            $config['hostname'] = 'localhost';
            $config['password'] = '';
            $config['dbname'] = '';
        } else {
            $config['hostname'] = '';
            $config['password'] = '';
            $config['port'] = '';
            $config['dbname'] = '';
        }

        return $config;
    }

    public function params()
    {
        return $this->params;
    }

    public function menus()
    {
        return $this->menus;
    }
}

$app = new App();
