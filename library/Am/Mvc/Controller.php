<?php

/**
 * Page controller implements mvC logic
 * @package Am_Mvc_Controller
 */
class Am_Mvc_Controller extends Zend_Controller_Action
{
    const ACTION_KEY = 'action';

    protected $processed = false;
    /** @var Am_Mvc_Request */
    protected $_request;
    /** @var Am_View */
    public $view;

    public function __construct(Zend_Controller_Request_Abstract $request, Zend_Controller_Response_Abstract $response, array $invokeArgs = array())
    {
        if ($request === null)
            throw new Am_Exception_InternalError("Class " . get_class($this) . " constructed without \$request and \$response");
        $invokeArgs['noViewRenderer'] = true;
        $this->view = $invokeArgs['di']->view;
        parent::__construct($request, $response, $invokeArgs);
    }

    /** @return Am_Di */
    function getDi()
    {
        return $this->_invokeArgs['di'];
    }

    /**
     * Return variable from aMember config
     * @param string $key
     * @return mixed
     */
    function getConfig($key, $default = null)
    {
        return $this->_invokeArgs['di']->config->get($key, $default);
    }

    /** @return Am_View */
    function getView()
    {
        return $this->view;
    }

    public function _checkPermissions()
    {
        if (stripos($this->_request->getControllerName(), 'admin') === 0) {
            if ($this instanceof AdminAuthController)
                return;
            $admin = $this->getDi()->authAdmin->getUser();
            if (!$admin)
                throw new Am_Exception_InternalError("Visitor has got access to admin controller without admin authentication!");
            if (!$this->checkAdminPermissions($admin))
                throw new Am_Exception_AccessDenied("Admin [{$admin->login}] has no permissions to do selected operation in " . get_class($this));
        }
    }

    public function setActiveMenu($id)
    {
        $this->getView()->headScript()->appendScript('window.amActiveMenuID = "' . $id . '";');
    }

    /**
     *
     * @param Admin $admin
     */
    public function checkAdminPermissions(Admin $admin)
    {
        throw new Am_Exception_NotImplemented(__FUNCTION__ . " must be implemented in " . get_class($this));
    }

    /**
     * Call required action
     * @param $actionName
     */
    public function dispatch($action)
    {
        // Notify helpers of action preDispatch state
        $this->_helper->notifyPreDispatch();

        $this->_checkPermissions();

        try {
            $this->preDispatch();
        } catch (Am_Exception_Redirect $e) {
            $this->postDispatch();
            $this->_helper->notifyPostDispatch();
            return;
        }
        if (!$this->isProcessed()) {
            if ($this->getRequest()->isDispatched()) {
                if (null === $this->_classMethods) {
                    $this->_classMethods = get_class_methods($this);
                }

                // preDispatch() didn't change the action, so we can continue
                try {
                    if ($this->getInvokeArg('useCaseSensitiveActions') || in_array($action, $this->_classMethods)) {
                        if ($this->getInvokeArg('useCaseSensitiveActions')) {
                            trigger_error('Using case sensitive actions without word separators is deprecated; please do not rely on this "feature"');
                        }
                        $this->_runAction($action);
                    } else {
                        $this->__call($action, array());
                    }
                } catch (Am_Exception_Redirect $e) {
                    // all ok, we just called it for GOTO
                }
                $this->postDispatch();
            }
        }
        // whats actually important here is that this action controller is
        // shutting down, regardless of dispatching; notify the helpers of this
        // state
        $this->_helper->notifyPostDispatch();
    }

    /**
     * After running this function $this->_response must be filled-in
     * @param string $action
     */
    public function _runAction($action)
    {
        ob_start();
        $this->$action();
        $this->getResponse()->appendBody(ob_get_clean());
    }

    public function _setInvokeArgs(array $args = array())
    {
        return parent::_setInvokeArgs($args);
    }

    public function __call($methodName, $args)
    {
        // deprecated functions
        switch ($methodName)
        {
            case 'getJson':
                return json_encode($args[0]);
            case 'isAjax':
                return $this->_request->isXmlHttpRequest();
            case 'ajaxResponse':
                return call_user_func_array(array($this->_response, 'ajaxResponse'), $args);
        }
        require_once 'Zend/Controller/Action/Exception.php';
        if ('Action' == substr($methodName, -6)) {
            $action = substr($methodName, 0, strlen($methodName) - 6);
            throw new Zend_Controller_Action_Exception(sprintf('Action "%s" does not exist in %s and was not trapped in __call()', $action, get_class($this)), 404);
        }
        throw new Zend_Controller_Action_Exception(sprintf('Method "%s" does not exist and was not trapped in __call()', $methodName), 500);
    }

    /**
     * Run htmlentities() for the string
     * @param string string to escape
     * @return string escaped string
     * @deprecated do not call it in static context!
     */
    function escape($string)
    {
        return htmlentities($string, ENT_QUOTES, 'UTF-8', false);
    }

    /**
     * @deprecated Please do not call these functions !
     * @param type $name
     * @param type $arguments
     */
    public static function __callStatic($name, $arguments)
    {
        $replaceMap = array(
            'getJson' => 'json_encode',
            'escape' => array('Am_Html', 'escape'),
            'renderOptions' => array('Am_Html', 'renderOptions'),
            'renderArrayAsInputHiddens' => array('Am_Html', 'renderArrayAsInputHiddens'),
            'getArrayOfInputHiddens' => array('Am_Html', 'getArrayOfInputHiddens'),
            'setCookie' => array('Am_Cookie', 'set'),
            'redirectLocation' => array(Am_Di::getInstance()->response, 'redirectLocation'),
            'ajaxResponse' => array(Am_Di::getInstance()->response, 'ajaxResponse'),
            'getFullUrl' => array(Am_Di::getInstance()->request, 'getFullUrl'),
        );
        if ($name == 'decodeJson')
            return json_decode($arguments[0], true);
        elseif (!empty($replaceMap[$name]))
            return call_user_func_array($replaceMap[$name], $arguments);
        else
            throw new Exception("Static method [$name] does not exists in " . __CLASS__);
    }

    public function isProcessed()
    {
        return $this->processed;
    }

    /** call this to stop request processing */
    public function setProcessed($flag = true)
    {
        $this->processed = (bool) $flag;
    }

    public function isPost()
    {
        return $this->_request->isPost();
    }

    public function isGet()
    {
        return $this->_request->isGet();
    }

    /** @return mixed request parameter of if not exists in request, then $default value */
    function getParam($key, $default=null)
    {
        return $this->_request->getParam($key, $default);
    }

    /** @return int the same as get param but with intval(...) applied */
    function getInt($key, $default=0)
    {
        return $this->_request->getInt($key, $default);
    }

    /** @return string request parameter with removed chars except the a-zA-Z0-9-_ */
    function getFiltered($key, $default=null)
    {
        return $this->_request->getFiltered($key, $default);
    }

    /** @return string request parameter with htmlentities(..) applied */
    function getEscaped($key, $default=null)
    {
        return $this->_request->getEscaped($key, $default);
    }

    /**
     * Redirect customer to new url
     * @param $targetTop useful when doing a redirect in AJAX generated html
     */
    function redirectHtml($url, $text='', $title='Redirecting...', $targetTop=false, $proccessed = null, $total = null)
    {
        $this->view->assign('title', $title);
        $this->view->assign('text', $text);
        $this->view->assign('url', $url);
        if (!is_null($total)) {
            $width = (100 * $proccessed) / $total;
            $this->view->width = min(100, round($width));
            $this->view->showProgressBar = true;
            $this->view->total = $total;
            $this->view->proccessed = $proccessed;
        }
        if ($targetTop)
            $this->view->assign('target', '_top');
        if (ob_get_level())
            ob_end_clean();
        $this->getResponse()->setBody($this->view->render(defined('AM_ADMIN') ? 'admin/redirect.phtml' : 'redirect.phtml'));
        throw new Am_Exception_Redirect($url); // exit gracefully
    }

    function getUrl($controller = null, $action = null, $module = null, $params = null)
    {
        return call_user_func_array(array($this->getDi()->request, 'makeUrl'), func_get_args());
    }

    function url($path, $params = null, $encode = true, $absolute = false)
    {
        return call_user_func_array(array($this->getDi(), 'url'), func_get_args());
    }

    function rurl($path, $params = null, $encode = true)
    {
        return call_user_func_array(array($this->getDi(), 'rurl'), func_get_args());
    }

    function surl($path, $params = null, $encode = true)
    {
        return call_user_func_array(array($this->getDi(), 'surl'), func_get_args());
    }

    /**
     * @return Am_Session_Ns */
    public function getSession()
    {
        return $this->getDi()->session->ns('default');
    }

    /** @return Am_Module|null */
    public function getModule()
    {
        $module = $this->_request->getModuleName();
        if ($module == 'default')
            return null;
        return $this->getDi()->modules->get($module);
    }

    protected function _redirect($url, array $options = array())
    {
        if (!preg_match('#^(//|http)#', $url) || !empty($options['prependBase']))
            $url = $this->getDi()->url($url, false);
        $this->_helper->redirector->setExit(false);
        $options['prependBase'] = false;
        parent::_redirect($url, $options);
        throw new Am_Exception_Redirect($url);
    }
}

