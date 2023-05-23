<?php
class Am_Form_Brick_WordpressNickname extends Am_Form_Brick{
    protected $hideIfLoggedInPossible = self::HIDE_DONT;
    
    protected $labels = array(
        'Nickname'
    );
    
    public
        function insertBrick(\HTML_QuickForm2_Container $form)
    {
        $form->addText('_wordpress_nickname')
            ->setLabel($this->___('Nickname'))
            ->addRule('required');
    }    
    
    function getName()
    {
        return 'Wordpress Nickname';
    }
    
    function isAcceptableForForm(\Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Profile;
    }
}
class Am_Form_Brick_WordpressDisplay extends Am_Form_Brick{
    protected $hideIfLoggedInPossible = self::HIDE_DONT;
    protected $labels = array(
        'Display name publicly as'
    );
    
    function getName()
    {
        return 'Wordpress Display name publicly as';
    }
    
    public
        function insertBrick(\HTML_QuickForm2_Container $form)
    {
        $user = Am_Di::getInstance()->auth->getUser();
        $options = array_filter(array(
            $user->login, 
            $user->name_f,
            $user->name_l,
            $user->data()->get('_wordpress_nickname'),
            $user->name_f.' '.$user->name_l,
            $user->name_l.' '.$user->name_f,
        ));
        $form->addSelect('_wordpress_display')
            ->setLabel($this->___('Display name publicly as'))
            ->loadOptions(array_combine($options, $options));
    }    
    
    function isAcceptableForForm(\Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Profile;
    }
    
}