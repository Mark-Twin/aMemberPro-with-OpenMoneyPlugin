<?php

/**
 * Subscribe all matching users to newsletter list
 */
class Am_Grid_Action_NewsletterSubscribeAll extends Am_Grid_Action_Abstract
{
    /** @var NewsletterList */
    protected $list;
    
    protected $batchCount = 20;
    
    public function __construct($id = null, $title = null)
    {
        parent::__construct($id, ___('Subscribe all available users'));
    }

    protected function getConfirmationText()
    {
        return ___("Do you really want to %s?",
            ___('Subscribe all available users') . ' : ' . $this->list->title);
    }
    
    public function run()
    {
        $this->list = $this->grid->getDi()->newsletterListTable->load($this->getRecordId());
        if ($this->list->plugin_id == '')
            $this->batchCount = 100;
        if ($this->grid->getRequest()->get('confirm'))
            $this->doSubscribe();
        else
            echo $this->renderConfirmation();
    }
    protected function doSubscribe()
    {
        $batch = new Am_BatchProcessor(array($this, '_handleAll'));
        $page = $this->grid->getRequest()->getInt('group_context');
        if ($batch->run($page))
        {
            echo ___('DONE')."." ;
        } else {
            echo ($page*$this->batchCount). ' ' . ___('records processed.');
            echo $this->getAutoClickScript(5, 'input#group-action-continue');
            echo $this->renderContinueForm(___('Continue'), $page);
        }
    }
    
    public function _handleAll(& $page, Am_BatchProcessor $batch)
    {
        $q = $this->grid->getDi()->resourceAccessTable->getResourcesForMembers('newsletterlist', 'resource_id='.$this->list->pk());
        $ids = array();
        foreach ($q->selectRows((int)$page, $this->batchCount) as $row)
            $ids[] = $row['user_id'];
        $page++;
        
        if (!$ids) return true;
        
        if ($this->list->plugin_id == '')
        {
            $this->grid->getDi()->db->query(
                "INSERT IGNORE INTO ?_newsletter_user_subscription
                 SELECT null, user_id, ?d, 1, 'auto'
                 FROM ?_user 
                 WHERE user_id IN (?a) AND unsubscribed=0"
            , $this->list->pk(), $ids);
        } else {
            // select users who has no records in newsletter_user_subscription table for this newsletter list
            $users = $this->grid->getDi()->userTable->selectObjects("SELECT u.* 
                FROM ?_user u
                LEFT JOIN ?_newsletter_user_subscription s 
                    ON s.user_id = u.user_id AND s.list_id = ?d
                WHERE u.user_id IN (?a) AND ((s.list_id IS NULL) OR (s.is_active = 0 AND s.type = 'auto'))", $this->list->pk(), $ids);
            $newsletterUserSubscriptionTable = $this->grid->getDi()->newsletterUserSubscriptionTable;
            foreach ($users as $user)
                $newsletterUserSubscriptionTable->add($user, $this->list, NewsletterUserSubscription::TYPE_AUTO);
        }
    }
}