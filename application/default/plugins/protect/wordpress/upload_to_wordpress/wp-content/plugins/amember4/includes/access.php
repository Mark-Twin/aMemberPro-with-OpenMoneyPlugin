<?php
class am4AccessRequirement
{
    public $id;
    public $type;
    public $start;
    public $startType;
    public $stop;
    public $status;

    function __construct($record=array())
    {
        if($record) $this->fromArray($record);
    }

    function checkAccess(am4AccessCacheRecord $a)
    {
        // Check record type;
        if (($this->id != am4Access::ANY_PRODUCT) && ($this->type != $a->type)) return false;
        // Check record id; -1 means any;
        if ($this->id != am4Access::ANY_PRODUCT && $this->id != $a->id) return false;
        // Check status;
        if (!in_array($a->status, $this->status)) return false;
        // Check period;
        if ($this->startType == 'd' && $a->days < $this->start) return false;
        if ($this->startType == 'p' && $a->payments_count < $this->start) return false;
        if ($a->days > $this->stop) return false;

        return true;
    }

    static function createRequirements()
    {
        $req = array();
        $args = func_get_args();
        foreach((array)$args as $records)
            foreach((array)$records as $type => $r){
                foreach((array)$r as $id => $v){
                    $req[] = new self(array(
                        'type' => $type,
                        'id' => $id,
                        'start' => $v['start'],
                        'stop' => $v['stop']));
                }
            }
        return $req;
    }

    function toArray()
    {
        throw new Exception("Not implemented!");
    }

    function fromArray($record)
    {
        $this->id  = $record['id'];
        $this->type = $record['type'];
        if (preg_match('/([0-9]+)(d|p)/', $record['start'], $m)) {
            $this->start = intval($m[1]);
            $this->startType = $m[2];
        } else {
            $this->start = intval($record['start']);
            $this->startType = 'd';
        }
        $this->stop = intval($record['stop']);
        if($this->stop == -1) {
            $this->status = array(am4Access::ACTIVE, am4Access::EXPIRED);
        } else {
            $this->status = array(am4Access::ACTIVE);
        }
        if($this->stop <= 0) $this->stop = PHP_INT_MAX;
    }

    function __toString()
    {
        $value = '';
        if($this->id){
            switch($this->type){
                case am4Access::PRODUCT :
                    $titles = am4PluginsManager::getAMProducts();
                    break;
                case am4Access::CATEGORY :
                    $titles = am4PluginsManager::getAMCategories();
                    break;
                default:
                    throw new Exception('Unknown record type!');
            }
            if($this->id == -1) {
                $value = "<b>"._('Any Product')."</b> ";
            } else {
                $value = "<b>".@$titles[$this->id]."</b> ";
            }
            $value .= _(' from ').($this->start==0 ? _('start') : $this->start.$this->startType)._(' to ').(in_array(am4Access::EXPIRED, $this->status) ? _(' forever ') : ($this->stop == PHP_INT_MAX ? _('expiration') : $this->stop.'d'));
            return $value;
        }
        return $value;
    }
}

class am4AccessCacheRecord
{
    public $id;
    public $type;
    public $days;
    public $status;

    function __construct(array $record)
    {
        $this->id = $record['id'];
        $this->type = $record['type'];
        $this->days = $record['days'];
        $this->payments_count = $record['payments_count'];
        $this->status  = $record['status'];
    }
}

// User access class. All cache records that user have.
class am4Access
{
    private $cache = array();
    const PRODUCT = 'product';
    const CATEGORY = 'category';
    const FOREVER = -1;
    const EXPIRATION = 0;
    const START = 0;
    const ANY_PRODUCT = -1;
    const ACTIVE  = 'active';
    const EXPIRED = 'expired';

    function addRecord(am4AccessCacheRecord $c)
    {
        $this->cache[] = $c;
    }

    function getRecords()
    {
        return $this->cache;
    }

    function checkRequirement(am4AccessRequirement $r)
    {
        foreach($this->getRecords() as $a){
            if($r->checkAccess($a)) return true;
        }
        return false;
    }

    // return true when all requirements are true;
    function allTrue(Array $req)
    {
        $ret = true;
        foreach($req as $r){
            $ret = $ret && $this->checkRequirement($r);
        }
        return $ret;
    }

    // return true when all requirements are false;
    function allFalse(Array $req)
    {
        $ret = false;
        foreach($req as $r){
            $ret = $ret || $this->checkRequirement($r);
        }
        return !$ret;
    }

    // return true when any requirement is true;
    function anyTrue(Array $req)
    {
        foreach($req as $r){
            if($this->checkRequirement($r)) return true;
        }
        return false;
    }

    //return false when any requirement is false;
    function anyFalse(Array $req)
    {
        foreach($req as $r){
            if(!$this->checkRequirement($r)) return true;
        }
    }
}

class am4UserAccess extends am4Access
{
    private $user_id;
    static $_cache = null;

    function __construct()
    {
        if($this->isLoggedIn()){
            foreach((array)$this->getAccessCache() as $a){
                if(in_array($a['fn'], array('product_id', 'product_category_id')))
                    $this->addRecord(new am4AccessCacheRecord(array(
                        'type' => ($a['fn'] == 'product_id' ? self::PRODUCT : self::CATEGORY),
                        'days' => $a['days'],
                        'payments_count' => $a['payments_count'],
                        'id' => $a['id'],
                        'status' => $a['status'])));
            }
        }
    }

    function isLoggedIn()
    {
        return am4PluginsManager::getAPI()->isLoggedIn();
    }

    function isAffiliate()
    {
        $user = am4PluginsManager::getAPI()->getUser();
        return ($user['is_affiliate'] > 0);
    }

    function getAccessCache()
    {
        if (is_null(self::$_cache)) {
            self::$_cache = am4PluginsManager::getAPI()->getAccessCache();
        }
        return self::$_cache;
    }
}