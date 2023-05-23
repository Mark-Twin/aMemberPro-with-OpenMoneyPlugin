<?php
/**
 * @table paysystems
 * @id bitpay
 * @title BitPay
 * @visible_link https://bitpay.com/
 * @recurring none
 * @logo_url bitpay.png
 */

spl_autoload_register(function ($class)
{
  $prefix = 'Bitpay\\';
  $base_dir = dirname(__FILE__) . '/bitpay/Bitpay/';
  $len = strlen($prefix);

  if (strncmp($prefix, $class, $len) !== 0) {
    return;
  }

  $relative_class = substr($class, $len);
  $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

  if (file_exists($file)) {
    require $file;
  }
}, true, true);

class Am_Paysystem_Bitpay extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'BitPay';
    protected $defaultDescription = 'Pay with BitCoins';

    const BITPAY_INVOICE_ID = 'bitpay-invoice-id';

    private $transactionSpeedOptions = array(
        1 => 'Low: 6 confirmations, 30 minutes to 1 hour or more',
        2 => 'Medium: 3 confirmations, approximately 10-30 minutes',
        3 => 'High: Instant, for low priced digital products that require no confirmation'
    );

	private $transactionSpeed = array(
        1 => 'low',
        2 => 'medium',
        3 => 'high'
    );

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    function init()
    {
        parent::init();
        Am_Di::getInstance()->productTable->customFields()->add(
            new Am_CustomFieldSelect('bitpay_speed_risk', 'Bitpay speed/risk', null, null,
                array('options' => array('' => 'Using plugin settings') + $this->transactionSpeedOptions))
        );
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $pre = "payment.{$this->getId()}";
        if (!empty($_GET['reset_bitpay_token'])) {
            $this->getDi()->config->saveValue($pre, array());
            return Am_Mvc_Response::redirectLocation($this->getDi()->url("admin-setup/{$this->getId()}", false));
        }
        if(!($pkey = $this->getConfig('pkeys.public')))
        {
            $private = new \Bitpay\PrivateKey($this->getDi()->data_dir . '/bitpay-private.key');
            $public  = new \Bitpay\PublicKey($this->getDi()->data_dir . '/bitpay-public.key');
            $private->generate();
            $public->setPrivateKey($private);
            $public->generate();
            $manager = new \Bitpay\KeyManager(new \Bitpay\Storage\EncryptedFilesystemStorage('password'));
            $manager->persist($private);
            $manager->persist($public);
            $this->getDi()->config->saveValue("$pre.pkeys",array(
                'public' => serialize($public),
                'private' => serialize($private)));
        } else {
            $public  = unserialize($pkey);
            $private  = unserialize($this->getConfig('pkeys.private'));
        }
        if(!($this->getConfig('token')))
        {
            $client = $this->getClient(serialize($public), serialize($private));
            $sin = \Bitpay\SinKey::create()->setPublicKey($public)->generate();
            try {
                $token = $client->createToken(array(
                    'facade' => 'merchant',
                    'label' => 'aMember PRO',
                    'id' => (string) $sin));
            } catch (\Exception $e) {
                $this->getDi()->errorLogTable->logException($e);
            }
            if($token)
            {
                $this->getDi()->config->saveValue("$pre.token", $tk_ = $token->getToken());
                $this->getDi()->config->saveValue("$pre.pairing_code", $pc_ = $token->getPairingCode());
            }
        }

		if ($tk = $this->getConfig('token', @$tk_))
        {
            $_token = $form->addText('token_value')->setLabel(___("Token value"))->toggleFrozen(true);
            $form->setDefault('token_value', $tk);
            if($tk != $this->getConfig('token_ok'))
            {
                $client = $this->getClient(serialize($public), serialize($private), $tk);
                try
                {
                    $res = $client->getTokens();
                    $this->getDi()->config->saveValue("$pre.token_ok", $tk);
                } catch (Exception $ex) {
                    $form->addStatic()->setContent('<a href="https://bitpay.com/api-access-request?pairingCode=' . $this->getConfig('pairing_code', @$pc_) . '" target="_blank">Activate Token Code</a>')
                    ->setLabel(___("Follow the link to activate the token"));
                }
            }
            $form->addStatic()->setContent('<a href="?reset_bitpay_token=1" >Reset Token</a>')
                ->setLabel(___("Follow the link to reset the token"));
        }

        $form->addSelect('bitpay_speed_risk')
            ->setLabel('Default Bitpay speed/risk')
            ->loadOptions($this->transactionSpeedOptions);
    }

	function isConfigured()
    {
		return $this->getConfig('token_value') && $this->getConfig('pairing_code');
	}

    public function isNotAcceptableForInvoice(Invoice $invoice)
    {
        if ($invoice->rebill_times) {
            if (!(float) $invoice->first_total) {
                return ___("Can not handle this billing terms: "
						. "first_total is zero");
            }

            if ($invoice->first_period != $invoice->second_period) {
                return ___("Can not handle this billing terms: "
						. "first_period != second_period");
            }

			if (!in_array(
					$invoice->first_period,
					array('7d', '1m', '3m', '12m', '1y'))) {
                 return ___("Can not handle this billing terms: "
						. "not in possible periods");
			}

            if ($invoice->rebill_times != 99999) {
                return ___("Can not handle this billing terms: "
						. "rebill time must be forever");
            }
        }

        return parent::isNotAcceptableForInvoice($invoice);
    }

	public function getClient($public, $private, $token_value = null, InvoiceLog $invoiceLog = null)
    {
		$adapter = new \Bitpay\Client\Adapter\CurlAdapter();
        $network = new \Bitpay\Network\Livenet();

		$client = new \Bitpay\Client\Client($invoiceLog);
		$client->setPrivateKey(unserialize($private));
		$client->setPublicKey(unserialize($public));
		$client->setNetwork($network);
		$client->setAdapter($adapter);
        if($token_value)
        {
            $token = new \Bitpay\Token();
            $token->setToken($token_value);
            $client->setToken($token);
        }
		return $client;
	}

	function generatePeriod($period)
    {
		switch ($period) {
			case '7d':
				return 'weekly';
			case '1m':
				return 'monthly';
			case '3m':
				return 'quarterly';
			case '12m':
            case '1y':
				return 'yearly';
			default:
				throw new Am_Exception("Unhandled period: {$period}");
		}
	}

    function getInvoiceLog(Invoice $invoice)
    {
        if (!@$this->log)
        {
            $this->log = $this->getDi()->invoiceLogRecord;
            if ($invoice)
            {
                $this->log->invoice_id = $invoice->invoice_id;
                $this->log->user_id = $invoice->user_id;
            }
            $this->log->paysys_id = $this->getId();
            $this->log->remote_addr = $_SERVER['REMOTE_ADDR'];
            foreach ($this->getConfig() as $k => $v)
                if (is_scalar($v) && (strlen($v) > 4))
                    $this->log->mask($v);
        }
        return $this->log;
    }

	public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
		$user = $invoice->getUser();

        $invoiceLog = $this->getInvoiceLog($invoice);

		$client = $this->getClient($this->getConfig('pkeys.public'), $this->getConfig('pkeys.private'), $this->getConfig('token_value'), $invoiceLog);
        $bitInvoice = new \Bitpay\Invoice();

		$prSpeeds = array();

		/* @var $product Product */
		foreach ($invoice->getProducts() as $product) {
			$prSpeeds[] = ($o = $product->data()->get('bitpay_speed_risk')) ? $o : $this->getConfig('bitpay_speed_risk');
		}

		$transactionSpeed = $this->transactionSpeed[min($prSpeeds)];

		/* @var $item InvoiceItem */
		foreach ($invoice->getItems() as $item) {
			$bitItem = new \Bitpay\Item();
			$bitItem
				->setDescription($item->item_description)
				->setPrice($item->first_total)
				->setQuantity($item->qty);
			$bitInvoice->setItem($bitItem);
		}

		$buyer = new \Bitpay\Buyer();
		$buyer->setFirstName($user->name_f)
            ->setLastName($user->name_l)
			->setPhone($user->phone)
			->setEmail($user->email)
			->setAddress(
				array(
					$user->street,
					$user->street2
				)
			)
			->setCity($user->city)
			->setState($user->state)
			->setZip($user->zip)
			->setCountry($user->country);

		$bitInvoice->setBuyer($buyer);
		$bitInvoice->setCurrency(new \Bitpay\Currency($invoice->currency));
		$bitInvoice->setTransactionSpeed($transactionSpeed);
		$bitInvoice->setPrice($invoice->first_total);
		$bitInvoice->setNotificationUrl($this->getPluginUrl('ipn'));
		$bitInvoice->setOrderId($invoice->public_id);
		$bitInvoice->setRedirectUrl($this->getReturnUrl());

        try {
            $client->createInvoice($bitInvoice);
        } catch (\Exception $e) {
            throw new Am_Exception_InputError('Incorrect Gateway response received!');
        }

        if($invoice->rebill_times)
		{
			$schedule = new Bitpay\Schedule();
			$schedule->currency = $invoice->currency;
			$schedule->price = $invoice->second_total;
			$schedule->quantity = 1;
            $p = new Am_Period($invoice->first_period);
            $dueDate = $p->addTo($this->getDi()->sqlDate);
            list($y,$m,$d) =  explode('-', $dueDate);
            if($d>28)
                $dueDate = "$y-$m-28";
            $schedule->dueDate = $dueDate;
			$schedule->schedule = $this->generatePeriod($invoice->second_period);
            $schedule->items = array(array(
                'price' => $invoice->second_total,
                'quantity' => '1'
            ));
            $schedule->name = $invoice->getName();
            $schedule->email = $invoice->getEmail();
            
            try {
                $client->createSchedule($schedule);
            } catch (\Exception $e) {
                throw new Am_Exception_InputError('Incorrect Gateway response received!');
            }
		}
        if(!($url = $bitInvoice->getUrl()))
            throw new Am_Exception_InputError('Incorrect Gateway response received!');
        $invoice->data()->set(self::BITPAY_INVOICE_ID, $bitInvoice->getId())->update();

        $result->setAction(new Am_Paysystem_Action_Redirect($url));
    }

    public function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Bitpay($this, $request, $response, $invokeArgs);
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Bitpay($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Bitpay extends Am_Paysystem_Transaction_Incoming
{
    /**
	 * @var Bitpay\Invoice
	 */
	private $bitInvoice, $vars;

    public function process()
    {
        $rawBody = $this->request->getRawBody();

        $vars = json_decode($rawBody, true);

        if (!isset($vars['id']))
            throw new Am_Exception_InternalError("BitPay API Error. Request[incoming] has no [id].");

		/* @var $client Bitpay\Client\Client */
		$client = $this->plugin->getClient($this->plugin->getConfig('pkeys.public'), $this->plugin->getConfig('pkeys.private'), $this->plugin->getConfig('token_value'));

        $this->vars = $vars;
        $this->bitInvoice = $client->getInvoice($vars['id']);

        parent::process();
    }

    public function validateSource()
    {
        return true;
    }

    public function findInvoiceId()
    {
        if($invoice = Am_Di::getInstance()
						->invoiceTable
						->findFirstByData(
							Am_Paysystem_Bitpay::BITPAY_INVOICE_ID,
							$this->vars['id']))
            return $invoice->public_id;
        else
            throw new Am_Exception_InternalError(
						"BitPay Error. "
						. "Not found invoice by bitpayInvoiceId "
						. "#[{$this->vars['id']}].");

    }

    public function validateStatus()
    {
        switch ($this->bitInvoice->getStatus())
        {
            case 'paid':
            case 'confirmed':
            case 'complete':
                return true;
			default:
				return false;
        }
    }

    public function getUniqId()
    {
        return (string) $this->vars['id'];
    }

    public function validateTerms()
    {
        $this->assertAmount(
			$this->invoice->first_total,
			(string)$this->bitInvoice->getPrice());

		return true;
    }
}
