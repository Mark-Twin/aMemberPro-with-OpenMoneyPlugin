<?php


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

    function process(User $user, array $r)
    {
        $times = unserialize($r['wp_optimizemember_paid_registration_times']);
        $max = max($times);
        foreach ($times as $level => $time)
        {
            if(!($pid = $this->_translateProduct($level))) continue;
            $bpId = Am_Di::getInstance()->productTable->load($pid)->default_billing_plan_id;
            $fisrtPeriod = Am_Di::getInstance()->billingPlanTable->load($bpId)->first_period;
            $period = new Am_Period($fisrtPeriod);

            $this->payment[$pid] = array(
                'start' => sqlTime($time),
                'stop' => $period->addTo(sqlTime($time))
            );
            if($time == $max)
            {
                $lastProduct = $pid;
            }
        }
        $this->payment[$lastProduct]['subscr_id'] = $r['wp_optimizemember_subscr_id'];

        $this->user = $user;
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
                WHERE `table`='product' AND `key`='wom:id'");
        }
        return @$cache[$pid];
    }

    protected function insertAccess($access, $invoice_id=null, $payment_id=null)
    {
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
        $a->product_id = $access['product_id'];
        $a->insert();
    }
}

class InvoiceCreator_Manual extends InvoiceCreator_Abstract
{
    function doWork()
    {
        foreach ($this->payment as $pid => $arr)
        {
            $access = array(
                'product_id' => $pid,
                'access_start_date' => $arr['start'],
                'access_end_date' => $arr['stop'],
            );
            $this->insertAccess($access);
        }
        $this->user->checkSubscriptions();
    }

}

class InvoiceCreator_Paypal extends InvoiceCreator_Abstract
{
    function doWork()
    {
        foreach ($this->payment as $pid => $arr)
        {
            if(empty($arr['subscr_id']))
            {
                $access = array(
                    'product_id' => $pid,
                    'access_start_date' => $arr['start'],
                    'access_end_date' => $arr['stop'],
                );
                $this->insertAccess($access);
            } else
            {
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->user_id = $this->user->pk();

                $item = $invoice->createItem(Am_Di::getInstance()->productTable->load($pid));
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
                $invoice->tm_added = $arr['start'];
                $invoice->tm_started = $arr['start'];
                $invoice->calculate();
                $invoice->data()->set('external_id', $arr['subscr_id']);
                $invoice->insert();

                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->user_id = $this->user->user_id;
                $payment->invoice_id = $invoice->pk();
                $payment->currency = $invoice->currency;
                $payment->amount = $invoice->first_total;
                $payment->paysys_id = 'paypal';
                $payment->dattm = $arr['start'];
                $payment->receipt_id = $arr['subscr_id'];
                $payment->transaction_id = 'import-paypal-' . mt_rand(10000, 99999);
                $payment->insert();

                $access = array(
                    'product_id' => $pid,
                    'access_start_date' => $arr['start'],
                    'access_end_date' => $arr['stop'],
                );
                $this->insertAccess($access, $invoice->pk(), $payment->pk());
            }
        }
        $this->user->checkSubscriptions();
    }
}

class InvoiceCreator_Stripe extends InvoiceCreator_Abstract
{
    function doWork()
    {
        foreach ($this->payment as $pid => $arr)
        {
            if(empty($arr['subscr_id']))
            {
                $access = array(
                    'product_id' => $pid,
                    'access_start_date' => $arr['start'],
                    'access_end_date' => $arr['stop'],
                );
                $this->insertAccess($access);
            } else
            {
                $invoice = $this->getDi()->invoiceRecord;
                $invoice->user_id = $this->user->pk();

                $item = $invoice->createItem(Am_Di::getInstance()->productTable->load($pid));
                $item->qty = 1;
                $item->first_discount = 0;
                $item->first_shipping = 0;
                $item->first_tax = 0;
                $item->second_discount = 0;
                $item->second_shipping = 0;
                $item->second_tax = 0;
                $item->_calculateTotal();

                $invoice->addItem($item);
                $invoice->paysys_id = 'stripe';
                $invoice->tm_added = $arr['start'];
                $invoice->tm_started = $arr['start'];
                $invoice->calculate();
                $invoice->data()->set('stripe_token', $arr['subscr_id']);
                $invoice->insert();

                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->user_id = $this->user->user_id;
                $payment->invoice_id = $invoice->pk();
                $payment->currency = $invoice->currency;
                $payment->amount = $invoice->first_total;
                $payment->paysys_id = 'paypal';
                $payment->dattm = $arr['start'];
                $payment->receipt_id = $arr['subscr_id'];
                $payment->transaction_id = 'import-paypal-' . mt_rand(10000, 99999);
                $payment->insert();

                $access = array(
                    'product_id' => $pid,
                    'access_start_date' => $arr['start'],
                    'access_end_date' => $arr['stop'],
                );
                $this->insertAccess($access, $invoice->pk(), $payment->pk());
            }
        }
        $this->user->checkSubscriptions();
    }
}



abstract  class Am_Import_Abstract extends Am_BatchProcessor
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

class Am_Import_User3 extends Am_Import_Abstract
{
    function doWork(& $context)
    {
        $maxImported =
            (int) $this->getDi()->db->selectCell("SELECT `value` FROM ?_data
                WHERE `table`='user' AND `key`='wom:id'
                ORDER BY `id` DESC LIMIT 1");
        $count = @$this->options['count'];
        if ($count)
            $count -= $context;
        if ($count < 0)
            return true;
        $q = $this->db_wordpress->queryResultOnly("
            SELECT
                u.*,
                um1.meta_value as first_name,
                um2.meta_value as last_name,
                um3.meta_value as wp_optimizemember_subscr_gateway,
                um4.meta_value as wp_optimizemember_subscr_id,
                um5.meta_value as wp_optimizemember_paid_registration_times
            FROM ?_users u
            LEFT JOIN ?_usermeta um1 on u.ID = um1.user_id and um1.meta_key = 'first_name'
            LEFT JOIN ?_usermeta um2 on u.ID = um2.user_id and um2.meta_key = 'last_name'
            LEFT JOIN ?_usermeta um3 on u.ID = um3.user_id and um3.meta_key = 'wp_optimizemember_subscr_gateway'
            LEFT JOIN ?_usermeta um4 on u.ID = um4.user_id and um4.meta_key = 'wp_optimizemember_subscr_id'
            LEFT JOIN ?_usermeta um5 on u.ID = um5.user_id and um5.meta_key = 'wp_optimizemember_paid_registration_times'
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

            $u->data()->set('wom:id', $r['ID']);
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
                if($r['wp_optimizemember_subscr_gateway'])
                {
                    InvoiceCreator_Abstract::factory($r['wp_optimizemember_subscr_gateway'], $this->db_wordpress)->process($u, $r);
                }elseif ($r['wp_optimizemember_paid_registration_times'])
                {
                    InvoiceCreator_Abstract::factory('Manual', $this->db_wordpress)->process($u, $r);
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
}

class AdminImportWordpressOptimizeMemberController extends Am_Mvc_Controller
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

        if($import == 'product')
        {
            foreach ($importSettings['pr_link'] as $wom => $am)
            {
                if($am)
                {
                    $this->getDi()->productTable->load($am)->data()->set('wom:id', $wom)->update();
                    $done++;
                }
            }
            $this->view->title = "Product Linking Finished";
            $this->view->content = "$done records linked from Wordpress Optimize Member Plugin";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-wordpress-optimize-member') . "'>Continue to import other information</a>";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->wordpress_import = null;
            return;
        }

        $class = "Am_Import_" . ucfirst($import) . "3";
        $importer = new $class($this->db_wordpress, (array) @$importSettings[$import]);

        if ($importer->run($done) === true)
        {
            $this->view->title = ucfirst($import) . " Import Finished";
            $this->view->content = "$done records imported from Wordpress Optimize Member Plugin";
            $this->view->content .= "<br /><br/><a href='" . Am_Di::getInstance()->url('/admin-import-wordpress-optimize-member') . "'>Continue to import other information</a>";
            $this->view->content .= "<br /><br />Do not forget to <a href='" . Am_Di::getInstance()->url('admin-rebuild') . "'>>Rebuild Db</a> after all import operations are done.";
            $this->view->display('admin/layout.phtml');
            $this->getSession()->wordpress_import = null;
        }
        else
        {
            $this->redirectHtml($this->getDi()->url("admin-import-wordpress-optimize-member", array('done'=>$done,'i'=>$import),false), "$done records imported");
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
                    'pr_link' => @$val['pr_link'],
                );
                $this->_redirect('admin-import-wordpress-optimize-member');
                return;
            }
        }
        $this->view->title = "Import Wordpress Optimize Member Plugin Information";
        $this->view->content = (string) $this->form;
        $this->view->display('admin/layout.phtml');
    }

    function createImportForm(& $defaults)
    {
        $form = new Am_Form_Admin;
        /** count imported */
        $imported_products = $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='product' AND `key`='wom:id'");

        $wopCfg = $this->db_wordpress->selectCell("SELECT option_value FROM ?_options WHERE option_name = ?", 'ws_plugin__optimizemember_options');
        $wopCfg = unserialize($wopCfg);
        if(is_array($wopCfg))
        {

            $amProducts = array('' => '-- Please Select --') + $this->getDi()->productTable->getOptions();
            $womProducts = array();
            for($i = 0; $i <= 10; $i++)
            {
                $womProducts['level' . ($i ? $i : '')] = $wopCfg['level' . $i . '_label'];
            }
            if ($imported_products)
            {
                $cb = $form->addStatic()->setContent("Linked");
            }
            else
            {
                $cb = $form->addRadio('import', array('value' => 'product'));
                foreach ($womProducts as $key => $value)
                {
                    $form->addSelect("pr_link[" . $key . "]")
                        ->setLabel($value)
                        ->loadOptions($amProducts);
                }
                $form->addRule('callback2', '-error-', array($this, 'validateForm'));
            }
            $cb->setLabel('Link Products');
        }
        
        if (!is_array($wopCfg) || $imported_products)
        {
            $imported_users =
                $this->getDi()->db->selectCell("SELECT COUNT(id) FROM ?_data WHERE `table`='user' AND `key`='wom:id'");
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
                $cb->addStatic()->setContent('<br />Keep the same user IDs');
                $keep_id_chkbox = $cb->addCheckbox('user[keep_user_id]');
                if($this->getDi()->db->selectCell("SELECT COUNT(*) FROM ?_user")){
                    $keep_id_chkbox->setAttribute('disabled');
                    $cb->addStatic()
                        ->setContent('User database have records already. Please use Clean Up if you want to keep the same user IDs');
                }
            }
            $cb->setLabel('Import User and Payment Records');
        }
        $form->addSaveButton('Run');

        $defaults = array(
            //'user' => array('start' => 5),
        );
        return $form;
    }

    public function validateForm($vars)
    {
        if($vars['import'] == 'product')
            foreach ($vars['pr_link'] as $v)
                if($v)
                    return null;
        return "It's required to link Wordpress Optimize Member products with aMember products";
    }


    function askDbSettings()
    {
        $this->form = $this->createMysqlForm();
        if ($this->form->isSubmitted() && $this->form->validate())
        {

            $this->getSession()->wordpress_db = $this->form->getValue();
            $this->_redirect('admin-import-wordpress-optimize-member');
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
