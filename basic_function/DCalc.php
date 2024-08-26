<?php 

date_default_timezone_set('UTC');
set_time_limit(90000);

class DCalc
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

    /**
     * The Driver ID
     *
     * @var array
     */
    protected $driver = null;

    public function __construct($driver, $dbQuery, $driverCtrl)
    {
        $this->driver = $driver;
        $this->dbQuery = $dbQuery;
        $this->driverCtrl = $driverCtrl;
    }

    /**
     * Die Funktion überprüft die Eingangsparameter, findet die Lohngruppen
     * und startet die Berechnungen der Lohnbasisdaten.
     *
     * @param string    startDate    Startdatum
     * 
     * @author Oleg Manov
     */ 
    public function run($startDate)
    {
        $start = microtime(true);

        $this->proceedDriver($this->driver, $startDate);
    
        $timeElapsed = microtime(true) - $start;
        echo (YELLOW . $timeElapsed . RESET . "\n" );

        return;
    }

     /**
     * Die Funktion überprüft den Fahrer, die Fahrerkarten und die Aktivitäten des Fahrers
     * und startet die Berechnung der Lohnbasisdaten ab der letzten Lohntag des Fahrers,
     * oder ab dem gegebenen Datum, wenn der Fahrer keine Lohntage hat
     *
     * @param int       driver       Fahrer
     * @param string    startDate     Startdatum
     *
     * @author Oleg Manov
     */
    private function proceedDriver($driver, $startDate)
    {
        if (!is_null($driver))
        {
            echo (GREEN . 'Fahrer ID: ' . $driver['idx'] . RESET . "\n");
            if (!is_null($driver['dtco_driver_idx']))
            {
                $lastDocket = $this->dbQuery->getLastDocketDay($driver['idx']);
                if (!is_null($lastDocket['dateStart']))
                {
                  //  $startDate = $lastDocket['dateStart'];
                    echo GREEN . "Letzter Lohntag: " . $startDate . RESET . "\n";
                }
                else
                {
                    echo BLUE . "Start Date: " . $startDate . RESET . "\n";
                }
                $dateStart = new \DateTime($startDate." 00:00:00");

                $driver['parent_idx'] == 0 ? $cid = $driver['client_idx'] : $cid = $driver['parent_idx'];
                //var_dump(strpos($driver['driver_types_idx'], '9'));

                $wageGroups = $this->dbQuery->getClientPaymentGroups($cid, 2, $driver['idx']);
                //var_dump($wageGroups);
                if (!is_null($wageGroups) && count($wageGroups)>0)
                {
                    foreach ($wageGroups as $key => $wageGroup)
                    {
                        is_null($wageGroup['time_start']) ? $wageGroups[$key]['start_timestamp'] = null:
                        $wageGroups[$key]['start_timestamp'] = strtotime($wageGroup['time_start']);
                        is_null($wageGroup['additional_time_start']) ? $wageGroups[$key]['additional_start_timestamp'] = null :
                        $wageGroups[$key]['additional_start_timestamp'] = strtotime($wageGroup['additional_time_start']);
                        is_null($wageGroup['time_end']) ? $wageGroups[$key]['end_timestamp'] = null:
                        $wageGroups[$key]['end_timestamp'] = strtotime($wageGroup['time_end']);
                        is_null($wageGroup['additional_time_end']) ? $wageGroups[$key]['additional_end_timestamp'] = null :
                        $wageGroups[$key]['additional_end_timestamp'] = strtotime($wageGroup['additional_time_end']);
                    }
                    $this->startCalculations($driver, $wageGroups, $dateStart->modify('+1 day')->format('Y-m-d H:i:s'));
                    if ($cid == 13 && ($driver['driver_types_idx'] == '11' || $driver['driver_types_idx'] == '12'))
                        $this->calculateNightHours($driver, $startDate);
                }
                else
                {
                    echo RED . "Keine Lohnarten gefunden!" . RESET . "\n";
                }
            }
            else
            {
               // exit(2);
                echo (RED .'Fahrer '.$driver['idx'].' hat keine Fahrerkarte!' . RESET . "\n");
            }
        }
        else
        {
            echo RED . "Fahrer nicht gefunden!" . RESET . "\n";
        }

        return;
    }

   /**
     * Berechnet und speichert die Lohnbasisdaten ab dem gegebenen Datum.
     * Alte Lohnbasisdaten werden gelöscht.
     *
     * @param array     $driver         Fahrerdaten
     * @param array     $wageGroups     Lohngruppen des Kundes
     * @param string    $startDate      Startdatum
     * 
     * @author Oleg Manov
     */ 
    private function startCalculations($driver, $wageGroups, $startDate)
    {
        $midnight24     = strtotime('24:00:00');
        $midnight0      = strtotime('00:00:00');
        $lastActivity   = null;
        $activities     = null;

        $driverCardIds = explode(',', $driver['dtco_driver_idx']);
        echo GREEN . $startDate . RESET . "\n";

        // Wenn der Fahrer mindestens eine FK hat, werden die Aktivitäten genommen
        if (!is_null($driverCardIds) && count($driverCardIds) > 0)
        {
            //var_dump($driverCardIds);
            if ($driver['client_idx'] == 13)
            {
                $activities = $this->getShiftsAsOneActivity($driver['idx'], $startDate, true);
            }
            else
            {
                $activities = $this->driverCtrl->getDriverActivitiesD($driverCardIds, $startDate, true);
                
            }
        }
        else
        {
            //exit(4);
            echo (RED . 'Fehler mit der Fahrerkarte! Fahrer: ' . $driver['idx'] . RESET . "\n");
        }
//var_dump($activities);
        // Wenn der Fahrer Aktivitäten hat, dann werden diese bearbeitet
        if (!is_null($activities) && count($activities)>0)
        {
            echo (YELLOW . "calculating dockets..." . RESET . "\n");

            $wageTimesByDays = array();
            $wageDay = $this->getEmptyDayObj($activities[0]['start_date'], $wageGroups);
            $shiftBeginn = $startDate;

            foreach ($activities as $activity)
            {
                // driver_activites in payment_groups mapping
                // 1	Lenken
                // 2	Arbeit
                // 3	Bereitschaft einzeln
                // 4	Bereitschaft Team
                // 5	Pause kleiner 15 Min.
                // 6	Pause größer gleich 15 Min.
                $activity['t_start'] = (new \DateTime($activity['date_start']))->format('H:i:s');
                $activity['t_end'] = (new \DateTime($activity['date_end']))->format('H:i:s');
                //print '.';
                // lange Bereitschafts- oder Arbeitsblocke werden als Pause betrachtet
                if (!is_null($lastActivity) &&
                    ($activity['activity'] == 1 || $activity['activity'] == 2) &&
                    !($lastActivity['activity'] == 0 && $lastActivity['duration'] > 453) &&  $activity['duration'] > 300)
                {
                    $activity['activity'] = 0;
                }

                // Pausen länger 15 Min und Bereitschaft (nicht im Team) sind keine Arbeitszeit
                 if ((!(($activity['activity'] == 0 && $activity['duration'] >= 15) || ($activity['activity'] == 1 && $activity['team'] == 0)) && $driver['client_idx'] != 507) ||
                    (!(($activity['activity'] == 0 || $activity['activity'] == 1)) && $driver['client_idx'] == 507))
                {
                    if (!isset($activity['t_start'])) 
                    {
                        $activity['t_start'] = (new \DateTime($activity['date_start']))->format('H:i:s');
                        $activity['t_end'] = (new \DateTime($activity['date_end']))->format('H:i:s');
                    }
                    $activity['start_timestamp'] = strtotime($activity['t_start']);
                    $activity['end_timestamp'] = strtotime($activity['t_end']);
                    // Endet die Aktivität am nächsten Tag, wird diese gesplittet. Danach wird diese bearbeitet.
                    if ($activity['start_date'] != $activity['end_date'])
                    {
                        // ein neuer Tag
                        $sameDayActivity = $activity;
                        $timeDiff = ($midnight24 - $activity['start_timestamp'])/60;
                        $sameDayActivity['duration'] = $timeDiff;
                        $sameDayActivity['date_end'] = $activity['start_date']." 24:00:00";
                        $wageDay = $this->proceedActivity($wageGroups, $sameDayActivity, $wageDay, $activity['start_timestamp'], $midnight24, $shiftBeginn);
                        array_push($wageTimesByDays, $wageDay);
                    
                        $wageDay = $this->getEmptyDayObj($activity['end_date'], $wageGroups);
                        $nextDayActivity = $activity;
                        $nextDayActivity['duration'] = $activity['duration'] - $timeDiff;
                        $nextDayActivity['date_start'] = $activity['end_date']." 00:00:00";

                        $wageDay = $this->proceedActivity($wageGroups, $nextDayActivity, $wageDay, $midnight0, $activity['end_timestamp'], $shiftBeginn);
                    }
                    else
                    {
                        // der gleiche Tag
                        $wageDay = $this->proceedActivity($wageGroups, $activity, $wageDay, $activity['start_timestamp'], $activity['end_timestamp'], $shiftBeginn);
                    }
                }
                else
                {
                    // Endet die Pause/Ruhezeit/Bereitschaft am nächsten Tag, dann wird der Abrechnungstag zu den anderen Tagen hinzugefügt
                    if ($activity['start_date'] != $activity['end_date'])
                    {
                        // ein neuer Tag nach eine längere Pause/Bereitschaft
                        array_push($wageTimesByDays, $wageDay);
                        $wageDay = $this->getEmptyDayObj($activity['end_date'], $wageGroups);
                    }

                    // Wenn die Pause länger 2/3 von 11 Stunden ist -> neue Schicht
                    if ($activity['duration'] > 453)
                    {
                        $shiftBeginn = $activity['end_date'];
                    }
                }
            }

            array_push($wageTimesByDays, $wageDay);
            
            // Values für die SQL-Abfrage werden gebaut
            $wageTimes = '';
            //var_dump($wageTimesByDays);
            foreach ($wageTimesByDays as $wageDay) 
            {
                foreach ($wageGroups as $wageGroup)
                {
                    if ($wageDay[$wageGroup['idx']] > 0)
                    {
                        $wageTimes .= "('" . $wageDay['date'] . "'," . $wageGroup['idx'] . ",0," . $wageDay[$wageGroup['idx']] ."), ";
                    }
                }
            }

            $this->dbQuery->deleteWageTimes($driver['idx'], "'".$startDate."'");
            $this->dbQuery->saveWageTimes($driver['idx'], rtrim($wageTimes, ", "));
        }
        else
        {
            //  exit(3);
            echo (RED .'Fahrer '.$driver['idx'].' hat keine Aktivitäten!' . RESET . "\n");
        }

        return;
    }

    /**
     * Bearbeitet die gegebene Aktivität. Es wird geprüft, zu welcher Lohngruppe die Aktivität passt. 
     * Eigenschaften, die überprüft werden sind: Zeitraum, Tag, Abfahrt vor Mitternacht.
     * TODO: Fahrergruppen, Aktivitäten-Typen, global oder Fahrer zugeordnet.
     *
     * @param array     $wageGroups     Lohngruppen des Kundes
     * @param array     $activity       Aktivität, die bearbeitet wird
     * @param array     $wageDay        Lohntag
     * @param string    $startDateTime  Startzeit
     * @param string    $endDateTime    Endzeit
     * @param string    $shiftBeginn    Beginn der Schicht
     * 
     * @author Oleg Manov
     */ 
    private function proceedActivity($wageGroups, $activity, $wageDay, $startDateTime, $endDateTime, $shiftBeginn)
    {
        $proceeded = false;
        //var_dump('_____________________');
        //var_dump($activity);
        // Überprüft, ob die Aktivität an einem Feiertag war
        foreach ($wageGroups as $wageGroup)
        {
            if(!is_null($wageGroup['days_idx']) && $this->checkHolidayCompliance($wageGroup, $wageDay['date']))
            {
                $wageDay[$wageGroup['idx']] += $activity['duration'];
                $proceeded = true;
            }
        }

        // Wenn die Aktivität bei keinem Feiertag passt, wird diese weiter berarbeitet
        if ($proceeded == false)
        {
            //var_dump('in');
            foreach ($wageGroups as $wageGroup)
            {
                // Überprüft, ob die Aktivität an einem Wochentag war und ob die Abfahrt stimmt.
                // Dann werden folgenden 4 Fälle überprüft:
                // 1: 
                // 2:
                // 3:
                // 4:
                if(!is_null($wageGroup['days_idx']) && $this->checkWeekDayCompliance($wageGroup, $wageDay['date']) && 
                 ($wageGroup['begin_before_midnight'] === '2' ||
                    ($wageGroup['begin_before_midnight'] === '1' && $shiftBeginn !== $activity['start_date']) ||
                    ($wageGroup['begin_before_midnight'] === '0') && $shiftBeginn === $activity['start_date']))
                {
                    if ($startDateTime >= $wageGroup['start_timestamp'] && $startDateTime <= $wageGroup['end_timestamp'])
                    {
                        if ($endDateTime <= $wageGroup['end_timestamp'])
                        {
                            // Fall 1
                            //var_dump('f1');
                            $wageDay[$wageGroup['idx']] += $activity['duration'];
                        }
                        else
                        {
                            // Fall 2
                            //var_dump('f2');
                            //var_dump($activity);
                            $timeDiff = ($wageGroup['end_timestamp'] - $startDateTime)/60;
                            //var_dump($timeDiff);
                            $wageDay[$wageGroup['idx']] += $timeDiff;
                        }
                    }
                    elseif ($startDateTime <= $wageGroup['start_timestamp'] && $endDateTime >= $wageGroup['start_timestamp'])
                    {
                        if ($endDateTime >= $wageGroup['end_timestamp'])
                        {
                            // Fall 3
                            //var_dump('f3');
                            $wageDay[$wageGroup['idx']] += ($wageGroup['end_timestamp'] - $wageGroup['start_timestamp'])/60;
                        }
                        else
                        {
                            // Fall 4
                            //var_dump('f4');
                            $timeDiff = ($endDateTime - $wageGroup['start_timestamp'])/60;
                            //var_dump($timeDiff);
                            $wageDay[$wageGroup['idx']] += $timeDiff;
                        }
                    }

                    if ($wageGroup['additional_time_start'] !== null && $wageGroup['additional_time_end'] !== null)
                    {
                        if ($startDateTime >= $wageGroup['additional_start_timestamp'] && $startDateTime <= $wageGroup['additional_end_timestamp'])
                        {
                            if ($endDateTime <= $wageGroup['additional_end_timestamp'])
                            {
                                // Fall 1
                                //var_dump('f1a');
                                $wageDay[$wageGroup['idx']] += $activity['duration'];
                            }
                            else
                            {
                                // Fall 2
                                //var_dump('f2a');
                                $timeDiff = ($wageGroup['additional_end_timestamp'] - $startDateTime)/60;
                                //var_dump($timeDiff);
                                //var_dump($activity);
                                $wageDay[$wageGroup['idx']] += $timeDiff;
                            }
                        }
                        elseif ($startDateTime <= $wageGroup['additional_start_timestamp'] && $endDateTime >= $wageGroup['additional_start_timestamp'])
                        {
                            if ($endDateTime >= $wageGroup['additional_end_timestamp'])
                            {
                                // Fall 3
                                //var_dump('f3a');
                                $timeDiff = ($wageGroup['additional_end_timestamp'] - $wageGroup['additional_start_timestamp'])/60;
                                $wageDay[$wageGroup['idx']] += $timeDiff;
                            }
                            else
                            {
                                // Fall 4
                                //var_dump('f4a');
                                $timeDiff = ($endDateTime - $wageGroup['additional_start_timestamp'])/60;
                                $wageDay[$wageGroup['idx']] += $timeDiff;
                            }
                        }
                    }
                }
            }
        }
        return $wageDay;
    }

    public function getShiftsAsOneActivity($driverId, $startDate, $changeUndefined)
    {
        $activities = array();
        $workingTimes = $this->dbQuery->getWorkingTimesCET($driverId, $startDate);
        //var_dump($workingTimes);
        if (!is_null($workingTimes))
        {
            $lastShift = null;
            foreach ($workingTimes as $shift)
            {
                if (!is_null($lastShift))
                {
                    $activity = array();
                    $activity['date_start'] = is_null($lastShift['payment_end_manual']) ? $lastShift['payment_end'] : $lastShift['payment_end_manual'];
                    $activity['date_end'] = is_null($shift['payment_start_manual']) ? $shift['payment_start'] : $shift['payment_start_manual'];
                    $activity['duration'] = 60;
                    $activity['activity'] = 0;
                    $dStart = new \DateTime($activity['date_start']);
                    $dEnd = new \DateTime($activity['date_end']);
                    $activity['t_start'] = $dStart->format('H:i:s');
                    $activity['t_end'] = $dEnd->format('H:i:s');
                    $activity['start_date'] = $dStart->format('Y-m-d');
                    $activity['end_date'] = $dEnd->format('Y-m-d');
                    if ($activity['date_start'] != $activity['date_end']) { array_push($activities, $activity); }
                }
                $activity = array();
                $activity['date_start'] = is_null($shift['payment_start_manual']) ? $shift['payment_start'] : $shift['payment_start_manual'];
                $activity['date_end'] = is_null($shift['payment_end_manual']) ? $shift['payment_end'] : $shift['payment_end_manual'];
                $activity['duration'] = ($shift['duration_all_manual'] == 0) ? $shift['duration_all'] : $shift['duration_all_manual'];
                $dStart = new \DateTime($activity['date_start']);
                $dEnd = new \DateTime($activity['date_end']);
                $activity['t_start'] = $dStart->format('H:i:s');
                $activity['t_end'] = $dEnd->format('H:i:s');
                $activity['start_date'] = $dStart->format('Y-m-d');
                $activity['end_date'] = $dEnd->format('Y-m-d');
                $activity['activity'] = 3;
                if ($activity['date_start'] != $activity['date_end']) { array_push($activities, $activity); }
                
                $lastShift = $shift;
            }
            $activity = array();
            $activity['date_start'] = is_null($lastShift['payment_end_manual']) ? $lastShift['payment_end'] : $lastShift['payment_end_manual'];
            $activity['date_end'] = is_null($shift['payment_start_manual']) ? $shift['payment_start'] : $shift['payment_start_manual'];
            $activity['duration'] = 60;
            $activity['activity'] = 0;
            $dStart = new \DateTime($activity['date_start']);
            $dEnd = new \DateTime($activity['date_end']);
            $activity['t_start'] = $dStart->format('H:i:s');
            $activity['t_end'] = $dEnd->format('H:i:s');
            $activity['start_date'] = $dStart->format('Y-m-d');
            $activity['end_date'] = $dEnd->format('Y-m-d');
            if ($activity['date_start'] != $activity['date_end']) { array_push($activities, $activity); }
        }

        return $activities;
    }

    private function checkHolidayCompliance($wageGroup, $date)
    {
        $dayDates = $this->dbQuery->getDayDates($wageGroup['days_idx'], 6);
        if (!is_null($dayDates))
        {
            foreach ($dayDates as $dayDate)
            {
                if ($dayDate['f_day_date'] == $date)
                {
                    return true;
                }
            }
        }
        return false;
    }

    private function checkWeekDayCompliance($group, $date)
    {
        $date = new \DateTime($date);
        $groupDays = explode(',', $group['days_idx']);
        if (in_array($date->format('N'), $groupDays))
        {
            return true;
        }
        return false;
    }

    private function getEmptyDayObj($date, $paymentGroups)
    {
        $wageDay['date'] = $date;
        foreach ($paymentGroups as $paymentGroup)
        {
            $wageDay[$paymentGroup['idx']] = 0;
        }
        return $wageDay;
    }

    private function calculateNightHours($driver, $startDate)
    {
        $workingTimes = null;
        if (!is_null($driver['dtco_driver_idx']))
        {
            $workingTimes = $this->dbQuery->getWorkingTimesExp($driver['idx'], $startDate);
        }
        else
        {
            echo (RED . 'Fehler mit der Fahrerkarte! Fahrer: ' . $driver['idx'] . RESET . "\n");
        }

        if (!is_null($workingTimes) && count($workingTimes) > 0)
        {
            echo (YELLOW . "calculating night hours ..." . RESET . "\n");
            $lastShift = null;
            $homeZip = explode(';', trim($driver['home_zip']));
            $wageTimeValues = '';

            foreach ($workingTimes as $shift)
            {
                if (!is_null($lastShift))
                {
                    if (!is_null($lastShift['place_end']) && !in_array($lastShift['place_end'], $homeZip))
                    {
                        $rest = (strtotime($shift['payment_start_db']) - strtotime($lastShift['payment_end_db']))/60;
                        if ($rest - 540 > 0)
                        {
                            $duration = $rest - 540;
                            $endMinRest = $duration % 15;
                            if ($endMinRest > 7)
                            {
                                $duration += 15-$endMinRest;
                            }
                            else
                            {
                                $duration -= $endMinRest;
                            }
                            $wageTimeValues .= "('".$lastShift['date_end']."',76,0,".$duration."), ";
                        }
                    }
                    $lastShift = $shift;
                }
                else
                {
                    $lastShift = $shift;  
                }
            }
            //var_dump(rtrim($wageTimeValues, ", "));
            if ($wageTimeValues != '')
                $this->dbQuery->saveWageTimes($driver['idx'], rtrim($wageTimeValues, ", "));
        }
        else
        {
            echo (RED .'Fahrer '.$driver['idx'].' hat keine Arbeitszeiten!' . RESET . "\n");
        }

        return;
    }
}