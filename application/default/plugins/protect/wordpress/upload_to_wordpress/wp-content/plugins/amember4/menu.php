<?php
class am4Menu extends am4Plugin
{
    private $slug = 'am4-settings';
    const POSTION = 756;

    function action_AdminMenu()
    {
        add_menu_page('aMember', 'aMember', 'administrator', $this->slug,'','',self::POSTION);
        foreach(get_declared_classes() as $c){
            if(preg_match('/am4MenuPage_(\S+)/',$c,$regs)){
                $this->addMenuItem("am4-".strtolower($regs[1]), $c);
            }
        }
    }

    function addMenuItem($slug,  $class)
    {
        $title = call_user_func(array($class, 'getTitle'));
        $a = add_submenu_page($this->slug, $title, $title,'administrator', $slug, am4PluginsManager::createController($class));
        if(method_exists($class, 'staticAction')){
    	    add_action('load-'.$a, array($class, 'staticAction'));
        }
    }
}

class am4MenuPageController extends am4FormController
{
    static function getTitle()
    {
        throw new Exception("getTitle should be overriden in childs");
    }

    function preDispatch(){}
}

class am4MenuPageFormController extends am4MenuPageController
{
    function preDispatch()
    {
        parent::preDispatch();
        if(!current_user_can('manage_options'))
            return '';

        // Verify nonce
        if(am4Request::$method=='POST' && !check_admin_referer(get_class())){
            throw new Exception('Security check!');
        }
        $this->amPostScript();
        ?>
        <div class="wrap">
            <div id="icon-options-general" class="icon32"><br /></div>
            <h2><?php  _e($this->getTitle());?></h2>
            <form id="amember-settings-form" method="POST" action="">
            <?php
            wp_nonce_field(get_class());

        return true;
    }

    function postDispatch()
    {
        parent::postDispatch();
        $this->actionInput("save");
        ?>
            <input type="submit" class="button-primary" name="save" value="Apply Changes">
            </form>
        </div>

        <?php
    }
}

class am4MenuPage_settings extends am4MenuPageFormController
{
    protected $chroot;

    static function getTitle()
    {
        return __("Settings", 'am4-plugin');
    }

    function getOptions()
    {
        $options = new am4_Settings_Config();
        if(!$options->{'user_action'} || !$options->{'guest_action'}) $options->loadDefaults($this->getProtectionDefaults());
        if(!$options->{'disable_protection'}) $options->{'disable_protection'} = array('administrator');
        return $options;
    }

    function saveForm($post)
    {
        $options = new am4_Settings_Config();
        $options->loadFromArray($post)->save();
    }

    function validate($options)
    {
        try{
            am4PluginsManager::initAPI(@$options['path']);
        }catch(Exception $ex){
            return $ex->getMessage();
        }
        return true;
    }

    static function staticAction()
    {
        if(am4Request::$method!='POST') return;
        if(!current_user_can('manage_options')) return;

        if(!check_admin_referer('am4MenuPageFormController')){
            throw new Exception('Security check!');
        }
        $options = get_magic_quotes_gpc() ? stripslashes_deep(am4Request::get('options')) : am4Request::get('options');

        try {
            am4PluginsManager::initAPI(@$options['path']);
        } catch (Exception $e) {}
    }

    function doAjaxValidate()
    {
        if (($err = $this->validate(array('path' => am4Request::get('path')))) === true){
            $am = Am_Lite::getInstance();
            $e = new aMemberJson();
            $e->valid=1;
            $e->url = $am->getRootURL();
            echo $e;
        } else {
            $e = new aMemberJsonError($err);
            echo $e;
        }
    }

    function doAjaxBrowse()
    {
        $dirOrig = am4Request::get('dir', ABSPATH);
        $dirOrig = is_dir($dirOrig) ? $dirOrig : ABSPATH;

        $selected = am4Request::get('selected', false);

        $dir = ($selected) ? dirname($dirOrig) : $dirOrig;
        $dir = realpath($dir);

        if (!is_dir($dir)) {
            $dir = ABSPATH;
        }

        $dirList = $this->getDirList($dir);

        if ($selected) {
            foreach ($dirList as $k => $dirDescription) {
                if ($dirDescription['path'] == $dirOrig) {
                    $dirList[$k]['selected'] = true;
                    break;
                }
            }
        }

        $currentDir = $this->getCurrentDir($dir);
        $prevDir = $this->getPrevDir($dir);

        $result = array(
            'dirList' => $dirList,
            'currentDir' => $currentDir,
            'prevDir' => $prevDir,
            'separator' => DIRECTORY_SEPARATOR
        );

        aMemberJson::init($result)->send();
    }

    protected function getCurrentDir($dir)
    {
        $result = array();
        $dirParts = explode(DIRECTORY_SEPARATOR, $dir);

        $path = array();
        foreach ($dirParts as $part) {
            $path[]= $part;

            $part_path = implode(DIRECTORY_SEPARATOR, $path);
            $dir = array (
                'name' => $part,
                'path' => ($this->checkChRoot($part_path) ? $part_path : null )
            );
           $result[] = $dir;
        }

        return $result;
    }

    protected function getPrevDir($dir)
    {
        $prevDir = null;

        $prevDirPath = dirname($dir);

        //root of file system
        if ($prevDirPath == $dir) return null;

        $dirParts = explode(DIRECTORY_SEPARATOR, $prevDirPath);

        $prevDirName = end($dirParts);

        if (is_dir( $prevDirPath ) ) {
            $prevDir = array (
                'name' => $prevDirName,
                'path' => ($this->checkChRoot($prevDirPath) ? $prevDirPath : null)
            );
        }

        return $prevDir;
    }

    protected function getDirList($dir)
    {
        $result = array();
        $dirName = $dir;

        $dirHandler = opendir($dirName);
        while(false !== ($fn = readdir($dirHandler))) {
            if (is_dir($dirName . DIRECTORY_SEPARATOR . $fn) &&
                    !in_array($fn, array('..', '.'))) {

                $result[] = $this->getDirRecord($dirName, $fn);
            }
        }
        closedir($dirHandler);
        usort($result, function($a, $b) {return strcmp($a["name"], $b["name"]);});

        return $result;
    }

    protected function getDirRecord($dirName, $fn)
    {
        $stat = stat($dirName . DIRECTORY_SEPARATOR . $fn);

        $dir = array(
            'name' => $fn,
            'path' => $dirName . DIRECTORY_SEPARATOR . $fn,
            'url' => $this->guessUrl($dirName . DIRECTORY_SEPARATOR . $fn),
            'perm' => $this->formatPermissions($stat['mode']),
            'created' => $this->formatDate($stat['ctime']),
            'selected' => false
        );
        return $dir;
    }

    public function guessUrl($dir)
    {
        $documentRootFixed = str_replace('/', DIRECTORY_SEPARATOR, $_SERVER['DOCUMENT_ROOT']);
        //check if it is possible to calculate url
        if (strpos($dir, $documentRootFixed) !== 0) return false;

        $rootUrlMeta = parse_url(get_option('blog_url'));

        //combine url
        return sprintf('%s://%s%s/%s',
            @$rootUrlMeta['scheme'],
            @$rootUrlMeta['host'],
            (isset($rootUrlMeta['port']) ? ':' . @$rootUrlMeta['port'] : ''),
            trim(str_replace(DIRECTORY_SEPARATOR, '/', str_replace($documentRootFixed, '', $dir)), '/')
        );
    }

    protected function formatPermissions($p)
    {
        $res = '';
        $res .= ($p & 256) ? 'r' : '-';
        $res .= ($p & 128) ? 'w' : '-';
        $res .= ($p & 64) ?  'x' : '-';
        $res .= ' ';
        $res .= ($p & 32) ?  'r' : '-';
        $res .= ($p & 16) ?  'w' : '-';
        $res .= ($p & 8) ?   'x' : '-';
        $res .= ' ';
        $res .= ($p & 4) ?   'r' : '-';
        $res .= ($p & 2) ?   'w' : '-';
        $res .= ($p & 1) ?   'x' : '-';
        return $res;
    }

    protected function formatDate($tm)
    {
        $format = get_option('date_format') . ', ' . get_option('time_format');
        return date_i18n($format, $tm);
    }

    protected function checkChRoot($dir)
    {
        if (!is_null($this->chroot) &&
            strpos($dir, $this->chroot)!==0) {
            return false;
        } else {
            return true;
        }
    }
}

/*
class am4MenuPage_styles extends am4MenuPageFormController{
    protected $styles = array('widget_login_form.phtml', 'widget_after_login.phtml');
    static function getTitle(){
        return __('Templates', 'am4-plugin');
    }
    function saveForm($options){
        foreach($options as $k=>$v){
            if(!in_array($k,$this->styles)) unset($options[$k]);
        }
        foreach($this->styles as $f){
            $file = file_get_contents(AM4_PLUGIN_DIR."/views/".$f);
            if(strcmp(trim($file), trim($options[$f]))=== 0) unset($options[$f]);
        }
        if($options){
            $templates = new am4_Settings_Templates();
            $templates->loadFromArray($options)->save();
        }
    }

    function getOptions(){
        $templates = new am4_Settings_Templates();
        foreach($this->styles as $s){
            if(!$templates->get($s)){
                $templates->set($s, file_get_contents(AM4_PLUGIN_DIR."/views/".$s));
            }
        }
        return $templates;

    }

}

*/
class am4MenuPage_errormessages extends am4MenuPageFormController
{
    protected $simple;

    function __construct($simple = false)
    {
        $this->simple = $simple;
    }

    static function getTitle()
    {
        return __('Error Messages', 'am4-plugin');
    }

    function isSimple()
    {
        return $this->simple;
    }

    function postDispatch()
    {
        return; // doNothing;
    }

    function saveForm($options)
    {
        $errors = $this->getOptions();
        if (isset($options['id']) && ($options['id']!== '')) {
            $errors->set(intval($options['id']), $options);
        } else {
            $errors->add($options);
        }
        $errors->save();
    }

    function getOptions()
    {
        $errors = new am4_Settings_Error();
        return $errors;
    }

    function doAjaxDelete()
    {
        $id = am4Request::getInt('id');
        $errors = $this->getOptions();
        $errors->delete($id)->save();
    }
}