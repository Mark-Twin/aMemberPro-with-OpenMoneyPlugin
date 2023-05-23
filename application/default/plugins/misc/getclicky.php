<?php

class Am_Plugin_Getclicky extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $id;
    protected $done = false;

    public function __construct(Am_Di $di, array $config)
    {
        $this->id = $di->config->get('getclicky_id');
        parent::__construct($di, $config);
    }

    public function isConfigured()
    {
        return !empty($this->id);
    }

    function onSetupForms(Am_Event_SetupForms $forms)
    {
        $form = new Am_Form_Setup('getclicky');
        $form->setTitle("GetClicky");
        $forms->addForm($form);
        $form->addInteger('getclicky_id')
             ->setLabel(___("GetClicky Site Id\n" .
                 "GetClicky Account -> Preferences -> Info"));
    }

    function onAfterRender(Am_Event_AfterRender $event)
    {
        if ($this->done) return;
        if (preg_match('/thanks\.phtml$/', $event->getTemplateName())) {
            $this->done += $event->replace("|</body>|i",
                    $this->getSaleCode($event->getView()->invoice, $event->getView()->payment)
                    . $this->getTrackingCode()
                    . "</body>", 1);
        } elseif (!preg_match('/\badmin\b/', $t = $event->getTemplateName())) {
            $this->done += $event->replace("|</body>|i", $this->getTrackingCode() . "</body>", 1);
        }
    }

    function getTrackingCode()
    {
        return <<<CUT
<!-- getclicky code -->
<a title="Real Time Analytics" href="http://getclicky.com/$this->id" rel="nofollow"><img alt="Real Time Analytics" src="//static.getclicky.com/media/links/badge.gif" border="0" width="1" height="1"/></a>
<script type="text/javascript">
var clicky_site_ids = clicky_site_ids || [];
clicky_site_ids.push($this->id);
(function() {
  var s = document.createElement('script');
  s.type = 'text/javascript';
  s.async = true;
  s.src = '//static.getclicky.com/js';
  ( document.getElementsByTagName('head')[0] || document.getElementsByTagName('body')[0] ).appendChild( s );
})();
</script>
<noscript><p><img alt="Clicky" width="1" height="1" src="//in.getclicky.com/{$this->id}ns.gif" /></p></noscript>
<!-- end of getclicky code -->
CUT;
    }

    function getSaleCode(Invoice $invoice, InvoicePayment $payment)
    {
        if (empty($payment->amount)) {
            $goal = array('name' => 'Free Signup');
        } else {
            $goal = array('name' => 'Purchase', 'revenue' => $payment->amount);
        }
        $goal = json_encode($goal);
        return <<<CUT
<script type="text/javascript">
  var clicky_custom = {};
  clicky_custom.goal = $goal;
</script>
CUT;
    }
}