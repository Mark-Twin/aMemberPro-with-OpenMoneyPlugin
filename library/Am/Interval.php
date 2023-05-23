<?php

/**
 * Represent an interval to run report
 * @package Am_Report
 */
class Am_Interval {

    const PERIOD_TODAY = 'today';
    const PERIOD_YESTERDAY = 'yesterday';
    const PERIOD_THIS_WEEK_FROM_SUN = 'this-week-from-sun';
    const PERIOD_THIS_WEEK_FROM_MON = 'this-week-from-mon';
    const PERIOD_LAST_7_DAYS = 'last-7-days';
    const PERIOD_LAST_WEEK_FROM_SUN = 'last-week-from-sun';
    const PERIOD_LAST_WEEK_FROM_MON = 'last-week-from-mon';
    const PERIOD_LAST_WEEK_BUSINESS = 'last-week-business';
    const PERIOD_LAST_14_DAYS = 'last-14-days';
    const PERIOD_THIS_MONTH = 'this-month';
    const PERIOD_LAST_30_DAYS = 'last-30-days';
    const PERIOD_LAST_MONTH = 'last-month';
    const PERIOD_THIS_QUARTER = 'this-quarter';
    const PERIOD_LAST_QUARTER = 'last-quarter';
    const PERIOD_LAST_90_DAYS = 'last-90-days';
    const PERIOD_LAST_6_MONTHS = 'last-6-months';
    const PERIOD_LAST_YEAR = 'last-year';
    const PERIOD_THIS_YEAR = 'this-year';
    const PERIOD_ALL = 'all';

    public function getOptions()
    {
        return array(
            self::PERIOD_TODAY => ___('Today'),
            self::PERIOD_YESTERDAY => ___('Yesterday'),
            self::PERIOD_THIS_WEEK_FROM_SUN => ___('This Week (Sun-Sat)'),
            self::PERIOD_THIS_WEEK_FROM_MON => ___('This Week (Mon-Sun)'),
            self::PERIOD_LAST_7_DAYS => ___('Last 7 Days'),
            self::PERIOD_LAST_WEEK_FROM_SUN => ___('Last Week (Sun-Sat)'),
            self::PERIOD_LAST_WEEK_FROM_MON => ___('Last Week (Mon-Sun)'),
            self::PERIOD_LAST_WEEK_BUSINESS => ___('Last Business Week (Mon-Fri)'),
            self::PERIOD_LAST_14_DAYS => ___('Last 14 Days'),
            self::PERIOD_THIS_MONTH => ___('This Month'),
            self::PERIOD_LAST_30_DAYS => ___('Last 30 Days'),
            self::PERIOD_LAST_MONTH => ___('Last Month'),
            self::PERIOD_THIS_QUARTER => ___('This Quarter'),
            self::PERIOD_LAST_QUARTER => ___('Last Quarter'),
            self::PERIOD_LAST_90_DAYS => ___('Last 90 Days'),
            self::PERIOD_LAST_6_MONTHS => ___('Last 6 Months'),
            self::PERIOD_LAST_YEAR => ___('Last Year'),
            self::PERIOD_THIS_YEAR => ___('This Year'),
            self::PERIOD_ALL => ___('All Time')
        );
    }

    public function getIntervals()
    {
        if (!isset($this->intervals)) {
            $this->intervals = array();
            foreach ($this->getOptions() as $k => $v) {
                $this->intervals[$k] = $this->getStartStop($k);
            }
        }
        return $this->intervals;
    }

    function getTitle($type)
    {
        $options = $this->getOptions();
        return isset($options[$type]) ? $options[$type] : null;
    }

    function getDuration($type)
    {
        switch($type) {
            case self::PERIOD_TODAY:
            case self::PERIOD_YESTERDAY:
                return '1 day';
            case self::PERIOD_LAST_WEEK_BUSINESS:
            case self::PERIOD_THIS_WEEK_FROM_SUN:
            case self::PERIOD_THIS_WEEK_FROM_MON:
            case self::PERIOD_LAST_WEEK_FROM_SUN:
            case self::PERIOD_LAST_WEEK_FROM_MON:
                return '1 week';
            case self::PERIOD_THIS_MONTH:
            case self::PERIOD_LAST_MONTH:
                return '1 month';
            case self::PERIOD_LAST_30_DAYS:
                return '30 days';
            case self::PERIOD_LAST_90_DAYS:
                return '90 days';
            case self::PERIOD_LAST_6_MONTHS:
                return '6 month';
            case self::PERIOD_THIS_QUARTER:
            case self::PERIOD_LAST_QUARTER:
                return '3 month';
            case self::PERIOD_LAST_YEAR:
            case self::PERIOD_THIS_YEAR:
                return '1 year';
        }
    }

    function getStartStop($type, DateTime $now = null)
    {
        is_null($now) && $now = Am_Di::getInstance()->dateTime;

        $start = $now;
        $stop = clone $now;

        switch ($type) {
            case self::PERIOD_TODAY :
                return array(
                    $start->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_YESTERDAY :
                $start->modify('-1 day');
                $stop->modify('-1 day');
                return array(
                    $start->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_THIS_WEEK_FROM_SUN :
                $w = $start->format('w');
                $start->modify("-$w days");
                $nearestSunday = $start;
                $stop = clone $start;
                $stop->modify("+6 days");
                return array(
                    $nearestSunday->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_THIS_WEEK_FROM_MON :
                $w = $start->format('w');
                $day = (7 + $w - 1) % 7;
                $start->modify("-$day days");
                $nearestMonday = $start;
                $stop = clone $start;
                $stop->modify("+6 days");
                return array(
                    $nearestMonday->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_LAST_7_DAYS :
                    $start->modify('-7 days');
                return array(
                    $start->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_LAST_WEEK_FROM_SUN :
                $w = $start->format('w');
                $day = (7 + $w - 6) % 7;
                if ($day == 0) $day = 7;
                $start->modify("-$day days");
                $saturday = $start;
                $sunday = clone $saturday;
                $sunday->modify('-6 days');
                return array(
                    $sunday->format('Y-m-d 00:00:00'),
                    $saturday->format('Y-m-d 23:59:59'));
            case self::PERIOD_LAST_WEEK_FROM_MON:
                $w = $start->format('w');
                $day = (7 + $w - 0) % 7;
                if ($day == 0) $day = 7;
                $start->modify("-$day days");
                $sunday = $start;
                $monday = clone $sunday;
                $monday->modify('-6 days');
                return array(
                    $monday->format('Y-m-d 00:00:00'),
                    $sunday->format('Y-m-d 23:59:59'));
            case self::PERIOD_LAST_WEEK_BUSINESS :
                $w = $start->format('w');
                $day = (7 + $w - 5) % 7;
                $start->modify("-$day days");
                $friday = $start;
                $monday = clone $friday;
                $monday->modify('-4 days');
                return array(
                    $monday->format('Y-m-d 00:00:00'),
                    $friday->format('Y-m-d 23:59:59'));
            case self::PERIOD_LAST_14_DAYS :
                $start->modify('-14 days');
                return array(
                    $start->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_THIS_MONTH :
                return array(
                    $start->format('Y-m-01 00:00:00'),
                    $stop->format('Y-m-t 23:59:59'));
            case self::PERIOD_LAST_30_DAYS :
                $start->modify('-30 days');
                return array(
                    $start->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_LAST_MONTH :
                $start->modify('first day of previous month');
                $stop->modify('last day of previous month');
                return array(
                    $start->format('Y-m-01 00:00:00'),
                    $stop->format('Y-m-t 23:59:59'));
            case self::PERIOD_THIS_QUARTER:
                $m = $start->format('m');
                $q = (ceil($m/3)-1)*3+1;
                $qe = $q+2;
                $dq = $q-$m;
                $dqe = $qe-$m;
                $start->modify("$dq month");
                $stop->modify("$dqe month");
                return array(
                    $start->format("Y-m-1 00:00:00"),
                    $stop->format("Y-m-t 23:59:59")
                );
            case self::PERIOD_LAST_QUARTER:
                $m = $start->format('m');
                $q = (ceil($m/3)-1)*3+1;
                $q -= 3; //prev quarter
                $qe = $q+2;
                $dq = $q-$m;
                $dqe = $qe-$m;
                $start->modify("$dq month");
                $stop->modify("$dqe month");
                return array(
                    $start->format("Y-m-1 00:00:00"),
                    $stop->format("Y-m-t 23:59:59")
                );
            case self::PERIOD_LAST_90_DAYS :
                $start->modify('-90 days');
                return array(
                    $start->format('Y-m-d 00:00:00'),
                    $stop->format('Y-m-d 23:59:59'));
            case self::PERIOD_LAST_6_MONTHS :
                $start->modify('last month');
                $start->modify('-5 month');
                $stop->modify('last month');
                return array(
                    $start->format('Y-m-01 00:00:00'),
                    $stop->format('Y-m-t 23:59:59'));
            case self::PERIOD_LAST_YEAR :
                $start->modify('last year');
                $stop->modify('last year');
                return array(
                    $start->format('Y-01-01 00:00:00'),
                    $stop->format('Y-12-t 23:59:59'));
            case self::PERIOD_THIS_YEAR :
                return array(
                    $start->format('Y-01-01 00:00:00'),
                    $stop->format('Y-12-t 23:59:59'));
            case self::PERIOD_ALL :
                return array(
                    date('Y-m-d 00:00:00', 0),
                    '2037-12-31 23:59:59');
            default:
                throw new Am_Exception_InputError(sprintf('Unknown period type [%s]', $type));
        }
    }
}