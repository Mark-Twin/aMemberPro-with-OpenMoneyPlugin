<?php
/**
 * @table integration
 * @id efront
 * @title eFront
 * @visible_link http://www.efrontlearning.net/
 * @hidden_link http://www.amember.com/forum/showthread.php?t=8632
 * @description eFront is an open-source, easy to use and administer,
 * eLearning (LMS) system
 * @different_groups 1
 * @single_login 1
 * @type Content Management Systems (CMS)
 */
class Am_Protect_Efront extends Am_Protect_Abstract
{

    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_FREE;
    const PLUGIN_REVISION = '5.5.4';

    const EXPACT_DEACTIVATE = 'deactivate';
    const EXPACT_UNENROLL = 'unenroll';
    //// eFront API;
    protected $api;
    const EFRONT = 'efront';
    const USER_NEED_SETPASS = 'user_need_setpass';

    public function getPasswordFormat()
    {
        return self::EFRONT;
    }

    public function cryptPassword($pass, &$salt = null, User $user = null)
    {
        if(is_null($salt)){
            $salt = md5(uniqid());
        }
        $c = new Am_Crypt_Compat($salt);
        return $c->encrypt($pass);
    }

    function isConfigured()
    {
        return  $this->getConfig('api_url') &&
                $this->getConfig('login') &&
                $this->getConfig('password');
    }

    function onSetupForms(Am_Event_SetupForms $event)
    {
        $form = new Am_Form_Setup($this->getId());

        $fs = $form->addFieldset()->setLabel(___('API Authentication Information'));
        $fs->addText('api_url', array('class' => 'el-wide'))
            ->setLabel("API URL\n" .
                'example: http://mydomain.com/efront/www/api2.php');
        $fs->addText('login')
            ->setLabel("eFront Administrative Login\n" .
                'must be an administrative account');
        $fs->addPassword('password')
            ->setLabel("eFront Password\n" .
                'must not contain any special characters such as &');

        $fs = $form->addFieldset()->setLabel(___('Settings'));
        $fs->addSelect("expact", array(), array('options' => array(
                    self::EXPACT_DEACTIVATE => ___('Deactivate Student'),
                    self::EXPACT_UNENROLL => ___('Unenroll Student')
                )
            ))
            ->setLabel("Action on Expire\n" .
                'Note: Unenrolling deletes student progress');
        $fs->addAdvCheckbox('log')->setLabel(___('Log all API Requests'));


        $form->addFieldsPrefix("protect.{$this->getId()}.");
        if ($plugin_readme = $this->getReadme())
        {
            $plugin_readme = str_replace(
                array('%root_url%', '%root_surl%', '%root_dir%'), array(ROOT_URL, ROOT_SURL, ROOT_DIR), $plugin_readme);
            $form->addEpilog('<div class="info"><pre>' . $plugin_readme . '</pre></div>');
        }
        $event->addForm($form);
    }

    function getAPI()
    {
        if(!$this->api) {
            $this->api = new Am_Efront_API($this);
        }
        return $this->api;
    }

    function getGroups()
    {
        $groups = array('' => ' -- ');
        $res = $this->getAPI()->groups();
        if(@$res->status == Am_Efront_API::STATUS_ERROR) return array();
        foreach(@$res->groups->group as $g){
            $groups[(string)$g->id] = (string)$g->name;
        }
        return $groups;
    }

    function getCourses()
    {
        $courses = array('' =>' -- ');
        $res = $this->getAPI()->catalog();
        if(@$res->status == Am_Efront_API::STATUS_ERROR) return array();
        foreach(@$res->catalog->courses->course as $c){
            if($c->active == 1) $courses[$c->id."_course"] = "Course: " . $c->name;
        }
        foreach(@$res->catalog->lessons->lesson as $l){
            if($l->active == 1 && $l->course_only=='0') $courses[$l->id."_lesson"] = "Lesson: " . $l->name;
        }
        return $courses;
    }

    function getIntegrationFormElements(HTML_QuickForm2_Container $group)
    {
        parent::getIntegrationFormElements($group);
        $groups = $this->getGroups();
        $courses = $this->getCourses();

        $group->addSelect('group', array(), array('options'=>$groups))
            ->setLabel("eFront Groups\n" .
                'Users subscribed to this product will beadded to the following Group(s) in eFront');
        $group->addSelect('course', array(), array('options'=>$courses))
            ->setLabel("eFront Subscriptions\n" .
                'Users subscribed to this product will be added to the following Course(s)/Lesson(s) in eFront');
    }

    public function getIntegrationTable()
    {
        if (!$this->_integrationTable)
            $this->_integrationTable = $this->getDi()->integrationTable;
        return $this->_integrationTable;
    }

    function calculateGroups(User $user=null,$var='group')
    {
        $groups = array();
        if ($user && $user->pk())
        {
            foreach ($this->getIntegrationTable()->getAllowedResources($user, $this->getId()) as $integration)
            {
                $vars = unserialize($integration->vars);
                $groups[] = $vars;
            }
        }
        $ret = array();
        foreach ($groups as $config)
            $ret[] = $config[$var];

        $ret = array_filter($ret);
        return $ret;
    }

    function getPass(User $user)
    {
        $pass = $this->getDi()->savedPassTable->findSaved($user, $this->getPasswordFormat());
        if(is_null($pass)) return md5(uniqid());
        $c = new Am_Crypt_Compat($pass->salt);
        return $c->decrypt($pass->pass);
    }

    function onSubscriptionChanged(Am_Event_SubscriptionChanged $event, User $oldUser = null)
    {
        if ($oldUser === null) $oldUser = $event->getUser();
        $user = $event->getUser();
        foreach(array('group', 'course') as $v){
            $$v = $this->calculateGroups($user,$v);
        }

        $pass = $this->getPass($user);
        $r = $this->getAPI()->user_info(array('login' => $user->login));
        if(is_null($r)) return;
        if($r->status == Am_Efront_API::STATUS_ERROR && $r->message == Am_Efront_API::ERR_USER_NOTFOUND){
            if(!$group && !$course) return; // Add user only if he should be added to efront;
            $r = $this->getAPI()->create_user(array(
                'login' => $user->login,
                'name'=>$user->name_f,
                'surname'=>$user->name_l,
                'email' =>  $user->email,
                'password'  =>  $pass,
                'languages' =>  'english'
                ));
        } else {
            $this->getAPI()->update_user(array(
                    'login' =>  $user->login,
                    'email' =>  $user->email,
                    'name'  =>  $user->name_f,
                    'surname'   =>  $user->name_l,
                    'password'  =>  $pass
                    ));
        }
        $this->updateGroups($user);
    }

    function updateGroups($user)
    {
        foreach(array('group', 'course') as $v){
            $$v = $this->calculateGroups($user,$v);
        }

        // Get available groups;
        $a_groups = array_keys((array)$this->getGroups());
        if($a_groups){
            $gr_to_add = $group;
            $gr_to_remove  = array_diff($a_groups, $group);
            foreach((array) $gr_to_remove as $g){
                $this->getAPI()->group_from_user(array('login'=>$user->login, 'group' => $g));
            }
            foreach((array) $gr_to_add as $g){
                $this->getAPI()->group_to_user(array('login'=>$user->login, 'group' => $g));
            }
        }
        // now get info about all courses and lessons that user have.
        $a_course = array();
        foreach($this->getAPI()->user_lessons(array('login'=>$user->login))->lesson as $l){
            $a_course[] = $l->id."_lesson";
        }
        foreach($this->getAPI()->user_courses(array('login'=>$user->login))->course as $c){
            $a_course[] = $c->id."_course";
            foreach($this->getAPI()->course_lessons(array('course' => (string)$c->id))->lessons->lesson as $l){
                $key = array_search($l->id."_lesson", $a_course);
                if($key !== false){
                    unset($a_course[$key]);
                }
            }
        }
        $c_to_add    = $course;
        $c_to_remove = array_diff($a_course, $course);
        foreach($c_to_add as $c){
            list($id, $type) = explode("_", $c);
            $r = call_user_func(array($this->getAPI(), $type."_to_user"), array('login'=>$user->login, $type=>$id, 'type'=>'student'));

            call_user_func(array($this->getAPI(), 'activate_user_'.$type), array('login' => $user->login, $type=>$id));

        }

        foreach($c_to_remove as $c){
            list($id, $type) = explode("_", $c);

            if($this->getConfig('expact', self::EXPACT_DEACTIVATE) == self::EXPACT_UNENROLL)
                call_user_func(array($this->getAPI(), $type."_from_user"), array('login'=>$user->login, $type=>$id));
            else
                call_user_func(array($this->getAPI(), "deactivate_user_".$type), array('login'=>$user->login, $type=>$id));
        }
    }

    function onUserAfterUpdate(Am_Event_UserAfterUpdate $event)
    {
        $e = new Am_Event_SubscriptionChanged($event->getUser(), array(), array());
        return $this->onSubscriptionChanged($e, $event->getOldUser());
    }

    function onSetPassword(Am_Event_SetPassword $event)
    {
        $user = $event->getUser();
        $r=$this->getAPI()->user_info(array('login' =>$user->login));
        if ($r->status != Am_Efront_API::STATUS_ERROR)
        {
            $pass = $this->getPass($user);
            $this->getAPI()->update_user(array(
                    'login' =>  $user->login,
                    'email' =>  $user->email,
                    'name'  =>  $user->name_f,
                    'surname'   =>  $user->name_l,
                    'password'  =>  $pass
                    ));
            $user->data()->set(self::USER_NEED_SETPASS, null)->update();
        }
    }

    function onUserAfterDelete(Am_Event_UserAfterDelete $event)
    {
        $this->getAPI()->remove_user(array("login"=>$event->getUser()->login));
    }

    function onCheckUniqLogin(Am_Event_CheckUniqLogin $event)
    {
        if ($this->getAPI()->user_info(array('login' => $event->getLogin()))->status == Am_Efront_API::STATUS_OK)
            $event->setFailureAndStop();
    }

    public function onRebuild(Am_Event_Rebuild $event)
    {
        $batch = new Am_BatchProcessor(array($this, 'batchProcess'));

        $context = $event->getDoneString();
        $this->_batchStoreId = 'rebuild-' . $this->getId() . '-' . $this->getDi()->session->getId();
        if ($batch->run($context))
        {
            $event->setDone();
            $this->getDi()->store->delete($this->_batchStoreId);
        } else
        {
            $event->setDoneString($context);
        }
    }

    public function batchProcess(&$context, Am_BatchProcessor $batch)
    {
        @list($step, $start) = explode('-', $context);
        $pageCount = 30;
                $q = new Am_Query($this->getDi()->userTable);
                $count = 0;
                $records = $q->selectPageRecords($start / $pageCount, $pageCount);
                foreach ($records as $user)
                {
                    $count++;
                    /* @var $user User */
                    $this->onSubscriptionChanged(new Am_Event_SubscriptionChanged($user, array(), array()));
                }
                if (!$count)
                {
                    $context = null;
                    return true;
                }
                $start += $count;
                $context = "0-$start";
    }

    function onAuthAfterLogin(Am_Event_AuthAfterLogin $event)
    {
        if ($this->skipAfterLogin)
            return;
        $r = $this->getAPI()->user_info(array('login' => $event->getUser()->login));
        if ($r->status == Am_Efront_API::STATUS_ERROR)
            return;

        // there we handled situation when user was added without knowledge of password
        // @todo implement situation when we have found there is not password
        // in related user record during login
        if ($event->getPassword() && $event->getUser()->data()->get(self::USER_NEED_SETPASS))
        {
            $user = $event->getUser();
            $user->setPass($event->getPassword());
            $user->save();
            $user->data()->set(self::USER_NEED_SETPASS, null)->update();
        }
        define("G_MD5KEY", 'cDWQR#$Rcxsc');
        Am_Cookie::set("cookie_login", $event->getUser()->login, time()+3600, '/', $this->getDi()->request->getHttpHost());
        Am_Cookie::set("cookie_password", md5($this->getPass($event->getUser()).G_MD5KEY),time()+3600, '/', $this->getDi()->request->getHttpHost());
        $this->getAPI()->efrontlogin(array('login' =>$event->getUser()->login));

    }

    function onAuthAfterLogout(Am_Event_AuthAfterLogout $event)
    {
        Am_Cookie::set('cookie_login', '', time()-3600*24, '/', $this->getDi()->request->getHttpHost());
        Am_Cookie::set('cookie_password', '', time()-3600*24, '/', $this->getDi()->request->getHttpHost());
        $this->getAPI()->efrontlogout(array('login' =>$event->getUser()->login));
    }

    function getReadme()
    {
        return <<<CUT
<b>eFront plugin</b>

This plugin provides product and user management integration between aMember and eFront (v3.6.7+)
<b>1.</b> Enable in Admin CP -> Plugins -> eFront
<b>2.</b> Configure eFront admin userid, password and full path to API2

   <b>Example:</b> http://www.mydomain.com/efront/www/api2.php

<b>3.</b> Configure subscription on product by product basis(amember CP -> Protect Content -> Integrations)
<b>4.</b> In eFront, logged on as Administrator, go to System Configuration:
   Enable API module, and make sure to allow your server IP address to list
   of allowed IP for API access.

   Set "Redirect after logout to:" to http://mydomain.com/amember/logout

Direct support questions to: support@cgi-central.net

<b>Note:</b> Be sure to sanitize your course / lesson names from any high ascii characters
      such as & and # as it may cause problems when building the course / lesson list or updating their profile.
      To avoid possible issues with single login between aMember and Efront, edit /amember/application/configs/config.php
      and add this line to  the top of the file after &lt;?php line:
      define('AM_SESSION_NAME', 'AMSESSID');
CUT;

    }
}

class Am_Efront_API
{
    const ERR_INVALID_TOKEN = 'Invalid token';
    const ERR_USER_ALREADY_EXISTS = 'User already exists';
    const ERR_USER_NOTFOUND = 'User does not exist';
    const ERR_ASSIGMENT_EXISTS = 'Assignment already exists';
    const STATUS_OK = 'ok';
    const STATUS_ERROR = 'error';


    const TOKEN_REFRESH_REQUIRED = true;
    /**
     * @param Am_Protect_Efront $_plugin - plugin;
     */
    protected $_plugin;

    /**
     * @param Am_Di $_di - Am_Di;
     */
    protected $_di;
    protected $_token = null;

    function __construct(Am_Protect_Efront $plugin)
    {
        $this->_plugin = $plugin;
    }

    function getUrl()
    {
        return $this->getPlugin()->getConfig('api_url');
    }

    function logEnabled()
    {
        return (bool)$this->getPlugin()->getConfig('log', false);
    }

    function getLogin()
    {
        return $this->getPlugin()->getConfig('login');
    }

    function getPassword()
    {
        return $this->getPlugin()->getConfig('password');
    }

    /**
     *
     * @param type $action
     * @param type $args
     * @return SimpleXMLElement $response
     */
    function httpRequest($action, $args=array())
    {
        $url = sprintf("%s?%s", $this->getUrl(), http_build_query(array_merge(array('action' => $action), $args), '', '&'));
        $r = new Am_HttpRequest($url);
        $resp = $r->send();
        if ($resp->getStatus() != 200)
        {
            throw new Am_Exception_Efront_API_Transport("Unable to load eFront API. Status is not 200");
        }
        if ($this->logEnabled())
            $this->getPlugin()->logDebug($url . " RESULT: " . $resp->getBody());
        
        return $this->parseResponse($resp->getBody());
    }

    function getToken()
    {
        if(is_null($this->_token))
            $this->_token = $this->getPlugin()->getConfig("token", null);
        return $this->_token;
    }

    function setToken($token)
    {
        $this->_token = $token;
        $this->getDi()->config->saveValue("protect.{$this->getPlugin()->getId()}.token", $this->_token);
    }

    function getDi()
    {
        return $this->_plugin->getDi();
    }

    function getPlugin()
    {
        return $this->_plugin;
    }


    function token($refresh = false)
    {
        $token = $this->getToken();
        if(!$refresh && !is_null($token)) return $token;

        try{
            $r = $this->httpRequest('token');
            if($token = (string) $r->token){
                $this->setToken($token);
                // now login
                $r = $this->httpRequest('login', array('token'=>$token,'username'=>$this->getLogin(), 'password'=>$this->getPassword()));
                if((string) $r->status != self::STATUS_OK)
                    throw new Am_Exception_Efront_API_Transport("Unable to login! ".(string)$r->message);
                return $token;
            }
        }catch(Exception $e){
            $this->getDi()->errorLogTable->logException($e);
            return null;
        }
    }

    function __call($name, $args=array())
    {
        $refresh = false;
        $args = array_pop($args);
        do{
            try{
                $token = $this->token($refresh);
                if(is_null($token)&&$refresh)
                    throw new Am_Exception_Efront_API_Transport("Can't get token!");
                $args['token'] = $token;
                $r = $this->httpRequest($name, $args);
                if( ((string)$r->status == self::STATUS_ERROR) && ((string) $r->message == self::ERR_INVALID_TOKEN)){
                    throw new Am_Exception_Efront_API_InvalidToken($r->message);
                }
                return $r;
            }catch(Am_Exception_Efront_API_InvalidToken $e){
                $refresh = true;
            }catch(Exception $e){
                $this->getDi()->errorLogTable->logException($e);
                return null;
            }

        }while(true);
    }

    function parseResponse($response)
    {
        $xml = new SimpleXMLElement(preg_replace('/&(?!amp;)/','&amp;',$response));
        return $xml;
    }
}

class Am_Exception_Efront_API_Error extends Am_Exception
{

}

class Am_Exception_Efront_API_InvalidToken extends Am_Exception
{

}

class Am_Exception_Efront_API_Transport extends Am_Exception
{

}