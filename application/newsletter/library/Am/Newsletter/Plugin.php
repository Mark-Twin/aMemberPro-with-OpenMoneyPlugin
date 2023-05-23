<?php

abstract class Am_Newsletter_Plugin extends Am_Plugin
{
    protected $_configPrefix = 'newsletter.';
    protected $_idPrefix = 'Am_Newsletter_Plugin_';

    const UNSUBSCRIBE_AFTER_ADDED = 1;
    const UNSUBSCRIBE_AFTER_PAID = 2;

    /**
     * Method must subscribe user to $addLists and unsubscribe from $deleteLists
     */
    abstract function changeSubscription(User $user, array $addLists, array $deleteLists);

    /**
     * Method must change customer e-mail when user changes it in aMember UI
     */
    function changeEmail(User $user, $oldEmail, $newEmail) { }

    /**
     * @return array lists 'id' => array('title' => 'xxx', )
     */
    function getLists() { }

    /**
     * @return true if plugin can return lists (getLists overriden)
     */
    function canGetLists()
    {
        $rm = new ReflectionMethod($this, 'getLists');
        return ($rm->getDeclaringClass()->getName() !== __CLASS__) && $this->isConfigured();
    }

    public function deactivate()
    {
        parent::deactivate();
        foreach ($this->getDi()->newsletterListTable->findByPluginId($this->getId()) as $list)
            $list->disable();
    }

    public function onUserAfterUpdate(Am_Event_UserAfterUpdate $event) { }

    function _afterInitSetupForm(Am_Form_Setup $form)
    {
        $url = $this->getDi()->url('default/admin-content/p/newsletter/index');
        $text = ___("Once the plugin configuration is finished on this page, do not forget to add\n".
                    "a record on %saMember CP -> Protect Content -> Newsletters%s page",
            '<a href="'.$url.'" target="_blank" class="link">', '</a>');
        $form->addProlog(<<<CUT
<div class="warning_box">
    $text
</div>
CUT
        );

        if($lists = $this->getListOptions())
        {
            $gr = $form->addGroup()->setLabel(___('Unsubscribe customer from selected newsletter threads'));
            $gr->addSelect('unsubscribe_after_signup')->loadOptions(array(
                '' => ___('Please Select'),
                self::UNSUBSCRIBE_AFTER_ADDED => ___('After the user has been added'),
                self::UNSUBSCRIBE_AFTER_PAID => ___('After first payment has been completed')
            ));
            $gr->addStatic()->setContent('<br><br>');
            $gr->addMagicSelect('unsubscribe_after_signup_lists')
                ->loadOptions($lists);
        }
        parent::_afterInitSetupForm($form);
    }

    protected function getListOptions()
    {
        $lists = array();
        if($this->canGetLists())
        {
            $pid = 'newsletter_plugins_' . $this->getId() . '_lists';
            if($s = $this->getDi()->store->getBlob($pid))
            {
                $slists = (array)@unserialize($s);
                foreach($slists as $k => $v) {
                    $lists[$k] = $v['title'];
                }
            } else {
                try {
                    $slists = $this->getLists();
                    foreach($slists as $k => $v) {
                        $lists[$k] = $v['title'];
                    }
                } catch(Exception $e) {
                    $this->getDi()->errorLogTable->logException($e);
                }
                $this->getDi()->store->setBlob($pid, serialize((array)@$slists), '+1 hour');
            }
        } elseif ($this->isConfigured()) {
            $lists = $this->getDi()->db->selectCol(<<<CUT
                SELECT plugin_list_id AS ARRAY_KEY, title
                    FROM ?_newsletter_list
                    WHERE plugin_id = ?
CUT
                , $this->getId());
        }
        return $lists;
    }

    public function onUserAfterInsert(Am_Event_UserAfterInsert $e)
    {
        if($this->getConfig('unsubscribe_after_signup') != self::UNSUBSCRIBE_AFTER_ADDED) return;
        if(!$lists = $this->getConfig('unsubscribe_after_signup_lists')) return;

        try {
            $this->changeSubscription($e->getUser(), array(), $lists);
        } catch(Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
        }
    }

    public function onPaymentAfterInsert(Am_Event_PaymentAfterInsert $e)
    {
        $this->handlePayment($e);
    }

    public function onPaymentWithAccessAfterInsert(Am_Event_PaymentWithAccessAfterInsert $e)
    {
        $this->handlePayment($e);
    }

    public function handlePayment(Am_Event $e)
    {
        if($this->getConfig('unsubscribe_after_signup') != self::UNSUBSCRIBE_AFTER_PAID) return;
        $user = $e->getUser();
        if($user->data()->get('unsubscribe_after_signup')) return;
        $user->data()->set('unsubscribe_after_signup', self::UNSUBSCRIBE_AFTER_PAID)->update();
        if($lists = $this->getConfig('unsubscribe_after_signup_lists')) return;

        try{
            $this->changeSubscription($e->getUser(), array(), $lists);
        } catch(Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
        }
    }

    public function getIntegrationFormElements(HTML_QuickForm2_Container $container) { }
}