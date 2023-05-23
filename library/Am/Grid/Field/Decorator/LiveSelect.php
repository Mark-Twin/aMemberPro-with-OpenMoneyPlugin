<?php

/**
 * This decorator will be automatically added by live-select action
 */
class Am_Grid_Field_Decorator_LiveSelect extends Am_Grid_Field_Decorator_Abstract
{
    /** @var Am_Grid_Action_LiveEdit */
    protected $action;

    public function __construct(Am_Grid_Action_LiveSelect $action)
    {
        $this->action = $action;
        parent::__construct();
    }

    public function render(&$out, $obj, $grid)
    {
        $options = $this->action->getOptions();
        $wrap = $this->getWrapper($obj, $grid);
        preg_match('{(<td.*>)(.*)(</td>)}is', $out, $match);
        $out = $match[1] . '<div class="editable"></div>'. $wrap[0]
                . ($match[2] ? $grid->escape($options[$match[2]]) : $grid->escape($this->action->getPlaceholder()))
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
        $start = sprintf('<span class="live-select%s" id="%s" data-url="%s" data-data="%s" data-placeholder="%s" data-options="%s" data-template="%s" data-val="%s">',
            $val ? '' : ' live-edit-placeholder',
            $grid->getId() . '_' . $this->field->getFieldName() . '-' . $grid->escape($id),
            $url,
            $grid->escape(json_encode($params)),
            $grid->escape($this->action->getPlaceholder()),
            $grid->escape(json_encode($this->action->getOptions())),
            $grid->escape(sprintf('<select>%s</select>', Am_Html::renderOptions($this->action->getOptions()))),
            $grid->escape($val)
        );
        $stop = '</span>';
        return array($start, $stop);
    }
}