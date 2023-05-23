<?php

class Am_Grid_Action_Insert extends Am_Grid_Action_Abstract
{
    protected $privilege = 'insert';
    protected $title;
    protected $type = self::NORECORD; // this action does not operate on existing records
    protected $showFormAfterSave = false;
    protected $urlParams = array();

    public function __construct($id = null, $title = null)
    {
        $this->title = ___('New %s');
        parent::__construct($id, $title);
        $this->setTarget('_top');
    }

    public function showFormAfterSave($flag)
    {
        $this->showFormAfterSave = (bool)$flag;
        return $this;
    }

    public function run()
    {
        if ($this->_runFormAction(Am_Grid_Editable::ACTION_INSERT))
        {
            $this->log();
            if ($this->grid->hasPermission(null, 'edit') && $this->showFormAfterSave)
            {
                $record_id = $this->grid->getDataSource()->getIdForRecord($this->grid->getRecord());
                if ($record_id)
                    return $this->grid->redirect($this->grid->makeUrl(array(
                        Am_Grid_Editable::ACTION_KEY => 'edit',
                        Am_Grid_Editable::ID_KEY => $record_id,
                    ),false));
            }
            return $this->grid->redirectBack();
        }
    }

    /**
     * Add additional value to be appended into URL string.
     * This allows to define default values for insert form
     * @param string $k
     * @param string $v
     */
    public function addUrlParam($k, $v)
    {
        $this->urlParams[$k] = $v;
        return $this;
    }

    public function getUrl($record = null, $id = null)
    {
        if ($this->urlParams)
            $append = '&' . http_build_query($this->urlParams, '', '&');
        else
            $append = '';
        return parent::getUrl($record, $id) . $append;
    }

    public function log($message = null, $tablename = null, $record_id = null)
    {
        if ($record_id === null)
            $record_id = $this->grid->getDataSource()->getIdForRecord($this->grid->getRecord());
        parent::log($message, $tablename, $record_id);
    }
}