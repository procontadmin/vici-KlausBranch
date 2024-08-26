<?php 

include_once 'core/CoreDbQueries.php';
include_once 'core/DriverController.php';

define ("RED",   "\033[1;31m");
define ("GREEN", "\033[1;32m");
define ("YELLOW","\033[1;33m");
define ("BLUE",  "\033[1;34m");
define ("RESET", "\033[0m");

set_time_limit(90000);
$start = microtime(true);

$stm = new ShiftTimesMapper;
$stm->run();

$timeElapsed = microtime(true) - $start;
echo YELLOW . $timeElapsed . "\n";
echo 'Fertig!' . RESET . "\n";

class ShiftTimesMapper
{
    /**
     * The DbQuery object
     *
     * @var DbQueries
     */
    protected $dbQuery = null;

    public function __construct()
    {
    }

    /**
     * Die Funktion überprüft die Eingangsparameter und 
     * startet das Schicht-Mapping pro Fahrer 
     *
     * @param int       i    Fahrer Id
     * @param int       c    Client Id
     * @param string    d    Startdatum
     * 
     * @author Oleg Manov
     */ 
    public function run()
    {
        $options = getopt("d:i:c:");

        if (array_key_exists("d", $options) && array_key_exists("c", $options))
        {
            $startDate  = $options['d'];
            $clientId   = $options['c'];
            $this->dbQuery = new CoreDbQueries(0);
            isset($options['i']) ? $driverId = $options['i'] : $driverId = null;

            if (is_null($driverId))
            {
                $allDrivers = $this->dbQuery->getAllClientDrivers($clientId);

                if(!is_null($allDrivers))
                {
                    foreach ($allDrivers as $driver)
                    {
                        $this->mappShiftTimes($clientId, $driver['driver_idx'], $startDate);
                    }
                }
            }
            else
            {
                $this->mappShiftTimes($clientId, $driverId, $startDate);
            }
        }
        else
        {
            echo RED . "Parameter ist nicht gesetzt!" . RESET . "\n";
        }

        return;
    }

    /**
     * Die Funktion mappt alle Spesenorten für einen bestimmten Fahrer
     *
     * @param int       driverId    Fahrer Id
     * @param int       clientId    Client Id
     * @param string    startDate   Startdatum
     * 
     * @author Oleg Manov
     */
    public function mappShiftTimes($clientId, $driverId, $startDate)
    {
        echo (GREEN . 'Fahrer ID: ' . $driverId . RESET . "\n");
        $telematic = $this->dbQuery->hasTelematic($clientId);
        !is_null($telematic) && count($telematic)>0 && !is_null($telematic[0]['time_zone']) ? $timezone = $telematic[0]['time_zone'] : $timezone = 'CET';
        $workingTimes = $this->dbQuery->getWorkingTimesForMapper($driverId, $startDate, $timezone, -180, 180);

        if (!is_null($workingTimes) && count($workingTimes)>0)
        {
            if ($telematic && $telematic[0]['provider'] != 'manually'){
                echo (YELLOW . "Schichtszeiten mappen..." . RESET . "\n");
                //$start = microtime(true);
                $allShiftTimes = $this->dbQuery->getSchiftTimes($clientId.'', $driverId, $startDate);
                //$timeElapsed = microtime(true) - $start;
                //echo (YELLOW . $timeElapsed . RESET . "\n" );
                if (!is_null($allShiftTimes) && count($allShiftTimes)>0)
                {
                    $roundedTimes = $this->getRoundedTimes($allShiftTimes);
                    foreach ($workingTimes as $shift) {
                        //$start = microtime(true);
                        $start = new DateTime($shift['payment_start']);
                        $end = new DateTime($shift['payment_end']);
                        $realStart = new DateTime($shift['payment_start_real']);
                        $realEnd = new DateTime($shift['payment_end_real']);
                        $shiftTimes = $this->getSchiftTimes($roundedTimes, $start, $end);
                        //$timeElapsed = microtime(true) - $start;
                        //echo (YELLOW . $timeElapsed . RESET . "\n" );
                        if (!is_null($shiftTimes) && count($shiftTimes) > 0)
                        {
                            echo '+';
                            $newStart = null;
                            $newEnd = null;
                            //echo 'Schift: ' . $shift['payment_start'] . ' - ' . $shift['payment_end'] . "\n";
                            foreach($shiftTimes as $shiftTime)
                            {
                               // echo '+ ' . $shiftTime['time_stamp']->format('Y-m-d H:i:s') . ' - ' .$shiftTime['shift'] . "\n";
                                if ($shiftTime['shift'] == 1)
                                {
                                    if ($shiftTime['time_stamp'] <= $realStart && (is_null($newStart) || $shiftTime['time_stamp'] <= $newStart))
                                    {
                                        $newStart = $shiftTime['time_stamp'];
                                    }
                                }
                                elseif ($shiftTime['shift'] == 0)
                                {
                                    if ($shiftTime['time_stamp'] >= $realEnd && (is_null($newEnd) || $shiftTime['time_stamp'] <= $newEnd))
                                    {
                                        $newEnd = $shiftTime['time_stamp'];
                                    }
                                }
                            }
                            
                            $durationManual = $shift['duration_all_real'];
                            if (!is_null($newStart))
                            {
                                //echo 'NEW START! ' . $newStart->format('Y-m-d H:i:s') . 'OLD START: ' . $shift['payment_start_real_f'] . "\n ";
                                $durationManual += intval((strtotime($realStart->format('Y-m-d H:i:s')) / 60)) - intval((strtotime($newStart->format('Y-m-d H:i:s')) / 60));
                            }
                            if (!is_null($newEnd))
                            {
                                //echo 'NEW END! ' . $newEnd->format('Y-m-d H:i:s') . 'OLD END: ' . $shift['payment_end_real'] . "\n ";
                                $durationManual += intval((strtotime($newEnd->format('Y-m-d H:i:s')) / 60)) - intval((strtotime($realEnd->format('Y-m-d H:i:s')) / 60));
                            }
                            if (!is_null($newStart) || !is_null($newEnd))
                                $this->dbQuery->updateShiftTimes($driverId, $shift['idx'], (is_null($newStart) ? null : $newStart->format('Y-m-d H:i:s')), (is_null($newEnd) ? null : $newEnd->format('Y-m-d H:i:s')), $durationManual);
                        }
                        else
                        {
                            echo '.';
                        }
                    }
                    echo "\n";
                }
                else
                {
                    echo RED . 'Fahrer ' . $driverId . ' hat keine Geo-Positionen!' . RESET . "\n";
                }
            }
        }
        echo (YELLOW . "Fahrer ist bearbeitet!" . RESET . "\n");

        return;
    }

    private function getRoundedTimes($times)
    {
        $roundedTimes = array();
        foreach($times as $time)
        {
            //var_dump($time);
            $time['time_stamp'] = new DateTime(date('Y-m-d H:i:s', intval((round(strtotime($time['time_stamp']) / 60) * 60))));
            //var_dump($time);
            array_push($roundedTimes, $time);
        }

        return $roundedTimes;
    }

    /* nm 
     * 
     *
     * 
     * @return 
     */
    private function getSchiftTimes($allShiftTimes, $start, $end)
    {
        //print "============================================\n";
       // print "Shift: ".$start->format('Y-m-d H:i:s').' - '.$end->format('Y-m-d H:i:s')."\n";
        $shiftTimes = array();
        foreach ($allShiftTimes as $time)
        {
            //print $time['time_stamp']->format('Y-m-d H:i:s') . ' - ' . $time['shift'] ."\n";
            if ($time['time_stamp'] >= $start && $time['time_stamp'] <= $end)
            {
                //print $time['time_stamp']->format('Y-m-d H:i:s') . ' - ' . $time['shift'] ."\n";
                array_push($shiftTimes, $time);
            }
        }

        return $shiftTimes;
    }
}