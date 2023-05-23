<?php

/**
 * This decorator will be automatically added by live-edit action
 */
class Am_Grid_Field_Decorator_LiveEdit extends Am_Grid_Field_Decorator_Abstract
{
    /** @var Am_Grid_Action_LiveEdit */
    protected $action;
    protected $inputTemplate;
    protected $inputSize = 20;

    public function __construct(Am_Grid_Action_LiveEdit $action)
    {
        $this->action = $action;
        parent::__construct();
    }

    public function render(&$out, $obj, $grid)
    {
        $wrap = $this->getWrapper($obj, $grid);
        preg_match('{(<td.*>)(.*)(</td>)}is', $out, $match);
        $out = $match[1] . '<div class="editable"></div>'. $wrap[0]
                . (strlen($match[2]) ? $match[2] : $grid->escape($this->action->getPlaceholder()))
                . $wrap[1] . $match[3];
    }

    protected function divideUrlAndParams($url)
    {
        $ret = explode('?', $url, 2);
        if (count($ret)<=1) return array($ret[0], null);
        parse_str($ret[1], $params);
        return array($ret[0], $params);
    }

    protected function getWrapper($obj, $grid)
    {
        $id = $this->action->getIdForRecord($obj);
        $val = $obj->{$this->field->getFieldName()};
        list($url, $params) = $this->divideUrlAndParams($this->action->getUrl($obj, $id));
        $start = sprintf('<span class="live-edit%s" id="%s" livetemplate="%s" liveurl="%s" livedata="%s" placeholder="%s" data-init-callback="%s">',
            strlen($val) ? '' : ' live-edit-placeholder',
            $grid->getId() . '_' . $this->field->getFieldName() . '-' . $grid->escape($id),
            $grid->escape($this->getInputTemplate()),
            $url,
            $grid->escape(json_encode($params)),
            $grid->escape($this->action->getPlaceholder()),
            $grid->escape($this->action->getInitCallback())
        );
        $stop = '</span>';
        return array($start, $stop);
    }

    public function getInputTemplate()
    {
        return $this->inputTemplate ?: sprintf("<input type='text' size='%d' />", $this->inputSize);
    }

    public function setInputTemplate($tpl)
    {
        $this->inputTemplate = $tpl;
    }

    public function setInputSize($size)
    {
        $this->inputSize = (int)$size;
        return $this;
    }
}