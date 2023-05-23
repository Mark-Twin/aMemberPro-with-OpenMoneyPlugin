<?php

/**
 * @package Am_View
 */

/**
 * Class represents templates - derived from Zend_View_Abstract
 * @package Am_View
 */
class Am_View extends Zend_View_Abstract
{
    /** @var Am_Di */
    public $di;
    protected $layout = null;

    function __construct(Am_Di $di = null)
    {
        parent::__construct();
        $this->headMeta()->setName('generator', 'aMember Pro');
        $this->di = $di ?: Am_Di::getInstance();
        if ($this->di->hasService('theme')) {
            $this->theme = $this->di->theme;
        } else {
            $this->theme = new Am_Theme($this->di, 'default', array());
        }
        $this->setHelperPath('Am/View/Helper', 'Am_View_Helper_');
        $this->setEncoding('UTF-8');
        foreach ($this->di->viewPath as $dir) {
            $this->addScriptPath($dir);
        }
        if (!$this->getScriptPaths()) {
            $this->addScriptPath(dirname(__FILE__) . '/../../application/default/views');
        }

        static $init = 0;
        if (!$init++) {
            $this->headScript()->prependScript(<<<CUT
window.rootUrl = {$this->j($this->url("",false))}; //kept for compatibilty only! use amUrl() instead
function amUrl(u, return_path) {
    var ret = {$this->j($this->url("",false))} + u;
    return (return_path || 0) ? [ret, []] : ret;
};
window.amLangCount = {$this->j(count($this->di->getLangEnabled(false)))};
CUT
            );
        }
    }

    function j($_)
    {
        return json_encode($_);
    }

    /**
     * Return string of escaped HTML attributes from $attrs array
     * @param array $attrs
     */
    function attrs(array $attrs)
    {
        return Am_Html::attrs($attrs);
    }

    function display($name)
    {
        echo $this->render($name);
    }

    protected function _run()
    {
        $arg = func_get_arg(0);

        Am_Di::getInstance()->hook->call(Am_Event::BEFORE_RENDER,
            array('view' => $this, 'templateName' => $arg));

        extract($this->getVars());
        $savedLayout = $this->layout;
        ob_start();
        include func_get_arg(0);
        $content = ob_get_contents();
        ob_end_clean();
        if ($this->layout && $savedLayout != $this->layout) // was switched in template
        {
            while ($layout = array_shift($this->layout))
            {
                ob_start();
                include $this->_script($layout);
                $content = ob_get_contents();
                ob_end_clean();
            }
        }

        $event = Am_Di::getInstance()->hook->call(new Am_Event_AfterRender(null,
            array(
                'view' => $this,
                'templateName' => $arg,
                'output' => $content,
                )));
        echo $event->getOutput();
    }

    public function setLayout($layout)
    {
        $this->layout[] = $layout;
    }

    public function formOptions($options, $selected = '')
    {
        return Am_Html::renderOptions($options, $selected);
    }

    public function formCheckboxes($name, $options, $selected)
    {
        $out = "";
        $name = Am_Html::escape($name);
        foreach ($options as $k => $v)
        {
            $k = Am_Html::escape($k);
            $sel = is_array($selected) ? in_array($k, $selected) : $k == $selected;
            $sel = $sel ? " checked='checked'" : "";
            $out .= "<input type='checkbox' name='{$name}[]' value='$k'$sel>\n$v\n<br />\n";
        }
        return $out;
    }

    public function formRadio($name, $options, $selected)
    {
        $out = "";
        $name = Am_Html::escape($name);
        foreach ($options as $k => $v)
        {
            $k = Am_Html::escape($k);
            $sel = $k == $selected;
            $sel = $sel ? " checked='checked'" : "";
            $out .= "<input type='radio' name='{$name}' value='$k'$sel>\n$v\n<br />\n";
        }
        return $out;
    }

    function initI18n()
    {
        $am_i18n = json_encode(array(
            'toggle_password_visibility' => ___('Toggle Password Visibility'),
            'password_strength' => ___('Password Strength'),

            'upload_browse' => ___('browse'),
            'upload_upload' => ___('upload'),
            'upload_files' => ___('Uploaded Files'),
            'upload_uploading' => ___('Uploading...'),
            'ms_please_select' => ___('-- Please Select --'),
            'ms_select_all' => ___('Select All'),
        ));
        $this->headScript()->prependScript("am_i18n = $am_i18n;");
    }

    /**
     * Output all code necessary for aMember, this must be included before
     * closing </head> into layout.phtml
     * @param $safe_jquery_load  - Load jQuery only if it was not leaded before(true|false). Default is false.
     */
    function printLayoutHead($need_reset=true, $safe_jquery_load = false)
    {
        $this->initI18n();

        list($lang, ) = explode('_', $this->di->locale->getId());
        $jLang = json_encode($lang);

        if($need_reset) {
            $this->headLink()
                 ->appendStylesheet($this->_scriptCss('reset.css'));
        }
        $this->headLink()
            ->appendStylesheet($this->_scriptCss('amember.css'));
        $this->headLink()
            ->appendStylesheet($this->_scriptCss('ie-7.css'), 'screen', 'IE 7');

        $this->theme->printLayoutHead($this);

        if ($siteCss = $this->_scriptCss('site.css'))
            $this->headLink()->appendStylesheet($siteCss);

        $this->headLink()->appendStylesheet($this->_scriptJs('jquery/jquery.ui.css'));

        $hs = $this->headScript();
        try {
            $hs->prependScript(
                "window.uiDateFormat = " . json_encode($this->convertDateFormat(Am_Di::getInstance()->locale->getDateFormat())) . ";\n")
                    ->prependScript(sprintf("window.uiDefaultDate = new Date(%d,%d,%d);\n", date('Y'), date('n')-1, date('d')));
        } catch (Exception $e) {
            // we can live without it if Am_Locale is not yet registered, we will just skip this line
        }

        if($safe_jquery_load){
            $hs->prependScript('if (typeof jQuery == \'undefined\') {document.write(\'<script type="text/javascript" src="//code.jquery.com/jquery-2.2.4.min.js"></scr\'+\'ipt>\');} else {$=jQuery;}');
        }else{
            $hs->prependFile("//code.jquery.com/jquery-2.2.4.min.js");
        }
        $hs->appendFile($this->_scriptJs('jquery/jquery.ui.js'));
        $hs->appendFile($this->_scriptJs('user.js'));
        $hs->appendFile($this->_scriptJs('upload.js'));
        $hs->appendFile($this->_scriptJs('magicselect.js'));

        $jquii18n = json_encode(array(
            'closeText' => ___('Done'),
            'prevText' => ___('Prev'),
            'nextText' => ___('Next'),
            'currentText' => ___('Today'),
            'monthNames' => array_values($this->di->locale->getMonthNames('wide', true)),
            'monthNamesShort' => array_values($this->di->locale->getMonthNames('abbreviated', true))
        ));
        $hs->appendScript("jQuery.datepicker.setDefaults($jquii18n);");

        if (file_exists(AM_APPLICATION_PATH . '/configs/site.js'))
            $hs->appendFile($this->di->url('application/configs/site.js',false));

        echo "<!-- userLayoutHead() start -->\n";
        echo $this->placeholder("head-start") . "\n";
        echo $this->headMeta() . "\n";
        echo $this->headLink() . "\n";
        echo $this->headStyle() . "\n";
        echo $this->headScript() . "\n";
        echo $this->placeholder('head-finish') . "\n";
        echo "<!-- userLayoutHead() finish -->\n";
    }

    function adminHeadInit()
    {
        $this->initI18n();

        $this->headLink()->appendStylesheet($this->_scriptCss('reset.css'));
        $this->headLink()->appendStylesheet($this->_scriptJs("jquery/jquery.ui.css"));
        $this->headLink()->appendStylesheet("//cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/css/select2.min.css");
        $this->headLink()->appendStylesheet($this->_scriptCss('admin.css'));

        list($lang, ) = explode('_', Am_Di::getInstance()->locale->getId());

        if ($theme = $this->_scriptCss('admin-theme.css'))
            $this->headLink()->appendStylesheet($theme);
        $this->headScript()
            ->prependScript(
                "window.uiDateFormat = " . json_encode($this->convertDateFormat(Am_Di::getInstance()->locale->getDateFormat())) . ";\n")
            ->prependScript(sprintf("window.uiDefaultDate = new Date(%d,%d,%d);\n", date('Y'), date('n')-1, date('d')))
            ->prependScript(
                "window.lang = " . json_encode($lang) . ";\n")
            ->prependScript(sprintf("window.configDisable_rte = %d;\n", $this->di->config->get('disable_rte', 0)))
            ->prependFile($this->di->url("js.php?js=admin",false))
            ->prependFile("//cdnjs.cloudflare.com/ajax/libs/jquery.form/4.2.1/jquery.form.min.js")
            ->prependFile("//cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/js/select2.min.js")
            ->prependFile("//cdnjs.cloudflare.com/ajax/libs/jquery-json/2.6.0/jquery.json.min.js")
            ->prependFile("//code.jquery.com/jquery-2.2.4.min.js")
            ->appendFile("//cdn.ckeditor.com/4.10.0/full-all/ckeditor.js");

        $jquii18n = json_encode(array(
            'closeText' => ___('Done'),
            'prevText' => ___('Prev'),
            'nextText' => ___('Next'),
            'currentText' => ___('Today'),
            'monthNames' => array_values($this->di->locale->getMonthNames('wide', true)),
            'monthNamesShort' => array_values($this->di->locale->getMonthNames('abbreviated', true))
        ));
        $this->headScript()->appendScript("jQuery.datepicker.setDefaults($jquii18n);");

        $this->placeholder('body-start')->append(
            '<div id="flash-message"></div>'
        );
        if (!empty($this->use_angularjs))
        {
            $this->headScript()->prependFile($this->_scriptJs('angular/angular-route.js'));
            $this->headScript()->prependFile($this->_scriptJs('angular/angular-resource.js'));
            $this->headScript()->prependFile($this->_scriptJs('angular/angular.js'));
        }
    }

    /**
     * Convert date format from PHP date() to Jquery UI
     * @param string $dateFormat
     * @return string
     */
    public function convertDateFormat($dateFormat)
    {
        $convertionMap = array(
            'j' => 'd',  //day of month (no leading zero)
            'd' => 'dd', //day of month (two digit)
            'z' => 'oo', //day of the year (three digit)
            'D' => 'D',  //day name short
            'l' => 'DD', //day name long
            'm' => 'mm', //month of year (two digit)
            'M' => 'M',  //month name short
            'F' => 'MM', //month name long
            'y' => 'y',  //year (two digit)
            'Y' => 'yy'  //year (four digit)
        );
        return strtr($dateFormat, $convertionMap);
    }

    function getThemes($themeType = 'user')
    {
        $entries = scandir($td = AM_APPLICATION_PATH . ($themeType == 'user' ? '/default/themes' : '/default/themes-admin/'));
        $ret = array('default' => "Default Theme");
        foreach ($entries as $d)
        {
            if ($d[0] == '.')
                continue;
            $p = "$td/$d";
            if (is_dir($p) && is_readable($p))
                $ret[$d] = ucwords(str_replace ('-', ' ', $d));
        }
        return $ret;
    }

    /**
     * Converts $path to a file located inside aMember folder (!)
     * to an URL (if possible, relative, if impossible, using ROOT_SURL)
     * @throws Am_Exception_InternalError
     * @return string
     */
    function pathToUrl($path)
    {
        $p = realpath($path);
        $r = realpath(Am_Di::getInstance()->root_dir);
        if (strpos($p, $r) !== 0)
            throw new Am_Exception_InternalError("File [$p] is not inside application path [$r]");
        $rel = substr($p, strlen($r));
        return REL_ROOT_URL . str_replace('\\', '/', $rel);
    }

    /** Find location of the CSS (respecting the current theme)
     * @return string|null path including REL_ROOT_URL, or null
     */
    function _scriptCss($name, $escape = true)
    {
        try {
            $ret = $this->pathToUrl($this->_script('public/css/' . $name));
        } catch (Zend_View_Exception $e) {
            return;
        }
        return $escape ? $this->escape($ret) : $ret;
    }

    /** Find location of the CSS (respecting the current theme)
     * @return string|null path including REL_ROOT_URL, or null
     */
    function _scriptJs($name, $escape = true)
    {
        try {
            $ret = $this->pathToUrl($this->_script('public/js/' . $name));
        } catch (Zend_View_Exception $e) {
            return;
        }
        return $escape ? $this->escape($ret) : $ret;
    }

    /** Find location of the Image (respecting the current theme)
     * @return string|null path including REL_ROOT_URL, or null
     */
    function _scriptImg($name, $escape = true)
    {
        try {
            $ret = $this->pathToUrl($this->_script('public/img/' . $name));
        } catch (Zend_View_Exception $e) {
            return;
        }
        return $escape ? $this->escape($ret) : $ret;
    }

    /** Find path of the Image/CSS/JS (respecting the current theme)
     * @return string|null path
     */
    function _scriptPath($type/*img,js,css*/, $name, $escape = true)
    {
        try {
            $ret = $this->_script("public/$type/" . $name);
        } catch (Zend_View_Exception $e) {
            return;
        }
        return $escape ? $this->escape($ret) : $ret;
    }

    /**
     * Returns url of current page with given _REQUEST parameters overriden
     * @param array $parametersOverride
     */
    function overrideUrl(array $parametersOverride = array(), $skipRequestParams = false)
    {
        $vars = $skipRequestParams ? $parametersOverride : array_merge($_REQUEST, $parametersOverride);
        return $this->di->request->assembleUrl(false,true) . '?' . http_build_query($vars, '', '&');
    }

    /**
     * print escaped current url without parameters
     */
    function pUrl($controller = null, $action = null, $module = null, $params = null)
    {
        $args = func_get_args();
        echo call_user_func_array(array(Am_Di::getInstance()->request, 'makeUrl'), $args);
    }

    function rurl($path, $params = null, $encode = true)
    {
        return call_user_func_array(array($this->url, 'rurl'), func_get_args());
    }

    function surl($path, $params = null, $encode = true)
    {
        return call_user_func_array(array($this->url, 'surl'), func_get_args());
    }

    /**
     * Add necessary html code to page to enable graphical reports
     */
    function enableReports()
    {
        static $reportsEnabled = false;
        if ($reportsEnabled)
            return;
        $url1 = "//cdnjs.cloudflare.com/ajax/libs/raphael/2.1.2/raphael-min.js";
        $url2 = "//cdnjs.cloudflare.com/ajax/libs/morris.js/0.5.1/morris.min.js";
        $this->placeholder('head-finish')->append(<<<CUT
<script language="javascript" type="text/javascript" src="$url1"></script>
<script language="javascript" type="text/javascript" src="$url2"></script>
CUT
        );
        $reportsEnabled = true;
    }
}

/**
 * @package Am_View
 * helper to display theme variables in human-readable format
 */
class Am_View_Helper_ThemeVar
{
    function themeVar($k, $default = null)
    {
        $k = sprintf('themes.%s.%s', Am_Di::getInstance()->config->get('theme', 'default'), $k);
        return Am_Di::getInstance()->config->get($k, $default);
    }
}

/**
 * @package Am_View
 * helpder to display time interval in human readable format
 */
class Am_View_Helper_GetElapsedTime
{
    public $view = null;

    function setView(Zend_View_Interface $view)
    {
        $this->view = $view;
    }

    function getElapsedTime($date) {
        $sdate = amstrtotime($date);
        $edate = $this->view->di->time;

        $time = $edate - $sdate;
        if ($time < 0 || ($time>=0 && $time<=59)) {
            // Seconds
            return ___('just now');
        } elseif($time>=60 && $time<=3599) {
            // Minutes
            $pmin = $time / 60;
            $premin = explode('.', $pmin);

            $timeshift = $premin[0] . ' ' . ___('min');

        } elseif($time>=3600 && $time<=86399) {
            // Hours
            $phour = $time / 3600;
            $prehour = explode('.', $phour);

            $timeshift = $prehour[0]. ' ' . ___('hrs');

        } elseif($time>=86400 && $time<86400*30) {
            // Days
            $pday = $time / 86400;
            $preday = explode('.', $pday);

            $timeshift = $preday[0] . ' ' . ___('days');

        } elseif ($time>=86400*30 && $time<86400*30*12) {
            // Month
            $pmonth = $time / (86400 * 30);
            $premonth = explode('.', $pmonth);

            $timeshift = ___('more than') . ' ' . $premonth[0] . ' ' . ___('month');
        } else {
            // Year
            $pyear = $time / (86400 * 30 * 12);
            $preyear = explode('.', $pyear);

            $timeshift = ___('more than') . ' ' . $preyear[0] . ' ' . ___('year');
        }
        return $timeshift . ' ' . ___('ago');
    }
}

/**
 * helper to display blocks
 * @package Am_View
 * @link Am_Blocks
 * @link Am_Block
 */
class Am_View_Helper_Blocks extends Zend_View_Helper_Abstract
{
    /** @var Am_Blocks */
    protected $blocks;

    /** @return Am_Blocks */
    function getContainer()
    {
        if (!$this->blocks)
            $this->blocks = $this->view->di->blocks;
        return $this->blocks;
    }

    function setContainer(Am_Blocks $blocks)
    {
        $this->blocks = $blocks;
    }

    /**
     * Render blocks by $path pattern
     * Each block will be outlined by envelope
     * $vars array will be passed to $view into the block render()
     * @param string $path
     * @param string $envelope
     * @param array $vars
     * @return string
     */
    function render($path, $envelope = "%s", array $vars = array())
    {
        $out = "";
        foreach ($this->getContainer()->get($path) as $block)
        {
            $view = new Am_View;
            if ($vars) $view->assign($vars);
            $view->blocks()->setContainer($this->getContainer());
            $out .= $block->render($view, $envelope);
        }
        return $out;
    }

    /** if called as blocks() returns itself, if called as block('path') calls render('path') */
    function blocks($path = null, $envelope = "%s", $vars = array())
    {
        return $path === null ? $this : $this->render($path, $envelope, $vars);
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array(array($this->getContainer(), $name), $arguments);
    }

    public function requireJs($path)
    {
        if (!preg_match('#^(/|http(s?):)#i', $path))
            $path = $this->view->_scriptJs($path);
        $this->view->headScript()->appendFile($path);
        return $this;
    }

    public function requireCss($path)
    {
        if (!preg_match('#^(/|http(s?):)#i', $path))
            $path = $this->view->_scriptCss($path);
        $this->view->headLink()->appendStylesheet($path);
        return $this;
    }
}

/**
 * View helper to return translagted text (between start() and stop() calls)
 * @package Am_View
 * @deprecated
 */
class Am_View_Helper_Tr extends Zend_View_Helper_Abstract
{
    protected $text;
    protected $args;

    /**
     * Return translated text if argument found, or itself for usage of start/stop
     * @param string|null $text
     * @return Am_View_Helper_Tr|string
     */
    function tr($text = null)
    {
        if ($text === null)
            return $this;
        $this->args = func_get_args();
        $this->text = array_shift($this->args);
    }

    function start($arg1=null, $arg2=null)
    {
        $this->args = func_get_args();
        ob_start();
    }

    function stop()
    {
        $this->text = ob_get_clean();
        $this->doPrint();
    }

    protected function doPrint()
    {
        $tr = Zend_Registry::get('Zend_Translate');
        if (!$tr)
        {
            trigger_error("No Zend_Translate instance found", E_USER_WARNING);
            echo $this->text;
        }
        /* @var $tr Zend_Translate_Adapter */
        $this->text = $tr->_(trim($this->text));
        vprintf($this->text, $this->args);
    }
}

/**
 * For usage in templates
 * echo escaped variable
 */
function p($var)
{
    echo htmlentities($var, ENT_QUOTES, 'UTF-8', false);
}

/** echo variable escaped for javascript
 */
function j($var)
{
    echo strtr($var, array("'" => "\\'", '\\' => '\\\\', '"' => '\\"', "\r" => '\\r', '</' => '<\/', "\n" => '\\n'));
}
