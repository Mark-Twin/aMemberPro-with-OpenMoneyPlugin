<?php

class UserConsent extends Am_Record
{
    function isActual()
    {
        return empty($this->cancel_dattm);
    }
}

class UserConsentTable extends Am_Table
{
    protected
        $_key = 'consent_id';
    protected
        $_table = '?_user_consent';
    protected
        $_recordClass = 'UserConsent';

    /**
     *
     * @param User $user
     * @param type $type -> agreement type that consent is recorded for.
     * @param type $source -> Text description about where consent was taken.
     * Placeholders are supported in source:
     * %account_created% -> Account created on signup page
     * %invoice_created% -> Invoice created
     * %signup_form% -> Signup form submit
     * @param string|null $title -> Document Title
     *
     * @param type $ip -> remote addr.
     */
    function recordConsent(User $user, $type, $ip, $source="", $title=null)
    {
        $consent = $this->getDi()->userConsentRecord;

        $revision = $this->getDi()->agreementTable->getCurrentByType($type);
        $consent->revision_id = is_null($revision)?null:$revision->pk();
        $consent->type = $type;
        $consent->user_id = $user->pk();
        $consent->remote_addr = $ip;
        $consent->dattm = $this->getDi()->sqlDateTime;
        $consent->source =$source;
        $consent->title=$title;

        $consent->insert();

        return $consent;
    }

    function cancelConsent(User $user, $type, $ip, $source)
    {
        foreach($this->findBy(['user_id' => $user->pk(),  'type' => $type]) as $userConsent)
        {
            if(!$userConsent->isActual())
                continue;

            $userConsent->cancel_dattm = $this->getDi()->sqlDateTime;
            $userConsent->cancel_remote_addr = $ip;
            $userConsent->cancel_source = $source;
            $userConsent->update();
        }
    }
    /**
     *
     * @param User $user
     * @param type $type
     * @return true if user has consent for current document type
     */

    function hasConsent(User $user, $type)
    {
        $current = $this->getDi()->agreementTable->getCurrentByType($type);
        $consent = $this->findFirstBy(['user_id' => $user->pk(), 'revision_id' =>$current->agreement_revision_id]);
        return $consent && $consent->isActual();
    }
}