<?php

class PersonalDataController extends Am_Mvc_Controller
{

    use Am_PersonalData;

    /** @var User */
    protected
        $user;
    protected
        $user_id;

    function preDispatch()
    {
        if($this->getRequest()->getActionName()!='delete-result')
        {
            $this->getDi()->auth->requireLogin($this->getDi()->url('member', false));
            $this->user = $this->getDi()->user;
            $this->view->assign('user', $this->user);
            $this->user_id = $this->user->pk();
        }
    }

    function deleteAction()
    {
        if($this->getDi()->config->get('hide-delete-link'))
            throw new Am_Exception_Security("You are not allowed to do this action");
        
        $form = $this->getConfirmationForm('delete-personal-data-confirm', ___('Delete My Account and Personal Data'));

        if ($form->isSubmitted() && $form->validate())
        {
            $errors = [];
            switch ($this->getDi()->config->get('account-removal-method'))
            {
                case 'delete' :
                    $errors = $this->doDelete($this->user);
                    break;
                case 'anonymize' :
                    $errors = $this->doAnonymize($this->user);
                    break;
            }

            if (!empty($errors) || ($this->getDi()->config->get('account-removal-method') == 'delete-request'))
            {
                $this->addDeleteRequest($this->user, $errors);
                $this->notifyAdmin($this->user, $errors);
            }

            $this->getDi()->auth->logout();
            $this->redirectHtml($this->url('personal-data/delete-result', ['success' => empty($errors)?1:0]), ___('Please wait while we delete your Personal Data'));
            
        }
        else
        {
            $this->view->assign([
                'invoices' => $this->getRecurringActiveInvoices($this->user),
                'member_products' => $this->user->getActiveProducts(),
                'member_future_products' => $this->getDi()->user->getFutureProducts(),
                'products_rebill' => $this->user->getActiveProductsRebill(),
                'products_expire' => $this->user->getActiveProductsExpiration(),
                'products_upgrade' => []
            ]);

            $event = new Am_Event(Am_Event::RENDER_DELETE_ACCOUNT_CONFIRMATION, ['user' => $this->user]);
            $event->setReturn([]);
            $this->getDi()->hook->call($event);
            $this->view->assign('otherConfirmations', $event->getReturn());
            $this->view->assign('form', $form);


            $this->view->display('member/delete-confirm.phtml');
        }
    }

    function deleteResultAction()
    {
        $this->view->assign('success', $this->getParam('success'));
        $this->view->display('member/delete-result.phtml');
    }
    
    function getConfirmationForm($id = 'delete-personal-data-confirm', $submitTitle = '')
    {
        $form = new Am_Form($id);
        $form->addPassword('password', ['id' => 'password'])->setLabel(___('Your Password'))
            ->addRule('callback2', ___('Password is incorrect'), function($val)
            {
                if (!$this->user->checkPassword($val))
                {
                    return ___('Password is incorrect');
                }
                return;
            });
        $form->addSubmit('confirm-submit', ['value' => $submitTitle]);
        return $form;
    }

    function checkPasswordAction()
    {
        $pass = $this->getParam('password');
        if (!$this->user->checkPassword($pass))
        {
            $msg = ___('Password is incorrect');
        }
        else
        {
            $msg = null;
        }
        return $this->_response->ajaxResponse($msg);
    }

    function notifyAdmin(User $user, $errors = [])
    {
        if ($et = Am_Mail_Template::load('delete_personal_data_notification'))
        {
            $et->setUser($user);
            if (!empty($errors))
            {
                $et->setErrorsText(
                    ___("aMember has attempted to process this request automatically, but got several errors: \n"
                        . "%s\n"
                        . "Please review error messages and process this request manually\n", implode("\n", $errors))
                );
            }

            $et->send(Am_Mail_Template::TO_ADMIN);
        }
    }

    function addDeleteRequest(User $user, $errors)
    {
        $errStr = !empty($errors) ? implode("\n", $errors) : "";
        $this->getDi()->db->query(""
            . "INSERT INTO ?_user_delete_request "
            . "SET "
            . "user_id=?, added=?, remote_addr=?, errors=?, completed=? "
            . "ON DUPLICATE KEY UPDATE errors=?, completed=?", $user->pk(), $this->getDi()->sqlDateTime, $this->_request->getClientIp(), $errStr, 0, 
            $errStr, 0
        );
    }

    function downloadAction()
    {
        if (!$this->getDi()->config->get('enable-personal-data-download'))
            throw new Am_Exception_Security(___('Downloads are disabled in config'));

        // Confirmation step
        $form = $this->getConfirmationForm('confirm-download', ___('Confirm Download'));

        $form->insertBefore((new Am_Form_Element_Html(null, ['class' => 'no-label']))
                ->setHTML(___('Please enter your account password below to confirm your Personal Data Download')), $form->getElementById('password')
        );


        if ($form->isSubmitted() && $form->validate())
        {
            $this->getResponse()->setHeader('Content-type', 'text/xml');
            $this->getResponse()->setHeader('Content-Disposition', 'attachment; filename="personal_data.xml"');
            $data = $this->buildPersonalDataArray($this->getDi()->auth->getUser());
            $xml = new SimpleXMLElement("<personal-data/>");
            foreach ($data as $v)
            {
                $field = $xml->addChild('field');
                $field->addChild('name', $v['name']);
                $field->addChild('title', $v['title']);
                $field->addChild('value', is_array($v['value']) || is_object($v['value']) ? json_encode($v['value']) : $v['value']);
            }
            echo $xml->asXML();
        }
        else
        {
            $this->view->title = ___('Confirm Personal Data Download');
            $this->view->assign('content', $form);
            $this->view->display('member/layout.phtml');
        }
    }

}
