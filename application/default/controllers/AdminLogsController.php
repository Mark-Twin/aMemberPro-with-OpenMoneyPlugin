<?php

class Am_Grid_Filter_LogEntity extends Am_Grid_Filter_Abstract
{
    protected $varList = array('q', 'd1', 'd2');
    protected $dateFiled, $fields, $placeholder, $isTimestamp;

    function __construct($dateField, $fields, $placeholder)
    {
        $this->dateField = $dateField[0] == '@' ? substr($dateField, 1) : $dateField;
        $this->isTimestamp = $dateField[0] == '@';
        $this->fields = $fields;
        $this->placeholder = $placeholder;
    }

    protected function applyFilter()
    {
        class_exists('Am_Form', true);
        $query = $this->grid->getDataSource()->getDataSourceQuery();

        $callback = $this->isTimestamp ?
            function($_) {return amstrtotime($_);} :
            function($_) {return $_;};

        if ($d1 = $this->getParam('d1')) {
            $query->addWhere("t.{$this->dateField} >= ?",
                call_user_func($callback, Am_Form_Element_Date::convertReadableToSQL($d1) . ' 00:00:00'));
        }
        if ($d2 = $this->getParam('d2')) {
            $query->addWhere("t.{$this->dateField} <= ?",
                call_user_func($callback, Am_Form_Element_Date::convertReadableToSQL($d2) . ' 23:59:59'));
        }
        if ($q = $this->getParam('q')) {
            foreach ($this->fields as $field => $op) {
                if($field[0] == '#')//INT in the database
                {
                   if(!is_numeric($q))
                       continue;
                   else
                       $field = substr($field, 1);
                }
                $alias = null;
                if (preg_match('/^(.*)\.(.*)$/', $field, $_)) {
                    $alias = $_[1];
                    $field = $_[2];
                }
                $c = new Am_Query_Condition_Field($field, $op, $op == 'LIKE' ? "%$q%" : $q, $alias);
                if (!isset($condition))
                    $condition = $c;
                else
                    $condition->_or($c);
            }
            $query->add($condition);
        }
    }

    function renderInputs()
    {
        $prefix = $this->grid->getId();
        $d1 = Am_Html::escape($this->getParam('d1'));
        $d2 = Am_Html::escape($this->getParam('d2'));
        $q = Am_Html::escape($this->getParam('q'));
        $placeholder = Am_Html::escape($this->placeholder);

        $start = ___('Start Date');
        $end = ___('End Date');

        return <<<CUT
<input type="text" placeholder="$start" name="{$prefix}_d1" class='datepicker' style="width:80px" value="{$d1}" />
<input type="text" placeholder="$end" name="{$prefix}_d2" class='datepicker' style="width:80px" value="{$d2}" />
<input type="text" name="{$prefix}_q" value="{$q}" placeholder="$placeholder" />
CUT;
    }

    public function getTitle()
    {
        return '';
    }
}

class Am_Grid_Filter_LogEntity_Mail extends Am_Grid_Filter_LogEntity
{
    protected $varList = array('q', 'd1', 'd2', 's');

    function renderInputs()
    {
        return parent::renderInputs() . ' ' . $this->renderInputSelect('s', array(
            '' => ___('All'),
            1 => ___('Sent'),
            2 => ___('Not Sent')
        ));
    }

    protected function applyFilter()
    {
        parent::applyFilter();
        if ($status = $this->getParam('s')) {
            $cond = $status == 1 ? 'sent IS NOT NULL' : 'sent IS NULL';
            $this->grid->getDataSource()->getDataSourceQuery()
                ->addWhere($cond);
        }
    }
}

class AdminLogsController extends Am_Mvc_Controller_Pages
{
    public function initPages()
    {
        $admin = $this->getDi()->authAdmin->getUser();

        if ($admin->hasPermission(Am_Auth_Admin::PERM_LOGS))
            $this->addPage(array($this,'createErrors'), 'errors', ___('Errors'));

        if ($admin->hasPermission(Am_Auth_Admin::PERM_LOGS_ACCESS))
             $this->addPage(array($this, 'createAccess'), 'access', ___('Access'));

        if ($admin->hasPermission(Am_Auth_Admin::PERM_LOGS_INVOICE))
             $this->addPage(array($this, 'createInvoice'), 'invoice', ___('Invoice'));

        if ($admin->hasPermission(Am_Auth_Admin::PERM_LOGS_MAIL))
             $this->addPage(array($this, 'createMailQueue'), 'mailqueue', ___('Mail Queue'));

        if ($admin->hasPermission(Am_Auth_Admin::PERM_LOGS_ADMIN))
            $this->addPage(array($this, 'createAdminLog'), 'adminlog', ___('Admin Log'));
        if ($admin->hasPermission(Am_Auth_Admin::PERM_LOGS_DEBUG))
            $this->addPage(array($this,'createErrors'), 'debug', ___('Debug/Information'));
        
    }

    public function checkAdminPermissions(Admin $admin)
    {
        foreach(array(
            Am_Auth_Admin::PERM_LOGS,
            Am_Auth_Admin::PERM_LOGS_ACCESS,
            Am_Auth_Admin::PERM_LOGS_INVOICE,
            Am_Auth_Admin::PERM_LOGS_MAIL,
            Am_Auth_Admin::PERM_LOGS_ADMIN,
            Am_Auth_Admin::PERM_LOGS_DEBUG
            ) as $perm) {
            if ($admin->hasPermission($perm)) return true;
        }

        return false;
    }

    public function createErrors($id, $title, $controller)
    {
        $q = new Am_Query($id=='errors' ? $this->getDi()->errorLogTable : $this->getDi()->debugLogTable);
        $q->setOrder('log_id', 'desc');
        $g = new Am_Grid_ReadOnly('_error', $title, $q, $this->getRequest(), $this->view);
        $g->setPermissionId($id=='errors'?Am_Auth_Admin::PERM_LOGS : Am_Auth_Admin::PERM_LOGS_DEBUG);
        $g->addField(new Am_Grid_Field_Date('time', ___('Date/Time')));
        $g->addField(new Am_Grid_Field_Expandable('url', ___('URL'), true))
            ->setPlaceholder(Am_Grid_Field_Expandable::PLACEHOLDER_SELF_TRUNCATE_BEGIN)
            ->setMaxLength(25);
        $g->addField(new Am_Grid_Field('remote_addr', ___('IP')));
        $g->addField(new Am_Grid_Field_Expandable('error', ___('Message'), true, '', null, '45%'))
            ->setPlaceholder(Am_Grid_Field_Expandable::PLACEHOLDER_SELF_TRUNCATE_END)
            ->setMaxLength(500)
            ->setAttrs(array('class' => 'break'));
        $f = $g->addField(new Am_Grid_Field_Expandable('trace', ___('Trace')))
             ->setAjax($this->getDi()->url('admin-logs/get-'.($id=='errors'?'error' : 'debug').'-trace?id={log_id}', null,false));

        $g->setFilter(new Am_Grid_Filter_LogEntity('time', array(
            'url' => 'LIKE',
            'remote_addr' => 'LIKE',
            'referrer' => 'LIKE',
            'error' => 'LIKE',
        ), 'URL/IP/Referrrer/Error'));
        return $g;
    }

    function getErrorTraceAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission(Am_Auth_Admin::PERM_LOGS);
        $log = $this->getDi()->errorLogTable->load($this->getParam('id'));
        echo highlight_string($log->trace, true);
    }
    function getDebugTraceAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission(Am_Auth_Admin::PERM_LOGS);
        $log = $this->getDi()->debugLogTable->load($this->getParam('id'));
        echo highlight_string($log->trace, true);
    }

    public function createAccess()
    {
        $query = new Am_Query($this->getDi()->accessLogTable);
        $query->leftJoin('?_user', 'm', 't.user_id=m.user_id')
            ->addField("m.login", 'member_login')
            ->addField("CONCAT(m.name_f, ' ', m.name_l)", 'member_name');
        $query->setOrder('time', 'desc');
        $g = new Am_Grid_Editable('_access', ___('Access Log'), $query, $this->getRequest(), $this->view);
        $g->setPermissionId(Am_Auth_Admin::PERM_LOGS_ACCESS);
        $g->actionsClear();
        $g->addField(new Am_Grid_Field_Date('time', ___('Date/Time')));
        $g->addField(new Am_Grid_Field('member_login', ___('User'), true, '', array($this, 'renderAccessMember')));
        $g->addField(new Am_Grid_Field_Expandable('url', ___('URL')))
            ->setPlaceholder(Am_Grid_Field_Expandable::PLACEHOLDER_SELF_TRUNCATE_BEGIN)
            ->setMaxLength(25);
        $g->addField(new Am_Grid_Field('remote_addr', ___('IP')));
        $g->addField(new Am_Grid_Field_Expandable('referrer', ___('Referrer')))
            ->setPlaceholder(Am_Grid_Field_Expandable::PLACEHOLDER_SELF_TRUNCATE_BEGIN)
            ->setMaxLength(25);
        $g->setFilter(new Am_Grid_Filter_LogEntity('time', array(
            'remote_addr' => 'LIKE',
            'referrer' => 'LIKE',
            'url' => 'LIKE',
        ), 'URL/IP/Referrer'));
        $g->actionAdd(new Am_Grid_Action_Export);
        return $g;
    }

    public function createInvoice()
    {
        $query  = new Am_Query(new InvoiceLogTable);
        $query->addField("m.login", "login");
        $query->addField("m.user_id", "user_id");
        $query->addField("i.public_id");
        $query->leftJoin("?_user", "m", "t.user_id=m.user_id");
        $query->leftJoin("?_invoice", "i", "t.invoice_id=i.invoice_id");
        $query->setOrder('log_id', 'desc');

        $g = new Am_Grid_Editable('_invoice', ___('Invoice Log'), $query, $this->getRequest(), $this->view);
        $g->setPermissionId(Am_Auth_Admin::PERM_LOGS_INVOICE);

        $userUrl = new Am_View_Helper_UserUrl();

        $g->addField(new Am_Grid_Field_Date('tm', ___('Date/Time')));
        $g->addField(new Am_Grid_Field('invoice_id', ___('Invoice'), true, '', array($this, 'renderInvoice')));
        $g->addField(new Am_Grid_Field('login', ___('User')))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($userUrl->userUrl('{user_id}'), '_top'));
        $g->addField(new Am_Grid_Field('remote_addr', ___('IP')));
        $g->addField(new Am_Grid_Field('paysys_id', ___('Paysystem')));
        $g->addField(new Am_Grid_Field('title', ___('Title')));
        $g->addField(new Am_Grid_Field_Expandable('details', ___('Details'), false))
            ->setAjax($this->getDi()->url('admin-logs/get-invoice-details?id={log_id}',null,false));
        $g->actionsClear();
        $g->actionAdd(new Am_Grid_Action_InvoiceRetry('retry'));
        $g->setFilter(new Am_Grid_Filter_LogEntity('tm', array(
            'paysys_id' => 'LIKE',
            'title' => 'LIKE',
            'type' => 'LIKE',
            'details' => 'LIKE',
            'remote_addr' => '=',
            '#invoice_id' => '=',
            '#user_id' => '='
        ), ___('Filter by string or by invoice#/member#')));
        $g->actionAdd(new Am_Grid_Action_Group_Callback('retrygroup', ___("Repeat Action Handling"), array('Am_Grid_Action_InvoiceRetry', 'groupCallback')));
        $g->addCallback(Am_Grid_ReadOnly::CB_RENDER_STATIC, function(& $out, $grid) {
            $out .= <<<CUT
<style type="text/css">
<!--
._encodeddetailshead_ {
    cursor: pointer;
    position: relative;
    color: #34536e;
    display: inline-block;
    margin:0.5em 0;
}

._encodeddetailshead_:after {
    border-bottom: 1px #9aa9b3 dashed;
    content: '';
    height: 0;
    left: 0;
    right: 0;
    bottom: 1px;
    position: absolute;
}

._encodeddetails_ {
    background: #ededed;
    padding-left: 40px;
    margin-left:-40px;
}

._encodeddetailscontent_ {display:none}
-->
</style>
<script type="text/javascript">
jQuery('.grid-wrap').on('click', '._encodeddetailshead_', function(){
    jQuery(this).next().toggle();
})
</script>
CUT;
        });
        return $g;
    }

    public function renderInvoice($record)
    {
        return $record->invoice_id ?
                sprintf('<td><a class="link" target="_top" href="%s">%s/%s</a></td>',
                    $this->escape($this->getDi()->url("admin-user-payments/index/user_id/".$record->user_id."#invoice-".$record->invoice_id,null,false)),
                    $record->invoice_id, $record->public_id) :
                    '<td>&mdash;</td>'
            ;
    }

    public function renderAccessMember($record)
    {
        return sprintf('<td><a class="link" target="_top" href="%s">%s (%s)</a></td>',
            $this->getView()->userUrl($record->user_id), $record->member_login, $record->member_name);
    }

    public function renderRec(AdminLog $record)
    {
        $text = "";
        if ($record->tablename || $record->record_id)
            $text = $this->escape($record->tablename . ":" . $record->record_id);
        // @todo - add links here to edit pages
        return sprintf('<td>%s</td>', $text);
    }

    function getInvoiceDetailsAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission(Am_Auth_Admin::PERM_LOGS_INVOICE);
        $log = $this->getDi()->invoiceLogTable->load($this->getParam('id'));
        echo $this->renderInvoiceDetails($log);
    }

    public function renderInvoiceDetails(Am_Record $obj)
    {
        $ret = "";
        $ret .= "<div class='collapsible'>\n";
        $rows = $obj->getRenderedDetails();
        $open = count($rows) == 1 ? 'open' : '';
        foreach ($rows as $row)
        {
            $popup = @$row[2];
            if ($popup) $popup = sprintf('<br /><br /><div class="encoded-details"><span class="encoded-details-head">Encoded details</span><pre class="encoded-details-content">%s</pre></div>', $popup);
            $ret .= "\t<div class='item $open'>\n";
            $ret .= "\t\t<div class='head'>$row[0]</div>\n";
            $ret .= "\t\t<div class='more'>$row[1]$popup</div>\n";
            $ret .= "\t</div>\n";
        }
        $ret .= "</div>\n\n";
        return $ret;
    }

    public function createMailQueue()
    {
        $ds = new Am_Query($this->getDi()->mailQueueTable);
        $ds->clearFields();
        $ds->addField('recipients')
            ->addField('added')
            ->addField('sent')
            ->addField('subject')
            ->addField('queue_id');
        $ds->setOrder('added', true);

        $g = new Am_Grid_Editable('_mail', ___("E-Mail Queue"), $ds, $this->getRequest(), $this->view);
        $g->setPermissionId(Am_Auth_Admin::PERM_LOGS_MAIL);
        $g->addField(new Am_Grid_Field('recipients', ___('Recipients'), true, '', null, '20%'));
        $g->addField(new Am_Grid_Field_Date('added', ___('Added'), true));
        $g->addField(new Am_Grid_Field_Date('sent', ___('Sent'), true));
        $g->addField(new Am_Grid_Field('subject', ___('Subject'), true, '', null, '30%'))
            ->setRenderFunction(array($this, 'renderSubject'));
        $g->addField(new Am_Grid_Field_Expandable('queue_id', ___('Mail'), false, '', null, '20%'))
            ->setAjax($this->getDi()->url('admin-logs/get-mail?id={queue_id}', false))
            ->setSafeHtml(true);

        $g->setFilter(new Am_Grid_Filter_LogEntity_Mail('@added', array(
            'subject' => 'LIKE',
            'recipients' => 'LIKE',
        ), 'Subject/Recipient'));
        $g->actionsClear();
        $g->actionAdd(new Am_Grid_Action_MailRetry('retry'));

        if ($this->getDi()->authAdmin->getUser()->isSuper()) {
            $g->actionAdd(new Am_Grid_Action_Delete);
            $g->actionAdd(new Am_Grid_Action_Group_Delete);
        }

        $g->addCallback(Am_Grid_ReadOnly::CB_RENDER_CONTENT, function (& $out, $g) {
            $st_label = array(
                Am_Mail_Queue::QUEUE_DISABLED => ___('Disabled'),
                Am_Mail_Queue::QUEUE_OK => ___('Ok'),
                Am_Mail_Queue::QUEUE_ONLY_INSTANT => ___('Only Instant'),
                Am_Mail_Queue::QUEUE_FULL => ___('Full')
            );
            $q = Am_Mail_Queue::getInstance();

            if ($q->getQueueStatus() == Am_Mail_Queue::QUEUE_DISABLED) return;

            $status_label = Am_Html::escape(___('Queue Status'));
            $status = Am_Html::escape($st_label[$q->getQueueStatus()]);
            $out = <<<CUT
<div class="info">
$status_label: <strong>$status</strong>
</div>

CUT
                . $out;
        });

        return $g;
    }

    function getMailAction()
    {
        $this->getDi()->authAdmin->getUser()->checkPermission(Am_Auth_Admin::PERM_LOGS_MAIL);
        $mail = $this->getDi()->mailQueueTable->load($this->getParam('id'));
        echo $this->renderMail($mail);
    }

    function renderMail(Am_Record $obj)
    {
        $_body = $obj->body;
        $atRendered = null;

        $headersRendered = '';
        foreach (unserialize($obj->headers) as $headerName => $headerVal)
        {
            if (isset($headerVal['append'])) {
                unset($headerVal['append']);
                $headerVal = implode(',' . "\r\n" . ' ', $headerVal);
            } else {
                $headerVal = implode("\r\n", $headerVal);
            }

            $_headers[strtolower($headerName)] = $headerVal;
            if (strpos($headerVal, '=?') === 0)
                $headerVal = mb_decode_mimeheader($headerVal);

            $headersRendered .= '<strong>' . $headerName . '</strong>: <em>' . nl2br(Am_Html::escape($headerVal)) . '</em><br />';
        }

        $part = new Zend_Mail_Part(array(
            'headers' => $_headers,
            'content' => $_body
        ));

        $canHasAttacments = false;

        list($type) = explode(";", $part->getHeader('content-type'));
        if ($type == 'multipart/alternative') {
            $msgPart = $part->getPart(2);
        } else {
            $msgPart = $part->isMultipart() ? $part->getPart(1) : $part;
            if ($msgPart->isMultipart()) {
                $msgPart = $msgPart->getPart(2); //html part
            }
            $canHasAttacments = true;
        }

        list($type) = explode(";", $msgPart->getHeader('content-type'));
        $encoding = $msgPart->getHeader('content-transfer-encoding');

        $content = $msgPart->getContent();
        if ($encoding && $encoding == 'quoted-printable') {
            $content = quoted_printable_decode($content);
        } else {
            $content = base64_decode($content);
        }

        switch ($type) {
            case 'text/plain':
                $bodyRendered = sprintf('<pre style="background:white; padding:.5em">%s</pre>', Am_Html::escape($content));
                break;
            case 'text/html':
                $bodyRendered = sprintf('<iframe srcdoc="%s" style="border:none" width="100%%" onload="this.style.height=this.contentDocument.body.scrollHeight +\'px\';"></iframe>', htmlentities($content));
                break;
        }

        //attachments
        $atRendered = '';
        if ($canHasAttacments) {
            if ($part->isMultipart()) {
                for ($i=2; $i<=$part->countParts(); $i++) {
                    $attPart = $part->getPart($i);

                    preg_match('/filename="(.*)"/', $attPart->{'content-disposition'}, $matches);
                    $filename = @$matches[1];
                    $atRendered .= sprintf("&ndash; %s (<em>%s</em>)", $filename, $attPart->{'content-type'}) . '<br />';
                }
            }
        }

        $attachTitle = ___('Attachments');
        return $headersRendered .
            '<br />' .
            $bodyRendered .
            ($atRendered ? '<br /><strong>' . $attachTitle . '</strong>:<br />' . $atRendered : '');
    }

    function renderSubject(Am_Record $m)
    {
        $s = $m->subject;
        if (strpos($s, '=?') === 0)
            $s = mb_decode_mimeheader($s);
        return "<td>". Am_Html::escape($s) . "</td>";
    }

    public function createAdminLog()
    {
        $ds = new Am_Query($this->getDi()->adminLogTable);
        $ds->setOrder('log_id', 'desc');

        $g = new Am_Grid_ReadOnly('_admin', ___('Admin Log'), $ds, $this->getRequest(), $this->view);
        $g->setPermissionId(Am_Auth_Admin::PERM_LOGS_ADMIN);
        $g->addField(new Am_Grid_Field_Date('dattm', ___('Date/Time'), true));
        $g->addField(new Am_Grid_Field('admin_login', ___('Admin'), true))
            ->addDecorator(new Am_Grid_Field_Decorator_Link($this->getDi()->url("admin-admins?_admin_a=edit&_admin_id={admin_id}",null,false), '_top'));
        $g->addField(new Am_Grid_Field('ip', ___('IP'), true, '', null, '10%'));
        $g->addField(new Am_Grid_Field('message', ___('Message')));
        $g->addField(new Am_Grid_Field('record', ___('Record')))->setRenderFunction(array($this, 'renderRec'));

        $g->setFilter(new Am_Grid_Filter_LogEntity('dattm', array(
            'message' => 'LIKE',
            'record_id' => 'LIKE',
            'tablename' => 'LIKE',
            'ip' => '='
        ), 'Message/Record Id/Table'));
        return $g;
    }
}

class Am_Grid_Action_InvoiceRetry extends Am_Grid_Action_Abstract
{
    protected $type = self::SINGLE;

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Repeat Action Handling');
        parent::__construct($id, $title);
        $this->setTarget('_top');
    }

    public function isAvailable($record)
    {
        return (strpos($record->details, 'type="incoming-request"') !== false);
    }

    public static function repeat(InvoiceLog $invoiceLog, array & $response)
    {
        Am_Di::getInstance()->plugins_payment->load($invoiceLog->paysys_id);
        $paymentPlugin = Am_Di::getInstance()->plugins_payment->get($invoiceLog->paysys_id);
        $paymentPlugin->toggleDisablePostbackLog(true);
        /* @var $paymentPlugin Am_Paysystem_Abstract */
        try
        {
            $request = $invoiceLog->getFirstRequest();
            if (!$request instanceof Am_Mvc_Request)
                throw new Am_Exception_InputError('Am_Mvc_Request is not saved for this record, this action cannot be repeated');
            $resp = new Am_Mvc_Response();

            Am_Di::getInstance()->router->route($request);

            $paymentPlugin->toggleDisablePostbackLog(true);
            $paymentPlugin->directAction($request, $resp, array('di' => Am_Di::getInstance()));

            $response['status'] = 'OK';
            $response['msg'] = ___('The action has been repeated, ipn script response [%s]', $resp->getBody());
        } catch (Exception $e)
        {
            $response['status'] = 'ERROR';
            $response['msg'] = sprintf("Exception %s : %s", get_class($e), $e->getMessage());
        }
    }

    public function run()
    {
        echo $this->renderTitle();
        $invoiceLog = Am_Di::getInstance()->invoiceLogTable->load($this->getRecordId());

        $response = array();
        try {
            self::repeat($invoiceLog, $response);
        } catch (Exception $e) {
            $response['status'] = 'ERROR';
            $response['msg'] = $e->getMessage();
        }

        echo "<b>RESULT: $response[status]</b><br />";
        echo $response['msg'];
        echo "<br /><br />\n";
        echo $this->renderBackUrl();
    }

    static function groupCallback($id, InvoiceLog $record, Am_Grid_Action_Group_Callback $action, Am_Grid_Editable $grid)
    {
        @set_time_limit(3600);
        try {
            $req = $record->getFirstRequest();
            if (!$req) {
                echo "<br />\n$record->log_id: SKIPPED";
                return;
            }
            $response = array();
            self::repeat($record, $response);
        } catch (Exception $e) {
            $response['status'] = 'Error';
            $response['msg'] = $e->getMessage();
        }
        echo "<br />\n$record->log_id: {$response['status']} : {$response['msg']}";
    }
}

class Am_Grid_Action_MailRetry extends Am_Grid_Action_Abstract
{
    protected $type = self::SINGLE;

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('Resend Email');
        parent::__construct($id, $title);
        $this->setTarget('_top');
    }

    public function isAvailable($record)
    {
        return !$record->sent;
    }

    public function run()
    {
        echo $this->renderTitle();
        $record = Am_Di::getInstance()->mailQueueTable->load($this->getRecordId());
        $row = $record->toArray();

        $response = array();
        try {
            Am_Mail_Queue::getInstance()->_sendSavedMessage($row);
            Am_Di::getInstance()->db->query("UPDATE ?_mail_queue SET sent=?d WHERE queue_id=?d",
                $row['sent'], $row['queue_id']);

            $response['status'] = 'OK';
            $response['msg'] = ___('Email has been send');

        } catch (Exception $e) {
            $response['status'] = 'ERROR';
            $response['msg'] = $e->getMessage();
        }

        echo "<b>RESULT: $response[status]</b><br />";
        echo $response['msg'];
        echo "<br /><br />\n";
        echo $this->renderBackUrl();
    }
}