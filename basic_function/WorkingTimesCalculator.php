<?php 
ini_set('memory_limit', '1G');
include_once 'core/CoreDbQueries.php';
include_once 'core/DriverController.php';

define ("RED",   "\033[1;31m");
define ("GREEN", "\033[1;32m");
define ("YELLOW","\033[1;33m");
define ("BLUE",  "\033[1;34m");
define ("RESET", "\033[0m");

set_time_limit(90000);
$start = microtime(true);

$wtc = new WorkingTimesCalculator;
$wtc->run();

$timeElapsed = microtime(true) - $start;
echo (YELLOW . $timeElapsed . RESET . "\n" );
echo (YELLOW . 'END' . RESET . "\n");

class WorkingTimesCalculator
{
    /**
     * The DbQuery object
     *
     * @var CoreDbQueries
     */
    protected $dbQuery = null;

    /**
     * The DriverController object
     *
     * @var DriverContoller
     */
    protected $driverCtrl = null;

    public function __construct()
    {
    }

    /**
     * Die Funktion überprüft die Eingangsparameter und 
     * startet die Berechnung der Arbeitszeiten pro Fahrer 
     *
     * @param int       i    Fahrer-Id
     * @param int       c    Client Id
     * @param string    d    Startdatum
     * 
     * @author Oleg Manov
     */
    public function run()
    {
        $options = getopt("a:t:i:c:d:");

        if (array_key_exists("a", $options) && array_key_exists("d", $options))
        {
            $company    = $options['d'];
            $this->dbQuery = new CoreDbQueries($company);
            $this->driverCtrl = new DriverController($this->dbQuery);
            $allDrivers = $this->dbQuery->getAllDrivers();
            
            if(!is_null($allDrivers))
            {
                //var_dump($allDrivers);
                foreach ($allDrivers as $driver)
                {
                    //var_dump($driver);
                    //print '.';
                   // $this->dbQuery->truncateWorkingTimesTable($driver['driver_idx']);
                    //$this->dbQuery->truncateDocketsTable($driver['driver_idx']);
                    $this->proceedDriver($driver['driver_idx'], "2020-01-01 00:00:00");
                }
            }
        }
        elseif (array_key_exists("i", $options) && array_key_exists("d", $options))
        {
            //$startDate  = $options['t'];
            $startDate  = "2020-01-01 00:00:00";
            $driverId   = $options['i'];
            $company    = $options['d'];
            isset($options['c']) ? $clientId = $options['c'] : $clientId = null;
            $this->dbQuery = new CoreDbQueries($company);
            $this->driverCtrl = new DriverController($this->dbQuery);
            if (!is_null($clientId))
            {
                $allDrivers = $this->dbQuery->getAllClientDrivers($clientId);

                if(!is_null($allDrivers))
                {
                    //var_dump($allDrivers);
                    foreach ($allDrivers as $driver)
                    {
                        $this->proceedDriver($driver['driver_idx'], $startDate);
                    }
                }
            }
            else
            {
                $this->proceedDriver($driverId, $startDate);
            }
        }
        else
        {
            exit (9);
            echo RED . "Parameter ist nicht gesetzt!" . RESET . "\n";
        }

        return;
    }

    /**
     * Die Funktion überprüft den Fahrer, die Fahrerkarten und die Aktivitäten des Fahrers
     * und startet die Berechnung der Arbeitszeiten ab der letzten Schicht des Fahrers,
     * oder ab dem gegebenen Datum, wenn der Fahrer keine Schichten hat
     *
     * @param int       driverId      Fahrer-Id
     * @param string    startDate     Startdatum
     *
     * @author Oleg Manov
     */
    private function proceedDriver($driverId, $startDate)
    {
        $svg = "\"";
        $drivers = $this->dbQuery->getDriverInfoAllowances($driverId);
        //var_dump($driver);
        //$this->dbQuery->createDriverTables($driverId);
        if (!is_null($drivers))
        {
            foreach ($drivers as $driver)
            {
                echo (GREEN . 'Fahrer ID: ' . $driver['idx'] . RESET . "\n");
                if (!is_null($driver['dtco_driver_idx']))
                {
                    $lastShift = $this->dbQuery->getLastShift($driver['idx']);
                    if (!is_null($lastShift['dateStart']))
                    {
                        //$startDate = $lastShift['dateStart'];
                        echo GREEN . "Letzte Schicht: " . $startDate . RESET . "\n";
                    }
                    else
                    {
                        echo BLUE . "Start Date: " . $startDate . RESET . "\n";
                    }
                    $activities     = null;
                    $driverCardIds  = explode(',', $driver['dtco_driver_idx']);
                    if (!is_null($driverCardIds) && count($driverCardIds) > 0)
                    {
                        echo (GREEN . 'Fahrerkarten: ' . $driver['dtco_driver_idx'] . RESET . "\n");
                        $activities = $this->driverCtrl->getDriverActivities($driverCardIds, $startDate, true);
                        //var_dump($activities);
                    }
                    else
                    {
                        //exit(4);
                        echo (RED . 'Fehler mit der Fahrerkarte! Fahrer: ' . $driver['idx'] . RESET . "\n");
                    }

                    if (!is_null($activities) && count($activities) > 0)
                    {
                        $workingTimes = $this->calculateWorkingTimes($driver, $activities);
                        //exit(6);
                        if (!empty($workingTimes))
                        {
                            $this->proceedWorkingTimes($driver, $startDate, $workingTimes);
                            echo (YELLOW . "Done!" . RESET . "\n");
                        //  exit(7);
                        }
                        else
                        {
                            //exit(5);
                        }
                    }
                    else
                    {
                    //  exit(3);
                        echo (RED .'Fahrer '.$driver['idx'].' hat keine Aktivitäten!' . RESET . "\n");
                    }
                }
                else
                {
                // exit(2);
                    echo (RED .'Fahrer '.$driver['idx'].' hat keine Fahrerkarte!' . RESET . "\n");
                }
            }
        }
        else
        {
          //  exit(1);
            echo RED . "Fahrer nicht gefunden!" . RESET . "\n";
        }

        return;
    }

    /**
     * Die Funktion berechnet die Arbeitszeiten ab dem gegebenen Datum.
     *
     * @param array    $driver         Fahrerdaten
     * @param array    $activities     Aktivitäten des Fahrers
     * 
     * @return array    Die Arbeitszeiten des Fahrers
     * 
     * @author Oleg Manov
     */ 
    private function calculateWorkingTimes($driver, $activities)
    {
        echo (YELLOW . "calculating workingtimes..." . RESET . "\n");
        $workingTimes = array();
        $shiftObj = $this->getEmptyShiftObj();
        $lastActivity = null;
        //die nach Zeit sortierten Aktivitäten werden einzeln betrachtet
        foreach ($activities as $activity)
        {
            // suche nach der ersten Aktivität, die keine Ruhezeit ist
            if (is_null($shiftObj['date_start']) && !($activity['activity'] == 0 && $activity['duration'] > 453))
            {
                $shiftObj['date_start'] = $activity['date_start_utc'];
            }
            // wenn Dauer > 2/3 von 11 Stunden ist und es einen Anfang gibt -> neue Schicht
            if ($activity['activity'] == 0 && $activity['duration'] > 453 && !is_null($shiftObj['date_start']))
            {
                $shiftObj['date_end'] = $activity['date_start_utc'];
                $shiftObj['working_time'] = $shiftObj['a0'] + $shiftObj['a1_team'] + $shiftObj['a2'] + $shiftObj['a3'];
                $shiftObj['duration_all'] = $shiftObj['working_time'] + $shiftObj['a1'] + $shiftObj['a015'];
                if ($shiftObj['working_time'] > 600) {
                    $shiftObj['exceeding'] = $shiftObj['working_time'] - 600;
                }
                $shiftObj['daily_rest_time'] = min((1440 - $shiftObj['duration_all']), $activity['duration']);
                $shiftObj['rest_time_all'] = $activity['duration'];
                if ($activity['duration'] >= 2700) $shiftObj['rest_time_type'] = 1;
                if ($driver['client_idx'] == 13)
                {
                    $shiftObj = $this->getRoundedTime($shiftObj);
                }
                array_push($workingTimes, $shiftObj);
                $shiftObj = $this->getEmptyShiftObj();
            }
            // sonst wenn keine Ruhezeit -> Zeiten addieren
            elseif (!($activity['activity'] == 0 && $activity['duration'] > 453))
            {
                if ($activity['activity'] == 0 && $activity['duration'] >= 15)
                {
                    $activityType = 'a015';
                    if ($activity['duration'] >= 180)
                    {
                        $shiftObj['break_3h'] = 1;
                    }
                }
                elseif ($activity['activity'] == 1 && $activity['team'] == 1)
                {
                    $activityType = 'a1_team';
                }
                else
                {
                    $activityType = 'a'.$activity['activity'];
                }

                $shiftObj[$activityType] += $activity['duration'];
                if ($shiftObj['duration_all'] > 60 && $activity['team'] == 0 && $activity['duration'] > 5)
                {
                    $shiftObj['team_mode'] = 0;
                }
                $shiftObj['duration_all'] += $activity['duration'];
            }
            $lastActivity = $activity;
        }
        // die letze Aktivitätet wird bearbeitet
        $lastActivity = end($activities);
        // Pausen am ende der Schicht werden rausgenommen
        if ($lastActivity['activity'] == 0)
        {
            $shiftObj['date_end'] = $lastActivity['date_start_utc'];
            ($activity['duration'] >= 15) ? $shiftObj['a015'] -= $activity['duration']: $shiftObj['a0'] -= $activity['duration'];
        }
        else
        {
            $shiftObj['date_end'] = $lastActivity['date_end_utc'];
        }
        // die letze Aktivitätet wird zur Schicht hinzugefügt
        if (!is_null($shiftObj['date_start']))
        {
            $shiftObj['working_time'] = $shiftObj['a0'] + $shiftObj['a1_team'] + $shiftObj['a2'] + $shiftObj['a3'];
            $shiftObj['duration_all'] = $shiftObj['working_time'] + $shiftObj['a1'] + $shiftObj['a015'];
            if ($shiftObj['working_time'] > 600) {
                $shiftObj['exceeding'] = $shiftObj['working_time'] - 600;
            }
            $shiftObj['daily_rest_time'] = 1440 - $shiftObj['duration_all'];
            //Die Zeiten werden abgerundet
            if ($driver['client_idx'] == 13)
            {
                $shiftObj = $this->getRoundedTime($shiftObj);
            }
            array_push($workingTimes, $shiftObj);
        }

        //wir nehmen an, dass der Fahrer eine normale WRZ hat
        if(count($workingTimes) > 0)
        {
            end($workingTimes);         // setzt der Pointer auf das letzte Element
            $key = key($workingTimes);  // get key
            $workingTimes[$key]['rest_time_type'] = 1;
        }
        return $workingTimes;
    }

    /**
     * Die Funktion bearbeitet und speichert die Arbeitszeiten.
     * Spesenorte werden eingetragen.
     * Alte Schichten werden gelöscht.
     *
     * @param array     $driver         Fahrerdaten
     * @param string    $startDate      Startdatum
     * @param array     $workingTimes   Arbeitszeiten des Fahrers
     * 
     * @author Oleg Manov
     */ 
    private function proceedWorkingTimes($driver, $startDate, $workingTimes)
    {
        //Schichten werden zusammengefasst und danach gespeichert
        $workingTimes = $this->mergeShifts($workingTimes);
        $workingTimesValues = '';
        foreach ($workingTimes as $shift)
        {
            is_null($shift['payment_start_manual']) ? $pStartMan = "null" : $pStartMan = "'".$shift['payment_start_manual']."'";
            is_null($shift['payment_end_manual']) ? $pEndMan = "null" : $pEndMan = "'".$shift['payment_end_manual']."'";
            is_null($shift["rest_time_all"]) ? $restTimeAll = 'null': $restTimeAll = $shift["rest_time_all"];
            $workingTimesValues .= "('".$shift["date_start"]."','".$shift["date_end"]."',".$shift["duration_all"].",".
            $shift["a3"].",".$shift["a2"].",".$shift["a1"].",".$shift["a1_team"].",".$shift["a0"].",".$shift["a015"].",".
            $shift["working_time"].",".$shift["exceeding"].",".$shift["daily_rest_time"].",".$restTimeAll.",";
            is_null($shift["rest_time_type"]) ? $workingTimesValues .= "null,": $workingTimesValues .= $shift["rest_time_type"].",";
            $workingTimesValues .= $shift["break_3h"].",".$shift["team_mode"].",".$pStartMan.",".$pEndMan.",".$shift['duration_all_manual']."),";
        }
        //var_dump(rtrim($workingTimesValues, ', '));
        //$this->dbQuery->deleteWorkingTimes($driver['idx'], $startDate);
        //$this->dbQuery->truncateWorkingTimesTable($driver['idx']);
        //var_dump($workingTimes);
        $this->dbQuery->saveWorkingTimes($driver['idx'], rtrim($workingTimesValues, ', '));

        // Spesenorte automatisch eintragen, wenn der Fahrer solche bekommt.
        //TODO Spesenorte aus der Telematik eintragen.
        if ($driver['auto_expenses'] == 1)
        {
            $start = $this->dbQuery->getAEDate($driver["idx"], $startDate);
            echo (YELLOW . "AutoExpenses Date: ". $start["dateStart"] . RESET . "\n");
            if (is_null($driver['driver_types_idx']) || $driver['driver_types_idx'] == '' || strpos($driver['driver_types_idx'], '12') !== false)
            {
                echo (YELLOW . "set auto expenses NV..." . RESET . "\n");
                $this->dbQuery->updateExpensesPlaces($driver['idx'], $driver['home_zip'], $driver['home_zip'], $start["dateStart"]);
            }
            elseif (strpos($driver['driver_types_idx'], '11') !== false)
            {
                echo (YELLOW . "set auto expenses FV..." . RESET . "\n");
                $this->setAutoExpensesFV($driver, $workingTimes);
            }
        }
        return;
    }

    /**
     * Kurze Schichten, die eine Gesamtdauer von bis zu 15 Stunden haben, werden zusammengefasst.
     *
     * @param   array     $workingTimes  Alle schichten
     * @return  array     Die zusammengefasste Arbeitszeiten des Fahrers
     *
     * @author Oleg Manov
     */ 
    private function mergeShifts($workingTimes)
    {
        //var_dump($workingTimes);
        $lastShift = null;
        $mergedShifts = array();
        $pushLastShift = true;
        foreach ($workingTimes as $shift)
        {
            if (!is_null($lastShift))
            {
                // Hier wird geprüft, ob die Dauer der letzten Schicht, die Dauer der aktuellen Schicht und die Ruhezeit dazwischen kleiner 15 Stunden sind.
                if (($lastShift['duration_all'] + $lastShift['rest_time_all'] + $shift['duration_all']) <= 900 && $lastShift['team_mode'] = $shift['team_mode'])
                {
                    $newShift = $this->getEmptyShiftObj();
                    $newShift['date_start'] = $lastShift['date_start'];
                    $newShift['date_end'] = $shift['date_end'];
                    $newShift['duration_all'] = $lastShift['duration_all'] + $lastShift['rest_time_all'] + $shift['duration_all'];
                    $newShift['a0'] = $lastShift['a0'] + $shift['a0'];
                    $newShift['a015'] = $lastShift['a015'] + $shift['a015']+$lastShift['rest_time_all'];
                    $newShift['a1'] = $lastShift['a1'] + $shift['a1'];
                    $newShift['a1_team'] = $lastShift['a1_team'] + $shift['a1_team'];
                    $newShift['a2'] = $lastShift['a2'] + $shift['a2'];
                    $newShift['a3'] = $lastShift['a3'] + $shift['a3'];
                    $newShift['working_time'] = $lastShift['working_time'] + $shift['working_time'];
                    $newShift['exceeding'] = 0;
                    $newShift['daily_rest_time'] = $shift['daily_rest_time'];
                    $newShift['rest_time_all'] = $shift['rest_time_all'];
                    $newShift['rest_time_type'] = $shift['rest_time_type'];
                    $newShift['break_3h'] = 1;
                    $newShift['team_mode'] = $shift['team_mode'];
                    if ($pushLastShift == false) 
                    {
                        array_pop($mergedShifts);
                    }
                    array_push($mergedShifts, $newShift);
                    $pushLastShift = false;
                    $lastShift = $newShift;
                }
                else
                {
                    if ($pushLastShift == true) 
                    {
                        array_push($mergedShifts, $lastShift);
                    }
                    $pushLastShift = true;
                    $lastShift = $shift;
                }
            }
            else
            {
                $lastShift = $shift;
            }
        }

        if ($pushLastShift == true)
        {
            array_push($mergedShifts, $lastShift);
        }

        //var_dump($mergedShifts);
        return $mergedShifts;
    }

    /**
     * Spesenorte bei Fernverkehrfahrer automatisch eintragen.
     *
     * @param   array     $driver           Der Fahrer
     * @param   array     $workingTimes     Die Arbeitszeiten
     * @param   string    $startDate        Das Startdatum
     *
     * @author Oleg Manov
     */ 
    public function setAutoExpensesFV($driver, $workingTimes)
    {
        //var_dump($workingTimes);
        $lastShift = null;
        foreach ($workingTimes as $shift) {
            $placeStart = '';
            $placeEnd = '';
            if (is_null($lastShift) || !is_null($lastShift['rest_time_type']) || $lastShift['rest_time_all'] > 1440)
            {
                $placeStart = $driver['home_zip'];
            }
            else 
            {
                $placeStart = 'unterwegs';
            }

            if(!is_null($shift['rest_time_type']) || $shift['rest_time_all'] > 1440)
            {
                $placeEnd = $driver['home_zip'];
            }
            else
            {
                $placeEnd = 'unterwegs';
            }
            $this->dbQuery->updateExpensesPlaces($driver['idx'], $placeStart, $placeEnd, $shift['date_start'], true);
            $lastShift = $shift;
        }
        return;
    }

    /**
     * Rundet die Start- und Endzeiten der Schicht auf.
     *
     * @param   array     $shiftObj  Die Schicht
     * @return  array     Die Schicht mit aufgerundeten Zieten
     *
     * @author Oleg Manov
     */ 
    private function getRoundedTime($shiftObj)
    {
        $startDate = new \DateTime($shiftObj['date_start']);
        $endDate = new \DateTime($shiftObj['date_end']);
        $startMinRest = intval($startDate->format('i')) % 15;
        if ($startMinRest > 0 && $startMinRest < 8)
        {
            $shiftObj['payment_start_manual'] = $startDate->modify('-'.$startMinRest.' minute')->format('Y-m-d H:i:s');
            $shiftObj['duration_all_manual'] = $shiftObj['duration_all'] + $startMinRest;
        }
        elseif($startMinRest > 7)
        {
            $startMinRest = 15 - $startMinRest;
            $shiftObj['duration_all_manual'] = $shiftObj['duration_all'] - $startMinRest;
            $shiftObj['payment_start_manual'] = $startDate->modify('+'.$startMinRest.' minute')->format('Y-m-d H:i:s');
        }
        else
        {
            $shiftObj['duration_all_manual'] = $shiftObj['duration_all'];
        }
        $endMinRest = $endDate->format('i') % 15;
        if ($endMinRest > 0 && $endMinRest < 8)
        {
            $shiftObj['duration_all_manual'] -= $endMinRest;
            $shiftObj['payment_end_manual'] = $endDate->modify('-'.$endMinRest.' minute')->format('Y-m-d H:i:s');
        }
        elseif($endMinRest > 7)
        {
            $endMinRest = 15 - $endMinRest;
            $shiftObj['duration_all_manual'] += $endMinRest;
            $shiftObj['payment_end_manual'] = $endDate->modify('+'.$endMinRest.' minute')->format('Y-m-d H:i:s');
        }

        return $shiftObj;
    }

    private function getEmptyShiftObj()
    {
        $shiftObj['date_start'] = null;
        $shiftObj['date_end'] = null;
        $shiftObj['payment_start_manual'] = null;
        $shiftObj['payment_end_manual'] = null;
        $shiftObj['duration_all'] = 0;
        $shiftObj['duration_all_manual'] = 0;
        $shiftObj['a0'] = 0;
        $shiftObj['a015'] = 0;
        $shiftObj['a1'] = 0;
        $shiftObj['a1_team'] = 0;
        $shiftObj['a2'] = 0;
        $shiftObj['a3'] = 0;
        $shiftObj['working_time'] = 0;
        $shiftObj['exceeding'] = 0;
        $shiftObj['daily_rest_time'] = null;
        $shiftObj['rest_time_all'] = null;
        $shiftObj['rest_time_type'] = null;
        $shiftObj['break_3h'] = 0;
        $shiftObj['team_mode'] = 1;

        return $shiftObj;
    }
}