<?php

/**
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Affiliate management routines
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class Aff_AffController extends Am_Mvc_Controller
{
    function init()
    {
        if (!class_exists('Am_Form_Brick', false)) {
            class_exists('Am_Form_Brick', true);
            Am_Di::getInstance()->hook->call(Am_Event::LOAD_BRICKS);
        }
        parent::init();
    }

    public function preDispatch()
    {
        $this->getDi()->auth->requireLogin($this->getDi()->url('aff/aff', null, false));
        if ($this->getRequest()->getActionName() != 'enable-aff')
            if (!$this->getDi()->user->is_affiliate)
                $this->_redirect('member');
    }

    public function indexAction()
    {
        return $this->linksAction();
    }

    public function linksAction()
    {
        $catActive = $this->getParam('c', null);
        $affBanners = $this->getDi()->affBannerTable->findActive($catActive);

        $user_group_ids = $this->getDi()->user->getGroups();
        foreach ($affBanners as $k => $v) {
            if ($v->user_group_id && !array_intersect($user_group_ids, explode(',', $v->user_group_id)))
                unset($affBanners[$k]);
        }

        $affDownloads = $catActive ? null : $this->getDi()->uploadTable->findByPrefix('affiliate');

        if ($catActive) {
            $this->view->getHelper('breadcrumbs')->setPath(array(
                $this->getDi()->url('aff/aff') => ___('Affiliate info'),
                $catActive));
        }

        $this->view->assign('intro', $this->getModule()->getConfig('intro'));
        $this->view->assign('canUseCustomRedirect', $this->canUseCustomRedirect($this->getDi()->user));
        $this->view->assign('catActive', $catActive);
        $this->view->assign('category', $this->getDi()->affBannerTable->getCategories(true));
        $this->view->assign('generalLink', $this->getModule()->getGeneralAffLink($this->getDi()->auth->getUser()));
        $this->view->assign('affDownloads', $affDownloads);
        $this->view->assign('affBanners', $affBanners);
        $this->view->display('aff/links.phtml');
    }

    public function enableAffAction()
    {
//        if ($this->getDi()->config->get('aff.signup_type') == 2) {
//            throw new Am_Exception_AccessDenied('Signup disabled in config');
//        }
        //backwards
        $this->_redirect('aff/signup');
    }

    public function statsAction()
    {
        $this->_forward('stats', 'member');
    }

    //lowercase becuase of ?action=payout_info does not work with payoutInfoAction
    public function payoutinfoAction()
    {
        $this->_forward('payout-info', 'member');
    }

    public function clinkAction()
    {
        $user = $this->getDi()->auth->getUser();
        if ($this->canUseCustomRedirect($user)) {
            $url = $this->getModule()->getRedirectUrl($this->getParam('url', ''));
            if (!$url) {
                $link = $this->getModule()->getTrackingLink($user);
            } else {
                if ($this->getDi()->config->get('aff.tracking_code') && $this->pageHaveTrackingCode($url)) {
                    $link = $this->getRefLink($user->login, $url);
                } else {
                    $link = $this->getModule()->getCustomTrackingLink($user, $url);
                }
            }
        } else {
            $link = $this->getModule()->getTrackingLink($user);
        }
        $this->_response->ajaxResponse(array(
            'link' => html_entity_decode($link),
        ));
    }

    protected function canUseCustomRedirect(User $user)
    {
        $cr = $this->getModule()->getConfig('custom_redirect');
        return ($cr == Bootstrap_Aff::AFF_CUSTOM_REDIRECT_ALLOW_SOME_DENY_OTHERS && $user->aff_custom_redirect) ||
        ($cr == Bootstrap_Aff::AFF_CUSTOM_REDIRECT_DENY_SOME_ALLOW_OTHERS && !$user->aff_custom_redirect);
    }

    /**
     * Check whereever given url have tracking code included
     * @param type $url
     */
    function pageHaveTrackingCode($url)
    {
        $req = new Am_HttpRequest($url, Am_HttpRequest::METHOD_GET);
        try {
            $resp = $req->send();
        } catch (Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
            return false;
        }
        if (strpos($resp->getBody(), "id='am-ctcs-v1'") !== false)
            return true;

        return false;
    }

    function getRefLink($login, $url)
    {
        $url = parse_url($url);
        parse_str(@$url['query'], $query);
        $query['ref'] = $login;
        $url['query'] = http_build_query($query);
        return sprintf("%s://%s%s%s", $url['scheme'], $url['host'], $url['path'] ? $url['path'] : '/', $url['query'] ? "?" . $url['query'] : '');
    }
}