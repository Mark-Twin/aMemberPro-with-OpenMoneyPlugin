<?php

class Am_Form_Signup_Cart extends Am_Form_Signup
{
    public function getDefaultBricks()
    {
        return array(
            new Am_Form_Brick_Name,
            new Am_Form_Brick_Email,
            new Am_Form_Brick_Login,
            new Am_Form_Brick_Password,
        );
    }
    public function getAvailableBricks()
    {
        $ret = parent::getAvailableBricks();
        foreach ($ret as $k => $brick)
        {
            if (in_array($brick->getClass(), array('product', 'paysystem', 'coupon')))
                unset($ret[$k]);
        }
        return $ret;
    }
    public function isHideBricks()
    {
        return false;
    }
}
