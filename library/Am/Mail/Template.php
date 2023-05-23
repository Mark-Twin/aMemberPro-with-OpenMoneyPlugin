<?php

/**
 * Email template class - send mail based on saved email template
 * @method Am_Mail_Template setUser(User $user) provides fluent interface
 * @package Am_Mail_Template
 */
class Am_Mail_Template extends ArrayObject
{
    const TO_ADMIN = '|TO-ADMIN|';
    public $admins;
    /** @var array */
    protected $template = array();
    /** @var Am_Mail */
    protected $mail,
        $_mailPeriodic = Am_Mail::REGULAR,
        $is_parsed = false,
        $subject = null,
        $text = null,
        $html = null,
        $bcc = null,
        $cc = array();


    public function __construct($tplId = null, $lang = null)
    {
        $this->setFlags(self::ARRAY_AS_PROPS);
        $this->setArray(array(
            'site_title' => Am_Di::getInstance()->config->get('site_title'),
            'root_url'   => ROOT_URL,
            'admin_email' => Am_Di::getInstance()->config->get('admin_email'),
        ));
    }

    public function __call($name, $arguments)
    {
        if (strpos($name, 'set')===0) {
            $var = lcfirst(substr($name, 3));
            $this[$var] = $arguments[0];
            return $this;
        }
        trigger_error("Method [$name] does not exists in " . __CLASS__, E_USER_ERROR);
    }

    public function setArray(array $vars)
    {
       foreach ($vars as $k => $v)
           $this->$k = $v;
       return $this;
    }

    function setBcc($bcc)
    {
        $this->bcc = $bcc;
    }

    public function addCc($email, $name='')
    {
        if ($name) {
            $this->cc[$name] = $email;
        } else {
            $this->cc[] = $email;
        }
    }

    function setTemplate($format, $subject, $bodyText, $bodyHtml, $attachments, $id, $name, $layout = null)
    {
        // switch bodyText/bodyHtml based on format
        if (($format == 'text') && empty($bodyText)) {
            $bodyText = $bodyHtml;
            $bodyHtml = null;
        } elseif (($format == 'html') && empty($bodyHtml)) {
            $bodyHtml = $bodyText;
            $bodyText = null;
        }

        $this->template = array(
            'format' => $format,
            'subject' => $subject,
            'bodyText' => $bodyText,
            'bodyHtml' => $bodyHtml,
            'attachments' => $attachments,
            'id' => $id,
            'name' => $name,
            'layout' => $layout
        );
    }

    /** @return Am_Mail */
    function getMail()
    {
        if (!$this->mail)
            $this->mail = Am_Di::getInstance()->mail;;
        return $this->mail;
    }

    public function addTo($email, $name)
    {
        $this->getMail()->addTo($email, $name);
    }

    function parse()
    {
        Am_Di::getInstance()->hook->call(Am_Event::MAIL_TEMPLATE_BEFORE_PARSE, array(
            'template' => $this,
            'body' => !empty($this->template['bodyText']) ? $this->template['bodyText'] : $this->template['bodyHtml'],
            'subject' => $this->template['subject'],
            'mail' => $this->getMail()
        ));
        if($this->getMailPeriodic() == Am_Mail::REGULAR) $this->getMail()->addUnsubscribeLink(Am_Mail::LINK_USER);

        $this->subject = $this->_parse($this->template['subject']);

        $layout = $this->template['layout'];

        if ($_text = $this->template['bodyText']) {
            $this->text = $this->_parse($_text, $layout);
        }
        if ($_html = $this->template['bodyHtml']) {
            $html = $this->_parse($_html, $layout);
            $this->text = strip_tags($this->_parse($_html));
            $this->html = strpos($html, '<html') === false ?
                "<html><head><title>{$this->subject}</title></head><body>$html</body></html>" :
                $html;
        }

        $this->parseAttachments();
    }

    protected function parseAttachments()
    {
        if(in_array($this->template['name'],array(EmailTemplate::AUTORESPONDER,
            EmailTemplate::EXPIRE, EmailTemplate::PRODUCTWELCOME, EmailTemplate::PAYMENT)))
            $upload = new Am_Upload(Am_Di::getInstance(), EmailTemplate::ATTACHMENT_AUTORESPONDER_EXPIRE_FILE_PREFIX);
        elseif(in_array($this->template['name'],array(EmailTemplate::PENDING_TO_ADMIN, EmailTemplate::PENDING_TO_USER)))
            $upload = new Am_Upload(Am_Di::getInstance(), EmailTemplate::ATTACHMENT_PENDING_FILE_PREFIX);
        else
            $upload = new Am_Upload(Am_Di::getInstance(), EmailTemplate::ATTACHMENT_FILE_PREFIX);
        $upload->unserialize($this->template['attachments']);
        foreach ($upload->getUploads() as $file)
        {
            $f = @fopen($file->getFullPath(), 'r');
            if (!$f) {
                trigger_error("Could not open attachment [" . $file->getName() . "] for EmailTemplate#{$this->email_template_id}",
                    E_USER_WARNING);
                continue;
            }
            $this->getMail()->createAttachment($f, $file->getType(),
                    Zend_Mime::DISPOSITION_ATTACHMENT, Zend_Mime::ENCODING_BASE64, $file->getName());
        }
    }

    protected function _parse($text, $layout = null)
    {
        $tpl = new Am_SimpleTemplate();
        $tpl->assignStdVars();
        $tpl->assign($this->getArrayCopy());
        $tpl->assign(get_object_vars($this));
        $text = $tpl->render($text);
        if ($layout) {
            $tpl->assign('content', $text);
            $text = $tpl->render($layout);
        }
        return $text;
    }

    function send($recepient, $transport = null)
    {
        try {
            $this->_send($recepient, $transport);
        } catch (Exception $e) {
            // Catch all exceptions here. If there is an issue with template,
            // other parts of the script should not be affected.
            Am_Di::getInstance()->errorLogTable->log($e);
            trigger_error("Could not send message - error happened: " . $e->getMessage(), E_USER_WARNING);
        }
    }

    protected function _send($recepient, $transport = null)
    {
        if (!$this->template)
            throw new Am_Exception_InternalError("Template was not set in " . __METHOD__);

        $this->getMail()->clearRecipients();
        if ($this->bcc) {
            $this->getMail()->addBcc($this->bcc);
        }
        if ($this->cc) {
            $this->getMail()->addCc($this->cc);
        }
        if ($recepient instanceof User) {
            $this->getMail()->addTo($email = $recepient->email, $recepient->getName());
        } elseif ($recepient instanceof Admin) {
            $this->getMail()->addTo($email = $recepient->email, $recepient->getName());
        } elseif ($recepient===self::TO_ADMIN) {
            $name = Am_Di::getInstance()->config->get('site_title') . ' Admin';
            if($this->admins)
            {
                if(in_array(-1, $this->admins))
                    $this->addTo(Am_Di::getInstance()->config->get('admin_email'), Am_Di::getInstance()->config->get('site_title') . ' Admin');
                foreach (Am_Di::getInstance()->adminTable->loadIds($this->admins) as $admin) {
                    if (!$admin->is_disabled) {
                        $this->getMail()->addTo($admin->email, $admin->getName());
                    }
                }

                if ($copyAdmin = Am_Di::getInstance()->config->get('copy_admin_email'))
                    foreach (preg_split("/[,;]/", $copyAdmin) as $copy)
                        if ($copy) $this->getMail()->addBcc($copy);
            } else {
                $this->getMail()->toAdmin();
            }
        } else {
            $this->getMail()->addTo($email = $recepient);
        }

        if (!$this->is_parsed) {
            $this->is_parsed = true;
            $this->parse();
            $this->getMail()->setSubject($this->subject);
        }
        $this->getMail()->setBodyText($this->text);
        if ($this->html) {
            $this->getMail()->setBodyHtml($this->html);
        }

        $this->getMail()->setPeriodic($this->getMailPeriodic());
        Am_Di::getInstance()->hook->call(Am_Event::MAIL_TEMPLATE_BEFORE_SEND, array(
            'template' => $this,
            'recepient' => $recepient
        ));
        $this->getMail()->send($transport);
    }

    /**
     * Shortcut to email subscribed admins
     */
    function sendAdmin()
    {
        $this->send(self::TO_ADMIN);
    }

    function getMailPeriodic()
    {
        return $this->_mailPeriodic;
    }

    function setMailPeriodic($periodic)
    {
        $this->_mailPeriodic = $periodic;
    }

    /**
     * @return Am_Mail_Template|null null if no template found
     */
    static function load($id, $lang = null, $throwException = false)
    {
        $di = Am_Di::getInstance();
        if(is_null($lang)) $lang = $di->locale->getLanguage();
        list($lang,) = explode('_', $lang);
        $et = $di->emailTemplateTable->findFirstExact($id, $lang);
        if ($et)
        {
            return self::createFromEmailTemplate($et);
        } elseif ($throwException)
            throw new Am_Exception_Configuration("No e-mail template found for [$id,$lang]");
    }

    /** @return Am_Mail_Template */
    static function createFromEmailTemplate(EmailTemplate $et)
    {
        $t = new self;
        $t->setTemplate(
            $et->format,
            $et->subject,
            $et->plain_txt,
            $et->txt,
            $et->attachments,
            $et->email_template_id . '-' . $et->name . '-' . $et->lang,
            $et->name,
            $et->getLayout()
        );

        $t->admins = array_filter(explode(',', $et->recipient_admins));
        $rec = Am_Mail_TemplateTypes::getInstance()->find($et->name);
        if ($rec) {
            $t->setMailPeriodic($rec['mailPeriodic']);
            if (!empty($rec['from'])) {
                $t->getMail()->setFrom($rec['from'][0], $rec['from'][1]);
            }
        }

        $bcc = $et->bcc ? array_map('trim', explode (',', $et->bcc)) : array();
        $t->setBcc($bcc);
        if ($et->reply_to && ($admin = $et->getDi()->adminTable->load($et->reply_to, false))) {
            $t->getMail()->setReplyTo($admin->email, $admin->getName());
        }

        return $t;
    }

    function getConfig()
    {
        return $this->template;
    }
}
