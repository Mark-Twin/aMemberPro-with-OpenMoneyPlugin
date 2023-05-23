<?php

include_once 'myshortcart/myshortcartbase.php';
include_once 'myshortcart/transaction.php';

class Am_Paysystem_MyshortcartAlfa extends Am_Paysystem_Myshortcart_Base 
{
	protected $defaultTitle = 'Myshortcart (Alfa Group)';
	
	protected function getPaymentMethod() {
		return self::METHOD_ALFA;
	}
}
