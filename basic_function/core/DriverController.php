<?php
include_once 'CoreDbQueries.php';

class DriverController
{
    /**
     * The DbQuery object
     *
     * @var CoreDbQueries
     */
    protected $dbQuery = null;

    public function __construct(CoreDbQueries $dbQueries)
    {
        $this->dbQuery = $dbQueries;
    }

    /**
     * Die Funktion gibt die Aktivitäten des Fahrers zurück
     *
     * @param integer   $driverId  ID des Fahrers
     * 
     * @author Oleg Manov
     */
    public function getDriverActivities($driverCardIds, $startDate, $changeUndefined = false, $endDate = null)
    {
        $activities = $this->dbQuery->getDriverActivities($driverCardIds, $startDate, $endDate);
        if (!is_null($activities))
        {
            return $this->mergeActivities($activities, $changeUndefined);
        }
        return null;
    }

    public function getDriverActivitiesD($driverCardIds, $startDate, $changeUndefined = false, $endDate = null)
    {
        $activities = $this->dbQuery->getDriverActivitiesD($driverCardIds, $startDate, $endDate);
        if (!is_null($activities))
        {
            return $this->mergeActivities($activities, $changeUndefined);
        }
        return null;
    }

    private function mergeActivities($activities, $changeUndefined)
    {
        $mergedActivities = array();
        $lastActivity = null;
        $inserted = 0;
        foreach ($activities as $activity)
        {
            $activity['timestamp_start'] = strtotime($activity['date_start']);
            $activity['timestamp_end'] = strtotime($activity['date_end']);
            $activity['start_timestamp'] = strtotime($activity['t_start']);
            $activity['end_timestamp'] = strtotime($activity['t_end']);
            if ($changeUndefined == true)
            {
                // unbekannte Aktivitäten werden als Pause betrachtet
                if ($activity['activity'] == 4)
                {
                    $activity['activity'] = 0;
                }
            }
            
            if ($lastActivity)
            {
                // suche nach Lücken (z.B. wegen Folgekarte)
                if ($lastActivity['timestamp_end'] < $activity['timestamp_start'])
                {
                    $diff = ($activity['timestamp_start']-$lastActivity['timestamp_end'])/60;
                    
                    if ($lastActivity['activity'] == 0 || $lastActivity['activity'] == 4)
                    {
                        $lastActivity['date_end'] = $activity['date_start'];
                        $lastActivity['end_date'] = $activity['start_date'];
                        $lastActivity['date_end_utc'] = $activity['date_end_utc'];
                        $lastActivity['time_end'] = $activity['time_end'];
                        $lastActivity['timestamp_end'] = $activity['timestamp_end'];
                        $lastActivity['end_timestamp'] = $activity['end_timestamp'];
                        $lastActivity['duration'] += $diff;
                    }
                    else
                    {
                        $newActivity['slot'] = $lastActivity['slot'];
                        $newActivity['team'] = '0';
                        $newActivity['inserted'] = '0';;
                        $newActivity['activity'] = '0';
                        $newActivity['duration'] = $diff;
                        $newActivity['date_start'] = $lastActivity['date_end'];
                        $newActivity['start_date'] = $lastActivity['end_date'];
                        $newActivity['date_start_utc'] = $lastActivity['date_end_utc'];
                        $newActivity['date_end'] = $activity['date_start'];
                        $newActivity['end_date'] = $activity['start_date'];
                        $newActivity['date_end_utc'] = $activity['date_start_utc'];
                        $newActivity['time_start'] = $lastActivity['time_end'];
                        $newActivity['time_end'] = $activity['time_start'];
                        $newActivity['timestamp_start'] = $lastActivity['timestamp_end'];
                        $newActivity['timestamp_end'] = $activity['timestamp_start'];
                        $newActivity['start_timestamp'] = $lastActivity['end_timestamp'];
                        $newActivity['end_timestamp'] = $activity['start_timestamp'];
                        array_push($mergedActivities, $lastActivity);
                        $lastActivity = $newActivity;
                        //var_dump($lastActivity);
                        //var_dump($activity);
                        //var_dump($newActivity);
                    }
                }

                if (($activity['activity']==$lastActivity['activity'] && ($activity['team']==$lastActivity['team'] || $activity['activity'] == 0)) || ($lastActivity['activity'] == 0 && $inserted == 1 && $activity['activity'] == 4))
                {
                    $lastActivity['duration'] = intval($lastActivity['duration']) + intval($activity['duration'])/60;
                    $lastActivity['date_end'] = $activity['date_end'];
                    $lastActivity['end_date'] = $activity['end_date'];
                    $lastActivity['date_end_utc'] = $activity['date_end_utc'];
                    $lastActivity['time_end'] = $activity['time_end'];
                    $lastActivity['timestamp_end'] = $activity['timestamp_end'];
                    $lastActivity['end_timestamp'] = $activity['end_timestamp'];
                    if ($activity['inserted'] == 1) $inserted = 1;
                }
                else
                {
                    array_push($mergedActivities, $lastActivity);
                    $lastActivity = $activity;
                    $inserted = $activity['inserted'];
                    $lastActivity['duration'] = intval($activity['duration'])/60;
                }
            }
            else
            {
                if ($changeUndefined == true && $activity['activity'] == 4 ) #!($lastActivity['activity'] == 0 && $lastActivity['duration'] > 453) &&
                {
                    $activity['activity'] = 0;
                }
                $lastActivity = $activity;
                $inserted = $activity['inserted'];
                $lastActivity['duration'] = intval($activity['duration'])/60;
            }
        }
        
        array_push($mergedActivities, $lastActivity);
        if ($changeUndefined == true)
        {
            $filteredActivities = array();
            foreach ($mergedActivities as $mergedActivity)
            {
                // lange Bereitschafts- oder Arbeitsblocke werden als Pause betrachtet
                if ((($mergedActivity['activity'] == 1 || $mergedActivity['activity'] == 2) && $mergedActivity['duration'] > 360) ||
                    ($mergedActivity['activity'] == 3 && $mergedActivity['duration'] > 480)) #!($lastActivity['activity'] == 0 && $lastActivity['duration'] > 453) &&
                {
                    $mergedActivity['activity'] = 0;
                }
                array_push($filteredActivities, $mergedActivity);
            }
            return $filteredActivities;
        }

        return $mergedActivities;
    }

    public function trimActivities($activities, $startDate, $endDate)
    {
        $startDate = new \DateTime($startDate);
        $startDateTime = strtotime($startDate->format('Y-m-d H:i:s'));
        $endDate = new \DateTime($endDate);
        $endDateTime = strtotime(str_replace("00:00:00", "24:00:00", $endDate->format('Y-m-d H:i:s')));
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

    public function getDriverRestTimes($driverId, $startDate, $endDate, $shortened)
    {
        if ($shortened == false)
        {
            $restTimes = $this->dbQuery->getRestTimes($driverId, $startDate);
        }
        else
        {
            $restTimes = $this->dbQuery->getShortenedRestTimes($driverId, $startDate, $endDate);
        }

        return $restTimes;
    }

    /**
     * Die Funktion aktualisiert den letzten Download und die Führerscheinnummer 
     * des Fahrers in der difa_resources.driver Tabelle
     *
     * @param integer   $driverId  ID des Fahrers
     * 
     * @author Oleg Manov
     */
    public function updateDriverData($driverId)
    {
        $newestData = $this->dbQuery->getNewestDriverData($driverId);
        //var_dump('driver: '.$driverId.'; last download: '.$newestData['file_created'].'; driver licence:'.$newestData['licence_number']);
        $values = array('last_download' => $newestData['file_created'], 'licence_number' => $newestData['licence_number'], 'idx' => $driverId);
        $setStr = "`last_download` = :last_download, `driver_licence` = :licence_number";
        $this->dbQuery->updateDriverData($values, $setStr);
    }
}