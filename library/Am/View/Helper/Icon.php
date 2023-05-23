<?php

/**
 * Display icon from sprite or from icons/$name.png file
 * @package Am_View
 */
class Am_View_Helper_Icon extends Zend_View_Helper_Abstract
{
    static $src = array();

    public function icon($name, $alt='', $source='icon')
    {
        $arr = is_string($alt) ? array('alt' => $alt) : (array)$alt;
        if (!empty($arr['alt']) && empty($arr['title'])) {
            $arr['title'] = $arr['alt'];
        }
        $attrs = "";
        foreach ($arr as $k => $v)
            $attrs .= $this->view->escape($k) . '="' . $this->view->escape($v) . '" ';

        $spriteOffset = Am_Di::getInstance()->sprite->getOffset($name, $source);
        try {
            if ($spriteOffset !== false) {
                $res = sprintf('<div class="glyph sprite-%s" style="background-position: %spx center;" %s></div>',
                        $source, -1 * $spriteOffset, $attrs);
            } elseif ($src = $this->view->_scriptImg('icons/' . $name . '.png'))  {
                $res = sprintf ('<img src="%s" '.$attrs.' />', $src);
            } elseif (isset(self::$src[$name])) {
                $res = sprintf ('<img src="%s" '.$attrs.' />', self::$src[$name]);
            } else {
                if (!empty($arr['alt']))
                    $res = $arr['alt'];
                else
                    $res = null;
            }
        } catch (Exception $e) {
            trigger_error("Sprite [$name] search exception: (" . $e->getCode() . ") " . $e->getMessage(), E_USER_NOTICE);
            return null;
        }
        return $res;
    }
}

