<?php

class ContentController extends Am_Mvc_Controller
{
    /** @access private for unit testing */
    public function _setHelper($v)
    {
        $this->_helper->addHelper($v);
    }

    /**
     * Serve file download
     */
    function fAction()
    {
        set_time_limit(0);
        $f = $this->loadWithAccessCheck($this->getDi()->fileTable, $this->getInt('id'));
        // download limits works for user access only and not for guest access
        if ($this->getDi()->auth->getUserId())
        {
            if (!$this->getDi()->fileDownloadTable->checkLimits($this->getDi()->auth->getUser(), $f)) {
                throw new Am_Exception_AccessDenied(___('Download limit exceeded for this file'));
            }
            $this->getDi()->fileDownloadTable->logDownload($this->getDi()->auth->getUser(), $f, $this->getRequest()->getClientIp());
        }
        if ($path = $f->getFullPath())
        {
            @ini_set('zlib.output_compression', 'Off'); // for IE
            $this->_helper->sendFile($path, $f->getMime(),
                array(
                    'filename' => $f->getDisplayFilename(),
                    'file_id' => $f->pk()
                ));
        } else {
            Am_Mvc_Response::redirectLocation($f->getProtectedUrl(600));
        }
    }

    /**
     * Display saved page
     */
    function pAction()
    {
        $page = ($path = $this->getParam('path')) ?
            $this->getDi()->pageTable->findFirstByPath($path):
            null;

        $p = $this->loadWithAccessCheck($this->getDi()->pageTable, $page ? $page->pk() : $this->getInt('id'));
        if ($this->getDi()->auth->getUserId() && ($mp = $this->getDi()->navigationUser->findOneById("page-{$p->pk()}"))) {
            $mp->setActive(true);
        }
        echo $p->render($this->view,
            $this->getDi()->auth->getUserId() ? $this->getDi()->auth->getUser() : null,
            !$this->getRequest()->isXmlHttpRequest());
    }

    /**
     * Display allowed content within category
     */
    function cAction()
    {
        if (!$this->getDi()->auth->getUserId()) {
            $this->_redirect('login?amember_redirect_url=' . urlencode($this->_request->assembleUrl(false,true)));
        }
        if ($this->getDi()->config->get('disable_resource_category'))
            throw new Am_Exception_InternalError("Resource categories are disabled");
        /* @var $cat ResourceCategory */
        $cat = $this->getDi()->resourceCategoryTable->load($this->getParam('id'));
        if ($this->getParam('title') != $cat->title) {
            throw new Am_Exception_InputError;
        }

        $_ = $cat->getAllowedResources($this->getDi()->user);
        $perpage = $this->getDi()->config->get('resource_category_records_per_page', 15);

        $this->view->showSearch = count($_) > $perpage;
        $this->view->cq = $this->getParam('cq');

        if ($q = $this->getParam('cq')) {
            foreach ($_ as $k => $el) {
                if (stripos($el->title, $q) === false) {
                    unset($_[$k]);
                }
            }
        }

        $paginator = new Am_Paginator(ceil(count($_)/$perpage),
            $this->getRequest()->getParam('cp', 0), null, 'cp', $this->getRequest());

        $this->view->paginator = $paginator;
        $this->view->resources = array_slice($_, $this->getRequest()->getParam('cp', 0) * $perpage, $perpage);
        $this->view->category = $cat;
        $this->view->display('member/category.phtml');
    }

    function loadWithAccessCheck(ResourceAbstractTable $table, $id)
    {
        if ($id<=0)
            throw new Am_Exception_InputError(___('Wrong link - no id passed'));

        $p = $table->load($id);
        if (!$this->getDi()->auth->getUserId()) // not logged-in
        {
            if ($p->hasAccess(null)) { // guest access allowed?
                return $p;           // then process
            }
            $this->_redirect('login?amember_redirect_url=' . urlencode($this->_request->assembleUrl(false, true)));
        }
        if (!$p->hasAccess($this->getDi()->user))
        {
            Am_Mvc_Response::redirectLocation($p->no_access_url ?: $this->url('no-access/content', array('id' => $id, 'type' => $table->getName(true)), false));
        }

        return $p;
    }
}