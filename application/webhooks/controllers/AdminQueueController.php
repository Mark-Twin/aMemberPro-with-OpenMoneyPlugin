<?php

class Webhooks_AdminQueueController extends Am_Mvc_Controller_Grid
{
    protected $layout = 'admin/layout.phtml';

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Webhooks::ADMIN_PERM_ID);
    }

    function createGrid()
    {
        $ds = new Am_Query($this->getDi()->webhookQueueTable);
        $ds->setOrder('added', true);
        $grid = new Am_Grid_Editable('_w', ___('Webhooks Queue'), $ds, $this->getRequest(), $this->getView(), $this->getDi());
        $grid->setPermissionId(Bootstrap_Webhooks::ADMIN_PERM_ID);
        $grid->actionDelete('edit');
        $grid->actionDelete('insert');
        $grid->actionAdd(new Am_Grid_Action_Group_Delete);
        $grid->addField(new Am_Grid_Field_Date('added', ___('Added')));
        $grid->addField(new Am_Grid_Field_Date('sent', ___('Sent')));
        $grid->addField('event_id', ___('Event'));
        $grid->addField('url', ___('Url'));
        $grid->addField('failures', ___('Failures'));
        $grid->addField('last_error', ___('Last Error'));
        $grid->addField(new Am_Grid_Field_Expandable('params', ___('Params'), false))
            ->setAjax($this->getDi()->url('webhooks/admin-queue/get-queue-details?id={webhook_queue_id}',null,false));
        $grid->setFilter(new Am_Grid_Filter_WebhookQueue);
        return $grid;
    }

    function getQueueDetailsAction()
    {
        $log = $this->getDi()->webhookQueueTable->load($this->getParam('id'));
        echo $this->renderQueueDetails($log);
    }

    public function renderQueueDetails(WebhookQueue $obj)
    {
        $ret = "";
        $ret .= "<div class='collapsible'>\n";
        if (empty($obj->params)) {
            $rows = array();
        } else {
            $rows = unserialize($obj->params);
        }

        $open = count($rows) == 1 ? 'open' : '';
        foreach ($rows as $name => $array)
        {
            $ret .= "\t<div class='item $open'>\n";
            $ret .= "\t\t<div class='head'>$name</div>\n";
            $ret .= "\t\t<div class='more'><pre>".print_r($array,true)."</pre></div>\n";
            $ret .= "\t</div>\n";
        }
        $ret .= "</div>\n\n";
        return $ret;
    }
}

class Am_Grid_Filter_WebhookQueue extends Am_Grid_Filter_Abstract
{
    protected $title = "Filter by Event";

    function applyFilter()
    {
        $this->grid->getDataSource()
            ->getDataSourceQuery()
            ->addWhere('event_id=?', $this->getParam('filter'));
    }

    function renderInputs()
    {
        $k = array_keys($this->grid->getDi()->modules->loadGet('webhooks')->getTypes());
        return $this->renderInputSelect('filter', array(''=>'') + array_combine($k, $k));
    }
}