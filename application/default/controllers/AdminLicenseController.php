<?php

class Am_Form_Admin_FixLicense extends Am_Form_Admin
{
    function init()
    {
        $this->addText('root_url', array('class' => 'el-wide'))
            ->setLabel(___("Root URL\nroot script URL, usually %s", '<i>http://www.yoursite.com/amember</i>'))
            ->addRule('callback2', '-error-must-be-returned-', array($this, 'validateRootUrl'));

        $this->addText('root_surl', array('class' => 'el-wide'))
            ->setLabel(___("Secure Root URL\nsecure URL, usually %s", '<i>http<b>s</b>://www.yoursite.com/amember</i>'))
            ->addRule('callback2', '-error-must-be-returned-', array($this, 'validateRootUrl'));

        $this->addAdvCheckbox('force_ssl')
            ->setLabel(___("Force https Connection\n" .
                    "redirect all request to https"))
            ->addRule('callback2', '-error-must-be-returned-', array($this, 'validateSsl'));

        $check_url = json_encode(Am_Di::getInstance()->url('admin-license/check', false));

        $this->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    $('[name=root_surl]').change(function(){
        var surl = $(this).val();
        $('[name=force_ssl]').prop('disabled', 'disabled');
        $('[name=force_ssl]').closest('div.row').addClass('disabled');
        if (/^https:/.exec(surl)) {
            $.get($check_url, {root_surl:surl}, function(resp){
                if (resp) {
                    $('[name=force_ssl]').prop('disabled', null);
                    $('[name=force_ssl]').closest('div.row').removeClass('disabled');
                    if ($('[name=force_ssl]:checked').length) {
                        $('[name=root_url]').val($('[name=root_surl]').val());
                    }
                } else {
                    $('[name=force_ssl]').prop('checked', null).change();
                }
            })
        }
    }).change();
    $('[name=force_ssl]').change(function(){
        if (this.checked) {
            $('[name=root_url]').val($('[name=root_surl]').val());
            $('[name=root_url]').closest('div.row').hide();
        } else {
            $('[name=root_url]').closest('div.row').show();
        }
    }).change();
})
CUT
        );

        if ('==TRIAL==' == '==' . 'TRIAL==') {
            $license = Am_Di::getInstance()->config->get('license');
            $this->addTextarea('license', array(
                    'class' => 'el-wide',
                    'rows' => count(explode("\n", $license)) + 1,
                ))
                ->setLabel(___("License Key"))
                ->addRule('required')
                ->addRule('notregex', ___('You have license keys from past versions of aMember, please replace it with latest, one-line keys'), '/====\s+LICENSE/')
                ->addRule('callback', ___('Valid license key are one-line string,starts with L and ends with X'), array($this, 'validateKeys'));

            if ($_ = Am_License::getInstance()->getLicenses()) {
                $cnt = array();
                foreach($_ as $domain => $expire) {
                    $cnt[] = sprintf('<li><strong>%s</strong> %s</li>', $domain,
                        in_array($expire, array('2099-12-31', Am_Period::MAX_SQL_DATE)) ?
                            ___('Lifetime') :
                            ___("expires %s", amDate($expire)));
                }
                $cnt = sprintf('<ul>%s</ul>', implode('', $cnt));
            } else {
                $cnt = ___('No License Configured');
            }
        } else {
            $cnt = "Using TRIAL Version - expires ==TRIAL_EXPIRES==";
        }
        $this->addStatic()->setLabel(___('Configured License Keys'))->setContent(sprintf('<div>%s</div>', $cnt));

        parent::init();

        $this->addSaveButton(___('Update License Information'));
    }

    function validateKeys($keys)
    {
        $keys = explode("\n", $keys);
        $ok = 0;
        foreach ($keys as $k) {
            $k = trim($k, "\t\n\r ");
            if (empty($k))
                continue;
            if (!preg_match('/^L[A-Za-z0-9\/=+]+X$/', $k))
                continue;
            $ok++;
        }
        return $ok > 0;
    }

    function validateRootUrl($url)
    {
        if (defined('APPLICATION_HOSTED'))
            return;

        if (!preg_match('/^http(s|):\/\/.+$/', $url))
            return ___('URL must start from %s or %s', '<i>http://</i>', '<i>https://</i>');
        if (preg_match('/\/+$/', $url))
            return ___('URL must be specified without trailing slash');
    }

    function validateSsl($ssl, $el)
    {
        $vars = $el->getContainer()->getValue();

        if ($ssl) {
            if (!preg_match('/^https:/', $vars['root_surl'])) {
                return ___('You need to properly setup HTTPS on your server to enable this option');
            }
            try {
                $req = new Am_HttpRequest($vars['root_surl'] . '/login');
                $resp = $req->send();
                if ($resp->getStatus() != 200)
                    return ___('You need to properly setup HTTPS on your server to enable this option');
            }
            catch (Exception $e) {
                return ___('You need to properly setup HTTPS on your server to enable this option');
            }
        }
    }
}

class AdminLicenseController extends Am_Mvc_Controller
{
    function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }

    function indexAction()
    {
        $this->view->title = ___('Fix aMember Pro License Key');
        $this->view->msg = Am_License::getInstance()->check();

        $form = new Am_Form_Admin_FixLicense();

        if (!$form->isSubmitted()) {
            $form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array($this->getDefaults())
            ));
        }

        if ($form->isSubmitted() && $form->validate()) {
            $vars = $form->getValue();
            Am_Config::saveValue('license', $vars['license']);
            Am_Config::saveValue('root_url', $vars['root_url']);
            Am_Config::saveValue('root_surl', $vars['root_surl']);
            Am_Config::saveValue('force_ssl', $vars['force_ssl']);
            $this->getDi()->adminLogTable->log('Update License/Root URLs');
            return Am_Mvc_Response::redirectLocation($this->getDi()->url('admin-license', false));
        }

        $this->view->form = $form;
        $this->view->display('admin/fixlicense.phtml');
    }

    function checkAction()
    {
        try {
            $req = new Am_HttpRequest($this->getParam('root_surl') . '/login');
            $resp = $req->send();
            $this->getResponse()->ajaxResponse($resp->getStatus() == 200);
        } catch (Exception $e) {
            $this->getResponse()->ajaxResponse(false);
        }
    }

    protected function getDefaults()
    {
        return array(
            'license' => $this->getDi()->config->get('license'),
            'root_url' => $this->getDi()->config->get('root_url'),
            'root_surl' => $this->getDi()->config->get('root_surl'),
            'force_ssl' => $this->getDi()->config->get('force_ssl')
        );
    }
}