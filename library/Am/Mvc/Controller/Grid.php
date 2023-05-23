<?php

/**
 * Class to make usage of Am_Grid even simpler
 * it must get grid configured in @link init()
 * method and then it will do the rest
 */
abstract class Am_Mvc_Controller_Grid extends Am_Mvc_Controller
{
    /** @var Am_Grid_Editable */
    protected $grid;
    protected $layout = 'admin/layout.phtml';
    
    public function preDispatch()
    {
        $this->grid = $this->createGrid();
        parent::preDispatch();
    }
    abstract function createGrid();
    public function indexAction()
    {
        if (is_null($this->layout)) {
            echo $this->grid->run();
        } else {
            $this->grid->runWithLayout($this->layout);
        }
    }
    function renderTd($s, $escape = true)
    {
        return '<td>' . ($escape ? $this->escape($s) : $s) . '</td>' . PHP_EOL;
    }
}