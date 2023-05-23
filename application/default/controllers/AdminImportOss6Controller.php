<?php

class_exists('Am_Paysystem_Abstract', true);

class InvoiceCreator_Paypal extends InvoiceCreator_Abstract
{

    function doWork()
    {

        $access = $this->db_oss->select("SELECT ProductID AS ARRAY_KEY, s.* FROM ?_subscriptions  s
            WHERE UserID=?", $this->user->data()->get('oss6:id'));

        foreach ($this->groups as $pid => $list)
        {
            $data = unserialize($list[0]['Details']);
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->user_id = $this->user->pk();

            $newP = $this->_translateProduct($pid);
            $item = $invoice->createItem(Am_Di::getInstance()->productTable->load($newP));
            $item->qty = 1;
            $item->first_discount = 0;
            $item->first_shipping = 0;
            $item->first_tax = 0;
            $item->second_discount = 0;
            $item->second_shipping = 0;
            $item->second_tax = 0;

            $item->_calculateTotal();
            $invoice->addItem($item);
            $invoice->paysys_id = 'paypal';
            $invoice->tm_added = $list[0]['Date'];
            $invoice->tm_started = $list[0]['Date'];

            $invoice->public_id = $list[0]['UniqID'];
            $invoice->currency = $data['mc_currency'];

            $invoice->calculate();
            $invoice->status = Invoice::PAID;

            if (isset($list[0]['subscr_id']))
            {
                $invoice->data()->set('external_id', $list[0]['subscr_id']);
            }


            foreach ($list as $p)
                $pidlist[] = $p['ID'];
            $invoice->data()->set('oss6:id', implode(',', $pidlist));
            $invoice->insert();

            // insert payments

            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['ProductID']);


                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->user_id = $this->user->user_id;
                $payment->invoice_id = $invoice->pk();
                $payment->currency = $invoice->currency;
                $payment->amount = $p['Cost'];
                $payment->paysys_id = 'paypal';
                $payment->dattm = $p['Date'];
                $payment->receipt_id = $p['txn_id'];
                $payment->transaction_id = 'import-paypal-' . mt_rand(10000, 99999);
                $payment->insert();
                $this->getDi()->db->query("INSERT INTO ?_data SET
                    `table`='invoice_payment',`id`=?d,`key`='oss6:id',`value`=?", $payment->pk(), $p['ID']);
            }


            if (isset($access[$pid]))
            {
                $this->insertAccess($access[$pid], $invoice->pk(), $payment->pk());
                unset($access[$pid]);
            }
        }

        //add other access as manually added
        if ($access)
        {
            foreach ($access as $a)
            {
                $this->insertAccess($a);
            }
        }

        $this->user->checkSubscriptions();
    }

    protected
        function insertAccess($access, $invoice_id = null, $payment_id = null)
    {


        $newP = $this->_translateProduct($access['ProductID']);

        $a = $this->getDi()->accessRecord;
        $a->user_id = $this->user->user_id;
        $a->setDisableHooks();
        $a->begin_date = $access['StartDate'];
        $a->expire_date = $access['ExpireDate'] ? $access['ExpireDate'] : Am_Period::RECURRING_SQL_DATE;

        if (!is_null($invoice_id))
        {
            $a->invoice_id = $invoice_id;
        }

        if (!is_null($payment_id))
        {
            $a->invoice_payment_id = $payment_id;
        }
        $a->product_id = $newP;
        $a->insert();
    }

    public
        function groupByInvoice()
    {
        foreach ($this->payments as $p)
        {
            $this->groups[$p['ProductID']][] = $p;
        }
    }

}

class Am_Import_User3 extends Am_Import_Abstract
{

    function doWork(& $context)
    {
        //$crypt = $this->getDi()->crypt;
        $maxImported =
            (int) $this->getDi()->db->selectCell("SELECT `value` FROM ?_data
                WHERE `table`='user' AND `key`='oss:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_oss->queryResultOnly("SELECT *
            FROM ?_users
            WHERE id > ?d 
            ORDER BY id
            {LIMIT ?d} ", $maxImported, $count ? $count : DBSIMPLE_SKIP);

        while ($r = $this->db_oss->fetchRow($q))
        {
            if (!$this->checkLimits())
                return;
            $u = $this->getDi()->userRecord;
            $u->name_f = (string) $r['firstname'];
            $u->name_l = (string) $r['lastname'];
            $u->email = $r['email'];
            $u->added = $r['signup_date'];
            $u->remote_addr = $r['signup_ip'];
            $u->login = $r['username'];

            $u->setPass($r['plain_password'], true); // do not salt passwords heavily to speed-up
            $u->data()->set('external_id', $this->findPayerId($r['id']));
            $u->data()->set('oss6:id', $r['id']);
            $u->data()->set('signup_email_sent', 1); // do not send signup email second time
            try
            {
                $u->insert();
                $this->insertPayments($r['id'], $u);

                $context++;
            }
            catch (Am_Exception_Db_NotUnique $e)
            {
                echo "Could not import user: " . $e->getMessage() . "<br />\n";
            }
        }
        return true;
    }

    function findPayerId($id)
    {
        $payerId = null;
        $payment = $this->db_oss->selectRow("
            select * from ?_payments 
            where payment_system_code = 'paypal' and Status = 'completed' and UserID = ? and subscr_id  is not null and subscr_id >''
            ", $id);
        if ($payment)
        {
            $details = unserialize($payment['Details']);
            $payerId = isset($details['payer_id']) ? $details['payer_id'] : $payerId;
        }
        return $payerId;
    }

    function insertPayments($id, User $u)
    {

        $payments = $this->db_oss->select("
            select * 
            from ?_payments p left join ?_payments_products pr on p.ID = pr.PaymentId 
            where payment_system_code = 'paypal' and Status='completed' and UserID=? and Details like '%subscr_payment%'", $id);

        $payments = $payments ? $payments : array(); //to add access if exists
        $byPs = array(
            'paypal' => array()
        );
        foreach ($payments as $p)
        {
            $byPs['paypal'][] = $p;
        }
        foreach ($byPs as $paysys_id => $list)
        {
            InvoiceCreator_Abstract::factory($paysys_id, $this->db_oss)->process($u, $list);
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
        $db_oss;

    public
        function getDi()
    {
        return Am_Di::getInstance();
    }

    public
        function __construct($paysys_id, DbSimple_Interface $db)
    {
        $this->db_oss = $db;
        $this->paysys_id = $paysys_id;
    }

    function process(User $user, array $payments)
    {
        $this->user = $user;
        foreach ($payments as $p)
        {
            $this->payments[$p['ID']] = $p;
        }
        $this->groupByInvoice();
        $this->beforeWork();
        return $this->doWork();
    }

    function groupByInvoice()
    {
        foreach ($this->payments as $p)
        {
            $this->groups[$p['product_id']][] = $p;
        }
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
                WHERE `table`='product' AND `key`='oss6:id'");
        }
        return @$cache[$pid];
    }

}

abstract
    class Am_Import_Abstract extends Am_BatchProcessor
{

    /** @var DbSimple_Mypdo */
    protected
        $db_oss;
    protected
        $options = array();

    /** @var Am_Session_Ns */
    protected
        $session;

    public
        function __construct(DbSimple_Interface $db_oss, array $options = array())
    {
        $this->db_oss = $db_oss;
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
        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`='oss6:id'");
        $q = $this->db_oss->queryResultOnly("SELECT * FROM ?_groups");
        while ($r = $this->db_oss->fetchRow($q))
        {
            if (in_array($r['id'], $importedProducts))
                continue;
            $r['Duration'] = unserialize($r['Duration']);
            $r['Url'] = unserialize($r['Url']);

            $context++;

            $p = $this->getDi()->productRecord;
            $p->title = $r['_group'];
            $p->description = $r['comment'];
            $p->currency = $r['Url']['currency'];
            $p->data()->set('oss6:id', $r['id']);

            $p->insert();

            $bp = $p->createBillingPlan();

            $bp->title = 'default';
            if ($r['price'] != 0)
            {

                $bp->first_price = $r['Url']['a1'] > 0 ? $r['Url']['a1'] : $r['Url']['a3'];
                $bp->first_period = $r['Url']['p1'] > 0 ? $r['Url']['p1'] . strtolower($r['Url']['t1']) : $r['Url']['p3'] . strtolower($r['Url']['t3']);
                $bp->second_price = $r['Url']['a3'];
                $bp->second_period = $r['Url']['p3'] . strtolower($r['Url']['t3']);
                $bp->rebill_times = IProduct::RECURRING_REBILLS;
            }
            else
            { // not free
                $bp->first_price = $r['price'];
                $bp->first_period = $r['Duration']['days'] ? $r['Duration']['days'] . 'd' : ($r['Duration']['months'] ? $r['Duration']['months'] . 'm' : $r['Duration']['years'] . 'y');
                $bp->rebill_times = 0;
            }

            $bp->insert();
        }
        return true;
    }

}

class AdminImportOss6Controller extends Am_Mvc_Controller
{

    /** @var Am_Form_Admin */
    protected
        $dbForm;

    /** @var DbSimple_Mypdo */
    protected
        $db_oss;

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
            $this->getSession()->oss_db = null;
            $this->getSession()->oss_import = null;
        }
        elseif ($this->_request->get('import_settings'))
        {
            $this->getSession()->oss_import = null;
        }

        if (!$this->getSession()->oss_db)
            return $this->askDbSettings();

        $this->db_oss = Am_Db::connect($this->getSession()->oss_db);

        if (!$this->getSession()->oss_import)
            return $this->askImportSettings();

        // disable ALL hooks
        $this->getDi()->hook = new Am_Hook($this->getDi());


        $done = $this->_request->getInt('done', 0);

        $importSettings = $this->getSession()->oss_import;
        $import = $this->_request->getFiltered('i', $importSettings['import']);
        $class = "Am_Import_" . ucfirst($import) . "3";
        $importer = new $class($this->db_oss, (array) @$importSettings[$import]);

        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from OSS6";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-oss6') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->oss_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-oss6",array('done'=>$done,'i'=>$import),false), "$done records imported");
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
                $this->getSession()->oss_import = array(
                    'import' => $val['import'],
                    'user' => @$val['user'],
                );
                $this->_redirect('admin-import-oss6');
                return;
            }
        }
        $this->view->title = "Import OSS6 Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }

    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products =
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='oss6:id'");
        $total = $this->db_oss->selectCell("SELECT COUNT(*) FROM ?_groups");
        if ($imported_products >= $total)
        {
            $cb = $form->addStatic()->setContent("Imported ($imported_products of $total)");
        }
        else
        {
            $cb = $form->addRadio('import', array('value' => 'product'));
        }
        $cb->setLabel('Import Products');

        if ($imported_products)
        {
            $imported_users =
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='oss6:id'");
            $total = $this->db_oss->selectCell("SELECT COUNT(*) FROM ?_users");
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
            $this->getSession()->oss_db = $this->form->getValue();
            $this->_redirect('admin-import-oss6');
        }
        else
        {
            $this->view->title = "Import OSS6 Information";
            $this->view->content = (string) $this->form;
            $this->view->display('admin/layout.phtml');
        }
    }

    /** @return Am_Form_Admin */
    function createMysqlForm()
    {
        $form = new Am_Form_Admin;

        $el = $form->addText('host')->setLabel('OSS6 MySQL Hostname');
        $el->addRule('required', 'This field is required');

        $form->addText('user')->setLabel('OSS6 MySQL Username')
            ->addRule('required', 'This field is required');
        $form->addPassword('pass')->setLabel('OSS6 MySQL Password');
        $form->addText('db')->setLabel('OSS6 MySQL Database Name')
            ->addRule('required', 'This field is required');
        $form->addText('prefix')->setLabel('OSS6 Tables Prefix');

        $dbConfig = $this->getDi()->getParameter('db');
        $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'host' => $dbConfig['mysql']['host'],
            'user' => $dbConfig['mysql']['user'],
            'prefix' => 'oss6_',
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
            $db->query("SELECT * FROM ?_users LIMIT 1");
        }
        catch (Exception $e)
        {
            return "Check database settings - " . $e->getMessage();
        }
    }

}