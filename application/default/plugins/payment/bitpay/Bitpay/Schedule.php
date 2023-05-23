<?php

namespace Bitpay;

/**
 *
 * @package Bitpay
 */
class Schedule implements ScheduleInterface
{
	public $currency;
	public $price;
	public $quantity;
	public $schedule;
    public $dueDate;
    public $token;
    public $items;
    public $name;
    public $email;
    
	
	function getSchedule() 
	{
		return $this->schedule;
	}

	public function getBillData() {
		return array(
			'currency' => $this->currency,
			'price' => $this->price,
			'quantity' => $this->quantity,
            'dueDate' => $this->dueDate,
            'token' => $this->token,
            'items' => $this->items,
            'name' => $this->name,
            'email' => $this->email
		);
	}
}

