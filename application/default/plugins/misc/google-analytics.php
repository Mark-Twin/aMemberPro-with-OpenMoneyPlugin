<?php

class Am_Paysystem_Action_Tracked_Redirect extends Am_Paysystem_Action_Redirect
{
    protected $orig, $invoice, $plugin;
    
    public function __construct(Am_Paysystem_Action_Redirect $orig, Am_Plugin_GoogleAnalytics $plugin, Invoice $invoice)
    {
        $this->orig = $orig;
        $this->plugin = $plugin;
        $this->invoice = $invoice;
    }
    
    public function process(/*Am_Mvc_Controller*/ $action = null)
    {
        if($this->invoice->saved_form_id)
            $savedForm = Am_Di::getInstance()->savedFormTable->load($this->invoice->saved_form_id, false);
        
        if(empty($savedForm))
            return $this->orig->process($action);
        
        $strategy = $this->plugin->getStrategy();
        $strategy->setUserId($this->invoice->user_id);
        $strategy->setSignup($savedForm->pk() . '-' . $savedForm->title, 'redirectToPayment');
        
        $head = $strategy->getHead();
        $header = $strategy->getHeader() . $strategy->getTrackingCode();
        
        $url = json_encode($this->orig->getUrl());
        
echo <<<CUT
<html>
  <head>
    <title></title>
    $head
<script>
window.amGtmEventCallback = function()
{
    window.location = $url;
}
window.setTimeout(window.amGtmEventCallback, 2100); // run if GTM failed
</script>
  </head>
  <body>
    $header
  </body>
</html>
CUT;
        throw new Am_Exception_Redirect;
    }
    
    public function toXml(XMLWriter $x)
    {
        return $this->orig->toXml($x);
    }
}

class Am_Plugin_GoogleAnalytics extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';
    const TRACKED_DATA_KEY = 'google-analytics-done';

    protected $id;
    protected $done = false;
    protected $strategy ;
    protected $signupSavedForm;
    
    public function __construct(Am_Di $di, array $config)
    {
        $this->id = $di->config->get('google_analytics');
        parent::__construct($di, $config);
    }
    public function isConfigured()
    {
        return !empty($this->id);
    }
    function onSetupForms(Am_Event_SetupForms $forms)
    {
        $form = new Am_Form_Setup('google_analytics');
        $form->setTitle("Google Analytics");
        $forms->addForm($form);
        $form->addText('google_analytics')
             ->setLabel("Google Analytics Account ID\n" .
                 'To enable automatic sales and hits tracking with GA,
             enter Google Analytics cAccount ID into this field.
             <a href=\'http://www.google.com/support/googleanalytics/bin/answer.py?answer=55603\' target="_blank" rel="noreferrer">Where can I find my tracking ID?</a>
             The tracking ID will look like <i>UA-1231231-1</i>.
             Please note - this tracking is only for pages displayed by aMember,
             pages that are just protected by aMember, cannot be tracked.
             Use '.
             '<a href="http://www.google.com/support/googleanalytics/bin/search.py?query=how+to+add+tracking&ctx=en%3Asearchbox" target="_blank" rel="noreferrer">GA instructions</a>
             how to add tracking code to your own pages.
             ');
        $form->addAdvCheckbox("google_analytics_only_sales_code")
            ->setLabel("Include only sales code\n" .
                "Enable this if you already have tracking code in template");
        $form->addAdvCheckbox("google_analytics_track_free_signups")
            ->setLabel("Track free signups");
        $form->addAdvCheckbox("google_analytics_user_id")
            ->setLabel("Enable <a target=_blank href='https://support.google.com/analytics/answer/3123666'>UserId Tracking</a>");

        $form->addSelect("analytics_version")
            ->setLabel("Analytics Version")
            ->loadOptions(array(
                'google' => 'Google Analytics',
                'universal' => 'Universal Analytics',
                'tag' => 'Google Tag Manager',
            ));
    }
    public function getStrategy()
    {
        if (!$this->strategy)
        {
            $version = $this->getDi()->config->get('analytics_version', 'google');
            $class = 'Am_Plugin_GoogleAnalytics_Strategy_' . ucfirst($version);
            $this->strategy = new $class($this->getDi());
        }
        return $this->strategy;
    }
    
    public function onSavedFormGetBricks($event)
    {
        $this->signupSavedForm = $event->getSavedForm();
    }
    
    public function onPaymentBeforeProcess($event)
    {
        if (!defined('AM_ADVANCED_SIGNUP_TRACKING')) return; // available for advanced customers only
        $result = $event->getReturn();
        /* @var $result Am_Paysystem_Result */
        if (!$result->isAction()) return;
        if (get_class($result->getAction()) == 'Am_Paysystem_Action_Redirect')
        {
            $result->setAction(new Am_Paysystem_Action_Tracked_Redirect($result->getAction(), $this, $event->getInvoice()));
        }
    }
    
    function onAfterRender(Am_Event_AfterRender $event)
    {
        if ($this->done) return;
        
        $strategy = $this->getStrategy();
        
        if (defined('AM_ADMIN') && AM_ADMIN)
            return; 
        
        $invoice = null;
        $payment = null;
        $signup = null;
        if (preg_match('/thanks\.phtml$/', $event->getTemplateName()) && $event->getView()->invoice && $event->getView()->payment)
        {
            $invoice = $event->getView()->invoice;
            $payment = $event->getView()->payment;
        } elseif ($this->getDi()->request->getControllerName() == 'signup') {
            if ($this->signupSavedForm)
                $signup = $this->signupSavedForm->pk() . '-' . $this->signupSavedForm->title;
        } else {
            if ($user_id = $this->getDi()->auth->getUserId()) 
            { // cache!!!!
                if ($this->getDi()->session->google_analytics_tracked<=5) // only 5 hits allowed
                {
                    $payments = $this->getDi()->invoicePaymentTable->findBy(array(
                        'user_id' => $user_id,
                        'dattm' => '>' . sqlTime('-5 days')
                    ));
                    foreach ($payments as $p) 
                    {
                        if ($p->data()->get(self::TRACKED_DATA_KEY)) continue;
                        $payment = $p;
                        $invoice = $p->getInvoice();
                        break;
                    }
                    $this->getDi()->session->google_analytics_tracked++;
                }
            }
        }
        if ($invoice && $payment)
        {
            $strategy->setPayment($invoice, $payment);
        } elseif ($signup) {
            $strategy->setSignup($signup);
        } elseif ($this->getDi()->config->get("google_analytics_only_sales_code")) {
            return;
        }
        
        $this->done += $event->replace("|</body>|i", 
            $strategy->getHeader() 
            . $strategy->getTrackingCode() 
            . "\n</body>", 1);
        if ($payment && $this->done) // mark payment as tracked
        {
            $payment = $event->getView()->payment;
            $payment->data()->set(self::TRACKED_DATA_KEY, 1);
            $payment->save();
        }
        $this->done += $event->replace('#</head>#', $strategy->getHead() . "\n</head>", 1);
    }
    
    function getReadme()
    {
        return <<<CUT
    Special note for using "Google Tag Manager' mode. 
        
    Create a custom user event in Google Tag Manager named 'track-ecommerce-event' 
    and create a tag "Universal Analytics -> Conversion" triggered by event 
    'track-ecommerce-event'.
CUT;
    }
  
    
}

abstract class Am_Plugin_GoogleAnalytics_Strategy_Abstract
{
    protected $invoice;
    protected $payment;
    protected $signup, $signupStep;
    protected $userId;
    
    public function __construct(Am_Di $di)
    {
        $this->di = $di;
        $this->id = preg_replace('/[^A-Za-z0-9_-]/', '', $di->config->get('google_analytics'));
    }
    /** @return code to insert before </head> */
    function getHead() { }
    abstract function getHeader();
    abstract function getSaleCode(Invoice $invoice, InvoicePayment $payment);
    abstract function getTrackingCode();
    /** @return code to display on signup page */
    function getSignupCode(){}
    
    function setPayment($invoice, $payment)
    {
        $this->invoice = $invoice;
        $this->payment = $payment;
        return $this;
    }
    function setSignup($signup, $step = 'open')
    {
        $this->signup = $signup;
        $this->signupStep = $step;
        return $this;
    }
    function setUserId($userId)
    {
        $this->userId = $userId;
    }
    
    function needTrackSale(Invoice $invoice, InvoicePayment $payment)
    {
        if (empty($payment->amount) && !$this->getDi()->config->get('google_analytics_track_free_signups'))
            return false;
        $product_ids = array();
        foreach ($invoice->getItems() as $item)
            $product_ids[] = $item->item_id;
        if (array_intersect($product_ids, $this->getDi()->config->get('analytics_exclude_if_product', array())))
            return false; // exclude if product option active
        return true;
    }
    function getDi()
    {
        return $this->di;
    }
    function getTrackUserId()
    {
        if (!$this->getDi()->config->get('google_analytics_user_id'))
            return null;
        $id = ($this->userId!==null) ? $this->userId : $this->getDi()->auth->getUserId();
        if ($id)
            return $this->getDi()->security->siteHash('track-userid-'.$id, 8);
        
    }
}

class Am_Plugin_GoogleAnalytics_Strategy_Google extends Am_Plugin_GoogleAnalytics_Strategy_Abstract
{
    function getHeader()
    {
        return <<<CUT

<!-- start of GA code -->
<script type="text/javascript">
    var gaJsHost = (("https:" == document.location.protocol) ? "https://ssl." : "http://www.");
    document.write(unescape("%3Cscript src='" + gaJsHost + "google-analytics.com/ga.js' type='text/javascript'%3E%3C/script%3E"));
</script>
CUT;
        
    }
    function getSaleCode(Invoice $invoice, InvoicePayment $payment)
    {
        $out = "";
        if (!$this->needTrackSale($invoice, $payment))
            return $out;
        if (empty($payment->amount))
        {
            $a = array(
                $invoice->public_id,
                $this->getDi()->config->get('site_title'),
                0,
                0,
                0,
                $invoice->getCity(),
                $invoice->getState(),
                $invoice->getCountry(),
            );
        } else {
            $a = array(
                $payment->transaction_id,
                $this->getDi()->config->get('site_title'),
                $payment->amount - $payment->tax - $payment->shipping,
                (float)$payment->tax,
                (float)$payment->shipping,
                $invoice->getCity(),
                $invoice->getState(),
                $invoice->getCountry(),
            );
        }
        $a = implode(",\n", array_map('json_encode', $a));
        $items = "";
        foreach ($invoice->getItems() as $item)
        {
            $items .= "['_addItem', '$payment->transaction_id', '$item->item_id', '$item->item_title','', $item->first_total, $item->qty],";
        }
        return $out . <<<CUT
if (typeof(_gaq)=='object') { // sometimes google-analytics can be blocked and we will avoid error
    _gaq.push(
        ['_addTrans', $a],
        $items
        ['_trackTrans']
    );
};

CUT;
    }
    function getTrackingCode()
    {
        $out = $this->payment ? $this->getSaleCode($this->invoice, $this->payment) : null;
        $out .= $this->signup ? $this->getSignupCode() : null;

        return <<<CUT

<script type="text/javascript">
if (typeof(_gaq)=='object') { // sometimes google-analytics can be blocked and we will avoid error
    _gaq.push(['_setAccount', '{$this->id}']);
    _gaq.push(['_trackPageview']);
}
$out
</script>
<!-- end of GA code -->

CUT;
    }
}

class Am_Plugin_GoogleAnalytics_Strategy_Universal extends Am_Plugin_GoogleAnalytics_Strategy_Abstract
{
    function getHeader()
    {
            return <<<CUT

<!-- start of GA code -->
<script type="text/javascript">
    (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
    (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
    m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
    })(window,document,'script','//www.google-analytics.com/analytics.js','ga');
</script>
CUT;
        
    }
    function getSaleCode(Invoice $invoice, InvoicePayment $payment)
    {
        $out = "";
        if (!$this->needTrackSale($invoice, $payment))
            return $out;
        
        foreach ($invoice->getItems() as $item)
        {
            $it = json_encode(array(
                'id' => $payment->transaction_id,
                'name' => $item->item_title,
                'sku' => $item->item_id,
                'price' => moneyRound($item->first_total/$item->qty),
                'quantity' => $item->qty,
            ));
            $items .= "ga('ecommerce:addItem', $it);\n";
        }
        $tr = json_encode(array(
            'id' => $payment->transaction_id,
            'affiliation' => $this->getDi()->config->get("site_title"),
            'revenue' => empty($payment->amount) ? 0 : ($payment->amount - $payment->tax - $payment->shipping),
            'shipping' => empty($payment->amount) ? 0 : $payment->shipping,
            'tax' => empty($payment->amount) ? 0 : $payment->tax,
        ));
        return $out . <<<CUT
    ga('require', 'ecommerce');
    ga('ecommerce:addTransaction', $tr);
    $items
    ga('ecommerce:send');
CUT;
    }
    function getTrackingCode()
    { 
        $out = $this->payment ? $this->getSaleCode($this->invoice, $this->payment) : null;
        $out .= $this->signup ? $this->getSignupCode() : null;

        $userId = $this->getTrackUserId();
        $params = $userId ? json_encode(array('userId' => $userId)) : '{}';
        return <<<CUT
<script type="text/javascript">
    ga('create', '{$this->id}', 'auto', $params);
    ga('send', 'pageview');
$out        
</script>
<!-- end of GA code -->
CUT;
    }
}

class Am_Plugin_GoogleAnalytics_Strategy_Tag extends Am_Plugin_GoogleAnalytics_Strategy_Abstract
{
    function getHead()
    {
        $out = "";
        
        $userId = $this->getTrackUserId();
        if ($userId) $out .= <<<CUT
window.dataLayer.push({'userId': '$userId'});

CUT;

        $out .= $this->payment ? $this->getSaleCode($this->invoice, $this->payment) : null;
        $out .= $this->signup ? $this->getSignupCode() : null;

        return <<<CUT
<!-- Google Tag Manager -->
<script>
<!-- site code -->
window.dataLayer = window.dataLayer || [];
$out        
<!-- end of site code -->
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$this->id}');</script>
<!-- End Google Tag Manager -->
CUT;
    }
    function getHeader()
    {
        return <<<CUT
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id={$this->id}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->
CUT;
    }
    function getSaleCode(Invoice $invoice, InvoicePayment $payment)
    {
        $out = "";
        if (!$this->needTrackSale($invoice, $payment))
            return $out;
        
        $a = array();
        $a['actionField'] = array(
            'id' => $payment->transaction_id,
            'affiliation' => $this->getDi()->config->get('site_title'),
            'revenue' => $payment->amount - $payment->tax - $payment->shipping,
            'tax' => (float)$payment->tax,
            'shipping' => (float)$payment->shipping,
        );
        if (empty($payment->amount))
            $a['id'] = $invoice->public_id;
            
        foreach ($invoice->getItems() as $item)
        {
            $a['products'][] = array(
                'sku' => $item->item_id,
                'name' => $item->item_title,
                'price' => $item->first_total,
                'quantity' => $item->qty,
            );         
        }
        $a['ecommerce']['purchase'] = $a;
        $a = json_encode($a);
        return <<<CUT
window.dataLayer.push($a);
            
CUT;
    }
    function getTrackingCode()
    {
    }
    
    function getSignupCode()
    {
        $a = array(
            'event' => 'checkout',
            'ecommerce' => array(
                'checkout' => array(
                    'actionField' => array('step' => $this->signupStep, 'option' => $this->signup ),
                ),
            ),
            'eventTimeout' => 2000,
        );
        $a = json_encode($a);
        return <<<CUT
        
var _amDl = $a;       
_amDl.eventCallback = function() { 
    if (typeof window.amGtmEventCallback !== 'undefined') {
        window.amGtmEventCallback();
    }; 
};
window.dataLayer.push(_amDl);

CUT;
    }
}
