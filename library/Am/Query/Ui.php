<?php

/**
 * Represents a switchable query UI
 * @package Am_Query_Ui
 */
class Am_Query_Ui_Abstract
{
    protected $queryDescription;
    /** @var Am_Query_User */
    protected $query;
    protected $title = "";
    protected $name = "noquery";
    protected $options = array();

    public function __construct()
    {
        $this->queryDescription = ___("All Users");
        $this->query = new Am_Query_User;
        $this->query->addWhere("u.unsubscribed=0");
    }

    function setFromRequest(Am_Mvc_Request $request)
    {
        $search = $request->getParam('search-'.$this->getName());
        // try to set normal options, else try unserialize previous
        if (!empty($search))
            $this->options = is_array($search) ? $search : unserialize($search);
    }

    function render() {}
    function getTargetListIds() {  return array();  }
    function getTitle() { return $this->title; }
    function getDescription() {
        return $this->queryDescription ? $this->queryDescription : $this->getTitle();
    }
    function getName() { return $this->name; }
    function getHidden() { return array('search-' . $this->getName() => serialize($this->options)); }

    /** @return Am_Query_User */
    function getQuery()
    {
        return $this->query;
    }

    function query($start = null, $count = 0)
    {
        return $this->query->query($start, $count);
    }

    function getFoundRows()
    {
        return $this->query->getFoundRows();
    }
}

/**
 * Simple users search on e-mail page
 */
class Am_Query_Ui_Simple extends Am_Query_Ui_Abstract
{
    protected $name = "simple";

    public function __construct()
    {
        $this->title = ___("Simple Search Users");
        parent::__construct();
    }

    public function setFromRequest(Am_Mvc_Request $request)
    {
        if ($search = $request->get('search-simple')) {
            $this->options = unserialize($search);
        } else {
            $this->options = $request->get('_search-simple', array());
        }
        $this->applySimpleSearch();
    }

    function getSimpleOptions()
    {
        $ret = array();
        $ret[___('User Search')] = array(
            'all' => ___('All Users'),
            'aff' => ___('All Affiliates'),
            'active-all' => ___('Active Users'),
            'expired-all' => ___('Expired Users'),);
        if (in_array('newsletter', Am_Di::getInstance()->modules->getEnabled()))
        {
            $newsletters = Am_Di::getInstance()->newsletterListTable->getAdminOptions();
            foreach ($newsletters as $k => $v)
                $ret[___('Subscribed To Newsletters')]['newsletter-' . $k] = array(
                    'label' => $v,
                    'data-label' => ___('Newsletters') . ': ' . $v);
        }
        $products = Am_Di::getInstance()->productTable->getOptions();
        foreach ($products as $k => $v)
            $ret[___('Having Active Subscription To:')]['active-' . $k] = array(
                    'label' => $v,
                    'data-label' => ___('Active') . ': ' . $v);
        foreach ($products as $k => $v)
            $ret[___('Having Expired Subscription To:')]['expired-' . $k] = array(
                    'label' => $v,
                    'data-label' => ___('Expired') . ': ' . $v);
        return $ret;
    }

    function applySimpleSearch()
    {
        $search = array();
        foreach ($this->options as $string)
        {
            @list($k, $id) = explode('-', $string, 2);
            $search[$k][] = $id == 'all' ? 'all' : intval($id);
        }

        $queries = array(); // union these queries with $this->query
        $this->queryDescription = array();
        foreach ($search as $k => $items)
        {
            if ($k == 'all')
            {
                $q = new Am_Query_User();
                $q->addWhere("u.unsubscribed=0");
                $queries = array($q);
                $this->queryDescription = array(___("All Users"));
                break;
            }
            switch ($k)
            {
                case 'aff':
                    $q = new Am_Query_User();
                    $q->addWhere("u.unsubscribed=0");
                    $q->addWhere("is_affiliate>0");
                    $queries[] = $q;
                    $this->queryDescription[] = ___("All Affiliates");
                    break;
                case 'active':
                case 'expired':
                    $q = new Am_Query_User();
                    $q->addWhere("u.unsubscribed=0");
                    $product_ids = in_array('all', $items) ? null : $items;
                    if(!$product_ids)
                        $q->addWhere("u.status=".($k == 'expired' ? User::STATUS_EXPIRED : User::STATUS_ACTIVE));
                    else
                        $q->add(new Am_Query_User_Condition_HaveSubscriptionTo($product_ids,
                                $k == 'expired' ? User::STATUS_EXPIRED : User::STATUS_ACTIVE));
                    $queries[] = $q;
                    $this->queryDescription[] =
                        ($k == 'expired' ? ___("Expired Users") : ___("Active Users")) .
                        ($product_ids ? (" " . ___("of products") . " " . join(",", $product_ids)) : null);
                    break;
                case 'newsletter':
                    if (Am_Di::getInstance()->modules->isEnabled('newsletter'))
                    {
                        $q = new Am_Query_User();
                        $q->addWhere("u.unsubscribed=0");
                        $q->add(new Am_Query_User_Condition_SubscribedToNewsletter($items));
                        $queries[] = $q;
                        $this->queryDescription[] = ___("Users subscribed to Newsletter Threads #") . join(',', $items);
                    }
                    break;
            }
        }
        if ($queries)
        {
            $this->query = array_shift($queries);
            foreach ($queries as $q)
                $this->query->addUnion($q);
        } else {
            $this->query->addWhere('0=1');
        }
        $this->queryDescription = join(' ,also ', $this->queryDescription);
    }

    public function render()
    {
        $options = Am_Html::renderOptions($this->getSimpleOptions(), $this->options);

        $apply = ___("Apply Filter");
        return <<<CUT
<div class="popup-wide">
    <div class="popup-top-arrow"></div>
    <div class="popup-content">
        <form method="post">
            <select name="_search-simple[]" multiple="multiple" class="el-wide magicselect">
            $options
            </select>
            <input type="hidden" name="search-type" value="simple" /><br />
            <input type="submit" value="$apply" style="margin-top: 5px"/>
        </form>
    </div>
    <div class="popup-bottom"></div>
</div>
CUT;
    }

    public function getTargetListIds()
    {
        $ret = array();
        if ($this->options) {
            foreach ($this->options as $s)
            {
                @list($k, $v) = @explode('-', $s, 2);
                if ($k == 'newsletter')
                    $ret[] = $v;
            }
        }
        return $ret;
    }
}

/**
 * Saved query loaded from database
 */
class Am_Query_Ui_Load extends Am_Query_Ui_Abstract
{
    protected $queryDescription;
    protected $title = "";
    protected $name = "load";
}

/**
 * Query by filter
 */
class Am_Query_Ui_Filter extends Am_Query_Ui_Abstract
{
    protected $name = "filter";

    public function render()
    {
        $val = Am_Html::escape($this->options);
        $selfUrl = Am_Html::escape(Am_Di::getInstance()->request->assembleUrl(false,true));
        $label_filter_title = ___('Filter by Username or Name or E-Mail Address#');
        $label_filter = ___('Filter');
        return <<<CUT
        <form class='filter' method='get' action='$selfUrl'>
        <strong>$label_filter_title</strong>
        <input type='text' name='search-filter' value='$val'>
        <input type='text' name='search-type' value='filter' />
        <input type='submit' value='$label_filter' class='query-button'>
        </form>
CUT;
    }
}

/**
 * Advanced user search
 */
class Am_Query_Ui_Advanced extends Am_Query_Ui_Abstract
{
    protected $queryDescription;
    protected $name  = "advanced";

    public function __construct()
    {
        parent::__construct();
        $this->title = ___("Advanced Search");
    }

    public function render()
    {
        return $this->query->renderForm("\n<input type='hidden' name='search-type' value='advanced' />\n");
    }

    public function getDescription()
    {
        return $this->query->getDescription();
    }

    public function setFromRequest(Am_Mvc_Request $request)
    {
        if (is_string($search = $request->get('search'))) {
            $this->query->unserialize($search);
        } else {
            if ($id = $request->getInt('_u_search_load'))
            {
                $this->query->load($id);
            } else {
                $this->query->setFromRequest($request);
            }
        }
    }

    public function getHidden()
    {
        return array('search' => $this->query->serialize());
    }

    public function getTargetListIds()
    {
        if ($search = $this->query->getConditions())
            foreach ($search as $condition)
            {
                if ($condition instanceof Am_Query_User_Condition_SubscribedToNewsletter)
                    return $condition->getLists();
            }
        return array();
    }
}

/**
 * Class implements different search Ui for customers
 * @package Am_Query_Ui
 */
class Am_Query_Ui
{
    /** @var Am_Query_Ui_Abstract */
    protected $active;
    protected $interfaces;

    function getAll()
    {
        return $this->intefaces;
    }

    /**
     * @param Am_Query_Ui_Abstract $interface
     */
    function add($interface)
    {
        if (!$this->active) $this->active = $interface;
        $this->interfaces[] = $interface;
    }

    function addDefaults()
    {
        $this->add(new Am_Query_Ui_Abstract);
        foreach (amFindSuccessors('Am_Query_Ui_Abstract') as $className) {
            $this->add(new $className);
        }
    }

    function getActive()
    {
        return $this->active;
    }

    function render()
    {
        $out = "";
        foreach ($this->interfaces as $q)
        {
            /* @var $q Am_Query_Ui */
            if ($q->getTitle() == '') continue;
            if ($q == $this->active)
                $out .= '<input type="checkbox" id="query-check-'.$q->getName().'" checked="cheked" disabled="disabled">'."\n";
            $out .= sprintf('<input type="button" class="query-button" id="query-button-%s" value="%s" /><br />'."\n",
                    $q->getName(), $q->getTitle());
            $out .= sprintf('<div class="query-form-div" id="query-form-%s">'."\n", $q->getName());
            $out .= $q->render();
            $out .= "\n</div>\n";
        }
        $out .= $this->getJs();
        return $out;
    }

    function getJs()
    {
        return <<<CUT
<script type="text/javascript">
jQuery(function(){
    jQuery(".query-button").click(function(){
        jQuery(this).toggleClass('active');
        jQuery(this).closest('.filter-wrap').find('.query-button').not(this).removeClass('active');

        var id = this.id.replace(/^query-button-/, "");
        jQuery(".query-form-div[id!='query-form-"+id+"']").hide();
        jQuery("#query-form-"+id)
            .toggle()
            .filter(":visible")
            .position({my:'right top',at:'right bottom',of:'.filter-wrap',overflow:'fit'});
    });
});
</script>
<style type="text/css">
.query-form-div {
    position: absolute; display: none;
    z-index: 100;
}
.query-button   { width: 20em }
</style>
CUT;
    }

    function setFromRequest(Am_Mvc_Request $request)
    {
        foreach ($this->interfaces as $interface)
        {
            $interface->setFromRequest($request);
            if ($interface->getName() == $request->get('search-type')) {
                $this->active = $interface;
            }
        }
    }

    function getHidden()
    {
        $ret = array('search-type' => $this->active->getName());
        foreach ($this->interfaces as $interface) {
            $ret = array_merge($ret, $interface->getHidden());
        }
        return $ret;
    }

    function query($start = null, $count = null)
    {
        return $this->active->query($start, $count);
    }

    function getFoundRows()
    {
        return $this->active->getFoundRows();
    }

    function getTargetListIds()
    {
        return $this->active->getTargetListIds();
    }
}