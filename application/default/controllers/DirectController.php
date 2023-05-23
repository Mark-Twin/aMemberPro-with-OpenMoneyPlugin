<?php

class DirectController extends Am_Mvc_Controller
{
    public function __call($methodName, $args) {
        $pluginId = filterId($this->_request->getUserParam('plugin_id'));
        $this->_request->setActionName(filterId($this->_request->getActionName()));
        if (!$pluginId)
            throw new Am_Exception_InputError("Internal Error: wrong URL used - no plugin id");

        $type = $this->_request->getUserParam('type');
        if (!$this->getDi()->plugins->offsetGet($type))
            throw new Am_Exception_InternalError("Wrong [type] requested");

        $pluginMgr = $this->getDi()->plugins[$type];
        if (!$pluginMgr->isEnabled($pluginId))
            throw new Am_Exception_InputError("The [$pluginId] plugin is disabled");

        $ps = $pluginMgr->loadGet($pluginId);
        if (!$ps->isConfigured())
            throw new Am_Exception_Configuration("The plugin [$pluginId] is not configured, directAction failed");
        return $ps->directAction($this->_request, $this->_response, $this->_invokeArgs);
    }
}