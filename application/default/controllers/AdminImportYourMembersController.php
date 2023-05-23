<?php

class_exists('Am_Paysystem_Abstract', true);

class YourMember_Packs extends stdClass
{

    var
        $packs = array();

    function getCount()
    {
        return count($this->packs);
    }

    function getAll()
    {
        return $this->packs;
    }

}

class AdminImportYourMembersController extends Am_Mvc_Controller
{

    /** @var Am_Form_Admin */
    protected
        $dbForm;

    /** @var DbSimple_Mypdo */
    protected
        $db_yourmembers;

    public
        function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SUPER_USER);
    }

    function indexAction()
    {
        Am_Mail::setDefaultTransport(new Am_Mail_Transport_Null());

        if ($this->_request->get('start'))
        {
            $this->getSession()->yourmembers_db = null;
            $this->getSession()->yourmembers_import = null;
        }
        elseif ($this->_request->get('import_settings'))
        {
            $this->getSession()->yourmembers_import = null;
        }

        if (!$this->getSession()->yourmembers_db)
            return $this->askDbSettings();

        $this->db_yourmembers = Am_Db::connect($this->getSession()->yourmembers_db);

        if (!$this->getSession()->yourmembers_import)
            return $this->askImportSettings();

        // disable ALL hooks
        $this->getDi()->hook = new Am_Hook($this->getDi());


        $done = $this->_request->getInt('done', 0);

        $importSettings = $this->getSession()->yourmembers_import;
        $import = $this->_request->getFiltered('i', $importSettings['import']);
        $class = "Am_Import_" . ucfirst($import) . "3";
        $importer = new $class($this->db_yourmembers, (array) @$importSettings[$import]);

        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from Wordpress Your Members Plugin";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-your-members') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->yourmembers_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-your-members", array('done'=>$done,'i'=>$import),false), "$done records imported");
        }
    }

    function askImportSettings()
    {
        $this->form = $this->createImportForm($defaults);
        $this->form->addDataSource($this->_request);
        if (!$this->form->isSubmitted())
            $this->form->addDataSource(new HTML_QuickForm2_DataSource_Array($defaults));
        if ($this->form->isSubmitted() && $this->form->validate())
        {
            $val = $this->form->getValue();
            if (@$val['import'])
            {
                $this->getSession()->yourmembers_import = array(
                    'import' => $val['import'],
                    'user' => @$val['user'],
                );
                $this->_redirect('admin-import-your-members');
                return;
            }
        }
        $this->view->title = "Import Wordpress Your Members Plugin Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }

    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products =
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='ym:id'");
        $memberTypes = unserialize($this->db_yourmembers->selectCell("SELECT option_value  FROM ?_options where option_name='ym_packs'"));

        $total = $memberTypes->getCount();
        if ($imported_products >= $total)
        {
            $cb = $form->addStatic()->setContent("Imported ($imported_products of $total)");
        }
        else
        {
            $cb = $form->addRadio('import', array('value' => 'product'));
        }
        $cb->setLabel('Import Products');

        // Import coupons
        $imported_coupons =
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='coupon' AND `key`='ym:id'");
        $totalc = $this->db_yourmembers->selectCell("SELECT COUNT(*) FROM ?_ym_coupon");
        if ($imported_products)
        {
            if ($imported_coupons >= $totalc)
            {
                $cb = $form->addStatic()->setContent("Imported ($imported_coupons of $totalc)");
            }
            else
            {
                $cb = $form->addRadio('import', array('value' => 'coupon'));
            }
            $cb->setLabel('Import Coupons');
        }

        if ($imported_products && ($imported_coupons || !$totalc))
        {
            $imported_users =
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='ym:id'");
            $total = $this->db_yourmembers->selectCell("SELECT COUNT(*) FROM ?_users");
            if ($imported_users >= $total)
            {
                $cb = $form->addStatic()->setContent("Imported ($imported_users)");
            }
            else
            {
                $cb = $form->addGroup();
                if ($imported_users)
                    $cb->addStatic()->setContent("partially imported ($imported_users of $total total)<br /><br />");
                $cb->addRadio('import', array('value' => 'user'));
                $cb->addStatic()->setContent('<br /><br /># of users (keep empty to import all) ');
                $cb->addInteger('user[count]');
            }
            $cb->setLabel('Import User and Payment Records');
        }
        $form->addSaveButton('Run');

        $defaults = array(
            //'user' => array('start' => 5),
        );
        return $form;
    }

    function askDbSettings()
    {
        $this->form = $this->createMysqlForm();
        if ($this->form->isSubmitted() && $this->form->validate())
        {

            $this->getSession()->yourmembers_db = $this->form->getValue();
            $this->_redirect('admin-import-your-members');
        }
        else
        {
            $this->view->title = "Import Wordpress  Your Members Plugin Information";
            $this->view->content = (string) $this->form;
            $this->view->display('admin/layout.phtml');
        }
    }

    /** @return Am_Form_Admin */
    function createMysqlForm()
    {
        $form = new Am_Form_Admin;

        $el = $form->addText('host')->setLabel('Wordpress  MySQL Hostname');
        $el->addRule('required', 'This field is required');

        $form->addText('user')->setLabel('Wordpress  MySQL Username')
            ->addRule('required', 'This field is required');
        $form->addPassword('pass')->setLabel('Wordpress MySQL Password');
        $form->addText('db')->setLabel('Wordpress MySQL Database Name')
            ->addRule('required', 'This field is required');
        $form->addText('prefix')->setLabel('Wordpress Tables Prefix');

        $dbConfig = $this->getDi()->getParameter('db');
        $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'host' => $dbConfig['mysql']['host'],
            'user' => $dbConfig['mysql']['user'],
            'prefix' => 'wp_',
        )));

        $el->addRule('callback2', '-', array($this, 'validateDbConnect'));

        $form->addSubmit(null, array('value' => 'Continue...'));
        return $form;
    }

    function validateDbConnect()
    {
        $config = $this->form->getValue();
        try
        {
            $db = Am_Db::connect($config);
            if (!$db)
                return "Check database settings - could not connect to database";
            $db->query("SELECT * FROM ?_ym_transaction LIMIT 1");
        }
        catch (Exception $e)
        {
            return "Check database settings - " . $e->getMessage();
        }
    }

}

abstract
    class InvoiceCreator_Abstract
{

    /** User  */
    protected
        $user;
    // all payments
    protected
        $payments = array();
    // grouped by invoice
    protected
        $groups = array();
    // prepared Invoices
    protected
        $invoices = array();
    //
    protected
        $paysys_id;

    /** @var DbSimple_Mypdo */
    protected
        $db_yourmembers;

    public
        function getDi()
    {
        return Am_Di::getInstance();
    }

    public
        function __construct($paysys_id, DbSimple_Interface $db)
    {
        $this->db_yourmembers = $db;
        $this->paysys_id = $paysys_id;
    }

    function process(User $user, array $payments)
    {
        $this->user = $user;
//        foreach ($payments as $p)
//        {
//            $this->payments[$p['id']] = $p;
//        }
        $this->payments = $payments;
        $this->groupByInvoice();
        $this->beforeWork();
        return $this->doWork();
    }

    function beforeWork()
    {
        
    }

    abstract
        function doWork();

    static
        function factory($paysys_id, DbSimple_Interface $db)
    {
        $class = 'InvoiceCreator_' . ucfirst(toCamelCase($paysys_id));
        if (class_exists($class, false))
            return new $class($paysys_id, $db);
        else
            throw new Exception(sprintf('Unknown Payment System [%s]', $paysys_id));
    }

    protected
        function _translateProduct($pid)
    {
        static $cache = array();
        if (empty($cache))
        {
            $cache = Am_Di::getInstance()->db->selectCol("
                SELECT `value` as ARRAY_KEY, `id` 
                FROM ?_data 
                WHERE `table`='product' AND `key`='ym:id'");
        }
        return @$cache[$pid];
    }

}

class Am_Import_Coupon3 extends Am_Import_Abstract
{

    static
        $batches;

    function createBatch(Array $r)
    {
        
    }

    public
        function doWork(&$context)
    {
        throw new Am_Exception_InputError("Not implemented");
    }

}

abstract
    class Am_Import_Abstract extends Am_BatchProcessor
{

    /** @var DbSimple_Mypdo */
    protected
        $db_yourmembers;
    protected
        $options = array();

    /** @var Am_Session_Ns */
    protected
        $session;

    public
        function __construct(DbSimple_Interface $db_wordpress, array $options = array())
    {
        $this->db_yourmembers = $db_wordpress;
        $this->options = $options;
        $this->session = $this->getDi()->session->ns(get_class($this));
        parent::__construct(array($this, 'doWork'));
        $this->init();
    }

    public
        function init()
    {
        
    }

    public
        function run(&$context)
    {
        $ret = parent::run($context);
        if ($ret)
            $this->session->unsetAll();
        return $ret;
    }

    /** @return Am_Di */
    public
        function getDi()
    {
        return Am_Di::getInstance();
    }

    abstract public
        function doWork(& $context);
}

class Am_Import_Product3 extends Am_Import_Abstract
{

    public
        function doWork(&$context)
    {
        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`='ym:id'");
        $products = unserialize($this->db_yourmembers->selectCell("SELECT option_value FROM ?_options where option_name='ym_packs'"));
        foreach ($products->getAll() as $r)
        {
            if (in_array($r['id'], $importedProducts))
                continue;


            $context++;

            $p = $this->getDi()->productRecord;
            $p->title = $r['account_type'];
            $p->description = $r['description'];
            $p->is_disabled = $r['hide_subscription'] ? 1 : 0;

            $p->data()->set('ym:id', $r['id']);
            $p->currency = $r['currency'];
            $p->insert();


            $bp = $p->createBillingPlan();
            $bp->title = 'default';
            $bp->second_price = $r['cost'];
            $bp->second_period = $r['duration'] . $r['duration_type'];
            if ($r['trial_on'])
            {
                $bp->first_price = $r['trial_cost'];
                $bp->first_period = $r['trial_duration'] . $r['trial_duration_type'];
            }
            else
            {
                $bp->first_price = $bp->second_price;
                $bp->first_period = $bp->second_period;
            }
            $bp->rebill_times = $r['num_cycles'] ? $r['num_cycles'] - 1 : IProduct::RECURRING_REBILLS;

            $bp->insert();
            $p->default_billing_plan_id = $bp->pk();
            $p->data()->set('zombaio_pricing_id', $r['zombaio_price_id'])->update();
            $p->update();
        }
        return true;
    }

}

class Am_Import_User3 extends Am_Import_Abstract
{

    function doWork(& $context)
    {
        //$crypt = $this->getDi()->crypt;
        $maxImported =
            (int) $this->getDi()->db->selectCell("SELECT `value` FROM ?_data
                WHERE `table`='user' AND `key`='ym:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_yourmembers->queryResultOnly("SELECT u.*, um1.meta_value as first_name, um2.meta_value as last_name
            FROM ?_users u
            LEFT JOIN ?_usermeta um1 on u.ID= um1.user_id and um1.meta_key = 'first_name'
            LEFT JOIN ?_usermeta um2 on u.ID= um2.user_id and um2.meta_key = 'last_name'
            WHERE u.ID > ?d 
            ORDER BY u.ID
            {LIMIT ?d} ", $maxImported, $count ? $count : DBSIMPLE_SKIP);

        while ($r = $this->db_yourmembers->fetchRow($q))
        {
            if (!$this->checkLimits())
                return;
            $u = $this->getDi()->userRecord;
            $u->email = $r['user_email'];
            $u->added = $r['user_registered'];
            $u->login = $r['user_login'];
            $u->name_f = (string) $r['first_name'];
            $u->name_l = (string) $r['last_name'];
            $u->is_approved = 1;

            $u->data()->set('ym:id', $r['ID']);
            $u->data()->set('signup_email_sent', 1); // do not send signup email second time
            $u->pass = $r['user_pass'];
            try
            {
                $u->insert();

                $this->insertPayments($r['ID'], $u);

                $context++;
            }
            catch (Am_Exception_Db_NotUnique $e)
            {
                echo "Could not import user: " . $e->getMessage() . "<br />\n";
            }
        }
        return true;
    }

    function insertPayments($id, User $u)
    {

        $data = $this->db_yourmembers->selectPage($total, "select t.*, a.name as action from ?_ym_transaction t left join ?_ym_transaction_action a on t.action_id = a.id where user_id=? order by unixtime", $id);
        $data = $data ? $data : array();
        $payments = array();
        foreach ($data as $d)
        {
            if (!isset($payments[$d['transaction_id']]))
                $payments[$d['transaction_id']] = new YourMembers_Transaction($u);
            $payments[$d['transaction_id']]->addTransactionData($d);
        }
        InvoiceCreator_Abstract::factory('standard', $this->db_yourmembers)->process($u, $payments);
    }

}

class YourMembers_Transaction
{

    var
        $ipn = array();

    const
        IPN = 10;
    const
        PRODUCT_ID = 12;
    const
        STATUS_UPDATE = 11;
    const
        ACCESS_EXTENSION = 9;
    const
        PAYMENT = 1;
    const
        ACCESS_EXPIRITY = 8;
    const
        ACCOUNT_TYPE = 2;

    var
        $product_id = null;
    var
        $user;
    var
        $paysys;
    var
        $access;

    function __construct(User $user)
    {
        $this->user = $user;
    }

    function addTransactionData($data)
    {
        switch ($data['action_id'])
        {
            case self::IPN :
                $this->ipn = unserialize($data['data']);
                break;
            case self::PRODUCT_ID :
                $this->product_id = $data['data'];
                break;
            case self::PAYMENT :
                $this->payment = $data;
                break;
            case self::ACCESS_EXTENSION:
                $this->access = $data;
                break;
            case self::STATUS_UPDATE:
                if (preg_match('/Cancelled/', $data['data']) && empty($this->cancel))
                {
                    $this->cancel = $data;
                }
                break;
            case self::ACCOUNT_TYPE:
            case self::ACCESS_EXPIRITY:
                break;
            default:
                throw new Am_Exception_InternalError("Unknown transaction type: " . $data['action_name']);
        }
    }

    function getProductId()
    {
        if (is_null($this->product_id))
        {
            $this->product_id = Am_Di::getInstance()->db->selectCell("select product_id from ?_access where user_id =? order by begin_date desc limit 1", $this->user->pk());
        }
        return $this->product_id;
    }

    function process()
    {



        if (!empty($this->cancel))
        {
            $invoice = YourMembers_IPN::create($this)->getInvoice();
            if (empty($invoice))
                return;
            $invoice->setCancelled();
            $invoice->update();
        }else if (!$this->product_id)
            return; // Unable to find product ID here; 
        if (!empty($this->ipn['ym_process']))
        {
            $this->paysys = $this->ipn['ym_process'];
        }
        $product = Am_Di::getInstance()->productTable->findFirstByData('ym:id', $this->product_id);

        if (!$product)
            return;


        if (!empty($this->payment))
        {
            if (empty($this->ipn))
            {
                return;
            }
            if (empty($this->paysys))
                return;
            $ipn = YourMembers_IPN::create($this);
            $invoice = $ipn->getInvoice();
            $invoice_payment = Am_Di::getInstance()->invoicePaymentRecord;
            $invoice_payment->amount = $ipn->getAmount();
            $invoice_payment->currency = $invoice->currency;
            $invoice_payment->dattm = date('Y-m-d H:i:s', $this->payment['unixtime']);
            $invoice_payment->invoice_id = $invoice->pk();
            $invoice_payment->paysys_id = $ipn->getPaysysId();
            $invoice_payment->receipt_id = $ipn->getReceiptId();
            $invoice_payment->transaction_id = sprintf('import-%s', $invoice_payment->receipt_id);
            $invoice_payment->user_id = $this->user->pk();
            $invoice_payment->insert();
        }

        if (!empty($this->access))
        {
            $access = Am_Di::getInstance()->accessRecord;
            $access->user_id = $this->user->pk();
            $access->setDisableHooks();
            $access->begin_date = date('Y-m-d', $this->access['unixtime']);
            $access->expire_date = date('Y-m-d', $this->access['data']);
            $access->product_id = $product->pk();
            if (!empty($invoice))
                $access->invoice_id = $invoice->pk();
            if (!empty($invoice_payment))
                $access->invoice_payment_id = $invoice_payment->pk();
            $access->insert();
        }
    }

    function findInvoiceId()
    {
        
    }

}

class YourMembers_IPN
{

    protected
        $transaction;

    function __construct(YourMembers_Transaction $transaction)
    {
        $this->transaction = $transaction;
    }

    function getInvoiceId()
    {
        return null;
    }

    function getPaysysId()
    {
        return null;
    }

    function getAmount()
    {
        return null;
    }

    function getReceiptId()
    {
        return null;
    }

    function getInvoice()
    {
        $invoice_id = $this->getInvoiceId();

        if (empty($invoice_id))
            return null;

        if (preg_match('/^buy_subscription_(.*)/', $invoice_id, $r))
            $invoice_id = $r[1];

        $invoice_id = $this->getPaysysId() . "_" . $invoice_id;
        $invoice = Am_Di::getInstance()->invoiceTable->findFirstBy(array('public_id' => $invoice_id));

        if (!$invoice)
        {
            $invoice = Am_Di::getInstance()->invoiceRecord;
            $invoice->user_id = $this->transaction->user->pk();
            $invoice->add($product = Am_Di::getInstance()->productTable->findFirstByData('ym:id', $this->transaction->product_id), 1);
            $invoice->calculate();
            $invoice->tm_added = date('Y-m-d H:i:s', $this->transaction->payment['unixtime']);
            $invoice->tm_started = date('Y-m-d H:i:s', $this->transaction->payment['unixtime']);
            $invoice->public_id = $invoice_id;
            $invoice->paysys_id = $this->getPaysysId();
            $invoice->status = Invoice::PAID;
            $invoice->data()->set('ym:id', $invoice_id);
            $invoice->insert();
            $invoice->public_id = $invoice_id;
            $invoice->update();
        }

        return $invoice;
    }

    /**
     * 
     * @param YourMembers_Transaction $transaction
     * @return YourMembers_IPN
     * @throws Am_Exception_InternalError
     */
    static
        function create(YourMembers_Transaction $transaction)
    {
        $cname = "YourMembers_IPN_" . $transaction->paysys;
        if (!class_exists($cname, false))
        {
            return new self($transaction);
        }
        return new $cname($transaction);
    }

}

class YourMembers_IPN_ym_zombaio extends YourMembers_IPN
{

    function getInvoiceId()
    {
        return $this->transaction->ipn['extra'];
    }

    function getPaysysId()
    {
        return 'zombaio';
    }

    function getAmount()
    {
        return $this->transaction->ipn['Amount'];
    }

    function getReceiptId()
    {
        return $this->transaction->ipn['SUBSCRIPTION_ID'] . '-' . $this->transaction->ipn['TRANSACTION_ID'];
    }

}

class YourMembers_IPN_ym_paypal extends YourMembers_IPN
{

    function getInvoiceId()
    {
        return $this->transaction->ipn['item_number'];
    }

    function getPaysysId()
    {
        return 'paypal';
    }

    function getAmount()
    {
        return $this->transaction->ipn['mc_gross'] ? $this->transaction->ipn['mc_gross'] : $this->transaction->ipn['payment_gross'];
    }

    function getReceiptId()
    {
        return $this->transaction->ipn['txn_id'];
    }

}

class InvoiceCreator_Standard extends InvoiceCreator_Abstract
{

    function doWork()
    {
        $user = $this->user;
        foreach ($this->groups as $oid => $list)
        {

            $list->process();
        }
        $this->user->checkSubscriptions();
    }

    public
        function groupByInvoice()
    {
        $this->groups = $this->payments;
    }

}

