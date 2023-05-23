<?php
class Am_Form_Echeck extends Am_Form
{
    const PAYFORM = 'payform';
    const USER_UPDATE = 'user-update';
    const ADMIN_UPDATE = 'admin-update';
    const ADMIN_INSERT = 'admin-insert';

    protected $payButtons = array();

    /** @var Am_Paysystem_CreditCard */
    protected $plugin;
    protected $formType = self::PAYFORM;

    public function __construct(Am_Paysystem_Echeck $plugin, $formType = self::PAYFORM)
    {
        $this->plugin = $plugin;
        $this->formType = $formType;
        $this->payButtons = array(
            self::PAYFORM => ___('Subscribe And Pay'),
            self::ADMIN_UPDATE => ___('Update eCheck Info'),
            self::USER_UPDATE => ___('Update eCheck Info'),
            self::ADMIN_INSERT => ___('Update eCheck Info'),
        );
        parent::__construct('ec');
    }

    public function init()
    {
        parent::init();

        $name = $this->addGroup()
            ->setLabel(___("Your Name\n" .
                'your first and last name'));
        $name->setSeparator(' ');
        $name->addRule('required', ___('Please enter your name'));

        $name->addText('echeck_name_f', array('size' => 15))
            ->addRule('required', ___('Please enter first name'))
            ->addRule('regex', ___('Please enter first name'), '|^[a-zA-Z_\' -]+$|');

        $name->addText('echeck_name_l', array('size' => 15))
            ->addRule('required', ___('Please enter your last name'))
            ->addRule('regex', ___('Please enter your last name'), '|^[a-zA-Z_\' -]+$|');

        if ($this->formType == self::ADMIN_UPDATE)
        {
            $group = $this->addGroup()->setLabel(___("Bank Account Number\n" .
                'Up to 20 digits'));
            $group->addStatic()->setContent('<div>');
            $group->addStatic('echeck');
            $group->addText('echeck_ban', array('autocomplete' => 'off', 'maxlength' => 20, 'style' => 'display:none'))
                ->addRule('regex', ___('Invalid Bank Account Number'), '/^[a-zA-Z0-9]{1,20}$/');

            $group->addScript("")->setScript(<<<CUT
jQuery(function(){
    jQuery("input#echeck_ban-0").closest(".element").click(function(){
        var input = jQuery("input#echeck_ban-0").detach();
        jQuery(this).empty().append(input.show());
        input.focus();
        jQuery(this).unbind('click');
    });
});
CUT
            );
            $group->addStatic()->setContent('</div>');
        } else
        {
            $this->addText('echeck_ban', array('autocomplete' => 'off', 'maxlength' => 20))
                ->setLabel(___("Your Bank Account Number\n" .
                    'Up to 20 digits'))
                ->addRule('required', ___('Please enter Account Number'))
                ->addRule('regex', ___('Invalid Account Number'), '/^[a-zA-Z0-9]{1,20}$/');
        }

        $this->addText('echeck_aba', array('autocomplete' => 'off', 'maxlength' => 9))
            ->setLabel(___("ABA Routing Number\n" .
                '9 digits'))
            ->addRule('required', ___('Please enter Routing Number'))
            ->addRule('regex', ___('Invalid Routing Number'), '/^[a-zA-Z0-9]{1,9}$/');

        $options = $this->plugin->getFormOptions();

        if (in_array(Am_Paysystem_Echeck::ECHECK_COMPANY, $options))
        {
            $this->addText(Am_Paysystem_Echeck::ECHECK_COMPANY)
            ->setLabel(___("Company Name\n" .
                'the company name associated with the billing address for ' .
                'the transaction'));
        }

        if (in_array(Am_Paysystem_Echeck::ECHECK_TYPE_OPTIONS, $options))
        {
            $type = $this->addSelect(Am_Paysystem_Echeck::ECHECK_TYPE_OPTIONS)
                ->setLabel(___("Bank Account Type\n" .
                    'please select one'))
                ->loadOptions(array_merge(array(''=>'-- ' . ___('Please choose') . ' --'),
                    $this->plugin->getEcheckTypeOptions()));
            $type->addRule('required', ___('Please choose a Bank Account Type'));
        }

        if (in_array(Am_Paysystem_Echeck::ECHECK_BANK_NAME, $options))
        {
            $this->addText(Am_Paysystem_Echeck::ECHECK_BANK_NAME, array('autocomplete' => 'off', 'maxlength' => 50))
               ->setLabel(___('Bank Name'))
                ->addRule('required', ___('Please enter Bank Name'));
        }

        if (in_array(Am_Paysystem_Echeck::ECHECK_ACCOUNT_NAME, $options))
        {
            $this->addText(Am_Paysystem_Echeck::ECHECK_ACCOUNT_NAME, array('autocomplete' => 'off', 'maxlength' => 50))
                ->setLabel(___("Bank Account Name\n" .
                    'name associated with the bank account'))
                ->addRule('required', ___('Please enter Bank Account Name'));
        }

        if (in_array(Am_Paysystem_Echeck::ECHECK_ADDRESS, $options))
        {
            $fieldSet = $this->addFieldset(___('Address Info'))
                ->setLabel(___("Address Info\n" .
                    '(must match your credit card statement delivery address)'));
            if (in_array(Am_Paysystem_Echeck::ECHECK_STREET, $options))
            {
                $street = $fieldSet->addText('echeck_street')->setLabel(___('Street Address'))
                                   ->addRule('required', ___('Please enter Street Address'));
            }
            if (in_array(Am_Paysystem_Echeck::ECHECK_STREET2, $options))
            {
                $street2 = $fieldSet->addText('echeck_street2')->setLabel(___('Street Address (Second Line)'))
                               ->addRule('required', ___('Please enter Street Address'));
            }
            if (in_array(Am_Paysystem_Echeck::ECHECK_CITY, $options))
            {
                $city = $fieldSet->addText('echeck_city')->setLabel(___('City'))
                                 ->addRule('required', ___('Please enter City'));
            }

            if (in_array(Am_Paysystem_Echeck::ECHECK_ZIP, $options))
            {
                $zip = $fieldSet->addText('echeck_zip')->setLabel(___('ZIP'))
                        ->addRule('required', ___('Please enter ZIP code'));
            }
            if (in_array(Am_Paysystem_Echeck::ECHECK_COUNTRY, $options))
            {
                $country = $fieldSet->addSelect('echeck_country')->setLabel(___('Country'))
                    ->setId('f_cc_country')
                    ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
                $country->addRule('required', ___('Please enter Country'));
            }

            if (in_array(Am_Paysystem_Echeck::ECHECK_STATE, $options))
            {
                $group = $fieldSet->addGroup()->setLabel(___('State'));
                $group->addRule('required', ___('Please enter State'));
                /** @todo load correct states */
                $stateSelect = $group->addSelect('echeck_state')
                    ->setId('f_cc_state')
                    ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['echeck_country'], true));
                $stateText = $group->addText('echeck_state')->setId('t_cc_state');
                $disableObj = $stateOptions ? $stateText : $stateSelect;
                $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');
            }

            if (in_array(Am_Paysystem_Echeck::ECHECK_PHONE, $options))
            {
                $phone = $fieldSet->addText('echeck_phone', array('size'=>14))->setLabel(___('Phone'))
                        ->addRule('required', ___('Please enter phone number'))
                        ->addRule('regex', ___('Please enter phone number'), '|^[\d() +-]+$|');
            }
        }

        $buttons = $this->addGroup();
        $buttons->addSubmit('_echeck_', array('value' =>
            '    '
            . $this->payButtons[$this->formType]
            . '    '));
        if ($this->formType == self::USER_UPDATE)
        {
            $buttons->addInputButton('_echeck_', array('value' =>
                '    '
                . ___("Back")
                . '    ',
                'onclick' => 'goBackToMember()'));
            $this->addScript("")->setScript("function goBackToMember(){ window.location = amUrl('/member'); }");
        }
        $this->plugin->onFormInit($this);
    }

    /**
     * Return array of default values based on $user record
     * @param User $user
     */
    public function getDefaultValues(User $user)
    {
        return array(
            'echeck_name_f'  => $user->name_f,
            'echeck_name_l'  => $user->name_l,
            'echeck_street'  => $user->street,
            'echeck_street2' => $user->street2,
            'echeck_city'    => $user->city,
            'echeck_state'   => $user->state,
            'echeck_country' => $user->country,
            'echeck_zip'     => $user->zip,
            'echeck_phone'   => $user->phone,
        );
    }

    public function validate()
    {
        return parent::validate() && $this->plugin->onFormValidate($this);
    }

    public function getValue()
    {
        $ret = parent::getValue();
        array_walk_recursive($ret, function(&$v, $k) {$v=trim($v);});
        if (!empty($ret['echeck_ban']))
            $ret['echeck_ban'] = preg_replace('/\D/', '', $ret['echeck_ban']);
        return $ret;
    }
    public function toEcheckRecord(EcheckRecord $echeck)
    {
        $values = $this->getValue();
        unset($values['a']);
        unset($values['id']);
        unset($values['action']);
        $echeck->setForInsert($values);
    }
}
