<?php

class_exists('Am_Form_Setup_Standard', true);

class AdminPlayerConfigController extends Am_Mvc_Controller
{

    public function checkAdminPermissions(Admin $admin)
    {
        return true;
    }

    public function editAction()
    {
        $form = $this->createForm();

        $config = $this->getRequest()->getParam('config');
        $config = $config ? unserialize($config) : $this->getDi()->config->get('flowplayer', array());


        $form->setDataSources(array(
            new HTML_QuickForm2_DataSource_Array(
                $config
            )
        ));

        echo (string) $form;
    }

    function updateAction()
    {
        $form = $this->createForm();
        $form->setDataSources(array(
            new HTML_QuickForm2_DataSource_SuperGlobal('GET')
        ));

        $val = $this->filterValues($form->getValue());

        if ($this->getRequest()->getParam('_id') != '--custom--')
        {
            $this->presetUpdate($this->getRequest()->getParam('_id'), $val);
        }

        echo serialize($val);
    }

    protected function presetUpdate($id, $val)
    {

        $presets = $this->getDi()->store->getBlob('flowplayer-presets');
        $presets = @unserialize($presets) ? unserialize($presets) : array();

        $presets[$id]['config'] = $val;

        $this->getDi()->store->setBlob('flowplayer-presets', serialize($presets));
    }

    protected function filterValues($values)
    {
        foreach ($values as $k => $v)
        {
            if ($k[0] == '_')
                unset($values[$k]);
        }
        return $values;
    }

    function presetSaveAction()
    {
        $presets = $this->getDi()->store->getBlob('flowplayer-presets');
        $presets = @unserialize($presets) ? unserialize($presets) : array();

        $id = 'preset-' . mktime();

        $presets[$id] = array(
            'name' => $this->getRequest()->getParam('name'),
            'config' => unserialize($this->getRequest()->getParam('config'))
        );

        $this->getDi()->store->setBlob('flowplayer-presets', serialize($presets));

        $this->_response->ajaxResponse(array(
            'id' => $id,
            'name' => $this->getRequest()->getParam('name'),
            'config' => $this->getRequest()->getParam('config')
        ));
    }

    function presetDeleteAction()
    {

        $id = $this->getRequest()->getParam('_id');
        if (!$id) throw new Am_Exception_InputError("_id is note defined in request");

        $presets = $this->getDi()->store->getBlob('flowplayer-presets');
        $presets = @unserialize($presets) ? unserialize($presets) : array();

        if (!isset($presets[$id])) throw new Am_Exception_InputError(sprintf('Can not find preset with id [%s]', $id));

        $config = serialize($presets[$id]['config']);
        foreach ($this->getDi()->videoTable->findByConfig($id) as $video) {
            $video->config = $config;
            $video->save();
        }

        unset($presets[$id]);
        $this->getDi()->store->setBlob('flowplayer-presets', serialize($presets));

        $this->_response->ajaxResponse(array(
            'id' => $id,
            'config' => $config
        ));
    }

    function presetAction()
    {
        $form = new Am_Form_Admin('player-config-preset');
        $form->addElement('text', 'name')
            ->setLabel(___('Name of Preset'));
        echo (string) $form;
    }

    /**
     *
     * @return Am_Form_Admin 
     */
    function createForm()
    {
        $form = new Am_Form_Admin('player-config');

        $setupForm = new Am_Form_Setup_VideoPlayer();
        $setupForm->setupElements($form);

        return $form;
    }

}

