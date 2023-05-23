<?php
class Am_Newsletter_Plugin_Madmimi extends Am_Newsletter_Plugin
{
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('username', array('size' => 20))
            ->setLabel('Madmimi Username')
            ->addRule('required');        
        $form->addSecretText('api_key', array('size' => 40))
            ->setLabel('Madmimi API Key')
            ->addRule('required');
    }

    public function isConfigured()
    {
        return $this->getConfig('api_key') && $this->getConfig('username');
    }

    /** @return Am_Plugin_Madmimi */
    function getApi()
    {
        return new Am_Madmimi_Api($this);
    }
    
	function escape_for_csv($s) {
		// Watch out! We may have quotes! So quote them.
		$s = str_replace('"', '""', $s);
		if(preg_match('/,/', $s) || preg_match('/"/', $s) || preg_match("/\n/", $s)) {
			// Quote the whole thing b/c we have a newline, comma or quote.
			return '"'.$s.'"';
		} else {
			// False alarm. We're good.
			return $s;
		}
	}

    function build_csv(User $user) {
        $arr = array(
            'email' => 'email',
            'name_f' => 'firstName',
            'name_l' => 'lastName'
        );
		$csv = "";
		foreach ($arr as $madmimi_field_name) {
			$value = $this->escape_for_csv($madmimi_field_name);
			$csv .= $value . ",";
		}
		$csv = substr($csv, 0, -1);
		$csv .= "\n";
		foreach (array_keys($arr) as $amember_field_name) {
			$value = $this->escape_for_csv($user->get($amember_field_name));
			$csv .= $value . ",";
		}
		$csv = substr($csv, 0, -1);
		$csv .= "\n";
		return $csv;
	}

    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $api = $this->getApi();
        $users = new SimpleXMLElement($res = $api->sendRequest('/audience_members/search.xml',array('query'=>rawurlencode($user->email))));
        if(!@count($users))
        {
            $api->sendRequest('/audience_members',array('csv_file'=>$this->build_csv($user)),  Am_HttpRequest::METHOD_POST);
        }
        foreach ($addLists as $list_id)
        {
            $list_id=rawurlencode($list_id);
            $api->sendRequest("/audience_lists/$list_id/add",array('email'=>$user->email),  Am_HttpRequest::METHOD_POST);
        }
        foreach ($deleteLists as $list_id)
        {
            $list_id=rawurlencode($list_id);
            $api->sendRequest("/audience_lists/$list_id/remove",array('email'=>$user->email),  Am_HttpRequest::METHOD_POST);
        }
        return true;
    }

    public function getLists()
    {
        $api = $this->getApi();
        $xml = new SimpleXMLElement($api->sendRequest('/audience_lists/lists.xml'));
        $lists = array();
        foreach (@$xml as $l)
            $lists[(string)$l['name']] = array('title'=>(string)$l['name']);
        return $lists;
    }
    
    public function getReadme()
    {
        return <<<CUT
   Madmimi plugin readme
       
This module allows aMember Pro users to subscribe/unsubscribe from e-mail lists
created in Madmimi. To configure the module:

 - go to <a target='_blank' rel="noreferrer" href='https://madmimi.com/user/edit'>www.madmimi.com -> Account -> API</a>
 - if no "API Keys" exists, click "Regenarate API Key" button
 - copy "API Key" value and insert it into aMember Madmimi plugin settings (this page) and click "Save"
 - go to aMember CP -> Protect Content -> Newsletters, you will be able to define who and how can 
   subscribe to your Madmimi lists.
   
   

CUT;
    }
}

class Am_Madmimi_Api extends Am_HttpRequest
{
    /** @var Am_Plugin_Madmimi */
    protected $plugin;
    
    public function __construct(Am_Newsletter_Plugin_Madmimi $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct();
    }
    public function sendRequest($path, $params = array(), $method = self::METHOD_GET)
    {
        $this->setMethod($method);        
        $this->setHeader('Expect','');
        $params['username'] = $this->plugin->getConfig('username');
        $params['api_key'] = $this->plugin->getConfig('api_key');
        if($method == self::METHOD_GET)
            $this->setUrl($url = 'http://api.madmimi.com'.$path. '?' . http_build_query($params, '', '&'));
        else
        {
            $this->setUrl($url = 'http://api.madmimi.com'.$path);
            foreach($params as $name => $value)
                $this->addPostParameter($name, $value);
        }
        $ret = parent::send();
        if ($ret->getStatus() != '200')
        {
            throw new Am_Exception_InternalError("Madmimi API Error, configured API Key is wrong");
        }
        return $ret->getBody();
    }
}