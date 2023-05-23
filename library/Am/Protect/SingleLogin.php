<?php
/**
 * API interface to provide single-login functionality to Am_Protect_Databased
 * plugins
 * @see Am_Protect_Databased
 * @package Am_Protect 
 */
interface Am_Protect_SingleLogin
{
    /**
     * Return record of customer currently logged-in to the
     * third-party script, or null if not found or not logged-in
     * @return Am_Record|null
     */
    function getLoggedInRecord();

    /**
     * Login specified customer to the third-party app
     * all checks are already done, just do login
     * @param Am_Record $record
     * @param String $password - not encoded password.
     * @return bool true if success
     */
    function loginUser(Am_Record $record, $password);
    
    /*
     * Logout user from third-party script;
     * @param User $user - user  that should be logged out
     */
    function logoutUser(User $user);
}
