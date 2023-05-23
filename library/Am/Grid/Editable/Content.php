<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: LGPL http://www.gnu.org/copyleft/lesser.html
*/

abstract class Am_Grid_Editable_Content extends Am_Grid_Editable
{
    public function __construct(Am_Mvc_Request $request, Am_View $view)
    {
        $adapter = $this->joinSort($this->createAdapter());
        parent::__construct('_'.$this->getContentGridId(), $this->getTitle(), $adapter, $request, $view);
        $this->setEventId('gridContent' . ucfirst($this->getContentGridId()));

        $this->addCallback(self::CB_AFTER_INSERT, array($this, 'afterInsert'));
        $this->addCallback(self::CB_AFTER_UPDATE, array($this, 'afterInsert'));

        $this->addCallback(self::CB_VALUES_TO_FORM, array($this, '_valuesToForm'));
        foreach ($this->getActions() as $action)
            $action->setTarget('_top');
    }

    /**
     * Add join to resource_access_sort and sort field
     * to be present in the result
     */
    protected function joinSort(Am_Query $q)
    {
        $q->getTable()->addDefaultSort($q);
        return $q;
    }

    public function getTitle()
    {
        return ___(ucfirst($this->getContentGridId()));
    }

    public function getContentGridId()
    {
        $id = preg_split('#[\\\\_]#', get_class($this));
        $id = strtolower(array_pop($id));
        return $id;
    }

    function renderAccessTitle(ResourceAbstract $r)
    {
        $title = $this->escape($r->title);
        if (!empty($r->hide))
            $title = "<span class='disabled-text'>$title</span>";
        return $this->renderTd($title, false);
    }

    protected function initGridFields()
    {
        $this->addField(new Am_Grid_Field_Expandable('_access', ___('Products'), false))
            ->setPlaceholder(array($this, 'getPlaceholder'))
            ->setRenderFunction(array($this, 'renderProducts'))
            ->setGetFunction(array($this, 'getProducts'))
            ->setMaxLength(200);
        $this->addField('_link', ___('Link'), false)->setRenderFunction(array($this, 'renderLink'));
        $this->actionAdd(new Am_Grid_Action_SortContent());
        $this->actionAdd(new Am_Grid_Action_Group_ContentChangeOrder);
        parent::initGridFields();
    }

    public function renderLink(ResourceAbstract $resource)
    {
        $html = "";
        $url = $resource->getUrl();
        if (!empty($url))
            $html = sprintf('<a href="%s" target="_blank" class="link">%s</a>',
                $this->escape($url), ___('link'));
        return $this->renderTd($html, false);
    }

    public function renderProducts($obj, $fieldName, $controller, $field)
    {
        return $this->renderTd($field->get($obj, $controller), false);
    }

    public function renderCategory(ResourceAbstract $e)
    {
        $res = array();
        $options = $this->getDi()->resourceCategoryTable->getOptions();
        foreach ($e->getCategories() as $resc_id)
        {
            $res[] = $options[$resc_id];
        }
        return $this->renderTd(implode(", ", $res));
    }

    public function getProducts(ResourceAbstract $resource)
    {
        $s = "";
        foreach ($resource->getAccessList() as $access) {
            $l = "";
            if ($access->getStart())
                $l .= " from " . $access->getStart();
            if ($access->getStop())
                $l .= " to " . $access->getStop();
            $s .= sprintf("%s <b>%s</b> %s<br />\n", $access->getClassTitle(), $access->getTitle(), $l);
        }
        return $s;
    }

    public function getPlaceholder($val, ResourceAbstract $resource)
    {
        return ___('%d access records&hellip;', count($resource->getAccessList()));
    }

    public function afterInsert(array & $values, ResourceAbstract $record)
    {
        $record->setAccess($values['_access']);
    }

    public function _valuesToForm(array & $values, Am_Record $record)
    {
        $values['_access'] = $this->getRecord()->getAccessList();
    }

    public function renderPath(ResourceAbstractFile $file)
    {
        $upload = $file->getUpload();

        try{
            $file->isLocal();
        } catch (Exception $e) {
            if (!$upload)
                return $this->renderTd(
                    '<span class="error">' . ___('The file has been removed from disk or corrupted. Please re-upload it.') . '</span>' .
                    ' <span style="color: grey">(' . ___('Error from Storage Engine') . ': ' . $this->escape($e->getMessage()) . ')</span>' .
                    '<br />' . ___('Real Path') . ': ' . $this->escape($file->path), false);
        }

        return $upload && !file_exists($upload->getFullPath()) ?
            $this->renderTd(
                '<div class="reupload-conteiner-hide"><span class="error">' . ___('The file has been removed from disk or corrupted. Please re-upload it.') . '</span>'.
                '<div class="reupload-conteiner"><span class="upload-name">' . $this->escape($upload->getName() . '/' . $upload->getFilename()) . '</span><br />' .
                '<div><span class="reupload" data-upload_id="' . $upload->pk() . '"  data-return-url="' . $this->escape($this->makeUrl()) . '" id="reupload-' . $upload->pk() . '"></span></div></div></div>', false) :
            $this->renderTd(sprintf('%s <span style="color:grey">(%s)</span>',
                    $this->escape($file->getDisplayFilename()), $file->getStorageId()), false);
    }

    protected function getTemplateOptions()
    {
        $folders = array(
            AM_APPLICATION_PATH . '/default/views/' => 1,
            AM_APPLICATION_PATH . '/default/themes/' . $this->getDi()->config->get('theme') => 2,
        );
        $ret = array(
            null => 'layout.phtml'
        );
        foreach (array_keys($folders) as $f) {
            foreach ((array) glob($f . '/layout*.phtml') as $file) {
                if (!strlen($file))
                    continue;
                $file = basename($file);
                if ($file == 'layout.phtml')
                    continue;
                $ret[$file] = $file;
            }
        }
        return $ret;
    }

    protected function addCategoryToForm($form)
    {
        if (!$this->getDi()->config->get('disable_resource_category'))
        {
            $form->addCategory('_category', null, array(
                'base_url' => 'admin-resource-categories',
                'link_title' => ___('Edit Categories'),
                'title' => ___('Content Categories'),
                'options' => Am_Di::getInstance()->resourceCategoryTable->getOptions()))
            ->setLabel(___("Content Categories\n" .
                "these categories will be shown in user's menu if user has access to " .
                "resources in this category. You can uses these categories to organize " .
                "your content by pages"));
        }
    }
}