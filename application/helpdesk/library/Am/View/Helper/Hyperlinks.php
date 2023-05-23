<?php

class Am_View_Helper_Hyperlinks extends Zend_View_Helper_Abstract
{

    public function hyperlinks($string)
    {
        $pattern = '#(https?://(.*?))(([>".?,)]{0,1}(\s|$))|(&(quot|gt);))#i';
        return preg_replace($pattern, '<a href="$1" target="_blank" rel="noreferrer">$1</a>$3', $string);
    }

}