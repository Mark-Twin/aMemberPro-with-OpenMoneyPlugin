<?php

/**
 * Represents a page in multi-tabs controller
 * @see Am_Mvc_Controller_Pages
 * @package Am_Mvc_Controller 
 */
class Am_Mvc_Controller_Pages_Page 
{
    protected $id;
    protected $title;
    protected $callback;
    function __construct($id, $title, $callback)
    {
        $this->id = $id;
        $this->title = $title;
        $this->callback = $callback;
    }
    public function getId()
    {
        return $this->id;
    }
    public function getTitle()
    {
        return $this->title;
    }
    public function getPerformer(Am_Mvc_Controller_Pages $controller)
    {
        $callback = $this->callback;
        if (is_string($callback))
            $page = new $callback($controller->getRequest(), $controller->view);
        else
            $page = call_user_func($callback, $this->id, $this->title, $controller);
        if (!is_object($page))
            throw new Am_Exception_InternalError("Could not ".__METHOD__."({$this->id}) - not an object");
        return $page;
    }
}



/**
 * This class represents a controller with several tabs
 * every page is a separate and rendered by a widget
 * In most common case it is an Am_Grid
 * 
 * @see Am_Mvc_Controller_Pages_Page
 * @package Am_Mvc_Controller 
 */
abstract class Am_Mvc_Controller_Pages extends Am_Mvc_Controller
{
    protected $pages = array();
    protected $pageId = null;
    protected $defaultPageId = null;
    protected $layout = 'admin/layout.phtml';

    public function init()
    {
        $this->initPages();
        $this->getDi()->hook->call(Am_Event::INIT_CONTROLLER_PAGES, array('controller' => $this));
        parent::init();
    }
    
    public function __call($methodName, $args)
    {
        if (preg_match('/^([a-zA-Z0-9_-]+)Action$/', $methodName, $regs))
        {
            $this->pageId = $this->getPageId();
            if (!$this->getPage($this->pageId))
            {
                throw new Am_Exception_InternalError("Could not find page[$id]");
            }
            return $this->renderPage($this->getActivePage());
        }
        //if ($this->)
        return parent::__call($methodName, $args);
    }
    
    abstract function initPages();
    
    public function addPage($callbackOrPage, $id=null, $title=null)
    {
        if (is_object($callbackOrPage))
            $page = $callbackOrPage;
        else
            $page = new Am_Mvc_Controller_Pages_Page($id, $title, $callbackOrPage);
        $this->pages[$page->getId()] = $page;
        if ($this->defaultPageId === null)
            $this->defaultPageId = $page->getId();
        return $this;
    }
    public function setDefault($id)
    {
        $this->defaultPageId = $id;
    }
    public function getPageId()
    {
        if (empty($this->pageId))
        {
            $this->pageId = filterId($this->_request->getParam('page_id', 'index'));
            if (!array_key_exists($this->pageId, $this->pages))
                $this->pageId = $this->defaultPageId;
            if (!$this->pageId)
                throw new Am_Exception_InternalError("Could not find page id for request : [" . $this->_request->getActionName() . "]");
        }
        return $this->pageId;
    }
    public function getPage($id)
    {
        $id = filterId($id);
        if (!array_key_exists($id, $this->pages))
            throw new Am_Exception_InternalError("Could not find page[$id]");
        return $this->pages[$id];
    }
    /** @return Am_Mvc_Controller_Pages_Page */
    public function getActivePage()
    {
        return $this->getPage($this->getPageId());
    }
    public function renderPage(Am_Mvc_Controller_Pages_Page $page)
    {
        $performer = $page->getPerformer($this);
        if ($performer instanceof Zend_Controller_Action)
            $performer->run ($this->_request, $this->_response);
        else
            $performer->run($this->getResponse());
        
        if ($this->getResponse()->isRedirect() || $this->_request->isXmlHttpRequest())
            return;

        $content =  $this->renderTabs($this->pageId) .  $this->getResponse()->getBody();
        
        $this->getResponse()->clearBody();
        $this->view->title = $this->getActivePage()->getTitle();
        $this->view->layoutNoTitle = true;
        $this->view->content = $content;
        $this->view->display($this->layout);
    }

    public function renderTabs()
    {
        $n = new Am_Navigation_Container;
        foreach ($this->pages as $page)
        {
            $p = new Am_Navigation_Page_Mvc(array(
                'id' => "tab-{$page->getId()}",
                'module' => $this->_request->getModuleName(),
                'action' => 'index',
                'controller' => $this->_request->getControllerName(),
                'label' => $page->getTitle(),
                'params' => array (
                    'page_id' => $page->getId()
                ),
                'route' => 'inside-pages'
            ));
            $p->setActive($this->getPageId() == $page->getId());
            $n->addPage($p);
        }
        $h = new Am_View_Helper_AdminTabs;
        $h->setView($this->view);
        return $h->adminTabs($n);
    }
}