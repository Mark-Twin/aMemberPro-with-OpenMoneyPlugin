<?php

/**
 * A query object that can be displayed as search form,
 * then submitted and queried to database
 * @package Am_Query
 */
abstract class Am_Query_Renderable extends Am_Query
{
    public $possibleConditions = array();
    protected $search = array();
    /** @var Am_Form */
    protected $form;
    /** @var string path to smarty template */
    protected $template = null;
    /** @var string name of saved template or null */
    protected $name;
    protected $saved_search_id;
    
    /** @var string vars prefix */
    protected $prefix = '_u_search';
    
    /**
     * This function must be overriden and fill-in
     * @see $this->possibleConditions array
     */
    abstract function initPossibleConditions();
    
    public function setPrefix($prefix)
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * Set $conditions based on user input. This function must be called
     * only once, because it does not do any cleanup
     * @param Am_Mvc_Request|array $vars
     * @see $this->search
     */
    function setFromRequest($vars){
        if ($vars instanceof Am_Mvc_Request)
            $vars = $vars->toArray();
        $this->initPossibleConditions();
        $this->createForm((array)$vars);
        $vars = $this->form->getValue();
        $search = @$vars[$this->prefix];
        if(!is_array($search)) return; // Nothing to set;
        foreach ($this->possibleConditions as $cond){
            if ($cond->setFromRequest($search))
            {
                $this->conditions[] = $cond;
                $el = $this->form->getElementById($cond->getId());
                if ($el) $el->setAttribute('class', 'searchField'); // remote "empty" class
            }
        }
    }
    function createForm(array $vars){
        $form = new Am_Form;
        $form->setDataSources(array(new Am_Mvc_Request($vars)));
        $select = $form->addSelect('', array('size'=>1, 'id'=>'search-add-field', 'class'=>'el-wide'));
        $search = $form->addGroup($this->prefix);
        
        $search->options[''] = '** '.___('Select a condition to add into search').' **';
        foreach ($this->possibleConditions as $cond) {
            $cond->renderElement($search);
        }
        $select->loadOptions($search->options);
        $this->form = $form;
    }
    function renderForm($addHidden){
        $this->initPossibleConditions();
        $t = new Am_View;
        $renderer = HTML_QuickForm2_Renderer::factory('array');
        if (!$this->form)
            $this->createForm(array());
        $this->form->render($renderer);
        $t->assign('form', $renderer->toArray());
        $t->assign('description', $this->getDescription(true));
        $t->assign('serialized', $this->serialize());
        $t->assign('hidden', $addHidden);
        $t->assign('loadSearchOptions', Am_Html::renderOptions($this->getLoadOptions(), $this->saved_search_id));
        return $t->render($this->template);
    }
    static function removeEmptyElements(array &$array){
        foreach ($array as $k => $v)
            if (is_array($v)) {
                self::removeEmptyElements($array[$k]);
                if (!count($array[$k]))
                    unset($array[$k]);
            } elseif ($v == "")
                unset($array[$k]);
    }
    function serialize(){
        if (!$this->form)
            $this->createForm(array());
        $arr = $this->form->getValue();
        if(array_key_exists($this->prefix, $arr))
            $arr = $arr[$this->prefix];
        else
            $arr = array();
        self::removeEmptyElements($arr);
        unset($arr['_save_']);
        return json_encode($arr);
    }
    function unserialize($s){
        $vars = array($this->prefix =>  (array)json_decode($s, true));
        $this->setFromRequest($vars);
    }
    function getDescription($short=false){
        $d = array();
        foreach ($this->conditions as $cond) 
            $d[] = $cond->getDescription();
        $conds = preg_replace('/\s{2,}/', ' ', implode(', ', $d));
        if ($short) return $conds;
        return $d ? ___('Records that match all these conditions').': '.$conds : ___('All records');
    }
    function setName($name){
        $this->name = $name;
        return $this;
    }
    function getName(){
        return $this->name;
    }
    function save(){
        $vars = array(
            'name' => Am_Html::escape($this->name),
            'class' => get_class($this),
            'search' => $this->serialize(),
        );
        Am_Di::getInstance()->db->query("INSERT INTO ?_saved_search SET ?a", $vars);
        return Am_Di::getInstance()->db->selectCell("SELECT LAST_INSERT_ID()");
    }
    function getLoadOptions(){
        $options = Am_Di::getInstance()->db->selectCol("SELECT saved_search_id as ARRAY_KEY,name
            FROM ?_saved_search WHERE class=?", get_class($this));
        return array_map(array('Am_Html', 'escape'), $options);
    }
    function load($id){
        $id = intval($id);
        $class = get_class($this);
        $r = Am_Di::getInstance()->db->selectRow("SELECT * FROM ?_saved_search
            WHERE saved_search_id=?d and class=?", $id, $class);
        if ($r) {
            $this->clearConditions();
            $this->setName($r['name']);
            $this->saved_search_id = $r['saved_search_id'];
            $this->unserialize($r['search']);
            if (!$this->getConditions())
                throw new Am_Exception_InternalError("Could not load search [$id,$class] - empty conditions");
        } else
            throw new Am_Exception_InputError("Could not load query, not found [" . htmlentitites($id) . ",$class]");
        return $this;
    }
    function deleteSaved($id)
    {
        $id = intval($id);
        $class = get_class($this);
        Am_Di::getInstance()->db->query("DELETE FROM ?_saved_search
            WHERE saved_search_id=?d and class=?", $id, $class);
        return $this;
    }
}

interface Am_Query_Renderable_Condition {
    /**
     * If this condition is exists in user-submitted
     * request, assign it to current object
     * and return true
     * @param array of parameters in form : fieldname-op, fieldname-val
     *
     */
    function setFromRequest(array $input);
    /**
     * Add corresponding elements to the form
     * @param HTML_QuickForm2 $form
     * @return HTML_QuickForm2_Element|array of elements
     */
    function renderElement(HTML_QuickForm2_Container $form);
    /**
     * @return bool true if field was not enabled during loadFromInput
     */
    function isEmpty();
    /** @return string id of the fieldset */
    function getId();
    /** @return textual description of the filter */
    function getDescription();
}

class Am_Query_Renderable_Condition_Field extends Am_Query_Condition_Field
    implements Am_Query_Renderable_Condition
{
    const NUM = 1;
    const BOOL = 2;
    const STRING = 3;
    const DATE = 4;
    const IS_NULL = 5;

    protected $title;
    protected $fieldType;
    protected $isNull;

    protected $fieldGroupTitle = "FIELDS";

    static $renderOperations = array(
        self::NUM => array('=' => '=', '<>'=>'<>', '>'=>'>', '<'=>'<', '>='=>'>=', '<='=>'<=', ),
        self::DATE => array('=' => '=', '<>'=>'<>', '>'=>'>', '<'=>'<', '>='=>'>=', '<='=>'<=', ),
        self::STRING => array('='=>'=', '<>'=>'<>', 'LIKE'=>'LIKE', 'NOT LIKE'=>'NOT LIKE', 'IN' => 'IN'),
        self::BOOL => array(),
        self::IS_NULL => array('IS NULL' => 'IS NULL', 'IS NOT NULL' => 'IS NOT NULL'),
    );
    /** @var array User fields that must be displayed as select instead of op=>value inputs */
    public function __construct($field, $title, $mysqlFieldType, $isNull = false) {
        $this->field = $field;
        $this->isNull = $isNull;
        $this->title = $title;
        @list($type,$len) = explode('(', $mysqlFieldType);
        $len = intval($len);
        if (in_array($type, array('tinyint'))){
            $this->fieldType = self::BOOL;
        } elseif (in_array($type,array('date', 'datetime','timestamp'))){
            $this->fieldType = self::DATE;
        } elseif (in_array($type, array('smallint', 'int','float','double','numeric'))){
            $this->fieldType = self::NUM;
        } else {
            $this->fieldType = self::STRING;
        }
    }
    public function setFromRequest(array $input) {
        $id = $this->getId();
        if (array_key_exists($id, $input) && array_filter($input[$id], 'strlen'))
        {
            if (empty($input[$id]['op'])) $input[$id]['op'] = '=';
            if (in_array($input[$id]['op'], array('LIKE', 'NOT LIKE')) && (strpos($input[$id]['val'], '%') === false)) {
                $input[$id]['val'] = '%' . $input[$id]['val'] . '%';
            }
            if ($this->fieldType == self::DATE &&
                $input[$id]['op'] == '=') {
                //we need it so fields datetime and timestamp works correctly for =
                //since we do not allow to chose time in condition
                $input[$id]['op'] = 'LIKE';
                $input[$id]['val'] = @$input[$id]['val'] . '%';
            }
            $this->init(@$input[$id]['op'], @$input[$id]['val']);
            return true;
        } else {
            $this->empty = true;
            $this->op = null;
        }
    }
    /**
     * @return HTML_QuickForm2_Container
     */
    public function addGroup(HTML_QuickForm2_Container $form){
       $form->options[$this->fieldGroupTitle][$this->getId()] = $this->title;
       return $form->addGroup($this->getId())
           ->setLabel($this->title)
           ->setAttribute('id', $this->getId())
           ->setAttribute('class', 'searchField empty');
    }
    public function getId(){ return 'field-'.$this->field; }
    public function renderElement(HTML_QuickForm2_Container $form) {
        $group = $this->addGroup($form);
        $operations = self::$renderOperations[$this->fieldType];
        if ($this->isNull)
            $operations += self::$renderOperations[self::IS_NULL];
        if ($operations)
            $group->addSelect('op')->loadOptions($operations);
        switch ($this->fieldType) {
            case self::BOOL:
                $group->addAdvCheckbox('val');
                break;
            case self::NUM:
                $group->addText('val', array('size'=>6));
                break;
            case self::DATE:
                $group->addDate('val', array('size'=>12));
                break;
            default:
                $group->addText('val', array('size'=>20));
        }
    }
    public function isEmpty() {
        return $this->op === null;
    }
    public function getDescription(){
        return $this->title . ' ' . $this->op . (isset(self::$renderOperations[self::IS_NULL][$this->op]) ? '' : ' [' . htmlentities($this->value).']');
    }
}
