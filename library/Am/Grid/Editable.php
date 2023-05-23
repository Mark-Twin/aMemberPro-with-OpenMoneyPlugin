<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: LGPL http://www.gnu.org/copyleft/lesser.html
*/

/**
 * Editable grid class
 * @package Am_Grid
 * @method getDataSource() Am_Grid_DataSource_Interface_ReadOnly
 */
class Am_Grid_Editable extends Am_Grid_ReadOnly
{
    const ACTION_INSERT = 'insert';
    const ACTION_EDIT = 'edit';

    const CB_BEFORE_INSERT = 'beforeInsert';
    const CB_AFTER_INSERT = 'afterInsert';
    const CB_BEFORE_UPDATE = 'beforeUpdate';
    const CB_AFTER_UPDATE = 'afterUpdate';
    const CB_BEFORE_SAVE = 'beforeSave';
    const CB_AFTER_SAVE = 'afterSave';
    const CB_BEFORE_DELETE = 'beforeDelete';
    const CB_AFTER_DELETE = 'afterDelete';
    const CB_VALUES_TO_FORM = 'valuesToForm';
    const CB_VALUES_FROM_FORM = 'valuesFromForm';
    const CB_INIT_FORM = 'initForm';

    const GET = 'get';
    const SET = 'set';
    const RECORD = 'RECORD';

    /** @var string to be automatically instantiated in @link createForm */
    protected $formClass = null;
    /** @var Am_Form */
    protected $form;

    /** @var Am_Record - to be strong that is stdclass but usually it is Am_Record anyway */
    protected $record;

    /** @var Am_Grid_DataSource_Interface_Editable */
    protected $dataSource;

    /** @var array Am_Grid_Action_Abstract */
    protected $actions = array();

    /** @var array field transformation for record<-->form */
    protected $formValueCallbacks = array(
        self::GET => array(),
        self::SET => array(),
    );

    protected $recordTitle;

    public function renderTitle($noTags = false)
    {
        if ($this->getRequest()->getActionName() == 'index')
            return parent::renderTitle($noTags);
        $action = $this->actionGet($this->getCurrentAction());
        if (!$action) return parent::renderTitle($noTags);
        $title = $this->getRecordTitle($action->getTitle());
        if (!$noTags)
            $title .= sprintf(' (<a href="%s">%s</a>)',
                $this->escape($this->getBackUrl()),
                ___("return")
            );
        return $title;
    }

    public function __construct($id, $title, Am_Grid_DataSource_Interface_Editable $ds,
        Am_Mvc_Request $request, Am_View $view, Am_Di $di = null)
    {
        parent::__construct($id, $title, $ds, $request, $view, $di);
        $this->recordTitle = ___("Record");
        $this->initActions();
    }

    public function initActions()
    {
        // override to add default actions
        $this->actionAdd(new Am_Grid_Action_Edit);
        $this->actionAdd(new Am_Grid_Action_Delete);
        $this->actionAdd(new Am_Grid_Action_Insert);
        //$this->actionAdd(new Am_Grid_Action_Group_Delete);
    }

    /**
     * Add action to be executed on the grid
     */
    public function actionAdd(Am_Grid_Action_Abstract $action)
    {
        $this->actions[ $action->getId() ] = $action;
        $action->setGrid($this);
        return $action;
    }

    /** @return Am_Grid_Action_Abstract */
    public function actionGet($id)
    {
        if ($id == 'index') return null;
        return isset($this->actions[$id]) ? $this->actions[$id] : null;
    }

    public function getActions($type = null)
    {
        if ($type !== null)
        {
            $ret = array();
            foreach ($this->actions as $action)
                if ($action->getType() == $type)
                    $ret[] = $action;
        } else {
            $ret = $this->actions;
        }
        foreach ($ret as $k => $action)
            if (!$action->hasPermissions())
                unset($ret[$k]);
        return $ret;
    }

    public function actionsClear()
    {
        $this->actions = array();
        return $this;
    }

    public function actionDelete($id)
    {
        foreach ($this->actions as $k => $action)
            if ($action->getId() == $id)
                unset($this->actions[$k]);
        return $this;
    }

    /**
     * Add action fields if necessary
     * @staticvar int $fieldActionsAdded
     * @return type
     */
    public function getFields()
    {
        static $fieldActionsAdded = 0;
        if (!$fieldActionsAdded++)
        {
            if ($this->getActions(Am_Grid_Action_Abstract::SINGLE))
                $this->addField(new Am_Grid_Field_Actions('_actions'));
            if ($this->getActions(Am_Grid_Action_Abstract::GROUP))
                $this->prependField(new Am_Grid_Field_Checkboxes('_checkboxes', 'Checkboxes'));
            $fieldActionsAdded = 1;
        }
        return $this->fields;
    }

    /**
     * @return Am_Form
     */
    public function getForm()
    {
        if ($this->form === null)
        {
            $this->form = $this->createForm();
            $args = array($this->form);
            $this->runCallback(self::CB_INIT_FORM, $args);
            $this->initForm();
        }
        return $this->form;
    }

    /**
     * Prepare form to be used in the grid
     */
    public function initForm()
    {
        $this->form->setDataSources(array(
            $this->getCompleteRequest(),
        ));
        $vars = array();
        foreach ($this->getVariablesList() as $k) {
            $vars[$this->getId() .'_'. $k] = $this->request->get($k, "");
        }
        foreach (Am_Html::getArrayOfInputHiddens($vars) as $name => $value) {
            $this->form->addHidden($name)->setValue($value);
        }
        $this->form->addSaveButton(___("Save"));
    }

    /**
     * Set className of forms, or clean form object
     * @param string|Am_Form $formClass
     * @return Am_Grid_Editable
     */
    public function setForm($formClass)
    {
        $this->formClass = $formClass;
        return $this;
    }

    /**
     * Set a function to be executed to get and set value
     * record->form, form->record
     */
    public function setFormValueCallback($field, $getCallback, $setCallback, $getArgs=array(), $setArgs=array())
    {
        if ($getCallback !== null)
            $this->formValueCallbacks[self::GET][$field] = array($getCallback, $getArgs);
        else
            unset($this->formValueCallbacks[self::GET][$field]);
        ///
        if ($setCallback !== null)
            $this->formValueCallbacks[self::SET][$field] = array($setCallback, $setArgs);
        else
            unset($this->formValueCallbacks[self::SET][$field]);
        return $this;
    }

    /**
     * You must either set @link $formClass or override this method in subclass
     * @return Am_Form
     */
    public function createForm()
    {
        if (!$this->formClass)
            throw new Am_Exception_InternalError("No [formClass] set in " . __METHOD__);
        if ($this->formClass instanceof Am_Form) {
            $ret = $this->formClass;
        } elseif (is_string($this->formClass)) {
            $class = $this->formClass;
            $ret = new $class;
        } elseif (is_callable($this->formClass)) {
            $ret = call_user_func($this->formClass, $this);
        }
        return $ret;
    }

    public function actionRun($actionName)
    {
        if ($actionName == 'index')
            return $this->indexAction();
        if (!array_key_exists($actionName, $this->actions))
            throw new Am_Exception_InputError("Wrong action called : [$actionName] in " .get_class($this));
        $action = $this->actions[$actionName];
        try {
            $action->checkPermissions();
            $action->run($this);
        } catch (Am_Exception_InputError $e) {
            echo $this->renderException($e, $actionName);
        }
    }

    protected function renderException($e, $actionName)
    {
        $out  = '<div class="error">Error happened during [$customAction] operation: ';
        $out .= $this->escape($e->getMessage());
        $out .= '</div>' . PHP_EOL;
        $out .= sprintf('<a href="%s">%s</a>'.PHP_EOL,
            $this->escape($this->getBackUrl()),
            ___("Continue"));
        return $out;
    }

    /**
     * @param string|callback $recordTitle
     */
    public function setRecordTitle($recordTitle)
    {
        $this->recordTitle = $recordTitle;
    }

    public function getRecordTitle($actionName = null)
    {
        if ($actionName !== null)
            return sprintf($actionName, $this->_getRecordTitle());
        else
            return $this->_getRecordTitle();
    }

    protected function _getRecordTitle()
    {
        return is_callable($this->recordTitle)
            ? call_user_func($this->recordTitle, $this->getRecordId(false) ? $this->getRecord() : null)
            : $this->recordTitle;
    }

    public function renderTable()
    {
        return $this->renderButtons() .
            parent::renderTable() .
            $this->renderGroupActions();
    }

    /**
     * Return url for action
     * @param Am_Grid_Action_Abstract|string $action
     * @param int $id
     * @return type
     */
    public function getActionUrl($action, $id = null)
    {
        if (is_string($action))
            $action = $this->actionGet($action);
        $params = array(
            Am_Grid_ReadOnly::ACTION_KEY   => $action->getId(),
            Am_Grid_ReadOnly::BACK_KEY     => $this->makeUrl(),
        );
        if ($id && ($action->getType() == Am_Grid_Action_Abstract::SINGLE))
            $params[Am_Grid_ReadOnly::ID_KEY] = $id;
        $url = $this->makeUrl($params);
        return $url;
    }

    public function renderButtons()
    {
        $actions = $this->getActions(Am_Grid_Action_Abstract::NORECORD);
        if (!$actions) return;
        $out = '<div class="norecord-actions">' . PHP_EOL;
        foreach ($actions as $action)
        {
            $out .= sprintf('<a class="%s" id="%s" href="%s" %s>%s</a>' . PHP_EOL,
                $action->getCssClass(),
                $this->getCssClass() . '-' . $action->getId() . '-button',
                $this->escape($action->getUrl()),
                !is_null($action->getTarget()) ? sprintf('target="%s" ', $action->getTarget()) : '',
                $this->escape($this->getRecordTitle($action->getTitle()))
                );
        }
        $out .= "</div>" . PHP_EOL;
        return $out;
    }

    public function renderGroupActions()
    {
        $actions = $this->getActions(Am_Grid_Action_Abstract::GROUP);
        if (!$actions) return;
        $out = '<div class="group-wrap">' . PHP_EOL;
        $out .= '<select name="'.$this->getId().'-group-action">' . PHP_EOL;
        $out .= '<option value="">*** '.$this->escape(___("Bulk Actions")).' ***</option>' . PHP_EOL;
        foreach ($actions as $action)
        {
            $attribs = $action->getAttributes();
            $target = @$attribs['target'];
            $out .= sprintf('<option id="%s" value="%s" data-url="%s" data-target="%s">%s</option>' . PHP_EOL,
                $this->getCssClass() . '-' . $action->getId() . '-button',
                $this->escape($action->getId()) ,
                $this->escape($action->getUrl()),
                $this->escape($target),
                $this->escape($action->getTitle())
                );
        }
        $out .= "</select>" . PHP_EOL;
        $out .= '</div>' . PHP_EOL;
        return $out;
    }

    public function getVariablesList()
    {
        return array_merge(parent::getVariablesList(), array(self::ID_KEY, self::BACK_KEY));
    }

    public function getBackUrl()
    {
        $url = $this->getRequest()->get('b');
        if (!$url) $url = $this->makeUrl(null);
        return $url;
    }

    public function redirect($url)
    {
        $this->response->headersSentThrowsException = false;
        $this->response->setRedirect($url);
    }

    /**
     * return back to grid using headers or special redirect text based on request contents
     */
    public function redirectBack()
    {
        $this->redirect($this->getBackUrl());
    }

    public function _transformFormValues($direction, & $arr)
    {
        foreach ($this->formValueCallbacks[$direction] as $field => $cbarr)
        {
            list($cb, $args) = $cbarr;
            array_unshift($args, !isset($arr[$field]) ? null : $arr[$field]);
            if (is_array($cb) && ($cb[0]===self::RECORD))
                $cb[0]=$this->getRecord();
            $arr[$field] = call_user_func_array($cb, $args);
        }
    }

    public function valuesToForm()
    {
        $record = $this->getRecord();
        $ret = method_exists($record, 'toArray') ? $record->toArray() : (array)$record;
        $this->_transformFormValues(self::GET, $ret);
        $args = array(& $ret, $record);
        $this->runCallback(self::CB_VALUES_TO_FORM, $args);
        return $ret;
    }

    public function valuesFromForm()
    {
        $values = $this->getForm()->getValue();
        foreach ($this->getVariablesList() as $k)
        {
            unset($values[$this->getId() . '_' . $k]);
        }
        unset($values['save']);
        unset($values['_save_']);
        $this->_transformFormValues(self::SET, $values);
        $args = array(& $values, $this->getRecord());
        $this->runCallback(self::CB_VALUES_FROM_FORM, $args);
        return $values;
    }

    /** @return Am_Record */
    function getRecord($action = null)
    {
        if ($action === null)
            $action = preg_match('|^insert|', $this->request->getActionName()) ? self::ACTION_INSERT : self::ACTION_EDIT;
        if ($this->record === null)
            if ($action == self::ACTION_INSERT)
            {
                $this->record = $this->getDataSource()->createRecord();
            } else {
                $this->record = $this->getDataSource()->getRecord($this->getRecordId());
            }
        return $this->record;
    }

    function getRecordId($throwException = true)
    {
        $id = $this->getRequest()->getFiltered(self::ID_KEY, null);
        if (!$id && $throwException) throw new Am_Exception_InputError("Wrong link - empty id in " . __METHOD__);
        return $id;
    }

    function validate()
    {
        return $this->getForm()->validate();
    }

    /**
     * Do all necessary actions to validate and save form value
     * @param type $action
     * @return bool true if saved
     */
    function doFormActions($action)
    {
        $form = $this->getForm();
        if ($form->isSubmitted() && $this->validate())
        {
            $record = $this->getRecord($action);
            $values = $this->valuesFromForm();
            $args = array(& $values, $record, $this);
            if ($action == self::ACTION_INSERT)
            {
                $this->runCallback(self::CB_BEFORE_INSERT, $args);
                $this->runCallback(self::CB_BEFORE_SAVE, $args);
                $this->getDataSource()->insertRecord($record, $values);
                $this->runCallback(self::CB_AFTER_INSERT, $args);
                $this->runCallback(self::CB_AFTER_SAVE, $args);
            } else {
                $this->runCallback(self::CB_BEFORE_UPDATE, $args);
                $this->runCallback(self::CB_BEFORE_SAVE, $args);
                $this->getDataSource()->updateRecord($this->getRecord($action), $values);
                $this->runCallback(self::CB_AFTER_UPDATE, $args);
                $this->runCallback(self::CB_AFTER_SAVE, $args);
            }
            return true;
        } elseif (!$form->isSubmitted()) {
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array($this->valuesToForm(),
                    $this->getCompleteRequest())
            ));
        }
        return false;
    }
}