<?php

class_exists('Am_Form', true);

class UploadController extends Am_Mvc_Controller
{
    public function getAction()
    {
        if ($path = $this->getParam('path')) {
            if ($path[0]!='.') {$path = '.' . $path;}
            $upload = $this->getDi()->uploadTable->findFirstByPath($path);
        } else {
            $upload = $this->getDi()->uploadTable->load($this->getParam('id'));
        }

        if (!$upload) {
            throw new Am_Exception_InputError(
                'Can not fetch file for id: ' . $this->getParam('id')
            );
        }

        if (!$this->getDi()->uploadAcl->checkPermission($upload,
                Am_Upload_Acl::ACCESS_READ,
                $this->getDi()->auth->getUser())) {
            throw new Am_Exception_AccessDenied();
        }

        $this->_helper->sendFile($upload->getFullPath(), $upload->getType(),
            array(
                //'cache'=>array('max-age'=>3600),
                'filename' => $upload->getName(),
        ));
        exit;
    }

    public function uploadAction()
    {
        if (!$this->getDi()->uploadAcl->checkPermission($this->getParam('prefix'),
                Am_Upload_Acl::ACCESS_WRITE,
                $this->getDi()->auth->getUser())) {
            throw new Am_Exception_AccessDenied();
        }

        $secure = $this->getParam('secure', false);

        $upload = new Am_Upload($this->getDi());
        $upload->setPrefix($this->getParam('prefix'));
        $upload->processSubmit('upload', false);
        //find currently uploaded file
        list($file) = $upload->getUploads();

        try {
            $this->getResponse()->ajaxResponse(array(
                'ok' => true,
                'name' => $file->getName(),
                'size_readable' => $file->getSizeReadable(),
                'upload_id' => $secure ?  Am_Form_Element_Upload::signValue($file->pk()) : $file->pk(),
                'mime' => $file->mime
            ));
        } catch (Am_Exception $e) {
            $this->getResponse()->ajaxResponse(array(
                'ok' => false,
                'error' => ___('No files uploaded'),
            ));
        }
    }
}