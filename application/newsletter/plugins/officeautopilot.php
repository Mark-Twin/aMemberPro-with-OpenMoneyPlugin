<?php

class Am_Newsletter_Plugin_Officeautopilot extends Am_Newsletter_Plugin
{
    function sendRequest($data, $method)
    {
        $request = new Am_HttpRequest('http://api.moon-ray.com/cdata.php',  Am_HttpRequest::METHOD_POST);
        $request->addPostParameter(array(
            'appid' => $this->getConfig('app_id'),
            'key' => $this->getConfig('app_key'),
            'return_id' => 1,
            'reqType' => $method,
            'data' => $data            
        ));
        $ret = $request->send();
        if ($ret->getStatus() != '200')
        {
            throw new Am_Exception_InternalError("Officeautopilot API Error");
        }
        
        $res = $ret->getBody();
        if (preg_match("|<error>(.*)</error>|",$res,$r))
        {
            throw new Am_Exception_InternalError("Officeautopilot API Error - unknown response [" . $r[1] . "]");
        }
        return $res;
    }
    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('app_id', array('size' => 40))->setLabel('OfficeAutoPilot App ID')->addRule('required');
        $form->addSecretText('app_key', array('size' => 40))->setLabel('OfficeAutoPilot App KEY')->addRule('required');

    }

    public function isConfigured()
    {
        return $this->getConfig('app_id') && $this->getConfig('app_key');
    }

    public function getLists()
    {
        $res = array();
        $xml = simplexml_load_string($this->sendRequest("","fetch_sequences"));
        foreach($xml->sequence as $s)
            $res[strval($s['id'])] = array('title' => strval($s));
        return $res;
    }
	function get_user_xml(User $user,$lists,$id="")
	{
		$lists = '*/*'.implode('*/*',$lists).'*/*';
		return "<contact id='$id'>
			<Group_Tag name='Contact Information'>
			<field name='First Name'>{$user->name_f}</field>
			<field name='Last Name'>{$user->name_l}</field>
			<field name='E-Mail'>{$user->email}</field>
			<field name='City'>{$user->city}</field>
			<field name='State'>{$user->state}</field>
			<field name='Zip Code'>{$user->zip}</field>
			<field name='Country'>{$user->country}</field>
			<field name='Address'>{$user->street}</field>
			</Group_Tag>
			<Group_Tag name='Sequences and Tags'>
			<field name='Sequences'>$lists</field>
			</Group_Tag>
			</contact>";
	}
    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        $data= '<search><equation>
		<field>E-Mail</field><op>e</op>
		<value>'.$user->email.'</value>
		</equation></search>';
        $xml = simplexml_load_string($this->sendRequest($data,"search"));
        if($id = intval($xml->contact['id']))
        {
            $sequences = '';
            foreach($xml->contact->Group_Tag as $group_tag)
                if(strval($group_tag['name']) == 'Sequences and Tags')
                    foreach($group_tag->field as $field)
                        if(strval($field['name']) == 'Sequences')
                            $sequences = strval($field);
            $lists = explode('*/*',$sequences);
            $lists = array_filter($lists);
            $lists = array_merge($lists, $addLists);
            $lists = array_diff($lists,$deleteLists);
            $lists = array_unique($lists);
            $res = $this->sendRequest($this->get_user_xml($user,$lists,$id),"update");
        }
        else
        {
            $res = $this->sendRequest($this->get_user_xml($user,$addLists,''),"add");
        }
        return true;
    }
}
