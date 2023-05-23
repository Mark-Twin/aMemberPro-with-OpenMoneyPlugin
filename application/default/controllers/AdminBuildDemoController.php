<?php

class Am_Grid_Action_DemoDel extends Am_Grid_Action_Abstract
{
    protected $title = "Delete";
    protected $id = "delete";

    public function __construct()
    {
        parent::__construct();
        $this->setTarget('_top');
    }

    public function getUrl($record = null, $id = null)
    {
        return $this->grid->getDi()->url('admin-build-demo/delete', array('id' => $record->id));
    }

    public function run()
    {
        //nop
    }
}

class Am_Form_Admin_BuildDemoForm extends Am_Form_Admin
{
    function init()
    {
        $this->addText('users_count')
                ->setLabel(___('Generate Users Count'))
                ->setValue(100);
        $this->addText('email_domain')
                ->setLabel(___("Email Domain\nused to generate email address for users"))
                ->setValue('cgi-central.int');

        if ($this->isProductsExists()) {
            $this->addCheckbox('do_not_generate_products', array('checked'=>'checked'))
                    ->setLabel(
                    ___("Do not generate products\n".
                    "use existing products for demo records")
                    )
                    ->setId('form-do-not-generate-products');

            $this->addMagicSelect('product_ids')
                ->setLabel(___("Use the following product for demo users\n" .
                    'keep it empty to use any products'))
                ->setId('form-product_ids')
                ->loadOptions(Am_Di::getInstance()->productTable->getOptions());

            $this->addScript('script')
                ->setScript(<<<CUT
jQuery(function() {

    function toggle_do_not_generate_products() {
        if (jQuery('input[name=do_not_generate_products]').prop('checked')) {
            jQuery('#form-products-count').parents('.row').hide();
            jQuery('#form-product_ids').parents('.row').show();
        } else {
            jQuery('#form-products-count').parents('.row').show();
            jQuery('#form-product_ids').parents('.row').hide();
        }
    }

    toggle_do_not_generate_products()

    jQuery('input[name=do_not_generate_products]').bind('change', function(){
        toggle_do_not_generate_products();
    })
});
CUT
            );
        }

        $this->addText('products_count', array('size'=>3))
                ->setLabel(___('Generate Products Count'))
                ->setValue(3)
                ->setId('form-products-count');

        $gr = $this->addGroup()->setLabel(___('Invoices Per User'));
        $gr->addText('invoices_per_user', array('size'=>3))
                ->setValue(2);
        $gr->addStatic()->setContent(' +/- ');
        $gr->addText('invoices_per_user_variation', array('size'=>3))
                ->setValue(1);

        $gr = $this->addGroup()->setLabel(___('Products Per Invoice'));
        $gr->addText('products_per_invoice', array('size'=>3))->setValue(2);
        $gr->addStatic()->setContent(' +/- ');
        $gr->addText('products_per_invoice_variation', array('size'=>3))->setValue(1);

        $gr = $this->addGroup()
            ->setLabel(___('Period'));
        $gr->setSeparator(' ');
        $gr->addDate('date_begin', array('size'=>8))
            ->setValue(sqlDate('-60 days'));
        $gr->addDate('date_end', array('size'=>8))
            ->setValue(sqlDate('now'));

        parent::init();
        $this->addSaveButton(___('Generate'));
    }

    function isProductsExists()
    {
        return (boolean)Am_Di::getInstance()->productTable->count();
    }
}

class AdminBuildDemoController extends Am_Mvc_Controller
{
    /** @var Am_Session_Ns */
    protected $session;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_BUILD_DEMO);
    }

    public function init()
    {
        $this->session = $this->getDi()->session->ns('amember_build_demo');
        foreach ($this->getDi()->plugins_protect->loadEnabled()->getAllEnabled() as $pl)
            $pl->destroy();
    }

    function indexAction()
    {
        $this->view->title = ___('Build Demo');
        $this->session->unsetAll();

        $form = new Am_Form_Admin_BuildDemoForm();

        if ($form->isSubmitted()) {
            $form->setDataSources(array(
                    $this->getRequest()
            ));
        }

        if ($form->isSubmitted() && $form->validate())
        {
            $values = $form->getValue();
            $this->session->params = array();
            $this->session->params['email_domain'] = $values['email_domain'];
            $this->session->params['users_count'] = $values['users_count'];
            $this->session->params['products_count'] = $values['products_count'];
            $this->session->params['invoices_per_user'] = $values['invoices_per_user'];
            $this->session->params['invoices_per_user_variation'] = $values['invoices_per_user_variation'];
            $this->session->params['products_per_invoice'] = $values['products_per_invoice'];
            $this->session->params['products_per_invoice_variation'] = $values['products_per_invoice_variation'];
            $this->session->params['product_ids'] = isset($values['product_ids']) ? $values['product_ids'] : null;
            $this->session->params['date_begin'] = amstrtotime($values['date_begin']);
            $this->session->params['date_end'] = amstrtotime($values['date_end']);
            $this->session->proccessed = 0;

            $this->updateDemoHistory();

            if (@$values['do_not_generate_products']) {
                $this->session->params['products_count'] = 0;
                $this->readProductsToSession();
            } else {
                $this->generateProducts();
            }
            $this->sendRedirect();
        }

        $history = $this->getDi()->store->getBlob('demo-builder-records') ?
            $this->createDemoHistoryGrid()->render() : '';

        $this->view->form = $form;
        $this->view->content = (string)$form . $history;
        $this->view->display('admin/layout.phtml');
    }

    function renderGridTitle($record)
    {
        return $record->completed ?
                sprintf('<td>%s</td>',
                    ___('You have generated %d demo products and %d demo customers',
                    $record->products_count,
                    $record->user_count)
                ) :
                sprintf('<td>%s</td>',
                    ___('Generation of demo data  was terminated while processing. Not all records were created.')
                );
    }

    public function createDemoHistoryGrid()
    {
        $records = $this->getDi()->store->getBlob('demo-builder-records');
        $records = $records ? unserialize($records) : array();
        $ds = new Am_Grid_DataSource_Array($records);
        $grid = new Am_Grid_Editable('_h', ___('Demo History'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_BUILD_DEMO);
        $grid->addField(new Am_Grid_Field_Date('date', 'Date', false, '', null, '10%'))
            ->setFormatDate();
        $grid->addField(new Am_Grid_Field('title', 'Title', false, '', array($this, 'renderGridTitle'), '90%'));
        $grid->actionsClear();
        $grid->actionAdd(new Am_Grid_Action_DemoDel);
        return $grid;
    }

    public function generateUser(& $context, $batch)
    {
        $payplugin = $context['payplugin'];
        $demoBuilder = new Am_DemoBuilder($this->getDi(), $this->getID());

        $added = mt_rand($this->session->params['date_begin'],
            $this->session->params['date_end']);

        $user = $demoBuilder->createUser($this->session->params['email_domain'], $added);
        $this->getDi()->hook->call(Am_Event::BUILD_DEMO, array(
            'user' => $user,
            'demoId' => $this->getID(),
            'usersCreated' => $this->session->processed,
            'usersTotal' => $this->session->params['users_count'],
        ));
        $user->save();

        $demoBuilder->createInvoices($user,
                $payplugin,
                $this->session->productIds,
                $this->session->params['invoices_per_user'],
                $this->session->params['invoices_per_user_variation'],
                $this->session->params['products_per_invoice'],
                $this->session->params['products_per_invoice_variation'],
                $added, $this->session->params['date_end']);
        $demoBuilder->createNotes($user, $added, $this->session->params['date_end']);

        $user = null;
        unset($user);

        $this->session->proccessed++;
        return $this->session->proccessed >= $this->session->params['users_count'];
    }

    public function doAction()
    {
        // disable all emails
        Am_Mail::setDefaultTransport(new Am_Mail_Transport_Null());

        $payplugin = null;
        foreach ($this->getDi()->plugins_payment->getEnabled() as $pl)
        {
            if ($pl == 'free') continue;
            $payplugin = $this->getDi()->plugins_payment->loadGet($pl);
            break;
        }

        if (empty($payplugin))
            throw new Am_Exception_InputError('No payment plugins enabled. Visit [aMember Cp -> Setup/Configuration -> Plugins] and enable one');

        $batch = new Am_BatchProcessor(array($this, 'generateUser'));
        $context = array(
            'payplugin' => $payplugin
        );

        if (!$batch->run($context)) {
            $this->sendRedirect();
        }

        $this->updateDemoHistory(true);

        $this->session->unsetAll();
        $this->_redirect('admin-build-demo');
    }

    public function deleteAction()
    {
        $this->session->unsetAll();
        $this->session->proccessed = 0;
        $this->session->lastUserId = 0;

        $query = new Am_Query(Am_Di::getInstance()->userTable);
        $this->session->total = $query->getFoundRows();

        $this->session->params = array();
        $this->session->params['demo-id'] = $this->getRequest()->getParam('id');

        if (!$this->session->params['demo-id']) {
            throw new Am_Exception_InputError('demo-id is undefined');
        }

        $this->deleteProducts($this->session->params['demo-id']);
        $this->deleteProductCategories($this->session->params['demo-id']);

        $this->sendDelRedirect();
    }

    function deleteUser(& $context, $batch)
    {
        $count = 10;

        $query = new Am_Query(Am_Di::getInstance()->userTable);
        $query = $query->addOrder('user_id')->addWhere('user_id>?', $this->session->lastUserId);

        $users = $query->selectPageRecords(0, $count);

        $moreToProcess = false;
        foreach ($users as $user) {
            $demoId = $user->data()->get('demo-id');
            $this->session->lastUserId = $user->pk();
            if ($demoId && $demoId == $this->session->params['demo-id']) {
                $user->delete();
            }
            $this->session->proccessed++;
            $moreToProcess = true;
        }

        return !$moreToProcess;
    }

    function doDeleteAction()
    {
        $batch = new Am_BatchProcessor(array($this, 'deleteUser'));
        $context = null;

        if (!$batch->run($context)) {
            $this->sendDelRedirect();
        }

        $this->delDemoHistory($this->session->params['demo-id']);

        $this->session->unsetAll();
        $this->_redirect('admin-build-demo');
    }

    protected function updateDemoHistory($completed = false)
    {
        $records = $this->getDi()->store->getBlob('demo-builder-records');
        $records = $records ? unserialize($records) : array();

        $record = new stdClass();
        $record->date = $this->getDi()->sqlDate;
        $record->user_count = $this->session->proccessed;
        $record->products_count = $this->session->params['products_count'];
        $record->id = $this->getID();
        $record->completed = $completed;

        $records[$this->getID()] = $record;
        $this->getDi()->store->setBlob('demo-builder-records', serialize($records));
    }

    protected function delDemoHistory($demoId)
    {
        $records = $this->getDi()->store->getBlob('demo-builder-records');
        $records = $records ? unserialize($records) : array();
        unset($records[$demoId]);
        $this->getDi()->store->setBlob('demo-builder-records', serialize($records));
    }

    protected function deleteProducts($demoId)
    {
        foreach ($this->getDi()->productTable->getOptions() as $product_id => $title) {
            $product = $this->getDi()->productTable->load($product_id);
            $prDemoId = $product->data()->get('demo-id');
            if ($prDemoId == $demoId) {
                $product->delete();
            }
        }
        return;
    }

    protected function deleteProductCategories($demoId)
    {
        $query = new Am_Query(new ProductCategoryTable);
        $query->add(new Am_Query_Condition_Field('code', 'LIKE', $demoId.':%'));
        $count = $query->getFoundRows() ? $query->getFoundRows() : 1;
        foreach($query->selectPageRecords(0, $count) as $pCategory) {
            $pCategory->delete();
        }
    }

    protected function readProductsToSession()
    {
        foreach ($this->getDi()->productTable->findBy() as $p) {
            if ($this->session->params['product_ids'] &&
                !in_array($p->pk(), $this->session->params['product_ids'])) continue;
            $this->session->productIds[$p->pk()] = $p->pk();
        }
    }

    protected function generateProducts()
    {
        $demoBuilder = new Am_DemoBuilder($this->getDi(), $this->getID());

        $this->session->productIds = $demoBuilder->createProducts(
                $this->session->params['products_count'], 2);
    }

    protected function sendRedirect()
    {
        $proccessed = $this->session->proccessed;
        $total = $this->session->params['users_count'];
        $this->redirectHtml($this->getUrl('admin-build-demo', 'do'), ___('Building demo records').". " .___('Please wait')."...", ___('Build Demo'), false, $proccessed, $total);
    }

    protected function sendDelRedirect()
    {
        $proccessed = $this->session->proccessed;
        $total = $this->session->total;
        $this->redirectHtml($this->getUrl('admin-build-demo', 'do-delete'), ___('Cleaning up').". ". ___('Please wait')."...", ___('Cleanup'), false, $proccessed, $total);
    }

    protected function getID()
    {
        if (!$this->session->ID) {
            $this->session->ID = md5(mktime() . rand(0, 999));
        }
        return $this->session->ID;
    }
}