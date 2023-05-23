<?php

class AjaxController extends Am_Mvc_Controller
{
    public function preDispatch()
    {
        if (!$this->getRequest()->isXmlHttpRequest()) {
            $e = new Am_Exception_InputError;
            $e->setLogError(false);
            throw $e;
        }
    }

    function ajaxError($msg)
    {
        $this->_response->ajaxResponse(array('msg' => $msg));
    }

    function ajaxGetStates($vars)
    {
        return $this->_response->ajaxResponse($this->getDi()->stateTable->getOptions($vars['country']));
    }

    function ajaxCheckUniqLogin($vars)
    {
        $user_id = $this->getDi()->auth->getUserId();
        if (!$user_id) {
            $user_id = $this->getDi()->session->signup_member_id;
        }
        $login = $vars['login'];
        $msg = null;
        if (!$this->getDi()->userTable->checkUniqLogin($login, $user_id)) {
            $msg = ___('Username %s is already taken. Please choose another username', Am_Html::escape($login));
        }
        if (!$msg) {
            $msg = $this->getDi()->banTable->checkBan(array('login'=>$login));
        }

        return $this->_response->ajaxResponse($msg ? $msg : true);
    }

    function ajaxCheckUniqEmail($vars)
    {
        $user_id = $this->getDi()->auth->getUserId();
        if (!$user_id) {
            $user_id = $this->getDi()->session->signup_member_id;
        }

        $email = $vars['email'];
        $msg = null;
        if(isset($vars['_url'])) {
            $url = $this->getDi()->url('login', array('amember_redirect_url' => $vars['_url']));
        } else {
            $url = $this->getDi()->url('member');
        }
        if (!$this->getDi()->userTable->checkUniqEmail($email, $user_id))
            $msg = ___('An account with the same email already exists.').'<br />'.
                    ___('Please %slogin%s to your existing account.%sIf you have not completed payment, you will be able to complete it after login','<a href="' . $url . '" class="ajax-link" title="' . Am_Html::escape($this->getDi()->config->get('site_title')) . '">','</a>','<br />');

        if (!$msg) {
            $msg = $this->getDi()->banTable->checkBan(array('email'=>$email));
        }
        if (!$msg && !Am_Validate::email($email)) {
            $msg = ___('Please enter valid Email');
        }

        return $this->_response->ajaxResponse($msg ? $msg : true);
    }

    function ajaxCheckCoupon($vars)
    {
        if (!$vars['coupon']) return $this->_response->ajaxResponse(true);
        $user_id = $this->getDi()->auth->getUserId();
        if (!$user_id)
            $user_id = $this->getDi()->session->signup_member_id;

        $coupon = $this->getDi()->couponTable->findFirstByCode($vars['coupon']);
        $msg = $coupon ? $coupon->validate($user_id) : ___('No coupons found with such coupon code');
        return $this->_response->ajaxResponse(is_null($msg) ? true : $msg);
    }

    function indexAction()
    {
        $vars = $this->_request->toArray();
        switch ($this->_request->getFiltered('do')){
            case 'get_states':
                $this->ajaxGetStates($vars);
                break;
            case 'check_uniq_login':
                $this->ajaxCheckUniqLogin($vars);
                break;
            case 'check_uniq_email':
                $this->ajaxCheckUniqEmail($vars);
                break;
            case 'check_coupon':
                $this->ajaxCheckCoupon($vars);
                break;
            default:
                $this->ajaxError('Unknown Request: ' . $vars['do']);
        }
    }

    function invoiceSummaryAction()
    {
        $vars = $this->getRequest()->getParams();
        if(!$user = $this->getDi()->auth->getUser()) {
            $user = $this->getDi()->userRecord;
            $user->user_id = -1;
        }
        $user->toggleFrozen(true);
        if($vars['country']) {
            $user->country = $vars['country'];
        }
        if($vars['state']) {
            $user->state = $vars['state'];
        }
        if($vars['zip']) {
            $user->zip = $vars['zip'];
        }
        if (isset($vars['tax_id'])) {
            $user->tax_id = $vars['tax_id'];
        }

        $user->remote_addr = $this->_request->getClientIp();

        $param = array();
        $page_current = $this->getRequest()->getParam('_save_');
        $vars_added = false;
        $ns = $this->getDi()->session->ns('am_form_container_signup');
        foreach ($ns->data['values'] as $page => $v) {
            if ($page == $page_current) {
                $v = array_merge($v, $vars);
                $vars_added = true;
            }
            $param = array_merge($param, $v);
        }

        $vars = $vars_added ? $param : array_merge($param, $vars);

        if (!empty($vars['_button']) && ($btn = $this->getDi()->buttonTable->findFirstByHash($vars['_button']))) {
            $invoice = $btn->createInvoice();
            $invoice->setUser($user);
            $invoice->calculate();
        } else {
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->setUser($user);

            foreach ($vars as $k => $v) {
                if (strpos($k, 'product_id')===0) {
                    foreach ((array)$vars[$k] as $key => $product_id) {
                        if (substr($key, 0, 4) == '_qty') continue;
                        @list($product_id, $plan_id, $qty) = explode('-', $product_id, 3);

                        $qty_key = sprintf('_qty-%d-%d', $product_id, $plan_id);
                        if (isset($vars[$k][$qty_key]))
                            $qty = $vars[$k][$qty_key];

                        $product_id = (int)$product_id;
                        if (!$product_id) continue;
                        $p = $this->getDi()->productTable->load($product_id);
                        if ($plan_id > 0) $p->setBillingPlan(intval($plan_id));
                        $qty = (int)$qty;
                        if (!$p->getBillingPlan()->variable_qty || ($qty <= 0))
                            $qty = 1;
                        $plan_id = $p->getBillingPlan()->pk();
                        $options = array();
                        if (!empty($vars['productOption']["$product_id-$plan_id"])) {
                            $options = $vars['productOption']["$product_id-$plan_id"][0];
                        }
                        $prOpt = $p->getOptions(true);
                        foreach ($options as $opk => $opv) {
                            $options[$opk] = array('value' => $opv, 'optionLabel' => $prOpt[$opk]->title,
                                'valueLabel' => $prOpt[$opk]->getOptionLabel($opv));
                        }
                        $invoice->add($p, $qty, $options);
                    }
                }
            }
            if (!empty($vars['coupon'])) {
                $invoice->setCouponCode($vars['coupon']);
                if ($error = $invoice->validateCoupon()) {
                    $invoice->setCouponCode('');
                }
            }
            $this->_handleDonation($invoice, $vars);

            $invoice->calculate();
            if (($invoice->first_total > 0 || $invoice->second_total > 0) &&
                isset($vars['paysys_id'])) {
                $invoice->setPaysystem($vars['paysys_id']);
            }
        }
        $v = $this->getDi()->view;
        $v->invoice = $invoice;
        $v->show_terms = $this->getParam('_show_terms');
        $html = $v->render('_invoice-summary.phtml');
        $this->_response->ajaxResponse(array(
            'html' => $html,
            'hash' => md5($html)
        ));
    }

    function _handleDonation(Invoice $invoice, $vars)
    {
        //we take into account only first period - it is just preview
        foreach ($invoice->getItems() as $item) {
            if ($item->item_type == 'product' && isset($vars['donation'][$item->item_id])) {
                if (!$vars['donation'][$item->item_id] && !$vars['donation_allow_free'][$item->item_id]) {
                    $invoice->deleteItem($item);
                } else {
                    $item->first_price = $vars['donation'][$item->item_id];
                    $item->data()->set('orig_first_price', $item->first_price);
                }
            }
        }
    }

    function unsubscribedAction()
    {
        $v = $this->_request->getPost('unsubscribed');
        if (strlen($v) != 1)
            throw new Am_Exception_InputError("Wrong input");
        $v = ($v > 0) ? 1 : 0;
        if (($s = $this->getFiltered('s')) && ($e = $this->getParam('e')) &&
            Am_Mail::validateUnsubscribeLink($e, $s)) {
            $user = $this->getDi()->userTable->findFirstByEmail($e);
        } else {
            $user = $this->getDi()->user;
        }
        if (!$user)
            return $this->ajaxError(___('You must be logged-in to run this action'));
        if ($user->unsubscribed != $v) {
            $user->set('unsubscribed', $v)->update();
            if (!$v) {
                $this->getDi()->userConsentTable->recordConsent(
                        $user,
                        'site-emails',
                        $this->getRequest()->getClientIp(),
                        ___('Subscription Management Page: %s', ___('Dashboard')),
                        ___('Site Email Messages')
                    );
            } else {
                $this->getDi()->userConsentTable->cancelConsent(
                        $user,
                        'site-emails',
                        $this->getRequest()->getClientIp(),
                        ___('Subscription Management Page: %s', ___('Dashboard'))
                    );
            }
            $this->getDi()->hook->call(Am_Event::USER_UNSUBSCRIBED_CHANGED,
                array('user'=>$user, 'unsubscribed' => $v));
        }
        $this->_response->ajaxResponse(array('status' => 'OK', 'value' => $v));
    }
}