<?php

abstract class ResourceAbstractFile extends ResourceAbstract
{
    /** @var Upload */
    private $_upload;
    /** @var Am_Storage_Item */
    protected $_storageItem;

    function getDisplayFilename()
    {
        return $this->isLocal() ?
            (($upload = $this->getUpload()) ? $upload->name : basename($this->getFullPath())) :
            basename($this->path);
    }

    /** @return Am_Storage_File */
    protected function getStorageFile()
    {
        if (!$this->_storageItem)
            $this->_storageItem = $this->getDi()->plugins_storage->getFile($this->path);
        return $this->_storageItem;
    }

    function isLocal()
    {
        return (bool)$this->getStorageFile()->getLocalPath();
    }

    function getStorageId()
    {
        return $this->getStorageFile()->getStorageId();
    }

    function getProtectedUrl($exptime, $force_download = true)
    {
        return $this->getStorageFile()->getUrl($exptime, $force_download);
    }

    function getFullPath()
    {
        return $this->getStorageFile()->getLocalPath();
    }

    /** @return Upload|null */
    function getUpload()
    {
        if ($this->_upload && $this->_upload->upload_id == $this->path)
            return $this->_upload;
        return ($this->path > 0) ? $this->_upload = $this->getDi()->uploadTable->load($this->path) : null;
    }

    function isExists()
    {
        return file_exists($this->getFullPath());
    }

    function getMime()
    {
        return $this->mime ? $this->mime : 'application/octet-stream';
    }

    function getSize()
    {
        return $this->isLocal() ? filesize($this->getFullPath()) : @$this->size;
    }

    function getName()
    {
        $upload = $this->getUpload();
        return $upload ? $upload->getName() : $this->title;
    }
}