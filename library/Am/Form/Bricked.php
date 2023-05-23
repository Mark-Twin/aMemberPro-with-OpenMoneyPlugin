<?php

/**
 * Class containing Am_Form_Brick objects from saved config
 * used for signup and user profile forms
 * @package Am_SavedForm
 */
interface Am_Form_Bricked
{
    /** @return array Am_Form_Brick */
    public function getDefaultBricks();
    public function getAvailableBricks();
    public function isMultiPage();
    public function isHideBricks();
}
