<?php


class_exists('Am_Paysystem_Abstract', true);

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
        $db_drupal;

    public
        function getDi()
    {
        return Am_Di::getInstance();
    }

    public
        function __construct($paysys_id, DbSimple_Interface $db)
    {
        $this->db_drupal = $db;
        $this->paysys_id = $paysys_id;
    }

    function process(User $user, array $payments)
    {
        $this->user = $user;
        foreach ($payments as $p)
        {
            $this->payments[$p['pid']] = $p;
        }
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
                WHERE `table`='product' AND `key`='dms:id'");
        }
        return @$cache[$pid];
    }

}

class InvoiceCreator_Standard extends InvoiceCreator_Abstract
{

    function doWork()
    {
        $user = $this->user;
        foreach ($this->groups as $oid => $list)
        {
            // Create new invoice;
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->user_id = $this->user->pk();
            foreach ($this->db_drupal->selectPage($total, "select pid, qty from  ?_ms_order_products op left join ?_ms_products_plans p on  op.id = p.sku where oid=?", $oid) as $row)
            {
                $invoice->add($product = $this->getDi()->productTable->findFirstByData('dms:id', $row['pid']), $row['qty']);
            }
            $invoice->calculate();
            $invoice->tm_added = date('Y-m-d H:i:s', $list[0]['order_created']);
            $invoice->tm_started = date('Y-m-d H:i:s', $list[0]['created']);
            $invoice->public_id = $list[0]['order_key'];
            switch ($list[0]['gateway'])
            {
                case "ms_paypal_wps" : $invoice->paysys_id = 'paypal';
                    break;
                default : $invoice->paysys_id = 'free';
            }
            switch ($list[0]['order_status'])
            {
                case 'completed': $invoice->status = Invoice::PAID;
                    break;
                case 'active' : $invoice->status = Invoice::RECURRING_ACTIVE;
                    break;
                case 'cancelled' : $invoice->status = Invoice::RECURRING_CANCELLED;
                    $invoice->tm_cancelled = date('Y-m-d H:i:s', $list[0]['order_modified']);
                    break;
                default : $invoice->status = Invoice::PENDING;
                    break;
            }
            $invoice->data()->set('dms:id', $oid);
            $invoice->insert();

            foreach ($list as $rec)
            {
                switch ($rec['type'])
                {
                    case 'cart' :
                    case 'rec_signup' :
                    case 'rec_payment' :
                        if ($rec['amount'] > 0)
                        {
                            // Add payment record;
                            $payment = $this->getDi()->invoicePaymentRecord;
                            $payment->amount = $rec['amount'];
                            $payment->currency = $rec['currency'];
                            $payment->dattm = date('Y-m-d H:i:s', $rec['created']);
                            $payment->invoice_id = $invoice->pk();
                            $payment->paysys_id = $invoice->paysys_id;
                            $payment->receipt_id = $rec['transaction'];
                            $payment->transaction_id = sprintf('import-%s', $payment->receipt_id);
                            $payment->user_id = $user->pk();
                            $payment->insert();
                        }
                        else
                        {
                            $payment = null;
                        }

                        // Insert Access; 
                        $access = $this->getDi()->accessRecord;
                        $access->user_id = $user->pk();
                        $access->setDisableHooks();
                        $access->begin_date = date('Y-m-d', $rec['created']);
                        $p = new Am_Period($invoice->first_period);
                        $access->expire_date = $p->addTo($access->begin_date);
                        $access->invoice_id = $invoice->pk();
                        if (!is_null($payment))
                            $access->invoice_payment_id = $payment->pk();
                        $access->product_id = $product->pk();
                        $access->insert();
                        break;
                    case 'refund' :
                        $refund = $this->getDi()->invoiceRefundRecord;
                        $refund->invoice_id = $invoice->pk();
                        $refund->user_id = $user->pk();
                        $refund->paysys_id = $invoice->paysys_id;
                        $refund->receipt_id = $refund->transaction_id = $rec['transaction'];
                        $refund->dattm = date('Y-m-d H:i:s', $rec['created']);
                        $refund->currency = $rec['currency'];
                        $refund->amount = $rec['amount'];
                        $refund->insert();
                        break;
                }
            }
        }

        $this->user->checkSubscriptions();
    }

    public
        function groupByInvoice()
    {
        foreach ($this->payments as $p)
        {
            $this->groups[$p['oid']][] = $p;
        }
    }

}

abstract
    class Am_Import_Abstract extends Am_BatchProcessor
{

    /** @var DbSimple_Mypdo */
    protected
        $db_drupal;
    protected
        $options = array();

    /** @var Am_Session_Ns */
    protected
        $session;

    public
        function __construct(DbSimple_Interface $db_drupal, array $options = array())
    {
        $this->db_drupal = $db_drupal;
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
        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`='dms:id'");
        $q = $this->db_drupal->queryResultOnly("SELECT * FROM ?_ms_products_plans");
        while ($r = $this->db_drupal->fetchRow($q))
        {
            if (in_array($r['pid'], $importedProducts))
                continue;
            $rs = unserialize($r['recurring_schedule']);
            $r['data'] = unserialize($r['data']);
            $context++;

            $p = $this->getDi()->productRecord;
            $p->title = $r['name'];
            $p->description = $r['description'];
            $p->sort_order = $r['weight'];
            $p->data()->set('dms:id', $r['pid']);

            $p->insert();

            $bp = $p->createBillingPlan();
            $bp->title = 'default';
            if ($r['cart_type'] == 'recurring')
            {

                $bp->first_price = $rs['has_trial'] ? $rs['trial_amount'] : $rs['main_amount'];
                $bp->first_period = $rs['has_trial'] ? strtolower($rs['trial_length'] . $rs['trial_unit']) : strtolower($rs['main_length'] . $rs['main_unit']);
                $bp->second_price = $rs['main_amount'];
                $bp->second_period = strtolower($rs['main_length'] . $rs['main_unit']);
                $bp->rebill_times = $rs['total_occurrences'] ? $rs['total_occurrences'] : IProduct::RECURRING_REBILLS;
            }
            else
            { // not recurring
                $bp->first_price = $rs['main_amount'];
                $bp->first_period = $rs['main_length'] ? $rs['main_length'] . strtolower($rs['main_unit']) : Am_Period::MAX_SQL_DATE;
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
        $maxImported =
            (int) $this->getDi()->db->selectCell("SELECT `value` FROM ?_data
                WHERE `table`='user' AND `key`='dms:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_drupal->queryResultOnly("SELECT *
            FROM ?_users
            WHERE uid > ?d 
            ORDER BY uid
            {LIMIT ?d} ", $maxImported, $count ? $count : DBSIMPLE_SKIP);

        while ($r = $this->db_drupal->fetchRow($q))
        {
            if (!$this->checkLimits())
                return;
            $u = $this->getDi()->userRecord;
            $u->email = $r['mail'];
            $u->added = date('Y-m-d H:i:s', $r['created']);
            $u->login = $r['name'];
            $u->is_approved = 1;

            $u->data()->set('dms:id', $r['uid']);
            $u->data()->set('signup_email_sent', 1); // do not send signup email second time
            $u->data()->set(Am_Protect_Databased::USER_NEED_SETPASS, 1);
            $u->generatePassword();
            try
            {
                $u->insert();
                $savedPass = $this->getDi()->savedPassRecord;
                $savedPass->user_id = $u->pk();
                $savedPass->format = 'drupal';
                $savedPass->pass = $r['pass'];
                $savedPass->salt = null;
                $savedPass->save();

                $this->insertPayments($r['uid'], $u);

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

        $payments = $this->db_drupal->select("
            select p.*, o.created as order_created,
            o.order_key as order_key,
            o.status as order_status,
            o.modified as order_modified
            from ?_ms_payments p left join ?_ms_orders o using(oid)
            where o.uid = ?
            order by p.pid desc", $id);

        $payments = $payments ? $payments : array(); //to add access if exists
        InvoiceCreator_Abstract::factory('standard', $this->db_drupal)->process($u, $payments);
    }

}

class AdminImportDrupalMsController extends Am_Mvc_Controller
{

    /** @var Am_Form_Admin */
    protected
        $dbForm;

    /** @var DbSimple_Mypdo */
    protected
        $db_drupal;

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
            $this->getSession()->drupal_db = null;
            $this->getSession()->drupal_import = null;
        }
        elseif ($this->_request->get('import_settings'))
        {
            $this->getSession()->drupal_import = null;
        }

        if (!$this->getSession()->drupal_db)
            return $this->askDbSettings();

        $this->db_drupal = Am_Db::connect($this->getSession()->drupal_db);

        if (!$this->getSession()->drupal_import)
            return $this->askImportSettings();

        // disable ALL hooks
        $this->getDi()->hook = new Am_Hook($this->getDi());


        $done = $this->_request->getInt('done', 0);

        $importSettings = $this->getSession()->drupal_import;
        $import = $this->_request->getFiltered('i', $importSettings['import']);
        $class = "Am_Import_" . ucfirst($import) . "3";
        $importer = new $class($this->db_drupal, (array) @$importSettings[$import]);

        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from Drupal Membership Script";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-drupal-ms') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->drupal_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-drupal-ms",array('done'=>$done,'i'=>$import),false), "$done records imported");
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
                $this->getSession()->drupal_import = array(
                    'import' => $val['import'],
                    'user' => @$val['user'],
                );
                $this->_redirect('admin-import-drupal-ms');
                return;
            }
        }
        $this->view->title = "Import Drupal Membership Script Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }

    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products =
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='dms:id'");
        $total = $this->db_drupal->selectCell("SELECT COUNT(*) FROM ?_ms_products_plans");
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
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='dms:id'");
            $total = $this->db_drupal->selectCell("SELECT COUNT(*) FROM ?_users");
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

            $this->getSession()->drupal_db = $this->form->getValue();
            $this->_redirect('admin-import-drupal-ms');
        }
        else
        {
            $this->view->title = "Import Drupal Membership  Script Information";
            $this->view->content = (string) $this->form;
            $this->view->display('admin/layout.phtml');
        }
    }

    /** @return Am_Form_Admin */
    function createMysqlForm()
    {
        $form = new Am_Form_Admin;

        $el = $form->addText('host')->setLabel('Drupal  MySQL Hostname');
        $el->addRule('required', 'This field is required');

        $form->addText('user')->setLabel('Drupal MySQL Username')
            ->addRule('required', 'This field is required');
        $form->addPassword('pass')->setLabel('Drupal MySQL Password');
        $form->addText('db')->setLabel('Drupal MySQL Database Name')
            ->addRule('required', 'This field is required');
        $form->addText('prefix')->setLabel('Drupal Tables Prefix');

        $dbConfig = $this->getDi()->getParameter('db');
        $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
            'host' => $dbConfig['mysql']['host'],
            'user' => $dbConfig['mysql']['user'],
            'prefix' => '',
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
            $db->query("SELECT * FROM ?_ms_orders LIMIT 1");
        }
        catch (Exception $e)
        {
            return "Check database settings - " . $e->getMessage();
        }
    }

}