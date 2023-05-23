<?php

abstract class TranslationDataSource_Abstract
{
    const FETCH_MODE_ALL = 'all';
    const FETCH_MODE_REWRITTEN = 'rewritten';
    const FETCH_MODE_UNTRANSLATED = 'untranslated';
    const LOCALE_KEY = '_default_locale';

    final public function getTranslationData($locale, $fetchMode = TranslationDataSource_Abstract::FETCH_MODE_ALL)
    {
        list($lang, ) = explode('_', $locale);

        switch ($fetchMode) {
            case self::FETCH_MODE_ALL :
                $result = $this->getBaseTranslationData($lang);
                $result = $this->mergeWithCustomTranslation($result, $locale);
                break;
            case self::FETCH_MODE_REWRITTEN :
                $result = Am_Di::getInstance()->translationTable->getTranslationData($locale);
                $base = $this->getBaseTranslationData($lang);
                foreach ($result as $k=>$v) {
                    if (!key_exists($k, $base)) {
                        unset($result[$k]);
                    }
                }
                break;
            case self::FETCH_MODE_UNTRANSLATED :
                $result = $this->getBaseTranslationData($lang);
                $result = $this->mergeWithCustomTranslation($result, $locale);
                $flip = array_flip($result);
                $result = array_filter($result, function($v) use ($flip) {
                    return (bool)(!$v || $v == $flip[$v]);
                });
                break;
            default:
                throw new Am_Exception_InternalError('Unknown fetch mode : ' . $fetchMode);
                break;
        }

        if (isset($result[self::LOCALE_KEY])) {
            unset($result[self::LOCALE_KEY]);
        }
        return $result;
    }

    public function createTranslation($language)
    {
        $filename = $this->getFileName($language);
        $path = Am_Di::getInstance()->root_dir . "/application/default/language/user/{$filename}";

        if ($error = $this->validatePath($path)) {
            return $error;
        }

        $content = $this->getFileContent($language);
        file_put_contents($path, $content);
        return '';
    }

    protected function mergeWithCustomTranslation($translationData, $locale)
    {
        list($lang, ) = explode('_', $locale);

        foreach (array_unique(array($lang, $locale)) as $l) {
            if ($custom = Am_Di::getInstance()->translationTable->getTranslationData($l)) {
                foreach ($translationData as $k => $v) {
                    if (isset($custom[$k])) {
                        $translationData[$k] = $custom[$k];
                    }
                }
                foreach ($custom as $k => $v) {
                    if (!isset($translationData[$k])) {
                        $translationData[$k] = $custom[$k];
                    }
                }
            }
        }
        return $translationData;
    }

    protected function getBaseTranslationData($language)
    {
        return $this->_getBaseTranslationData($language);
    }

    protected function validatePath($path)
    {
        if (file_exists($path)) {
            return ___('File %s is already exist. You can not create already existing translation.', $path);
        }

        $dir = dirname($path);
        if (!is_writeable($dir)) {
            return ___('Folder %s is not a writable for the PHP script. Please <br />
            chmod this file using webhosting control panel file manager or using your<br />
            favorite FTP client to 777 (write and read for all)<br />
            Please, don\'t forget to chmod it back to 755 after creation of translation', $dir);
        }

        return '';
    }

    abstract protected function _getBaseTranslationData($language);
    abstract function getFileName($language);
    abstract function getFileContent($language, $translationData = array());
}

class TranslationDataSource_PHP extends TranslationDataSource_Abstract
{
    function getFileName($language)
    {
        return $language . '.php';
    }

    function getFileContent($language, $translationData = array())
    {
        $expectedLocaleName = $language;
        $locale = new Zend_Locale($expectedLocaleName);
        //prepend local to start of array
        $translationData = array_reverse($translationData);
        $translationData[self::LOCALE_KEY] = $locale;
        $translationData = array_reverse($translationData);

        $out = '';
        $out .= "<?php"
                . PHP_EOL
                . "return array ("
                . PHP_EOL;

        foreach ($translationData as $msgid => $msgstr) {
            $out .= "\t";
            $out .= sprintf("'%s'=>'%s',",
            str_replace("'", "\'", $msgid),
            str_replace("'", "\'", $msgstr)
            );
            $out .= PHP_EOL;
        }
        $out .= "\t''=>''" . PHP_EOL;
        $out .= ");";
        return $out;
    }

    protected function _getBaseTranslationData($language)
    {
        $result = include(AM_APPLICATION_PATH . "/default/language/user/default.php");
        $result = array_merge($result, (array)@include(AM_APPLICATION_PATH . "/default/language/user/{$language}.php"));
        $result = array_merge($result, (array)@include(AM_APPLICATION_PATH . "/default/language/admin/default.php"));
        $result = array_merge($result, (array)@include(AM_APPLICATION_PATH . "/default/language/admin/{$language}.php"));
        return $result;
    }
}

class TranslationDataSource_PO extends TranslationDataSource_Abstract
{
    function getFileName($language)
    {
        return $language . '.po';
    }

    function getFileContent($language, $translationData=array())
    {
        $expectedLocaleName = $language;
        $locale = new Zend_Locale($expectedLocaleName);
        //prepend local to start of array
        $translationData = array_reverse($translationData);
        $translationData[self::LOCALE_KEY] = $locale;
        $translationData = array_reverse($translationData);

        $out = '';

        foreach ($translationData as $msgid => $msgstr) {
            $out .= sprintf('msgid "%s"', $this->prepare($msgid, true));
            $out .= PHP_EOL;
            $out .= sprintf('msgstr "%s"', $this->prepare($msgstr, true));
            $out .= PHP_EOL;
            $out .= PHP_EOL;
        }

        return $out;
    }

    protected function _getBaseTranslationData($language)
    {
        $result= array();
        $result = $this->getTranslationArray(AM_APPLICATION_PATH . "/default/language/user/default.pot");
        $result = array_merge($result, $this->getTranslationArray(AM_APPLICATION_PATH . "/default/language/user/{$language}.po"));
        return $result;
    }

    protected function getTranslationArray($file)
    {
        $result = array();

        $fPointer = fopen($file, 'r');

        $part = '';
        while (!feof($fPointer)) {
            $line = fgets($fPointer);
            $part .= $line;
            if (!trim($line)) { //entity divided with empty line in file
                $result = array_merge($result, $this->getTranslationEntity($part));
                $part = '';
            }
        }

        fclose($fPointer);

        unset($result['']);//unset meta
        return $result;
    }

    protected function getTranslationEntity($contents)
    {
        $result = array();
        $matches = array();

        $matched = preg_match(
                '/(msgid\s+("([^"]|\\\\")*?"\s*)+)\s+' .
                '(msgstr\s+("([^"]|\\\\")*?"\s*)+)/u',
                $contents, $matches
        );

        if ($matched) {
            $msgid = $matches[1];
            $msgid = preg_replace(
                    '/\s*msgid\s*"(.*)"\s*/s', '\\1', $matches[1]);
            $msgstr = $matches[4];
            $msgstr = preg_replace(
                    '/\s*msgstr\s*"(.*)"\s*/s', '\\1', $matches[4]);
            $result[$this->prepare($msgid)] = $this->prepare($msgstr);
        }

        return $result;
    }

    protected function prepare($string, $reverse = false)
    {
        if ($reverse) {
            $smap = array('"', "\n", "\t", "\r");
            $rmap = array('\\"', '\\n"' . "\n" . '"', '\\t', '\\r');
            return (string) str_replace($smap, $rmap, $string);
        } else {
            $smap = array('/"\s+"/', '/\\\\n/', '/\\\\r/', '/\\\\t/', '/\\\\"/');
            $rmap = array('', "\n", "\r", "\t", '"');
            return (string) preg_replace($smap, $rmap, $string);
        }
    }
}

class TranslationDataSource_DB extends TranslationDataSource_Abstract
{
    public function createTranslation($language)
    {
        throw new Am_Exception_InputError('Local translations can not be created');
    }

    function getFileName($language)
    {
        throw new Am_Exception_InputError('Local translations can not be exported');
    }

    function getFileContent($language, $translationData = array())
    {
        throw new Am_Exception_InputError('Local translations can not be exported');
    }

    protected function _getBaseTranslationData($language)
    {
        $result = array();
        foreach(Am_Di::getInstance()->plugins_payment->loadEnabled()->getAllEnabled() as $pl)
        {
            $result[$pl->getConfig('title')] = "";
            $result[$pl->getConfig('description')] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title, description FROM ?_product") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['description'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT terms FROM ?_billing_plan WHERE terms<>''") as $r) {
            $result[ $r['terms'] ] = "";
        }
        foreach ((array)Am_Di::getInstance()->config->get('member_fields') as $field) {
            $result[ $field['title'] ] = "";
            $result[ $field['description'] ] = "";
        }
        foreach ((array)Am_Di::getInstance()->config->get('helpdesk_ticket_fields') as $field) {
            $result[ $field['title'] ] = "";
            $result[ $field['description'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title, `desc` FROM ?_folder") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['desc'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title, `desc` FROM ?_file") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['desc'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title, `desc` FROM ?_page") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['desc'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title, `desc` FROM ?_link") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['desc'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title, `desc` FROM ?_video") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['desc'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title, description FROM ?_resource_category") as $r) {
            $result[ $r['title'] ] = "";
            $result[ $r['description'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title FROM ?_saved_form") as $r) {
            $result[ $r['title'] ] = "";
        }
        foreach (Am_Di::getInstance()->db->select("SELECT title FROM ?_user_group") as $r) {
            $result[ $r['title'] ] = "";
        }
        $savedFormTbl = Am_Di::getInstance()->savedFormTable->getName();
        foreach (Am_Di::getInstance()->db->select("SELECT fields FROM {$savedFormTbl}") as $r) {
            $fields = json_decode($r['fields'], true);
            foreach ($fields as $field){
                if (isset($field['labels'])) {
                    foreach ($field['labels'] as $k => $v) {
                        $result[ $v ] = "";
                    }
                }
            }
        }
        return Am_Di::getInstance()->hook->filter($result, Am_Event::GET_BASE_TRANSLATION_DATA);
    }
}

class Am_Grid_DataSource_Array_Trans extends Am_Grid_DataSource_Array
{
    /* @var TranslationDataProvider_Abstract */
    protected $tDataSource = null,
        $locale = null,
        $_order_f = null,
        $_order_d = null;

    public function __construct($locale)
    {
        $this->tDataSource = $this->createTDataSource();
        $this->locale = $locale;

        $translationData = $this->tDataSource
            ->getTranslationData($this->locale, TranslationDataSource_Abstract::FETCH_MODE_ALL);
        return parent::__construct(self::prepareArray($translationData));
    }

    public static function prepareArray($translationData)
    {
        $records = array();
        foreach ($translationData as $base => $trans) {
            $record = new stdClass();
            $record->base = $base;
            $record->trans = $trans;
            $records[] = $record;
        }
        return $records;
    }

    /**
     * @return TranslationDataSource_Abstract
     */
    public function getTDataSource()
    {
        return $this->tDataSource;
    }

    public function setOrder($fieldNameOrRaw, $desc=null)
    {
        $this->_order_f = $fieldNameOrRaw;
        $this->_order_d = $desc;
        return parent::setOrder($fieldNameOrRaw, $desc);
    }

    //this method is only for use in Am_Grid_Filter
    public function _friendGetOrder()
    {
        return array($this->_order_f, $this->_order_d);
    }

    protected function createTDataSource()
    {
        return new TranslationDataSource_PHP();
    }
}

class Am_Grid_Action_NewTrans extends Am_Grid_Action_Abstract
{
    protected $title = "Add Language";
    protected $type = self::NORECORD;

    public function run()
    {
        $form = $this->getForm();
        if ($form->isSubmitted() && $form->validate()) {

            $error = $this->grid->getDataSource()
                    ->getTDataSource()
                    ->createTranslation($this->grid->getCompleteRequest()->getParam('new_language'));
            if ($error) {
                $form->setError($error);
            } else {
                Zend_Locale::hasCache() && Zend_Locale::clearCache();
                Zend_Translate::hasCache() && Zend_Translate::clearCache();

                $this->grid->getDi()->cache->clean();
                $this->grid->redirectBack();
            }
        }
        echo $this->renderTitle();
        echo $form;
    }

    public function getForm()
    {
        $languageTranslation = Am_Locale::getSelfNames();

        $avalableLocaleList = Zend_Locale::getLocaleList();
        $existingLanguages = $this->grid->getDi()->languagesListUser;
        $languageOptions = array();

        foreach ($avalableLocaleList as $k=>$v) {
            $locale = new Zend_Locale($k);
            if (!array_key_exists($locale->getLanguage(), $existingLanguages) &&
                    isset($languageTranslation[$locale->getLanguage()])) {

                $languageOptions[$locale->getLanguage()] = "($k) {$languageTranslation[$locale->getLanguage()]}";
            }
        }

        asort($languageOptions);

        $form = new Am_Form_Admin();
        $form->setAction($this->grid->makeUrl(null));

        $form->addSelect('new_language', array('class'=>'am-combobox-fixed'))
                ->setLabel(___('Language'))
                ->loadOptions($languageOptions)
                ->setId('languageSelect');
        $form->addHidden('a')
                ->setValue('new');

        $form->addSaveButton();

        foreach ($this->grid->getVariablesList() as $k) {
            if ($val = $this->grid->getRequest()->get($k)) {
                $form->addHidden($this->grid->getId() .'_'. $k)->setValue($val);
            }
        }

        return $form;
    }
}

class Am_Grid_Action_ExportTrans extends Am_Grid_Action_Abstract
{
    protected $title = "Export";
    protected $type = self::NORECORD;

    public function run()
    {
        if (!$language = $this->grid->getCompleteRequest()->get('language')) {
            $language = Am_Di::getInstance()->locale->getLanguage();
        }

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest'; //return response without layout
        $outputDataSource = new TranslationDataSource_PO();
        $inputDataSource = $this->grid->getDataSource()->getTDataSource();

        $filename = $outputDataSource->getFileName($language);

        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");

        header('Content-type: text/plain');
        header("Content-Disposition: attachment; filename=$filename");
        echo $outputDataSource->getFileContent($language, $inputDataSource->getTranslationData($language, TranslationDataSource_Abstract::FETCH_MODE_REWRITTEN));
    }
}

class Am_Grid_Filter_Trans extends Am_Grid_Filter_Abstract
{
    protected $locale = null,
        $varList = array('filter', 'mode');

    public function  __construct($locale)
    {
        $this->title = ' ';
        $this->locale = $locale;
    }

    protected function applyFilter()
    {
        $tDataSource = $this->grid->getDataSource()->getTDataSource();

        $tData = $tDataSource
            ->getTranslationData($this->locale, $this->getParam('mode', TranslationDataSource_Abstract::FETCH_MODE_ALL));
        $tData = $this->filter($tData, $this->getParam('filter'));
        $this->grid->getDataSource()->_friendSetArray(
                Am_Grid_DataSource_Array_Trans::prepareArray($tData)
        );

        list($fieldname, $desc) = $this->grid->getDataSource()->_friendGetOrder();
        if ($fieldname) {
            $this->grid->getDataSource()->setOrder($fieldname, $desc);
        }
    }

    function renderInputs()
    {
        $options = array(
            TranslationDataSource_Abstract::FETCH_MODE_ALL => 'All',
            TranslationDataSource_Abstract::FETCH_MODE_REWRITTEN => 'Customized Only',
            TranslationDataSource_Abstract::FETCH_MODE_UNTRANSLATED => 'Untranslated Only'
        );

        $filter = ___('Display Mode') . ' ';

        $filter .= $this->renderInputSelect('mode', $options, array('id'=>'trans-mode'));
        $filter .= ' ';
        $filter .= $this->renderInputText(array(
            'name' => 'filter',
            'placeholder' => ___('Filter by String')
        ));
        $filter .= sprintf('<input type="hidden" name="language" value="%s">', $this->locale);

        return $filter;
    }

    protected function filter($array, $filter)
    {
        if (!$filter) return $array;
        foreach ($array as $k=>$v) {
            if (false === stripos($k, $filter) &&
                    false === stripos($v, $filter)) {

                unset($array[$k]);
            }
        }
        return $array;
    }
}

class AdminTransGlobalController extends Am_Mvc_Controller_Grid
{
    protected $language = null;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_TRANSLATION);
    }

    public function init()
    {
        $this->getView()->headScript()->appendScript($this->getJs());

        $enabled = $this->getDi()->getLangEnabled();
        $locale = $this->getDi()->locale->getId();
        $lang = $this->getDi()->locale->getLanguage();
        $this->language = $this->getParam('language') ?: (in_array($locale, $enabled) ? $locale : $lang);
        parent::init();
    }

    public function createGrid()
    {
        $grid = $this->_createGrid(___('Translations'));
        //$grid->actionAdd(new Am_Grid_Action_NewTrans);
        $grid->actionAdd(new Am_Grid_Action_ExportTrans())->setTarget('_top');
        return $grid;
    }

    protected function _createGrid($title)
    {
        $ds = $this->createDS($this->getLocale());
        $ds->setOrder('base');
        $grid = new Am_Grid_Editable('_trans', $title, $ds, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_TRANSLATION);
        $grid->addField(new Am_Grid_Field('base', ___('Key/Default'), true, '', null, '50%'))
            ->setRenderFunction(function($obj, $f, $grid, $field){
                return $grid->renderTd(nl2br($grid->escape($obj->$f)), false);
            });
        $grid->addField(new Am_Grid_Field('trans', ___('Current'), true, '', array($this, 'renderTrans'), '50%'));
        $grid->setFilter(new Am_Grid_Filter_Trans($this->getLocale()));
        $grid->actionsClear();
        $grid->setRecordTitle(___('Translation'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_CONTENT, array($this, 'wrapContent'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, array($this, 'wrapTable'));
        return $grid;
    }

    protected function createDS($locale)
    {
        return new Am_Grid_DataSource_Array_Trans($locale);
    }

    function wrapTable(& $out, $grid)
    {
        $out = '<form method="post" target="_top" name="translations" action="'
                . $this->getUrl(null, 'save', null, array('language'=>$this->getLocale()))
                . '">'
                . $out
                . sprintf('<input type="hidden" name="language" value="%s">', $this->getLocale());

        $vars = $this->grid->getVariablesList();
        $vars[] = 'p'; //stay on current page
        foreach ($vars as $var) {
            if ($val = $this->grid->getRequest()->getParam($var)) {
                $out .= sprintf('<input type="hidden" name="%s" value="%s">', $this->grid->getId() . '_' . $var, $val);
            }
        }
        $out .= '<div class="group-wrap"><input type="submit" name="submit" value="Save"></div>'
                . '</form>';
    }

    function wrapContent(& $out, $grid)
    {
        $out = $this->renderLanguageSelection() . $out;
    }

    function renderTrans($record)
    {
        return sprintf(<<<CUT
<td class="text-edit-wrapper">
        <input type="hidden" name="trans_base[%s]" value="%s"/>
        <textarea class="text-edit" name="trans[%s]">%s</textarea>
</td>
CUT
            , md5($record->base), Am_Html::escape($record->base),
              md5($record->base), Am_Html::escape($record->trans));
    }

    public function getLocale()
    {
        return $this->language;
    }

    function saveAction()
    {
        $trans = array_map(function($el) {return preg_replace('/\r?\n/', "\n", $el);},
            $this->getRequest()->getParam('trans', array()));
        $trans_base = array_map(function($el) {return preg_replace('/\r?\n/', "\n", $el);},
            $this->getRequest()->getParam('trans_base', array()));

        $tData = $this->grid->getDataSource()
                ->getTDataSource()
                ->getTranslationData($this->getLocale(), TranslationDataSource_Abstract::FETCH_MODE_ALL);

        $toReplace = array();
        foreach ($trans as $k=>$v) {
            if ( $v != $tData[$trans_base[$k]] ) {
                $toReplace[$trans_base[$k]] = $v;
            }
        }

        if (count($toReplace)) {
            $this->getDi()->translationTable->replaceTranslation($toReplace, $this->getLocale());
            Zend_Translate::hasCache() && Zend_Translate::clearCache();
        }

        $_POST['trans'] = $_GET['trans'] = $_POST['trans_base'] = $_GET['trans_base'] = null;
        $this->grid->getRequest()->setParam('trans', null);
        $this->grid->getCompleteRequest()->setParam('trans', null);
        $this->getRequest()->setParam('trans', null);
        $this->grid->getRequest()->setParam('trans_base', null);
        $this->grid->getCompleteRequest()->setParam('trans_base', null);
        $this->getRequest()->setParam('trans_base', null);

        $url = $this->getDi()->url('admin-trans-global/index', $this->getRequest()->toArray(), false);
        $this->_response->redirectLocation($url);
    }

    protected function renderLanguageSelection()
    {
        $form = new Am_Form_Admin();

        $form->addSelect('language')
                ->setLabel(___('Language'))
                ->setValue($this->getLocale())
                ->loadOptions($this->getLanguageOptions());

        $renderer = HTML_QuickForm2_Renderer::factory('array');

        $form->render($renderer);

        $form = $renderer->toArray();
        $filter = '';
        foreach ($form['elements'] as $el) {
            $filter .= ' ' . $el['label'] . ' ' . $el['html'];
        }
        $url = $this->getDi()->url('admin-setup/language');
        $icon = $this->view->icon('plus');
        return sprintf("<div class='filter-wrap'><form class='filter' method='get' action='%s'>\n",
                $this->escape($this->getUrl(null, 'index'))) .
                $filter .
                " <a href=\"$url\" target=\"_top\" style=\"display:inline-block; vertical-align:middle\">$icon</a></form></div>\n" ;
    }

    protected function getLanguageOptions()
    {
        $op =  $this->getDi()->languagesListUser;
        $enabled = $this->getDi()->getLangEnabled();
        $_ = array();
        foreach ($enabled as $k) {
            $_[$k] = $op[$k];
        }
        return $_;
    }

    protected function getJs()
    {
        $revertIcon = $this->getView()->icon('revert');

        $cancel_title = ___('Cancel All Changes in Translations on Current Page');
        $jsScript = <<<CUT
(function($){
    jQuery(function() {
        jQuery(document).on('change', 'form.filter select#trans-mode', function() {
            jQuery(this).parents('form').get(0).submit();
        })
    })

    var changedNum = 0;
    jQuery(document).on('focus', ".text-edit", function(event) {
        if (!jQuery(this).data('valueSaved')) {
            jQuery(this).data('valueSaved', true);
            jQuery(this).data('value', jQuery(this).prop('value'));
        }
    })

    jQuery(document).on('change', "select[name=language]", function(){
        this.form.submit();
    });

    jQuery(document).on('change', ".text-edit", function(event) {
        if (!jQuery(this).hasClass('changed')) {
            jQuery(this).addClass('changed');
            var aRevert = jQuery('<a href="#" class="text-edit-revert">{$revertIcon}</a>').attr('title', jQuery(this).data('value')).click(function(){
                input = jQuery(this).closest('.text-edit-wrapper').find('.text-edit');
                input.prop('value', input.data('value'));
                jQuery(this).remove();
                input.removeClass('changed');
                changedNum--;
                if (!changedNum && jQuery(".am-pagination").hasClass('hidden')) {
                    jQuery(".am-pagination").next().remove();
                    jQuery(".am-pagination").removeClass('hidden');
                    jQuery(".am-pagination").show();
                }
                return false;
            })
            changedNum++;
            jQuery(this).after(aRevert);
        }
        var aCancel = jQuery('<a href="javascript:;" class="local">$cancel_title</a>').click(function(){
            jQuery(".text-edit").filter(".changed").each(function(){
                 input = jQuery(this);
                 input.prop('value', input.data('value'));
                 input.next().remove();
                 input.removeClass('changed');
             })
             if (jQuery(".am-pagination").hasClass('hidden')) {
                 jQuery(".am-pagination").next().remove();
                 jQuery(".am-pagination").removeClass('hidden');
                 jQuery(".am-pagination").show();
             }
             changedNum = 0;
             return false;
        })

        aCancel = aCancel.wrap('<div class="trans-cancel"></div>').parents('div');

        if (jQuery(".am-pagination").css('display')!='none') {
            jQuery(".am-pagination").addClass('hidden')
            jQuery(".am-pagination").after(aCancel);
            jQuery(".am-pagination").hide();
        }
    })
})(jQuery)
CUT;
        return $jsScript;
    }
}