<?php

include_once 'myshortcartbase.php';
include_once 'transaction.php';

class Am_Paysystem_Myshortcart extends Am_Paysystem_Myshortcart_Base 
{
	protected $defaultTitle = 'Myshortcart (Doku Wallet)';

	protected function getPaymentMethod() {
		return self::METHOD_DOKU;
	}
}