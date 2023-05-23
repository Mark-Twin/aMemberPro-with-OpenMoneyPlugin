<?php

/**
 * @package Am_Pdf
 */
class Am_Pdf_Cell {
    protected $left;
    protected $top;
    protected $width;
    protected $content;
    protected $align;
    protected $styles;
    const TOP = 1;
    const RIGHT = 2;
    const BOTTOM = 3;
    const LEFT = 4;
    protected $padding = array(
            self::TOP => 0,
            self::RIGHT => 0,
            self::BOTTOM => 0,
            self::LEFT => 0
    );

    public function __construct($content)
    {
        $this->content = $content;
    }

    public function setStyle($styles)
    {
        $this->styles = $styles;
    }

    public function setWidth($width)
    {
        $this->width = $width;
    }

    public function setPadding($top=0, $right=0, $bottom=0, $left=0)
    {
        $this->padding = array(
                self::TOP => $top,
                self::RIGHT => $right,
                self::BOTTOM => $bottom,
                self::LEFT => $left
        );
    }

    public function getPadding($side)
    {
        return $this->padding[$side];
    }

    public function getWidth()
    {
        return $this->width;
    }

    public function render(Am_Pdf_Page_Decorator $page, $x, $y)
    {
        if ($font = $this->getProperty('font')) {
            $fontTmp = $page->getFont();
            $fontSizeTmp = $page->getFontSize();

            $page->setFont($font['face'], $font['size']);
        }
        $lineHeight = ($page->getFont()->getLineHeight()/$page->getFont()->getUnitsPerEm()) * $page->getFontSize();
        $lineBegin = $y - $this->getPadding(self::TOP) - $lineHeight;
        $width = $this->getWidth() - $this->getPadding(self::LEFT) - $this->getPadding(self::RIGHT);

        switch ($this->getProperty('align', Am_Pdf_Page_Decorator::ALIGN_LEFT)) {
            case Am_Pdf_Page_Decorator::ALIGN_LEFT :
                $lineEnd = $page->drawTextWithFixedWidth($this->content,
                        $x + 1 + $this->getPadding(self::LEFT),
                        $lineBegin, $width);
                break;
            case Am_Pdf_Page_Decorator::ALIGN_RIGHT :
                $lineEnd = $page->drawTextWithFixedWidth($this->content,
                        $x + $this->getWidth() - 1 - $this->getPadding(self::RIGHT),
                        $lineBegin,
                        $width, 'UTF-8', Am_Pdf_Page_Decorator::ALIGN_RIGHT);
                break;
        }

        $rowHeight = $lineBegin - $lineEnd;

        if ($font) {
            $page->setFont($fontTmp, $fontSizeTmp);
        }

        $shape = $this->getProperty('shape',
                array(
                'type' => Zend_Pdf_Page::SHAPE_DRAW_STROKE,
                'color' => new Zend_Pdf_Color_Html('#cccccc')
        ));

        return array(
            'x' => $x,
            'y' => $y,
            'width' => $this->getWidth(),
            'height' => $rowHeight + $this->getPadding(self::BOTTOM) + $this->getPadding(self::TOP),
            'shape' => $shape);
    }

    protected function getProperty($propName, $default = null)
    {
        if (isset($this->styles[$propName])) {
            return $this->styles[$propName];
        } else {
            return $default;
        }
    }
}