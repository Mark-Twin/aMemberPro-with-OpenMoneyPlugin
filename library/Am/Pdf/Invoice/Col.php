<?php

class Am_Pdf_Invoice_Col extends stdClass
{
    function add($text, $token = null)
    {
        $token = $token ?: uniqid();
        $this->$token = $text;
        return $token;
    }

    function prepend($text, $token = null)
    {
        $ref = key((array)$this);
        $ref ?
            $this->addBefore($text, $ref, $token) :
            $this->add($text, $token);
    }

    function remove($token)
    {
        foreach ((array)$token as $t) {
            unset($this->$t);
        }
    }

    function clear()
    {
        foreach (get_object_vars($this) as $token => $value) {
            unset($this->$token);
        }
    }

    function addBefore($text, $ref, $token = null)
    {
        $f = false;
        foreach ((array)$this as $prop => $val) {
            if ($prop == $ref) {
                $token = $token ?: uniqid();
                $this->$token = $text;
                $f = true;
            }
            if ($f) {
                unset($this->$prop);
                $this->$prop = $val;
            }
        }
        return $token;
    }

    function addAfter($text, $ref, $token = null)
    {
        $f = false;
        foreach ((array)$this as $prop => $val) {
            if ($prop == $ref) {
                $token = $token ?: uniqid();
                $this->$token = $text;
                $f = true;
                continue;
            }
            if ($f) {
                unset($this->$prop);
                $this->$prop = $val;
            }
        }
        return $token;
    }

    function moveBefore($token, $ref)
    {
        $_ = $this->$token;
        unset($this->$token);
        return $this->addBefore($_, $ref, $token);
    }

    function moveAfter($token, $ref)
    {
        $_ = $this->$token;
        unset($this->$token);
        return $this->addAfter($_, $ref, $token);
    }
}