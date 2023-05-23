<?php

class Am_Form_Signup_Aff extends Am_Form_Signup
{
    public function getAvailableBricks()
    {
        $bricks = parent::getAvailableBricks();
        foreach ($bricks as $k => $b)
        {
            if (($b instanceof Am_Form_Brick_Product) 
             || ($b instanceof Am_Form_Brick_Paysystem)
             || ($b instanceof Am_Form_Brick_Coupon)) 
                unset($bricks[$k]);
        }
        return $bricks;
    }
    public function getDefaultBricks()
    {
        return array(
            new Am_Form_Brick_Name,
            new Am_Form_Brick_Email,
            new Am_Form_Brick_Login,
            new Am_Form_Brick_Password,
            new Am_Form_Brick_Address,
            new Am_Form_Brick_Agreement,
            new Am_Form_Brick_Payout
        );
    }

    public function isHideBricks()
    {
        return true;
    }
}