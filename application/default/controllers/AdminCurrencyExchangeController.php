<?php

class Am_Grid_Filter_CurrencyExchange extends Am_Grid_Filter_Abstract
{
    protected function applyFilter()
    {
        $filter = $this->getParam('filter');
        if (!empty($filter['currency']))
        {
            $this->grid->getDataSource()->addWhere('currency=?', $filter['currency']);
        }
    }
    public function renderInputs()
    {
        $gridId = $this->grid->getId();
        $options = array_merge(array(''=>''), Am_Currency::getSupportedCurrencies());
        array_remove_value($options, Am_Currency::getDefault());
        $filter = $this->getParam('filter');
        return sprintf("<select name='{$gridId}_filter[currency]'>\n%s\n</select>\n",
            Am_Html::renderOptions($options, @$filter['currency']));
    }
}

class AdminCurrencyExchangeController extends Am_Mvc_Controller_Grid
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }
    public function createGrid()
    {
        $grid = new Am_Grid_Editable('_curr', ___('Currency Exchange Rates'),
            new Am_Query($this->getDi()->currencyExchangeTable),
            $this->_request, $this->view);
        $grid->setFilter(new Am_Grid_Filter_CurrencyExchange);
        $grid->addField('currency', ___('Currency'));
        $grid->addField(new Am_Grid_Field_Date('date', ___('Date')))->setFormatDate();
        $grid->addField('rate', ___('Exchange Rate'));
        $grid->setForm(array($this, 'createForm'));
        return $grid;
    }
    public function createForm()
    {
        $form = new Am_Form_Admin;
        $options = Am_Currency::getSupportedCurrencies();
        array_remove_value($options, Am_Currency::getDefault());
        
        $sel = 
            $form->addSelect('currency', array('class' => 'am-combobox'))
            ->setLabel(___('Currency'))
            ->loadOptions($options)
            ->addRule('required');
        
        $date = $form->addDate('date')->setLabel(___('Date'))
            ->addRule('required')
            ->addRule('callback2', "--wrong date--", array($this, 'checkDate'));
        
        $rate = $form->addText('rate', array('length' => 8))
            ->setLabel(___("Exchange Rate\nenter cost of 1 (one) %s", Am_Currency::getDefault()))
            ->addRule('required');
        
        return $form;
    }

    public function checkDate($date)
    {
        if ($date < $this->getDi()->sqlDate) return ___('You can not set up exchange rate for past.');
    }
}
