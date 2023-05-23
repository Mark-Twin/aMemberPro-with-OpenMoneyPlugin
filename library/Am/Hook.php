<?php
/**
 * Am_Hook/Am_Events system
 *
 * @author   Alex Scott <alex@cgi-central.net>
 * @package Am_Events
 * @version  $Id$
 * @license  http://www.amember.com/p/Main/License
 * @copyright Copyright &copy; 2006, Alex Scott
 */
if (!defined('INCLUDED_AMEMBER_CONFIG'))
    die("Direct access to this location is not allowed");

/**
 * Class handles Am_Hooks addition, management and calling
 * for example
 * Am_Hook::Manager()->add('BeforeUserDelete', 'myfunc', __FILE__);
 * Am_Hook::Manager()->add('daily', array(&$this, 'daily'));
 *
 * $ev = Am_Hook::Manager()->call('daily', time());
 * // arguments will be passed to Am_Event_Daily constructor
 * // and the event will be returned
 */
class Am_HookCallback
{
    protected $callback;
    protected $file;

    function  __construct($callback, $file=null)
    {
        $this->callback = $callback;
        $this->file = $file;
    }

    function getCallback()
    {
        return $this->callback;
    }

    function getFile()
    {
        return $this->file;
    }

    /**
     * Return string description of $callback
     * @return string
     */
    static public function static_getSignature($callback)
    {
        if ($callback === null)
            return "NULL";
        if (is_string($callback))
            return $callback;
        if ($callback instanceof Closure )
        {
            $r = new ReflectionFunction($callback);
            return sprintf("Closure [%s:%d]", basename($r->getFileName()), $r->getStartLine());
        } elseif (is_object($callback[0]))
            return get_class($callback[0]) . '->' . $callback[1];
        else
            return (string)($callback[0]) . '::' . $callback[1];
    }

    /**
     * Return string description of this callback
     * @return string
     */
    public function getSignature()
    {
        return self::static_getSignature($this->callback);
    }
}

/**
 * Defines hook manager
 * @see Am_Di::getInstance()->hook
 * @package Am_Events
 */
class Am_Hook
{
    // @todo implement class,reflection checks caching (protected $okEvents=array())
    protected $hooks = array();
    protected $disabled = array();
    protected $disableAll = false;
    protected $observers = array();
    /** @var Am_Di */
    protected $di;

    public function __construct(Am_Di $di)
    {
        $this->di = $di;
    }

    public function _addObserver($callback)
    {
        $this->observers[] = $callback;
    }

    protected function addPrepareVars(&$hook, & $callback, &$file)
    {
        $className='Am_Event_'.ucfirst($hook);
 // Commented out - we need to place Am_Event classes to outside of loaded core
 //       if (!class_exists($className, false))
 //           throw new Am_Exception_InternalError("Could not add hook, class $className does not exists");
 //
 //       $reflection = new ReflectionClass($className);
 //       if ($reflection->isAbstract())
 //           throw new Am_Exception_InternalError("Could not add hook, class $className is abstract");
        $strongName = lcfirst(substr($className, strlen('Am_Event_')));
 //       $strongName = lcfirst(substr($reflection->getName(), strlen('Am_Event_')));
        if (strcmp($strongName, $hook))
            throw new Am_Exception_InternalError("Could not add hook, hookname case is incorrect, must be exactly [$strongName], passed [$hook]");

        if (!is_callable($callback, ($file != null)))
            throw new Am_Exception_InternalError("Could not add hook, callback provided [".Am_HookCallback::static_getSignature($callback)."] is not callable");
        if ($file !== null) {
            $rfile = $this->_getRelativePath($file);
            if (!$rfile)
                throw new Am_Exception_InternalError("Could not add hook, path provided is not relative [".htmlentities($file)."]");
            $file = $rfile;
        }
    }

    /**
     * Add Am_Hook
     * @param string $hook Am_Hook Name, use constants
     * @param callback $callback Callback to be called
     * @param string $file (optional) File to include that contains given callback function
     */
    function add($hook, $callback, $file=null)
    {
        foreach ((array)$hook as $h) {
            $this->addPrepareVars($h, $callback, $file);
            $this->hooks[$h][] = new Am_HookCallback($callback, $file);
        }
        return $this;
    }

    /**
     * Prepend Am_Hook
     * @param string $hook Am_Hook Name, use constants
     * @param callback $callback Callback to be called
     * @param string $file (optional) File to include that contains given callback function
     */
    function prepend($hook, $callback, $file=null)
    {
        $this->addPrepareVars($hook, $callback, $file);
        if (!isset($this->hooks[$hook]))
            $this->hooks[$hook] = array();
        array_unshift($this->hooks[$hook], new Am_HookCallback($callback, $file));
        return $this;
    }

    /**
     * Does the hook have listeners ?
     * @param string|array $hook
     */
    public function have($hook)
    {
        $hook = (array)$hook;
        foreach ($hook as $h)
            if (!empty($this->hooks[$h]) && count($this->hooks[$h]))
                return true;
        return false;
    }

    /**
     * Delete all registered hooks from $hook type
     * @param string $hook
     */
    function delete($hook)
    {
        $this->hooks[$hook] = array();
    }

    /**
     * shortcut for special case of call
     */
    function filter($val, $event, $params = array())
    {
        $e = $event instanceof Am_Event ? $event : new Am_Event($event, $params);
        $e->setReturn($val);
        $this->call($e);
        return $e->getReturn();
    }

    /**
     * call registered hooks
     *
     * This function can be called like:
     *  $m->call(new Am_Event_SubscriptionAdded())
     *  $m->call(new Event('myEventId', array('user' => $user)));
     *  or the same
     *  $m->call('myEventId', array('user' => $user));
     *  or if no parameters necessary
     *  $m->call('myEventId');
     *
     * @param string|Am_Event $hookOrEvent
     * @param Am_Event|arraynull $event
     * @return Am_Event
     */
    function call($hook, $event = null)
    {
        if ($event === null)
        {
            if (is_string($hook)) {
                $event = new Am_Event($hook);
            } elseif ($hook instanceof Am_Event) {
                $event = $hook;
                $hook = $event->getId();
            } else {
                throw new Am_Exception_InternalError("Unknown argument for " . __METHOD__ );
            }

        } elseif (is_array($event))
        {
            $event = new Am_Event($hook, $event);
        }

        $event->_setDi($this->di);

        foreach ($this->observers as $o) // notify observers
            call_user_func($o, $event, $hook, $this);

        if (!$this->disableAll
        && !array_key_exists($hook, $this->disabled)
        && array_key_exists($hook, $this->hooks)
        && count($this->hooks[$hook]))
            $event->handle($this->hooks[$hook]);

        return $event;
    }

    /**
     * Disables all hooks of given type
     * @param string
     */
    function disable($hook)
    {
        $this->disabled[$hook] = true;
    }

    /**
     * Enables hooks of given type
     */
    function enable($hook)
    {
        unset($this->disabled[$hook]);
    }

    /**
     * Toggle or return "disable" flag value for a hook
     * @param string $hook
     * @param bool (optional) flag to set
     * @return bool previous "disabled" state
     */
    function toggleDisabled($hook, $flag=null)
    {
        $ret = !empty($this->disabled[$hook]);
        if ($flag !== null)
            $flag ? $this->disable($hook) : $this->enable($hook);
        return $ret;
    }

    /**
     * Disable or enable ALL hooks
     * @param bool $state
     */
    function toggleDisableAll($state)
    {
        $this->disableAll = (bool)$state;
    }

    /**
     * Return list of registered hooks
     * @param hook type $hook
     * @return array
     */
    function getRegisteredHooks($hook)
    {
        return array_key_exists($hook, $this->hooks) ? $this->hooks[$hook] : array();
    }

    /** Function works as regular realpath() but does not resolve symlinks and
     *  not check real file existances
     */
    function realpath($path)
    {
        $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
        $parts = explode(DIRECTORY_SEPARATOR, $path);
        $absolutes = array();
        foreach ($parts as $part) {
            if ('.' == $part) continue;
            if ('..' == $part) {
                array_pop($absolutes);
            } else {
                $absolutes[] = $part;
            }
        }
        return implode(DIRECTORY_SEPARATOR, $absolutes);
    }

    function _getRelativePath($file)
    {
        $root_dir = Am_Di::getInstance()->root_dir;
        // if file path is not absolute (win/unix), consider it is relative to amember root
        if (AM_WIN && !preg_match('{([a-zA-Z]:)}i', $file)) {
            $file = $root_dir . DIRECTORY_SEPARATOR . $file;
        } elseif (!AM_WIN && $file[0] != '/') {
            $file = $root_dir . DIRECTORY_SEPARATOR . $file;
        }
        // now check if file actually exists
        $file = $this->realpath($file);
        if (strpos($file, $root_dir) !== 0)
            return null;
        else
            return $this->normalizePath(substr($file, strlen($root_dir) + strlen(DIRECTORY_SEPARATOR)));
    }

    function normalizePath($path)
    {
        if (AM_WIN){
            $path = preg_replace('|^[A-Za-z]:|', '', $path);
            $path = str_replace("\\", '/', $path);
        }
        return $path;
    }

    function dumpHooks()
    {
        $ret = array();
        foreach ($this->hooks as $k => $a)
            foreach ($a as $h)
                $ret[$k][] = $h->getSignature();
        return $ret;
    }

    /**
     * Remove all hooks calling methods of the $obj
     * @param mixed $obj object, callback array or functionname
     */
    function unregisterHooks($obj)
    {
        foreach ($this->hooks as $k => & $a)
            foreach ($a as $k => $h)
            {
                $callback = $h->getCallback();
                if (is_string($obj) && $obj === $callback)
                    unset($a[$k]);
                elseif (is_array($obj) && $obj === $callback)
                    unset($a[$k]);
                elseif (is_object($obj) && (is_array($callback) && $callback[0] === $obj))
                    unset($a[$k]);
            }
    }
}