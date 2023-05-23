<?php

/**
 * Exception during report
 * @package Am_Report
 */
class Am_Exception_Report extends Am_Exception_InternalError {}

/**
 * An abstract report class
 * @package Am_Report
 */
abstract class Am_Report_Abstract
{
    static private $availableReports = array();
    /** @var Am_Form_Admin */
    protected $form;

    /** @var mixed executed query statement (PDOStatement?) */
    protected $stmt;

    const POINT_FLD = 'point';
    const POINT_DATE = 'date';
    const POINT_VALUE = 'value';

    /** @var Am_Report_Quant */
    protected $quantity;

    protected $id, $title, $description;
    /** @var start and stop, for example start/stop date */
    protected $start = null, $stop = null;
    protected $origColors = array();

    public function __construct() {}

    public function getId()
    {
        if (!empty($this->id)) return $this->id;
        return lcfirst(str_ireplace('Am_Report_', '', get_class($this)));
    }

    public function getTitle()
    {
        if (!empty($this->title)) return $this->title;
        return ucfirst($this->getId());
    }

    public function getDescription()
    {
        if (!empty($this->description)) return $this->description;
    }

    public function setInterval($start, $stop)
    {
        $this->start = $start;
        $this->stop = $stop;
        return $this;
    }

    public function getStart() { return $this->start; }
    public function getStop()  { return $this->stop; }

    /**
     * @return Am_Form_Admin
     */
    public function getForm()
    {
        if (!$this->form)
            $this->form = $this->createForm();
        return $this->form;
    }

    public function applyConfigForm(Am_Mvc_Request $request)
    {
        $form = $this->getForm();
        $form->setDataSources(array($request));
        $values = $form->getValue(); // get filtered input
        $this->processConfigForm($values);
    }

    public function hasConfigErrors()
    {
        return !$this->getForm()->validate();
    }

    public function setQuantity(Am_Report_Quant $quantity)
    {
        $this->quantity = $quantity;
        return $this;
    }

    /** @var Am_Report_Quant */
    public function getQuantity()
    {
        return $this->quantity;
    }

    /** @return Am_Report_Result */
    public function getReport(Am_Report_Result $result = null, Am_Report_Shift $shift = null)
    {
        $needPropagation = true;
        if ($result === null) {
            $needPropagation = false;
            $result = new Am_Report_Result;
            $result->setTitle($this->getTitle());
        }

        $result->setQuantity($this->getQuantity());
        $i = 0;
        foreach ($this->getLines() as $line) {
            $this->origColors[$i] = is_null($shift) ? $line->getColor() : $this->origColors[$i];
            $result->addLine($line, $shift, $this->origColors[$i]);
            $i++;
        }

        $this->runQuery();
        while ($r = $this->fetchNextRow())
        {
            $k = $r[self::POINT_FLD];
            unset($r[self::POINT_FLD]);
            $result->addValues($k, $this->getQuantity()->getLabel($k), $r, $shift);
        }

        if ($needPropagation) {
            $lines = $result->getLines();
            foreach ($result->getPoints() as $point) {
                foreach ($lines as $line) {
                    if (is_null($point->getValue($line->getkey()))) $point->addValue($line->getkey(), '');
                }
            }
        }

        $result->sortPoints();
        return $result;
    }

    /** @return Am_Report_Output[] */
    public function getOutput(Am_Report_Result $result)
    {
        return array(
            new Am_Report_Graph_Line($result),
            new Am_Report_Table($result)
        );
    }

    /** @return Am_Report_Line[] lines of current report */
    abstract public function getLines();

    public static function getAvailableReports()
    {
        if (!self::$availableReports) {

            Am_Di::getInstance()->hook->call(Am_Event::LOAD_REPORTS);

            foreach (amFindSuccessors(__CLASS__) as $c)
                self::$availableReports[] = new $c;
            usort(self::$availableReports, function ($a, $b) {
                return strcmp($a->getTitle(), $b->getTitle());
            });
        }
        return self::$availableReports;
    }

    /** @return Am_Report_Abstract */
    public static function createById($id)
    {
        foreach (self::getAvailableReports() as $r)
            if ($r->getId() == $id)
                return clone $r;
    }

    /**
     * Must return the report query returning specific field names
     * without the date column and date grouping applied!
     * @see getLines
     * @see applyQueryPoints
     * @return Am_Query
     *
     */
    protected function getQuery()
    {
        throw new Am_Exception_NotImplemented("override getQuery() or runQuery() method");
    }

    /** @return string "Point" field - usually dattm, date column of the table with table alias */
    protected function getPointField()
    {
        throw new Am_Exception_NotImplemented("override getPointField() or, instead entire runQuery() method");
    }

    /**
     * Add elements to config form
     * no need to add "time" controls
     */
    protected function createForm()
    {
        $form = new Am_Form_Admin('form-'.$this->getId());
        $form->addDataSource(new HTML_QuickForm2_DataSource_Array($this->getFormDefaults()));
        $form->setAction($this->getDi()->url('admin-reports/run/report_id/'.$this->getId(),null,false));
        $this->_initConfigForm($form);
        $this->_afterInitConfigForm($form);
        $form->addSubmit('save', array('value'=>___('Run Report')));
        return $form;
    }

    protected function _initConfigForm(Am_Form $form)
    {
        // to override
    }

    protected function _afterInitConfigForm(Am_Form $form)
    {
        // to override
    }

    protected function getFormDefaults()
    {
        return array();
    }

    protected function processConfigForm(array $values)
    {
        // to override
    }

    protected function fetchNextRow()
    {
        return $this->getDi()->db->fetchRow($this->stmt);
    }

    /** @return Am_Di */
    protected function getDi()
    {
        return Am_Di::getInstance();
    }

    protected function runQuery()
    {
        $q = $this->getQuery();
        $this->quantity->buildQuery($q, $this->getPointField(), $this);
        $this->applyQueryInterval($q);
        $this->stmt = $q->query();
    }

    protected function applyQueryInterval(Am_Query $q)
    {
        if (!is_null($this->start) && !is_null($this->stop)) {
            $pointField = $this->getPointField();
            $q->addWhere("$pointField BETWEEN ? AND ?", $this->start, $this->stop);
        }
    }
}

abstract class Am_Report_Date extends Am_Report_Abstract
{
    const PERIOD_EXACT = 'exact';
    protected $shift = array();

    public function getPointFieldType()
    {
        return Am_Report_Abstract::POINT_DATE;
    }

    public function setInterval($start, $stop)
    {
        $this->start = date('Y-m-d 00:00:00', strtotime($start));
        $this->stop = date('Y-m-d 23:59:59', strtotime($stop));
        return $this;
    }

    protected function _initConfigForm(Am_Form $form)
    {
        $period = $form->addSelect('period')
            ->setLabel(___('Period'))
            ->loadOptions(
                    array_merge($this->getDi()->interval->getOptions(), array(self::PERIOD_EXACT=> ___('Exact'))));

        $intervals = array();
        foreach ($this->getDi()->interval->getIntervals() as $k => $v) {
            if ($k == Am_Interval::PERIOD_ALL) {
                $intervals[$k] = '';
                continue;
            }
            list($b, $e) = $v;
            $b = amDate($b);
            $e = amDate($e);
            $intervals[$k] = ($b == $e) ? $b : sprintf('%s&mdash;%s', $b, $e);
        }
        $period->setAttribute('data-intervals', json_encode($intervals));

        $period_exact = self::PERIOD_EXACT;
        $script = <<<CUT
jQuery(function(){
jQuery('select[name=period]').change(function(){
    var str = jQuery(this).data('intervals')[jQuery(this).val()];
    jQuery(this).parent().find('.am-period-hint').remove();
    jQuery(this).after(jQuery('<span class="am-period-hint" style="margin-left:1em; color:#c2c2c2">').html(str));
    jQuery(this).closest('.am-form').find('input[name=start], input[name=stop]').
        closest('div.row').
        toggle(jQuery(this).val() == '{$period_exact}');
}).change();
})
CUT;
        $form->addScript()->setScript($script);

        $start = $form->addDate('start')->setLabel(___('Start'));
        $start->addRule('required');
        $stop  = $form->addDate('stop')->setLabel(___('End'));
        $stop->addRule('required');
        $form->addRule('callback', 'Start Date cannot be later than the End Date', array($this, 'checkStopDate'));
        $quant = $form->addSelect('quant')->setLabel(___('Grouping'));
        $quant->addRule('required');
        $quant->loadOptions($this->getQuantityOptions());
    }

    protected function _afterInitConfigForm(Am_Form $form)
    {
        foreach (Am_Report_Shift::getOptions() as $k => $v) {
            $s = Am_Report_Shift::create($k, null);
            $form->addAdvCheckbox('shift_' . $k, array('rel' =>
                    implode(' ', $s->getCompatiablePeriods())
                ), array('content' => $v));
        }
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
jQuery('select[name=period]').change(function(){
    jQuery(this).closest('.am-form').find('input[name^=shift_]').
        closest('div.row').hide();
    jQuery(this).closest('.am-form').find('input[name^=shift_][rel*=' + jQuery(this).val() + ']').
        closest('div.row').show();
}).change();
})
CUT
            );
    }

    public function checkStopDate($val)
    {
        $res = $val['stop']>$val['start'];
        if (!$res) {
            $elements = $this->getForm()->getElementsByName('start');
            $elements[0]->setError(___('Start Date cannot be later than the End Date'));
        }
        return $res;
    }

    protected function getFormDefaults()
    {
        return array(
                'start' => sqlDate('-1 month'),
                'stop'  => sqlDate('now'),
            );
    }

    protected function getStartStop(array $values)
    {
        switch ($values['period']) {
            case self::PERIOD_EXACT :
                return array($values['start'], $values['stop']);
            default :
                return $this->getDi()->interval->getStartStop($values['period']);
        }
    }

    protected function getQuantityOptions()
    {
        $res = array();
        foreach (Am_Report_Quant::getAvailableQuants($this->getPointFieldType()) as $q)
            $res[$q->getId()] = $q->getTitle();
        return $res;
    }

    protected function processConfigForm(array $values)
    {
        list($start, $stop) = $this->getStartStop($values);
        $this->setInterval($start, $stop);
        $quant = Am_Report_Quant::createById($values['quant'], $this->getPointFieldType());
        $this->setQuantity($quant);

        $this->period = $values['period'];
        foreach ($values as $k => $v) {
            if (strpos($k, 'shift_') === 0 && $v) {
                $this->shift[] = str_replace('shift_', '', $k);
            }
        }
    }

    public function getReport(Am_Report_Result $result = null, Am_Report_Shift $shift = null)
    {
        $result = parent::getReport($result);
        $origStart = $this->start;
        $origStop = $this->stop;
        foreach ($this->shift as $id) {
            $shift = Am_Report_Shift::create($id, Am_Di::getInstance()->interval->getDuration($this->period));
            if (!in_array($this->period, $shift->getCompatiablePeriods())) continue;
            $diff = '-' .  $shift->getDiff();
            $this->setInterval("{$origStart} $diff", "{$origStop} $diff");
            $result = parent::getReport($result, $shift);
        }

        return $result;
    }
}

abstract class Am_Report_Shift
{
    const SHIFT_PERIOD = 'period';
    const SHIFT_YEAR = 'year';

    static function create($id, $diff)
    {
        switch ($id) {
            case self::SHIFT_PERIOD:
                return new Am_Report_Shift_Period($diff);
            case self::SHIFT_YEAR:
                return new Am_Report_Shift_Year;
        }
    }

    static function getOptions()
    {
        return array(
            self::SHIFT_PERIOD => ___('Compare to Previous Period'),
            self::SHIFT_YEAR => ___('Compare to Same Period in Previous Year')
        );
    }



    function getId()
    {
        return $this->id;
    }

    abstract function getDiff();
    abstract function getTitle();
    abstract function getCompatiablePeriods();

}

class Am_Report_Shift_Period extends Am_Report_Shift {
    protected $id = self::SHIFT_PERIOD;
    protected $diff;

    function __construct($diff)
    {
        $this->diff = $diff;
    }

    function getTitle()
    {
        return ___('Previous Period');
    }

    function getDiff()
    {
        return $this->diff;
    }

    function getCompatiablePeriods()
    {
        return array(
            Am_Interval::PERIOD_LAST_14_DAYS,
            Am_Interval::PERIOD_LAST_30_DAYS,
            Am_Interval::PERIOD_LAST_6_MONTHS,
            Am_Interval::PERIOD_LAST_7_DAYS,
            Am_Interval::PERIOD_LAST_90_DAYS,
            Am_Interval::PERIOD_LAST_MONTH,
            Am_Interval::PERIOD_LAST_QUARTER,
            Am_Interval::PERIOD_LAST_WEEK_BUSINESS,
            Am_Interval::PERIOD_LAST_WEEK_FROM_MON,
            Am_Interval::PERIOD_LAST_WEEK_FROM_SUN,
            Am_Interval::PERIOD_LAST_YEAR,
            Am_Interval::PERIOD_THIS_MONTH,
            Am_Interval::PERIOD_THIS_QUARTER,
            Am_Interval::PERIOD_THIS_WEEK_FROM_MON,
            Am_Interval::PERIOD_THIS_WEEK_FROM_SUN,
            Am_Interval::PERIOD_THIS_YEAR,
            Am_Interval::PERIOD_TODAY,
            Am_Interval::PERIOD_YESTERDAY
        );
    }
}

class Am_Report_Shift_Year extends Am_Report_Shift {
    protected $id = self::SHIFT_YEAR;

    function getTitle()
    {
        return ___('Previous Year');
    }

    function getDiff()
    {
        return '1 year';
    }

    function getCompatiablePeriods()
    {
        return array(
            Am_Interval::PERIOD_LAST_14_DAYS,
            Am_Interval::PERIOD_LAST_30_DAYS,
            Am_Interval::PERIOD_LAST_6_MONTHS,
            Am_Interval::PERIOD_LAST_7_DAYS,
            Am_Interval::PERIOD_LAST_90_DAYS,
            Am_Interval::PERIOD_LAST_MONTH,
            Am_Interval::PERIOD_LAST_QUARTER,
            Am_Interval::PERIOD_LAST_WEEK_BUSINESS,
            Am_Interval::PERIOD_LAST_WEEK_FROM_MON,
            Am_Interval::PERIOD_LAST_WEEK_FROM_SUN,
            Am_Interval::PERIOD_THIS_MONTH,
            Am_Interval::PERIOD_THIS_QUARTER,
            Am_Interval::PERIOD_THIS_WEEK_FROM_MON,
            Am_Interval::PERIOD_THIS_WEEK_FROM_SUN,
            Am_Interval::PERIOD_TODAY,
            Am_Interval::PERIOD_YESTERDAY
        );
    }
}

/**
 * Report period quantity to group results by axis X
 */
abstract class Am_Report_Quant
{
    static $quantsList = array();

    protected $sqlExpr = null;

    public function getId()
    {
        return lcfirst(str_ireplace('Am_Report_Quant_', '', get_class($this)));
    }

    public function getTitle()
    {
        return ucfirst($this->getId());
    }

    public function getSqlExpr($pointField)
    {
        return sprintf($this->sqlExpr, $pointField);
    }

    abstract public function getPointFieldType();

    static function getAvailableQuants($pointType)
    {
        if (!isset(self::$quantsList[$pointType]))
        {
            self::$quantsList[$pointType] = array();
            foreach (amFindSuccessors(__CLASS__) as $c)
            {
                $o = new $c;
                if ($o->getPointFieldType() == $pointType)
                    self::$quantsList[$pointType][] = $o;
                else
                    unset($o);
            }
        }
        return self::$quantsList[$pointType];
    }

    public static function createById($id, $pointType)
    {
        foreach (self::getAvailableQuants($pointType) as $q)
            if ($q->getId() == $id)
                return clone $q;
    }

    /**
     * return human readable label
     *
     * @param string $key SQL point value
     */
    abstract public function getLabel($key);
    /**
     * get params for X axis of morris line
     */
    abstract public function getLineAxisParams();
    /**
     * format value for X axis of morris line graph
     *
     * @param string $key SQL point value
     */
    abstract public function formatKey($key, $graphType = 'line');
    /**
     * return next point on X axis according quant
     *
     * @param string $key SQL point value
     */
    abstract public function getNext($key);
    public function add($key, Am_Report_Shift $lag)
    {
        return $key;
    }
    public function buildQuery(Am_Query $q, $pointField, Am_Report_Date $report)
    {
        $f = $this->getSqlExpr($pointField);
        $q->addField($f, Am_Report_Abstract::POINT_FLD);
        $q->groupBy(Am_Report_Abstract::POINT_FLD, "");
    }
}

class Am_Report_Quant_Exact extends Am_Report_Quant {

    protected $sqlExpr = "%s";
    protected $step;

    public function  __construct($step=1)
    {
        $this->step = $step;
    }

    public function getPointFieldType()
    {
        return Am_Report_Abstract::POINT_VALUE;
    }

    public function getLabel($key)
    {
        return $key;
    }

    public function getLineAxisParams()
    {
        return array('parseTime' => false);
    }

    public function formatKey($key, $graphType = 'line')
    {
        return $key;
    }

    public function getNext($key)
    {
        return $key + $this->step;
    }
}

class Am_Report_Quant_Enum extends Am_Report_Quant {

    protected $sqlExpr = "%s";
    protected $options;

    public function  __construct($options = array())
    {
        $this->options = $options;
    }

    public function getPointFieldType()
    {
        return Am_Report_Abstract::POINT_VALUE;
    }

    public function getLabel($key)
    {
        return $this->options[$key];
    }

    public function getLineAxisParams()
    {
        return array('parseTime' => false);
    }

    public function formatKey($key, $graphType = 'line')
    {
        return $this->options[$key];
    }

    public function getNext($key)
    {
        $returnNext = false;
        foreach ($this->options as $k => $v) {
            if ($returnNext) return $k;
            if ($key == $k) $returnNext = true;
        }
        return $key;
    }
}

class Am_Report_Quant_Map extends Am_Report_Quant_Enum
{
    public function getNext($key)
    {
        return $key;
    }
}

abstract class Am_Report_Quant_Date extends Am_Report_Quant
{
    public function getPointFieldType()
    {
        return Am_Report_Abstract::POINT_DATE;
    }

    /**
     * retrive start and stop datetime for given key in SQL DATE Format
     *
     * @return array
     * @param string $key SQL point value
     */
    abstract public function getStartStop($key);
}

class Am_Report_Quant_Day extends Am_Report_Quant_Date
{
    protected $sqlExpr = "CAST(%s as DATE)";

    public function getTitle()
    {
        return ___("Day");
    }
    public function getLabel($key)
    {
        return amDate($key);
    }
    public function formatKey($key, $graphType = 'line')
    {
        return strtotime($key) * 1000;
    }
    public function getLineAxisParams()
    {
        return array('xLabels' => 'day');
    }
    public function getStartStop($key)
    {
        $date = sqlDate($key);
        return array(sprintf('%s', $date),sprintf('%s', $date));
    }

    public function shift($key, $diff)
    {
        $diff = '+' . $diff;
        return sqlDate(strtotime($diff, amstrtotime($key)));
    }

    public function getNext($key)
    {
        return sqlDate(strtotime('+1 day', amstrtotime($key)));
    }
}

class Am_Report_Quant_Week extends Am_Report_Quant_Date
{
    protected $sqlExpr = "YEARWEEK(%s, 5)";

    public function getTitle()
    {
        return ___("Week");
    }

    protected function getStart($key)
    {
        return strtotime(sprintf('Monday %04d-01-01 +%04d week', substr($key,0,4), substr($key, 4,2)-1));
    }

    public function formatKey($key, $graphType = 'line')
    {
        return date("Y \WW", $this->getStart($key));
    }

    public function getLabel($key)
    {
        $tm1 = $this->getStart($key);
        return amDate($tm1).'-'.amDate($tm1+6*24*3600);
    }

    public function getLineAxisParams()
    {
        return array('xLabels' => 'week');
    }
    public function getStartStop($key)
    {
        $start = $this->getStart($key);
        return array(sqlDate($start),sqlDate($start + 7*24*3600 - 1));
    }

    public function shift($key, $diff)
    {
        $diff = '+' . $diff;
        return date('YW', strtotime($diff, $this->getStart($key)));
    }

    public function getNext($key)
    {
        $year = substr($key,0,4);
        $week = substr($key, 4,2);
        //ISO-8601: 28 December is always in the last week of its year
        $lastWeek = date('W', amstrtotime("$year-12-28"));
        if ($week == $lastWeek) {
            $week=1;
            $year++;
        } else {
            $week++;
        }
        return sprintf('%04d%02d', $year, $week);
    }

}
class Am_Report_Quant_Month extends Am_Report_Quant_Date
{

    public function getTitle()
    {
        return ___("Month");
    }

    public function getSqlExpr($dateField) {
        return "DATE_FORMAT($dateField, '%Y%m')";
    }

    protected function getStart($key)
    {
        return strtotime(sprintf('%04d-%02d-01 00:00:00', substr($key,0,4), substr($key, 4,2)));
    }

    public function formatKey($key, $graphType = 'line')
    {
        return date('Y-m', $this->getStart($key));
    }

    public function getLabel($key)
    {
        $month = Am_Di::getInstance()->locale->getMonthNames('abbreviated', false);
        return $month[date('n', $this->getStart($key))] . date(' Y', $this->getStart($key));
    }

    public function getLineAxisParams()
    {
        return array('xLabels' => 'month');
    }

    public function getStartStop($key)
    {
        $start = $this->getStart($key);
        return array(sqlDate($start),sqlDate(strtotime(sprintf('%s +1 month', date('Y-m', $start)))-1));
    }

    public function shift($key, $diff)
    {
        $diff = '+' . $diff;
        return date('Ym', strtotime($diff, $this->getStart($key)));
    }

    public function getNext($key)
    {
        return date('Ym', strtotime('+1 month', $this->getStart($key)));
    }
}

class Am_Report_Quant_Quarter extends Am_Report_Quant_Date
{

    public function getTitle()
    {
        return ___("Quarter");
    }

    public function getSqlExpr($dateField) {
        return "CONCAT(YEAR($dateField), '-', QUARTER($dateField))";
    }

    protected function getStart($key)
    {
        list($year, $quarter) = explode('-', $key);
        return strtotime(sprintf('%04d-%02d-01 00:00:00', $year, ($quarter - 1)*3+1));
    }

    public function formatKey($key, $graphType = 'line')
    {
        $tm = $this->getStart($key);
        return date('Y', $tm) . ' Q' . ceil(date('m', $tm)/3);
    }

    public function getLabel($key)
    {
        list($start, $stop) = $this->getStartStop($key);
        $start = amstrtotime($start);
        $stop = amstrtotime($stop);
        $month = Am_Di::getInstance()->locale->getMonthNames('abbreviated', false);
        return $month[date('n', $start)] . date(' Y', $start) . '-' .
            $month[date('n', $stop)] . date(' Y', $stop);
    }

    public function getLineAxisParams()
    {
        return array();
    }

    public function getStartStop($key)
    {
        $start = $this->getStart($key);
        return array(sqlDate($start),sqlDate(strtotime('+3 month', $start)-1));
    }

    public function shift($key, $diff)
    {
        $diff = '+' . $diff;
        $time = strtotime($diff, $this->getStart($key));
        return date('Y', $time) . '-' . ceil(date('m', $time)/3);
    }

    public function getNext($key)
    {
        $time = strtotime('+3 month', $this->getStart($key));
        return date('Y', $time) . '-' . ceil(date('m', $time)/3);
    }
}

class Am_Report_Quant_Year extends Am_Report_Quant_Date
{
    protected $sqlExpr = "YEAR(%s)";

    public function getTitle()
    {
        return ___("Year");
    }

    protected function getStart($key)
    {
        return strtotime(sprintf('%04d-01-01', $key));
    }

    public function formatKey($key, $graphType = 'line')
    {
        return $key;
    }

    public function getLabel($key)
    {
        return $key;
    }

    public function getLineAxisParams()
    {
        return array('xLabels' => 'year');
    }

    public function getStartStop($key)
    {
        return array(sprintf('%s-01-01', $key),sprintf('%s-12-31', $key));
    }

    public function shift($key, $diff)
    {
        $diff = '+' . $diff;
        return date('Y', strtotime($diff, $this->getStart($key)));
    }

    public function getNext($key)
    {
        return ++$key;
    }
}

class Am_Report_Point
{
    protected $key;
    protected $label;
    protected $values = array();

    public function __construct($key, $label) {
        $this->key = $key;
        $this->label = $label;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function addValue($k, $v)
    {
        isset($this->values[$k]) ?
                $this->values[$k]+=$v :
                $this->values[$k] = $v;
    }

    public function addValues(array $values)
    {
        foreach ($values as $k => $v)
            isset($this->values[$k]) ?
                $this->values[$k]+=$v :
                $this->values[$k] = $v;
    }

    public function getValue($k)
    {
        return isset($this->values[$k]) ? $this->values[$k] : null;
    }

    public function hasValues()
    {
        return (bool)$this->values;
    }
}

class Am_Report_Result
{
    protected $points = array();
    protected $lines = array();
    protected $title = "Report";
    /** @var Am_Report_Quant */
    protected $quantity;
    protected $min = null;
    protected $max = null;

    public function addPoint(Am_Report_Point $p)
    {
        $this->points[$p->getKey()] = $p;
        return $p;
    }

    public function addValues($pointKey, $pointLabel, array $values, Am_Report_Shift $shift = null)
    {
        if ($shift) {
            $pointKey = $this->getQuantity()->shift($pointKey, $shift->getDiff());
            $nvalues = array();
            foreach ($values as $key => $value) {
                $nvalues[$key.$shift->getId()] = $value;
            }
            $values = $nvalues;
        }

        if (empty($this->points[$pointKey])) {
            $p = $this->addPoint(new Am_Report_Point($pointKey, $pointLabel));
            if (!is_null($this->max) && $p->getKey() > $this->max) {
                $this->populatePoints($this->max, $values);
                $this->max = $p->getKey();
            }
            if (!is_null($this->min) && $p->getKey() < $this->min) {
                $this->populatePoints($p->getKey(), $values);
                $this->min = $p->getKey();
            }
            if (is_null($this->min))
                $this->min = $this->max = $p->getKey();
        }
        $this->points[$pointKey]->addValues($values);
    }

    public function addLine(Am_Report_Line $line, Am_Report_Shift $shift = null, $refColor = null)
    {
        if ($shift) {
            $line->setColor(Am_Report_Line::transparent($refColor, 0.4));
            $line->setLabel(sprintf('%s (%s)', $line->getLabel(), $shift->getTitle()));
            $line->setKey($line->getKey() . $shift->getId());
        }
        $this->lines[$line->getKey()] = $line;
    }

    public function getLines()
    {
        return $this->lines;
    }

    public function getPoints()
    {
        return $this->points;
    }

    public function getPointsWithValues()
    {
        $ret = array();
        foreach ($this->points as $p)
            if ($p->hasValues()) $ret[] = $p;
        return $ret;
    }

    public function getValues($key)
    {
        $ret = array();
        foreach ($this->points as $p) $ret[] = doubleval($p->getValue($key));
        return $ret;
    }

    public function getLabels()
    {
        $ret = array();
        foreach ($this->points as $p) $ret[] = $p->getLabel();
        return $ret;
    }

    public function getRange($key)
    {
        $vals = $this->getValues($key);
        if (!$vals) $vals = array(0);
        $min = $max = $vals[0];
        foreach ($vals as $v)
        {
            if ($min>$v) $min=$v;
            if ($max<$v) $max=$v;
        }
        return array($min, $max);
    }

    public function setTitle($title){ $this->title = $title; }
    public function getTitle() { return $this->title; }
    public function setQuantity(Am_Report_Quant $quant) { $this->quantity = $quant; }
    public function getQuantity() { return $this->quantity; }

    /**
     * Sort points by keys. By default name sort will be used.
     * @param callback $cmpFunction
     * @return Am_Report_Result
     */
    public function sortPoints($cmpFunction=null)
    {
        uksort($this->points, $cmpFunction ?
                $cmpFunction :
                function($a, $b) {
                    if ($a == $b) {
                        return 0;
                    }
                    return ($a < $b) ? -1 : 1;
                });
        return $this;
    }

    protected function populatePoints($start, $values)
    {
        $start = $this->quantity->getNext($start);
        $values = array_map(function($a) {return "";}, $values);
        while (empty($this->points[$start]))
        {
            $this->addPoint(new Am_Report_Point($start, $this->getQuantity()->getLabel($start)));
            $this->points[$start]->addValues($values);
            $start = $this->quantity->getNext($start);
        }
    }
}

class Am_Report_Line
{
    static $colors = array(
        '#0b62a4',
        '#7A92A3',
        '#4da74d',
        '#afd8f8',
        '#edc240',
        '#cb4b4b',
        '#9440ed'
    );
    protected $key;
    protected $label;
    protected $color;
    protected $hasTotal;
    protected $formatFunc = null;

    public function __construct($key, $label, $color = null, $formatFunc = null, $hasTotal = true) {
        $this->key = $key;
        $this->label = $label;
        $this->color = $color ? $color : self::generateColor();
        $this->hasTotal = $hasTotal;
        $this->setFormatFunc($formatFunc);
    }

    public function getKey(){ return $this->key; }
    public function setKey($key){ $this->key = $key; }
    public function getLabel() { return $this->label; }
    public function setLabel($label) { $this->label = $label; }
    public function setColor($color) { $this->color = $color; }
    public function getColor() { return $this->color; }

    public function hasTotal()
    {
        return $this->hasTotal;
    }

    public function formatValue($val)
    {
        return $this->formatFunc ?
            call_user_func($this->formatFunc, $val) :
            $val;
    }

    protected function setFormatFunc($callable)
    {
        if (is_callable($callable))
            $this->formatFunc = $callable;
    }

    protected static function generateColor()
    {
        //circular array
        return next(self::$colors) ?: reset(self::$colors);
    }

    static function transparent($color, $opacity)
    {
        $bg = array(246, 245, 243);
        $color = str_replace('#', '', $color);
        if (strlen($color) == 3) {
            $color = str_repeat(substr($color,0,1), 2).str_repeat(substr($color,1,1), 2).str_repeat(substr($color,2,1), 2);
        }
        $rgb = '';
        for ($x=0; $x<3; $x++){
            $overlay = hexdec(substr($color,(2*$x),2));
            $c = $opacity * $overlay + (1-$opacity) * $bg[$x];
            $c = ($c < 0) ? 0 : dechex($c);
            $rgb .= (strlen($c) < 2) ? '0'.$c : $c;
        }
        return '#'.$rgb;
    }
}

/**
 * Abstract report output
 * @package Am_Report
 */
abstract class Am_Report_Output
{
    protected $title = "Report Output";
    /** @var Am_Report_Result */
    protected $result;
    protected $divId;

    public function __construct(Am_Report_Result $result) {
        $this->result = $result;
        $this->divId = 'report-' . substr(sha1(sprintf('%s-%s', microtime(), spl_object_hash($this))), 0, 5);
    }
    public function getTitle() { return $this->title . ' ' . $this->result->getTitle(); }
    /** @return string */
    abstract public function render();
}

/**
 * Table output
 */
class Am_Report_Table extends Am_Report_Output
{
    protected $title = "Table";
    protected $addTotal;

    function __construct(Am_Report_Result $result, $addTotal = true)
    {
        $this->addTotal = $addTotal;
        parent::__construct($result);
    }

    public function render()
    {
        $out  = "<div class='grid-container'>\n";
        $out .= "<table class='grid'>\n";
        $out .= "<tr>\n";
        $out .= "<th>#</th>\n";
        foreach ($this->result->getLines() as $line)
            $out .= "<th align='right'>" . Am_Html::escape($line->getLabel()) . "</th>\n";
        $out .= "</tr>\n";
        $lines = $totals = $vals = array();
        foreach ($this->result->getPoints() as $point)
        {
            if (!$point->hasValues()) continue;
            $i = 0;
            foreach ($this->result->getLines() as $line)
            {
                $vals[$point->getLabel()][$i] = $point->getValue($line->getKey());
                $lines[$i] = $line;
                @$totals[ $i++ ] += $point->getValue($line->getKey());
            }
        }
        $rn = 0;
        foreach ($vals as $pointLabel => $v) {
            $out .= '<tr class="grid-row ' . ($rn++ % 2 ? 'odd' : 'even') . '">';
            $out .= "<td>" . Am_Html::escape($pointLabel) . "</td>";
            foreach ($v as $i => $lineV)
            {
                $out .= sprintf('<td style="text-align: right"><span title="%s">%s</span></td>',
                    ($totals[$i] > 0) ?
                        Am_Html::escape(round($lineV*100/$totals[$i], 2) . '%') :
                        '',
                    Am_Html::escape($lines[$i]->formatValue($lineV)));
            }
            $out .= "</tr>\n";
        }
        if ($this->addTotal) {
            foreach ($totals as $k => $tt)
            {
                // if we have at least one numeric value in totals, display total row
                if ($tt > 0)
                {
                    $out .= "<tr class='grid-row am-report-total" . ($rn++ % 2 ? ' odd' : '') . "'><td><b>" . ___("Total") . "</b></td>";
                    foreach ($totals as $k => $v) {
                        $val = $lines[$k]->hasTotal() ? $lines[$k]->formatValue($v) : '';
                        $out .= "<td style='text-align: right'><b>" . Am_Html::escape($val) . "</b></td>";
                    }
                    $out .= "</tr>\n";
                    break;
                }
            }
        }
        $out .= "</table>\n";
        $out .= "</div>";
        return $out;
    }
}

/**
 * Text report output
 */
class Am_Report_Text extends Am_Report_Output
{
    protected $title = "Text";
    public function render()
    {
        $out  = "#";
        foreach ($this->result->getLines() as $line)
            $out .= " / " . $line->getLabel();
        $out .= "\n";
        foreach ($this->result->getPoints() as $point)
        {
            if (!$point->hasValues()) continue;
            $out .= $point->getLabel();
            foreach ($this->result->getLines() as $line)
                $out .= " / " . $line->formatValue($point->getValue($line->getKey()));
            $out .= "\n";
        }
        return $out;
    }
}

/**
 * Graphical report output
 */
abstract class Am_Report_Graph extends Am_Report_Output
{
    protected $title = "Graph";
    /** @var Am_Report_Result */
    protected $width = '100%';
    protected $height = 600;
    public function setSize($w, $h)
    {
        $this->width = $w;
        $this->height = $h;
        return $this;
    }

    public function render()
    {
        $ret = $this->getData();
        $ret['element'] = $this->divId;

        $class = $ret['class'];
        unset($ret['class']);

        $options = json_encode($ret);
        return <<<CUT
<div id='{$this->divId}' style='width: {$this->getWidth()}; height: {$this->getHeight()};'></div>
<script type='text/javascript'>
jQuery(function(){
    new $class($options);
});
</script>
CUT;
    }

    protected function getWidth()
    {
        return is_numeric($this->width) ? $this->width . 'px' : $this->width;
    }

    protected function getHeight()
    {
        return is_numeric($this->height) ? $this->height . 'px' : $this->height;
    }

    abstract protected function getData();
}

/**
 * A graph line
 */
class Am_Report_Graph_Line extends Am_Report_Graph
{
    protected function getData()
    {
        // prepare data
        $series = array();
        $keys = array();
        $lines = $this->result->getLines();

        foreach ($this->result->getPoints() as $p)
        {
            $keys[] = $p->getKey();
            $i = 0;
            $k = $p->getKey();
            $k = $this->result->getQuantity()->formatKey($k, 'line');
            $d = array(
                'x' => $k,
            );
            foreach ($lines as $line)
            {
                $v = $p->getValue($line->getKey());
                if ($v !== null) $v = floatval($v);
                $d['y' . $i++] = $v;
            }
            $series[] = $d;
        }

        /// build config
        $config = array(
            'class' => 'Morris.Line',
            'lineWidth' => 2,
            'pointSize' => 2,
            'hideHover' => 'auto',
            'data' => $series,
            'xkey' => 'x',
            'resize' => true,
//            'title' => array('text' => $this->getTitle() ),
        );
        $config = array_merge($config, (array)$this->result->getQuantity()->getLineAxisParams());
        $i = 0;
        foreach ($this->result->getLines() as $line)
        {
            $config['ykeys'][]  = 'y' . ($i++);
            $config['labels'][] = $line->getLabel();
            $config['lineColors'][] = $line->getColor();
        }
        return $config;
    }
}

/**
 * A graph bar
 */
class Am_Report_Graph_Bar extends Am_Report_Graph
{
    protected function getData()
    {
        // prepare data
        $series = array();
        $keys = array();
        $lines = $this->result->getLines();
        foreach ($this->result->getPoints() as $p)
        {
            $keys[] = $p->getKey();
            $i = 0;
            $k = $p->getKey();
            $d = array(
                'x' => $p->getLabel(),
            );
            foreach ($lines as $line)
            {
                $v = $p->getValue($line->getKey());
                if ($v !== null) $v = floatval($v);
                $d['y' . $i++] = $v;
            }
            $series[] = $d;
        }
        /// build config
        $config = array(
            'class' => 'Morris.Bar',
            'data' => $series,
            'hideHover' => 'auto',
            'xLabelMargin' => 10,
            'gridTextSize' => 10,
            'xkey' => 'x',
            'resize' => true,
//            'title' => array('text' => $this->getTitle() ),
        );
        $i = 0;
        foreach ($this->result->getLines() as $line)
        {
            $config['ykeys'][]  = 'y' . ($i++);
            $config['labels'][] = $line->getLabel();
        }
        return $config;
    }
}

class Am_Report_Csv extends Am_Report_Output
{
    protected $title = "CSV";
    const DELIM = ",";

    public function render()
    {
        $out = "#" . self::DELIM;
        //render headers
        foreach ($this->result->getLines() as $line)
        {
            $out .= amEscapeCsv($line->getLabel(), self::DELIM) . self::DELIM;
        }
        $out .= "\r\n";

        $totals = array();
        foreach ($this->result->getPoints() as $point)
        {
            if (!$point->hasValues()) continue;
            $out .= amEscapeCsv($point->getLabel(), self::DELIM) . self::DELIM;
            $i = 0;
            foreach ($this->result->getLines() as $line)
            {
                $out .= amEscapeCsv($line->formatValue($point->getValue($line->getKey())), self::DELIM) . self::DELIM;
                @$lines[$i] = $line;
                @$totals[ $i++ ] += $point->getValue($line->getKey());
            }
            $out .= "\r\n";
        }

        foreach ($totals as $k => $tt)
        {
            // if we have at least one numeric value in totals, display total row
            if ($tt > 0)
            {
                $out .= ___("Total") . self::DELIM;
                foreach ($totals as $v)
                    $out .= amEscapeCsv($lines[$k]->formatValue($v), self::DELIM) . self::DELIM;
                $out .= "\r\n";
                break;
            }
        }
        return $out;
    }
}
