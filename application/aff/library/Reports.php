<?php

class Am_Report_AffClicks extends Am_Report_Date
{
    protected $aff_id;

    public function __construct()
    {
        $this->title = ___('Affiliate Clicks');
        $this->description = ___('number of affiliate program clicks');
        parent::__construct();
    }

    public function getPointField()
    {
        return 'cl.time';
    }

    public function getQuery()
    {
        $q = new Am_Query($this->getDi()->affClickTable, 'cl');
        $q->clearFields();
        $q->addField('COUNT(DISTINCT cl.remote_addr) AS clicks');
        $q->addField('COUNT(cl.log_id) AS clicks_all');
        if ($this->aff_id) {
            $q->addWhere("aff_id = ?d", $this->aff_id);
        }
        return $q;
    }

    function getLines()
    {
        return array(
            new Am_Report_Line("clicks", ___('Unique Clicks')),
            new Am_Report_Line("clicks_all", ___('All Clicks')),
        );
    }

    public function setAffId($aff_id)
    {
        $this->aff_id = (int)$aff_id;
    }
}

class Am_Report_AffStats extends Am_Report_Date
{
    protected $aff_id;

    public function __construct()
    {
        $this->title = ___('Affiliate Sales');
        $this->description = ___('affiliate program commissions');
    }

    public function getPointField()
    {
        return 'cl.date';
    }

    public function getQuery()
    {
        $q = new Am_Query($this->getDi()->affCommissionTable, 'cl');
        $q->clearFields();
        $q->addField("SUM(IF(cl.record_type='commission', cl.amount, -cl.amount)) AS commission");
        if ($this->aff_id)
            $q->addWhere("aff_id = ?d", $this->aff_id);
        return $q;
    }

    function getLines()
    {
        return array(
            new Am_Report_Line("commission", ___('Commission'), null, array('Am_Currency', 'render')),
        );
    }

    public function setAffId($aff_id)
    {
        $this->aff_id = (int)$aff_id;
    }
}

class Am_Report_AffSales extends Am_Report_Date
{
    protected $aff_id;

    public function __construct()
    {
        $this->title = ___('Affiliate Sales Number');
        $this->description = ___('number of sales by affiliate');
    }

    public function getPointField()
    {
        return 'cl.date';
    }

    public function getQuery()
    {
        $q = new Am_Query($this->getDi()->affCommissionTable, 'cl');
        $q->clearFields();
        $q->addField("COUNT(DISTINCT invoice_payment_id) AS sales");
        if ($this->aff_id)
            $q->addWhere("aff_id = ?d and record_type='commission'", $this->aff_id);
        return $q;
    }

    function getLines()
    {
        return array(
            new Am_Report_Line("sales", ___('Sales')),
        );
    }

    public function setAffId($aff_id)
    {
        $this->aff_id = (int)$aff_id;
    }
}

class Am_Report_AffUserPayout extends Am_Report_Abstract
{
    const PERIOD_EXACT = 'exact';

    public function __construct()
    {
        $this->title = ___('Affiliate Payout Amount by User');
    }

    public function _initConfigForm(Am_Form $form)
    {
        parent::_initConfigForm($form);
        $period = $form->addSelect('period')->setLabel(___('Period'))
                ->loadOptions(
                    array_merge($this->getDi()->interval->getOptions(),
                        array(self::PERIOD_EXACT=> ___('Exact'))));

        $intervals = array();
        foreach ($this->getDi()->interval->getIntervals() as $k => $v) {
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

        $min = $form->addGroup()
            ->setLabel(___("Minimum Payout\n" .
                "include to report only affiliates with payout in qiven period more or equal to"));
        $min->setSeparator(' ');
        $min->addText('min', array('size'=>5, 'placeholder'=>'0.00'));
        $min->addStatic()
            ->setContent((string)Am_Currency::getDefault());

    }

    protected function getFormDefaults()
    {
        return array(
                'start' => sqlDate('-1 month'),
                'stop'  => sqlDate('now'),
            );
    }

    public function checkStopDate($val)
    {
        $res = $val['stop']>=$val['start'];
        if (!$res) {
            $elements = $this->getForm()->getElementsByName('start');
            $elements[0]->setError('Start Date cannot be later than the End Date');
        }
        return $res;
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

    protected function runQuery()
    {
        $q = new Am_Query($this->getDi()->affPayoutDetailTable, 'pd');
        $q->leftJoin('?_aff_payout', 'ap', 'pd.payout_id=ap.payout_id');
        $q->clearFields();
        $q->addWhere('pd.is_paid=1');
        $q->addField("SUM(pd.amount) AS amount");
        $q->addField('pd.aff_id', self::POINT_FLD);
        $q->addWhere("ap.date BETWEEN ? AND ?", $this->start, $this->stop);
        $q->groupBy('aff_id');
        $q->addHaving('amount>?', (float)$this->min);

        $this->stmt = $q->query();
    }

    protected function processConfigForm(array $values)
    {
        list($start, $stop) = $this->getStartStop($values);
        $this->min = $values['min'];
        $this->setInterval($start, $stop);

        $op = $this->getDi()->db->selectCol(<<<CUT
            SELECT login, apd.aff_id AS ARRAY_KEY
                FROM ?_aff_payout_detail apd
                LEFT JOIN ?_user u ON u.user_id = apd.aff_id
                LEFT JOIN ?_aff_payout ap ON apd.payout_id = ap.payout_id
                WHERE
                    apd.is_paid=1 AND
                    ap.date BETWEEN ? AND ?
CUT
            , $this->start, $this->stop);

        $quant = new Am_Report_Quant_Map($op);
        $this->setQuantity($quant);
    }

    public function setInterval($start, $stop)
    {
        $this->start = date('Y-m-d 00:00:00', strtotime($start));
        $this->stop = date('Y-m-d 23:59:59', strtotime($stop));
        return $this;
    }

    function getLines()
    {
        return array(
            new Am_Report_Line("amount", ___('Payout'), null, array('Am_Currency', 'render'))
        );
    }

    public function getOutput(Am_Report_Result $result)
    {
        return array(new Am_Report_Table($result));
    }
}

class Am_Report_AffUserComm extends Am_Report_Abstract
{
    const PERIOD_EXACT = 'exact';

    public function __construct()
    {
        $this->title = ___('Affiliate Commission Amount by User');
    }

    public function _initConfigForm(Am_Form $form)
    {
        parent::_initConfigForm($form);
        $period = $form->addSelect('period')->setLabel(___('Period'))
                ->loadOptions(
                    array_merge($this->getDi()->interval->getOptions(),
                        array(self::PERIOD_EXACT=> ___('Exact'))));

        $intervals = array();
        foreach ($this->getDi()->interval->getIntervals() as $k => $v) {
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
    }

    protected function getFormDefaults()
    {
        return array(
                'start' => sqlDate('-1 month'),
                'stop'  => sqlDate('now'),
            );
    }

    public function checkStopDate($val)
    {
        $res = $val['stop']>=$val['start'];
        if (!$res) {
            $elements = $this->getForm()->getElementsByName('start');
            $elements[0]->setError('Start Date cannot be later than the End Date');
        }
        return $res;
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

    protected function runQuery()
    {
        $q = new Am_Query($this->getDi()->affCommissionTable, 'c');
        $q->addField("SUM(IF(c.record_type = 'void', -1, 1) * c.amount) AS amount");
        $q->addField('c.aff_id', self::POINT_FLD);
        $q->addWhere("c.date BETWEEN ? AND ?", $this->start, $this->stop);
        $q->groupBy('aff_id');

        $this->stmt = $q->query();
    }

    protected function processConfigForm(array $values)
    {
        list($start, $stop) = $this->getStartStop($values);
        $this->setInterval($start, $stop);

        $op = $this->getDi()->db->selectCol(<<<CUT
            SELECT login, c.aff_id AS ARRAY_KEY
                FROM ?_aff_commission c
                LEFT JOIN ?_user u ON u.user_id = c.aff_id
                WHERE
                    c.date BETWEEN ? AND ?
CUT
            , $this->start, $this->stop);

        $quant = new Am_Report_Quant_Map($op);
        $this->setQuantity($quant);
    }

    public function setInterval($start, $stop)
    {
        $this->start = date('Y-m-d 00:00:00', strtotime($start));
        $this->stop = date('Y-m-d 23:59:59', strtotime($stop));
        return $this;
    }

    function getLines()
    {
        return array(
            new Am_Report_Line("amount", ___('Commission'), null, array('Am_Currency', 'render'))
        );
    }

    public function getOutput(Am_Report_Result $result)
    {
        return array(new Am_Report_Table($result));
    }
}