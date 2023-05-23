<?php

class AdminVatGroupController extends Am_Mvc_Controller
{
    function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SETUP);
    }

    function indexAction()
    {
        $plugin = $this->getPlugin();

        $form = new Am_Form_Admin;
        $form->addText('name', array('class' => 'el-wide'))
            ->setLabel(___("Tax Group Name\n" .
                'for your reference'))
            ->addRule('required');

        $label_cancel = Am_Html::escape(___('Cancel'));
        $url_cancel = $this->getDi()->url("admin-setup/".$plugin->getId());
        $g = $form->addGroup();
        $g->setSeparator(' ');
        $g->addSubmit('save', array('value'=>___('Save')));
        $g->addHtml()
            ->setHtml(<<<CUT
<a href="$url_cancel" class="link" style="margin-left:1em;">$label_cancel</a>
CUT
                );

        if ($form->isSubmitted() && $form->validate()) {
            $v = $form->getValue();
            $tax_groups = $plugin->getConfig('tax_groups', array());
            do {
                $id = uniqid();
            } while (isset($tax_groups[$id]));
            Am_Config::saveValue("tax.{$plugin->getId()}.tax_groups.$id", $v['name']);
            Am_Mvc_Response::redirectLocation($this->getDi()->url("admin-setup/{$plugin->getId()}#tax-rate-group-$id",false));
        } else {
            $this->view->title = ___('New Tax Group');
            $this->view->content = (string) $form;
            $this->view->display('admin/layout.phtml');
        }
    }

    function deleteAction()
    {
        $plugin = $this->getPlugin();

        if (($id = $this->getParam('id')) && $id != 'rate') {
            $tax_groups = $this->getConfig("tax.{$plugin->getId()}.tax_groups");
            unset($tax_groups[$id]);
            Am_Config::saveValue("tax.{$plugin->getId()}.tax_groups", $tax_groups);
            Am_Config::saveValue("tax.{$plugin->getId()}.$id", null);
            $this->getDi()->db->query(<<<CUT
                UPDATE ?_product SET tax_rate_group=?
                    WHERE tax_rate_group=?;
CUT
                ,'', $id);
        }
        Am_Mvc_Response::redirectLocation($this->getDi()->url("admin-setup/{$plugin->getId()}",false));
    }

    function editAction()
    {
        $plugin = $this->getPlugin();

        if (($id = $this->getParam('id')) && $id != 'rate') {
            $tax_groups = $plugin->getConfig('tax_groups');

            $form = new Am_Form_Admin;
            $form->addText('name', array('class' => 'el-wide'))
                ->setLabel(___("Tax Group Name\n" .
                    'for your reference'))
                ->addRule('required');
            $form->addHidden('id')
                ->setValue($id);

            $label_cancel = Am_Html::escape(___('Cancel'));
            $url_cancel = $this->getDi()->url("admin-setup/{$plugin->getId()}#tax-rate-group-$id");
            $g = $form->addGroup();
            $g->setSeparator(' ');
            $g->addSubmit('save', array('value'=>___('Save')));
            $g->addHtml()
            ->setHtml(<<<CUT
<a href="$url_cancel" class="link" style="margin-left:1em;">$label_cancel</a>
CUT
                );

            $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
                'name' => $tax_groups[$id]
            )));

            if ($form->isSubmitted() && $form->validate()) {
                $v = $form->getValue();
                Am_Config::saveValue("tax.{$plugin->getId()}.tax_groups." . $v['id'], $v['name']);
                Am_Mvc_Response::redirectLocation($this->getDi()->url("admin-setup/{$plugin->getId()}#tax-rate-group-{$v['id']}",false));
            } else {
                $this->view->title = ___('New Tax Group');
                $this->view->content = (string) $form;
                $this->view->display('admin/layout.phtml');
            }
        } else {
            throw new Am_Exception_InputError;
        }
    }

    function getPlugin()
    {
        list($pl) = $this->getDi()->plugins_tax->getAllEnabled();
        return $pl;
    }
}