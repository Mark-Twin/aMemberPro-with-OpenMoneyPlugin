<?php


class_exists('Am_Paysystem_Abstract', true);


class InvoiceCreator_Standard extends InvoiceCreator_Abstract
{

    function doWork()
    {
        $user = $this->user;
        foreach ($this->groups as $oid => $list)
        {
            if(!@$list['payment']){
                // Create an access and that's all;
                foreach($list['access'] as $v){
                        $product = $this->getDi()->productTable->findFirstByData('wm:id', $v['sub_id']);                        
                        if(!$product) continue;
                        $access = $this->getDi()->accessRecord;
                        $access->user_id = $this->user->pk();
                        $access->setDisableHooks();
                        $access->begin_date  =  date('Y-m-d', strtotime($v['startdate']));
                        $access->expire_date =  date('Y-m-d', strtotime($v['expirydate']));
                        $access->product_id = $product->pk();
                        $access->insert();
                    
                }
            }else{
                // Create new invoice;
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->user_id = $this->user->pk();
                $invoice->add($product = $this->getDi()->productTable->findFirstByData('wm:id', $list['payment'][0]['sub_id']), 1);
                $invoice->calculate();
                $invoice->tm_added = date('Y-m-d H:i:s', $list['payment'][0]['stamp']);
                $invoice->tm_started = date('Y-m-d H:i:s', $list['payment'][0]['stamp']);
                $invoice->public_id = $oid;
                $invoice->paysys_id = 'paypal';
                $invoice->status = Invoice::PAID;
                $invoice->data()->set('wm:id', $oid);
                $invoice->insert();
                
                foreach ($list['payment'] as $rec)
                {
                    switch ($rec['status'])
                    {
                        case 'Completed' :
                                // Add payment record;
                                $payment = $this->getDi()->invoicePaymentRecord;
                                $payment->amount = $rec['amount']/100;
                                $payment->currency = $rec['currency'];
                                $payment->dattm = date('Y-m-d H:i:s', $rec['stamp']);
                                $payment->invoice_id = $invoice->pk();
                                $payment->paysys_id = $invoice->paysys_id;
                                $payment->receipt_id = $rec['txn_id'];
                                $payment->transaction_id = sprintf('import-%s', $payment->receipt_id);
                                $payment->user_id = $this->user->pk();
                                $payment->insert();
                                break;
                        case 'Refunded' :
                            $refund = $this->getDi()->invoiceRefundRecord;
                            $refund->invoice_id = $invoice->pk();
                            $refund->user_id = $this->user->pk();
                            $refund->paysys_id = $invoice->paysys_id;
                            $refund->receipt_id = $refund->transaction_id = $rec['txn_id'];
                            $refund->dattm = date('Y-m-d H:i:s', $rec['stamp']);
                            $refund->currency = $rec['currency'];
                            $refund->amount = -$rec['amount']/100;
                            $refund->insert();
                            break;
                    }
                }
                
                if(@$list['access'])
                foreach(@$list['access'] as $rec){
                            // Insert Access; 
                            $access = $this->getDi()->accessRecord;
                            $access->user_id = $this->user->pk();
                            $access->setDisableHooks();
                            $access->begin_date  =  date('Y-m-d', strtotime($rec['startdate']));
                            $access->expire_date =  date('Y-m-d', strtotime($rec['expirydate']));
                            $access->invoice_id = $invoice->pk();
                            $access->product_id = $product->pk();
                            $access->insert();
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
            $type = ($p['status'] == 'Access' ? 'access' : 'payment');
            $this->groups[$p['sub_id'].'-'.$p['user_id']][$type][] = $p;
        }
        
    }

}




class AdminImportWordpressMembershipController extends Am_Mvc_Controller
{

    /** @var Am_Form_Admin */
    protected
        $dbForm;

    /** @var DbSimple_Mypdo */
    protected
        $db_wordpress;

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
            $this->view->content = "$done records imported from Wordpress Membership Plugin";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-wordpress-membership') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->wordpress_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-wordpress-membership", array('done'=>$done,'i'=>$import),false), "$done records imported");
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
                );
                $this->_redirect('admin-import-wordpress-membership');
                return;
            }
        }
        $this->view->title = "Import Wordpress Membership Plugin Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }

    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products =
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='wm:id'");
        $total = $this->db_wordpress->selectCell("SELECT COUNT(*) FROM ?_m_subscriptions");
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
            $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='coupon' AND `key`='wm:id'");
        $totalc = $this->db_wordpress->selectCell("SELECT COUNT(*) FROM ?_m_coupons");
        if($imported_products){
            if ($imported_coupons >= $totalc)
            {
                $cb = $form->addStatic()->setContent("Imported ($imported_coupons of $totalc)");
            } else {
                $cb = $form->addRadio('import', array('value' => 'coupon'));
            }
            $cb->setLabel('Import Coupons');
        }

        if ($imported_products && ($imported_coupons||!$totalc))
        {
            $imported_users =
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='wm:id'");
            $total = $this->db_wordpress->selectCell("SELECT COUNT(*) FROM ?_users");
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

            $this->getSession()->wordpress_db = $this->form->getValue();
            $this->_redirect('admin-import-wordpress-membership');
        }
        else
        {
            $this->view->title = "Import Wordpress  Membership  Information";
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
        $form->addText('prefix')->setLabel('Drupal Tables Prefix');

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
            $db->query("SELECT * FROM ?_m_subscriptions LIMIT 1");
        }
        catch (Exception $e)
        {
            return "Check database settings - " . $e->getMessage();
        }
    }

}


class Am_Import_Coupon3 extends Am_Import_Abstract
{
    static $batches;
    function createBatch(Array $r){
        
        $batch = $this->getDi()->couponBatchRecord;
        $batch->begin_date = $r['coupon_startdate'];
        if($r['discount_type'] == 'amt')
        {
            $batch->discount_type = 'number';
            $batch->discount    =   $r['discount'];
        }
        else
        {
            $batch->discount_type = 'percent';
            $batch->discount    = doubleval($r['discount']);
        }
        $batch->expire_date = $r['coupon_enddate'];
        $batch->is_disabled = 0;
        $batch->is_recurring    =  0;
        $batch->use_count   =   $r['coupon_uses'];
        $batch->user_use_count  =   $r['coupon_uses'];
        $batch->product_ids = $r['coupon_sub_id'] ? $this->getDi()->db->selectCell("select id from ?_data where `table`='product' and `key`='wm:id' and `value`=?", $r['coupon_sub_id']) : "";
        $batch->insert();
        return $batch->pk();
    }
    
    public function doWork(&$context)
    {
        // Imported coupons 
        $importedCoupons = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='coupon' AND `key`='wm:id'");
        $q = $this->db_wordpress->queryResultOnly("SELECT * FROM ?_m_coupons LIMIT ?d,9000000",$context);
        while ($r = $this->db_wordpress->fetchRow($q))
        {
            if (!$this->checkLimits()) return;
            if (in_array($r['id'], $importedCoupons)) 
                continue;
            $context++;

            $coupon = $this->getDi()->couponRecord;
            $coupon->code = $r['couponcode'];
            $coupon->batch_id = $this->createBatch($r);
            $coupon->used_count = $r['coupon_used'];
            $coupon->insert();
            $coupon->data()->set('wm:id', $r['id'])->update();
        }
        return true;
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
        $db_wordpress;

    public
        function getDi()
    {
        return Am_Di::getInstance();
    }

    public
        function __construct($paysys_id, DbSimple_Interface $db)
    {
        $this->db_wordpress = $db;
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
                WHERE `table`='product' AND `key`='wm:id'");
        }
        return @$cache[$pid];
    }

}
abstract
    class Am_Import_Abstract extends Am_BatchProcessor
{

    /** @var DbSimple_Mypdo */
    protected
        $db_wordpress;
    protected
        $options = array();

    /** @var Am_Session_Ns */
    protected
        $session;

    public
        function __construct(DbSimple_Interface $db_wordpress, array $options = array())
    {
        $this->db_wordpress = $db_wordpress;
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
        $importedProducts = $this->getDi()->db->selectCol("SELECT `value` FROM ?_data WHERE `table`='product' AND `key`='wm:id'");
        $q = $this->db_wordpress->queryResultOnly("SELECT * FROM ?_m_subscriptions");
        while ($r = $this->db_wordpress->fetchRow($q))
        {
            if (in_array($r['id'], $importedProducts))
                continue;
            
            
            $context++;

            $p = $this->getDi()->productRecord;
            $p->title = $r['sub_name'];
            $p->description = $r['sub_description'];
            $p->sort_order = $r['order_num'];
            $p->is_disabled = $r['sub_active'] ? 0 : 1;
            $p->data()->set('wm:id', $r['id']);

            $p->insert();

            $bpdata = $this->db_wordpress->selectRow("select * from  ?_m_subscriptions_levels where sub_id = ?", $r['id']);
            $bp = $p->createBillingPlan();
            $bp->title = 'default';
            switch($bpdata['sub_type']){
                case 'finite' : 
                        $bp->first_price = $bpdata['level_price'];
                        $bp->first_period = $bpdata['level_period'].$bpdata['level_period_unit'];
                        $bp->rebill_times = 0;
                    break;
                case 'indefinite' : 
                        $bp->first_price = $bpdata['level_price'];
                        $bp->first_period = Am_Period::MAX_SQL_DATE;
                        $bp->rebill_times = 0;
                    break;
                case 'serial' : 
                        $bp->first_price = $bp->second_price = $bpdata['level_price'];
                        $bp->first_period = $bp->second_period = $bpdata['level_period'].$bpdata['level_period_unit'];
                        $bp->rebill_times = IProduct::RECURRING_REBILLS;
                    break;
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
                WHERE `table`='user' AND `key`='wm:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_wordpress->queryResultOnly("SELECT u.*, um1.meta_value as first_name, um2.meta_value as last_name
            FROM ?_users u
            LEFT JOIN ?_usermeta um1 on u.ID= um1.user_id and um1.meta_key = 'first_name'
            LEFT JOIN ?_usermeta um2 on u.ID= um2.user_id and um2.meta_key = 'last_name'
            WHERE u.ID > ?d 
            ORDER BY u.ID
            {LIMIT ?d} ", $maxImported, $count ? $count : DBSIMPLE_SKIP);

        while ($r = $this->db_wordpress->fetchRow($q))
        {
            if (!$this->checkLimits())
                return;
            $u = $this->getDi()->userRecord;
            $u->email = $r['user_email'];
            $u->added = $r['user_registered'];
            $u->login = $r['user_login'];
            $u->name_f = (string)$r['first_name'];
            $u->name_l = (string)$r['last_name'];
            $u->is_approved = 1;

            $u->data()->set('wm:id', $r['ID']);
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

        $payments = $this->db_wordpress->select("
(select concat('tr', transaction_ID) as id,  transaction_subscription_ID  as sub_id, transaction_user_ID as user_id, transaction_paypal_ID as txn_id, 
transaction_total_amount as amount, transaction_status as status, '' as startdate, '' as expirydate, transaction_stamp as stamp , transaction_currency as currency from ?_m_subscription_transaction where transaction_user_ID=?)
union 
(select concat('rel', rel_id) as id, sub_id, user_id, '' as txn_id, 0 as amount, 'Access', startdate, expirydate, 0  as stamp, '' as currency from ?_m_membership_relationships where user_id =?)
order by user_id desc
", 
            $id,$id);

        $payments = $payments ? $payments : array(); //to add access if exists
        InvoiceCreator_Abstract::factory('standard', $this->db_wordpress)->process($u, $payments);
    }

}
