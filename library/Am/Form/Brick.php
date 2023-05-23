<?php

/**
 * File contains available form bricks for saved forms
 */

/**
 * @package Am_SavedForm
 */
abstract class Am_Form_Brick
{
    const HIDE = 'hide';
    const HIDE_DONT = 0;
    const HIDE_DESIRED = 1;
    const HIDE_ALWAYS = 2;

    protected $config = array();
    protected $hideIfLoggedInPossible = self::HIDE_DESIRED;
    protected $hideIfLoggedIn = false;
    protected $id, $name;
    protected $labels = array();
    protected $customLabels = array();

    abstract public function insertBrick(HTML_QuickForm2_Container $form);

    public function __construct($id = null, $config = null)
    {
        // transform labels to array with similar key->values
        if ($this->labels && is_int(key($this->labels))) {
            $ll = array_values($this->labels);
            $this->labels = array_combine($ll, $ll);
        }
        if ($id !== null)
            $this->setId($id);
        if ($config !== null)
            $this->setConfigArray($config);
        if ($this->hideIfLoggedInPossible() == self::HIDE_ALWAYS)
            $this->hideIfLoggedIn = true;
        // format labels
    }

    /**
     * this function can be used to bind some special processing
     * to hooks
     */
    public function init()
    {

    }

    function getClass()
    {
        return fromCamelCase(str_replace('Am_Form_Brick_', '', get_class($this)), '-');
    }

    function getName()
    {
        if (!$this->name)
            $this->name = str_replace('Am_Form_Brick_', '', get_class($this));
        return $this->name;
    }

    function getId()
    {
        if (!$this->id) {
            $this->id = $this->getClass();
            if ($this->isMultiple())
                $this->id .= '-0';
        }
        return $this->id;
    }

    function setId($id)
    {
        $this->id = (string) $id;
    }

    function getConfigArray()
    {
        return $this->config;
    }

    function setConfigArray(array $config)
    {
        $this->config = $config;
    }

    function getConfig($k, $default = null)
    {
        return array_key_exists($k, $this->config) ?
            $this->config[$k] : $default;
    }

    function getStdLabels()
    {
        return $this->labels;
    }

    function getCustomLabels()
    {
        return $this->customLabels;
    }

    function setCustomLabels(array $labels)
    {
        $this->customLabels = array_map(
                function($_){return preg_replace("/\r?\n/", "\r\n", $_);},
                $labels);
    }

    function ___($id)
    {
        $args = func_get_args();
        $args[0] = array_key_exists($id, $this->customLabels) ?
            $this->customLabels[$id] :
            $this->labels[$id];
        return call_user_func_array('___', $args);
    }

    function initConfigForm(Am_Form $form)
    {

    }

    /** @return bool true if initConfigForm is overriden */
    function haveConfigForm()
    {
        $r = new ReflectionMethod(get_class($this), 'initConfigForm');
        return $r->getDeclaringClass()->getName() != __CLASS__;
    }

    function setFromRecord(array $brickConfig)
    {
        if ($brickConfig['id'])
            $this->id = $brickConfig['id'];
        $this->setConfigArray(empty($brickConfig['config']) ? array() : $brickConfig['config']);
        if (isset($brickConfig[self::HIDE]))
            $this->hideIfLoggedIn = $brickConfig[self::HIDE];
        if (isset($brickConfig['labels']))
            $this->setCustomLabels($brickConfig['labels']);
        return $this;
    }

    /** @return array */
    function getRecord()
    {
        $ret = array(
            'id' => $this->getId(),
            'class' => $this->getClass(),
        );
        if ($this->hideIfLoggedIn)
            $ret[self::HIDE] = $this->hideIfLoggedIn;
        if ($this->config)
            $ret['config'] = $this->config;
        if ($this->customLabels)
            $ret['labels'] = $this->customLabels;
        return $ret;
    }

    function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return true;
    }

    public function hideIfLoggedIn()
    {
        return $this->hideIfLoggedIn;
    }

    public function hideIfLoggedInPossible()
    {
        return $this->hideIfLoggedInPossible;
    }

    /** if user can add many instances of brick right in the editor */
    public function isMultiple()
    {
        return false;
    }


    /**
     * if special info is passed into am-order-data, check it here and change brick config
     * @return if bool false returned , brick will be removed from the form
     */
    public function applyOrderData(array $orderData)
    {
        if (isset($orderData['hide-bricks']) &&
            in_array($this->getClass(), $orderData['hide-bricks'])) {

            return false;
        }
        return true;
    }

    static function createAvailableBricks($className)
    {
        return new $className;
    }

    /**
     * @param array $brickConfig - must have keys: 'id', 'class', may have 'hide', 'config'
     *
     * @return Am_Form_Brick */
    static function createFromRecord(array $brickConfig)
    {
        if (empty($brickConfig['class']))
            throw new Am_Exception_InternalError("Error in " . __METHOD__ . " - cannot create record without [class]");
        if (empty($brickConfig['id']))
            throw new Am_Exception_InternalError("Error in " . __METHOD__ . " - cannot create record without [id]");
        $className = 'Am_Form_Brick_' . ucfirst(toCamelCase($brickConfig['class']));
        if (!class_exists($className, true)) {
            Am_Di::getInstance()->errorLogTable->log("Missing form brick: [$className] - not defined");
            return;
        }
        $b = new $className($brickConfig['id'], empty($brickConfig['config']) ? array() : $brickConfig['config']);
        if (array_key_exists(self::HIDE, $brickConfig))
            $b->hideIfLoggedIn = (bool) @$brickConfig[self::HIDE];
        if (!empty($brickConfig['labels']))
            $b->setCustomLabels($brickConfig['labels']);
        return $b;
    }

    static function getAvailableBricks(Am_Form_Bricked $form)
    {
        $ret = array();
        foreach (get_declared_classes () as $className) {
            if (is_subclass_of($className, 'Am_Form_Brick')) {
                $class = new ReflectionClass($className);
                if ($class->isAbstract())
                    continue;
                $obj = call_user_func(array($className, 'createAvailableBricks'), $className);
                if (!is_array($obj)) {
                    $obj = array($obj);
                }
                foreach ($obj as $k => $o)
                    if (!$o->isAcceptableForForm($form))
                        unset($obj[$k]);
                $ret = array_merge($ret, $obj);
            }
        }
        return $ret;
    }
}

class Am_Form_Brick_Name extends Am_Form_Brick
{
    const DISPLAY_BOTH = 0;
    const DISPLAY_FIRSTNAME = 1;
    const DISPLAY_LASTNAME = 2;
    const DISPLAY_BOTH_SINGLE_INPUT = 3;
    const DISPLAY_BOTH_REVERSE = 4;

    protected $labels = array(
        'First & Last Name',
        'Last & First Name',
        'First Name',
        'Last Name',
        'Please enter your First Name',
        'Please enter your First & Last Name',
        'Please enter your Last & First Name',
        'Please enter your Last Name',
    );
    protected $hideIfLoggedInPossible = self::HIDE_DESIRED;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Name');
        parent::__construct($id, $config);
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $user = Am_Di::getInstance()->auth->getUser();
        $disabled = $user && ($user->name_f || $user->name_l) && $this->getConfig('disabled');

        if ($this->getConfig('two_rows') && $this->getConfig('display') != self::DISPLAY_BOTH_SINGLE_INPUT) {
            if ($this->getConfig('display') != self::DISPLAY_LASTNAME) {
                $row1 = $form->addGroup('')->setLabel($this->___('First Name'));
                if (!$this->getConfig('not_required') && !$disabled) {
                    $row1->addRule('required');
                }
            }
            if ($this->getConfig('display') != self::DISPLAY_FIRSTNAME) {
                $row2 = $form->addGroup('')->setLabel($this->___('Last Name'));
                if (!$this->getConfig('not_required') && !$disabled) {
                    $row2->addRule('required');
                }
            }
            $len = 30;
        } else {
            $row1 = $form->addGroup('', array('id' => 'name-0'))
                ->setLabel($this->label());
            if (!$this->getConfig('not_required') && !$disabled) {
                $row1->addRule('required');
            }
            $row2 = $row1;
            $len = 10;
        }

        if (!$this->getConfig('display') || $this->getConfig('display') == self::DISPLAY_FIRSTNAME) {
            $this->_addNameF($row1, $len, $disabled);
            $row1->addElement('html')->setHtml(' ');
        }
        if ($this->getConfig('display') == self::DISPLAY_BOTH_REVERSE) {
            $this->_addNameL($row1, $len, $disabled);
            $row1->addElement('html')->setHtml(' ');
        }

        if (!$this->getConfig('display') || $this->getConfig('display') == self::DISPLAY_LASTNAME) {
            $this->_addNameL($row2, $len, $disabled);
        }
        if ($this->getConfig('display') == self::DISPLAY_BOTH_REVERSE) {
            $this->_addNameF($row2, $len, $disabled);
        }

        if ($this->getConfig('display') == self::DISPLAY_BOTH_SINGLE_INPUT) {
            $name = $row1->addElement('name', '_name', array('size' => 30));
            if (!$this->getConfig('not_required') && !$disabled) {
                $name->addRule('required', $this->___('Please enter your First & Last Name'));
            }
            if (!$disabled) {
                $name->addRule('regex', $this->___('Please enter your First & Last Name'), '/^[^=:<>{}()"]+$/D');
            }

            $filter = function ($v) {return trim($v);};
            if ($this->getConfig('ucfirst')) {
                $filter = function ($v) use ($filter) {
                    return ucwords(strtolower(call_user_func($filter, $v)));
                };
            }

            $form->addFilter(function($v) use ($filter) {
                if (isset($v['_name'])) {
                    list($v['name_f'], $v['name_l']) = array_pad(array_map($filter, explode(' ', $v['_name'], 2)), 2, '');
                    unset($v['_name']);
                }
                return $v;
            });

            if ($disabled) {
                $name->toggleFrozen(true);
            }
        }
    }

    protected function label()
    {
        switch ($this->getConfig('display')) {
            case self::DISPLAY_BOTH:
            case self::DISPLAY_BOTH_SINGLE_INPUT:
                return $this->___('First & Last Name');
            case self::DISPLAY_BOTH_REVERSE:
                return $this->___('Last & First Name');
            case self::DISPLAY_FIRSTNAME:
                return $this->___('First Name');
            case self::DISPLAY_LASTNAME:
                return $this->___('Last Name');
        }
    }

    protected function _addNameF($conteiner, $len, $disabled)
    {
        $this->_addName('name_f', $conteiner, $len, $disabled, $this->___('Please enter your First Name'));
    }

    protected function _addNameL($conteiner, $len, $disabled)
    {
        $this->_addName('name_l', $conteiner, $len, $disabled, $this->___('Please enter your Last Name'));
    }

    protected function _addName($token, $conteiner, $len, $disabled, $error)
    {
        $el = $conteiner->addElement('text', $token, array('size' => $len));
        if (!$this->getConfig('not_required') && !$this->getConfig('disabled')) {
            $el->addRule('required', $error);
        }
        if (!$disabled) {
            $el->addRule('regex', $error, '/^[^=:<>{}()"]+$/D');
        }

        if ($this->getConfig('ucfirst'))
            $el->addFilter(function($v){return ucfirst(strtolower($v));});

        if ($disabled)
            $el->toggleFrozen(true);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addSelect('display')
            ->setId('name-display')
            ->loadOptions(array(
                self::DISPLAY_BOTH => ___('both First and Last Name'),
                self::DISPLAY_BOTH_REVERSE => ___('both Last and First Name'),
                self::DISPLAY_FIRSTNAME => ___('only First Name'),
                self::DISPLAY_LASTNAME => ___('only Last Name'),
                self::DISPLAY_BOTH_SINGLE_INPUT => ___('both First and Last Name in Single Input')

            ))->setLabel(___('User must provide'));

        $form->addAdvCheckbox('two_rows')
            ->setId('name-two_rows')
            ->setLabel(___('Display in 2 rows'));

        $form->addAdvCheckbox('not_required')->setLabel(___('Do not require to fill in these fields'));
        $form->addAdvCheckbox('ucfirst')->setLabel(___('Make the first letters of first and last name Uppercase'));
        $form->addAdvCheckbox('disabled')->setLabel(___("Disallow Name Change\nin event of user already fill in this field then he will not be able to alter it"));

        $both = self::DISPLAY_BOTH;
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#name-display').change(function(){
        jQuery('#name-two_rows').closest('.row').toggle(jQuery(this).val() == '$both');
    }).change();
})
CUT
                );
    }
}

class Am_Form_Brick_HTML extends Am_Form_Brick
{
    static $counter = 0;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('HTML text');
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addSelect('position', array('class' => 'brick-html-position'))
            ->setLabel(___('Position for HTML'))
            ->loadOptions(array(
                '' => ___('Default'),
                'header' => ___('Above Form (Header)'),
                'footer' => ___('Below Form (Footer)'),
                'sidebar' => ___('Sidebar')
            ));

        $form->addHtmlEditor('html', array('rows' => 15, 'class' => 'html-editor'), array('dontInitMce' => true))
            ->setLabel(___('HTML Code that will be displayed'));

        $form->addText('label', array('class' => 'el-wide', 'rel' => 'position-default'))->setLabel(___('Label'));
        $form->addAdvCheckbox('no_label', array('rel' => 'position-default'))->setLabel(___('Remove Label'));

        $form->addMagicSelect('lang')
            ->setLabel(___("Language\n" .
                'Display this brick only for the following languages. ' .
                'Keep it empty to display for any language.'))
            ->loadOptions($this->getLangaugeList());
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('.brick-html-position').change(function(){
        jQuery(this).closest('form').find('[rel=position-default]').closest('.row').toggle(jQuery(this).val() == '');
    }).change();
})
CUT
            );
    }

    public function getLangaugeList()
    {
        $di = Am_Di::getInstance();
        $avail = $di->languagesListUser;
        $_list = array();
        if ($enabled = $di->getLangEnabled(false))
            foreach ($enabled as $lang)
                if (!empty($avail[$lang]))
                    $_list[$lang] = $avail[$lang];
        return $_list;
    }

    public function getLanguage()
    {
        $_list = $this->getLangaugeList();
        $_locale = key(Zend_Locale::getDefault());
        if (!array_key_exists($_locale, $_list))
            list($_locale) = explode('_', $_locale);
        return $_locale;
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $html = $this->getConfig('html');
        if ($user = Am_Di::getInstance()->auth->getUser()) {
            $t = new Am_SimpleTemplate();
            $t->assign('user', $user);
            $html = $t->render($html);
        }

        $lang = $this->getConfig('lang');
        if ($lang && !in_array($this->getLanguage(), $lang)) return;

        switch ($this->getConfig('position')) {
            case 'sidebar' :
                $form->addProlog(<<<CUT
<div class="am-form-container">
    <div class="am-form-form">
CUT
                    );
                $form->addEpilog(<<<CUT
    </div>
    <div class="am-form-sidebar">
        <div class="am-form-sidebar-sidebar">
            {$html}
        </div>
    </div>
</div>
CUT
                    );
                break;
            case 'header' :
                $form->addProlog($html);
                break;
            case 'footer' :
                $form->addEpilog($html);
                break;
            default:
                $attrs = $data = array();
                $data['content'] = $html;
                if ($this->getConfig('no_label')) {
                    $attrs['class'] = 'no-label';
                } else {
                    $data['label'] = $this->getConfig('label');
                }
                $form->addStatic('html' . (++self::$counter), $attrs, $data);
        }
    }

    public function isMultiple()
    {
        return true;
    }
}

class Am_Form_Brick_JavaScript extends Am_Form_Brick
{
    public function initConfigForm(Am_Form $form)
    {
        $form->addTextarea('code', array('rows' => 15, 'class' => 'el-wide'))
            ->setLabel(___("JavaScript Code\n" .
                "it will be injected on signup form"));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $form->addScript()->setScript($this->getConfig('code'));
    }

    public function isMultiple()
    {
        return true;
    }
}

class Am_Form_Brick_Email extends Am_Form_Brick
{
    protected $labels = array(
        "Your E-Mail Address\na confirmation email will be sent to you at this address",
        'Please enter valid Email',
        'Confirm Your E-Mail Address',
        'E-Mail Address and E-Mail Address Confirmation are different. Please reenter both',
        'An account with the same email already exists.',
        'Please %slogin%s to your existing account.%sIf you have not completed payment, you will be able to complete it after login'
    );
    protected $hideIfLoggedInPossible = self::HIDE_ALWAYS;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('E-Mail');
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox('validate')->setLabel(___('Validate E-Mail Address by sending e-mail message with code'));
        $form->addAdvCheckbox('confirm')
            ->setLabel(___("Confirm E-Mail Address\n" .
                'second field will be displayed to enter email address twice'))
            ->setId('email-confirm');
        $form->addAdvCheckbox('do_not_allow_copy_paste')
            ->setLabel(___('Does not allow to Copy&Paste to confirmation field'))
            ->setId('email-do_not_allow_copy_paste');
        $form->addAdvCheckbox("disabled")->setLabel(___('Read-only'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#email-confirm').change(function(){
        jQuery('#email-do_not_allow_copy_paste').closest('div.row').toggle(this.checked);
    }).change()
})
CUT
        );
    }

    public function check($email)
    {
        $user_id = Am_Di::getInstance()->auth->getUserId();
        if (!$user_id)
            $user_id = Am_Di::getInstance()->session->signup_member_id;

        if (!Am_Di::getInstance()->userTable->checkUniqEmail($email, $user_id))
            return $this->___('An account with the same email already exists.') . '<br />' .
            $this->___('Please %slogin%s to your existing account.%sIf you have not completed payment, you will be able to complete it after login', '<a href="' . Am_Di::getInstance()->url('login', array('amember_redirect_url'=>$_SERVER['REQUEST_URI'])) . '">', '</a>', '<br />');
        return Am_Di::getInstance()->banTable->checkBan(array('email' => $email));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $email = $form->addText('email', array('size' => 30))
                ->setLabel($this->___("Your E-Mail Address\na confirmation email will be sent to you at this address"));
        $email->addRule('required', $this->___('Please enter valid Email'))
            ->addRule('callback', $this->___('Please enter valid Email'), array('Am_Validate', 'email'));
        if ($this->getConfig('disabled'))
            $email->toggleFrozen(true);
        $redirect = isset($_GET['amember_redirect_url']) ? $_GET['amember_redirect_url'] : $_SERVER['REQUEST_URI'];
        $email->addRule('callback2', '--wrong email--', array($this, 'check'))
            ->addRule('remote', '--wrong email--', array(
                'url' => Am_Di::getInstance()->url('ajax', array('do'=>'check_uniq_email','_url'=>$redirect), false)
            ));
        if ($this->getConfig('confirm', 0)) {
            $email0 = $form->addText('_email', array('size' => 30))
                    ->setLabel($this->___("Confirm Your E-Mail Address"))
                    ->setId('email-confirm');
            $email0->addRule('required');
            $email0->addRule('eq', $this->___('E-Mail Address and E-Mail Address Confirmation are different. Please reenter both'), $email);

            if ($this->getConfig('do_not_allow_copy_paste')) {
                $form->addScript()
                    ->setScript('
jQuery(function(){
    var $ = jQuery;
    jQuery("#email-confirm").bind("paste", function() {
        return false;
    })
})');
            }
            return array($email, $email0);
        }
    }
}

class Am_Form_Brick_Login extends Am_Form_Brick
{
    protected $labels = array(
        "Choose a Username\nit must be %d or more characters in length\nmay only contain letters, numbers, and underscores",
        'Please enter valid Username. It must contain at least %d characters',
        'Username contains invalid characters - please use digits, letters or spaces',
        'Username contains invalid characters - please use digits, letters, dash and underscore',
        'Username %s is already taken. Please choose another username',
    );
    protected $hideIfLoggedInPossible = self::HIDE_ALWAYS;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___("Username");
        parent::__construct($id, $config);
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $len = Am_Di::getInstance()->config->get('login_min_length', 6);
        $login = $form->addText('login', array('size' => 30, 'maxlength' => Am_Di::getInstance()->config->get('login_max_length', 64)))
                ->setLabel($this->___("Choose a Username\nit must be %d or more characters in length\nmay only contain letters, numbers, and underscores", $len));
        $login->addRule('required', sprintf($this->___('Please enter valid Username. It must contain at least %d characters'), $len))
            ->addRule('length', sprintf($this->___('Please enter valid Username. It must contain at least %d characters'), $len), array($len, Am_Di::getInstance()->config->get('login_max_length', 64)))
            ->addRule('regex', !Am_Di::getInstance()->config->get('login_disallow_spaces') ?
                    $this->___('Username contains invalid characters - please use digits, letters or spaces') :
                    $this->___('Username contains invalid characters - please use digits, letters, dash and underscore'),
                Am_Di::getInstance()->userTable->getLoginRegex())
            ->addRule('callback2', "--wrong login--", array($this, 'check'))
            ->addRule('remote', '--wrong login--', array(
                'url' => Am_Di::getInstance()->url('ajax', array('do'=>'check_uniq_login'), false),
            ));

        if (!Am_Di::getInstance()->config->get('login_dont_lowercase'))
            $login->addFilter('strtolower');

        $this->form = $form;
    }

    public function check($login)
    {
        if (!Am_Di::getInstance()->userTable->checkUniqLogin($login, Am_Di::getInstance()->session->signup_member_id))
            return sprintf($this->___('Username %s is already taken. Please choose another username'), Am_Html::escape($login));
        return Am_Di::getInstance()->banTable->checkBan(array('login' => $login));
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }
}

class Am_Form_Brick_NewLogin extends Am_Form_Brick
{
    protected $labels = array(
        "Username\nyou can choose new username here or keep it unchanged.\nUsername must be %d or more characters in length and may\nonly contain small letters, numbers, and underscore",
        "Please enter valid Username. It must contain at least %d characters",
        "Username contains invalid characters - please use digits, letters or spaces",
        "Username contains invalid characters - please use digits, letters, dash and underscore",
        'Username %s is already taken. Please choose another username',
    );

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Change Username');
        parent::__construct($id, $config);
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $len = Am_Di::getInstance()->config->get('login_min_length', 6);
        $login = $form->addText('login', array('maxlength' => Am_Di::getInstance()->config->get('login_max_length', 64)))
                ->setLabel(sprintf($this->___("Username\nyou can choose new username here or keep it unchanged.\nUsername must be %d or more characters in length and may\nonly contain small letters, numbers, and underscore"), $len)
        );
        if ($this->getConfig('disabled')) {
            $login->toggleFrozen(true);
        } else {
            $login
                ->addRule('required')
                ->addRule('length', sprintf($this->___("Please enter valid Username. It must contain at least %d characters"), $len), array($len, Am_Di::getInstance()->config->get('login_max_length', 64)))
                ->addRule('regex', !Am_Di::getInstance()->config->get('login_disallow_spaces') ?
                        $this->___("Username contains invalid characters - please use digits, letters or spaces") :
                        $this->___("Username contains invalid characters - please use digits, letters, dash and underscore"),
                    Am_Di::getInstance()->userTable->getLoginRegex())
                ->addRule('callback2', $this->___('Username %s is already taken. Please choose another username'), array($this, 'checkNewUniqLogin'));
        }
    }

    function checkNewUniqLogin($login)
    {
        $auth_user = Am_Di::getInstance()->auth->getUser();
        if (strcasecmp($login, $auth_user->login) !== 0)
            if (!$auth_user->getTable()->checkUniqLogin($login, $auth_user->pk()))
                return sprintf($this->___('Username %s is already taken. Please choose another username'), Am_Html::escape($login));
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Profile;
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox("disabled")->setLabel(___('Read-only'));
    }
}

class Am_Form_Brick_Password extends Am_Form_Brick
{
    protected $labels = array(
        "Choose a Password\nmust be %d or more characters",
        'Confirm Your Password',
        'Please enter Password',
        'Password must contain at least %d letters or digits',
        'Password and Password Confirmation are different. Please reenter both',
        'Password should contain at least 2 capital letters, 2 or more numbers and 2 or more special chars',
    );
    protected $hideIfLoggedInPossible = self::HIDE_ALWAYS;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___("Password");
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox('do_not_confirm')
            ->setLabel(___("Does not Confirm Password\n" .
                'second field will not be displayed to enter password twice'))
            ->setId('password-do_not_confirm');
        $form->addAdvCheckbox('do_not_allow_copy_paste')
            ->setLabel(___('Does not allow to Copy&Paste to confirmation field'))
            ->setId('password-do_not_allow_copy_paste');
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#password-do_not_confirm').change(function(){
        jQuery('#password-do_not_allow_copy_paste').closest('div.row').toggle(!this.checked);
    }).change()
})
CUT
        );
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $len = Am_Di::getInstance()->config->get('pass_min_length', 6);
        $pass = $form->addPassword('pass', array('size' => 30, 'autocomplete'=>'off', 'maxlength' => Am_Di::getInstance()->config->get('pass_max_length', 64), 'class' => 'am-pass-indicator'))
                ->setLabel($this->___("Choose a Password\nmust be %d or more characters", $len));

        $pass->addRule('required', $this->___('Please enter Password'));
        $pass->addRule('length', sprintf($this->___('Password must contain at least %d letters or digits'), $len),
            array($len, Am_Di::getInstance()->config->get('pass_max_length', 64)));

        if (Am_Di::getInstance()->config->get('require_strong_password')) {
            $pass->addRule('regex', $this->___('Password should contain at least 2 capital letters, 2 or more numbers and 2 or more special chars'),
                Am_Di::getInstance()->userTable->getStrongPasswordRegex());
        }

        if (!$this->getConfig('do_not_confirm')) {
            $pass0 = $form->addPassword('_pass', array('size' => 30, 'autocomplete'=>'off'))
                    ->setLabel($this->___('Confirm Your Password'))
                    ->setId('pass-confirm');
            $pass0->addRule('required');
            $pass0->addRule('eq', $this->___('Password and Password Confirmation are different. Please reenter both'), array($pass));

            if ($this->getConfig('do_not_allow_copy_paste')) {
                $form->addScript()
                    ->setScript('
jQuery(function($){
    jQuery("#pass-confirm").bind("paste", function() {
        return false;
    })
})');
            }
            return array($pass, $pass0);
        } else {
            $pass->setAttribute('class', 'am-pass-reveal am-with-action am-pass-indicator');
        }
        return $pass;
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }
}

class Am_Form_Brick_NewPassword extends Am_Form_Brick
{
    protected $labels = array(
        "Password",
        "Change",
        "Your Current Password\nif you are changing password, please\n enter your current password for validation",
        "New Password\nyou can choose new password here or keep it unchanged\nmust be %d or more characters",
        'Confirm New Password',
        'Please enter Password',
        'Password must contain at least %d letters or digits',
        'Password and Password Confirmation are different. Please reenter both',
        'Please enter your current password for validation',
        'Current password entered incorrectly, please try again',
        'Password should contain at least 2 capital letters, 2 or more numbers and 2 or more special chars',
    );

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Change Password');
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox('do_not_ask_current_pass')
            ->setLabel(___("Does not Ask Current Password\n" .
                'user will not need to enter his current password to change it'));
        $form->addAdvCheckbox('do_not_confirm')
            ->setLabel(___("Does not Confirm Password\n" .
                'second field will not be displayed to enter password twice'))
            ->setId('new-password-do_not_confirm');
        $form->addAdvCheckbox('do_not_allow_copy_paste')
            ->setLabel(___('Does not allow to Copy&Paste to confirmation field'))
            ->setId('new-password-do_not_allow_copy_paste');
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#new-password-do_not_confirm').change(function(){
        jQuery('#new-password-do_not_allow_copy_paste').closest('div.row').toggle(!this.checked);
    }).change()
})
CUT
        );
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $change = Am_Html::escape($this->___('Change'));
        $form->addHtml()
            ->setLabel($this->___('Password'))
            ->setHtml(<<<CUT
<a href="javascript:;" class="local-link am-change-pass-toggle" onclick="jQuery(this).closest('.row').hide(); jQuery('.am-change-pass').closest('.row').show()">$change</a>
<script type="text/javascript">
  jQuery(function(){
    jQuery('.am-change-pass').closest('.row').toggle(jQuery('[name=pass]').val() != '');
    jQuery('.am-change-pass-toggle').closest('.row').toggle(jQuery('[name=pass]').val() == '');
  });
</script>
CUT
            );

        $len = Am_Di::getInstance()->config->get('pass_min_length', 6);
        if (!$this->getConfig('do_not_ask_current_pass')) {
            $oldPass = $form->addPassword('_oldpass', array('size' => 30, 'autocomplete'=>'off', 'class'=>'am-change-pass'))
                    ->setLabel($this->___("Your Current Password\nif you are changing password, please\n enter your current password for validation"));
            $oldPass->addRule('callback2', 'wrong', array($this, 'validateOldPass'));
        }
        $pass = $form->addPassword('pass', array('size' => 30, 'autocomplete'=>'off', 'maxlength' => Am_Di::getInstance()->config->get('pass_max_length', 64), 'class'=>'am-change-pass'))
                ->setLabel($this->___("New Password\nyou can choose new password here or keep it unchanged\nmust be %d or more characters", $len));
        $pass->addRule('length', sprintf($this->___('Password must contain at least %d letters or digits'), $len),
            array($len, Am_Di::getInstance()->config->get('pass_max_length', 64)));

        if (Am_Di::getInstance()->config->get('require_strong_password')) {
            $pass->addRule('regex', $this->___('Password should contain at least 2 capital letters, 2 or more numbers and 2 or more special chars'),
                Am_Di::getInstance()->userTable->getStrongPasswordRegex());
        }

        if (!$this->getConfig('do_not_confirm')) {
            $pass0 = $form->addPassword('_pass', array('size' => 30, 'autocomplete'=>'off', 'class'=>'am-change-pass'))
                    ->setLabel($this->___('Confirm New Password'))
                    ->setId('pass-confirm');

            $pass0->addRule('eq', $this->___('Password and Password Confirmation are different. Please reenter both'), array($pass));

            if ($this->getConfig('do_not_allow_copy_paste')) {
                $form->addScript()
                    ->setScript('
jQuery(function($){
    jQuery("#pass-confirm").bind("paste", function() {
        return false;
    })
})');
            }

            return array($pass, $pass0);
        }

        return $pass;
    }

    public function validateOldPass($vars, HTML_QuickForm2_Element_InputPassword $el)
    {
        $vars = $el->getContainer()->getValue();
        if ($vars['pass'] != '') {
            if ($vars['_oldpass'] == '')
                return $this->___('Please enter your current password for validation');

            $protector = new Am_Auth_BruteforceProtector(
                    Am_Di::getInstance()->db,
                    Am_Di::getInstance()->config->get('protect.php_include.bruteforce_count', 5),
                    Am_Di::getInstance()->config->get('protect.php_include.bruteforce_delay', 120),
                    Am_Auth_BruteforceProtector::TYPE_USER);

            if ($wait = $protector->loginAllowed($_SERVER['REMOTE_ADDR'])) {
                return ___('Please wait %d seconds before next attempt', $wait);
            }

            if (!Am_Di::getInstance()->user->checkPassword($vars['_oldpass'])) {
                $protector->reportFailure($_SERVER['REMOTE_ADDR']);
                return $this->___('Current password entered incorrectly, please try again');
            }
        }
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Profile;
    }
}

class Am_Form_Brick_Address extends Am_Form_Brick
{
    protected $labels = array(
        'Address Information' => 'Address Information',
        'Street' => 'Street',
        'Street (Second Line)' => 'Street (Second Line)',
        'City' => 'City',
        'State' => 'State',
        'ZIP Code' => 'ZIP Code',
        'Country' => 'Country',
    );

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Address Information');

        if (empty($config['fields'])) {
            $config['fields'] = array(
                'street' => 1,
                'city' => 1,
                'country' => 1,
                'state' => 1,
                'zip' => 1,
            );
        }
        parent::__construct($id, $config);
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $fieldSet = $this->getConfig('hide_fieldset') ?
            $form :
            $form->addElement('fieldset', 'address', array('id' => 'row-address-0'))->setLabel($this->___('Address Information'));

        foreach ($this->getConfig('fields', array()) as $f => $required) {
            switch ($f) {
                case 'street' :
                    $street = $fieldSet->addText('street', array('class' => 'el-wide'))->setLabel($this->___('Street'));
                    if ($required)
                        $street->addRule('required', ___('Please enter %s', $this->___('Street')));
                    break;
                case 'street2' :
                    $street = $fieldSet->addText('street2', array('class' => 'el-wide'))->setLabel($this->___('Street (Second Line)'));
                    if ($required)
                        $street->addRule('required', ___('Please enter %s', $this->___('Street (Second Line)')));
                    break;
                case 'city' :
                    $city = $fieldSet->addText('city', array('class' => 'el-wide'))->setLabel($this->___('City'));
                    if ($required)
                        $city->addRule('required', ___('Please enter %s', $this->___('City')));
                    break;
                case 'zip' :
                    $zip = $fieldSet->addText('zip')->setLabel($this->___('ZIP Code'));
                    if ($required)
                        $zip->addRule('required', ___('Please enter %s', $this->___('ZIP Code')));
                    break;
                case 'country' :
                    $country = $fieldSet->addSelect('country')->setLabel($this->___('Country'))
                            ->setId('f_country')
                            ->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
                    if ($required)
                        $country->addRule('required', ___('Please enter %s', $this->___('Country')));
                    break;
                case 'state' :
                    $group = $fieldSet->addGroup(null, array('id' => 'grp-state'))->setLabel($this->___('State'));
                    $stateSelect = $group->addSelect('state')
                            ->setId('f_state')
                            ->loadOptions($stateOptions = Am_Di::getInstance()->stateTable->getOptions(@$_REQUEST['country'], true));
                    $stateText = $group->addText('state')->setId('t_state');
                    $disableObj = $stateOptions ? $stateText : $stateSelect;
                    $disableObj->setAttribute('disabled', 'disabled')->setAttribute('style', 'display: none');
                    if ($required)
                        $group->addRule('required', ___('Please enter %s', $this->___('State')));
                    break;
            }
        }

        if ($this->getConfig('country_default')) {
            $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
                    'country' => $this->getConfig('country_default')
                )));
        }
    }

    public function setConfigArray(array $config)
    {
        // Deal with old style Address required field.
        if (isset($config['required']) && $config['required'] && !array_key_exists('street_display', $config)) {
            foreach (array('zip', 'street', 'city', 'state', 'country') as $f) {
                $config[$f . '_display'] = 1; // Required
            }
        }
        unset($config['required']);

        if (isset($config['street_display'])) {
            //backwards compatability
            //prev it stored as fieldName_display = enum(-1, 0, 1)
            //-1 - do not display
            // 0 - display
            // 1 - display and required
            isset($config['fields']) || ($config['fields'] = array());

            $farr = array('street', 'street2', 'city', 'zip', 'country', 'state');

            foreach ($farr as $f) {
                if (-1 != ($val = @$config[$f . '_display'])) {
                    $config['fields'][$f] = (int) $val;
                }
                unset($config[$f . '_display']);
            }
        }

        parent::setConfigArray($config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $farr = array('street', 'street2', 'city', 'zip', 'country', 'state');

        $fieldsVal = $this->getConfig('fields');

        $fields = $form->addElement(new Am_Form_Element_AddressFields('fields'));
        $fields->setLabel(___('Fields To Display'));
        foreach ($farr as $f) {
            $attr = array(
                'data-label' => ucfirst($f) . ' <input type="checkbox" onChange = "jQuery(this).closest(\'div\').find(\'input[type=hidden]\').val(this.checked ? 1 : 0)" /> required',
                'data-value' => !empty($fieldsVal[$f]),
            );
            $fields->addOption(ucfirst($f), $f, $attr);
        }

        $fields->setJsOptions('{
            sortable : true,
            getOptionName : function (name, option) {
                return name.replace(/\[\]$/, "") + "[" + option.value + "]";
            },
            getOptionValue : function (option) {
                return jQuery(option).data("value");
            },
            onOptionAdded : function (context, option) {
                if (jQuery(context).find("input[type=hidden]").val() == 1) {
                    jQuery(context).find("input[type=checkbox]").prop("checked", "checked");
                }
            }
        }');

        $form->addSelect('country_default')->setLabel('Default Country')->loadOptions(Am_Di::getInstance()->countryTable->getOptions(true));
        $form->addAdvCheckbox('hide_fieldset')->setLabel(___('Hide Brick Title'));
    }
}

class Am_Form_Brick_Phone extends Am_Form_Brick
{
    protected $labels = array(
        'Phone Number' => 'Phone Number',
    );

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $phone = $form->addText('phone')->setLabel($this->___('Phone Number'));
        if ($this->getConfig('required')) {
            $phone->addRule('required', ___('Please enter %s', $this->___('Phone Number')));
        }
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox('required')->setLabel(___('Required'));
    }
}

class Am_Form_Brick_Product extends Am_Form_Brick
{
    const DISPLAY_ALL = 0;
    const DISPLAY_CATEGORY = 1;
    const DISPLAY_PRODUCT = 2;
    const DISPLAY_BP = 3;

    const REQUIRE_DEFAULT = 0;
    const REQUIRE_ALWAYS = 1;
    const REQUIRE_NEVER = 2;
    const REQUIRE_ALTERNATE = 3;

    protected $labels = array(
        'Membership Type',
        'Please choose a membership type',
        'Add Membership'
    );
    protected $hideIfLoggedInPossible = self::HIDE_DONT;
    protected static $bricksAdded = 0;
    protected static $bricksWhichCanBeRequiredAdded = 0;
    protected static $bricksAlternateAdded = 0;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Product');
        parent::__construct($id, $config);
    }

    function shortRender(Product $p, BillingPlan $plan = null, $bpTitle = false)
    {
        $plan = $plan ?: $p->getBillingPlan();
        return ($bpTitle ? $plan->title : $p->getTitle()) . ' - ' . $plan->getTerms();
    }

    function renderProduct(Product $p, BillingPlan $plan = null, $short = false, $bpTitle = false)
    {
        return $p->defaultRender($plan, $short, $bpTitle);
    }

    function getProducts()
    {
        $ret = array();
        switch ($this->getConfig('type', 0)) {
            case self::DISPLAY_CATEGORY:
                $ret = Am_Di::getInstance()->productTable->getVisible($this->getConfig('groups', array()));
                break;
            case self::DISPLAY_PRODUCT:
                $ret = array();
                $ids = $this->getConfig('products', array());
                $arr = Am_Di::getInstance()->productTable->loadIds($ids);
                foreach ($ids as $id) {
                    foreach ($arr as $p)
                        if ($p->product_id == $id) {
                            if ($p->is_disabled)
                                continue;
                            $ret[] = $p;
                        }
                }
                break;
            case self::DISPLAY_BP:
                $ret = array();
                $ids = array_map('intval', $this->getConfig('bps', array())); //strip bp
                $arr = Am_Di::getInstance()->productTable->loadIds($ids);
                foreach ($ids as $id) {
                    foreach ($arr as $p)
                        if ($p->product_id == $id) {
                            if ($p->is_disabled)
                                continue;
                            $ret[] = $p;
                        }
                }
                break;
            default:
                $ret = Am_Di::getInstance()->productTable->getVisible(null);
        }
        $event = new Am_Event(Am_Event::SIGNUP_FORM_GET_PRODUCTS);
        $event->setReturn($ret);
        Am_Di::getInstance()->hook->call($event);
        return $event->getReturn();
    }

    function getBillingPlans($products)
    {
        switch ($this->getConfig('type', 0)) {
            case self::DISPLAY_BP:
                $map = array();
                foreach ($products as $p) {
                    $map[$p->pk()] = $p;
                }
                $res = array();
                foreach ($this->getConfig('bps', array()) as $item) {
                    list($p_id, $bp_id) = explode('-', $item);
                    if (isset($map[$p_id])) {
                        foreach ($map[$p_id]->getBillingPlans(true) as $bp) {
                            if ($bp->pk() == $bp_id)
                                $res[] = $bp;
                        }
                    }
                }
                break;
            case self::DISPLAY_ALL:
            case self::DISPLAY_CATEGORY:
            case self::DISPLAY_PRODUCT:
            default:
                $res = array();
                foreach ($products as $product) {
                    $res = array_merge($res, $product->getBillingPlans(true));
                }
        }
        $e = new Am_Event(Am_Event::SIGNUP_FORM_GET_BILLING_PLANS);
        $e->setReturn($res);
        Am_Di::getInstance()->hook->call($e);
        return $e->getReturn();
    }

    function getProductsFiltered()
    {
        $products = $this->getProducts();
        if ($this->getConfig('display-type', 'hide') == 'display')
            return $products;

        $user = Am_Di::getInstance()->auth->getUser();
        $haveActive = $haveExpired = array();
        if (!is_null($user)) {
            $haveActive = $user->getActiveProductIds();
            $haveExpired = $user->getExpiredProductIds();
        }
        $ret = Am_Di::getInstance()->productTable
                ->filterProducts($products, $haveActive, $haveExpired,
                    ($this->getConfig('display-type') != 'hide-always' && $this->getConfig('input-type') == 'checkbox') ? true : false);
        $event = new Am_Event(Am_Event::SIGNUP_FORM_GET_PRODUCTS_FILTERED);
        $event->setReturn($ret);
        Am_Di::getInstance()->hook->call($event);
        return $event->getReturn();
    }

    /**
     * Reset config to just one product for usage in fixed order forms
     */
    function applyOrderData(array $orderData)
    {
        $_ = parent::applyOrderData($orderData);

        if (!empty($orderData['billing_plan_id'])) {
            $bp = Am_Di::getInstance()->billingPlanTable->load($orderData['billing_plan_id']);
            $this->config['type'] = self::DISPLAY_BP;
            $id = $bp->product_id . '-' . $bp->pk() ;
            $this->config['bps'] = array( $id );
            $this->config['default'] = $id;
            $this->config['input-type'] = 'advradio';
            $this->config['require'] = 1;
        }

        return $_;
    }

    public function insertProductOptions(HTML_QuickForm2_Container $form, $pid, array $productOptions,
            BillingPlan $plan)
    {
        foreach ($productOptions as $option)
        {
            $elName = 'productOption[' . $pid . '][0][' . $option->name . ']';
            $isEmpty = empty($_POST['productOption'][$pid][0][$option->name]);
            /* @var $option ProductOption */
            $el = null;
            switch ($option->type)
            {
                case 'text':
                    $el = $form->addElement('text', $elName);
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'radio':
                    $el = $form->addElement('advradio', $elName);
                    $el->loadOptions($option->getSelectOptionsWithPrice($plan));
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'select':
                    $el = $form->addElement('select', $elName);
                    $el->loadOptions($option->getSelectOptionsWithPrice($plan));
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'multi_select':
                    $el = $form->addElement('magicselect', $elName);
                    $el->loadOptions($option->getSelectOptionsWithPrice($plan));
                    if ($isEmpty)
                        $el->setValue($option->getDefaults());
                    break;
                case 'textarea':
                    $el = $form->addElement('textarea', $elName, 'class=el-wide rows=5');
                    if ($isEmpty)
                        $el->setValue($option->getDefault());
                    break;
                case 'checkbox':
                    $opts = $option->getSelectOptionsWithPrice($plan);
                    if ($opts)
                    {
                        $el = $form->addGroup($elName);
                        $el->setSeparator("<br />");
                        foreach ($opts as $k => $v) {
                            $chkbox = $el->addAdvCheckbox(null, array('value' => $k))->setContent(___($v));
                            if ($isEmpty && in_array($k, (array)$option->getDefaults()))
                                $chkbox->setAttribute('checked', 'checked');
                        }
                        $el->addHidden(null, array('value' => ''));
                        $el->addFilter('array_filter');
                        if (count($opts) == 1 && $option->is_required) {
                            $chkbox->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::ONBLUR_CLIENT);
                        }
                    } else {
                        $el = $form->addElement('advcheckbox', $elName);
                    }
                    break;
                case 'date':
                    $el = $form->addElement('date', $elName);
                    break;
                }
            if ($el && $option->is_required)
            {
                // onblur client set to only validate option fields with javascript
                // else there is a problem with hidden fields as quickform2 does not skip validation for hidden
                $el->addRule('required', ___('This field is required'), null, HTML_QuickForm2_Rule::ONBLUR_CLIENT);
            }
            $el->setLabel(___($option->title));
        }
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $product_paysys = Am_Di::getInstance()->config->get('product_paysystem');
        $base_name = 'product_id_' . $form->getId();
        $name = self::$bricksAdded ? $base_name . '_' . self::$bricksAdded : $base_name;
        $productOptions = array();
        $products = $this->getProductsFiltered();
        if (!$products) {
            if ($this->getConfig('require', self::REQUIRE_DEFAULT) == self::REQUIRE_NEVER) return;
            throw new Am_Exception_QuietError(___("There are no products available for purchase. Please come back later."));
        }

        self::$bricksAdded++;

        if ($this->getConfig('require', self::REQUIRE_DEFAULT) != self::REQUIRE_NEVER)
            self::$bricksWhichCanBeRequiredAdded++;

        if ($this->getConfig('require', self::REQUIRE_DEFAULT) == self::REQUIRE_ALTERNATE)
            self::$bricksAlternateAdded++;

        $options = $shortOptions = $attrs = $dataOptions = array();
        if ($this->getConfig('empty-option')) {
            $shortOptions[null] = $this->getConfig('empty-option-text', ___('Please select'));
            $options[null] = '<span class="am-product-title am-product-empty">' . $shortOptions[null] .
                '</span><span class="am-product-terms"></span><span class="am-product-desc"></span>';
            $attrs[null] = array();
            $dataOptions[null] = array(
                'value' => null,
                'label' => $options[null],
                'selected' => false,
                'variable_qty' => false,
                'qty' => 1,);
        }
        foreach ($this->getBillingPlans($products) as $plan) {
            $p = $plan->getProduct();
            $pid = $p->product_id . '-' . $plan->plan_id;
            $options[$pid] = $this->renderProduct($p, $plan, false, $this->getConfig('title_source') == 'bp');
            $shortOptions[$pid] = $this->shortRender($p, $plan, $this->getConfig('title_source') == 'bp');
            $attrs[$pid] = array(
                'data-first_price' => $plan->first_price,
                'data-second_price' => $plan->second_price,
                'data-paysys' => $product_paysys && $plan->paysys_id
            );
            $dataOptions[$pid] = array(
                'label' => $options[$pid],
                'value' => $pid,
                'variable_qty' => $plan->variable_qty,
                'qty' => $plan->qty,
                'selected' => false,
            );
            $productOptions[$pid] = $p->getOptions();
            $billingPlans[$pid] = $plan;
        }
        $inputType = $this->getConfig('input-type', 'advradio');
        if (count($options) == 1) {
            if ($this->getConfig('hide_if_one'))
                $inputType = 'none';
            elseif ($inputType != 'checkbox')
                $inputType = 'hidden';
        }
        $oel = null; //outer element
        $productOptionsDontHide = false;
        switch ($inputType) {
            case 'none':
                list($pid, $label) = each($options);
                $oel = $el = $form->addHidden($name, $attrs[$pid]);
                $el->setValue($pid);
                $el->toggleFrozen(true);
                $productOptionsDontHide = true; // normally options display with js but not in this case!
                break;
            case 'checkbox':
                $data = array();
                foreach ($this->getBillingPlans($products) as $plan) {
                    $p = $plan->getProduct();
                    $data[$p->product_id . '-' . $plan->pk()] = array(
                        'data-first_price' => $plan->first_price,
                        'data-second_price' => $plan->second_price,
                        'data-paysys' => $product_paysys && $plan->paysys_id,
                        'options' => array(
                            'value' => $p->product_id . '-' . $plan->pk(),
                            'label' => $this->renderProduct($p, $plan, false, $this->getConfig('title_source') == 'bp'),
                            'variable_qty' => $plan->variable_qty,
                            'qty' => $plan->qty,
                            'selected' => false,
                        ),
                    );
                }
                if ($this->getConfig('display-popup')) {
                    $search = '';
                    if ($this->getConfig('cat-filter')) {
                        $all_cats = array();
                        foreach ($this->getBillingPlans($products) as $plan) {
                            $p = $plan->getProduct();
                            $p_cats = $p->getCategoryTitles();
                            $all_cats = array_merge($all_cats, $p_cats);
                            $data[$p->product_id . '-' . $plan->pk()]['rel'] = implode(', ', array_merge(array('All'), $p_cats));
                        }
                        $exclude = array_map(function($el) {return $el->title;}, $_ = Am_Di::getInstance()->productCategoryTable->loadIds($this->getConfig('cat-filter-exclude', array())));
                        $all_cats = array_unique($all_cats);
                        $all_cats = array_diff($all_cats, $exclude);
                        sort($all_cats);
                        array_unshift($all_cats, 'All');
                        foreach ($all_cats as $t) {
                            $search[] = sprintf('<a href="javascript:;" data-title="%s" class="local-link am-brick-product-popup-cat">%s</a>', $t, $t);
                        }
                        $search = sprintf('<div class="am-brick-product-popup-cats">%s</div>', implode(' | ', $search));
                    }

                    $oel = $gr = $form->addGroup();
                    $gr->addStatic()
                        ->setContent(sprintf('<div id="%s-preview"></div>', $name));

                    $gr->addStatic()
                        ->setContent(sprintf('<div><a id="%s" class="local-link" href="javascript:;" data-title="%s">%s</a></div>',
                                $name, $this->___('Membership Type'),
                                $this->___('Add Membership')));
                    $gr->addStatic()
                        ->setContent(sprintf('<div id="%s-list" class="am-brick-product-popup" style="display:none">%s<div style="height:350px; overflow-y:scroll;" class="am-brick-product-popup-list">', $name, $search));
                    $el = $gr->addElement(new Am_Form_Element_SignupCheckboxGroup($name, $data, 'checkbox'));
                    $gr->addStatic()
                        ->setContent('</div></div>');
                    $form->addScript()
                        ->setScript(<<<CUT
function propogateChanges(name) {
        jQuery('#' + name + '-preview').empty();
        jQuery('#' + name + '-list input[name^=product][type=checkbox]:checked').each(function(){
            jQuery('#' + name + '-preview').
                append(
                    jQuery('<div style="margin-bottom:0.2em" class="am-selected-product-row"></div>').
                        append('<a href="javascript:;" class="am-brick-product-remove" onclick="jQuery(\'#' + name + '-list input[type=checkbox][value=' + jQuery(this).val() + ']\').prop(\'checked\', \'\'); propogateChanges(\'' + name + '\'); return false;">&#10005;</a> ').
                        append(jQuery(this).parent().html().replace(/<input.*?>/g, ''))
                );
        })
        jQuery('#' + name + '-list input[type=checkbox]').change();
   }
jQuery(function(){
   jQuery('.am-brick-product-popup-cat').click(function(){
      jQuery(this).parent().find('a').removeClass('am-brick-product-popup-cat-active');
      jQuery(this).addClass('am-brick-product-popup-cat-active');
      var \$q = jQuery(this).closest('.am-brick-product-popup').find('input[type=checkbox]');
      \$q.closest('label').show().
                            next().show();
      \$q.not('[rel*="' + jQuery(this).data('title') + '"]').closest('label').hide().
                            next().hide();
   })
   jQuery('#$name').click(function(){
        jQuery('#$name-list').amPopup({
            title : jQuery(this).data('title'),
            width : 450,
            onClose: function(){
                propogateChanges('$name');
            }
        });
        return false;
   })
   propogateChanges('$name');
});
CUT
                    );
                } else {
                    $oel = $el = $form->addElement(new Am_Form_Element_SignupCheckboxGroup($name, $data, 'checkbox'));
                }

                break;
            case 'select':
                $oel = $el = $form->addSelect($name);
                foreach ($shortOptions as $pid => $label)
                    $el->addOption($label, $pid, empty($attrs[$pid]) ? null : $attrs[$pid]);
                break;
            case 'hidden':
            case 'advradio':
            default:
                $data = array();
                $first = 0;
                foreach ($options as $pid => $label) {
                    $data[$pid] = $attrs[$pid];
                    $data[$pid]['options'] = $dataOptions[$pid];
                    if (!$first++ && Am_Di::getInstance()->request->isGet()) // pre-check first option
                        $data[$pid]['options']['selected'] = true;
                }
                $oel = $el = $form->addElement(new Am_Form_Element_SignupCheckboxGroup($name, $data,
                            $inputType == 'advradio' ? 'radio' : $inputType));
                break;
        }

        $oel->setLabel($this->___('Membership Type'));
        if ($this->getConfig('no_label')) {
            $oel->setAttribute('class', 'no-label');
        }

        switch ($this->getConfig('require', self::REQUIRE_DEFAULT)) {
            case self::REQUIRE_DEFAULT :
                if (self::$bricksWhichCanBeRequiredAdded == 1)
                    $el->addRule('required', $this->___('Please choose a membership type'));
                break;
            case self::REQUIRE_ALWAYS :
                $el->addRule('required', $this->___('Please choose a membership type'));
                break;
            case self::REQUIRE_NEVER :
                break;
            case self::REQUIRE_ALTERNATE :
                if (self::$bricksAlternateAdded == 1) {
                    $f = $form;
                    while ($container = $f->getContainer())
                        $f = $container;

                    $f->addRule('callback2', $this->___('Please choose a membership type'), array($this, 'formValidate'));
                }
                break;
            default:
                throw new Am_Exception_InternalError('Unknown require type [%s] for product brick', $this->getConfig('require', self::REQUIRE_DEFAULT));
        }

        if (self::$bricksAdded == 1) {
            $script = <<<EOF
jQuery(function($){
    jQuery(":checkbox[name^='product_id'], select[name^='product_id'], :radio[name^='product_id'], input[type=hidden][name^='product_id']").change(function(){
        var el = jQuery(this);
        //product options
        el.closest("form").find("[class^=am-product-options-]").hide();
        jQuery(":checkbox[name^='product_id']:checked, select[name^='product_id'] option:selected, :radio[name^='product_id']:checked, input[type=hidden][name^='product_id']").each(function(){
            var el = jQuery(this);
            el.closest("form").find(".am-product-options-" + el.val()).show();
        });
        //variable quantity
        var show = el.is(":checked") || el.is(":selected") || this.type == 'hidden';
        el.closest("label").find(".am-product-qty")
            .toggle(show).prop("disabled", !show);
        if (this.type == 'radio')
        {   // in case of radio elements we must disable not-selected
            el.closest("form")
                .find("label:has(input[name='"+this.name+"']:not(:checked)) .am-product-qty")
                .hide().prop("disabled", true);
        }
    }).change();
});
EOF;
            $form->addScript()->setScript($script);
        }

        $d = Am_Di::getInstance()->hook->filter($this->getConfig('default'), Am_Event::SIGNUP_FORM_DEFAULT_PRODUCT);
        if ($d && $inputType != 'none' && $inputType != 'hidden') {
            $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
                $name => ($inputType == 'checkbox' || $inputType == 'advradio') ? array($d) : $d
            )));
        }
        foreach ($productOptions as $pid => $productOptions)
        {
            if ($productOptions)
            {
                $fs = $form->addElement('fieldset', '', array(
                    'class' => 'am-product-options-' . $pid,
                    'style' => $productOptionsDontHide ? '' : 'display:none;'));
                $this->insertProductOptions($fs, $pid, $productOptions, $billingPlans[$pid]);
            }
        }
    }

    public function initConfigForm(Am_Form $form)
    {
        $radio = $form->addSelect('type')
            ->setLabel(___('What to Display'));
        $radio->loadOptions(array(
            self::DISPLAY_ALL => ___('Display All Products'),
            self::DISPLAY_CATEGORY => ___('Products from selected Categories'),
            self::DISPLAY_PRODUCT => ___('Only Products selected below'),
            self::DISPLAY_BP => ___('Only Billing Plans selected below')
        ));

        $groups = $form->addMagicSelect('groups', array('data-type' => self::DISPLAY_CATEGORY,))->setLabel(___('Product Gategories'));
        $groups->loadOptions(Am_Di::getInstance()->productCategoryTable->getAdminSelectOptions(array(ProductCategoryTable::COUNT => 1)));

        $products = $form->addSortableMagicSelect('products', array('data-type' => self::DISPLAY_PRODUCT,))->setLabel(___('Product(s) to display'));
        $products->loadOptions(Am_Di::getInstance()->productTable->getOptions(true));

        $bpOptions = array();
        foreach (Am_Di::getInstance()->productTable->getVisible() as $product) {
            /* @var $product Product */
            foreach ($product->getBillingOptions() as $bp_id => $title) {
                $bpOptions[$product->pk() . '-' . $bp_id] = sprintf('%s (%s)', $product->title, $title);
            }
        }

        $bps = $form->addSortableMagicSelect('bps', array('data-type' => self::DISPLAY_BP,))->setLabel(___('Billing Plan(s) to display'));
        $bps->loadOptions($bpOptions);

        $form->addSelect('default')
            ->setLabel(___('Select by default'))
            ->loadOptions(array(''=>'') + $bpOptions);

        $inputType = $form->addSelect('input-type')->setLabel(___('Input Type'));
        $inputType->loadOptions(array(
            'advradio' => ___('Radio-buttons (one product can be selected)'),
            'select' => ___('Select-box (one product can be selected)'),
            'checkbox' => ___('Checkboxes (multiple products can be selected)'),
        ));

        $form->addAdvCheckbox('display-popup')
            ->setlabel(___('Display Products in Popup'));
        $form->addAdvCheckbox('cat-filter')
            ->setlabel(___('Add Category Filter to Popup'));
        $form->addMagicSelect('cat-filter-exclude', array('class'=>'cat-filter-exclude'))
            ->setLabel(___('Exclude the following categories from Filter'))
            ->loadOptions(Am_Di::getInstance()->productCategoryTable->getOptions());

        $form->addSelect('display-type', array('style' => 'max-width:400px'))
            ->setLabel(___('If product is not available because of require/disallow settings'))
            ->loadOptions(array(
                'hide' => ___('Remove It From Signup Form'),
                'hide-always' => ___('Remove It From Signup Form Even if Condition can meet in Current Purchase'),
                'display' => ___('Display It Anyway')
            ));

        $form->addAdvRadio('title_source')
            ->setLabel(___("Title Source\n" .
                "where to get title to represent product"))
            ->loadOptions(array(
                'product' => ___('Product'),
                'bp' => ___('Billing Plan')
            ));

        $form->addCheckboxedGroup('empty-option')
            ->setLabel(___("Add an 'empty' option to select box\nto do not choose any products"))
            ->addText('empty-option-text');

        $form->addAdvCheckbox('hide_if_one')
            ->setLabel(___("Hide Select\n" .
                'if there is only one choice'));

        $form->addAdvRadio('require')
            ->setLabel(___('Require Behaviour'))
            ->loadOptions(array(
                self::REQUIRE_DEFAULT => sprintf('<strong>%s</strong>: %s', ___('Default'), ___('Make this Brick Required Only in Case There is not any Required Brick on Page Above It')),
                self::REQUIRE_ALWAYS => sprintf('<strong>%s</strong>: %s', ___('Always'), ___('Force User to Choose Some Product from this Brick')),
                self::REQUIRE_NEVER => sprintf('<strong>%s</strong>: %s', ___('Never'), ___('Products in this Brick is Optional (Not Required)')),
                self::REQUIRE_ALTERNATE => sprintf('<strong>%s</strong>: %s', ___('Alternate'), ___('User can Choose Product in any Brick of Such Type on Page but he Should Choose at least One Product still'))))
            ->setValue(self::REQUIRE_DEFAULT);

        $formId = $form->getId();
        $script = <<<EOF
        jQuery(document).ready(function($) {
            // there can be multiple bricks like that :)
            if (!window.product_brick_hook_set)
            {
                window.product_brick_hook_set = true;
                jQuery(document).on('change',"select[name='type']", function (event){
                    var val = jQuery(event.target).val();
                    var frm = jQuery(event.target).closest("form");
                    jQuery("[data-type]", frm).closest(".row").hide();
                    jQuery("[data-type='"+val+"']", frm).closest(".row").show();
                })
                jQuery("select[name='type']").change();
                jQuery(document).on('change',"select[name='input-type']", function (event){
                    var val = jQuery(event.target).val();
                    var frm = jQuery(event.target).closest("form");
                    jQuery("input[name='display-popup']", frm).closest(".row").toggle(val == 'checkbox');
                    jQuery("input[name='empty-option']", frm).closest(".row").toggle(val == 'advradio' || val == 'select');
                })
                jQuery(document).on('change',"[name='display-popup']", function (event){
                    var frm = jQuery(event.target).closest("form");
                    jQuery("input[name='cat-filter']", frm).closest(".row").toggle(event.target.checked);
                })
                jQuery(document).on('change',"[name='cat-filter']", function (event){
                    var frm = jQuery(event.target).closest("form");
                    jQuery(".cat-filter-exclude", frm).closest(".row").toggle(event.target.checked);
                })
                jQuery("[name='cat-filter']").change();
                jQuery("select[name='input-type']").change();
                jQuery("[name='display-popup']").change();
            }
        });
EOF;
        $form->addScript()->setScript($script);

        $form->addAdvCheckbox('no_label')->setLabel(___('Remove Label'));
    }

    public function formValidate(array $values)
    {
        foreach ($values as $k => $v)
            if (strpos($k, 'product_id') === 0)
                if (!empty($v))
                    return;

        return $this->___('Please choose a membership type');
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    public function isMultiple()
    {
        return true;
    }
}

class Am_Form_Brick_Paysystem extends Am_Form_Brick
{
    protected $labels = array(
        'Payment System',
        'Please choose a payment system',
    );
    protected $hide_if_one = false;
    protected $hideIfLoggedInPossible = self::HIDE_DONT;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Payment System');
        parent::__construct($id, $config);
    }

    function renderPaysys(Am_Paysystem_Description $p)
    {
        return sprintf('<span class="am-paysystem-title" id="am-paysystem-%s-title">%s</span> <span class="am-paysystem-desc" id="am-paysystem-%s-desc">%s</span>',
            $p->getId(), ___($p->getTitle()),
            $p->getId(), ___($p->getDescription()));
    }

    public function getPaysystems()
    {
        $psList = Am_Di::getInstance()->paysystemList->getAllPublic();
        $_psList = array();
        foreach ($psList as $k => $ps) {
            $ps->title = ___($ps->title);
            $ps->description = ___($ps->description);
            $_psList[$ps->getId()] = $ps;
        }

        $psEnabled = $this->getConfig('paysystems', array_keys($_psList));
        $event = new Am_Event(Am_Event::SIGNUP_FORM_GET_PAYSYSTEMS);
        $event->setReturn($psEnabled);
        Am_Di::getInstance()->hook->call($event);
        $psEnabled = $event->getReturn();

        //we want same order of paysystems as in $psEnabled
        $ret = array();
        foreach ($psEnabled as $psId) {
            if (isset($_psList[$psId]))
                $ret[] = $_psList[$psId];
        }

        return $ret;
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $paysystems = $this->getPaysystems();
        if ((count($paysystems) == 1) && $this->getConfig('hide_if_one')) {
            reset($paysystems);
            $form->addHidden('paysys_id')->setValue(current($paysystems)->getId())->toggleFrozen(true);
            return;
        }
        $psOptions = $psHide = $psIndex = array();
        foreach ($paysystems as $ps) {
            $psOptions[$ps->getId()] = $this->renderPaysys($ps);
            $psIndex[$ps->getId()] = $ps;
            $psHide[$ps->getId()] = Am_Di::getInstance()->plugins_payment->loadGet($ps->getId())->hideBricks();
        }
        $psHide = json_encode($psHide);
        if (count($paysystems) != 1) {
            $attrs = array('id' => 'paysys_id');
            $el0 = $el = $form->addAdvRadio('paysys_id', array('id' => 'paysys_id'),
                    array('intrinsic_validation' => false));
            $first = 0;
            foreach ($psOptions as $k => $v) {
                $attrs = array(
                    'data-recurring' => json_encode((bool)$psIndex[$k]->isRecurring())
                );
                if (!$first++ && Am_Di::getInstance()->request->isGet())
                    $attrs['checked'] = 'checked';
                $el->addOption($v, $k, $attrs);
            }
        } else {
            /** @todo display html here */
            reset($psOptions);
            $el = $form->addStatic('_paysys_id', array('id' => 'paysys_id'))->setContent(current($psOptions));
            $el->toggleFrozen(true);
            $el0 = $form->addHidden('paysys_id')->setValue(key($psOptions));
        }
        $el0->addRule('required', $this->___('Please choose a payment system'),
            // the following is added to avoid client validation if select is hidden
            null, HTML_QuickForm2_Rule::SERVER);
        $el0->addFilter('filterId');
        $el->setLabel($this->___('Payment System'));
        /**
         * hide payment system selection if:
         * - there are only free products in the form
         * - there are selected products, and all of them are free
         * - option product_psysytem is enabled and user choose product with assign payment system
         */
        $form->addScript()->setScript(<<<CUT
jQuery(document).ready(function($) {
    jQuery(":checkbox[name^='product_id'], select[name^='product_id'], :radio[name^='product_id'], input[type=hidden][name^='product_id']").change(function(){
        var count_free = 0, count_paid = 0, total_count_free = 0, total_count_paid = 0,
            total_count_paysys = 0, total_count_no_paysys = 0, count_paysys = 0, count_no_paysys = 0,
            count_recurring = 0;
        jQuery(":checkbox[name^='product_id']:checked, select[name^='product_id'] option:selected, :radio[name^='product_id']:checked, input[type=hidden][name^='product_id']").each(function(){
            if ((jQuery(this).data('first_price')>0) || (jQuery(this).data('second_price')>0))
                count_paid++;
            else
                count_free++;

            if (jQuery(this).data('second_price')>0)
                count_recurring++;

            if (jQuery(this).data('paysys'))
                count_paysys++;
            else
                count_no_paysys++;

        });

        jQuery(":checkbox[name^='product_id'], select[name^='product_id'] option, :radio[name^='product_id'], input[type=hidden][name^='product_id']").each(function(){
            if ((jQuery(this).data('first_price')>0) || (jQuery(this).data('second_price')>0))
                total_count_paid++;
            else
                total_count_free++;

            if (jQuery(this).data('paysys'))
                total_count_paysys++;
            else
                total_count_no_paysys++;
        });

        if (count_recurring) {
            jQuery('[type=radio][name=paysys_id]').each(function(){
                if (!$(this).data('recurring') && this.checked) {
                    $("[name='paysys_id'][data-recurring=true]:first").prop('checked', true);
                }
                $(this).closest('label').toggle($(this).data('recurring'));
            })
        } else {
            jQuery('[type=radio][name=paysys_id]').closest('label').show();
        }

        if ( ((count_free && !count_paid) ||
            (!total_count_paid && total_count_free) ||
            (!total_count_no_paysys && total_count_paysys) ||
            (count_paysys)) && (total_count_paid + total_count_free)>0)
        { // hide select
            jQuery("#row-paysys_id").hide().after("<input type='hidden' name='paysys_id' value='free' class='hidden-paysys_id' />");
        } else { // show select
            jQuery("#row-paysys_id").show();
            jQuery(".hidden-paysys_id").remove();
        }
    }).change();
    window.psHiddenBricks = [];
    jQuery("input[name='paysys_id']").change(function(){
        if (!this.checked) return;
        var val = jQuery(this).val();
        var hideBricks = $psHide;
        jQuery.each(window.psHiddenBricks, function(k,v){ jQuery('#row-'+v+'-0').show(); });
        window.psHiddenBricks = hideBricks[val];
        if (window.psHiddenBricks)
        {
            jQuery.each(window.psHiddenBricks, function(k,v){ jQuery('#row-'+v+'-0').hide(); });
        }
    }).change();
});
CUT
        );
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    public function initConfigForm(Am_Form $form)
    {
        Am_Di::getInstance()->plugins_payment->loadEnabled();
        $ps = $form->addSortableMagicSelect('paysystems')
            ->setLabel(___("Payment Options\n" .
                'if none selected, all enabled will be displayed'))
            ->loadOptions(Am_Di::getInstance()->paysystemList->getOptionsPublic());
        $form->addAdvCheckbox('hide_if_one')
            ->setLabel(___("Hide Select\n" .
                'if there is only one choice'));
    }
}

class Am_Form_Brick_Recaptcha extends Am_Form_Brick
{
    protected $name = 'reCAPTCHA';
    protected $labels = array(
        'Anti Spam',
        'Anti Spam check failed'
    );
    /** @var HTML_QuickForm2_Element_Static */
    protected $static;

    public function initConfigForm(Am_Form $form)
    {
        $form->addSelect('theme')
            ->setLabel(___("reCAPTCHA Theme"))
            ->loadOptions(array('light' => 'light', 'dark' => 'dark'));
        $form->addSelect('size')
            ->setLabel(___("reCAPTCHA Size"))
            ->loadOptions(array('normal' => 'normal', 'compact' => 'compact'));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $captcha = $form->addGroup()
                ->setLabel($this->___("Anti Spam"));
        $captcha->addRule('callback', $this->___('Anti Spam check failed'), array($this, 'validate'));
        $this->static = $captcha->addStatic('captcha')->setContent(Am_Di::getInstance()->recaptcha
            ->render($this->getConfig('theme'), $this->getConfig('size')));
    }

    public static function createAvailableBricks($className)
    {
        return Am_Recaptcha::isConfigured() ?
            parent::createAvailableBricks($className) :
            array();
    }

    public function validate()
    {
        $form = $this->static;
        while ($np = $form->getContainer())
            $form = $np;

        foreach ($form->getDataSources() as $ds) {
            if ($resp = $ds->getValue('g-recaptcha-response'))
                break;
        }

        $status = false;
        if ($resp)
            $status = Am_Di::getInstance()->recaptcha->validate($resp);
        return $status;
    }
}

class Am_Form_Brick_Coupon extends Am_Form_Brick
{
    protected $labels = array(
        'Enter coupon code',
        'No coupons found with such coupon code',
        'Please enter coupon code'
    );
    protected $hideIfLoggedInPossible = self::HIDE_DONT;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Coupon');
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox('required')
            ->setLabel(___('Required'));
        $form->addText('coupon_default')
            ->setLabel(___("Default Coupon\npre populate field with this code"));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $di = Am_Di::getInstance();

        $coupon = $form->addText('coupon', array('size' => 15))
            ->setLabel($this->___('Enter coupon code'));
        if ($this->getConfig('required')) {
            $coupon->addRule('required', $this->___('Please enter coupon code'));
        }
        $coupon->addRule('callback2', '--error--', array($this, 'validateCoupon'))
            ->addRule('remote', '--error--', array(
                'url' => $di->url('ajax', array('do'=>'check_coupon'), false),
            ));

        if (($code = $this->getConfig('coupon_default')) &&
            ($c = $di->couponTable->findFirstByCode($code)) &&
            !$c->validate($di->auth->getUserId())) {

            $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
                'coupon' => $code
            )));
        }
    }

    function validateCoupon($value)
    {
        if ($value == "")
            return null;
        $coupon = htmlentities($value);
        $coupon = Am_Di::getInstance()->couponTable->findFirstByCode($coupon);
        $msg = $coupon ? $coupon->validate(Am_Di::getInstance()->auth->getUserId()) : $this->___('No coupons found with such coupon code');
        return $msg === null ? null : $msg;
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }
}

class Am_Form_Brick_Field extends Am_Form_Brick
{
    const TYPE_NORMAL = 'normal';
    const TYPE_READONLY = 'disabled';
    const TYPE_HIDDEN = 'hidden';

    protected $field = null;

    static function createAvailableBricks($className)
    {
        $res = array();
        foreach (Am_Di::getInstance()->userTable->customFields()->getAll() as $field) {
            if (strpos($field->name, 'aff_') === 0)
                continue;

            // Do not create bricks for fields started with _
            if (strpos($field->name, '_') === 0)
                continue;

            $res[] = new self('field-' . $field->getName());
        }
        return $res;
    }

    public function __construct($id = null, $config = null)
    {
        $fieldName = str_replace('field-', '', $id);
        $this->field = Am_Di::getInstance()->userTable->customFields()->get($fieldName);
        // to make it fault-tolerant when customfield is deleted
        if (!$this->field)
            $this->field = new Am_CustomFieldText($fieldName, $fieldName);
        $this->labels = array($this->field->title, $this->field->description);
        parent::__construct($id, $config);
    }

    function getName()
    {
        return $this->field->title;
    }

    function insertBrick(HTML_QuickForm2_Container $form)
    {
        if (!$this->getConfig('skip_access_check') && isset($this->field->from_config) && $this->field->from_config) {
            $hasAccess = Am_Di::getInstance()->auth->getUserId() ?
                Am_Di::getInstance()->resourceAccessTable->userHasAccess(Am_Di::getInstance()->auth->getUser(), amstrtoint($this->field->name), Am_CustomField::ACCESS_TYPE) :
                Am_Di::getInstance()->resourceAccessTable->guestHasAccess(amstrtoint($this->field->name), Am_CustomField::ACCESS_TYPE);

            if (!$hasAccess)
                return;
        }

        $this->field->title = $this->___($this->field->title);
        $this->field->description = $this->___($this->field->description);
        if ($this->getConfig('validate_custom')) {
            $this->field->validateFunc = $this->getConfig('validate_func');
        }
        if ($this->getConfig('display_type', self::TYPE_NORMAL) == self::TYPE_READONLY) {
            $this->field->validateFunc = array();
        }
        switch ($this->getConfig('display_type', self::TYPE_NORMAL)) {
            case self::TYPE_HIDDEN :
                $v = $this->getConfig('value');
                $form->addHidden($this->field->getName())
                    ->setValue($v ? $v : @$this->field->default);
                break;
            case self::TYPE_READONLY :
                $el = $this->field->addToQF2($form);
                $el->toggleFrozen(true);
                break;
            case self::TYPE_NORMAL :
                $this->field->addToQF2($form, array(), array(),
                    $this->getConfig('cond_enabled') ?
                        HTML_QuickForm2_Rule::ONBLUR_CLIENT :
                        HTML_QuickForm2_Rule::CLIENT_SERVER);
                break;
            default:
                throw new Am_Exception_InternalError(sprintf('Unknown display type [%s] in %s::%s',
                        $this->getConfig('display_type', self::TYPE_NORMAL), __CLASS__, __METHOD__));
        }
        if ($this->getConfig('cond_enabled')) {
            $c_cond = $this->getConfig('cond_type') > 0 ? '==' : '!=';
            $c_field_val = json_encode($this->getConfig('cond_field_val'));
            $c_field_name = $this->getConfig('cond_field');
            $name = $this->field->name;
            $form->addScript()
                ->setScript(<<<CUT
jQuery('select[name=$c_field_name],\
    [type=radio][name=$c_field_name],\
    [type=checkbox][name="{$c_field_name}[]"],\
    [type=checkbox][name="{$c_field_name}"]').change(function(){

    var val;
    switch (this.type) {
        case 'radio':
            val = jQuery('[name=$c_field_name]:checked').val();
            break;
        case 'select':
        case 'select-one':
            val = jQuery('[name=$c_field_name]').val();
            break;
        case 'checkbox':
            var el = jQuery("[name='" + this.name + "']:checked");
            val = el.length > 1 ?
                el.filter("[value='" + $c_field_val + "']").val() :
                el.val();
            break;
    }
    val = val || 0;
    jQuery('[name=$name]').closest('.row').toggle(val $c_cond $c_field_val);
}).change();
CUT
                    );
        }
    }

    function getFieldName()
    {
        return $this->field->name;
    }

    public function initConfigForm(Am_Form $form)
    {
        $id = $this->field->name . '-display-type';
        $id_value = $this->field->name . '-value';

        $form->addSelect('display_type')
            ->setLabel(___('Display Type'))
            ->setId($id)
            ->loadOptions(array(
                self::TYPE_NORMAL => ___('Normal'),
                self::TYPE_READONLY => ___('Read-only'),
                self::TYPE_HIDDEN => ___('Hidden')
            ));
        $form->addText('value', array(
                'placeholder' => ___('Keep empty to use default value from field settings'),
                'class' => 'el-wide'
            ))
            ->setId($id_value)
            ->setLabel(___("Default Value for this field\n" .
                'hidden field will be populated with this value'));

        $type_hidden = self::TYPE_HIDDEN;
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#$id').change(function(){
        jQuery('#$id_value').closest('.row').toggle(jQuery(this).val() == '$type_hidden');
    }).change()
});
CUT
        );
        $id_validate_custom = $this->field->name . '-validate_custom';
        $id_validate_func = $this->field->name . '-validate_func';
        $form->addAdvCheckbox('validate_custom', array('id' => $id_validate_custom))
            ->setLabel(___("Use Custom Validation Settings\n" .
                'otherwise Validation settings from field definition is used'));

        $form->addMagicSelect('validate_func', array('id' => $id_validate_func))
            ->setLabel(___('Validation'))
            ->loadOptions(array(
                'required' => ___('Required Value'),
                'integer' => ___('Integer Value'),
                'numeric' => ___('Numeric Value'),
                'email' => ___('E-Mail Address'),
                'emails' => ___('List of E-Mail Address'),
                'url' => ___('URL'),
                'ip' => ___('IP Address')
            ));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('#$id_validate_custom').change(function(){
        jQuery('#$id_validate_func').closest('.row').toggle(this.checked);
    }).change()
});
CUT
        );

        $form->addAdvCheckbox('skip_access_check')
            ->setLabel(___("Do not check Access Permissions\n" .
                "for this field on this form (show it without any conditions)"));

        list($fields, $allOp) = $this->getEnumFieldOptions($this->field->name);
        if ($fields) {
            $allOp = json_encode($allOp);
            $current_val = json_encode($this->getConfig('cond_field_val'));
            $gr = $form->addGroup();
            $gr->setLabel(___('Conditional'));
            $gr->setSeparator(' ');
            $gr->addAdvCheckbox('cond_enabled');
            $gr->addSelect('cond_type')
                ->loadOptions(array(
                    '1' => ___('Show'),
                    '-1' => ___('Hide')
                ));
            $l_if = ___('if');
            $gr->addHtml()
                ->setHtml("<span> $l_if </span>");
            $gr->addSelect('cond_field')
                ->loadOptions($fields);
            $gr->addHtml()
                ->setHtml('<span> = </span>');
            $gr->addSelect('cond_field_val', null, array('intrinsic_validation' => false))
                ->loadOptions(array());

            $id = $form->getId();
            $gr->addScript()
                ->setScript(<<<CUT
jQuery(function(){
    $("#$id [name=cond_enabled]").change(function(){
        $(this).nextAll().toggle(this.checked);
    }).change();
    var current_val = $current_val;
    var opt = $allOp;
    $("#$id [name=cond_field]").change(function(){
        $("#$id [name=cond_field_val]").empty();
        var cOpt = opt[$(this).val()];
        for (var k in cOpt) {
            $("#$id [name=cond_field_val]").append(
                $('<option>').text(cOpt[k]).attr('value', k)
            );
        }
        if (current_val != undefined) {
            $("#$id [name=cond_field_val]").val(current_val);
            current_val = undefined;
        } else {
            $("#$id [name=cond_field_val]").val($("#$id [name=cond_field_val] option:first").attr('value'));
        }
    }).change();
});
CUT
                    );
        }
    }

    function getEnumFieldOptions($myName)
    {
        $fields = array();
        $options = array();
        foreach (Am_Di::getInstance()->userTable->customFields()->getAll() as $fd) {
            if ($myName == $fd->name) continue;

            if (in_array($fd->type, array('radio', 'select', 'checkbox', 'single_checkbox'))) {
               $fields[$fd->name] = $fd->title;
               $options[$fd->name] = $fd->type == 'single_checkbox' ?
                   array(1 => ___('Checked'), 0 => ___('Unchecked')) :
                   $fd->options;
            }
        }
        return array($fields, $options);
    }

    public function setConfigArray(array $config)
    {
        //backwards compatiability
        if (isset($config['disabled'])) {
            $config['display_type'] = $config['disabled'] ? self::TYPE_READONLY : self::TYPE_NORMAL;
            unset($config['disabled']);
        }
        if (!isset($config['display_type']))
            $config['display_type'] = self::TYPE_NORMAL;
        parent::setConfigArray($config);
    }
}

class Am_Form_Brick_Agreement extends Am_Form_Brick
{
    static $bricksAdded = 0;
    protected $labels = array(
        'User Agreement',
        'I have read and agree to the %s',
        'Please agree to %s',
    );

    function __construct($id = null, $config = null)
    {
        $this->name = ___('User Consent');
        parent::__construct($id, $config);
        $di = Am_Di::getInstance();
        $di->hook->add('gridSavedFormAfterSave', function(Am_Event_Grid $event) use ($di)
        {
            $form = $event->getGrid()->getRecord();
            $fields = $event->getGrid()->getRecord()->getFields();
            foreach ($fields as $k => $field)
            {
                if ($field['class'] == 'agreement' && @$field['config']['_agreement_text'])
                {
                    // Create agreement regord;
                    $agreement = $di->agreementRecord;
                    $agreement->type = sprintf('agreement-%s-%s', $form->type, $form->code ?: 'default');
                    $agreement->title = ___('Terms of Use');
                    $agreement->body = "<pre>" . $field['config']['_agreement_text'] . "</pre>";
                    $agreement->is_current = 1;
                    $agreement->comment = ___('Created from Forms Editor');
                    $agreement->save();

                    unset($fields[$k]['config']['_agreement_text']);
                    $fields[$k]['config']['agreement_type'] = $agreement->type;
                }
            }
            $form->setFields($fields);
            $form->save();
        });
    }

    function init()
    {
        $di = Am_Di::getInstance();
        $type = $this->getConfig('agreement_type');
        $di->hook->add(array(Am_Event::SIGNUP_USER_ADDED, Am_Event::SIGNUP_USER_UPDATED), function(Am_Event $e) use ($di, $type)
        {
            $user = $e->getUser();
            $vars = $e->getVars();
            $type = is_array($type) ? $type : array($type);
            $given_consent = array();
            if (!empty($vars['_i_agree']) && is_array($vars['_i_agree']))
            {
                foreach ($vars['_i_agree'] as $value)
                {
                    $v = json_decode($value, true);
                    $given_consent = array_merge($given_consent, $v);
                }
            }
            if (!empty($given_consent))
            {
                foreach ($type as $t)
                {
                    if (in_array($t, $given_consent))
                    {
                        $di->userConsentTable->recordConsent(
                            $user, $t, $di->request->getClientIp(), sprintf("Signup Form: %s", $di->surl($e->getSavedForm()->getUrl())));
                    }
                }
            }
        });
    }

    function insertBrick(HTML_QuickForm2_Container $form)
    {
        $di = Am_Di::getInstance();

        $type = $this->getConfig('agreement_type');
        $type = is_array($type) ? $type : array($type);

        foreach ($type as $k => $v)
        {
            if (!$this->getConfig('agree_invoice') && ($user = $di->auth->getUser()) && $di->userConsentTable->hasConsent($user, $v))
                unset($type[$k]);
        }

        if (empty($type))
            return;

        $el_name = "_i_agree[{$form->getId()}-" . (self::$bricksAdded++) . "]";

        $agreements = $this->getAgreements();
        $labels = array();
        if ($this->getConfig('do_not_show_agreement_text') || $this->getConfig('do_not_show_caption'))
        {
            $conteiner = $form;
        }
        else
        {
            $conteiner = $form->addFieldset()
                ->setId('fieldset-agreement')
                ->setLabel($this->getTitles($agreements));
        }

        foreach ($agreements as $agreement)
        {

            if (!$this->getConfig('do_not_show_agreement_text'))
            {
                if ($this->getConfig("is_popup"))
                {
                    $form->addEpilog('<div class="agreement" style="display:none" id="' . $agreement->type . '">' . $agreement->body . '</div>');
                }
                else
                {
                    $agr = $conteiner->addStatic('_agreement', array('class' => 'no-label'));
                    $agr->setContent('<div class="agreement">' . $agreement->body . '</div>');
                }
            }
            $header = json_encode($agreement->title);

            if (!empty($agreement->url))
            {
                $url = $agreement->url;
                if ($this->getConfig("is_popup")) {
                    $attrs = Am_Di::getInstance()->view->attrs(array(
                        'href' => $url,
                        'class' => 'ajax-link',
                        'data-popup-width' => '400',
                        'data-popup-height' => '400',
                        'title' => $agreement->title,
                    ));
                } else {
                    $attrs = Am_Di::getInstance()->view->attrs(array(
                        'href' => $url,
                        'title' => $agreement->title,
                        'target' => '_blank'
                    ));
                }
            }
            else
            {
                $attrs = Am_Di::getInstance()->view->attrs(array(
                    'href' => "javascript:",
                    'class' => 'local-link',
                    'onclick' => 'jQuery("#' . $agreement->type . '").amPopup({width:400, title:' . $header . '});'
                ));
            }
            $labels[] = ($this->getConfig('is_popup') || !empty($agreement->url)) ? "<a {$attrs}>" . $agreement->title . "</a>" : $agreement->title;
        }


        $data = array();
        $label = $this->___('I have read and agree to the %s', implode(", ", $labels));

        if ($this->getConfig('no_label'))
        {
            $data['content'] = $label;
        }

        $checkbox = $conteiner->addAdvCheckbox($el_name, array('value' => json_encode($type)), $data);

        if (!$this->getConfig('no_label'))
        {
            $checkbox->setLabel($label);
        }

        $checkbox->addRule('required', $this->___('Please agree to %s', $this->getTitles($agreements)));

        if ($this->getConfig('checked'))
        {
            $checkbox->setAttribute('checked');
        }
    }

    function getTitles($agreements)
    {
        return implode(", ", array_map(function($agreement)
            {
                return $agreement->title;
            }, $agreements));
    }

    function getAgreements()
    {
        $type = $this->getConfig('agreement_type');
        $type = is_array($type) ? $type : array($type);
        $ret = array();
        foreach ($type as $t)
        {
            $ret[] = Am_Di::getInstance()->agreementTable->getCurrentByType($t);
        }
        return array_filter($ret);
    }

    function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox("do_not_show_agreement_text")
            ->setLabel(___("Hide Agreement Text\n" .
                    'display only tick box'))
            ->setId('do-not-show-agreement-text');
        $form->addAdvCheckbox("do_not_show_caption")
            ->setLabel(___('Hide Caption'))
            ->setAttribute('rel', 'agreement-text');

        $gr = $form->addGroup()->setLabel(___('Agreement Document'));
        if (!Am_Di::getInstance()->agreementTable->getTypeOptions()) {
            $gr->addTextarea("_agreement_text", array('rows' => 20, 'class' => 'el-wide'));
            $gr->addHidden('agreement_type');
        } else {
            $gr->addMagicSelect("agreement_type")
                ->loadOptions(Am_Di::getInstance()->agreementTable->getTypeOptions());
        }

        $url = Am_Di::getInstance()->url('admin-agreement');

        $linkTitle = ___('Create New Document / Manage Documents');

        $gr->addHtml()->setHtml(<<<CUT
            <br/>
            <a href="{$url}" target='_top'>{$linkTitle}</a>
CUT
        );

        $form->addAdvCheckbox("is_popup")
            ->setLabel(___('Display Agreement in Popup'))
            ->setAttribute('rel', 'agreement-text');

        $form->addScript()
            ->setScript(<<<CUT
jQuery('#do-not-show-agreement-text').change(function(){
    jQuery('[rel=agreement-text]').closest('.row').toggle(!this.checked);
}).change();
CUT
        );
        $form->addAdvCheckbox('no_label')
            ->setLabel(___("Move Label to Tickbox"));
        $form->addAdvCheckbox('checked')
            ->setLabel(___("Checked by Default"));
        $form->addAdvCheckbox("agree_invoice")
            ->setLabel(___("User should agree each time when use form\n" .
                    "agreement state will be recorded to invoice instead of user"));
    }

    function isMultiple()
    {
        return true;
    }
}

class Am_Form_Brick_PageSeparator extends Am_Form_Brick
{
    protected $labels = array(
        'title',
        'back',
        'next',
    );
    protected $hideIfLoggedInPossible = self::HIDE_DONT;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Form Page Break');
        parent::__construct($id, $config);
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        // nop;
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return (bool)$form->isMultiPage();
    }

    public function isMultiple()
    {
        return true;
    }
}

class Am_Form_Brick_UserGroup extends Am_Form_Brick
{
    protected $hideIfLoggedInPossible = self::HIDE_DONT;

    public function init()
    {
        Am_Di::getInstance()->hook->add(Am_Event::SIGNUP_USER_ADDED, array($this, 'assignGroups'));
        Am_Di::getInstance()->hook->add(Am_Event::SIGNUP_USER_UPDATED, array($this, 'assignGroups'));
    }

    public function assignGroups(Am_Event $event)
    {
        /* @var $user User */
        $user = $event->getUser();

        $existing = $user->getGroups();
        $new = $this->getConfig('groups', array());
        $user->setGroups(array_unique(array_merge($existing, $new)));
    }

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Assign User Groups (HIDDEN)');
        parent::__construct($id, $config);
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addMagicSelect('groups')
            ->loadOptions(Am_Di::getInstance()->userGroupTable->getOptions())
            ->setLabel(___('Add user to these groups'));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        // nothing to do.
    }
}

class Am_Form_Brick_UserGroups extends Am_Form_Brick
{
    protected $hideIfLoggedInPossible = self::HIDE_DONT;
    protected $labels = array('User Groups');

    public function init()
    {
        Am_Di::getInstance()->hook->add(array(
            Am_Event::SIGNUP_USER_ADDED,
            Am_Event::SIGNUP_USER_UPDATED,
            Am_Event::PROFILE_USER_UPDATED
        ), array($this, 'assignGroups'));
    }

    public function assignGroups(Am_Event $event)
    {
        /* @var $user User */
        $user = $event->getUser();
        $vars = $event->getVars();

        $existing = $user->getGroups();
        $scope = $this->getConfig('scope') ?: array_keys(Am_Di::getInstance()->userGroupTable->getOptions());

        $existing = array_diff($existing, $scope);
        $user->setGroups(array_unique(array_merge($existing, $vars['_user_groups'])));
    }

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('User Groups');
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addMagicSelect('scope')
            ->loadOptions(Am_Di::getInstance()->userGroupTable->getOptions())
            ->setLabel(___("Scope\nlist of groups that user can manage, keep empty to allow manage all groups"));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $op = Am_Di::getInstance()->db->selectCol(<<<CUT
            SELECT title, user_group_id AS ARRAY_KEY FROM ?_user_group
                WHERE 1=1 {AND user_group_id IN (?a)}
CUT
            , $this->getConfig('scope') ?: DBSIMPLE_SKIP);

        if ($op) {
            $form->addMagicSelect('_user_groups')
                ->loadOptions($op)
                ->setLabel($this->___('User Groups'));

            if ($user = Am_Di::getInstance()->auth->getUser()) {
                $form->addDataSource(new HTML_QuickForm2_DataSource_Array(array(
                        '_user_groups' => $user->getGroups()
                    )));
            }
        }
    }
}

class Am_Form_Brick_ManualAccess extends Am_Form_Brick
{
    protected $hideIfLoggedInPossible = self::HIDE_DONT;

    public function init()
    {
        Am_Di::getInstance()->hook->add(Am_Event::SIGNUP_USER_ADDED, array($this, 'addAccess'));
    }

    public function addAccess(Am_Event $event)
    {
        /* @var $user User */
        $user = $event->getUser();
        $product_ids = $this->getConfig('product_ids');
        if (!$product_ids) return;
        foreach ($product_ids as $id) {
            $product = Am_Di::getInstance()->productTable->load($id, false);
            if (!$product) continue;

            //calucalet access dates
            $invoice = Am_Di::getInstance()->invoiceRecord;
            $invoice->setUser($user);
            $invoice->add($product);

            $begin_date = $product->calculateStartDate(Am_Di::getInstance()->sqlDate, $invoice);
            $p = new Am_Period($product->getBillingPlan()->first_period);
            $expire_date = $p->addTo($begin_date);

            $access = Am_Di::getInstance()->accessRecord;
            $access->setForInsert(array(
                'user_id' => $user->pk(),
                'product_id' => $product->pk(),
                'begin_date' => $begin_date,
                'expire_date' => $expire_date,
                'qty' => 1
            ));
            $access->insert();
            Am_Di::getInstance()->emailTemplateTable->sendZeroAutoresponders($user, $access);
        }
    }

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Add Subscription Before Payment (HIDDEN)');
        parent::__construct($id, $config);
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addMagicSelect('product_ids')
            ->loadOptions(Am_Di::getInstance()->productTable->getOptions())
            ->setLabel(___(
                "Add Subscription to the following products\n" .
                "right after signup form has been submitted, " .
                "subscription will be added only for new users"));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        // nothing to do.
    }
}

class Am_Form_Brick_Fieldset extends Am_Form_Brick
{
    protected $labels = array(
        'Fieldset title'
    );

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Fieldset');
        parent::__construct($id, $config);
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $fieldSet = $form->addElement('fieldset', 'fieldset')->setLabel($this->___('Fieldset title'));
    }

    public function isMultiple()
    {
        return true;
    }
}

class Am_Form_Brick_RandomQuestions extends Am_Form_Brick
{
    protected $labels = array(
        'Please answer above question',
        'Your answer is wrong'
    );

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Random Questions');
        parent::__construct($id, $config);
    }

    public function isMultiple()
    {
        return false;
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        if (!$this->getConfig('questions'))
            return;
        $questions = array();
        foreach (explode(PHP_EOL, $this->getConfig('questions')) as $line) {
            $line = explode('|', $line);
            $questions[] = array_shift($line);
        }
        $q_id = array_rand($questions);
        $question = $form->addText('question')
            ->setLabel($questions[$q_id] . "\n" . $this->___('Please answer above question'));
        $question->addRule('callback', $this->___('Your answer is wrong'), array($this, 'validate'));
        $form->addHidden('q_id')->setValue($q_id)->toggleFrozen(true);
        //setValue does not work right second time
        $_POST['q_id_sent'] = @$_POST['q_id'];
        $_POST['q_id'] = $q_id;
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addTextarea('questions', array('rows' => 10, 'class'=>'el-wide'))
            ->setLabel(___("Questions with possible answers\n" .
                "one question per line\n" .
                "question and answers should be\n" .
                "separated by pipe, for example\n" .
                "Question1?|Answer1|Answer2|Answer3\n" .
                "Question2?|Answer1|Answer2\n" .
                "register of answers does not matter"));
    }

    public function validate($answer)
    {
        if (!$answer)
            return false;
        $lines = explode(PHP_EOL, $this->getConfig('questions'));
        $line = $lines[(isset($_POST['q_id_sent']) ? $_POST['q_id_sent'] : $_POST['q_id'])];
        $q_ = explode('|', strtolower(trim($line)));
        array_shift($q_);
        if (@in_array(strtolower($answer), $q_))
            return true;
        else
            return false;
    }
}

class Am_Form_Brick_Unsubscribe extends Am_Form_Brick
{
    protected $labels = array(
        'Unsubscribe from all e-mail messages'
    );

    public function init()
    {
        Am_Di::getInstance()->hook->add(Am_Event::PROFILE_USER_UPDATED, array($this, 'triggerEvent'));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $form->addAdvCheckbox('unsubscribed')
            ->setLabel($this->___('Unsubscribe from all e-mail messages'));
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Profile;
    }

    public function triggerEvent(Am_Event $e)
    {
        $oldUser = $e->getOldUser();
        $user = $e->getUser();
        if ($oldUser->unsubscribed != $user->unsubscribed) {
            Am_Di::getInstance()->hook->call(Am_Event::USER_UNSUBSCRIBED_CHANGED,
                array('user'=>$user, 'unsubscribed' => $user->unsubscribed));
        }
    }
}

class Am_Form_Brick_InvoiceSummary extends Am_Form_Brick
{
    protected $hideIfLoggedInPossible = self::HIDE_DONT;

    public function __construct($id = null, $config = null)
    {
        $this->name = ___('Invoice Summary');
        parent::__construct($id, $config);
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addSelect('position', array('class' => 'invoice-summary-position'))
            ->loadOptions(array(
                'above' => ___('Above Form'),
                'below' => ___('Below Form'),
                'brick' => ___('Brick Position'),
                'custom' => ___('Custom Element')
            ))->setLabel('Position');
        $form->addText('selector', array('placeholder' => '#invoice-summary'))
            ->setLabel(___('CSS Selector for conteiner'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(document).on('change', '.invoice-summary-position', function() {
   jQuery(this).closest('form').find('[name=selector]').closest('.row').toggle(jQuery(this).val() == 'custom');
});
jQuery(function(){
    jQuery('.invoice-summary-position').change();
})
CUT
            );
        $form->addAdvCheckbox('show_terms')
            ->setLabel(___('Display Subscription Terms'));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        $selector = '#invoice-summary';

        switch ($this->getConfig('position', 'above')) {
            case 'above' :
                $form->addProlog('<div id="invoice-summary"></div>');
                break;
            case 'below' :
                $form->addEpilog('<div id="invoice-summary"></div>');
                break;
            case 'brick' :
                $form->addHtml(null, array('class'=>'row-wide'))
                    ->setHtml('<div id="invoice-summary"></div>');
                break;
            default:
                $selector = $this->getConfig('selector', '#invoice-summary') ?: '#invoice-summary';
        }
        $form->addHidden('_show_terms')
            ->setValue($this->getConfig('show_terms') ? 1 : 0);
        $url = Am_Di::getInstance()->url('ajax/invoice-summary', false);
        $form->addScript()
            ->setScript(<<<CUT
var invoiceSummaryNeedRefresh = true;

jQuery(document).ready(function($) {
    var updateSummary = function() {
        if (invoiceSummaryNeedRefresh) {
            invoiceSummaryNeedRefresh = false;
            jQuery.get('$url', jQuery('.am-signup-form').serializeArray(), function(r){
                if (jQuery('$selector').data('summary-hash') !== r.hash) {
                    jQuery('$selector').data('summary-hash', r.hash);
                    jQuery('$selector').html(r.html);
                    jQuery('$selector').fadeTo('slow', 0.1).fadeTo('slow', 1.0);
                }
            });
        }
    };
    updateSummary();
    setInterval(function(){updateSummary()},1000);
    jQuery(".am-signup-form").on('change', ":checkbox[name^='product_id'], select[name^='product_id'], :radio[name^='product_id'], input[type=text][name^='product_id'], input[type=hidden][name^='product_id'], [name=paysys_id], [name=coupon], :checkbox[name^='productOption'], select[name^='productOption'], :radio[name^='productOption'], input[type=text][name^='productOption'], textarea[name^='productOption'], select[name='country'], input[name='tax_id'], select[name='state'], input[name='zip']", function(){
        invoiceSummaryNeedRefresh = true;
    });
});
CUT
                );
    }

    public function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $form instanceof Am_Form_Signup;
    }

    public function isMultiple()
    {
        return false;
    }
}

class Am_Form_Brick_VatId extends Am_Form_Brick
{
    protected $labels = array(
        'VAT Settings are incorrect - no Vat Id configured',
        'Invalid VAT Id, please try again',
        'Cannot validate VAT Id, please try again',
        'Invalid EU VAT Id format',
        'EU VAT Id (optional)',
        'Please enter EU VAT Id'
    );

    protected function isVatEnabled()
    {
        foreach (Am_Di::getInstance()->plugins_tax->getAllEnabled() as $_) {
            if ($_ instanceof Am_Invoice_Tax_Vat2015) {
                return true;
            }
        }

        return false;
    }

    function isAcceptableForForm(Am_Form_Bricked $form)
    {
        return $this->isVatEnabled();
    }

    public function initConfigForm(Am_Form $form)
    {
        $form->addAdvCheckbox('dont_validate')->setLabel(___('Disable online VAT Id Validation'));
        $form->addAdvCheckbox('required')->setLabel(___('Required'));
    }

    public function insertBrick(HTML_QuickForm2_Container $form)
    {
        if (!$this->isVatEnabled())
            return;

        $el = $form->addText('tax_id')->setLabel($this->___("EU VAT Id (optional)"))
            ->addFilter(function($value) {
                return str_replace(' ', '', $value);
            })
            ->addRule('regex', $this->___('Invalid EU VAT Id format'), '/^[A-Za-z]{2}[a-zA-Z0-9\s]+$/');
        if (!$this->getConfig('dont_validate'))
            $el->addRule('callback2', '-error-', array($this, 'validate'));
        if ($this->getConfig('required'))
            $el->addRule('required', $this->___("Please enter EU VAT Id"));
    }

    public function validate($id)
    {
        if (!$id) return; //skip validation in case of VAT was not supplied

        $plugins = Am_Di::getInstance()->plugins_tax->getAllEnabled();
        $me = is_array($plugins) ? $plugins[0]->getConfig('my_id') : "";
        if (!$me) return $this->___('VAT Settings are incorrect - no Vat Id configured');

        $cacheKey = 'vc_' . preg_replace('/[^A-Z0-9a-z_]/', '_', $me) . '_' .
            preg_replace('/[^A-Z0-9a-z_]/', '_', $id);

        if (($ret = Am_Di::getInstance()->cache->load($cacheKey)) !== false) {
            return $ret === 1 ? null : $this->___('Invalid VAT Id, please try again');
        }

        $country = strtoupper(substr($id, 0, 2));
        $number = substr($id, 2);
        $request = new Am_HttpRequest('http://ec.europa.eu/taxation_customs/vies/services/checkVatService', Am_HttpRequest::METHOD_POST);
        $request->setBody(<<<CUT
<s11:Envelope xmlns:s11='http://schemas.xmlsoap.org/soap/envelope/'>
<s11:Body>
  <tns1:checkVat xmlns:tns1='urn:ec.europa.eu:taxud:vies:services:checkVat:types'>
    <tns1:countryCode>{$country}</tns1:countryCode>
    <tns1:vatNumber>{$number}</tns1:vatNumber>
  </tns1:checkVat>
</s11:Body>
</s11:Envelope>
CUT
            );

        $resp = $request->send();

        if ($resp->getStatus() != 200) {
            return $this->___("Cannot validate VAT Id, please try again");
        }

        $xml = simplexml_load_string($resp->getBody());

        if ($xml === false) {
            return $this->___("Cannot validate VAT Id, please try again");
        }

        if (($res = $xml->xpath("//*[local-name()='checkVatResponse']/*[local-name()='valid']"))
            && strval($res[0]) == 'true') {

            Am_Di::getInstance()->cache->save(1, $cacheKey);
            return;
        }

        Am_Di::getInstance()->cache->save(0, $cacheKey);
        return $this->___('Invalid VAT Id, please try again');
    }
}
