<?php

/**
 * Profile editor
 * @package Am_SavedForm
 */
class Am_Form_Profile extends Am_Form implements Am_Form_Bricked
{
    /** @var SavedForm */
    protected $savedForm;
    protected $user;

    public function initFromSavedForm(SavedForm $record)
    {
        foreach ($record->getBricks() as $brick)
            $brick->insertBrick($this);
        $this->addSubmit('_submit_', array('value'=> ___('Save Profile'), 'class' => 'am-cta-profile'));
    }

    public function isMultiPage()
    {
        return false;
    }

    public function isHideBricks()
    {
        return false;
    }

    public function getAvailableBricks()
    {
        return Am_Form_Brick::getAvailableBricks($this);
    }

    public function getRecord()
    {
        return $this->savedForm;
    }

    public function setRecord(SavedForm $record)
    {
        $this->savedForm = $record;
    }

    public function __construct()
    {
        parent::__construct('profile');
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getDefaultBricks()
    {
        return array(
            new Am_Form_Brick_Name,
            new Am_Form_Brick_Email,
            new Am_Form_Brick_NewPassword,
        );
    }

    public function validate()
    {
        $event = new Am_Event_ValidateSavedForm($this->getValue(), $this, $this->getRecord());
        Am_Di::getInstance()->hook->call($event);
        if ($errors = $event->getErrors())
        {
            $this->setError($errors[0]);
            return false;
        }
        return parent::validate();
    }

    static function getSavedFormUrl(SavedForm $record)
    {
        if ($record->isDefault(SavedForm::D_PROFILE))
            return "profile";
        else
            return "profile/" . urlencode($record->code);
    }
}