<?php

class Am_Plugin_Autocoupons extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_COMM = self::COMM_FREE;
    const PLUGIN_REVISION = '5.5.4';
    
    protected $_configPrefix = 'misc.';

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addSelect('batchId')
            ->setLabel(___("Default Coupon Batch\n" .
                "placeholder %coupon% will be replaced with coupon code from this batch"))
            ->loadOptions($this->getDi()->couponBatchTable->getOptions());
    }

    private function getCouponCode($batchId = null)
    {
        if (!$batchId)
        {
            $batchId = $this->getConfig('batchId');
        }
        $coupon = $this->getDi()->couponRecord;
        $coupon->batch_id = $batchId;
        $coupon->code = $this->getDi()->couponTable->generateCouponCode(8,$length);
        $coupon->save();
        
        return $coupon->code;
    }
    
    public function onMailTemplateBeforeParse(Am_Event $event)
    {
        /* @var $template Am_Mail_Template */
        $template = $event->getTemplate();
        $tConfig = $template->getConfig();
        $mailBody = (!empty($tConfig['bodyText'])) ? $tConfig['bodyText'] : $tConfig['bodyHtml'];
        if (preg_match_all('/%coupon(_([0-9]+))?%/', $mailBody, $matches))
        {
            $count = count($matches[0]);
            for($i=0; $i<$count;$i++)
            {
                if (isset($template['coupon' . $matches[1][$i]])) continue; //already set in template itself
                $coupon = $this->getCouponCode($matches[2][$i]);
                $template->{"setCoupon".$matches[1][$i]}($coupon); 
            }
        }
    }
    public function onMailSimpleTemplateBeforeParse(Am_Event $event)
    {
        /* @var $template Am_SimpleTemplate */
        $template = $event->getTemplate();
        $body = $event->getBody();
        $subject = $event->getSubject();
        if (preg_match_all('/%coupon(_([0-9]+))?%/', $subject.' '.$body, $matches))
        {
            $count = count($matches[0]);
            for($i=0; $i<$count;$i++)
            {
                $coupon = $this->getCouponCode($matches[2][$i]);
                $template->{"coupon".$matches[1][$i]} = $coupon; 
            }
        }
    }

    function getReadme()
    {

return <<<CUT
This plugin allow you to use special placeholders in any email template
which will be replaced with unique coupon code.

You can use either placeholder <strong>%coupon%</strong> to replace it with coupon
code from default coupon batch (You can specify default coupon batch in plugin
settings) or placeholder <strong>%coupon_batchID%</strong> to replace it
with coupon code from batch with id equal batchID (batchID is numeric value
of batch_id for existing batch in your installation eg. %coupon_33%. You can find
batch_id on page with your coupon batches <em>aMember CP -> Products -> Coupons</em>, column #)
CUT;
    }
}