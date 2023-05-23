<?php

class Am_View_Helper_GetTicketField extends Zend_View_Helper_Abstract
{
    protected $customFields = array();

    function __construct()
    {
        $this->customFields = Am_Di::getInstance()->helpdeskTicketTable->customFields()->getAll();
    }

    public function getTicketField(HelpdeskTicket $ticket, $field_name)
    {
        return $this->formatValue($this->getValue($ticket, $field_name), $field_name);
    }

    protected function getValue(HelpdeskTicket $ticket, $field_name)
    {
        return $this->customFields[$field_name]->sql ?
            $ticket->{$field_name} :
            $ticket->data()->get($field_name);
    }

    protected function formatValue($val, $field_name)
    {
        if (isset($this->customFields[$field_name])) {
            $field = $this->customFields[$field_name];
            switch($field->getType()) {
                case 'date':
                    $res = amDate($val);
                    break;
                case 'select':
                case 'radio':
                case 'checkbox':
                case 'multi_select':
                    $val = (array)$val;
                    foreach ($val as $k=>$v)
                        $val[$k] = @$field->options[$v];
                    $res = implode(", ", $val);
                    break;
                default:
                    $res = $val;
            }
        } else {
            $res = $val;
        }
        return $res;
    }
}