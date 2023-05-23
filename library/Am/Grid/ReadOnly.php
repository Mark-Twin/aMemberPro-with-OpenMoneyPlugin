<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: LGPL http://www.gnu.org/copyleft/lesser.html
*/
/**
 * Base grid class (read-only)
 * @package Am_Grid
 */
class Am_Grid_ReadOnly
{
    const ACTION_KEY = 'a';
    const ID_KEY = 'id';
    const GROUP_ID_KEY = 'group_id';
    const BACK_KEY = 'b';

    const CB_RENDER_TABLE = 'onRenderTable';
    const CB_RENDER_PAGINATOR = 'onRenderPaginator';
    const CB_RENDER_TITLE = 'onRenderTitle';
    const CB_RENDER_CONTENT = 'onRenderContent';
    const CB_RENDER_STATIC = 'onRenderStatic';
    const CB_TR_ATTRIBS = 'onGetTrAttribs';
    const CB_BEFORE_RUN = 'onBeforeRun';
    const CB_NO_RECORDS = 'onNoRecords';
    const CB_MAKE_URL = 'onMakeUrl';
    const CB_INIT_GRID = 'initGrid';
    const CB_INIT_GRID_FINISHED = 'initGridFinished';

    /** @var Am_Mvc_Response */
    protected $response;
    protected $cssClass = 'grid-wrap';
    /** @var string */
    protected $id;
    /** @var string */
    protected $title;
    /** @var array Am_Grid_Field */
    protected $fields = array();
    /** @var Am_Grid_DataSource_Interface_ReadOnly */
    protected $dataSource;
    /** @var Am_Mvc_Request all request as it submitted */
    protected $completeRequest;
    /** @var Am_Mvc_Request only vars specific to this grid */
    protected $request;
    /** @var Am_View passed from controller may be null */
    private $view = null;
    /** @var Am_Grid_Filter_Interface */
    protected $filter;
    /** @var bool set this to not-null to override autodetection */
    protected $isAjax = null;
    /** @var int */
    protected $countPerPage;
    /** @var Am_Di */
    private $di;
    /** @var string by default 'grid'.$this->getId() */
    protected $permissionId = null;
    /** @var array callbackConst => array of callbacks */
    protected $callbacks = array();
    /** @var string eventId */
    protected $eventId = null;

    public function __construct($id, $title,
        Am_Grid_DataSource_Interface_ReadOnly $ds, Am_Mvc_Request $request, Am_View $view, Am_Di $di = null)
    {
        if ($id[0] != '_') throw new Am_Exception_InternalError("id must start with underscore _ in " . __METHOD__);
        $this->id = $id;
        $this->title = $title;
        $this->dataSource = $ds;
        $this->view = $view;
        $this->di = $di ?: Am_Di::getInstance();
        $this->countPerPage = $this->getDi()->config->get('admin.records-on-page', 10);
        $this->initGridFields();
        $this->setRequest($request);
        $this->init();
    }

    function init(){}

    public function getId() { return $this->id; }

    /** @return Am_Di */
    public function getDi() { return $this->di; }

    public function setRequest(Am_Mvc_Request $request)
    {
        $this->completeRequest = $request;
        $arr = array();
        foreach ($request->toArray() as $k => $v) {
            if (strpos($k, $this->id.'_')===0)
            {
                $k = substr($k, strlen($this->id)+1);
                if (!strlen($k)) continue;
                $arr[$k] = $v;
            }
        }
        $this->request = new Am_Mvc_Request($arr);
        $sort = $this->request->get('sort');
        if (!empty($sort))
        {
            $sort = explode(' ', $sort, 2);
            $this->getDataSource()->setOrder(filterId($sort[0]), !empty($sort[1]));
        }
    }

    /** must be overriden */
    protected function initGridFields() {}

    function getCountPerPage()
    {
        return $this->countPerPage;
    }

    function setCountPerPage($count)
    {
        $this->countPerPage = (int)$count;
    }

    function getCurrentPage()
    {
        return $this->request->getInt('p');
    }

    /**
     * @param Am_Grid_Field|string $field
     * @return Am_Grid_Field
     */
    function addField($field, $title=null, $sortable=null,
            $align=null, $renderFunc=null, $width=null)
    {
        if (func_num_args()>1 || !$field instanceof Am_Grid_Field)
            $field = $this->_createField(func_get_args());
        $this->fields[] = $field;
        $field->init($this);
        return $field;
    }

    /**
     * Find a field by name
     * @throws Am_Exception_InternalError
     * @param string $fieldName
     * @return Am_Grid_Field
     */
    function getField($fieldName, $throwExceptions = true)
    {
        foreach ($this->fields as $field)
            if ($field->getFieldName() == $fieldName) return $field;

        if ($throwExceptions)
            throw new Am_Exception_InternalError("Field [$fieldName] not found in " . __METHOD__);
    }

    /**
     * @deprecated use @link addField instead
     */
    function addGridField($field)
    {
        $args = func_get_args();
        return call_user_func_array(array($this, 'addField'), $args);
    }

    function removeField($fieldName)
    {
        foreach ($this->fields as $k => $field)
            if ($field->getFieldName() == $fieldName)
                unset($this->fields[$k]);
        return $this;
    }

    private function _createField(array $args)
    {
        $reflectionObj = new ReflectionClass('Am_Grid_Field');
        return $reflectionObj->newInstanceArgs($args);
    }

    function prependField($field, $title=null, $sortable=null,
            $align=null, $renderFunc=null, $width=null)
    {
        if (func_num_args()>1 || !$field instanceof Am_Grid_Field)
            $field = $this->_createField(func_get_args());
        array_unshift($this->fields, $field);
        $field->init($this);
        return $field;
    }

    function setFilter(Am_Grid_Filter_Interface $filter)
    {
        $this->filter = $filter;
        //$this->filter->initFilter($this);
    }

    function getFilter()
    {
        return $this->filter;
    }

    function getFields()
    {
        return $this->fields;
    }

    /** @return Am_Grid_DataSource_Interface_ReadOnly */
    function getDataSource()
    {
        return $this->dataSource;
    }

    function renderTitle($noTags = false)
    {
        if ($noTags) return $this->title;
        $total = $this->getDataSource()->getFoundRows();
        $page = $this->getCurrentPage();
        $count = $this->getCountPerPage();
        $ret  = "";
        $ret .= '<h1>';
        $ret .= $this->escape($this->title);
        $msgs = array();
        if ($total)
        {
            $msgs[] = ___("displaying records %s-%s from %s",
                number_format($page*$count+1),
                number_format(min($total, ($page+1)*$count)),
                number_format($total));
        } else {
            $msgs[] = ___("no records");
        }
        if ($this->filter && $this->filter->isFiltered())
        {
            $override = array();
            foreach ($this->filter->getVariablesList() as $k)
                $override[$k] = null;
            $u = $this->makeUrl($override);
            $u .= (strpos($u, '?')===false) ? '?' : '&';
            $u .= $this->getId();
            $msgs[] = sprintf('%s - <a class="filtered" href="%s">%s</a>',
                ___('filtered'),
                $this->escape($u),
                ___('reset'));
        }
        if ($msgs) $ret .= ' (' . implode(", ", $msgs) . ')';
        $ret .= "</h1>";
        // run callback
        $args = array(& $ret, $this);
        $this->runCallback(self::CB_RENDER_TITLE, $args);
        // done
        return $ret;
    }

    function getCssClass()
    {
        return $this->cssClass;
    }

    function getTrAttribs($record)
    {
        $ret = array();
        $args = array(& $ret, $record);
        $this->runCallback(self::CB_TR_ATTRIBS, $args);
        return $ret;
    }

    function getHiddenVars()
    {
        return array(
            'totalRecords' => $this->getDataSource()->getFoundRows(),
            'page' => $this->getRequest()->getInt('p'),
        );
    }

    /** @return Am_Mvc_Request - with filtered vars */
    function getRequest()
    {
        return $this->request;
    }

    /** @return Am_Mvc_Request - global */
    function getCompleteRequest()
    {
       return $this->completeRequest;
    }

    function renderTable()
    {
        $this->checkPermission(null, 'browse');
        if (empty($this->request))
            throw new Am_Exception_InternalError("request is empty in " . __METHOD__);

        $records = $this->getDataSource()->selectPageRecords($this->getCurrentPage(), $this->getCountPerPage());
        $out = "";
        $args = array(&$out, $this);
        if (!$records)
        {
            $this->runCallback(self::CB_NO_RECORDS, $args);
            if ($out != '') //something has been received from the callback
                return $out;
        }
        $out .= '<div class="grid-container">'.PHP_EOL;
        $out .= sprintf('<table class="grid" data-info="%s">'.PHP_EOL, $this->escape(json_encode($this->getHiddenVars())));
        $out .= "\t<thead><tr>\n";
        foreach ($this->getFields() as $field)
            $out .= "\t\t" . $field->renderTitle($this) . PHP_EOL;
        $out .= "\t</tr></thead><tbody>\n";
        foreach ($records as $record)
            $out .= $this->renderRow($record);
        $out .= "</tbody></table></div>\n\n";
        // run callback
        $args = array(& $out, $this);
        $this->runCallback(self::CB_RENDER_TABLE, $args);
        // done
        return $out;
    }

    function renderRow($record)
    {
        $out = "";

        $attribs = (array)$this->getTrAttribs($record);
        if (isset($attribs['class'])) {
            $attribs['class'] .= ' grid-row';
        } else {
            $attribs['class'] = 'grid-row';
        }
        static $odd = 0; // to get zebra colors
        if (empty($attribs['class'])) $attribs['class'] = "";
        if ($odd++ % 2) {
            $attribs['class'] = "odd " . $attribs['class'];
        } else {
            $attribs['class'] = "even " . $attribs['class'];
        }
        $astring = "";
        foreach ($attribs as $k => $v)
            $astring .= ' ' . htmlentities($k, null, 'UTF-8') . '="' . htmlentities($v, ENT_QUOTES, 'UTF-8').'"';

        $out .= "\t<tr".$astring.">\n";
        foreach ($this->getFields() as $field)
            $out .= "\t\t" . $field->render($record, $this) . "\n";
        $out .= "\t</tr>\n";
        return $out;
    }

    function renderPaginator()
    {
        $urlTemplate = null;
        $total = $this->getDataSource()->getFoundRows();
        $p = new Am_Paginator(ceil($total/$this->getCountPerPage()), $this->getCurrentPage(), $urlTemplate, $this->id . '_p', $this->getCompleteRequest());
        $out = $p->render();
        $args = array(&$out, $this);
        $this->runCallback(self::CB_RENDER_PAGINATOR, $args);
        return $out;
    }

    function renderFilter()
    {
        if ($this->filter)
            return $this->filter->renderFilter();
    }

    function renderContent()
    {
        // it is important to run it first to get query executed
        $table = $this->renderTable();
        $out =
            $this->renderFilter() .
            $table .
            $this->renderPaginator();

        // run callback
        $args = array(& $out, $this);
        $this->runCallback(self::CB_RENDER_CONTENT, $args);
        // done
        return $this->renderTitle() .
                $out;
    }

    /**
     * Render static html or js or css code that must not be reloaded
     * during AJAX requests
     */
    function renderStatic()
    {
        $out = "";
        foreach ($this->fields as $field)
            $out .= $field->renderStatic() . PHP_EOL;
        if ($this->filter)
            $out .= $this->filter->renderStatic() . PHP_EOL;
        // run callback
        $args = array(& $out, $this);
        $this->runCallback(self::CB_RENDER_STATIC, $args);
        return $out;
    }

    function render()
    {
        return sprintf(
            '<!-- start of grid -->' . PHP_EOL .
            '<div class="%s" id="%s">'. PHP_EOL .
            '%s' . PHP_EOL .
            "</div>" . PHP_EOL .
            '%s' . PHP_EOL .
            '<!-- end of grid -->'
            ,
            $this->getCssClass(),
            'grid-' . preg_replace('/^_/','', $this->getId()),
            $this->renderContent(),
            $this->renderStatic()
            );
    }

    /** @array string html */
    function renderGridHeaderSortHtml(Am_Grid_Field $field)
    {
        $desc = null;
        @list($sort, $desc) = explode(' ', $this->request->getParam('sort'), 2);
        if ($sort == $field->getFieldName())
            $desc = ($desc != "DESC");
        $url = $this->escape($this->makeUrl(array(
            'sort' => $field->getFieldName() . ($desc ? " DESC" : ""),
        )));

        $cssClass = "a-sort";
        if ($sort == $field->getFieldName())
        {
            $cssClass .= $desc ? ' sorted-desc' : ' sorted-asc';
        }
        $sort1 = sprintf("<a class='$cssClass' href='%s'>", $url);
        $sort2 = "</a>";
        return array($sort1, $sort2);
    }

    static function renderTd($content, $doEscape=true)
    {
        return sprintf('<td>%s</td>',
            $doEscape ? self::escape($content) : $content);
    }

    /**
     * if $override === null return url without ANY parameters
     */
    public function makeUrl($override = array(), $includeGlobal = true)
    {
        if ($includeGlobal)
        {
            $req = $this->completeRequest->toArray();
        } else
            $req = null;
        $uri = $this->completeRequest->getRequestUri();
        $uri = preg_replace('/\?.*/', '', $uri);
        if (defined('ROOT_URL') && defined('ROOT_SURL'))
            $uri = str_replace(array(ROOT_URL, ROOT_SURL), array(REL_ROOT_URL, REL_ROOT_URL), $uri);

        if ($override === null)
        {
            $req = array();
        } else {
            foreach ($override as $x => $y)
            {
                $x = $this->id . '_' . $x;
                if ($y === null)
                    unset($req[$x]);
                else
                    $req[$x] = $y;
            }
        }
        $args = array($uri, $req);
        $this->runCallback(self::CB_MAKE_URL, $args);
        list($uri, $req) = $args;
        return $req ? ($uri . '?' . http_build_query($req, '', '&')) : $uri;
    }

    public function run(Am_Mvc_Response $response = null)
    {
        $args = array($this);
        $this->runCallback(self::CB_INIT_GRID, $args);
        $this->runCallback(self::CB_INIT_GRID_FINISHED, $args);

        if ($this->filter) {
            $this->filter->initFilter($this);
        }

        $args = array($this);
        $this->runCallback(self::CB_BEFORE_RUN, $args);

        if ($response===null)
            $response = new Am_Mvc_Response;
        $this->response = $response;
        $action = $this->getCurrentAction();
        $this->request->setActionName($action);

        ob_start();
        $this->actionRun($action);

        if ($this->response->isRedirect() && $this->completeRequest->isXmlHttpRequest())
        {
            $url = null;
            foreach ($response->getHeaders() as $header)
                if ($header['name'] == 'Location') $url = $header['value'];
            $code = $response->getHttpResponseCode();
            // change request to ajax response
            $response->clearAllHeaders(); $response->clearBody();
            $response->setHttpResponseCode(200);
            $response->setHeader("Content-Type","application/json; charset=UTF-8", true);
            $response->setBody(json_encode(array('ngrid-redirect' => $url, 'status' => $code)));
            //throw new Am_Exception_Redirect($url);
        } else {
            $response->appendBody(ob_get_clean());
        }
        unset($this->response);
        return $response;
    }

    /** @return string */
    public function getCurrentAction()
    {
        return $this->request->getFiltered(self::ACTION_KEY, 'index');
    }

    public function actionRun($action)
    {
        $callback = array($this, $action.'Action');
        if (!is_callable($callback))
            throw new Am_Exception_InternalError("Action [$action] does not exists in " . get_class($this));
        call_user_func($callback);
    }

    public function runWithLayout($layout = 'admin/layout.phtml', Am_Mvc_Response $response = null)
    {
        if (!$response)
            $response = new Am_Mvc_Response;
        $this->run($response);
        if ($this->completeRequest->isXmlHttpRequest() || $response->isRedirect())
        {
            $response->sendResponse();
        } else {
            $view = $this->getView();
            $view->layoutNoTitle = true;
            $view->title = $this->renderTitle(true);
            $view->content = $response->getBody();
            $view->display($layout);
        }
    }

    public function indexAction()
    {
        echo $this->isAjax() ?
            $this->renderContent() : $this->render();
    }

    public function isAjax($setFlag = null)
    {
        if ($setFlag !== null)
            $this->isAjax = (bool)$setFlag;
        if ($this->isAjax !== null)
            return $this->isAjax;
        return $this->completeRequest->isXmlHttpRequest();
    }

    /**
     * @return array string of variable names to pass between requests
     */
    public function getVariablesList()
    {
        $ret = $this->filter ? $this->filter->getVariablesList() : array();
        $ret[] = self::ACTION_KEY;
        return $ret;
    }

    function addCallback($gridEvent, $callback)
    {
        $this->callbacks[$gridEvent][] = $callback;
    }

    function getCallbacks()
    {
        return $this->callbacks;
    }

    function runCallback($gridEvent, array & $args)
    {
        if (!empty($this->callbacks[$gridEvent])) {
            foreach ($this->callbacks[$gridEvent] as $callback)
            {
                call_user_func_array($callback, $args);
            }
        }
        // now run external callbacks
        $event = new Am_Event_Grid($gridEvent, $args, $this);
        $this->getDi()->hook->call('grid' . ucfirst($gridEvent), $event);
        $this->getDi()->hook->call($this->eventId . ucfirst($gridEvent), $event);
        $args = $event->getArgs();
    }

    /**
     * If set, the grid will raise Am_Event_Grid for all callbacks (see constants)
     * @param string $eventId
     */
    function setEventId($eventId)
    {
        $this->eventId = (string)$eventId;
    }

    static function escape($s)
    {
        return htmlentities($s, ENT_QUOTES, 'UTF-8');
    }

    function getView()
    {
        return $this->view ? $this->view : $this->getDi()->view;
    }

    function hasPermission($perm = null, $priv = null)
    {
        if (!defined('AM_ADMIN') ||!AM_ADMIN)
            return true;
        if ($perm === null)
            $perm = $this->getPermissionId();
        return $this->getDi()->authAdmin->getUser()->hasPermission($this->getPermissionId(), $priv);
    }

    function checkPermission($perm = null, $priv = null)
    {
        if ($perm === null)
            $perm = $this->getPermissionId();
        if (!$this->hasPermission($perm, $priv))
            $this->throwPermission($perm, $priv);
    }

    function getPermissionId()
    {
        return $this->permissionId ? $this->permissionId : 'grid'.$this->id;
    }

    function setPermissionId($id)
    {
        $this->permissionId = $id;
    }

    function throwPermission($perm = null, $priv = null)
    {
        if ($perm === null)
            $perm = $this->getPermissionId();
        throw new Am_Exception_AccessDenied(___("You have no enough permissions for this operation")
            ." (".$perm."-$priv)");
    }
}