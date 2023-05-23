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
 *
 * @todo check with utf-8
 * @todo check queue
 * @todo check parallel working (locking?)
 *
 */

class AdminEmailController extends Am_Mvc_Controller
{
    /** @var Am_Query_Ui */
    protected $searchUi;
    protected $_attachments = array();
    protected $queue_id;
    /** @var EmailSent */
    protected $saved;
    protected $form;
    protected $tagOptions;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_EMAIL);
    }

    function preDispatch()
    {
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);
        $this->setActiveMenu('users-email');
        if ($this->queue_id = $this->getFiltered('queue_id')) {
            $this->saved = $this->getDi()->emailSentTable->load($this->queue_id);
            $this->_request->fromArray($this->saved->unserialize());
        } elseif ($id = $this->getInt('resend_id')) {
            $this->saved = $this->getDi()->emailSentTable->load($id);
            unset($_GET['resend_id']);
            $_POST = array();
            $this->getDi()->request->setPost($this->saved->unserialize());
            unset($_POST['_save_']);
            $this->getRequest()->fromArray($_POST);
        }
        $this->_request->set('format', $this->getParam('format', 'html'));
        $this->searchUi = new Am_Query_Ui;
        $this->searchUi->addDefaults();
        $this->searchUi->setFromRequest($this->_request);
    }

    function renderUserUrl(User $user)
    {
        $url = $this->getView()->userUrl($user->user_id);
        return sprintf('<td><a href="%s" target="_blank">%s</a></td>',
            $this->escape($url), $this->escape($user->login));
    }

    function browseUsersAction()
    {
        $withWrap = (bool) $this->_request->get('_u_wrap');
        unset($_GET['_u_wrap']);

        $ds = $this->searchUi->getActive()->getQuery();
        $grid = new Am_Grid_ReadOnly('_u', ___('Selected for E-Mailing'), $ds,
                $this->_request, $this->view);
        if ($withWrap)
            $grid->isAjax(false);
        $grid->setCountPerPage(10);
        $grid->addField('login', ___('Username'))->setRenderFunction(array($this, 'renderUserUrl'));
        $grid->addField('name_f', ___('First Name'));
        $grid->addField('name_l', ___('Last Name'));
        $grid->addField('email', ___('E-Mail Address'));
        $grid->run($this->getResponse());
    }

    function getMailAction()
    {
        $mail = $this->getDi()->emailSentTable->load($this->getParam('id'));
        switch ($mail->format) {
            case 'text':
                $bodyRendered = sprintf('<pre style="background:white; padding:.5em">%s</pre>',
                    Am_Html::escape($mail->body));
                break;
            case 'html':
                $bodyRendered = sprintf('<iframe srcdoc="%s" style="border:none" width="100%%" onload="this.style.height=this.contentDocument.body.scrollHeight +\'px\';"></iframe>',
                    Am_Html::escape($mail->body));
                break;
        }
        echo $bodyRendered;
    }

    /**
     * For Admin CP->Setup->Email 'test' function
     */
    function testAction()
    {
        check_demo();

        $config = $this->getDi()->config;

        foreach ($this->getRequest()->toArray() as $k => $v) {
            $config->set($k, strip_tags($v));
        }

        $m = $this->getDi()->mail;
        $m->addTo($this->getParam('email'), 'Test E-Mail')
            ->setSubject('Test E-Mail Message from aMember ')
            ->setBodyText(sprintf(<<<CUT
This is a test message sent from aMember CP

URL: %s
Email Sending Method: %s
Date/Time: %s
CUT
            , $this->getDi()->rurl('', false), $config->get('email_method'), amDatetime('now')));
        $m->setPeriodic(Am_Mail::ADMIN_REQUESTED);
        $m->setPriority(Am_Mail::PRIORITY_HIGH);
        try {
            $m->send(new Am_Mail_Queue($config));
        } catch (Exception $e) {
            echo '<span class="error">' . ___('Error during e-mail sending') . ': ' . get_class($e) . ':' . $e->getMessage() . '</span>';
            return;
        }

        $f = current(Am_Mail::getDefaultFrom());
        $e = htmlentities($this->getParam('email'));
        $tm = date('Y-m-d H:i:s');
        print ___('<p>Message has been sent successfully. Please wait 2 minutes and check the mailbox <em>%s</em>.<br />' .
                'There must be a message with subject [Test E-Mail]. Do not forget to check <em>Spam</em> folder.</p>' .
                '<p>If the message does not arrive shortly, contact your webhosting support and ask them to find <br />' .
                'in <strong>mail.log</strong> what happened with a message sent from <em>%s</em> to <em>%s</em> at %s</p>',
                $e, $f, $e, $tm);
    }

    function getAttachments()
    {
        if (!$this->_request->getParam('files'))
            return array();
        if (!$this->_attachments) {
            $this->_attachments = array();
            foreach ($this->getDi()->uploadTable->findByIds($this->getParam('files'), 'email') as $f) {
                /* @var $f Upload */
                $at = new Zend_Mime_Part(file_get_contents($f->getFullPath(), 'r'));
                $at->type = $f->getType();
                $at->filename = $f->getName();
                $at->disposition = Zend_Mime::DISPOSITION_ATTACHMENT;
                $at->encoding = Zend_Mime::ENCODING_BASE64;
                $this->_attachments[] = $at;
            }
        }
        return $this->_attachments;
    }

    function createSendSession()
    {
        $saved = $this->getDi()->emailSentTable->createRecord();
        $saved->admin_id = $this->getDi()->authAdmin->getUserId();

        $saved->serialize($this->getRequest()->toArray());
        $saved->count_users = $this->searchUi->getFoundRows();
        $saved->desc_users = $this->searchUi->getActive()->getDescription();
        $saved->sent_users = 0;
        $saved->is_cancelled = 0;
        $saved->newsletter_ids = implode(',', array_filter(array_map('intval', $this->searchUi->getTargetListIds())));
        $saved->insert();
        $this->saved = $saved;
    }

    function sendRedirect()
    {
        $done = $this->saved->sent_users;
        $total = $this->saved->count_users;
        $url = $this->getUrl(null, 'send', null, array('queue_id' => $this->saved->pk()));
        $text = $total > 0 ? (___('Sending e-mail (sent to %d from %d)', $done, $total) . '. ' . ___('Please wait')) :
            ___('E-Mail sending started');
        $text .= '...';
        $this->redirectHtml($url, $text, ___('E-Mail Sending') . '...', false, $done, $total);
    }

    function sendComplete()
    {
        $this->saved->updateQuick('tm_finished', $this->getDi()->sqlDateTime);
        $total = $this->saved->count_users;
        $this->view->assign('title', ___('Email Sent'));
        ob_start();
        $queue_id = $this->getFiltered('queue_id');
        print '<div class="info">';
        __e('E-Mail has been successfully sent to %s customers. E-Mail Batch ID is %s', $total, $queue_id);
        print '. ';
        $url = $this->getUrl(null, 'index');
        print '<a href="' . $url . '">' . ___('Send New E-Mail') . '</a></div>';
        $this->view->assign('content', ob_get_clean());
        $this->view->display('admin/layout.phtml');
    }

    protected function getReplyToOptions()
    {
        $op = array();
        $op['default'] = Am_Html::escape(sprintf('%s <%s>',
            $this->getConfig('admin_email_name', $this->getConfig('site_title')),
            $this->getConfig('admin_email_from', $this->getConfig('admin_email'))));
        foreach (Am_Di::getInstance()->adminTable->findBy() as $admin) {
           $op['admin-' . $admin->pk()] = Am_Html::escape(sprintf('%s <%s>', $admin->getName(), $admin->email));
        }
        $op['other'] = ___('Other') . ':';
        return $op;
    }

    function createForm()
    {
        $form = new Am_Form_Admin('am-form-email');
        $form->setDataSources(array($this->getRequest()));
        $form->setAction($this->getUrl(null, 'preview'));

        if ($options = $this->getDi()->emailTemplateLayoutTable->getOptions()) {
            $form->addSelect('email_template_layout_id')
                ->setLabel(___('Layout'))
                ->loadOptions(array(''=>___('No Layout')) + $options);
        }

        $gr = $form->addGroup()
            ->setLabel(___("Reply To\n" .
                "mailbox for replies to message"))
            ->setSeparator(' ');

        $sel = $gr->addSelect('reply_to')
            ->loadOptions($this->getReplyToOptions());
        $id = $sel->getId();

        $gr->addText('reply_to_other', array('placeholder' => ___('Email Address')))
            ->setId($id.'-other')
            ->persistentFreeze(true); // ??? why is it necessary? but it is
        $gr->addScript()
            ->setScript(<<<CUT
jQuery('#$id').change(function(){
   jQuery('#{$id}-other').toggle(jQuery(this).val() == 'other');
}).change();
CUT
                );

        $subj = $form->addText('subject', array('class' => 'el-wide'))
                ->setLabel(___('Email Subject'));
        $subj->persistentFreeze(true); // ??? why is it necessary? but it is
        $subj->addRule('required', ___('Subject is required'));
//        $arch = $form->addElement('advcheckbox', 'do_archive')->setLabel("Archive Message\n" . 'if you are sending it to newsletter subscribers');
        $format = $form->addGroup(null)->setLabel(___('E-Mail Format'));
        $format->setSeparator(' ');
        $format->addRadio('format', array('value' => 'html'))->setContent(___('HTML Message'));
        $format->addRadio('format', array('value' => 'text'))->setContent(___('Plain-Text Message'));

        $group = $form->addGroup('', array('id' => 'body-group', 'class' => 'no-label'))
                ->setLabel(___('Message Text'));
        $group->addStatic()->setContent('<div class="mail-editor">');
        $group->addStatic()->setContent('<div class="mail-editor-element">');
        $group->addElement('textarea', 'body', array('id' => 'body-0', 'rows' => '15', 'class' => 'el-wide'));
        $group->addStatic()->setContent('</div>');

        $group->addStatic()->setContent('<div class="mail-editor-element">');
        $this->tagsOptions = Am_Mail_TemplateTypes::getInstance()->getTagsOptions('send_signup_mail');
        $tagsOptions = array();
        foreach ($this->tagsOptions as $k => $v) {
            $tagsOptions[$k] = "$k - $v";
        }
        $sel = $group->addSelect('', array('id' => 'insert-tags',));
        $sel->loadOptions(array_merge(array('' => ''), $tagsOptions));
        $group->addStatic()->setContent('</div>');
        $group->addStatic()->setContent('</div>');

        $fileChooser = new Am_Form_Element_Upload('files', array('multiple' => '1'), array('prefix' => 'email'));
        $form->addElement($fileChooser)->setLabel(___('Attachments'));

        foreach ($this->searchUi->getHidden() as $k => $v) {
            $form->addHidden($k)->setValue($v);
        }

        $id = 'body-0';
        $vars = "";
        foreach ($this->tagsOptions as $k => $v) {
            $vars .= sprintf("[%s, %s],\n", json_encode($v), json_encode($k));
        }
        $vars = trim($vars, "\n\r,");

        if($this->queue_id)
            $form->addHidden('queue_id')->setValue($this->queue_id);

        $form->addScript('_bodyscript')->setScript(<<<CUT
jQuery(function(){
    jQuery('select#insert-tags').change(function(){
        var val = jQuery(this).val();
        if (!val) return;
        jQuery("#$id").insertAtCaret(val);
        jQuery(this).prop("selectedIndex", -1);
    });

    if (CKEDITOR.instances["$id"]) {
        delete CKEDITOR.instances["$id"];
    }
    var editor = null;
    jQuery("input[name='format']").change(function()
    {
        if (window.configDisable_rte) return;
        if (!this.checked) return;
        if (this.value == 'html')
        {
            if (!editor) {
                editor = initCkeditor("$id", { placeholder_items: [
                    $vars
                ],entities_greek: false});
            }
            jQuery('select#insert-tags').hide();
        } else {
            if (editor) {
                editor.destroy();
                editor = null;
            }
            jQuery('select#insert-tags').show();
        }
    }).change();
});

CUT
        );

        $this->getDi()->hook->call(Am_Event::MAIL_SIMPLE_INIT_FORM, array('form' => $form));

        $buttons = $form->addGroup('buttons');
        $buttons->addSubmit('send', array('value' => ___('Preview')));

        return $form;
    }

    /** @return Am_Form_Admin */
    function getForm()
    {
        if (!$this->form)
            $this->form = $this->createForm();
        return $this->form;
    }

    function testEmailAction()
    {
        if ($r = $this->getDi()->userTable->findFirstByLogin($this->getParam('_test_email'))) {
            $this->doSend($r->toArray());
            $r = array(
                'status' => 'ok',
                'msg' => ___("Email has been sent.")
            );
        } else {
            $r = array(
                'status' => 'error',
                'msg' => ___("User with such login is not found.")
            );
        }
        $this->getResponse()->ajaxResponse($r);
    }

    function previewAction()
    {
        $form = $this->getForm();
        if ($this->form->validate()) {
            $form->toggleFrozen(true);

            $form->setAction($this->getUrl(null, 'send'));
            $el = $form->getElementById('send-0')->setAttribute('value', ___('Send E-Mail Message'));
            $el->getContainer()->setSeparator(' ');
            $el->getContainer()->addElement('inputbutton', 'back', array(
                'value' => 'Back',
                'class' => 'form-back',
                'data-href' => $this->getUrl(null, 'index')));

            // remove text and add hidden instead
            $group = $form->getElementById('body-group');
            $group->removeChild($form->getElementById('body-0'));
            $group->addHidden('body');
            $body = $this->getParam('body');
            if($id_ = $this->getParam('email_template_layout_id'))
            {
                $layout_ = $this->getDi()->emailTemplateLayoutTable->load($id_);
                $tpl_ = new Am_SimpleTemplate();
                $tpl_->assign('content',$body);
                $body = $tpl_->render($layout_->layout);
            }
            $html = json_encode($body);
            // now add it for display

            $form->addScript('_bodyscript')->setScript(<<<CUT
jQuery(function(){
    var html = $html;
    // if format == 'text' add <br> after newlines
    if (jQuery("input[name='format']").val() == 'text')
    {
        jQuery("#row-body-group .element.group").html('<pre>').find('pre').text(html);
    }
    else
    {
        var output = jQuery("#row-body-group .element.group");
        var iframe = document.createElement( "iframe" );
        iframe.setAttribute("id", "am_preview_iframe");
        output.append( iframe );
        var doc = (iframe.contentWindow || iframe.contentDocument).document;
		doc.write(html);
		doc.close();
        jQuery("#am_preview_iframe").css('height',jQuery("#am_preview_iframe").contents().height())
            .css('width','100%').css('border','none');
    }

    jQuery("input.form-back").click(function(){
        jQuery(this).closest('form').prop('action', jQuery(this).data('href')).submit();
    });
});
CUT
            );

            $s = json_encode(___('Send Test E-Mail'));
            $se = json_encode(___('Sending Test E-Mail...'));
            $gr = $form->addGroup(null, array('class' => 'row-highlight'))
                ->setLabel(___("Send Test Email\nUsername of existing user to send test email"));
            $gr->setSeparator(" ");
            $gr->addText('_test_email', array('placeholder' => 'Username'));
            $gr->addInputButton('_', array('value' => ___('Send Test E-Mail'), 'id' => 'send-test-email'));
            $form->addScript()
                ->setScript(<<<CUT
jQuery("input[name=_test_email]").autocomplete({
    minLength: 2,
    source: amUrl("/admin-users/autocomplete")
});
jQuery(function(){
    jQuery('#send-test-email').click(function(){
        jQuery(this).prop('disabled', true);
        jQuery(this).val($se);
        jQuery.post(amUrl("/admin-email/test-email"), jQuery(this).closest('form').serialize(), function(r){
            jQuery('#send-test-email').prop('disabled', false);
            jQuery('#send-test-email').val($s);
            if (r.status == 'ok') {
                flashMessage(r.msg);
            } else {
                flashError(r.msg);
            }
        });
    });
});
CUT
                );

        }
        return $this->indexAction();
    }

    function indexAction()
    {
        $this->view->headScript()->appendFile($this->view->_scriptJs("htmlreg.js"));
        $form = $this->getForm();

        if ($this->form->isSubmitted())
            $this->form->validate();

        $this->view->form = $form;
        $this->view->users_found = $this->searchUi->getFoundRows();
        if ($this->_request->getActionName() != 'preview')
            $this->view->search = $this->searchUi->render();
        else
            $this->view->search = '<br /><br /><br />';
        $this->view->filterCondition = $this->searchUi->getActive()->getDescription();

        $this->view->display('admin/email.phtml');
    }

    function historyRowsAction()
    {
        $q = new Am_Query($this->getDi()->emailSentTable);
        $q->leftJoin('?_admin', 'a', 't.admin_id=a.admin_id');
        $q->addField('a.login', 'admin_login');
        $q->setOrder('email_sent_id', 'DESC');
        // dirty hack
        $withWrap = (bool) $this->_request->get('_h_wrap');
        unset($_GET['_h_wrap']);
        $grid = new Am_Grid_Editable('_h', ___('E-Mails History'), $q, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_EMAIL);
        if ($withWrap)
            $grid->isAjax(false);
        $grid->setCountPerPage(20);
        $grid->addField(new Am_Grid_Field_Date('tm_added', ___('Started')));
        $grid->addField('subject', ___('Subject'));
        $grid->addField(new Am_Grid_Field_Expandable('email_sent_id', ___('Mail'), false, '', null, '20%'))
            ->setAjax($this->getDi()->url('admin-email/get-mail?id={email_sent_id}', false))
            ->setSafeHtml(true);
        $grid->addField('admin_login', ___('Sender'));
        $grid->addField('count_users', ___('Total'));
        $grid->addField('sent_users', ___('Sent'));
        $grid->addField('desc_users', ___('To'))
            ->setAttrs(array('class' => 'break'));
        $grid->actionsClear();
        $url = $this->getDi()->url('admin-email',array('resend_id'=>'__ID__'),false);
        $grid->actionAdd(new Am_Grid_Action_Url('resend', ___('Resend'), $url))->setTarget('_top');
        $url = $this->getDi()->url('admin-email/send',array('queue_id'=>'__ID__'),false);
        $grid->actionAdd(new Am_Grid_Action_Url('continue', ___('Continue'), $url))
            ->setTarget('_top')
            ->setIsAvailableCallback(array($this, 'needContinueLink'));
        if ($this->getDi()->authAdmin->getUser()->isSuper()) {
            $grid->actionAdd(new Am_Grid_Action_Delete());
        }
        $grid->run($this->getResponse());
    }

    function needContinueLink(EmailSent $s)
    {
        return $s->count_users > $s->sent_users;
    }

    function sendAction()
    {
        if ($this->getParam('back'))
            return $this->_redirect('admin-email');

        check_demo();

        if (!$this->saved) {
            $this->createSendSession();
            return $this->sendRedirect();
        }

        $batch = new Am_BatchProcessor(array($this, 'batchSend'));
        $breaked = !$batch->run($this->saved);
        $breaked ? $this->sendRedirect() : $this->sendComplete();
    }

    function doSend($r)
    {
        $r['name'] = $r['name_f'] . ' ' . $r['name_l'];
        $r['unsubscribe_link'] = Am_Mail::getUnsubscribeLink($r['email'], Am_Mail::LINK_USER);
        $m = $this->getDi()->mail;
        $m->setPeriodic(Am_Mail::ADMIN_REQUESTED);
        $m->addHeader('X-Amember-Queue-Id', $this->_request->getFiltered('queue_id'));
        $m->addUnsubscribeLink(Am_Mail::LINK_USER);
        $m->addTo($r['email'], $r['name']);

        if ($reply_to = $this->getParam('reply_to')) {
            switch ($reply_to) {
                case 'default' :
                    $email = false;
                    break;
                case 'other' :
                    $email = $this->getParam('reply_to_other');
                    $name = null;
                    break;
                default:
                    preg_match('/^admin-(\d+)$/', $reply_to, $match);
                    $admin = $this->getDi()->adminTable->load($match[1], false);
                    if ($admin) {
                        $email = $admin->email;
                        $name = $admin->getName();
                    }
                    break;
            }
            if ($email = filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $m->setReplyTo($email, $name);
            }
        }

        $subject = $this->getParam('subject');
        $body = $this->getParam('body');

        $tpl = new Am_SimpleTemplate();
        $tpl->assignStdVars();
        if ((strpos($body, '%user.unsubscribe_link%') !== false) ||
            (strpos($subject, '%user.unsubscribe_link%') !== false)) {

            $r['unsubscribe_link'] = Am_Mail::getUnsubscribeLink($r['email'], Am_Mail::LINK_USER);
        }
        $tpl->user = $r;
        $this->getDi()->hook->call(Am_Event::MAIL_SIMPLE_TEMPLATE_BEFORE_PARSE, array(
            'template' => $tpl,
            'body' => $body,
            'subject' => $subject,
            'mail' => $m,
            'request' => $this->getRequest()
        ));
        $subject = $tpl->render($subject);
        $body = $tpl->render($body);
        if ($this->getParam('email_template_layout_id') &&
            ($layout = $this->getDi()->emailTemplateLayoutTable->load($this->getParam('email_template_layout_id', false)))) {

            $tpl->assign('content', $body);
            $body = $tpl->render($layout->layout);
        }

        $m->setSubject($subject);
        if ($this->getParam('format') == 'text') {
            $m->setBodyText($body);
        } else {
            $text = strip_tags($body);
            $html = strpos($body, '<html') === false ?
                "<html><head><title>$subject</title></head><body>$body</body></html>" :
                $body;
            $m->setBodyHtml($html);
            $m->setBodyText($text);
        }
        foreach ($this->getAttachments() as $at)
            $m->addAttachment($at);
        try {
            $m->send();
        } catch (Zend_Mail_Exception $e) {
            trigger_error("Error happened while sending e-mail to $r[email] : " . $e->getMessage(), E_USER_WARNING);
        }
    }

    function batchSend(&$context, Am_BatchProcessor $batch)
    {
        if ($this->saved->count_users <= $this->saved->sent_users)
            return true; // we are done;
        $q = $this->searchUi->query($this->saved->sent_users, 100);

        $i = 0;
        $db = $this->getDi()->db;
        $foundrows = false;
        while ($r = $db->fetchRow($q)) {
            $foundrows = true;
            if (!$batch->checkLimits()) return false;
            $this->saved->updateQuick(array('last_email' => $r['email'], 'sent_users' => $this->saved->sent_users + 1));
            if ($r['email'] == '')
                continue;

            $this->doSend($r);
        }

        $this->getDi()->db->freeResult($q);
        if(!$foundrows)
            return true;
        if ($this->saved->count_users <= $this->saved->sent_users)
            return true; // we are done;
    }
}