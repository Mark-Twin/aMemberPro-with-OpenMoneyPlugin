<?php

class Api_CheckAccessController extends Am_Mvc_Controller_Api
{
    protected function checkUser(User $user = null, $errCode = null, $errMsg = null, $ip = null)
    {
        if ($user)
        {
            $ns = $this->getDi()->session->ns('amember_auth');
            $auth = new Am_Auth_User($ns, $this->getDi());
            if($res = $auth->checkUser($user, $ip)) {
                $ret = array(
                    'ok' => false,
                    'code' => $res->getCode(),
                    'msg'  => $res->getMessage(),
                );
            } else {
                $resources = $this->getDi()->resourceAccessTable
                    ->getAllowedResources($user, ResourceAccess::USER_VISIBLE_TYPES);

                $res = array();
                foreach ($resources as $k => $r) {
                    if ($link = $r->renderLink())
                        $res[] = $link;
                }



                $ret = array(
                    'ok' => true,
                    'user_id' => $user->pk(),
                    'name' => $user->getName(),
                    'name_f' => $user->name_f,
                    'name_l' => $user->name_l,
                    'email' => $user->email,
                    'login' => $user->login,
                    'subscriptions' => $user->getActiveProductsExpiration(),
                    'categories' => $user->getActiveCategoriesExpiration(),
                    'groups' => $user->getGroups(),
                    'resources' => $res
                );
            }
        } else {
            if (empty($errCode)) $errCode = -1;
            if (empty($errMsg)) $errMsg = "Failure";
            $ret = array(
                'ok' => false,
                'code' => $errCode,
                'msg'  => $errMsg,
            );
        }
        $this->_response->ajaxResponse($ret);
    }

    /**
     * Check access by username/password
     */
    function byLoginPassAction()
    {
        $code = null;
        $user = $this->getDi()->userTable->getAuthenticatedRow($this->_getParam('login'), $this->_getParam('pass'), $code);
        $res = new Am_Auth_Result($code);
        $this->checkUser($user, $res->getCode(), $res->getMessage());
    }

    /**
     * Check access by username
     */
    function byLoginAction()
    {
        $user = $this->getDi()->userTable->findFirstByLogin($this->_getParam('login'));
        $this->checkUser($user);
    }

    /**
     * Check access by email address
     */
    function byEmailAction()
    {
        $user = $this->getDi()->userTable->findFirstByEmail($this->_getParam('email'));
        $this->checkUser($user);
    }

    /**
     * Check access by username/password/ip
     */
    function byLoginPassIpAction()
    {
        $code = null;
        $user = $this->getDi()->userTable->getAuthenticatedRow($this->_getParam('login'), $this->_getParam('pass'), $code);
        $res = new Am_Auth_Result($code);
        $this->checkUser($user, $res->getCode(), $res->getMessage(), $this->_getParam('ip'));
    }

    function sendPassAction()
    {
        $login = trim($this->_getParam('login'));

        if (!$user = $this->getDi()->userTable->findFirstByLogin($login)) {
            $user = $this->getDi()->userTable->findFirstByEmail($login);
        }

        if (!$user) {
            $this->_response->ajaxResponse(array('ok' => false));
            return;
        }

        require_once AM_APPLICATION_PATH . '/default/controllers/SendpassController.php';
        $c = new SendpassController($this->getRequest(), $this->getResponse(), array('di' => $this->getDi()));
        $c->sendSecurityCode($user);

        $this->_response->ajaxResponse(array(
            'ok' => true,
            'msg' => ___('A link to reset your password has been emailed to you. Please check your mailbox.')
        ));
    }
}