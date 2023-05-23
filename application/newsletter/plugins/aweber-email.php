<?php

class Am_Newsletter_Plugin_AweberEmail extends Am_Newsletter_Plugin
{
    public function _initSetupForm(Am_Form_Setup $form)
    {
        parent::_initSetupForm($form);
    }
    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        foreach ($addLists as $listId)
        {
            $mail = $this->getDi()->mail;
            $mail->addTo($listId . '@aweber.com');
            $mail->setSubject("aMember Pro v4 Subscribe Parser");
            $mail->setBodyText(
                "SUBSCRIBE\n" . 
                "Email: " . $user->email . "\n".
                "Name: "  . $user->getName() . "\n".
                "Login: " . $user->login . "\n"
            );
            $mail->send();
        }
        foreach ($deleteLists as $listId)
        {
            $mail = $this->getDi()->mail;
            $mail->addTo($listId . '@aweber.com');
            $why = "";
            $mail->setSubject("REMOVE#".$user->email."#$why#".$listId);
            $mail->send();
        }
        return true;
    }
    public function getReadme()
    {
        return <<<CUT
(Optional) Configure AWeber E-Mail Parser for aMember.
AWeber API has restriction: 60 requests max per minute. Normally it must
not be a problem, but in case of "Rebuild Db" this limit may be over and your
requests will not work. 
For such situations you may want to enable "E-Mail Parser" functionality of Aweber. 
In this case, aMember will send email messages to AWeber to subscribe/unsubscribe users, 
instead of making API calls. To use this functionality, do the following:
  * Go to <a href='https://www.aweber.com/users/parser' target="_blank" rel="noreferrer">www.aweber.com -> login -> My Lists -> Email Parser</a>
  * Scroll down to "Custom Parsers" and click <a href='https://www.aweber.com/users/parser/create' target="_blank" rel="noreferrer">add new</a> link
  * Fill in fields:
       Description: aMember Pro v4
       Trigger Rule: Subject: aMember Pro v4 Subscribe Parser
       Rule 2: \n[>\s]*Email:\s+(.+?)\n       Match: [Body] Store In: [Email]
       Rule 2: \n[>\s]*Name:\s+(.+?)\n        Match: [Body] Store In: [Name]
    Check a checkbox "Enable parser for all lists in this account" and click "Save" button
  * Go to this setup page, and enable "Use E-Mail Parsers instead of API Calls", click "Save"
CUT;
    }
}