<?php declare(strict_types=1);

abstract class Db
{
    /**
     * The database connection instance
     *
     * @var pdo
     */
    private static $instance = null;

    protected function __construct() {}     // prevent object creation with 'new'
    private function __clone() {}           // prevent object cloning
    private function __wakeup() {}          // prevent object unserialization

    /**
     * Connect to the database server
     *
     * @return pdo
     */
    private static function connect($company)
    {
        try{
            if ($company == 0)
            {
                $json_config = file_get_contents('C:/vici/basic_config/pcs_mariadb.json');
                $db_config = json_decode($json_config, true);
            }
            elseif ($company == 1) 
            {
                // $json_config = file_get_contents('C:/vici/basic_config/transics_mariadb.json');
                $json_config = file_get_contents('C:/vici/basic_config/pcs_mariadb.json');
                $db_config = json_decode($json_config, true);
            }
            $db = new \PDO("mysql:".$db_config['db']."=PHP;host=".$db_config['host'].";port=".$db_config['port'].";charset=".$db_config['charset'],$db_config['username'],$db_config['password']);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        catch (PDOException $e){
            echo "Datenbank ist zur Zeit nicht erreichbar";
            var_dump($e);
            exit (1);
        }

        return $db;
    }

    /**
     * Get the database connection (singleton).
     *
     * @return pdo
     */
    public static function getInstance($company)
    {
        if (self::$instance === null) {
            self::$instance = self::connect($company);
        }

        return self::$instance;
    }    
}