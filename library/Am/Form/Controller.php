<?php
/**
 * @package Am_Form
 */

/**
 * extends QF2 multi-page controller
 */
class Am_Form_Controller extends HTML_QuickForm2_Controller
{
    /** @var Am_Mvc_Controller */
    protected $parent;

    public function __construct($id = null, $wizard = true, $propagateId = false) {
        parent::__construct($id, $wizard, $propagateId);
        $this->addHandler('jump',    new Am_Form_Controller_Action_Jump);
        $this->addHandler('display', new Am_Form_Controller_Action_Display);
        $this->addHandler('process', new Am_Form_Controller_Action_Process);
    }
    public function setParentController(Am_Mvc_Controller $parent)
    {
        $this->parent = $parent;
    }
    /** @return Am_Mvc_Controller */
    public function getParentController()
    {
        return $this->parent;
    }
    /** @return Am_Form_Controller_SessionContainer */
    public function getSessionContainer() {
        if (empty($this->sessionContainer)) {
            $this->sessionContainer = new Am_Form_Controller_SessionContainer($this);
        }
        return $this->sessionContainer;
    }
    public function destroySessionContainer()
    {
        $this->getSessionContainer()->destroy();
    }
}

class Am_Form_Controller_SessionContainer extends HTML_QuickForm2_Controller_SessionContainer
{
    protected $name;
    protected $ns;

    public function __construct(HTML_QuickForm2_Controller $controller) {

        $this->name = 'am_form_container_' . $controller->getId();

        $this->ns = Am_Di::getInstance()->session->ns($this->name);
        
        if (empty($this->ns->data))
        {
            $this->ns->data = array(
                'datasources' => array(),
                'values'      => array(),
                'valid'       => array()
            );
        }
        
        $this->data = $this->ns->data;
    }

    function serialize()
    {
        return serialize($this->data);
    }
    function unserialize($data)
    {
        $this->ns->data = unserialize($data);
        $this->data = $this->ns->data;
    }
    function destroy()
    {
        unset($this->ns->data);
    }
    
    public function storeOpaque($name, $value)
    {
        parent::storeOpaque($name, $value);
        $this->updateSession();
    }
    
    public function storeValues($pageId, array $values)
    {
        parent::storeValues($pageId, $values);
        $this->updateSession();
    }
    
    public function storeValidationStatus($pageId, $status)
    {
        parent::storeValidationStatus($pageId, $status);
        $this->updateSession();
    }
    
    public function updateSession()
    {
        $this->ns->data = $this->data;
    }
}

class Am_Form_Controller_Action_Jump implements HTML_QuickForm2_Controller_Action
{
   /**
    * Splits (part of) the URI into path and query components
    *
    * @param    string  String of the form 'foo?bar'
    * @return   array   Array of the form array('foo', '?bar)
    */
    protected static function splitUri($uri)
    {
        if (false === ($qm = strpos($uri, '?'))) {
            return array($uri, '');
        } else {
            return array(substr($uri, 0, $qm), substr($uri, $qm));
        }
    }

   /**
    * Removes the '..' and '.' segments from the path component
    *
    * @param    string  Path component of the URL, possibly with '.' and '..' segments
    * @return   string  Path component of the URL with '.' and '..' segments removed
    */
    protected static function normalizePath($path)
    {
        $pathAry = explode('/', $path);
        $i       = 1;

        do {
            if ('.' == $pathAry[$i]) {
                if ($i < count($pathAry) - 1) {
                    array_splice($pathAry, $i, 1);
                } else {
                    $pathAry[$i] = '';
                    $i++;
                }

            } elseif ('..' == $pathAry[$i] && $i > 1 && '..' != $pathAry[$i - 1]) {
                if ($i < count($pathAry) -1) {
                    array_splice($pathAry, $i - 1, 2);
                    $i--;
                } else {
                    array_splice($pathAry, $i - 1, 2, '');
                }

            } else {
                $i++;
            }
        } while ($i < count($pathAry));

        return implode('/', $pathAry);
    }

   /**
    * Resolves relative URL using current page's URL as base
    *
    * The method follows procedure described in section 4 of RFC 1808 and
    * passes the examples provided in section 5 of said RFC. Values from
    * $_SERVER array are used for calculation of "current URL"
    *
    * @param    string  Relative URL, probably from form's action attribute
    * @return   string  Absolute URL
    */
    protected static function resolveRelativeURL($url)
    {
        $https  = !empty($_SERVER['HTTPS']) && ('off' != strtolower($_SERVER['HTTPS']));
        $scheme = ($https? 'https:': 'http:');
        if ('//' == substr($url, 0, 2)) {
            return $scheme . $url;

        } else {
            $host   = $scheme . '//' . $_SERVER['SERVER_NAME'] .
                      (($https && 443 == $_SERVER['SERVER_PORT'] ||
                        !$https && 80 == $_SERVER['SERVER_PORT'])? '': ':' . $_SERVER['SERVER_PORT']);
            if ('' == $url) {
                return $host . $_SERVER['REQUEST_URI'];

            } elseif ('/' == $url[0]) {
                return $host . $url;

            } else {
                list($basePath, $baseQuery) = self::splitUri($_SERVER['REQUEST_URI']);
                list($actPath, $actQuery)   = self::splitUri($url);
                if ('' == $actPath) {
                    return $host . $basePath . $actQuery;
                } else {
                    $path = substr($basePath, 0, strrpos($basePath, '/') + 1) . $actPath;
                    return $host . self::normalizePath($path) . $actQuery;
                }
            }
        }
    }

    public function perform(HTML_QuickForm2_Controller_Page $page, $name)
    {
        // we check whether *all* pages up to current are valid
        // if there is an invalid page we go to it, instead of the
        // requested one
        if ($page->getController()->isWizard()
            && !$page->getController()->isValid($page)
        ) {
            $page = $page->getController()->getFirstInvalidPage();
        }

        // generate the URL for the page 'display' event and redirect to it
        $action = $page->getForm()->getAttribute('action');
        // Bug #13087: RFC 2616 requires an absolute URI in Location header
        if (!preg_match('!^https?://!i', $action)) {
            $action = self::resolveRelativeURL($action);
        }

        if (!$page->getController()->propagateId()) {
            $controllerId = '';
        } else {
            $controllerId = '&' . HTML_QuickForm2_Controller::KEY_ID . '=' .
                            $page->getController()->getId();
        }

        Am_Mvc_Response::redirectLocation($action . (false === strpos($action, '?')? '?': '&') .
            $page->getButtonName('display') . '=true' . $controllerId);
        exit();
        
    }
}

class Am_Form_Controller_Action_Display implements HTML_QuickForm2_Controller_Action
{
    public function perform(HTML_QuickForm2_Controller_Page $page, $name) {
        $validate        = false;
        $datasources     = $page->getForm()->getDataSources();
        $container       = $page->getController()->getSessionContainer();
        list(, $oldName) = $page->getController()->getActionName();
        // Check the original action name, we need to do additional processing
        // if it was 'display'
        if ('display' == $oldName) {
            // In case of wizard-type controller we should not allow access to
            // a page unless all previous pages are valid (see also bug #2323)
            if ($page->getController()->isWizard()
                && !$page->getController()->isValid($page)
            ) {
                return $page->getController()->getFirstInvalidPage()->handle('jump');
            }
            // If we have values in container then we should inject the Session
            // DataSource, if page was invalid previously we should later call
            // validate() to get the errors
            if (count($container->getValues($page->getForm()->getId()))) {
                array_unshift($datasources, new HTML_QuickForm2_DataSource_Session(
                    $container->getValues($page->getForm()->getId())
                ));
                $validate = false === $container->getValidationStatus($page->getForm()->getId());
            }
        }

        // Add "defaults" datasources stored in session
        $page->getForm()->setDataSources(array_merge($datasources, $container->getDatasources()));
        $page->populateFormOnce();
        if ($validate) {
            $page->getForm()->validate();
        }
        $amController = $page->getController()->getParentController();
        $amController->display($page->getForm(), $page->getTitle());
    }
}

/** call Am_Mvc_Controller's function process() */
class Am_Form_Controller_Action_Process implements HTML_QuickForm2_Controller_Action
{
    public function perform(HTML_QuickForm2_Controller_Page $page, $name) {
        $page->getController()->getParentController()->process($page->getController()->getValue(), $name, $page);
    }
}