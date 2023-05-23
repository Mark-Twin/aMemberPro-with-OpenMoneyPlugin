<?php

class AffCommissionCommissionRule extends Am_Record
{
}

class AffCommissionCommissionRuleTable extends Am_Table {
    protected $_key = 'commission_commission_rule_id';
    protected $_table = '?_aff_commission_commission_rule';
    protected $_recordClass = 'AffCommissionCommissionRule';
}