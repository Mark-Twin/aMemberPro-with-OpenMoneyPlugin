<?php

include_once 'application/default/controllers/AdminEmailController.php';

class Am_Newsletter_Plugin_Smtp extends Am_Newsletter_Plugin
{
	const URL = 'http://sendapi.smtp.com/transsend/api/send';
	private $api;
	
	public function changeSubscription(
		\User $user, 
		array $addLists, 
		array $deleteLists) {
		// TODO: implement
	}
	
	function init() {
		//$this->getDi()->hook->add(Am_Event::MAIL_SIMPLE_TEMPLATE_BEFORE_PARSE, array($this, 'onMailSimpleTemplateBeforeParse'));
		
		if ($this->isConfigured()) {
			$this->api = new SmtpApi($this->getConfig('api_key'));
		}
	}
	
	function _initSetupForm(\Am_Form_Setup $form) {
		$form->addSecretText('api_key', array('size' => 80))
			->setLabel("API Key")
			->addRule(
				'regex', 
				'API key is 40 lowercase hexadecimal digits', 
				'/^[a-z0-9]{40}$/');
	}
	
	function isConfigured() {
		return $this->getConfig('api_key');
	}
	
	function onMailSimpleTemplateBeforeParse(Am_Event $e) {
		/* @var $m Am_Mail */
		$m = $e->getMail();
		$header = array('unique_args' => array('CampaignID' => 'DDF38'));
		$m->addHeader('X-SMTPAPI', $header);
		$this->getDi()->errorLogTable->log(print_r($m->getHeaders(), 1));
	}
}

class SmtpController extends Am_Mvc_Controller
{
	/** @var SmtpApi Api */
	private $api;
		
	function init() {
		//error_log('here');

		$apiKey = $this->getDi()->config->get('newsletter.smtp.api_key');
		$this->api = new SmtpApi($apiKey);
	}
	
	function notifyAction() {
		$request = $this->getDi()->request;
		$json = $request->getRawBody();
		$arr = json_decode($json, true);
		
		$message = print_r($arr, 1);
		
		$this->getDi()->errorLogTable->log($message);
	}
}

class SmtpApi
{
	const URL = 'http://sendapi.smtp.com/transsend/api/';
	private $apiKey;
	
	public function __construct($key) {
		$this->apiKey = $key;
	}
	
	function send(array $vars)
    {
        $vars['ApiKey'] = $this->apiKey;
		$req = new Am_HttpRequest(
				self::URL . 'send', 
				Am_HttpRequest::METHOD_POST);
		
        $req->addPostParameter($vars);
        
		//error_log(print_r($vars, 1));
		
		return $req->send();
    }
}