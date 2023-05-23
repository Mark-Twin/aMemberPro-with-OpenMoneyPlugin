<?php

/**
 * Controller to serve remote API requests, based
 * on a Am_Table/Am_Query pair
 */
abstract class Am_Mvc_Controller_Api_Table extends Am_Mvc_Controller_Api
{
    /** describes nested records and relation
     * @example
     *     array('invoice' => array('controller' => 'invoices', 'key' => 'user_id',));
     */
    protected $_nested = array();
    /** array default value of incoming _nested param */
    protected $_defaultNested = array();
    /** @access private */
    protected $_nestedControllers = array();
    /** whatever is passed as 'nested' parameter in update/insert request */
    protected $_nestedInput = array();
    /** @var Am_Record current record in POST/PUT/DELETE action */
    protected $record;

    /** @return Am_Table */
    abstract function createTable();

    /** @return Am_Query */
    function createQuery()
    {
        return new Am_Query($this->createTable());
    }

    protected function getNestedController($nest)
    {
        if (!empty($this->_nestedControllers[$nest]))
            return $this->_nestedControllers[$nest];
        if (empty($this->_nested[$nest])) throw new Am_Exception_InputError("Nested relation [$nest] is not defined");
        $relation = $this->_nested[$nest];
        if (!empty($relation['file']))
            require_once AM_APPLICATION_PATH . '/' . $relation['file'];
        $class = $relation['class'];
        $controller = new $class($this->getRequest(), $this->getResponse(), $this->getInvokeArgs());
        $this->_nestedControllers[$nest] = $controller;

        $module = $this->getDi()->modules->get('api');

        $module->checkPermissions($this->getDi()->request, $nest,
            strtolower($this->_request->getMethod()));

        return $controller;
    }
    protected function getNestedKeyField($nest)
    {
        if (empty($this->_nested[$nest])) throw new Am_Exception_InputError("Nested relation [$nest] is not defined");
        $relation = $this->_nested[$nest];
        if (!empty($relation['key']))
            return $relation['key'];
        else
            return $this->createTable()->getKeyField();
    }

    /**
     * Prepare record for displaying
     * @return Am_Record
     */
    protected function prepareRecordForDisplay(Am_Record $rec)
    {
        // include nested records
        $nested = (array)$this->getRequest()->getParam('_nested');
        if (empty($nested)) $nested = $this->_defaultNested;

        $_nested = array();
        if (empty($rec->_nested_)) $rec->_nested_ = array();
        foreach ($nested as $nest)
        {
            if (empty($rec->_nested_[$nest]))
                $rec->_nested_[$nest] = array();
            $controller = $this->getNestedController($nest);
            $keyField = $this->getNestedKeyField($nest);

            /* @var $controller Am_Mvc_Controller_Api_Table */
            $controller->setRequest(new Am_Mvc_Request(array(
                '_filter' => array($keyField => $rec->pk()),
            )));
            $nestedRecords = $controller->selectRecords($t_, true);
            foreach ($nestedRecords as $nestedRecord)
            {
                $_nested[$nest][] = $nestedRecord;
            }
        }
        $rec->_nested_ = $_nested;
        return $rec;
    }
    protected function apiOutRecords(array $records, array $addInfo = array())
    {
        $ret = $addInfo;
        foreach ($records as $r)
        {
            $ret[] = $this->prepareRecordForDisplay($r);
        }
        $this->dumpResponse($ret);
    }
    protected function recordToXml(Am_Record $rec, XmlWriter $x)
    {
        $rec->exportXml($x, array());
    }
    protected function recordToArray(Am_Record $rec)
    {
        $ret = $rec->toArray();
        if (!empty($rec->_nested_))
        {
            foreach ($rec->_nested_ as $table => $nestedRecords)
            {
                foreach ($nestedRecords as $nestedRecord)
                {
                    $ret['nested'][$table][] = $nestedRecord->toArray();
                }
            }
        }
        return $ret;
    }
    protected function dumpResponse(array $ret)
    {
        $format = $this->getRequest()->getParam('_format');
        switch ($format)
        {
            case 'xml':
                $x = new XMLWriter();
                $x->openMemory();
                $x->setIndent(2);
                $x->startDocument('1.0', 'utf-8');
                $x->startElement('rows');
                foreach ($ret as $k => $rec)
                {
                    if (!$rec instanceof Am_Record)
                        $x->writeElement($k, (string)$rec);
                    else {
                        $x->startElement('row');
                        $rec->exportXml($x, array('element' => null));
                        if (!empty($rec->_nested_))
                        {
                            $x->startElement('nested');
                            foreach ($rec->_nested_ as $table => $nestedRecords)
                            {
                                $x->startElement($table);
                                foreach ($nestedRecords as $nestedRecord)
                                    $nestedRecord->exportXml($x);
                                $x->endElement(); // $table
                            }
                            $x->endElement(); //nested
                        }
                        $x->endElement(); //row
                    }
                }
                $x->endElement();
                $x->endDocument();
                $out = $x->flush();
                $this->getResponse()->setHeader('Content-type', 'application/xml; charset=UTF-8', true);
                break;
            case 'serialize':
            case 'json':
            default:
                foreach ($ret as $k => $rec)
                {
                    if ($rec instanceof Am_Record)
                        $ret[$k] = $this->recordToArray($rec);
                }
                if ($format == 'serialize')
                {
                    $this->getResponse()->setHeader('Content-type', 'text/plain; charset=UTF-8', true);
                    $out = serialize($ret);
                } else {
                    $this->getResponse()->setHeader('Content-type', 'application/json; charset=UTF-8', true);
                    $out = json_encode($ret);
                }
        }
        $this->getResponse()->setBody($out);
    }

    public function selectRecords(& $total = 0, $skipCountLimit = false)
    {
        $page = $this->_request->get('_page', 0);
        $count = min(1000, $this->_request->get('_count', 20));

        $ds = $this->createQuery();

        $filter = (array)$this->getRequest()->getParam('_filter');
        foreach ($filter as $k => $v)
        {
            if (strpos($v, '%')!==false)
                $ds->addWhere('?# LIKE ?', $k, $v);
            else
                $ds->addWhere('?#=?', $k, $v);
        }
        if ($skipCountLimit) {
            $ret = $ds->selectAllRecords();
        } else {
            $ret = $ds->selectPageRecords($page, $count);
        }
        $total = $ds->getFoundRows();
        return $ret;
    }

    /** api to return list of records */
    public function indexAction()
    {
        $total = 0;
        $records = $this->selectRecords($total);

        $this->apiOutRecords($records, array('_total' => $total));
    }
    /** api to return a single record */
    public function getAction()
    {
        $t = $this->createTable();
        $records = array($t->load($this->getRequest()->getInt('_id')));
        $this->apiOutRecords($records);
    }
    /** api to create new record */
    public function postAction()
    {
        $t = $this->createTable();
        /* @var $ds Am_Grid_DataSource_Interface_Editable */
        $this->record = $t->createRecord();
        $vars = $this->getRequest()->getParams();
        if (!empty($vars['nested']))
        {
            $this->_nestedInput = $vars['nested'];
            unset($vars['nested']);
        }
        $this->setForInsert($this->record, $vars);
        $this->record->insert();
        $this->insertNested($this->record, $vars);
        $this->apiOutRecords(array($this->record));
    }
    /** api to update existing record */
    public function putAction()
    {
        $t = $this->createTable();
        $this->record = $t->load($this->getRequest()->getInt('_id'));
        $vars = $this->getRequest()->getParams();
        if (!empty($vars['nested']))
        {
            $this->_nestedInput = $vars['nested'];
            unset($vars['nested']);
        }
        $this->setForUpdate($this->record, $vars);
        $this->record->update();
        $this->updateNested($this->record, $vars);
        $this->apiOutRecords(array($this->record));
    }
    /** api to delete existing record */
    public function deleteAction()
    {
        $t = $this->createTable();
        $this->record = $t->load($this->getRequest()->getInt('_id'));
        $this->beforeDelete($this->record);
        $this->record->delete();
        $this->apiOutRecords(array($this->record), array('_success' => true));
    }

    public function setForInsert(Am_Record $record, array $vars)
    {
        $record->setForInsert($vars);
        $this->setInsertNested($record, $vars);
    }
    public function setForUpdate(Am_Record $record, array $vars)
    {
        $record->setForUpdate($vars);
        $this->setUpdateNested($record, $vars);
    }
    /**
     * insert records from $this->_nestedInput
     * after $record->insert() call
     */
    public function insertNested(Am_Record $record, array $vars)
    {
        foreach ($this->_nestedInput as $nest => $records)
        {
            $controller = $this->getNestedController($nest);
            foreach ($records as $rec)
            {
                $rec[$this->getNestedKeyField($nest)] = $record->pk();
                $request = new Am_Mvc_Request($rec, 'POST');
                $controller->setRequest($request);
                $controller->postAction();
            }
        }
    }
    /**
     * update records from $this->_nestedInput
     * after $record->update() call
     */
    public function updateNested(Am_Record $record, array $vars)
    {
        foreach ($this->_nestedInput as $nest => $records)
        {
            $controller = $this->getNestedController($nest);
            foreach ($records as $rec)
            {
                throw new Am_Exception_InputError("PUT for nested records is not implemented");
            }
        }
    }
    /**
     * set variables in $record from $this->_nestedInput
     * before $record->insert() call
     */
    public function setInsertNested(Am_Record $record, array $vars)
    {

    }
    /**
     * set variables in $record from $this->_nestedInput
     * before $record->update() call
     */
    public function setUpdateNested(Am_Record $record, array $vars)
    {

    }
    public function beforeDelete(Am_Record $record) {}
}
