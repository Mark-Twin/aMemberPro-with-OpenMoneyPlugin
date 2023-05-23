<?php

/**
 * Class represents a transaction coming from (maybe!) a paysystem
 * to direct URL, say http://site.com/amember/payment/paypal/ipn
 * it must do a serie of validating checks before it can be handled
 *
 * @see DirectController
 * @see Am_Paysystem_Abstract::directAction()
 * @see Am_Paysystem_Abstract::createTransaction()
 */
abstract class Am_Paysystem_Transaction_Incoming extends Am_Paysystem_Transaction_Abstract
{
    /** @var Am_Mvc_Request */
    protected $request;
    /** @var Am_Mvc_Response */
    protected $response;
    /** @var array controller invokeArgs */
    protected $invokeArgs;
    /** @var InvoiceLog */
    protected $log;
    
    /** redefine to get @link fetchUserInfo working 
     *  special keys:
     *      "user_external_id" - if defined, will be used by @link generateUserExternalId
     *      "invoice_external_id" - if defined, will be used by @link generateInvoiceExternalId
     *      "name" - automatically parsed to "name_f" and "name_l"
     *  @var array userField -> transactionField */
    protected $_autoCreateMap = array();
    
    /**
     * @param Am_Paysystem_Abstract $plugin
     * @param Am_Mvc_Request $request
     * @param Am_Mvc_Response $response
     * @param type $invokeArgs
     */
    public function __construct(/*Am_Paysystem_Abstract*/ $plugin, /*Am_Mvc_Request*/ $request, /*Am_Mvc_Response*/ $response, $invokeArgs)
    {
        $this->request = $request;
        $this->response = $response;
        $this->invokeArgs = $invokeArgs;
        parent::__construct($plugin);
    }
    
    /**
     * By transaction :
     *  - find or create user 
     *  - find or create invoice
     *  - return created invoice
     * @return Invoice
     */
    function autoCreateInvoice()
    {
        $invoiceExternalId = $this->generateInvoiceExternalId();
        $invoice = false;
        if(is_array($invoiceExternalId) && count($invoiceExternalId))
        {
            foreach ($invoiceExternalId as $id)
                if($invoice = Am_Di::getInstance()->invoiceTable->findFirstByData('external_id', $id))
                {
                    $invoiceExternalId = $id;
                    break;
                }
            if(!$invoice)
                $invoiceExternalId = $invoiceExternalId[0];
        }
        else
            $invoice = Am_Di::getInstance()->invoiceTable->findFirstByData('external_id', $invoiceExternalId);

        $products = $this->autoCreateGetProducts();
        if (!$invoice && !$products)
            return null;
        
        // If we are able to retrive invoice but doesn;t have products, 
        // we should get products from invoice in order to handle situations when invoice was imported into amember;
        if($invoice && !$products)
        {
            $products = $invoice->getProducts();
        }
        if (!is_array($products))
            $products = array($products);

        $userTable = $this->getPlugin()->getDi()->userTable;
        
        $userInfo = $this->fetchUserInfo();
        $externalId = $this->generateUserExternalId($userInfo);
        
        $user = null;
        if ($externalId)
            $user = $userTable->findFirstByData('external_id', $externalId);
        if (!$user) 
        {
            $user = $userTable->findFirstByEmail($userInfo['email']);
            if ($user)
                $user->data()->set('external_id', $externalId)->update();
        }
        if (!$user && @$userInfo['login']) 
        {
            $user = $userTable->findFirstByLogin($userInfo['login']);
            if ($user)
                $user->data()->set('external_id', $externalId)->update();
        }
        if (!$user)
        {
            $user = $userTable->createRecord($userInfo);
            if(!$user->login)
                $user->generateLogin();
            if(!$user->pass)
                $user->generatePassword();
            else
                $user->setPass($user->pass);
            $user->data()->set('external_id', $externalId);
            $user->insert();
            if ($this->getPlugin()->getDi()->config->get('registration_mail'))
                $user->sendRegistrationEmail();
            if ($this->getPlugin()->getDi()->config->get('registration_mail_admin'))
                $user->sendRegistrationToAdminEmail();
        }
        
        //
        
        if ($invoice)
        {
            if ($invoice->user_id != $user->user_id) 
            {   
                $invoice = null; // strange!!!
            } else {
                $invoice->_autoCreated = true;
            }
        }
        /// 
        if (!$invoice)
        {
            $invoice = $this->getPlugin()->getDi()->invoiceRecord;
            $invoice->setUser($user);
            foreach ($products as $pr)
                $invoice->add($pr, $this->autoCreateGetProductQuantity($pr));
            $invoice->calculate();
            $invoice->data()->set('external_id', $invoiceExternalId);
            $invoice->paysys_id = $this->plugin->getId();
            $invoice->insert();
            $invoice->_autoCreated = true;
        }
        if ($invoice && $this->log)
        {
            $this->log->updateQuick(array(
                'invoice_id' => $invoice->pk(),
                'user_id' => $user->user_id,
            ));
        }
        
        return $invoice;
    }
    
    /** 
     * find matching products according to request
     * @return array<Product>
     */
    function autoCreateGetProducts()
    {
        throw new Am_Exception_NotImplemented("autoCreateGetProducts not implemented");
    }
    
    function autoCreateGetProductQuantity(Product $pr){
        return 1;
    }
    /**
     * Find out user info from transaction details
     * @see $_autoCreateMap
     * @return array
     */
    function fetchUserInfo()
    {
        if (!$this->_autoCreateMap)
            throw new Am_Exception_NotImplemented("fetchUserInfo not implemented");
        $ret = array();
        foreach ($this->_autoCreateMap as $field => $valKey)
            switch ($field)
            {
                case 'user_external_id': 
                case 'invoice_external_id':
                    break;
                case 'name':
                    @list($ret['name_f'], $ret['name_l']) = array_pad(preg_split('/\s+/', $this->request->get($valKey), 2), 2, '');
                    break;
                default:
                    $ret[$field] = $this->request->get($valKey);
            }
        return $ret;
    }
    function fillInUserFields(User $user)
    {
        $info = $this->fetchUserInfo();
        if (!$info) return;
        $updated = 0;
        foreach ($info as $k => $v)
        {
            if (''==$user->get($k))
            {
                $user->set($k, $v);
                $updated++;
            }
        }
        if ($updated)
            $user->update();
    }
    /**
     * @see $_autoCreateMap
     * @return string unique id of user - so user will not be re-generated even if he changes email
     */
    function generateUserExternalId(array $userInfo)
    {
        $field = @$this->_autoCreateMap['user_external_id'];
        if (!empty($field))
            return $this->request->getFiltered($field);
        if (!empty($userInfo['email']))
            return md5($userInfo['email']);
        throw new Am_Exception_Paysystem_TransactionInvalid("Could not generate externalId");
    }
    /**
     * Must return the same value for single rebill sequence
     * @see $_autoCreateMap
     * @return string unique id of invoice - so rebills can be added to the same invoice
     */
    function generateInvoiceExternalId()
    {
        $field = @$this->_autoCreateMap['invoice_external_id'];
        if (!empty($field)){
            if(is_array($field))
            {
                $ids = array();
                foreach($field as $v) if($res = $this->request->getFiltered($v)) $ids[]=$res;
                return $ids;
            }
            return $this->request->getFiltered($field);
        }
        throw new Am_Exception_Paysystem_NotImplemented("Not Implemented");
    }
    
    function resendPostback()
    {
        if ($this->plugin->getConfig('resend_postback'))
        {
            $urls = $this->plugin->getConfig('resend_postback_urls');
            $urls = explode("\n", $urls);
            $urls = array_filter(array_map('trim', $urls));
            foreach ($urls as $url)
                try {
                    $tm = microtime(true);
                    $this->log->add("Resending postback to [$url]");
                    if ($url == $this->plugin->getPluginUrl('ipn'))
                    {
                        throw new Am_Exception_Configuration("DO NOT CONFIGURE RESENDING IPN TO ITSELF!");
                    }
                    $req = new Am_HttpRequest($url);
                    $req->setConfig('connect_timeout', 1000);
                    $req->setConfig('timeout', 2000);
                    $method = strtoupper($this->request->getMethod());
                    $req->setMethod($method);
                    if ($method == 'POST') 
                    {
                        foreach ($this->request->getPost() as $k => $v)
                            $req->addPostParameter($k, $v);
                    } else {
                        $arr = $this->request->getQuery();
                        $req->setUrl($req->getUrl() . '?' . http_build_query($arr, '', '&'));
                    }
                    $req->send();
                    $tm = sprintf('%.3f', microtime(true) - $tm);
                    $this->log->add("Postback resent successfully ($tm sec)");
                } catch (Exception $e) {
                    $tm = sprintf('%.3f', microtime(true) - $tm);
                    $this->log->add("Cannot resend postback ($tm sec)");
                    $this->log->add($e);
                }
        }
    }
    
    function process()
    {
        // resend postback first as exception may be raised below
        $this->resendPostback();
        parent::process();
        // all went OK, with no exceptions, lets try to update customer info
        if (!empty($this->invoice->_autoCreated) && ($user = $this->invoice->getUser()))
            $this->fillInUserFields($user);
    }
    
    /** @return Invoice|null */
    public function loadInvoice($invoiceId)
    {
        $invoiceId = preg_replace('/-[^-]*$/', '', $invoiceId);
        $invoice = Am_Di::getInstance()->invoiceTable->findFirstByPublicId($invoiceId);
        // update invoice_id in the log record
        if ($invoice && $this->log)
        {
            $this->log->updateQuick(array(
                'invoice_id' => $invoice->pk(),
                'user_id' => $invoice->user_id,
            ));
        }
        return $invoice;
    }
    
    protected function autoCreate()
    {
        try {
            $invoiceId = $this->findInvoiceId();
            if ($invoiceId === null)
                throw new Am_Exception_Paysystem_TransactionEmpty("Looks like an invalid IPN post - no Invoice# passed");
            $invoiceId = filterId($invoiceId);
            if (!strlen($invoiceId))
                throw new Am_Exception_Paysystem_TransactionInvalid("Could not load Invoice related to this transaction, passed id is not a valid Invoice#[$invoiceId]");
            if (!$this->invoice = $this->loadInvoice($invoiceId))
                throw new Am_Exception_Paysystem_TransactionUnknown("Unknown transaction: related invoice not found #[$invoiceId]");
        } catch (Am_Exception_Paysystem $e) {
            if (!$this->plugin->getConfig('auto_create')) 
                throw $e;
            // try auto-create invoice
            $invoice = $this->autoCreateInvoice();
            if ($invoice) 
                $this->invoice = $invoice;
            else
                throw $e;
        }
        $this->setTime($this->findTime());
    }

    /**
     * Validates if transaction comes from trusted source,
     * and if it matches found invoice
     * @throws Am_Exception_Paysystem
     * @return null must throw exception if it is not OK
     */
    public function validate()
    {
        if (!$this->validateSource())
            throw new Am_Exception_Paysystem_TransactionSource("IPN seems to be received from unknown source, not from the paysystem");
        $this->autoCreate();
        
        if($this->invoice->paysys_id != $this->getPaysysId())
            throw new Am_Exception_Paysystem_TransactionInvalid("Invoice was created by another payment plugin.");
            
        if (empty($this->invoice->_autoCreated) && !$this->validateTerms())
            throw new Am_Exception_Paysystem_TransactionInvalid("Subscriptions terms in the IPN does not match subscription terms in our Invoice");
        if (!$this->validateStatus())
            throw new Am_Exception_Paysystem_TransactionInvalid("Payment status is invalid, this IPN is not regarding a completed payment");
    }

    /**
     * Make sure transaction is coming from the payment system and not from the fraudient source
     * @return true if OK, false if not
     */
    abstract public function validateSource();

    /**
     * Make sure the transaction have the same terms (at least paid amount as the @see $invoice )
     * NOTE: if invoice is auto-created, its terms will NOT be validated
     * @return true if OK, false if not
     */
    abstract public function validateTerms();

    /**
     * Make sure this transaction is not regarding a pending or failed payment
     * @return true if OK, false if not
     */
    abstract public function validateStatus();

    /**
     * Find Invoice related with the current post as passed to the paysystem
     * must return null if no invoice_id present in the post
     * @return int|null id of invoice, null if not found
     */
    public function findInvoiceId()
    {
        throw new Am_Exception_NotImplemented("findInvoiceId is not implemented");
    }

    /**
     * Compare request IP with configured in plugin
     * and raise exception if that is wrong
     * @param mixed $ip string will be parsed using this format: $ip1_start [- $ip1_end][\n$ip2_start [- $ip2_end]] etc... 
     * Array should have this format: array( array('start1', 'stop1'), single_ip, array('start2', 'stop2'))
     * also it may automatically check for hostname belonging to subdomain like
     * .worldpay.com
     */
    public function _checkIp($ip)
    {
        $got = $this->request->getClientIp(false);
        if(!is_array($ip))
        {
            $expected = array();
            foreach(explode("\n", $ip) as $l){
                if(strpos($l, "-")!== false){
                    list($k, $v) = explode("-", $l);
                    $expected[] = array(trim($k),trim($v));
                }else{
                    $expected[] = trim($l);
                }
            }
        }else $expected = $ip;
        
        $expected = array_filter($expected);
        if(empty($expected))
            throw new Am_Exception_InputError("{$this->plugin->getId()} configuration error. Expected IP address array is empty!");
        
        $found =false;
        $hostname = null;
        foreach ($expected as $v)
        {
            if (is_array($v))
            {
                if(ip2long($got) >= ip2long($v[0]) && ip2long($got) <= ip2long($v[1])) 
                {
                    $found = true;
                    break;
                }
            } else {
                if($got == $v) 
                {
                    $found = true;
                    break;
                }
                if ($v[0] == '.')
                {
                    if (!$hostname) $hostname = gethostbyaddr($got);
                    if (preg_match($x='|'.preg_quote($v).'$|', $hostname))
                    {
                        $found = true;
                        break;
                    }
                }
            }
        }
        if (!$found)
            throw new Am_Exception_Paysystem_TransactionSource("{$this->plugin->getId()} post comes from unknown IP [$got]");
    }
    
    public function assertAmount($expected, $got, $whereMsg = "amount")
    {
        if ($e=moneyRound($expected) != $g=moneyRound($got))
            throw new Am_Exception_Paysystem_TransactionInvalid("Transaction $whereMsg [$g] does not match expected [$e]");
    }

}