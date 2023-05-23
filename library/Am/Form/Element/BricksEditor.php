<?php

/**
 * An admin UI element to handle visual bricks configuration
 *
 * @package Am_SavedForm
 */
class Am_Form_Element_BricksEditor extends HTML_QuickForm2_Element
{
    const ALL = 'all';
    const ENABLED = 'enabled';
    const DISABLED = 'disabled';

    protected $bricks = array();
    protected $value = array();
    /** @var Am_Form_Bricked */
    protected $brickedForm = null;

    public function __construct($name, $attributes, Am_Form_Bricked $form)
    {
        $attributes['class'] = 'no-label';
        parent::__construct($name, $attributes, null);
        $this->brickedForm = $form;
        class_exists('Am_Form_Brick', true);
        foreach ($this->brickedForm->getAvailableBricks() as $brick)
            $this->bricks[$brick->getClass()][$brick->getId()] = $brick;
    }

    public function getType()
    {
        return 'hidden'; // we will output the row HTML too
    }

    public function getRawValue()
    {
        $value = array();
        foreach ($this->value as $row)
        {
            if ($brick = $this->getBrick($row['class'], $row['id']))
                $value[] = $brick->getRecord();
        }
        return json_encode($value);
    }

    public function setValue($value)
    {
        if (is_string($value))
            $value = json_decode($value, true);
        $this->value = (array)$value;
        foreach ($this->value as & $row)
        {
            if (empty($row['id']))
                continue;
            if (isset($row['config']) && is_string($row['config']))
            {
                parse_str($row['config'], $c);
                if (get_magic_quotes_gpc())
                    $c = Am_Mvc_Request::ss($c); // remove quotes
                $row['config'] = $c;
            }
            if ($brick = $this->getBrick($row['class'], $row['id']))
            {
                $brick->setFromRecord($row);
            }
        }
        // handle special case - where there is a "multiple" brick and that is enabled
        // we have to insert additional brick to "disabled", so new bricks of same
        // type can be added in editor
        $disabled = $this->getBricks(self::DISABLED);
        foreach ($this->getBricks(self::ENABLED) as $brick)
        {
            if (!$brick->isMultiple()) continue;
            $found = false;
            foreach ($disabled as $dBrick)
                if ($dBrick->getClass() == $brick->getClass()) { $found = true; break;};
            // create new disabled brick of same class
            if (!$found)
                $this->getBrick($brick->getClass(), null);
        }
    }

    /**
     * Clones element if necessary (if id passed say as "id-1" and it is not found)
     * @return Am_Form_Brick|null
     */
    public function getBrick($class, $id)
    {
        if
        (  !isset($this->bricks[$class][$id])
            && isset($this->bricks[$class])
            && current($this->bricks[$class])->isMultiple()
        )
        {
            if ($id === null)
                for ($i = 0; $i<100; $i++)
                    if (!array_key_exists($class . '-' . $i, $this->bricks[$class]))
                    {
                        $id = $class . '-' . $i;
                        break;
                    }
            $this->bricks[$class][$id] = Am_Form_Brick::createFromRecord(array('class' => $class, 'id' => $id));
        }
        return empty($this->bricks[$class][$id]) ? null : $this->bricks[$class][$id];
    }

    public function getBricks($where = self::ALL)
    {
        $enabled = array();
        foreach ($this->value as $row)
            if (!empty($row['id']))
                $enabled[ ] = $row['id'];

        $ret = array();
        foreach ($this->bricks as $class => $bricks)
            foreach ($bricks as $id => $b)
            {
                if ($where == self::ENABLED && !in_array($id, $enabled))
                    continue;
                if ($where == self::DISABLED && in_array($id, $enabled))
                    continue;
                $ret[$id] = $b;
            }
        // if we need enabled element, we need to maintain order according to value
        if ($where == self::ENABLED)
        {
            $ret0 = $ret;
            $ret = array();
            foreach ($enabled as $id)
                if (isset($ret0[$id]))
                    $ret[$id] = $ret0[$id];
        }
        return $ret;
    }

    public function render(HTML_QuickForm2_Renderer $renderer)
    {
        $view = new Am_View;
        $p = explode('bricks-editor.js', $view->_scriptJs('bricks-editor.js')); // dirty hack to get path to JS folder
        $renderer->getJavascriptBuilder()->addLibrary('bricks-editor', 'bricks-editor.js',
            $p[0]);
        return parent::render($renderer);
    }

    public function __toString()
    {
        $enabled = $disabled = "";
        foreach ($this->getBricks(self::ENABLED) as $brick)
            $enabled .= $this->renderBrick($brick, true) . "\n";
        foreach ($this->getBricks(self::DISABLED) as $brick)
            $disabled .= $this->renderBrick($brick, false) . "\n";

        $hidden = is_string($this->value) ? $this->value : json_encode($this->value);
        $hidden = Am_Html::escape($hidden);

        $name = $this->getName();
        $formBricks = ___("Form Bricks (drag to right to remove)");
        $availableBricks = ___("Available Bricks (drag to left to add)");
        $comments = nl2br(
            ___("To add fields into the form, move item from 'Available Bricks' to 'Form Bricks'.\n".
            "To remove fields, move it back to 'Available Bricks'.\n".
            "To make form multi-page, insert 'PageSeparator' item into the place where you want page to be split.")
           );

        $filter = $this->renderFilter();
        return $this->getCss() . $this->getJs() . <<<CUT
    <input type="hidden" name="$name" value="$hidden">
    <div class="brick-section">
    <div class='brick-header'><h3>$formBricks</h3> $filter</div>
    <div id='bricks-enabled' class='connectedSortable'>
    $enabled
    </div>
</div>
<div class="brick-section brick-section-available">
    <div class='brick-header'><h3>$availableBricks</h3> $filter</div>
    <div id='bricks-disabled' class='connectedSortable'>
    $disabled
    </div>
</div>
<div style='clear: both'></div>
<div class='brick-comment'>$comments</div>
CUT;
    }

    public function renderConfigForms()
    {
        $out = "<!-- brick config forms -->";
        foreach ($this->getBricks(self::ALL) as $brick)
        {
            if (!$brick->haveConfigForm())
                continue;
            $form = new Am_Form_Admin(null,null,true);
            $brick->initConfigForm($form);
            $form->setDataSources(array(new Am_Mvc_Request($brick->getConfigArray())));
            $out .= "<div id='brick-config-{$brick->getId()}' class='brick-config' style='display:none'>\n";
            $out .= (string) $form;
            $out .= "</div>\n\n";
        }

        $form = new Am_Form_Admin;
        $form->addElement('textarea', '_tpl', array('rows' => 2, 'class' => 'el-wide'))->setLabel('-label-');
        $out .= "<div id='brick-labels' style='display:none'>\n";
        $out .= (string)$form;
        $out .= "</div>\n";
        $out .= "<!-- end of brick config forms -->";
        return $out;
    }

    public function renderBrick(Am_Form_Brick $brick, $enabled)
    {
        $class = '';
        $configure = $labels = null;
        $attr = array(
            'id' => $brick->getId(),
            'class' => "brick $class " . $brick->getClass(),
            'data-class' => $brick->getClass(),
            'data-title' => strtolower($brick->getName())
        );
        if ($brick->haveConfigForm())
        {
            $attr['data-config'] = json_encode($brick->getConfigArray());
            $configure = "<a class='configure local' href='javascript:;' title='" .
                Am_Html::escape($brick->getName() . ' ' . ___('Configuration')) . "'>" . ___('configure') . "</a>";
        }
        if ($brick->getStdLabels())
        {
            $attr['data-labels'] = json_encode($brick->getCustomLabels());
            $attr['data-stdlabels'] = json_encode($brick->getStdLabels());
            $class = $brick->getCustomLabels() ? 'labels custom-labels' : 'labels';
            $labels = "<a class='$class local' href='javascript:;' title='" . Am_Html::escape(___('Edit Brick Labels')) . "'>" . ___('labels') . "</a>";
        }

        if ($brick->isMultiple())
            $attr['data-multiple'] = "1";

        if ($brick->hideIfLoggedInPossible() == Am_Form_Brick::HIDE_DESIRED)
            $attr['data-hide'] = $brick->hideIfLoggedIn() ? 1 : 0;

        $attrString = "";
        foreach ($attr as $k => $v)
            $attrString .= " $k=\"".htmlentities($v, ENT_QUOTES, 'UTF-8', true)."\"";

        $checkbox = $this->renderHideIfLoggedInCheckbox($brick);
        return "<div $attrString>
        <strong class=\"brick-title\">{$brick->getName()}</strong>
        $configure
        $labels
        $checkbox
        </div>";
    }

    public function renderFilter()
    {
        $l_filter = Am_Html::escape(___('filter'));
        $l_placeholder = Am_Html::escape(___('type part of brick name to filter…'));
        return <<<CUT
<span><a href="javascript:;" class="input-brick-filter-link local closed">$l_filter</a></span>
<div class="input-brick-filter-wrapper">
    <div class="input-brick-filter-inner-wrapper">
        <input class="input-brick-filter"
               type="text"
               name="q"
               autocomplete="off"
               placeholder="$l_placeholder" />
        <div class="input-brick-filter-empty">&nbsp;</div>
    </div>
</div>
CUT;
    }

    protected function renderHideIfLoggedInCheckbox(Am_Form_Brick $brick)
    {
        if (($this->brickedForm->isHideBricks()))
        {
            if ($brick->hideIfLoggedInPossible() != Am_Form_Brick::HIDE_DONT)
            {
                static $checkbox_id = 0;
                $checkbox_id++;
                $checked = $brick->hideIfLoggedIn();
                if ($brick->hideIfLoggedInPossible() == Am_Form_Brick::HIDE_ALWAYS)
                {
                    $checked = "checked='checked'";
                    $disabled = "disabled='disabled'";
                } else {
                    $disabled = "";
                    $checked = $brick->hideIfLoggedIn() ? "checked='checked'" : '';
                }
                return
                    "<span class='hide-if-logged-in'><input type='checkbox'".
                    " id='chkbox-$checkbox_id' value=1 $checked $disabled />" .
                    " <label for='chkbox-$checkbox_id'>" . ___('hide if logged-in') . "</label></span>\n";
            }
        }
    }

    public function getJs()
    {
        return <<<CUT
<script type="text/javascript">
jQuery(function(){
    jQuery('.input-brick-filter-link').click(function(){
        jQuery('.input-brick-filter-wrapper', jQuery(this).closest('.brick-section')).toggle();
        if (jQuery(this).hasClass('closed'))
            jQuery('.input-brick-filter-wrapper input', jQuery(this).closest('.brick-section')).focus();
        jQuery(this).toggleClass('opened closed')
        jQuery('.input-brick-filter', jQuery(this).closest('.brick-section')).val('').change();
    });
    jQuery(document).on('keyup change','.input-brick-filter', function(){
         var \$context = jQuery(this).closest('.brick-section');
         jQuery('.input-brick-filter-empty', \$context).toggle(jQuery(this).val().length != 0);

         if (jQuery(this).val()) {
             jQuery('.brick', \$context).hide();
             jQuery('.brick[data-title*="' + jQuery(this).val().toLowerCase() + '"]', \$context).show();
         } else {
             jQuery('.brick', \$context).show();
         }
    })

    jQuery('.input-brick-filter-empty').click(function(){
        jQuery(this).closest('.input-brick-filter-wrapper').find('.input-brick-filter').val('').change();
        jQuery(this).hide();
    })
});
</script>
CUT;
    }

    public function getCss()
    {
        $id = $this->getId();
        $declined = Am_Di::getInstance()->view->_scriptImg('icons/decline-d.png');
        $decline = Am_Di::getInstance()->view->_scriptImg('icons/decline.png');
        return <<<CUT
<style type="text/css">
.brick {
    border: solid 1px #e7e7e7;
    margin: 4px;
    padding: 0.4em;
    background: #f1f1f1;
    cursor: move;
    -webkit-border-radius: 2px;
    -moz-border-radius: 2px;
    border-radius: 2px;
    box-sizing: content-box;
}

.brick:hover {
    border-color: #777;
}

.brick-title {
    font-weight: normal;
    color: black
}

.page-separator {
    background: #FFFFCF;
}

.invoice-summary {
    background: #afccaf;
}

.product {
    background: #D3DCE3;
}

.paysystem {
    background: #ffd963;
}

.manual-access,
.user-group {
    opacity: .5;
}

.brick-section {
    width: 40%;
    padding: 10px;
    float: left;
}

.brick-section.brick-section-available {
    width: 55%;
}

.brick-comment {
    padding: 10px;
}

.hide-if-logged-in {
    margin-left: 20px;
    float: right;
    font-size: .8rem;
}

#bricks-enabled .page-separator {
    margin-bottom: 20px;
}

#bricks-enabled {
    min-height: 200px;
    padding-bottom:4em;
    border: 2px dashed #ddd;
}

#bricks-disabled {
    overflow: hidden;
    min-height: 50px;
}

#bricks-disabled a.configure,
#bricks-disabled a.labels,
#bricks-disabled .hide-if-logged-in {
    display: none;
}

#bricks-disabled .brick {
    float: left;
    margin: 2px;
    width: 45%;
    overflow: hidden;
    white-space: nowrap
}

a.configure,
a.labels {
    margin-left: 0.2em;
    cursor: pointer;
    color: #34536E;
}

a.labels.custom-labels {
    color: #360;
}

/* Filter */

.brick-header {
    margin-bottom:0.8em;
}
.brick-header h3 {
    display: inline;
}

.input-brick-filter-wrapper {
    overflow: hidden;
    padding: 0.4em;
    border: 1px solid #C2C2C2;
    margin-bottom: 1em;
    display: none;
}

.input-brick-filter-inner-wrapper {
    position: relative;
    padding-right:15px;
}

.input-brick-filter-empty {
    position: absolute;
    top:0;
    right:0;
    width: 20px;
    cursor: pointer;
    opacity: .3;
    background: url("$declined") no-repeat center center transparent;
}

.input-brick-filter-empty:hover {
   opacity: 1;
   background-image: url("$decline");
}

input[type=text].input-brick-filter {
    padding:0;
    margin:0;
    border: none;
    width:100%;
}
input[type=text].input-brick-filter:focus {
    border: none;
    box-shadow: none;
}
input[type=text].input-brick-filter:focus {
    border: none;
    outline: 0;
    background: none;
}
#bricks-enabled .brick-editor-placeholder {
    border: 1px dashed #d3dce3;
    margin: 4px;
    height: 25px;
}
#bricks-disabled .brick-editor-placeholder {
    border: 1px dashed #d3dce3;
    margin: 2px;
    height: 25px;
    width: 45%;
    float: left;
}
</style>
CUT;
    }
}