<?php

/**
 * @package Am_Pdf
 */
class Am_Pdf_Table {
    protected $rows = array();
    protected $data;
    protected $width = null;
    protected $stylesColl = array();
    protected $stylesRow = array();
    protected $newPageCallback = null, $newPageThreshold = 0;
    const TOP = 1;
    const RIGHT = 2;
    const BOTTOM = 3;
    const LEFT = 4;
    protected $margin = array(
            self::TOP => 0,
            self::RIGHT => 0,
            self::BOTTOM => 0,
            self::LEFT => 0
    );

    public function __construct($newPageCallback = null, $newPageThreshold = 0)
    {
        $this->newPageCallback = $newPageCallback;
        $this->newPageThreshold = $newPageThreshold;
    }

    public function setData($data)
    {
        $this->data = $data;
        foreach($data as $rowData) {
            $row = new Am_Pdf_Row();
            $row->setData($rowData);
            $this->rows[] = $row;
        }
    }

    public function setStyleForColumn($colNum, $style)
    {
        $this->stylesColl[$colNum] = $style;
    }

    public function setStyleForRow($rowNum, $style)
    {
        $this->stylesRow[$rowNum] = $style;
    }

    public function getStyleForRow($rowNum)
    {
        if (isset($this->stylesRow[$rowNum])) {
            return $this->stylesRow[$rowNum];
        } else {
            return array();
        }
    }

    public function getStyleForColumn($colNum)
    {
        if (isset($this->stylesColl[$colNum])) {
            return $this->stylesColl[$colNum];
        } else {
            return array();
        }
    }

    public function setMargin($top=0, $right=0, $bottom=0, $left=0)
    {
        $this->margin = array(
                self::TOP => $top,
                self::RIGHT => $right,
                self::BOTTOM => $bottom,
                self::LEFT => $left
        );
    }

    public function getMargin($side)
    {
        return $this->margin[$side];
    }

    /**
     * @param array $rowData
     * @return Am_Pdf_Row
     */
    public function addRow($rowData)
    {
        $row = new Am_Pdf_Row();
        $row->setData(is_array($rowData) ? $rowData : func_get_args());
        $this->rows[] = $row;
        return $row;
    }

    public function setWidth($width)
    {
        $this->width = $width;
    }

    protected function getRows()
    {
        return $this->rows;
    }

    protected function newPage(Am_Pdf_Page_Decorator $page, &$y)
    {
        return $this->newPageCallback ?
            call_user_func_array($this->newPageCallback, array($page, &$y)) :
            $page;
    }

    public function render(Am_Pdf_Page_Decorator $page, $x, $y)
    {
        $this->width = $this->width ? $this->width : $page->getWidth() - $x;

        $y = $y - $this->getMargin(self::TOP);
        $x = $x + $this->getMargin(self::LEFT);
        $rowNum = 1;
        $shadowPage = new Am_Pdf_Page_Decorator(clone $page->getImp());
        $shadowPage->setFont($page->getFont(), $page->getFontSize());
        foreach ($this->getRows() as $row) {
            $row->setTable($this);
            $row->setWidth(
                    $this->width - $this->getMargin(self::LEFT) -
                    $this->getMargin(self::RIGHT)
            );

            $row->addStyle($this->getStyleForRow($rowNum));

            $row->render($shadowPage, $x, $y);
            $needHeight = $row->getHeight($shadowPage);
            if ($y - $needHeight < $this->newPageThreshold) {
                $page = $this->newPage($page, $y);
            }
            $row->render($page, $x, $y);
            $y = $y - $row->getHeight($page);
            $rowNum++;
        }

        return $y;
    }
}
