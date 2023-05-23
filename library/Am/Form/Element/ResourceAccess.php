<?php

class Am_Form_Element_ResourceAccess extends HTML_QuickForm2_Element
{
    protected $value = array();

    public function getType()
    {
        return 'resource-access';
    }

    public function getRawValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    public function __toString()
    {
        $id = preg_replace('/[^a-z0-9A-Z-_]/', '_', $this->getName());
        $id = Am_Html::escape($id);
        $ret = "<div class='resourceaccess' id='$id'>";

        if (!$this->getAttribute('without_free')) {
            $ret .= "<span class='free-switch protected-access'>\n";
            $ret .= ___('Choose Products and/or Product Categories that allows access') . "<br />\n";
            $ret .= ___('or %smake access free%s', "<a href='javascript:' data-access='free' class='local'>", '</a>') . "<br /><br />\n";
        }

        $select = new HTML_QuickForm2_Element_Select(null, array('class' => 'access-items am-combobox-fixed'));
        $select->addOption(___('Please select an item...'), '');
        $g = $select->addOptgroup(___('Product Categories'), array('class' => 'product_category_id', 'data-text' => ___("Category")));
        $g->addOption(___('Any Product'), '-1', array('style' => 'font-weight: bold'));
        foreach (Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions() as $k => $v) {
            $g->addOption($v, "product_category_id$k");
        }
        $g = $select->addOptgroup(___('Products'), array('class' => 'product_id', 'data-text' => ___("Product")));
        foreach (Am_Di::getInstance()->productTable->getOptions() as $k => $v) {
            $g->addOption($v, "product_id$k");
        }
        if (!$this->getAttribute('without_user_group_id') &&
            ($op = Am_Di::getInstance()->userGroupTable->getOptions())) {
            $g = $select->addOptgroup(___('User Groups'), array('class' => 'user_group_id', 'data-text' => ___("User Group")));
            foreach ($op as $k => $v) {
                $g->addOption($v, "user_group_id$k");
            }
        }
        $data = $this->getData();
        if (!empty($data['special']))
        {
            $g = $select->addOptgroup(___('Special Conditions'), array('class' => 'special', 'data-text' => ___("Also available for  ")));
            $spec = (array)$data['special'];
            foreach (Am_Di::getInstance()->resourceAccessTable->getFnSpecialOptions() as $k => $v)
                if (in_array($k, $spec))
                    $g->addOption($v, "special$k");
        }
        $ret .= (string) $select;

        foreach (Am_Di::getInstance()->resourceAccessTable->getFnValues() as $k)
            $ret .= "<div class='$k-list'></div>";

        $ret .= "</span>\n";

        $hide_free_without_login = (bool) $this->getAttribute('without_free_without_login');

        $ret .= "<span class='free-switch free-access' style='display:none;'>" .
            nl2br(___("this item is available for %sall registered customers%s.\n"
                    . "click to %smake this item protected%s\n"
                    . "%sor %smake this item available without login and registration%s\n%s"
                    , "<b>", "</b>"
                    , "<a href='javascript:;' data-access='protected' class='local'>", "</a>"
                    , ($hide_free_without_login ? '<span style="display:none">' : '<span>')
                    , "<a href='javascript:;' data-access='free_without_login' class='local'>", "</a>", '</span>')) .
            "</span>";

        $ret .= "<span class='free-switch free_without_login-access' style='display:none;'>" .
            nl2br(___("this item is available for %sall visitors (without log-in and registration) and for all members%s\n"
                    . "click to %smake this item protected%s\n"
                    . "or %smake log-in required%s\n"
                    , "<b>", "</b>"
                    , "<a href='javascript:;' data-access='protected' class='local'>", "</a>"
                    , "<a href='javascript:;' data-access='free' class='local'>", "</a>")) .
            "</span>";

        $json = array();
        if (
            !empty($this->value['product_category_id'])
            || !empty($this->value['product_id'])
            || !empty($this->value['free'])
            || !empty($this->value['free_without_login'])
            || !empty($this->value['user_group_id'])
            || !empty($this->value['special'])
        ) {
            $json = $this->value;
            foreach ($json as & $fn)
                foreach ($fn as & $rec) {
                    if (is_string($rec))
                        $rec = json_decode($rec, true);
                }
        } else
            foreach ($this->value as $cl => $access) {
                $json[$access->getClass()][$access->getId()] = array(
                    'text' => $access->getTitle(),
                    'start' => $access->getStart(),
                    'stop' => $access->getStop(false),
                );
            }

        $json = Am_Html::escape(json_encode($json));
        $ret .= "<input type='hidden' class='resourceaccess-init' value='$json' />\n";
        $ret .= "</div>";

        $without_period = $this->getAttribute('without_period') ? 'true' : 'false';
        $with_date_based = $this->getAttribute('with_date_based') ? 'true' : 'false';
        $ret .= "
        <script type='text/javascript'>
        jQuery(function(){
             jQuery('#$id.resourceaccess').resourceaccess({without_period: $without_period, with_date_based: $with_date_based});
        });
        </script>
        ";
        return $ret;
    }
}
