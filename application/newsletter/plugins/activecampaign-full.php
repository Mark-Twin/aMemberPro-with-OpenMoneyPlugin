<?php

class Am_Newsletter_Plugin_ActivecampaignFull extends Am_Newsletter_Plugin
{
    protected $api;
    
    const ACTIVE_SUB = 'active-sub';
    const ACTIVE_UNSUB = 'active-unsub';
    const INACTIVE_SUB = 'inactive-sub';
    const INACTIVE_UNSUB = 'inactive-unsub';
    const EXPIRE_SUB = 'expire-sub';
    const EXPIRE_UNSUB = 'expire-unsub';

    const ACTIVE_SUB_TAG = 'active-sub-tag';
    const ACTIVE_UNSUB_TAG = 'active-unsub-tag';
    const INACTIVE_SUB_TAG = 'inactive-sub-tag';
    const INACTIVE_UNSUB_TAG = 'inactive-unsub-tag';
    const EXPIRE_SUB_TAG = 'expire-sub-tag';
    const EXPIRE_UNSUB_TAG = 'expire-unsub-tag';
    
    protected $activecampaign = null;
    
    function _initSetupForm(Am_Form_Setup $form)
    {
        $el = $form->addAdvRadio('api_type')
            ->setLabel(___('Version of script'))
            ->loadOptions(array(
            '0' => ___('Downloaded on your own server'),
            '1' => ___('Hosted at Activecampaing\'s server')));
        $form->addScript()->setScript(<<<CUT
jQuery(document).ready(function() {
    function api_ch(val){
        jQuery("input[id^=api_key]").parent().parent().toggle(val == '1');
        jQuery("input[id^=api_user]").parent().parent().toggle(val == '0');
        jQuery("input[id^=api_password]").parent().parent().toggle(val == '0');
    }
    jQuery("input[type=radio]").change(function(){ api_ch(jQuery(this).val()); }).change();
    api_ch(jQuery("input[type=radio]:checked").val());
});
CUT
        );
        $form
            ->addText('api_url', array('class' => 'el-wide'))
            ->setLabel("Activecampaign API url\n" .
                        "it should be with http://");
        $form->addSecretText('api_key', array('class' => 'el-wide'))->setLabel('Activecampaign API Key');

        $form->addText('api_user', array('class' => 'el-wide'))->setLabel('Activecampaign Admin Login');
        $form->addSecretText('api_password', array('class' => 'el-wide'))->setLabel('Activecampaign Admin Password');
        $form->addAdvCheckbox('debug')
            ->setLabel("Debug logging\n" .
                'Record debug information in the log');
    }
    
    public function init() 
    {        
        $lists = array('' => '*** None');
        
        $app_lists = $this
            ->getDi()
            ->newsletterListTable
            ->findByPluginId('activecampaign-full');
        
        foreach ($app_lists as $l)
        {
            $lists[$l->plugin_list_id] = $l->title;
        }

        class_exists('Am_Record_WithData', true);

        // AFTER PURCHASE PRODUCT
        // subscribe to
        $f = new Am_CustomFieldSelect(
            self::ACTIVE_SUB, 
            "SUBSCRIBE to Activecampaign List\n"
            . "after ACTIVATE this product");
        $f->options = $lists;
        $this->getDi()->productTable->customFields()->add($f);
        
        // unsubscribe from
        $f = new Am_CustomFieldSelect(
            self::ACTIVE_UNSUB, 
            "UNSUBSCRIBE from Activecampaign List\n"
            . "after ACTIVATE this product");
        $f->options = $lists;
        $this->getDi()->productTable->customFields()->add($f);

        // AFTER NON PAID PRODUCT
        // subscribe to
        $f = new Am_CustomFieldSelect(
            self::INACTIVE_SUB, 
            "SUBSCRIBE to Activecampaign List\nafter NON PAID this product");
        $f->options = $lists;
        $this->getDi()->productTable->customFields()->add($f);
        
        // unsubscribe from
        $f = new Am_CustomFieldSelect(
            self::INACTIVE_UNSUB, 
            "UNSUBSCRIBE from Activecampaign List\n"
            . "after NON PAID this product");
        $f->options = $lists;
        $this->getDi()->productTable->customFields()->add($f);

        // AFTER EXPIRE PRODUCT
        // subscribe to
        $f = new Am_CustomFieldSelect(
            self::EXPIRE_SUB, 
            "SUBSCRIBE to Activecampaign List\n"
            . "after EXPIRE this product");
        $f->options = $lists;
        $this->getDi()->productTable->customFields()->add($f);
        
        // unsubscribe from
        $f = new Am_CustomFieldSelect(
            self::EXPIRE_UNSUB, 
            "UNSUBSCRIBE from Activecampaign List\n"
            . "after EXPIRE this product");
        $f->options = $lists;
        $this->getDi()->productTable->customFields()->add($f);
        //**********************************************************************
        // AFTER PURCHASE PRODUCT
        // subscribe to
        $f = new Am_CustomFieldText(
            self::ACTIVE_SUB_TAG, 
            "Add TAG\n"
            . "after ACTIVATE this product");
        $this->getDi()->productTable->customFields()->add($f);
        
        // unsubscribe from
        $f = new Am_CustomFieldText(
            self::ACTIVE_UNSUB_TAG, 
            "Remove TAG\n"
            . "after ACTIVATE this product");
        $f->options = $lists;
        $this->getDi()->productTable->customFields()->add($f);
        
        // AFTER NON PAID PRODUCT
        // subscribe to
        $f = new Am_CustomFieldText(
            self::INACTIVE_SUB_TAG, 
            "Add TAG\n"
            . "after NON PAID this product");
        $this->getDi()->productTable->customFields()->add($f);
        
        // AFTER NON PAID PRODUCT
        // subscribe to
        $f = new Am_CustomFieldText(
            self::INACTIVE_UNSUB_TAG, 
            "Remove TAG\n"
            . "after NON PAID this product");
        $this->getDi()->productTable->customFields()->add($f);
        
        // AFTER EXPIRE PRODUCT
        // subscribe to
        $f = new Am_CustomFieldText(
            self::EXPIRE_SUB_TAG, 
            "Add TAG\n"
            . "after EXPIRE this product");
        $this->getDi()->productTable->customFields()->add($f);
        
        // AFTER EXPIRE PRODUCT
        // subscribe to
        $f = new Am_CustomFieldText(
            self::EXPIRE_UNSUB_TAG, 
            "Remove TAG\n"
            . "after EXPIRE this product");
        $this->getDi()->productTable->customFields()->add($f);
        
    }

    function isConfigured()
    {
        return ($this->getConfig('api_type')  == 0 && 
                $this->getConfig('api_user') && 
                $this->getConfig('api_password')) ||
                ($this->getConfig('api_type')  == 1 && 
                $this->getConfig('api_key'));
    }

    /** @return Am_ActivecampaignFull_Api */
    function getApi()
    {
        if (!isset($this->api)) {
            $this->api = new Am_ActivecampaignFull_Api($this);
        }
        
        return $this->api;
    }

    function onSubscriptionChanged(
        Am_Event_SubscriptionChanged $event, 
        User $oldUser = null)
    {
        $pAdded = $event->getAdded();
        $pDeleted = $event->getDeleted();
        $user = $event->getUser();
        $lAdded = $lDeleted = array();
        
        $tags_add = array();
        $tags_remove = array();
        
        foreach ($pAdded as $pId)
        {
            $product = $this->getDi()->productTable->load($pId);
            
            if($list = $product->data()->get(self::ACTIVE_SUB))
            {
                if(!in_array($list, $lAdded))
                    $lAdded[] = $list;
            }
            
            if($list = $product->data()->get(self::ACTIVE_UNSUB))
            {
                if(!in_array($list, $lDeleted))
                    $lDeleted[] = $list;
            }
            
            if($tag = $product->data()->get(self::ACTIVE_SUB_TAG))
                $tags_add = array_merge($tags_add, explode (',', $tag));
            
            if($tag = $product->data()->get(self::ACTIVE_UNSUB_TAG))
                $tags_remove = array_merge($tags_remove, explode (',', $tag));
        }

        foreach ($pDeleted as $pId)
        {
            $product = $this->getDi()->productTable->load($pId);
            
            if(
                // expired after all rebill times:
                ($invoiceId = $this
                    ->getDi()
                    ->db
                    ->selectCell(""
                        . "SELECT invoice_id "
                        . "FROM ?_access "
                        . "WHERE user_id=?d "
                        . "AND product_id=?d "
                        . "ORDER BY expire_date "
                        . "DESC",
                    $user->pk(), 
                    $product->pk()))
                && ($invoice = $this->getDi()->invoiceTable->load($invoiceId))
                && ($invoice->status == Invoice::RECURRING_FINISHED)
            ){
                if($list = $product->data()->get(self::EXPIRE_SUB))
                {
                    if(!in_array($list, $lAdded)) {
                        $lAdded[] = $list;
                    }
                }
                
                if($list = $product->data()->get(self::EXPIRE_UNSUB))
                {
                    if(!in_array($list, $lDeleted)) {
                        $lDeleted[] = $list;
                    }
                }
                
                if($tag = $product->data()->get(self::EXPIRE_SUB_TAG))
                {
                    $tags_add = array_merge($tags_add, explode (',', $tag));
                }
                
                if($tag = $product->data()->get(self::EXPIRE_UNSUB_TAG))
                {
                    $tags_remove = array_merge($tags_remove, explode (',', $tag));
                }
                
            } else
            {
                if($list = $product->data()->get(self::INACTIVE_SUB))
                {
                    if(!in_array($list, $lAdded)) {
                        $lAdded[] = $list;
                    }
                }
                
                if($list = $product->data()->get(self::INACTIVE_UNSUB))
                {
                    if(!in_array($list, $lDeleted)) {
                        $lDeleted[] = $list;
                    }
                }
                
                if($tag = $product->data()->get(self::INACTIVE_SUB_TAG))
                {
                    $tags_add = array_merge($tags_add, explode (',', $tag));
                }
                
                if($tag = $product->data()->get(self::INACTIVE_SUB_TAG))
                {
                    $tags_remove = array_merge($tags_remove, explode (',', $tag));
                }
            }
        }
        
        foreach($lAdded as $list) {
            $am_list = $this->getDi()->newsletterListTable->findFirstBy(array(
                'plugin_id' => $this->getId(),
                'plugin_list_id' => $list
            ));
            
            $this->getDi()->newsletterUserSubscriptionTable->add(
                $user, 
                $am_list, 
                NewsletterUserSubscription::TYPE_AUTO);
        }
        
        foreach($lDeleted as $list) 
        {            
            $am_list = $this->getDi()->newsletterListTable->findFirstBy(array(
                'plugin_id' => $this->getId(),
                'plugin_list_id' => $list
            ));
            
            $table = $this
                ->getDi()
                ->newsletterUserSubscriptionTable;
            
            /* @var $record NewsletterUserSubscription */
            if ($record = $table
                ->findFirstBy(array(
                    'user_id' => $user->pk(),
                    'list_id' => $am_list->pk()
            ))) {
            
            //error_log($record->subscription_id);
            
                $record->disable();
            }
        }
        
        if(count($tags_add))
        {
            $api = $this->getApi();
            $api->sendRequest('contact_tag_add', 
                array('email' => $user->email, 'tags' => $tags_add), 
                Am_HttpRequest::METHOD_POST);

        }
        if(count($tags_remove))
        {
            $api = $this->getApi();
            $api->sendRequest('contact_tag_remove', 
                array('email' => $user->email, 'tags' => $tags_remove), 
                Am_HttpRequest::METHOD_POST);

        }
    }

    function changeSubscription(
        User $user, 
        array $addLists, 
        array $deleteLists)
    {
        //error_log('here');
        $api = $this->getApi();
        $acuser = $api->sendRequest(
                'contact_view_email', 
                array('email' => $user->email), 
                Am_HttpRequest::METHOD_GET);
        
        if ($acuser['id'])
        {
            $lists = array();
            
            foreach ($addLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 1;
            }
            
            foreach ($deleteLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 2;
            }
            
            //user exists in ActiveCampaign
            $ret = $api->sendRequest('contact_edit', array_merge(array(
                    'id' => $acuser['subscriberid'],
                    'email' => $user->email,
                    'first_name' => $user->name_f,
                    'last_name' => $user->name_l,
                    'overwrite' => 0
                    ), $lists));
            
            if (!$ret)
                return false;
        } else {            
            $lists = array();
            
            foreach ($addLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 1;
            }
            
            foreach ($deleteLists as $id)
            {
                $lists["p[$id]"] = $id;
                $lists["status[$id]"] = 2;
            }
            
            //user does no exist in ActiveCampaign
            $ret = $api->sendRequest('contact_add', array_merge(array(
                    'email' => $user->email,
                    'first_name' => $user->name_f,
                    'last_name' => $user->name_l
                    ), $lists));
            
            if (!$ret) return false;
        }
        
        return true;
    }

    function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $api = $this->getApi();
        $user = $event->getUser();
        
        $acuser = $api->sendRequest(
                    'contact_view_email', 
                    array('email' => $user->email), 
                    Am_HttpRequest::METHOD_GET);
        
        if (isset($acuser['id']))
        {
            $id = $acuser['id'];
            //error_log($id);
            $api->sendRequest(
                    'contact_edit', 
                    array(
                        'id' => $id,
                        'email' => $user->email,
                        'overwrite' => 0,
                        'first_name' => $user->name_f,
                        'last_name' => $user->name_l,
                        'phone' => $user->phone
                    ), 
                    Am_HttpRequest::METHOD_GET);
            
            
        }
    }

    public function getLists()
    {
        $api = $this->getApi();
        $ret = array();
        
        $lists = $api->sendRequest(
            'list_list', 
            array('ids' => 'all'), 
            Am_HttpRequest::METHOD_GET);
        
        foreach ($lists as $l)
        {
            $ret[$l['id']] = array(
                'title' => $l['name'],
            );
        }
        
        return $ret;
    }

    public function getReadme()
    {
        return <<<CUT
Activecampaign Full plugin readme

This module allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in Activecampaign.

  - copy "API Key" and "API Url" values from your Activecampaign account and 
    insert it into aMember Activecampaign plugin settings (this page) 
    and click "Save"
  - go to aMember CP -> Protect Content -> Newsletters, you will be able to 
    define who and how can subscribe to your Activecampaign lists.
  - Configure for every product list, user need subscribe/unsubscribe basing on
    different events.
CUT;
    }
}

class Am_ActivecampaignFull_Api extends Am_HttpRequest
{
    /** @var Am_Newsletter_Plugin */
    protected $plugin;
    protected $vars = array(); // url params
    protected $params = array(); // request params

    public function __construct(Am_Newsletter_Plugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }

    public function sendRequest(
        $api_action, 
        $params, 
        $method = self::METHOD_POST)
    {
        $this->setMethod($method);
        $this->setHeader('Expect', '');
        $corrid = rand();

        $this->params = $params;
        if ($this->plugin->getConfig('api_type') == 0) {
            $this->vars['api_user'] = $this->plugin->getConfig('api_user');
            $this->vars['api_pass'] = $this->plugin->getConfig('api_password');
        } else {
            $this->vars['api_key'] = $this->plugin->getConfig('api_key');
        }
        $this->vars['api_action'] = $api_action;
        $this->vars['api_output'] = 'serialize';

        if ($method == self::METHOD_POST) {
            $this->addPostParameter($this->params);
            
            $url = $this->plugin->getConfig('api_url') . 
                    '/admin/api.php?' . 
                    http_build_query($this->vars, '', '&');
            
            if($this->plugin->getConfig('debug')) {
                $this->plugin->logDebug("[{$corrid}] ACTIVECAMPAIGN POST REQUEST : $url" . 
                            var_export($this->params, true));
            }
        } else {
            $url = $this->plugin->getConfig('api_url') . 
                    '/admin/api.php?' . 
                    http_build_query($this->vars + $this->params, '', '&');
            
            if($this->plugin->getConfig('debug')) {
                $this->plugin->logDebug("[{$corrid}] ACTIVECAMPAIGN GET REQUEST : $url");
            }
        }
        
        $this->setUrl($url);

        $ret = parent::send();
        
        if (!in_array($ret->getStatus(),array(200,404))) {
            throw new Am_Exception_InternalError(""
                . "[{$corrid}] Activecampaign API Error, "
                . "configured API Key is wrong");
        }
        
        $arr = unserialize($ret->getBody());
        
        if($this->plugin->getConfig('debug')) {
            $this->plugin->logDebug("[{$corrid}] ACTIVECAMPAIGN RESPONSE : " . 
                    var_export($arr, true));
        }
        
        if (!$arr) {
            throw new Am_Exception_InternalError(
                "[{$corrid}] Activecampaign API Error - "
                . "unknown response [" . $ret->getBody() . "]");
        }
        
        if ($arr['result_code'] != 1) {
            $this->plugin->logDebug("[{$corrid}] Activecampaign API Error - "
                    . "code [" . $arr['result_code'] . "]"
                    . "response [" . $arr['result_message'] . "]");
        }
        
        unset(
            $arr['result_code'], 
            $arr['result_message'], 
            $arr['result_output']);
        
        return $arr;
    }
}