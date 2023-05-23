<?php
//needed tables:
//wp_users
//wp_mgm_transaction_options
//wp_mgm_transaction


class_exists('Am_Paysystem_Abstract', true);
define('WP_IMPORT_ID','wmgcmm:id');

abstract class InvoiceCreator_Abstract
{
    /** User  */
    protected $user;
    protected $order = array();
    protected $paysys_id;

    /** @var DbSimple_Mypdo */
    protected $db_wordpress;

    public function getDi()
    {
        return Am_Di::getInstance();
    }

    public function __construct($paysys_id, DbSimple_Interface $db)
    {
        $this->db_wordpress = $db;
        $this->paysys_id = $paysys_id;
    }

    function process(User $user, array $order)
    {
        $this->user = $user;
        $this->order = $order;
        return $this->doWork();
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
                WHERE `table`='product' AND `key`= ? ", WP_IMPORT_ID);
        }
        return @$cache[$pid];
    }

    protected function insertAccess($access, $invoice_id=null, $payment_id=null)
    {
        $a = $this->getDi()->accessRecord;
        $a->setDisableHooks();
        $a->user_id = $this->user->user_id;
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
        $a->product_id = $access['product_id'];
        $a->insert();
    }
}

class InvoiceCreator_Paypal extends InvoiceCreator_Abstract
{
    function doWork()
    {
        /*@var $product Product*/
        if(!($product = Am_Di::getInstance()->productTable->load($this->_translateProduct($this->order['item_id']), false)))
            return;
        /*@var $invoice Invoice*/
        $invoice = $this->getDi()->invoiceRecord;
        $invoice->user_id = $this->user->pk();
        $invoice->public_id = $this->order['iid'];

        /*@var $item InvoiceItem*/
        $item = $invoice->createItem($product);
        $item->qty = $this->order['quantity'];
        $item->first_discount = 0;
        $item->first_shipping = 0;
        $item->first_tax = 0;
        $item->second_discount = 0;
        $item->second_shipping = 0;
        $item->second_tax = 0;
        $item->_calculateTotal();

        $invoice->addItem($item);
        $invoice->paysys_id = 'paypal';
        $invoice->tm_added = $this->order['date_added'];
        $invoice->tm_started = $this->order['date_added'];
        $invoice->calculate();
        $invoice->insert();

        $payments = $this->db_wordpress->select("
            SELECT
                txn_id, subscr_id, received, txn_type, ipn_content
            FROM mm_paypal_ipn_log
            WHERE
                order_id = ?d
                AND (payment_status = ? OR txn_type = ?)
        ", $this->order['order_id'], 'Completed', 'subscr_cancel');

        $cnt = 0;
        foreach ($payments as $p)
        {
            $invoice->data()->set('external_id', $p['subscr_id']);

            if($p['txn_type'] == 'subscr_cancel')
            {
                $invoice->tm_cancelled = $p['received'];
                $invoice->status = Invoice::RECURRING_CANCELLED;
                continue;
            }
            $data = unserialize($p['ipn_content']);
            $amount = ($a = $data['mc_gross']) ? $a : $data['payment_gross'];

            /*@var $payment InvoicePayment*/
            $payment = $this->getDi()->invoicePaymentRecord;
            $payment->user_id = $this->user->user_id;
            $payment->invoice_id = $invoice->pk();
            $payment->currency = $data['mc_currency'];
            $payment->amount = moneyRound($amount);
            $payment->paysys_id = 'paypal';
            $payment->dattm = $p['received'];
            $payment->receipt_id = $p['subscr_id'];
            $payment->transaction_id = 'import-paypal-' . $p['txn_id'];
            $payment->insert();

            // access
            $start = sqlDate($p['received']);
            $period = new Am_Period($cnt ? $invoice->second_period : $invoice->first_period);
            $expire = $period->addTo($start);

            $access = array(
                'product_id' => $product->pk(),
                'access_start_date' => $start,
                'access_end_date' => $expire,
            );
            $this->insertAccess($access, $invoice->pk(), $payment->pk());

            $cnt++;
        }
        $invoice->update();
        $this->user->checkSubscriptions();
    }
}

abstract class Am_Import_Abstract extends Am_BatchProcessor
{
    /** @var DbSimple_Mypdo */
    protected $db_wordpress;
    protected $options = array();

    /** @var Am_Session_Ns */
    protected $session;

    public function __construct(DbSimple_Interface $db_wordpress, array $options = array())
    {
        $this->db_wordpress = $db_wordpress;
        $this->options = $options;
        $this->session = $this->getDi()->session->ns(get_class($this));
        parent::__construct(array($this, 'doWork'));
        $this->init();
    }

    public function init()
    {
    }

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
        define('AM_DEBUG',true);error_reporting(E_ALL);ini_set('display_errors','On');

        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`= ? ", WP_IMPORT_ID);
        //print_rre($importedProducts);
        $prs = $this->db_wordpress->selectCell("SELECT option_value FROM ?_options where option_name='mgm_subscription_packs_options'");
        $prs = unserialize($prs);
        foreach($prs['packs'] as $r)
        {
            if (@in_array($r['id'], $importedProducts))
                continue;
            $data = $r;
            
            //******************************************************************
            // PRODUCT
            //******************************************************************
            if (!in_array($data['membership_type'], $importedProducts))
            {
                $context++;
                $p = $this->getDi()->productRecord;
                $p->title = $data['description'];
                $p->currency = $data['currency'];
                $p->data()->set(WP_IMPORT_ID, $data['id']);
                if(@$this->options['keep_product_id']){
                    $p->disableInsertPkCheck(true);
                    $p->product_id = $r['id'];
                    $p->insert(false)->refresh();
                }
                else
                    $p->insert();
                
                $importedProducts[] = $data['id'];
                $bp = $p->createBillingPlan();
                $bp->title = 'default';
                if(@$data['trial_on'] && @$data['trial_duration'] > 0)
                {
                    $bp->first_price = moneyRound(@$data['trial_cost']);
                    $bp->first_period = $data['trial_duration'].@$data['trial_duration_type'];
                }
                else
                {
                    $bp->first_price = moneyRound(@$data['cost']);
                    $bp->first_period = $data['duration'].$data['duration_type'];
                }

                if(array_key_exists('num_cycles',$data))
                {
                    if($data['num_cycles'] == '0')
                    {
                        if(moneyRound($data['cost']) > 0)
                            $bp->rebill_times = 99999;
                        else
                            $bp->rebill_times = 0;
                    }
                    elseif($data['num_cycles'] == 1)
                        $bp->rebill_times = 0;
                    else
                        $bp->rebill_times = $data['num_cycles'];
                    if($bp->rebill_times > 1)
                    {
                        $bp->second_price = moneyRound($data['cost']);
                        $bp->second_period = $data['duration'].$data['duration_type'];
                    }
                }
                $bp->insert();
            }
        }
        return true;
    }

}

class Am_Import_User3 extends Am_Import_Abstract
{
    function doWork(& $context)
    {
        $maxImported = (int) $this->getDi()->db->selectCell("
            SELECT `value` FROM ?_data WHERE `table`='user' AND `key`= ? ORDER BY `id` DESC LIMIT 1
        ", WP_IMPORT_ID);
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_wordpress->queryResultOnly("
            SELECT u.* FROM ?_users u
            WHERE u.ID > ?d
            ORDER BY u.ID
            {LIMIT ?d}
        ", $maxImported, $count ? $count : DBSIMPLE_SKIP);

        while ($r = $this->db_wordpress->fetchRow($q))
        {
            if (!$this->checkLimits())
                return;
            $u = $this->getDi()->userRecord;
            $u->email = $r['user_email'];
            $u->added = $r['user_registered'];
            $u->login = $r['user_login'];
            list($f,$l) = explode(' ', $r['display_name']);
            if($f && $l)
            {
                $u->name_f = $f;
                $u->name_l = $l;
            }
            else
            {
                list($f,$l) = preg_split('/(?=[A-Z])/', $r['display_name'], -1, PREG_SPLIT_NO_EMPTY);
                if($f && $l)
                {
                    $u->name_f = $f;
                    $u->name_l = $l;
                }
                else
                {
                    $u->name_f = $r['display_name'];
                    $u->name_l = $r['display_name'];
                }
            }
            $u->is_approved = 1;

            $u->data()->set(WP_IMPORT_ID, $r['ID']);
            $u->data()->set('signup_email_sent', 1); // do not send signup email second time
            

            $u->pass = $r['user_pass'];
            if(@$this->options['keep_user_id']){
                $u->disableInsertPkCheck(true);
                $u->user_id = $r['ID'];
            }
            try
            {
                if(@$this->options['keep_user_id'])
                    $u->insert(false)->refresh();
                else
                    $u->insert();
                $context++;
            }
            catch (Am_Exception_Db_NotUnique $e)
            {
                echo "Could not import user: " . $e->getMessage() . "<br />\n";
            }
        }
        return true;
    }

}

class Am_Import_Payment3 extends Am_Import_Abstract
{
    function doWork(& $context)
    {
        define('AM_DEBUG',true);error_reporting(E_ALL);ini_set('display_errors','On');
        $q = $this->db_wordpress->queryResultOnly("
            SELECT t.*,top.option_value as ccbill_subscription_id,DATE(t.transaction_dt) as begin_date FROM ?_mgm_transactions t
            LEFT JOIN ?_mgm_transaction_options top ON t.id = top.transaction_id and top.option_name = 'ccbill_subscription_id'
            WHERE t.status in('Active','Awaiting Cancelled')
            ORDER BY t.id
            LIMIT ?d, 1000
        ", $context);
        $products = array();
        while ($r = $this->db_wordpress->fetchRow($q))
        {
            //$this->getDi()->errorLogTable->log('batch '.time().' - '.$r['id']);
            if($this->getDi()->db->selectCell("SELECT 'value' from ?_data where `key` = ? and `table` = 'access'", WP_IMPORT_ID) > 0 )
            {
                $context++;
                continue;                
            }
            if (!$this->checkLimits())
            {
                $this->getDi()->errorLogTable->log('checkLimits');
                return;
            }
            $data = json_decode($r['data'],true);
            if(!$data['id'])
                $data['id'] = $data['pack_id'];
            if(!$data['id'])
            {
                $context++;
                continue;
            }
            $amount = moneyRound($data['cost']);
            if(!@$products[$data['id']])
            {
                $products[$data['id']] = $this->getDi()->productTable->findFirstByData(WP_IMPORT_ID, $data['id']);
            }
            if(!$amount)
            {
                //add just access                
                $a = $this->getDi()->accessRecord;
                if(@$this->options['use_wp_id'])
                {
                    $a->user_id = $r['user_id'];
                    $a->product_id = $data['id'];
                }
                else
                {
                    $a->user_id = $this->getDi()->db->selectCell("SELECT id from ?_data where `table`='user' and `key` = ? and `value` = ?", WP_IMPORT_ID, $r['user_id']);
                    $a->product_id = $this->getDi()->db->selectCell("SELECT id from ?_data where `table`='product' and `key` = ? and `value` = ?", WP_IMPORT_ID, $data['id']);
                }
                $a->begin_date = $r['begin_date'];
                $p = new Am_Period($products[$data['id']]->getBillingPlan()->first_period);
                $a->expire_date = $p->addTo($a->begin_date);
                $a->data()->set(WP_IMPORT_ID,$r['id']);
                try{
                $a->insert();
                }
                catch(Am_Exception $ex)
                {
                    $this->getDi()->errorLogTable->logException($ex);
                    echo "Could not import transaction: " . $ex->getMessage() . "<br />\n";
                }
                $context++;
            }
            else
            {
                $invoice = false;
                foreach($this->getDi()->invoiceTable->findBy(array('user_id' => $r['user_id'])) as $invoice_)
                {
                    if($invoice)
                        continue;
                    if($item_ = $this->getDi()->invoiceItemTable->findFirstBy(array('invoice_id' => $invoice_->invoice_id,'item_type' => 'product', 'item_id' => $data['id'])))
                        $invoice = $invoice_;
                }
                if(!$invoice)
                {
                    $invoice = $this->getDi()->invoiceRecord;
                    if(@$this->options['use_wp_id'])
                    {
                        try{
                            $invoice->setUser($this->getDi()->userTable->load($r['user_id']));
                            $invoice->add($products[$data['id']]);
                        }
                        catch(Am_Exception $ex)
                        {
                            echo "Could not import transaction: " . $ex->getMessage() . "<br />\n";
                            $context++;
                            continue;
                        }

                    }
                    else
                    {
                        try{
                            $invoice->setUser($this->getDi()->userTable->findFirstByData(WP_IMPORT_ID, $r['user_id']));
                            $invoice->add($products[$data['id']]);
                        }
                        catch(Am_Exception $ex)
                        {
                            echo "Could not import transaction: " . $ex->getMessage() . "<br />\n";
                            $context++;
                            continue;
                        }
                        
                    }
                    $invoice->currency = $data['currency'];
                    $invoice->calculate();
                        $invoice->setPaysystem('ccbill');
                    $invoice->insert();
                }
                //*************************************
                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->amount = $amount;
                $payment->currency = $data['currency'];
                $payment->dattm = $r['transaction_dt'];
                $payment->paysys_id = 'ccbill';
                $payment->invoice_id = $invoice->pk();
                $payment->receipt_id = $r['ccbill_subscription_id'] ? $r['ccbill_subscription_id'] : $r['id'];
                $payment->user_id = $invoice->user_id;
                $payment->transaction_id = $r['ccbill_subscription_id'];
                $payment->invoice_public_id = $invoice->public_id;
                try{
                    $payment->insert();
                }
                catch(Am_Exception $ex)
                {
                    echo "Could not import transaction: " . $ex->getMessage() . "<br />\n";
                    $context++;
                    continue;
                }
                //**********************************                    
                $a = $this->getDi()->accessRecord;
                
                $a->user_id = $invoice->user_id;
                if(@$this->options['use_wp_id'])
                {
                    $a->product_id = $data['id'];
                }
                else
                {
                    $a->product_id = $this->getDi()->db->selectCell("SELECT id from ?_data where `table`='product' and `key` = ? and `value` = ?", WP_IMPORT_ID, $data['id']);
                }
                $a->begin_date = $r['begin_date'];
                $p = new Am_Period($products[$data['id']]->getBillingPlan()->first_period);
                $a->expire_date = $p->addTo($a->begin_date);
                $a->invoice_id = $invoice->pk();
                $a->invoice_payment_id = $payment->pk();
                $a->transaction_id = $r['ccbill_subscription_id'];
                $a->invoice_public_id = $invoice->public_id;
                $a->data()->set(WP_IMPORT_ID,$r['id']);
                $a->insert();
                $context++;               
                
            }
        }
        return true;
    }

}


class AdminImportWordpressMagicMembersController extends Am_Mvc_Controller
{

    /** @var Am_Form_Admin */
    protected $dbForm;

    /** @var DbSimple_Mypdo */
    protected $db_wordpress;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SUPER_USER);
    }

    function indexAction()
    {
        Am_Mail::setDefaultTransport(new Am_Mail_Transport_Null());
        if ($this->_request->get('start'))
        {
            $this->getSession()->wordpress_db = null;
            $this->getSession()->wordpress_import = null;
        }
        elseif ($this->_request->get('import_settings'))
        {
            $this->getSession()->wordpress_import = null;
        }

        if (!$this->getSession()->wordpress_db)
            return $this->askDbSettings();

        $this->db_wordpress = Am_Db::connect($this->getSession()->wordpress_db);
        if (!$this->getSession()->wordpress_import)
            return $this->askImportSettings();

        // disable ALL hooks
        $this->getDi()->hook = new Am_Hook($this->getDi());


        $done = $this->_request->getInt('done', 0);

        $importSettings = $this->getSession()->wordpress_import;
        $import = $this->_request->getFiltered('i', $importSettings['import']);
        $class = "Am_Import_" . ucfirst($import) . "3";
        $importer = new $class($this->db_wordpress, (array) @$importSettings[$import]);

        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from Wordpress Magic Members Plugin";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-wordpress-magic-members') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->wordpress_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-wordpress-magic-members",array('done'=>$done,'i'=>$import),false), "$done records imported");
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
                $this->getSession()->wordpress_import = array(
                    'import' => $val['import'],
                    'user' => @$val['user'],
                    'product'   => @$val['product'],
                    'payment'   => @$val['payment'],
                    'pr_link' => @$val['pr_link'],
                );
                $this->_redirect('admin-import-wordpress-magic-members');
                return;
            }
        }
        $this->view->title = "Import Wordpress Magic Members Plugin Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }
    
    function createCleanUpForm(){
        $form = new Am_Form_Admin();
        
        $total_products = $this->getDi()->db->selectCell('SELECT count(product_id) FROM ?_product');
        
        $imported_products = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`= ? ",WP_IMPORT_ID);
        
        $total_users = $this->getDi()->db->selectCell('SELECT count(user_id) FROM ?_user');
        
        $imported_users = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`= ? ",WP_IMPORT_ID);

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
        $form->addAdvCheckbox('remove_invoices')->setLabel('Remove Invoices only');
        $form->addPassword('password')->setLabel('Please confirm your Admin password');
        $form->addSaveButton('Clean Up');
        return $form;
        
    }
    function cleanUpData($value){
        if(@$value['remove_invoices'])
            $tables = array('access', 'access_cache', 'access_log', 'cc', 'coupon', 'coupon_batch', 'invoice', 'invoice_item', 'invoice_log',
                'invoice_payment', 'invoice_refund');
        else
            $tables = array('access', 'access_cache', 'access_log', 'cc', 'coupon', 'coupon_batch', 'invoice', 'invoice_item', 'invoice_log',
                'invoice_payment', 'invoice_refund', 'saved_pass', 'user', 'user_status', 'user_user_group');
        
        if(@$value['remove_products'])
            $tables = array_merge($tables, array('billing_plan', 'product', 'product_product_category'));
        
        
        if($this->getDi()->modules->isEnabled('helpdesk'))
            $tables = array_merge($tables, array('helpdesk_ticket', 'helpdesk_message'));
        
        if($this->getDi()->modules->isEnabled('newsletter'))
            $tables = array_merge($tables, array('newsletter_list', 'newsletter_user_subscription'));
        
        
        // Doing cleanup
        foreach($tables as $table){
            $this->getDi()->db->query('TRUNCATE TABLE ?_'.$table);
        }
        
        // Doing data table separately. 
        if(!@$value['remove_products']){
            $where = "where `table` <> 'product' and `table`<>'billing_plan'";
        }
        elseif(!@$value['remove_invoices']){
            $where = "where `table` in('access','invoice_item','invoice') ";
        }else $where = '';
            $this->getDi()->db->query('delete from ?_data '.$where);
        
       $this->redirectHtml($this->getDi()->url('admin-import-wordpress-magic-members'), 'Records removed');
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

    function createImportForm(& $defaults)
    {
        define('AM_DEBUG',true);error_reporting(E_ALL);ini_set('display_errors','On');
        $form = new Am_Form_Admin;
        if($total_products = $this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_product")) 
        $form->addStatic()
            ->setLabel(___('Clean up v4 Database'))
            ->setContent(
                sprintf(___('Use this %slink%s to delete data from aMember v4 database and use clean database for import'), 
                    '<a href="'.$this->getDi()->url('admin-import-wordpress-magic-members/clean').'">', '</a>'));
            //******************************************************************
            //  PRODUCTS
            //******************************************************************
        $imported_products = 
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`= ? ", WP_IMPORT_ID);
        $prs = $this->db_wordpress->selectCell("SELECT option_value FROM ?_options where option_name='mgm_subscription_packs_options'");
        $prs = unserialize($prs);
        $total = count($prs['packs']);
        
        /** count imported */
        $cb = $form->addGroup();
        $cb->setLabel('Import Products');
        if ($imported_products >= $total)
        {
            $cb->addStatic()->setContent("Imported ($imported_products of $total)");
        } else {
            $cb->addRadio('import', array('value' => 'product'));
        }
        $cb->addStatic()->setContent('<br />Keep the same Product  IDs');
        $keep_id_chkbox = $cb->addCheckbox('product[keep_product_id]');
        if($total_products){
            //$keep_id_chkbox->setAttribute('disabled'); 
            $cb->addStatic()
                ->setContent('Product table have records already. Please BE CAREFUL and use Clean Up if you want to keep the same product IDs');
        }

        if ($imported_products)
        {
            //******************************************************************
            //  USERS
            //******************************************************************
            $imported_users = 
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`= ? ", WP_IMPORT_ID);
            $total = $this->db_wordpress->selectCell("SELECT COUNT(*) FROM ?_users");
            if ($imported_users >= $total)
            {
                $cb = $form->addStatic()->setContent("Imported ($imported_users)");
            } else {
                $cb = $form->addGroup();
                if ($imported_users)
                    $cb->addStatic()->setContent("partially imported ($imported_users of $total total)<br /><br />");
                $cb->addRadio('import', array('value' => 'user'));
                
                $cb->addStatic()->setContent('<br />Keep the same user IDs');
                $keep_id_chkbox = $cb->addCheckbox('user[keep_user_id]');
                if($this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_user")){
                    $keep_id_chkbox->setAttribute('disabled'); 
                    $cb->addStatic()
                        ->setContent('User database have records already. Please use Clean Up if you want to keep the same user IDs');
                }
            }
            $cb->setLabel('Import Users');
            
            //******************************************************************
            //  PAYMENTS
            //******************************************************************
            $imported_payments = 
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='access' AND `key`= ? ", WP_IMPORT_ID);
            $total = $this->db_wordpress->selectCell("SELECT COUNT(*) FROM ?_mgm_transactions WHERE status in('Active','Awaiting Cancelled')");
            if ($imported_payments >= $total)
            {
                $cb = $form->addStatic()->setContent("Imported ($imported_users)");
            } else {
                $cb = $form->addGroup();
                if ($imported_payments)
                    $cb->addStatic()->setContent("partially imported ($imported_payments of $total total)<br /><br />");
                $cb->addRadio('import', array('value' => 'payment'));
                
                $cb->addStatic()->setContent('<br />Use WP products and users IDs');
                $keep_id_chkbox = $cb->addCheckbox('payment[use_wp_id]');
            }
            $cb->setLabel('Import Payments');
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

            $this->getSession()->wordpress_db = $this->form->getValue();
            $this->_redirect('admin-import-wordpress-magic-members');
        }
        else
        {
            $this->view->title = "Import Wordpress Membership Information";
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
            $db->query("SELECT * FROM ?_users LIMIT 1");
        }
        catch (Exception $e)
        {
            return "Check database settings - " . $e->getMessage();
        }
    }

}
