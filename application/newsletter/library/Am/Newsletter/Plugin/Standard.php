<?php

class Am_Newsletter_Plugin_Standard extends Am_Newsletter_Plugin
{
    public function changeSubscription(User $user, array $addLists, array $deleteLists)
    {
        return true;
    }
}
