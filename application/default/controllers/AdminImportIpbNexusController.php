<?php

/*
 * Tables description:
 * members                      -> users
 * nexus_gateways               -> gateways list
 * nexus_invoices               -> invoices
 * nexus_packages               -> products list
 * nexus_paymethods             -> payment gateways settings
 * nexus_purchases              -> payments/accesses
 * nexus_transactions           -> payments
*/

class_exists('Am_Paysystem_Abstract', true);

abstract class InvoiceCreator_Abstract
{
    /** User  */
    protected $user;
    // all payments
    protected $payments = array();
    // grouped by invoice
    protected $groups = array();
    // prepared Invoices
    protected $invoices = array();
    //
    protected $paysys_id;
    /** @var DbSimple_Mypdo */
    protected $db_ipb_nexus;

    public function getDi()
    {
        return Am_Di::getInstance();
    }

    public function __construct($paysys_id, DbSimple_Interface $db)
    {
        $this->db_ipb_nexus = $db;
        $this->paysys_id = $paysys_id;
    }

    function process(User $user, array $payments)
    {
        $this->user = $user;
        $this->payments = $payments;
        return $this->doWork();
    }

    function groupByInvoice()
    {
        foreach ($this->payments as $p)
        {
            $this->groups[$p['product_id']][] = $p;
        }
    }

    abstract function doWork();

    static function factory($paysys_id, DbSimple_Interface $db)
    {
        $class = 'InvoiceCreator_' . ucfirst(toCamelCase($paysys_id));
        if (class_exists($class, false))
            return new $class($paysys_id, $db);
        else
            throw new Exception(sprintf('Unknown Payment System [%s]', $paysys_id));
    }

    protected function _translateProduct($pid)
    {
        static $cache = array();
        if (empty($cache))
        {
            $cache = Am_Di::getInstance()->db->selectCol("
                SELECT `value` as ARRAY_KEY, `id` 
                FROM ?_data 
                WHERE `table`='product' AND `key`='ipb_nexus:id'");
        }
        return @$cache[$pid];
    }

}

class InvoiceCreator_Ccbill extends InvoiceCreator_Abstract
{
    function doWork()
    {
        foreach ($this->payments as $p)
        {
            if(!($amPrId = $this->_translateProduct($p['ps_item_id'])))
            {
                if($bp = $this->getDi()->billingPlanTable->findFirstByFirstPrice($p['i_total']))
                {
                    $amPrId = $bp->product_id;
                } else
                {
                    if($p['i_total'] == 3.00)
                    {
                        $amPrId = $this->_translateProduct(3);
                    } else
                    {
                        $this->getDi()->errorLogTable->log("Not found product #{$p['ps_item_id']} for user #[{$p['i_member']}]");
                        continue;
                    }
                }
            }
            
            // ivoice + item
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->user_id = $this->user->pk();

            $item = $invoice->createItem(Am_Di::getInstance()->productTable->load($amPrId));
            $item->qty = 1;
            $item->first_discount = 0;
            $item->first_shipping = 0;
            $item->first_tax = 0;
            $item->second_discount = 0;
            $item->second_shipping = 0;
            $item->second_tax = 0;

            $item->_calculateTotal();
            $invoice->addItem($item);
            $invoice->paysys_id = 'ccbill';
            $invoice->tm_added = date('Y-m-d H:i:s', $p['i_date']);
            $invoice->tm_started = date('Y-m-d H:i:s', $p['i_paid']);

            $invoice->currency = Am_Currency::getDefault();

            $invoice->calculate();
            $invoice->status = Invoice::PAID;

            $invoice->data()->set('ibp_nexus:id', $p['i_id']);
            $invoice->insert();

            // insert payments
            $payment = $this->getDi()->invoicePaymentRecord;
            $payment->user_id = $this->user->user_id;
            $payment->invoice_id = $invoice->pk();
            $payment->currency = $invoice->currency;
            $payment->amount = $p['i_total'];
            $payment->paysys_id = $invoice->paysys_id;
            $payment->dattm = $invoice->tm_started;
            $payment->receipt_id = $payment->transaction_id = 'import-ipb-nexus-' . mt_rand(10000, 99999);
            $payment->data()->set('ibp_nexus:id', $p['ps_id']);
            $payment->insert();

            //insert access
            $a = $this->getDi()->accessRecord;
            $a->invoice_id = $invoice->pk();
            $a->invoice_payment_id = $payment->pk();
            $a->user_id = $this->user->user_id;
            $a->product_id = $amPrId;
            $a->transaction_id = $payment->transaction_id;
            $a->begin_date = date('Y-m-d', $p['ps_start']);
            $a->expire_date = $p['ps_expire'] ? date('Y-m-d', $p['ps_expire']) : Am_Period::MAX_SQL_DATE;
            $a->invoice_item_id = $invoice->getItem(0)->pk();
            $a->invoice_public_id = $invoice->public_id;
            $a->setDisableHooks();
            $a->insert();
        }
        $this->user->checkSubscriptions();
    }
}

abstract class Am_Import_Abstract extends Am_BatchProcessor
{

    /** @var DbSimple_Mypdo */
    protected $db_ipb_nexus;
    protected $options = array();
    /** @var Am_Session_Ns */
    protected $session;
    public function __construct(DbSimple_Interface $db_ipb_nexus, array $options = array())
    {
        $this->db_ipb_nexus = $db_ipb_nexus;
        $this->options = $options;
        $this->session = $this->getDi()->session->ns(get_class($this));
        parent::__construct(array($this, 'doWork'));
        $this->init();
    }

    public function init(){}

    public function run(&$context)
    {
        $ret = parent::run($context);
        if ($ret)
            $this->session->unsetAll();
        return $ret;
    }

    /** @return Am_Di */
    public function getDi()
    {
        return Am_Di::getInstance();
    }

    abstract public function doWork(& $context);
}

class Am_Import_Product3 extends Am_Import_Abstract
{

    public function doWork(&$context)
    {
        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`='ipb_nexus:id'");
        $q = $this->db_ipb_nexus->queryResultOnly("SELECT * FROM ?_nexus_packages");
        while ($r = $this->db_ipb_nexus->fetchRow($q))
        {
            if (in_array($r['p_id'], $importedProducts))
                continue;

            $context++;

            $p = $this->getDi()->productRecord;
            $p->title = $r['p_name'];
            $p->description = $r['p_desc'];
            $p->data()->set('ipb_nexus:id', $r['p_id']);

            $p->insert();

            $bp = $p->createBillingPlan();
            $bp->title = 'default';
            $data = unserialize($r['p_renew_options']);
            $bp->first_price = $data[0]['price'];
            $bp->first_period = $data[0]['term'] . $data[0]['unit'];
            $bp->rebill_times = 0;

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
                WHERE `table`='user' AND `key`='ipb_nexus:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_ipb_nexus->queryResultOnly("
            SELECT member_id, name, email, joined, ip_address, members_pass_hash, members_pass_salt
            FROM ?_members
            WHERE member_id > ?d
            ORDER BY member_id
            {LIMIT ?d}
        ",$maxImported, $count ? $count : DBSIMPLE_SKIP);
//$q = $this->db_ipb_nexus->queryResultOnly("
//    SELECT member_id, name, email, joined, ip_address, members_pass_hash, members_pass_salt
//    FROM ?_members
//    WHERE member_id > ?d AND member_id < 5000
//    ORDER BY member_id
//    {LIMIT ?d}
//",$maxImported, $count ? $count : DBSIMPLE_SKIP);
        
//$q = $this->db_ipb_nexus->queryResultOnly("SELECT member_id, name, email, joined, ip_address, members_pass_hash, members_pass_salt
//    FROM ?_members
//    WHERE member_id IN (?a)
//", array(9, 79290, 92408));

        while ($r = $this->db_ipb_nexus->fetchRow($q))
        {
            if (!$this->checkLimits()) return;
            
            $u = $this->getDi()->userRecord;
            if ($r['name'])
                $u->login = $r['name'];
            else
                $u->generateLogin();
            $u->email = $r['email'];
            $u->added = date('Y-m-d H:i:s', $r['joined']);
            $u->last_ip = $r['ip_address'];
            $u->is_approved = 1;

            $u->data()->set('ipb_nexus:id', $r['member_id']);
            $u->data()->set('signup_email_sent', 1); // do not send signup email second time
            $u->data()->set(Am_Protect_Databased::USER_NEED_SETPASS, 1);
            try
            {
                $u->insert();
                $savedPass = $this->getDi()->savedPassRecord;
                $savedPass->user_id = $u->pk();
                $savedPass->format = 'Invision';
                $savedPass->pass = $r['members_pass_hash'];
                $savedPass->salt = $r['members_pass_salt'];
                $savedPass->save();

                if($this->insertPayments($r['member_id'], $u))
                {
                    $u->setGroups(array(1));
                }
                
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
        $payments = $this->db_ipb_nexus->select("
            SELECT p.ps_id, i.i_id, p.ps_start, p.ps_expire, p.ps_item_id, i.i_member, i.i_total, i.i_date, i.i_paid
            FROM ?_nexus_purchases p
            LEFT JOIN ?_nexus_invoices i ON p.ps_original_invoice = i.i_id
            WHERE
                i.i_member = $id
                AND i.i_status = 'paid'
            ORDER BY i_id
        ");
        if(empty($payments))
            return false;

        InvoiceCreator_Abstract::factory('ccbill', $this->db_ipb_nexus)->process($u, $payments);
        return true;
    }

}

class AdminImportIpbNexusController extends Am_Mvc_Controller
{

    /** @var Am_Form_Admin */
    protected $dbForm;
    /** @var DbSimple_Mypdo */
    protected $db_ipb_nexus;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SUPER_USER);
    }

    function indexAction()
    {
        $enabled = $this->getDi()->plugins_protect->loadEnabled()->getAllEnabled();
        foreach ($enabled as $k => $pl)
        {
            if($pl->getId() == 'invision')
            {
                unset ($enabled[$k]);
                break;
            }
        }
        $this->getDi()->plugins_protect->setEnabled($enabled);
        Am_Mail::setDefaultTransport(new Am_Mail_Transport_Null());

        if ($this->_request->get('start'))
        {
            $this->getSession()->ipb_nexus_db = null;
            $this->getSession()->ipb_nexus_import = null;
        }
        elseif ($this->_request->get('import_settings'))
        {
            $this->getSession()->ipb_nexus_import = null;
        }

        if (!$this->getSession()->ipb_nexus_db)
            return $this->askDbSettings();

        $this->db_ipb_nexus = Am_Db::connect($this->getSession()->ipb_nexus_db);

        if (!$this->getSession()->ipb_nexus_import)
            return $this->askImportSettings();

        // disable ALL hooks
        $this->getDi()->hook = new Am_Hook($this->getDi());


        $done = $this->_request->getInt('done', 0);

        $importSettings = $this->getSession()->ipb_nexus_import;
        $import = $this->_request->getFiltered('i', $importSettings['import']);
        $class = "Am_Import_" . ucfirst($import) . "3";
        $importer = new $class($this->db_ipb_nexus, (array) @$importSettings[$import]);

        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from IPB Nexus";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-ipb-nexus') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->ipb_nexus_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-ipb-nexus",array('done'=>$done,'i'=>$import),false), "$done records imported");
        }
    }

    function createCleanUpForm()
    {
        $form = new Am_Form_Admin();

        $total_products = $this->getDi()->db->selectCell('SELECT count(product_id) FROM ?_product');

        $imported_products = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='ipb_nexus:id'");

        $total_users = $this->getDi()->db->selectCell('SELECT count(user_id) FROM ?_user');

        $imported_users = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='ipb_nexus:id'");

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

    function cleanUpData($value)
    {
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

       $this->redirectHtml($this->getDi()->url("admin-import-ipb-nexus",false), 'Records removed');
    }

    function cleanAction()
    {
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
                $this->getSession()->ipb_nexus_import = array(
                    'import' => $val['import'],
                    'user' => @$val['user'],
                );
                $this->_redirect('admin-import-ipb-nexus');
                return;
            }
        }
        $this->view->title = "Import IPB Nexus Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }

    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products =
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='ipb_nexus:id'");
        $total = $this->db_ipb_nexus->selectCell("SELECT COUNT(*) FROM ?_nexus_packages");
        if($this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_product"))
        {
            $form->addStatic()
                ->setLabel(___('Clean up v4 Database'))
                ->setContent(
                    sprintf(___('Use this %slink%s to delete data from aMember v4 database and use clean database for import'),
                        '<a href="'.$this->getDi()->url('admin-import-ipb-nexus/clean').'">', '</a>'));
        }
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
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='ipb_nexus:id'");
            $total = $this->db_ipb_nexus->selectCell("SELECT COUNT(*) FROM ?_members");
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
            $this->getSession()->ipb_nexus_db = $this->form->getValue();
            $this->_redirect('admin-import-ipb-nexus');
        }
        else
        {
            $this->view->title = "Import IPB NExus Information";
            $this->view->content = (string) $this->form;
            $this->view->display('admin/layout.phtml');
        }
    }

    /** @return Am_Form_Admin */
    function createMysqlForm()
    {
        $form = new Am_Form_Admin;

        $el = $form->addText('host')->setLabel('IPB MySQL Hostname');
        $el->addRule('required', 'This field is required');

        $form->addText('user')->setLabel('IPB MySQL Username')
            ->addRule('required', 'This field is required');
        $form->addPassword('pass')->setLabel('IPB MySQL Password');
        $form->addText('db')->setLabel('IPB MySQL Database Name')
            ->addRule('required', 'This field is required');
        $form->addText('prefix')->setLabel('IPB Tables Prefix');

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
            $db->query("SELECT * FROM ?_members LIMIT 1");
        }
        catch (Exception $e)
        {
            return "Check database settings - " . $e->getMessage();
        }
    }

}