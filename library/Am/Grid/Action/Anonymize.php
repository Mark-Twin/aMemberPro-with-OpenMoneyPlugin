<?php
class Am_Grid_Action_Anonymize extends Am_Grid_Action_Abstract
{
    use Am_PersonalData;

    protected $privilege = 'delete';

    function markAsProcessed()
    {
        $rec = Am_Di::getInstance()->userDeleteRequestTable->findFirstBy(['user_id' => $this->user->pk()]);
        
            
        if($rec){
            if(Am_Di::getInstance()->config->get('account-removal-method') == 'delete'){
                $rec->delete();
            }else{
                $rec->processed = Am_Di::getInstance()->sqlDateTime;
                $rec->completed = 1;
                $rec->admin_id = Am_Di::getInstance()->authAdmin->getUserId();
                $rec->update();
            }
        }
        return $this->grid->redirectBack();
    }
    
    public
        function run()
    {
        $this->user = Am_Di::getInstance()->userTable->load($this->grid->getRecord()->user_id, false);
        if ($this->grid->getRequest()->get('confirm'))
        {

            if ($this->grid->getRequest()->get('confirm_errors'))
            {
                $this->anonymizeUser($this->user);
                return $this->markAsProcessed();
            }
            else
            {
                $errors = [];
                switch (Am_Di::getInstance()->config->get('account-removal-method'))
                {
                    case 'delete' :
                        $errors = $this->doDelete($this->user);
                        break;
                    default : 
                        $errors = $this->doAnonymize($this->user);
                        break;
                }

                if (!empty($errors))
                {
                    echo $this->renderConfirmation(___('I confirm,  I fixed above errors. Delete Personal Data Now!'), $errors);
                }
                else
                {
                    return $this->markAsProcessed();
                }
            }
        }
        else
        {
            echo $this->renderConfirmation();
        }
    }

    public
        function renderConfirmation($btnText = null, $errors = [])
    {
        $message = Am_Html::escape($this->getConfirmationText());
        if (!empty($errors))
        {
            $errorsText = "<div><p style='color:red;'>"
                . ___('aMember was unable to delete Personal Data automatically. Please review and fix below errors then click to continue')
                . "</p></div>";
            $errorsText .= "<pre>" . implode("\n", $errors) . "</pre>";
            $addHtml = sprintf("<input type='hidden' name='%s_confirm_errors' value='yes'/>", $this->grid->getId());
        }
        else
        {
            $errorsText = $addHtml = '';
        }


        $form = $this->renderConfirmationForm($btnText, $addHtml);
        $back = $this->renderBackButton(___('No, cancel'));
        return <<<CUT
<div class="info">
<p>{$message}{$errorsText}</p>
<br />
<div class="buttons">
$form $back
</div>
</div>
CUT;
    }

    function getConfirmationText()
    {
        return ___("Do you really want to delete Personal Data for user %s?\n"
            . "This action can't be reverted!", $this->user->login);
    }
        
}