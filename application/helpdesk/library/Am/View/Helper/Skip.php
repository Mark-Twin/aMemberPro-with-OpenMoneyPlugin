<?php

class Am_View_Helper_Skip extends Zend_View_Helper_Abstract
{
    public function skip($string)
    {
        $arr = explode("\n", $string);
        $s_arr = array();

        //find line for skipe
        //store this info to array
        foreach ($arr as $k => $v) {
            if (preg_match('/^\s*>\s*>/', trim($v))) {
                $s_arr[$k] = 1;
            } else {
                $s_arr[$k] = 0;
            }
        }

        //add one none skiped line at the end
        //in order to resolve problem if last line should be skiped too
        $arr[] = '';
        $arr = array_map(array('Am_Html', 'escape'), $arr);
        $s_arr[] = 0;

        //remove empty lines beetween skiped lines
        $prev = 0;
        $empty_lines = array();
        foreach ($s_arr as $k => $v) {
            if (trim($arr[$k]) == '') {
                $empty_lines[] = $k;
            } elseif ($v && $prev) {
                foreach ($empty_lines as $key) {
                    $s_arr[$key] = 1;
                }
                $empty_lines = array();
                $prev = 1;
            } elseif ($v) {
                $empty_lines = array();
                $prev = 1;
            } else {
                $prev = 0;
            }
        }

        //skip
        $skiped = 0;
        $skiped_lines = array();
        $label_lines_skipped = ___('lines skipped');
        foreach ($s_arr as $k => $v) {
            if ($v) {
                $skiped_lines[] = $arr[$k];
                if (!$skiped) {
                    $first_skiped_line = $k;
                } else {
                    unset($arr[$k]);
                }
                $skiped++;
            } elseif ($skiped) {
                $arr[$first_skiped_line] = '<div style="color:#F44336; cursor:pointer; display:inline" onclick="elem = this.nextSibling; elem.style.display = (elem.style.display == \'block\') ? \'none\' : \'block\';">...' . $skiped . ' ' . $label_lines_skipped . '...</div><div style="display:none; border-left:1px solid red; padding-left:0.5em"><pre>' . implode("\n", $skiped_lines) . '</pre></div>';
                $skiped = 0;
                $skiped_lines = array();
            }
        }

        $string = implode("\n", $arr);
        return $string;
    }
}