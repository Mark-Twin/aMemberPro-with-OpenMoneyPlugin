<?php

abstract class MediaController extends Am_Mvc_Controller
{
    protected $id;
    protected $media;
    protected $type;

    abstract function getFlowplayerParams(ResourceAbstractFile $media);
    abstract function getJWPlayerParams(ResourceAbstractFile $media);

    function getPlayerParams(ResourceAbstractFile $media)
    {
        $method = 'get'.$this->getDi()->config->get('video_player', 'Flowplayer').'Params';

        if(!method_exists($this, $method))
            throw Am_Exception_InternalError(sprintf('Method %s is not defined.', $method));

        return call_user_func(array($this, $method), $media);
    }

    function getPlayerConfig(ResourceAbstractFile $media)
    {
        $localConfig = array();

        if (!$media->config) {

        } elseif (substr($media->config, 0,6) == 'preset') {
            $presets = unserialize($this->getDi()->store->getBlob('flowplayer-presets'));
            $localConfig = $presets[$media->config]['config'];
        } else {
            $localConfig = unserialize($media->config);
        }

        $config = array_merge($this->getDi()->config->get('flowplayer', array()), $localConfig);
        return $config;

    }

    function getMedia()
    {
        if (!$this->media) {
            $this->id = $this->_request->getInt('id');
            if (!$this->id)
                throw new Am_Exception_InputError("Wrong URL - no media id passed");
            $this->media = $this->getDi()->videoTable->load($this->id, false);
            if (!$this->media)
                throw new Am_Exception_InputError("This media has been removed");
        }
        return $this->media;
    }

    function dAction()
    {
        $id = $this->_request->get('id');
        $this->validateSignedLink($id);
        $id = intval($id);
        $media = $this->getDi()->videoTable->load($id);
        set_time_limit(600);

        while (@ob_end_clean());
        $this->getDi()->session->writeClose();

        if ($path = $media->getFullPath()) {
            $this->_helper->sendFile($path, $media->getMime());
        } else
            $this->_response->redirectLocation($media->getProtectedUrl($this->getDi()->config->get('storage.s3.expire', 15) * 60));
    }

    function pAction()
    {
        $media = $this->getMedia();
        $view = $this->view;

        $view->meta_title = $media->meta_title ? $media->meta_title : $media->title;
        if ($media->meta_keywords)
            $view->headMeta()->setName('keywords', $media->meta_keywords);
        if ($media->meta_description)
            $view->headMeta()->setName('description', $media->meta_description);
        if ($media->meta_robots)
            $view->headMeta()->setName('robots', $media->meta_robots);
        $view->title = $media->title;
        $view->media_item = $media;
        $view->content =
            '<script type="text/javascript" id="am-' . $this->type . '-' . $this->id . '">' . "\n" .
            $this->renderJs() .
            "\n</script>";
        $view->display($media->tpl ? $media->tpl : 'layout.phtml');
    }

    function getSignedLink(ResourceAbstract $media)
    {
        $rel = $media->pk() . '-' . ($this->getDi()->time + 3600 * 24);
        return $this->getDi()->url(
            $this->type . '/d/id/' . $rel . '-' .
            $this->getDi()->security->siteHash('am-' . $this->type . '-' . $rel, 10),
            false,
            $this->getRequest()->isSecure() ? true : 2);
    }

    function validateSignedLink($id)
    {
        @list($rec_id, $time, $hash) = explode('-', $id, 3);
        if ($rec_id <= 0)
            throw new Am_Exception_InputError('Wrong media id#');
        if ($time < Am_Di::getInstance()->time)
            throw new Am_Exception_InputError('Media Link Expired');
        if ($hash != $this->getDi()->security->siteHash("am-" . $this->type . "-$rec_id-$time", 10))
            throw new Am_Exception_InputError('Media Link Error - Wrong Sign');
    }

    function renderJs()
    {
        if(!$this->getDi()->auth->getUserId())
            $this->getDi()->auth->checkExternalLogin($this->getRequest());

        $media = $this->getMedia();
        $config = $this->getPlayerConfig($media);
        $params = $this->getPlayerParams($media);
        $this->view->id = $this->id;
        $this->view->type = $this->type;
        $this->view->width = $this->_request->getInt('width', isset($params['width']) ? $params['width'] : 520);
        $this->view->height = $this->_request->getInt('height', isset($params['height']) ? $params['height'] : 330);
        unset($params['width']);
        unset($params['height']);

        $guestAccess = false;
        $guestAccess = $media->hasAccess(null);
        if (!$this->getDi()->auth->getUserId() && !$guestAccess) {
            try {
                if ($media->mime == 'audio/mpeg') throw new Exception; //skip it for audio files
                $m = $this->getDi()->videoTable->load($this->getDi()->config->get('video_non_member'));
                $media = $m;
                $this->view->media = $this->getSignedLink($m);
                $this->view->mime = $m->mime;
            } catch (Exception $e) {
                $this->view->error = ___("You must be logged-in to open this media");
                $this->view->link = $this->getDi()->url("login",null,false);
            }
        } elseif (!$guestAccess && !$media->hasAccess($this->getDi()->user)) {
            try {
                if ($media->mime == 'audio/mpeg') throw new Exception; //skip it for audio files
                $m = $this->getDi()->videoTable->load($this->getDi()->config->get('video_not_proper_level'));
                $media = $m;
                $this->view->media = $this->getSignedLink($m);
                $this->view->mime = $m->mime;
            } catch (Exception $e) {
                $this->view->error = ___("Your subscription does not allow access to this media");
                if(!empty($media->no_access_url))
                    $this->view->link = $media->no_access_url;
                else
                    $this->view->link = $this->getDi()->url('no-access/content',
                        array('id'=>$media->pk(),'type'=> $media->getTable()->getName(true)));
            }
        } else {
            $this->view->media = $this->getSignedLink($media);
            $this->view->mime = $media->mime;
        }

        if ($poster_id = $media->poster_id ?: (isset($config['poster_id']) ? $config['poster_id'] : "")) {
            $poster = $this->getDi()->uploadTable->load($poster_id, false);
            $this->view->poster = $poster ? $this->getDi()->url('upload/get/' . preg_replace('/^\./', '', $poster->path),null,false) : '';
        } else {
            $this->view->poster = '';
        }
        unset($params['poster_id']);
        $this->view->playerParams = $params;
        $this->view->isSecure = $this->getRequest()->isSecure();
        return $this->view->render('_media.'.  strtolower($this->getDi()->config->get('video_player', 'flowplayer')).'.phtml');
    }

    function jsAction()
    {
        $this->_response->setHeader('Content-type', 'text/javascript');
        $this->getMedia();
        echo $this->renderJs();
    }
}