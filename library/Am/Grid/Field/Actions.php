<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: LGPL http://www.gnu.org/copyleft/lesser.html
*/

class Am_Grid_Field_Actions extends Am_Grid_Field
{
    public function __construct($field='_', $title=null, $sortable = true, $align = null, $renderFunc = null, $width = null)
    {
        parent::__construct($field, '', false);
    }

    public function renderTitle($controller)
    {
        return sprintf('<th class="actions">%s</th>', $controller->escape($this->title));
    }

    public function render($obj, $grid)
    {
        $out  = '<td class="actions" nowrap width="1%">' . PHP_EOL;
        $id = $grid->getDataSource()->getIdForRecord($obj);
        foreach ($grid->getActions(Am_Grid_Action_Abstract::SINGLE) as $action)
        {
            if (!$action->isAvailable($obj)) continue;

            $attributes = "";
            foreach ($action->getAttributes() as $k => $v)
                $attributes .= htmlentities ($k, null, 'UTF-8')
                               . '="' . htmlentities($v, ENT_QUOTES, 'UTF-8') . '" ';

            $icon = $grid->getView()->icon($action->getId(), $grid->getRecordTitle($action->getTitle()));
            if (!$icon)
                $icon = $action->getTitle();

            $url = $action->getUrl($obj, $id);

            $out .= sprintf('&nbsp;<a %shref="%s">%s</a>' . PHP_EOL,
                $attributes,
                $url,
                $icon
            );
        }
        $out .= '</td>' . PHP_EOL;
        return $out;
    }
}