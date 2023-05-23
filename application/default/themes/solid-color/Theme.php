<?php

class Am_Theme_SolidColor extends Am_Theme_Default
{
    protected $publicWithVars = array(
        'css/theme.css',
    );

    const F_TAHOMA = 'Tahoma',
        F_ARIAL = 'Arial',
        F_TIMES = 'Times',
        F_HELVETICA = 'Helvetica',
        F_ROBOTO = 'Roboto',
        F_POPPINS = 'Poppins',
        F_OXYGEN = 'Oxygen',
        F_HIND = 'Hind',

        SHADOW = '0px 0px 5px #00000022;';

    public function initSetupForm(Am_Form_Setup_Theme $form)
    {
        $this->getDi()->view->headLink()
            ->appendStylesheet('https://fonts.googleapis.com/css?family=Roboto')
            ->appendStylesheet('https://fonts.googleapis.com/css?family=Poppins:300')
            ->appendStylesheet('https://fonts.googleapis.com/css?family=Oxygen')
            ->appendStylesheet('https://fonts.googleapis.com/css?family=Hind');

        parent::initSetupForm($form);
        $form->removeElementByName('header');
        $el = HTML_QuickForm2_Factory::createElement('advradio', 'logo_align', null, array(
            'options' => array(
                'left' => ___('Left'),
                'center' => ___('Center'),
                'right' => ___('Right')
            )
        ));
        $el->setLabel(___('Logo Position'));

        $form->insertBefore($el, $form->getElementById('logo-link-group'));
        $el = HTML_QuickForm2_Factory::createElement('advradio', 'logo_width', null, array(
            'options' => array(
                'auto' => ___('As Is'),
                '100%' => ___('Responsive')
            )
        ));
        $el->setLabel(___('Logo Width'));

        $form->insertBefore($el, $form->getElementById('logo-link-group'));

        $form->addProlog(<<<CUT
<style type="text/css">
<!--
    .color-pick {
        vertical-align: middle;
        cursor: pointer;
        display: inline-block;
        width: 1em;
        height: 1em;
        border-radius: 50%;
        transition: transform .3s;
    }
    .color-pick:hover {
        transform: scale(1.8);
    }
-->
</style>
CUT
        );

        $this->addElementColor($form, 'color', "Theme Color\n" .
                'you can use any valid <a href="http://www.w3schools.com/html/html_colors.asp" class="link" target="_blank" rel="noreferrer">HTML color</a>, you can find useful color palette <a href="http://www.w3schools.com/TAGS/ref_colornames.asp" class="link" target="_blank" rel="noreferrer">here</a>', 'theme-color');

        $this->addElementBg($form);

        $this->addElementColor($form, 'link_color', "Links Color\n" .
                'you can use any valid <a href="http://www.w3schools.com/html/html_colors.asp" class="link" target="_blank" rel="noreferrer">HTML color</a>, you can find useful color palette <a href="http://www.w3schools.com/TAGS/ref_colornames.asp" class="link" target="_blank" rel="noreferrer">here</a>');

        $form->addScript()
            ->setScript(<<<CUT
jQuery(document).on('click', '.color-pick', function(){
    $(this).closest('.row').find('input').val($(this).data('color')).change().valid();
});
jQuery(function(){
    function hexToRgb(hex) {
       var result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
       return result ? {
           r: parseInt(result[1], 16),
           g: parseInt(result[2], 16),
           b: parseInt(result[3], 16)
       } : null;
    }

    $('.color-input').change(function(){
        var tColor = 'inherit';

        if ((c = hexToRgb($(this).val())) &&
            (1 - (0.299 * c.r + 0.587 * c.g + 0.114 * c.b) / 255 > 0.5)) {
            tColor = '#fff';
        }
        $(this).css({background: $(this).val(), color: tColor, border: 'none'});
    }).change();
});
CUT
            );

        $gr = $form->addGroup()
            ->setLabel(___('Layout Width'));
        $gr->setSeparator(' ');
        $gr->addText('max_width', array('size' => 3));
        $gr->addHtml()->setHtml('px');

        $gr = $form->addGroup()
            ->setLabel(___('Border Radius'));
        $gr->setSeparator(' ');
        $gr->addText('border_radius', array('size' => 3, 'placeholder' => 0));
        $gr->addHtml()->setHtml('px');

        $form->addAdvCheckbox('drop_shadow')
            ->setLabel(___('Drop Shadow'));

        $gr = $form->addGroup()
            ->setLabel(___("Font\nSize and Family"));
        $gr->setSeparator(' ');

        $gr->addText('font_size', array('size' => 3));
        $gr->addHtml()->setHtml('px');
        $gr->addSelect('font_family')
            ->loadOptions($this->getFontOptions());
        $form->addHtml()
            ->setHtml(<<<CUT
<div id="font-preview" style="opacity:.7; white-space: nowrap; overflow: hidden; text-overflow:ellipsis">Almost before we knew it, we had left the ground.</div>
CUT
        );

        $form->addScript()
            ->setScript(<<<CUT
jQuery(document).on('change', '[name$=font_family]', function(){
    $('#font-preview').css({fontFamily: $(this).val()});
});
jQuery(document).on('change', '[name$=font_size]', function(){
    $('#font-preview').css({fontSize: $(this).val() + 'px'});
});
jQuery(function(){
    $('[name$=font_family]').change();
    $('[name$=font_size]').change();
});
CUT
        );

        $fs = $form->addAdvFieldset('', array('id' => 'sct-css'))
            ->setlabel(___("Custom CSS Rule"));
        $fs->addTextarea('css', array('class' => 'el-wide'))
            ->setLabel('CSS');

        $form->addSaveCallbacks(array($this, 'moveBgFile'), null);
        $form->addSaveCallbacks(array($this, 'updateBg'), null);
        $form->addSaveCallbacks(array($this, 'findInverseColor'), null);
        $form->addSaveCallbacks(array($this, 'findDarkenColor'), null);
        $form->addSaveCallbacks(array($this, 'updateVersion'), null);
        $form->addSaveCallbacks(array($this, 'updateShadow'), null);
        $form->addSaveCallbacks(array($this, 'normalize'), null);

        $form->addSaveCallbacks(null, array($this, 'updateFile'));
    }

    function addElementBg($form)
    {
        $form->addProlog(<<<CUT
<style type="text/css">
<!--
    #bg-settings {
        display:none;
        border-bottom:1px solid #d5d5d5;
    }
    #bg-settings div.row {
        border-bottom: none;
    }
-->
</style>
CUT
        );

        $l_settings = Am_Html::escape(___('Settings'));

        $form->addHtml()
            ->setLabel(___('Background'))
            ->setHtml(<<<CUT
<a href="javascript:;" class="link local link-toggle"
    onclick="jQuery('#bg-settings').toggle(); jQuery(this).closest('.row').toggleClass('row-head'); jQuery(this).toggleClass('link-toggle-on');">{$l_settings}</a>
CUT
            );

        $form->addRaw()
            ->setContent(<<<CUT
                <div id="bg-settings">
CUT
                );

        $form->addUpload('bg_img', array('class' => 'row-highlight'), array('prefix' => 'theme-default'))
                ->setLabel(___("Backgroud Image"))->default = '';

        $form->addAdvRadio("bg_size", array('class' => 'row-highlight'))
            ->setLabel(___("Background Size"))
            ->loadOptions(array(
                'auto' => 'As Is',
                '100%' => '100% Width',
                'cover' => 'Cover',
                'contain' => 'Contain'
            ));

        $form->addAdvRadio("bg_attachment", array('class' => 'row-highlight'))
            ->setLabel(___("Background Attachment"))
            ->loadOptions(array(
                'scroll' => 'Scroll',
                'fixed' => 'Fixed',
            ));

        $form->addAdvRadio("bg_repeat", array('class' => 'row-highlight'))
            ->setLabel(___("Background Repeat"))
            ->loadOptions(array(
                'no-repeat' => 'No Repeat',
                'repeat' => 'Repeat',
                'repeat-x' => 'Repeat X',
                'repeat-y' => 'Repeat Y',
            ));

        $form->addRaw()
            ->setContent('</div>');
    }

    function addElementColor($form, $name, $label, $id = null)
    {
        $gr = $form->addGroup()
            ->setLabel($label);
        $gr->setSeparator(' ');

        $attr = array('size' => 7, 'class' => 'color-input');
        if ($id) {
            $attr['id'] = $id;
        }

        $gr->addText($name, $attr)
            ->addRule('regex', ___('Color should be in hex representation'), '/#[0-9a-f]{6}/i');

        foreach (array('#f1f5f9', '#dee7ec', '#ffebcd', '#ff8a80', '#ea80fc',
            '#d1c4e9', '#e3f2fd', '#bbdefb', '#0079d1', '#b2dfdb', '#e6ee9c',
            '#c8e6c9', '#4caf50', '#bcaaa4', '#212121', '#263238') as $color) {
            $gr->addHtml()
                ->setHtml("<div class='color-pick' style='background:{$color}' data-color='$color'></div>");
        }
    }

    function printLayoutHead(Am_View $view)
    {
        if ($this->getConfig('font_family') == self::F_ROBOTO) {
            $view->headLink()->appendStylesheet('https://fonts.googleapis.com/css?family=Roboto');
        }
        if ($this->getConfig('font_family') == self::F_POPPINS) {
            $view->headLink()->appendStylesheet('https://fonts.googleapis.com/css?family=Poppins:300');
        }
        if ($this->getConfig('font_family') == self::F_OXYGEN) {
            $view->headLink()->appendStylesheet('https://fonts.googleapis.com/css?family=Oxygen');
        }
        if ($this->getConfig('font_family') == self::F_HIND) {
            $view->headLink()->appendStylesheet('https://fonts.googleapis.com/css?family=Hind');
        }
        $_ = $this->getConfig('version');
        if (file_exists("{$this->getDi()->public_dir}/{$this->getId()}/theme.css")) {
            $view->headLink()
                ->appendStylesheet($this->getDi()->url("data/public/{$this->getId()}/theme.css", strval($_), false));
        } else {
            $view->headLink()
                ->appendStylesheet($this->urlPublicWithVars("css/theme.css" . ($_ ? "?$_" : "")));
        }

        if ($css = $this->getConfig('css')) {
            $view->headStyle()->appendStyle($css);
        }
    }

    function getFontOptions()
    {
        return array(
            self::F_TAHOMA => 'Tahoma',
            self::F_ARIAL => 'Arial',
            self::F_TIMES => 'Times',
            self::F_HELVETICA => 'Helvetica',
            self::F_ROBOTO => 'Roboto',
            self::F_POPPINS => 'Poppins',
            self::F_OXYGEN => 'Oxygen',
            self::F_HIND => 'Hind',
        );
    }

    function moveBgFile(Am_Config $before, Am_Config $after)
    {
        $this->moveFile($before, $after, 'bg_img', 'bg_path');
    }

    public function updateVersion(Am_Config $before, Am_Config $after)
    {
        $t = "themes.{$this->getId()}.version";
        $_ = $after->get($t);
        $after->set($t, ++$_);
    }

    public function normalize(Am_Config $before, Am_Config $after)
    {
        $t = "themes.{$this->getId()}.border_radius";
        $after->set($t, (int)$after->get($t));
    }

    public function updateShadow(Am_Config $before, Am_Config $after)
    {
        $t_id = "themes.{$this->getId()}.drop_shadow";
        $t_new = "themes.{$this->getId()}.content_shadow";

        $after->set($t_new, $after->get($t_id) ? self::SHADOW : 'none');
    }

    public function updateBg(Am_Config $before, Am_Config $after)
    {
        $t_id = "themes.{$this->getId()}.bg_path";
        $t_new = "themes.{$this->getId()}.bg";
        $t_color = "themes.{$this->getId()}.color";
        $t_repeat = "themes.{$this->getId()}.bg_repeat";

        $url = $this->getDi()->url("data/public/{$after->get($t_id)}", false);

        $after->set($t_new, $after->get($t_id) ?
            "url('{$url}') {$after->get($t_color)} top center {$after->get($t_repeat)};" :
            $after->get($t_color));
    }

    public function updateFile(Am_Config $before, Am_Config $after)
    {
        $this->config = $after->get("themes.{$this->getId()}") + $this->getDefaults();

        $css = $this->parsePublicWithVars('css/theme.css');
        $filename = "{$this->getDi()->public_dir}/{$this->getId()}/theme.css";
        mkdir(dirname($filename), 0755, true);
        file_put_contents($filename, $css);
    }

    public function findInverseColor(Am_Config $before, Am_Config $after)
    {
        $t_id = "themes.{$this->getId()}.color";
        $t_new = "themes.{$this->getId()}.color_c";
        $after->set($t_new, $this->inverse($after->get($t_id)));
    }

    public function findDarkenColor(Am_Config $before, Am_Config $after)
    {
        $t_id = "themes.{$this->getId()}.color";
        $t_new = "themes.{$this->getId()}.color_d";
        $after->set($t_new, $this->brightness($after->get($t_id), -50));
    }

    protected function inverse($color)
    {
        if ($color[0] != '#') return '#ffffff';

        $color = str_replace('#', '', $color);
        if (strlen($color) == 3) {
            $color = str_repeat(substr($color,0,1), 2).str_repeat(substr($color,1,1), 2).str_repeat(substr($color,2,1), 2);
        }
        $rgb = '';
        for ($x=0; $x<3; $x++){
            $c = 255 - hexdec(substr($color,(2*$x),2));
            $c = ($c < 0) ? 0 : dechex($c);
            $rgb .= (strlen($c) < 2) ? '0'.$c : $c;
        }
        return '#'.$rgb;
    }

    protected function brightness($color, $steps)
    {
        if ($color[0] != '#') return $color;

        $steps = max(-255, min(255, $steps));

        $color = str_replace('#', '', $color);
        if (strlen($color) == 3) {
            $color = str_repeat(substr($color,0,1), 2).str_repeat(substr($color,1,1), 2).str_repeat(substr($color,2,1), 2);
        }
        $rgb = '';
        for ($x=0; $x<3; $x++){
            $c = max(0, min(255, hexdec(substr($color,(2*$x),2)) + $steps));
            $c = dechex($c);
            $rgb .= (strlen($c) < 2) ? '0'.$c : $c;
        }
        return '#'.$rgb;
    }

    public function getDefaults()
    {
        return parent::getDefaults() + array(
            'color' => '#f1f5f9',
            'link_color'=> '#3f7fb0',
            'color_c' => '#0e0a06',
            'color_d' => '#bfc3c7',
            'logo_align' => 'left',
            'max_width' => 900,
            'logo_width' => 'auto',
            'font_size' => 13,
            'font_family' => self::F_ARIAL,
            'drop_shadow' => 1,
            'content_shadow' => self::SHADOW,
            'version' => '',
            'border_radius' => 0,
            'bg_size' => 'auto',
            'bg_attachment' => 'scroll',
            'bg_repeat' => 'no-repeat',
        );
    }
}