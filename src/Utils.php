<?php
/**
 * (c) Nuna Akpaglo <princedorcis@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Prinx\USSD;

require_once 'constants.php';

class SMSUtils
{
    public static $max_sms_content = 139;

    public function send_sms(array $data, array $required_params = [])
    {
        $required_params = empty($required_params) ?
        ['message', 'recipient', 'sender', 'endpoint'] :
        $required_params;

        foreach ($required_params as $param) {
            if (!isset($data[$param])) {
                throw new \Exception('"send_sms" function requires parameter "' . $param . '".');
            }
        }

        $sms_data = [
            'message' => '',
            'recipient' => $data['recipient'],
            'sender' => $data['sender'],
        ];

        $msg_chunks = self::prepare_msg_for_sms($data['message']);

        foreach ($msg_chunks as $message) {
            $sms_data['message'] = $message;
            HTTPUtils::post($sms_data, $data['endpoint'], 'Sending SMS');
        }
    }

    public static function prepare_msg_for_sms($msg)
    {
        if (strlen($msg) > self::$max_sms_content) {
            $continued = '...';
            $message_chunks = str_split($msg, self::$max_sms_content - strlen($continued));

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
}

class URLUtils
{
    public static function is_url($url)
    {
        /*
        The function `filter_var` to validate URL, has some limitations...
        Among all the limitations, tt doesn't parse url with non-latin caracters.
        I'm not really felling comfortable using it here.

        But let's stick to it for the meantime.

        TO IMPROVE this `is_url` function, do not remove completly
        the filter_var function unless you have a better function.
        Instead, use filter_var to validate and check the cases that
        filter_var does not support.
         */
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

class HTTPUtils
{
    public static function post($postvars, $endpoint, $request_description = '')
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
            echo '<br/>Error while sending a POST request: ' . $request_description . '<br/>' . $err . '<br/>';
        } else {
            return $result;
        }
    }
}

class DBUtils
{
    public static function load_db($params)
    {
        $dsn = $params['driver'];
        $dsn .= ':host=' . $params['host'];
        $dsn .= ';port=' . $params['port'];
        $dsn .= ';dbname=' . $params['dbname'];

        $user = $params['username'];
        $pass = $params['password'];

        try {
            return new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_PERSISTENT => true,
            ]);
        } catch (\PDOException $e) {
            exit('Unable to connect to the database. Check if the server is ON and the parameters are correct.<br/><br/>Error: ' . $e->getMessage());
        }
    }
}
