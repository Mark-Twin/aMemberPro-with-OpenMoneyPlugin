<?php

/**
 * @package Am_Pdf
 */
class Am_Pdf_Row {
    protected $cells = array();
    protected $width;
    protected $height;
    protected $data;
    protected $table;
    protected $styles = array();

    public function setStyle($styles)
    {
        $this->styles = $styles;
    }

    public function addStyle($styles)
    {
        $this->styles = array_merge($this->styles, $styles);
    }

    public function getStyle()
    {
        return $this->styles;
    }

    public function setTable($table)
    {
        $this->table = $table;
    }

    protected function getCells()
    {
        return $this->cells;
    }

    protected function getCellsCount()
    {
        return count($this->cells);
    }

    public function setWidth($width)
    {
        $this->width = $width;
    }

    public function getWidth()
    {
        return $this->width;
    }

    protected function getWidths()
    {
        $res = array();
        for ($i=1; $i<=$this->getCellsCount(); $i++) {
            $styles = $this->table->getStyleForColumn($i);
            if (isset($styles['width'])) {
                $res[] = $styles['width'];
            }
        }

        return $res;
    }

    protected function getColWidth($colNum)
    {
        $styles = $this->table->getStyleForColumn($colNum);
        if (isset($styles['width'])) {
            return $styles['width'];
        } else {
            $widths = $this->getWidths();
            $w = floor(($this->getWidth()-array_sum($widths))/
                    ($this->getCellsCount() - count($widths)));
            $wl = $this->getWidth() - array_sum($widths) -
                $w*($this->getCellsCount() - count($widths) -1);
            return $this->getCellsCount() == $colNum ? $wl : $w;
        }
    }

    public function render(Am_Pdf_Page_Decorator $page, $x, $y)
    {
        $cellWidth = floor($this->getWidth()/$this->getCellsCount());
        $colNum = 1;
        $cellBorder = array();
        $maxHeight = 0;
        foreach ($this->getCells() as $cell) {
            $cellWidth = $this->getColWidth($colNum);
            $cell->setWidth($cellWidth);
            $cell->setPadding(5,5,5,5);
            $cell->setStyle(
                    array_merge(
                    $this->table->getStyleForColumn($colNum),
                    $this->getStyle()
                    )
            );
            $border = $cell->render($page, $x, $y);
            $maxHeight = max($maxHeight, $border['height']);
            $cellBorder[] = $border;
            $x = $x + $cellWidth;
            $colNum++;
        }
        $this->height = $maxHeight;

        foreach ($cellBorder as $border) {
            extract($border, EXTR_OVERWRITE);
            $page->setLineColor($shape['color']);
            $page->setFillColor($shape['color']);
            $page->drawRectangle($x, $y, $x + $width, $y - $maxHeight, $shape['type']);
            $page->setFillColor(new Zend_Pdf_Color_Html('#000000'));
        }
    }

    public function getHeight($page)
    {
        return $this->height;
    }

    public function setData($data) {
        $this->data = $data;
        foreach($data as $cellData) {
            $cell = new Am_Pdf_Cell($cellData);
            $this->cells[] = $cell;
        }
    }
}