<?php

/**
 * @package Am_View
 */
class Am_View_Helper_Obfuscate extends Zend_View_Helper_Abstract
{
    public function obfuscate($id)
    {
        return $this->view->di->security->obfuscate($id);
    }
}

