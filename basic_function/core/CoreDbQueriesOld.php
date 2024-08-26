<?php

include_once 'Db.php';

class CoreDbQueries
{
    /**
     * The database connection
     *
     * @var mixed
     */
    protected $db = null;

    public function __construct($company)
    {
        $this->db = Db::getInstance($company);
    }

    public function getDriverInfo($driverId)
    {
        $sql = "SELECT d.idx, d.dtco_driver_idx, dc.client_idx, dc.driver_types_idx, dc.contract_start, dc.expenses_near, dc.expenses_far, d.number,
                        cc.parent_idx, dc.allowance_groups_idx, dc.home_zip, dc.auto_expenses, d.last_name, d.first_name, dc.hours_per_day, dc.state_idx,
		 	DATE_FORMAT(dc.contract_start, '%Y-%m-%d') as 'contract_start'
                FROM difa_resources.driver_contract dc
                LEFT JOIN difa_resources.driver d
                    ON d.idx = dc.driver_idx
                LEFT JOIN difa_resources.client_company cc
                    ON dc.client_idx = cc.idx
                WHERE dc.driver_idx = $driverId
                    AND dc.enabled = 1 AND dc.deleted = 0
                    AND d.enabled = 1 AND d.deleted = 0
        ORDER BY dc.contract_start DESC";

        return $this->querySelect($sql, true);
    }

    public function getDriverInfoAllowances($driverId)
    {
        $sql = "SELECT d.idx, d.dtco_driver_idx, cc.parent_idx, dc.client_idx, dc.contract_start, dch.driver_types_idx,
                        dch.idx as 'history_idx', dch.allowance_groups_idx, dch.hours_per_day, dch.state_idx, dch.change_date
                FROM difa_resources.driver_contract dc
                LEFT JOIN difa_resources.driver d
                    ON d.idx = dc.driver_idx
                LEFT JOIN difa_resources.client_company cc
                    ON dc.client_idx = cc.idx
                 LEFT JOIN difa_resources.driver_contract_history dch
                     ON dc.idx = dch.driver_contract_idx   
                WHERE dc.driver_idx = $driverId
                    AND dc.enabled = 1 AND dc.deleted = 0
                    AND d.enabled = 1 AND d.deleted = 0";

        return $this->querySelect($sql);
    }

    public function getLastDShift($driverId, $dateStart)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(MAX(payment_end), '%Y-%m-%d') as 'dateStart'
                FROM $dbTable
                WHERE payment_start < '$dateStart'
                AND rest_time_type is not NULL;";

        return $this->querySelect($sql, true);
    }

    public function getDriverInfoExp($driverId)
    {
        $sql = "SELECT d.idx, d.dtco_driver_idx, dc.client_idx, dc.driver_types_idx, dc.contract_start, dc.expenses_near, dc.expenses_far, d.number,
                        cc.parent_idx, dc.allowance_groups_idx, dc.home_zip, dc.auto_expenses, d.last_name, d.first_name, dc.hours_per_day, dc.state_idx,
                        eg.values_table, eg.min_hours*60 as 'min_hours', eg.max_lying_days, eg.lying_days_mode, TIME_FORMAT(eg.first_day_start, '%H:%i') as 'first_day_start',
                        TIME_FORMAT(eg.last_day_end, '%H:%i') as 'last_day_end', eg.abroad_expenses, eg.shift_based, eg.return_mode
                FROM difa_resources.driver_contract dc
                LEFT JOIN difa_resources.driver d
                    ON d.idx = dc.driver_idx
                LEFT JOIN difa_resources.client_company cc
                    ON dc.client_idx = cc.idx
                LEFT JOIN difa_driver_payments.expenses_groups eg
                    ON (eg.driver_type_idx in(dc.driver_types_idx) OR (eg.driver_type_idx = '-1')) AND (cc.idx = eg.client_idx OR cc.parent_idx = eg.idx)
                WHERE dc.driver_idx = $driverId
                    AND dc.enabled = 1 AND dc.deleted = 0
                    AND d.enabled = 1 AND d.deleted = 0
                    AND eg.deleted = 0
                    AND dc.contract_end is null";

        return $this->querySelect($sql, true);
    }

    public function getAllClientDrivers($clientIds)
    {
        $sql = "SELECT dc.driver_idx, dc.client_idx, dc.driver_types_idx
                FROM difa_resources.client_company cc
                LEFT JOIN difa_resources.driver_contract dc
                    ON dc.client_idx = cc.idx
		left join difa_resources.driver d
                on d.idx = dc.driver_idx
                WHERE (cc.idx = $clientIds OR cc.parent_idx = $clientIds) 
		    AND dc.enabled = 1
                    AND dc.deleted = 0
                ORDER BY dc.driver_idx ASC";

        return $this->querySelect($sql);
    }

    public function getAllDrivers()
    {
        $sql = "SELECT dc.driver_idx, dc.client_idx, dc.driver_types_idx
                FROM difa_resources.driver d
                LEFT JOIN difa_resources.driver_contract dc
                ON d.idx = dc.driver_idx
                WHERE dc.enabled = 1
                    AND dc.deleted = 0 and dc.client_idx = 480
                    AND d.dtco_driver_idx is not null
                ORDER BY dc.driver_idx ASC";

        return $this->querySelect($sql);
    }

    public function getShiftTimes($telematicId, $driverId, $startDate) # Holt alle Positionen eines Fahrer in einem Datumsbereich
    {
        $dbTable = "difa_driver_geopositions.difa_geopositions_$telematicId";
        $dbTable .= str_pad($driverId, 15 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT IF(activity = 795, 1, 0) as 'shift',
                        DATE_FORMAT(time_stamp, '%Y-%m-%d %H:%i:%s') as 'time_stamp'
                FROM $dbTable
                WHERE time_stamp >= '$startDate' and activity in (795, 1612)
                ORDER BY time_stamp ASC";
        return $this->querySelect($sql);
    }

    public function getTelematicDriverId($driverId)
    {
        $sql = "SELECT DISTINCT(td.telematic_driver_idx) as 'telematic_driver_idx' FROM difa_resources.driver d 
                LEFT JOIN difa_dtco_datacentre.dtco_driver dt ON FIND_IN_SET(dt.idx, d.dtco_driver_idx)
                LEFT JOIN difa_geo_datacentre.difa_telematic_driver td ON td.card_number like CONCAT('%', SUBSTRING(dt.number,1,CHAR_LENGTH(dt.number) - 2),'%') OR td.card_number_manual = dt.number
                WHERE d.idx = $driverId AND td.telematic_idx is not null and td.inactive = 0 ORDER BY td.idx DESC";

        return $this->querySelect($sql, true);
    }

    public function getNewestDriverData($driverId)
    {
        $sql = "SELECT fi.file_created, fi.licence_number
                FROM difa_resources.driver d
                LEFT JOIN difa_dtco_datacentre.dtco_driver dd
                    ON find_in_set(dd.idx, d.dtco_driver_idx)
                LEFT JOIN difa_file_datacentre.dtco_file_info fi
                    ON dd.idx in (fi.dtco_driver_idx)
                WHERE d.idx = $driverId
                ORDER BY fi.file_created DESC
                LIMIT 1;";

        return $this->querySelect($sql);
    }

    public function updateDriverData($values)
    {
        $sql = "UPDATE difa_resources.driver
                SET `last_download` = :last_download, `driver_licence` = :licence_number
                WHERE `idx` = :idx;";

        return $this->iudLines($sql, $values);
    }

    public function getDriverActivities($driverCardIds, $startDate, $endDate = null)
    {
        $filter = " WHERE `start` >= '".$startDate."'";
        if (!is_null($endDate))
        {
            $filter .= " AND `start` <= '".$endDate." 23:59:59'";
        }

        if (count($driverCardIds) > 1)
        {
            for ($i = 0; $i < count($driverCardIds); $i++)
            {
                if ($i == 0)
                {
                    $dbTable = "difa_temp.driver_activities_";
                    $dbTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $tempTable = "difa_drivercard_activities.driver_activities_";
                    $tempTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $drop = "DROP TABLE IF EXISTS $dbTable;";
                    $this->iuLines($drop);

                    $stm = "CREATE temporary TABLE $dbTable 
                            ENGINE = MEMORY as
                            (SELECT  *
                            FROM $tempTable 
                            WHERE (`start`, `activity`, `duration`) 
                                not in (
                                    SELECT `start`, `activity`, `duration` 
                                    FROM $tempTable 
                                    WHERE `inserted`=1 AND `activity`=4 AND `start` 
                                        in ( 
                                            SELECT `start` 
                                            FROM $tempTable 
                                            GROUP BY `start` 
                                            HAVING count(*) > 1)))";  
                }
                else
                {
                    $tempTable = "difa_drivercard_activities.driver_activities_";
                    $tempTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $stm .= " UNION 
                                (SELECT *
                                FROM $tempTable
                                WHERE (`start`, `activity`, `duration`) 
                                    not in (
                                        SELECT `start`, `activity`, `duration` 
                                        FROM $tempTable
                                        WHERE `inserted`=1 AND `activity`=4 AND `start` 
                                            in ( 
                                                SELECT `start` 
                                                FROM $tempTable
                                                GROUP BY `start` 
                                                HAVING count(*) > 1)))";
                }
            }

            $stm .= ";";
            $this->iuLines($stm);

            $sql = "SELECT  `slot`,
                            `team`,
                            `inserted`,
                            `activity`,
                            `duration`,
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d') as 'start_date',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d %H:%i:%s') as 'date_start',
                            `start` as 'date_start_utc',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d %H:%i:%s') as 'date_end',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d') as 'end_date',
                            date_add(`start`, INTERVAL (duration/60) MINUTE) as 'date_end_utc',
                            Time(CONVERT_TZ(`start`,'UTC','CET')) as 'time_start',
                            Time(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE)) as 'time_end',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%H:%i:%s') as 't_start',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%H:%i:%s') as 't_end'
                    FROM  $dbTable";
            $sql .= $filter;
            $sql .= " ORDER BY date_start ASC;";
        }
        else
        {
            $dbTable = "difa_drivercard_activities.driver_activities_";
            $dbTable .= str_pad($driverCardIds[0], 8 ,"0" , STR_PAD_LEFT);

            $sql = "SELECT  `slot`,
                            `team`,
                            `inserted`,
                            `activity`,
                            `duration`,
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d %H:%i:%s') as 'date_start',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d') as 'start_date',
                            `start` as 'date_start_utc',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d %H:%i:%s') as 'date_end',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d') as 'end_date',
                            date_add(`start`, INTERVAL (duration/60) MINUTE) as 'date_end_utc',
                            Time(CONVERT_TZ(`start`,'UTC','CET')) as 'time_start',
                            Time(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE)) as 'time_end',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%H:%i:%s') as 't_start',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%H:%i:%s') as 't_end'
                    FROM  (SELECT  *
                            FROM $dbTable
                            WHERE (`start`, `activity`, `duration`) 
                                not in (
                                    SELECT `start`, `activity`, `duration` 
                                    FROM $dbTable 
                                    WHERE `inserted`=1 AND `activity`=4 AND `start` 
                                        in ( 
                                            SELECT `start` 
                                            FROM $dbTable 
                                            GROUP BY `start` 
                                            HAVING count(*) > 1))) v";
            $sql .= $filter;
            $sql .= " ORDER BY date_start ASC;";
        }
        return $this->querySelect($sql);
    }

    public function getDriverActivitiesD($driverCardIds, $startDate, $endDate = null)
    {
        $filter = " WHERE date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE) >= '".$startDate."'";
        if (!is_null($endDate))
        {
            $filter .= " AND `start` <= '".$endDate." 23:59:59'";
        }

        if (count($driverCardIds) > 1)
        {
            for ($i = 0; $i < count($driverCardIds); $i++)
            {
                if ($i == 0)
                {
                    $dbTable = "difa_temp.driver_activities_";
                    $dbTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $tempTable = "difa_drivercard_activities.driver_activities_";
                    $tempTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $drop = "DROP TABLE IF EXISTS $dbTable;";
                    $this->iuLines($drop);

                    $stm = "CREATE temporary TABLE $dbTable 
                            ENGINE = MEMORY as
                            (SELECT  *
                            FROM $tempTable 
                            WHERE (`start`, `activity`, `duration`) 
                                not in (
                                    SELECT `start`, `activity`, `duration` 
                                    FROM $tempTable 
                                    WHERE `inserted`=1 AND `activity`=4 AND `start` 
                                        in ( 
                                            SELECT `start` 
                                            FROM $tempTable 
                                            GROUP BY `start` 
                                            HAVING count(*) > 1)))";  
                }
                else
                {
                    $tempTable = "difa_drivercard_activities.driver_activities_";
                    $tempTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $stm .= " UNION 
                                (SELECT *
                                FROM $tempTable
                                WHERE (`start`, `activity`, `duration`) 
                                    not in (
                                        SELECT `start`, `activity`, `duration` 
                                        FROM $tempTable
                                        WHERE `inserted`=1 AND `activity`=4 AND `start` 
                                            in ( 
                                                SELECT `start` 
                                                FROM $tempTable
                                                GROUP BY `start` 
                                                HAVING count(*) > 1)))";
                }
            }

            $stm .= ";";
            $this->iuLines($stm);

            $sql = "SELECT  `slot`,
                            `team`,
                            `inserted`,
                            `activity`,
                            `duration`,
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d') as 'start_date',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d %H:%i:%s') as 'date_start',
                            `start` as 'date_start_utc',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d %H:%i:%s') as 'date_end',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d') as 'end_date',
                            date_add(`start`, INTERVAL (duration/60) MINUTE) as 'date_end_utc',
                            Time(CONVERT_TZ(`start`,'UTC','CET')) as 'time_start',
                            Time(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE)) as 'time_end',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%H:%i:%s') as 't_start',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%H:%i:%s') as 't_end'
                    FROM  $dbTable";
            $sql .= $filter;
            $sql .= " ORDER BY date_start ASC;";
        }
        else
        {
            $dbTable = "difa_drivercard_activities.driver_activities_";
            $dbTable .= str_pad($driverCardIds[0], 8 ,"0" , STR_PAD_LEFT);

            $sql = "SELECT  `slot`,
                            `team`,
                            `inserted`,
                            `activity`,
                            `duration`,
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d %H:%i:%s') as 'date_start',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%Y-%m-%d') as 'start_date',
                            `start` as 'date_start_utc',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d %H:%i:%s') as 'date_end',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%Y-%m-%d') as 'end_date',
                            date_add(`start`, INTERVAL (duration/60) MINUTE) as 'date_end_utc',
                            Time(CONVERT_TZ(`start`,'UTC','CET')) as 'time_start',
                            Time(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE)) as 'time_end',
                            DATE_FORMAT(CONVERT_TZ(`start`,'UTC','CET'), '%H:%i:%s') as 't_start',
                            DATE_FORMAT(date_add(CONVERT_TZ(`start`,'UTC','CET'), INTERVAL (duration/60) MINUTE), '%H:%i:%s') as 't_end'
                    FROM  (SELECT  *
                            FROM $dbTable
                            WHERE (`start`, `activity`, `duration`) 
                                not in (
                                    SELECT `start`, `activity`, `duration` 
                                    FROM $dbTable 
                                    WHERE `inserted`=1 AND `activity`=4 AND `start` 
                                        in ( 
                                            SELECT `start` 
                                            FROM $dbTable 
                                            GROUP BY `start` 
                                            HAVING count(*) > 1))) v";
            $sql .= $filter;
            $sql .= " ORDER BY date_start ASC;";
        }
        return $this->querySelect($sql);
    }

     public function getWorkingTimesForMapper($driverId, $startDate, $timezone, $beginnInterval = -130, $endInterval = 360) # Holt alle WorkingTimes von einem Fahrer die kein place_start und kein country_start haben
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT  idx,
		        duration_all as 'duration_all_real',
                        IF(duration_all_manual = 0 or duration_all_manual is null, duration_all, duration_all_manual) as 'duration_all',
                        duration_driving,
                        DATE_ADD(CONVERT_TZ(payment_start,'UTC','$timezone'), INTERVAL $beginnInterval MINUTE) as 'payment_start',
                        DATE_ADD(CONVERT_TZ(payment_end,'UTC','$timezone'), INTERVAL $endInterval MINUTE) as 'payment_end',
                        DATE_FORMAT(CONVERT_TZ(payment_start,'UTC','$timezone'), '%Y-%m-%d %H:%i:%s') as 'payment_start_real',
                        DATE_FORMAT(CONVERT_TZ(payment_end,'UTC','$timezone'), '%Y-%m-%d %H:%i:%s') as 'payment_end_real',
                        DATE_FORMAT(CONVERT_TZ(payment_start_manual,'UTC','$timezone'), '%Y-%m-%d %H:%i:%s') as 'payment_start_real_manual',
                        DATE_FORMAT(CONVERT_TZ(payment_end_manual,'UTC','$timezone'), '%Y-%m-%d %H:%i:%s') as 'payment_end_real_manual'
                    FROM $dbTable
                    WHERE payment_start >= '$startDate'
                    ORDER BY payment_start ASC";

        return $this->querySelect($sql);
    }

    public function getWorkingTimes($driverId, $startDate, $endDate = null, $order = null)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT  *,
                        DATE_FORMAT(payment_start, '%Y') as 'year',
                        DATE_FORMAT(payment_start, '%d.%m.%Y %H:%i') as 'payment_start',
                        DATE_FORMAT(payment_end, '%d.%m.%Y %H:%i') as 'payment_end',
                        DATE_FORMAT(payment_start, '%Y-%m-%d %H:%i') as 'payment_start_db',
                        DATE_FORMAT(payment_end, '%Y-%m-%d %H:%i') as 'payment_end_db',
                        DATE_FORMAT(payment_start, '%Y-%m-%d') as 'date_start',
                        DATE_FORMAT(payment_end, '%Y-%m-%d') as 'date_end',
                        DATE_FORMAT(time_border_crossing, '%Y-%m-%d') as 'date_border_crossing',
                        DATE_FORMAT(time_border_crossing, '%Y-%m-%d %H:%i') as 'datetime_border_crossing',
                        DATE_FORMAT(payment_start, '%H:%i') as 'time_start', 
                        DATE_FORMAT(payment_end, '%H:%i') as 'time_end'
                FROM $dbTable
                WHERE payment_start >= '$startDate'";
        if (!is_null($endDate))
        {
            $sql .= "AND payment_start < '$endDate'";
        }
        if (is_null($order))
        {
            $sql .= ' GROUP BY `payment_start` ORDER BY `payment_start_db`';
        }
        elseif ($order == 'daily_rest_time')
        {
            $sql .= ' GROUP BY `payment_start` ORDER BY `daily_rest_time` ASC';
        }
        elseif ($order == 'duration_driving')
        {
            $sql .= ' GROUP BY `payment_start` ORDER BY `duration_driving` DESC';
        }

        return $this->querySelect($sql);
    }

    public function getWorkingTimesCET($driverId, $startDate, $endDate = null)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $filter = '';
        if(!is_null($endDate)) $filter = " AND CONVERT_TZ(`payment_start`,'UTC','CET') <= '$startDate 23:59:59' ";
        $sql = "SELECT  DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%Y') as 'year',
                        CONVERT_TZ(`payment_start`,'UTC','CET') as 'payment_start',
                        CONVERT_TZ(`payment_end`,'UTC','CET') as 'payment_end',
                        DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%Y-%m-%d') as 'date_start',
                        DATE_FORMAT(CONVERT_TZ(`payment_end`,'UTC','CET'), '%Y-%m-%d') as 'date_end',
                        DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%H:%i:%s') as 'time_start', 
                        DATE_FORMAT(CONVERT_TZ(`payment_end`,'UTC','CET'), '%H:%i:%s') as 'time_end',
                        DATE_FORMAT(CONVERT_TZ(IF(payment_start_manual IS NULL, IF(payment_start_system is null, payment_start, payment_start_system),`payment_start_manual`) ,'UTC','CET'), '%H:%i:%s') AS 'time_start_manual',
                        DATE_FORMAT(CONVERT_TZ(IF(payment_end_manual IS NULL, IF(payment_end_system is null, payment_end, payment_end_system),`payment_end_manual`) ,'UTC','CET'), '%H:%i:%s') AS 'time_end_manual',

                        DATE_FORMAT(CONVERT_TZ(IF(payment_start_manual IS NULL, IF(payment_start_system is null, payment_start, payment_start_system),`payment_start_manual`) ,'UTC','CET'), '%Y-%m-%d') AS 'date_start_manual',
                        DATE_FORMAT(CONVERT_TZ(IF(payment_end_manual IS NULL, IF(payment_end_system is null, payment_end, payment_end_system),`payment_end_manual`) ,'UTC','CET'), '%Y-%m-%d') AS 'date_end_manual',

                        CONVERT_TZ(IF(payment_start_manual IS NULL, IF(payment_start_system is null, payment_start, payment_start_system),`payment_start_manual`) ,'UTC','CET') AS 'payment_start_manual',
                        CONVERT_TZ(IF(payment_end_manual IS NULL, IF(payment_end_system is null, payment_end, payment_end_system),`payment_end_manual`) ,'UTC','CET') AS 'payment_end_manual',
                        TIMESTAMPDIFF(MINUTE,
                            IF(payment_start_manual is null, IF(payment_start_system is null, payment_start, payment_start_system), payment_start_manual),
                            IF(payment_end_manual is null, IF(payment_end_system is null, payment_end, payment_end_system), payment_end_manual)) as 'duration_all_manual',
                            `duration_all`
                FROM $dbTable
                WHERE CONVERT_TZ(`payment_start`,'UTC','CET') >= '$startDate' $filter
                    AND `status` = 1
                GROUP BY `payment_start`
                ORDER BY `payment_start`";

        return $this->querySelect($sql);
    }

    public function getWorkingTimesExp($driverId, $startDate)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT  *,
                        DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%Y') as 'year',
                        DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%d.%m.%Y %H:%i:%s') as 'payment_start',
                        DATE_FORMAT(CONVERT_TZ(`payment_end`,'UTC','CET'), '%d.%m.%Y %H:%i:%s') as 'payment_end',
                        DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%Y-%m-%d %H:%i') as 'payment_start_db',
                        DATE_FORMAT(CONVERT_TZ(`payment_end`,'UTC','CET'), '%Y-%m-%d %H:%i') as 'payment_end_db',
                        DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%Y-%m-%d') as 'date_start',
                        DATE_FORMAT(CONVERT_TZ(`payment_end`,'UTC','CET'), '%Y-%m-%d') as 'date_end',
                        DATE_FORMAT(CONVERT_TZ(`time_border_crossing`,'UTC','CET'), '%Y-%m-%d') as 'date_border_crossing',
                        DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%H:%i') as 'time_start', 
                        DATE_FORMAT(CONVERT_TZ(`payment_end`,'UTC','CET'), '%H:%i') as 'time_end',
                        IF(payment_start_manual IS NULL,
                            DATE_FORMAT(CONVERT_TZ(`payment_start`,'UTC','CET'), '%d.%m.%Y %H:%i:%s'),
                            DATE_FORMAT(CONVERT_TZ(`payment_start_manual`,'UTC','CET'), '%d.%m.%Y %H:%i:%s')) AS 'payment_start_manual',
                        IF(payment_end_manual IS NULL,
                            DATE_FORMAT(CONVERT_TZ(`payment_end`,'UTC','CET'), '%d.%m.%Y %H:%i:%s'),
                            DATE_FORMAT(CONVERT_TZ(`payment_end_manual`,'UTC','CET'), '%d.%m.%Y %H:%i:%s')) AS 'payment_end_manual',
                        IF(`place_start_manual` is null, `place_start`, `place_start_manual`) as 'place_start',
                        IF(`country_start_manual` is null, `country_start`, `country_start_manual`) as 'country_start',
                        IF(`stopover_manual` is null, `stopover`, `stopover_manual`) as 'stopover',
                        IF(`place_end_manual` is null, `place_end`, `place_end_manual`) as 'place_end',
                        IF(`country_end_manual` is null, `country_end`, `country_end_manual`) as 'country_end',
                        IF(`country_border_crossing_manual` is null, `country_border_crossing`, `country_border_crossing_manual`) as 'country_border_crossing',
                        IF(`time_border_crossing_manual` is null, 
                            DATE_FORMAT(CONVERT_TZ(`time_border_crossing`,'UTC','CET'), '%Y-%m-%d %H:%i'),
                            DATE_FORMAT(CONVERT_TZ(`time_border_crossing_manual`,'UTC','CET'), '%Y-%m-%d %H:%i')) as 'time_border_crossing'
                FROM $dbTable
                WHERE CONVERT_TZ(`payment_start`,'UTC','CET') >= '$startDate'
                GROUP BY `payment_start`
                ORDER BY `payment_start_db`";

        return $this->querySelect($sql);
    }

    public function getWorkingTimesForGeo($driverId, $startDate, $timezone, $beginnInterval = -130, $endInterval = 360) # Holt alle WorkingTimes von einem Fahrer die kein place_start und kein country_start haben
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT  idx,
                        duration_all,
                        duration_driving,
                        DATE_ADD(CONVERT_TZ(payment_start,'UTC','$timezone'), INTERVAL $beginnInterval MINUTE) as 'payment_start',
                        DATE_ADD(CONVERT_TZ(payment_end,'UTC','$timezone'), INTERVAL $endInterval MINUTE) as 'payment_end',
                        DATE_FORMAT(DATE_ADD(CONVERT_TZ(payment_start,'UTC','$timezone'), INTERVAL 10 MINUTE), '%Y-%m-%d %H:%i:%s') as 'payment_start_real',
                        DATE_FORMAT(DATE_ADD(CONVERT_TZ(payment_end,'UTC','$timezone'), INTERVAL -10 MINUTE), '%Y-%m-%d %H:%i:%s') as 'payment_end_real'
                    FROM $dbTable
                    WHERE payment_start >= '$startDate'
                    ORDER BY payment_start ASC";

        return $this->querySelect($sql);
    }

    public function getGeoPositions_old($clientId, $driverId, $startDate, $endDate) # Holt alle Positionen eines Fahrer in einem Datumsbereich
    {
        $dbTable = "difa_geo_datacentre.geo_position_";
        $dbTable .= str_pad($clientId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT city, country, time_stamp
                FROM $dbTable
                WHERE driver_idx = $driverId
                    AND (time_stamp BETWEEN '$startDate' AND '$endDate')
                ORDER BY time_stamp ASC";

        return $this->querySelect($sql);
    }

    public function getGeoPositions($clientId, $driverId, $startDate) # Holt alle Positionen eines Fahrer in einem Datumsbereich
    {
        $dbTable = "difa_geo_datacentre.geo_position_";
        $dbTable .= str_pad($clientId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT city, country, time_stamp
                FROM $dbTable
                WHERE driver_idx = $driverId
                    AND time_stamp >= '$startDate'
                ORDER BY time_stamp ASC";
        return $this->querySelect($sql);
    }

    public function getSchiftTimes($clientId, $driverId, $startDate) # Holt alle Positionen eines Fahrer in einem Datumsbereich
    {
        $dbTable = "difa_client_workingtimes.shift_";
        $dbTable .= str_pad($clientId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT *,
                        DATE_FORMAT(time_stamp, '%Y-%m-%d %H:%i:%s') as 'time_stamp'
                FROM $dbTable
                WHERE driver_idx = $driverId
                    AND time_stamp >= '$startDate'
                ORDER BY time_stamp ASC";
        return $this->querySelect($sql);
    }

    public function getLDMode($driverCardIds, $startDate, $endDate) # Holt alle Positionen eines Fahrer in einem Datumsbereich
    {
        $dbTable = "difa_drivercard_usage.driver_usage_";
        $dbTable .= str_pad($driverCardIds, 8 ,"0" , STR_PAD_LEFT);
        
        $sql = "SELECT *
                FROM $dbTable
                WHERE begin_use >= '$startDate'
                    AND end_use <= '$endDate'";
        return $this->querySelect($sql);
    }

    public function changeET($number)
    {
        $dbTable = "difa_driver_expenses.driver_expenses_";
        $dbTable .= str_pad($number, 8 ,"0" , STR_PAD_LEFT);
        $sql1 ="ALTER TABLE $dbTable 
            CHANGE COLUMN user_amount user_amount DECIMAL(4, 2) DEFAULT NULL;";
        $sql2 ="UPDATE $dbTable
            SET user_amount = null WHERE 1;";
         $this->iuLines($sql1);
        return $this->iuLines($sql2);
    }

    public function updateGeoCoordinates($driverId, $idx, $startLandId, $startCity, $endLandId, $endCity, $borderLandId, $borderTime, $stopover)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $stmt = "UPDATE $dbTable 
                 SET place_start=?, country_start=?, place_end=?, country_end=?, country_border_crossing=?, time_border_crossing=?, stopover = ?
                 WHERE idx=$idx";
        return $this->iudLines($stmt, [$startCity,$startLandId,$endCity,$endLandId,$borderLandId,$borderTime,$stopover]);
    }

    public function updateShiftTimes($driverId, $idx, $start, $end, $durationManual)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $stmt = "UPDATE $dbTable
                 SET payment_start_manual = IF(payment_start_manual is null, CONVERT_TZ(?,'CET','UTC'), payment_start_manual),
                    payment_end_manual = IF(payment_end_manual is null, CONVERT_TZ(?,'CET','UTC'), payment_end_manual),
                    duration_all_manual = ?,
                    DATE_updated = NOW()
                 WHERE idx=$idx";

        return $this->iudLines($stmt, [$start,$end, $durationManual]);
    }

    public function saveWorkingTimes($driverId, $workingTimes)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "INSERT INTO $dbTable
                        (`payment_start`, `payment_end`, `duration_all`, `duration_driving`, `duration_work`, `duration_standby`, `duration_standby_team`, `duration_break0`, 
                        `duration_break15`, `working_time`, `exceeding`, `daily_rest_time`, `rest_time_all`, `rest_time_type`, `break_3h`, `team_mode`, `payment_start_manual`,
                        `payment_end_manual`, `duration_all_manual`)
                VALUES     $workingTimes
                ON DUPLICATE KEY UPDATE `payment_end` = VALUES(payment_end), `duration_all` = VALUES(duration_all),`duration_driving` = VALUES(duration_driving),
                                        `duration_work` = VALUES(duration_work), `duration_standby` = VALUES(duration_standby),`duration_standby_team` = VALUES(duration_standby_team),
                                        `duration_break0` = VALUES(duration_break0),`duration_break15` = VALUES(duration_break15),`working_time` = VALUES(working_time),
                                        `exceeding` = VALUES(exceeding),`daily_rest_time` = VALUES(daily_rest_time),`rest_time_all` = VALUES(rest_time_all),
                                        `rest_time_type` = VALUES(rest_time_type), `break_3h` = VALUES(break_3h),`team_mode` = VALUES(team_mode),`date_updated` = NOW();";

        return $this->iuLines($sql);
    }

    public function deleteWorkingTimes($driverId, $startDate)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "DELETE FROM $dbTable
                WHERE payment_start >= $startDate;";

        return $this->iuLines($sql);
    }

    public function truncateWorkingTimesTable($driverId)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "TRUNCATE TABLE $dbTable;";

        return $this->iuLines($sql);
    }

    public function truncateDocketsTable($driverId)
    {
        $dbTable = "difa_driver_dockets.driver_docket_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "TRUNCATE TABLE $dbTable;";

        return $this->iuLines($sql);
    }

    public function savePlaces($clientId, $placesValues)
    {
        $dbTable = "difa_geo_datacentre.geo_position_";
        $dbTable .= str_pad($clientId, 8 ,"0" , STR_PAD_LEFT);
        $sql = "INSERT INTO $dbTable
                        (`city`, `country`, `time_stamp`, `driver_number`, `driver_idx`, `status_driver`, `status_geo`)
                VALUES     $placesValues";

        return $this->iuLines($sql);
    }

    public function saveAllowances($driverId, $allowances)
    {
        $dbTable = "difa_driver_allowances.driver_allowances_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "INSERT INTO $dbTable
                            (`payment_date`, `payment_group`, `amount`)
                    VALUES     $allowances   
                    ON DUPLICATE KEY UPDATE `amount` = VALUES(amount), `date_updated` = NOW();";

        return $this->iuLines($sql);
    }

    public function deleteAllowances($driverId, $startDate)
    {
        $dbTable = "difa_driver_allowances.driver_allowances_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "DELETE FROM $dbTable
                WHERE payment_date >= '$startDate';";

        return $this->iuLines($sql);
    }

    public function getAllowanceAmount($valueIdx, $timeDiff, $type)
    {
        $sql = "SELECT vr.*
                FROM difa_driver_payments.group_value_ranges vr
                WHERE vr.value_idx = $valueIdx 
                    AND vr.from < $timeDiff
                    AND ((vr.to is not null AND vr.to >= $timeDiff) OR vr.to is null)
                    AND vr.type = $type;";

        return $this->querySelect($sql, true);
    }

    public function getFreeDays($driverId, $month, $type)
    {
        $dbTable = "difa_driver_dockets.driver_docket_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT count(*) as 'count'
                FROM $dbTable
                WHERE payment_type in ($type)
                    AND DATE_FORMAT(payment_date, '%m') = $month;";

        return $this->querySelect($sql, true);
    }

    public function getLastShift($driverId, $date = null)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(MAX(payment_start), '%Y-%m-%d %H:%i:%s') as 'dateStart'
                FROM $dbTable";
        if (!is_null($date))
            $sql .= " WHERE payment_start < '$date'";

        return $this->querySelect($sql, true);
    }

    public function getLastShiftV($driverId, $date = null)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(MAX(payment_start), '%Y-%m-%d %H:%i:%s') as 'dateStart'
                FROM $dbTable";
        if (!is_null($date))
            $sql .= " WHERE payment_start < '$date' AND rest_time_type = 1 and rest_time_all > 2880";

        return $this->querySelect($sql, true);
    }

    public function getLastEDay($driverId)
    {
        $dbTable = "difa_driver_expenses.driver_expenses_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(MAX(date), '%Y-%m-%d %H:%i:%s') as 'expenses_day'
                FROM $dbTable";

        return $this->querySelect($sql, true);
    }

    public function getLastEShift($driverId, $dateStart, $expPlaces)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(MAX(payment_start), '%Y-%m-%d %H:%i:%s') as 'dateStart'
                FROM $dbTable
                WHERE payment_start < '$dateStart'
                AND  FIND_IN_SET(place_end, '$expPlaces')
                AND rest_time_type is not NULL;";

        return $this->querySelect($sql, true);
    }

    public function getLastDocketDay($driverId)
    {
        $dbTable = "difa_driver_dockets.driver_docket_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(MAX(payment_date), '%Y-%m-%d') as 'dateStart'
                FROM $dbTable
                WHERE payment_type = 0;";

        return $this->querySelect($sql, true);
    }

    public function getClientPaymentGroups($clientId, $type, $driverId, $condition = false) 
    {
        $sql = "SELECT  pg.idx,
                        pg.global,
                        tr.time_start,
                        REPLACE( tr.time_end, '00:00:00', '24:00:00' ) AS 'time_end',
                        tr.additional_time_start,
                        REPLACE( tr.additional_time_end, '00:00:00', '24:00:00' ) AS 'additional_time_end',
                        tr.begin_before_midnight,
                        tr.working_times_source,
                        pg.days_idx,
                        pv.idx as 'value_idx',
                        pv.unit_idx,
                        pv.calc_type_idx,
                        pv.value_type,
                        pv.calc_basis,
                        pv.amount,
                        pv.min_amount,
                        pv.max_amount,
                        pv.subtraction,
                        pv.subtraction_type,
                        pv.subtraction_basis,
                        pv.cost_rate_idx,
                        pv.event_type,
                        DATE_FORMAT(pg.DATE_created, '%d.%m.%Y') as 'date_created'
                FROM difa_driver_payments.payment_groups pg
                LEFT JOIN difa_driver_payments.group_time_ranges tr
                    ON pg.time_range_idx = tr.idx
                LEFT JOIN difa_driver_payments.payment_values pv
                    ON pg.value_idx = pv.idx
                WHERE pg.client_idx = $clientId
                    AND pg.group_type_idx = $type
                    AND pg.deleted = 0
                    AND (find_in_set (pg.idx, (SELECT allowance_groups_idx 
                                                FROM difa_resources.driver_contract 
                                                WHERE driver_idx = $driverId
                                                    AND contract_end is null)) 
                    OR pg.global = 1)";
        if ($condition)
            $sql .= $condition;
        $sql .= ";";

        return $this->querySelect($sql);
    }

    public function getClientPaymentGroupsAllowances($clientId, $type, $driverId, $historyId, $driverType, $condition = false) 
    {
        $sql = "SELECT  pg.idx,
                        pg.global,
                        tr.time_start,
                        REPLACE( tr.time_end, '00:00:00', '24:00:00' ) AS 'time_end',
                        tr.additional_time_start,
                        REPLACE( tr.additional_time_end, '00:00:00', '24:00:00' ) AS 'additional_time_end',
                        tr.begin_before_midnight,
                        tr.working_times_source,
                        pg.days_idx,
                        pv.idx as 'value_idx',
                        pv.unit_idx,
                        pv.calc_type_idx,
                        pv.value_type,
                        pv.calc_basis,
                        pv.amount,
                        pv.min_amount,
                        pv.max_amount,
                        pv.subtraction,
                        pv.subtraction_type,
                        pv.subtraction_basis,
                        pv.cost_rate_idx,
                        pv.event_type,
                        DATE_FORMAT(pg.DATE_created, '%d.%m.%Y') as 'date_created'
                FROM difa_driver_payments.payment_groups pg
                LEFT JOIN difa_driver_payments.group_time_ranges tr
                    ON pg.time_range_idx = tr.idx
                LEFT JOIN difa_driver_payments.payment_values pv
                    ON pg.value_idx = pv.idx
                WHERE pg.client_idx = $clientId
                    AND pg.group_type_idx = $type
                    AND pg.deleted = 0
                    AND (find_in_set (pg.idx, (SELECT dch.allowance_groups_idx 
                                                FROM difa_resources.driver_contract_history dch
                                                LEFT JOIN difa_resources.driver_contract dc
                                                ON dc.idx = dch.driver_contract_idx
                                                WHERE dch.idx = $historyId 
                                                AND dc.contract_end is not null))
                    OR pg.global = 1
                    OR find_in_set ($driverType, pg.driver_type_idx))";
        if ($condition)
            $sql .= $condition;
        $sql .= ";";
	//print $sql."\n\n";
        return $this->querySelect($sql);
    }

    public function getDayDates($days, $clientRegions) {
        $sql = "SELECT  dd.*,
                        DATE_FORMAT(day_date, '%Y-%m-%d') as 'f_day_date'
                FROM difa_driver_payments.payment_days_dates dd
                WHERE dd.day_idx in ($days)
                    AND enabled = 1;";

        return $this->querySelect($sql);
    }

    public function saveWageTimes($driverId, $wageTimesValues)
    {
        $dbTable = "difa_driver_dockets.driver_docket_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "INSERT INTO $dbTable
                            (`payment_date`, `payment_group`, `payment_type`, `payment_duration`)
                    VALUES     $wageTimesValues
                    ON DUPLICATE KEY UPDATE `payment_duration` = VALUES(payment_duration), `date_updated` = NOW();";

        return $this->iuLines($sql);
    }

    public function deleteWageTimes($driverId, $startDate, $endDate = null)
    {
        $dbTable = "difa_driver_dockets.driver_docket_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);
        $filter = '';
        if (!is_null($endDate)) $filter = " AND payment_date <= $endDate ";
        $sql = "DELETE FROM $dbTable
                WHERE payment_date >= $startDate
                    $filter
                    AND payment_type = 0;";

        return $this->iuLines($sql);
    }
    
    public function getCountries()
    {
        $sql = "SELECT  *
                FROM difa_resources.client_regions cr
                WHERE cr.region_level = 1
                    AND cr.enabled = 1
                    AND cr.deleted = 0";

        return $this->querySelect($sql);
    }

    public function getExpensesForCountry($regionId, $year, $clientId = '0')
    {   
        if ($regionId == null) {return null;}
        $dbTable = "difa_client_expenses.client_expenses_";
        $dbTable .= str_pad($clientId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT *
                FROM $dbTable
                WHERE `region_idx` = $regionId
                    AND `year` = $year;";

        return $this->querySelect($sql, true);
    }

    public function getExpenses($driverId, $dateStart, $dateEnd, $condition = false)
    {
        $dbTable = "difa_driver_expenses.driver_expenses_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT  DATE_FORMAT(e.date, '%Y-%m-%d') as 'date',
                        e.amount 
                FROM $dbTable e
                WHERE date >= '$dateStart'
                    AND date <= '$dateEnd'";
        if ($condition != false)
        {
            $sql .= $condition;
        }
        return $this->querySelect($sql);       
    }

    public function deleteExpenses($driverId, $date)
    {
        $dbTable = "difa_driver_expenses.driver_expenses_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "DELETE FROM $dbTable
                WHERE date >= '$date';";

        return $this->iuLines($sql);
    }

    public function saveExpenses($driverId, $expensesValues)
    {
        $dbTable = "difa_driver_expenses.driver_expenses_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "INSERT IGNORE INTO $dbTable
                            (`date`, `amount`, `shifts`, `tour`, `country`)
                    VALUES     $expensesValues";

        return $this->iuLines($sql);
    }

    public function updateExpensesPlaces($driverId, $placeStart, $placeEnd, $startDate, $single = false)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "UPDATE $dbTable SET place_start = '$placeStart', place_end = '$placeEnd', country_start = 4, country_end = 4 WHERE";
        $single == false ? $sql .= " payment_start >= '$startDate';" : $sql .= " payment_start = '$startDate';";
        return $this->iuLines($sql);
    }

    public function getOvernight($driverId, $dateStart, $dateEnd)
    {
        $dbTable = "difa_driver_expenses.driver_expenses_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(e.date, '%Y-%m-%d') as 'date' FROM $dbTable e where date >= '$dateStart' and date < '$dateEnd' and RIGHT(shifts,5) = '24:00'";

        return $this->querySelect($sql);
    }

    public function getExtraHours($driverId, $dateStart, $dateEnd, $condition)
    {
        $dbTable = "difa_driver_dockets.driver_docket_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(d.payment_date, '%Y-%m-%d') as 'date',
                        d.payment_duration
                FROM $dbTable d
                WHERE d.payment_date >= '$dateStart'
                    AND d.payment_date < '$dateEnd'
                    AND d.payment_type in ($condition)";

        return $this->querySelect($sql);
    }

    public function getHolydays($dateStart, $dateEnd, $regions)
    {
        $sql = "SELECT DATE_FORMAT(dd.day_date, '%Y-%m-%d') as 'date', pd.factor
                FROM difa_driver_payments.payment_days_dates dd 
                LEFT JOIN difa_driver_payments.payment_days pd
                    ON dd.day_idx = pd.idx
                WHERE dd.day_date > '$dateStart'
                    AND dd.day_date < '$dateEnd'
                    AND pd.regions_idx in ($regions)";

        return $this->querySelect($sql);
    }

    public function saveViolations($violationValues)
    {
        $sql = "INSERT IGNORE INTO difa_driver_violation.driver_violations
                        (`driver_idx`, `violation_idx`, `date_start`, `date_end`, `duration`, `group`, `addition`, `fine`)
                VALUES     $violationValues";

        return $this->iuLines($sql);
    }

    public function getDriverIdByCardNumber($driverCardId)
    {
        $sql = "SELECT idx FROM difa_resources.driver WHERE FIND_IN_SET($driverCardId, dtco_driver_idx);";

        return $this->querySelect($sql, true);
    }

    public function deleteViolations($driverId, $startDate)
    {
        $sql = "DELETE FROM difa_driver_violation.driver_violations
                WHERE driver_idx = $driverId
                AND date_start >= '$startDate'
                AND deleted = 0";

        return $this->iuLines($sql);
    }

    public function getLandSigns($driverCardIds, $time)
    {
        if (count($driverCardIds) > 1)
        {
            for ($i = 0; $i < count($driverCardIds); $i++)
            {
                if ($i == 0)
                {
                    $dbTable = "difa_temp.driver_places_";
                    $dbTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $tempTable = "difa_drivercard_places.driver_places_";
                    $tempTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $drop = "DROP TABLE IF EXISTS $dbTable;";
                    $this->iuLines($drop);

                    $stm = "CREATE temporary TABLE $dbTable 
                            ENGINE = MEMORY as
                            (SELECT  * FROM $tempTable)";  
                }
                else
                {
                    $tempTable = "difa_drivercard_places.driver_places_";
                    $tempTable .= str_pad($driverCardIds[$i], 8 ,"0" , STR_PAD_LEFT);
                    $stm .= " UNION (SELECT * FROM $tempTable)";
                }
            }

            $stm .= ";";
            $this->iuLines($stm);

            $sql = "SELECT idx
                    FROM  $dbTable
                    WHERE `entry` < date_add('$time', INTERVAL 2 HOUR)
                        AND `entry` > date_add('$time', INTERVAL -2 HOUR);";
        }
        else
        {
            $dbTable = "difa_drivercard_places.driver_places_";
            $dbTable .= str_pad($driverCardIds[0], 8 ,"0" , STR_PAD_LEFT);

            $sql = "SELECT idx
                    FROM $dbTable
                    WHERE `entry` < date_add('$time', INTERVAL 2 HOUR)
                        AND `entry` > date_add('$time', INTERVAL -2 HOUR);";
        }

        return $this->querySelect($sql);
    }

    public function getRestTimes($driverId, $startDate)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);
        
        $sql = "SELECT  *,
                        date_add(payment_end, INTERVAL rest_time_all MINUTE) as 'time_end',
                        DATE_FORMAT(payment_end, '%Y-%m-%d %H:%i') as 'date_start_utc',
                        DATE_FORMAT(date_add(payment_end, INTERVAL rest_time_all MINUTE), '%Y-%m-%d %H:%i') as 'date_end_utc',
                        DATE_FORMAT(date_add(date_add(payment_end, INTERVAL rest_time_all MINUTE), INTERVAL 6 DAY), '%Y-%m-%d %H:%i:%s') as 'violation_start_utc'
                FROM $dbTable
                WHERE payment_end >= '$startDate'
                    AND rest_time_type = 1
                ORDER BY payment_start";
//print "\n".$sql."\n";
        return $this->querySelect($sql);
    }

    public function getShortenedRestTimes($driverId, $startDate, $endDate)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT  *,
                        date_add(payment_end, INTERVAL rest_time_all MINUTE) as 'time_end',
                        DATE_FORMAT(payment_end, '%Y-%m-%d %H:%i') as 'date_start_utc',
                        DATE_FORMAT(date_add(payment_end, INTERVAL rest_time_all MINUTE), '%Y-%m-%d %H:%i') as 'date_end_utc',
                        DATE_FORMAT(date_add(date_add(payment_end, INTERVAL rest_time_all MINUTE), INTERVAL 6 DAY), '%Y-%m-%d %H:%i:%s') as 'violation_start_utc'
                FROM $dbTable
                WHERE payment_end >= '$startDate'
                    AND payment_start < '$endDate'
                    AND rest_time_all >= 1440
                    AND (rest_time_type != 1
                        OR
                        rest_time_type is null)
                    ORDER BY payment_start";

        return $this->querySelect($sql);
    }

    public function setShortenedWRT($driverId, $values)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

      
        $sql = "UPDATE $dbTable
                SET `rest_time_type` = 2,
                    `rest_time_compensation` = :rest_time_compensation
                WHERE `idx` = :idx;";

        return $this->iudLines($sql, $values);
    }

    public function resetShortenedWRT($driverId, $startDate)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "UPDATE $dbTable
                SET `rest_time_type` = null,
                    `rest_time_compensation` = null
                WHERE `payment_start` >= :payment_start AND rest_time_type = 2;";
        $values = array("payment_start" => $startDate);

        return $this->iudLines($sql, $values);
    }

    public function getWeeklyRestTimes($driverId, $startDate)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT  *,
                        DATE_FORMAT(payment_end, '%Y-%m-%d %H:%i') as 'date_start_utc',
                        DATE_FORMAT(date_add(payment_end, INTERVAL rest_time_all MINUTE), '%Y-%m-%d %H:%i') as 'date_end_utc'
                FROM $dbTable
                WHERE payment_end >= '$startDate'
                    AND rest_time_type is not null
                ORDER BY payment_start";

        return $this->querySelect($sql);
    }

    public function getCountry($countryId)
    {
        $sql = "SELECT c.lang
                FROM difa_resources.client_regions c
                WHERE c.idx = $countryId";

        return $this->querySelect($sql, true);
    }

    public function hasTelematic($clientId) # Hat der Kunde Telematik?
    {
        $sql = "SELECT ta.idx, tt.provider, tt.time_zone, tt.idx as 'telematic_id'
                 FROM difa_resources.telematic_accounts ta
                 LEFT JOIN difa_resources.telematic_types tt
                    ON ta.telematic_type_idx = tt.idx 
                 WHERE client_idx=$clientId";

        return $this->querySelect($sql);
    }

    final protected function querySelect(&$query, $oneLine = false, $log = false)
    {
        try
        {
            $stmt = $this->db->query($query);
            $amount = $stmt->rowCount();
            if ($amount > 0)
            {
                if ($oneLine)
                {
                    return $stmt->fetch();
                }
                else
                {
                    return $stmt->fetchAll();
                }
            } 
            elseif ($amount == 0)
            {
                return null;
            } 
            else
            {
                return null;
            }
        } 
        catch(PDOException $e)
        {
            print_r($e);
            return null;
        }
    }
    
    public function createDriverTables($driverId)
    {
        $dbTable = "difa_driver_dockets.driver_docket_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $stm = "CREATE TABLE IF NOT EXISTS $dbTable (
                    `idx` int(11) NOT NULL AUTO_INCREMENT,
                    `payment_date` date NOT NULL,
                    `payment_group` int(11) NOT NULL,
                    `payment_duration` int(4) NOT NULL DEFAULT 0,
                    `payment_type` int(2) DEFAULT 0,
                    `comment` varchar(255) DEFAULT NULL,
                    `DATE_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `DATE_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`payment_date`, `payment_group`, `payment_type`),
                    UNIQUE KEY `idx_UNIQUE` (`idx`)
                ) ENGINE = INNODB,
                CHARACTER SET utf8mb4,
                COLLATE utf8mb4_unicode_ci;";

        $this->iuLines($stm);

        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $stm = "CREATE TABLE IF NOT EXISTS $dbTable (
                    `idx` int(11) NOT NULL AUTO_INCREMENT,
                    `payment_start` datetime NOT NULL,
                    `payment_end` datetime NOT NULL,
                    `duration_all` int(4) NOT NULL DEFAULT 0,
                    `duration_driving` int(4) NOT NULL DEFAULT 0,
                    `duration_work` int(4) NOT NULL DEFAULT 0,
                    `duration_standby` int(4) NOT NULL DEFAULT 0,
                    `duration_standby_team` int(4) NOT NULL DEFAULT 0,
                    `duration_break0` int(4) NOT NULL DEFAULT 0,
                    `duration_break15` int(4) NOT NULL DEFAULT 0,
                    `working_time` int(4) NOT NULL DEFAULT 0,
                    `exceeding` INT(4) NOT NULL DEFAULT 0,
                    `break_3h` INT(1) NOT NULL DEFAULT 0,
                    `daily_rest_time` INT(4) NOT NULL,
                    `rest_time_all` INT(4) DEFAULT NULL,
                    `rest_time_compensation` INT(4) DEFAULT NULL,
                    `rest_time_type` INT(1) DEFAULT NULL,
                    `team_mode` INT(1) NOT NULL DEFAULT 0,
                    `place_start` VARCHAR(50) DEFAULT NULL,
                    `country_start` INT(4) DEFAULT NULL,
                    `stopover` VARCHAR(255) DEFAULT NULL,
                    `place_end` VARCHAR(50) DEFAULT NULL,
                    `country_end` INT(4) DEFAULT NULL,
                    `place_border_crossing` VARCHAR(50) DEFAULT NULL,
                    `country_border_crossing` INT(4) DEFAULT NULL,
                    `time_border_crossing` DATETIME DEFAULT NULL,
                    `payment_start_manual` DATETIME DEFAULT NULL,
                    `payment_end_manual` DATETIME DEFAULT NULL,
                    `duration_all_manual` INT(4) NOT NULL DEFAULT 0,
                    `activities_start` DATETIME DEFAULT NULL,
                    `activities_end` DATETIME DEFAULT NULL,
                    `duration_activities` INT(4) DEFAULT NULL,
                    `DATE_created` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `DATE_updated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`idx`),
                    UNIQUE KEY `idx_UNIQUE` (`idx`),
                    UNIQUE INDEX `UK_payment_start` (`payment_start`)
                ) ENGINE = INNODB,
                CHARACTER SET utf8mb4,
                COLLATE utf8mb4_unicode_ci;";
                        
        $this->iuLines($stm);

        $dbTable = "difa_driver_allowances.driver_allowances_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $stm = "CREATE TABLE IF NOT EXISTS  $dbTable (
                    `payment_date` DATETIME NOT NULL,
                    `payment_group` INT(11) NOT NULL,
                    `amount` DECIMAL(8, 2) NOT NULL,
                    DATE_created DATETIME NOT NULL DEFAULT current_timestamp(),
                    DATE_updated DATETIME NOT NULL DEFAULT current_timestamp(),
                    PRIMARY KEY (`payment_date`, `payment_group`, `amount`)
                )
                ENGINE = INNODB,
                CHARACTER SET utf8mb4,
                COLLATE utf8mb4_unicode_ci;";

        $this->iuLines($stm);

        $dbTable = "difa_driver_expenses.driver_expenses_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $stm = "CREATE TABLE IF NOT EXISTS $dbTable (
                    `date` DATETIME NOT NULL,
                    `amount` DECIMAL(4, 2) NOT NULL DEFAULT 0.00,
                    `user_amount` DECIMAL(4, 2) DEFAULT NULL,
                    `shifts` VARCHAR(255) NOT NULL,
                    `tour` VARCHAR(255) NOT NULL,
                    `country` INT(10) DEFAULT NULL,
                    `DATE_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `DATE_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`date`)
                )
                ENGINE = INNODB,
                CHARACTER SET utf8mb4,
                COLLATE utf8mb4_unicode_ci;";

        return $this->iuLines($stm);
    }
    
    final protected function iudLines(&$query, $values, $returnId = false, $log = false)
    {
        $stmt = $this->db->prepare($query);

        try
        {
            $this->db->beginTransaction();
            $stmt->execute($values);
            $this->db->commit();
            $amount =  $stmt->rowCount();
            if($returnId === true)
            {
                switch ($amount)
                {
                    case 1:
                        return $this->db->lastInsertId();
                        break;
                    case -1:    
                        //$this->logSQLError($codeLine, $query);
                    case 0:        
                        return null;
                        break;
                }
            }
            if ($amount >= 0)
            {
                return $amount;
            } 
            else
            {
                return null;
            }
        } 
        catch(PDOException $e)
        {
            $this->db->rollback();
            print_r($e);
            return null;
        }
    }
    
    public function getAEDate($driverId, $driverZip)
    {
        $dbTable = "difa_driver_workingtimes.driver_workingtimes_";
        $dbTable .= str_pad($driverId, 8 ,"0" , STR_PAD_LEFT);

        $sql = "SELECT DATE_FORMAT(MAX(payment_end), '%Y-%m-%d %H:%i:%s') as 'dateStart'
                FROM $dbTable
                WHERE (rest_time_type is not null or rest_time_all > 1440) AND place_start = '$driverZip';";
        return $this->querySelect($sql, true);
    }

    final protected function iuLines(&$query, $returnId = false, $log = false)
    {
        $stmt = $this->db->prepare($query);

        try
        {
            $this->db->beginTransaction();
            $stmt->execute();
            $this->db->commit();
            $amount =  $stmt->rowCount();
            if($returnId === true)
            {
                switch ($amount)
                {
                    case 1:
                        return $this->db->lastInsertId();
                        break;
                    case -1:    
                        //$this->logSQLError($codeLine, $query);
                    case 0:        
                        return null;
                        break;
                }
            }
            if ($amount >= 0)
            {
                return $amount;
            } 
            else
            {
                return null;
            }
        } 
        catch(PDOException $e)
        {
            $this->db->rollback();
            print_r($e);
            return null;
        }
    }
}