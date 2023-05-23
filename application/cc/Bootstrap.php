<?php

class Bootstrap_Cc extends Am_Module
{
    const EVENT_CC_FORM = 'ccForm';

    public function init()
    {
        $this->getDi()->plugins_payment->addPath(dirname(__FILE__) . '/plugins');
    }
    function onSetupEmailTemplateTypes(Am_Event $event)
    {
        $event->addReturn(array(
                'id' => 'cc.admin_rebill_stats',
                'title' => 'Cc Rebill Rebill Stats',
                'mailPeriodic' => Am_Mail::USER_REQUESTED,
                'isAdmin' => true,
            ), 'cc.admin_rebill_stats');
        $event->addReturn(array(
                'id' => 'cc.rebill_failed',
                'title' => 'Cc Rebill Failed',
                'mailPeriodic' => Am_Mail::USER_REQUESTED,
                'vars' => array('user', 'invoice', 'product_title'=> ___('Product(s) Title')),
            ), 'cc.rebill_failed');
        $event->addReturn(array(
                'id' => 'cc.rebill_success',
                'title' => 'Cc Rebill Success',
                'mailPeriodic' => Am_Mail::USER_REQUESTED,
                'vars' => array('user', 'invoice', 'product_title'=> ___('Product(s) Title')),
            ), 'cc.rebill_success');
    }
    public function onAdminWarnings(Am_Event $event)
    {
        $plugins = $this->getDi()->plugins_payment->loadEnabled()->getAllEnabled();
        $key_pl = '';
        foreach($plugins as $pl)
            if($pl instanceof Am_Paysystem_CreditCard)
                $key_pl = $pl->getId();
        $setupUrl = $this->getDi()->url('admin-setup');
        
        ///check for configuration problems
        if (class_exists('Am_Paysystem_CreditCard', false))
        {
            if(!$this->getDi()->config->get('use_cron'))
            {
                $event->addReturn(___('%sEnable%s and %sconfigure%s external cron if you are using credit card payment plugins',
                        '<a class="link" href="'.$this->getDi()->url('admin-setup/advanced'). '">', '</a>', '<a class="link" href="http://www.amember.com/docs/Cron">', '</a>'));
            }
            try
            {
                $crypt = $this->getDi()->crypt;
            } catch (Am_Exception_Crypt_Key $e)
            {
                if($key_pl)
                    $event->addReturn("Encryption subsystem error: " . '<a class="link" href="'.$setupUrl. '/'.$key_pl.'">' . $e->getMessage(). '</a>');
                else
                    $event->addReturn("Encryption subsystem error: " . $e->getMessage());

            } catch (Am_Exception_Crypt $e)
            {
                $event->addReturn("Encryption subsystem error: " . $e->getMessage());
            }
        }
    }
    public function onHourly(Am_Event $event)
    {
        foreach ($this->getPlugins() as $ps)
            $ps->ccRebill($this->getDi()->sqlDate);
    }
    /** @return array of Am_Paysystem_CreditCard */
    public function getPlugins()
    {
        $this->getDi()->plugins_payment->loadEnabled();
        $ret = array();
        foreach ($this->getDi()->plugins_payment->getAllEnabled() as $ps)
            if ($ps instanceof Am_Paysystem_CreditCard || $ps instanceof Am_Paysystem_Echeck)
                $ret[] = $ps;
        return $ret;
    }
    public function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        $this->getDi()->ccRecordTable->deleteByUserId($event->getUser()->pk());
        $this->getDi()->echeckRecordTable->deleteByUserId($event->getUser()->pk());
    }
    function onUserTabs(Am_Event_UserTabs $event)
    {
        if ($event->getUserId() > 0)
        {
            $event->getTabs()->addPage(array(
                'id' => 'cc',
                'module' => 'cc',
                'controller' => 'admin',
                'action' => 'info-tab',
                'params' => array(
                    'user_id' => $event->getUserId(),
                ),
                'label' => ___('Credit Cards'),
                'order' => 900,
                'resource' => 'cc',
            ));
            foreach ($this->getPlugins() as $ps)
            {
                if($ps instanceof Am_Paysystem_Echeck)
                {
                    $event->getTabs()->addPage(array(
                        'id' => 'cc',
                        'module' => 'cc',
                        'controller' => 'admin',
                        'action' => 'info-tab-echeck',
                        'params' => array(
                            'user_id' => $event->getUserId(),
                        ),
                        'label' => ___('Echeck'),
                        'order' => 901,
                        'resource' => 'cc',
                    ));
                    break;
                }
            }
        }
    }

    function onAdminMenu(Am_Event $event)
    {
        $parent = $event->getMenu()->findBy('id', 'utilites');
        if (!$parent) $parent = $event->getMenu();
        $parent->addPage(array(
            'id' => 'ccrebills',
            'module' => 'cc',
            'controller' => 'admin-rebills',
            'label' => ___('Credit Card Rebills'),
            'resource' => 'cc',
        ));
        /* disabled  until real-life tested
        if (count($this->getPlugins()) > 1)
        {
            $parent->addPage(array(
                'id' => 'cc-change',
                'module' => 'cc',
                'controller' => 'admin',
                'action' => 'change-paysys',
                'label' => 'Change Paysystem',
            ));
        }
         *
         */
    }
    function onGetPermissionsList(Am_Event $event)
    {
        $event->addReturn(___("Can view/edit customer Credit Card information and rebills"), 'cc');
    }
    function onGetMemberLinks(Am_Event $event)
    {
        $user = $event->getUser();
        if ($user->status == User::STATUS_PENDING) return ;
        foreach ($this->getPlugins() as $pl)
        {
            if($pl instanceof Am_Paysystem_CreditCard && ($link = $pl->getUpdateCcLink($user)))
                $event->addReturn(___("Update Credit Card Info"), $link);
            elseif($pl instanceof Am_Paysystem_Echeck && ($link = $pl->getUpdateEcheckLink($user)))
                $event->addReturn(___("Update Echeck Info"), $link);
        }
    }
}
