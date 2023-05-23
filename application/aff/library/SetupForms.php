<?php

class Am_Form_Setup_Aff extends Am_Form_Setup
{

    function __construct()
    {
        parent::__construct('aff');
        $this->setTitle(___('Affiliates'));
        $this->data['help-id'] = 'Setup/Affiliates';
    }

    function initElements()
    {
        $el = $this->addAdvRadio('aff.model', null, array('help-id' => '#Affiliate_Program_Model'))
            ->setLabel('Affiliate Program Model');
        $el->addOption(<<<CUT
          <b>Default</b><br />user assigned to affiliate on signup. All following payments of that user will generate commission to affiliate who referred this user initially<br />
CUT
   , Bootstrap_Aff::MODEL_DEFAULT);
        $el->addOption(<<<CUT
          <b>Last Click Wins</b><br />payment will generate commission only if affiliate cookie exists. aMember will get affiliate information from cookie and not from user record. User still will be assigned to affiliate (so 2-tier commission will be generated for parent affiliate still), but if there is no affiliate cookie referred affiliate will not receive commission for that payment<br />
CUT
   , Bootstrap_Aff::MODEL_LAST_CLICK_WINS);
        $el->addOption(<<<CUT
          <b>Hybrid</b><br />get affiliate from cookie, if empty use affiliate from profile<br />
CUT
   , Bootstrap_Aff::MODEL_HYBRID);

        $this->setDefault('aff.model', Bootstrap_Aff::MODEL_DEFAULT);

        $this->addSelect('aff.signup_type', null, array('help-id' => '#Affiliate_Options'))
            ->setLabel(___("Affiliates Signup Type"))
            ->setId('aff-signup-type')
            ->loadOptions(
                array(
                    '' => ___('Default - user clicks a link to become affiliate'),
                    1 => ___('All new users automatically become affiliates'),
                    2 => ___('Only admin can enable user as an affiliate'),
                )
        );

        $form = Am_Di::getInstance()->savedFormTable->findFirstByType('aff');
        $edit_label = Am_Html::escape(___('Edit'));
        $edit_url = $this->getDi()->url('admin-saved-form', array(
                '_s_a' => 'edit',
                '_s_b' => $this->getDi()->url('admin-setup/aff', null, false),
                '_s_id' => $form->pk()
        ));
        $this->addStatic()
            ->setLabel(___('Affiliate Signup Form'))
            ->setContent(<<<CUT
<a href="$edit_url" class="link" id="aff-signup-form-edit">$edit_label</a>
CUT
        );
        $this->addElement('email_link', 'aff.manually_approve', array('rel' => 'aff-approve'))
            ->setLabel(___("Require Approval Notification to Affiliate"));
        $this->addElement('email_link', 'aff.manually_approve_admin', array('rel' => 'aff-approve'))
            ->setLabel(___("Require Approval Notification to Admin"));

        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('#aff-signup-type').change(function(){
        jQuery('[rel=aff-approve]').closest('.row').toggle(jQuery(this).val() == 2);
        jQuery('#aff-signup-form-edit').closest('.row').toggle(jQuery(this).val() != 1)
    }).change();
})
CUT
        );

        $this->addElement('email_checkbox', 'aff.registration_mail')
            ->setLabel(___("Affiliate Registration E-Mail"));

        $this->addElement('email_checkbox', 'aff.admin_registration_mail')
            ->setLabel(___("Affiliate Registration Notification to Admin"));

        $this->setDefault('aff.cookie_lifetime', 365);
        $this->addInteger('aff.cookie_lifetime', null, array('help-id' => '#Affiliate_Options'))
            ->setLabel(___("Affiliate Cookie Lifetime\n" .
                    "days to store cookies about referred affiliate"))
            ->addRule('regex', ___('Please specify number less then 9999'), '/^[0-9]{0,4}$/');

        $this->addInteger('aff.commission_days')
            ->setLabel(___("User-Affiliate Relation Lifetime\n" .
                    "how long (in days) calculate commission for referred affiliate (default: 0 - forever)"));

        $this->setDefault('aff.commission_days', 0);

        $fs = $this->addFieldset()
            ->setLabel(___('Payout'))
            ->setId('payout');

        $url = $this->getDi()->url('aff/admin-payout');
        $fs->addHtml(null, array('class' => 'no-label row-highlight'))
            ->setHtml(___('aMember generate payout reports automatically according your settings below. ' .
                    'Then you can use these reports to make real payout. You can find list of payout reports %shere%s. ' .
                    'User without defined valid payout method will not be included to payout report until he fill payout ' .
                    'method in member area.', '<a class="link" href="' . $url . '">', '</a>'));

        $el = $fs->addMagicSelect('aff.payout_methods', array(
                'multiple' => 'multiple'), array('help-id' => '#Accepted_Payout_methods'))
            ->setLabel(___('Accepted Payout Methods'))
            ->loadOptions(Am_Aff_PayoutMethod::getAvailableOptions());

        $el = $fs->addMagicSelect('aff.payout_day', null, array('help-id' => '#Affiliate_Payout_Options'))
            ->setLabel(___("Affiliates Payout Day\n" .
                "choose a day of month when payout is generated"));
        for ($i = 1; $i <= 28; $i++)
            $el->addOption(___("%d-th day", $i), $i . 'd');
        $wd = Am_Di::getInstance()->locale->getWeekdayNames();
        for ($i = 0; $i < 7; $i++)
        {
            $el->addOption(___('Every %s', $wd[$i]), $i . 'w');
        }
        for ($i = 0; $i < 7; $i++)
        {
            $el->addOption(___('Bi-Weekly (on %s)', $wd[$i]), $i . 'W');
        }

        $fs->addElement('email_link', 'aff.new_payouts')
            ->setLabel(___('New Affiliate Payout to Admin'));

        $fs->addInteger('aff.payout_min', array('placeholder' => ___('Unlimited')), array('help-id' => '#Affiliate_Payout_Options'))
            ->setLabel(___("Minimum Payout\n" .
                    'minimal commission amount earned by affiliate to include it to payout report'));


        $fs->addElement('email_checkbox', 'aff.notify_payout_empty')
            ->setLabel(___("Empty Payout Method Notification to User\n" .
                    "send email to user in case he has commission but did not define payout method yet.\n" .
                    'This email will be sent only once.'));

        $fs->addElement('email_checkbox', 'aff.notify_payout_paid')
            ->setLabel(___("Affiliate Payout Paid Notification to User\n" .
                    "send email to user when his payout is marked as paid"));

        $fs->addInteger('aff.payout_delay_days', null, array('help-id' => '#Affiliate_Payout_Options'))
            ->setLabel(___("Delay Payout (days)\n" .
                    'number of days that should go through before commision is included to payout report'));

        $this->setDefault('aff.payout_delay_days', 30);
        $fs = $this->addAdvFieldset('', ['class'=>'custom-payout-delay'])->setLabel('Custom Payout Delay for User Groups');
        $vals = $this->getDi()->config->get('aff.custom_payout_delay');

        foreach($this->getDi()->userGroupTable->getOptions() as $id=>$gr)
        {
            $fs->addInteger("aff.custom_payout_delay.".$id, ['size'=>3])->setLabel("#{$id} - {$gr}")
               ->setValue(isset($vals[$id])?$vals[$id] : "");
        }

        $this->addEpilog(<<<CUT
<style>
    @media all and (min-width:1100px) {
        .custom-payout-delay .fieldset .row {
            float:left;
            width:50%;
        }
    }
    .custom-payout-delay .fieldset {
      background: #f5f5f5;
        overflow: hidden;
    }

</style>

CUT
            );


        $fs = $this->addFieldset()
            ->setLabel(___('Commission'));

        $gr = $fs->addGroup('', array('id' => 'commission'), array('help-id' => '#Affiliate_Payout_Options'))->setLabel(___('Default Commission'));
        $gr->addStatic()->setContent('<div>');
        if (Am_Di::getInstance()->affCommissionRuleTable->hasCustomRules())
        {
            $gr->addStatic()->setContent(
                ___('Custom Commission Rules added') . ' ');
        } else
        {
            $rule = Am_Di::getInstance()->affCommissionRuleTable->findFirstBy(array(
                'type' => AffCommissionRule::TYPE_GLOBAL,
                'tier' => 0));
            $gr->addStatic()->setContent(___('First Payment (calculated for first payment in each invoice)') . ' ');
            $first = $gr->addElement(new Am_Form_Element_AffCommissionSize('aff_comm[first]', null, 'first_payment'));
            $gr->addStatic()->setContent(' ' . ___('Rebill') . ' ');
            $second = $gr->addElement(new Am_Form_Element_AffCommissionSize('aff_comm[recurring]', null, 'recurring'));
            $gr->addStatic()->setContent(
                ' ' . ___('or') . ' ');
            if ($rule && !$this->isSubmitted())
            {
                $first->getElementById('first_payment_c-0')->setValue($rule->first_payment_c);
                $first->getElementById('first_payment_t-0')->setValue($rule->first_payment_t);
                $second->getElementById('recurring_c-0')->setValue($rule->recurring_c);
                $second->getElementById('recurring_t-0')->setValue($rule->recurring_t);
            }
        }
        $gr->addStatic()->setContent(
            '<a class="link" href="' . $this->getDi()->url('aff/admin-commission-rule').'">'
            . ___('Edit Custom Commission Rules')
            . '</a>'
        );
        $gr->addStatic()->setContent('</div>');

        $fs->addAdvCheckbox('aff.commission_include_tax')
            ->setLabel(___("Calculate Affiliate Commissions from Totals Including Tax\n" .
                    "by default commission calculated from amounts before tax"));

        $fs->addElement('email_checkbox', 'aff.mail_sale_admin', null, array('help-id' => '#Setting_Up_Commission_Notification_Emails'))
            ->setLabel(___("E-Mail Commission to Admin"));

        $fs->addElement('email_checkbox', 'aff.mail_sale_user', null, array('help-id' => '#Setting_Up_Commission_Notification_Emails'))
            ->setLabel(___('E-Mail Commission to Affiliate'));

        $fs = $this->addFieldset()
            ->setLabel(___('Miscellaneous'));

        $this->addAdvCheckbox('aff.affiliate_can_view_details', null, array('help-id' => '#Affiliate_Payout_Options'))
            ->setLabel(___("Affiliate Can View Sales Details\n" .
                    'Leave this checkbox unselected to restrict affiliates from seeing their sales details'));
        $this->addAdvCheckbox('aff.affiliate_can_view_email')
            ->setLabel(___("Affiliate Can View Email of User\n" .
                    'Leave this checkbox unselected to restrict affiliates from seeing user emails'));
        $this->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    jQuery('[name$=affiliate_can_view_details]:checkbox').change(function(){
        jQuery('[name$=affiliate_can_view_email]').closest('.row').toggle(this.checked);
    }).change();
});
CUT
            );
        $gr = $this->addGroup()
            ->setLabel(___("Allow Affiliates to redirect Referrers to any url"));
        $gr->addSelect('aff.custom_redirect', array('id' => 'custom_redirect'))
            ->loadOptions(
                array(
                    Bootstrap_Aff::AFF_CUSTOM_REDIRECT_DISABLED => ___('Disabled'),
                    Bootstrap_Aff::AFF_CUSTOM_REDIRECT_ALLOW_SOME_DENY_OTHERS => ___('Allow for some affiliates, disallow for others'),
                    Bootstrap_Aff::AFF_CUSTOM_REDIRECT_DENY_SOME_ALLOW_OTHERS => ___('Disallow for some affiliates, allow for others'),
        ));
        $gr->addHtml()->setHtml('<br /><br />');
        $gr->addAdvCheckbox('aff.custom_redirect_other_domains', null, array('content' => ___('Allow redirecting to external URLs')));
        $this->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    jQuery('#custom_redirect').change(function(){
        jQuery(this).nextAll().toggle($(this).val() != 0);
    }).change();
})
CUT
            );

        $this->addHtmlEditor('aff.intro', null, array('showInPopup' => true))
            ->setLabel(___("Intro Text on Affiliate Info Page"));

        $this->addAdvCheckbox('aff.tracking_code')
            ->setLabel(___("Enable Click Tracking Code\n" .
                    'Enable ability to track affiliate clicks on any page on your site'));

        $url = $this->getDi()->url('signup');
        $code = htmlentities(Am_Di::getInstance()->modules->loadGet('aff')->getClickJs());
        $this->addHTML('tracking_code', array('class' => 'row-wide'))->setHTML(<<<EOT
<p>To track affiliate referrals on any page of your site your site pages have to contain click tracking code. Insert this JS code to the footer on every site's page just before &lt;/body&gt; tag:</p>
<div class='info'><pre>{$code}</pre></div>
<p>Then your affiliate can use any url of your site as affiliate link. They just need to append GET parameter <strong>?ref=username</strong> to it eg.
$url<strong>?ref=username</strong> where username is actual username of affiliate.</p>
EOT
        )->setLabel(___('Click Tracking Code'));
        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('input[type=checkbox][name=aff___tracking_code]').change(function(){
        jQuery('#row-tracking_code-0, #row-unsubscribe_txt-0').toggle(this.checked)
    }).change();
})
CUT
        );
        $this->addAdvCheckbox('aff.keywords')->setLabel(___("Enable Keywords Support\n" .
                'Enable ability to track traffic sources adding keywords to url'));
        $this->setDefault('aff.keywords', 1);
    }

    public function beforeSaveConfig(Am_Config $before, Am_Config $after)
    {
        $arr = $after->getArray();

        if (empty($arr['aff_comm']))
            return;

        $this->rule = Am_Di::getInstance()->affCommissionRuleTable->findFirstBy(array(
            'type' => AffCommissionRule::TYPE_GLOBAL,
            'tier' => 0));
        if (empty($this->rule))
        {
            $this->rule = Am_Di::getInstance()->affCommissionRuleTable->createRecord();
            $this->rule->type = AffCommissionRule::TYPE_GLOBAL;
            $this->rule->tier = 0;
            $this->rule->comment = "Default Commmission";
        }
        foreach ($arr['aff_comm'] as $aa)
            foreach ($aa as $k => $v)
                $this->rule->set($k, $v);
        unset($arr['aff_comm']);

        $after->setArray($arr);
    }

    public function afterSaveConfig(Am_Config $before, Am_Config $after)
    {
        if (!empty($this->rule)) {
            $this->rule->sort_order = 1000;
            $this->rule->save();
        }
    }
}