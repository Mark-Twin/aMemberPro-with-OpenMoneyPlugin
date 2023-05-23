<?php

/**
 * Provides an UI element to edit regional taxes
 * @package Am_Form
 */
class Am_Form_Element_RegionalTaxes extends HTML_QuickForm2_Element
{
    protected $regional_taxes = array();

    public function getRawValue()
    {
        return $this->regional_taxes;
    }

    protected function updateValue()
    {
        $name = $this->getName();
        $name2 = Am_Form_Setup::name2underscore($name);
        foreach ($this->getDataSources() as $ds) {
            if (null !== ($value = $ds->getValue($name))) {
                $this->setValue($value);
                return;
            }
            if (null !== ($value = $ds->getValue($name2))) {
                $this->setValue($value);
                return;
            }
        }
    }

    public function setValue($value)
    {
        foreach ((array)$value as $k => $r)
        {
            if (is_array($r)) continue;
            $r = preg_replace('/[^A-Za-z0-9_.,|-]/', '', $r);
            $r = explode('|', $r, 4);
            $value[$k] = array(
                'country' => $r[0],
                'state'   => $r[1],
                'zip'     => $r[2],
                'tax_value'    => sprintf('%.3f', $r[3]),
            );
        }
        $this->regional_taxes = $value;
    }

    public function getType()
    {
        return 'custom_row';
    }

    public function __toString()
    {
        $output = sprintf('<div style="padding:0.5em"><h1>%s</h1>
<div class="info">%s</div>
<div class="grid-container"><table id="regional-tax-table" class="grid"><tr><th>%s</th>
	<th>%s</th>
        <th>%s</th>
	<th>%s</th>
	<th>&nbsp;</th></tr>',
            ___('Configured Tax Values'),
            ___('You can leave any location field empty to match wildcard. ZIP field accept comma separated list of ZIPs (eg. 10021,90210) and intervals (eg. 90100-90210,10021,10120-10240)'),
            ___('Country'),
            ___('State'),
            ___('Zip'),
            ___('Tax Value')
        );

        $label_add = ___('Add');
        $output .= "<tr class='regional-tax-add grid-row'>" .
            "<td width='30%'><select id='regional-tax-country' size='1'><option/>".Am_Html::renderOptions(Am_Di::getInstance()->countryTable->getOptions())."</select>" .
            "<td width='30%'><select id='regional-tax-state' size='1'></select></td>" .
            "<td><input type='text' id='regional-tax-zip' /></td>" .
            "<td><input type='text' id='regional-tax-rate' size='5' maxlength='6' /></td>" .
            "<td><input type='button' id='regional-tax-add-button' value='$label_add'/></td>" .
            "</tr>\n";

        $name = $this->getName();
        foreach ($this->regional_taxes as $id => $region) {
            $hidden = Am_Html::escape(implode('|', array(
                $region['country'],
                $region['state'],
                $region['zip'],
                $region['tax_value'],
            )));
            $output .= '<tr class="grid-row">'
                . sprintf('<td>%s</td>', Am_Di::getInstance()->countryTable->getTitleByCode($region['country']))
                . sprintf('<td>%s</td>', ($region['state'] ? Am_Di::getInstance()->stateTable->getTitleByCode($region['country'], $region['state']) : '*'))
                . sprintf('<td>%s</td>', ($region['zip'] ? $region['zip'] : '*'))
                . sprintf('<td>%.3f%s</td>', $region['tax_value'], '&nbsp;%')
                . sprintf('<td><a href="javascript:" class="regional-tax-remove local">%s</a>%s</td>',
                        ___('Remove'),
                        "<input type='hidden' name='{$name}[]' value='$hidden'>")
                . '</tr>';
        }

        $output .= '</table></div></div>';
        $id = $this->getId();
        $output .= "
        <style type='text/css'>
            #row-$id .element-title { display: none;  }
            #row-$id .element { margin-left: 0 }
        </style>
        ";
        return sprintf('<tr class="grid-row"><td colspan="2" id="tax-regional-regions">%s</td></tr>',
            $output . $this->getJs());
    }

    function getJs()
    {
        $countries = json_encode(Am_Di::getInstance()->countryTable->getOptions());
        $states = array();
        foreach (Am_Di::getInstance()->db->select("SELECT country, state, title FROM ?_state") as $r)
            $states[$r['country']][$r['state']] = $r['title'];
        $states = json_encode($states);
        $name = $this->getName();
        $remove = ___('Remove');
        return <<<CUT
<script type='text/javascript'>
jQuery(function(){
    var countries = $countries;
    var states = $states;
    jQuery("#regional-tax-country").change(function(){
        var sel = jQuery("#regional-tax-state");
        sel.find('option').remove();
        var options = states[ jQuery(this).val() ];
        sel.append(jQuery('<option>'));
        if (!options) return;
        jQuery.each(options, function(key, value) {
            sel.append(jQuery('<option>', { value : key })
                .text(value));
        });
    });
    jQuery("#regional-tax-add-button").click(function(){
        var c = jQuery("#regional-tax-country").val();
        var r = jQuery("#regional-tax-rate").val();
        if (!c || !r) {
            flashError("Country and Tax Rate fields are required");
            return;
        }
        var s = jQuery("#regional-tax-state").val();
        var z = jQuery("#regional-tax-zip").val();
        //
        var row = jQuery("<tr class='grid-row' />");
        row.append(jQuery("<td />").text(countries[c]));
        row.append(jQuery("<td />").text(states[c][s] ? states[c][s] : ''));
        row.append(jQuery("<td />").text(z));
        row.append(jQuery("<td />").text(r + ' %'));
        var hidden = jQuery("<input type='hidden' name='{$name}[]'/>").val(c+'|'+s+'|'+z+'|'+r);
        row.append(jQuery("<td><a href='javascript:' class='regional-tax-remove local'>$remove</a></td>").append(hidden));
        jQuery("#regional-tax-zip").val("");
        jQuery("#regional-tax-state").val("");
        jQuery("#regional-tax-table").append(row);
    });
    jQuery(document).on('click',"a.regional-tax-remove", function(){
        jQuery(this).closest("tr").remove();
    });
});
</script>
CUT;
    }
}