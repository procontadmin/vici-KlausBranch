<?php

ini_set('memory_limit', '1G');
include_once 'core/CoreDbQueries.php';
include_once 'core/DriverController.php';
include_once 'WTCalc.php';
include_once 'DCalc.php';
include_once 'VCalc.php';
include_once 'ACalc.php';
include_once 'STMapper.php';
include_once 'GeoMapper.php';
include_once 'Faehre.php';

define("RED", "\033[1;31m");
define("GREEN", "\033[1;32m");
define("YELLOW", "\033[1;33m");
define("BLUE", "\033[1;34m");
define("SKY", "\033[1;36m");
define("RESET", "\033[0m");

set_time_limit(90000);
$start = microtime(true);

$wtc = new CoreCalculator();
$wtc->run();

$timeElapsed = microtime(true) - $start;
echo(YELLOW . "TIME: " . $timeElapsed . RESET . "\n");
echo(YELLOW . 'END' . RESET . "\n");

class CoreCalculator
{
    protected CoreDbQueries $dbQuery;
    /**
     * Die Funktion überprüft die Eingangsparameter und
     * startet die Berechnungen
     *
     * @param int       i    Fahrer-Id
     * @param int       c    Client Id
     * @param string    d    systemId
     *
     * @author Oleg Manov
     */
    public function run()
    {
        $options = getopt("t:i:d:a:");
        if (array_key_exists("a", $options) && array_key_exists("d", $options)) {
            $systemId = $options['d'];
            $this->dbQuery = new CoreDbQueries($systemId);
            $driverCtrl = new DriverController($this->dbQuery);
            $allDrivers = $this->dbQuery->getAllDrivers();

            if (!is_null($allDrivers)) {
                //var_dump($allDrivers);
                foreach ($allDrivers as $driver) {
                    $startDate = "2022-02-01 00:00:00";
                    $dateStartD = "2021-01-01";
                    $startDateE = "2021-09-01 00:00:00";
                    echo GREEN . "=============================== " . RESET . "\n";
                    $driver = $this->dbQuery->getDriverInfo($driver['driver_idx']);
                    if (!is_null($driver)) {
                        $mpStm = new STMapper($driver, $this->dbQuery);
                        $mpStm->run($startDate);
                        //$mpGeo = new GeoMapper($driver, $this->dbQuery);
                        //$mpGeo->run($startDateE);
                        //$wtCalc = new WTCalc($driver, $this->dbQuery, $driverCtrl);
                        //$wtCalc->run($startDate);
                        //$dCalc = new DCalc($driver, $this->dbQuery, $driverCtrl);
                        //$dCalc->run($dateStartD);
                        //$vCalc = new VCalc($driver, $this->dbQuery, $driverCtrl);
                        //$vCalc->run($startDate);
                    } else {
                        // exit(1);
                        echo RED . "Fahrer nicht gefunden!" . RESET . "\n";
                    }
                }
            }
        } elseif (array_key_exists("i", $options) && array_key_exists("d", $options)) {
            $startDate = "2021-01-01 00:00:00";
            $startDateE = "2021-01-01 00:00:00";
            $dateStartD = "2021-01-01";
            $driverId = $options['i'];
            $systemId = $options['d'];
            $this->dbQuery = new CoreDbQueries($systemId);

            $driver = $this->dbQuery->getDriverInfo($driverId);
            //echo SKY ."driverID: ". $driverId . RESET;
            //echo SKY ." and ". $driver['idx'] . RESET . "\n";
            if (!is_null($driver)) {
                //$this->dbQuery->createDriverTables($driver['idx']);
                $lastShift = $this->dbQuery->getLastShift($driver['idx']);
                if (!is_null($lastShift['dateStart'])) {
                    $startDate = $lastShift['dateStart'];
                    echo GREEN . "Letzte Schicht: " . $startDate . RESET . "\n";
                } else {
                    echo BLUE . "Start Date AZ: " . $startDate . RESET . "\n";
                }
                $lastEDay = $this->dbQuery->getLastEDay($driver['idx']);
                if (!is_null($lastEDay)) {
                    $lastTimeHome = $this->dbQuery->getLastEShift($driver['idx'], str_replace("00:00:00", "23:59:59", $lastEDay['expenses_day']), str_replace(";", ",", $driver['home_zip']));
                    if (!is_null($lastTimeHome['dateStart'])) {
                        $startDateE = $lastTimeHome['dateStart'];
                        echo GREEN . "Letzte Schicht SP: " . $startDateE . RESET . "\n";
                    } else {
                        echo BLUE . "Start Date SP: " . $startDateE . RESET . "\n";
                    }
                } else {
                    echo SKY . "Start Date SP: " . $startDateE . RESET . "\n";
                }
                // if($driver["client_idx"] == "501") {
                //     // var_dump($driver);
                //     $faehre = new Faehre($driver, $this->dbQuery);
                //     $faehre->run();
                // }
                //$this->checkFaehre($driver, $this->dbQuery);
                $driverCtrl = new DriverController($this->dbQuery);

                $vCalc = new VCalc($driver, $this->dbQuery, $driverCtrl);
                $vCalc->run($startDate);
                $aCalc = new ACalc($driver, $this->dbQuery, $driverCtrl);
                $aCalc->run($startDateE);

            } else {
                //  exit(1);
                echo RED . "Fahrer nicht gefunden!" . RESET . "\n";
            }
        } else {
            echo RED . "Parameter ist nicht gesetzt!" . RESET . "\n";
            exit(9);
        }

        return;
    }


}
