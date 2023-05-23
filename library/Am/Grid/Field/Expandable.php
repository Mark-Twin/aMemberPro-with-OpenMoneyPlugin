<?php

class Am_Grid_Field_Expandable extends Am_Grid_Field
{
    protected $maxLength = 15;
    protected $placeholder = "Click to Expand";
    protected $isHtml = false;
    protected $isAjax = false;
    protected $isSafeHtml = false;
    protected $url = null;
    protected $isNeedExpand = null;

    const PLACEHOLDER_SELF_TRUNCATE_BEGIN = 'placeholder-self-truncate-begin';
    const PLACEHOLDER_SELF_TRUNCATE_END = 'placeholder-self-truncate-end';

    public function __construct($field, $title, $sortable = false, $align = null, $renderFunc = null, $width = null)
    {
        $this->setGetFunction(array($this, 'expandableGet'));
        parent::__construct($field, $title, $sortable, $align, $renderFunc, $width);
        $this->isNeedExpand = array($this, '_defaultIsNeedExpand');
    }

    /**
     *
     * @param int $maxLength
     * @return Am_Grid_Field_Expandable
     */
    public function setMaxLength($maxLength)
    {
        $this->maxLength = (int) $maxLength;
        return $this;
    }

    /**
     * You can use variables like
     * {user_id} and {getInvoiceId()} in the template
     * it will be automatically fetched from record, escaped and substituted
     */
    public function setAjax($url)
    {
        $this->isAjax = true;
        $this->url = $url;
        return $this;
    }

    /**
     *
     * @param string $placeholder
     * @return Am_Grid_Field_Expandable
     */
    public function setPlaceholder($placeholder)
    {
        $this->placeholder = $placeholder;
        return $this;
    }

    public function render($obj, $controller)
    {
        $val = $this->get($obj, $controller, $this->field);
        if (!$this->isNeedExpand($val, $obj)) {
            return parent::render($obj, $controller);
        } else {
            $align_class = $this->align ? ' align_' . $this->align : null;
            $placeholder = $this->getPlaceholder($val, $obj);
            $data_class = ($this->isHtml ? ' isHtml' : '') .
                ($this->isAjax ? ' isAjax' : '') .
                ($this->isSafeHtml ? ' isSafeHtml' : '');
            $data_val = $controller->escape($this->isAjax ? $this->parseUrl($this->url, $obj) : $val);
            $id = "{$controller->getId()}-$this->field-{$controller->getDataSource()->getIdForRecord($obj)}";
            $title = Am_Html::escape((!$this->isHtml && !$this->isAjax) ? $val : '');
            $out = <<<CUT
<td class="expandable$align_class" id="$id">
    <div class="arrow"></div>
    <div class="placeholder" title="{$title}">$placeholder</div>
    <input type="hidden" class="data$data_class" value="$data_val" />
</td>
CUT;
        }
        return $out;
    }

    function setIsNeedExpandFunction($func)
    {
        $this->isNeedExpand = $func;
        return $this;
    }

    protected function isNeedExpand($val, $obj)
    {
        return call_user_func($this->isNeedExpand, $val, $obj, $this->field, $this);
    }

    function _defaultIsNeedExpand($val, $obj, $field, $fieldObj)
    {
        return $this->isAjax || mb_strlen($val) > $this->maxLength;
    }

    protected function parseUrl($url, $record)
    {
        $that = $this;
        $ret = preg_replace_callback('|{(.+?)}|', function($matches) use ($that, $record) {
            return $that->_pregReplace($matches, $record);
        }, $url);
        if ((strpos($ret, 'http')!==0) && ($ret[0] != '/'))
            $ret = Am_Di::getInstance()->url($ret,null,false);
        return $ret;
    }

    public function _pregReplace($matches, $record)
    {
        $var = $matches[1];
        if ($var == 'THIS_URL') {
            $ret = Am_Di::getInstance()->request->getRequestUri();
        } elseif (preg_match('|^(.+)\(\)$|', $var, $regs)) {
            $ret = call_user_func(array($record, $regs[1]));
        } else {
            $ret = $record->{$var};
        }
        return urlencode($ret);
    }

    /**
     *
     * @param string $val
     * @return string html code of placeholder
     */
    protected function getPlaceholder($val, $obj)
    {
        if (is_null($this->placeholder) || $this->placeholder == self::PLACEHOLDER_SELF_TRUNCATE_END) {
            $placeholder = htmlentities(mb_substr($val, 0, $this->maxLength), null, 'UTF-8') . ( mb_strlen($val) > $this->maxLength ? '&hellip;' : '');
        } elseif ($this->placeholder == self::PLACEHOLDER_SELF_TRUNCATE_BEGIN) {
            $placeholder = ( mb_strlen($val) > $this->maxLength ? '&hellip;' : '') . htmlentities(mb_substr($val, -1 * $this->maxLength), null, 'UTF-8');
        } elseif(is_callable($this->placeholder)) {
            $placeholder = call_user_func($this->placeholder, $val, $obj);
        } else {
            $placeholder = htmlentities($this->placeholder, null, 'UTF-8');
        }

        return $placeholder;
    }

    public function expandableGet($obj, $controller, $field)
    {
        $val = $obj->{$field};
        if (!$val) return $val;
        // try unserialize
        if (is_string($val)) {
            if (($x = @unserialize($val)) !== false)
                $val = $x;
        }
        switch (true)
        {
            case is_array($val) :
                $out = '';
                foreach ($val as $k => $v)
                    $out .= $k . ' = ' . ((is_array($v)) ? print_r($v, true) : (string) $v) . PHP_EOL;
                return $out;
            case is_object($val) :
                return get_class($val) . "\n" . (string) $obj;
        }
        return $val;
    }

    public function setEscape($flag = null)
    {
        $ret = ! $this->isHtml;
        if ($flag !== null) $this->isHtml = ! $flag;
        return $ret;
    }

    public function setSafeHtml($flag)
    {
        $this->isSafeHtml = $flag;
        return $this;
    }

    public function renderStatic()
    {
        $view = new Am_View;
        $url = $view->escape($view->_scriptJs('htmlreg.js'));
        return parent::renderStatic() .
        '    <script type="text/javascript" src="'.$url.'"></script>';
    }
}