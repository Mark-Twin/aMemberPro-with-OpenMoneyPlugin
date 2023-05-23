<?php

abstract class Am_Grid_Action_LiveAbstract extends Am_Grid_Action_Abstract
{
    protected $privilege = 'edit';
    protected $type = self::HIDDEN;
    protected $fieldName;
    protected $placeholder = null;
    protected $callback;
    protected $initCallback;
    protected $updateCallback;
    protected $decorator;

    public function setGrid(Am_Grid_Editable $grid)
    {
        parent::setGrid($grid);
        if ($this->hasPermissions()) {
            $grid->getField($this->fieldName)->addDecorator($this->decorator);
            if (!static::$jsIsAlreadyAdded) {
                $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_STATIC, array($this, 'renderStatic'));
                static::$jsIsAlreadyAdded = true;
            }
        }
    }

    public function setCallback($callback)
    {
        $this->callback = $callback;
        return $this;
    }

    public function setInitCallback($callback)
    {
        $this->initCallback = $callback;
        return $this;
    }

    public function setUpdateCallback($callback)
    {
        $this->updateCallback = $callback;
        return $this;
    }

    public function getInitCallback()
    {
        return $this->initCallback;
    }

    function getPlaceholder()
    {
        return $this->placeholder;
    }

    function getDecorator()
    {
        return $this->decorator;
    }

    public function getIdForRecord($obj)
    {
        return $this->grid->getDataSource()->getIdForRecord($obj);
    }

    public function run()
    {
        try {
            $prefix = $this->fieldName . '-';
            $ds = $this->grid->getDataSource();
            foreach ($this->grid->getRequest()->getPost() as $k => $v)
            {
                if (strpos($k, $prefix)===false) continue;
                $id = filterId(substr($k, strlen($prefix)));
                $record = $ds->getRecord($id);
                if (!$record) throw new Am_Exception_InputError("Record [$id] not found");
                call_user_func($this->updateCallback ?: array($this, 'updateRecord'), $ds, $record, $this->fieldName, $v);
                $newValue = $v;
                $this->log('LiveEdit [' . $this->fieldName . ']');
            }

            $resp = array(
                'ok' => true,
                'message' => ___("Field Updated"),
                'newValue' => $newValue
            );
            if ($this->callback)
                $resp['callback'] = $this->callback;
        } catch (Exception $e) {
            $resp = array(
                'ok'=>false,
                'error' => true,
                'message'=>$e->getMessage()
            );
        }

        Am_Mvc_Response::ajaxResponse($resp);
    }

    protected function updateRecord($ds, $record, $fieldname, $v)
    {
        $ds->updateRecord($record, array($fieldname => $v));
    }
}