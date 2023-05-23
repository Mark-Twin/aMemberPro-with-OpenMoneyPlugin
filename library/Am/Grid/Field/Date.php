<?php

class Am_Grid_Field_Date extends Am_Grid_Field
{
    const DATETIME = 'dt',
        DATE = 'd',
        TIME = 't';

    protected $format = self::DATETIME;

    public function __construct($field, $title, $sortable = true, $align = null, $renderFunc = null, $width = null)
    {
        parent::__construct($field, $title, $sortable, $align, $renderFunc, $width);
        $this->setFormatFunction(array($this, '_format'));
        $this->setRenderFunction(array($this, '_render'));
    }

    public function setFormatTime(){ $this->format = self::TIME; return $this; }

    public function setFormatDatetime(){ $this->format = self::DATETIME; return $this; }

    public function setFormatDate(){ $this->format = self::DATE; return $this; }

    public function _format($d)
    {
        if (trim($d)=='') return '';
        if (sqlDate($d) == Am_Period::MAX_SQL_DATE) return ___('Lifetime');
        switch ($this->format)
        {
            case self::DATE:
                return amDate($d);
            case self::TIME:
                return amTime($d);
            default:
                return amDatetime($d);
        }
    }

    public function _render($r, $fn, $g, $fo)
    {
        return $g->renderTd(sprintf(
            '<time datetime="%s" title="%s">%s</time>',
                date('c', amstrtotime($r->$fn)),
                amstrtotime($r->$fn) < time() ? Am_Html::escape($g->getDi()->view->getElapsedTime($r->$fn)) : '',
                $this->format($this->get($r, $g))),
            false);
    }
}