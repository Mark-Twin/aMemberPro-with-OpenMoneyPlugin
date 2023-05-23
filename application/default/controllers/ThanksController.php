<?php

class ThanksController extends Am_Mvc_Controller
{
    /** @var Invoice */
    protected $invoice;

    function indexAction()
    {
        $this->getDi()->plugins_payment->loadEnabled()->getAllEnabled();
        $id = filterId(urldecode($this->_request->get('id')));
        if (empty($id)) $id = filterId(@urldecode(@$_GET['id']));
        $this->invoice = null;
        if ($id)
        {
            $this->invoice = $this->getDi()->invoiceTable->findBySecureId($id, 'THANKS');
            if (!$this->invoice)
                throw new Am_Exception_InputError(___("Invoice #%s not found", $id));
            $tm = max($this->invoice->tm_started, $this->invoice->tm_added);
            if (($this->getDi()->time - strtotime($tm)) > 48*3600)
                throw new Am_Exception_InputError(___("Link expired"));

            $this->view->invoice = $this->invoice;
            foreach ($this->invoice->getPaymentRecords() as $p) {
                $this->view->payment = $p;
            }

            $cd_sec = 10;
            if (!$this->invoice->tm_started)
            {
                $this->view->show_waiting = true;
                $this->view->refreshTime = "<span id='am-countdown'>00:{$cd_sec}</span> ".___("seconds");
            }
            $this->view->script = $this->getJs($cd_sec);
        }
        if (($login = $this->getParam('uid')) &&
            $this->getDi()->security->hash($login, 8) == $this->getParam('h')) {

            $this->view->user = $this->getDi()->userTable->findFirstByLogin($login);
        }

        // Clean signup_member_login and signup_member_id to avoid duplicate signups with the same email address
        $this->getSession()->signup_member_id = null;
        $this->getSession()->signup_member_login = null;

        $this->getDi()->hook->call(Am_Event::THANKS_PAGE, array(
            'controller' => $this,
            'invoice'    => $this->invoice,
        ));

        $this->view->layoutNoMenu = true;
        $this->view->display('thanks.phtml');
    }

    function getJs($seconds)
    {
        return <<<CUT
jQuery(function(){
    var left = $seconds;
    var f = function()
    {
        left--;
        var m = Math.floor(left / 60);
        var s = left - m*60;
        if (m<10) m = "0"+m;
        if (s<10) s = "0"+s;
        jQuery("#am-countdown").text(m+":"+s);
        if (!left) {
            window.location.href = window.location.href; // reload page
        } else {
            setTimeout(f, 1000);
        }
    };
    f();
});
CUT;
    }
}