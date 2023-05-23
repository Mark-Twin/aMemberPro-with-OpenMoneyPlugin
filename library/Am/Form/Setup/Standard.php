<?php

class Am_Form_Setup_Global extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('global');
        $this->setTitle(___('Global'))
        ->setComment('');
        $this->data['help-id'] = 'Setup/Global';
    }

    function validateCurl($val)
    {
        if (!$val) return;
        exec("$val http://www.yahoo.com/ 2>&1", $out, $return);
        if ($return)
            return "Couldn't execute '$val http://www.yahoo.com/'. Exit code: $return, $out";
    }

    function initElements()
    {
        $this->addText('site_title', array(
                'class' => 'el-wide',
        ), array('help-id' => '#Setup.2FEdit_Site_Title'))
        ->setLabel(___('Site Title'));

        $this->addStatic(null, null, array('help-id' => '#Root_URL_and_License_Key'))->setContent(
                '<a href="' . Am_Di::getInstance()->url('admin-license') . '" target="_top" class="link">'
                . ___('change')
                . '</a>')->setLabel(___('Root Url and License Keys'));

        $players = array('Flowplayer'=>'Flowplayer');
        if(file_exists($this->getDi()->root_dir . '/application/default/views/public/js/jwplayer/jwplayer.js'))
            $players['JWPlayer'] = 'JWPlayer';

        $this->addSelect('video_player')
            ->setId('video-player')
            ->setLabel(___('Video Player'))
            ->loadOptions($players)
            ->toggleFrozen(count($players)==1);

        $this->setDefault('video_player', 'Flowplayer');

        $this->addText('flowplayer_license')
                ->setId('video-player-Flowplayer')
                ->setLabel(___("FlowPlayer License Key\nyou may get your key in %smembers area%s",
                '<a href="http://www.amember.com/amember/member?flowplayer_key=1" class="link">', '</a>'))
                ->addRule('regex', ___('Value must be alphanumeric'), '/^[a-zA-Z0-9]*$/');

        $this->addText('jwplayer_license')
                ->setId('video-player-JWPlayer')
                ->setLabel(___("JWPlayer License Key"));

        $this->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#video-player').change(function(){
        jQuery('#video-player-Flowplayer').closest('.row').toggle(jQuery(this).val() == 'Flowplayer');
        jQuery('#video-player-JWPlayer').closest('.row').toggle(jQuery(this).val() == 'JWPlayer');
    }).change();
})
CUT
            );

        $g = $this->addGroup(null, array('help-id' => '#Setup.2FEdit_User_Pages_Theme'))
            ->setLabel(___('User Pages Theme'));

        $g->setSeparator(' ');
        $g->addSelect('theme')
            ->loadOptions(Am_Di::getInstance()->view->getThemes('user'));

        $themeId = Am_Di::getInstance()->view->theme->getId();
        $config_link = Am_Di::getInstance()->url("admin-setup/themes-$themeId");
        $g->addHtml()
            ->setHtml(<<<CUT
<a href="$config_link" class="link" id="theme-config-link">configure</a>
<script type="text/javascript">
    jQuery(function(){
        jQuery('[name=theme]').change(function(){
            jQuery('#theme-config-link').hide();
        });
    })
</script>
CUT
                );

        $this->addSelect('admin_theme', null, array('help-id' => '#Setup.2FEdit_Admin_Pages_Theme'))
            ->setLabel(___('Admin Pages Theme'))
            ->loadOptions(Am_Di::getInstance()->view->getThemes('admin'));

        $tax_plugins =array(
            'global-tax' => ___('Global Tax'),
            'regional' => ___('Regional Tax'),
            'vat2015' => ___('EU VAT'),
            'gst' => ___('GST (Inclusive Tax)')
        );
        foreach(Am_Di::getInstance()->plugins_tax->getAvailable() as $plugin)
            if(!isset($tax_plugins[$plugin]))
                $tax_plugins[$plugin] = ucwords(str_replace("-", ' ', $plugin));

        $this->addSelect('plugins.tax', array('size' => 1))
            ->setLabel(___('Tax'))
            ->loadOptions(array(
                '' => ___('No Tax')
            ) + $tax_plugins);

        $fs = $this->addAdvFieldset('##02')
            ->setLabel(___('Signup Form Configuration'));

        $this->setDefault('login_min_length', 5);
        $this->setDefault('login_max_length', 16);

        $loginLen = $fs->addGroup(null, null, array('help-id' => '#Setup.2FEdit_Username_Rules'))->setLabel(___('Username Length'));
        $loginLen->addInteger('login_min_length', array('size'=>3))->setLabel('min');
        $loginLen->addStatic('')->setContent(' &mdash; ');
        $loginLen->addInteger('login_max_length', array('size'=>3))->setLabel('max');

        $fs->addAdvCheckbox('login_disallow_spaces', null, array('help-id' => '#Setup.2FEdit_Username_Rules'))
            ->setLabel(___('Do not Allow Spaces in Username'));

        $fs->addAdvCheckbox('login_dont_lowercase', null, array('help-id' => '#Setup.2FEdit_Username_Rules'))
            ->setLabel(___("Do not Lowercase Username\n".
                "by default, aMember automatically lowercases entered username\n".
                "here you can disable this function"));

        $this->setDefault('pass_min_length', 6);
        $this->setDefault('pass_max_length', 25);
        $passLen = $fs->addGroup(null, null, array('help-id' => '#Setup.2FEdit_Password_Length'))->setLabel(___('Password Length'));
        $passLen->addInteger('pass_min_length', array('size'=>3))->setLabel('min');
        $passLen->addStatic('')->setContent(' &mdash; ');
        $passLen->addInteger('pass_max_length', array('size'=>3))->setLabel('max');

        $fs->addAdvCheckbox('require_strong_password')
            ->setLabel(___("Require Strong Password\n" .
                'password should contain at least 2 capital letters, 2 or more numbers and 2 or more special chars'));

        $fs = $this->addFieldset('##03')
            ->setLabel(___('Miscellaneous'));

        $this->setDefault('admin.records-on-page', 10);
        $fs->addInteger('admin.records-on-page')
            ->setLabel(___('Records per Page (for grids)'));

        $fs->addAdvCheckbox('disable_rte')
            ->setLabel(___('Disable Visual HTML Editor'));

        $this->setDefault('currency', 'USD');
        $currency = $fs->addSelect('currency', array (
                'size' => 1, 'class' => 'am-combobox'
            ), array('help-id' => '#Set_Up.2FEdit_Base_Currency'))
            ->setLabel(___("Base Currency\n".
                "base currency to be used for reports and affiliate commission. ".
                "It could not be changed if there are any invoices in database.")
        )
        ->loadOptions(Am_Currency::getFullList());
        if (Am_Di::getInstance()->db->selectCell("SELECT COUNT(*) FROM ?_invoice")) {
            $currency->toggleFrozen(true);
        }

        $url = Am_Di::getInstance()->url('admin-currency-exchange');
        $label = Am_Html::escape(___('Edit'));
        $this->addHtml()
            ->setLabel(___('Currency Exchange Rates'))
            ->setHtml(<<<CUT
<a href="$url" class="link">$label</a>
CUT
                );

        $this->addSelect('404_page')
            ->setLabel(___("Page Not Found (404)\n" .
                "%sthis page will be public and do not require any login/password%s\n" .
                'you can create new pages %shere%s', '<strong>', '</strong>',
                '<a class="link" href="' . Am_Di::getInstance()->url('default/admin-content/p/pages') . '">', '</a>'))
            ->loadOptions(array(''=>___('Default Not Found Page')) +
                Am_Di::getInstance()->db->selectCol("SELECT page_id AS ?, title FROM ?_page", DBSIMPLE_ARRAY_KEY));
    }
}

class Am_Form_Setup_Plugins extends Am_Form_Setup
{
    // list of cc plugins saved for special handling
    protected $plugins_cc = array();

    function __construct()
    {
        parent::__construct('plugins');
        $this->setTitle(___('Plugins'))
        ->setComment('');
        $this->data['help-id'] = 'Setup/Plugins';
    }

    function getPluginsList($folders)
    {
        $ret = array();
        foreach ($folders as $folder)
            foreach (scandir($folder) as $f)
            {
                if ($f[0] == '.') continue;
                $path = "$folder/$f";
                if (is_file($path) && preg_match('/^(.+)\.php$/', $f, $regs)) {
                    $ret[ $regs[1] ] = $regs[1];
                } elseif (is_dir($path)) {
                    if (is_file("$path/$f.php"))
                        $ret[$f] = $f;
                }
            }
        ksort($ret);
        return $ret;
    }

    function initElements()
    {
        /* @var $bootstrap Bootstrap */
        $modules = $this->addMagicSelect('modules', null, array('help-id' => '#Enabling.2FDisabling_Modules'))
            ->setLabel(___('Enabled Modules'));
        $this->setDefault('modules', array());

        foreach (Am_Di::getInstance()->modules->getAvailable() as $module)
        {
            $fn = AM_APPLICATION_PATH . '/' . $module . '/module.xml';
            if (!file_exists($fn)) continue;
            $xml = simplexml_load_file($fn);
            if (!$xml) continue;
            if ($module == 'cc') continue;
            $modules->addOption($module . ' &ndash; ' . $xml->desc, $module);
        }

        foreach (Am_Di::getInstance()->plugins as $type => $mgr)
        {
            if ($type == 'modules') continue;

            /* @var $mgr Am_Plugins */
            switch($type)
            {
                case 'payment' :
                    $help_id = '#Enabling.2FDisabling_Payment_Plugins';
                    break;
                case 'protect' :
                    $help_id = '#Enabling.2FDisabling_Integration_Plugins';
                    break;
                case 'misc' :
                    $help_id = '#Enabling.2FDisabling_Other_Plugins';
                    break;
                default :
                    $help_id = '';
                    break;
            }

            $el = $this->addMagicSelect('plugins.' . $type, array('class' => 'magicselect am-combobox-fixed'), array('help-id' => $help_id))
                ->setLabel(___('%s Plugins', ___($mgr->getTitle())));

            $paths = $mgr->getPaths();
            $plugins = self::getPluginsList($paths);
            if ($type == 'payment')
            {
                $this->plugins_cc = file_exists(AM_APPLICATION_PATH . '/cc/plugins') ?
                    self::getPluginsList(array(AM_APPLICATION_PATH . '/cc/plugins')) :
                    array();
                if (!Am_Di::getInstance()->modules->isEnabled('cc'))
                {
                    $plugins = array_merge($plugins, $this->plugins_cc);
                    ksort($plugins);
                }

                array_remove_value($plugins, 'free');
            } elseif ($type == 'storage') {
                $plugins = array('upload'=>'upload', 'disk'=>'disk') + $plugins;
            }

            $el->loadOptions($plugins);
        }
        $this->setDefault('plugins.payment', array());
        $this->setDefault('plugins.protect', array());
        $this->setDefault('plugins.misc', array());
        $this->setDefault('plugins.storage', array('upload', 'disk'));

        if (!empty(Am_Di::getInstance()->session->plugin_enabled)) {
            $ids = json_encode(Am_Di::getInstance()->session->plugin_enabled);

            $this->addScript()
                ->setScript(<<<CUT
jQuery(function(){
    var ids = {$ids};
    for (id of ids) {
        jQuery("#setup-form-" + id).addClass('tab-highlight');
    }
})
CUT
                );
            Am_Di::getInstance()->session->plugin_enabled = null;
        }
    }

    public function beforeSaveConfig(Am_Config $before, Am_Config $after)
    {
        $cc_enabled = false;
        foreach ($after->get('plugins.payment') as $plugin)
        {
            if (!empty($this->plugins_cc[$plugin]))
            {
                $cc_enabled = true;
                break;
            }
        }

        $modules = $after->get('modules', array());

        if ($cc_enabled)
        {
            $modules[] = 'cc';
        }
        else
        {
            array_remove_value($modules, 'cc');
        }

        $after->set('modules', array_unique($modules));

        $all_enabled = array();
        // Do the same for plugins;
        foreach(Am_Di::getInstance()->plugins as $type => $pm)
        {
            /* @var $pm Am_Plugins */
            $configKey = $type == 'modules' ? 'modules' : ('plugins.'.$type);
            $b = (array)$before->get($configKey);
            $a = (array)$after->get($configKey);
            $enabled = array_filter(array_diff($a, $b), 'strlen');
            $disabled = array_filter(array_diff($b, $a), 'strlen');

            foreach ($disabled as $plugin) {
                if ($pm->load($plugin))
                    try {
                    $pm->get($plugin)->deactivate();
                } catch(Exception $e) {
                    Am_Di::getInstance()->errorLogTable->logException($e);
                    trigger_error("Error during plugin [$plugin] deactivation: " . get_class($e). ": " . $e->getMessage(), E_USER_WARNING);
                }
                // Now clean config for plugin;
                $after->set($pm->getConfigKey($plugin), array());
            }
            foreach ($enabled as $plugin) {
                if ($pm->load($plugin)) {
                    $class = $pm->getPluginClassName($plugin);
                    try {
                        call_user_func(array($class, 'activate'), $plugin, $type);
                    } catch(Exception $e) {
                        Am_Di::getInstance()->errorLogTable->logException($e);
                        trigger_error("Error during plugin [$plugin] activattion: " . get_class($e). ": " . $e->getMessage(),E_USER_WARNING);
                    }
                }
            }
            $all_enabled = array_merge($all_enabled, $enabled);
        }
        Am_Di::getInstance()->session->plugin_enabled = $all_enabled;
        Am_Di::getInstance()->config->set('modules', $modules = $after->get('modules', array()));
        Am_Di::getInstance()->app->dbSync(true, $modules);
        $after->save();
    }
}

class Am_Form_Setup_Email extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('email');
        $this->setTitle(___('E-Mail'))
            ->setComment('');
        $this->data['help-id'] = 'Setup/Email';
    }

    function checkSMTPHost($val){
        $res = ($val['email_method'] == 'smtp') ?
        (bool)strlen($val['smtp_host']) : true;

        if (!$res) {
            $elements = $this->getElementsByName('smtp_host');
            $elements[0]->setError(___('SMTP Hostname is required if you have enabled SMTP method'));
        }

        return $res;
    }

    function initElements()
    {
        $this->addText('admin_email', array (
                'class' => 'el-wide',
        ), array('help-id' => '#Email_Address_Configuration'))
        ->setLabel(___("Admin E-Mail Address\n".
                "used to send email notifications to admin\n".
                "and as default outgoing address")
        )
        ->addRule('callback', ___('Please enter valid e-mail address'), array('Am_Validate', 'email'));

        $this->addText('technical_email', array('class' => 'el-wide'))
        ->setLabel(___("Technical E-Mail Address\n".
                "shown on error pages. If empty, [Admin E-Mail Address] is used"))
        ->addRule('callback', ___('Please enter valid e-mail address'), array('Am_Validate', 'empty_or_email'));

        $this->addText('admin_email_from', array (
                'class' => 'el-wide',
        ), array('help-id' => '#Email_Address_Configuration'))
        ->setLabel(___(
                "Outgoing Email Address\n".
                "used as From: address for sending e-mail messages\n".
                "to customers. If empty, [Admin E-Mail Address] is used"
        ))
        ->addRule('callback', ___('Please enter valid e-mail address'), array('Am_Validate', 'empty_or_email'));

        $this->addText('admin_email_name', array (
                'class' => 'el-wide',
        ), array('help-id' => '#Email_Address_Configuration'))
        ->setLabel(___(
                "E-Mail Sender Name\n" .
                "used to display name of sender in outgoing e-mails"
        ));

        $fs = $this->addFieldset('##19')
            ->setLabel(___('E-Mail System Configuration'));

        $fs->addSelect('email_method', null, array('help-id' => '#Email_System_Configuration'))
            ->setLabel(___(
                "Email Sending method\n" .
                "PLEASE DO NOT CHANGE if emailing from aMember works"))
                ->loadOptions(array(
                        'mail' => ___('Internal PHP mail() function (default)'),
                        'smtp' => ___('SMTP'),
                        'ses' => ___('Amazon SES'),
                        'sendgrid' => ___('Send Grid (Web API v2)'),
                        'sendgrid3' => ___('Send Grid (Web API v3)'),
                        'campaignmonitor' => ___('CampaignMonitor (Transactional API)'),
                        'mailjet' => ___('Mail Jet'),
                        'postmark' => ___('Postmark'),
                        'disabled' => ___('Disabled')
                ));

        $fs->addText('smtp_host', array('class' => 'el-wide'), array('help-id' => '#SMTP_Mail_Settings'))
            ->setLabel(___('SMTP Hostname'));
        $this->addRule('callback', ___('SMTP Hostname is required if you have enabled SMTP method'), array($this, 'checkSMTPHost'));

        $fs->addInteger('smtp_port', array('size' => 4),  array('help-id' => '#SMTP_Mail_Settings'))
            ->setLabel(___('SMTP Port'));
        $fs->addSelect('smtp_security', null,  array('help-id' => '#SMTP_Mail_Settings'))
            ->setLabel(___('SMTP Security'))
            ->loadOptions(array(
                ''     => 'None',
                'ssl'  => 'SSL',
                'tls'  => 'TLS',
            ));
        $fs->addSelect('smtp_auth', null,  array('help-id' => '#SMTP_Mail_Settings'))
            ->setLabel(___('Authentication Type'))
            ->loadOptions(array(
                'login' => 'Login',
                'plain'  => 'Plain',
            ));
        $fs->addText('smtp_user', array('autocomplete'=>'off'),  array('help-id' => '#SMTP_Mail_Settings'))
            ->setLabel(___('SMTP Username'));
        $fs->addPassword('smtp_pass', array('autocomplete'=>'off'),  array('help-id' => '#SMTP_Mail_Settings'))
            ->setLabel(___('SMTP Password'));

        $fs->addText('ses_id', array('class' => 'el-wide'))
            ->setLabel(___('Amazon SES Access Id'));
        $fs->addPassword('ses_key', array('class' => 'el-wide'))
            ->setLabel(___('Amazon SES Secret Key'));
        $fs->addSelect('ses_region', '', array('options' =>array(
                Am_Mail_Transport_Ses::REGION_US_EAST_1 => 'US East (N. Virginia)',
                Am_Mail_Transport_Ses::REGION_US_WEST_2 => 'US West (Oregon)',
                Am_Mail_Transport_Ses::REGION_EU_WEST_1 => 'EU (Ireland)'
            )))
            ->setLabel(___('Amazon SES Region'));

        $fs->addText('sendgrid_user')
            ->setLabel(___('SendGrid Username'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $fs->addPassword('sendgrid_key')
            ->setLabel(___('SendGrid Password'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $fs->addPassword('sendgrid3_key', array('class' => 'el-wide'))
            ->setLabel(___('SendGrid API Key'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $fs->addText('campaignmonitor_apikey', array('class' => 'el-wide'))
            ->setLabel(___('Campaignmonitor API Key'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $fs->addText('campaignmonitor_clientid', array('class' => 'el-wide'))
            ->setLabel(___('Client API ID'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $fs->addText('mailjet_apikey_public', array('class' => 'el-wide'))
            ->setLabel(___('Mail Jet API Public Key'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $fs->addPassword('mailjet_apikey_private', array('class' => 'el-wide'))
            ->setLabel(___('Mail Jet API Private Key'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $fs->addPassword('postmark_token', array('class' => 'el-wide'))
            ->setLabel(___('Postmark Server API token'))
            ->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::CLIENT);

        $test = ___('Test E-Mail Settings');
        $em = ___('E-Mail Address to Send to');
        $se = ___('Send Test E-Mail');
        $fs->addStatic('email_test', null,  array('help-id' => '#Test_Email_Settings'))->setContent(<<<CUT
<div style="text-align: center">
<span class="red">$test</span><span class="admin-help"><a href="http://www.amember.com/docs/Setup/Email#Test_Email_Settings" target="_blank"><sup>?</sup></a></span>
<input type="text" name="email" size=30 placeholder="$em" />
<input type="button" name="email_test_send" value="$se" />
<div id="email-test-result" style="display:none"></div>
</div>
CUT
        );

        $se = ___('Sending Test E-Mail...');
        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#row-email_test-0 .element-title").hide();
    jQuery("#row-email_test-0 .element").css({ 'margin-left' : '0px'});

    jQuery("input[name='email_test_send']").click(function(){
        var btn = jQuery(this);
        var vars = btn.parents('form').serializeArray();

        var dialogOpts = {
              modal: true,
              bgiframe: true,
              autoOpen: true,
              width: 450,
              draggable: true,
              resizeable: true
           };

        var savedVal = btn.val();
        btn.val("$se").prop("disabled", "disabled");
        var url = amUrl("/admin-email/test", 1);
        jQuery.post(url[0], jQuery.merge(vars, url[1]), function(data){
            jQuery("#email-test-result").html(data).dialog(dialogOpts);
            btn.val(savedVal).prop("disabled", "");
        });

    });

    jQuery("#email_method-0").change(function(){
        jQuery(".row[id*='smtp_']").toggle(jQuery(this).val() == 'smtp');
        jQuery(".row[id*='ses_']").toggle(jQuery(this).val() == 'ses');
        jQuery(".row[id*='sendgrid_']").toggle(jQuery(this).val() == 'sendgrid');
        jQuery(".row[id*='sendgrid3_']").toggle(jQuery(this).val() == 'sendgrid3');
        jQuery(".row[id*='campaignmonitor_']").toggle(jQuery(this).val() == 'campaignmonitor');
        jQuery(".row[id*='mailjet_']").toggle(jQuery(this).val() == 'mailjet');
        jQuery(".row[id*='postmark_']").toggle(jQuery(this).val() == 'postmark');
    }).change();
});
CUT
        );

        $this->setDefault('email_log_days', 0);
        $fs->addText('email_log_days', array (
                'size' => 6,
            ), array('help-id' => '#Outgoing_Messages_Log'))
            ->setLabel(___('Log Outgoing E-Mail Messages for ... days'));

        $fs->addAdvCheckbox('email_queue_enabled', null, array('help-id' => '#Using_the_Email_Throttle_Queue'))
            ->setLabel(___('Use E-Mail Throttle Queue'));
        $fs->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#email_queue_enabled-0").change(function(){
        jQuery("#email_queue_period-0").closest(".row").toggle(this.checked);
        jQuery("#email_queue_limit-0").closest(".row").toggle(this.checked);
    }).change();
});
CUT
        );

        $fs->addSelect('email_queue_period')
            ->setLabel(___(
                "Allowed E-Mails Period\n" .
                "choose if your host is limiting e-mails per day or per hour"))
                ->loadOptions(
                        array (
                            3600 => 'Hour',
                            86400 => 'Day',
                        )
                );

        $this->setDefault('email_queue_limit', 100);
        $fs->addInteger('email_queue_limit', array('size' => 6))
            ->setLabel(___(
                "Allowed E-Mails Count\n" .
                "enter number of emails allowed within the period above"));

        $fs = $this->addFieldset('##10')
            ->setLabel(___('Validation Messages to Customer'));

        $fs->addElement('email_link', 'verify_email_signup', null, array('help-id' => '#Validation_Message_Configuration'))
        ->setLabel(___("Verify E-Mail Address On Signup Page\n".
            "e-mail verification may be enabled for each signup form separately\n".
            "at aMember CP -> Forms Editor -> Edit, click \"configure\" on E-Mail brick"));

        $fs->addElement('email_link', 'verify_email_profile', null, array('help-id' => '#Validation_Message_Configuration'))
        ->setLabel(___("Verify New E-Mail Address On Profile Page\n".
            "e-mail verification for profile form may be enabled\n".
            "at aMember CP -> Forms Editor -> Edit, click \"configure\" on E-Mail brick"));

        $fs = $this->addFieldset('##11')
            ->setLabel(___('Signup Messages'));

        $fs->addElement('email_checkbox', 'registration_mail')
            ->setLabel(___("Send Registration E-Mail\n".
                "once customer completes signup form (before payment)"));
        $fs->addElement('email_checkbox', 'registration_mail_admin')
            ->setLabel(___("Send Registration E-Mail to Admin\n".
                "once customer completes signup form (before payment)"));

        $fs = $this->addFieldset('##12')
            ->setLabel(___("Pending Invoice Notification Rules"));

        $fs->addElement(new Am_Form_Element_PendingNotificationRules('pending_to_user'))
        ->setLabel(___("Pending Invoice Notifications to User\n".
                "only one email will be send for each defined day.\n".
                "all email for specific day will be selected and conditions will be checked.\n".
                "First email with matched condition will be send and other ignored"));

        $fs->addElement(new Am_Form_Element_PendingNotificationRules('pending_to_admin'))
        ->setLabel(___("Pending Invoice Notifications to Admin\n".
                "only one email will be send for each defined day.\n".
                "all email for specific day will be selected and conditions will be checked.\n".
                "First email with matched condition will be send and other ignored"));

        $fs = $this->addFieldset('##13')
            ->setLabel(___('Messages to Customer after Payment'));

        $fs->addElement('email_checkbox', 'send_signup_mail', null, array('help-id' => '#Email_Messages_Configuration'))
        ->setLabel(___("Send Signup E-Mail\n".
                "once FIRST subscripton is completed"));

        $fs->addElement('email_checkbox', 'send_payment_mail', null, array('help-id' => '#Email_Messages_Configuration'))
            ->setLabel(___("E-Mail Payment Receipt to User\n".
                'every time payment is received'));

        $fs->addElement('email_checkbox', 'send_payment_admin', null, array('help-id' => '#Email_Messages_Configuration'))
            ->setLabel(___("Admin Payment Notifications\n".
                "to admin once payment is received"));

        $fs->addElement('email_checkbox', 'send_free_payment_admin', null, array('help-id' => '#Email_Messages_Configuration'))
            ->setLabel(___("Admin Free Subscription Notifications\n".
                "to admin once free signup is completed"));

        $fs = $this->addFieldset('##15')
            ->setLabel(___('E-Mails by User Request'));

        $fs->addElement('email_checkbox', 'mail_cancel_member')
            ->setLabel(___("Send Cancel Notifications to User\n" .
            'send email to member when he cancels recurring subscription.'));

        $fs->addElement('email_checkbox', 'mail_upgraded_cancel_member')
            ->setLabel(___("Send Cancel (due to upgrade) Notifications to User\n" .
            'send email to member when he cancels recurring subscription due to upgrade.'));

        $fs->addElement('email_checkbox', 'mail_cancel_admin')
            ->setLabel(___("Send Cancel Notifications to Admin\n" .
            'send email to admin when recurring subscription cancelled by member'));

        $fs->addElement('email_link', 'send_security_code')
            ->setLabel(___("Remind Password to Customer"));

        $fs->addElement('email_checkbox', 'changepass_mail')
            ->setLabel(___("Change Password Notification\n" .
            'send email to user after password change'));

        if($this->haveCronRebillPlugins())
        {
            $fs = $this->addFieldset('##17')
                ->setLabel(___('E-Mail Messages on Rebilling Event', ''));

            $fs->addElement('email_checkbox', 'cc.admin_rebill_stats')
                ->setLabel(___("Send Credit Card Rebill Stats to Admin\n" .
                "Credit Card Rebill Stats will be sent to Admin daily. It works for payment processors like Authorize.Net and PayFlow Pro only"));

            $fs->addElement('email_checkbox', 'cc.rebill_failed')
                ->setLabel(___("Credit Card Rebill Failed\n" .
                "if credit card rebill failed, user will receive the following e-mail message. It works for payment processors like Authorize.Net and PayFlow Pro only"));

            $fs->addElement('email_checkbox', 'cc.rebill_success')
                ->setLabel(___("Credit Card Rebill Successfull\n" .
                "if credit card rebill was sucessfull, user will receive the following e-mail message. It works for payment processors like Authorize.Net and PayFlow Pro only"));

            if($this->haveStoreCreditCardPlugins())
            {
                $gr = $fs->addGroup()
                    ->setLabel(___("Credit Card Expiration Notice\n" .
                        "if saved customer credit card expires soon, user will receive the following e-mail message. It works for payment processors like Authorize.Net and PayFlow Pro only"));
;
                $gr->addElement('email_checkbox', 'cc.card_expire');
                $gr->addHTML()->setHTML(' ' . ___('Send message') . ' ');
                $gr->addText('cc.card_expire_days', array('size'=>2, 'value'=>5));
                $gr->addHTML()->setHTML(' ' . ___('days before rebilling'));

            }
        }
        $fs = $this->addFieldset('##16')
            ->setLabel(___('E-Mails by Admin Request'));

        $fs->addElement('email_link', 'send_security_code_admin', null, array('help-id' => '#Forgotten_Password_Templates'))
        ->setLabel(___('Remind Password to Admin'));


        $fs = $this->addFieldset('##18')
            ->setLabel(___('Miscellaneous'));

        $fs->addElement('email_checkbox', 'profile_changed', null, array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___("Send Notification to Admin When Profile is Changed\n".
                    "admin will receive an email if user has changed profile"
            ));

        $fs->addAdvCheckbox('disable_unsubscribe_link', null, array('help-id' => '#Miscellaneous_Email_Settings'))
        ->setLabel(___('Do not include Unsubscribe Link into e-mails'));

        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('input[type=checkbox][name=disable_unsubscribe_link]').change(function(){
        jQuery('#row-unsubscribe_html-0, #row-unsubscribe_txt-0').toggle(!this.checked)
    }).change();
})
CUT
        );

        $fs->addTextarea('unsubscribe_html', array('class' => 'el-wide', 'rows'=>6),  array('help-id' => '#Miscellaneous_Email_Settings'))
        ->setLabel(___("HTML E-Mail Unsubscribe Link\n" .
                "%link% will be replaced to actual unsubscribe URL"));
        $this->setDefault('unsubscribe_html', Am_Mail::UNSUBSCRIBE_HTML);

        $fs->addTextarea('unsubscribe_txt', array('class' => 'el-wide', 'rows'=>6),  array('help-id' => '#Miscellaneous_Email_Settings'))
        ->setLabel(___("Text E-Mail Unsubscribe Link\n" .
                "%link% will be replaced to actual unsubscribe URL"));
        $this->setDefault('unsubscribe_txt', Am_Mail::UNSUBSCRIBE_TXT);

        $fs->addAdvCheckbox('disable_unsubscribe_block', null, array('help-id' => '#Miscellaneous_Email_Settings'))
        ->setLabel(___('Do not Show Unsubscribe Block on Member Page'));

        $fs->addText('copy_admin_email', array('class' => 'el-wide'), array('help-id' => '#Miscellaneous_Email_Settings'))
            ->setLabel(___("Send Copy of All Admin Notifications\n" .
                'will be used to send copy of email notifications to admin ' .
                'you can specify more then one email separated by comma: ' .
                'test@email.com,test1@email.com,test2@email.com'))
            ->addRule('callback', 'Please enter valid e-mail address', array('Am_Validate', 'emails'));
    }

    function haveCronRebillPlugins()
    {
        foreach(Am_Di::getInstance()->plugins_payment->getAllEnabled() as $p)
        {
            if($p->getRecurringType() == Am_Paysystem_Abstract::REPORTS_CRONREBILL)
                return true;
        }
    }

    function haveStoreCreditCardPlugins()
    {
        foreach(Am_Di::getInstance()->plugins_payment->getAllEnabled() as $p)
        {
            if($p->storesCcInfo())
                return true;
        }
    }
}

class Am_Form_Setup_Pdf extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('pdf');
        $this->setTitle(___('PDF Invoice'))
        ->setComment('');
        $this->data['help-id'] = 'Setup/PDF Invoice';

        $info = ___('You can find info regarding pdf invoice customization %shere%s', '<a class="link" target="_blank" href="http://www.amember.com/docs/How_to_customize_PDF_invoice_output">', '</a>');
        $this->addProlog(<<<CUT
<div class="info">$info</div>
CUT
            );
    }

    function initElements()
    {
        $this->addAdvCheckbox('send_pdf_invoice', null, array('help-id' => '#Enabling_PDF_Invoices'))
            ->setLabel(___("Enable PDF Invoice"));

        $g = $this->addGroup()
            ->setLabel(___('Display Options'))
            ->setSeparator('<br />');

        $g->addAdvCheckbox('pdf_invoice_sent_user', null, array('content' => ___('Attach invoice file (.pdf) to Payment Receipt to User')));
        $g->addAdvCheckbox('pdf_invoice_sent_admin', null, array('content' => ___('Attach invoice file (.pdf) to Payment Receipt to Admin')));
        $g->addAdvCheckbox('pdf_invoice_link', null, array('content' => ___('Allow user to download PDF invoice in his account')));

        $this->addText('invoice_filename', array('size'=>30, 'class' => 'el-wide'))
            ->setLabel(___("Filename for Invoice\n" .
            '%public_id% will be replaced with real public id of invoice, %receipt_id% will be replaced with payment receipt, ' .
            'also you can use the following placehoders %payment.date%, %user.name_f%, %user.name_l%'));

        $this->setDefault('invoice_filename', 'amember-invoice-%public_id%.pdf');

        $this->addAdvRadio('invoice_format', null, array('help-id' => '#PDF_Invoice_Format'))
            ->setLabel(___('Paper Format'))
            ->loadOptions(array(
                    Am_Pdf_Invoice::PAPER_FORMAT_LETTER => ___('USA (Letter)'),
                    Am_Pdf_Invoice::PAPPER_FORMAT_A4 => ___('European (A4)')
            ));

        $this->setDefault('invoice_format', Am_Pdf_Invoice::PAPER_FORMAT_LETTER);

        $this->addAdvcheckbox('invoice_include_access')
            ->setLabel(___('Include Access Periods to PDF Invoice'));

        if (Am_Di::getInstance()->plugins_tax->getEnabled()) {
            $this->addAdvcheckbox('invoice_always_tax')
                ->setLabel(___('Show Tax even it is 0'));
        }

        $this->addAdvcheckbox('invoice_do_not_include_terms')
            ->setLabel(___('Do not Include Subscription Terms to PDF Invoice'));

        $this->addAdvcheckbox('different_invoice_for_refunds')
            ->setLabel(___("Display Separate Invoice for Refunds\n".
                    "Setting affect aMember Control Panel only. User will see regular invoice which includes refund information inside"));

        $upload = $this->addUpload('invoice_custom_template',
                array(), array('prefix'=>'invoice_custom_template', 'help-id' => '#PDF_Invoice_Template')
            )->setLabel(___('Custom PDF Template for Invoice (optional)')
            )->setAllowedMimeTypes(array(
                    'application/pdf'
            ));

        $this->setDefault('invoice_custom_template', '');

        $upload->setJsOptions(<<<CUT
{
    onChange : function(filesCount) {
        jQuery('fieldset#template-custom-settings').toggle(filesCount>0);
        jQuery('fieldset#template-generated-settings').toggle(filesCount==0);
    }
}
CUT
        );

        $fsCustom = $this->addFieldset('template-custom')
            ->setLabel(___('Custom Template Settings'))
            ->setId('template-custom-settings');

        $this->setDefault('invoice_skip', 150);
        $fsCustom->addText('invoice_skip')
            ->setLabel(___(
                "Top Margin\n".
                "How much [pt] skip from top of template before start to output invoice\n".
                "1 pt = 0.352777 mm"));

        $fsGenerated = $this->addFieldset('template-generated')
            ->setLabel(___('Auto-generated Template Settings'))
            ->setId('template-generated-settings');

        $invoice_logo = $fsGenerated->addUpload('invoice_logo', array(),
                array('prefix'=>'invoice_logo', 'help-id' => '#Company_Logo_for_Invoice')
            )->setLabel(___("Company Logo for Invoice\n".
                "it must be png/jpeg/tiff file"))
                ->setAllowedMimeTypes(array(
                        'image/png', 'image/jpeg', 'image/tiff'
                ));

        $this->setDefault('invoice_logo', '');

        $fsGenerated->addAdvRadio('invoice_logo_position')
            ->setLabel('Logo Postion')
            ->loadOptions(array(
                'left' => ___('Left'),
                'right' => ___('Right')
            ));
        $this->setDefault('invoice_logo_position', 'left');

        $fsGenerated->addTextarea('invoice_contacts', array (
                'rows' => 5, 'class' => 'el-wide'
            ), array('help-id' => '#Invoice_Contact_Information'))
            ->setLabel(___("Invoice Contact information\n" .
                "included to header"));

        $fsGenerated->addTextarea('invoice_footer_note', array (
                'rows' => 5, 'class' => 'el-wide'
            ), array('help-id' => '#Invoice_Footer_Note'))
            ->setLabel(___("Invoice Footer Note\n" .
                "This text will be included at bottom to PDF Invoice. " .
                "You can use all user specific placeholders here ".
                "eg. %user.login%, %user.name_f%, %user.name_l% etc."));

        $script = <<<CUT
(function($){
    jQuery(function() {
        function change_template_type(obj) {
            var show = parseInt(obj.val()) > 0;
            jQuery('fieldset#template-custom-settings').toggle(show);
            jQuery('fieldset#template-generated-settings').toggle(!show);
        }

        jQuery('input[name=send_pdf_invoice]').change(function(){
            if (!this.checked) {
                jQuery(this).closest('.row').nextAll().not('script').hide()
                jQuery(this).closest('form').find('input[type=submit]').closest('.row').show();
            } else {
                jQuery(this).closest('.row').nextAll().not('script').show();
                change_template_type(jQuery('input[name=invoice_custom_template]:enabled').last());
            }
        }).change();
    });
})(jQuery)
CUT;
        $this->addScript()->setScript($script);

        $gr = $this->addAdvFieldset('invoice_custom_font')
                ->setLabel(___('Advanced'));

        $gr->addUpload('invoice_custom_ttf',
                array(), array('prefix'=>'invoice_custom_ttf')
            )->setLabel(___("Custom Font for Invoice (optional)\n".
                "Useful for invoices with non-Latin symbols " .
                "when there is a problem with displaying such symbols in the PDF invoice. " .
                "Please upload .ttf file only."));
        $this->setDefault('invoice_custom_ttf', '');
        $gr->addUpload('invoice_custom_ttfbold',
                array(), array('prefix'=>'invoice_custom_ttfbold')
            )->setLabel(___("Custom Bold Font for Invoice (optional)\n".
                "Useful for invoices with non-Latin symbols " .
                "when there is a problem with displaying such symbols in the PDF invoice." .
                "Please upload .ttf file only."));
        $this->setDefault('invoice_custom_ttfbold', '');
        $gr->addAdvCheckbox('store_pdf_file')->setLabel(___("Store PDF invoices in file system\n".
            "once generated file will be saved and further changes for example of customer's profile will not affect it"));
    }
}

class Am_Form_Setup_VideoPlayer extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('video-player');
        $this->setTitle(___('Video Player'))
        ->setComment('');
    }

    function initElements()
    {
        $this->setupElements($this, 'flowplayer.');

        $this->setDefault('flowplayer.logo_postion', 'top-right');
        $this->setDefault('flowplayer.cc_postion', 'top');
        $this->setDefault('flowplayer.width', 520);
        $this->setDefault('flowplayer.height', 330);
        $this->setDefault('flowplayer.autoBuffering', 0);
        $this->setDefault('flowplayer.bufferLength', 3);
        $this->setDefault('flowplayer.autoPlay', 1);
        $this->setDefault('flowplayer.scaling', 'scale');
    }

    public function setupElements(Am_Form $form, $prefix = null)
    {
        $form->addUpload($prefix . 'logo_id', null, array('prefix' => 'video-poster'))
            ->setLabel(___("Logo Image\n" .
                "watermark on video"));

        $form->addAdvRadio($prefix . 'logo_position')
            ->setLabel(___('Logo Position'))
            ->loadOptions(array(
                'top-right' => ___('Top Right'),
                'top-left' => ___('Top Left'),
                'bottom-right' => ___('Bottom Right'),
                'bottom-left' => ___('Bottom Left')
            ));

        $form->addAdvRadio($prefix . 'cc_position')
            ->setLabel(___('Closed Caption Position'))
            ->loadOptions(array(
                'top' => ___('Top'),
                'bottom' => ___('Bottom')
            ));

        $form->addUpload($prefix . 'poster_id', null, array('prefix' => 'video-poster'))
            ->setLabel(___("Poster Image\n" .
                "default poster image"));

        $gr = $form->addGroup()
            ->setLabel(___("Default Size\n" .
                "width&times;height"));

        $gr->addText($prefix . 'width', array('size' => 4));
        $gr->addStatic()->setContent(' &times ');
        $gr->addText($prefix . 'height', array('size' => 4));

        $form->addSelect($prefix . 'autoPlay')
            ->setLabel(___("Auto Play\n" .
                'whether the player should start playback immediately upon loading'))
            ->loadOptions(array(
                0 => ___('No'),
                1 => ___('Yes')
            ));

        $form->addSelect($prefix . 'autoBuffering')
            ->setLabel(___("Auto Buffering\n" .
                'whether loading of clip into player\'s memory should begin ' .
                'straight away. When this is true and autoPlay is false then ' .
                'the clip will automatically stop at the first frame of the video.'))
            ->loadOptions(array(
                    0 => ___('No'),
                    1 => ___('Yes')
            ));

        $form->addInteger($prefix . 'bufferLength')
            ->setLabel(___("Buffer Length\n" .
                'The amount of video data (in seconds) which should be loaded ' .
                'into Flowplayer\'s memory in advance of playback commencing.'));

        $form->addSelect($prefix . 'scaling')
            ->setLabel(___("Scaling\n" .
                "Setting which defines how video is scaled on the video screen. Available options are:\n" .
                "<strong>fit</strong>: Fit to window by preserving the aspect ratio encoded in the file's metadata.\n" .
                "<strong>half</strong>: Half-size (preserves aspect ratio)\n" .
                "<strong>orig</strong>: Use the dimensions encoded in the file. " .
                "If the video is too big for the available space, the video is scaled using the 'fit' option.\n" .
                "<strong>scale</strong>: Scale the video to fill all available space. ".
                "Ignores the dimensions in the metadata. This is the default setting."))
                ->loadOptions(array(
                        'fit' => 'fit',
                        'half' => 'half',
                        'orig' => 'orig',
                        'scale' => 'scale'
                ));
    }
}

class Am_Form_Setup_Advanced extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('advanced');
        $this->setTitle(___('Advanced'))
        ->setComment('');
        $this->data['help-id'] = 'Setup/Advanced';
    }

    function checkBackupEmail($val)
    {
        $res = $val['email_backup_frequency'] ?
        Am_Validate::email($val['email_backup_address']) : true;

        if (!$res) {
            $elements = $this->getElementsByName('email_backup_address');
            $elements[0]->setError(___('This field is required'));
        }

        return $res;
    }

    function initElements()
    {
        $this->addAdvCheckbox('use_cron', null, array('help-path' => 'Cron'))
            ->setLabel(___('Use External Cron'));

        $gr = $this->addGroup(null, null, array('help-id' => '#Configuring_Advanced_Settings'))->setLabel(array(
            ___('Maintenance Mode'), ___('put website offline, making it available for admins only')));
        $gr->setSeparator(' ');
        $gr->addCheckbox('', array('id' => 'maint_checkbox',
                'data-text' => ___('Site is temporarily disabled for maintenance')));
        $gr->addTextarea('maintenance', array('id' => 'maint_textarea', 'rows'=>3, 'cols'=>80));
        $gr->addScript()->setScript(<<<CUT
jQuery(function(){
    var checkbox = jQuery('#maint_checkbox');
    var textarea = jQuery('#maint_textarea');
    jQuery('#maint_checkbox').click(function(){
        textarea.toggle(checkbox.prop('checked'));
        if (textarea.is(':visible'))
        {
            textarea.val(checkbox.data('text'));
        } else {
            checkbox.data('text', textarea.val());
            textarea.val('');
        }
    });
    checkbox.prop('checked', !!textarea.val());
    textarea.toggle(checkbox.is(':checked'));
});
CUT
        );

        $gr = $this->addGroup(null, null, array('help-id'=>'#Configuring_Advanced_Settings'))->setLabel(___("Clear Access Log"));
        $gr->addAdvCheckbox('clear_access_log', null, array('help-id' => '#Configuring_Advanced_Settings'));
        $gr->addStatic()->setContent(sprintf('<span class="clear_access_log_days"> %s </span>', ___("after")));
        $gr->addText('clear_access_log_days', array('class'=>'clear_access_log_days', 'size' => 4));
        $gr->addStatic()->setContent(sprintf('<span class="clear_access_log_days"> %s </span>', ___("days")));

        $this->setDefault('clear_access_log_days', 7);

        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('input[name=clear_access_log]').change(function(){
        jQuery('.clear_access_log_days').toggle(this.checked);
    }).change();
})

CUT
            );


        $this->addText('clear_debug_log_days', array('size' => 4))
            ->setLabel(___('Log Debug Information for ... days'));
        $this->setDefault('clear_debug_log_days', 7);

        $gr = $this->addGroup()->setLabel(___('Clear Incomplete Invoices'));
        $gr->addAdvCheckbox('clear_inc_payments');
        $gr->addStatic()->setContent(sprintf('<span class="clear_inc_payments_days"> %s </span>', ___("after")));
        $gr->addInteger('clear_inc_payments_days', array('class'=>'clear_inc_payments_days', 'size'=>4));
        $gr->addStatic()->setContent(sprintf('<span class="clear_inc_payments_days"> %s </span>', ___("days")));

        $this->setDefault('clear_inc_payments_days', 7);

        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('input[name=clear_inc_payments]').change(function(){
        jQuery('.clear_inc_payments_days').toggle(this.checked);
    }).change();
})

CUT
            );

        $gr = $this->addGroup()->setLabel(___('Clear Incomplete Users'));
        $gr->addAdvCheckbox('clear_inc_users');
        $gr->addStatic()->setContent(sprintf('<span class="clear_inc_users_days"> %s </span>', ___("after")));
        $gr->addInteger('clear_inc_users_days', array('class'=>'clear_inc_users_days', 'size'=>4));
        $gr->addStatic()->setContent(sprintf('<span class="clear_inc_users_days"> %s </span>', ___("days")));

        $this->setDefault('clear_inc_users_days', 7);

        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('input[name=clear_inc_users]').change(function(){
        jQuery('.clear_inc_users_days').toggle(this.checked);
    }).change();
})

CUT
            );

        $this->setDefault('multi_title', ___('Membership'));
        $this->addText('multi_title', array('class' => 'el-wide'), array('help-id' => '#Configuring_Advanced_Settings'))
            ->setLabel(___("Multiple Order Title\n".
                "when user ordering multiple products,\n".
                "display the following on payment system\n".
                "instead of product name"));

        if (!Am_Di::getInstance()->modules->isEnabled('cc')) {
            $fs = $this->addFieldset('##3')
                ->setLabel(___('E-Mail Database Backup'));

            $fs->addSelect('email_backup_frequency', null, array('help-id' => '#Enabling.2FDisabling_Email_Database_Backup'))
                ->setLabel(___('Email Backup Frequency'))
                ->setId('select-email-backup-frequency')
                ->loadOptions(array(
                        '0' => ___('Disabled'),
                        'd' => ___('Daily'),
                        'w' => ___('Weekly')
                ));

            $di = Am_Di::getInstance();
            $backUrl = $di->rurl("backup/cron/k/{$di->security->siteHash('backup-cron', 10)}");

            $text = ___('It is required to setup a cron job to trigger backup generation');
            $html = <<<CUT
<div id="email-backup-note-text">
</div>
<div id="email-backup-note-text-template" style="display:none">
    $text <br />
    <strong>%EXECUTION_TIME% /usr/bin/curl $backUrl</strong><br />
</div>
CUT;

            $fs->addHtml('email_backup_note')->setHtml($html);

            $fs->addText('email_backup_address')
                ->setLabel(___('E-Mail Backup Address'));

            $this->addRule('callback', ___('Email is required if you have enabled Email Backup Feature'), array($this, 'checkBackupEmail'));

            $script = <<<CUT
(function($) {
    function toggle_frequency() {
        if (jQuery('#select-email-backup-frequency').val() == '0') {
            jQuery("input[name=email_backup_address]").closest(".row").hide();
        } else {
            jQuery("input[name=email_backup_address]").closest(".row").show();
        }

        switch (jQuery('#select-email-backup-frequency').val()) {
            case 'd' :
                jQuery('#email-backup-note-text').empty().append(
                    jQuery('#email-backup-note-text-template').html().
                        replace(/%FREQUENCY%/, 'daily').
                        replace(/%EXECUTION_TIME%/, '15 0 * * *')
                )
                jQuery('#email-backup-note-text').closest('.row').show();
                break;
            case 'w' :
                jQuery('#email-backup-note-text').empty().append(
                    jQuery('#email-backup-note-text-template').html().
                        replace(/%FREQUENCY%/, 'weekly').
                        replace(/%EXECUTION_TIME%/, '15 0 * * 1')
                )
                jQuery('#email-backup-note-text').closest('.row').show();
                break;
            default:
                jQuery('#email-backup-note-text').closest('.row').hide();
        }
    }

    toggle_frequency();

    jQuery('#select-email-backup-frequency').bind('change', function(){
        toggle_frequency();
    })

})(jQuery)
CUT;

            $this->addScript()->setScript($script);
        }

        $fs = $this->addFieldset()
                ->setLabel(___('Manually Approve'));

        $fs->addAdvCheckbox('manually_approve', null, array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___("Manually Approve New Users\n" .
            "manually approve all new users (first payment)\n" .
            "don't enable it if you have huge users base already\n" .
            "- all old members become not-approved"));

        $fs->addElement('email_link', 'manually_approve', array('rel'=>'manually_approve'), array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___('Require Approval Notification to User  (New Signup)'));

        $fs->addElement('email_link', 'manually_approve_admin', array('rel'=>'manually_approve'), array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___('Require Approval Notification to Admin (New Signup)'));

        $fs->addAdvCheckbox('manually_approve_invoice', null, array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___("Manually Approve New Invoices\n" .
                'manually approve all new invoices'));
        $maPc = array();
        foreach (Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions() as $id => $title) {
            $maPc['c' . $id] = $title;
        }
        if ($maPc) {
            $maOptions = array(
                ___('Products') => Am_Di::getInstance()->productTable->getOptions(),
                ___('Product Categories') => $maPc
            );
        } else {
            $maOptions = Am_Di::getInstance()->productTable->getOptions();
        }

        $fs->addMagicSelect('manually_approve_invoice_products', array('rel'=>'manually_approve_invoice'), array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___("Require Approval Only if Invoice has these Products (Invoice)\n" .
                'By default each invoice will be set as "Not Approved" ' .
                'although you can enable this functionality only for selected products'))
            ->loadOptions($maOptions);

        $fs->addElement('email_link', 'invoice_approval_wait_admin', array('rel'=>'manually_approve_invoice'), array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel('Require Approval Notification to Admin (Invoice)');

        $fs->addElement('email_link', 'invoice_approval_wait_user', array('rel'=>'manually_approve_invoice'), array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel('Require Approval Notification to User  (Invoice)');

        $fs->addElement('email_link', 'invoice_approved_user', array('rel'=>'manually_approve_invoice'), array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___('Invoice Approved Notification to User (Invoice)'));

        $fs->addTextarea('manually_approve_note', array('rows' => 8, 'class' => 'el-wide'))
            ->setId('form-manually_approve_note')
            ->setLabel(___("Manually Approve Note (New Signup/Invoice)\n" .
                'this message will be shown for customer after purchase. ' .
                'you can use html markup here'));

        $this->setDefault('manually_approve_note', <<<CUT
<strong>IMPORTANT NOTE: We review  all new payments manually, so your payment is under review currently.<br/>
You will get  email notification after payment will be approved by admin. We are sorry  for possible inconvenience.</strong>
CUT
            );

        $fs->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('[name=manually_approve_invoice], [name=manually_approve]').change(function(){
        jQuery('#form-manually_approve_note').closest('.row').
            toggle(jQuery('[name=manually_approve_invoice]:checked, [name=manually_approve]:checked').length > 0);
    }).change();
    jQuery("#manually_approve_invoice-0").change(function(){
        jQuery("[rel=manually_approve_invoice]").closest(".row").toggle(this.checked);
    }).change();
    jQuery("#manually_approve-0").change(function(){
        jQuery("[rel=manually_approve]").closest(".row").toggle(this.checked);
    }).change();
});
CUT
        );

        $fs = $this->addFieldset('##5')
            ->setLabel(___('Miscellaneous'));

        $fs->addAdvCheckbox('dont_check_updates', null, array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___("Disable Checking for aMember Updates"));

        $fs->addAdvCheckbox('signup_disable')
            ->setLabel(___("Disable New Signups"));

        $fs->addAdvCheckbox('product_paysystem')
            ->setLabel(___("Assign Paysystem to Product"));

        $fs->addAdvCheckbox('am3_urls', null, array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___("Use aMember3 Compatible Urls\n".
                    "Enable old style urls (ex.: signup.php, profile.php)\n".
                    "Usefull only after upgrade from aMember v3 to keep old links working.\n"
            ));

        $fs->addAdvCheckbox('allow_coupon_upgrades')
            ->setLabel(___("Allow usage of coupons for %sUpgrade paths%s",
                '<a href="'.Am_Di::getInstance()->url('admin-products/upgrades').'" class="link">', '</a>'));

        $fs->addAdvCheckbox('allow_restore')
            ->setLabel(___("Allow resume cancelled recurring subscription"));

        $fs->addAdvCheckbox('allow_cancel')
            ->setLabel(___("Allow cancel recurring subscription from user account"));

        if (!Am_Di::getInstance()->config->get('disable_resource_category')) {
            $fs->addInteger('resource_category_records_per_page', array('placeholder' => 15))
                ->setLabel(___('Resource Category Items per Page'));
        }

        if(!ini_get('suhosin.session.encrypt')) {
            $fs->addSelect('session_storage', null, array('help-id' => '#Configuring_Advanced_Options'))
            ->setLabel(___("Session Storage"))
            ->loadOptions(array(
                    'db' => ___('aMember Database (default)'),
                    'php' => ___('Standard PHP Sessions'),
            ));
        } else {
            $fs->addHTML('session_storage')
            ->setLabel(___('Session Storage'))
            ->setHTML('<strong>'.___('Standard PHP Sessions').'</strong> <em>'.___("Can't be changed because your server have suhosin extension enabled")."</em>");
        }
    }
}

class Am_Form_Setup_Loginpage extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('loginpage');
        $this->setTitle(___('Login Page'));
        $this->data['help-id'] = 'Setup/Login_Page';
    }

    function initElements()
    {
        $gr = $this->addGroup(null, null, array('help-id' => '#Login_Page_Options'))
            ->setLabel(___("Redirect After Login\n".
                "where customer redirected after successful\n".
                "login at %s", '<strong>'.Am_Di::getInstance()->url('login') . '</strong>'));
        $sel = $gr->addSelect('protect.php_include.redirect_ok',
                array('size' => 1, 'id' => 'redirect_ok-sel'), array('options' => array(
                        'first_url' => ___('First available protected url'),
                        'last_url' => ___('Last available protected url'),
                        'single_url' => ___('If only one protected URL, go directly to the URL. Otherwise go to membership page'),
                        'member' => ___('Membership Info Page'),
                        'url' => ___('Fixed Url'),
                        'referer' => ___('Page Where Log In Link was Clicked'),
                )));
        $gr->setSeparator(' ');
        $txt = $gr->addText('protect.php_include.redirect_ok_url',
                array('size' => 40, 'style'=>'display:none', 'id' => 'redirect_ok-txt'));
        $this->setDefault('protect.php_include.redirect_ok_url', ROOT_URL);
        $gr->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#redirect_ok-sel").change(function(){
        jQuery("#redirect_ok-txt").toggle(jQuery(this).val() == 'url');
    }).change();
});
CUT
        );

        $gr = $this->addGroup(null, null, array('help-id' => '#Login_Page_Options'))
            ->setLabel(___('Redirect After Logout'));

        $gr->setSeparator(' ');

        $gr->addSelect('protect.php_include.redirect_logout')
            ->setId('redirect_logout')
            ->loadOptions(array(
                'home' => ___('Home Page'),
                'url' => ___('Fixed Url'),
                'referer' => ___('Page Where Logout Link was Clicked')
            ));

        $gr->addText('protect.php_include.redirect', 'size=40')
            ->setId('redirect');

        $gr->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("#redirect_logout").change(function(){
        jQuery("#redirect").toggle(jQuery(this).val() == 'url');
    }).change();
});
CUT
        );

        $this->addAdvCheckbox('protect.php_include.remember_login', null, array('help-id' => '#Login_Page_Options'))
            ->setId('remember-login')
            ->setLabel(___("Remember Login\n".
                "remember username/password in cookies"));

        $this->addAdvCheckbox('protect.php_include.remember_auto', array('rel' => 'remember-login'), array('help-id' => '#Login_Page_Options'))
            ->setLabel(___("Always Remember\n".
                "if set to Yes, don't ask customer - always remember"));

        $this->setDefault('protect.php_include.remember_period', 60);
        $this->addInteger('protect.php_include.remember_period', array('rel' => 'remember-login'), array('help-id' => '#Login_Page_Options'))
        ->setLabel(___("Remember Period\n" .
                "cookie will be stored for ... days"));

        $this->addScript()
            ->setScript(<<<CUT
jQuery('#remember-login').change(function(){
    jQuery('[rel=remember-login]').closest('.row').toggle(this.checked)
}).change();
CUT
            );

        $gr = $this->addGroup();
        $gr->setSeparator(' ');
        $gr->setLabel(___("Force Change Password\n" .
            "ask user to change password every XX days"));
        $gr->addAdvCheckbox('force_change_password', array('id' => 'force_change_password'))
            ->setLabel(___('Force Change Password'));
        $gr->addStatic()->setContent('<span>' . ___('every'));
        $gr->addText('force_change_password_period', array('placeholder' => 30, 'size'=>3));
        $gr->addStatic()->setContent(___('days') . '</span>');

        $gr->addScript()
            ->setScript(<<<CUT
jQuery('#force_change_password').change(function(){
    jQuery(this).nextAll().toggle(this.checked);
}).change();
CUT
            );

        $this->addAdvCheckbox('auto_login_after_signup', null, array('help-id' => '#Login_Page_Options'))
            ->setLabel(___('Automatically Login Customer After Signup'));

        $this->setDefault('login_session_lifetime', 120);
        $this->addInteger('login_session_lifetime', null, array('help-id' => '#Login_Page_Options'))
            ->setLabel(___("User Session Lifetime (minutes)\n".
                "default - 120"))
            ->addRule('regex', ___('Please specify number greater then zero'), '/^[1-9][0-9]*$/');

        $gr = $this->addGroup(null, null, array('help-id' => '#Account_Sharing_Prevention'))
            ->setLabel(___("Account Sharing Prevention"));

        $gr->addStatic()->setContent('<div>');
        $gr->addStatic()->setContent(___('if customer uses more than') . ' ');
        $gr->addInteger('max_ip_count', array('size' => 4));
        $gr->addStatic()->setContent(' ' . ___('IP within') . ' ');
        $gr->addInteger('max_ip_period', array('size' => 5));
        $gr->addStatic()->setContent(' ' . ___('minutes %sdeny access for user%s and do the following', '<strong>', '</strong>'));
        $gr->addStatic()->setContent('<br /><br />');
        $ms = $gr->addMagicSelect('max_ip_actions')
            ->loadOptions(array (
                        'disable-user' => ___('Disable Customer Account'),
                        'email-admin' => ___('Email Admin Regarding Account Sharing'),
                        'email-user' => ___('Email User Regarding Account Sharing'),
                    ));
        $ms->setJsOptions('{onChange:function(val){
                jQuery("#max_ip_actions_admin").toggle(val.hasOwnProperty("email-admin"));
                jQuery("#max_ip_actions_user").toggle(val.hasOwnProperty("email-user"));
        }}');
        $gr->addStatic()->setContent('<br />');
        $gr->addStatic()->setContent('<div id="max_ip_actions_admin" style="display:none;">');
        $gr->addElement('email_link', 'max_ip_actions_admin')
            ->setLabel(___('Email Admin Regarding Account Sharing'));
        $gr->addStatic()->setContent('<div>'.___('Admin notification').'</div><br /></div><div id="max_ip_actions_user" style="display:none;">');
        $gr->addElement('email_link', 'max_ip_actions_user')
            ->setLabel(___('Email User Regarding Account Sharing'));
        $gr->addStatic()->setContent('<div>'.___('User notification').'</div><br /></div>');
        $gr->addSelect('max_ip_octets')->loadOptions(array(
            0 => ___('Count all IP as different'),
            1 => ___('Use first %d IP address octets to determine different IP (%s)', 3, '123.32.22.xx'),
            2 => ___('Use first %d IP address octets to determine different IP (%s)', 2, '123.32.xx.xx'),
            3 => ___('Use first %d IP address octets to determine different IP (%s)', 1, '123.xx.xx.xx'),
        ));
        $gr->addStatic()->setContent('</div>');

        $gr = $this->addGroup(null, null, array('help-id' => '#Bruteforce_Protection'))
        ->setLabel(___('Bruteforce Protection'));
        $gr->addStatic()->setContent('<div>');
        $this->setDefault('bruteforce_count', '5');
        $gr->addStatic()->setContent(___('if user enters wrong password') . ' ');
        $gr->addInteger('bruteforce_count', array('size' => 4));
        $gr->addStatic()->setContent(' ' . ___('times within') . ' ');
        $this->setDefault('bruteforce_delay', '120');
        $gr->addInteger('bruteforce_delay', array('size'=>5));
        $gr->addStatic()->setContent(' ' . ___('seconds, he will be forced to wait until next try'));
        $gr->addStatic()->setContent('</div>');

        $this->addElement('email_checkbox', 'bruteforce_notify')
            ->setLabel(___("Bruteforce Notification\n".
                "notify admin when bruteforce attack is detected"));

        if (Am_Recaptcha::isConfigured()) {
            $gr = $this->addGroup()
                ->setLabel(___("Enable ReCaptcha\n".
                    "on login and restore password forms for both admin and user interfaces"));
            $gr->addAdvCheckbox('recaptcha');
            $url = json_encode(Am_Di::getInstance()->url('admin-setup/ajax', array('_p' => $this->getPageId()), false));
            $gr->addHtml()->setHtml(<<<CUT
<a href="javascript:;" class="local" id="recaptcha-enable-link">Enable</a><a href="javascript:;" class="local" id="recaptcha-disable-link">Disable</a><div style="display:none" id="recaptcha-form"></div> <span id="recaptcha-need-save" style="display:none; color:#aaa">Do not forget to press button Save below to apply new settings</span>
<script type="text/javascript">
jQuery(function(){
    jQuery('[name=recaptcha]').hide();
    jQuery('[name=recaptcha]').change(function(){
        jQuery('#recaptcha-enable-link').toggle(!this.checked);
        jQuery('#recaptcha-disable-link').toggle(this.checked);
    }).change();
    jQuery(document).on('click', '#recaptcha-disable-link', function(){
        jQuery('[name=recaptcha]').prop('checked', false).change();
        jQuery('#recaptcha-need-save').show();
    });
    jQuery(document).on('click', '#recaptcha-enable-link', function(){
        jQuery('#recaptcha-form').load($url, function(){
            jQuery('#recaptcha-form').dialog({
                modal: true,
                title: "Enable reCaptcha",
                width: 340
            });
        });
    });
});
</script>
CUT
                );

        } else {
            $label = Am_Html::escape(___('Configure ReCaptcha to Enable this Option'));
            $url = $this->getDi()->url('admin-setup/recaptcha');
            $this->addHtml()
                ->setLabel(___("Enable ReCaptcha\n".
                    "on login and restore password forms"))
                ->setHtml(<<<CUT
<a href="$url" class="link">$label</a>
CUT
                    );
        }

        $this->addAdvCheckbox('reset_pass_no_disclosure')
            ->setLabel(___("Do not provide feedback on Reset Password Form\n" .
                'do not give info about existence of user with such email/login, it improve security but decrease quality of user experience'));

        $this->addAdvCheckbox('skip_index_page')
            ->setLabel(___("Skip Index Page if User is Logged-in\n" .
                'When logged-in user try to access /amember/index page, he will be redirected to /amember/member'))
            ->setId('skip-index-page');

        $this->addSelect('index_page')
            ->setLabel(___("Index Page\n" .
                "%sthis page will be public and do not require any login/password%s\n" .
                'you can create new pages %shere%s', '<strong>', '</strong>',
                '<a class="link" href="' . Am_Di::getInstance()->url('default/admin-content/p/pages') . '">', '</a>'))
            ->loadOptions(array(''=> '** ' . ___('Default Index Page'), '-1' => '** ' . ___('Login Page')) +
                Am_Di::getInstance()->db->selectCol("SELECT page_id AS ?, title FROM ?_page", DBSIMPLE_ARRAY_KEY))
            ->setId('index-page');

         $this->addSelect('video_non_member')
            ->setLabel(___("Video for Guest User\n" .
                'this video will be shown instead of actual video in case of ' .
                'guest (not logged in) user try to access protected video content. %sThis video ' .
                'will be public and do not require any login/password%s. ' .
                'You can add new video %shere%s', '<strong>', '</strong>',
                '<a class="link" href="' . Am_Di::getInstance()->url('default/admin-content/p/video') . '">', '</a>'))
            ->loadOptions(array(''=>___('Show Error Message')) +
                Am_Di::getInstance()->db->selectCol("SELECT video_id AS ?, title FROM ?_video", DBSIMPLE_ARRAY_KEY));
         $this->addSelect('video_not_proper_level')
            ->setLabel(___("Video for User without Proper Membership Level\n" .
                "this video will be shown instead of actual video in case of " .
                "user without proper access try to access protected video " .
                "content. %sThis video will be public and do not require any login/password%s. " .
                "You can add new video %shere%s", '<strong>', '</strong>',
                '<a class="link" href="' . Am_Di::getInstance()->url('default/admin-content/p/video') . '">', '</a>'))
            ->loadOptions(array(''=>___('Show Error Message')) +
                Am_Di::getInstance()->db->selectCol("SELECT video_id AS ?, title FROM ?_video", DBSIMPLE_ARRAY_KEY));

        $this->addAdvCheckbox('other_domains_redirect')
            ->setLabel(___("Allow Redirects to Other Domains\n".
                        "By default aMember does not allow to redirect to foreign domain names via 'amember_redirect_url' parameter.\n".
                        "These redirects are only allowed for urls within your domain name.\n".
                        "This is restricted to avoid potential security issues.\n"
                ));

        $this->addAdvCheckbox('allow_auth_by_savedpass')
            ->setLabel(___("Allow to Use Password Hash from 3ty part Scripts to Authenticate User in aMember\n" .
                "you need to enable this option only if you imported users from 3ty part script without known plain text password"));

        $fs = $this->addAdvFieldset()
            ->setLabel(___("Login Page Meta Data"));

        $fs->addText('login_meta_title', array('class' => 'el-wide'))
            ->setLabel(___("Login Page Title\nmeta data (used by search engines)"));
        $fs->addText('login_meta_keywords', array('class' => 'el-wide'))
            ->setLabel(___("Login Page Keywords\nmeta data (used by search engines)"));
        $fs->addText('login_meta_description', array('class' => 'el-wide'))
            ->setLabel(___("Login Page Description\nmeta data (used by search engines)"));
    }

    function ajaxAction($request)
    {
        $form = new Am_Form_Admin('recaptcha-validate');
        $form->setAction(Am_Di::getInstance()->url('admin-setup/ajax', false));
        $captcha = $form->addGroup(null, array('class' => 'row-wide'))
                ->setLabel(___("Please complete reCapatcha\nit is necessary to validate your reCaptcha config before enable it for login page, otherwise you can lock himself from admin interface"));
        $captcha->addHtml()->setHtml('<div style="text-align:center">');
        $captcha->addRule('callback', ___('Validation is failed. Please check %sreCAPTCHA configuration%s (Public and Secret Keys)', '<a href="' . Am_Di::getInstance()->url('admin-setup/recaptcha') . '">', '</a>'), function() use ($form) {
            foreach ($form->getDataSources() as $ds) {
                if ($resp = $ds->getValue('g-recaptcha-response'))
                    break;
            }

            $status = false;
            if ($resp)
                $status = Am_Di::getInstance()->recaptcha->validate($resp);
            return $status;
        });
        $captcha->addStatic('captcha')
            ->setContent(Am_Di::getInstance()->recaptcha
            ->render());
        $captcha->addHtml()->setHtml('</div>');

        $btn = $form->addGroup(null, array('class' => 'row-wide'));
        $btn->addHtml()->setHtml('<div style="text-align:center">');
        $btn->addSubmit('save', array('value' => ___('Confirm')));
        $btn->addHtml()->setHtml('</div>');

        $form->addHidden('_p', array('value' => $this->getPageId()));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    $('#recaptcha-validate').ajaxForm({target: '#recaptcha-form'});
});
CUT
            );

        if ($form->isSubmitted() && $form->validate()) {
            echo <<<CUT
<script type="text/javascript">
    jQuery('#recaptcha-form').dialog('close');
    jQuery('[name=recaptcha]').prop('checked', true).change();
    jQuery('#recaptcha-need-save').show();
</script>
CUT;
        } else {
            echo $form;
        }
    }
}

class Am_Form_Setup_Language extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('language');
        $this->setTitle(___('Languages'));
        $this->data['help-id'] = 'Setup/Languages';
    }

    function initElements()
    {
        $this->addAdvCheckbox('lang.display_choice', null, array('help-id' => '#Enabling.2FDisabling_Language_Choice_Option'))
            ->setLabel(___('Display Language Choice'));
        $list = Am_Di::getInstance()->languagesListUser;

        $this->setDefault('lang.default', 'en');
        $this->addSelect('lang.default', array('class' => 'am-combobox'), array('help-id' => '#Selecting.2FEditing_Default_Language'))
            ->setLabel(___('Default Locale'))
            ->loadOptions($list);

        $this->addSortableMagicSelect('lang.enabled', array('class' => 'am-combobox'), array('help-id' => '#Selecting_Languages_to_Offer'))
            ->setLabel(___("Available Locales\ndefines both language and date/number formats, default locale is always enabled"))
            ->loadOptions($list);

        $formats = array();
        foreach (array(
            "M j, Y",
            "j M Y",
            "F j, Y",
            "Y-m-d",
            "m/d/Y",
            "m/d/y",
            "d/m/Y",
            "d.m.Y",
            "d/m/y"
        ) as $f) {
            $formats[$f] = date($f) . " <span style=\"color:#c2c2c2; padding-left:.5em\">{$f}</span>";
        }

        $this->addAdvRadio('date_format')
            ->setLabel(___('Date Format'))
            ->loadOptions(array('' => ___('Use Locale Preference')) + $formats);

        $formats = array();
        foreach (array(
            "g:i a",
            "g:i A",
            "H:i"
        ) as $f) {
            $formats[$f] = date($f) . " <span style=\"color:#c2c2c2; padding-left:.5em\">{$f}</span>";;
        }

        $this->addAdvRadio('time_format')
            ->setLabel(___('Time Format'))
            ->loadOptions(array('' => ___('Use Locale Preference')) + $formats);
    }

    public function beforeSaveConfig(Am_Config $before, Am_Config $after)
    {
        $enabled = $after->get('lang.enabled');
        $default = $after->get('lang.default');
        if (!in_array($default, $enabled)) {
            $enabled[] = $default;
            $after->set('lang.enabled', $enabled);
        }
    }
}

class Am_Form_Setup_Theme extends Am_Form_Setup
{
    protected $themeId;

    public function __construct($themeId)
    {
        $this->themeId = $themeId;
        parent::__construct('themes-'.$themeId);
    }

    public function prepare()
    {
        parent::prepare();
        $this->addFieldsPrefix('themes.'.$this->themeId.'.');
    }
}

class Am_Form_Setup_Recaptcha extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('recaptcha');
        $this->setTitle(___('reCAPTCHA'));
    }

    function initElements()
    {
        $this->addText("recaptcha-public-key", array('class'=>'el-wide'), array('help-id' => 'Setup/ReCaptcha'))
            ->setLabel("reCAPTCHA Site key\n" .
            "you can get it in your account on <a href='http://www.google.com/recaptcha' class='link' target='_blank' rel=\"noreferrer\">reCAPTCHA site</a>, you may need to sign up (it is free) if you have no account yet")
        ->addRule('required', ___('This field is required'));
        $this->addText("recaptcha-private-key", array('class'=>'el-wide'), array('help-id' => 'Setup/ReCaptcha'))
            ->setLabel("reCAPTCHA Secret key\n" .
            "you can get it in your account on <a href='http://www.google.com/recaptcha' class='link' target='_blank' rel=\"noreferrer\">reCAPTCHA site</a>, you may need to sign up (it is free) if you have no account yet")
        ->addRule('required', ___('This field is required'));
        $this->addAdvRadio('recaptcha-theme')
            ->loadOptions(array(
                'light' =>  'light',
                'dark' =>  'dark'
            ))->setLabel(___('reCAPTCHA Theme'));
        $this->addAdvRadio('recaptcha-size')
            ->loadOptions(array(
                'normal' =>  'normal',
                'compact' =>  'compact'
            ))->setLabel(___('reCAPTCHA Size'));
        $this->setDefault('recaptcha-size', 'normal');
        $this->setDefault('recaptcha-theme', 'light');
    }

    function getReadme()
    {
        $url = $this->getDi()->url('admin-saved-form');
        return <<<CUT
<strong>reCAPTCHA configuration</strong>

Complete instructions can be found here:
<a href='http://www.amember.com/docs/Setup/ReCaptcha' target='_blank'>http://www.amember.com/docs/Setup/ReCaptcha</a>

Use <a href="$url">Forms Editor</a> in order to add reCAPTCHA field to signup/renewal form.
It will appear as new form brick within forms editor.
CUT;
    }
}

class Am_Form_Setup_PersonalData extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('personal-data');
        $this->setTitle(___('Personal Data'));
    }

    function initElements()
    {
        $this->addAdvCheckbox('enable-account-delete', ['id'=>'enable-account-delete'])
            ->setLabel(___("Enable 'Delete Personal Data' functionality for admins/users\n"
                . "Allow for user to request 'Personal Data' to be removed from system\n"
                ));

        $fs = $this->addFieldset('', ['id' => 'personal-data-removal'])->setLabel('Personal Data Removal Settings');

        $fs->addAdvCheckbox('hide-delete-link', ['id'=>'hide-delete-link'])
            ->setLabel(___("Hide  'Delete Personal Data' link in member's area\n"
                . "Do not allow for users to delete personal data on their own\n"
                . "Only admins should have ability to do this"
                ));

        $fs->addSelect('account-removal-method', ['class'=>'am-combobox', 'id'=>'account-removal-method'])
            ->setLabel(___('Removal Method'))
            ->loadOptions([
                'delete'         => ___('Automatically remove account and all associated Personal Data'),
                'delete-request' => ___('Send removal request to Site Admin'),
                'anonymize'      => ___('Automatically anonymize Personal Data')
            ]);

        $fs->addMagicSelect('keep-personal-fields', ['class' => 'am-combobox', 'id'=>'keep-fields'])
            ->setLabel(___("Do not delete/anonymize  these fields\n"
            . "For legal reasons you  may need to keep some information about user\n"
            . "Select Fields that should not be deleted or anonymized"))
            ->loadOptions($this->getDi()->userTable->getPersonalDataFieldOptions());

        if($this->getDi()->modules->isEnabled('aff'))
        {
            $fs->addAdvCheckbox('keep-payout-info', ['id'=>'keep-payout'])->setLabel(___("Do not delete payout details\n"
                . "For legal reasons you may need to keep user's payout details\n"
                . "aMember will not delete user's payout detail fields, user name  and  address\n"
                . "if user receive affiliate payout from you prevously"));
        }
            $fs->addAdvCheckbox('keep-access-log', ['id'=>'keep-access-log'])->setLabel(___("Do not delete user's access log\n"
                . "For legal reasons you may need to keep user's access log\n"
                . "aMember will not delete user's access log (urls that user visited and user's IP address)\n"));

        $fs->addElement('email_link', 'delete_personal_data_notification')
            ->setLabel(___("Admin Notification Message\n"
                . "Notification wil lbe sent if you choose \n"
                . "'Send removal request to Site Admin' method\n"
                . "or if amember was unable to remove Personal Data automatically"));


        $fs = $this->addFieldset('', ['id' => 'personal-data-download'])->setLabel('Allow To Download Personal Data');
        $fs->addAdvCheckbox('enable-personal-data-download', ['id'=>'enable-data-download'])
            ->setLabel(___("Show Personal Data Download Link in Useful Links Block\n"
                . "user will see Personal Data Download link \n"
                . "and will be able to get XML document with Personal Data\n"
                . "below you can select what fields will be included in document"));

        $fs->addMagicSelect('personal-data-download-fields', ['class' => 'am-combobox', 'id'=>'download-fields'])
            ->setLabel(___("These fields will be inclulded in XML document\n"
            . "If none selected, aMember will include all listed fields"))
            ->loadOptions($this->getDi()->userTable->getPersonalDataFieldOptions());


        $fs = $this->addFieldset('', ['id' => 'agreement-documents'])->setLabel('Agreement Documents');
        $this->addHTML()->setHTML(
            ___('Use %sAgreement  Editor%s to create "End User Agreement",  "Privacy Policy" or "Terms of Use"',
                sprintf("<a href='%s'>", $this->getDi()->url('admin-agreement')), '</a>')
            )->setLabel('Agreement Editor');

        /**
        $fs = $this->addFieldset('', ['personal-data-recs'])->setLabel(___('Information'));
        $fs->addHTML()->setHTML($this->checkSignupForms())->setLabel(___("Signup Forms\n"
            . "Please check description below"
            ));

        if($this->getDi()->modules->isEnabled('newsletter'))
        {
            $fs->addHTML()->setHTML($this->checkNewsleterLists())->setLabel(___("Newsletter Lists\n"
                . "Please check descripiton below"));

        }
           **/

        $this->addScript()->setScript(<<<PDJS
jQuery(function(){
    jQuery("#enable-account-delete").on("change", function(){
            jQuery("#personal-data-removal").toggle(jQuery(this).is(":checked"));
    }).trigger('change');
    jQuery('#account-removal-method').on('change', function(){
        jQuery("#keep-fields, #keep-payout, #keep-access-log").closest('.row').toggle(jQuery(this).val() == 'anonymize' || jQuery(this).val() == 'delete-request');
    }).trigger('change');
    jQuery('#enable-data-download').on('change', function(){
            jQuery("#download-fields").closest('.row').toggle(jQuery(this).is(':checked'));
    }).trigger('change');

});
PDJS
            );

    }

    function checkSignupForms()
    {
        $formsCount = $termsMissing = 0;
        foreach($this->getDi()->savedFormTable->selectObjects("select * from ?_saved_form") as $form)
        {
            $formsCount++;
            if($form->findBrickById('agreement'))
            {
                $termsMissing++;
            }
        }
        if($termsMissing){
            $out="<p style='color:red'>".___("%s out of %s Signup Forms doesn't have agreement brick included", $termsMissing, $formsCount)."</p>";
        }else{
            $out="<p style='color:green'>".___("All Signup Forms have agreement bricks")."</p>";
        }
        return $out;
    }

    function checkNewsleterLists()
    {
        $wrongLists = $this->getDi()->newsletterListTable->findBy(['auto_subscribe' => 1, 'disabled'=>0]);
        if(count($wrongLists)){
            $out.="<p style='color:red'>".___("You have %s newsletter lists that auto-subscribe without user attention", count($wrongLists))."</p>";
        }else{
            $out="<p style='color:green'>".___("You do not have auto-subscribe lists")."</p>";
        }
        return $out;
    }

    public
        function getReadme()
    {
        return <<<PDREADME
<strong>Personal Data Settings</strong>
Removal Method:

 <b>Automatically remove account and all associated Personal Data</b>
 Account will be removed from system completly, all related Personal Data will be deleted(incl. access and invoice logs),
 all recurring subscirptions will be cancelled. This action can't be reversed and not recommended if you need to keep invoices for tax/vat purposes.

 <b>Send removal request to Site Admin</b>
 This action does not remove any data from system. Removal request will be sent by email to Site Admin. All further actions should be done by admin manually.

 <b>Automatically anonymize Personal Data</b>
 User Personal Data will be anonymized, access/invoice  log will be deleted. Using this method, invoices and payments won;t be deleted.
 All necessary information that is required for tax/vat purposes(incl. user country and IP address) will not be affected.

<b>Notify 3rd parties for erasure</b>

You have to inform all third parties that you have deleted user's Personal Data.
If you use integration plugins like wordpress, or add user to newsletter lists in mailchimp,
user's Personal Data have to be removed from third-parties too.
aMember will try to do this automatically but that process may require your manual attention.
If automatic process has failed for some reason, new "Personal Data Delete" request will be added,
admin will also get email notification about failure.

<b>Signup Forms</b>

According to GDPR regulations:
Individuals have the right to be informed about the collection and use of their personal data.

You must provide individuals with information including:
 your purposes for processing their personal data,
 your retention periods for that personal data, and who it will be shared with.

You must provide privacy information to individuals at the time you collect their personal data from them.
Make sure that you have added agreement brick with "Terms of Use" and "Privacy Policy" to each Signup Form.
Make sure that agreement checkbox is not pre-selected as this does not count as “consent”

You also may need to re-work your "Terms of Use" and "Privacy Policy" information that you provide on signup page.
We have implemented special plugin for this case. Plugin allow to re-request agreement consent from user.
You can find it in <a href='%root_surl%/admin-plugins/' target='_top'>aMember CP -> Setup -> Plugins</a>.  Plugin name: force-i-agree

<b>Newsletter Lists</b>

GDPR states clearly that:
 Consent should be given by a clear affirmative act establishing a freely given, specific, informed and unambiguous indication
 of the data subject’s agreement to the processing of personal data relating to him or her, such as by a written statement,
 including by electronic means, or an oral statement

So if you add user to any newsletter list you should clearly mention that on signup page.
You should not add user to list without user attention so make sure that you do not enable
"Auto-Subscribe users to list" in newsletter list configuration.

<b>Personal Information that is being collected by default</b>

   1. None of Personal Information is being sent to CGI Central or amember.com
   2. aMember PRO uses jQuery  CDN to load jQuery js library, so user's IP address could be visible for jQuery.com
   3. By default aMember PRO asks to provide customer Email, Name and Username unless you have changed this in forms editor at amember CP -> Forms Editor.
   4. Customer's IP address is being collected by system.
   5. Above information could be passed to payment processor that you have enabled at amember CP -> Setup -> Plugins -> Paysystems.
   6. aMember PRO do not store any cookies to track customers(unless you have conversion track or google analytics plugins enabled).
       Only sessions cookies are being set. These cookies are required by  system  in order to provide services for customer.
       Some other plugins like facebook, google connect, twitch connect may add own cookies and tracking info.

   You may need to update your site's Privacy Policy according to above information.


PDREADME;
    }
}