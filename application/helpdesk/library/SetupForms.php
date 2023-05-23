<?php

class Am_Form_Setup_Helpdesk extends Am_Form_Setup
{
    function __construct()
    {
        parent::__construct('helpdesk');
        $this->setTitle(___('Helpdesk'));
        $this->data['help-id'] = 'Setup/Helpdesk';
    }

    function initElements()
    {
        $fs = $this->addFieldset()
            ->setLabel('Email From');

        $fs->addText('helpdesk.email_from')
            ->setLabel(___(
                    "Outgoing Email Address\n".
                    "used as From: address for sending e-mail messages\n".
                    "to customers. If empty, [Admin E-Mail Address] is used"
            ))
            ->addRule('callback', ___('Please enter valid e-mail address'), array('Am_Validate', 'empty_or_email'));

        $fs->addText('helpdesk.email_name', array ('class' => 'el-wide'))
            ->setLabel(___(
                    "E-Mail Sender Name\n" .
                    "used to display name of sender in outgoing e-mails"
            ));

        $fieldSetNotifications = $this->addFieldset()
                ->setLabel(___('Notifications'));

        $fieldSetNotifications->addElement('email_checkbox', 'helpdesk.notify_new_message', null, array('help-id' => '#Enabling.2FDisabling_Customer_Notifications'))
            ->setLabel(___("Send Notification about New Messages to Customer\n" .
                    "aMember will email a notification to user " .
                    "each time admin responds to a user ticket"));
        $this->setDefault('helpdesk.notify_new_message', 1);

        $fieldSetNotifications->addElement('email_checkbox', 'helpdesk.notify_new_message_admin')
            ->setLabel(___("Send Notification about New Messages to Admin\n" .
                    "aMember will email a notification to admin " .
                    "each time user responds to a ticket"));
        $this->setDefault('helpdesk.notify_new_message_admin', 1);

        $fieldSetNotifications->addElement('email_checkbox', 'helpdesk.new_ticket')
            ->setLabel(___("New Ticket Autoresponder to Customer\n" .
                    "aMember will email an autoresponder to user " .
                    "each time user create new ticket"));

        $fieldSetNotifications->addElement('email_checkbox', 'helpdesk.notify_assign')
            ->setLabel(___("Send Notification When Ticket is Assigned to Admin\n" .
                    "aMember will email a notification to admin " .
                    "each time ticket is assigned to him"));

        $fieldSetConversation = $this->addFieldset()
                ->setLabel(___('Conversation'));

        $gr = $fieldSetConversation->addGroup()
            ->setLabel(___("Admin Can Edit His Messages"));
        $gr->addAdvCheckbox('helpdesk.can_edit');
        $gr->addHtml()->setHtml('<span id="can_edit_timeout-wrapper"> within ');
        $gr->addText('helpdesk.can_edit_timeout', array('size'=>3, 'placeholder' => 5));
        $gr->addHtml()->setHtml(' minutes after post</span>');

        $gr->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('[name=helpdesk___can_edit]').change(function(){
        jQuery('#can_edit_timeout-wrapper').toggle(this.checked);
    }).change()
});
CUT
            );

        $fieldSetConversation->addAdvCheckbox('helpdesk.add_signature')
            ->setLabel(___('Add Signature to Response'));

        $fieldSetConversation->addTextarea('helpdesk.signature', array('rows'=>3, 'class'=>'el-wide'))
            ->setLabel(___("Signature Text\n" .
                    "You can use the following placeholders %name_f%, %name_l% " .
                    "it will be expanded to first and last name of admin in operation"));

        $this->addScript('script')
            ->setScript(<<<CUT
(function($){
    jQuery(function(){
        jQuery("[id='helpdesk.add_signature-0']").change(function(){
            jQuery("[id='helpdesk.signature-0']").closest('div.row').toggle(this.checked);
        }).change()
    })
})(jQuery)
CUT
        );

        $fieldSetConversation->addAdvCheckbox('helpdesk.disclosure_admin')
            ->setLabel(___("Disclosure Admin real name in user interface\n" .
                "otherwise only word Administrator is shown"));

        $fieldSetConversation->addAdvCheckbox('helpdesk.does_not_quote_in_reply')
            ->setLabel(___('Does Not Quote Message in Reply'));

        $fieldSetConversation->addAdvCheckbox('helpdesk.does_not_allow_attachments')
            ->setLabel(___('Does Not Allow to Upload Attachments for Users'));

        $fieldSetConversation->addAdvCheckbox('helpdesk.user_can_reopen')
            ->setLabel(___('User Can Re Open Closed Tickets'));

        $gr = $fieldSetConversation->addGroup()
            ->setLabel(___("Autoclose Tickets Due to Inactivity"));
        $gr->addAdvCheckbox('helpdesk.autoclose')
            ->setId('helpdesk_autoclose');
        $gr->addStatic()->setContent(sprintf('<span class="helpdesk_autoclose_hours"> %s </span>', ___("after")));
        $gr->addText('helpdesk.autoclose_period', array('class'=>'helpdesk_autoclose_hours', 'size' => 3, 'placeholder'=>70));
        $gr->addStatic()->setContent(sprintf('<span class="helpdesk_autoclose_hours"> %s </span>', ___("hours")));

        $fieldSetConversation->addElement('email_checkbox', 'helpdesk.notify_autoclose')
            ->setLabel(___("Send Autoclose Notification to User\n" .
                    "aMember will email an autoresponder to user " .
                    "when ticket is closed due to inactivity"));

        $this->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery('#helpdesk_autoclose').change(function(){
        jQuery('.helpdesk_autoclose_hours').toggle(this.checked);
        jQuery('input[name=helpdesk___notify_autoclose]').closest('.row').toggle(this.checked);
    }).change();
})
CUT
            );

        $fieldSetFeatures = $this->addFieldset()
                ->setLabel(___('Features'));

        $fieldSetFeatures->addAdvCheckbox('helpdesk.live')
            ->setLabel(___("Enable Live Conversation (experimental)\n" .
                "update conversation instantly without page reload (can consume more server resources)"));

        $fieldSetFeatures->addAdvCheckbox('helpdesk.show_gravatar')
            ->setId('gravatar')
            ->setLabel(___("Show Gravatars in Ticket Conversation\n" .
                'more details about gravatar can be found %shere%s',
                '<a href="http://gravatar.com/support/what-is-gravatar/" class="link" target="_blank" rel="noreferrer">',
                '</a>'));

        if (Am_Di::getInstance()->plugins_misc->isEnabled('avatar')) {
            $fieldSetFeatures->addAdvCheckbox('helpdesk.show_avatar')
                ->setId('avatar')
                ->setLabel(___("Show Avatars in Ticket Conversation\n" .
                    'this option has priority over gravatar if enabled'));
            $this->addScript()->setScript(<<<CUT
jQuery(function($){
    jQuery('#avatar').change(function(){
        jQuery('#gravatar').prop('disabled', this.checked ? 'disabled' : null);
        jQuery('#gravatar').closest('div.row').toggleClass('disabled', this.checked);
    }).change();
});
CUT
                );
        }

        $fieldSetFeatures->addAdvCheckbox('helpdesk.does_not_require_login')
            ->setLabel(___("Does Not Require Login to Access FAQ Section\n" .
                'make it public'));

        $fieldSetFeatures->addAdvCheckbox('helpdesk.does_not_show_faq_tab')
            ->setLabel(___('Does Not Show FAQ Tab in Member Area'));

        $fieldSetFeatures->addAdvCheckbox('helpdesk.show_faq_search')
            ->setLabel(___('Show Search Function in FAQ'));

        $this->addHtmlEditor('helpdesk.intro', null, array('showInPopup' => true))
            ->setLabel(___("Intro Text on Helpdesk Page"));
        $this->setDefault('helpdesk.intro', 'We answer customer tickets Mon-Fri, 10am - 5pm EST. You can also call us by phone if you have an urgent question.');
    }
}
