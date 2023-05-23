<?php

/*
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Configuration
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class AdminSetupController extends Am_Mvc_Controller
{
    static protected $instance;
    protected $forms = array();

    /** @var string */
    protected $p;

    /** @var Am_Form_Setup */
    protected $form;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_SETUP);
    }

    function getConfigValues()
    {
        $c = new Am_Config;
        $c->read();
        $ret = $this->_getConfigValues('', $c->getArray());
        // strip keys encoded for form
        foreach ($ret as $k => $v)
        {
            if (preg_match('/___/', $k))
                unset($ret[$k]);
        }
        return $ret;
    }

    function _getConfigValues($prefix, $node)
    {
        $ret = array();
        foreach ($node as $k => $v)
        {
            if (!is_array($v) || (isset($v[0]) || isset($v[1]))) {
                $ret[$prefix . $k] = $v;
            } else {
                $ret = array_merge_recursive($ret, $this->_getConfigValues("$prefix$k.", $v));
            }
        }
        return $ret;
    }

    function indexAction()
    {
        $this->_request->setParam('p', 'global');
        return $this->displayAction();
    }

    function displayAction()
    {
        $this->setActiveMenu('setup');

        $this->p = filterId($this->_request->getParam('p'));
        if ($this->p === 'ajax')
            return $this->ajaxAction();
        $this->initSetupForms();
        $this->form = $this->getForm($this->p, false);
        $this->form->prepare();
        if ($this->form->isSubmitted())
        {
            $this->form->setDataSources(array($this->_request));
            if ($this->form->validate() && $this->form->saveConfig())
            {
                $this->getDi()->adminLogTable->log(sprintf('Update Configuration [%s]', $this->form->getPageId()));
                $this->redirectHtml($this->getUrl(null, $this->p), ___('Config values updated...'));
                return;
            }
        } else {
            $cfg = $this->getConfigValues();
            unset($cfg['p']);
            unset($cfg['page']);
            unset($cfg['hp_c']);
            $this->form->setDataSources(array(
                new HTML_QuickForm2_DataSource_Array($cfg),
                new HTML_QuickForm2_DataSource_Array($this->form->getDefaults()),
            ));
        }
        $this->view->assign('p', $this->p);
        $this->view->assign('pages', $this->renderPages());
        $this->form->replaceDotInNames();
        $this->view->assign('pageObj', $this->form);
        $this->view->assign('form', $this->form);
        $this->view->display('admin/setup.phtml');
    }

    public function ajaxAction()
    {
        $this->p = filterId($this->_request->getParam('_p'));
        $this->initSetupForms();
        $this->form = $this->getForm($this->p, false);
        $this->form->prepare();
        $this->form->setDataSources(array($this->_request));
        $this->form->ajaxAction($this->getRequest());
    }

    function renderPages()
    {
        $chunks = array_chunk($this->forms, 7, true);
        $out = "";
        foreach ($chunks as $chunk)
        {
            $out .= "<tr>";
            $i = 0;
            foreach ($chunk as $k => $page)
            {
                $out .= $this->renderPage($page);
                $i++;
            }
            if ($i < 7)
                $out .= sprintf("<td colspan='%d' class='notself'></td>\n", 8 - $i);
            $out .= "</tr>\n";
        }
        return $out;
    }

    function renderPage(Am_Form_Setup $form)
    {
        $cl = ($form->getPageId() == $this->p) ? 'sel' : 'notsel';
        return
            sprintf('<td class="%s" id="setup-form-%s"><a href="%s" title="%s"><span>%s</span></a></td>' . "\n",
                $cl, $form->getPageId(), $this->getUrl(null, $form->getPageId()), $form->getComment(), $form->getTitle());
    }

    function initSetupForms()
    {
        @class_exists('Am_Form_Setup_Standard', true);
        foreach ($this->getDi()->modules->getEnabled() as $module)
        {
            $fn = AM_APPLICATION_PATH . '/' . $module . '/library/SetupForms.php';
            if (!file_exists($fn))
                continue;
            include_once $fn;
        }

        foreach (get_declared_classes() as $class)
        {
            if (is_subclass_of($class, 'Am_Form_Setup'))
            {
                $rc = new ReflectionClass($class);
                if ($rc->isAbstract())
                    continue;
                if ($class == 'Am_Form_Setup_Theme')
                    continue;
                $this->addForm(new $class);
            }
        }

        foreach ($this->getDi()->plugins as $k => $mgr)
        {
            $mgr->loadEnabled()->getAllEnabled();
        }

        $event = new Am_Event_SetupForms($this);
        $this->getDi()->hook->call($event);
    }

    function addForm(Am_Form_Setup $form)
    {
        $id = $form->getPageId();
        if (isset($this->forms[$id]))
            throw new Am_Exception_InternalError("Form [$id] is already exists");
        $this->forms[$id] = $form;
        return $this;
    }

    /** @return Am_Form_Setup */
    function getFormByTitle($title)
    {
        foreach ($this->forms as $f)
        {
            if ($f->getTitle() == $title)
                return $f;
        }
        $form = new Am_Form_Setup(strtolower(filterId($title)));
        $form->setTitle($title);
        $this->addForm($form);
        return $form;
    }

    function getForm($id, $autoCreate = true)
    {
        if (isset($this->forms[$id]))
            return $this->forms[$id];
        if (!$autoCreate)
            throw new Am_Exception_InputError("Form [$id] does not exists");
        $form = new Am_Form_Setup($id);
        $this->addForm($form);
        return $form;
    }

    static public function getInstance()
    {
        if (!self::$instance)
            self::$instance = new self;
        return self::$instance;
    }

    public function preDispatch()
    {
        $vars = $this->_request->toArray();
        foreach ($vars as $k => $v)
        {
            $kk = Am_Form_Setup::name2dots($k);
            if ($kk != $k)
            {
                unset($vars[$k]);
                $vars[$kk] = $v;
            }
        }
        $this->_request->setParams($vars);
    }
}