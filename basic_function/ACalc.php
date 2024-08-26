<?php

class ACalc
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
     * und startet die Berechnungen der Zulagen.
     *
     * @param string    startDate    Startdatum
     *
     * @author Oleg Manov
     */
    public function run($startDate)
    {
        $start = microtime(true);
        echo(YELLOW . "calculating allowances..." . RESET . "\n");
        $driver = $this->dbQuery->getDriverInfoAllowances($this->driver["idx"]);
        if (!is_null($driver) && count($driver) > 0) {
            $dateStart = new \DateTime('2021-01-02');

            for ($i = 0; $i < count($driver); $i++) {
                //var_dump($driver[$i]['history_idx']);
                echo YELLOW . "driver: " . $driver[$i]['idx'] . RESET . "\n";
                $driver[$i]['parent_idx'] == 0 ? $cid = $driver[$i]['client_idx'] : $cid = $driver[$i]['parent_idx'];
                if (!is_null($driver[$i]["change_date"])) {
                    $changeDate = new \DateTime($driver[$i]["change_date"]);
                    if ($dateStart < $changeDate) {
                        $dateStart = $changeDate ;
                    }
                }
                if (is_null($driver[$i]["driver_types_idx"])) {
                    $driver[$i]["driver_types_idx"] = "-1";
                }
                $driverTypes = explode(',', $driver[$i]["driver_types_idx"]);
                foreach ($driverTypes as $driverType) {
                    echo YELLOW . "Driver group: " . $driverType. RESET . "\n";
                    $aGroupsHB = $this->dbQuery->getClientPaymentGroupsAllowances($cid, 1, $driver[$i]['idx'], $driver[$i]['history_idx'], $driverType, " AND (pv.unit_idx = 6 OR pv.calc_type_idx = 1) ");
                    $aGroupsDB = $this->dbQuery->getClientPaymentGroupsAllowances($cid, 1, $driver[$i]['idx'], $driver[$i]['history_idx'], $driverType, " AND pv.unit_idx in (2,5) ");
                    $aGroupsSB = $this->dbQuery->getClientPaymentGroupsAllowances($cid, 1, $driver[$i]['idx'], $driver[$i]['history_idx'], $driverType, " AND pv.unit_idx = 4 ");
                    $aGroupsSC = $this->dbQuery->getClientPaymentGroupsAllowances($cid, 1, $driver[$i]['idx'], $driver[$i]['history_idx'], $driverType, " AND pv.unit_idx = 10 ");
                    // var_dump((!is_null($aGroupsDB) && count($aGroupsDB)>0));
                    if ((!is_null($aGroupsHB) && count($aGroupsHB)>0) || (!is_null($aGroupsDB) && count($aGroupsDB)>0) ||
                        (!is_null($aGroupsSB) && count($aGroupsSB)>0) || (!is_null($aGroupsSC) && count($aGroupsSC)>0)) {
                        $this->startDriverCalculations($driver[$i], $aGroupsHB, $aGroupsDB, $aGroupsSB, $aGroupsSC, $dateStart->modify('-1 day')->format('Y-m-d'));
                    } else {
                        echo RED . "Keine Zulagen gefunden!" . RESET . "\n";
                    }
                }
            }

        } else {
            echo RED . "Fahrer nicht gefunden!" . RESET . "\n";
        }


        $timeElapsed = microtime(true) - $start;
        echo(YELLOW . $timeElapsed . RESET . "\n");

        return;
    }

    private function startDriverCalculations($driver, $aGroupsHB, $aGroupsDB, $aGroupsSB, $aGroupsSC, $startDate)
    {
        //$this->model->createDocketTable($driver['idx']);
        //var_dump($driver['driver_idx']);

        $workingTimes = null;
        $sDate = $startDate;
        $workingTimes = $this->dbQuery->getWorkingTimesCET($driver['idx'], $startDate);

        //var_dump(count($workingTimes));
        if (!is_null($workingTimes) && count($workingTimes)>0) {
            //$this->dbQuery->deleteAllowances($driver['idx'], $sDate);

            if (!is_null($aGroupsHB)) {
                $this->calculateHourlyBasedAllowances($driver, $startDate, $workingTimes, $aGroupsHB);
            }
            if (!is_null($aGroupsDB)) {
                $this->calculateDailyBasedAllowances($driver, $startDate, $workingTimes, $aGroupsDB);
            }
            if (!is_null($aGroupsSB)) {
                $this->calculateShiftBasedAllowances($driver, $startDate, $workingTimes, $aGroupsSB);
            }
            if (!is_null($aGroupsSC)) {
                $this->calculateSpecialAllowances($driver, $startDate, $aGroupsSC);
            }
        }
        return json_encode('END');
    }

    private function calculateHourlyBasedAllowances($driver, $startDate, $workingTimes, $allowancesGroups)
    {
        $midnight24 = strtotime('24:00:00');
        $midnight0 = strtotime('00:00:00');
        $lastShift = null;
        $allowancesTimesByDays = array();
        $allowanceDay = $this->getEmptyDayObj($startDate, $allowancesGroups);
        //var_dump($allowancesGroups);

        foreach ($workingTimes as $shift) {
            print '.';

            $startDateTime = strtotime($shift['time_start']);
            $endDateTime = strtotime($shift['time_end']);
            $startDateTimeManual = strtotime($shift['time_start_manual']);
            $endDateTimeManual = strtotime($shift['time_end_manual']);

            if ($shift['date_start'] != $shift['date_end']) {
                //new day?
                if ($allowanceDay['date'] != $shift['date_start']) {
                    //var_dump($allowanceDay);
                    array_push($allowancesTimesByDays, $allowanceDay);
                    $allowanceDay = $this->getEmptyDayObj($shift['date_start'], $allowancesGroups);
                }

                $sameDayShift = $shift;
                $timeDiff = ($midnight24 - $startDateTime)/60;
                $sameDayShift['duration_all'] = $timeDiff;
                $timeDiffManual = ($midnight24 - $startDateTimeManual)/60;
                $sameDayShift['duration_all_manual'] = $timeDiffManual;
                $sameDayShift['date_end'] = $shift['date_start']." 24:00:00";
                //$allowancesGroups, $shiftDuration, $allowanceDay, $startDateTime, $endDateTime, $dateStart
                $allowanceDay = $this->proceedShift($allowancesGroups, $allowanceDay, $sameDayShift['duration_all'], $sameDayShift['duration_all_manual'], $startDateTime, $startDateTimeManual, $midnight24, $midnight24);
                //var_dump($allowanceDay);
                array_push($allowancesTimesByDays, $allowanceDay);

                $allowanceDay = $this->getEmptyDayObj($shift['date_end'], $allowancesGroups);
                $nextDayShift = $shift;
                $nextDayShift['duration_all'] = $shift['duration_all'] - $timeDiff;
                $nextDayShift['duration_all_manual'] = $shift['duration_all_manual'] - $timeDiffManual;
                $nextDayShift['date_start'] = $shift['date_end']." 00:00:00";

                $allowanceDay = $this->proceedShift($allowancesGroups, $allowanceDay, $nextDayShift['duration_all'], $nextDayShift['duration_all_manual'], $midnight0, $midnight0, $endDateTime, $endDateTimeManual);
            } else {
                if ($allowanceDay['date'] == $shift['date_start']) {
                    //same day
                    $allowanceDay = $this->proceedShift($allowancesGroups, $allowanceDay, $shift['duration_all'], $shift['duration_all_manual'], $startDateTime, $startDateTimeManual, $endDateTime, $endDateTimeManual);
                } else {
                    //new day
                    //var_dump('tuk');
                    //var_dump($allowanceDay);
                    array_push($allowancesTimesByDays, $allowanceDay);
                    $allowanceDay = $this->getEmptyDayObj($shift['date_start'], $allowancesGroups);
                    $allowanceDay = $this->proceedShift($allowancesGroups, $allowanceDay, $shift['duration_all'], $shift['duration_all_manual'], $startDateTime, $startDateTimeManual, $endDateTime, $endDateTimeManual);
                }
            }
        }
        echo RESET . "\n";
        //var_dump($allowanceDay);
        array_push($allowancesTimesByDays, $allowanceDay);
        //$result = '';
        $allowancesTimes = '';
        //var_dump($allowancesTimesByDays);
        foreach ($allowancesTimesByDays as $allowanceDay) {
            //var_dump("===========================");
            //var_dump($allowanceDay['date']);
            //$result .= "\n";
            foreach ($allowancesGroups as $aGroup) {

                if ($allowanceDay[$aGroup['idx']] > 0) {
                    //$result .= "\n";
                    //var_dump($allowanceDay[$aGroup['idx']]);
                    //$result .= "// ".$allowanceDay['date']." - ".$aGroup['idx'].": ".$allowanceDay[$aGroup['idx']];
                    $time = $allowanceDay[$aGroup['idx']];
                    // var_dump($aGroup['idx']);
                    //var_dump($time);

                    //TODO: hardcodiert - muss als Einstellung implementiert werden
                    if (!is_null($aGroup['subtraction'])) {
                        //var_dump($time);
                        $time -= floor($time/60)*$aGroup['subtraction'];
                        //var_dump($time);
                    }

                    // fester Wert
                    if ($aGroup['value_type'] == 1) {
                        $amountValue = $aGroup['amount'];
                    } else {
                        // Wert Intervall
                        // Länge der Arbeitsverhältnisse
                        if ($aGroup['calc_basis'] == 1) {
                            if (!is_null($driver['contract_start'])) {
                                $driverContract = new \DateTime($driver['contract_start']);
                                $aDay = new \DateTime($allowanceDay['date']);
                                $timeDiff = $aDay->diff($driverContract);
                                $amountValue = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                            }
                        }
                    }

                    if ($aGroup['unit_idx'] == 10 and $aGroup['calc_type_idx'] == 1) {
                        $amount = $time;
                    } else {
                        switch ($aGroup['cost_rate_idx']) {
                            case 1:
                                $amount = floor($time/60)*$amountValue;
                                break;
                            case 2:
                                $amount = ceil($time/60)*$amountValue;
                                break;
                            case 3:
                                $amount = floor($time/60)*$amountValue+(($time%60)/60)*$amountValue;
                                break;
                        }
                    }
                    //var_dump($amount);
                    $endAmount = $this->getAmount($amount, $aGroup['min_amount'], $aGroup['max_amount']);
                    //var_dump($endAmount);
                    $allowancesTimes .= "('" . $allowanceDay['date'] . "'," . $aGroup['idx'] . "," . ($endAmount) ."), ";
                }
            }
        }
        if ($allowancesTimes != '') {
            $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowancesTimes, ', '));
        }
        return true;
    }

    private function calculateDailyBasedAllowances($driver, $startDate, $workingTimes, $allowancesGroups)
    {
        $allowances = '';
        $allowancesMonth = array();
        $driverGroups =  explode(',', $driver['allowance_groups_idx']);
        foreach ($allowancesGroups as $aGroup) {

            foreach ($workingTimes as $shift) {
                if ($aGroup['working_times_source'] == 0) {
                    $shiftDateStart = $shift['date_start'];
                    $shiftDateEnd = $shift['date_end'];
                    $startDate = new \DateTime($shift['payment_start']);
                } else {
                    $shiftDateStart = $shift['date_start_manual'];
                    $shiftDateEnd = $shift['date_end_manual'];
                    $startDate = new \DateTime($shift['payment_start_manual']);
                }

                if ($aGroup['unit_idx'] == 5) {
                    //var_dump($shiftDateStart);
                    print('.');
                    $gTimeStart = strtotime($aGroup['time_start']);
                    $gTimeEnd = strtotime($aGroup['time_end']);

                    $startDateTime = strtotime($startDate->format('H:i:s'));

                    $aGroup['working_times_source'] == 0 ? $endDate = new \DateTime($shift['payment_end']) : $endDate = new \DateTime($shift['payment_end_manual']);
                    $endDateTime = strtotime($endDate->format('H:i:s'));

                    if($shiftDateStart === $shiftDateStart && $this->checkWeekDayCompliance($aGroup, $shiftDateStart)) {
                        if(($gTimeStart >= $startDateTime && $gTimeStart <= $endDateTime) ||
                            ($gTimeEnd >= $startDateTime && $gTimeEnd <= $endDateTime) ||
                            ($startDateTime >= $gTimeStart && $startDateTime <= $gTimeEnd) ||
                            ($endDateTime >= $gTimeStart && $endDateTime <= $gTimeEnd)) {
                            // fester Wert
                            if ($aGroup['value_type'] == 1) {
                                $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
                            } else {
                                // Wert Intervall
                                // Länge der Arbeitsverhältnisse
                                if ($aGroup['calc_basis'] == 1) {
                                    if (!is_null($driver['contract_start'])) {
                                        $driverContract = new \DateTime($driver['contract_start']);
                                        $timeDiff = $startDate->diff($driverContract);
                                        $amount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                                        $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$amount['amount']."), ";
                                    }
                                }
                            }
                        }
                    }

                    if($shiftDateStart !== $shiftDateStart) {
                        if ($this->checkWeekDayCompliance($aGroup, $shiftDateStart) && $gTimeEnd >= $startDateTime && $gTimeEnd <= $midnight24) {
                            // fester Wert
                            if ($aGroup['value_type'] == 1) {
                                $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
                            } else {
                                // Wert Intervall
                                // Länge der Arbeitsverhältnisse
                                if ($aGroup['calc_basis'] == 1) {
                                    if (!is_null($driver['contract_start'])) {
                                        $driverContract = new \DateTime($driver['contract_start']);
                                        $timeDiff = $startDate->diff($driverContract);
                                        $amount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                                        $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$amount['amount']."), ";
                                    }
                                }
                            }
                        }
                        if ($this->checkWeekDayCompliance($aGroup, $shiftDateStart) && $gTimeStart <= $endDateTime && $gTimeStart >= $midnight0) {
                            // fester Wert
                            if ($aGroup['value_type'] == 1) {
                                $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
                            } else {
                                // Wert Intervall
                                // Länge der Arbeitsverhältnisse
                                if ($aGroup['calc_basis'] == 1) {
                                    if (!is_null($driver['contract_start'])) {
                                        $driverContract = new \DateTime($driver['contract_start']);
                                        $timeDiff = $endDate->diff($driverContract);
                                        $amount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                                        $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$amount['amount']."), ";
                                    }
                                }
                            }
                        }
                    }

                    if (!is_null($aGroup['additional_time_start']) && !is_null($aGroup['additional_time_end'])) {
                        $agTimeStart = strtotime($aGroup['additional_time_start']);
                        $agTimeEnd = strtotime($aGroup['additional_time_end']);

                        if($shiftDateStart === $shiftDateStart && $this->checkWeekDayCompliance($aGroup, $shiftDateStart)) {
                            if(($agTimeStart >= $startDateTime && $agTimeStart <= $endDateTime) ||
                                ($agTimeEnd >= $startDateTime && $agTimeEnd <= $endDateTime) ||
                                ($startDateTime >= $agTimeStart && $startDateTime <= $agTimeEnd) ||
                                ($endDateTime >= $agTimeStart && $endDateTime <= $agTimeEnd)) {
                                // fester Wert
                                if ($aGroup['value_type'] == 1) {
                                    $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
                                } else {
                                    // Wert Intervall
                                    // Länge der Arbeitsverhältnisse
                                    if ($aGroup['calc_basis'] == 1) {
                                        if (!is_null($driver['contract_start'])) {
                                            $timeDiff = $startDate->diff($driverContract);
                                            $amount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                                            $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$amount['amount']."), ";
                                        }
                                    }
                                }
                            }
                        }

                        if($shiftDateStart !== $shiftDateStart) {
                            if ($this->checkWeekDayCompliance($aGroup, $shiftDateStart) && $agTimeEnd >= $startDateTime && $agTimeEnd <= $midnight24) {
                                // fester Wert
                                if ($aGroup['value_type'] == 1) {
                                    $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
                                } else {
                                    // Wert Intervall
                                    // Länge der Arbeitsverhältnisse
                                    if ($aGroup['calc_basis'] == 1) {
                                        if (!is_null($driver['contract_start'])) {
                                            $timeDiff = $startDate->diff($driverContract);
                                            $amount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                                            $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$amount['amount']."), ";
                                        }
                                    }
                                }
                            }
                            if ($this->checkWeekDayCompliance($aGroup, $shiftDateStart) && $agTimeStart <= $endDateTime && $agTimeStart >= $midnight0) {
                                // fester Wert
                                if ($aGroup['value_type'] == 1) {
                                    $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
                                } else {
                                    // Wert Intervall
                                    // Länge der Arbeitsverhältnisse
                                    if ($aGroup['calc_basis'] == 1) {
                                        if (!is_null($driver['contract_start'])) {
                                            $timeDiff = $startDate->diff($driverContract);
                                            $amount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                                            $allowances .= "('".$shiftDateStart."', ".$aGroup['idx'].", ".$amount['amount']."), ";
                                        }
                                    }
                                }
                            }
                        }
                    }
                } elseif ($aGroup['unit_idx'] == 2) {
                    //TODO get month from db and check if date is set in allowancesMonth.
                    $mDate = $startDate->format('Y-m').'-01';

                    //var_dump($mDate);
                    //var_dump($aGroup['amount']);
                    //var_dump($allowances);
                    // fester Wert
                    if ($aGroup['value_type'] == 1) {
                        $endAmount = $aGroup['amount'];
                        if ($aGroup['subtraction_type'] == 2) {
                            if ($aGroup['subtraction_basis'] == 2) {
                                $freeDays = $this->dbQuery->getFreeDays($driver['idx'], $startDate->format('m'), "2,3");
                                $subtAmount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], $freeDays['count'], 1);
                                $endAmount -= $freeDays['count'] * $subtAmount['amount'];
                            } elseif ($aGroup['subtraction_basis'] == 3) {
                                $freeDays = $this->dbQuery->getFreeDays($driver['idx'], $startDate->format('m'), "2");
                                $subtAmount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], $freeDays['count'], 1);
                                $endAmount -= $freeDays['count'] * $subtAmount['amount'];
                            }
                        }
                        $allowancesMonth[$mDate][$aGroup['idx']] = $this->getAmount($endAmount, $aGroup['min_amount'], $aGroup['max_amount']);
                    } else {
                        // Wert Intervall
                        // Länge der Arbeitsverhältnisse
                        if ($aGroup['calc_basis'] == 1) {
                            if (!is_null($driver['contract_start'])) {
                                $driverContract = new \DateTime($driver['contract_start']);
                                $timeDiff = $startDate->diff($driverContract);
                                $amount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], (($timeDiff->format('%y') * 12) + $timeDiff->format('%m')), 0);
                                $endAmount = $amount['amount'];
                                if ($aGroup['subtraction_type'] == 2) {
                                    if ($aGroup['subtraction_basis'] == 2) {
                                        $freeDays = $this->dbQuery->getFreeDays($driver['idx'], $startDate->format('m'), "2,3");
                                        // var_dump($freeDays);
                                        $subtAmount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], $freeDays['count'], 1);
                                        // var_dump($subtAmount);
                                        $endAmount -= $freeDays['count'] * $subtAmount['amount'];
                                    } elseif ($aGroup['subtraction_basis'] == 3) {
                                        $freeDays = $this->dbQuery->getFreeDays($driver['idx'], $startDate->format('m'), "2");
                                        // var_dump($freeDays);
                                        $subtAmount = $this->dbQuery->getAllowanceAmount($aGroup['value_idx'], $freeDays['count'], 1);
                                        // var_dump($subtAmount);
                                        $endAmount -= $freeDays['count'] * $subtAmount['amount'];
                                    }

                                }
                                $allowancesMonth[$mDate][$aGroup['idx']] = $this->getAmount($endAmount, $aGroup['min_amount'], $aGroup['max_amount']);
                            }
                        }
                    }
                    //var_dump($allowances);
                    //var_dump('///////////////////');
                }
            }

        }

        //var_dump($allowances);
        if ($allowances != '') {
            $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
        }
        // $this->model->saveAllowances($driverId, $allowances);
        $allowances = '';
        //var_dump($allowancesMonth);
        foreach($allowancesMonth as $aDate => $allowancesDay) {
            foreach($allowancesDay as $groupIdx => $amountDay) {
                $allowances .= "('".$aDate."', ".$groupIdx.", ".$amountDay."), ";
            }
        }
        //var_dump($allowances);
        if ($allowances != '') {
            $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
        }
        return true;
    }

    private function calculateShiftBasedAllowances($driver, $startDate, $workingTimes, $allowancesGroups)
    {
        $allowances = '';
        $driverGroups =  explode(',', $driver['allowance_groups_idx']);
        foreach ($allowancesGroups as $aGroup) {
            if ($aGroup['global'] == 1 || in_array($aGroup['idx'], $driverGroups)) {
                foreach ($workingTimes as $shift) {
                    $aGroup['working_times_source'] == 0 ? $dateStart = $shift['date_start'] : $dateStart = $shift['date_start_manual'];
                    $allowances .= "('".$dateStart."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
                }
            }
        }
        if ($allowances != '') {
            $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
        }
        return true;
    }

    private function calculateSpecialAllowances($driver, $startDate, $allowancesGroups)
    {
        // var_dump('SP!');
        $allowances = '';
        $driverGroups =  explode(',', $driver['allowance_groups_idx']);
        foreach ($allowancesGroups as $aGroup) {
            switch (intval($aGroup['calc_type_idx'])) {
                case 2:
                    $this->calcExpenses($driver, $startDate, $aGroup, " AND (amount = 14 or amount = 28);");
                    break;
                case 3:
                    $this->calcExpenses($driver, $startDate, $aGroup, " AND amount != 14 AND amount != 28;");
                    break;
                case 4:
                    $this->calcExpenses($driver, $startDate, $aGroup);
                    break;
                case 5:
                    $this->calcExtraHours($driver, $startDate, $aGroup, "1");
                    break;
                case 6:
                    $this->calcExtraHours($driver, $startDate, $aGroup, "2,3");
                    break;
                case 7:
                    $this->calcHolydays($driver, $startDate, $aGroup);
                    break;
                case 8:
                    $this->calc($driver, $startDate, $aGroup);
                    break;
                case 9:
                    $this->calcOvernight($driver, $startDate, $aGroup);
                    break;
            }
        }
        // var_dump($allowances);
        //$this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
        return true;
    }

    private function calcExpenses($driver, $startDate, $aGroup, $condition)
    {
        $allowances = '';
        $today = new \DateTime();
        $expenses = $this->dbQuery->getExpenses($driver['idx'], $startDate, $today->format('Y-m-d'), $condition);
        if ($expenses) {
            foreach ($expenses as $dailyExpenses) {
                $allowances .= "('".$dailyExpenses['date']."', ".$aGroup['idx'].", ".$dailyExpenses['amount']."), ";
            }
        }
        if ($allowances != '') {
            $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
        }
    }

    private function calcOvernight($driver, $startDate, $aGroup)
    {
        //var_dump('overnight!');
        $allowances = '';
        $today = new \DateTime();
        $overnights = $this->dbQuery->getOvernight($driver['idx'], $startDate, $today->format('Y-m-d'));
        if ($overnights) {
            foreach ($overnights as $overnight) {
                $allowances .= "('".$overnight['date']."', ".$aGroup['idx'].", ".$aGroup['amount']."), ";
            }
        }
        //var_dump($allowances);
        $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
    }

    private function calcExtraHours($driver, $startDate, $aGroup, $condition)
    {
        $allowances = '';
        $today = new \DateTime();
        $extraHours = $this->dbQuery->getExtraHours($driver['idx'], $startDate, $today->format('Y-m-d'), $condition);
        if ($extraHours) {
            foreach ($extraHours as $extraHour) {
                $allowances .= "('".$extraHour['date']."', ".$aGroup['idx'].", ".$extraHour['payment_duration']."), ";
            }
        }
        $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
    }

    private function calcHolydays($driver, $startDate, $aGroup)
    {
        $allowances = '';
        $regions = "4";
        if (!is_null($driver['state_idx'])) {

            $regions .= ",".$driver['state_idx'];
        }
        if (strlen($regions) > 1){
            $regionsList = explode(",", $regions);
        }else{
            $regionsList = array($regions);
        }

        $today = new \DateTime();
        is_null($driver['hours_per_day']) || $driver['hours_per_day'] == '' ? $driverWorkingHours = 8*60 : $driverWorkingHours = $driver['hours_per_day'];
        $holydays = []; 
        $holidaysList = $this->dbQuery->getHolydays($startDate, $today->format('Y-m-d'));

        for ($i = 0; $i < count($holidaysList); $i++) {
            $holidayRegions = explode(",", $holidaysList[$i]['regions_idx']);
        
            foreach ($regionsList as $region) {
                if (in_array($region, $holidayRegions)) {
                    $holydays[] = [
                        'date' => $holidaysList[$i]['date'],
                        'factor' => $holidaysList[$i]['factor'],
                    ];
                }
            }
        }


        if (!empty($holydays)) {
            foreach ($holydays as $holyday) {
                if ($this->checkWeekDayCompliance($aGroup, $holyday['date']) == true) {
                    $duration = $driverWorkingHours * $holyday['factor'];
                    $allowances .= "('".$holyday['date']."', ".$aGroup['idx'].", ".$duration."), ";
                }
            }
            if ($allowances != '') {
                $this->dbQuery->saveAllowances($driver['idx'], rtrim($allowances, ', '));
            }
        }

        return;
    }
    

    private function calc($driver, $startDate, $aGroup)
    {

    }

    private function proceedShift($allowancesGroups, $allowanceDay, $shiftDuration, $shiftDurationManual, $startTime, $startTimeManual, $endTime, $endTimeManual)
    {
        $proceeded = false;
        //var_dump($shiftDuration);
        //var_dump($shiftDurationManual);
        foreach ($allowancesGroups as $aGroup) {
            if(!is_null($aGroup['days_idx']) && $this->checkHolidayCompliance($aGroup, $allowanceDay['date'])) {
                $aGroup['working_times_source'] == 0 ? $allowanceDay[$aGroup['idx']] += $shiftDuration : $allowanceDay[$aGroup['idx']] += $shiftDurationManual;
                $proceeded = true;
            }
        }

        if ($proceeded == false) {
            foreach ($allowancesGroups as $aGroup) {
                //var_dump('WG: '.$aGroup['begin_before_midnight'].'; '.$aGroup['idx']);
                if(!is_null($aGroup['days_idx']) && $this->checkWeekDayCompliance($aGroup, $allowanceDay['date'])) {
                    //var_dump($aGroup['idx']);
                    //var_dump($aGroup['working_times_source']);
                    if ($aGroup['working_times_source'] == 0) {
                        $startDateTime = $startTime;
                        $endDateTime = $endTime;
                    } else {
                        $startDateTime = $startTimeManual;
                        $endDateTime = $endTimeManual;
                    }

                    //var_dump('IN GROUP: '.$aGroup['idx']);
                    $timeStart = strtotime($aGroup['time_start']);
                    $timeEnd = strtotime($aGroup['time_end']);
                    //var_dump('timeStart: '.$timeStart.'; timeEnd: '.$timeEnd.'; startDateTime: '.$startDateTime.'; endDateTime: '.$endDateTime);
                    //$timeEnd = $timeEnd->format('H:i:s');
                    if ($startDateTime >= $timeStart && $startDateTime <= $timeEnd) {
                        // var_dump('IN 1');
                        if ($endDateTime <= $timeEnd) {
                            //var_dump('Fall 1');
                            // Fall 1
                            //$this->text .= "// Fall 1 // shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $timeStart)." groupEndTime: ".date('H:i:s', $timeEnd)." \n";
                            $aGroup['working_times_source'] == 0 ? $allowanceDay[$aGroup['idx']] += $shiftDuration : $allowanceDay[$aGroup['idx']] += $shiftDurationManual;
                        } else {
                            //var_dump('Fall 2');
                            // Fall 2
                            $timeDiff = ($timeEnd - $startDateTime)/60;
                            //$this->text .= "// Fall 2 // diff: ".$timeDiff." shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $timeStart)." groupEndTime: ".date('H:i:s', $timeEnd)." \n";
                            $allowanceDay[$aGroup['idx']] += $timeDiff;
                        }
                    } elseif ($startDateTime <= $timeStart && $endDateTime >= $timeStart) {
                        // var_dump('IN 2');
                        if ($endDateTime >= $timeEnd) {
                            // var_dump('Fall 3');
                            // Fall 3
                            //$this->text .= "// Fall 3 // shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $timeStart)." groupEndTime: ".date('H:i:s', $timeEnd)." \n";
                            $allowanceDay[$aGroup['idx']] += ($timeEnd - $timeStart)/60;

                        } else {
                            //var_dump('Fall 4');
                            // Fall 4
                            $timeDiff = ($endDateTime - $timeStart)/60;
                            //$this->text .= "// Fall 4 // diff: ".$timeDiff." shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $timeStart)." groupEndTime: ".date('H:i:s', $timeEnd)." \n";
                            $allowanceDay[$aGroup['idx']] += $timeDiff;
                        }
                    }

                    if ($aGroup['additional_time_start'] !== null && $aGroup['additional_time_end'] !== null) {
                        $addTimeStart = strtotime($aGroup['additional_time_start']);
                        $addTimeEnd = strtotime($aGroup['additional_time_end']);

                        if ($startDateTime >= $addTimeStart && $startDateTime <= $addTimeEnd) {
                            if ($endDateTime <= $addTimeEnd) {
                                //var_dump('Fall 1');
                                // Fall 1
                                //$this->text .= "// Fall 1 // shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $addTimeStart)." groupEndTime: ".date('H:i:s', $addTimeEnd)." \n";
                                $aGroup['working_times_source'] == 0 ? $allowanceDay[$aGroup['idx']] += $shiftDuration : $allowanceDay[$aGroup['idx']] += $shiftDurationManual;
                            } else {
                                //var_dump('Fall 2');
                                // Fall 2
                                // var_dump($addTimeEnd);
                                // var_dump($startDateTime);
                                $timeDiff = ($addTimeEnd - $startDateTime)/60;
                                //var_dump($timeDiff);
                                //$this->text .= "// Fall 2 // diff: ".$timeDiff." shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $addTimeStart)." groupEndTime: ".date('H:i:s', $addTimeEnd)." \n";
                                $allowanceDay[$aGroup['idx']] += $timeDiff;
                            }
                        } elseif ($startDateTime <= $addTimeStart && $endDateTime >= $addTimeStart) {
                            if ($endDateTime >= $addTimeEnd) {
                                //var_dump('Fall 3');
                                // Fall 3

                                $timeDiff = ($addTimeEnd - $addTimeStart)/60;
                                //$this->text .= "// Fall 3 // shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $addTimeStart)." groupEndTime: ".date('H:i:s', $addTimeEnd)." \n";
                                $allowanceDay[$aGroup['idx']] += $timeDiff;
                            } else {
                                // var_dump('Fall 4');
                                // Fall 4
                                $timeDiff = ($endDateTime - $addTimeStart)/60;
                                //$this->text .= "// Fall 4 // diff: ".$timeDiff." shift_idx: "." group_idx: ".$aGroup['idx']." groupStartTime: ".date('H:i:s', $addTimeStart)." groupEndTime: ".date('H:i:s', $addTimeEnd)." \n";
                                $allowanceDay[$aGroup['idx']] += $timeDiff;

                            }
                        }
                    }
                }
            }
        }
        //var_dump($allowanceDay);
        return $allowanceDay;
    }

    private function checkHolidayCompliance($allowancesGroup, $date)
    {
        $dayDates = $this->dbQuery->getDayDates($allowancesGroup['days_idx'], 6);
        if (!is_null($dayDates)) {
            foreach ($dayDates as $dayDate) {
                if ($dayDate['f_day_date'] == $date) {
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
        if (in_array($date->format('N'), $groupDays)) {
            return true;
        }
        return false;
    }

    private function getEmptyDayObj($date, $paymentGroups)
    {
        $allowanceDay['date'] = $date;
        foreach ($paymentGroups as $paymentGroup) {
            $allowanceDay[$paymentGroup['idx']] = 0;
        }
        return $allowanceDay;
    }

    private function getAmount($current, $min, $max)
    {
        $minAmount = max($min, $current);
        if (is_null($minAmount)) {
            $minAmount = 0;
        }
        is_null($max) ? $amount = $minAmount : $amount = min($minAmount, $max);
        return $amount;
    }
}
