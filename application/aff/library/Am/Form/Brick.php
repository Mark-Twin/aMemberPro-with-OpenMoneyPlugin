<?php

class Am_Form_Brick_Payout extends Am_Form_Brick
{
    protected $hideIfLoggedInPossible = self::HIDE_DONT;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Payout Method');
        parent::__construct($id, $config);
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $module = Am_Di::getInstance()->modules->loadGet('aff');
        if ($module->getConfig('payout_methods'))
            Am_Di::getInstance()->modules->loadGet('aff')->addPayoutInputs($form);
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup_Aff;
    }
}

class Am_Form_Brick_ReferredBy extends Am_Form_Brick
{
    protected $hideIfLoggedInPossible = self::HIDE_ALWAYS;
    protected $labels = array(
        'you were referred by %s'
    );

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Referred By');
        if (empty($config['display'])) {
            $config['display'] = 'login';
        }
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addSelect('position')
            ->loadOptions(array(
                'below' => ___('Below Form'),
                'above' => ___('Above Form'),
                'inline' => ___('Brick Position')
            ))->setLabel('Position');
        $form->addAdvRadio('display')
            ->setLabel(___('Display'))
            ->loadOptions(array(
                'login' => ___('Username'),
                'name' => ___('Full Name'),
                'email' => ___('E-Mail')
            ));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        if (($aff_id = Am_Di::getInstance()->modules->loadGet('aff')->findAffId()) &&
            ($aff = Am_Di::getInstance()->userTable->load($aff_id, false))) {

            switch ($this->getConfig('display')) {
                case 'name' :
                    $id = $aff->getName();
                    break;
                case 'email' :
                    $id = $aff->email;
                    break;
                case 'login' :
                default:
                    $id = $aff->login;
            }

            $text = $this->___('you were referred by %s', $id);
            $html = '<div class="am-aff-referred-by">' . $text . '</div>';

            switch ($this->getConfig('position', 'below')) {
                case 'above' :
                    $form->addProlog($html);
                    break;
                case 'below' :
                    $form->addEpilog($html);
                    break;
                default:
                    $form->addHtml(null, array('class'=>'no-lable row-wide'))
                        ->setHtml($html);
            }
        }
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }
}