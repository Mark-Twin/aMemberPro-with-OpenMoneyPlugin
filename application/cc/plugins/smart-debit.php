<?php

class Am_Paysystem_SmartDebit extends Am_Paysystem_Echeck
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_DATE = '$Date$';
    const PLUGIN_REVISION = '5.5.4';
    const REF_PREFIX = 'A00';

    const API_ENDPOINT = 'https://secure.ddprocessing.co.uk/api';
    const STORE_KEY_ARUDD = 'smart-debit-last-check-arudd';
    const STORE_KEY_ADDACS = 'smart-debit-last-check-addacs';
    const STORE_KEY_AUDDIS = 'smart-debit-last-check-auddis';

    protected $defaultTitle = "SmartDebit";
    protected $defaultDescription = "";

    public function getRecurringType()
    {
        return self::REPORTS_CRONREBILL;
    }

    public function storesCcInfo()
    {
        return false;
    }

    public function init()
    {
        parent::init();
        $this->getDi()->blocks->add('thanks/success', new Am_Block_Base('SmartDebit Statement', 'smart-debit-statement', $this, array($this, 'renederStatement')));
    }

    public function onPdfInvoiceColLeft(Am_Event $e)
    {
        $invoice = $e->getInvoice();
        if ($invoice->paysys_id == $this->getId()) {
            $c = $e->getCol();
            $c->ddr = ___('Direct Debit Reference: %s', self::REF_PREFIX . $invoice->public_id);
        }
    }

    public function renederStatement(Am_View $v)
    {
        if (isset($v->invoice) && $v->invoice->paysys_id == $this->getId()) {
            $ref = self::REF_PREFIX . $v->invoice->public_id;
            $legal_name = $this->getConfig('legal_name');
            $legal_address = $this->getConfig('legal_address');
            return <<<CUT
<p>&nbsp;</p>
<hr />
<div class="am-smart-debit-statement">
    <p>&nbsp;</p>
    <h2>Thank you for your purchase.</h2>
    <p>Your Direct Debit Reference Number is <strong>$ref</strong>. The company name which will appear on your bank statement against the Direct Debit is <strong>$legal_name</strong>.</p>
    <p>Should you have any queries regarding your Direct Debit please do not hesitate to contact us.</p>
    <p>That completes the Direct Debit Instruction, thank you. An email confirming the details wil be sent within 3 working days or not later than 10 working days before first collection. Please find a copy of Direct Debit Guarantee below.</p>
    <p>&nbsp;</p>
    <hr />
    <p>&nbsp;</p>
    <h2>The Direct Debit Gurantee</h2>
    <ul class="am-list">
        <li>This Gurantee is offered by all banks and building societies that accept instructions to pay Direct Debit.</li>
        <li>If there are any changes to the amount, date or frequency of your Direct Debit <strong>$legal_name</strong> will notify you five (5) working days in advance of your account being debited or as otherwise agreed. If you request <strong>$legal_name</strong> to collect a payment, confirmation of the amount and date will be given to you at the time of the request.</li>
        <li>If an error is made in the payment of your Direct Debit, by <strong>$legal_name</strong> or your bank or building society, you are entitled to a full and immediate refund of the amount paid from your bank or building society. If you receive a refund you are not entitled to, you must pay it back when <strong>$legal_name</strong> asks you to.</li>
        <li>You can cancel a Direct Debit at any time by simply contacting your bank or building society. Written confirmation may be required. Please also notify us.</li>
    </ul>
    <p>&nbsp;</p>
    <hr />
    <p>&nbsp;</p>
    <h2>$legal_name</h2>
    <p>$legal_address</p>
</div>
CUT;
        }
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('login')
            ->setLabel('API Login')
            ->addRule('required');
        $form->addSecretText('passwd')
            ->setLabel('API Password')
            ->addRule('required');
        $form->addText('pslid')
            ->setLabel('PSLID (Service User)')
            ->addRule('required');
        $form->addText('sun')
            ->setLabel('Service User Number (SUN)')
            ->addRule('required');

        $form->addText('lead_time')
                ->setLabel('Lead time, days')
            ->default = 12;

        for ($i = 1; $i <= 28; $i++) {
            $options[$i] = ___("%d-th day", $i);
        }

        $payment_day = $form->addMagicSelect('payment_day')
            ->setLabel(___("Process Payment Day"))
            ->loadOptions($options);
        $payment_day->default = 8;
        $payment_day->addRule('required');
        $form->addText('legal_name', array('class' => 'el-wide'))
            ->setLabel(___("Legal Name\n" .
                    "Please enter the legal name of your organisation " .
                    "which will be included in the Direct Debit Guarantee statement"))
            ->addRule('required');
        $form->addTextarea('legal_address', array('class' => 'el-wide', 'rows' => 5))
            ->setLabel(___("Legal Address\n" .
                    "Please enter the legal address of your organisation " .
                    "which will be included in the Direct Debit Guarantee statement"))
            ->addRule('required');
        $form->addAdvCheckbox('auddis', array('id' => 'auddis'))
            ->setLabel("Log AUDDIS reports as Tickets in helpdesk\n" .
                "helpdesk module should be enabled");
        $form->addText('auddis_login', array('id' => 'auddis-login'))
            ->setLabel('Username of user for AUDDIS reports');

        $form->addScript()
            ->setScript(<<<CUT
(function(){
    jQuery('#auddis').change(function(){
        jQuery('#auddis-login').closest('.row').toggle(this.checked);
    }).change();
    jQuery('#auddis-login').autocomplete({
        minLength: 2,
        source: amUrl("/admin-users/autocomplete/")
    });
})()
CUT
        );
    }

    public function createForm($actionName)
    {
        return new Am_Form_Echeck_SmartDebit($this);
    }

    protected function createController(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Mvc_Controller_Echeck_SmartDebit($request, $response, $invokeArgs);
    }

    public function storeEcheck(EcheckRecord $echeck, Am_Paysystem_Result $result)
    {
        //nop
    }

    public function _doBill(Invoice $invoice, $doFirst, EcheckRecord $echeck, Am_Paysystem_Result $result)
    {
        $user = $invoice->getUser();

        if ($doFirst) {
            $request = new Am_HttpRequest(self::API_ENDPOINT . '/ddi/adhoc/create',
                    Am_HttpRequest::METHOD_POST);
            $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

            $params = array(
                'adhoc_ddi[reference_number]' => self::REF_PREFIX . $invoice->public_id,
                'adhoc_ddi[first_name]' => $echeck->name_f,
                'adhoc_ddi[last_name]' => $echeck->name_l,
                'adhoc_ddi[address_1]' => $echeck->street,
                'adhoc_ddi[town]' => $echeck->city,
                'adhoc_ddi[postcode]' => $echeck->zip,
                'adhoc_ddi[country]' => $echeck->country,
                'adhoc_ddi[account_name]' => $echeck->account_name,
                'adhoc_ddi[sort_code]' => $echeck->sort_code,
                'adhoc_ddi[account_number]' => $echeck->account_number,
                'adhoc_ddi[service_user][pslid]' => $this->getConfig('pslid'),
                'adhoc_ddi[payer_reference]' => sprintf('U%07d', $user->pk()),
                'adhoc_ddi[title]' => preg_replace('/[^- .&/a-zA-Z0-9]/', '', $invoice->getLineDescription()),
                'adhoc_ddi[email_address]' => $user->email
            );
            if ((float) $invoice->first_total) {
                $params['adhoc_ddi[debits][debit][][amount]'] = $invoice->first_total * 100;
                $params['adhoc_ddi[debits][debit][][date]'] = $this->getPaymentDate(true);
            }
            $request->addPostParameter($params);
        } else {
            $request = new Am_HttpRequest(self::API_ENDPOINT . sprintf('/ddi/adhoc/%s/update', self::REF_PREFIX . $invoice->public_id),
                    Am_HttpRequest::METHOD_POST);
            $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

            $params = array(
                'adhoc_ddi[debits][debit][][amount]' => $invoice->second_total * 100,
                'adhoc_ddi[debits][debit][][date]' => $this->getPaymentDate()
            );
            $request->addPostParameter($params);
        }

        $tr = new Am_Paysystem_Transaction_SmartDebit_Create($this, $invoice, $request, $doFirst);
        $t = new DateTime($this->getPaymentDate($doFirst) . date(' H:i:s', $this->getDi()->time));
        $tr->setPaymentDate($t->format('Y-m-d H:i:s'));
        $tr->run($result);
    }

    function cancelInvoice(Invoice $invoice)
    {
        $request = new Am_HttpRequest(self::API_ENDPOINT . sprintf('/ddi/adhoc/%s/cancel', self::REF_PREFIX . $invoice->public_id),
                Am_HttpRequest::METHOD_POST);
        $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));
        $tr = new Am_Paysystem_Transaction_SmartDebit_Cancel($this, $invoice, $request, false);
        $tr->run(new Am_Paysystem_Result);
    }

    function getPaymentDate($lead_time = false)
    {
        $n = $lead_time ? strtotime(sprintf('+ %d days', $this->getConfig('lead_time')), $this->getDi()->time) : $this->getDi()->time;
        $nd = date('d', $n);

        $days = $this->getConfig('payment_day');
        sort($days);
        foreach ($days as $d) {
            if ($d > $nd)
                return date('Y-m-' . sprintf("%02d", $d), $n);
        }
        return date('Y-m-' . sprintf("%02d", $days[0]), strtotime('+1 month', $n));
    }

    function onDaily(Am_Event $e)
    {
        //ARUDD
        $request = new Am_HttpRequest(self::API_ENDPOINT . '/arudd/list', Am_HttpRequest::METHOD_POST);
        $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

        $query = array(
            'query[service_user][pslid]' => $this->getConfig('pslid'),
            'query[id_from]' => (int) $this->getDi()->store->get(self::STORE_KEY_ARUDD) + 1
        );

        $request->addPostParameter($query);
        $response = $request->send();

        if ($response->getStatus() == 200) {
            $xml = simplexml_load_string($response->getBody());
            $log = $this->logRequest($request, 'ARUDD-LIST');
            $log->add($response);
            $log->add($xml->asXml());
            $id = 0;
            foreach ($xml->arudd as $arudd) {
                $id = max($id, (int) $arudd->arudd_id);
                $this->processARUDD((string) $arudd['uri']);
            }
            if ($id > $this->getDi()->store->get(self::STORE_KEY_ARUDD))
                $this->getDi()->store->set(self::STORE_KEY_ARUDD, $id);
        }

        //ADDACS
        $request = new Am_HttpRequest(self::API_ENDPOINT . '/addac/list', Am_HttpRequest::METHOD_POST);
        $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

        $query = array(
            'query[service_user][pslid]' => $this->getConfig('pslid'),
            'query[id_from]' => (int) $this->getDi()->store->get(self::STORE_KEY_ADDACS) + 1
        );


        $request->addPostParameter($query);
        $response = $request->send();
        if ($response->getStatus() == 200) {
            $xml = simplexml_load_string($response->getBody());
            $log = $this->logRequest($request, 'ADDACS-LIST');
            $log->add($response);
            $log->add($xml->asXml());
            $id = 0;
            foreach ($xml->addac as $addac) {
                $id = max($id, (int) $addac->addac_id);
                $this->processADDACS((string) $addac['uri']);
            }
            if ($id > $this->getDi()->store->get(self::STORE_KEY_ADDACS))
                $this->getDi()->store->set(self::STORE_KEY_ADDACS, $id);
        }

        //AUDDIS
        if ($this->getConfig('auddis') && $this->getDi()->modules->isEnabled('helpdesk')) {
            $request = new Am_HttpRequest(self::API_ENDPOINT . '/auddis/list', Am_HttpRequest::METHOD_POST);
            $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

            $query = array(
                'query[service_user][pslid]' => $this->getConfig('pslid'),
                'query[id_from]' => (int) $this->getDi()->store->get(self::STORE_KEY_AUDDIS) + 1
            );


            $request->addPostParameter($query);
            $response = $request->send();
            if ($response->getStatus() == 200) {
                $xml = simplexml_load_string($response->getBody());
                $log = $this->logRequest($request, 'AUDDIS-LIST');
                $log->add($response);
                $log->add($xml->asXml());
                $id = 0;
                foreach ($xml->auddis as $auddis) {
                    $id = max($id, (int) $auddis->auddis_id);
                    $this->processAUDDIS((string) $auddis['uri']);
                }
                if ($id > $this->getDi()->store->get(self::STORE_KEY_AUDDIS))
                    $this->getDi()->store->set(self::STORE_KEY_AUDDIS, $id);
            }
        }
    }

    function processARUDD($uri)
    {
        $request = new Am_HttpRequest($uri, Am_HttpRequest::METHOD_POST);
        $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

        $response = $request->send();

        $origXml = simplexml_load_string($response->getBody());
        $xml = simplexml_load_string(base64_decode((string) $origXml->file));

//       $log = $this->logRequest($request, 'ARUDD');
//       $log->add($response);
//       $log->add($origXml->asXML());
//       $log->add($xml->asXML());

        foreach ($xml->Data->ARUDD->Advice->OriginatingAccountRecords->OriginatingAccountRecord->ReturnedDebitItem as $ARUDD) {
            $ref = (string) $ARUDD['ref'];
            /* @var $invoice Invoice */
            $invoice = $this->getDi()->invoiceTable->findFirstByPublicId(substr($ref, strlen(self::REF_PREFIX)));
            if ($invoice) {
                $payments = $invoice->getPaymentRecords();
                $p = array_pop($payments);
                $tr = new Am_Paysystem_Transaction_Manual($this);
                $tr->setInvoice($invoice);
                $tr->setReceiptId(sprintf('%s-%s', (string) $ARUDD['transCode'], (string) $ARUDD['returnCode']));
                $invoice->addVoid($tr, $p->receipt_id);
            }
        }
    }

    function processADDACS($uri)
    {
        $request = new Am_HttpRequest($uri, Am_HttpRequest::METHOD_POST);
        $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

        $response = $request->send();
        $origXml = simplexml_load_string($response->getBody());
        $xml = simplexml_load_string(base64_decode((string) $origXml->file));

//        $log = $this->logRequest($request, 'ADDACS');
//        $log->add($response);
//        $log->add($origXml->asXML());
//        $log->add($xml->asXML());

        foreach ($xml->Data->MessagingAdvices->MessagingAdvice as $ADDACS) {
            $ref = (string) $ADDACS['reference'];
            $reason_code = (string) $ADDACS['reason-code'];
            if (!in_array($reason_code, array('0','1','2'))) continue;

            /* @var $invoice Invoice */
            $invoice = $this->getDi()->invoiceTable->findFirstByPublicId(substr($ref, strlen(self::REF_PREFIX)));
            if ($invoice) {
                $invoice->setCancelled();
            }
        }
    }

    function processAUDDIS($uri)
    {
        $request = new Am_HttpRequest($uri, Am_HttpRequest::METHOD_POST);
        $request->setAuth($this->getConfig('login'), $this->getConfig('passwd'));

        $response = $request->send();

        $user = $this->getDi()->userTable->findFirstByLogin($this->getConfig('auddis_login'));
        if (!$user)
            return;

        $origXml = simplexml_load_string($response->getBody());
        $xml = simplexml_load_string(base64_decode((string) $origXml->file));

        $ticket = $this->getDi()->helpdeskTicketRecord;
        $ticket->subject = 'AUDDIS ' . (string) $xml->Data->MessagingAdvices->Header['report-generation-date'];
        $ticket->created = $this->getDi()->sqlDateTime;
        $ticket->updated = $this->getDi()->sqlDateTime;
        $ticket->category_id = null;
        $ticket->user_id = $user->pk();
        $ticket->insert();

        $message = $this->getDi()->helpdeskMessageRecord;
        $message->content = $xml->asXML();
        $message->ticket_id = $ticket->pk();
        $message->dattm = $this->getDi()->sqlDateTime;

        $message->insert();
    }

    public function getReadme()
    {
        return <<<CUT
You need to have Adhoc service user. aMember uses Adhoc Direct Debit Instructions.
CUT;
    }

}

class Am_Mvc_Controller_Echeck_SmartDebit extends Am_Mvc_Controller_Echeck
{

    public function echeckAction()
    {
        // invoice must be set to this point by the plugin
        if (!$this->invoice)
            throw new Am_Exception_InternalError('Empty invoice - internal error!');

        $a = $this->getParam('s', 'declation');
        switch ($a) {
            case 'declation' :
                $this->doDeclartion();
                break;
            case 'details' :
                $this->doDetails();
                break;
            case 'confirmation' :
                $this->doConfimation(unserialize($this->getParam('_vars')), unserialize($this->getParam('_address')));
                break;
        }
    }

    function doDeclartion()
    {
        $form = $this->createDeclarationForm();

        if ($form->isSubmitted() && $form->validate()) {
            return $this->doDetails();
        }

        $this->view->form = $form;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->display('echeck/info.phtml');
    }

    function doDetails()
    {
        $form = $this->createForm();

        if ($r = $this->getParam('_request')) {
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array(
                    unserialize($r)
                )
            ));
        }

        $form->addHidden('s')->setValue('details');
        list($el) = $form->getElementsByName('_save_');
        $el->setValue($form->getId());

        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();
            if ($errors = $this->validateDdi($vars, $this->invoice, $address)) {
                $this->view->error = $errors;
            } else {
                return $this->doConfimation($vars, $address);
            }
        }

        $this->view->form = $form;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->display('echeck/info.phtml');
    }

    function doConfimation($vars, $address)
    {

        $form = $this->createConfirmationForm($vars, $address);
        $form->addHidden('_vars')->setValue(serialize($vars));
        $form->addHidden('_address')->setValue(serialize($address));

        if ($r = $this->getParam('_request')) {
            $form->addHidden('_request')->setValue($r);
        } else {
            $form->addHidden('_request')->setValue(serialize($this->getRequest()->getRequestOnlyParams()));
        }

        $form->addHidden('s')->setValue('confirmation');
        list($el) = $form->getElementsByName('_save_');
        $el->setValue($form->getId());

        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();

            if (isset($vars['amend']))
                return $this->doDetails();

            if (isset($vars['cancel'])) {
                $this->view->layoutNoTitle = true;
                $this->view->content = '<strong>You made decision to not complete payment.</strong>';
                $this->invoice->delete();
                $this->view->display('member/layout.phtml');
                return;
            }


            $this->form = $this->createForm();
            $this->form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array(
                    unserialize($vars['_request'])
                )
            ));
            return $this->processEcheck();
        }

        $this->view->form = $form;
        $this->view->invoice = $this->invoice;
        $this->view->display_receipt = true;
        $this->view->layoutNoMenu = true;
        $this->view->display('echeck/info.phtml');
    }

    public function validateDdi($vars, $invoice, &$address)
    {
        $user = $invoice->getUser();

        $request = new Am_HttpRequest(Am_Paysystem_SmartDebit::API_ENDPOINT . '/ddi/adhoc/validate',
                Am_HttpRequest::METHOD_POST);
        $request->setAuth($this->plugin->getConfig('login'), $this->plugin->getConfig('passwd'));

        $params = array(
            'adhoc_ddi[reference_number]' => Am_Paysystem_SmartDebit::REF_PREFIX . $invoice->public_id,
            'adhoc_ddi[first_name]' => $vars['name_f'],
            'adhoc_ddi[last_name]' => $vars['name_l'],
            'adhoc_ddi[address_1]' => $vars['street'],
            'adhoc_ddi[town]' => $vars['city'],
            'adhoc_ddi[postcode]' => $vars['zip'],
            'adhoc_ddi[country]' => $vars['country'],
            'adhoc_ddi[account_name]' => $vars['account_name'],
            'adhoc_ddi[sort_code]' => $vars['sort_code'],
            'adhoc_ddi[account_number]' => $vars['account_number'],
            'adhoc_ddi[service_user][pslid]' => $this->plugin->getConfig('pslid'),
            'adhoc_ddi[payer_reference]' => sprintf('U%07d', $user->pk()),
            'adhoc_ddi[title]' => preg_replace('/[^- .&/a-zA-Z0-9]/', '', $invoice->getLineDescription()),
            'adhoc_ddi[email_address]' => $user->email
        );
        $request->addPostParameter($params);

        $response = $request->send();
        $r = simplexml_load_string($response->getBody());

        if (!$r)
            return array('Incorrect Response from Smart Debit');

        if ($response->getStatus() != 200) {
            $err = array();
            foreach ($r->error as $e)
                $err[] = (string) $e;
            return $err;
        }

        foreach ($r->success as $s)
            ;

        foreach ($s->attributes() as $k => $v) {
            $address[$k] = (string) $v;
        }

        return;
    }

    public function createConfirmationForm($vars, $address)
    {
        $form = new Am_Form('dd-confirmation');

        $name = $this->escape($this->plugin->getConfig('legal_name'));
        $addr = $this->plugin->getConfig('legal_address');

        $form->addProlog(<<<CUT
<h1>Confirmation</h1>
<strong>$name</strong>
<br />
$addr
<br />
<br />
CUT
        );

        $form->addStatic()
            ->setContent($this->escape(sprintf('%s %s', $vars['name_f'], $vars['name_l'])))
            ->setLabel('Name of Account Holder');
        $form->addStatic()
            ->setContent($this->escape($vars['account_number']))
            ->setLabel('Bank/Building Society Account Number');
        $form->addStatic()
            ->setContent($this->escape($vars['sort_code']))
            ->setLabel('Branch Sort Code');

        $street = implode('<br/>', array_map(array($this, 'escape'), array_filter(array(
                    $address['address1'],
                    $address['address2'],
                    $address['address3'],
                    $address['address4'],
                ))));

        $form->addStatic()
            ->setContent(sprintf('
%s<br />
%s<br />
%s<br />
%s<br />
%s<br />
%s', $this->escape($address['bank_name']), $this->escape($address['branch']), $street, $this->escape($address['town']), $this->escape($address['county']), $this->escape($address['postcode'])))
            ->setLabel('Name and full postal address of your Bank or Building Society');

        $form->addStatic()
            ->setContent($this->plugin->getConfig('sun'))
            ->setLabel('Service User Number (SUN)');

        $form->addStatic()
            ->setContent(Am_Paysystem_SmartDebit::REF_PREFIX . $this->invoice->public_id)
            ->setLabel('Reference');
        $form->addStatic(null, array('class' => 'no-label'))
            ->setContent(<<<CUT
<strong>Instruction to your Bank or Building Society</strong>
<br /><br />
Please pay <strong>$name</strong> Direct Debits from the account detailed in this Instruction
subject to the safeguards assured by the Direct Debit Guarantee. I understand
that this Instruction may remain with <strong>$name</strong> and, if so, details will be passed
electronically to my Bank/Building Society.
CUT
        );

        $g = $form->addGroup(null, array('class' => 'no-label'));
        $g->setSeparator('&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;');
        $g->addSubmit('confirm', array('value' => 'Confirm'));
        $g->addSubmit('amend', array('value' => 'Amend'));
        $g->addSubmit('cancel', array('value' => 'Cancel'));
        $g->addSubmit('print', array('value' => 'Print'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery('input[name=print]').click(function(){
    window.print();
    return false;
})
CUT
        );

        $form->addHidden(Am_Mvc_Controller::ACTION_KEY)->setValue($this->getRequest()->getActionName());
        $form->addHidden('id')->setValue($this->getFiltered('id'));
        return $form;
    }

    public function createDeclarationForm()
    {
        $form = new Am_Form('dd-declaretion');
        $form->addProlog('<h2>Declaration</h2>');

        $form->addAdvCheckbox('i_agree')
            ->setLabel('I wish to start Direct Debit')
            ->addRule('required');
        $form->addSaveButton('Proceed');
        $form->addEpilog(<<<CUT
<h2>Information</h2>
<p>All the normal Direct Debit safeguards and guarantees
apply. No changes in the amount, date, frequency to be debited can be made
without notifying you at least five (5) working days in advance of your
account being debited. In the event of error, you are entitled to an immediate
refund from your Bank or Building Society. You have the right to cancel a Direct
Debit Instruction at any time simply by writing to your Bank or Building
Society, with a copy to us.</p>
CUT
        );

        $form->addHidden(Am_Mvc_Controller::ACTION_KEY)->setValue($this->getRequest()->getActionName());
        $form->addHidden('id')->setValue($this->getFiltered('id'));
        return $form;
    }

}

class Am_Form_Echeck_SmartDebit extends Am_Form_Echeck
{

    public function init()
    {
        $fieldSetBank = $this->addFieldset()
            ->setLabel(___("Bank Details"));
        $fieldSetBank->addStatic(null, array('class' => 'no-label'))
            ->setContent(___('These details can be found on your cheque book, bank statement, or bank card'));

        $name = $fieldSetBank->addGroup()
            ->setLabel(___("Name of Account Holder\n" .
                'first and last name'));
        $name->setSeparator(' ');
        $name->addRule('required', ___('Please enter name'));

        $name->addText('name_f', array('size' => 15))
            ->addRule('required', ___('Please enter first name'))
            ->addRule('regex', ___('Please enter first name'), '|^[a-zA-Z_\' -]+$|');

        $name->addText('name_l', array('size' => 15))
            ->addRule('required', ___('Please enter your last name'))
            ->addRule('regex', ___('Please enter last name'), '|^[a-zA-Z_\' -]+$|');

        $fieldSetBank->addText('account_number', array('autocomplete' => 'off', 'maxlength' => 20))
            ->setLabel(___("Bank Account Number"))
            ->addRule('required', ___('Please enter Account Number'))
            ->addRule('regex', ___('Invalid Account Number'), '/^[a-zA-Z0-9]{1,20}$/');

        $fieldSetBank->addText('sort_code', array('autocomplete' => 'off', 'maxlength' => 9))
            ->setLabel(___("Branch Sort Code"))
            ->addRule('required', ___('Please enter Branch Sort Code'))
            ->addRule('regex', ___('Invalid Routing Number'), '/^[a-zA-Z0-9]{1,9}$/');

        $fieldSetBank->addText('account_name', array('autocomplete' => 'off', 'maxlength' => 50))
            ->setLabel(___("Bank Account Name\n" .
                    'name associated with the bank account'))
            ->addRule('required');

        $fieldSetBank->addText()
            ->setlabel(___('Date of First Collection'))
            ->setValue(amDate($this->plugin->getPaymentDate(true)))
            ->toggleFrozen(true);

        $fieldSetAddress = $this->addFieldset()
            ->setLabel(___("Address Details"));

        $street = $fieldSetAddress->addText('street')->setLabel(___('Street Address'))
            ->addRule('required', ___('Please enter Street Address'));

        $city = $fieldSetAddress->addText('city')->setLabel(___('City'))
            ->addRule('required', ___('Please enter City'));

        $zip = $fieldSetAddress->addText('zip')->setLabel(___('ZIP'))
            ->addRule('required', ___('Please enter ZIP code'));

        $country = $fieldSetAddress->addSelect('country')->setLabel(___('Country'))
            ->setId('f_cc_country')
            ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
        $country->addRule('required', ___('Please enter Country'));

        $group = $fieldSetAddress->addGroup()->setLabel(___('State'));
        $group->addRule('required', ___('Please enter State'));
        /** @todo load correct states */
        $stateSelect = $group->addSelect('state')
            ->setId('f_cc_state')
            ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['country'], true));
        $stateText = $group->addText('state')->setId('t_cc_state');
        $disableObj = $stateOptions ? $stateText : $stateSelect;
        $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');

        $this->addSaveButton(___('Submit Details'));
    }

    public function getDefaultValues(User $user)
    {
        return array(
            'name_f' => $user->name_f,
            'name_l' => $user->name_l,
            'street' => $user->street,
            'city' => $user->city,
            'state' => $user->state,
            'country' => $user->country,
            'zip' => $user->zip
        );
    }

}

class Am_Paysystem_Transaction_SmartDebit_Create extends Am_Paysystem_Transaction_CreditCard
{

    public function getUniqId()
    {
        if (!$this->_id)
            $this->_id = uniqid();
        return $this->_id;
    }

    function setPaymentDate($dattm)
    {
        $this->_pd = $dattm;
    }

    function getPaymentDate()
    {
        return $this->_pd;
    }

    public function validate()
    {
        if (!$this->vars)
            return $this->result->setFailed('Incorrect Response');

        if ($this->response->getStatus() != 200) {
            $err = array();
            foreach ($this->vars->error as $e)
                $err[] = (string) $e;
            return $this->result->setFailed($err);
        }

        $this->result->setSuccess($this);
    }

    public function parseResponse()
    {
        $this->vars = simplexml_load_string($this->response->getBody());
    }

    public function processValidated()
    {
        if (!$this->doFirst || (float) $this->invoice->first_total) {
            $p = $this->invoice->addPayment($this);
            $p->updateQuick('dattm', $this->getPaymentDate());
        } else {
            $this->invoice->addAccessPeriod($this);
        }
    }

}

class Am_Paysystem_Transaction_SmartDebit_Cancel extends Am_Paysystem_Transaction_CreditCard
{

    public function getUniqId()
    {
        if (!$this->_id)
            $this->_id = uniqid();
        return $this->_id;
    }

    public function validate()
    {
        if (!$this->vars)
            return $this->result->setFailed('Incorrect Response');

        if ($this->response->getStatus() != 200) {
            $err = array();
            foreach ($this->vars->error as $e)
                $err[] = (string) $e;
            return $this->result->setFailed($err);
        }

        $this->result->setSuccess($this);
    }

    public function parseResponse()
    {
        $this->vars = simplexml_load_string($this->response->getBody());
    }

    public function processValidated()
    {
        //nop
    }

}