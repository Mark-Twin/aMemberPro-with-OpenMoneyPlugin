<?php

class Api_AdminController extends Am_Mvc_Controller_Grid
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }

    public function createGrid()
    {
        $ds = new Am_Query($this->getDi()->apiKeyTable);

        $grid = new Am_Grid_Editable('_api', ___("API Keys"), $ds, $this->_request, $this->view, $this->getDi());
        $grid->addField('comment', ___('Comment'));
        $grid->addField(new Am_Grid_Field_Expandable('key', ___('Key')))->setPlaceholder(array($this, 'truncateKey'));
        $grid->addField(new Am_Grid_Field_IsDisabled());

        $grid->setForm(array($this, 'createForm'));
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, array($this, 'valuesToForm'));
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_FROM_FORM, array($this, 'valuesFromForm'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_CONTENT, array($this, 'renderContent'));

        return $grid;
    }

    function renderContent(& $out)
    {
        $out .= sprintf('<a href="http://www.amember.com/docs/REST" target="_blank" class="link">%s</a>', ___('REST API Documentation'));
    }

    function truncateKey($key)
    {
        return substr($key, 0, 3) . '........' . substr($key, -2, 2);
    }

    function createForm()
    {
        $form = new Am_Form_Admin;

        $form->addText('comment', 'class=el-wide')
            ->setLabel(___('Comment'))->addRule('required');

        $form->addText('key', 'class=el-wide')
            ->setLabel(___('Api Key'))
            ->addRule('required')
            ->addRule('regex', ___('Digits and latin letters only please'), '/^[a-zA-Z0-9]+$/')
            ->addRule('minlength', ___('Key must be 20 chars or longer'), 20);

        $form->addTextarea('ip', array('class'=>'one-per-line'))
            ->setLabel(___("Allowed IPs\nkeep empty to allow usage from any IP"));

        $form->addAdvCheckbox('is_disabled')->setLabel(___('Is Disabled'));

        $gr = $form->addGroup()
            ->setLabel(___('Permissions'));
        $gr->addHtml()
            ->setHtml(<<<CUT
<div style="float:right"><label for="perm-check-all"><input type="checkbox" id="perm-check-all" onchange="jQuery('[name^=_perm]').prop('checked', this.checked).change();" /> Check All</lable></div>
<script type="text/javascript">
    jQuery(function(){
        jQuery('[name^=_perm]').change(function(){
            jQuery(this).closest('label').css({opacity: this.checked ? 1 : 0.8})
        }).change();
    });
</script>
CUT
            );

        $module = $this->getModule();
        foreach ($module->getControllers() as $alias => $record)
        {
            $url = $this->getDi()->surl("api/$alias");
            $gr->addStatic()
                ->setContent("<div><strong>$alias</strong> &ndash; " . $record['comment'] . '<br />'
                    . '<a href="' . $url . '" target="_blank">' . $url . '</a><div style="padding: .8em 0 0 1em;">');
            foreach ($record['methods'] as $method)
            {
                $gr->addCheckbox("_perms[$alias][$method]")->setContent($method);
                $gr->addStatic()->setContent(' ');
            }
            $gr->addStatic()->setContent("</div></div><br />");
        }

        return $form;
    }

    function valuesToForm(array & $values, ApiKey $record)
    {
        if (empty($values['key'])) {
            $values['key'] = $this->getDi()->security->randomString(20);
        }
        $values['_perms'] = $record->getPerms();
    }

    function valuesFromForm(array & $values, ApiKey $record)
    {
        $record->setPerms($values['_perms']);
        $values['perms'] = $record->perms;
    }
}