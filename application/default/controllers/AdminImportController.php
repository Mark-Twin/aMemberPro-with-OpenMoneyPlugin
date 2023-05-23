<?php

/**
 * Session variable in use
 * path - step 1, path of uploaded file
 * fieldsMap - step 2, map of assigned fields
 * importOptions - step 2, options of import
 * fieldsValue - step 3, collection of defined fields values
 * mode - step 4, mode of import
 * step - current step;
 */
class AdminImportController extends Am_Mvc_Controller
{
    const FIELD_TYPE_USER = 1;
    const FIELD_TYPE_SUBSCRIPTION = 2;
    const FIELD_TYPE_ENCRYPTED_PASS = 3;
    const FIELD_TYPE_ACCESS = 4;

    const MODE_SKIP = 1;
    const MODE_UPDATE = 2;
    const MODE_OVERWRITE = 3;
    const MODE_UPDATE_LEAVE_PASSWORD = 4;

    const FORM_UPLOAD = 'upload';
    const FORM_ASSIGN = 'assign';
    const FORM_DEFINE = 'define';
    const FORM_CONFIRM = 'confirm';

    /** @var Am_Upload */
    protected $upload;
    /** @var Am_Session_Ns */
    protected $session;
    /** @var Import_DataSource */
    protected $dataSource = null;
    /** @var Import_Log */
    protected $log = null;
    protected $importFields = array();
    /* the following properties is used in method getForm */
    private $uploadForm = null;
    private $assignForm = null;
    private $defineForm = null;
    private $confirmForm = null;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_IMPORT);
    }

    public function backAction()
    {
        // dummy - @see _runAction
    }

    public function preDispatch()
    {
        // Try to set any available english UTF-8 locale. Required for fgetcsv function to parse UTF-8 content.
        setlocale(LC_ALL, 'C.UTF-8', 'en_US.UTF-8', 'C');
        Am_Mail::setDefaultTransport(new Am_Mail_Transport_Null);
    }

    public function _runAction($action)
    {
        //handle back action here
        //unset session variable for current step
        //change action name
        //go to process in normal way
        if ($action == 'backAction') {
            switch ($this->session->step) {
                case 2 :
                    if (@$this->session->uploadSerialized) {
                        $this->upload->unserialize($this->session->uploadSerialized);
                        $this->upload->removeFiles();
                    }
                    unset($this->session->path);
                    unset($this->session->fieldsMap);
                    unset($this->session->importOptions);
                    $action = 'indexAction';
                    break;
                case 3 :
                    unset($this->session->fieldsValue);
                    $action = 'assignAction';
                    break;
                case 4 :
                    $action = 'defineAction';
                    break;
            }
            $this->getRequest()->setActionName(str_replace('Action', '', $action));
        }
        parent::_runAction($action);
    }

    public function init()
    {
        if (!$this->getDi()->uploadAcl->checkPermission('import',
                Am_Upload_Acl::ACCESS_WRITE,
                $this->getDi()->authAdmin->getUser())) {

            throw new Am_Exception_AccessDenied();
        }
        $this->session = $this->getDi()->session->ns('amember_import');
        $this->log = Import_Log::getInstance();
        $this->upload = new Am_Upload($this->getDi());
        $this->upload->setPrefix('import')->setTemp(3600);
        if ($this->session->path) {
            $this->dataSource = new Import_DataSource($this->session->path);
            if (isset($this->session->importOptions['delim'])) {
                $this->dataSource->setDelim($this->session->importOptions['delim']);
            }
        }
        $this->addImportFields();
    }

    public function indexAction()
    {
        $this->cleanup();

        if (!is_null($this->getParam('_h_p'))) {
            print ($this->view->importHistory = $this->createDemoHistoryGrid()->render());
            return;
        }
        $this->view->importHistory = $this->getDi()->store->getBlob('import-records') ?
            $this->createDemoHistoryGrid()->render() : '';

        $this->session->step = 1;
        $form = $this->getForm(self::FORM_UPLOAD);

        if ($this->isPost()
            && $form->isSubmitted()
            && $this->upload->processSubmit('file')
            && $files = $this->upload->getUploads()) {
            $file = $files[0];
            $this->session->uploadSerialized = $this->upload->serialize();
            $this->session->path = $file->getFullPath();
            $this->dataSource = new Import_DataSource($this->session->path);
            $this->assignAction();
        } else {
            $this->session->unsetAll();
            $this->view->title = ___('Import: Step 1 of 4');
            $this->view->form = $form;
            $this->view->display('admin/import/index.phtml');
        }
    }

    public function assignAction()
    {
        $this->session->step = 2;

        $form = $this->getForm(self::FORM_ASSIGN);

        $submited = $form->isSubmitted();

        if ($submited) {
            $this->session->importOptions['skip'] = $this->getParam('skip', 0);
            $this->session->importOptions['add_subscription'] = $this->getParam('add_subscription', 0);
            $this->session->importOptions['add_encrypted_pass'] = $this->getParam('add_encrypted_pass', 0);
            $this->session->importOptions['encrypted_pass_format'] = $this->getParam('encrypted_pass_format');
            $this->session->importOptions['delim'] = $this->getParam('delim');
            if ($delimCode = $this->session->importOptions['delim']) {
                $this->dataSource->setDelim($delimCode);
            }
            $this->session->fieldsMap = $this->getFieldsMapFromRequest();
            //some import fields can be not applicable with new configuration
            $this->clearImportFields();
            $this->addImportFields();
            //recreate form with new configuration
            $form = $this->getForm(self::FORM_ASSIGN, $recreate = true, $force_submited = true);
        }

        if ($this->_request->isXmlHttpRequest()) {
            echo $this->renderAssignTable();
            exit;
        }

        if ($submited && !($error = $this->validateAssign())) {
            $this->defineAction();
        } else {
            $table = $this->renderAssignTable();
            if (isset($error) && $error) {
                $this->view->error = $error;
            }
            $this->view->title = ___('Import: Step 2 of 4');
            $this->view->table = $table;
            $this->view->display('admin/import/assign.phtml');
        }
    }

    protected function validateAssign()
    {
        $error = array();
        $fildsToAssign = array();
        foreach ($this->getImportFields(self::FIELD_TYPE_USER) as $field) {
            if ($field->isRequired() && $field->isMustBeAssigned() && !$field->isAssigned()) {
                $fildsToAssign[] = $field->getTitle();
            }
        }

        if (isset($this->session->importOptions['add_subscription']) &&
            1 == $this->session->importOptions['add_subscription']) {

            foreach ($this->getImportFields(array(self::FIELD_TYPE_ACCESS, self::FIELD_TYPE_SUBSCRIPTION)) as $field) {
                if ($field->isRequired() && $field->isMustBeAssigned() && !$field->isAssigned()) {
                    $fildsToAssign[] = $field->getTitle();
                }
            }
        }

        if (isset($this->session->importOptions['add_ecrypted_pass']) &&
            1 == $this->session->importOptions['add_ecrypted_pass']) {

            foreach ($this->getImportFields(self::FIELD_TYPE_ENCRYPTED_PASS) as $field) {
                if ($field->isRequired() && $field->isMustBeAssigned() && !$field->isAssigned()) {
                    $fildsToAssign[] = $field->getTitle();
                }
            }
        }

        if (count($fildsToAssign)) {
            $error[] = ___('Please assign the following fields: ') . implode(', ', $fildsToAssign);
        }

        //lets check if one field was assigned to more than one column
        $fieldsDoubleAssigned = array();
        $alreadyAssigned = array();
        foreach ($this->getRequest()->getParams() as $key => $fieldId) {
            if (strpos($key, 'FIELD') !== 0 || !$fieldId)
                continue;
            if (in_array($fieldId, $alreadyAssigned)) {
                $field = $this->getImportField($fieldId);
                if (!$field) {
                    $field = $this->getImportField($fieldId, self::FIELD_TYPE_ACCESS);
                }
                if (!$field) {
                    $field = $this->getImportField($fieldId, self::FIELD_TYPE_SUBSCRIPTION);
                }

                if (!$field) {
                    $field = $this->getImportField($fieldId, self::FIELD_TYPE_ENCRYPTED_PASS);
                }
                $fieldsDoubleAssigned[] = $field->getTitle();
            } else {
                array_push($alreadyAssigned, $fieldId);
            }
        }

        if (count($fieldsDoubleAssigned)) {
            $error[] = ___('One field can be assigned to one column only, you assigned following fields to several columns: ') . implode(', ', $fieldsDoubleAssigned);
        }

        return $error;
    }

    public function defineAction()
    {
        $this->session->step = 3;
        $this->session->importOptions['skip_invoice'] = $this->getParam('_skip_invoice', 0);
        $form = $this->getForm(self::FORM_DEFINE);

        if ($form->isSubmitted()) {
            $this->session->fieldsValue = $form->getValue();

        }

        $table = $this->renderPreviewTable();

        if ($this->_request->isXmlHttpRequest()) {
            echo $table . '<br />' . $form;
            exit;
        }

        if ($form->isSubmitted() && $form->validate()) {
            $this->confirmAction();
        } else {
            $this->view->title = ___('Import: Step 3 of 4');
            $this->view->table = $table;
            $this->view->form = $form;
            $this->view->display('admin/import/define.phtml');
        }
    }

    public function confirmAction()
    {
        $this->session->step = 4;

        $form = $this->getForm(self::FORM_CONFIRM);

        if ($form->isSubmitted()) {
            $this->session->mode = $this->getParam('mode', self::MODE_SKIP);
            $this->log->clearLog();
            $this->importAction();
        } else {
            $this->view->title = ___('Import: Step 4 of 4');
            $this->view->table = $this->renderPreviewTable();
            $this->view->form = $form;
            $this->view->display('admin/import/confirm.phtml');
        }
    }

    public function doImport(& $context, $batch)
    {
        if ($lineParsed = $this->dataSource->getNextLineParsed()) {
            $this->importLine($lineParsed);
            $this->updateImportHistory();
            return false;
        }
        return true;
    }

    public function importAction()
    {
        $this->getDi()->hook->toggleDisableAll(true);

        $this->dataSource->setOffset($this->getStartOffset());

        if (!$this->getStartOffset()) { //first chunk
            $this->session->timeStart = time();
            if ($this->session->importOptions['skip']) {
                $this->dataSource->getNextLineParsed(); //skip first line;
            }
            if ($this->session->importOptions['add_encrypted_pass']) {
                Am_Config::saveValue('allow_auth_by_savedpass', 1);
            }
        }

        $batch = new Am_BatchProcessor(array($this, 'doImport'));

        $context = null;

        if (!$batch->run($context)) {
            $this->sendRedirect();
        }

        $this->updateImportHistory(true);
        if (@$this->session->uploadSerialized) {
            $this->upload->unserialize($this->session->uploadSerialized);
            $this->upload->removeFiles();
        }
        $this->reportAction();
    }

    public function reportAction()
    {
        $this->view->stat = $this->log->getStat();
        $this->view->import_id = $this->getID();
        $this->view->errors = $this->log->getErrors();
        $this->view->skip = $this->log->getSkip();

        $interval = time() - $this->session->timeStart;
        $duration = array();
        $duration['hrs'] = floor($interval / 3600);
        $duration['min'] = floor(($interval - $duration['hrs'] * 3600) / 60);
        $duration['sec'] = $interval - $duration['hrs'] * 3600 - $duration['min'] * 60;
        $this->view->duration = sprintf("%02d:%02d:%02d", $duration['hrs'], $duration['min'], $duration['sec']
        );
        $this->view->display('admin/import/report.phtml');
        $this->cleanup();
    }

    protected function cleanup()
    {
        if ($this->dataSource)
            unset($this->dataSource);
        $uploads = $this->upload->getUploads();
        foreach ($uploads as $file) {
            $file->delete();
        }
        $this->session->unsetAll();
    }

    public function deleteAction()
    {
        $this->session->unsetAll();
        $this->session->proccessed = 0;
        $this->session->lastUserId = 0;

        $query = new Am_Query($this->getDi()->userTable);
        $this->session->total = $query->getFoundRows();

        $this->session->params = array();
        $this->session->params['import-id'] = $this->getRequest()->getParam('id');

        if (!$this->session->params['import-id']) {
            throw new Am_Exception_InputError('import-id is undefined');
        }

        $this->sendDelRedirect();
    }

    function deleteUser(& $context, $batch)
    {
        $count = 10;

        $query = new Am_Query($this->getDi()->userTable);
        $query = $query->addOrder('user_id')->addWhere('user_id>?', $this->session->lastUserId);

        $users = $query->selectPageRecords(0, $count);

        $moreToProcess = false;
        foreach ($users as $user) {
            $importId = $user->data()->get('import-id');
            $this->session->lastUserId = $user->pk();
            if ($importId && $importId == $this->session->params['import-id']) {
                $user->delete();
            }
            $this->session->proccessed++;
            $moreToProcess = true;
        }

        return!$moreToProcess;
    }

    function doDeleteAction()
    {
        $batch = new Am_BatchProcessor(array($this, 'deleteUser'));
        $context = null;

        if (!$batch->run($context)) {
            $this->sendDelRedirect();
        }

        $this->delImportHistory($this->session->params['import-id']);

        $this->session->unsetAll();
        $this->_redirect('admin-import');
    }

    function renderGridTitle($record)
    {
        return $record->completed ?
            sprintf('<td>%s</td>',
                ___('You have imported %d customers',
                    $record->user_count)
            ) :
            sprintf('<td>%s</td>',
                ___('Import of data was terminated while processing. Anyway some data was imported.')
            );
    }

    public function createDemoHistoryGrid()
    {
        $records = $this->getDi()->store->getBlob('import-records');
        $records = $records ? unserialize($records) : array();
        $ds = new Am_Grid_DataSource_Array($records);
        $ds->setOrder('date', true);
        $grid = new Am_Grid_Editable('_h', ___('Import History'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_IMPORT);
        $grid->addField(new Am_Grid_Field_Date('date', ___('Date'), false, '', null, '10%'))
            ->setFormatDate();

        $urlTpl = $this->getDi()->url('admin-users', array(
            '_u_search' => array(
                'import' => array(
                        'id' => '__ID__'
                    )
            )
        ), false);
        $urlTpl = str_replace('__ID__', '{id}', $urlTpl);

        $grid->addField('id', '#', false, '', null, '10%')
            ->addDecorator(new Am_Grid_Field_Decorator_Link($urlTpl));
        $grid->addField(new Am_Grid_Field('title', ___('Title'), false, '', array($this, 'renderGridTitle')));
        $grid->actionsClear();
        $grid->actionAdd(new Am_Grid_Action_ImportDel);
        return $grid;
    }

    protected function sendDelRedirect()
    {
        $proccessed = $this->session->proccessed;
        $total = $this->session->total;
        $this->redirectHtml($this->getUrl('admin-import', 'do-delete'), ___('Clean up data. Please wait...'), ___('Clean up...'), false, $proccessed, $total);
    }

    protected function updateImportHistory($completed = false)
    {
        $records = $this->getDi()->store->getBlob('import-records');
        $records = $records ? unserialize($records) : array();

        $record = new stdClass();
        $record->date = $this->getDi()->sqlDate;
        $record->user_count = $this->log->getStat(Import_Log::TYPE_SUCCESS);
        $record->id = $this->getID();
        $record->can_be_canceled = ($this->session->mode == self::MODE_SKIP);
        $record->completed = $completed;

        $records[$this->getID()] = $record;
        $this->getDi()->store->setBlob('import-records', serialize($records));
    }

    protected function delImportHistory($importId)
    {
        $records = $this->getDi()->store->getBlob('import-records');
        $records = $records ? unserialize($records) : array();
        unset($records[$importId]);
        $this->getDi()->store->setBlob('import-records', serialize($records));
    }

    protected function sendRedirect()
    {
        $this->session->offset = $this->dataSource->getOffset();
        $proccessed = $this->log->getStat(Import_Log::TYPE_PROCCESSED);
        $total = $this->dataSource->getEstimateTotalLines($proccessed);
        $this->redirectHtml($this->getUrl('admin-import', 'import'), ___('Import data. Please wait...'), ___('Import...'), false, $proccessed, $total);
    }

    protected function getID()
    {
        if (!$this->session->ID) {
            $this->session->ID = sprintf('I-%s',
                strtoupper($this->getDi()->security->randomString(6)));
        }

        return $this->session->ID;
    }

    protected function importLine($lineParsed)
    {
        $this->log->touchStat(Import_Log::TYPE_PROCCESSED);
        $record = $this->createUserRecord($lineParsed);
        $skip_user_fields = array();
        $skip_add_encrypted_pass = false;

        if ($record->isLoaded()) {
            switch ($this->session->mode) {
                case self::MODE_OVERWRITE :
                    $record->delete();
                    $record = $this->createUserRecord($lineParsed);
                    break;
                case self::MODE_UPDATE :
                    break;
                case self::MODE_UPDATE_LEAVE_PASSWORD :
                    $skip_user_fields = array('pass');
                    $skip_add_encrypted_pass = true;
                    break;
                case self::MODE_SKIP :
                    $this->log->touchStat(Import_Log::TYPE_SKIP);
                    $this->log->logSkip($lineParsed);
                    return false;
                default:
                    throw new Am_Exception_InternalError('Unknown mode [' . $this->mode . '] in class ' . __CLASS__);
            }
        }

        foreach ($this->getImportFields(self::FIELD_TYPE_USER) as $field) {
            if (in_array($field->getName(), $skip_user_fields)) continue;
            $field->setValueForRecord($record, $lineParsed);
        }

        try {
            $record->comment .=  ($record->comment ? ', ' : '') . "Imported (import #{$this->getID()})";
            $record->data()->set('import-id', $this->getID());
            $record->data()->set('signup_email_sent', 1);
            $record->save();

            $this->log->touchStat(Import_Log::TYPE_SUCCESS);

            if ($this->session->importOptions['add_subscription']) {
                $this->addSub($record, $lineParsed);
                $record->checkSubscriptions(true);
            }
            if ($this->session->importOptions['add_encrypted_pass'] && !$skip_add_encrypted_pass) {
                $this->addEncryptedPass($record, $lineParsed, $this->session->importOptions['encrypted_pass_format']);
            }
            return $record->pk();
        } catch (Exception $e) {
            $this->log->touchStat(Import_Log::TYPE_ERROR);
            $this->log->logError($e->getMessage(), $lineParsed);
            return false;
        }
    }

    protected function createUserRecord($lineParsed)
    {
        $record = null;

        if (!$record) {
            $loginField = $this->getImportField('login');
            if ($login = $loginField->getValue($lineParsed)) {
                $record = $this->getDi()->userTable->findFirstByLogin($login);
            }
        }

        if (!$record) {
            $emailField = $this->getImportField('email');
            if ($email = $emailField->getValue($lineParsed)) {
                $record = $this->getDi()->userTable->findFirstByEmail($email);
            }
        }

        if (!$record) {
            $record = $this->getDi()->userRecord;
        }

        return $record;
    }

    protected function addSub(Am_Record $user, $lineParsed)
    {
        $user_id = $user->pk();

        $product = $this->getDi()->productTable->load(
                $this->getImportField('product_id', self::FIELD_TYPE_ACCESS)->getValue($lineParsed),
                false
        );

        if (!$product)
            return;

        if(!@$this->session->importOptions['skip_invoice'])
        {
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->tm_added =
            $invoice->tm_started = $this->getImportField('begin_date', self::FIELD_TYPE_ACCESS)->getValue($lineParsed);
            $invoice->user_id = $user_id;
            $invoice->paysys_id = $this->getImportField('paysys_id', self::FIELD_TYPE_SUBSCRIPTION)->getValue($lineParsed);
            $invoice->currency = Am_Currency::getDefault();
            $invoice->add($product);
            $items = $invoice->getItems();
            $invoice->calculate();
            $items[0]->first_price =
            $items[0]->first_total =
            $invoice->first_subtotal =
            $invoice->first_total = $this->getImportField('amount', self::FIELD_TYPE_SUBSCRIPTION)->getValue($lineParsed);
            $invoice->save();
            if ($external_id = $this->getImportField('invoice_external_id', self::FIELD_TYPE_SUBSCRIPTION)->getValue($lineParsed))
                $invoice->data()->set('external_id', $external_id)->update();

            $payment = null;
            if ($amount = $this->getImportField('amount', self::FIELD_TYPE_SUBSCRIPTION)->getValue($lineParsed)) {
                $payment = $this->getDi()->invoicePaymentRecord;
                $payment->amount = $amount;
                $payment->user_id = $user_id;
                $payment->paysys_id = $this->getImportField('paysys_id', self::FIELD_TYPE_SUBSCRIPTION)->getValue($lineParsed);
                $payment->invoice_id = $invoice->pk();
                $payment->invoice_public_id = $invoice->public_id;
                $payment->receipt_id = $this->getImportField('receipt_id', self::FIELD_TYPE_SUBSCRIPTION)->getValue($lineParsed);
                $payment->transaction_id = $this->getID();
                $payment->currency = $invoice->currency;
                $payment->dattm = $this->getImportField('begin_date', self::FIELD_TYPE_ACCESS)->getValue($lineParsed);
                if (empty($payment->dattm)) {
                    $payment->dattm = $this->getDi()->sqlDateTime; // fallback to import time
                }
                $payment->save();
            }
        }
        $access = $this->getDi()->accessRecord;
        $access->begin_date = $this->getImportField('begin_date', self::FIELD_TYPE_ACCESS)->getValue($lineParsed);

        if (!$expire_date = $this->getImportField('expire_date', self::FIELD_TYPE_ACCESS)->getValue($lineParsed)) {
            $p = new Am_Period($product->getBillingPlan()->first_period);
            $expire_date = $p->addTo($access->begin_date);
        }

        $access->expire_date = $expire_date;
        $access->user_id = $user_id;
        $access->product_id = $product->pk();
        $access->invoice_id = !empty($invoice) ? $invoice->pk() : null;
        $access->invoice_public_id = !empty($invoice) ? $invoice->public_id : null;
        $access->invoice_payment_id = !empty($payment) ? $payment->pk() : null;
        $access->transaction_id = $this->getID();
        $access->save();

        if(!@$this->session->importOptions['skip_invoice'])
        {
            $invoice->updateStatus();
            if ($invoice->status == Invoice::RECURRING_ACTIVE)
                $invoice->recalculateRebillDate();
        }
    }

    protected function addEncryptedPass(Am_Record $user, $lineParsed, $format)
    {
        $user_id = $user->pk();

        if ($format == SavedPassTable::PASSWORD_PHPASS) {
            /* Special Case for Native aMember Hash Format */
            $field = $this->getImportField('pass', self::FIELD_TYPE_ENCRYPTED_PASS);
            $user->updateQuick('pass', $field->getValue($lineParsed));
        } else {
            $savedPass = $this->getDi()->savedPassTable->findFirstBy(array(
                    'user_id' => $user_id,
                    'format' => $format
                ));

            if (!$savedPass) {
                $savedPass = $this->getDi()->savedPassRecord;
                $savedPass->format = $format;
                $savedPass->user_id = $user_id;
            }
            foreach ($this->getImportFields(self::FIELD_TYPE_ENCRYPTED_PASS) as $field) {
                $field->setValueForRecord($savedPass, $lineParsed);
            }
            $savedPass->save();
        }
        $user->data()->set(Am_Protect_Databased::USER_NEED_SETPASS, 1);
        $user->save();
    }

    protected static function getImportModeOptions()
    {
        return array(
            self::MODE_SKIP => ___('Skip Line if Exist User with Same Login'),
            self::MODE_UPDATE => ___('Update User if Exist User with Same Login'),
            self::MODE_UPDATE_LEAVE_PASSWORD => ___('Update User if Exist User with Same Login (Do Not Overwrite Existing Password)'),
            self::MODE_OVERWRITE => ___('Overwrite User if Exist User with Same Login')
        );
    }

    protected function getEncryptedPassFormatOptions()
    {
        $types = array(
            SavedPassTable::PASSWORD_PHPASS => 'aMember',
            SavedPassTable::PASSWORD_CRYPT => SavedPassTable::PASSWORD_CRYPT,
            SavedPassTable::PASSWORD_MD5_MD5_PASS_SALT => SavedPassTable::PASSWORD_MD5_MD5_PASS_SALT
        );

        foreach ($this->getDi()->plugins_protect->loadEnabled()->getAllEnabled() as $pl) {
            $format = $pl->getPasswordFormat();
            if ($format === null)
                continue;
            $types[$format] = isset($types[$format]) ? $types[$format] . ', ' . $pl->getTitle() : $pl->getTitle();
        }

        return $types;
    }

    protected function getFieldsMapFromRequest()
    {
        $fieldsMap = array();
        for ($i = 0; $i < $this->dataSource->getColNum(); $i++) {
            $fieldName = $this->getParam('FIELD' . $i);
            $fieldsMap[$fieldName] = $i;
        }
        return $fieldsMap;
    }

    protected function getRequestVarsFromFieldsMap()
    {
        $vars = array();
        $fieldsMap = isset($this->session->fieldsMap) ? $this->session->fieldsMap : array();
        foreach ($fieldsMap as $k => $v) {
            $vars['FIELD' . $v] = $k;
        }
        return $vars;
    }

    protected function getRequestVarsFromImportOptions()
    {
        $result = array();

        if (!isset($this->session->importOptions)) {
            return $result;
        }

        $options = array('skip', 'add_subscription', 'add_encrypted_pass', 'encrypted_pass_format', 'delim');
        foreach ($options as $opName) {
            if (isset($this->session->importOptions[$opName])) {
                $result[$opName] = $this->session->importOptions[$opName];
            }
        }

        return $result;
    }

    protected function getRequestVarsFromFieldsValue()
    {
        if (!isset($this->session->fieldsValue)) {
            return array();
        } else {
            return $this->session->fieldsValue;
        }
    }

    protected function addImportField(Import_Field $field, $type = self::FIELD_TYPE_USER)
    {
        $field->setSession($this->session);
        $field->setDi($this->getDi());
        $this->importFields[$type][$field->getName()] = $field;
    }

    protected function getImportFields($type = self::FIELD_TYPE_USER)
    {
        if(!is_array($type))
            $type = array($type);
        $ret = array();
        foreach($type as $key)
            $ret+= $this->importFields[$key];
        return $ret;
    }

    protected function getSubscriptionImportFields()
    {
        return $this->getImportFields(
                        (isset($this->session->importOptions['skip_invoice']) && $this->session->importOptions['skip_invoice']) ?
                            array(self::FIELD_TYPE_ACCESS) :
                            array(self::FIELD_TYPE_ACCESS, self::FIELD_TYPE_SUBSCRIPTION)

                    );
    }

    /**
     * @param string $fieldName
     * @param enum $type
     * @return Import_Field
     */
    protected function getImportField($fieldName, $type = self::FIELD_TYPE_USER)
    {
        return isset($this->importFields[$type][$fieldName]) ? $this->importFields[$type][$fieldName] : null;
    }

    protected function clearImportFields()
    {
        unset($this->importFields);
    }

    protected function addImportFields()
    {
        //User Fields
        $this->addImportField(new Import_Field('email', ___('Email'), true));
        if (!@$this->session->importOptions['add_encrypted_pass']) {
            $this->addImportField(new Import_Field_UserPass('pass', 'Password', true));
        }
        $this->addImportField(new Import_Field_Name('name', ___('First & Last Name')));
        $this->addImportField(new Import_Field('name_f', ___('First Name')));
        $this->addImportField(new Import_Field('name_l', ___('Last Name')));
        $this->addImportField(new Import_Field_UserLogin('login', ___('Username'), true));
        $this->addImportField(new Import_Field_WithFixed('phone', ___('Phone')));
        $this->addImportField(new Import_Field('street', ___('Street')));
        $this->addImportField(new Import_Field('street2', ___('Street (Second Line)')));
        $this->addImportField(new Import_Field('city', ___('City')));
        $this->addImportField(new Import_Field_State('state', ___('State')));
        $this->addImportField(new Import_Field_Country('country', ___('Country')));
        $this->addImportField(new Import_Field('zip', ___('Zip Code')));
        $this->addImportField(new Import_Field('tax_id', ___('VatId')));
        $this->addImportField(new Import_Field('remote_addr', ___('User IP address')));
        $this->addImportField(new Import_Field_WithFixed('comment', ___('Comment')));
        $this->addImportField(new Import_Field('unsubscribed', ___('Is Unsubscribed? (0 - No, 1 - Yes)')));
        $this->addImportField(new Import_Field('is_locked', ___('Is Locked? (0 - No, 1 - Yes, -1 - Disable auto-locking for this customer)')));
        $this->addImportField(new Import_Field('is_affiliate', ___('Is Affiliate? (0 - No, 1 - Yes)')));
        $this->addImportField(new Import_Field('aff_id', ___('Affiliate Id')));
        $this->addImportField(new Import_Field_Data('external_id', 'Member External ID'));

        //Additional Fields
        foreach ($this->getDi()->userTable->customFields()->getAll() as $field) {
            if (isset($field->from_config) && $field->from_config) {
                if ($field->sql) {
                    if ($field->type == 'date') {
                        $this->addImportField(new Import_Field_Date($field->name, $field->title));
                    } elseif(in_array($field->type, array('multi_select', 'checkbox'))) {
                        $this->addImportField(new Import_Field_Multiselect($field->name, $field->title));
                    } else {
                        $this->addImportField(new Import_Field($field->name, $field->title));
                    }
                } else {
                    if ($field->type == 'date') {
                        $this->addImportField(new Import_Field_Data_Date($field->name, $field->title));
                    } elseif (in_array($field->type, array('multi_select', 'checkbox'))) {
                        $this->addImportField(new Import_Field_Data_Multiselect($field->name, $field->title));
                    } else {
                        $this->addImportField(new Import_Field_Data($field->name, $field->title));
                    }
                }
            }
        }

        //Subscription Fields
        $this->addImportField(new Import_Field_SubProduct('product_id', ___('Subscription (Either ID or Title)'), true), self::FIELD_TYPE_ACCESS);
        $this->addImportField(new Import_Field_SubPaysystem('paysys_id', ___('Paysystem'), true), self::FIELD_TYPE_SUBSCRIPTION);
        $this->addImportField(new Import_Field_WithFixed('receipt_id', ___('Receipt'), true), self::FIELD_TYPE_SUBSCRIPTION);
        $this->addImportField(new Import_Field_WithFixed('amount', ___('Payment Amount'), true), self::FIELD_TYPE_SUBSCRIPTION);
        $this->addImportField(new Import_Field_Date('begin_date', ___('Subscription Begin Date'), true), self::FIELD_TYPE_ACCESS);
        $this->addImportField(new Import_Field_Date('expire_date', ___('Subscription Expire Date'), false), self::FIELD_TYPE_ACCESS);
        $this->addImportField(new Import_Field_Data('invoice_external_id', 'Invoice External ID'), self::FIELD_TYPE_SUBSCRIPTION);

        //Encrypted Pass Fields
        $this->addImportField(new Import_Field('pass', ___('Hash'), true), self::FIELD_TYPE_ENCRYPTED_PASS);
        $this->addImportField(new Import_Field('salt', ___('Salt'), true), self::FIELD_TYPE_ENCRYPTED_PASS);
    }

    protected function getStartOffset()
    {
        if (isset($this->session->offset)) {
            return $this->session->offset;
        } else {
            return 0;
        }
    }

    protected function getFieldOptions($type = self::FIELD_TYPE_USER)
    {
        $options = array();
        foreach ($this->getImportFields($type) as $field) {
            if ($field->isForAssign()) {
                $options[$field->getName()] = $field->getTitle();
            }
        }
        return $options;
    }

    protected function loadFieldsOptions($fSelect, $add_subscription=0, $add_encrypted_pass=0)
    {
        $fSelect->addOption('', '');
        if ($add_subscription || $add_encrypted_pass) {
            $optUser = $fSelect->addOptgroup(___('User'));
            foreach ($this->getFieldOptions(self::FIELD_TYPE_USER) as $key => $value) {
                $optUser->addOption($value, $key);
            }

            if ($add_subscription) {
                $optSub = $fSelect->addOptgroup(___('Subscription'));
                foreach ($this->getFieldOptions(array(self::FIELD_TYPE_ACCESS, self::FIELD_TYPE_SUBSCRIPTION)) as $key => $value) {
                    $optSub->addOption($value, $key);
                }
            }

            if ($add_encrypted_pass) {
                $optSub = $fSelect->addOptgroup(___('Encrypted Password'));
                foreach ($this->getFieldOptions(self::FIELD_TYPE_ENCRYPTED_PASS) as $key => $value) {
                    $optSub->addOption($value, $key);
                }
            }
        } else {
            $fSelect->loadOptions(array('' => '') + $this->getFieldOptions());
        }
    }

    protected function getForm($name, $recreate = false, $force_submited = false)
    {
        $propertyName = $name . 'Form';
        $methodName = 'create' . ucfirst($name) . 'Form';
        if (!$this->$propertyName || $recreate) {
            $this->$propertyName = $this->$methodName($force_submited);
        }
        return $this->$propertyName;
    }

    protected function createAssignForm($force_submited = false)
    {
        $form = new Am_Form_Admin('assign');
        $form->setAction($this->getUrl(null, 'assign'));
        $form->addElement('checkbox', 'skip')
            ->setLabel(___('Skip First Line'))
            ->setId('skip');
        $form->addElement('checkbox', 'add_subscription')
            ->setLabel('<strong>' . ___('Add Subscription') . '</strong>')
            ->setId('add_subscription');
        $form->addElement('checkbox', 'add_encrypted_pass')
            ->setLabel('<strong>' . ___('Import Encrypted Password') . '</strong>')
            ->setId('add_encrypted_pass');
        $form->addElement('select', 'encrypted_pass_format')
            ->loadOptions($this->getEncryptedPassFormatOptions())
            ->setId('encrypted_pass_format');
        $form->addElement('select', 'delim')
            ->setLabel(___('Delimiter'))
            ->loadOptions(Import_DataSource::getDelimOptions())
            ->setId('delim');
        $form->addElement('submit', '_submit_', array('value' => ___('Next')))
            ->setId('_submit_');
        for ($i = 0; $i < $this->dataSource->getColNum(); $i++) {
            $fSelect = $form->addSelect('FIELD' . $i, array('class' => 'csv-fields'))
                    ->setId('FIELD' . $i);
        }

        if ($force_submited || $form->isSubmitted()) {
            $form->setDataSources(array(
                $this->getRequest()
            ));
        } else {
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array(array(
                    'delim' => $this->dataSource->getDelim(Import_DataSource::DELIM_CODE)
                    ) + $this->getRequestVarsFromFieldsMap()
                    + $this->getRequestVarsFromImportOptions()
                )
            ));
        }

        $formValues = $form->getValue();
        $add_subscription = @$formValues['add_subscription'];
        $add_encrypted_pass = @$formValues['add_encrypted_pass'];
        for ($i = 0; $i < $this->dataSource->getColNum(); $i++) {
            $fSelect = $form->getElementsByName('FIELD' . $i);

            $this->loadFieldsOptions($fSelect[0], $add_subscription, $add_encrypted_pass);
        }

        return $form;
    }

    protected function createDefineForm($force_submited = false)
    {
        $form = new Am_Form_Admin('commit');
        $form->setAction($this->getUrl(null, 'define'));
        $fieldset = $form->addElement('fieldset', 'user')
                ->setLabel(___('User'));
        foreach ($this->getImportFields() as $field) {
            $field->buildForm($fieldset);
        }

        if ($this->session->importOptions['add_subscription']) {
            $fieldset = $form->addElement('fieldset', 'subscription')
                    ->setLabel(___('Subscription'));
            $fieldset->addAdvCheckbox('_skip_invoice')->setLabel(___('Do not create invoice. Add only access record'));
            foreach ($this->getSubscriptionImportFields() as $field) {
                $field->buildForm($fieldset);
            }
        }

        if ($this->session->importOptions['add_encrypted_pass']) {
            $fieldset = $form->addElement('fieldset', 'encrypted_pass')
                    ->setLabel(___('Encrypted Pass'));
            foreach ($this->getImportFields(self::FIELD_TYPE_ENCRYPTED_PASS) as $field) {
                $field->buildForm($fieldset);
            }
        }

        $group = $form->addGroup();
        $group->setSeparator(' ');
        $group->addElement('inputbutton', 'back', array('value' => ___('Back')))
            ->setId('back');
        $group->addElement('submit', '_submit_', array('value' => ___('Next')))
            ->setId('_submit_');

        if ($force_submited || $form->isSubmitted()) {
            $form->setDataSources(array($this->getRequest()));
        } else {
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array($this->getRequestVarsFromFieldsValue())
            ));
        }
        return $form;
    }

    protected function createConfirmForm($force_submited = false)
    {
        $form = new Am_Form_Admin('confirm');
        $form->setAction($this->getUrl(null, 'confirm'));

        $form->addAdvRadio('mode')
            ->setLabel(___('Import Mode'))
            ->loadOptions(self::getImportModeOptions())
            ->setValue(self::MODE_SKIP);


        $group = $form->addGroup();
        $group->setSeparator(' ');
        $group->addElement('inputbutton', 'back', array('value' => ___('Back')))
            ->setId('back');
        $group->addElement('submit', '_submit_', array('value' => ___('Do Import')))
            ->setId('_submit_');

        if ($force_submited || $form->isSubmitted()) {
            $form->setDataSources(array($this->getRequest()));
        } else {
            $form->setDataSources(array(new HTML_QuickForm2_DataSource_Array(array())));
        }
        return $form;
    }

    protected function createUploadForm($force_submited = false)
    {
        $form = new Am_Form_Admin('upload');
        $form->setAction($this->getUrl(null, ''));
        $form->setAttribute('enctype', 'multipart/form-data');
        $file = $form->addElement('file', 'file[]')
                ->setLabel(___('File'));
        $file->setAttribute('class', 'styled');
        $file->addRule('required', ___('This field is a requried field'));

        $form->addElement('submit', '_submit_', array('value' => ___('Next')));

        return $form;
    }

    protected function getAssignFormRendered()
    {
        $form = $this->getForm(self::FORM_ASSIGN);

        $renderer = HTML_QuickForm2_Renderer::factory('array');
        $form->render($renderer);
        $form = $renderer->toArray();

        $elements = array();
        foreach ($form['elements'] as $element) {
            $elements[$element['id']] = $element;
        }
        $form['elements'] = $elements;

        return $form;
    }

    protected function renderAssignTable()
    {
        $form = $this->getAssignFormRendered();
        $linesParsed = $this->dataSource->getFirstLinesParsed(10);
        if (!count($linesParsed)) {
            return sprintf('<ul class="error"><li>%s</li></ul>',
                ___('No one line found in the file. It looks like file is empty. You can go back and try another file.'));
        }

        $out = sprintf('<form %s>', $form['attributes']);
        $out .= '<div class="filter-wrap">';
        $out .= $form['elements']['add_encrypted_pass']['label'] . ': ' . $form['elements']['add_encrypted_pass']['html'];
        if ($this->session->importOptions['add_encrypted_pass']) {
            $out .= '<br />';
            $out .= $form['elements']['encrypted_pass_format']['html'];
        }
        $out .= '<br />';
        $out .= $form['elements']['add_subscription']['label'] . ': ' . $form['elements']['add_subscription']['html'];
        $out .= '<br />';
        $out .= $form['elements']['skip']['label'] . ': ' . $form['elements']['skip']['html'];
        $out .= '<br />';
        $out .= $form['elements']['delim']['label'] . ': ' . $form['elements']['delim']['html'];
        $out .= '</div>';
        $out .= '<div class="import-table-wrapper">';
        $out .= '<table class="grid import-preview">';
        $out .= '<tr><th></th>';
        for ($i = 0; $i < $this->dataSource->getColNum(); $i++) {
            $out .= sprintf('<th>%s</th>', $form['elements']['FIELD' . $i]['html']);
        }
        $out .= '</tr>';
        foreach ($linesParsed as $lineNum => $lineParsed) {
            $out .= '<tr class="grid-row data"><td width="1%">' . $lineNum . '</td>';

            foreach ($lineParsed as $colNum => $value) {
                $out .= sprintf('<td class="%s">%s</td>', 'FIELD' . $colNum, $value);
            }

            $out .= '</tr>';
        }
        $out .= '</table>';
        $out .= '</div>';
        $out .= '<br />';
        $out .= '<div class="am-form"><div class="row"><div class="element">';
        $out .= sprintf('<input type="button" name="back" value="%s"> ', ___('Back'));
        $out .= $form['elements']['_submit_']['html'];
        $out .= implode('', $form['hidden']);
        $out .= '</div></div></div>';
        $out .= '</form>';
        return $out;
    }

    protected function renderPreviewTable()
    {
        $out = '<div class="import-table-wrapper">';
        $out .= '<table class="grid import-preview">';
        $out .= '<tr><th></th>';
        $importFields = $this->getImportFields();
        foreach ($importFields as $field) {
            if ($field->isForImport()) {
                $out .= sprintf('<th%s>%s</th>', ($field->isRequired() && !$field->isDefined()) ? ' class="required"' : '', $field->getTitle()
                );
            }
        }
        if ($this->session->importOptions['add_subscription']) {
            $importSubFields = $this->getSubscriptionImportFields();
            foreach ($importSubFields as $field) {
                if ($field->isForImport()) {
                    $out .= sprintf('<th%s>%s</th>', ($field->isRequired() && !$field->isDefined()) ? ' class="required"' : '', $field->getTitle()
                    );
                }
            }
        }
        if ($this->session->importOptions['add_encrypted_pass']) {
            $importEncryptedFields = $this->getImportFields(self::FIELD_TYPE_ENCRYPTED_PASS);
            foreach ($importEncryptedFields as $field) {
                if ($field->isForImport()) {
                    $out .= sprintf('<th%s>%s</th>', ($field->isRequired() && !$field->isDefined()) ? ' class="required"' : '', $field->getTitle()
                    );
                }
            }
        }
        $out .= '</tr>';
        $linesParsed = $this->dataSource->getFirstLinesParsed(10);
        if ($this->session->importOptions['skip']) {
            unset($linesParsed[0]);
        }
        foreach ($linesParsed as $lineNum => $lineParsed) {
            $out .= '<tr class="grid-row data"><td>' . $lineNum . '</td>';

            $dummyUser = $this->getDi()->userRecord;
            foreach ($importFields as $field) {
                if ($field->isForImport()) {
                    $field->setValueForRecord($dummyUser, $lineParsed);
                    $out .= sprintf('<td>%s</td>', $field->getReadableValue($lineParsed, $dummyUser));
                }
            }

            if ($this->session->importOptions['add_subscription']) {
                $importSubFields = $this->getSubscriptionImportFields();
                foreach ($importSubFields as $field) {
                    if ($field->isForImport()) {
                        $out .= sprintf('<td>%s</td>', $field->getReadableValue($lineParsed));
                    }
                }
            }

            if ($this->session->importOptions['add_encrypted_pass']) {
                $importPassFields = $this->getImportFields(self::FIELD_TYPE_ENCRYPTED_PASS);
                foreach ($importPassFields as $field) {
                    if ($field->isForImport()) {
                        $out .= sprintf('<td>%s</td>', $field->getReadableValue($lineParsed));
                    }
                }
            }
            $out .= '</tr>';
        }
        $out .= '</table>';
        $out .= '</div>';
        return $out;
    }
}

class Import_Field
{
    protected $title;
    protected $name;
    protected $isRequired = false;
    /** @var Am_Session_Ns */
    protected $session = null;
    //field can be fetched from CSV file
    protected $isForAssign = true;
    protected $isMustBeAssigned = true;
    /** @var Am_Di */
    protected $di;

    public function __construct($name, $title, $isRequired = false)
    {
        $this->name = $name;
        $this->title = $title;
        $this->isRequired = $isRequired;
    }

    public function setDi(Am_Di $di)
    {
        $this->di = $di;
    }

    /**
     * @return Am_Di
     */
    public function getDi()
    {
        return $this->di;
    }

    public function setSession(Am_Session_Ns $session)
    {
        $this->session = $session;
    }

    public function buildForm(HTML_QuickForm2_Container $form)
    {
        if (!$this->isAssigned()) {
            $this->_buildForm($form);
        }
    }

    protected function _buildForm(HTML_QuickForm2_Container $form)
    {
        //nop
    }

    public function isAssigned()
    {
        return isset($this->session->fieldsMap[$this->getName()]);
    }

    //field can be fetched from CSV file
    public function isForAssign()
    {
        return $this->isForAssign;
    }

    public function isRequired()
    {
        return $this->isRequired;
    }

    //field should be used in import process (Required or Defined)
    public function isForImport()
    {
        return $this->isRequired() ||
        ($this->isAssigned() || $this->isDefined());
    }

    public function isDefined()
    {
        //try to guess if this field is defined
        //getValue should return non empty value
        //in this case
        static $dummyArray;
        if (!is_array($dummyArray)) {
            $dummyArray = range(1, 30);
        }
        return!('' === $this->getValue($dummyArray));
    }

    //this field can be fetched only from CSV file
    public function isMustBeAssigned()
    {
        return $this->isMustBeAssigned;
    }

    public function getAssignedIndex()
    {
        if (isset($this->session->fieldsMap[$this->getName()])) {
            return $this->session->fieldsMap[$this->getName()];
        } else {
            return false;
        }
    }

    public function getName()
    {
        return $this->name;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function setValueForRecord($record, $lineParsed)
    {
        if ($this->isForImport()) {
            $this->_setValueForRecord($record, $this->getValue($lineParsed, $record));
        }
    }

    protected function _setValueForRecord($record, $value)
    {
        $record->{$this->getName()} = $value;
    }

    public function getValue($lineParsed, $partialRecord = null)
    {
        if ($this->isAssigned()) {
            return trim($lineParsed[$this->getAssignedIndex()]);
        } else {
            return '';
        }
    }

    public function getReadableValue($lineParsed, $partialRecord = null)
    {
        return $this->getValue($lineParsed, $partialRecord);
    }
}


class Import_Field_Name extends Import_Field
{
    protected function _setValueForRecord($record, $value)
    {
        $names = explode(" ", $value);
        $name_l = array_pop($names);
        $name_f = implode(" ", $names);
        
        $record->name_f = $name_f;
        $record->name_l = $name_l;
        
    }
    
}


class Import_Field_WithFixed extends Import_Field
{
    protected $isMustBeAssigned = false;

    protected function _buildForm(HTML_QuickForm2_Container $form)
    {
        $el = $form->addText('field_' . $this->getName(), array('class' => 'fixed'))
                ->setLabel($this->getTitle());

        if ($this->isRequired()) {
            $el->addRule('required', ___('This field is a requried field'));
        }
    }

    public function getValue($lineParsed, $partialRecord=null)
    {
        if ($this->isAssigned()) {
            return parent::getValue($lineParsed, $partialRecord);
        } elseif (isset($this->session->fieldsValue['field_' . $this->getName()])) {
            return $this->session->fieldsValue['field_' . $this->getName()];
        } else {
            return '';
        }
    }
}

class Import_Field_Date extends Import_Field
{
    protected $isMustBeAssigned = false;

    protected function _buildForm(HTML_QuickForm2_Container $form)
    {
        $el = $form->addDate('field_' . $this->getName(), array('class' => 'fixed'))
                ->setLabel($this->getTitle());

        if ($this->isRequired()) {
            $el->addRule('required', ___('This field is a requried field'));
        }
    }

    public function getValue($lineParsed, $partialRecord = null)
    {
        $rawValue = $this->getRawValue($lineParsed, $partialRecord);
        return $rawValue ? date('Y-m-d', amstrtotime($rawValue)) : '';
    }

    protected function getRawValue($lineParsed, $partialRecord = null)
    {
        if ($this->isAssigned()) {
            return parent::getValue($lineParsed, $partialRecord);
        } else {
            return (isset($this->session->fieldsValue['field_' . $this->getName()])) ?
                $this->session->fieldsValue['field_' . $this->getName()] :
                '';
        }
    }

    public function getReadableValue($lineParsed, $partialRecord = null)
    {
        if ($date = $this->getValue($lineParsed, $partialRecord)) {
            return amDate($date);
        } else {
            return '';
        }
    }
}

class Import_Field_State extends Import_Field
{
    static $stateOptions;

    public function getReadableValue($lineParsed, $partialRecord = null)
    {
        $state = $this->getValue($lineParsed, $partialRecord);
        $stateOptions = $this->getStateOptions();
        if (isset($stateOptions[$state])) {
            return $stateOptions[$state];
        } else {
            return $state;
        }
    }

    private function getStateOptions()
    {
        if (is_null(self::$stateOptions)) {
            $res = $this->getDi()->db->selectCol("SELECT state as ARRAY_KEY,
                    CASE WHEN tag<0 THEN CONCAT(title, ' (disabled)') ELSE title END
                    FROM ?_state");
            self::$stateOptions = $res;
        }

        return self::$stateOptions;
    }
}

class Import_Field_Country extends Import_Field
{
    static $countryOptions;

    public function getReadableValue($lineParsed, $partialRecord = null)
    {
        $country = $this->getValue($lineParsed, $partialRecord);
        $countryOptions = $this->getCountryOptions();
        if (isset($countryOptions[$country])) {
            return $countryOptions[$country];
        } else {
            return '';
        }
    }

    private function getCountryOptions()
    {
        if (is_null(self::$countryOptions)) {
            self::$countryOptions = $this->getDi()->countryTable->getOptions();
        }

        return self::$countryOptions;
    }
}

class Import_Field_SubProduct extends Import_Field
{
    protected $isMustBeAssigned = false;
    protected static $productOptions = null;
    protected static $productIndex = null;

    static function id($id)
    {
        if (is_null(self::$productIndex)) {
            self::$productIndex = Am_Di::getInstance()->db
                ->selectCol('SELECT product_id, title AS ARRAY_KEY ' .
                    'FROM ?_product');
        }

        return is_numeric($id) ?
            $id :
            (isset(self::$productIndex[$id]) ? self::$productIndex[$id] : '');
    }

    protected function _buildForm(HTML_QuickForm2_Container $form)
    {
        $el = $form->addElement('select', 'field_' . $this->getName())
                ->setLabel($this->getTitle())
                ->loadOptions($this->getProductOptions());

        if ($this->isRequired()) {
            $el->addRule('required', ___('This field is a requried field'));
        }
    }

    public function getValue($lineParsed, $partialRecord = null)
    {
        if ($this->isAssigned()) {
            return self::id(parent::getValue($lineParsed, $partialRecord));
        } elseif (isset($this->session->fieldsValue['field_' . $this->getName()])) {
            return $this->session->fieldsValue['field_' . $this->getName()];
        } else {
            return '';
        }
    }

    public function getReadableValue($lineParsed, $partialRecord = null)
    {
        $product_id = $this->getValue($lineParsed, $partialRecord);
        $productOptions = $this->getProductOptions();
        if (isset($productOptions[$product_id])) {
            return $productOptions[$product_id];
        } else {
            return '';
        }
    }

    private function getProductOptions()
    {
        if (is_null(self::$productOptions)) {
            self::$productOptions = $this->getDi()->productTable->getOptions();
        }

        return self::$productOptions;
    }
}

class Import_Field_SubPaysystem extends Import_Field
{
    protected $isMustBeAssigned = false;
    protected $isForAssign = true;
    private static $paysystemOptions = null;

    protected function _buildForm(HTML_QuickForm2_Container $form)
    {
        $el = $form->addElement('select', 'field_' . $this->getName())
                ->setLabel($this->getTitle())
                ->loadOptions($this->getPaysystemOptions());

        if ($this->isRequired()) {
            $el->addRule('required', ___('This field is a requried field'));
        }
    }

    public function getValue($lineParsed, $partialRecord = null)
    {
        if ($this->isAssigned()) {
            return parent::getValue($lineParsed, $partialRecord);
        } elseif (isset($this->session->fieldsValue['field_' . $this->getName()])) {
            return $this->session->fieldsValue['field_' . $this->getName()];
        } else {
            return '';
        }
    }

    public function getReadableValue($lineParsed, $partialRecord = null)
    {
        $paysys_id = $this->getValue($lineParsed, $partialRecord);
        $paysystemOptions = $this->getPaysystemOptions();
        if (isset($paysystemOptions[$paysys_id])) {
            return $paysystemOptions[$paysys_id];
        } else {
            return '';
        }
    }

    private function getPaysystemOptions()
    {
        if (is_null(self::$paysystemOptions)) {
            self::$paysystemOptions = $this->getDi()->paysystemList->getOptions();
        }

        return self::$paysystemOptions;
    }
}

class Import_Field_Multiselect extends Import_Field
{
    protected function _setValueForRecord($record, $value)
    {
        $record->{$this->getName()} = preg_split('/[:,]/', $value);
    }
}

class Import_Field_Data extends Import_Field
{
    protected function _setValueForRecord($record, $value)
    {
        $record->data()->set($this->getName(), $value);
    }
}

class Import_Field_Data_Date extends Import_Field_Date
{
    protected function _setValueForRecord($record, $value)
    {
        $record->data()->set($this->getName(), $value);
    }
}

class Import_Field_Data_Multiselect extends Import_Field
{
    protected function _setValueForRecord($record, $value)
    {
        $record->data()->set($this->getName(), preg_split('/[:,]/', $value));
    }
}

class Import_Field_UserPass extends Import_Field
{
    const KEY_FIXED = 'FIXED';
    const KEY_GENERATE = 'GENERATE';
    protected $isMustBeAssigned = false;

    protected function _buildForm(HTML_QuickForm2_Container $form)
    {
        $fieldGroup = $form->addElement('group', 'field_' . $this->getName())
                ->setLabel($this->getTitle());

        $fieldGroup->addElement('select', 'type')
            ->loadOptions(
                array(
                    self::KEY_GENERATE => 'Generate',
                    self::KEY_FIXED => 'Fixed'
                )
        );
        $fieldGroup->addElement('text', 'fixed', array('class' => 'fixed'));

        if ($this->isRequired()) {
            $fieldGroup->addRule('required', ___('This field is a requried field'));
        }
    }

    protected function _setValueForRecord($record, $value)
    {
        $record->setPass($value);
    }

    public function getValue($lineParsed, $partialRecord=null)
    {
        if ($this->isAssigned()) {
            return parent::getValue($lineParsed, $partialRecord);
        } elseif (self::KEY_FIXED == $this->session->fieldsValue['field_' . $this->getName()]['type']) {
            return $this->session->fieldsValue['field_' . $this->getName()]['fixed'];
        } else {
            return $this->getDi()->security->randomString(8);
        }
    }

    public function setValueForRecord($record, $lineParsed)
    {
        //user already exists in database
        //so we do not generate new password for him
        //but admin still can assign new password while import
        if (!$this->isAssigned() && @$record->pass)
            return;
        parent::setValueForRecord($record, $lineParsed);
    }
}

class Import_Field_UserLogin extends Import_Field
{
    protected $isMustBeAssigned = false;

    protected function _buildForm(HTML_QuickForm2_Container $form)
    {
        $form->addElement('static', 'field_' . $this->getName())
            ->setLabel($this->getTitle())
            ->setContent(sprintf("<div>%s</div>", ___('Generated')));
    }

    public function getValue($lineParsed, $partialRecord = null)
    {
        /* @var $partialRecord User */
        if ($this->isAssigned()) {
            return parent::getValue($lineParsed, $partialRecord);
        } else {
            if ($partialRecord) {
                $partialRecord->generateLogin();
                return $partialRecord->login;
            } else {
                return $this->getDi()->security->randomString(8);
            }
        }
    }

    public function setValueForRecord($record, $lineParsed)
    {
        //user already exists in database and found by email address
        //so we do not want to overwrite his login with autogenerated value but we
        //still use new value for login if it is fetched from file
        if (!$this->isAssigned() && @$record->login)
            return;
        parent::setValueForRecord($record, $lineParsed);
    }
}

class Import_DataSource
{
    const MAX_LINE_LENGTH = 4096;
    const DELIM_SEMICOLON = 1;
    const DELIM_COMMA = 2;
    const DELIM_SPACE = 3;
    const DELIM_TABULATION = 4;
    const DELIM_VALUE = 1;
    const DELIM_CODE = 2;

    protected $filePointerIterator = null;
    protected $filePointer = null;
    protected $colNum = null;
    protected $delimCode = null;
    protected $firstLineRaw = null;
    protected $firstLineParsed = null;

    public function __construct($path)
    {
        $this->filePointer = fopen($path, 'r');
        $this->filePointerIterator = fopen($path, 'r');
    }

    public function __destruct()
    {
        fclose($this->filePointer);
        fclose($this->filePointerIterator);
    }

    public function getOffset()
    {
        return ftell($this->filePointerIterator);
    }

    public function setOffset($offset = 0)
    {
        fseek($this->filePointerIterator, $offset);
    }

    public function rewind()
    {
        $this->setOffset(0);
    }

    public function getDelim($mode = self::DELIM_VALUE)
    {
        if (is_null($this->delimCode)) {
            $this->delimCode = $this->guessDelim();
        }

        switch ($mode) {
            case self::DELIM_VALUE :
                return self::getDelimByCode($this->delimCode);
            case self::DELIM_CODE :
                return $this->delimCode;
            default :
                throw new Am_Exception_InputError(
                    ___('Unknown mode [%s] in %s->%s', $mode, __CLASS__, __METHOD__));
        }
    }

    public function setDelim($delimCode)
    {
        $this->delimCode = $delimCode;

        //remove cached values that depends on delimiter
        $this->colNum = null;
        $this->firstLineParsed = null;
    }

    public function getNextLineParsed($pointer=null, $normalize = true)
    {
        $pointer = $pointer ? $pointer : $this->filePointerIterator;

        $res = $this->_getNextLineParsed($pointer);
        if ($res === false || !is_array($res))
            return false;
        if (is_null($res[0]))
            return $this->getNextLineParsed($pointer, $normalize);

        return $normalize ? $this->normalizeLineParsed($res) : $res;
    }

    protected function _getNextLineParsed($pointer)
    {
        if (feof($pointer)) {
            return false;
        } else {
            return fgetcsv($pointer, self::MAX_LINE_LENGTH, $this->getDelim());
        }
    }

    public function getFirstLineParsed($normalize = true)
    {
        if (!$this->firstLineParsed) {
            fseek($this->filePointer, 0);
            $this->firstLineParsed = $this->getNextLineParsed($this->filePointer, $normalize);
        }
        return $this->firstLineParsed;
    }

    public function getFirstLinesParsed($num, $normalize = true)
    {
        $result = array();

        fseek($this->filePointer, 0);
        for ($i = 0; $i < $num; $i++) {
            $res = $this->getNextLineParsed($this->filePointer, $normalize);
            if (!$res) {
                break;
            }
            $result[$i] = $res;
        }

        return $result;
    }

    public function getColNum()
    {
        if (!$this->colNum) {
            $this->colNum = count((array) $this->getFirstLineParsed(false));
        }
        return $this->colNum;
    }

    public static function getDelimOptions()
    {
        return array(
            self::DELIM_SEMICOLON => ___('Semicolon'),
            self::DELIM_COMMA => ___('Comma'),
            self::DELIM_SPACE => ___('Space'),
            self::DELIM_TABULATION => ___('Tabulation')
        );
    }

    public function getEstimateTotalLines($proccessed)
    {
        $perLine = round($this->getOffset() / $proccessed);
        $total = round($this->getFileSize() / $perLine);
        return $total;
    }

    protected function getFirstLineRaw()
    {
        if (!$this->firstLineRaw) {
            fseek($this->filePointer, 0);
            $this->firstLineRaw = trim(fgets($this->filePointer));
        }
        return $this->firstLineRaw;
    }

    private function getFileSize()
    {
        $stat = fstat($this->filePointer);
        return $stat['size'];
    }

    protected function normalizeLineParsed($lineParsed)
    {
        $result = (array) $lineParsed;

        if (count($lineParsed) > $this->getColNum()) {
            $result = array_slice($result, 0, $this->getColNum());
        } elseif (count($lineParsed) < $this->getColNum()) {
            $result = array_pad($result, $this->getColNum(), '');
        }

        return $result;
    }

    protected static function getDelimMap()
    {
        return array(
            self::DELIM_SEMICOLON => ';',
            self::DELIM_COMMA => ',',
            self::DELIM_SPACE => ' ',
            self::DELIM_TABULATION => "\t"
        );
    }

    protected static function getDelimByCode($delimCode)
    {
        $map = self::getDelimMap();

        if (!isset($map[$delimCode])) {
            throw new Am_Exception_InputError('Unknown delim code [' . $delimCode . ']');
        }

        return $map[$delimCode];
    }

    protected function guessDelim()
    {
        foreach (self::getDelimMap() as $delimCode => $delim) {
            if (count(explode($delim, $this->getFirstLineRaw())) >= 3) {
                return $delimCode;
            }
        }
        return self::DELIM_SEMICOLON;
    }
}

class Import_Log
{
    const TYPE_SKIP = 1;
    const TYPE_ERROR = 2;
    const TYPE_SUCCESS = 3;
    const TYPE_PROCCESSED = 4;

    const MAX_ERRORS_LOG = 15;
    const MAX_SKIP_LOG = 15;

    /** @var Am_Session_Ns */
    protected $session;
    protected static $instance = null;

    protected function __construct()
    {
        $this->session = Am_Di::getInstance()->session->ns('amember_import_log');
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function touchStat($type)
    {
        if (!isset($this->session->stat) ||
            !is_array($this->session->stat)) {

            $this->session->stat = array(
                self::TYPE_SKIP => 0,
                self::TYPE_ERROR => 0,
                self::TYPE_SUCCESS => 0,
                self::TYPE_PROCCESSED => 0
            );
        }
        $this->session->stat[$type]++;
    }

    public function getStat($type = null)
    {
        if (is_null($type)) {
            return $this->session->stat;
        }

        if (isset($this->session->stat[$type])) {
            return $this->session->stat[$type];
        } else {
            return 0;
        }
    }

    public function logError($message, $lineParsed)
    {
        if (!isset($this->session->errors)) {
            $this->session->errors = array();
        }
        if (count($this->session->errors) >= self::MAX_ERRORS_LOG) {
            return;
        }

        $error = array();
        $error['msg'] = $message;
        $error['lineParsed'] = $lineParsed;
        $this->session->errors[] = $error;
    }

    public function logSkip($lineParsed)
    {
        if (!isset($this->session->skip)) {
            $this->session->skip = array();
        }
        if (count($this->session->skip) >= self::MAX_SKIP_LOG) {
            return;
        }
        $this->session->skip[] = $lineParsed;
    }

    public function clearLog()
    {
        $this->session->errors = array();
        $this->session->skip = array();
        $this->session->stat = null;
    }

    public function getErrors()
    {
        if (!isset($this->session->errors)) {
            $this->session->errors = array();
        }
        return $this->session->errors;
    }

    public function getSkip()
    {
        if (!isset($this->session->skip)) {
            $this->session->skip = array();
        }
        return $this->session->skip;
    }
}

class Am_Grid_Action_ImportDel extends Am_Grid_Action_Abstract
{
    protected $title = 'Delete';
    protected $id = "delete";

    public function __construct()
    {
        parent::__construct();
        $this->setTarget('_top');
    }

    public function run()
    {
        //nop
    }

    public function getUrl($record = null, $id = null)
    {
        return $this->grid->getDi()->url('admin-import/delete',array('id' => $record->id), false);
    }

    public function isAvailable($record)
    {
        return $record->can_be_canceled;
    }
}