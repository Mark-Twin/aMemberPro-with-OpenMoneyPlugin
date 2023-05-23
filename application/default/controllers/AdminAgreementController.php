<?php

class AdminAgreementController extends Am_Mvc_Controller
{
    function checkAdminPermissions(\Admin $admin)
    {
        return $admin->isSuper();
    }

    function createGrid()
    {
        $ds = new Am_Query('?_agreement');

        $grid = new Am_Grid_Editable('_agreement_editor', ___('Agreements Editor'), $ds, $this->_request, $this->view);
        return $grid;
    }


    function indexAction()
    {
        if (count($types = $this->getDi()->agreementTable->getTypes()) >= 1)
        {
            $this->advancedAction();
        }
        else
        {
            $this->simpleAction(empty($types[0]) ? null : $types[0]);
        }
    }

    function simpleAction($type = null)
    {
        if (is_null($type))
        {
            $type = $this->getParam('type');
        }

        $types = $this->getDi()->agreementTable->getTypes();

        $form = $this->createForm();

        if (is_null($type))
        {
            $this->view->title = ___('Create New Agreement');
            $form->removeElementByName('_new_revision');
        }
        else
        {
            $this->view->title = ___('Edit Agreement');
            $form->addHidden('agreement_revision_id');
        }

        $form->addSaveButton(empty($types) ? ___('Create Terms & Policy Document') : ___('Save Modifications'));

        if ($form->isSubmitted() && $form->validate())
        {
            $formData = $form->getValue();
            unset($formData['save']);
            if ($formData['_new_revision'])
                unset($formData['agreement_revision_id']);


            if (isset($formData['agreement_revision_id']))
            {
                $record = $this->getDi()->agreementTable->load($formData['agreement_revision_id']);
                $record->setForUpdate($formData);
            }
            else
            {
                $record = $this->getDi()->agreementRecord;
                $record->setForInsert($formData);
            }

            $record->save();

            if (@$formData['_is_current'])
                $record->setCurrent();
        }else
        {
            $record = $this->getDi()->agreementTable->getCurrentByType($type);
        }

        if ($record)
            $form->addDataSource(new HTML_QuickForm2_DataSource_Array($record->toArray() + ['_is_current' => $record->is_current]));


        $this->view->content = $form;
        $advancedText = ___('Switch to Advanced Mode');
        $this->view->content .= <<<CUT
<div style='float:right;'>
<a href='{$this->getDi()->url('admin-agreement/advanced')}'>{$advancedText}</a>
</div>
CUT;
        $this->view->display('admin/layout.phtml');
    }

    function advancedAction()
    {
        $query = new Am_Query($this->getDi()->agreementTable);

        $query->addWhere('is_current=1');
        $query->setOrder('type');

        $grid = new Am_Grid_Editable('_agreement', ___('Current Terms & Policy Documents'), $query, $this->getRequest(), $this->view);

        $grid->addField('type', ___('Type'));
        $grid->addField('title', ___('Document Title'));
        $grid->addField('comment', ___('Comments'));
        $grid->addField('dattm', ___('Date Modified'), false)->setRenderFunction(function($record)
        {
            $revLink = $this->getDi()->url("admin-agreement/revisions/type/{$record->type}");
            return sprintf(
                "<td>%s %s</td>", amDatetime($record->dattm), $this->getDi()->db->selectCell(
                    "SELECT COUNT(*) "
                    . "FROM ?_agreement "
                    . "WHERE agreement_revision_id <> ? AND dattm>?  AND type=?",
                    $record->pk(), $record->dattm, $record->type)
                ? "<i>( <a target='_top' href='{$revLink}'>" . ___('More recent darfs available') . "</a> )</i>" : ""
            );
        });

        $grid->addField('url', ___('Document URL'), false)
            ->setRenderFunction(function($rec, $fn, $grid, $fo){
            return $grid->renderTd(
                !empty($rec->url) ?
                    sprintf('<a href="%s" target="_blank" class="link">link</a>', $grid->escape($rec->url)) :
                    sprintf(
                        "<a href='%s' target='_blank' class='link'>text</a> | <a href='%s' target='_blank' class='link'>html</a>",
                        $this->url('agreement/'.$rec->type."?text"),
                        $this->url('agreement/'.$rec->type)
                    ), false
                );
        });

        $grid->setForm($this->createForm());

        $this->setGridCallbacks($grid);

        $grid->actionAdd((new Am_Grid_Action_Url('revisions', ___('Revisions'), $this->getDi()->url('admin-agreement/revisions/type/{type}')))->setTarget('_top'));

        $grid->actionAdd(new Am_Grid_Action_Delete_Agreement());
        $grid->runWithLayout();
    }

    function setGridCallbacks(Am_Grid_Editable $grid)
    {
        $grid->addCallback(Am_Grid_Editable::CB_INIT_FORM, function(Am_Form $form) use ($grid)
        {
            if ($grid->getRecord()->pk())
            {
                $form->getElementById('agreement-type')->toggleFrozen(true);
                $form->addHidden('type')->setValue($grid->getRecord()->type);
            }else{
                $form->removeElementByName('_new_revision');
            }
        });

        $grid->addCallback(Am_Grid_Editable::CB_BEFORE_SAVE, function(&$values, $record, $grid)
        {
            if ($values['_new_revision'])
            {
                $record = $this->getDi()->agreementRecord;
                $record->setForInsert($values);
                $record->insert();
                if ($values['_is_current'])
                    $grid->addCallback(Am_Grid_Editable::CB_AFTER_SAVE, function() use ($record)
                    {
                        $record->setCurrent();
                    });
                $values = array();
            }
            else
            {
                $values['dattm'] = $this->getDi()->sqlDateTime;
            }
            if(empty($values['_url_enable']))
                $values['url'] ='';
        });

    }

    function revisionsAction()
    {
        $type = $this->getParam('type');

        $agreement = $this->getDi()->agreementTable->getCurrentByType($type);

        $query = new Am_Query($this->getDi()->agreementTable);
        $query->addWhere('type = ?', $type);

        $query->setOrder('dattm', true);
        $grid = new Am_Grid_Editable('_agreement_revisions', ___('(%s) %s Revisions', $agreement->type, $agreement->title), $query, $this->getRequest(), $this->view);

        $grid->addField('is_current', 'Is Current?');
        $grid->addField('agreement_revision_id', ___('Revision ID'));
        $grid->addField('type', ___('Type'));
        $grid->addField('title', ___('Document Title'));
        $grid->addField('comment', ___('Comment'));
        $grid->addField('dattm', ___('Date Modified'), false);

        $grid->setForm($this->createForm());


        $grid->actionAdd(new Am_Grid_Action_Is_Current('is_current'));

        $this->setGridCallbacks($grid);


        $grid->actionAdd(new Am_Grid_Action_Delete_Agreement_Revision);
        $grid->actionDelete('insert');

        $grid->addCallback(Am_Grid_Editable::CB_RENDER_STATIC, function(&$out){
           $out.="<div style='float:right'><a target='_top' href='{$this->getDi()->url('admin-agreement')}'>".___('Back to Site Terms & Policy')."</a></div>";
        });

        $grid->runWithLayout();
    }

    /**
     *
     * @return \Am_Form_Admin
     */
    function createForm()
    {
        $form = new Am_Form_Admin('_agreement_editor');
        $types = $this->getDi()->agreementTable->getTypes();

        $form->addText('type', ['class' => 'el-wide', 'id' => 'agreement-type'])
            ->setLabel(___("Agreement Type\n"
                    . "use only letters or numbers like terms-of-use or privacy-policy"))
            ->addRule('required')
            ->addRule('regex', ___('Please use only letters or numbers'), '/^[a-zA-Z0-9_-]+$/');

        $form->addText('comment', ['class' => 'el-wide'])
            ->setLabel(___("Comment\n"
                    . "For admin use only. Won't be displayed anywhere"));

        $form->addText('title', ['class' => 'el-wide'])
            ->setLabel(___("Document Title\n"
                    . "Will be displayed for user on signup page"))
            ->addRule('required');


        $form->addAdvCheckbox('_url_enable', ['id'=>'external-agreement-enabled'])
            ->setLabel(___("Show  External Agreement Document\n"
            . "By default aMember will display agreement text, \n"
            . "but you can create agreement page outside of amember and specify it url"));

        $form->addText('url', ['id'=>'external-agreement-url', 'class'=>'el-wide'])->setLabel(___('URL of external agreement page'));

        $form->addElement(new Am_Form_Element_HtmlEditor('body', ['id'=>'agreement-body']))
            ->setLabel(___('Document Body'));

        $form->addAdvCheckbox('_is_current')
            ->setLabel(___("Document  is current for this type\n"
                    . "Only one document can be current for each agreement  type.\n"
                    . "All previous revisions of this document will be put into history"));

        $form->addAdvCheckbox('_new_revision')
            ->setLabel(___("Create New Revision of Agreement from current one\n"
                    . "If unchecked, modifications will be applied to current document\n"
                    . "If enabled, new Agreement  will be created from current one"
        ));

        $form->addScript()->setScript(<<<CUT
jQuery(function(){
  jQuery("#external-agreement-enabled").on("click", function(){
   jQuery("#external-agreement-url").closest('.row').toggle(jQuery(this).is(":checked"));
   jQuery("#agreement-body").closest('.row').toggle(!jQuery(this).is(":checked"));
  });
    if(!jQuery("#external-agreement-url:input").val()){
        jQuery("#external-agreement-url").closest('.row').hide();
    }else{
        jQuery("#external-agreement-enabled").click();
    }
});
CUT
            );

        return $form;
    }
}

class Am_Grid_Action_Is_Current  extends Am_Grid_Action_LiveCheckbox
{
    function renderStatic(& $out)
    {
        $out .= <<<CUT
<script type="text/javascript">
jQuery(document).on('click',".live-checkbox", function(event)
{

    jQuery('.live-checkbox').prop('checked', false);
    jQuery(this).prop('checked', true);
    var vars = jQuery(this).data('params');
    var t = this;

    vars[jQuery(this).attr("name")] = this.checked ? jQuery(this).data('value') : jQuery(this).data('empty_value');
    jQuery.post(jQuery(this).data('url'), vars, function(res){
        if (res.ok && res.callback)
            eval(res.callback).call(t, res.newValue);
    });

});
</script>
CUT;
    }
    function updateRecord($ds, $record, $fieldname, $v)
    {
        $record->setCurrent();

    }
}

class Am_Grid_Action_Delete_Agreement extends Am_Grid_Action_Delete
{
    function __construct()
    {
        parent::__construct('delete', ___('Delete Agreement Document'));
    }

    function getConfirmationText()
    {
        return ___("Do you really want to %s? All document revisions and information about user consent will be deleted too",
            $this->grid->getRecordTitle($this->getTitle()));
    }

    function delete()
    {
        $record = $this->grid->getRecord();
        $args = array( $record, $this->grid );
        $this->grid->runCallback(Am_Grid_Editable::CB_BEFORE_DELETE, $args);
        $this->grid->getDi()->agreementTable->deleteByType($record->type);
        $this->grid->runCallback(Am_Grid_Editable::CB_AFTER_DELETE, $args);
        $this->log();
        $this->grid->redirectBack();

    }
}

class Am_Grid_Action_Delete_Agreement_Revision extends Am_Grid_Action_Delete
{
    function __construct()
    {
        parent::__construct('delete', ___('Delete Agreement Document Revision'));
    }

    function getConfirmationText()
    {
        return ___("Do you really want to %s? Information about User Consent will be deleted too",
            $this->grid->getRecordTitle($this->getTitle()));
    }
}