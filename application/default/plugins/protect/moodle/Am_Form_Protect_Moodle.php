<?php
if(!class_exists('Am_Form_Protect_Moodle', false)){
class Am_Form_Protect_Moodle extends Am_Form_Setup_ProtectDatabased{
    function addGroupSettings()
    {
        $title = $this->getTitle();
        $fs = $this->addFieldset('settings')->setLabel("$title Integration Settings");
        $fs->addHidden('group_settings_hidden')->setValue('0');
        $fs->addAdvCheckbox("remove_users")
            ->setLabel(___("Remove Users\n".
            "when user record removed from aMember\n".
            "must the related record be removed from %s", $title));
        
    }
}
}
?>
