<?php

class AdminPluginMakerController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }

    public function getSession()
    {
        if (empty($this->session))
            $this->session = $this->getDi()->session->ns('amember_plugin_maker');
        return $this->session;
    }

    function indexAction()
    {
        $controller = new HTML_QuickForm2_Controller('plugin_maker');
        $controller->addPage(new Am_Form_Controller_Page_PluginMaker_Mysql(new Am_Form('mysql')));
        $controller->addPage(new Am_Form_Controller_Page_PluginMaker_Plugin(new Am_Form('plugin')));
        $controller->addPage(new Am_Form_Controller_Page_PluginMaker_Tables(new Am_Form('tables')));
        $controller->addPage(new Am_Form_Controller_Page_PluginMaker_Columns(new Am_Form('columns')));
        $controller->addPage(new Am_Form_Controller_Page_PluginMaker_Display(new Am_Form('display')));

        $controller->addHandler('next', new HTML_QuickForm2_Controller_Action_Next());
        $controller->addHandler('back', new HTML_QuickForm2_Controller_Action_Back());
        $controller->addHandler('jump', new HTML_QuickForm2_Controller_Action_Jump());

        ob_start();
        $controller->run();
        $this->view->content = ob_get_clean();

        $this->view->title = "Integration Plugin Maker";
        $this->view->display('admin/layout.phtml');
    }

    function getMysqlInfo()
    {
        $this->getSession()->unsetAll();
        $form = $this->createMysqlForm();
        if (!$form->isSubmitted() || !$form->validate())
        {
            $this->view->content = (string)$form;
        } else {
            $this->getSession()->info = $form->getValue();
            return $this->selectTables();
        }
    }

    function selectColumns()
    {
        $tables = $this->getSession()->tables;
        $db = $this->getDb();
        $db->setPrefix($tables['prefix']);

        // user table
        if (!$tables['user'])
            throw new Am_Exception_InputError("No user table selected");
        $cols = $this->getDi()->db->selectCol("SHOW COLUMNS FROM ?_{$tables['user']}");
        $out = "<h1>Select Tables Field Mapping</h1>";
        $out .= "<h2>User Table</h2>";
        $out .= "<table><tr>";
        foreach ($cols as $c)
        {
            $out .= "<th>$c</th>";
        }
        $out .= "</tr>";
        foreach ($db->select("SELECT * FROM ?_{$tables['user']}") as $r)
        {
            $out .= "<tr>";
            foreach ($r as $c => $v)
                $out .= "<td>" . Am_Html::escape($v) . "</td>\n";
            $out .= "</tr>\n";
        }
        $out .= "</table>\n";
    }

    function selectTables()
    {
        if ($this->_getParam('tables') == 1)
        {
            $tables = array(
                'user'  => $this->_getParam('user'),
                'group' => $this->_getParam('group'),
                'usergroup' => $this->_getParam('usergroup'),
                'prefix' => $this->_getParam('prefix')
            );
            $tables['user'] = preg_replace('/^' . preg_quote($tables['prefix']) . '/', '', $tables['user']);
            $tables['group'] = preg_replace('/^' . preg_quote($tables['prefix']) . '/', '', $tables['group']);
            $tables['usergroup'] = preg_replace('/^' . preg_quote($tables['prefix']) . '/', '', $tables['usergroup']);
            $this->getSession()->tables = $tables;
            return $this->selectColumns();
        }



        $out .= "<br /><input type='hidden' name='tables' value='1'><input type='submit' />";
        $out .= "</form>\n";
        $this->view->content = $out;
    }

    function getDb(array $info = null)
    {
        if (empty($info))
            $info = $this->getSession()->info;
        if (empty($this->db))
            $this->db = Am_Db::connect($info);
        return $this->db;
    }
}

class Am_Form_Controller_Page_PluginMaker_Display extends HTML_QuickForm2_Controller_Page
{
    protected function populateForm()
    {
        $info = $this->getController()->getValue();
        foreach ($info as $k=>$v) if ($k[0] == '_') unset($info[$k]);
        if (@$info['table_prefix'])
            foreach ($info['table'] as & $v)
                $v = preg_replace('/^'.preg_quote($info['table_prefix']).'/', '', $v);

        $userFields = array();
        $guessFields = array();
        foreach ($info['field']['user'] as $fn => $a)
        {
            if (empty($a['field'])) continue;
            $guessFields[] = $fn;
            $expr = $a['field'];
            if ($expr == 'string')
                $expr = var_export(':'.$a['text'], true);
            elseif ($expr == 'expr')
                $expr = '"!'.$a['text'].'"';
            $userFields[] = "array($expr, '$fn'),";
        }
        $view = new Am_View();
        $view->assign($info);
        $view->assign('guessFields', $guessFields);
        $view->assign('userFields', $userFields);

        $cnt = "<h2>Plugin Template Generated</h2>";
        $cnt .= "<p>Put the following code to <i>application/default/plugins/protect/{$info['plugin']}.php</i> and start customization</p>";
        $cnt .= "<div style='background-color: #eee; width: 100%; height: 300px; overflow-x: scroll; overflow-y: scroll;'>\n";
        $cnt .= highlight_string($view->render('admin/plugin-template.phtml'), true);
        $cnt .= "</div>";

        $el = $this->form->addStatic(null, array('class'=>'no-label'))->setContent($cnt);

        $gr = $this->form->addGroup(null, array('class' => 'no-label'));
        $gr->setSeparator(' ');
        $gr->addHtml()->setHtml('<div style="text-align:center">');
        $gr->addSubmit($this->getButtonName('back'), array('value'=>'Back'));

        $page = $this;
        while ($p = $page->getController()->previousPage($page))
            $page = $p;
        $gr->addSubmit($page->getButtonName('jump'), array('value'=>'Start Over'));
        $gr->addHtml()->setHtml('</div>');
    }
}

class Am_Form_Controller_Page_PluginMaker_Mysql extends HTML_QuickForm2_Controller_Page
{
    protected function populateForm()
    {
        $this->getController()->destroySessionContainer();
        $this->form->addStatic()->setContent('Enter third-party plugin database connection details');
        $this->form->addText('host')->setLabel('MySQL Hostname')->addRule('required');
        $this->form->addText('port')->setLabel('MySQL Port');
        $this->form->addText('user')->setLabel('MySQL Username')->addRule('required');
        $this->form->addPassword('pass')->setLabel('MySQL Password');
        $this->form->addText('db')->setLabel('MySQL Database')->addRule('required');
        $this->form->addSubmit($this->getButtonName('next'), array('value'=>'Next'));
        $this->form->addRule('callback2', '-', array($this, 'validateMysql'));
        $this->form->addDataSource(new HTML_QuickForm2_DataSource_Array(array('port' => 3606)));
    }
    function validateMysql(array $info)
    {
        try {
            Am_Db::connect($info);
        } catch (Am_Exception_Db $e) {
            return "Wrong info: " . $e->getMessage();
        }
    }
}

class Am_Form_Controller_Page_PluginMaker_Plugin extends HTML_QuickForm2_Controller_Page
{
    protected function populateForm()
    {
        $this->form->addText('plugin')->setLabel('Plugin Id')
            ->addRule('required')
            ->addRule('regex', 'Must match regex /[a-z][a-z0-9-]+/', '/^[a-z][a-z0-9-]+$/');

        $sel = $this->form->addSelect('password_type')->setLabel('Password Type');
        $sel->addOption('-- Select --', '');
        $sel->addRule('required');
        $class = new ReflectionClass('SavedPassTable');
        foreach ($class->getConstants() as $k => $v)
            if (strpos($k, 'PASSWORD')===0)
                $sel->addOption('SavedPassTable::'.$k . ' ('.$v.')', 'SavedPassTable::'.$k);
        $sel->addOption('Custom (define method)', 'custom');

        $sel = $this->form->addSelect('group_mode')->setLabel('Group Mode');
        $sel->addOption('-- Select --', '');
        $sel->addRule('required');
        $class = new ReflectionClass('Am_Protect_Databased');
        foreach ($class->getConstants() as $k => $v)
            if (strpos($k, 'GROUP_')===0)
                $sel->addOption($class->getName().'::'.$k, $class->getName().'::'.$k);

        $gr = $this->form->addGroup(null, array('class' => 'no-label'));
        $gr->setSeparator(' ');
        $gr->addHtml()->setHtml('<div style="text-align:center">');
        $gr->addSubmit($this->getButtonName('back'), array('value'=>'Start Over'));
        $gr->addSubmit($this->getButtonName('next'), array('value'=>'Next'));
        $gr->addHtml()->setHtml('</div>');
    }
}

class Am_Form_Controller_Page_PluginMaker_Tables extends HTML_QuickForm2_Controller_Page
{
    protected function populateForm()
    {
        $info = $this->getController()->getValue();
        $db = Am_Db::connect($info);

        $tables = $db->selectCol("SHOW TABLES");
        $tables = array_merge(array('' => '-- Select --'), array_combine($tables, $tables));
        $this->form->addSelect('table[user]')->setLabel('User Table')
            ->loadOptions($tables)->addRule('required');
        if ($info['group_mode'] != 'Am_Protect_Databased::GROUP_NONE')
        {
            $this->form->addSelect('table[group]')->setLabel('Groups Table')
                ->loadOptions($tables)->addRule('required');
            if ($info['group_mode'] != 'Am_Protect_Databased::GROUP_SINGLE')
                $this->form->addSelect('table[usergroup]')->setLabel('User<->Group Relation Table (optional)')
                    ->loadOptions($tables);
        }
        $this->form->addSelect('table[session]')->setLabel('Session Storage Table (optional)')
            ->loadOptions($tables);

        $prefixes = $this->findPrefixes($tables);
        $options = @array_merge(array('' => 'No Prefix'), @array_combine($prefixes, $prefixes));
        $this->form->addSelect('table_prefix')->setLabel('Tables Prefix')->loadOptions((array)$options);

        $gr = $this->form->addGroup(null, array('class' => 'no-label'));
        $gr->setSeparator(' ');
        $gr->addHtml()->setHtml('<div style="text-align:center">');
        $gr->addSubmit($this->getButtonName('back'), array('value'=>'Back'));
        $gr->addSubmit($this->getButtonName('next'), array('value'=>'Next'));
        $gr->addHtml()->setHtml('</div>');
    }

    /**
     * find most possible prefixes
     */
    function findPrefixes(array $tables)
    {
        $found = array();
        foreach ($tables as $t)
        {
            $a = explode('_', $t);
            if (count($a)>1)
            {
                @$found[$a[0] . '_']++;
            }
        }
        return array_keys($found);
    }
}

class Am_Form_Controller_Page_PluginMaker_Columns extends HTML_QuickForm2_Controller_Page
{
    public function addTable($name, Am_Table $table, $title, array $options)
    {
        $fs = $this->form->addFieldset()->setLabel($title . ' Columns: ' . $table->getName(true));
        $sampleRows = $table->getAdapter()->select("SELECT * FROM ?#
            LIMIT ?d,3", $table->getName(true), max($table->countBy() - 3, 0));

        $key = null;
        foreach ($table->getFields() as $fieldName => $f)
        {
            if ($f->key == 'PRI')
                $key = $fieldName;
            unset($f->field);
            unset($f->null);
            $gr = $fs->addGroup('field['.$name . ']['.$fieldName.']')
                ->setLabel($fieldName . "\n". implode(' ', get_object_vars($f)));
            if ($fieldName != $key)
            {
                $options = array_merge(array('' => '-- Select --'), $options);
                $gr->addSelect('field', array('class' => 'field'))->loadOptions($options);
                $gr->addText('text', array('size'=>'40', 'style' => 'display:none'));
            }

            $sample = "<div style='color: lightgray; float: right; width: 50%; text-align: right; '>";
            foreach ($sampleRows as $r)
            {
                $v = Am_Html::escape($r[$fieldName]);
                if (strlen($v) > 60) $v = substr($v, 0, 60) . '<i>...cut</i>';
                $sample .= $v . "<br />";
            }
            $sample .= "</div>";

            $gr->addStatic()->setContent($sample);
        }
        if (!$key) $key = current($table->getFields(true));
        $this->form->addHidden('key['.$name.']')->setValue($key);
    }

    public function addFieldSelect($name, Am_Table $table, $title, array $options)
    {
        $fs = $this->form->addFieldset()->setLabel($title . ' Columns: ' . $table->getName(true));
        $sampleRows = $table->getAdapter()->select("SELECT * FROM ?#
            LIMIT ?d,3", $table->getName(true), max($table->countBy() - 3, 0));

        $sample = "<div style='color: lightgray'>";
        foreach ($sampleRows as $r)
        {
            $sample .= nl2br(Am_Html::escape(substr(print_r($r, true), 0, 1024))) . "\n";
        }
        $sample .= "</div>";
        $fs->addStatic()->setLabel('Sample Rows')->setContent($sample);


        $fields = $table->getFields(true);
        $fields = array_merge(array(''), $fields);
        foreach ($options as $k => $v)
        {
            $el = $fs->addSelect('field['.$name . ']['.$k.']')->setLabel($v)
                ->loadOptions(array_combine($fields, $fields));
            if (!in_array($k,array('Am_Protect_SessionTable::FIELD_UID',
                'Am_Protect_SessionTable::FIELD_IP',
                'Am_Protect_SessionTable::FIELD_UA',
                'Am_Protect_SessionTable::FIELD_CREATED',
                'Am_Protect_SessionTable::FIELD_CHANGED')))
                $el->addRule('required');
        }
    }

    protected function populateForm()
    {
        $info = $this->getController()->getValue();
        $db = Am_Db::connect($info);
        $tables = array();

        foreach ($info['table'] as $k => $v)
        {
            if (!empty($v))
                $tables[$k] = new Am_Table($db, $v);
        }

        /// user table
        $userOptions = array('expr' => 'PHP Expression', 'string' => 'PHP String');
        $class = new ReflectionClass('Am_Protect_Table');
        foreach ($class->getConstants() as $k => $v)
        {
            if (strpos($k, 'FIELD_')!==0) continue;
            $userOptions[$class->getName() . '::' . $k] = $class->getName() . ':' . $k;
        }
        $this->addTable('user', $tables['user'], 'User Table', $userOptions);

        /// group table
        if (!empty($tables['group']))
        {
            $groupOptions = array(
                'id' => 'Group ID',
                'title' => 'Group Title',
            );
            $this->addFieldSelect('group', $tables['group'], 'Group Table', $groupOptions);
        }

        /// user <-> group table
        if (!empty($tables['usergroup']))
        {
            $usergroupOptions = array(
                'Am_Protect_Table::GROUP_UID' => 'User ID',
                'Am_Protect_Table::GROUP_GID' => 'Group ID',
            );
            $this->addFieldSelect('usergroup', $tables['usergroup'], 'User<->Group Table', $usergroupOptions);
        }

        /// session table
        if (!empty($tables['session']))
        {
            $sessionOptions = array(
                'Am_Protect_SessionTable::FIELD_SID' => 'Session ID',
                'Am_Protect_SessionTable::FIELD_UID' => 'User ID',
                'Am_Protect_SessionTable::FIELD_IP' => 'IP Address',
                'Am_Protect_SessionTable::FIELD_UA' => 'User Agent',
                'Am_Protect_SessionTable::FIELD_CREATED' => 'Created',
                'Am_Protect_SessionTable::FIELD_CHANGED' => 'Changed',
            );
            $this->addTable('session', $tables['session'], 'Session Table', $sessionOptions);
        }

        $this->form->addScript()->setScript(<<<CUT
jQuery(function(){
    jQuery("select.field").change(function(){
        var row = jQuery(this).closest(".row");
        var val = jQuery(this).val();
        row.find("input[name$='[text]']").toggle(val=='expr' || val=='string');
    }).change();
});
CUT
);
        $gr = $this->form->addGroup(null, array('class' => 'no-label'));
        $gr->setSeparator(' ');
        $gr->addHtml()->setHtml('<div style="text-align:center">');
        $gr->addSubmit($this->getButtonName('back'), array('value'=>'Back'));
        $gr->addSubmit($this->getButtonName('next'), array('value'=>'Next'));
        $gr->addHtml()->setHtml('</div>');
    }
}