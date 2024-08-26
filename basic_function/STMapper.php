<?php 
include_once 'core/CoreDbQueries.php';

class STMapper
{
    /**
     * DbQuery Object
     *
     * @var CoreDbQueries
     */
    protected $dbQuery = null;

    /**
     * Fahrer
     *
     * @var array
     */
    protected $driver = null;


    public function __construct($driver, $dbQuery)
    {
        $this->driver = $driver;
        $this->dbQuery = $dbQuery;
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
    public function run($startDate)
    {
        $start = microtime(true);

        $this->driver['parent_idx'] == 0 ? $clientId = $this->driver['client_idx'] : $clientId = $this->driver['parent_idx'];
        $telematic = $this->dbQuery->hasTelematic($clientId);
        if (intval($this->driver['client_idx']) == 480 && !is_null($telematic) && count($telematic)>0 && $telematic[0]['provider'] != 'manually' && !is_null($telematic[0]['time_zone']))
        { 
            $this->mappShiftTimes($clientId.'', $this->driver['idx'], $startDate, $telematic[0]['time_zone']);
        }
        else
        {
            echo RED . 'Kein Telematikanbieter!' . RESET . "\n";
        }
        $timeElapsed = microtime(true) - $start;
        echo YELLOW . $timeElapsed . "\n";
        
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
    public function mappShiftTimes($clientId, $driverId, $startDate, $timezone)
    {
        usleep(5000);
        $telematic = $this->dbQuery->hasTelematic($clientId);
        $workingTimes = $this->dbQuery->getWorkingTimesForMapper($driverId, $startDate, $timezone, -180, 180);

        if (!is_null($workingTimes) && count($workingTimes)>0)
        {
            if ($telematic && $telematic[0]['provider'] != 'manually'){
                echo (YELLOW . "Schichtszeiten mappen..." . RESET . "\n");
                usleep(5000);
                //$start = microtime(true);
                $allShiftTimes = null;
                if ($clientId == 480)
                {
                    $driverTelematicId = $this->dbQuery->getTelematicDriverId($driverId);
                    //var_dump($driverTelematicId);
                    $allShiftTimes = $this->dbQuery->getShiftTimes($telematic[0]["telematic_id"], $driverTelematicId["telematic_driver_idx"], $startDate);
                }
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
                                
                                if ($shiftTime['shift'] == 1)
                                {
                                    //echo '+ 1 ' . $shiftTime['time_stamp']->format('Y-m-d H:i:s') . ' - ' .$shiftTime['shift'] . "\n";
                                    if ($shiftTime['time_stamp'] <= $realStart && (is_null($newStart) || $shiftTime['time_stamp'] <= $newStart) && 
                                        (is_null($shift['payment_start_real_manual']) || $shift['payment_start_real_manual'] == $shiftTime['time_stamp']->format('Y-m-d H:i:s')))
                                    {
                                        $newStart = $shiftTime['time_stamp'];
                                    }
                                }
                                elseif ($shiftTime['shift'] == 0)
                                {
                                   // echo '+ 0 ' . $shiftTime['time_stamp']->format('Y-m-d H:i:s') . ' - ' .$shiftTime['shift'] . "\n";
                                    if ($shiftTime['time_stamp'] >= $realEnd && (is_null($newEnd) || $shiftTime['time_stamp'] <= $newEnd) &&
                                        (is_null($shift['payment_end_real_manual']) || $shift['payment_end_real_manual'] == $shiftTime['time_stamp']->format('Y-m-d H:i:s')))
                                    {
                                        $newEnd = $shiftTime['time_stamp'];
                                    }
                                }
                            }

                            $durationManual = intval($shift['duration_all_real']);
                            if (!is_null($newStart))
                            {
                                //echo 'NEW START! ' . $newStart->format('Y-m-d H:i:s') . 'OLD START: ' . $shift['payment_start_real'] . "\n ";
                                //echo $durationManual. ' --- ' .intval(intval((strtotime($realStart->format('Y-m-d H:i:s')) / 60)) - intval((strtotime($newStart->format('Y-m-d H:i:s')) / 60)))."\n";
                                $durationManual += intval((strtotime($realStart->format('Y-m-d H:i:s')) / 60)) - intval((strtotime($newStart->format('Y-m-d H:i:s')) / 60));
                                //echo $durationManual."\n";
                            }
                            if (!is_null($newEnd))
                            {
                                //echo 'NEW END! ' . $newEnd->format('Y-m-d H:i:s') . 'OLD END: ' . $shift['payment_end_real'] . "\n ";
                                //echo $durationManual. ' --- ' .intval(intval((strtotime($newEnd->format('Y-m-d H:i:s')) / 60)) - intval((strtotime($realEnd->format('Y-m-d H:i:s')) / 60)))."\n";
                                $durationManual += intval((strtotime($newEnd->format('Y-m-d H:i:s')) / 60)) - intval((strtotime($realEnd->format('Y-m-d H:i:s')) / 60));
                                //echo $durationManual."\n";
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