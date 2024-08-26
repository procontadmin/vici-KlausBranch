<?php

class Faehre
{
    /**
     * The DbQuery object
     *
     * @var CoreDbQueries
     */
    protected $dbQuery = null;

    /**
     * The Driver
     *
     * @var array
     */
    protected $driver = null;

    public function __construct($driver, $dbQuery)
    {
        $this->driver = $driver;
        $this->dbQuery = $dbQuery;
    }

    public function run()
    {

        //get data
        $driverCardIds = explode(',', $this->driver['dtco_driver_idx']);
        $ferryEntrys = array();
        foreach ($driverCardIds as $key => $driverCardId) {
            $ferryEntrys = array_merge($ferryEntrys, $this->dbQuery->getSpecificCondition($driverCardId));

            //check the activities
            if ($this->checkForEmpty($ferryEntrys)) {
                //loop through ferryEntrys
                foreach($ferryEntrys as $key => $value) {
                    $test = new \Datetime($value["entry_time"]);
                    if($test->format("Y") == "2023") {
                        if($key > 1) {
                            if($this->calculateEntrysOnSameDay(
                                $ferryEntrys[$key],
                                $ferryEntrys[$key - 1],
                                $ferryEntrys[$key - 2]
                            )) {
                                $ferryEntrysToCalculate = array();
                                array_push(
                                    $ferryEntrysToCalculate,
                                    $ferryEntrys[$key - 2],
                                    $ferryEntrys[$key - 1],
                                    $ferryEntrys[$key]
                                );
                                $workingTime = array();
                                $workingTime2 = array();
                                $workingTime = $this->dbQuery->getWorkingtime($this->driver["idx"], $ferryEntrys[$key - 2]["entry_time"]);
                                $workingTime2 = $this->dbQuery->getWorkingtime($this->driver["idx"], $ferryEntrys[$key]["entry_time"]);
                                if($this->checkForEmpty($workingTime) && $this->checkForEmpty($workingTime2)) {

                                    if($workingTime == $workingTime2) {
                                        $this->dbQuery->setWorkingTimeInactive($this->driver["idx"], $workingTime[0]["payment_start"]);
                                    } else {
                                        $this->dbQuery->setWorkingTimeInactive($this->driver["idx"], $workingTime[0]["payment_start"]);
                                        $this->dbQuery->setWorkingTimeInactive($this->driver["idx"], $workingTime2[0]["payment_start"]);
                                    }
                                    $restTimeType = $this->dbQuery->getRestTimeType($this->driver["idx"], $ferryEntrys[$key]["entry_time"]);
                                    $restTimeType = is_null($restTimeType) ? 0 : $restTimeType[0]["rest_time_type"];
                                    $activities = array();
                                    $activities = $this->dbQuery->getExtraActivities($driverCardId, $workingTime[0]["payment_start"], $ferryEntrys[$key]["entry_time"]);
                                    // var_dump($ferryEntrys[$key]["entry_time"]);
                                    // var_dump(count($activities));
                                    // var_dump($activities[0]["start"]);
                                    // var_dump($activities[count($activities) - 1]["start"]);
                                    // var_dump($this->secondsToTime($activities[count($activities) - 1]["duration"]));
                                    $newWorkingTime = $this->dataForNewFerryWorkingTime($activities, $ferryEntrysToCalculate, $restTimeType);
                                    // var_dump($newWorkingTime["payment_end"]);
                                    // var_dump($newWorkingTime["daily_rest_time"]);
                                    // var_dump($newWorkingTime["daily_rest_time"]/60);
                                    $secondStartTime = $newWorkingTime["payment_end"];
                                    $secondStartTime = substr($secondStartTime, 1, -1);
                                    $newStartTime = new \Datetime($secondStartTime);
                                    // var_dump($newStartTime->format('Y-m-d H:i:s'));
                                    $adf = new \DateInterval('PT'. $newWorkingTime["daily_rest_time"] .'M');
                                    // var_dump($adf);
                                    $newStartTime->add($adf);
                                    $newEndTime = new \Datetime($workingTime2[0]["payment_end"]);
                                    if($newStartTime < $newEndTime) {
                                        $secondActivities = array();
                                        $secondActivities = $this->dbQuery->getExtraActivities($driverCardId, $newStartTime->format('Y-m-d H:i:s'), $newEndTime->format('Y-m-d H:i:s'));
                                        $extraActiv = $this->dbQuery->getExtraActivitiesAfter($driverCardId, $secondActivities[count($secondActivities)-1]["block_counter"]+1);
                                        if($this->checkForEmpty($extraActiv)) {
                                            $secondActivities = array_merge($secondActivities, $extraActiv);
                                        }
                                        // var_dump($extraActiv);
                                        // var_dump($secondActivities[count($secondActivities)-1]);
                                        // $newStartTime->setTimezone(new \DateTimeZone('Europe/Berlin'));
                                        // var_dump($newStartTime->format('Y-m-d H:i:s'));
                                        // $newEndTime->setTimezone(new \DateTimeZone('Europe/Berlin'));
                                        // var_dump($newEndTime->format('Y-m-d H:i:s'));
                                        $secondWorkingTime = $this->dataForNewFerryWorkingTime($secondActivities, $ferryEntrysToCalculate, $restTimeType);
                                        // var_dump($secondWorkingTime["payment_end"]);
                                        $this->dbQuery->insertNewWorkingTime($this->driver["idx"], $newWorkingTime);
                                        $this->dbQuery->insertNewWorkingTime($this->driver["idx"], $secondWorkingTime);
                                        echo("done\n");
                                    } else {
                                        $this->dbQuery->insertNewWorkingTime($this->driver["idx"], $newWorkingTime);
                                        echo("edgecase\n");
                                    }

                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function secondsToTime($seconds)
    {
        $dtF = new \DateTime('@0');
        $dtT = new \DateTime("@$seconds");
        return $dtF->diff($dtT)->format('%a days, %h hours, %i minutes and %s seconds');
    }

    private function checkForEmpty($arrayToCheck)
    {
        if (!is_null($arrayToCheck) && count($arrayToCheck) > 0) {
            return true;
        } else {
            return false;
        }
    }

    public function calculateEntrysOnSameDay(
        $specificConditionPotentialLast,
        $specificConditionPotentialMiddle,
        $specificConditionPotentialFirst
    ) {
        $date = new \Datetime($specificConditionPotentialLast["entry_time"]);
        $dateBefore = new \Datetime($specificConditionPotentialMiddle["entry_time"]);
        $dateTwoBefore = new \Datetime($specificConditionPotentialFirst["entry_time"]);
        $diffBefore = $date->diff($dateBefore);
        $diffTwoBefore = $date->diff($dateTwoBefore);
        if($diffBefore->format('%a') == 0 && $diffTwoBefore->format('%a') == 0) {
            return true;
        } else {
            return false;
        }
    }

    public function calculateRightUsage($activity, $calculationConditions)
    {
        $restDuration = 0;
        $otherDuration = 0;
        foreach ($activity as $key => $value) {
            if ($value["activity"] == 0) {
                $start = new \Datetime($value["start"]);
                $sdiff = $start->diff(new \Datetime($calculationConditions[0]["entry_time"]));
                $sdiff1 = $start->diff(new \Datetime($calculationConditions[1]["entry_time"]));
                $sdiff2 = $start->diff(new \Datetime($calculationConditions[2]["entry_time"]));
                $timediff = 10;
                $sbool = $sdiff->format('%i') < $timediff && $sdiff->format('%h') == 0;
                $sbool1 = $sdiff1->format('%i') < $timediff && $sdiff1->format('%h') == 0;
                $sbool2 = $sdiff2->format('%i') < $timediff && $sdiff2->format('%h') == 0;
                if($sbool || $sbool1 || $sbool2) {
                    $restDuration += $value["duration"];
                }
            } else {
                $otherDuration += $value["duration"];
            }
        }

        if ($restDuration > 11*60*60 && $otherDuration < 60*60) {
            return true;
        } else {
            return false;
        }
    }

    public function checkForNewerActivity($activity, $timeToCheck)
    {
        if(!is_null($activity)) {
            if(count($activity) == 2) {
                $start = new \Datetime($activity[0]["start"]);
                $sdiff = $start->diff(new \Datetime($timeToCheck));
                $start2 = new \Datetime($activity[1]["start"]);
                $sdiff2 = $start2->diff(new \Datetime($timeToCheck));
                $closerTime = $sdiff->format('%i') > $sdiff2->format('%i') ? 0 : 1;
                return $activity[$closerTime];
            } else {
                return $activity[0];
            }

        }
    }

    public function dataForNewFerryWorkingTime($completeActivities, $ferryTime, $restTime)
    {
        $ferryDateTimeFirst = new \DateTime($ferryTime[0]["entry_time"]);
        $ferryDateTimeSecond = new \DateTime($ferryTime[1]["entry_time"]);
        $workingTimes["payment_start"] = "";
        $workingTimes["payment_end"] = "";
        $workingTimes["duration_all"] = 0;
        $workingTimes["duration_driving"] = 0;
        $workingTimes["duration_work"] = 0;
        $workingTimes["duration_standby"] = 0;
        $workingTimes["duration_standby_team"] = 0;
        $workingTimes["duration_break0"] = 0;
        $workingTimes["duration_break15"] = 0;
        $workingTimes["working_time"] = 0;
        $workingTimes["exceeding"] = 0;
        $workingTimes["break_3h"] = 0;
        $workingTimes["daily_rest_time"] = 0;
        $workingTimes["rest_time_all"] = 0;
        if($restTime != 0) {
            $workingTimes["rest_time_type"] = $restTime;
        }
        $workingTimes["team_mode"] = 0;
        /* $workingTimes["place_start"] = "";
         $workingTimes["country_start"] = "";
         $workingTimes["stopover"] = "";
         $workingTimes["place_end"] = "";
         $workingTimes["country_end"] = "";
         $workingTimes["country_border_crossing"] = "";
         $workingTimes["time_border_crossing"] = "";*/
        $workingTimes["type"] = 0;
        $workingTimes["status"] = 1;

        //Berechnung
        foreach ($completeActivities as $key => $value) {
            if($workingTimes["team_mode"] == 0 && $value["team"] == 1) {
                $workingTimes["team_mode"] = 1;
            }
            $startingTime = new \DateTime($value["start"]);
            $diffFirst = date_diff($ferryDateTimeFirst, $startingTime);
            $diffSecond = date_diff($ferryDateTimeSecond, $startingTime);
            $minutesFirst = ($diffFirst->days * 24 * 60) + ($diffFirst->h * 60) + $diffFirst->i;
            $minutesSecond = ($diffSecond->days * 24 * 60) + ($diffSecond->h * 60) + $diffSecond->i;
            switch($value["activity"]) {
                case 0:
                    if($value["duration"]/60 < 15) {
                        $workingTimes["duration_break0"] += $value["duration"]/60;
                        $workingTimes["working_time"] += $value["duration"]/60;
                        $workingTimes["duration_all"] += $value["duration"]/60;
                    } elseif($minutesFirst <= 10) {
                        $workingTimes["rest_time_all"] += $value["duration"]/60;
                        // echo(1);
                        // var_dump($value["duration"]/60/60);
                        $workingTimes["payment_end"] =
                        substr($value["start"], 11) == "00:00:00" && $completeActivities[$key - 1]["activity"] == 0 ?
                             $completeActivities[$key - 1]["start"] : $value["start"];
                    } elseif($minutesSecond == 0) {
                        $workingTimes["rest_time_all"] += $value["duration"]/60;
                        // echo(2);
                        // var_dump($value["duration"]/60/60);
                    } elseif($value["start"] == $completeActivities[count($completeActivities) - 1]["start"]
                    && $value["block_counter"] == $completeActivities[$key - 1]["block_counter"]) {
                        $workingTimes["rest_time_all"] += $value["duration"]/60;
                        // echo(3);
                        // var_dump($value["duration"]/60/60);
                        // $startingTime->setTimezone(new \DateTimeZone('Europe/Berlin'));
                        // var_dump($startingTime->format('Y-m-d H:i:s'));
                        $workingTimes["payment_end"] = $workingTimes["payment_end"] == "" ? $value["start"] : $workingTimes["payment_end"];
                    } elseif($value["start"] == $completeActivities[count($completeActivities) - 2]["start"]
                    && $value["block_counter"] == $completeActivities[$key - 1]["block_counter"]) {
                        $workingTimes["rest_time_all"] += $value["duration"]/60;
                        // echo(4);
                        $workingTimes["payment_end"] = $workingTimes["payment_end"] == "" ? $value["start"] : $workingTimes["payment_end"];
                    } elseif($value["block_counter"] != $completeActivities[$key - 1]["block_counter"]) {
                        $workingTimes["rest_time_all"] += $value["duration"]/60;
                        // echo(5);
                    } else {
                        $workingTimes["duration_break15"] += $value["duration"]/60;
                        $workingTimes["duration_all"] += $value["duration"]/60;
                        if($value["duration"]/60 > 3*60) {
                            $workingTimes["break_3h"] = 1;
                        }
                    }
                    break;
                case 3:
                    $workingTimes["duration_driving"] += $value["duration"]/60;
                    $workingTimes["working_time"] += $value["duration"]/60;
                    $workingTimes["duration_all"] += $value["duration"]/60;
                    $workingTimes["payment_start"] = $workingTimes["payment_start"] == "" ? $value["start"] : $workingTimes["payment_start"];
                    break;
                case 2:
                    $workingTimes["duration_work"] += $value["duration"]/60;
                    $workingTimes["working_time"] += $value["duration"]/60;
                    $workingTimes["duration_all"] += $value["duration"]/60;
                    $workingTimes["payment_start"] = $workingTimes["payment_start"] == "" ? $value["start"] : $workingTimes["payment_start"];
                    break;
                case 1:
                    if($value["team"]=="1") {
                        $workingTimes["duration_standby"] += $value["duration"]/60;
                        //$workingTimes["working_time"] += $value["duration"]/60;
                        $workingTimes["duration_all"] += $value["duration"]/60;
                    } else {
                        $workingTimes["duration_standby_team"] += $value["duration"]/60;
                        //$workingTimes["working_time"] += $value["duration"]/60;
                        $workingTimes["duration_all"] += $value["duration"]/60;
                    }
                    break;
            }
            if($value["start"] > $ferryTime[0]["entry_time"] && $value["start"] < $ferryTime[2]["entry_time"]) {
                if($value["activity"] != 0) {
                    $workingTimes["rest_time_all"] += $value["duration"]/60;
                }
            }
        }

        $workingTimes["exceeding"] = $workingTimes["working_time"] > 600 ? $workingTimes["working_time"] - 600 : 0;

        //$workingTimes["daily_rest_time"] = "";
        $paymentStart = new \DateTime($workingTimes["payment_start"]);
        $paymentEnd = new \DateTime($workingTimes["payment_end"]);
        $diffStartToEnd = date_diff($paymentStart, $paymentEnd);
        $minutesStartToEnd = ($diffStartToEnd->days * 24 * 60) + ($diffStartToEnd->h * 60) + $diffStartToEnd->i;
        // var_dump($minutesStartToEnd/60);
        // var_dump(24*60);
        // var_dump($workingTimes["rest_time_all"]/60);
        if($minutesStartToEnd > 24*60) {
            $workingTimes["daily_rest_time"] = 24*60 - $minutesStartToEnd;
        } else {
            $workingTimes["daily_rest_time"] =
            $minutesStartToEnd - 24*60 > $workingTimes["rest_time_all"] ? $minutesStartToEnd - 24*60 : $workingTimes["rest_time_all"];
        }

        $workingTimes["payment_start"] = "'" . $workingTimes["payment_start"] . "'";
        $workingTimes["payment_end"] = "'" . $workingTimes["payment_end"] . "'";
        return $workingTimes;
    }
}
