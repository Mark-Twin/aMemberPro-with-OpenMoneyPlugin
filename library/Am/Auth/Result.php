<?php

/**
 * Class holds authentication results
 * @package Am_Auth
 */
class Am_Auth_Result
{
    const SUCCESS = 1;
    const INVALID_INPUT = -1;
    const WRONG_CREDENTIALS = -2;
    const INTERNAL_ERROR = -3;
    const FAILURE_ATTEMPTS_VIOLATION = -4;
    const LOCKED = -5;
    const USER_NOT_FOUND = -6;
    const NOT_APPROVED = -7;
    const AUTH_CONTINUE = -8;

    protected $code, $message, $user;

    function __construct($code, $message = null, $user = null)
    {
        $this->code = $code;
        if ($message === null)
            $message = $this->_getMessage($code);
        $this->message = $message;
        $this->user = $user;
    }

    public function getCode()
    {
        return $this->code;
    }

    public function getUser()
    {
        return $this->user;
    }

    protected function _getMessage($code)
    {
        switch ($code) {
            case self::SUCCESS:
                return null;
            case self::INVALID_INPUT:
                return ___('Please login');
            case self::INTERNAL_ERROR:
                return ___('Internal Error');
            case self::FAILURE_ATTEMPTS_VIOLATION:
                return ___('Please wait %d seconds before next login attempt', 90);
            case self::LOCKED:
                return ___("Authentication problem, please contact website administrator");
            case self::NOT_APPROVED: 
                return ___('Your account has not yet been approved. You will be notified via email once a site administrator has reviewed your account and enabled access.');
            case self::AUTH_CONTINUE:
                return ___('Your account require additional authentification factor');
            case self::USER_NOT_FOUND:
            case self::WRONG_CREDENTIALS:
            default:
                return ___('The user name or password is incorrect');
        }
    }

    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        return $this->code == self::SUCCESS;
    }

    public function isContinue()
    {
        return $this->code == self::AUTH_CONTINUE;
    }

}