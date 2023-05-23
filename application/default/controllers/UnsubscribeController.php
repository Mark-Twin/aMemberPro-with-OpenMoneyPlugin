<?php

class UnsubscribeController extends Am_Mvc_Controller
{

    function indexAction()
    {
        if (!$e = $this->getParam('e'))
            throw new Am_Exception_InputError("Empty e-mail parameter passed - wrong url");

        $s = $this->getFiltered('s');
        if (!Am_Mail::validateUnsubscribeLink($e, $s, Am_Mail::LINK_USER))
            throw new Am_Exception_InputError(___('Wrongly signed URL, please contact site admin'));

        $this->view->user = $this->getDi()->userTable->findFirstByEmail($e);

        if (!$this->view->user)
            throw new Am_Exception_InputError(___("Wrong parameters, error #1253"));

        if ($prefs = $this->_request->get('pref'))
        {
            switch ($prefs['ALL'])
            {
                case 'subscribe' :
                    $this->view->user->unsubscribed = 0;
                    $this->getDi()->userConsentTable->recordConsent(
                        $this->view->user, 
                        'site-emails', 
                        $this->getRequest()->getClientIp(),
                        ___('Subscription Management Page: %s', $this->getRequest()->getHeader('REFERER') ?: ___('Dashboard')), 
                        ___('Site Email Messages')
                    );
                    
                    break;
                case 'unsubscribe' :
                    $this->view->user->unsubscribed = 1;
                    $this->getDi()->userConsentTable->cancelConsent(
                        $this->view->user, 
                        'site-emails', 
                        $this->getRequest()->getClientIp(),
                        ___('Subscription Management Page: %s', $this->getRequest()->getHeader('REFERER') ?: ___('Dashboard')) 
                    );
                    
                    break;
            }

            $this->view->user->update();

            $this->getDi()->hook->call(
                Am_Event::USER_UNSUBSCRIBED_CHANGED, 
                ['user' => $this->view->user, 'unsubscribed' => $this->view->user->unsubscribed]
                );

            unset($prefs['ALL']);

            if ($this->getDi()->modules->isEnabled('newsletter'))
            {
                $lists = $this->getDi()->newsletterListTable->getAllowed($this->view->user);
                $subs = [];
                foreach ($this->getDi()->newsletterUserSubscriptionTable->findByUserId($this->view->user->pk()) as $s)
                    $subs[$s->list_id] = $s;

                foreach ($prefs as $k => $v)
                {
                    if (!is_int($k))
                        continue;

                    switch ($v)
                    {
                        case 'unsubscribe':
                            if (!empty($subs[$k])){
                                $subs[$k]->unsubscribe();
                                $this->getDi()->userConsentTable->cancelConsent(
                                    $this->view->user, 
                                    'newsletter-list-' . $k, 
                                    $this->getRequest()->getClientIp(),
                                    ___('Subscription Management Page: %s', $this->getRequest()->getHeader('REFERER') ?: ___('Dashboard')) 
                                    );
                            }
                            break;
                        case 'subscribe':
                            $list = $this->getDi()->newsletterListTable->load($k);

                            $this->getDi()->userConsentTable->recordConsent(
                                $this->view->user, 
                                'newsletter-list-' . $k, 
                                $this->getRequest()->getClientIp(),
                                ___('Subscription Management Page: %s', $this->getRequest()->getHeader('REFERER') ?: ___('Dashboard')), 
                                $list->title
                            );

                            $this->getDi()->newsletterUserSubscriptionTable->add($this->view->user, $list, NewsletterUserSubscription::TYPE_USER);
                            break;
                        default:
                            throw new Am_Exception_InputError(___('Wrong value submitted'));
                    }
                }
            }
            $_GET['_msg'] = ___('Settings saved successfully');
        }

        $this->view->e = $this->getParam('e');
        $this->view->s = $this->getFiltered('s');

        if ($this->getDi()->modules->isEnabled('newsletter'))
        {
            $lists = $this->getDi()->newsletterListTable->getAllowed($this->view->user);

            $subscribed = $this->getDi()->newsletterUserSubscriptionTable->getSubscribedIds($this->view->user->pk());

            foreach ($lists as $k => $list)
            {
                if ($list->hide)
                    unset($lists[$k]);
            }
        }else
        {
            $lists = $subscribed = [];
        }

        $this->view->lists = $lists;
        $this->view->subscribed = $subscribed;


        $this->view->display('unsubscribe.phtml');
    }

}