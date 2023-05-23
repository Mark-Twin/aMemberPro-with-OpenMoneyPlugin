<?php

class Bootstrap_Api extends Am_Module
{
    public $gridMethods = array(
        'index',
        'get',
        'put',
        'delete',
        'post',
    );

    protected $controllers = array();

    public function init()
    {
        $this->getDi()->router->addRoute('api', new Am_Api_RestRouter($this));
    }

    public function onAdminMenu(Am_Event $event)
    {
        $event->getMenu()->addPage(array(
            'id'    => 'api',
            'controller' => 'admin',
            'module' => 'api',
            'label' => ___('Remote API Permissions'),
            'resource' => Am_Auth_Admin::PERM_SUPER_USER
        ));
    }

    public function addController($alias, $controller, array $methods, $comment, $module = 'default')
    {
        $this->controllers[$alias] = array(
            'alias' => $alias,
            'controller' => $controller,
            'methods' => $methods,
            'comment' => $comment,
            'module'  => $module,
        );
    }

    public function getControllers()
    {
        if (empty($this->controllers))
            $this->registerControllers ();
        return $this->controllers;
    }

    public function findController($alias)
    {
        if (empty($this->controllers))
            $this->registerControllers ();
        if (!empty($this->controllers[$alias]))
            return $this->controllers[$alias];
    }

    function onSetupForms(Am_Event_SetupForms $event)
    {
        $form = new Am_Form_Setup($this->getId());
        $form->setTitle('REST API');
        $event->addForm($form);
        $url = $this->getDi()->url('admin-logs');
        $form->addAdvCheckbox('api_debug_mode')->setLabel(___('Enable Debug Mode') . "\n" .
            ___('all requests will be added to %sLogs%s,
useful if something is going wrong', '<a href="'.$url.'">', '</a>'));
    }

    /**
     * Throws exception if no permissions added
     * @param Am_Mvc_Request $request
     * @param array $record
     */
    public function checkPermissions(Am_Mvc_Request $request, $alias, $method)
    {
        if($this->getDi()->config->get('api_debug_mode'))
            $this->logDebug(var_export($request->getParams(),true));
        
        $event = $this->getDi()->hook->call(Am_Event::API_CHECK_PERMISSIONS, array(
            'request' => $request,
            'alias'   => $alias,
            'method'  => $method,
        ));
        foreach ($event->getReturn() as $return)
        {
            if ($return === true) return ; // skip checks if allowed by hook
        }

        $s = $request->getFiltered('_key');
        if (empty($s) || strlen($s) < 10)
            throw new Am_Exception_InputError("API Error 10001 - no [key] specified or key is too short");
        $apikey = $this->getDi()->apiKeyTable->findFirstByKey($s);
        if (!$apikey || $apikey->is_disabled)
            throw new Am_Exception_InputError("API Error 10002 - [key] is not found or disabled");
        if (!empty($apikey->ip) && !in_array($_SERVER['REMOTE_ADDR'], array_map('trim', explode("\n", $apikey->ip)))) {
            throw new Am_Exception_InputError("API Error 10004 - access from this server is not allowed");
        }
        $perms = $apikey->getPerms();
        if (empty($perms[$alias][$method]) || !$perms[$alias][$method])
            throw new Am_Exception_InputError("API Error 10003 - no permissions for $alias-$method API call");
    }

    protected function addDefaultControllers()
    {
        $this->addController('users', 'users', $this->gridMethods, 'Users', 'api');
        $this->addController('user-consent', 'user-consent', ['index', 'get', 'post'], 'User Consent', 'api');
        $this->addController('access-log', 'access-log', $this->gridMethods, 'Access Log', 'api');
        $this->addController('products', 'products', $this->gridMethods, 'Products', 'api');
        $this->addController('product-category', 'product-category', $this->gridMethods, 'Product Categories', 'api');
        $this->addController('product-product-category', 'product-product-category', $this->gridMethods, 'Relations Between Products and Categories', 'api');
        $this->addController('billing-plans', 'billing-plans', $this->gridMethods, 'Product Billing Plans', 'api');
        $this->addController('invoices', 'invoices', $this->gridMethods, 'Invoices', 'api');
        $this->addController('invoice-items', 'invoice-items', $this->gridMethods, 'Invoice Items', 'api');
        $this->addController('invoice-payments', 'invoice-payments', $this->gridMethods, 'Invoice Payments', 'api');
        $this->addController('invoice-refunds', 'invoice-refunds', $this->gridMethods, 'Invoice Refunds', 'api');
        $this->addController('access', 'access', $this->gridMethods, 'Access', 'api');
        $this->addController('check-access', 'check-access', array('by-login-pass', 'by-login', 'by-email', 'by-login-pass-ip', 'send-pass'),
            'Check User Access', 'api');
    }

    protected function registerControllers()
    {
        $this->addDefaultControllers();
        $this->getDi()->hook->call(Am_Event::GET_API_CONTROLLERS, array(
            'list' => $this,
        ));
    }
}

class Am_Api_RestRouter extends Am_Mvc_Router_Route_Abstract
{
    protected $_module;

    public function __construct(Bootstrap_Api $module)
    {
        $this->_module = $module;
    }

    public function assemble($data = array(), $reset = false, $encode = false)
    {
    }

    public function match($path)
    {
        /* @var $request Am_Mvc_Request */
        $request = $path;

        $path   = $request->getPathInfo();
        $params = $request->getParams();
        $values = array();
        $path   = trim($path, '/');

        $result = false;

        $vars = explode('/', $path);

        $module = array_shift($vars);
        if ($module !== 'api') return false; // that is not about me
        if (empty($vars[0]) || ($vars[0] === 'admin')) return false; // that is my regular admin page

        $controller = array_shift($vars);

        $record = $this->_module->findController($controller);
        if (!$record)
            throw new Am_Exception_Security("No API Action set: looks like controller [$controller] is not configured in API module");

        // detect REST request method
        $method = null;

        if ($request->getParam('_method'))
            $method = strtoupper(preg_replace('/[^a-zA-Z0-9-]/', '', $request->getParam('_method')));
        else {
            $method = $request->getMethod();
            if ($request->isPut()) {
                $putParams = array();
                parse_str($request->getRawBody(), $putParams);
                $request->setParams($putParams);
            }
        }

        switch ($method)
        {
            case 'POST'   :
                $method = 'post';
                break;
            case 'DELETE' :
            case 'PUT':
                if (empty($vars[0]) || !filter_var($vars[0], FILTER_VALIDATE_INT)) // not int id passed
                    throw new Am_Exception_InputError("No id passed for ".$method." method");
                $request->setParam('_id', (int)array_shift($vars));
                break;
            case 'OPTIONS': $method = 'options'; break;
            case 'HEAD'   : $method = 'head'; break;
            case 'TRACE'  : $method = 'trace'; break;
            case 'GET'    :
            default       :
                if (!empty($vars[0]) && filter_var($vars[0], FILTER_VALIDATE_INT)) // if int id passed
                {
                    $request->setParam('_id', (int)array_shift($vars));
                    $method = 'get';
                } else {
                    if ($method == 'GET')
                        $method = null;
                    if (empty($method))
                        $method = array_shift($vars);
                    if (empty($method))
                        $method = 'index';
                }
        }
        $method = strtolower($method);

        $version = (int)$request->getParam('_version');
        if (!$version) $version = 1;

        $this->_module->checkPermissions($request, $record['alias'], $method);

        $request
            ->setModuleName($record['module'])
            ->setControllerName($record['controller'])
            ->setActionName($method);

        return $vars + array('_api_action' => $method, '_version' => $version);
    }
}