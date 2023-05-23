<?php
class Am_Grid_Action_Aff_VoidAction extends Am_Grid_Action_Abstract
{
    protected $privilege = 'void';
    protected $title;
    public function __construct($id = null, $title = null)
    {
        $this->title = ___("Void");
        $this->attributes['data-confirm'] = ___("Do you really want to void commission?");
        parent::__construct($id, $title);
    }

    function run()
    {
        $form = new Am_Form_Admin('form-vomm-void');
        $form->setAttribute('name', 'void');

        $comm = $this->grid->getRecord();

        $form->addText('amount', array('size' => 6))
            ->setlabel(___('Void Amount'));

        foreach ($this->grid->getVariablesList() as $k)
        {
            $form->addHidden($this->grid->getId() . '_' . $k)->setValue($this->grid->getRequest()->get($k, ""));
        }

        $g = $form->addGroup();
        $g->setSeparator(' ');
        $g->addSubmit('_save', array('value' => ___("Void")));
        $g->addStatic()
            ->setContent(sprintf('<a href="%s" class="link" style="margin-left:0.5em">%s</a>',
                $this->grid->getBackUrl(), ___('Cancel')));

        $form->setDataSources(array(
            $this->grid->getCompleteRequest(),
            new HTML_QuickForm2_DataSource_Array(array('amount' => $comm->amount))));

        if ($form->isSubmitted() && $form->validate())
        {
            $values = $form->getValue();
            $this->void($values['amount']);
            $this->grid->redirectBack();
        }
        else
        {
            echo $this->renderTitle();
            echo $form;
        }
    }

    public function void($amount)
    {
        $record = $this->grid->getRecord();
        if(!$record->is_voided) {
            Am_Di::getInstance()->affCommissionTable->void($record, sqlTime('now'), $amount);
        }
        $this->log();
        $this->grid->redirectBack();
    }
    public function isAvailable($record)
    {
        return (!$record->is_voided && ($record->record_type == AffCommission::COMMISSION));
    }

}
