<?php

/**
 * Tax plugins storage
 * @package Am_Invoice
 */
class Am_Plugins_Tax extends Am_Plugins
{
    /** @return array of calculators */
    function match(Invoice $invoice)
    {
        $di = $invoice->getDi();
        $ret = array();
        foreach ($this->getEnabled() as $id)
        {
            $obj = $this->get($id);
            $calcs = $obj->getCalculators($invoice);
            if ($calcs && !is_array($calcs))
                $calcs = array($calcs);
            if ($calcs)
                $ret = array_merge($ret, $calcs);
        }
        return $ret;
    }

    function getAvailable(){
        $result = array();
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, 'Am_Invoice_Tax'))
                $result[] = fromCamelCase(str_replace('Am_Invoice_Tax_', '', $class), '-');
        }
        return $result;
    }
}

/**
 * Abstract tax plugin
 * @package Am_Invoice
 */
abstract class Am_Invoice_Tax extends Am_Pluggable_Base
{
    protected $_idPrefix = 'Am_Invoice_Tax_';
    protected $_configPrefix = 'tax.';
    protected $_alwaysAbsorb = false; //backward compatability (Am_Invoice_Tax_Gst)

    function initForm(HTML_QuickForm2_Container $form) {}

    /**
     * @param Invoice $invoice
     * @return double
     */
    function getRate(Invoice $invoice) {}

    // get calculators
    function getCalculators(Invoice $invoice)
    {
        $rate = $this->getRate($invoice);
        if ($rate > 0.0) {
            return ($this->getConfig('absorb') || $this->_alwaysAbsorb) ?
                new Am_Invoice_Calc_Tax_Absorb($this->getId(), $this->getConfig('title', $this->getTitle()), $this) :
                new Am_Invoice_Calc_Tax($this->getId(), $this->getConfig('title', $this->getTitle()), $this);
        }
    }

    protected function _beforeInitSetupForm()
    {
        $form = parent::_beforeInitSetupForm();
        $form->addText('title', array('class' => 'el-wide'))->setLabel(___('Tax Title'))->addRule('required');
        if (!$this->_alwaysAbsorb) {
            $form->addAdvCheckbox('absorb')
                ->setlabel(___('Catalog Prices Include Tax'));
        }
        $form->addAdvCheckbox('shipping')
            ->setlabel(___('Apply Tax To Shipping Price'));
        return $form;
    }
}

class Am_Invoice_Tax_GlobalTax extends Am_Invoice_Tax
{
    public function getTitle() { return ___("Global Tax"); }

    public function getRate(Invoice $invoice)
    {
        return $this->getConfig('rate');
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $gr = $form->addGroup()->setLabel(___("Tax Rate\nfor example 18.5 (no percent sign)"));
        $gr->addText('rate', array('size'=>5));
        $gr->addStatic()->setContent(' %');
    }
}

class Am_Invoice_Tax_Regional extends Am_Invoice_Tax
{
    public function getTitle()
    {
        return ___("Regional Tax");
    }

    public function getRate(Invoice $invoice)
    {
        $user = $invoice->getUser();
        if (!$user) return;
        $rate = null;
        foreach ((array)$this->getConfig('rate') as $t){
            if (!empty($t['zip']))
                if (!$this->compareZip($t['zip'], $user->get('zip')))
                    continue; // no match
            if (!empty($t['state']) && ($t['state'] == $user->get('state')) && ($t['country'] == $user->get('country')))
            {
                $rate = $t['tax_value'];
                break;
            }
            if (!$t['state'] && !empty($t['country']) && ($t['country'] == $user->get('country')))
            {
                $rate = $t['tax_value'];
                break;
            }
        }
        return $rate;
    }

    protected function compareZip($zipString, $zip)
    {
        $zip = trim($zip);
        foreach (preg_split('/[,;\s]+/', $zipString) as $s)
        {
            $s = trim($s);
            if (!strlen($s)) continue;
            if (strpos($s, '-'))
                list($range1, $range2) = explode('-', $s);
            else
                $range1 = $range2 = $s;
            if (($range1 <= $zip) && ($zip <= $range2))
            {
                return true;
            }
        }
        return false;
    }

    protected function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addElement(new Am_Form_Element_RegionalTaxes('rate'));
    }
}

class Am_Invoice_Tax_Vat2015 extends Am_Invoice_Tax
{
    protected $rates = array(
        'AT'=>20, 'BE' => 21, 'BG'=>20, 'CY'=>19, 'HR'=>25, 'CZ'=>21, 'DE'=>19,
        'DK'=>25, 'EE'=>20, 'GR'=>24, 'ES'=>21, 'ES-PM'=>0, 'ES-SC'=>0, 'FI'=>24,
        'FR'=>20, 'GP'=>8.5, 'MQ'=>8.5, 'RE'=>8.5, 'GF'=>0, 'YT'=>0,
        'GB'=>20, 'HU'=>27, 'IE'=>23, 'IM'=> 20, 'IT'=>22, 'LT'=>21, 'LU'=>17,
        'LV'=>21, 'MT'=>18, 'NL'=>21, 'PL'=>23, 'PT'=>23, 'RO'=>19,
        'SE'=>25, 'SK'=>20, 'SI'=>22, 'MC' => 20);

    //GR is known as EL for tax
    //MC (Monaco) are regarded as having been supplied to or from France (FR)
    //IM (Isle of Man) are regarded as having been supplied to or from the United Kingdom (GB)
    //France DOM: GP, MQ, RE, GF, YT

    protected $countries = array();
    protected $countryLookupService;

    const INVOICE_IP = 'tax_invoice_ip';
    const INVOICE_IP_COUNTRY = 'tax_invoice_ip_country';
    const USER_REGISTRATION_IP  = 'tax_user_registration_ip';
    const USER_REGISTRATION_IP_COUNTRY  = 'tax_user_registration_ip_country';
    const USER_COUNTRY = 'tax_user_country';
    const SELF_VALIDATION_COUNTRY = 'tax_self_validation_country';
    const INVOICE_COUNTRY = 'tax_invoice_country';

    public function __construct(Am_Di $di, array $config)
    {
        parent::__construct($di, $config);
        $countryList = $di->countryTable->getOptions();
        $statesList = $di->stateTable->getOptions('FR') + $di->stateTable->getOptions('ES');
        foreach ($this->rates as $k=>$c)
        {
            if(isset($countryList[$k])) {
                $this->countries[$k] = $countryList[$k];
            }
            if (isset($statesList[$k])) {
                $this->countries[$k] = sprintf("%s (%s)", $countryList[substr($k, 0, 2)], $statesList[$k]);
            }
        }
    }

    public function getTitle() { return ___("EU VAT"); }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $gr = $form->addGroup('')->setLabel(___("Electronically Supplied Service\n" .
            'Enable if ALL your products are electronic services.'));
        $gr->addAdvCheckbox('tax_digital');
        $gr->addHTML()->setHTML(<<<EOT
<div><a href="javascript:;" onclick="jQuery('#tax_digital_example').toggle()" class="local">Examples of Electronic Service</a></div>
<div id="tax_digital_example" style="display:none">
<ul class="list">
 <li>Website supply, web-hosting, distance maintenance of programmes  and equipment;</li>
 <li>Supply of software and updating thereof;</li>
 <li>Supply of images, text and information and making available of databases;</li>
 <li>Supply of music, films and games, including games of chance and gambling games, and of
political, cultural, artistic, sporting, scientific and entertainment broadcast and events;</li>
 <li>Supply of distance teaching.</li>
</ul>
</div>
EOT
   );
        $form->addAdvCheckbox('b2b_vat')
            ->setLabel(___("Add VAT to  all B2B payments\n"
                . "Normally aMember will not add VAT to invoice if\n"
                . "customer located in another country and specified valid VAT ID\n"
                . "With that setting enabled, VAT will be applied in this situation too."
                ));

        $fieldSet = $form->addFieldSet('maxmind', array('id' => 'maxmind'))->setLabel('Location Validation Settings');
        $fieldSet->addHTML(null, array('class'=>'no-label'))->setHTML(<<<EOT
<p>According to new EU VAT rules your are required to collect two pieces of non-conflicting evidence of customer's location country if you are selling Digital (Electronic Service) Products. These two pieces of evidence will be checked on each invoice which has Digital Product included:</p>
<ul class="list">
    <li>Address Contry (so make sure that you  have added Address info brick to signup form)</li>
    <li>IP Address Country</li>
</ul>
<p>In order to get country from customer's IP address, aMember uses MaxMind GeoIP2 service. Please signup <a href = "https://www.maxmind.com/en/geoip2-precision-country" target="_blank" rel="noreferrer" class="link">here</a> in order to get MaxMind user ID and license key.</p>

EOT
   );
        $fieldSet->addAdvCheckbox('tax_location_validation')
            ->setLabel(___("Enable Location Validation\n" .
                'aMember will require two peices of location evidence before an invoice is created. ' .
                'Invoice that fials validation will be blocked and user will receive warning.'));

        $fieldSet->addAdvCheckbox('tax_location_validate_all')
            ->setLabel(___("Validate Location even if Invoice has no VAT \n" .
                           "Validate All New Invoices (even if invoice has no VAT)\n".
                           "If unchecked, location will be validated only when \n".
                           "user selects country inside EU and only if VAT should be applied to invoice.\n".
                           "Free invoices won't be validated still"
                ));

        $fieldSet->addAdvCheckbox('tax_location_validate_self')
            ->setLabel(___("Enable Self-Validation\n" .
                           "If validation failed, user will be able to confirm current location manually\n"
                ));

        $fieldSet->addText('tax_maxmind_user_id')->setLabel(___('MaxMind User ID'));
        $fieldSet->addText('tax_maxmind_license')->setLabel(___('MaxMind License'));

        $fieldSet = $form->addFieldSet('vat_id')->setLabel(___("Account Information"));
        $fieldSet->addSelect('my_country')
            ->setLabel(___('My Country'))
            ->loadOptions($this->countries)
            ->addRule('required');

        $fieldSet->addText('my_id')
            ->setLabel(___('VAT Id'))
            ->addRule('required');


        $fieldSet = $form->addFieldSet('numbering')->setLabel(___('Invoice numbering'));
        $fieldSet->addAdvCheckbox("sequential")->setLabel(___("Sequential Receipt# Numbering\n" .
            "aMember still creates unique id for invoices, but it will\n" .
            "generate PDF receipts for each payment that will be\n".
            "available in the member area for customers"));
        $form->setDefault('sequential', 1);
        $form->setDefault('tax_digital', 1);

        $fieldSet->addText('invoice_prefix')->setLabel(___("Receipt# Number Prefix\n" .
            "If you change prefix numbers will start over from 1\n"
            . "You can use %year% shortcode in invoice number. It will be replaced to actual year\n"
            . "For example: INV-%year%-"))->setValue('INV-');
        $fieldSet->addText('initial_invoice_number')->setLabel(___('Initial Receipt# Number'))->setValue(1);
        $fieldSet->addText('invoice_refund_prefix')->setLabel(___("Refund Receipt# Prefix\n" .
            "If you change prefix numbers will start over from 1"
            . "You can use %year% shortcode in invoice number. It will be replaced to actual year\n"
            . "For example: RFND-%year%-"))->setValue('RFND-');
        $fieldSet->addText('initial_invoice_refund_number')->setLabel(___('Initial Receipt# Refund Number'))->setValue(1);

        $this->addRatesTable($form, 'rate', ___('Standard VAT Rates, %'));

        $groups = $this->getConfig('tax_groups') ?: array();

        foreach ($groups as $id => $title) {
            $this->addRatesTable($form, $id, $title . ', %', true);
        }

        $form->addHidden('schedule')
            ->setId('tax-rate-schedule-data');

        $i18n = array(
            'date' => ___('Date'),
            'tax_rate' => ___('Tax Rate'),
            'delete' => ___('delete'),
            'add' => ___('schedule change'),
            'change_to' => ___('to %s on %s')
        );

        $form->addEpilog(<<<CUT
<style type="text/css">
    .am-form .tax-rate-container div.element-title {
        white-space: nowrap;
        text-overflow: ellipsis;
        overflow: hidden;
    }

    @media all and (min-width:1100px) {
        .tax-rate-container .fieldset .row {
            float:left;
            width:50%;
        }
    }

    @media all and (min-width:1400px) {
        .tax-rate-container .fieldset .row {
            float:left;
            width:33%;
        }
    }

    .tax-rate-container .fieldset {
        background:#f5f5f5;
        overflow:hidden;
    }

    .tax-rate-container .fieldset .row {
        background: none;
    }

    .tax-rate-container.tax-rate-container-add .fieldset .row:first-child {
        width:auto;
        float:none;
        background:#eee;
    }

    .am-form .tax-rate-container div.element-title label {
        font-weight: normal;
    }

    .tax-rate-group-delete {
        color: #ba2727;
    }

    .tax-rate-schedule-delete {
        display: none;
        color: #ba2727;
    }

    .row.tax-rate-has-rule:hover .tax-rate-schedule-delete {
        display: inline;
    }

    .row:hover .tax-rate-schedule {
        display:inline;
    }

    .tax-rate-schedule,
    .row.tax-rate-has-rule:hover .tax-rate-schedule {
        display: none;
    }

    .tax-rate-schedule-preview {
        display:none;
    }

    .tax-rate-has-rule .tax-rate-schedule-preview {
        color: #c2c2c2;
        display: inline;
    }
    .tax-rate-has-rule.row:hover .tax-rate-schedule-preview {
        color: #313131;
    }
</style>
<div id="tax-rate-schedule" style="display:none;">
    <div class="am-form">
        <form>
            <div class="row">
                <div class="element-title">
                    <label>{$i18n['date']}</label>
                </div>
                <div class="element">
                    <input type="text" name="date" class="datepicker" size="8" />
                </div>
            </div>
            <div class="row">
                <div class="element-title">
                    <label>{$i18n['tax_rate']}</label>
                </div>
                <div class="element">
                    <input type="text" name="rate" size="3"/> %
                </div>
            </div>
        </form>
    </div>
</div>
CUT
            );
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function($){
    function hasSchedule(id, country)
    {
        return taxSchedule.hasOwnProperty(id) && taxSchedule[id].hasOwnProperty(country);
    }
    function getSchedule(id, country)
    {
        return taxSchedule[id][country];
    }
    function setSchedule(id, country, data)
    {
        taxSchedule.hasOwnProperty(id) || (taxSchedule[id] = {});
        taxSchedule[id][country] = data;
        toggleState();
    }
    function deleteSchedule(id, country)
    {
        delete taxSchedule[id][country];
        toggleState();
    }
    function _GetSQLDate(date) {
        var m = (date.getMonth() + 1).toString();
        m.length == 1 && (m = '0' + m);
        var d = date.getDate().toString();
        d.length == 1 && (d = '0' + d);
        return date.getFullYear() + '-' + m + '-' + d;
    }

    function toggleState()
    {
        $('.tax-rate').each(function(){
            var flagHas = hasSchedule($(this).data('id'), $(this).data('country'));

            $(this).closest('.row').toggleClass('tax-rate-has-rule', flagHas);
            if (flagHas) {
                d = getSchedule($(this).data('id'), $(this).data('country'));
                $(this).closest('.row').find('.tax-rate-schedule-preview-date').
                    text(d.date);
                $(this).closest('.row').find('.tax-rate-schedule-preview-rate').
                    text(d.rate);
            }
        });
        jQuery('#tax-rate-schedule-data').val(JSON.stringify(taxSchedule));
    }

    taxSchedule = jQuery('#tax-rate-schedule-data').val() ? JSON.parse(jQuery('#tax-rate-schedule-data').val()) : {};

    $('.tax-rate').each(function(){
        $(this).after(' <a href="javascript:;" class="local tax-rate-schedule">{$i18n['add']}</a>');
        var s = [
            '<span class="tax-rate-schedule-preview-rate"></span>%',
            '<span class="tax-rate-schedule-preview-date"></span>'];
        var i =1;
        var html = '{$i18n['change_to']}'.replace(/%s/g, function(e) {return s.shift();}, 'g');
        $(this).parent().append(' <span class="tax-rate-schedule-preview">' + html + ' <a href="javascript:;" class="local tax-rate-schedule-delete">{$i18n['delete']}</a></span>');
    })

    toggleState();

    $(document).on('click', '.tax-rate-schedule', function(){
        var c = $(this).closest('div').find('.tax-rate').data('country');
        var ct = $(this).closest('div').find('.tax-rate').data('country-title');
        var id = $(this).closest('div').find('.tax-rate').data('id');
        jQuery('#tax-rate-schedule').data('country', c);
        jQuery('#tax-rate-schedule').data('id', id);
        if (hasSchedule(id, c)) {
            d = getSchedule(id, c);
            jQuery('#tax-rate-schedule').find('[name=date]').datepicker('setDate', d.date);
            jQuery('#tax-rate-schedule').find('[name=rate]').val(d.rate);
        } else {
            jQuery('#tax-rate-schedule').find('[name=date]').val('');
            jQuery('#tax-rate-schedule').find('[name=rate]').val('');
        }
        jQuery('#tax-rate-schedule').dialog('option', 'title', ct);
        jQuery('#tax-rate-schedule').dialog('open');
    });

    $(document).on('click', '.tax-rate-schedule-delete', function(){
        deleteSchedule($(this).closest('.row').find('.tax-rate').data('id'),
            $(this).closest('.row').find('.tax-rate').data('country'));
    });

    jQuery("#tax-rate-schedule").dialog({
        buttons: {
            'Save' : function() {
                var d = _GetSQLDate(jQuery(this).find('[name=date]').datepicker('getDate'));
                var r = jQuery(this).find('[name=rate]').val();
                setSchedule(jQuery(this).data('id'), jQuery(this).data('country'), {
                    'date' : d,
                    'rate' : r});
                jQuery(this).dialog("close");
            },
            'Close' : function() {jQuery(this).dialog("close");}
        },
        modal : true,
        title : '',
        autoOpen: false
    });
});
CUT
            );
        $url = $this->getDi()->url('admin-vat-group');
        $label = Am_Html::escape(___('Add VAT Rates Group'));
        $form->addHtml()
            ->setHtml('<a href="' . $url . '" class="link">' . $label . '</a>');
    }

    function addRatesTable($form, $id, $title, $addDeleteLink = false)
    {
        $fs = $form->addAdvFieldset($id, array(
            'id' => 'tax-rate-group-' . $id,
            'class' => 'tax-rate-container' . ($addDeleteLink ? ' tax-rate-container-add' : '')))
            ->setLabel($title);
        if ($addDeleteLink) {
            $label_del = Am_Html::escape(___('Delete This VAT Rates Group'));
            $label_edit = Am_Html::escape(___('Change Title'));
            $url_del = $this->getDi()->url('admin-vat-group/delete', array('id' => $id));
            $url_edit = $this->getDi()->url('admin-vat-group/edit', array('id' => $id));
            $fs->addHtml(null, array('class' => 'no-label'))
                ->setHtml(<<<CUT
<a href="$url_edit" class="link">$label_edit</a>
&middot;
<a href="$url_del" onclick="return confirm('Are you sure?')" class="link tax-rate-group-delete">$label_del</a>
CUT
                    );
        }
        foreach($this->rates as $c=>$rate){
            if(!isset($this->countries[$c])) continue;
            $r = $fs->addText("$id.$c", array(
                'size' => 3,
                'class' => 'tax-rate',
                'data-id' => $id,
                'data-country' => $c,
                'data-country-title' => $this->countries[$c]))
                ->setLabel($this->countries[$c]);
            $r->setValue(!empty($rate[$c])?$rate[$c]:$this->rates[$c]);
        }
    }

    /**
     * @return Am_Invoice_CountryLookup_Abstract
     */
    function getCountryLookupService()
    {
        return $this->getDi()->countryLookup;
    }

    function hasDigitalProducts(Invoice $invoice)
    {
        if($this->getConfig('tax_digital')) return true;

        foreach($invoice->getProducts() as $p){
            if($p->tax_group && $p->tax_digital) return true;
        }
    }

    public function getRate(Invoice $invoice, InvoiceItem $item = null)
    {
        $u = $invoice->getUser();
        $id = is_null($u) ? false : $u->get('tax_id');
        if ($id && !$this->getConfig('b2b_vat')) {
            // if that is a foreign customer
            if (strtoupper(substr($this->getConfig('my_id'), 0, 2)) != strtoupper(substr($id, 0, 2)))
                return null;
        }
        $country = $this->hasDigitalProducts($invoice) ? ($id ? substr($id, 0, 2) : ( is_null($u) ? false : $u->get('country'))) : $country = $this->getConfig('my_country');

        if (!$country) $country = $this->getConfig('my_country');

        if(!array_key_exists(strtoupper($country), $this->countries))
            return null;

        return $this->getRatePerProduct($country, ($u ? $u->state : null), $item);
    }

    function getRatePerProduct($country, $state, InvoiceItem $item=null)
    {
        $rate = null;
        if ($state) {
            $rate = $this->_getRatePerProduct($state, $item);
        }
        if (is_null($rate)) {
            $rate = $this->_getRatePerProduct($country, $item);
        }
        return $rate;
    }

    protected function _getRatePerProduct($id, InvoiceItem $item=null)
    {
        $customRate = null;
        $this->schedule(sqlDate('now'));
        $id = strtoupper($id);
        if (!empty($item) && $item->item_type == 'product') {
            if(($product = $item->tryLoadProduct()) && $product->tax_rate_group) {
                $rates = $this->getConfig($product->tax_rate_group);
                $customRate = (is_array($rates) && isset($rates[$id]) && !empty($rates[$id])) ? $rates[$id] : null;
            }
        } else {
            $customRate = null;
        }
        return (!is_null($customRate) ? $customRate : $this->getConfig('rate.'.$id, @$this->rates[$id]));
    }

    function schedule($date)
    {
        if ($schedule = $this->getConfig('schedule')) {
            $schedule = json_decode($schedule, true);
            foreach ($schedule as $id => $data) {
                foreach ($data as $country => $rule) {
                    if ($rule['date'] <= $date) {
                        Am_Config::saveValue("tax.{$this->getId()}.$id.$country", $rule['rate']);
                        unset($schedule[$id][$country]);
                    }
                }
                if (!$schedule[$id]) {
                    unset($schedule[$id]);
                }
            }
            $schedule = $schedule ? json_encode($schedule) : '';
            Am_Config::saveValue("tax.{$this->getId()}.schedule", $schedule);
            $this->getDi()->config->read();
            $this->_setConfig((array)$this->getDi()->config->get($this->getDi()->plugins_tax->getConfigKey($this->getId())));
        }
    }

    function onDaily(Am_Event $e)
    {
        $this->schedule(sqlDate('now'));
    }

    function onGridProductInitForm(Am_Event $event)
    {
        $form = $event->getGrid()->getForm();

        $groups = $this->getConfig('tax_groups') ?: array();
        $fieldSet = $form->getElementById('billing');
        $fieldSet->addSelect('tax_rate_group')
            ->setLabel(___('VAT Rates Group'))
            ->loadOptions(array(
                '' => ___('Standard VAT Rates')
            ) + $groups);

        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('[name=tax_group]').change(function(){
        jQuery('[name=tax_rate_group]').closest('.row').toggle(this.checked);
    }).change();
});
CUT
                );

        if(!$this->getConfig('tax_digital')) {
            $fieldSet = $form->getElementById('billing');

            $gr = $fieldSet->addGroup('')->setLabel(___("Electronically Supplied Service\n" .
                'Enable if your product is an electronic service.'));
            $gr->addAdvCheckbox('tax_digital');
            $gr->addHTML()->setHTML(<<<EOT
<div><a href="javascript:;" onclick="jQuery('#tax_digital_example').toggle()" class="local">Examples of Electronic Service</a></div>
<div id="tax_digital_example" style="display:none">
<ul class="list">
 <li>Website supply, web-hosting, distance maintenance of programmes  and equipment;</li>
 <li>Supply of software and updating thereof;</li>
 <li>Supply of images, text and information and making available of databases;</li>
 <li>Supply of music, films and games, including games of chance and gambling games, and of
political, cultural, artistic, sporting, scientific and entertainment broadcast and events;</li>
 <li>Supply of distance teaching.</li>
</ul>
</div>
EOT
    );
        }
    }

    function onInvoiceValidate(Am_Event $event)
    {
        $invoice = $event->getInvoice();
        $user = $invoice->getUser();

        if($user->get('tax_id')) return; // User already has specified his tax ID, do not validate;

        // Disable validation for aMember CP;
        if(defined('AM_ADMIN') && AM_ADMIN)
            return;

        $invoice->data()->set(self::INVOICE_IP, $invoice_ip = $this->getDi()->request->getClientIp());
        $invoice->data()->set(self::USER_REGISTRATION_IP, $user->remote_addr);
        $invoice->data()->set(self::USER_COUNTRY, $user_country = $user->get('country'));
        if(!$invoice->data()->get(self::INVOICE_COUNTRY))
            $invoice->data()->set(self::INVOICE_COUNTRY, $user_country);

        if(
            (($invoice->first_tax>0) || ($invoice->second_tax>0) || ($this->getConfig('tax_location_validate_all') && ($invoice->first_total>0 || $invoice->second_total>0)))
            && $this->getConfig('tax_location_validation')
            && $this->hasDigitalProducts($invoice)
            )
        {
            if(!$this->locationIsValid($invoice))
                $event->setReturn(sprintf(___("Location validation failed.") .
                                          ___("Registration country and your IP address country doesn't match. ")
                                ));
        }
    }

    function locationIsValid(Invoice $invoice)
    {
        $evidence = array();

        $user = $invoice->getUser();

        $invoice_ip = $this->getDi()->request->getClientIp();
        $user_country = $user->get('country');
        if($this->getConfig('tax_location_validate_self'))
            $invoice->data()->set(self::SELF_VALIDATION_COUNTRY, $evidence[] = $user->data()->get(self::SELF_VALIDATION_COUNTRY));

        try {
            $invoice->data()->set(self::INVOICE_IP_COUNTRY, $evidence[] = $this->getCountryLookupService()->getCountryCodeByIp($invoice_ip));
        } catch(Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
        }

        try {
            $invoice->data()->set(self::USER_REGISTRATION_IP_COUNTRY, $evidence[] = $this->getCountryLookupService()->getCountryCodeByIp($user->remote_addr));
        } catch(Exception $e) {
            $this->getDi()->errorLogTable->logException($e);
        }

        if(!in_array($user_country, $evidence))
            return false;

        return true;
    }

    function onSetDisplayInvoicePaymentId(Am_Event $e)
    {
        if($this->getConfig('sequential'))
            $this->setSequentialNumber($e,$this->getConfig('invoice_prefix','INV-'), $this->getConfig('initial_invoice_number', 1));

    }

    function onSetDisplayInvoiceRefundId(Am_Event $e)
    {
        if($this->getConfig('sequential'))
            $this->setSequentialNumber($e,$this->getConfig('invoice_refund_prefix','RFND-'), $this->getConfig('initial_invoice_refund_number', 1));
    }

    function setSequentialNumber(Am_Event $e, $prefix, $default)
    {
        $prefix = str_replace(array('%year%'), array(date('Y')), $prefix);
        $numbers = $this->getDi()->store->getBlob('invoice_sequential_numbers');

        if(empty($numbers))
            $numbers = array();
        else
            $numbers = @unserialize($numbers);

        if(empty($numbers[$prefix]))
            $numbers[$prefix] = $default;
        else
            $numbers[$prefix]++;

        $this->getDi()->store->setBlob('invoice_sequential_numbers', serialize($numbers));
        $e->setReturn($prefix.$numbers[$prefix]);
    }

    function getReadme()
    {
        return <<<EOT
Latest version of plugin readme available here: <a href='http://www.amember.com/docs/Configure_EU_VAT'>http://www.amember.com/docs/Configure_EU_VAT</a>
EOT;
    }

    function onValidateSavedForm(Am_Event $e)
    {
        if(!$this->getConfig('tax_location_validate_self')) return;

        $form = $e->getForm();
        $el = $form->getElementById('f_country');
        if(!empty($el))
        {
            $user_country = $el->getValue();
            try{
                // Form has country element. Now we need to check user's choice and validate it agains IP country.
                $current_country = $this->getDi()->countryLookup->getCountryCodeByIp($this->getDi()->request->getClientIp());
            }catch(Exception $e){
                // Nothing to do;
                $error = $e->getMessage();
            }
            if(empty($current_country) || ($current_country !== $user_country)){
                // Need to add self-validation element;
                if(!($sve = $form->getElementById('tax_self_validation')) || !$sve->getValue()){
                    $sve = new Am_Form_Element_AdvCheckbox('tax_self_validation');
                    $sve->setLabel(___('Confirm Billing Country'))
                        ->setId('tax_self_validation')
                        ->setContent(sprintf(
                            "<span class='error' style='display:inline;'><b>".___("I confirm I'm based in %s")."</b></span>",
                            $this->getDi()->countryTable->getTitleByCode($user_country)));

                    foreach($form as $el1){
                        $form->insertBefore($sve, $el1);
                        break;
                    }
                    if($sve->getValue()){
                        // Confirmed;
                        $this->getDi()->session->tax_self_validation_country = $el->getValue();
                    }else{
                        $form->setError(
                                        ___("Please confirm your billing address manually") . "<br/>" .
                                        ___("It looks like you are not at home right now.") . "<br/>" .
                                        ___("In order to comply with EU VAT Rules we need you to confirm your billing country.")
                            );
                    }
                }else{
                    // Element already added;  nothing to do
                }
            }

        }
    }

    function setSelfValidationCountry(User $user)
    {
        if($country = $this->getDi()->session->tax_self_validation_country){
            $user->data()->set(self::SELF_VALIDATION_COUNTRY, $country);
            $this->getDi()->session->tax_self_validation_country = null;
        }
    }

    function onUserBeforeUpdate(Am_Event $e)
    {
        $this->setSelfValidationCountry($e->getUser());
    }

    function onUserBeforeInsert(Am_Event $e)
    {
        $this->setSelfValidationCountry($e->getUser());
    }

    function onAdminMenu(Am_Event $e)
    {
        $menu = $e->getMenu();
        $reports = $menu->findOneBy('id', 'reports');
        $reports->addPage(array(
                    'id' => 'reports-vat',
                    'controller' => 'admin-vat-report',
                    'label' => ___('EU VAT Report'),
                    'resource' => Am_Auth_Admin::PERM_REPORT,
                ));
    }

    function onGridUserInitForm(Am_Event_Grid $event)
    {
        $form = $event->getGrid()->getForm();

        $address_fieldset  = $form->getElementById('address_info');

        $tax_id_el = new HTML_QuickForm2_Element_InputText('tax_id');
        $tax_id_el->setLabel(___('Tax Id'));

        $form->insertBefore($tax_id_el, $address_fieldset);
    }
}

class Am_Invoice_Tax_Gst extends Am_Invoice_Tax_Regional
{
    protected $_alwaysAbsorb = true;

    public function getTitle()
    {
        return ___("GST (Inclusive Tax)");
    }
}