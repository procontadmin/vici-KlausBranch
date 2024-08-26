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
$message = 'END';

$ec = new ExpensesCalculator;
$ec->run();

$timeElapsed = microtime(true) - $start;
echo (YELLOW . $timeElapsed . RESET . "\n" );
echo (YELLOW . $message . RESET . "\n");

class ExpensesCalculator
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
        $options = getopt("t:d:i:c:");

        if (array_key_exists("t", $options) && array_key_exists("d", $options) && array_key_exists("i", $options))
        {
            $startDate  = $options['t'];
            $driverId   = $options['i'];
            $company    = $options['d'];
            isset($options['c']) ? $clientId = $options['c'] : $clientId = null;
            $this->dbQuery = new CoreDbQueries($company);
            $this->driverCtrl = new DriverController($this->dbQuery);
            $countries = $this->getCountries();
            /*for ($i = 1; $i < 420; $i ++)
            {
                echo RED . $i . RESET . "\n";
                $this->dbQuery->changeET($i.'');
            }*/
            
            if (!is_null($clientId))
            {
                $allDrivers = $this->dbQuery->getAllClientDrivers($clientId);

                if(!is_null($allDrivers))
                {
                    foreach ($allDrivers as $driver)
                    {
                        $this->proceedDriver($driver['driver_idx'], $startDate, $countries);
                    }
                }
            }
            else
            {
                $this->proceedDriver($driverId, $startDate, $countries);
            }
        }
        else
        {
            echo RED . "Parameter ist nicht gesetzt!" . RESET . "\n";
        }

        return;
    }

    /**
     * Die Funktion überprüft den Fahrer, die Fahrerkarten und die Aktivitäten des Fahrers
     * und startet die Berechnung der Arbeitszeiten ab der letzten Schicht des Fahrers,
     * oder ab dem gegebenen Datum, wenn der Fahrer keinen Schichten hat
     *
     * @param int       driverId      Fahrer-Id
     * @param string    startDate     Startdatum
     * 
     * @author Oleg Manov
     */
    private function proceedDriver($driverId, $startDate, $countries)
    {
        $driver = $this->dbQuery->getDriverInfoExp($driverId);

        if (!is_null($driver))
        {
            echo (GREEN . 'Fahrer ID: ' . $driver['idx'] . RESET . "\n");
            if (intval($driver['shift_based']) == 1)
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
                    $this->calculateShiftExpenses($driver, $workingTimes, $startDate, $countries);
                    echo (YELLOW . "Done!" . RESET . "\n");
                }
            }
            elseif (!is_null($driver['home_zip']))
            {
                /*
                $lastShift = $this->dbQuery->getLastShift($driver['idx'], $startDate);
                if (!is_null($lastShift['dateStart']))
                {
                    $startDate = $lastShift['dateStart'];
                    echo GREEN . "Letzte Schicht: " . $startDate . RESET . "\n";
                }
                */
                
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
                    $expenses = $this->calculateExpenses($driver, $workingTimes);
                    if (!empty($expenses))
                    {
                        $this->proceedExpenses($driver, $startDate, $workingTimes, $expenses, $countries);
                        echo (YELLOW . "Done!" . RESET . "\n");
                    }
                }
                else
                {
                    echo (RED .'Fahrer '.$driver['idx'].' hat keine Arbeitszeiten!' . RESET . "\n");
                }
            }
            else
            {
                echo (RED .'Fahrer '.$driver['idx'].' hat keinen Heimatort!' . RESET . "\n");
            }
        }
        else
        {
            echo RED . "Fahrer nicht gefunden! ID:" . $driverId . RESET . "\n";
        }

        return;
    }

    /**
     * Berechnet und speichert die Spesen ab dem gegebenen Datum.
     * Alte Spesendaten werden gelöscht.
     *
     * @param array     $driver  Fahrerdaten
     * @param string    $startDate Startdatum
     * 
     * @author Oleg Manov
     */ 
    private function calculateExpenses($driver, $workingTimes)
    {
        $driverExpenses = array();

        $homeZip = explode(';', trim($driver['home_zip']));
        //echo("\n".'////////////////// START - '.$driver['last_name'].', '.$driver['first_name'].' //////////////////');
        $lastShift = null;
        $expensesRatesId = $driver['values_table'];
        foreach ($workingTimes as $shift)
        {
            //var_dump($shift['date_start']);
            //var_dump($driverExpenses);
            //var_dump("==========");
            $amount = 0;
            if (!is_null($shift['place_start']) && !is_null($shift['country_start']) && !is_null($shift['place_end']) && !is_null($shift['country_end']))
            {
                $expensesDate = $shift['date_start'];
                //echo("\n".$expensesDate);
                //echo("\n+++++++++++++++++++++++++++");
                if ($shift['date_start'] != $shift['date_end']) $expensesDate = $this->getExpensesDate($shift);
                //echo("\n".'EXP DATE: '.$expensesDate);
                //echo("\n".$shift['place_start']);
                //echo("\n".$shift['place_end']);
                //echo("\n".$shift['duration_all']);
                //echo("\n".$shift['time_start']);
                if (in_array($shift['place_start'], $homeZip) && in_array($shift['place_end'], $homeZip) && $shift['duration_all'] >= $driver['min_hours'])
                {
                    //echo("\n".'in1');
                    //Fahrer hat seine Schicht von zu Hause gestartet und da beendet
                    //echo("\n".'Fahrer hat seine Schicht von zu Hause gestartet und da beendet');
                    if ($driver['abroad_expenses'] == 1 && !is_null($shift['country_border_crossing']) && $shift['country_border_crossing'] != 4)
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_border_crossing'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_short'];
                    }
                    else
                    {
                        $amount = $driver['expenses_near'];
                    }
                }
                elseif (in_array($shift['place_start'], $homeZip) && !in_array($shift['place_end'], $homeZip) && $shift['time_start'] != '00:00')
                {
                    //echo("\n".'in2');
                    //var_dump($lastShift);
                    //Fahrer ist von zu Hause aus gestartet und ist nicht zurückgekommen
                    //echo("\n".'Fahrer ist von zu Hause aus gestartet und ist nicht zurückgekommen');

                    //Spesen für den ersten Tag
                    if ($expensesDate != $shift['date_start'] && $shift['time_start'] < $driver['first_day_start']) 
                    {
                        $driverExpenses[$shift['date_start']] = $driver['expenses_near'];
                    }

                    if ($shift['time_start'] < $driver['first_day_start'])
                    {
                        if ($driver['abroad_expenses'] == 1 && $shift['country_end'] != 4)
                        {
                            $expenses = $this->dbQuery->getExpensesForCountry($shift['country_end'], $shift['year'], $expensesRatesId);
                            $amount = $expenses['expenses_short'];
                        }
                        elseif ($driver['abroad_expenses'] == 1 && !is_null($shift['date_border_crossing']) && $shift['country_border_crossing'] != 4 && $shift['date_border_crossing'] == $expensesDate)
                        {
                            $expenses = $this->dbQuery->getExpensesForCountry($shift['country_border_crossing'], $shift['year'], $expensesRatesId);
                            $amount = $expenses['expenses_short'];
                        }
                        else
                        {
                            $amount = $driver['expenses_near'];
                        }
                    }
                   // if ($expensesDate == '2020-12-06') var_dump($amount);
                    if (!in_array($lastShift['place_end'], $homeZip) && $lastShift['date_start'] !== $lastShift['date_end'])
                    {
                        //echo("\n"."in5");
                        if ($driver['abroad_expenses'] == 1 && $lastShift['country_end'] != 4)
                        {
                            $expenses = $this->dbQuery->getExpensesForCountry(($lastShift['country_end'] ?? $shift['country_start']), $lastShift['year'], $expensesRatesId);
                            $amountLS = $expenses['expenses_full'];
                        }
                        elseif ($driver['abroad_expenses'] == 1 && !is_null($lastShift['date_border_crossing']) && $lastShift['country_border_crossing'] != 4)
                        {
                            $expenses = $this->dbQuery->getExpensesForCountry($lastShift['country_border_crossing'], $lastShift['year'], $expensesRatesId);
                            $amountLS = $expenses['expenses_full'];
                        }
                        else
                        {
                            $amountLS = $driver['expenses_far'];
                        }
                      //echo("\n"."ALS: ".$amountLS);
                        $driverExpenses[$lastShift['date_end']] = $amountLS;
                    }
                }
                elseif ((!in_array($shift['place_start'], $homeZip) || $shift['time_start'] == '00:00') && !in_array($shift['place_end'], $homeZip))
                {
                    //echo("\n".'in3');
                    //Fahrer war den ganzen Tag unterwegs
                    //     var_dump('unterwegs');
                    //echo("\n".'Fahrer war den ganzen Tag unterwegs');
                    if (!is_null($lastShift) && !isset($driverExpenses[$lastShift['date_start']]))
                    {
                        $lsDateEnd = new \DateTime($lastShift['date_end']);
                        $sDateStart = new \DateTime($shift['date_start']);
                        if ($lastShift['date_end'] == $shift['date_start'] || $lsDateEnd->modify('+ 1 day')->format('Y-m-d') == $sDateStart->format('Y-m-d') && $lastShift['time_start'] < $driver['first_day_start'])
                        {
                            if ($lastShift['date_border_crossing'] == $lastShift['date_start'])
                            {
                                $expenses= $this->dbQuery->getExpensesForCountry($shift['country_start'], $shift['year'], $expensesRatesId);
                                $driverExpenses[$lastShift['date_start']] = $expenses['expenses_short'];
                            }
                            else
                                $driverExpenses[$lastShift['date_start']] =  $driver['expenses_near'];
                        }
                    }
                    if($driver['return_mode'] == 1)
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_end'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_full'];
                    }
                    elseif ($driver['abroad_expenses'] == 1 && $shift['country_end'] != 4 && $expensesDate == $shift['date_end'])
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_end'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_full'];
                    }
                    elseif ($driver['abroad_expenses'] == 1 && !is_null($shift['country_border_crossing']) && $shift['country_border_crossing'] != 4 && 
                            ($shift['date_border_crossing'] == $expensesDate || ($expensesDate == $shift['date_start'] && $shift['date_border_crossing'] == $shift['date_end'])))
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_border_crossing'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_full'];
                    }
                    elseif ($driver['abroad_expenses'] == 1 && $shift['country_start'] != 4 && $expensesDate == $shift['date_start'])
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_start'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_full'];
                    }
                    else
                    {
                        $amount = $driver['expenses_far'];
                    }
                }
                elseif (!in_array($shift['place_start'], $homeZip) && in_array($shift['place_end'], $homeZip) && $shift['time_end'] > $driver['last_day_end'])
                {
                    //echo("\n".'in4');
                    //var_dump($shift['payment_start']);
                    //Fahrer ist zurück nach Hause gekommen

                    //echo("\n".'Fahrer ist zurück nach Hause gekommen');
                    if ($driver['abroad_expenses'] == 1 && !is_null($shift['date_border_crossing']) && $shift['country_border_crossing'] != 4 && 
                        ($shift['date_border_crossing'] == $expensesDate || ($expensesDate == $shift['date_start'] && $shift['date_border_crossing'] == $shift['date_end'])))
                    {
                        //echo("\n".'1');
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_border_crossing'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_short'];
                    }
                    elseif ($driver['abroad_expenses'] == 1 && ((is_null($shift['date_border_crossing']) && $shift['country_start'] != 4) || (!is_null($shift['date_border_crossing']) && $shift['date_border_crossing'] == $shift['date_end'] && $shift['country_border_crossing'] == 4)))
                    {
                        //echo("\n".'2');
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_start'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_short'];
                    }
                    else
                    {
                        //echo("\n".'3');
                        $amount = $driver['expenses_near'];
                    }

                    if ($lastShift != null && $shift['time_end'] > $driver['last_day_end'] && $expensesDate == $shift['date_start'] && $shift['date_start'] != $shift['date_end'] && !isset($driverExpenses[$shift['date_end']]))
                    {
                        if ($shift['date_border_crossing'] == $shift['date_end'])
                        {
                            $expenses= $this->dbQuery->getExpensesForCountry($shift['country_start'], $shift['year'], $expensesRatesId);
                            $driverExpenses[$shift['date_end']] = $expenses['expenses_short'];
                        }
                        else
                            $driverExpenses[$shift['date_end']] =  $driver['expenses_near'];
                    }
                    //var_dump($amount);
                }
                if (!in_array($shift['place_start'], $homeZip) && in_array($shift['place_end'], $homeZip) && $shift['date_start'] != $shift['date_end'] && $expensesDate == $shift['date_start'])
                {
                    if (!is_null($shift['date_border_crossing']) && $shift['country_border_crossing'] != 4 && ($shift['date_border_crossing'] == $expensesDate || $shift['date_border_crossing'] == $shift['date_end']))
                    {
                        //echo("\n".'1');
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_border_crossing'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_full'];
                    }
                    elseif ((is_null($shift['date_border_crossing']) && $shift['country_start'] != 4) || (!is_null($shift['date_border_crossing']) && $shift['date_border_crossing'] == $shift['date_end'] && $shift['country_border_crossing'] == 4))
                    {
                        //echo("\n".'2');
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_start'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_full'];
                    }
                    else
                    {
                        //echo("\n".'3');
                        $amount = $driver['expenses_far'];
                    }
                }
                //echo("\n".'SPESEN FÜR '.$expensesDate.' = '.$amount.' €');
                /*  var_dump($shift['payment_start']);
                var_dump($shift['payment_end']); 
                var_dump('SPESEN FÜR '.$expensesDate.' = '.$amount.' €');*/
                
                if (isset($driverExpenses[$expensesDate]))
                {
                    //echo("\n".'KONFLIKT!!!!!!!');
                    $driverExpenses[$expensesDate] = $this->resolveConflict($expensesDate, $lastShift, $shift, $driver, $expensesRatesId);
                    //echo("\n".$driverExpenses[$expensesDate]);
                   // echo("\n".$expensesDate);
                }
                else
                {
                    $driverExpenses[$expensesDate] = $amount;
                }
              //  print "\n".$expensesDate." --> ".$driverExpenses[$expensesDate];
                if (!in_array($shift['place_start'], $homeZip))
                {
                    //echo("\n".'WAIT TIME!!!!!!!');
                    $driverExpenses = $this->checkForWaitTime($shift, $lastShift, $driverExpenses, $driver, $expensesRatesId);
                   // echo("\n".$expensesDate);
                }
                // var_dump('///////////////////////////////////////////////////////');
            }
            $lastShift = $shift;
        }
        ksort($driverExpenses);

        return $driverExpenses;
    }

    /**
     * Berechnet und speichert die Spesen ab dem gegebenen Datum.
     * Alte Spesendaten werden gelöscht.
     *
     * @param array     $driver  Fahrerdaten
     * @param string    $startDate Startdatum
     * 
     * @author Oleg Manov
     */
    private function proceedExpenses($driver, $startDate, $workingTimes, $driverExpenses, $countries)
    {
        $workingTimesByDays = array();
        //var_dump($driverExpenses);
        foreach ($workingTimes as $shift)
        {
            if (!array_key_exists($shift['date_start'], $workingTimesByDays) || !isset($workingTimesByDays[$shift['date_start']]['shifts']) ||
                is_null($workingTimesByDays[$shift['date_start']]['shifts']))
            {
                $workingTimesByDays[$shift['date_start']]['shifts'] = array();
                $workingTimesByDays[$shift['date_start']]['tour'] = array();
                $workingTimesByDays[$shift['date_start']]['country'] = '4';
                $workingTimesByDays[$shift['date_start']]['amount'] = 0;
            }
            //Baue das richtige String für den Tour
            //var_dump($shift);
            if ($shift['place_start'] != '')
            {
                $startPlace = ($shift['country_start'] != 4) ? $shift['place_start'].' '.$countries[$shift['country_start']]['region_short'] : $shift['place_start'];
            }
            else
            {
                $startPlace = null;
            }
            if ($shift['stopover'] != '')
            {
                $stopover = $shift['stopover'];
            }
            else
            {
                $stopover = null;
            }
            if ($shift['place_border_crossing'] != '')
            {
                $bcPlace = ($shift['country_border_crossing'] != 4) ? $shift['place_border_crossing'].' '.$countries[$shift['country_border_crossing']]['region_short'] : $shift['place_border_crossing'];
            }
            else
            {
                $bcPlace = null;
            }
            if ($shift['place_end'] != '')
            {
                $endPlace = (!is_null($shift['country_end']) && $shift['country_end'] != 4) ? $shift['place_end'].' '.$countries[$shift['country_end']]['region_short'] : $shift['place_end'];
            }
            else
            {
                $endPlace = null;
            }
            // Baue den Tag
            if ($shift['date_start'] == $shift['date_end'])
            {
                array_push($workingTimesByDays[$shift['date_start']]['shifts'], $shift['time_start'].' - '.$shift['time_end']);
                if (!is_null($startPlace) && end($workingTimesByDays[$shift['date_start']]['tour']) != $startPlace) array_push($workingTimesByDays[$shift['date_start']]['tour'], $startPlace);
                if (!is_null($stopover)) array_push($workingTimesByDays[$shift['date_start']]['tour'], $stopover);
                if (!is_null($bcPlace)) array_push($workingTimesByDays[$shift['date_start']]['tour'], $bcPlace);
                if (!is_null($endPlace)) array_push($workingTimesByDays[$shift['date_start']]['tour'], $endPlace);
                if (isset($driverExpenses[$shift['date_start']])) $workingTimesByDays[$shift['date_start']]['amount'] = $driverExpenses[$shift['date_start']];
            }
            else
            {
                array_push($workingTimesByDays[$shift['date_start']]['shifts'], $shift['time_start'].' - 24:00');
                if (!is_null($startPlace) && end($workingTimesByDays[$shift['date_start']]['tour']) != $startPlace) array_push($workingTimesByDays[$shift['date_start']]['tour'], $startPlace);
                if (!is_null($stopover)) array_push($workingTimesByDays[$shift['date_start']]['tour'], $stopover);
                if (!is_null($bcPlace) && $shift['date_border_crossing'] == $shift['date_start']) array_push($workingTimesByDays[$shift['date_start']]['tour'], $bcPlace);
                if (!is_null($endPlace)) array_push($workingTimesByDays[$shift['date_start']]['tour'], '('.$endPlace.')');
                if (isset($driverExpenses[$shift['date_start']])) $workingTimesByDays[$shift['date_start']]['amount'] = $driverExpenses[$shift['date_start']];
                $workingTimesByDays[$shift['date_end']]['shifts'] = array();
                $workingTimesByDays[$shift['date_end']]['tour'] = array();
                $workingTimesByDays[$shift['date_end']]['country'] = '4';
                $workingTimesByDays[$shift['date_end']]['amount'] = 0;
                array_push($workingTimesByDays[$shift['date_end']]['shifts'], '00:00 - '.$shift['time_end']);
                if (!is_null($startPlace)) array_push($workingTimesByDays[$shift['date_end']]['tour'], '('.$startPlace.')');
                if (!is_null($bcPlace) && $shift['date_border_crossing'] == $shift['date_end']) array_push($workingTimesByDays[$shift['date_start']]['tour'], $bcPlace);
                if (!is_null($endPlace)) array_push($workingTimesByDays[$shift['date_end']]['tour'], $endPlace);
                if (isset($driverExpenses[$shift['date_end']])) $workingTimesByDays[$shift['date_end']]['amount'] = $driverExpenses[$shift['date_end']];
            }
            if ($shift['country_start'] != 4) $workingTimesByDays[$shift['date_start']]['country'] = $shift['country_start'];
            if (!is_null($shift['country_border_crossing']) && $shift['country_border_crossing'] != 4) $workingTimesByDays[$shift['date_border_crossing']]['country'] = $shift['country_border_crossing'];
            if ($shift['country_end'] != 4) $workingTimesByDays[$shift['date_end']]['country'] = $shift['country_end'];
        }
       
        $lastKey = null;
        foreach ($driverExpenses as $key => $value)
        {
            if (!array_key_exists($key, $workingTimesByDays))
            {
                $workingTimesByDays[$key]['shifts'] = array('00:00 - 24:00');
                $workingTimesByDays[$key]['tour'] = array('Liegetag');
                !is_null($lastKey) ? $workingTimesByDays[$key]['country'] = $workingTimesByDays[$lastKey]['country'] : $workingTimesByDays[$key]['country'] = null;
                if ($driver['return_mode'] == 1 && intval($value) == 28) $workingTimesByDays[$key]['country'] = 4;
                $workingTimesByDays[$key]['amount'] = $value;
            }
            $lastKey = $key;
        }
        ksort($workingTimesByDays);
        //var_dump($workingTimesByDays);
        $calcExpenses = '';
        $lastWT = null;
        $lastKey = null;
        $homeZip = explode(';', trim($driver['home_zip']));
        foreach ($workingTimesByDays as $key => $value)
        {
            //var_dump($key);
            //var_dump($lastWT);
            //var_dump($value);
            //var_dump($homeZip);
            //var_dump('////////////////////////');
            
            if (!is_null($value['tour']) && !(!is_null($lastWT) && end($value['tour']) == 'Liegetag' && in_array(end($lastWT['tour']), $homeZip)))
            {  
                $tour = implode(',', $value['tour']);

                $shifts = implode(',', $value['shifts']);
                $diffArray = array_diff($value['tour'], $homeZip);
                if (count($diffArray) != count($value['tour']))
                {
                    if (!in_array(array_shift($value['tour']), $homeZip))
                    {
                        $shifts = '00:00'.substr($shifts, 5);
                    }
                    if (!in_array(end($value['tour']), $homeZip))
                    {
                        $shifts = substr($shifts, 0, -5).'24:00';
                    }
                }
                else
                {
                    if (!is_null($lastKey))
                    {
                        $eKey = new \DateTime($key);
                        $sKey = new \DateTime($lastKey);
                        $period = new \DatePeriod(
                            $sKey,
                            new \DateInterval('P1D'),
                            $eKey->modify('+ 1 day')
                        );
                        if (iterator_count($period) > 3)
                        {
                            $calcExpenses = $this->str_lreplace('24:00', substr(end($lastWT['shifts']), -5), $calcExpenses);
                            $shifts = substr($shifts, 0, 5).' - 24:00';
                        }
                        else
                        {
                            if ($driver['return_mode'] == 1 && $value['amount'] == 28) $value['country'] = 4;
                            $shifts = '00:00 - 24:00';
                        }
                    }
                    else
                    {
                        if ($driver['return_mode'] == 1 && $value['amount'] == 28) $value['country'] = 4;
                        $shifts = '00:00 - 24:00';
                    }
                }
                //var_dump($value);
                if (is_null($value['country']) && !is_null($lastWT)) $value['country'] = $lastWT['country'];
                $calcExpenses .= '("'.$key.'",'.$value['amount'].',"'.$shifts.'","'.$tour.'",'.($value['country'] ?? "null")."), ";
                
                $lastWT = $value;
                $lastKey = $key;
            }
            else
            {
                //var_dump($value);
            }
        }
        //var_dump(rtrim($calcExpenses, ', '));
        $this->dbQuery->deleteExpenses($driver['idx'], $startDate);
        //print($calcExpenses);
        if ($calcExpenses !== '')
        {
            $this->dbQuery->saveExpenses($driver['idx'], rtrim($calcExpenses, ', '));
        }

        return;
    }

     /**
     * Berechnet und speichert die Spesen pro Schicht ab dem gegebenen Datum.
     * Alte Spesendaten werden gelöscht.
     *
     * @param array     $driver  Fahrerdaten
     * @param string    $startDate Startdatum
     * 
     * @author Oleg Manov
     */ 
    private function calculateShiftExpenses($driver, $workingTimes, $startDate, $countries)
    {
        $calcExpenses = '';
        $expensesRatesId = $driver['values_table'];
        foreach ($workingTimes as $shift)
        {
            $amount = 0;
            $expensesDate = $shift['date_start'];
            if ($shift['duration_all'] >= $driver['min_hours'])
            {
                $country = 4;
                if ($driver['abroad_expenses'] == 0)
                {
                    $expenses = $this->dbQuery->getExpensesForCountry(4, $shift['year'], $expensesRatesId);
                    $amount = $expenses['expenses_short'];
                }
                else
                {
                    if ($shift['country_end'] != 4)
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_end'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_short'];
                        $country = $shift['country_end'];
                    }
                    elseif (!is_null($shift['country_border_crossing']) && $shift['country_border_crossing'] != 4)
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_border_crossing'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_short'];
                        $country = $shift['country_border_crossing'];
                    }
                    elseif ($shift['country_start'] != 4)
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($shift['country_start'], $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_short'];
                        $country = $shift['country_start'];
                    }
                    else
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry(4, $shift['year'], $expensesRatesId);
                        $amount = $expenses['expenses_short'];
                    }
                }
            }
            $tour = '';
            if ($shift['place_start'] != '')
            {
                $tour .= ($shift['country_start'] != 4) ? $shift['place_start'].' '.$countries[$shift['country_start']]['region_short'] : $shift['place_start'];
            }
            
            if ($shift['place_border_crossing'] != '')
            {
                $tour .= ',';
                $tour .= ($shift['country_border_crossing'] != 4) ? $shift['place_border_crossing'].' '.$countries[$shift['country_border_crossing']]['region_short'] : $shift['place_border_crossing'];
            }
            $tour .= ',';
            if ($shift['place_end'] != '')
            {
                $tour .= (!is_null($shift['country_end']) && $shift['country_end'] != 4) ? $shift['place_end'].' '.$countries[$shift['country_end']]['region_short'] : $shift['place_end'];
            }
            if ($shift['date_start'] == $shift['date_end'])
            {
                $shifts = $shift['time_start'] . ' - ' .$shift['time_end'];
            }
            else
            {
                $shifts = $shift['time_start'] . ' - 24:00,';
                $shifts .= '00:00 - ' .$shift['time_end'];
            }
            //var_dump($shift);
            $calcExpenses .= '("'.$shift['date_start'].'",'.$amount.',"'.$shifts.'","'.$tour.'",'.$country."), ";
        }
        //var_dump($driverExpenses);
        $this->dbQuery->deleteExpenses($driver['idx'], $startDate);
        if ($calcExpenses !== '')
        {
            $this->dbQuery->saveExpenses($driver['idx'], rtrim($calcExpenses, ', '));
        }
        return true;
    }

    function str_lreplace($search, $replace, $subject)
    {
        $pos = strrpos($subject, $search);

        if($pos !== false)
        {
            $subject = substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }

    private function getExpensesDate($shift)
    {
        $startToMidnight =  strtotime('24:00') - strtotime($shift['time_start']);
        $midnightToEnd = strtotime($shift['time_end']) - strtotime('00:00');
        if ($startToMidnight >= $midnightToEnd)
        {
            return $shift['date_start'];
        }

        return $shift['date_end'];
    }

    private function resolveConflict($expensesDate, $lastShift, $shift, $driver, $expensesRatesId)
    {
        //War der Fahrer im Ausland?
        $country = null;
        $amount = 0;
        if ($lastShift['date_start'] == $expensesDate && $lastShift['country_start'] != 4) $country = $lastShift['country_start'];
        if ($lastShift['date_border_crossing'] == $expensesDate && $lastShift['country_border_crossing'] != 4) $country = $lastShift['country_border_crossing'];
        if ($lastShift['date_end'] == $expensesDate && $lastShift['country_end'] != 4) $country = $lastShift['country_end'];
        if ($shift['date_start'] == $expensesDate && $shift['country_start'] != 4) $country = $shift['country_start'];
        if ($shift['date_border_crossing'] == $expensesDate && $shift['country_border_crossing'] != 4) $country = $shift['country_border_crossing'];
        if ($shift['date_end'] == $expensesDate && $shift['country_end'] != 4) $country = $shift['country_end'];

        $homeZip = explode(';', trim($driver['home_zip']));
        if (($lastShift['date_start'] == $expensesDate && in_array($lastShift['place_start'], $homeZip)) ||
            ($lastShift['date_end'] == $expensesDate && in_array($lastShift['place_end'], $homeZip)) ||
            ($shift['date_start'] == $expensesDate && in_array($shift['place_start'], $homeZip)) ||
            ($shift['date_end'] == $expensesDate && in_array($shift['place_end'], $homeZip)))
        {
            //Fahrer war zu Hause
            if (is_null($country) || $driver['abroad_expenses'] == 0)
            {
                //Fahrer war nur in DE
                $amount = $driver['expenses_near'];
            }
            else
            {
                //Fahrer war im Ausland
                $expenses = $this->dbQuery->getExpensesForCountry($country, $shift['year'], $expensesRatesId);
                $amount = $expenses['expenses_short'];
            }
        }
        else
        {
            //Fahrer war nicht zu Hause
            if (is_null($country) || $driver['abroad_expenses'] == 0)
            {
                //Fahrer war nur in DE
                $amount = $driver['expenses_far'];
            }
            else
            {
                //Fahrer war im Ausland
                $expenses = $this->dbQuery->getExpensesForCountry($country, $shift['year'], $expensesRatesId);
                $amount = $expenses['expenses_full'];
            }
        }

       // var_dump($expensesDate);
       // var_dump(' SPESEN = '.$amount);
        return $amount;
    }

    private function checkForWaitTime($shift, $lastShift, $driverExpenses, $driver, $expensesRatesId)
    {
        if (!is_null($lastShift['date_end']))
        {
            //echo("\n".$lastShift['date_end']);
            //echo("\n".$shift['date_start']);
            $halfDay = false;
            $homeZip = explode(';', trim($driver['home_zip']));
            $startDate = new \DateTime($lastShift['date_end']);
            $endDate = new \DateTime($shift['date_start']);
            $period = new \DatePeriod(
                $startDate,
                new \DateInterval('P1D'),
                $endDate->modify('+ 1 day')
            );
            //var_dump(iterator_count($period));
            $cut = false;
            if (iterator_count($period) > 2 && $driver['lying_days_mode'] == 1)
            {
                $ldMode = $this->dbQuery->getLDMode($driver['dtco_driver_idx'], $startDate->format('Y-m-d'), $endDate->format('Y-m-d'));
                if (is_null($ldMode))
                    $cut = true;
            }
            if (iterator_count($period) > ($driver['max_lying_days']+2) || $cut == true)
            {
                $periodArray = iterator_to_array($period);
                $periodTemp[0] = reset($periodArray);
                $periodTemp[1] = end($periodArray);
                $period = $periodTemp;
                $halfDay = true;
            }
            foreach ($period as $key => $value)
            {
                //var_dump($driverExpenses[$value->format('Y-m-d')]);
                //var_dump($value);
                if (!isset($driverExpenses[$value->format('Y-m-d')]))
                {
                    //var_dump($lastShift);
                    //var_dump($shift);
                   // echo("\n".'Liegetag: '.$value->format('Y-m-d'));
                    $country = null;
                    $expensesDate = $value->format('Y-m-d');
                    if ($lastShift['country_border_crossing'] != 4) $country = $lastShift['country_border_crossing'];
                    if ($lastShift['country_end'] != 4) $country = $lastShift['country_end'];
                    if ($shift['date_start'] >= $expensesDate && $shift['country_start'] != 4) $country = $shift['country_start'];
                    if ($shift['date_border_crossing'] == $expensesDate && $shift['country_border_crossing'] != 4) $country = $shift['country_border_crossing'];
                    //echo("\n".$country);
                    if ($driver['abroad_expenses'] == 1 && !is_null($country))
                    {
                        $expenses = $this->dbQuery->getExpensesForCountry($country, $shift['year'], $expensesRatesId);
                        $halfDay == false ? $driverExpenses[$expensesDate] = $expenses['expenses_full'] : $driverExpenses[$expensesDate] = $expenses['expenses_short'];
                    }
                    else
                    {
                        $halfDay == false ? $driverExpenses[$expensesDate] = $driver['expenses_far'] : $driverExpenses[$expensesDate] = $driver['expenses_near'];
                    }
                    //var_dump($driverExpenses[$expensesDate]);
                }
                else
                {
                    //var_dump($lastShift);
                    //var_dump($shift);
                    if ($lastShift['date_start'] != $lastShift['date_end'] && !in_array($lastShift['place_end'], $homeZip) && ($lastShift['date_end'] != $shift['date_start'] || iterator_count($period) == 1))
                    {
                        //echo("\n"."in");
                        $country = null;
                        $expensesDate = $lastShift['date_end'];

                        if ($lastShift['country_border_crossing'] != 4) $country = $lastShift['country_border_crossing'];
                        if ($lastShift['country_end'] != 4) $country = $lastShift['country_end'];
                        if ($shift['date_start'] >= $expensesDate && $shift['country_start'] != 4) $country = $shift['country_start'];
                        if ($shift['date_border_crossing'] == $expensesDate && $shift['country_border_crossing'] != 4) $country = $shift['country_border_crossing'];
                        //echo("\n".$country);
                        if ($driver['abroad_expenses'] == 1 && !is_null($country))
                        {
                            $expenses = $this->dbQuery->getExpensesForCountry($country, $shift['year'], $expensesRatesId);
                            //var_dump( $expenses);
                            //var_dump( $expensesDate);
                            (($shift['date_end'] != $expensesDate || !in_array($shift['place_end'], $homeZip)) && $halfDay == false) ? $driverExpenses[$expensesDate] = $expenses['expenses_full']: $driverExpenses[$expensesDate] = $expenses['expenses_short'];
                        }
                        else
                        {
                            (($shift['date_end'] != $expensesDate || !in_array($shift['place_end'], $homeZip)) && $halfDay == false) ? $driverExpenses[$expensesDate] = $driver['expenses_far']: $driverExpenses[$expensesDate] = $driver['expenses_near'];
                            //echo("\n".$driverExpenses[$expensesDate]);
                        }

                        if ($lastShift['date_end'] != $shift['date_start'])
                        {
                            $country = null;
                            $expensesDate = $shift['date_start'];

                            if ($lastShift['country_border_crossing'] != 4) $country = $lastShift['country_border_crossing'];
                            if ($lastShift['country_end'] != 4) $country = $lastShift['country_end'];
                            if ($shift['date_start'] >= $expensesDate && $shift['country_start'] != 4) $country = $shift['country_start'];
                            if ($shift['date_border_crossing'] == $expensesDate && $shift['country_border_crossing'] != 4) $country = $shift['country_border_crossing'];
                            //var_dump($country);
                            if ($driver['abroad_expenses'] == 1 && !is_null($country))
                            {
                                $expenses = $this->dbQuery->getExpensesForCountry($country, $shift['year'], $expensesRatesId);
                                //var_dump( $expenses);
                                //var_dump( $expensesDate);
                                if (($shift['date_end'] != $expensesDate || !in_array($shift['place_end'], $homeZip)) && $halfDay == false)
                                {
                                    $driverExpenses[$expensesDate] = $expenses['expenses_full'];
                                }
                                else
                                {
                                    $driverExpenses[$expensesDate] = $expenses['expenses_short'];
                                }
                            }
                            else
                            {
                                //echo("\n".$expensesDate);
                                if (($shift['date_end'] != $expensesDate || !in_array($shift['place_end'], $homeZip)) && $halfDay == false)
                                {
                                    $driverExpenses[$expensesDate] = $driver['expenses_far'];
                                }
                                elseif($shift['time_end'] > $driver['last_day_end'])
                                {
                                    $driverExpenses[$expensesDate] = $driver['expenses_near'];
                                }
                                //echo("\n".$driverExpenses[$expensesDate]);
                            }
                        }
                    }
                    elseif ($halfDay == true)
                    {
                        $country = null;
                        $expensesDate = $value->format('Y-m-d');
                        if ($lastShift['country_start'] != 4) $country = $lastShift['country_start'];
                        if ($lastShift['country_border_crossing'] != 4) $country = $lastShift['country_border_crossing'];
                        if ($lastShift['country_end'] != 4) $country = $lastShift['country_end'];
                        if ($shift['date_start'] == $expensesDate)
                        {
                            if ($shift['country_start'] != 4) $country = $shift['country_start'];
                            if ($shift['date_border_crossing'] == $expensesDate)
                                if ($shift['country_border_crossing'] != 4) $country = $shift['country_border_crossing'];
                            if ($shift['date_end'] == $expensesDate){
                                if ($shift['country_end'] != 4) $country = $shift['country_end'];}
                        }
                        //var_dump($country);
                        if ($driver['abroad_expenses'] == 1 && !is_null($country))
                        {
                            $expenses = $this->dbQuery->getExpensesForCountry($country, $shift['year'], $expensesRatesId);
                            //var_dump( $expenses);
                            //var_dump( $expensesDate);
                            $driverExpenses[$expensesDate] = $expenses['expenses_short'];
                        }
                        else
                        {
                            //echo("\n".$expensesDate);
                            $driverExpenses[$expensesDate] = $driver['expenses_near'];
                        }
                    }
                }
            }
        }
        return $driverExpenses;
    }

    private function getCountries()
    {
        $countries = $this->dbQuery->getCountries();
        $countriesByKey = array();
        foreach ($countries as $country)
        {
            $countriesByKey[$country['idx']] = array('region' => $country['region'], 'region_short' => $country['region_short']);
        }

        return $countriesByKey;
    }

    private function calculateNightHours($driver, $workingTimes)
    {
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
                        $wageTimeValues .= "('".$lastShift['date_end']."',76,".$duration."), ";
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
        $this->dbQuery->saveToDocket($driver['idx'], rtrim($wageTimeValues, ", "));

        return;
    }
}