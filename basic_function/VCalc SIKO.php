<?php 

set_time_limit(90000);

class VCalc
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
     * The Driver
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
     * Die Funktion überprüft die Eingangsparameter und startet die Berechnung 
     * der Verstöße
     *
     * @param int       i    Fahrer-Id
     * @param string    d    Startdatum
     * 
     * @author Oleg Manov
     */ 
    public function run($sDate)
    {
        $start = microtime(true);

        $startDate = new \DateTime($sDate);
        $lastShift = $this->dbQuery->getLastShiftV($this->driver['idx'], $startDate->modify('-2 months')->format('Y-m-d'));
        if (!is_null($lastShift['dateStart']))
        {
            $startDate = new \DateTime($lastShift['dateStart']);
        }
        echo GREEN . "Letzte Schicht Vio: " . $startDate->format('Y-m-d') . RESET . "\n";
        $this->startCalculations($this->driver, $startDate->format('Y-m-d'));
        
        $timeElapsed = microtime(true) - $start;
        echo (YELLOW . $timeElapsed . RESET . "\n" );
        return;
    }

    /**
     * Berechnet und speichert die Verstöße ab dem gegebenen Datum.
     * Alte Verstöße werden gelöscht.
     *
     * @param array     $driver  Fahrerdaten
     * @param string    $startDate Startdatum
     * 
     * @author Oleg Manov
     */ 
    private function startCalculations($driver, $startDate)
    {
        print (YELLOW. 'calculating violations... ' . RESET . "\n");
        $driverCardIds = explode(',', $driver['dtco_driver_idx']);
        $workingTimes = $this->dbQuery->getWorkingTimes($driver['idx'], $startDate);

        if (!is_null($driverCardIds) && count($driverCardIds) > 0 && !is_null($workingTimes) && count($workingTimes) > 0)
        {
            $violations = array();
            $dwtViolations = array();
            $lsViolations = $this->calculateLandSignViolation($driver, $driverCardIds, $workingTimes);
            $aawaViolations = $this->calculateAbsentAdditionAndWrongActivity($driver, $driverCardIds, $startDate);
            $dcViolations = $this->calculateDepartureControl($driver, $driverCardIds, $workingTimes);
            $wdtViolations = $this->calculateWeeklyDrivingTime($driver, $driverCardIds, $startDate);
            $wrtViolations = $this->calculateWeeklyRestTimes($driver['idx'], $driverCardIds, $startDate);
            $dViolations = $this->calculateDailyViolations($driver['idx'], $driverCardIds, $startDate);
            $dbViolations = $this->calculateDailyBreaksViolations($driver['idx'], $driverCardIds, $workingTimes);
            $wlwtViolations = $this->calculateWLWTViolations($driver['idx'], $driverCardIds, $workingTimes);
            $wldbViolations = $this->calculateWLDailyBreaksViolations($driver['idx'], $driverCardIds, $workingTimes);
            if (164 == $driver['client_idx'])
            {
                $dwtViolations = $this->calculateDailyWTViolations($driver['idx'], $driverCardIds, $workingTimes);
            }
            //var_dump($dbViolations);
            $violations = array_merge($lsViolations, $aawaViolations, $dcViolations, $wdtViolations, $wrtViolations,
                                        $dViolations, $dbViolations, $wlwtViolations, $wldbViolations, $dwtViolations);
            //var_dump($wdtViolations);
            $this->dbQuery->deleteViolations($driver['idx'], $startDate);
            $violationValues = '';
            foreach ($violations as $violation)
            {
                is_null( $violation['date_start']) ? $vioStart = 'null' : $vioStart = "'".$violation['date_start']."'";
                is_null( $violation['date_end']) ? $vioEnd = 'null' : $vioEnd = "'".$violation['date_end']."'";
                is_null( $violation['duration']) ? $vioDur = 'null' : $vioDur = "'".$violation['duration']."'";
                is_null( $violation['group']) ? $vioGro = 'null' : $vioGro = "'".$violation['group']."'";
                is_null( $violation['fine']) ? $vioFin = 'null' : $vioFin = "'".$violation['fine']."'";
                $violationValues .= "(". $violation['driver_idx'].", ". $violation['violation_idx'].", ".  $vioStart.", ". 
                    $vioEnd.", ".  $vioDur.", ".  $vioGro.", null, ".  $vioFin."), ";
            }
            //print rtrim($violationValues, ', ');
            if (count($violations) > 0) $this->dbQuery->saveViolations(rtrim($violationValues, ', '));
        }
        return;
    }

    public function calculateLandSignViolation($driver, $driverCardIds, $workingTimes)
    {
        print('Calculating LSV for driver #'.$driver['idx']."\n");
        $violations = array();
        //var_dump($driverCardIds);
        //var_dump($workingTimes);
        $arrKeys = array_keys($workingTimes);
        $lastKey = end($arrKeys);
        foreach ($workingTimes as $key => $shift)
        {
            //var_dump('IN');
            if (intval($shift['duration_driving']) > 0)
            {
                $landSignsStart = $this->dbQuery->getLandSigns($driverCardIds, $shift['payment_start_db']);
                $landSignsEnd = $this->dbQuery->getLandSigns($driverCardIds, $shift['payment_end_db']);
                // 3. if not there - violation
                if (is_null($landSignsStart) || count($landSignsStart) < 1)
                {
                    //var_dump($landSignsStart);
                    $violation = array('driver_idx' => $driver['idx'], 'violation_idx' => 15, 'date_start' => $shift['payment_start_db'],
                                        'date_end' => null, 'duration' => null, 'group' => null, 'addition' => null, 'fine' => 30);
                    array_push($violations, $violation);
                    //var_dump($violation);
                    //var_dump($shift['duration_driving']);
                }
                if ((is_null($landSignsEnd) || count($landSignsEnd) < 1) && 
                        !($key == $lastKey && (is_null($shift['rest_time_all']) || $shift['rest_time_all'] < 433))
                    )
                {
                    //var_dump($landSignsEnd);
                    $violation = array('driver_idx' => $driver['idx'], 'violation_idx' => 15, 'date_start' => $shift['payment_end_db'],
                                        'date_end' => null, 'duration' => null, 'group' => null, 'addition' => null, 'fine' => 30);
                    array_push($violations, $violation);
                    //var_dump($violation);
                    //var_dump($shift['duration_driving']);
                }
            }
        }
        return $violations;
    }

    public function calculateAbsentAdditionAndWrongActivity($driver, $driverCardIds, $startDate)
    {
        $violations = array();
        $activities = null;

        print('Calculating AA and WS for driver #'.$driver['idx']."\n");

        $activities = $this->driverCtrl->getDriverActivities($driverCardIds, $startDate);

        if (!is_null($activities) && count($activities) > 0)
        {
           // var_dump('IN - '.$driver['idx'].' count = '.count($activities));

            //die nach Zeit sortierten Aktivitäten werden einzeln betrachtet
            foreach ($activities as $activity)
            {
                // unbekannte Aktivitäten werden gesucht
                if ($activity['activity'] == 4 && $activity['duration'] > 30)
                {
                    $violation = array('driver_idx' => $driver['idx'], 'violation_idx' => 12, 'date_start' => $activity['date_end_utc'],
                                        'date_end' => null, 'duration' => $activity['duration'],
                                        'group' => null, 'addition' => null, 'fine' => 75);
                    array_push($violations, $violation);
                    //var_dump($violation);
                }
                elseif (($activity['activity'] == 1 || $activity['activity'] == 2) && $activity['duration'] > 360)
                {
                    ($activity['inserted'] == 0) ? $violationStart = $activity['date_start_utc'] : $violationStart = $activity['date_end_utc'];
                    $violation = array('driver_idx' => $driver['idx'], 'violation_idx' => 13, 'date_start' => $violationStart,
                    'date_end' => null, 'duration' => $activity['duration'], 'group' => null, 'addition' => null, 'fine' => 0);
                    array_push($violations, $violation);
                    //var_dump($violation);
                }
            }
        }

        return $violations;
    }

    public function calculateDepartureControl($driver, $driverCardIds, $workingTimes)
    {
        $violations = array();

        foreach ($workingTimes as $shift)
        {
            $activities = $this->driverCtrl->getDriverActivities($driverCardIds, $shift['payment_start_db'], true, $shift['payment_end_db']);

            if (!is_null($activities) && count($activities) > 0)
            {
                $violation = true;
                $driveBlock = false;
                $duration = 0;
                //die nach Zeit sortierten Aktivitäten werden einzeln betrachtet
                foreach ($activities as $activity)
                {
                    if ($activity['activity'] == 2) $duration += $activity['duration'];
                    if (($driveBlock == false && $violation == true && $duration >= 5) || ($driveBlock == false && $violation == true && $activity['activity'] == 1 && $activity['team'] == 1))
                    {
                        $violation = false;
                    }
                    
                    if ($driveBlock == false && $violation == true && $activity['activity'] == 3 && $activity['duration'] >= 15)
                    {
                        $driveBlock = true;
                        $violationDuration = 5 - $duration;
                        $violation = array('driver_idx' => $driver['idx'], 'violation_idx' => 14, 'date_start' => $activity['date_start_utc'],
                                'date_end' => null, 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine' => 50);
                        array_push($violations, $violation);
                        //var_dump($violation);
                    }
                }
            }
        }

        return $violations;
    }

    public function calculateWeeklyDrivingTime($driver, $driverCardIds, $startDate)
    {
        print('Calculating WDT for driver #'.$driver['idx']."\n");
       // var_dump($driverCardIds);
        $violations = array();
        $activities = null;
        date('w', strtotime($startDate)) == 1 ? $monday = strtotime($startDate) : $monday = strtotime('previous monday', strtotime($startDate));
        //var_dump('starting monday: '.date('Y-m-d', $monday));
        $start = new DateTime(date('Y-m-d', $monday));
        $today = new DateTime();
        $weeksDiff = intval($today->diff($start)->format("%a")/7);
        //var_dump($weeksDiff);
        $lastDrivingAll = 0;
        for ($i = 0; $i <= $weeksDiff; $i++)
        {
            $nextMonday = strtotime('next monday', $monday);
            //var_dump('monday: '.date('Y-m-d', $monday).' nextMonday: '.date('Y-m-d', $nextMonday));

            $activities = $this->driverCtrl->getDriverActivities($driverCardIds, date('Y-m-d', $monday), true, date('Y-m-d', $nextMonday));
            $drivingAll = 0;
            $foundViolationOneW = false;
            $foundViolationTwoW = false;
            $lastDrivingActivity = null;
            //var_dump($activities);
            if (!is_null($activities) && count($activities) > 0)
            {
                $trimedActivities = $this->trimActivities($activities, date('Y-m-d', $monday), date('Y-m-d', $nextMonday));
                //var_dump('IN - '.$driver['idx'].' count = '.count($trimedActivities));
                foreach ($trimedActivities as $activity)
                {
                    //var_dump($activity['start_date']);
                    if ($activity['activity'] == 3)
                    {
                        $lastDrivingActivity = $activity;
                        $drivingAll += $activity['duration'];
                    }
                    if ($drivingAll > 3360 && $foundViolationOneW == false)
                    {
                        $violationStart = new \DateTime($activity['date_end_utc']);
                        $timeDiff = $drivingAll - 3360;
                        $violationStart = $violationStart->modify('-'. $timeDiff .' minutes');
                        $foundViolationOneW = true;
                    }
                    if (($lastDrivingAll + $drivingAll) > 5400 && $foundViolationTwoW == false)
                    {
                        $dwViolationStart = new \DateTime($activity['date_end_utc']);
                        $timeDiff = ($lastDrivingAll + $drivingAll) - 5400;
                        $dwViolationStart = $dwViolationStart->modify('-'. $timeDiff .' minutes');
                        $foundViolationTwoW = true;
                    }
                }
                //var_dump('ALL: '.$drivingAll);
                if ($drivingAll > 3360)
                {
                    //calculate fine
                    $violationDuration = $drivingAll - 3360;
                    if ($violationDuration <= 120)
                    {
                        $fine = 30;
                    }
                    elseif ($violationDuration > 120 && $violationDuration <= 660)
                    {
                        $fine = intval(ceil($violationDuration/60)*30);
                    }
                    elseif ($violationDuration > 660)
                    {
                        $fine = intval(ceil($violationDuration/60)*60);
                    }

                    //calculate fine group
                    if ($violationDuration < 240)
                    {
                        $group = 1;
                    }
                    elseif ($violationDuration >= 240 && $violationDuration < 540)
                    {
                        $group = 2;
                    }
                    elseif ($violationDuration >= 540 && $violationDuration < 840)
                    {
                        $group = 3;
                    }
                    else
                    {
                        $group = 4;
                    }

                    $violation = array('driver_idx' => $driver['idx'], 'violation_idx' => 3, 'date_start' => $violationStart->format('Y-m-d H:i:s'),
                                'date_end' => $lastDrivingActivity['date_end_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                    array_push($violations, $violation);
                    //var_dump($violation);
                }
            }
            /*if (($lastDrivingAll + $drivingAll) > 5400)
            {
                //calculate fine
                $violationDuration = $lastDrivingAll + $drivingAll - 5400;
                if ($violationDuration <= 120)
                {
                    $fine = 30;
                }
                elseif ($violationDuration > 120 && $violationDuration <= 1080)
                {
                    $fine = intval(ceil($violationDuration/60)*30);
                }
                elseif ($violationDuration > 1080)
                {
                    $fine = intval(ceil($violationDuration/60)*60);
                }

                //calculate fine group
                if ($violationDuration < 600)
                {
                    $group = 1;
                }
                elseif ($violationDuration >= 600 && $violationDuration < 900)
                {
                    $group = 2;
                }
                elseif ($violationDuration >= 900 && $violationDuration < 1050)
                {
                    $group = 3;
                }
                else
                {
                    $group = 4;
                }

                $violation = array('driver_idx' => $driver['idx'], 'violation_idx' => 4, 'date_start' => $dwViolationStart->format('Y-m-d H:i:s'),
                            'date_end' => $lastDrivingActivity['date_end_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);*/
                //var_dump($violation);
            } 
            $lastDrivingAll = $drivingAll;
            $lastMonday = $monday;
            $monday = $nextMonday;
        }

        return $violations;
    }

    public function calculateWeeklyRestTimes($driverId, $driverCardIds, $startDate)
    {
        $lastRestTime = null;
        $violations = array();
        $restTimes = null;

        print('Calculating WRT for driver #'.$driverId."\n");

        $restTimes = $this->driverCtrl->getDriverRestTimes($driverId, $startDate, null, false);
        //var_dump($restTimes);
        if (!is_null($restTimes) && count($restTimes) > 0)
        {
            //var_dump('IN - '.$driverId.' count = '.count($restTimes));
            //var_dump($restTimes);
            //die nach Zeit sortierten Aktivitäten werden einzeln betrachtet
            foreach ($restTimes as $restTime)
            {
                $restTime['timestamp_start'] = strtotime($restTime['payment_end']);
                if (!is_null($restTime['time_end']))
                    $restTime['timestamp_end'] = strtotime($restTime['time_end']);
                if (!is_null($lastRestTime))
                {
                    //var_dump('first start: '.date('Y-m-d H:i', intval($lastRestTime['timestamp_start'])));
                    //var_dump('first end: '.date('Y-m-d H:i', intval($lastRestTime['timestamp_end'])));
                    //var_dump('second start: '.date('Y-m-d H:i', intval($restTime['timestamp_start'])));
                    //var_dump($lastRestTime);
                    $diff = ($restTime['timestamp_start']-$lastRestTime['timestamp_end'])/60;
                    //var_dump('DIFF = '.$diff);

                    if ($diff > 20160)
                    {
                        $shortenedWRTs = $this->driverCtrl->getDriverRestTimes($driverId, $lastRestTime['date_end_utc'], $restTime['date_start_utc'], true);
                       // var_dump($shortenedWRTs);
                        if (is_null($shortenedWRTs) || count($shortenedWRTs) == 0)
                        {
                            //calculate fine
                            $violationDuration = $diff-8640;
                            $fine = intval(ceil($violationDuration/1440)*60);

                            //calculate fine group
                            if ($violationDuration < 180)
                            {
                                $group = 1;
                            }
                            elseif ($violationDuration >= 180 && $violationDuration < 720)
                            {
                                $group = 2;
                            }
                            elseif ($violationDuration >= 720)
                            {
                                $group = 3;
                            }

                            //var_dump('F1a !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: '.($violationDuration));
                            $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' =>$lastRestTime['violation_start_utc'],
                                        'date_end' => $restTime['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                            array_push($violations, $violation);
                            //var_dump($violation);
                        }
                        elseif (count($shortenedWRTs) == 1)
                        {
                            $values['rest_time_compensation'] = 2700 - $shortenedWRTs[0]['rest_time_all'];
                            $values['idx'] = $shortenedWRTs[0]['idx'];
                            $this->dbQuery->setShortenedWRT($driverId, $values);
                            $currentViolations = $this->checkShortenedWRT($shortenedWRTs[0], $lastRestTime, $restTime, $driverId);
                            if (count($currentViolations) > 0)
                            {
                                foreach ($currentViolations as $currentViolation)
                                {
                                    //var_dump($currentViolation);
                                    array_push($violations, $currentViolation);
                                }
                            }
                        }
                        else
                        {
                            $found = false;
                            $potentialViolation = array();
                            $potentialWRTs = array();
                            foreach ($shortenedWRTs as $key => $shortenedWRT)
                            {
                                    $currentViolations = $this->checkShortenedWRT($shortenedWRT, $lastRestTime, $restTime, $driverId);
                                    if (count($currentViolations) == 0)
                                    {
                                        $found = true;
                                        $potentialWRTs[$key] = $shortenedWRT;
                                    }
                            }
                            if ($found == false)
                            {
                                //var_dump('### FOUND IS FALSE! CHECK IT!!! ###'); //berechne die Zeit zwischen 2 VWRZ und entscheide!! Frag Tanja danach!!
                                $currentViolations = $this->calculateViolationsAndChooseWRT($shortenedWRTs, $lastRestTime, $restTime, $driverId);
                                if (count($currentViolations) > 0)
                                {
                                    foreach ($currentViolations as $currentViolation)
                                    {
                                        //var_dump($currentViolation);
                                        array_push($violations, $currentViolation);
                                    }
                                }
                            }
                            elseif (count($potentialWRTs) > 1)
                            {
                                //var_dump('WRZ ist nicht klar!');
                                $keySWRT = $this->chooseShortenedWRT($driverId, $potentialWRTs, $lastRestTime['payment_end'], $restTime['payment_start']);
                                $values['rest_time_compensation'] = 2700 - $potentialWRTs[$keySWRT]['rest_time_all'];
                                $values['idx'] = $potentialWRTs[$keySWRT]['idx'];
                                $this->dbQuery->setShortenedWRT($driverId, $values);
                            }
                            else
                            {
                                //var_dump('WRT is one');
                                $values['rest_time_compensation'] = 2700 - $potentialWRT['rest_time_all'];
                                $values['idx'] = reset($potentialWRTs);
                                $this->dbQuery->setShortenedWRT($driverId, $values);
                            }
                        }
                    }
                    elseif ($diff > 8640)
                    {
                        $shortenedWRTs = $this->driverCtrl->getDriverRestTimes($driverId, $lastRestTime['date_end_utc'], $restTime['date_start_utc'], true);
                        //var_dump($shortenedWRTs);
                        if (is_null($shortenedWRTs) || count($shortenedWRTs) == 0)
                        {
                            //calculate fine
                            $violationDuration = $diff-8640;
                            $fine = intval(ceil($violationDuration/1440)*60);

                            //calculate fine group
                            if ($violationDuration < 180)
                            {
                                $group = 1;
                            }
                            elseif ($violationDuration >= 180 && $violationDuration < 720)
                            {
                                $group = 2;
                            }
                            elseif ($violationDuration >= 720)
                            {
                                $group = 3;
                            }

                            //var_dump('F1a !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: '.($violationDuration));
                            $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' =>$lastRestTime['violation_start_utc'],
                                        'date_end' => $restTime['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                            array_push($violations, $violation);
                            //var_dump($violation);
                        }
                        elseif (count($shortenedWRTs) == 1)
                        {
                            $values['rest_time_compensation'] = $shortenedWRTs[0]['idx'];
                            //TODO check!
                            $values['idx'] = $shortenedWRTs[0]['idx'];
                            $this->dbQuery->setShortenedWRT($driverId, $values);
                            $currentViolations = $this->checkShortenedWRT($shortenedWRTs[0], $lastRestTime, $restTime, $driverId);
                            if (count($currentViolations) > 0)
                            {
                                foreach ($currentViolations as $currentViolation)
                                {
                                    //var_dump($currentViolation);
                                    array_push($violations, $currentViolation);
                                }
                            }
                        }
                        else
                        {
                            $found = false;
                            $potentialViolation = array();
                            $potentialWRTs = array();
                            foreach ($shortenedWRTs as $key => $shortenedWRT)
                            {
                                $currentViolations = $this->checkShortenedWRT($shortenedWRT, $lastRestTime, $restTime, $driverId);
                                if (count($currentViolations) == 0)
                                {
                                    $found = true;
                                    $potentialWRTs[$key] = $shortenedWRT;
                                }
                            }
                            if ($found == false)
                            {
                                //var_dump('### FOUND IS FALSE! CHECK IT!!! ###'); //berechne die Zeit zwischen 2 VWRZ und entscheide!! Frag Tanja danach!!
                                $currentViolations = $this->calculateViolationsAndChooseWRT($shortenedWRTs, $lastRestTime, $restTime, $driverId);
                                if (count($currentViolations) > 0)
                                {
                                    foreach ($currentViolations as $currentViolation)
                                    {
                                        //var_dump($currentViolation);
                                        array_push($violations, $currentViolation);
                                    }
                                }
                            }
                            elseif (count($potentialWRTs) > 1)
                            {
                                $keySWRT = $this->chooseShortenedWRT($driverId, $potentialWRTs, $lastRestTime['payment_end'], $restTime['payment_start']);
                                $values['rest_time_compensation'] = 2700 - $potentialWRTs[$keySWRT]['rest_time_all'];
                                $values['idx'] = $potentialWRTs[$keySWRT]['idx'];
                                $this->dbQuery->setShortenedWRT($driverId, $values);
                            }
                            else
                            {
                                //var_dump('WRT is one');
                                $potentialWRT = reset($potentialWRTs);
                                $values['rest_time_compensation'] = 2700 - $potentialWRT['rest_time_all'];
                                $values['idx'] = $potentialWRT['idx'];
                                $this->dbQuery->setShortenedWRT($driverId, $values);
                            }
                        }
                    }
                    elseif ($diff <= 8640)
                    {
                        $dailyViolations = $this->checkForDailyViolations($driverId, $lastRestTime['date_end_utc'], $restTime['date_start_utc']);
                        //var_dump($dailyViolations);
                        if ($dailyViolations > 0)
                        {
                            $shortenedWRTs = $this->driverCtrl->getDriverRestTimes($driverId, $lastRestTime['date_end_utc'], $restTime['date_start_utc'], true);
                            //var_dump($shortenedWRTs);
                            if (!is_null($shortenedWRTs) && count($shortenedWRTs) > 0)
                            {
                                $keySWRT = $this->chooseShortenedWRT($driverId, $shortenedWRTs, $lastRestTime['payment_end'], $restTime['payment_start']);
                                //var_dump('NEW !!!');
                                //var_dump($keySWRT);
                                $neededCompensation = 2700 - $shortenedWRTs[$keySWRT]['rest_time_all'];
                            }
                        }
                    }

                    //var_dump('________________________________________________________________');
                }
                $lastRestTime = $restTime;
            }
        }
        //var_dump('ALL VIOLATIONS');
        //var_dump($violations);
        //if (count($violations) > 0) $this->model->saveViolations($violations);
        return $violations;
    }

    private function calculateViolationsAndChooseWRT($shortenedWRTs, $lastRestTime, $restTime, $driverId)
    {
        $suitableWRT = null;
        $shortenedViolation = false;
        $violations = array();
        foreach ($shortenedWRTs as $key => $shortenedWRT)
        {
            $shortenedWRT['timestamp_start'] = strtotime($shortenedWRT['payment_end']);
            $shortenedWRT['timestamp_end'] = strtotime($shortenedWRT['time_end']);
            if (($shortenedWRT['timestamp_start'] - $lastRestTime['timestamp_end'])/60 > 8640)
            {
                if (is_null($suitableWRT))
                {
                    //calculate fine
                    $violationDuration = (($shortenedWRT['timestamp_start'] - $lastRestTime['timestamp_end'])/60) - 8640;
                    $fine = intval(ceil($violationDuration/1440)*60);

                    //calculate fine group
                    if ($violationDuration < 180)
                    {
                        $group = 1;
                    }
                    elseif ($violationDuration >= 180 && $violationDuration < 720)
                    {
                        $group = 2;
                    }
                    elseif ($violationDuration >= 720)
                    {
                        $group = 3;
                    }

                    $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' => $lastRestTime['violation_start_utc'],
                                'date_end' => $shortenedWRT['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                    array_push($violations, $violation);
                    //var_dump($violation);
                    //var_dump('check this!');
                    $values['rest_time_compensation'] = 2700 - $shortenedWRT['rest_time_all'];
                    $values['idx'] = $shortenedWRT['idx'];
                    $this->dbQuery->setShortenedWRT($driverId, $values);
                    $lastRestTime = $shortenedWRT;
                   
                    //var_dump('F5 !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: ');
                    if ($shortenedViolation == true)
                    {
                        //calculate fine
                        $violationDuration = 2700 - $shortenedWRT['rest_time_all'];
                        if ($violationDuration <= 540)
                        {
                            $fine = intval(ceil($violationDuration/60)*30);
                        }
                        elseif ($violationDuration > 540)
                        {
                            $fine = intval(ceil($violationDuration/60)*60);
                        }

                        //calculate fine group
                        if ($violationDuration <= 180)
                        {
                            $group = 1;
                        }
                        elseif ($violationDuration > 180 && $violationDuration <= 540)
                        {
                            $group = 2;
                        }
                        elseif ($violationDuration > 540)
                        {
                            $group = 3;
                        }

                        $violationStart = new \DateTime($shortenedWRT['date_end_utc']);
                        $violationStart = $violationStart->modify('+'. $violationDuration .' minutes');
                        $violation = array('driver_idx' => $driverId, 'violation_idx' => 9, 'date_start' => $shortenedWRT['date_end_utc'],
                                    'date_end' => $violationStart->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                        array_push($violations, $violation);

                        $violation = array('driver_idx' => $driverId, 'violation_idx' => 8, 'date_start' => $shortenedWRT['date_end_utc'],
                                    'date_end' => $violationStart->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> 0);
                        array_push($violations, $violation);
                        //var_dump($violation);
                    }
                }
                else
                {
                    if ($shortenedViolation == false)
                    {
                        $shortenedViolation = true;
                    }
                    else
                    {
                        //calculate fine
                        $violationDuration = 2700 - $suitableWRT['rest_time_all'];
                        if ($violationDuration <= 540)
                        {
                            $fine = intval(ceil($violationDuration/60)*30);
                        }
                        elseif ($violationDuration > 540)
                        {
                            $fine = intval(ceil($violationDuration/60)*60);
                        }

                        //calculate fine group
                        if ($violationDuration <= 180)
                        {
                            $group = 1;
                        }
                        elseif ($violationDuration > 180 && $violationDuration <= 540)
                        {
                            $group = 2;
                        }
                        elseif ($violationDuration > 540)
                        {
                            $group = 3;
                        }

                        $violationStart = new \DateTime($suitableWRT['date_end_utc']);
                        $violationStart = $violationStart->modify('+'. $violationDuration .' minutes');
                        $violation = array('driver_idx' => $driverId, 'violation_idx' => 9, 'date_start' => $suitableWRT['date_end_utc'],
                                    'date_end' => $violationStart->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                        array_push($violations, $violation);

                        $violation = array('driver_idx' => $driverId, 'violation_idx' => 8, 'date_start' => $suitableWRT['date_end_utc'],
                                    'date_end' => $violationStart->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> 0);
                        array_push($violations, $violation);
                        //var_dump($violation);
                        //var_dump('F9 !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: ');
                        //var_dump('2 verkürzte nacheinander!');
                    }
                    //var_dump('check this!');
                    $values['rest_time_compensation'] = 2700 - $suitableWRT['rest_time_all'];
                    $values['idx'] = $suitableWRT['idx'];
                    $this->dbQuery->setShortenedWRT($driverId, $values);
                    $lastRestTime = $suitableWRT;
                    $suitableWRT = null;
                    $diff = ($shortenedWRT['timestamp_start'] - $lastRestTime['timestamp_end'])/60;
                    if ($diff > 8640)
                    {
                        //calculate fine
                        $violationDuration = $diff - 8640;
                        $fine = intval(ceil($violationDuration/1440)*60);

                        //calculate fine group
                        if ($violationDuration < 180)
                        {
                            $group = 1;
                        }
                        elseif ($violationDuration >= 180 && $violationDuration < 720)
                        {
                            $group = 2;
                        }
                        elseif ($violationDuration >= 720)
                        {
                            $group = 3;
                        }

                        $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' => $lastRestTime['violation_start_utc'],
                                    'date_end' => $shortenedWRT['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                        array_push($violations, $violation);
                        //var_dump($violation);
                        //var_dump('check this!');
                        $values['rest_time_compensation'] = 2700 - $shortenedWRT['rest_time_all'];
                        $values['idx'] = $shortenedWRT['idx'];
                        $this->dbQuery->setShortenedWRT($driverId, $values);
                        $lastRestTime = $shortenedWRT;
                        //var_dump('F6 !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: ');
                    }
                    elseif ($diff <= 8640)
                    {
                        $suitableWRT = $shortenedWRT;
                    }
                }
            }
            elseif (($shortenedWRT['timestamp_start'] - $lastRestTime['timestamp_end'])/60 <= 8640)
            {
                $suitableWRT = $shortenedWRT;
            }
        }

        if (($restTime['timestamp_start'] - $lastRestTime['timestamp_end'])/60 > 8640)
        {
            if (is_null($suitableWRT))
            {
                //var_dump('check this!');
                $neededCompensation = 

                $values['rest_time_compensation'] = 2700 - $lastRestTime['rest_time_all'];
                $values['idx'] = $lastRestTime['idx'];
                $this->dbQuery->setShortenedWRT($driverId, $values);
                //calculate fine
                $violationDuration = (($restTime['timestamp_start'] - $lastRestTime['timestamp_end'])/60) - 8640;
                $fine = intval(ceil($violationDuration/1440)*60);

                //calculate fine group
                if ($violationDuration < 180)
                {
                    $group = 1;
                }
                elseif ($violationDuration >= 180 && $violationDuration < 720)
                {
                    $group = 2;
                }
                elseif ($violationDuration >= 720)
                {
                    $group = 3;
                }

                $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' => $lastRestTime['violation_start_utc'],
                            'date_end' => $restTime['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);
                //var_dump($violation);
                //var_dump('F7 !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: ');
                //var_dump($lastRestTime);
            }
            else
            {
                //var_dump('check this!');
                $values['rest_time_compensation'] = 2700 - $suitableWRT['rest_time_all'];
                $values['idx'] = $suitableWRT['idx'];
                $this->dbQuery->setShortenedWRT($driverId, $values);

                if (($restTime['timestamp_start'] - $suitableWRT['timestamp_end'])/60 > 8640)
                {
                    //calculate fine
                    $violationDuration = (($restTime['timestamp_start'] - $suitableWRT['timestamp_end'])/60) - 8640;
                    $fine = intval(ceil($violationDuration/1440)*60);

                    //calculate fine group
                    if ($violationDuration < 180)
                    {
                        $group = 1;
                    }
                    elseif ($violationDuration >= 180 && $violationDuration < 720)
                    {
                        $group = 2;
                    }
                    elseif ($violationDuration >= 720)
                    {
                        $group = 3;
                    }

                    $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' => $suitableWRT['violation_start_utc'],
                                'date_end' => $restTime['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                    array_push($violations, $violation);
                    //var_dump($violation);
                    //var_dump('F8 !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: ');
                    //var_dump($suitableWRT);
                }
                
                //var_dump('PASST:');
                //var_dump($suitableWRT);
                if ($shortenedViolation == true)
                {
                    //calculate fine
                    $violationDuration = 2700 - $suitableWRT['rest_time_all'];
                    if ($violationDuration <= 540)
                    {
                        $fine = intval(ceil($violationDuration/60)*30);
                    }
                    elseif ($violationDuration > 540)
                    {
                        $fine = intval(ceil($violationDuration/60)*60);
                    }

                    //calculate fine group
                    if ($violationDuration <= 180)
                    {
                        $group = 1;
                    }
                    elseif ($violationDuration > 180 && $violationDuration <= 540)
                    {
                        $group = 2;
                    }
                    elseif ($violationDuration > 540)
                    {
                        $group = 3;
                    }

                    $violationStart = new \DateTime($suitableWRT['date_end_utc']);
                    $violationStart = $violationStart->modify('+'. $violationDuration .' minutes');
                    $violation = array('driver_idx' => $driverId, 'violation_idx' => 9, 'date_start' => $suitableWRT['date_end_utc'],
                                'date_end' => $violationStart->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                    array_push($violations, $violation);

                    $violation = array('driver_idx' => $driverId, 'violation_idx' => 8, 'date_start' => $suitableWRT['date_end_utc'],
                                'date_end' => $violationStart->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> 0);
                    array_push($violations, $violation);
                    //var_dump($violation);
                    //var_dump('F9 !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!VERSTOß!!!!!!!!!!!!!!!!!!!!!!! DAUER: ');
                    //var_dump('2 verkürzte nacheinander!');
                }
            }
        }

        return $violations;
    }

    private function checkShortenedWRT($shortenedWRT, $lastRestTime, $restTime, $driverId)
    {
        $shortenedWRT['timestamp_start'] = strtotime($shortenedWRT['payment_end']);
        if (!is_null($shortenedWRT['time_end']))
            $shortenedWRT['timestamp_end'] = strtotime($shortenedWRT['time_end']);
        $violations = array();
        if ((($shortenedWRT['timestamp_start'] - $lastRestTime['timestamp_end'])/60) > 8640)
        {
            //calculate fine
            $violationDuration = (($shortenedWRT['timestamp_start'] - $lastRestTime['timestamp_end'])/60) - 8640;
            $fine = intval(ceil($violationDuration/1440)*60);

            //calculate fine group
            if ($violationDuration < 180)
            {
                $group = 1;
            }
            elseif ($violationDuration >= 180 && $violationDuration < 720)
            {
                $group = 2;
            }
            elseif ($violationDuration >= 720)
            {
                $group = 3;
            }

            $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' =>$lastRestTime['violation_start_utc'],
                        'date_end' => $shortenedWRT['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
            array_push($violations, $violation);
        }
        if ((($restTime['timestamp_start'] - $shortenedWRT['timestamp_end'])/60) > 8640)
        {
            //calculate fine
            $violationDuration = (($restTime['timestamp_start'] - $shortenedWRT['timestamp_end'])/60) - 8640;
            $fine = intval(ceil($violationDuration/1440)*60);

            //calculate fine group
            if ($violationDuration < 180)
            {
                $group = 1;
            }
            elseif ($violationDuration >= 180 && $violationDuration < 720)
            {
                $group = 2;
            }
            elseif ($violationDuration >= 720)
            {
                $group = 3;
            }

            $violation = array('driver_idx' => $driverId, 'violation_idx' => 10, 'date_start' =>$shortenedWRT['violation_start_utc'],
                        'date_end' => $restTime['date_start_utc'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
            array_push($violations, $violation);
        }
        return $violations;
    }

    private function chooseShortenedWRT($driverId, $weeklyRestTimes, $startDate, $endDate)
    {
        $violations = array();
        //var_dump('check For Daily Violations');
        //var_dump($weeklyRestTimes);
        foreach ($weeklyRestTimes as $key => $weeklyRestTime)
        {
            $violations[$key] = $this->checkForDailyViolations($driverId, $startDate, $weeklyRestTime['payment_end']);
            $violations[$key] += $this->checkForDailyViolations($driverId, $weeklyRestTime['payment_end'], $endDate);
        }
        //var_dump($violations);
        $bestChoise = null;
        foreach ($violations as $key => $violation)
        {
            if (is_null($bestChoise))
            {
                $bestChoise = $key;
            }
            else
            {
                if ($violations[$bestChoise] > $violation)
                {
                    $bestChoise = $key;
                }
                elseif ($violations[$bestChoise] == $violation && $weeklyRestTimes[$bestChoise]['daily_rest_time'] < $weeklyRestTimes[$key]['daily_rest_time'])
                {
                    $bestChoise = $key;
                }
            }
        }
        //var_dump($bestChoise);
        return $bestChoise;
    }

    private function checkForDailyViolations($driverId, $startDate, $endDate)
    {
        $fines = 0;

        $ddtViolations = $this->calculateDaylyDrivingTimeViolations($driverId, $startDate, $endDate);
        foreach ($ddtViolations as $violation)
        {
            //var_dump($violation);
            if ($violation['violation_idx'] == 1) $fines += $violation['fine'];
        }
        $drtViolations = $this->calculateDaylyRestTimeViolations($driverId, $startDate, $endDate);
        foreach ($drtViolations as $violation)
        {
            //var_dump($violation);
            if ($violation['addition'] == '(11 Stunden)') $fines += $violation['fine'];
        }

        return $fines;
    }

    public function calculateDailyViolations($driverId, $driverCardIds, $startDate)
    {
        $lastRestTime = null;
        $violations = array();
        $restTimes = null;

        print('Calculating DDT for driver #'.$driverId."\n");

        $weeklyRestTimes = $this->dbQuery->getWeeklyRestTimes($driverId, $startDate);
        $now = new \DateTime();
        if (!is_null($weeklyRestTimes))
        {
            array_push($weeklyRestTimes, array('payment_end'=> $now->format('Y-m-d H:i')));
        }
        //var_dump($restTimes);
        if (!is_null($weeklyRestTimes) && count($weeklyRestTimes) > 0)
        {
           // var_dump('IN - '.$driverId.' count = '.count($weeklyRestTimes));
            //var_dump($restTimes);
            //die nach Zeit sortierten Aktivitäten werden einzeln betrachtet
            foreach ($weeklyRestTimes as $weeklyRestTime)
            {
                $ddtViolations = $this->calculateDaylyDrivingTimeViolations($driverId, $startDate, $weeklyRestTime['payment_end'], $driverCardIds);
                $drtViolations = $this->calculateDaylyRestTimeViolations($driverId, $startDate, $weeklyRestTime['payment_end']);
                $violations = array_merge($violations, $ddtViolations, $drtViolations);
                $startDate = $weeklyRestTime['payment_end'];
            }
        }

        return $violations;
    }

    public function calculateDaylyDrivingTimeViolations($driverId, $startDate, $endDate, $driverCardIds = null)
    {
        $violations = array();
        $activities = null;
        $workingTimes = $this->dbQuery->getWorkingTimes($driverId, $startDate, $endDate, 'duration_driving');
        //var_dump($workingTimes);
        if (!is_null($workingTimes) && count($workingTimes)>0)
        {
            $lastShift = null;
            $longDriveBlocks = 0;
            $first10hBlock = null;
            $second10hblock = null;
            foreach ($workingTimes as $shift)
            {       
                if ($shift['duration_driving'] > 540)
                {
                    $violationDriving = null;
                    if (is_null($first10hBlock))
                    {
                        $first10hBlock = $shift;
                    }
                    elseif (is_null($second10hblock))
                    {
                        $second10hblock = $shift;
                    }
                    elseif ($first10hBlock['duration_driving'] < $shift['duration_driving'])
                    {
                        $violationDriving = $first10hBlock;
                        $first10hBlock = $shift;
                    }
                    elseif ($second10hblock['duration_driving'] < $shift['duration_driving'])
                    {
                        $violationDriving = $second10hblock;
                        $second10hblock = $shift;
                    }
                    else
                    {
                        $violationDriving = $shift;
                    }

                    if (!is_null($violationDriving))
                    {
                        //calculate fine
                        $violationDuration = $violationDriving['duration_driving'] - 540;
                        if ($violationDuration <= 60)
                        {
                            $fine = 30;
                        }
                        elseif ($violationDuration > 60 && $violationDuration <= 120)
                        {
                            $fine = intval(ceil($violationDuration/30)*30);
                        }
                        elseif ($violationDuration > 120)
                        {
                            $fine = intval(ceil($violationDuration/30)*60);
                        }
                        
                        //calculate fine group
                        if ($violationDuration < 60)
                        {
                            $group = 1;
                        }
                        elseif ($violationDuration >= 60 && $violationDuration < 120)
                        {
                            $group = 2;
                        }
                        elseif (($violationDuration >= 120 && $violationDuration < 270) || ($violationDuration >= 270 && $shift['duration_all'] - $shift['duration_driving'] >= 270))
                        {
                            $group = 3;
                        }
                        else
                        {
                            $group = 4;
                        }

                        $violationStartEnd = $this->calculateViolationStartEnd($violationDriving['payment_start_db'], $violationDriving['payment_end_db'], 540, $driverCardIds);
                        $violation = array('driver_idx' => $driverId, 'violation_idx' => 1, 'date_start' => $violationStartEnd['start'],
                            'date_end' => $violationStartEnd['end'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                        array_push($violations, $violation);
                    }
                }
            }
            if (!is_null($first10hBlock) && $first10hBlock['duration_driving'] > 600)
            {
                $driveBlock = true;
                //calculate fine
                $violationDuration = $first10hBlock['duration_driving'] - 600;
                if ($violationDuration <= 120)
                {
                    $fine = intval(ceil($violationDuration/30)*30);
                }
                elseif ($violationDuration > 120)
                {
                    $fine = intval(ceil($violationDuration/30)*60);
                }

                //calculate fine group
                if ($violationDuration < 60)
                {
                    $group = 1;
                }
                elseif ($violationDuration >= 60 && $violationDuration < 120)
                {
                    $group = 2;
                }
                elseif (($violationDuration >= 120 && $violationDuration < 300) || ($violationDuration >= 300 && $shift['duration_all'] - $shift['duration_driving'] >= 270))
                {
                    $group = 3;
                }
                else
                {
                    $group = 4;
                }

                $violationStartEnd = $this->calculateViolationStartEnd($first10hBlock['payment_start_db'], $first10hBlock['payment_end_db'], 600, $driverCardIds);
                $violation = array('driver_idx' => $driverId, 'violation_idx' => 2, 'date_start' => $violationStartEnd['start'],
                        'date_end' => $violationStartEnd['end'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);
            }
            if (!is_null($second10hblock) && $second10hblock['duration_driving'] > 600)
            {
                //calculate fine
                $violationDuration = $second10hblock['duration_driving'] - 600;
                if ($violationDuration <= 120)
                {
                    $fine = intval(ceil($violationDuration/30)*30);
                }
                elseif ($violationDuration > 120)
                {
                    $fine = intval(ceil($violationDuration/30)*60);
                }

                //calculate fine group
                if ($violationDuration < 60)
                {
                    $group = 1;
                }
                elseif ($violationDuration >= 60 && $violationDuration < 120)
                {
                    $group = 2;
                }
                elseif (($violationDuration >= 120 && $violationDuration < 300) || ($violationDuration >= 300 && $shift['duration_all'] - $shift['duration_driving'] >= 270))
                {
                    $group = 3;
                }
                else
                {
                    $group = 4;
                }

                $violationStartEnd = $this->calculateViolationStartEnd($second10hblock['payment_start_db'], $second10hblock['payment_end_db'], 600, $driverCardIds);
                $violation = array('driver_idx' => $driverId, 'violation_idx' => 2, 'date_start' => $violationStartEnd['start'],
                    'date_end' => $violationStartEnd['end'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);
            }
        }
        //  if (!is_null($violations)) $this->model->saveViolations($violations);

        return $violations; #json_encode('END');
    }

    public function calculateDaylyRestTimeViolations($driverId, $startDate, $endDate)
    {
        $violations = array();
        $activities = null;

        $workingTimes = $this->dbQuery->getWorkingTimes($driverId, $startDate, $endDate, 'daily_rest_time');
        //var_dump($workingTimes);
        if (!is_null($workingTimes) && count($workingTimes)>0)
        {
            $lastShift = null;
            $shortRestBlocks = 0;
            $first9hRest = null;
            $second9hRest = null;
            $third9hRest = null;
            foreach ($workingTimes as $shift)
            {   
                if ($shift['team_mode'] == 1)
                {
                    $startDateTeam = strtotime($shift['payment_end_db']);
                    $endDateTeam = strtotime('+30 hours', strtotime($shift['payment_start_db']));
                    $teamModeRT = intval(($endDateTeam - $startDateTeam)/60);
                }

                if (($shift['team_mode'] == 0 && !($shift['daily_rest_time'] >= 660 || ($shift['daily_rest_time'] >= 540 && $shift['break_3h'] == 1))) ||
                    ($shift['team_mode'] == 1 && $teamModeRT < 540))
                {
                    $dailyRestViolation = null;
                    if (is_null($first9hRest))
                    {
                        $first9hRest = $shift;
                    }
                    elseif (is_null($second9hRest))
                    {
                        $second9hRest = $shift;
                    }
                    elseif (is_null($third9hRest))
                    {
                        $third9hRest = $shift;
                    }
                    elseif ($first9hRest['daily_rest_time'] > $shift['daily_rest_time'])
                    {
                        $dailyRestViolation = $first9hRest;
                        $first9hRest = $shift;
                    }
                    elseif ($second9hRest['daily_rest_time'] > $shift['daily_rest_time'])
                    {
                        $dailyRestViolation = $second9hRest;
                        $second9hRest = $shift;
                    }
                    elseif ($third9hRest['daily_rest_time'] > $shift['daily_rest_time'])
                    {
                        $dailyRestViolation = $third9hRest;
                        $third9hRest = $shift;
                    }
                    else
                    {
                        $dailyRestViolation = $shift;
                    }

                    if (!is_null($dailyRestViolation))
                    {
                        //calculate fine
                        if ($shift['team_mode'] == 1)
                        {
                            $violationDuration =  540 - $teamModeRT;
                        }
                        else
                        {
                            $violationDuration =  660 - $dailyRestViolation['daily_rest_time'];
                        }
                        if ($violationDuration <= 180)
                        {
                            $fine = intval(ceil($violationDuration/60)*30);
                        }
                        elseif ($violationDuration > 180)
                        {
                            $fine = intval(ceil($violationDuration/60)*60);
                        }

                        //calculate fine group
                        if ($violationDuration <= 60)
                        {
                            $group = 1;
                        }
                        elseif ($violationDuration > 60 && $violationDuration <= 150)
                        {
                            $group = 2;
                        }
                        elseif ($violationDuration > 150)
                        {
                            $group = 3;
                        }

                        $violationStart = $this->getNewDate($dailyRestViolation['payment_end_db'], '-', $violationDuration);
                        $violation = array('driver_idx' => $driverId, 'violation_idx' => 7, 'date_start' => $violationStart,
                            'date_end' => $dailyRestViolation['payment_end_db'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                        array_push($violations, $violation);
                    }
                }
            }
            if (!is_null($first9hRest) && $first9hRest['daily_rest_time'] < 540)
            {
                //calculate fine
                if ($first9hRest['team_mode'] == 1)
                {
                    $startDateTeam = strtotime($first9hRest['payment_end_db']);
                    $endDateTeam = strtotime('+30 hours', strtotime($first9hRest['payment_start_db']));
                    $teamModeRT = intval(($endDateTeam - $startDateTeam)/60);
                    $violationDuration =  540 - $teamModeRT;
                }
                else
                {
                    $violationDuration = 540 - $first9hRest['daily_rest_time'];
                }
                if ($violationDuration <= 180)
                {
                    $fine = intval(ceil($violationDuration/60)*30);
                }
                elseif ($violationDuration > 180)
                {
                    $fine = intval(ceil($violationDuration/60)*60);
                }

                //calculate fine group
                if ($violationDuration <= 60)
                {
                    $group = 1;
                }
                elseif ($violationDuration > 60 && $violationDuration <= 120)
                {
                    $group = 2;
                }
                elseif ($violationDuration > 120)
                {
                    $group = 3;
                }

                $violationStart = $this->getNewDate($first9hRest['payment_end_db'], '-', $violationDuration);
                $violation = array('driver_idx' => $driverId, 'violation_idx' => 6, 'date_start' => $violationStart,
                        'date_end' => $first9hRest['payment_end_db'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);
            }
            if (!is_null($second9hRest) && $second9hRest['daily_rest_time'] < 540)
            {
                //calculate fine
                if ($second9hRest['team_mode'] == 1)
                {
                    $startDateTeam = strtotime($second9hRest['payment_end_db']);
                    $endDateTeam = strtotime('+30 hours', strtotime($second9hRest['payment_start_db']));
                    $teamModeRT = intval(($endDateTeam - $startDateTeam)/60);
                    $violationDuration =  540 - $teamModeRT;
                }
                else
                {
                    $violationDuration = 540 - $second9hRest['daily_rest_time'];
                }
                if ($violationDuration <= 180)
                {
                    $fine = intval(ceil($violationDuration/60)*30);
                }
                elseif ($violationDuration > 180)
                {
                    $fine = intval(ceil($violationDuration/60)*60);
                }

                //calculate fine group
                if ($violationDuration <= 60)
                {
                    $group = 1;
                }
                elseif ($violationDuration > 60 && $violationDuration <= 120)
                {
                    $group = 2;
                }
                elseif ($violationDuration > 120)
                {
                    $group = 3;
                }

                $violationStart = $this->getNewDate($second9hRest['payment_end_db'], '-', $violationDuration);
                $violation = array('driver_idx' => $driverId, 'violation_idx' => 6, 'date_start' => $violationStart,
                    'date_end' => $second9hRest['payment_end_db'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);
            }
            if (!is_null($third9hRest) && $third9hRest['daily_rest_time'] < 540)
            {
                //calculate fine
                if ($third9hRest['team_mode'] == 1)
                {
                    $startDateTeam = strtotime($third9hRest['payment_end_db']);
                    $endDateTeam = strtotime('+30 hours', strtotime($third9hRest['payment_start_db']));
                    $teamModeRT = intval(($endDateTeam - $startDateTeam)/60);
                    $violationDuration =  540 - $teamModeRT;
                }
                else
                {
                    $violationDuration = 540 - $third9hRest['daily_rest_time'];
                }
                if ($violationDuration <= 180)
                {
                    $fine = intval(ceil($violationDuration/60)*30);
                }
                elseif ($violationDuration > 180)
                {
                    $fine = intval(ceil($violationDuration/60)*60);
                }

                //calculate fine group
                if ($violationDuration <= 60)
                {
                    $group = 1;
                }
                elseif ($violationDuration > 60 && $violationDuration <= 120)
                {
                    $group = 2;
                }
                elseif ($violationDuration > 120)
                {
                    $group = 3;
                }

                $violationStart = $this->getNewDate($third9hRest['payment_end_db'], '-', $violationDuration);
                $violation = array('driver_idx' => $driverId, 'violation_idx' => 6, 'date_start' => $violationStart,
                    'date_end' => $third9hRest['payment_end_db'], 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);
            }
        }
        //  if (!is_null($violations)) $this->model->saveViolations($violations);

        return $violations; #json_encode('END');
    }

    private function calculateDailyBreaksViolations($driverId, $driverCardIds, $workingTimes)
    {
        print('Calculateing DB for driver #'.$driverId."\n");
        $violations = array();

        foreach ($workingTimes as $shift)
        {
            //var_dump($shift);
            if ($shift['duration_driving'] > 270)
            {
                $activities = $this->driverCtrl->getDriverActivities($driverCardIds, $shift['payment_start_db'], true, $shift['payment_end_db']);
                $drivingTime = 0;
                $pause15 = false;
                $drivingTimeAfterBiggestPause = 0;
                $drivingTimeToBiggestPause = 0;
                $biggestPause = null;
                $violationPause15 = false;
                $startDate = $shift['payment_start_db'];
                //var_dump('/////////////////////////');
                //var_dump($activities);
                if (!is_null($activities) && count($activities) > 0)
                {
                    //var_dump('IN - '.$driverId.' count = '.count($activities));
                    //die nach Zeit sortierten Aktivitäten werden einzeln betrachtet
                    foreach ($activities as $activity)
                    {
                        if ($activity['activity'] == 0 || ($shift['team_mode'] == 1 && $activity['activity'] == 1) || ($activity['team'] == 1 && $activity['activity'] == 1))
                        {
                            //var_dump('/////////////////////////');
                      //      var_dump('DT:' .$drivingTime);
                            if (($activity['duration'] >= 45 || ($activity['duration'] >= 30 && $pause15 == true)))
                            {
                                if ($drivingTime > 270)
                                {
                                    $newViolations = $this->getDailyBreaksViolations($driverId, $driverCardIds, $startDate, $activity['date_start_utc'], $biggestPause, $violationPause15, $drivingTime, $drivingTimeAfterBiggestPause, $drivingTimeToBiggestPause);
                                    //var_dump($newViolations);
                                    if (count($newViolations) > 0) 
                                    {
                                        $violations = array_merge($violations, $newViolations); 
                                    }
                                    //var_dump($violations);
                                }
                                $drivingTime = 0;
                                $pause15 = false;
                                $biggestPause = null;
                                $drivingTimeToBiggestPause = 0;
                                $drivingTimeAfterBiggestPause = 0;
                                $violationPause15 = false;
                                $startDate = $activity['date_end_utc'];
                            }
                            else
                            {
                                if ($activity['duration'] >= 15 && $drivingTime > 0)
                                {
                                    if ($drivingTime > 270)
                                    {
                                        $newViolations = $this->getDailyBreaksViolations($driverId, $driverCardIds, $startDate, $activity['date_start_utc'], $biggestPause, $violationPause15, $drivingTime, $drivingTimeAfterBiggestPause, $drivingTimeToBiggestPause);
                                        //var_dump($newViolations);
                                        //var_dump($drivingTime);
                                        if (count($newViolations) > 0) 
                                        {
                                            $violations = array_merge($violations, $newViolations);
                                            if ($newViolations[0]['violation_idx'] == 11)
                                            {
                                                $drivingTime = $drivingTimeAfterBiggestPause;
                                                $pause15 = true;
                                                $startDate = $biggestPause['date_end_utc'];
                                            }
                                            else
                                            {
                                                $violationDuration = 45 - $activity['duration'];
                                                if ($pause15 == true) { $violationDuration -= 15;}
                                                if ($violationDuration <= 15)
                                                {
                                                    $fine = 30;
                                                }
                                                elseif ($violationDuration > 15)
                                                {
                                                    $fine = intval(ceil($violationDuration/15)*60);
                                                }
                                                $violationEnd = new \DateTime($activity['date_end_utc']);
                                                $violationEnd = $violationEnd->modify('+'. $violationDuration .' minutes');
                                                $violationPT = array('driver_idx' => $driverId, 'violation_idx' => 11, 'date_start' => $activity['date_end_utc'],
                                                            'date_end' => $violationEnd->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> $fine);
                                                array_push($violations, $violationPT);
                                                //var_dump($violationPT);

                                                $drivingTime = 0;
                                                $pause15 = false;
                                                $biggestPause = null;
                                                $drivingTimeToBiggestPause = 0;
                                                $drivingTimeAfterBiggestPause = 0;
                                                $violationPause15 = false;
                                                $startDate = $activity['date_end_utc'];
                                            }
                                        }
                                    }
                                    else
                                    {
                                        $pause15 = true;
                                    }
                                }

                                if ($activity['duration'] >= 15)
                                {
                                    if (is_null($biggestPause))
                                    {
                                        $biggestPause = $activity;
                                        $drivingTimeAfterBiggestPause = 0;
                                        $drivingTimeToBiggestPause = $drivingTime;
                                        $violationPause15 = false;
                                    }
                                    elseif ($biggestPause['duration'] <= $activity['duration'])
                                    {
                                        if ($biggestPause['duration'] >= 15) { $violationPause15 = true; }
                                        $biggestPause = $activity;
                                        $drivingTimeAfterBiggestPause = 0;
                                        $drivingTimeToBiggestPause = $drivingTime;
                                    }
                                }
                            }
                        }
                        if ($activity['activity'] == 3) {
                            $drivingTime += $activity['duration'];
                            $drivingTimeAfterBiggestPause += $activity['duration'];
                        }
                    }

                    if ($drivingTime > 270)
                    {
                        $newViolations = $this->getDailyBreaksViolations($driverId, $driverCardIds, $startDate, $shift['payment_end_db'], $biggestPause, $violationPause15, $drivingTime, $drivingTimeAfterBiggestPause, $drivingTimeToBiggestPause);
                        //var_dump($newViolations);
                        if (count($newViolations) > 0) 
                        {
                            $violations = array_merge($violations, $newViolations); 
                        }
                    }
                }
            }
        }
        //var_dump($violations);
        return $violations;
    }

    private function getDailyBreaksViolations($driverId, $driverCardIds, $dateStart, $dateEnd, $biggestPause, $violationPause15, $drivingTime, $drivingTimeAfterBiggestPause, $drivingTimeToBiggestPause)
    {
        $violations = array();
        //calculate fine
        $violationDuration = $drivingTime - 270;
        if ($violationDuration <= 60)
        {
            $fine = 30;
        }
        elseif ($violationDuration > 60)
        {
            $fine = intval(ceil($violationDuration/30)*30);
        }
        
        //calculate fine group
        if ($violationDuration < 30)
        {
            $group = 1;
        }
        elseif ($violationDuration >= 30 && $violationDuration < 90)
        {
            $group = 2;
        }
        elseif ($violationDuration >= 90)
        {
            $group = 3;
        }

        $violationStartEnd = $this->calculateViolationStartEnd($dateStart, $dateEnd, 270, $driverCardIds);
        $violationDT = array('driver_idx' => $driverId, 'violation_idx' => 5, 'date_start' => $violationStartEnd['start'],
                    'date_end' => $dateEnd, 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
        //var_dump($violationDT);
        if (!is_null($biggestPause))
        {
            $violationDuration = 45 - $biggestPause['duration'];
            if ($violationPause15 == true) { $violationDuration -= 15;}
            if ($violationDuration <= 15)
            {
                $fine = 30;
            }
            elseif ($violationDuration > 15)
            {
                $fine = intval(ceil($violationDuration/15)*60);
            }

            $violationEnd = new \DateTime($biggestPause['date_end_utc']);
            $violationEnd = $violationEnd->modify('+'. $violationDuration .' minutes');
            $violationPT = array('driver_idx' => $driverId, 'violation_idx' => 11, 'date_start' => $biggestPause['date_end_utc'],
                        'date_end' => $violationEnd->format('Y-m-d H:i:s'), 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> $fine);
            //var_dump($violationPT);

            $violationDTpb['fine'] = 0;
            $violationDTpa['fine'] = 0;
            if ($drivingTimeAfterBiggestPause > 270)
            {
                //calculate fine
                $violationDuration = $drivingTimeAfterBiggestPause - 270;
                if ($violationDuration <= 60)
                {
                    $fine = 30;
                }
                elseif ($violationDuration > 60)
                {
                    $fine = intval(ceil($violationDuration/30)*30);
                }

                //calculate fine group
                if ($violationDuration < 30)
                {
                    $group = 1;
                }
                elseif ($violationDuration >= 30 && $violationDuration < 90)
                {
                    $group = 2;
                }
                elseif ($violationDuration >= 90)
                {
                    $group = 3;
                }

                $start = $biggestPause['date_end_utc'] >= $dateEnd ? $dateStart : $biggestPause['date_end_utc'];
                $violationStartEnd = $this->calculateViolationStartEnd($start, $dateEnd, 270, $driverCardIds);
                $violationDTpb = array('driver_idx' => $driverId, 'violation_idx' => 5, 'date_start' => $violationStartEnd['start'],
                    'date_end' => $dateEnd, 'duration' => $violationDuration, 'group' => $group, 'addition' => null, 'fine'=> $fine);
                //var_dump($violationDTpb);
            }

            if ($drivingTimeToBiggestPause > 270)
            {
                //calculate fine
                $violationDuration = $drivingTimeToBiggestPause - 270;
                if ($violationDuration <= 60)
                {
                    $fine = 30;
                }
                elseif ($violationDuration > 60)
                {
                    $fine = intval(ceil($violationDuration/30)*30);
                }

                //calculate fine group
                if ($violationDuration < 30)
                {
                    $group = 1;
                }
                elseif ($violationDuration >= 30 && $violationDuration < 90)
                {
                    $group = 2;
                }
                elseif ($violationDuration >= 90)
                {
                    $group = 3;
                }

                $violationStartEnd = $this->calculateViolationStartEnd($dateStart, $biggestPause['date_start_utc'], 270, $driverCardIds);
                $violationDTpa = array('driver_idx' => $driverId, 'violation_idx' => 5, 'date_start' => $violationStartEnd['start'],
                    'date_end' => $biggestPause['date_start_utc'], 'duration' => $violationDuration, 'group' =>  $group, 'addition' => null, 'fine'=> $fine);
                //var_dump($violationDTpa);
            }
        }
        if (is_null($biggestPause) || $violationDT['fine'] < ($violationPT['fine'] +  $violationDTpb['fine'] + $violationDTpa['fine']))
        {
            array_push($violations, $violationDT);
            //var_dump($violationDT);
        }
        else
        {
            array_push($violations, $violationPT);
            if ($violationDTpb['fine'] != 0) {array_push($violations, $violationDTpb);}
            if ($violationDTpa['fine'] != 0) {array_push($violations, $violationDTpa);}
            //var_dump($violationPT);
            //var_dump($violationDTpb);
            //var_dump($violationDTpa);
        }

        return $violations;
    }

    private function calculateWLWTViolations($driverId, $driverCardIds, $workingTimes)
    {
        print('Calculateing AZG for driver #'.$driverId."\n");
        $violations = array();

        foreach ($workingTimes as $shift)
        {
            $durationWork = $shift['duration_driving'] + $shift['duration_work'] + $shift['duration_break0'];
            if ($durationWork > 600)
            {
                $violationDuration = $durationWork - 600;

                $fine = intval(ceil($violationDuration/60)*75);
                $violationStartEnd = $this->calculateWLViolationStartEnd($shift['payment_start_db'], $shift['payment_end_db'], 600, $driverCardIds);
                $violation = array('driver_idx' => $driverId, 'violation_idx' => 17, 'date_start' => $violationStartEnd['start'],
                    'date_end' => $violationStartEnd['end'], 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> $fine);
                array_push($violations, $violation);
                //var_dump($violation);
            }
        }

        return $violations;
    }

    private function calculateWLDailyBreaksViolations($driverId, $driverCardIds, $workingTimes)
    {
        print('Calculateing WLDB for driver #'.$driverId."\n");
        $violations = array();

        foreach ($workingTimes as $shift)
        {
            //var_dump($shift);
            if ($shift['duration_driving'] + $shift['duration_work'] + $shift['duration_break0'] > 360)
            {
                $activities = $this->driverCtrl->getDriverActivities($driverCardIds, $shift['payment_start_db'], true, $shift['payment_end_db']);
                $workingTime = 0;
                $firstPause = null;
                $secondPause = null;
                $lastPause = null;
                $durationPause = 0;
                $startDate = $shift['payment_start_db'];
                $block6h = 0;
                //var_dump('/////////////////////////');
                //var_dump($activities);
                if (!is_null($activities) && count($activities) > 0)
                {
                    //var_dump('IN - '.$driverId.' count = '.count($activities));
                    //die nach Zeit sortierten Aktivitäten werden einzeln betrachtet
                    foreach ($activities as $activity)
                    {
                        if ($activity['activity'] == 0)
                        {
                            //var_dump('DT:' .$workingTime);
                            if ($activity['duration'] >= 15)
                            {
                                //Wenn der Fahrer einen langen Block von übere 6 Stunden hat -> Verstoß
                                if ($block6h > 360 && $block6h != $workingTime)
                                {
                                    $violationDuration = $block6h - 360;
                                    $fine = intval(ceil($violationDuration/30)*75);
                                    $violationStartEnd = $this->calculateWLViolationStartEnd($lastPause['date_end_utc'], $activity['date_start_utc'], 360, $driverCardIds);
                                    $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $violationStartEnd['start'],
                                                'date_end' => $activity['date_start_utc'], 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> $fine);
                                    array_push($violations, $violationWT);
                                    //var_dump($violationWT);
                                    //var_dump('FALL 1');
                                }

                                if  ($workingTime > 540 && $durationPause < 45)
                                {
                                    if ($durationPause > 0)
                                    {
                                        $biigestPause = $firstPause;
                                        if (!is_null($secondPause) && $secondPause['duration'] > $firstPause['duration'])
                                        {
                                            $biigestPause = $secondPause;
                                        }
                                        $violationDurationWT = $workingTime - 540;
                                        $fineWT = intval(ceil($violationDurationWT/30)*75);
                                        $violationStartEnd = $this->calculateWLViolationStartEnd($shift['payment_start_db'], $activity['date_start_utc'], 540, $driverCardIds);
                                        $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $violationStartEnd['start'],
                                                    'date_end' => $activity['date_start_utc'], 'duration' => $violationDurationWT, 'group' => null, 'addition' => null, 'fine'=> $fineWT);
                                        
                                        $violationDurationP = 45 - $durationPause;
                                        $fineP = intval(ceil($violationDurationP/15)*75);
                                        //TODO calc date_end
                                        $violationP = array('driver_idx' => $driverId, 'violation_idx' => 19, 'date_start' => $biigestPause['date_end_utc'],
                                                    'date_end' => $biigestPause['date_end_utc'], 'duration' => $violationDurationP, 'group' => null, 'addition' => null, 'fine'=> $fineP);
                                       //var_dump($violationP);
                                        //var_dump($violationWT);
                                        //var_dump('FALL 3');
                                        if ($fineWT > $fineP)
                                        {
                                            //var_dump('P');
                                            array_push($violations, $violationP);
                                        }
                                        else
                                        {
                                           // var_dump('WT');
                                            array_push($violations, $violationWT);
                                        }
                                    }
                                    else
                                    {
                                        $violationDurationWT = $workingTime - 540;
                                        $fineWT = intval(ceil($violationDurationWT/30)*75);
                                        $violationStartEnd = $this->calculateWLViolationStartEnd($shift['payment_start_db'], $activity['date_start_utc'], 540, $driverCardIds);
                                        $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $violationStartEnd['start'],
                                                    'date_end' => $activity['date_start_utc'], 'duration' => $violationDurationWT, 'group' => null, 'addition' => null, 'fine'=> $fineWT);
                                        //array_push($violations, $violationWT);
                                        array_push($violations, $violationWT);
                                        //var_dump($violationWT);
                                        //var_dump('FALL 2');

                                        if ($activity['duration'] < 45)
                                        {
                                            $violationDurationP = 45 - $activity['duration'];
                                            $fineP = intval(ceil($violationDurationP/15)*75);
                                            //TODO calc date_end
                                            $violationP = array('driver_idx' => $driverId, 'violation_idx' => 19, 'date_start' => $activity['date_end_utc'],
                                                        'date_end' => $activity['date_end_utc'], 'duration' => $violationDurationP, 'group' => null, 'addition' => null, 'fine'=> $fineP);
                                            array_push($violations, $violationP);
                                            //var_dump($violationP);
                                            //var_dump('FALL 8');
                                        }
                                    }
                                }
                                elseif ($workingTime > 360 && $durationPause < 30)
                                {
                                    if ($durationPause > 0)
                                    {
                                        $violationDurationWT = $workingTime - 360;
                                        $fineWT = intval(ceil($violationDurationWT/30)*75);
                                        $violationStartEnd = $this->calculateWLViolationStartEnd($shift['payment_start_db'], $activity['date_start_utc'], 360, $driverCardIds);
                                        $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $violationStartEnd['start'],
                                                    'date_end' => $activity['date_start_utc'], 'duration' => $violationDurationWT, 'group' => null, 'addition' => null, 'fine'=> $fineWT);

                                        $violationDurationP = 30 - $durationPause;
                                        $fineP = intval(ceil($violationDurationP/15)*75);
                                        //TODO calc date_end
                                        $violationP = array('driver_idx' => $driverId, 'violation_idx' => 19, 'date_start' => $activity['date_end_utc'],
                                                    'date_end' => $activity['date_end_utc'], 'duration' => $violationDurationP, 'group' => null, 'addition' => null, 'fine'=> $fineP);
                                        //var_dump($violationP);
                                        //var_dump($violationWT);
                                        //var_dump('FALL 4');
                                        if ($fineWT > $fineP)
                                        {
                                            //var_dump('P');
                                            array_push($violations, $violationP);
                                        }
                                        else
                                        {
                                            //var_dump('WT');
                                            array_push($violations, $violationWT);
                                        }
                                    }
                                    else
                                    {
                                        $violationDuration = $workingTime - 360;
                                        $fine = intval(ceil($violationDuration/30)*75);
                                        $violationStartEnd = $this->calculateWLViolationStartEnd($shift['payment_start_db'], $activity['date_start_utc'], 360, $driverCardIds);
                                        $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $violationStartEnd['start'],
                                                    'date_end' => $activity['date_start_utc'], 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> $fine);
                                       // var_dump($violationWT);
                                        array_push($violations, $violationWT);
                                        //var_dump('FALL 5');

                                        if ($activity['duration'] < 30)
                                        {
                                            $violationDurationP = 30 - $activity['duration'];
                                            $fineP = intval(ceil($violationDurationP/15)*75);
                                            //TODO calc date_end
                                            $violationP = array('driver_idx' => $driverId, 'violation_idx' => 19, 'date_start' => $activity['date_end_utc'],
                                                        'date_end' => $activity['date_end_utc'], 'duration' => $violationDurationP, 'group' => null, 'addition' => null, 'fine'=> $fineP);
                                            //var_dump($violationP);
                                            array_push($violations, $violationP);
                                            //var_dump('FALL 7');
                                        }
                                        //add to array
                                    }
                                }
                                
                                if (is_null($firstPause))
                                {
                                    $firstPause = $activity;
                                }
                                elseif (is_null($secondPause))
                                {
                                    $secondPause = $activity; 
                                }
                                
                                $durationPause += $activity['duration'];
                                $block6h = 0;
                                $lastPause = $activity;
                            }
                        }
                        if ($activity['activity'] == 3 || $activity['activity'] == 2 || (($activity['activity'] == 0 || $activity['activity'] == 4) && $activity['duration'] < 15))
                        {
                            $workingTime += $activity['duration'];
                            $block6h += $activity['duration'];
                        }
                    }
                    if ($block6h > 360 && $block6h != $workingTime)
                    {
                        $violationDuration = $block6h - 360;
                        $fine = intval(ceil($violationDuration/30)*75);
                        $violationStartEnd = $this->calculateWLViolationStartEnd($lastPause['date_end_utc'], $shift['payment_end_db'], 360, $driverCardIds);
                        $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $violationStartEnd['start'],
                                    'date_end' => $activity['date_start_utc'], 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> $fine);
                        //add to array
                        array_push($violations, $violationWT);
                        //var_dump($violationWT);
                        //var_dump('FALL 9');
                    }

                    //check for no pause or 9h block
                    if ($workingTime > 540 && $durationPause < 45 && $durationPause > 0)
                    {
                        $biigestPause = $firstPause;
                        if (!is_null($secondPause) && $secondPause['duration'] > $firstPause['duration'])
                        {
                            $biigestPause = $secondPause;
                        }
                        $violationDurationWT = $workingTime - 540;
                        $fineWT = intval(ceil($violationDurationWT/30)*75);
                        $violationStartEnd = $this->calculateWLViolationStartEnd($shift['payment_start_db'], $shift['payment_end_db'], 540, $driverCardIds);
                        $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $violationStartEnd['start'],
                                    'date_end' => $violationStartEnd['end'], 'duration' => $violationDurationWT, 'group' => null, 'addition' => null, 'fine'=> $fineWT);
                        
                        $violationDurationP = 45 - $durationPause;
                        $fineP = intval(ceil($violationDurationP/15)*75);
                        
                        $violationP = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $biigestPause['date_end_utc'],
                                    'date_end' => $biigestPause['date_end_utc'], 'duration' => $violationDurationP, 'group' => null, 'addition' => null, 'fine'=> $fineP);
                        //var_dump($violationP);
                        //var_dump($violationWT);
                       // var_dump('FALL 6');
                        if ($fineWT > $fineP)
                        {
                            //var_dump('P');
                            array_push($violations, $violationP);
                        }
                        else
                        {
                           // var_dump('WT');
                            array_push($violations, $violationWT);
                        }
                    }

                    if ($durationPause = 0)
                    {
                        //var_dump('NO PAUSE!!!');
                        $fineP = 300;
                        $violationP = array('driver_idx' => $driverId, 'violation_idx' => 20, 'date_start' => $shift['payment_start_db'],
                                    'date_end' => $shift['payment_end_db'], 'duration' => null, 'group' => null, 'addition' => null, 'fine'=> $fine);
                        //var_dump($violationP);
                        //var_dump($violationWT);
                        //var_dump('FALL 10');
                    }
                }
            }
        }
        //var_dump($violations);
        return $violations;
    }

    private function calculateDailyWTViolations($driverId, $driverCardIds, $workingTimes)
    {
        print('Calculateing DWT for driver #'.$driverId."\n");
        $violations = array();

        foreach ($workingTimes as $shift)
        {
            //var_dump($shift);
            if ($shift['duration_work'] < 100)
            {
                $violationDuration = 100 - $shift['duration_work'];
                $violationWT = array('driver_idx' => $driverId, 'violation_idx' => 30, 'date_start' => $shift['payment_start_db'],
                            'date_end' => $shift['payment_end_db'], 'duration' => $violationDuration, 'group' => null, 'addition' => null, 'fine'=> 0);
                array_push($violations, $violationWT);
               // var_dump($violationWT);
                //var_dump('GILLE');
            }
        }
        //var_dump($violations);
        return $violations;
    }

    private function trimActivities($activities, $startDate, $endDate)
    {
        $startDate = new \DateTime($startDate);
        $startDateTime = strtotime($startDate->format('Y-m-d H:i:s'));
        $endDate = new \DateTime($endDate);
        $endDateTime = strtotime($endDate->format('Y-m-d H:i:s'));
        $trimedActivities = array();

        foreach ($activities as $activity)
        {
            $actStartDateTime = strtotime($activity['date_start']);
            $actEndDateTime = strtotime(str_replace("00:00:00","24:00:00",$activity['date_end']));

            if ($startDateTime > $actStartDateTime)
            {
                $activity['date_start'] = $startDate->format('Y-m-d H:i:s');
                $activity['duration'] = ($actEndDateTime - $startDateTime)/60;
            }
            if ($endDateTime < $actEndDateTime)
            {
                $activity['date_end'] = $endDate->format('Y-m-d H:i:s');
                $activity['duration'] = ($endDateTime - $actStartDateTime)/60;
            }
            array_push($trimedActivities, $activity);
        }

        return $trimedActivities;
    }

    private function calculateViolationStartEnd($dateStart, $dateEnd, $duration, $driverCardIds) #oder $activities?
    {
        if (is_null($driverCardIds))
        {
            return array('start' => null, 'end' => null);
        }
        else
        {
            //var_dump($dateStart);
            //var_dump($dateEnd);
            //var_dump($duration);
            $result = array();
            $durationDriving = 0;
            $found = false;
            $activities = $this->driverCtrl->getDriverActivities($driverCardIds, $dateStart, false, $dateEnd);

            foreach ($activities as $activity)
            {
                if ($activity['activity'] == 3)
                {
                    $lastDrivingBlock = $activity;
                    $durationDriving += $activity['duration'];
                    if ($duration < $durationDriving && $found == false)
                    {
                        $diff = $durationDriving - $duration;
                        $result['start'] = $this->getNewDate($activity['date_end_utc'], '-', $diff);
                        $found = true;
                    }
                }
            }
            
            $dateEnd = new \DateTime($lastDrivingBlock['date_end_utc']);
            $result['end'] = $dateEnd->format('Y-m-d H:i:s');
            return $result;
        }
    }

    private function calculateWLViolationStartEnd($dateStart, $dateEnd, $duration, $driverCardIds) #oder $activities?
    {
        if (is_null($driverCardIds))
        {
            return array('start' => null, 'end' => null);
        }
        else
        {
            $result = array();
            $durationWorking = 0;
            $found = false;
            $activities = $this->driverCtrl->getDriverActivities($driverCardIds, $dateStart, false, $dateEnd);
            if (is_null($activities))
            {
                return array('start' => null, 'end' => null);
            }
            else
            {
                foreach ($activities as $activity)
                {
                    if ($activity['activity'] == 3 || $activity['activity'] == 2 || (($activity['activity'] == 0 || $activity['activity'] == 4) && $activity['duration'] < 15))
                    {
                        $lastActivityBlock = $activity;
                        $durationWorking += $activity['duration'];
                        if ($duration < $durationWorking && $found == false)
                        {
                            $diff = $durationWorking - $duration;
                            $result['start'] = $this->getNewDate($activity['date_end_utc'], '-', $diff);
                            $found = true;
                        }
                    }
                }

                $dateEnd = new \DateTime($lastActivityBlock['date_end_utc']);
                $result['end'] = $dateEnd->format('Y-m-d H:i:s');
                return $result;
            }
        }
    }

    private function getNewDate($date, $operation, $minutes)
    {
        $newDate = new \DateTime($date);
        $newDate = $newDate->modify(''.$operation.' '. $minutes .' minutes');

        return $newDate->format('Y-m-d H:i:s');
    }
}