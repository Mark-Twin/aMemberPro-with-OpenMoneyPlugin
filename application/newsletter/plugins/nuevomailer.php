<?php

class Am_Newsletter_Plugin_Nuevomailer extends Am_Newsletter_Plugin
{
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        parent::_initSetupForm($form);
        $form->addText('install_url')->setLabel(___('NuevoMailer installation URL'));
        $form->addSecretText('api_key')->setLabel(___('API Key'));
        $form->addAdvCheckbox('api_send_email')->setLabel(___('Send opt-in/out emails'));
        $form->addAdvCheckbox('double_optin')->setLabel(___('Enable double opt-in'));
    }
    
    public
        function changeSubscription(\User $user, array $addLists, array $deleteLists)
    {
        if(!empty($addLists))
        {
            $req = new Am_HttpRequest($this->getConfig('install_url').'/subscriber/optIn.php', Am_HttpRequest::METHOD_POST);
            foreach(array(
                'api_action'        =>  'add',
                'api_key'           =>  $this->getConfig('api_key'),
                'api_send_email'    =>  $this->getConfig('api_send_email')? 'yes' : 'no',
                'email'             =>  $user->email,
                'double_optin'      =>  $this->getConfig('double_optin', 0),
                'lists'             =>  implode(',',$addLists)
            ) as $k=>$v)
            {
                $req->addPostParameter($k, $v);
            }
            $req->send();
        }
        
        if(!empty($deleteLists))
        {
            $req = new Am_HttpRequest($this->getConfig('install_url').'/subscriber/optOut.php', Am_HttpRequest::METHOD_POST);
            foreach(array(
                'api_action'        =>  'remove',
                'api_key'           =>  $this->getConfig('api_key'),
                'api_send_email'    =>  $this->getConfig('api_send_email')? 'yes' : 'no',
                'email'             =>  $user->email,
                'opt_out_type'      =>  1,
                'lists'             =>  implode(',',$addLists)
            ) as $k=>$v)
            {
                $req->addPostParameter($k, $v);
            }
            $req->send();
            
        }
        
        return true;
    }
   function getLists(){
        $ret = array();
        $req = new Am_HttpRequest($this->getConfig('install_url').'inc/getLists.php?api_key='.$this->getConfig('api_key'));
        try{
            $resp = $req->send();
            if($resp->getStatus() != 200)
                throw new Am_Exception_InternalError('Nuevomailer getLists api is not available!');
            
            $lists = @json_decode($resp->getBody(), true);
            if(empty($lists))
                throw new Am_Exception_InternalError('Unable to fetch lists from noevomailer');
            
        }catch(Exception $e){
            $this->getDi()->errorLogTable->logException($e);
            return $ret;
        }
        
        foreach($lists as $r){
            $ret[$r['idList']] = array('title' => $r['listName']);
        }
        return $ret;
    }

}

