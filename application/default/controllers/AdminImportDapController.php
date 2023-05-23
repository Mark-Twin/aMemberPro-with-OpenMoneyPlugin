<?php

/*
  The dap internal status on the transaction record.

  0 - Init status - db default
  1 - Paypal Verified (payment processor verified)
  2 - Paypal Invalid (payment processor declined) - admin  reprocessible
  3 - Paypal Communication Error (payment processor cannot be reached) -  admin reprocessible
  4 - Misc Error - admin reprocessible
  5 - Processed successfully.
  6 - Processed ERROR.
  7 - Processed Affiliations Successfully - Final State.
 */

class_exists('Am_Paysystem_Abstract', true);

/** Generate am4 invoices from amember 3 payments array */
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
        $db_dap;

    public
        function getDi()
    {
        return Am_Di::getInstance();
    }

    public
        function __construct($paysys_id, DbSimple_Interface $db)
    {
        $this->db_dap = $db;
        $this->paysys_id = $paysys_id;
    }

    function process(User $user, array $payments)
    {
        $this->user = $user;
        foreach ($payments as $p)
        {
            $this->payments[$p['id']] = $p;
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
                WHERE `table`='product' AND `key`='dap:id'");
        }
        return @$cache[$pid];
    }

}

class InvoiceCreator_Paypal extends InvoiceCreator_Abstract
{

    function doWork()
    {

        $access = $this->db_dap->select("SELECT product_id AS ARRAY_KEY, u.* FROM ?_users_products_jn u
            WHERE user_id=?", $this->user->data()->get('dap:id'));

        foreach ($this->groups as $pid => $list)
        {

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
            $invoice->tm_added = $list[0]['time'];
            $invoice->tm_started = $list[0]['time'];

            $invoice->public_id = $list[0]['trans_num'];
            $invoice->currency = $list[0]['payment_currency'];

            $invoice->calculate();
            $invoice->status = Invoice::PAID;

            parse_str($list[0]['trans_blob'], $trans_params);
            if (isset($trans_params['subscr_id']))
            {
                $invoice->data()->set('external_id', $trans_params['subscr_id']);
            }


            foreach ($list as $p)
                $pidlist[] = $p['id'];
            $invoice->data()->set('dap:id', implode(',', $pidlist));
            $invoice->insert();

            // insert payments

            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['product_id']);

                $tm = new DateTime($p['time']);

                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->user_id = $this->user->user_id;
                $payment->invoice_id = $invoice->pk();
                $payment->currency = $invoice->currency;
                $payment->amount = $p['payment_value'];
                $payment->paysys_id = 'paypal';
                $payment->dattm = $tm->format('Y-m-d H:i:s');
                $payment->receipt_id = $p['trans_num'];
                $payment->transaction_id = 'import-paypal-' . mt_rand(10000, 99999);
                $payment->insert();
                $this->getDi()->db->query("INSERT INTO ?_data SET
                    `table`='invoice_payment',`id`=?d,`key`='dap:id',`value`=?", $payment->pk(), $p['id']);
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

        $newP = $this->_translateProduct($access['product_id']);

        $a = $this->getDi()->accessRecord;
        $a->user_id = $this->user->user_id;
        $a->setDisableHooks();
        $a->begin_date = $access['access_start_date'];
        $a->expire_date = $access['access_end_date'];

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
            $this->groups[$p['product_id']][] = $p;
        }
    }

}

class InvoiceCreator_AuthorizeCim extends InvoiceCreator_Abstract
{

    function doWork()
    {

        $access = $this->db_dap->select("SELECT product_id AS ARRAY_KEY, u.* FROM ?_users_products_jn u
            WHERE user_id=?", $this->user->data()->get('dap:id'));

        foreach ($this->groups as $pid => $list)
        {

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
            $invoice->paysys_id = 'authorize-cim';
            $invoice->tm_added = $list[0]['time'];
            $invoice->tm_started = $list[0]['time'];

            $invoice->public_id = $list[0]['trans_num'];
            $invoice->currency = $list[0]['payment_currency'];

            $invoice->calculate();
            $invoice->status = Invoice::PAID;

            parse_str($list[0]['trans_blob'], $trans_params);
            if (isset($trans_params['subscr_id']))
            {
                $invoice->data()->set('external_id', $trans_params['subscr_id']);
            }


            foreach ($list as $p)
                $pidlist[] = $p['id'];
            $invoice->data()->set('dap:id', implode(',', $pidlist));
            $invoice->insert();

            // insert payments

            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['product_id']);

                $tm = new DateTime($p['time']);

                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->user_id = $this->user->user_id;
                $payment->invoice_id = $invoice->pk();
                $payment->currency = $invoice->currency;
                $payment->amount = $p['payment_value'];
                $payment->paysys_id = 'authorize-cim';
                $payment->dattm = $tm->format('Y-m-d H:i:s');
                $payment->receipt_id = $p['trans_num'];
                $payment->transaction_id = 'import-authorize-cim-' . mt_rand(10000, 99999);
                $payment->insert();
                $this->getDi()->db->query("INSERT INTO ?_data SET
                    `table`='invoice_payment',`id`=?d,`key`='dap:id',`value`=?", $payment->pk(), $p['id']);
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

        $newP = $this->_translateProduct($access['product_id']);

        $a = $this->getDi()->accessRecord;
        $a->user_id = $this->user->user_id;
        $a->setDisableHooks();
        $a->begin_date = $access['access_start_date'];
        $a->expire_date = $access['access_end_date'];

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
            $this->groups[$p['product_id']][] = $p;
        }
    }

}

class InvoiceCreator_Clickbank extends InvoiceCreator_Abstract
{

    function doWork()
    {

        $access = $this->db_dap->select("SELECT transaction_id AS ARRAY_KEY, u.* FROM ?_users_products_jn u
            WHERE user_id=?", $this->user->data()->get('dap:id'));

        foreach ($this->groups as $trans => $list)
        {

            $invoice = $this->getDi()->invoiceRecord;
            $invoice->user_id = $this->user->pk();
            $set = array();
            foreach ($list as $k)
            {

                $pid = $k['product_id'];
                if (isset($set[$pid]))
                    continue;
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
                $set[$pid] = 1;
            }
            $invoice->paysys_id = 'clickbank';
            $invoice->tm_added = $list[0]['time'];
            $invoice->tm_started = $list[0]['time'];

            $invoice->public_id = $list[0]['trans_num'];
            $invoice->currency = $list[0]['payment_currency'];

            $invoice->calculate();
            $invoice->status = Invoice::PAID;

            $invoice->data()->set('external_id', $trans);


            foreach ($list as $p)
                $pidlist[] = $p['id'];
            $invoice->data()->set('dap:id', implode(',', $pidlist));
            $invoice->insert();

            // insert payments

            foreach ($list as $p)
            {
                $newP = $this->_translateProduct($p['product_id']);

                $tm = new DateTime($p['time']);

                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->user_id = $this->user->user_id;
                $payment->invoice_id = $invoice->pk();
                $payment->currency = $invoice->currency;
                $payment->amount = $p['payment_value'];
                $payment->paysys_id = 'clickbank';
                $payment->dattm = $tm->format('Y-m-d H:i:s');
                $payment->receipt_id = $p['trans_num'];
                $payment->transaction_id = 'import-clickbank-' . mt_rand(10000, 99999);
                $payment->insert();
                $this->getDi()->db->query("INSERT INTO ?_data SET
                    `table`='invoice_payment',`id`=?d,`key`='dap:id',`value`=?", $payment->pk(), $p['id']);

                if (isset($access[$p['id']]))
                {
                    $this->insertAccess($access[$p['id']], $invoice->pk(), $payment->pk());
                    unset($access[$p['id']]);
                }
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


        $newP = $this->_translateProduct($access['product_id']);

        $a = $this->getDi()->accessRecord;
        $a->user_id = $this->user->user_id;
        $a->setDisableHooks();
        $a->begin_date = $access['access_start_date'];
        $a->expire_date = ($access['access_end_date'] == '9999-12-31' ? '2037-12-31' : $access['access_end_date']);

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
            $this->groups[$this->getInvoiceExternalId($p['trans_num'])][] = $p;
        }
    }

    function getInvoiceExternalId($transaction)
    {
        if (strpos($transaction, '-') !== false)
        {
            list($trans, ) = explode('-', $transaction);
        }
        else if (strpos($transaction, ':') !== false)
        {
            list(,, $trans) = explode(':', $transaction);
        }
        else
        {
            $trans = $transaction;
        }
        return $trans;
    }

}

abstract
    class Am_Import_Abstract extends Am_BatchProcessor
{

    /** @var DbSimple_Mypdo */
    protected
        $db_dap;
    protected
        $options = array();

    /** @var Am_Session_Ns */
    protected
        $session;

    public
        function __construct(DbSimple_Interface $db_dap, array $options = array())
    {
        $this->db_dap = $db_dap;
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
        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`='dap:id'");
        $q = $this->db_dap->queryResultOnly("SELECT * FROM ?_products");
        while ($r = $this->db_dap->fetchRow($q))
        {
            if (in_array($r['id'], $importedProducts))
                continue;

            $context++;

            $p = $this->getDi()->productRecord;
            $p->title = $r['name'];
            $p->description = $r['description'];
            $p->data()->set('dap:id', $r['id']);

            $p->insert();

            $bp = $p->createBillingPlan();
            $bp->title = 'default';
            if ($r['is_recurring'] == 'Y')
            {

                $bp->first_price = $r['trial_price'] > 0 ? $r['trial_price'] : $r['price'];
                $bp->first_period = $r['recurring_cycle_1'] . 'd';
                $bp->second_price = $r['price'];
                $bp->second_period = ($r['recurring_cycle_2'] ? $r['recurring_cycle_2'] : $r['recurring_cycle_1']) . 'd';
                $bp->rebill_times = $r['total_occur'] ? $r['total_occur'] : IProduct::RECURRING_REBILLS;
            }
            else
            { // not recurring
                $bp->first_price = $r['price'];
                $bp->first_period = Am_Period::MAX_SQL_DATE;
                $bp->rebill_times = 0;
            }

            $bp->insert();
        }
        return true;
    }

}

class Am_Import_User3 extends Am_Import_Abstract
{

    function doWork(& $context)
    {
        //$crypt = $this->getDi()->crypt;
        $maxImported = (int) $this->getDi()->db->selectCell("SELECT `value` FROM ?_data
                WHERE `table`='user' AND `key`='dap:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_dap->queryResultOnly("SELECT *
            FROM ?_users
            WHERE id > ?d AND account_type = 'U'
            ORDER BY id
            {LIMIT ?d} ", $maxImported, $count ? $count : DBSIMPLE_SKIP);

        while ($r = $this->db_dap->fetchRow($q))
        {
            if (!$this->checkLimits())
                return;
            $u = $this->getDi()->userRecord;
            $u->name_f = (string) $r['first_name'];
            $u->name_l = (string) $r['last_name'];
            $u->email = $r['email'];
            $u->street = trim(sprintf('%s %s', $r['address1'], $r['address2']));
            $u->city = $r['city'];
            $u->state = $r['state']; //@todo translate satate
            $u->country = $r['country']; //@todo translate country
            $u->phone = $r['phone'];
            $u->zip = $r['zip'];
            $u->added = $r['signup_date'];
            $u->is_affiliate = $r['is_affiliate'] == 'Y' ? 1 : 0;
            $u->remote_addr = $r['ipaddress'];
            if ($r['user_name'])
            {
                $u->login = $r['user_name'];
            }
            else
            {
                $u->generateLogin();
            }

            $u->setPass($r['password'], true); // do not salt passwords heavily to speed-up
            $u->data()->set('paypal_email', $r['paypal_email']);
            $u->data()->set('external_id', ($this->findPayerId($r['id'])? : $r['email']));
            $u->data()->set('dap:id', $r['id']);
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
        $payment = $this->db_dap->selectRow("SELECT t.*, u.id AS user_id FROM ?_transactions t
            LEFT JOIN ?_users u 
            ON IF(u.paypal_email IS NULL OR u.paypal_email = '', u.email = t.payer_email, u.paypal_email = t.payer_email)
            WHERE u.id=$id
            AND t.status IN (1, 5)
            AND t.trans_type = 'subscr_payment'
            AND payment_status = 'Completed'");
        if ($payment)
        {
            parse_str($payment['trans_blob'], $trans_params);
            $payerId = isset($trans_params['payer_id']) ? $trans_params['payer_id'] : $payerId;
        }
        return $payerId;
    }

    function insertPayments($id, User $u)
    {

        $payments = $this->db_dap->select("SELECT t.*, u.id AS user_id FROM ?_transactions t
            LEFT JOIN ?_users u 
            ON IF(u.paypal_email IS NULL OR u.paypal_email = '', u.email = t.payer_email, u.paypal_email = t.payer_email)
            WHERE u.id=$id
            AND t.status IN (1,5)
            AND t.trans_type = 'subscr_payment'
            AND t.payment_status = 'Completed'
            AND t.payment_processor = 'PAYPAL'
            ORDER BY t.id");

        $payments = $payments ? $payments : array(); //to add access if exists
        $byPs = array(
            'paypal' => array()
        );
        foreach ($payments as $p)
        {
            $byPs['paypal'][] = $p;
        }

        // Select clickbank payments; 

        $payments = $this->db_dap->select(""
            . "SELECT * FROM ?_transactions "
            . "WHERE (user_id=? OR payer_email=?) "
            . "AND  status IN (1,5) "
            . "AND trans_type='ctransaction' "
            . "AND payment_status='Completed' "
            . "AND payment_processor = 'CLICKBANK'"
            . "", $id, $u->data()->get('paypal_email') ? $u->data()->get('paypal_email') : $u->email);

        foreach ($payments as $p)
        {
            $byPs['clickbank'][] = $p;
        }

        // Select authorize payments; 

        $payments = $this->db_dap->select(""
            . "SELECT * FROM ?_transactions "
            . "WHERE (user_id=? OR payer_email=?) "
            . "AND  status IN (1,5) "
            . "AND trans_type='subscr_payment' "
            . "AND payment_status='Completed' "
            . "AND payment_processor = 'AUTHNET'"
            . "", $id, $u->data()->get('paypal_email') ? $u->data()->get('paypal_email') : $u->email);

        foreach ($payments as $p)
        {
            $byPs['authorize-cim'][] = $p;
        }

        foreach ($byPs as $paysys_id => $list)
        {
            if ($list)
                InvoiceCreator_Abstract::factory($paysys_id, $this->db_dap)->process($u, $list);
        }
    }

}

class AdminImportDapController extends Am_Mvc_Controller
{

    /** @var Am_Form_Admin */
    protected
        $dbForm;

    /** @var DbSimple_Mypdo */
    protected
        $db_dap;

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
            $this->getSession()->dap_db = null;
            $this->getSession()->dap_import = null;
        }
        elseif ($this->_request->get('import_settings'))
        {
            $this->getSession()->dap_import = null;
        }

        if (!$this->getSession()->dap_db)
            return $this->askDbSettings();

        $this->db_dap = Am_Db::connect($this->getSession()->dap_db);

        if (!$this->getSession()->dap_import)
            return $this->askImportSettings();

        // disable ALL hooks
        $this->getDi()->hook = new Am_Hook($this->getDi());


        $done = $this->_request->getInt('done', 0);

        $importSettings = $this->getSession()->dap_import;
        $import = $this->_request->getFiltered('i', $importSettings['import']);
        $class = "Am_Import_" . ucfirst($import) . "3";
        $importer = new $class($this->db_dap, (array) @$importSettings[$import]);

        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from DAP";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-dap') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->dap_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-dap",array('done'=>$done,'i'=>$import),false), "$done records imported");
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
                $this->getSession()->dap_import = array(
                    'import' => $val['import'],
                    'user' => @$val['user'],
                );
                $this->_redirect('admin-import-dap');
                return;
            }
        }
        $this->view->title = "Import DAP Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }

    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='dap:id'");
        $total = $this->db_dap->selectCell("SELECT COUNT(*) FROM ?_products");
        if($total)
            $form->addStatic()
                ->setLabel(___('Clean up v4 Database'))
                ->setContent(
                    sprintf(___('Use this %slink%s to delete data from aMember v4 database and use clean database for import'), 
                        '<a href="'.$this->getDi()->url('admin-import-dap/clean').'">', '</a>'));
        
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
            $imported_users = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='dap:id'");
            $total = $this->db_dap->selectCell("SELECT COUNT(*) FROM ?_users WHERE account_type='U'");
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
            $this->getSession()->dap_db = $this->form->getValue();
            $this->_redirect('admin-import-dap');
        }
        else
        {
            $this->view->title = "Import DAP Information";
            $this->view->content = (string) $this->form;
            $this->view->display('admin/layout.phtml');
        }
    }

    /** @return Am_Form_Admin */
    function createMysqlForm()
    {
        $form = new Am_Form_Admin;

        $el = $form->addText('host')->setLabel('DAP MySQL Hostname');
        $el->addRule('required', 'This field is required');

        $form->addText('user')->setLabel('DAP MySQL Username')
            ->addRule('required', 'This field is required');
        $form->addPassword('pass')->setLabel('DAP MySQL Password');
        $form->addText('db')->setLabel('DAP MySQL Database Name')
            ->addRule('required', 'This field is required');
        $form->addText('prefix')->setLabel('DAP Tables Prefix');

        $dbConfig = $this->getDi()->getParameter('db');
        $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'host' => $dbConfig['mysql']['host'],
            'user' => $dbConfig['mysql']['user'],
            'prefix' => 'dap_',
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
    
    function createCleanUpForm(){
        $form = new Am_Form_Admin();
        
        $total_products = $this->getDi()->db->selectCell('SELECT count(product_id) FROM ?_product');
        
        $imported_products = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='am3:id'");
        
        $total_users = $this->getDi()->db->selectCell('SELECT count(user_id) FROM ?_user');
        
        $imported_users = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='am3:id'");

        $form->addStatic()->setLabel('IMPORTANT INFO')->setContent(<<<EOL
    <font color=red><b>Clean UP process cannot be reversed, so if you don't understand what are you doing, please navigate away from this page!</b></font><br/>
Sometimes this is necessary to remove all data from aMember's database and start import over. This form helps to do this in one go. <b>Please make sure that you don't have any importand data in database</b>, because it can't be restored after clean up. This is good idea to <b>make a backup</b> before "Clean Up" operation. <br/>
<b>DATA WHICH WILL BE REMOVED BY THIS OPERATION:</b><br/>
User Accounts<br/>
User Invoices/Payments<br/>
User CC info<br/>
Products enabled by setting below<br/>
Affiliate data(if aff module is enabled)<br/>
Newsletter subscriptions data(if newsletter module is enabled)<br/>
Helpdesk tickets(if helpdesk module is enabled)<br/>

EOL
            );
        
        $form->addAdvCheckbox('remove_products')->setLabel('Remove products');
        $form->addPassword('password')->setLabel('Please confirm your Admin password');
        $form->addSaveButton('Clean Up');
        return $form;
        
    }
    
    function cleanUpData($value){
        $tables = array('access', 'access_cache', 'access_log', 'cc', 'coupon', 'coupon_batch', 'invoice', 'invoice_item', 'invoice_log',
            'invoice_payment', 'invoice_refund', 'saved_pass', 'user', 'user_status', 'user_user_group');
        
        if($this->getDi()->modules->isEnabled('aff'))
            $tables = array_merge($tables, array('aff_click', 'aff_commission', 'aff_lead', 'aff_payout', 'aff_payout_detail'));
        
        if(@$value['remove_products'])
            $tables = array_merge($tables, array('billing_plan', 'product', 'product_product_category'));
        
        
        if($this->getDi()->modules->isEnabled('helpdesk'))
            $tables = array_merge($tables, array('helpdesk_ticket', 'helpdesk_message'));
        
        if($this->getDi()->modules->isEnabled('newsletter'))
            $tables = array_merge($tables, array('newsletter_list', 'newsletter_user_subscription'));
        
        
        // Doing cleanup
        foreach($tables as $table){
            $this->getDi()->db->query('delete from ?_'.$table);
        }
        
        // Doing data table separately. 
        if(!@$value['remove_products']){
            $where = "where `table` <> 'product' and `table`<>'billing_plan'";
        }else $where = '';
            $this->getDi()->db->query('delete from ?_data '.$where);
        
       $this->redirectHtml($this->getDi()->url('admin-import-dap',null,false), 'Records removed');
    }
    
    function cleanAction(){
        $this->form = $this->createCleanUpForm();
        $this->form->addDataSource($this->_request);
        if($this->form->isSubmitted() && $this->form->validate()){
            $value = $this->form->getValue();
            // Validate password;
            $admin = $this->getDi()->authAdmin->getUser();
            if(!$admin->checkPassword($value['password'])){
                $this->form->setError('Incorrect Password!');
            }else{
                $this->cleanUpData($this->form->getValue());
            }
        }
        $this->view->title = "Clean up Database";
        $this->view->content = (string)$this->form;
        $this->view->display('admin/layout.phtml');
        
    }

}
