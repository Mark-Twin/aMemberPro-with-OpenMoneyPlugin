<?php

/**
 * View helper to return user editing url by $user_id
 * @package Am_View 
 */
class Am_View_Helper_UserUrl extends Zend_View_Helper_Abstract
{
    public function userUrl($user_id)
    {
        return Am_Di::getInstance()->url("admin-users", "_u_a=edit&_u_id={$user_id}", false);
    }
}