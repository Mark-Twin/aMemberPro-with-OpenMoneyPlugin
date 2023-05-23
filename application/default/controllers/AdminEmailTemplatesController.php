<?php

/*
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Admin Info / PHP
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class AdminEmailTemplatesController extends Am_Mvc_Controller
{

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_EMAIL_TPL);
    }

    public function deleteAction()
    {
        $this->getDi()->emailTemplateTable->load($this->getParam('id'))->delete();
        if (!$this->isAjax()) {
            $this->_redirect($this->getParam('b'), array('prependBase' => false));
        }
    }

    public function editAction()
    {
        if (!$this->getParam('name'))
            throw new Am_Exception_InputError(___('Name of template is undefined'));

        $form = $this->createForm($this->getParam('name'), $this->getParam('label'));
        $tpl = $this->getTpl($this->getParam('copy_from', null));

        if (!$form->isSubmitted()) {
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array(
                    array(
                        'attachments' => $this->prepareAttachments($tpl->attachments, true),
                        'conditions' => $this->prepareAttachments($tpl->conditions, true),
                        'days' => $this->prepareAttachments($tpl->days, true),
                        '_admins' => $tpl->recipient_admins ? explode(',', $tpl->recipient_admins) : array(-1),
                    ) + $tpl->toArray()
                )
            ));
        }

        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();
            unset($vars['label']);
            if (!$vars['email_template_layout_id']) {
                $vars['email_template_layout_id'] = null;
            }
            $tpl->isLoaded() ? $tpl->setForUpdate($vars) : $tpl->setForInsert($vars);
            $tpl->conditions = $this->prepareAttachments($vars['conditions']);
            $tpl->attachments = $this->prepareAttachments($vars['attachments']);
            $tpl->recipient_admins = implode(',', isset($vars['_admins']) ? $vars['_admins'] : array());
            $tpl->save();

            $this->getDi()->adminLogTable->log("Edit Email Template ({$tpl->name})", 'email_template', $tpl->pk());
        } else {
            echo $this->createActionsForm($tpl)
            . "\n"
            . $form
            . "\n"
            . $this->getJs(!$tpl->isLoaded());
        }
    }

    public function pendingNotificationRuleAction()
    {
        $form = $this->createPendingNotificationRulesForm($this->getParam('name'), $this->getParam('label'));
        if ($id = $this->getParam('id')) {
            $form->addHidden('id')
                ->setValue($id);

            $tpl = $this->getDi()->emailTemplateTable->load($id);
        } else {
            $tpl = $this->getDi()->emailTemplateRecord;
            $tpl->setForInsert($this->getDefaultNotificationTemplate($this->getParam('name')));
        }

        if (!$form->isSubmitted()) {
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array(
                    array(
                        'attachments' => $this->prepareAttachments($tpl->attachments, true),
                        '_conditions_pid' => array_filter($this->prepareAttachments($tpl->conditions, true), function ($_) {return strpos($_, 'PAYSYSTEM-') === false;}),
                        '_conditions_paysys_id' => array_filter($this->prepareAttachments($tpl->conditions, true), function ($_) {return strpos($_, 'PAYSYSTEM-') === 0;}),
                        'days' => $this->prepareAttachments($tpl->days, true),
                        '_admins' => $tpl->recipient_admins ? explode(',', $tpl->recipient_admins) : array(-1),
                    ) + $tpl->toArray()
                )
            ));
        }

        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();
            unset($vars['label']);
            if (!$vars['email_template_layout_id']) {
                $vars['email_template_layout_id'] = null;
            }
            $tpl->isLoaded() ? $tpl->setForUpdate($vars) : $tpl->setForInsert($vars);
            $tpl->conditions = $this->prepareAttachments(array_merge($vars['_conditions_pid'], $vars['_conditions_paysys_id']));
            $tpl->attachments = $this->prepareAttachments($vars['attachments']);
            $this->sortDays($vars['days']);
            $tpl->days = $this->prepareAttachments($vars['days']);
            $tpl->recipient_admins = implode(',', isset($vars['_admins']) ? $vars['_admins'] : array());
            $tpl->save();

            $el = new Am_Form_Element_PendingNotificationRules($tpl->name);
            $el->setLabel($this->getParam('label'));
            $this->_response->ajaxResponse(array(
                'content' => (string) $el
            ));
        } else {
            echo $form;
        }
    }

    public function exportAction()
    {
        $this->_helper->sendFile->sendData(
            $this->getDi()->emailTemplateTable->exportReturnXml(array('email_template_id')), 'text/xml', 'amember-email-templates-' . $this->getDi()->sqlDate . '.xml');
    }

    public function importAction()
    {
        $form = new Am_Form_Admin;

        $import = $form->addFile('import')
                ->setLabel(___('Upload file [email-templates.xml]'));

        $form->addStatic('')->setContent(___('WARNING! All existing e-mail templates will be removed from database!'));
        //$import->addRule('required', 'Please upload file');
        //$form->addAdvCheckbox('remove')->setLabel('Remove your existing templates?');
        $form->addSaveButton(___('Upload'));

        if ($form->isSubmitted() && $form->validate())
        {
            $value = $form->getValue();

            $fn = $this->getDi()->data_dir . '/import.email-templates.xml';

            if (!move_uploaded_file($value['import']['tmp_name'], $fn))
                throw new Am_Exception_InternalError(___('Could not move uploaded file'));

            $xml = file_get_contents($fn);
            if (!$xml)
                throw new Am_Exception_InputError(___('Could not read XML'));

            $count = $this->getDi()->emailTemplateTable->deleteBy(array())->importXml($xml);
            $this->view->content = ___('Import Finished. %d templates imported.', $count);
        } else {
            $this->view->content = (string) $form;
        }
        $this->view->title = ___('Import E-Mail Templates from XML file');
        $this->view->display('admin/layout.phtml');
    }

    protected function getNumberString($i)
    {
        switch ($i)
        {
            case 2 :
                return $i . 'nd';
                break;
            case 3 :
                return $i . 'rd';
                break;
            default :
                return $i . 'th';
        }
    }

    protected function getDayOptions()
    {
        $options = array(
            '0' => ___('Immediately'),
            ___('Hours') => array(
                '1h' => ___('Next Hour')
            ),
            ___('Days') => array(
                '1' => ___('Next Day')
            )
        );
        for ($i = 2; $i <= 6; $i++)
            $options[___('Hours')][$i . 'h'] = $this->getNumberString($i) . ___(' hour');
        for ($i = 2; $i <= 40; $i++)
            $options[___('Days')][$i] = $this->getNumberString($i) . ___(' day');

        return $options;
    }

    protected function getAdminOptions()
    {
        $op = array(
            '-1' => sprintf('%s <%s>',
                $this->getDi()->config->get('site_title') . ' Admin',
                $this->getDi()->config->get('admin_email'))
        );
        foreach ($this->getDi()->adminTable->findBy() as $admin) {
           $op["{$admin->pk()}"] = sprintf('%s <%s>', $admin->getName(), $admin->email);
        }
        $op = array_map(array($this, 'escape'), $op);
        return $op;
    }

    protected function getProductConditionOptions()
    {
        $product_options = array();
        foreach ($this->getDi()->productTable->getOptions() as $id => $title)
        {
            $product_options['PRODUCT-' . $id] = ___('Product: ') . Am_Html::escape(___($title));
        }

        $group_options = array();
        foreach ($this->getDi()->productCategoryTable->getAdminSelectOptions() as $id => $title)
        {
            $group_options['CATEGORY-' . $id] = ___('Product Category: ') . Am_Html::escape(___($title));
        }

        $options = array(
            ___('Products') => $product_options
        );

        if (count($group_options))
        {
            $options[___('Product Categories')] = $group_options;
        }

        return $options;
    }

    protected function getPaysysConditionOptions()
    {
        $paysys_options = array();
        foreach ($this->getDi()->paysystemList->getAllPublic() as $ps)
        {
            $paysys_options['PAYSYSTEM-' . $ps->getId()] = Am_Html::escape(___($ps->getTitle()));
        }

        return $paysys_options;
    }

    protected function getTpl($copy_from = null)
    {
        if ($copy_from)
            return $this->getCopiedTpl($copy_from);

        $tpl = $this->getDi()->emailTemplateTable->getExact(
            $this->getParam('name'), $this->getParam('lang', $this->getDefaultLang()), $this->getParam('day', null)
        );

        if (!$tpl)
        {
            $tpl = $this->getDi()->emailTemplateRecord;
            $tpl->name = $this->getParam('name');
            $tpl->lang = $this->getParam('lang', $this->getDefaultLang());
            $tpl->subject = $this->getParam('name');
            $tpl->day = $this->getParam('day', null);
            $tpl->format = 'text';
            $tpl->plain_txt = null;
            $tpl->txt = null;
            $tpl->attachments = null;
        }

        return $tpl;
    }

    protected function getCopiedTpl($copy_from)
    {
        $sourceTpl = $this->getDi()->emailTemplateTable->getExact(
            $this->getParam('name'), $copy_from
        );

        if (!$sourceTpl) {
            throw new Am_Exception_InputError(___('Trying to copy from unexisting template : %s', $copy_from));
        }

        $sourceTpl->lang = $this->getParam('lang', $this->getDefaultLang());

        return $sourceTpl;
    }

    protected function createForm($name, $label)
    {
        $form = new Am_Form_Admin('EmailTemplate');

        $form->addHtml('info')
            ->setLabel(___('Template'))
            ->setHtml(
                sprintf('<div><strong>%s</strong><br /><small>%s</small></div>',
                    $this->escape($name), $this->escape($label)));

        $form->addHidden('name');

        $langOptions = $this->getLanguageOptions(
                $this->getDi()->getLangEnabled()
        );
        $lang = $form->addSelect('lang')
                ->setId('lang')
                ->setLabel(___('Language'))
                ->loadOptions($langOptions);
        if (count($langOptions) == 1)
            $lang->toggleFrozen(true);
        $lang->addRule('required');

        if ($options = $this->getDi()->emailTemplateLayoutTable->getOptions()) {
            $form->addSelect('email_template_layout_id')
                ->setLabel(___('Layout'))
                ->loadOptions(array(''=>___('No Layout')) + $options);
        }

        $tt = Am_Mail_TemplateTypes::getInstance()->find($name);
        if($tt && !empty($tt['isAdmin'])) {
            $form->addMagicSelect('_admins')
                ->setLabel(___('Admin Recipients'))
                ->loadOptions($this->getAdminOptions())
                ->addRule('required');

        } else {
            $form->addText('bcc', array('class' => 'el-wide', 'placeholder' => ___('Email Addresses Separated by Comma')))
                ->setLabel(___("BCC\n" .
                    "blind carbon copy allows the sender of a message to conceal the person entered in the Bcc field from the other recipients"))
                ->addRule('callback', ___('Please enter valid e-mail addresses'), array('Am_Validate', 'emails'));
        }

        $form->addElement(new Am_Form_Element_MailEditor($name));

        $form->addHidden('label')
            ->setValue($label);

        $this->getDi()->hook->call(Am_Event::EMAIL_TEMPLATE_INIT_FORM, array('form' => $form));

        return $form;
    }

    protected function createActionsForm(EmailTemplate $tpl)
    {
        $form = new Am_Form_Admin('EmailTemplate_Actions');

        $form->addHidden('name')
            ->setValue($tpl->name);

        $langOptions = $this->getLanguageOptions(
                $this->getDi()->emailTemplateTable->getLanguages(
                    $tpl->name, null, $tpl->lang
                )
        );

        if (count($langOptions)) {
            $lang_from = $form->addSelect('copy_from')
                    ->setId('another_lang')
                    ->setLabel(___('Copy from another language'))
                    ->loadOptions(array('0' => '--' . ___('Please choose') . ' --') + $langOptions)
                    ->setValue(0);
        }

        if (isset($tpl->lang) && $tpl->lang)
        {
            $form->addHidden('lang')
                ->setValue($tpl->lang);
        }

        $form->addHidden('label')
            ->setValue($this->getParam('label'));

        //we do not show action's form if there is not any avalable action
        if (!count($langOptions))
        {
            $form = null;
        }

        return $form;
    }

    protected function createPendingNotificationRulesForm($name, $label)
    {
        $form = new Am_Form_Admin('EmailTemplate');

        $form->addHtml('info')
            ->setLabel(___('Template'))
            ->setHtml(
                sprintf('<div><strong>%s</strong><br /><small>%s</small></div>',
                    $this->escape($name), $this->escape($label)));

        if ($options = $this->getDi()->emailTemplateLayoutTable->getOptions()) {
            $form->addSelect('email_template_layout_id')
                ->setLabel(___('Layout'))
                ->loadOptions(array(''=>___('No Layout')) + $options);
        }

        $tt = Am_Mail_TemplateTypes::getInstance()->find($name);
        if($tt && !empty($tt['isAdmin']))
        {
            $form->addMagicSelect('_admins')
                ->setLabel(___('Admin Recipients'))
                ->loadOptions($this->getAdminOptions())
                ->setId('_admins')
                ->addRule('required');
        } else {
            $form->addText('bcc', array('class' => 'el-wide', 'placeholder' => ___('Email Addresses Separated by Comma')))
                ->setLabel(___("BCC\n" .
                    "blind carbon copy allows the sender of a message to conceal the person entered in the Bcc field from the other recipients"))
                ->addRule('callback', ___('Please enter valid e-mail addresses'), array('Am_Validate', 'emails'));
        }

        $form->addHidden('name');

        $form->addMagicSelect('days')
            ->setLabel(___('Days to Send'))
            ->loadOptions($this->getDayOptions());

        $g = $form->addFieldset()
            ->setLabel('Optional Conditions');

        $g->addMagicSelect('_conditions_pid', array('class' => 'row-highlight'))
            ->setLabel(___("Conditions by Product\n" .
                'notification will be sent in case of one of selected products exits in invoice, keep empty if you want to send for any'))
            ->loadOptions($this->getProductConditionOptions());
        $g->addHtml(null, array('class' => 'row-highlight'))->setHtml("<strong>AND</strong>");
        $g->addMagicSelect('_conditions_paysys_id', array('class' => 'row-highlight'))
            ->setLabel(___('Conditions by Payment System') . "\n" . ___('notification will be sent in case of one of selected payment system was used for invoice, keep empty if you want to send for any'))
            ->loadOptions($this->getPaysysConditionOptions());

        $form->addElement(new Am_Form_Element_MailEditor($name, array('upload-prefix'=>'email-pending')));

        $form->addHidden('label')
            ->setValue($label);

        $this->getDi()->hook->call(Am_Event::EMAIL_TEMPLATE_INIT_FORM, array('form' => $form));

        return $form;
    }

    protected function sortDays(& $days)
    {
        usort($days, function($el1, $el2) {
            if ($el1 && substr($el1, -1) != 'h')
                $el1 = 'z' . $el1;
            if ($el2 && substr($el2, -1) != 'h')
                $el2 = 'z' . $el2;
            return strcmp($el1, $el2);
        });
    }

    protected function prepareAttachments($att, $isReverse = false)
    {
        if ($isReverse) {
            $att = (!($att == '' || is_null($att)) ? explode(',', $att) : array());
            $att = array_filter($att, function($el) {return $el != -1;});
            return $att;
        } else {
            if (is_array($att)) {
                $att = array_filter($att, function($el) {return $el != -1;});
            }
            return ((is_array($att) && $att) ? implode(',', $att) : null);
        }
    }

    protected function getLanguageOptions($languageCodes)
    {
        $languageNames = $this->getDi()->languagesListUser;
        $options = array();
        foreach ($languageCodes as $k) {
            list($k, ) = explode('_', $k);
            $options[$k] = "[$k] " . $languageNames[$k];
        }
        return $options;
    }

    protected function getDefaultLang()
    {
        list($k, ) = explode('_', $this->getDi()->app->getDefaultLocale());
        return $k;
    }

    protected function getDefaultNotificationTemplate($name)
    {
        switch ($name) {
            case 'pending_to_user' :
                $txt = <<<CUT
Thank you for signup! Your payment status is PENDING.

%invoice_text%

You can use this link to complete your order
%paylink%

Your User ID: %user.login%

Your may log in to your member page at:
%root_url%/member
and check your subscription status.
CUT;
                break;
            case 'pending_to_admin' :
                $txt = <<<CUT
Pending Payment
----------------
User: %user.name_f% %user.name_l% <%user.email%>

%invoice_text%
CUT;
                break;
            default:
                $txt = '';
        }

        return array(
            'email_template_layout_id' => $name == 'pending_to_user' ? 1 : 2,
            'subject' => '%site_title%: Pending Payment',
            'txt' => $txt,
            'format' => 'text',
            'attachments' => null,
            'conditions' => null,
            'days' => null,
            'recipient_admins' => null

        );
    }

    protected function getJs($showOffer = false)
    {

        $offerText = json_encode((nl2br(___("This email template is empty in given language.\n" .
                        "Press [Copy] to copy template from default language [English]\n" .
                        "Press [Skip] to type it manually from scratch."))));
        $copy = ___("Copy");
        $skip = ___("Skip");
        if ($showOffer) {
            $jsOffer = <<<CUT
var div = jQuery('<div><div>');
div.append($offerText+"<br />")
jQuery('body').append(div);
div.dialog({
        autoOpen: true,
        modal : true,
        title : "",
        width : 350,
        position : {my: "center", at: "center", of: window},
        buttons: {
            "$copy" : function() {
                jQuery("#another_lang").val('en');
                jQuery("#another_lang").closest('form').ajaxSubmit({
                    success : function(data) {
                        jQuery('#email-template-popup').empty().append(data);
                    }
                });
                jQuery(this).dialog("close");
            },
            "$skip" : function() {
                jQuery(this).dialog("close");
            }
        },
        close : function() {
            div.remove();
        }
    });
CUT;
        } else {
            $jsOffer = '';
        }

        return <<<CUT
<script type="text/javascript">
(function($){
setTimeout(function(){
    jQuery("#lang").change(function(){
        var importantVars = new Array(
            'lang', 'name', 'label'
        );
        jQuery.each(this.form, function() {
            if (jQuery.inArray(this.name, importantVars) == -1) {
                if (this.name == 'format') {
                    this.selectedIndex = null;
                } else {
                    this.value='';
                }
            }
        })
        jQuery(this.form).ajaxSubmit({
                        success : function(data) {
                            jQuery('#email-template-popup').empty().append(data);
                        }
                    });
    });

    jQuery("#another_lang").change(function(){
        if (this.selectedIndex == 0) return;
        jQuery(this.form).ajaxSubmit({
                        success : function(data) {
                            jQuery('#email-template-popup').empty().append(data);
                        }
                    });
    });

    $jsOffer
}, 100);

})(jQuery)
</script>
CUT;
    }
}