<?php

class NoAccessController extends Am_Mvc_Controller
{
    function liteAction()
    {
        $this->view->accessObjectTitle = $this->getParam('title', ___('protected area'));
        $this->view->orderUrl = $this->url('signup', false);
        $this->view->useLayout = true;
        $this->view->display('no-access.phtml');
    }

    function folderAction()
    {
        if (!$id = $this->getParam('id')) {
            throw new Am_Exception_InputError("Empty folder#");
        }
        $folder = $this->getDi()->folderTable->load($id);

        // Check if login cookie exists. If not, user is not logged in and should be redirected to login page.
        $pl = $this->getDi()->plugins_protect->loadGet('new-rewrite');

        // User will be there only if file related to folder doesn't exists.
        // So if main file exists, this means that user is logged in but don't have an access.
        // If main file doesn't exists, redirect user to new-rewrite in order to recreate it.
        // Main file will be created even if user is not active.

        if (is_file($pl->getFilePath($pl->getEscapedCookie())))
        {
            if ($folder->no_access_url) {
                Am_Mvc_Response::redirectLocation($folder->no_access_url);
            } else {
                $this->view->accessObjectTitle = ___("Folder %s (%s)", $folder->title, $folder->url);
                $this->view->orderUrl = $this->url('signup', false);
                $this->view->useLayout = true;
                $this->view->display('no-access.phtml');
            }
        } else {
            Am_Mvc_Response::redirectLocation($this->url('protect/new-rewrite', array(
                    'f' => $id,
                    'url' => $this->getParam('url', $folder->getUrl()),
                ), false));
        }
    }

    function contentAction()
    {
        if (!$id = $this->getParam('id')) {
            throw new Am_Exception_InputError("Empty folder#");
        }
        $type = $this->getParam('type');

        $regestry = $this->getDi()->resourceAccessTable->getAccessTables();
        if (isset($regestry[$type]) && ($r = $regestry[$type]->load($id, false))) {
            $title = ___($r->getLinkTitle());
        } else {
            $title = ___("Protected Content [%s-%d]", $type, $id);
        }
        $this->view->accessObjectTitle = $title;
        $this->view->orderUrl = $this->url('signup', false);
        $this->view->useLayout = !$this->getRequest()->isXmlHttpRequest();
        $this->view->display('no-access.phtml');
    }
}