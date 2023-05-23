<?php

if (!@class_exists('Zend_Pdf_Page', true))
    include_once('Zend/Pdf_Pack.php');

/**
 * @package Am_Pdf
 */
class Am_Pdf_Page_Decorator
{

    /** @var Zend_Pdf_Page */
    protected $_imp = null;

    const ALIGN_LEFT = 'left';
    const ALIGN_RIGHT = 'right';
    const ALIGN_CENTER = 'center';
    const PAGE_A4_WIDTH = 595;
    const PAGE_A4_HEIGHT = 842;
    const PAGE_LETTER_WIDTH = 612;
    const PAGE_LETTER_HEIGHT = 792;

    public function __construct(Zend_Pdf_Page $page)
    {
        $this->_imp = $page;
    }

    /**
     *
     * @return Zend_Pdf_Page
     *
     */
    public function getImp()
    {
        return $this->_imp;
    }

    public function nl(&$pointer)
    {
        $pointer-=$this->getSpacing();
    }

    protected function getSpacing()
    {
        return floor($this->getFontSize() * 1.3);
    }

    public function __call($name, $arguments)
    {
        return call_user_func_array(array(
            $this->getImp(),
            $name
            ), $arguments);
    }

    public function drawTable(Am_Pdf_Table $table, $x, $y)
    {
        return $table->render($this, $x, $y);
    }

    public function drawText($text, $x, $y, $charEncoding = 'UTF-8', $align = self::ALIGN_LEFT)
    {
        $text = trim($text);

        switch ($align)
        {
            case self::ALIGN_RIGHT :
                $x = $x - $this->widthForString($text);
                break;
            case self::ALIGN_CENTER :
                $x = $x - ($this->widthForString($text) / 2);
                break;
            case self::ALIGN_LEFT :
            default:
                //nop
                break;
        }

        $this->getImp()->drawText($text, $x, $y, $charEncoding);
    }

    public function drawTextWithFixedWidth($text, $x, $y, $width, $charEncoding = 'UTF-8', $align = self::ALIGN_LEFT)
    {
        //$text =  str_replace(array("\n", "\r"), array('', ''), $text);
        $text = preg_replace('/ {2,}/i', ' ', $text);
        $text = preg_replace('#<br\s*/?>\r?\n#i', '<br>', $text);
        $text_parts = preg_split("#(\r?\n|<br\s*/?>)#", $text);
        foreach ($text_parts as $text)
        {
            $text = explode(' ', $text);
            $line = '';
            foreach ($text as $word)
            {
                $line .= ' ' . $word;
                if ($this->widthForString($line) > $width)
                {
                    $line = substr($line, 0, strlen($line) - (strlen($word) + 1));
                    $this->drawText($line, $x, $y, $charEncoding, $align);
                    $this->nl($y);
                    $line = $word;
                }
            }
            $this->drawText($line, $x, $y, $charEncoding, $align);
            $this->nl($y);
        }
        return $y;
    }

    public function widthForString($string)
    {
        $font = $this->getFont();
        $fontSize = $this->getFontSize();
        $drawingString = iconv('UTF-8', 'UTF-16BE//IGNORE', $string);
        $characters = array();
        for ($i = 0; $i < strlen($drawingString); $i++)
        {
            $characters[] = (ord($drawingString[$i++]) << 8) | ord($drawingString[$i]);
        }
        $glyphs = $font->glyphNumbersForCharacters($characters);
        $widths = $font->widthsForGlyphs($glyphs);
        $stringWidth = (array_sum($widths) / $font->getUnitsPerEm()) * $fontSize;
        return $stringWidth;
    }

}