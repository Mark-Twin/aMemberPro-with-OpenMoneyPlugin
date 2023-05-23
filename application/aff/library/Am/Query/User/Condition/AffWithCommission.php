<?php

class Am_Query_User_Condition_AffWithCommission extends Am_Query_Condition implements Am_Query_Renderable_Condition
{
    var $title;
    var $type = null;

    public function __construct()
    {
        $this->title = ___('Has Affiliate Commission');
        $this->op = array(
                'any' => ___('Any'),
                'paid' => ___('Paid (Included to Payout)'),
                'not-paid' => ___('Not Paid')
            );
    }

    public function setFromRequest(array $input)
    {
        $this->type = @$input[$this->getId()]['type'];
        if ($this->type)
            return true;
    }

    public function getId()
    {
        return 'aff-with-comm';
    }

    public function renderElement(HTML_QuickForm2_Container $form)
    {
        $form->options[___('Misc')][$this->getId()] = $this->title;
        $g = $form->addGroup($this->getId())
            ->setLabel($this->title)
            ->setAttribute('id', $this->getId())
            ->setAttribute('class', 'searchField empty');
        $g->addSelect('type')
            ->loadOptions($this->op);
    }

    public function isEmpty()
    {
        return !$this->type;
    }

    public function getDescription()
    {
        return ___('Has %s Affiliate Commission', $this->op[$this->type]);
    }

    function _getWhere(Am_Query $db)
    {
        $a = $db->getAlias();
        switch ($this->type) {
            case 'any':
                $c = '';
                break;
            case 'paid' :
                $c = 'AND payout_detail_id IS NOT NULL';
                break;
            case 'not-paid' :
                $c = 'AND payout_detail_id IS NULL';
                break;
        }

        return "EXISTS
            (SELECT * FROM ?_aff_commission
            WHERE aff_id=$a.user_id
                $c)";
    }

}