<?php

abstract class Am_BannerRenderer
{
    protected $affBanner = null;
    protected $aff = null;

    public function __construct(AffBanner $affBanner, User $aff)
    {
        $this->affBanner = $affBanner;
        $this->aff = $aff;
    }

    public static function create(AffBanner $affBanner, User $aff)
    {
        switch ($affBanner->type) {
            case AffBanner::TYPE_TEXTLINK :
                return new Am_BannerRenderer_TextLink($affBanner, $aff);
            case AffBanner::TYPE_BANNER :
                return new Am_BannerRenderer_Banner($affBanner, $aff);
            case AffBanner::TYPE_LIGHTBOX :
                return new Am_BannerRenderer_Lightbox($affBanner, $aff);
            case AffBanner::TYPE_CUSTOM :
                return new Am_BannerRenderer_Custom($affBanner, $aff);
            default:
                throw new Am_Exception_InternalError('Can not instantiate banner with type : ' . $affBanner->type);
        }
    }

    abstract protected  function _getCode();

    public function getCode()
    {
        $w = json_encode($this->_getCode());
        return <<<CUT
(function(){
    var data = $w;
    document.write(data);
})();
CUT;
    }

    public function getUrl()
    {
        return Am_Di::getInstance()->modules->get('aff')->getTrackingLink($this->aff, $this->affBanner->pk());
    }
}

class Am_BannerRenderer_TextLink extends Am_BannerRenderer
{
    protected function _getCode()
    {
        return sprintf('<a href="%s" rel="nofollow" target="%s">%s</a>',
            $this->getUrl(),
            $this->affBanner->is_blank ? "_blank" : "_top",
            $this->affBanner->title
        );
    }
}

class Am_BannerRenderer_Custom extends Am_BannerRenderer
{
    protected function _getCode()
    {
        return str_replace('%url%', $this->getUrl(), $this->affBanner->html);
    }
}

class Am_BannerRenderer_Banner extends Am_BannerRenderer
{
    protected function _getCode()
    {
        $upload = Am_Di::getInstance()->uploadTable->load($this->affBanner->upload_id);

        return sprintf('<a href="%s" rel="nofollow" target="%s"><img src="%s" border=0 alt="%s" %s></a>',
            $this->getUrl(),
            $this->affBanner->is_blank ? "_blank" : "_top",
            Am_Di::getInstance()->surl(array('file/get/path/%s/i/%d', preg_replace('/^\./', '', $upload->getPath()), $this->aff->pk())),
            Am_Html::escape($this->affBanner->title),
            ($this->affBanner->width ? sprintf('width="100%%" style="max-width:%dpx"', $this->affBanner->width) : '')
        );
    }
}

class Am_BannerRenderer_Lightbox extends Am_BannerRenderer
{
    protected function _getCode()
    {
        $upload = Am_Di::getInstance()->uploadTable->load($this->affBanner->upload_id);
        $upload_big = Am_Di::getInstance()->uploadTable->load($this->affBanner->upload_big_id);
        return sprintf('
            <a href="%s" class="am-lightbox" rel="nofollow" rev="%s" title="%s" data-target="%s"><img src="%s" border=0 alt="%s"></a>',
            Am_Di::getInstance()->surl(array('file/get/path/%s/i/%d',preg_replace('/^\./', '', $upload_big->getPath()), $this->aff->pk())),
            $this->getUrl(),
            $this->affBanner->title,
            $this->affBanner->is_blank ? "_blank" : "_top",
            Am_Di::getInstance()->surl(array('file/get/path/%s/i/%d',preg_replace('/^\./', '', $upload->getPath()), $this->aff->pk())),
            Am_Html::escape($this->affBanner->title)
        );
    }

    public function getCode()
    {
        $url = ROOT_URL;
        $a_url = $url . '/application/aff/views/public/img/';
        $l_url = $url . '/application/aff/views/public/js/jquery.lightbox.js';
        $s_url = $url . '/application/aff/views/public/css/jquery.lightbox.css';
        $w = json_encode($this->_getCode());
        return <<<CUT
(function(){
    if (!window.amLightboxInit) {
        var link=document.createElement("link");
        link.setAttribute("rel", "stylesheet");
        link.setAttribute("type", "text/css");
        link.setAttribute("media", "screen");
        link.setAttribute("href", "$s_url");
        document.getElementsByTagName("head")[0].appendChild(link);

        var scriptObj = document.createElement("script");
        scriptObj.src = "$l_url";
        scriptObj.type = "text/javascript";
        var head=document.getElementsByTagName("head")[0];
        head.insertBefore(scriptObj,head.firstChild);

        window.am_url = "$a_url";
        window.am_lightbox = {
            imageLoading : am_url + "/lightbox-ico-loading.gif",
            imageBtnClose : am_url + "/lightbox-btn-close.gif",
            imageBlank : am_url + "/lightbox-blank.gif"
        };
        window.amLightboxInit = true;
    }
    var data = $w;
    document.write(data);
    if (!window.amInterval) {
        window.amInterval = setInterval(function(){
            if ((typeof jQuery != "undefined") &&
                typeof jQuery.fn.lightBox != "undefined") {
                jQuery('.am-lightbox').lightBox(am_lightbox);
                clearInterval(window.amInterval);
                window.amInterval = false;
            }
        }, 1000);
    }
})();
CUT;
    }
}

class Aff_BannerController extends Am_Mvc_Controller
{
    public function indexAction()
    {
        if (($id = $this->getDi()->security->reveal($this->getParam('code'))) &&
            $banner = $this->getDi()->affBannerTable->load($id, false)) {

            $affiliate = $this->getParam('affiliate');
            $bannerRenderer = Am_BannerRenderer::create($banner, $this->getDi()->userTable->findFirstByLogin($affiliate));
            header('Content-type: ' . Upload::getMimeType('js'));
            echo $bannerRenderer->getCode();
        }
        exit();
    }
}