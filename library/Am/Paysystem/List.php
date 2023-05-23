<?php

/**
 * Payment systems list
 * @package Am_Paysystem
 * @deprecated inteface will be moved to {@link Am_Plugins}
 */
class Am_Paysystem_List
{
    protected $list = array();
    protected $loaded = false;
    private $_di;

    public function __construct(Am_Di $di)
    {
        $this->_di = $di;
    }

    /**
     * Trigger loading of all plugin to get
     * entires added
     */
    protected function loadAllEnabled()
    {
        if ($this->loaded) return;
        $this->_di->plugins_payment->loadEnabled();
        $this->_di->plugins_payment->getAllEnabled();
        $this->loaded = true;
    }

    function getList()
    {
        return $this->list;
    }

    /**
     * @param Am_Paysystem_Description $record
     */
    function add($record)
    {
        array_push($this->list, $record);
    }

    function makeFirst($id)
    {
        $first = null;
        foreach ($this->list as $k => $p)
            if ($p->getId() == $id) {
                $first = $p;
                unset($this->list[$k]);
                break;
            }
        if ($first) array_unshift($this->list, $first);
    }

    /**
     * @param string
     */
    function delete($id)
    {
        foreach ($this->list as $k => $p)
            if ($p->getId() == $id)
                   unset($this->list[$k]);
    }

    /**
     * @return Am_Paysystem_Description
     * @param id
     */
    function get($id)
    {
        $this->loadAllEnabled();
        foreach ($this->list as $k => $p)
            if ($p->getId() == $id)
                   return $p;
    }

    function getAll()
    {
        $this->loadAllEnabled();
        return $this->list;
    }

    function getAllPublic()
    {
        $ret = array();
        $free = null;
        foreach ($this->getAll() as $p) {
            if ($p->isPublic())
                if ($p->getId() == 'free')
                    $free = $p;
                else
                    $ret[] = $p;
        }
        // usually we hide 'free' option, but if no options, we will show it
        if (!$ret && $free) $ret[] = $free;
        return $ret;
    }

    /**
     * @return true if enabled and public
     */
    function isPublic($paysysId)
    {
        foreach ($this->getAll() as $p)
            if ($p->isPublic() && $p->getId() == $paysysId) return true;
        return false;
    }

    function getAllPublicAsArrays()
    {
        return array_map(function($p) {return $p->toArray();}, $this->getAllPublic());
    }

    /**
     * @return array key=>title for admin
     */
    function getOptions()
    {
        $ret = array();
        foreach ($this->getAll() as $p)
            $ret[ $p->getId() ] = $p->getTitle();
        return $ret;
    }

    /**
     * @return array key=>title for customers
     */
    function getOptionsPublic()
    {
        $ret = array();
        foreach ($this->getAllPublic() as $p)
            $ret[ $p->getId() ] = $p->getTitle();
        return $ret;
    }

    function getTitle($id)
    {
        foreach ($this->getAll() as $desc)
            if ($desc->paysys_id == $id) return $desc->title;
        return $id;
    }
}