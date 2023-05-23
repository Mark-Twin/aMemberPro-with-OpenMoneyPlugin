<?php

abstract class Am_Grid_Action_Group_Abstract extends Am_Grid_Action_Abstract
{
    protected $type = self::GROUP;
    
    const ALL = '[ALL]';
    
    protected $needConfirmation = true;
    protected $confirmationText;
    
    protected $batchCount = 20; // how many records to select and process between checkLimits() 
    
    public function __construct($id = null, $title = null)
    {
        $this->confirmationText = ___("Do you really want to %s %s %s records");
        parent::__construct($id, $title);
    }
    /**
     * @return array int 
     */
    public function getIds()
    {
        $ids = $this->grid->getRequest()->get(Am_Grid_Editable::GROUP_ID_KEY);
        $ids = explode(",", $ids);
        if (in_array(self::ALL, $ids)) return array(self::ALL);
        $ids = array_filter(array_map('intval', $ids));
        return $ids;
    }
    
    public function getConfirmationText()
    {
        return ___($this->confirmationText, 
                $this->getTitle(),
                $this->getTextCount(),
                $this->grid->getRecordTitle()
            ) . '?';
    }
    public function getDoneText(){
        return ___("DONE").". ";
    }
    public function getTextCount()
    {
        $ids = $this->getIds();
        if (in_array(self::ALL, $ids)) return $this->grid->getDataSource()->getFoundRows();
        return count($ids);
    }
    public function run()
    {
        // we do not accept GET requests by security reasons. so nobody can say give a link that deletes all users
        if ($this->needConfirmation && (!$this->grid->getRequest()->isPost() || !$this->grid->getRequest()->get('confirm')))
        {
            echo $this->renderConfirmation();
        } else {
            echo $this->renderRun($this->getIds());
        }
    }
    public function renderRun($ids)
    {
        echo ___("Running %s", $this->getTitle()) . '...';
        $this->doRun($ids);
    }
    public function renderDone()
    {
        return $this->getDoneText() .
            sprintf('<a href="%s">%s</a>', 
                $this->grid->escape($this->grid->getBackUrl()), 
                ___("Return"));
        
    }
    public function doRun(array $ids)
    {
        if ($ids[0] == self::ALL)
            $this->handleAll();
        else {
            $ds = $this->grid->getDataSource();
            foreach ($ids as $id) {
                $record = $ds->getRecord($id);
                if (!$record) trigger_error ("Cannot load record [$id]", E_USER_WARNING);
                $this->handleRecord($id, $record);
            }
            echo $this->renderDone();
        }
        $this->log();
    }
    public function _handleAll(& $context, Am_BatchProcessor $batch)
    {
        $ds = $this->grid->getDataSource();
        list($item, $processed) = explode('-', $context);
        $done = 0;
        $totalBefore = $ds->getFoundRows();
        $page = ceil($item/$this->batchCount);
        $currentItem = $page * $this->batchCount;
        foreach ($ds->selectPageRecords($page, $this->batchCount) as $record)
        {
            if ($currentItem >= $item) {
                $id = $ds->getIdForRecord($record);
                $this->handleRecord($id, $record);
                $done++;
            }
            $currentItem++;
        }
        $ds->selectPageRecords(0, 1); //to clear fornRows cache
        $totalAfter = $ds->getFoundRows();
        $item = $currentItem - $totalBefore + $totalAfter;
        $processed += $done;
        $context = implode('-', array($item, $processed));
        if ($done == 0) return true; // no more records
    }
    public function handleAll()
    {
        $batch = new Am_BatchProcessor(array($this, '_handleAll'));
        $context = $this->grid->getRequest()->getParam('group_context', '0-0');
        if ($batch->run($context))
        {
            echo $this->renderDone();
        } else {
            list(,$processed) = explode('-', $context);
            echo $processed . " " . ___('records processed.');
            echo $this->getAutoClickScript(3, 'input#group-action-continue');
            echo $this->renderContinueForm(___('Continue'), $context);
        }
    }
    abstract public function handleRecord($id, $record);
    public function log($message = null, $tablename = null, $record_id = null)
    {
        if ($record_id === null)
        {
            $ids = $this->getIds();
            if (empty($ids)) return;
            if ($ids[0] == self::ALL) 
                $record_id = 'several records';
            else
                $record_id = implode(',', $ids);
        }
        parent::log($message, $tablename, $record_id);
    }
}