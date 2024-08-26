<?php 
set_time_limit(90000);

class GeoMapper
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
     * startet das Geo-Mapping pro Fahrer 
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
        if (!is_null($telematic) && count($telematic)>0 && $telematic[0]['provider'] != 'manually' && !is_null($telematic[0]['time_zone']))
        { 
            $timezone = $telematic[0]['time_zone'];
            $allPositions = $this->dbQuery->getGeoPositions($clientId.'', $this->driver['idx'], $startDate);
            if (isset($allPositions) && !is_null($allPositions) && count($allPositions)>0)
            {
                $countries = $this->dissolveCountries();
                $this->mappGeoData($clientId.'', $startDate, $allPositions, $countries, $timezone);
            }
            else
                echo RED . 'Fahrer ' . $this->driver['idx'] . ' hat keine Geo-Positionen!' . RESET . "\n";
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
     * @param int       clientId        Client Id
     * @param string    startDate       Startdatum
     * @param array     allPositoins    alle Positionen des Fahrers
     * @param array     countries       alle Europa Länder
     * @param string    timezone        die Zeitzone vom Telematikanbieter
     * 
     * @author Oleg Manov
     */
    public function mappGeoData($clientId, $startDate, $allPositions, $countries, $timezone)
    {
        $workingTimes = $this->dbQuery->getWorkingTimesForGeo($this->driver['idx'], $startDate, $timezone);

        if (!is_null($workingTimes) && count($workingTimes)>0)
        {
            echo (YELLOW . "Geo-Positionen mappen..." . RESET . "\n");

            foreach ($workingTimes as $shift) {
                $positions = $this->getShiftPositions($allPositions, $shift['payment_start'], $shift['payment_end']);
                if (!is_null($positions) && count($positions)>0)
                {
                    //echo '+';
                    $cities = array();
                    $count = count($positions);
                    $startLand = $positions[0]['country'];
                    $startCity = $positions[0]['city'];
                    $endLand = $positions[$count-1]['country'];
                    $endCity = $positions[$count-1]['city'];
                    $stopover = null;
                    $borderLand = null;
                    $borderLandIDX = null;
                    $borderTime = null;
                    for ($i=0; $i < $count; $i++) { 
                        if ($startCity == '-')
                        {
                            $startCity = $positions[$i]['city'];
                            $startLand = $positions[$i]['country'];
                        }
                        if ($startLand != $positions[$i]['country'] && $startCity != '-' && $positions[$i]['country'] != $borderLand) {
                            $lastTime = new \DateTime($positions[$i]['time_stamp']);
                            $shiftEnd = new \DateTime($shift['payment_end_real']);
                            if($lastTime <= $shiftEnd)
                            {
                                $cities = array();
                                $borderLand = $positions[$i]['country'];
                                $firstTime = new \DateTime($positions[$i-1]['time_stamp']);
                                $borderTime = date('Y-m-d H:i:s', intval(($lastTime->getTimeStamp() - $firstTime->getTimeStamp())/2)+$firstTime->getTimeStamp());
                            }
                        }

                        if ($positions[$i]['country'] != 'de' && $positions[$i]['city'] != '' && $positions[$i]['city'] != '-')
                        {
                            array_push($cities, $positions[$i]['city']." ". strtoupper($positions[$i]['country']));
                        }
                    }
                    $startLandIDX = $countries[$startLand];
                    $endLandIDX = $countries[$endLand];
                    if ($borderLand != null && $borderLand != '-') {
                        $borderLandIDX = $countries[$borderLand];
                    }
                    else
                    {
                        $borderTime = null;
                    }

                    $stopover = null;
                    if ($borderLandIDX != null && $startLand == 'de' && $endLand == 'de')
                    {
                        $stopover = $cities[floor(count($cities)/2)];
                        //print $shift['payment_start']. "\n".implode(' ', $cities)."\n\n";
                        //print "\n". $shift['payment_start'] ." STOPOVER: ".$cities[floor(count($cities)/2)]."\n\n";
                    }
                    $this->dbQuery->updateGeoCoordinates($this->driver['idx'], $shift['idx'], $startLandIDX, $startCity, $endLandIDX, $endCity, $borderLandIDX, $borderTime, $stopover);
                }
            }
        }

        return;
    }

    /**
     * Die Funktion gibt alle Positionen von einem bestimmten Zeitinterval zurück (Schicht)
     * 
     * @param array     allPositoins  alle Positionen des Fahrers
     * @param string    start         Startdatum
     * @param string    end           Enddatum
     * 
     * @author Oleg Manov
     *
     * @return array
     **/
    private function getShiftPositions($allPositions, $start, $end)
    {
        $shiftPositions = array();
        foreach ($allPositions as $position)
        {
            if ($position['time_stamp'] >= $start && $position['time_stamp'] <= $end)
            {
                array_push($shiftPositions, $position);
            }
        }

        return $shiftPositions;
    }

    /**
     * Die Funktion gibt alle Länder als key -> value pair zurück
     * 
     * @author Oleg Manov
     *
     * @return array
     **/
    private function dissolveCountries()
    {
        echo YELLOW . 'Länder auflösen...' . RESET . "\n";
        $countriesByTld = array();
        $countries = $this->dbQuery->getCountries();
        foreach ($countries as $country)
        {
            $countriesByTld[strtolower($country['region_short'])] = $country['idx'];
        }

        return $countriesByTld;
    }
}