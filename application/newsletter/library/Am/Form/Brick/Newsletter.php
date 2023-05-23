<?php
class Am_Form_Brick_Newsletter extends Am_Form_Brick
{
    protected $labels = array(
        'Subscribe to Site Newsletters' => 'Subscribe to Site Newsletters',
    );

    protected $hideIfLoggedInPossible = self::HIDE_DESIRED;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Newsletter');
        parent::__construct($id, $config);
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        if ($this->getConfig('type') == 'checkboxes')
        {
            $options = Am_Di::getInstance()->newsletterListTable->getUserOptions();
            if ($enabled = $this->getConfig('lists')) {
                $_ = $options;
                $options = array();
                foreach ($enabled as $id) {
                    $options[$id] = $_[$id];
                }
            }
            if (!$options) return; // no lists enabled
            $group = $form->addGroup('_newsletter')->setLabel($this->___('Subscribe to Site Newsletters'));
            if ($this->getConfig('required')) {
                $group->addClass('row-required');
            }
            $group->setSeparator("<br />\n");
            foreach ($options as $list_id => $title)
            {
                $c = $group->addAdvCheckbox($list_id)->setContent($title);
                if (!$this->getConfig('unchecked')) {
                    $c->setAttribute('checked');
                }
                if ($this->getConfig('required')) {
                    $c->addRule('required');
                }
            }
        } else {
            $data = array();
            if ($this->getConfig('no_label')) {
                $data['content'] = $this->___('Subscribe to Site Newsletters');
            }
            $c = $form->addAdvCheckbox('_newsletter', array(), $data);
            if (!$this->getConfig('no_label')) {
                $c->setLabel($this->___('Subscribe to Site Newsletters'));
            }
            if (!$this->getConfig('unchecked')) {
                $c->setAttribute('checked');
            }
            if ($this->getConfig('required')) {
                $c->addRule('required');
            }
        }
    }

    public function initConfigForm(Am_Form $form)
    {
        $el = $form->addSelect('type', array('id'=>'newsletter-type-select'))->setLabel(___('Type'));
        $el->addOption(___('Single Checkbox'), 'checkbox');
        $el->addOption(___('Checkboxes for Selected Lists'), 'checkboxes');

        $form->addAdvCheckbox('no_label', array('id' => 'newsletter-no-label'))
            ->setLabel(___("Hide Label"));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#newsletter-type-select').change(function(){
        jQuery('#newsletter-no-label').closest('.row').toggle(jQuery(this).val() == 'checkbox')
    }).change();
})
CUT
            );

        $lists = $form->addSortableMagicSelect('lists', array('id'=>'newsletter-lists-select'))
            ->setLabel(___("Lists\n" .
                'All List will be displayed if none selected'));
        $lists->loadOptions(Am_Di::getInstance()->newsletterListTable->getAdminOptions());
        $form->addScript()->setScript(<<<CUT
jQuery(document).ready(function($) {
    jQuery("#newsletter-type-select").change(function(){
        var val = jQuery(this).val();
        jQuery("#row-newsletter-lists-select").toggle(val == 'checkboxes');
    }).change();
});
CUT
            );
        $form->addAdvCheckbox('unchecked')
            ->setLabel(___("Default unchecked\n" .
                'Leave unchecked if you want newsletter default to be checked'));

        $form->addAdvCheckbox('required')
            ->setLabel(___("Subscription is required?"));
    }
}