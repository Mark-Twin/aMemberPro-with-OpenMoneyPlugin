<?php
/**
 * Base class for all HTML_QuickForm2 elements
 *
 * PHP version 5
 *
 * LICENSE:
 *
 * Copyright (c) 2006-2011, Alexey Borzov <avb@php.net>,
 *                          Bertrand Mansion <golgote@mamasam.com>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *    * Redistributions of source code must retain the above copyright
 *      notice, this list of conditions and the following disclaimer.
 *    * Redistributions in binary form must reproduce the above copyright
 *      notice, this list of conditions and the following disclaimer in the
 *      documentation and/or other materials provided with the distribution.
 *    * The names of the authors may not be used to endorse or promote products
 *      derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS
 * IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
 * THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR
 * PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR
 * CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
 * EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
 * PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
 * PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
 * OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @category   HTML
 * @package    HTML_QuickForm2
 * @author     Alexey Borzov <avb@php.net>
 * @author     Bertrand Mansion <golgote@mamasam.com>
 * @license    http://opensource.org/licenses/bsd-license.php New BSD License
 * @version    SVN: $Id: Node.php 311435 2011-05-26 10:30:06Z avb $
 * @link       http://pear.php.net/package/HTML_QuickForm2
 */

/**
 * HTML_Common2 - base class for HTML elements
 */
require_once 'HTML/Common2.php';

// By default, we generate element IDs with numeric indexes appended even for
// elements with unique names. If you want IDs to be equal to the element
// names by default, set this configuration option to false.
if (null === HTML_Common2::getOption('id_force_append_index')) {
    HTML_Common2::setOption('id_force_append_index', true);
}

// set the default language for various elements' messages
if (null === HTML_Common2::getOption('language')) {
    HTML_Common2::setOption('language', 'en');
}

/**
 * Exception classes for HTML_QuickForm2
 */
require_once 'HTML/QuickForm2/Exception.php';

/**
 * Static factory class for QuickForm2 elements
 */
require_once 'HTML/QuickForm2/Factory.php';

/**
 * Base class for HTML_QuickForm2 rules
 */
require_once 'HTML/QuickForm2/Rule.php';


/**
 * Abstract base class for all QuickForm2 Elements and Containers
 *
 * This class is mostly here to define the interface that should be implemented
 * by the subclasses. It also contains static methods handling generation
 * of unique ids for elements which do not have ids explicitly set.
 *
 * @category   HTML
 * @package    HTML_QuickForm2
 * @author     Alexey Borzov <avb@php.net>
 * @author     Bertrand Mansion <golgote@mamasam.com>
 * @version    Release: @package_version@
 */
abstract class HTML_QuickForm2_Node extends HTML_Common2
{
   /**
    * Array containing the parts of element ids
    * @var array
    */
    protected static $ids = array();

   /**
    * Element's "frozen" status
    * @var boolean
    */
    protected $frozen = false;

   /**
    * Whether element's value should persist when element is frozen
    * @var boolean
    */
    protected $persistent = false;

   /**
    * Element containing current
    * @var HTML_QuickForm2_Container
    */
    protected $container = null;

   /**
    * Contains options and data used for the element creation
    * @var  array
    */
    protected $data = array();

   /**
    * Validation rules for element
    * @var  array
    */
    protected $rules = array();

   /**
    * An array of callback filters for element
    * @var  array
    */
    protected $filters = array();

   /**
    * Recursive filter callbacks for element
    *
    * These are recursively applied for array values of element or propagated
    * to contained elements if the element is a Container
    *
    * @var  array
    */
    protected $recursiveFilters = array();

   /**
    * Error message (usually set via Rule if validation fails)
    * @var  string
    */
    protected $error = null;

   /**
    * Changing 'name' and 'id' attributes requires some special handling
    * @var array
    */
    protected $watchedAttributes = array('id', 'name');

   /**
    * Intercepts setting 'name' and 'id' attributes
    *
    * These attributes should always be present and thus trying to remove them
    * will result in an exception. Changing their values is delegated to
    * setName() and setId() methods, respectively
    *
    * @param    string  Attribute name
    * @param    string  Attribute value, null if attribute is being removed
    * @throws   HTML_QuickForm2_InvalidArgumentException    if trying to
    *                                   remove a required attribute
    */
    protected function onAttributeChange($name, $value = null)
    {
        if ('name' == $name) {
            if (null === $value) {
                throw new HTML_QuickForm2_InvalidArgumentException(
                    "Required attribute 'name' can not be removed"
                );
            } else {
                $this->setName($value);
            }
        } elseif ('id' == $name) {
            if (null === $value) {
                throw new HTML_QuickForm2_InvalidArgumentException(
                    "Required attribute 'id' can not be removed"
                );
            } else {
                $this->setId($value);
            }
        }
    }

   /**
    * Class constructor
    *
    * @param    string  Element name
    * @param    mixed   Attributes (either a string or an array)
    * @param    array   Element data (label, options and data used for element creation)
    */
    public function __construct($name = null, $attributes = null, $data = null)
    {
        parent::__construct($attributes);
        $this->setName($name);
        // Autogenerating the id if not set on previous steps
        if ('' == $this->getId()) {
            $this->setId();
        }
        if (!empty($data)) {
            $this->data = array_merge($this->data, $data);
        }
    }


   /**
    * Generates an id for the element
    *
    * Called when an element is created without explicitly given id
    *
    * @param  string   Element name
    * @return string   The generated element id
    */
    protected static function generateId($elementName)
    {
        $stop      =  !self::getOption('id_force_append_index');
        $tokens    =  strlen($elementName)
                      ? explode('[', str_replace(']', '', $elementName))
                      : ($stop? array('qfauto', ''): array('qfauto'));
        $container =& self::$ids;
        $id        =  '';

        do {
            $token = array_shift($tokens);
            // Handle the 'array[]' names
            if ('' === $token) {
                if (empty($container)) {
                    $token = 0;
                } else {
                    $keys  = array_keys($container);
                    $token = end($keys);
                    while (isset($container[$token])) {
                        $token++;
                    }
                }
            }
            $id .= '-' . $token;
            if (!isset($container[$token])) {
                $container[$token] = array();
            // Handle duplicate names when not having mandatory indexes
            } elseif (empty($tokens) && $stop) {
                $tokens[] = '';
            }
            // Handle mandatory indexes
            if (empty($tokens) && !$stop) {
                $tokens[] = '';
                $stop     = true;
            }
            $container =& $container[$token];
        } while (!empty($tokens));

        return substr($id, 1);
    }


   /**
    * Stores the explicitly given id to prevent duplicate id generation
    *
    * @param    string  Element id
    */
    protected static function storeId($id)
    {
        $tokens    =  explode('-', $id);
        $container =& self::$ids;

        do {
            $token = array_shift($tokens);
            if (!isset($container[$token])) {
                $container[$token] = array();
            }
            $container =& $container[$token];
        } while (!empty($tokens));
    }


   /**
    * Returns the element options
    *
    * @return   array
    */
    public function getData()
    {
        return $this->data;
    }


   /**
    * Returns the element's type
    *
    * @return   string
    */
    abstract public function getType();


   /**
    * Returns the element's name
    *
    * @return   string
    */
    public function getName()
    {
        return isset($this->attributes['name'])? $this->attributes['name']: null;
    }


   /**
    * Sets the element's name
    *
    * @param    string
    * @return   HTML_QuickForm2_Node
    */
    abstract public function setName($name);


   /**
    * Returns the element's id
    *
    * @return   string
    */
    public function getId()
    {
        return isset($this->attributes['id'])? $this->attributes['id']: null;
    }


   /**
    * Sets the elements id
    *
    * Please note that elements should always have an id in QuickForm2 and
    * therefore it will not be possible to remove the element's id or set it to
    * an empty value. If id is not explicitly given, it will be autogenerated.
    *
    * @param    string  Element's id, will be autogenerated if not given
    * @return   HTML_QuickForm2_Node
    */
    public function setId($id = null)
    {
        if (is_null($id)) {
            $id = self::generateId($this->getName());
        } else {
            self::storeId($id);
        }
        $this->attributes['id'] = (string)$id;
        return $this;
    }


   /**
    * Returns the element's value without filters applied
    *
    * @return   mixed
    */
    abstract public function getRawValue();

   /**
    * Returns the element's value, possibly with filters applied
    *
    * @return mixed
    */
    public function getValue()
    {
        $value = $this->getRawValue();
        return is_null($value)? null: $this->applyFilters($value);
    }

   /**
    * Sets the element's value
    *
    * @param    mixed
    * @return   HTML_QuickForm2_Node
    */
    abstract public function setValue($value);


   /**
    * Returns the element's label(s)
    *
    * @return   string|array
    */
    public function getLabel()
    {
        if (isset($this->data['label'])) {
            return $this->data['label'];
        }
        return null;
    }


   /**
    * Sets the element's label(s)
    *
    * @param    string|array    Label for the element (may be an array of labels)
    * @return   HTML_QuickForm2_Node
    */
    public function setLabel($label)
    {
        $this->data['label'] = $label;
        return $this;
    }


   /**
    * Changes the element's frozen status
    *
    * @param    bool    Whether the element should be frozen or editable. If
    *                   omitted, the method will not change the frozen status,
    *                   just return its current value
    * @return   bool    Old value of element's frozen status
    */
    public function toggleFrozen($freeze = null)
    {
        $old = $this->frozen;
        if (null !== $freeze) {
            $this->frozen = (bool)$freeze;
        }
        return $old;
    }


   /**
    * Changes the element's persistent freeze behaviour
    *
    * If persistent freeze is on, the element's value will be kept (and
    * submitted) in a hidden field when the element is frozen.
    *
    * @param    bool    New value for "persistent freeze". If omitted, the
    *                   method will not set anything, just return the current
    *                   value of the flag.
    * @return   bool    Old value of "persistent freeze" flag
    */
    public function persistentFreeze($persistent = null)
    {
        $old = $this->persistent;
        if (null !== $persistent) {
            $this->persistent = (bool)$persistent;
        }
        return $old;
    }


   /**
    * Adds the link to the element containing current
    *
    * @param    HTML_QuickForm2_Container  Element containing the current one,
    *                                      null if the link should really be
    *                                      removed (if removing from container)
    * @throws   HTML_QuickForm2_InvalidArgumentException   If trying to set a
    *                               child of an element as its container
    */
    protected function setContainer(HTML_QuickForm2_Container $container = null)
    {
        if (null !== $container) {
            $check = $container;
            do {
                if ($this === $check) {
                    throw new HTML_QuickForm2_InvalidArgumentException(
                        'Cannot set an element or its child as its own container'
                    );
                }
            } while ($check = $check->getContainer());
            if (null !== $this->container && $container !== $this->container) {
                $this->container->removeChild($this);
            }
        }
        $this->container = $container;
        if (null !== $container) {
            $this->updateValue();
        }
    }


   /**
    * Returns the element containing current
    *
    * @return   HTML_QuickForm2_Container|null
    */
    public function getContainer()
    {
        return $this->container;
    }

   /**
    * Returns the data sources for this element
    *
    * @return   array
    */
    protected function getDataSources()
    {
        if (empty($this->container)) {
            return array();
        } else {
            return $this->container->getDataSources();
        }
    }

   /**
    * Called when the element needs to update its value from form's data sources
    */
    abstract protected function updateValue();

   /**
    * Adds a validation rule
    *
    * @param    HTML_QuickForm2_Rule|string     Validation rule or rule type
    * @param    string|int                      If first parameter is rule type, then
    *               message to display if validation fails, otherwise constant showing
    *               whether to perfom validation client-side and/or server-side
    * @param    mixed                           Additional data for the rule
    * @param    int                             Whether to perfom validation server-side
    *               and/or client side. Combination of HTML_QuickForm2_Rule::SERVER and
    *               HTML_QuickForm2_Rule::CLIENT constants
    * @return   HTML_QuickForm2_Rule            The added rule
    * @throws   HTML_QuickForm2_InvalidArgumentException    if $rule is of a
    *               wrong type or rule name isn't registered with Factory
    * @throws   HTML_QuickForm2_NotFoundException   if class for a given rule
    *               name cannot be found
    */
    public function addRule($rule, $messageOrRunAt = '', $options = null,
                            $runAt = 3) // changed by alex@cgi-central.net to enable client rules by default
    {
        /// added by alex@cgi-central.net
        if (($rule == 'required') && ($messageOrRunAt ===''))
            $messageOrRunAt = ___('This field is required');
        if ($messageOrRunAt === '')
        {
            $messageOrRunAt = '--error--';
        }
        /// end of added
        if ($rule instanceof HTML_QuickForm2_Rule) {
            $rule->setOwner($this);
            $runAt = '' == $messageOrRunAt? HTML_QuickForm2_Rule::SERVER: $messageOrRunAt;
        } elseif (is_string($rule)) {
            $rule = HTML_QuickForm2_Factory::createRule($rule, $this, $messageOrRunAt, $options);
        } else {
            throw new HTML_QuickForm2_InvalidArgumentException(
                'addRule() expects either a rule type or ' .
                'a HTML_QuickForm2_Rule instance'
            );
        }
        /// added by alex@cgi-central.net
        if ($messageOrRunAt === '--error--')
        {
            $form = $this;
            while ($form->getContainer()) $form = $form->getContainer();
            $rule->setMessage($form->findRuleMessage($rule, $this));
        }
        // end of added
        $this->rules[] = array($rule, $runAt);
        return $rule;
    }

   /**
    * Removes a validation rule
    *
    * The method will *not* throw an Exception if the rule wasn't added to the
    * element.
    *
    * @param    HTML_QuickForm2_Rule    Validation rule to remove
    * @return   HTML_QuickForm2_Rule    Removed rule
    */
    public function removeRule(HTML_QuickForm2_Rule $rule)
    {
        foreach ($this->rules as $i => $r) {
            if ($r[0] === $rule) {
                unset($this->rules[$i]);
                break;
            }
        }
        return $rule;
    }

   /**
    * Creates a validation rule
    *
    * This method is mostly useful when when chaining several rules together
    * via {@link HTML_QuickForm2_Rule::and_()} and {@link HTML_QuickForm2_Rule::or_()}
    * methods:
    * <code>
    * $first->addRule('nonempty', 'Fill in either first or second field')
    *     ->or_($second->createRule('nonempty'));
    * </code>
    *
    * @param    string                  Rule type
    * @param    string                  Message to display if validation fails
    * @param    mixed                   Additional data for the rule
    * @return   HTML_QuickForm2_Rule    The created rule
    * @throws   HTML_QuickForm2_InvalidArgumentException If rule type is unknown
    * @throws   HTML_QuickForm2_NotFoundException        If class for the rule
    *           can't be found and/or loaded from file
    */
    public function createRule($type, $message = '', $options = null)
    {
        return HTML_QuickForm2_Factory::createRule($type, $this, $message, $options);
    }


   /**
    * Checks whether an element is required
    *
    * @return   boolean
    */
    public function isRequired()
    {
        foreach ($this->rules as $rule) {
            if ($rule[0] instanceof HTML_QuickForm2_Rule_Required) {
                return true;
            }
        }
        return false;
    }

   /**
    * Adds element's client-side validation rules to a builder object
    *
    * @param HTML_QuickForm2_JavascriptBuilder
    */
    protected function renderClientRules(HTML_QuickForm2_JavascriptBuilder $builder)
    {
        if ($this->toggleFrozen()) {
            return;
        }
        foreach ($this->rules as $rule) {
            if ($rule[1] & HTML_QuickForm2_Rule::CLIENT) {
                $builder->addRule($rule[0], $rule[1] & (HTML_QuickForm2_Rule::ONBLUR_CLIENT ^ HTML_QuickForm2_Rule::CLIENT));
            }
        }
    }

   /**
    * Performs the server-side validation
    *
    * @return   boolean     Whether the element is valid
    */
    protected function validate()
    {
        foreach ($this->rules as $rule) {
            if (strlen($this->error)) {
                break;
            }
            if ($rule[1] & HTML_QuickForm2_Rule::SERVER) {
                $rule[0]->validate();
            }
        }
        return !strlen($this->error);
    }

   /**
    * Sets the error message to the element
    *
    * @param    string
    * @return   HTML_QuickForm2_Node
    */
    public function setError($error = null)
    {
        $this->error = (string)$error;
        return $this;
    }

   /**
    * Returns the error message for the element
    *
    * @return   string
    */
    public function getError()
    {
        return $this->error;
    }

   /**
    * Returns Javascript code for getting the element's value
    *
    * @param  bool  Whether it should return a parameter for qf.form.getContainerValue()
    * @return string
    */
    abstract public function getJavascriptValue($inContainer = false);

   /**
    * Returns IDs of form fields that should trigger "live" Javascript validation
    *
    * Rules added to this element with parameter HTML_QuickForm2_Rule::ONBLUR_CLIENT
    * will be run by after these form elements change or lose focus
    *
    * @return array
    */
    abstract public function getJavascriptTriggers();

    /**
     * Adds a filter
     *
     * A filter is simply a PHP callback which will be applied to the element value
     * when getValue() is called.
     *
     * @param    callback    The PHP callback used for filter
     * @param    array       Optional arguments for the callback. The first parameter
     *                       will always be the element value, then these options will
     *                       be used as parameters for the callback.
     * @return   HTML_QuickForm2_Node    The element
     * @throws   HTML_QuickForm2_InvalidArgumentException    If callback is incorrect
     */
    public function addFilter($callback, array $options = array())
    {
        if (!is_callable($callback, false, $callbackName)) {
            throw new HTML_QuickForm2_InvalidArgumentException(
                "Filter should be a valid callback, '{$callbackName}' was given"
            );
        }
        $this->filters[] = array($callback, $options);
        return $this;
    }

    /**
     * Adds a recursive filter
     *
     * A filter is simply a PHP callback which will be applied to the element value
     * when getValue() is called. If the element value is an array, for example with
     * selects of type 'multiple', the filter is applied to all values recursively.
     * A filter on a container will not be applied on a container value but
     * propagated to all contained elements instead.
     *
     * If the element is not a container and its value is not an array the behaviour
     * will be identical to filters added via addFilter().
     *
     * @param    callback    The PHP callback used for filter
     * @param    array       Optional arguments for the callback. The first parameter
     *                       will always be the element value, then these options will
     *                       be used as parameters for the callback.
     * @return   HTML_QuickForm2_Container    The container
     * @throws   HTML_QuickForm2_InvalidArgumentException    If callback is incorrect
     */
    public function addRecursiveFilter($callback, array $options = array())
    {
        if (!is_callable($callback, false, $callbackName)) {
            throw new HTML_QuickForm2_InvalidArgumentException(
                "Filter should be a valid callback, '{$callbackName}' was given"
            );
        }
        $this->recursiveFilters[] = array($callback, $options);
        return $this;
    }

   /**
    * Helper function for applying filter callback to a value
    *
    * @param    mixed   Value being filtered
    * @param    mixed   Array key (not used, present to be able to use this
    *                   method as a callback to array_walk_recursive())
    * @param    array   Array containing callback and additional callback
    *                   parameters
    */
    protected static function applyFilter(&$value, $key, $filter)
    {
        list($callback, $options) = $filter;
        array_unshift($options, $value);
        $value = call_user_func_array($callback, $options);
    }

    /**
     * Applies non-recursive filters on element value
     *
     * @param    mixed   Element value
     * @return   mixed   Filtered value
     */
    protected function applyFilters($value)
    {
        foreach ($this->filters as $filter) {
            self::applyFilter($value, null, $filter);
        }
        return $value;
    }
}
?>
