<?php
/**
 * (c) Nuna Akpaglo <princedorcis@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Prinx\USSD;

require_once 'constants.php';

class Session
{
    protected $ussd_lib;

    protected $id;
    protected $msisdn;
    protected $data;

    protected $db;
    protected $db_params = [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => '3306',
        'dbname' => '',
        'username' => 'root',
        'password' => '',
    ];

    protected $table_name;
    protected $table_name_suffix = '_ussd_sessions';

    public function __construct($ussd_lib)
    {
        $this->ussd_lib = $ussd_lib;
        $this->id = $ussd_lib->session_id();
        $this->msisdn = $ussd_lib->msisdn();
        $this->table_name = strtolower($ussd_lib->id()) . $this->table_name_suffix;

        $this->hydrate_db_params($ussd_lib->menu_manager()->db_params());
        $this->load_db();

        if ($ussd_lib->app_params()['environment'] !== PROD) {
            $this->create_session_table_if_not_exists();
        }

        $this->start();
    }

    public function hydrate_db_params($params)
    {
        $this->db_params = array_merge($this->db_params, $params);
    }

    public function load_db()
    {
        $this->db = DBUtils::load_db($this->db_params);
    }

    public function is_previous()
    {
        return !empty($this->data);
    }

    protected function start()
    {
        switch ($this->ussd_lib->ussd_request_type()) {
            case USSD_REQUEST_INIT:
                if ($this->ussd_lib->app_params()['always_start_new_session']) {
                    $this->delete_previous_session_data();
                    $this->data = [];
                } else {
                    $this->data = $this->retrieve_previous_session_data();
                }

                break;

            case USSD_REQUEST_USER_SENT_RESPONSE:
                $this->data = $this->retrieve_previous_session_data();
                break;
        }
    }

    private function create_session_table_if_not_exists()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `$this->table_name`(
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

    protected function delete_previous_session_data()
    {
        $this->delete();
    }

    public function delete()
    {
        $sql = "DELETE FROM $this->table_name WHERE msisdn = :msisdn";
        $result = $this->db->prepare($sql);
        $result->execute(['msisdn' => $this->msisdn]);
        $result->closeCursor();
    }

    public function reset_data()
    {
        $sql = "UPDATE $this->table_name SET session_data=null WHERE msisdn = :msisdn";
        $result = $this->db->prepare($sql);
        $result->execute(['msisdn' => $this->msisdn]);
        $result->closeCursor();
    }

    public function retrieve_previous_session_data()
    {
        $data = $this->retrieve_data();

        if (!empty($data)) {
            $this->update_id();
        }

        return $data;
    }

    public function retrieve_data()
    {
        $sql = "SELECT (session_data) FROM $this->table_name WHERE msisdn = :msisdn";

        $req = $this->db->prepare($sql);
        $req->execute(['msisdn' => $this->msisdn]);

        $result = $req->fetchAll(\PDO::FETCH_ASSOC);
        $req->closeCursor();

        if (empty($result)) {
            return [];
        }

        $session_data = $result[0]['session_data'];

        $data = ($session_data !== '') ? json_decode($session_data, true) : [];

        return $data;
    }

    public function update_id()
    {
        $req = $this->db
            ->prepare("UPDATE $this->table_name SET session_id = :session_id WHERE msisdn = :msisdn");

        $req->execute([
            'session_id' => $this->id,
            'msisdn' => $this->msisdn,
        ]);

        return $req->closeCursor();
    }

    public function data()
    {
        return $this->data;
    }

    public function save($data = [])
    {
        $sql = "SELECT COUNT(*) FROM $this->table_name WHERE msisdn = :msisdn";

        $result = $this->db->prepare($sql);
        $result->execute(['msisdn' => $this->msisdn]);

        $nb_rows = (int) $result->fetchColumn();

        $result->closeCursor();

        if ($nb_rows <= 0) {
            $sql = "INSERT INTO $this->table_name (session_data, msisdn, session_id) VALUES (:session_data, :msisdn, :session_id)";

            $result = $this->db->prepare($sql);
            $result->execute([
                'session_data' => json_encode($data),
                'msisdn' => $this->msisdn,
                'session_id' => $this->id,
            ]);

            return $result->closeCursor();
        }

        $sql = "UPDATE $this->table_name SET session_data = :session_data WHERE msisdn = :msisdn";

        $result = $this->db->prepare($sql);

        $result->execute([
            'session_data' => json_encode($data),
            'msisdn' => $this->msisdn,
        ]);

        return $result->closeCursor();
    }
}
