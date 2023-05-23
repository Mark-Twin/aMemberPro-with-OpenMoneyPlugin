<?php

abstract class Am_Mvc_Controller_AdminCategory extends Am_Mvc_Controller
{
    abstract protected function getTable();
    abstract protected function getTitle();

    function indexAction()
    {
        $this->view->isAjax = $this->_request->isXmlHttpRequest();
        if (!$this->_request->isXmlHttpRequest()) {
            $this->view->title = $this->getTitle();
        }

        $this->view->add_label = $this->getAddLabel();
        $this->view->options = $this->getOptions();
        $this->view->note = $this->getNote();
        $this->view->nodes = $this->getTable()->getTree();
        $this->view->tmpl = $this->getTable()->createRecord();
        $this->view->display('admin/category.phtml');
    }

    function saveAction()
    {
        $id = $this->getInt('id');
        if ($id) {
            $c = $this->getTable()->load($id);
        } else {
            $c = $this->getTable()->createRecord();
        }
        $c->title = $this->getParam('title');
        $c->description = $this->getParam('description');
        if (!is_null($code = $this->getParam('code'))) {
            $c->code = $code;
        }
        $c->parent_id = $this->getInt('parent_id');
        $c->sort_order = $this->getInt('sort_order');
        $c->save();
        return $this->_response->ajaxResponse(array(
            'record' => $c->toArray() + array('id' => $c->pk()),
            'options' => $this->getOptions(true)
        ));
    }

    function delAction()
    {
        if (!$id = $this->getInt('id')) {
            throw new Am_Exception_InputError(___('Wrong id'));
        }
        $c = $this->getTable()->load($id);
        $this->getTable()->moveNodes($c->pk(), $c->parent_id);
        $c->delete();
        return $this->_response->ajaxResponse(array(
            'status' => 'OK',
            'options' => $this->getOptions(true)
        ));
    }

    function optionsAction()
    {
        return $this->_response->ajaxResponse($this->getOptions(true, false));
    }

    function getOptions($isJs = false, $addRoot = true)
    {
        $options = ($addRoot ? array(0 => ___('-- Root')) : array()) +
            $this->getTable()->getOptions();
        if ($isJs) {
            $_ = array();
            foreach ($options as $k => $v) {
               $_[] = array($k, $v);
            }
            $options = $_;
        }
        return $options;
    }

    protected function getNote()
    {
        return '';
    }

    protected function getAddLabel()
    {
        return ___('Add Root Node');
    }
}