<?php

class Bootstrap_Newsletter extends Am_Module
{
    const NEWSLETTER_SIGNUP_DATA = 'newsletter_signup_data';

    function init()
    {
        $this->getDi()->plugins_newsletter = new Am_Plugins($this->getDi(),
            'newsletter', dirname(__FILE__) . '/plugins',
            'Am_Newsletter_Plugin_%s', '%s.%s'
        );
        class_exists('Am_Newsletter_Plugin_Standard', true);
        $this->getDi()->plugins_newsletter
            ->addEnabled('standard')
            ->loadEnabled()
            ->getAllEnabled();

        $this->getDi()->plugins->offsetSet('newsletter', $this->getDi()->plugins_newsletter);
    }

    function onGetBaseTranslationData(Am_Event $e)
    {
        $result = $e->getReturn();
        foreach (Am_Di::getInstance()->db->select("SELECT title, `desc` FROM ?_newsletter_list") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['desc'] ] = "";
        }
        $e->setReturn($result);
    }

    function onInitBlocks(Am_Event $event)
    {
        $event->getBlocks()->add('member/main/left', new Am_Widget_NewsletterManage, Am_Blocks::MIDDLE);
        $event->getBlocks()->add('unsubscribe', new Am_Widget_NewsletterManage, Am_Blocks::MIDDLE);
    }

    function onInitAccessTables(Am_Event $event)
    {
        $event->getRegistry()->registerAccessTable($this->getDi()->newsletterListTable);
    }

    function onLoadBricks()
    {
        class_exists('Am_Form_Brick_Newsletter', true);
    }

    function onUserSearchConditions(Am_Event $e)
    {
        $e->addReturn(new Am_Query_User_Condition_SubscribedToNewsletter);
        $e->addReturn(new Am_Query_User_Condition_NotSubscribedToNewsletter);
    }

    function onSignupUserAdded(Am_Event $event)
    {
        $vars = $event->getVars();
        if (!empty($vars['_newsletter']))
        {
            if(is_array($vars['_newsletter'])){
                foreach($vars['_newsletter'] as $k=>$v){
                    if(!$v) continue; 
                    $list = $this->getDi()->newsletterListTable->load($k);
                    $this->getDi()->userConsentTable->recordConsent(
                        $event->getUser(), 
                        'newsletter-list-'.$k, 
                        $this->getDi()->request->getClientIp(), 
                        ___('Signup Form: %s', $this->getDi()->request->getHeader('REFERER')), 
                        $list->title
                        );
                    
                }
            }else{
                $this->getDi()->userConsentTable->recordConsent(
                    $event->getUser(), 
                    'newsletters', 
                    $this->getDi()->request->getClientIp(), 
                    ___('Signup Form: %s', $this->getDi()->request->getHeader('REFERER')), 
                    ___('All Newsletter Lists')
                    );
}
            $event->getUser()->data()->set(self::NEWSLETTER_SIGNUP_DATA, $vars['_newsletter'])->update();
        }

        // handle free access newsletters;
        $this->getDi()->newsletterUserSubscriptionTable->checkSubscriptions($event->getUser());

    }

    function onSignupUserUpdated(Am_Event $event)
    {
        $this->onSignupUserAdded($event);
    }

    function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $newEmail = $event->getUser()->get('email');
        $oldEmail = $event->getOldUser()->get('email');
        if ($newEmail != $oldEmail)
            foreach ($this->getDi()->plugins_newsletter->getAllEnabled() as $pl)
                $pl->changeEmail($event->getUser(), $oldEmail, $newEmail);
    }

    function onSubscriptionChanged(Am_Event_SubscriptionChanged $event)
    {
        $this->getDi()->newsletterUserSubscriptionTable->checkSubscriptions($event->getUser());
    }

    function onUserUnsubscribedChanged(Am_Event $event)
    {
        $this->getDi()->newsletterUserSubscriptionTable->checkSubscriptions($event->getUser());
    }

    function onGridUserInitForm(Am_Event_Grid $event)
    {
        $form = $event->getGrid()->getForm()->getElementById('general');
        $el = $form->addMagicSelect('_newsletter')->setLabel(___('Newsletter Subscriptions'));
        $el->loadOptions($this->getDi()->newsletterListTable->getAdminOptions());
        $record = $event->getGrid()->getRecord();
        if ($record->isLoaded())
            $el->setValue($ids = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($record->pk()));
        $form->addHidden('_newsletter_hidden')->setValue(1);
    }

    function onGridUserValuesToForm(Am_Event_Grid $event)
    {
        $args = $event->getArgs();
        $record = $event->getGrid()->getRecord();
        if ($record->isLoaded())
            $args[0]['_newsletter'] = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($record->pk());
        else
            $args[0]['_newsletter'] = array();
    }

    function onGridUserAfterSave(Am_Event_Grid $event)
    {
        $vars = $event->getArg(0);
        if (!empty($vars['_newsletter_hidden'])) // if was submitted
        {
            $vals = @$vars['_newsletter'];
            $this->getDi()->newsletterUserSubscriptionTable->adminSetIds($event->getGrid()->getRecord()->pk(), (array)$vals);
        }
    }

    function onUserBeforeDelete(Am_Event $event)
    {
        $this->getDi()->newsletterUserSubscriptionTable->deleteByUserId($event->getUser()->pk());
    }

    function onRebuild(Am_Event_Rebuild $event)
    {
        $this->getDi()->db->query("DELETE FROM ?_newsletter_user_subscription
            WHERE list_id not in (SELECT list_id from ?_newsletter_list)");
        $batch = new Am_BatchProcessor(array($this, 'batchProcess'));
        $context = $event->getDoneString();
        $this->_batchStoreId = 'rebuild-' . $this->getId() . '-' . $this->getDi()->session->getId();
        if ($batch->run($context)) {
            $event->setDone();
        } else {
            $event->setDoneString($context);
        }
    }

    function batchProcess(& $context, Am_BatchProcessor $batch)
    {
        $db = $this->getDi()->db;
        $q = $db->queryResultOnly("SELECT * FROM ?_user WHERE user_id > ?d ORDER BY user_id", (int)$context);
        $userTable = $this->getDi()->userTable;
        $newsletterUserSubscriptionTable = $this->getDi()->newsletterUserSubscriptionTable;
        while ($r = $db->fetchRow($q)) {
            $u = $userTable->createRecord($r);
            $context = $r['user_id'];
            $newsletterUserSubscriptionTable->checkSubscriptions($u);
            if (!$batch->checkLimits()) return;
        }
        return true;
    }

    function onGetPermissionsList(Am_Event $event)
    {
        $event->addReturn(___('Manage Newsletters'), "newsletter");
    }
    
    function getActiveSubscriptionsForUser(User $user)
    {
        return array_filter(
            $this->getDi()
            ->newsletterUserSubscriptionTable
            ->findBy(array('user_id' => $user->pk())),
            function($el){
                return $el->is_active;
            });
        
    }
    
    function onRenderDeleteAccountConfirmation(Am_Event $event)
    {
        if($subscriptions = $this->getActiveSubscriptionsForUser($event->getUser())){
            $ret = sprintf("<h2>%s</h2>", ___('Your will be unsubscribed from these newsletter lists'));
            $ret .="<ul>";
            foreach($subscriptions as $sub){
                $ret .= "<li>".$sub->getList()->title."</li>";
            }
            $ret .="</ul>";
            $event->addReturn($ret);
        }
    }
    
    
    function onDeletePersonalData(Am_Event $event)
    {
        $errors = array();
        foreach($this->getActiveSubscriptionsForUser($event->getUser()) as $sub){
            try{
                $sub->disable(true);
            } catch (Exception $ex) {
                $errors[] = ___('Unable to unsubscribe user from newsletter list "%s" : %s', $sub->getList()->title, $ex->getMessage());
            }
        }
        $event->addReturn($errors);
    }
    
    function onGridContentEmailsInitGrid(Am_Event_Grid $event)
    {
        $grid = $event->getGrid();

        $grid->addCallback(Am_Grid_Editable::CB_INIT_FORM, function(Am_Form $form)
        {
            $form->addMagicSelect('_newsletter_lists', 'class="am-combobox"')
                ->setLabel(___("Newsletter Lists\n"
                        . "Send message only if user is subscribed to any newsletter from list"))
                ->loadOptions($this->getDi()->newsletterListTable->getAdminOptions());
        });

        $grid->addCallback(Am_Grid_Editable::CB_VALUES_FROM_FORM, function(&$values, $record)
        {
            $val = array_filter($values['_newsletter_lists']);
            $values['newsletter_lists'] = empty($val) ? null : implode(',', $val);
        });


        $grid->addCallback(Am_Grid_Editable::CB_VALUES_TO_FORM, function(&$values, $record)
        {
            $values['_newsletter_lists'] = explode(',', @$values['newsletter_lists']);
        });

        $grid->addField('newsletter_lists', ___('Newsletter Lists'), false)
            ->setRenderFunction(function($record)
            {

                if (is_null($record->newsletter_lists))
                    return "<td>&nbsp;</td>";

                static $lists;

                if (is_null($lists))
                    $lists = $this->getDi()->newsletterListTable->getAdminOptions();


                foreach (explode(",", $record->newsletter_lists) as $v)
                {
                    $display[] = sprintf("%s", $lists[$v]);
                }
                return sprintf("<td>%s</td>", implode("<br>", $display));
            });
    }

    function onEmailTemplateCheckConditions(Am_Event $event)
    {
        $template = $event->getTemplate();
        $user = $event->getUser();

        if (empty($template->newsletter_lists))
            return $event->setReturn(true);

        return $event->setReturn(
                (bool) array_intersect(
                    explode(",", $template->newsletter_lists), $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($user->pk())
        ));
    }

}