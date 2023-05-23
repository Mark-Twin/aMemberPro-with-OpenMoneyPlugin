<?php

include_once 'myshortcart/myshortcartbase.php';
include_once 'myshortcart/transaction.php';

class Am_Paysystem_MyshortcartBca extends Am_Paysystem_Myshortcart_Base 
{
	protected $defaultTitle = 'Myshortcart (BCA Klikpay)';
	
	protected function getPaymentMethod() {
		return self::METHOD_BCA;
	}
}

