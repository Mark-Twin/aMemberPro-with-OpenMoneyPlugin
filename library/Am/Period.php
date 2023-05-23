<?php

/**
 * Product subscription length
 * @package Am_Utils
 */
class Am_Period
{
    const DAY = 'd';
    const MONTH = 'm';
    const YEAR = 'y';
    const FIXED = 'fixed';

    const MAX_SQL_DATE = '2037-12-31';
    const RECURRING_SQL_DATE = '2036-12-31';

    /** @var int|string number of 'units' or exact date in SQL format yyyy-mm-dd */
    protected $count = null;
    /** @var string */
    protected $unit = null;

    /**
     * Parse incoming string
     */
    function __construct($count=null, $unit=null)
    {
        if ($count !== null) {
            $this->fromString($count . $unit);
        }
    }

    /**
     * Set count and period from the string or throw Exception
     */
    function fromString($string)
    {
        if ($string instanceof Am_Period) {
            $this->count = $string->getCount();
            $this->unit  = $string->getUnit();
        }
        $string = trim(strtolower($string));

        if ($string === '') {
            $this->count = $this->unit = null;
        } elseif (preg_match('/^(\d{4}-\d{2}-\d{2})(fixed|lifetime|d)*$/', $string, $regs)) {
            $this->count = $regs[1];
            $this->unit = self::FIXED;
        } elseif (preg_match($regex='/^(\d+)\s*(|w|'.join('|', array(self::DAY, self::MONTH, self::YEAR)).')$/', $string, $regs)) {
            $this->count = intval($regs[1]);
            $this->unit = $regs[2] == '' ? self::DAY : $regs[2];
            if ($this->unit == 'w') {
                $this->count *=7 ;
                $this->unit = self::DAY;
            }
        } elseif (preg_match('/lifetime$/', $string)) {
            $this->count = self::MAX_SQL_DATE;
            $this->unit  = self::FIXED;
        } else {
            throw new Am_Exception_InternalError("Unknown format of Am_Period string : [".htmlentities($string)."]");
        }
    }

    function getCount()
    {
        return $this->count;
    }

    function getUnit()
    {
        return $this->unit;
    }

    function __toString()
    {
        $unit = $this->unit == self::FIXED ? null : $this->unit;
        return $this->count . $unit;
    }

    function addTo($date)
    {
        if ($this->isEmpty())
            throw new Am_Exception_InternalError("Could not do this operation on empty object " . __METHOD__);
        if ($this->unit == self::FIXED)
            return $this->count;
        list($y,$m,$d) = explode('-', sqlDate($date));
        $tm = amstrtotime($date);
        switch ($this->unit)
        {
            case self::DAY:
                $tm2 = mktime(0,0,0, $m, $d+$this->count, $y);
                break;
            case self::MONTH:
                $tm2 = mktime(0,0,0, $m + $this->count, $d, $y);
                break;
            case self::YEAR:
                $tm2 = mktime(0,0,0, $m, $d, $y + $this->count);
                break;
            default:
                throw new Am_Exception_InternalError("Unknown period unit configured in " . $this->__toString());
        }
        if ($tm2 < $tm) // overflow, assign fixed "lifetime" date
            return self::MAX_SQL_DATE;
        return date('Y-m-d', $tm2);
    }

    function minusFrom($date)
    {
        if ($this->isEmpty())
            throw new Am_Exception_InternalError("Could not do this operation on empty object " . __METHOD__);
        if ($this->unit == self::FIXED)
            return $this->count;
        list($y,$m,$d) = explode('-', sqlDate($date));
        $tm = amstrtotime($date);
        switch ($this->unit)
        {
            case self::DAY:
                $tm2 = mktime(0,0,0, $m, $d-$this->count, $y);
                break;
            case self::MONTH:
                $tm2 = mktime(0,0,0, $m - $this->count, $d, $y);
                break;
            case self::YEAR:
                $tm2 = mktime(0,0,0, $m, $d, $y - $this->count);
                break;
            default:
                throw new Am_Exception_InternalError("Unknown period unit configured in " . $this->__toString());
        }
        return date('Y-m-d', $tm2);

    }

    function getText($format="%s", $skip_one_c = false)
    {
        return Am_Di::getInstance()->locale->formatPeriod($this, $format, $skip_one_c);
    }

    function isEmpty()
    {
        return empty($this->count) || empty($this->unit);
    }

    function isRecurring()
    {
        return $this->count == self::RECURRING_SQL_DATE;
    }

    function isLifetime()
    {
        return $this->count == self::MAX_SQL_DATE;
    }

    function isFixed()
    {
        return $this->unit == self::FIXED;
    }

    /**
     * Return an object with max_sql_date set as value
     * @return Am_Period
     */
    static function getLifetime()
    {
       return new Am_Period(self::MAX_SQL_DATE);
    }

    function equalsTo(Am_Period $p)
    {
        return $p->count == $this->count && $p->unit == $this->unit;
    }
}