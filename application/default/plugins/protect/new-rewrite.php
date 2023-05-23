<?php

class Am_Protect_NewRewrite extends Am_Protect_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';
    const NR_COOKIE = 'amember_nr';

    function getFilePath($cookie, $folder_id = null)
    {
        $file = $cookie;
        if ($folder_id) $file .= '-' . $folder_id;
        return $this->getDi()->data_dir . '/new-rewrite/' . $file;
    }

    function createFile($fn)
    {
        if (@file_put_contents($fn , "") === false)
            throw new Am_Exception_InternalError("Cannot create file [$fn] in " . __METHOD__);
    }

    function getEscapedCookie()
    {
        if (empty($_COOKIE[self::NR_COOKIE]))
            return null;
        $c = preg_replace('/[^a-zA-Z0-9]/', '', $_COOKIE[self::NR_COOKIE]);
        return strlen($c) ? $c : null;
    }

    function onAuthAfterLogin(Am_Event_AuthAfterLogin $event)
    {
        /** @var User */
        $user = $event->getUser();
        $cookie = $this->getEscapedCookie();
        if (!$cookie)
        {
            $cookie = md5(rand() . $user->login);
            Am_Cookie::set(self::NR_COOKIE, $cookie, 0, '/',
                $this->getDi()->request->getHttpHost(), false, false, true);
            $_COOKIE[self::NR_COOKIE] = $cookie;
        }
        // Create "main" file even if user is not active.
        // This file will be checked in NoAccessController if user doesn't have access to folder.
        // (in order to check is user logged in or not)
        $this->createFile($this->getFilePath($cookie));

        foreach ($this->getDi()->resourceAccessTable->getAllowedResources($user, ResourceAccess::FOLDER) as $f)
        {
            $this->createFile($this->getFilePath($cookie, $f->pk()));
        }
    }

    function onAuthAfterLogout(Am_Event_AuthAfterLogout $event)
    {
        $this->deleteCookieFiles();
        Am_Cookie::set(self::NR_COOKIE, null, time() - 36000, '/',
                $this->getDi()->request->getHttpHost(), false, false, true);
    }

    function deleteCookieFiles()
    {
        $c = $this->getEscapedCookie();
        if (!$c)
            return;
        $dirname = $this->getDi()->data_dir . '/new-rewrite';
        foreach ((array)glob("$dirname/$c*") as $f)
            if (strlen($f))
                @unlink($f);
    }

    function onDaily()
    {
        $d = opendir($dirname = $this->getDi()->data_dir . "/new-rewrite");
        if (!$d)
            return;
        while ($f = @readdir($d))
        {
            if ($f[0] == '.')
                continue;
            if ($f == '_vti_cnf')
                continue;
            if ($f == 'readme.txt')
                continue;
            if ((time() - @filectime("$dirname/$f")) > 3 * 3600)
                @unlink("$dirname/$f");
        }
        closedir($d);
    }

    public function needRefresh(User $user){
        // logout and login, just to be sure
        $this->deleteCookieFiles();
        $event = new Am_Event_AuthAfterLogin($user);
        $this->onAuthAfterLogin($event);
    }

    public function onAuthSessionRefresh(Am_Event_AuthSessionRefresh $event)
    {
        $this->needRefresh($event->getUser());
    }

    public function onThanksPage(Am_event $e)
    {
        if ($this->getDi()->auth->getUserId()) {
            $this->needRefresh($this->getDi()->auth->getUser());
        }
    }

    public function directAction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($url = $request->get('url'))
            $url = urldecode($url);

        if ($request->get('host') && $request->get('ssl'))
        {
            $url = (!strcasecmp($request->get('ssl'), 'on') ? 'https://' : 'http://') .
                $request->get('host') .
                $url;
            $request->set('url',$url);
        }
        // if user is logged in and went here, something is definitely wrong
        if ($this->getDi()->auth->getUserId())
        {
            $this->needRefresh($this->getDi()->auth->getUser());
            if(!parse_url($url, PHP_URL_SCHEME))
            {
                $url = sprintf('%s://%s%s', $request->isSecure()?'https':'http', $request->getHttpHost(),
                $url);
            }

            $response->redirectLocation($url);
            return;
        }

        require_once AM_APPLICATION_PATH . '/default/controllers/LoginController.php';
        $c = new LoginController($request, $response, $invokeArgs);
        $c->setRedirectUrl(Am_Html::escape($url));
        $c->run();
    }

    public function getPasswordFormat()
    {
        return null;
    }
}