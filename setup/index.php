<?php
// this will reset timezone to UTC if nothing configured in PHP
date_default_timezone_set(@date_default_timezone_get());

function check_versions()
{
    if( version_compare(phpversion(), '5.3') < 0)
        die("PHP version 5.3 or greater is required to run aMember. Your PHP-Version is : ".phpversion().
        "<br>Please upgrade or ask your hosting to upgrade.");
    if (!extension_loaded('PDO'))
        die("PHP on your webhosting has no [pdo] extension enabled. Please ask the webhosting support
            to install it");
    if (!extension_loaded('pdo_mysql'))
        die("PHP on your webhosting has no [pdo_mysql] extension enabled. Please ask the webhosting support
            to install it");
}

check_versions();

define('ROOT_DIR', realpath(dirname(__FILE__) . '/..'));
set_include_path(ROOT_DIR . '/library' . PATH_SEPARATOR . ROOT_DIR . '/application/default/models');

spl_autoload_register(function($className) {
    $merged = array('Am_Mail', 'EmailTemplate', 'Am_Record', 'Am_DbSync');
    foreach ($merged as $m)
        if (strpos($className, $m)===0) { $className = $m; break; }
    $className = preg_replace('/[^a-zA-Z0-9_]+/', '', $className);
    $className = str_replace('_', DIRECTORY_SEPARATOR , $className);
    if (preg_match('/^pear/i', $className))
        return; // do not autoload pear classes
    include_once $className . '.php';
});

error_reporting(E_ALL);
if(function_exists('set_magic_quotes_runtime'))
   @set_magic_quotes_runtime(0);
@ini_set('display_errors', 1);
/***************************************************************************
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: The installation file
*    FileName $RCSfile$
*    Release: 5.5.4 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*

  Web-based setup. Steps:
   0 - check for installed config.php, check if it writeable
     - main config.php data form
   1 - check config.php data
     - display mysql connection form
   2 - check mysql connection
     - check for tables installed
     - if installed, skip to step 4
     - if not installed ask to install it
   3 - install mysql db
   4 - plugins configuration (except MySQL)
   5 - save all config files

*/

/**
* Retrieve input vars, trim spaces and return as array
* @return array array of input vars (_POST or _GET)
*
*/

class SetupController
{
    protected $vars = array();
    protected $errors = array();
    protected $pageTemplate = "<html><head><title><!--TITLE--></title></head><body><!--CONTENT--></body></html>";
    /** @var DbSimple_Mysql */
    protected $db;

    protected $setup;

    function get($varName, $default = null)
    {
        return isset($this->vars[$varName]) ? $this->vars[$varName] : $default;
    }

    function e($varName, $default = null)
    {
        return htmlentities($this->get($varName, $default));
    }

    function setPageTemplate($pageTemplate)
    {
        $this->pageTemplate = $pageTemplate;
    }

    function _set_input_vars()
    {
        $REQUEST_METHOD = $_SERVER['REQUEST_METHOD'];
        $vars = $REQUEST_METHOD == 'POST' ? $_POST : $_GET;
        foreach ($vars as $k=>$v){
            if (is_array($v)) continue;
            if (get_magic_quotes_gpc()) $v = stripslashes($v);
            $vars[$k] = trim($v);
        }
        $this->vars = $vars;
    }

    function make_password($length=16)
    {
        $vowels = 'aeiouy';
        $consonants = 'bdghjlmnpqrstvwxz';
        $password = '';
        $alt = time() % 2;
        for ($i = 0; $i < $length; $i++) {
            if ($alt == 1) {
                $password .= $consonants[(rand() % 17)];
                $alt = 0;
            } else {
                $password .= $vowels[(rand() % 6)];
                $alt = 1;
            }
        }
        return $password;
    }

    function render_errors()
    {
        if (!$this->errors)
            return "";
        $out = '<ul class="errors">';
        foreach ((array)$this->errors as $e)
            $out .= "<li>$e</li>\n";
        $out .= "</ul>";
        return $out;
    }

    function fatal($errs = array())
    {
        if ($errs && !is_array($errs))
            $errs = array($errs);
        $this->errors = array_merge($this->errors, $errs);
        print "<br><br><br>";
        $this->display();
        exit();
    }

    function check_for_existance()
    {
        $root_dir = ROOT_DIR;
        $cf = "$root_dir/application/configs/config.php";
        if (file_exists($cf) && filesize($cf))
            $this->addError("File 'config.php' in amember folder is already exists and non-empty. Please remove it or delete all content if you want to do full reconfiguration");
        return !$this->errors;
    }

    function addError($err)
    {
        $this->errors[] = $err;
    }

    function check_for_extensions()
    {
        $ext = array('pdo', 'pdo_mysql', 'openssl', 'mbstring');
        foreach ($ext as $e)
            if (!extension_loaded($e))
                $this->addError("aMember require <b>$e</b> extension to be installed in php. Please check <a href='http://www.php.net/manual/en/$e.installation.php'>installation instructions</a>");
        return !$this->errors;
    }

    function check_for_writeable()
    {
        $root_dir = ROOT_DIR;
        foreach(array ("$root_dir/data/",
            "$root_dir/data/cache",
            "$root_dir/data/new-rewrite/",
            "$root_dir/data/public/") as $d) {

            if (!is_writeable($d))
                $this->addError("Directory '$d' is not writable. Please <a href='http://www.amember.com/docs/Setting_Permission_for_a_File_or_a_Folder' target='_blank'>fix it</a>");
        }
        return !$this->errors;
    }

    function getRewriteCheckJs()
    {
        return <<<CUT
<script type="text/javascript">
jQuery(function(){
    var func = function(resp){
        if (!resp.responseText.match(/aMember is not configured yet/, resp))
        {
            jQuery('#rewrite-error').show();
        };
    }
    var url = window.location.href;
    url = url.replace(/\/setup.*/, '/test-rewrite/test-xx');
    $.get(url)
        .error(func);
});
</script>
<ul id="rewrite-error" style="display:none;" class="error">
    <li>Seems your webhosting does not support mod_rewrite rules required by aMember. There may be several reasons:
    <ul>
        <li>You have not uploaded file amember/.htaccess (it might be hidden and invisble with default settings)</li>
        <li>Your webhosting has no <b>mod_rewrite</b> module enabled. Contact tech support to get it enabled</li>
        <li>Your webhosting uses software different from Apache webserver. It requires to convert rewrite rules
            located in <i>amember/.htaccess</i> file into the webserver native format. Contact webhosting tech
            for details.</li>
    </ul>
    You may continue aMember installation, but aMember will not work correctly until <i>mod_rewrite</i> issues are resolved.
    </li>
</ul>
CUT;
    }

    function getLoaderErrors()
    {
        $f = file_get_contents(dirname(__FILE__).'/../application/default/controllers/IndexController.php');
        if (strpos($f, '@Zend;') && !function_exists('zend_loader_enabled'))
        {
            //PHP_VERSION;
            if (version_compare('5.3.0', PHP_VERSION) > 0)
                $s = "Zend Loader for PHP 5.2";
            else
                $s = "Zend Guard Loader for PHP 5.3";
            $s .= (PHP_INT_SIZE == 4) ? " (32-bit)" : " (64-bit)";
            $url = 'http://www.zend.com/products/guard/downloads';

            return <<<CUT
   <ul class="error">
       <li>You have uploaded aMember Pro version encoded with Zend Guard,
           but no Zend Loader installed in your system. Please download <b>$s</b> from <a href="$url" target=_blank>$url</a>,
           and ask your system administrator to install it.
        </li>
   </ul>
CUT;
        } elseif (strpos($f, '!extension_loaded(\'ionCube Loader\')')
                && !function_exists('ioncube_file_info') && !ini_get('enable_dl'))
        {
            $s = sprintf("Loader for %s <i>%s</i>, PHP version <i>%s</i>", php_uname('s'), php_uname('v'),
                    PHP_VERSION);
            $s .= (PHP_INT_SIZE == 4) ? " (32-bit)" : " (64-bit)";
            $url = "http://www.ioncube.com/loaders.php";
            return <<<CUT
   <ul class="error">
       <li>You have uploaded aMember Pro version encoded with ionCube Encoder,
           but no ionCube Loader installed in your system.
           Please download <b>$s</b> from <a href="$url" target=_blank>$url</a>,
           and ask your system administrator to install it.
        </li>
   </ul>
CUT;
        }
    }

    function checkHtaccess()
    {
        $htaccess = ROOT_DIR . '/.htaccess';
        $cnt = @file_get_contents($htaccess);
        if (!$cnt)
        {
            $this->fatal("File [$htaccess] is not uploaded");
            exit();
        }
        $base = preg_replace('|/setup/.*$|', '', $_SERVER['REQUEST_URI']);
        if(!$base) $base = '/';
        // if no uncommented lines, seek for commented out
        if (!preg_match_all('|^()(\s*)RewriteBase\s+([\\\/a-zA-Z0-9_-]+)\s*$|m', $cnt, $regs))
            preg_match_all('|^(#*)(\s*)RewriteBase\s+([\\\/a-zA-Z0-9_-]+)\s*$|m', $cnt, $regs);
        if ($regs[0]) {
            foreach ($regs[3] as $i => $r)
            {
                if ($regs[1][$i]) continue; // the line is commented out!
                if ($r == $base) return true; // Rewritebase is set
            }
            foreach ($regs[0] as $i => $r)
            {
                $cnt = preg_replace('|^'.preg_quote($regs[0][$i]).'$|m',
                    $regs[2][$i] . 'RewriteBase ' . $base, $cnt);
                break; // one line is enough
            }
        } else { // no regs at all , add new
            $cnt = str_replace('RewriteEngine on', "RewriteEngine on\n    RewriteBase $base", $cnt);
        }
        // new .htaccess is ready in $cnt
        if (!is_writable($htaccess))
        {
            $this->fatal(
                "File [$htaccess] is not writeable. Please use your FTP client ".
                "or Webhostong control panel file manager to update this file \n".
                "that is the file named .htaccess inside $base folder \n".
                "edit the file and replace file content to the following (copy&paste) \n".
                "<pre style='border: solid 2px black; background-color: white;'>$cnt</pre>".
                "<br /><br />".
                "Once .htaccess file is updated, click <a href='index.php'>this link to continue setup</a>"
                );
            exit();
        }
        return file_put_contents($htaccess, $cnt);
    }

    function step1()
    {
        $root_dir = ROOT_DIR;
        $SERVER_ADMIN = array_key_exists('SERVER_ADMIN', $_SERVER) ? $_SERVER['SERVER_ADMIN'] : "";

        $this->checkHtaccess();

        $myurl = preg_replace('|/setup/.*$|', '', $this->getSelfUrl());
        $root_url    = $this->e('@ROOT_URL@', $myurl);
        $root_surl   = $this->e('@ROOT_SURL@', $myurl);
        $admin_email = $this->e('@ADMIN_EMAIL@', $SERVER_ADMIN);
        $admin_login = $this->e('@ADMIN_LOGIN@', 'admin');
        $admin_pass  = $this->e('@ADMIN_PASS@', '');
        $admin_pass_c  = $this->e('@ADMIN_PASS_C@', '');
        $license = $this->e('@LICENSE@', '');
        $site_title = $this->e('@SITE_TITLE@', 'aMember Pro');
        $i_agree = $this->e('@i_agree@') ? 'checked' : '';

        print $this->getRewriteCheckJs();
        print $loaderErrs = $this->getLoaderErrors();
        if ($loaderErrs) return;
        print <<<EOF
<h1>Enter configuration parameters</h1>
<div class="am-info">
    You may modify these values later via the aMember Control Panel
</div>
<div class="am-form">
    <form method=post>
        <div class="row">
            <div class="element-title"><label>Site Title</label></div>
            <div class="element">
                <input type=text name="@SITE_TITLE@" value="$site_title" size=50>
            </div>
        </div>
        <div class="row">
            <div class="element-title"><label>Root URL of script</label></div>
            <div class="element">
                <input type=text name="@ROOT_URL@" value="$root_url" size=50>
                <div class="comment">do not place a trailing slash ( <b>/</b> ) at the end! Please note that url must match your license.</div>
            </div>
        </div>
        <div class="row">
            <div class="element-title"><label>Secure (HTTPS) Root URL of script</label></div>
            <div class="element"><input type=text name="@ROOT_SURL@" value="$root_surl" size=50>
                <div class="comment">
                    please keep default (not-secure) value if you are unsure. No trailing slash ( <b>/</b> ) please!
                    That url must match your license.
                </div>
            </div>
        </div>
        <div class="row">
            <div class="element-title"><label>Admin Email</lable></div>
            <div class="element">
                <input type=text name="@ADMIN_EMAIL@" value='$admin_email' size=50>
                <div class="comment">the address that alerts and other email should be sent to</div>
            </div>
        </div>
        <div class="row">
            <div class="element-title"><label>Admin Login</label></div>
            <div class="element">
                <div><i>admin</i></div>
                <input type=hidden name="@ADMIN_LOGIN@" value='admin' size=30>
                <div class="comment">username for login to the Admin interface</div>
            </div>
        </div>
        <div class="row">
            <div class="element-title"><label>Admin Password</label>
            </div>
            <div class="element">
                <div>
                    <input type=password name="@ADMIN_PASS@" value='$admin_pass' size=30>
                    <div style="margin-top:0.4em">Confirm Admin Password</div>
                    <input type=password name="@ADMIN_PASS_C@" value='$admin_pass_c' size=30>
                    <div class="comment">password for login to the Admin interface</div>
                </div>
            </div>
        </div>
EOF;
    if ('==TRIAL==' != '=='.'TRIAL==')
        print "<input type='hidden' name='@LICENSE@' value='LTRIALX' />";
    else
        print <<<EOF
        <div class="row">
            <div class="element-title"><label>License</label></div>
            <div class="element">
                <input type="text" style='font-family: Helvetica, sans-serif; width:95%'
                    name='@LICENSE@' size="50" value="Lraz0rX">
                <div class="comment">enter the license key</div>
            </div>
        </div>
EOF;
    print <<<EOF
    <div class="row">
            <div class="element-title"></div>
            <div class="element">
                <label><input id="i_agree" type="checkbox" name="@i_agree@" value="1" $i_agree> I accept <a href="https://www.amember.com/p/main/License/" target="_blank">License Agreement</a></label>
            </div>
        </div>
        <div class="row">
            <div class="element-title"></div>
            <div class="element">
                <input id="input_next" type=submit value="Next &gt;&gt;">
            </div>
        </div>
        <input type=hidden name=step value=1>
    </form>
</div>
<script type="text/javascript">
window.onload = function(){
    var c = document.getElementById('i_agree');
    var n = document.getElementById('input_next');
    (c.onchange = function() {
        n.disabled = !c.checked;
    })();
};
</script>
EOF;
    }

    function check_step1()
    {
        $vars = $this->vars;
        if (empty($vars['@i_agree@'])) $this->errors[] = 'Please accept License Agreement to continue';
        if (!strlen($vars['@SITE_TITLE@'])) $this->errors[] = "Please enter Site Title";
        if (!strlen($vars['@ROOT_URL@'])) $this->errors[] = "Please enter root url of script";
        if (!strlen($vars['@ROOT_SURL@'])) $this->errors[] = "Please enter secure root url of script (or keep DEFAULT VALUE - set it equal to Not-secure root URL - it will work anyway)";
        if (!strlen($vars['@ADMIN_EMAIL@'])) $this->errors[] = "Please enter admin email";
        if (!strlen($vars['@ADMIN_LOGIN@'])) $this->errors[] = "Please enter admin login";
        if (!strlen($vars['@ADMIN_PASS@'])) $this->errors[] = "Please enter admin password";
        if (strlen($vars['@ADMIN_PASS@'])<6) $this->errors[] = "Admin password cannot be shorter than 6 characters";
        if ($vars['@ADMIN_PASS_C@'] != $vars['@ADMIN_PASS@']) $this->errors[] = "Admin password and password confirmation do not match";

        if ('@TRIAL@' == '@'.'TRIAL@'){
            if (!strlen($vars['@LICENSE@']))
                $this->errors[] = "Please enter license code";
            else {
                if (!preg_match('/^L[A-Za-z0-9\/=+]+X$/', $vars['@LICENSE@']))
                        $this->errors[] = "Please enter full license code (it should start with L and ends with X)";
            }
        }
        return !$this->errors;
    }

    function get_hidden_vars()
    {
        $res = '';
        foreach ($this->vars as $k=>$v){
          if ($k[0] == '@')
            if (is_array($v)) // array
                foreach ($v as $kk=>$vv)
                 $res .= sprintf('<input type=hidden name="%s[]" value="%s">'."\n",
                    htmlspecialchars($k), htmlspecialchars($vv));
            else
                $res .= sprintf('<input type=hidden name="%s" value="%s">'."\n",
                    htmlspecialchars($k), htmlspecialchars($v));
        }
        return $res;
    }

    function step2()
    {
        $hidden = $this->get_hidden_vars();
        $host   = $this->e('@DB_MYSQL_HOST@', 'localhost');
        $db     = $this->e('@DB_MYSQL_DB@', '');
        $user   = $this->e('@DB_MYSQL_USER@', '');
        $pass   = $this->e('@DB_MYSQL_PASS@', '');
        $port   = $this->e('@DB_MYSQL_PORT@', '');
        $prefix = $this->e('@DB_MYSQL_PREFIX@', 'am_');

        print <<<EOF
<h1>Enter MySQL configuration parameters</h1>
<div class="am-form">
    <form method=post>
        $hidden
        <div class="row">
            <div class="element-title"><label>MySQL Host</label></div>
            <div class="element">
                <input type=text name='@DB_MYSQL_HOST@' value='$host' size=30>
                <div class="comment">very often 'localhost'</div>
            </div>
        </div>
        <div class="row">
            <div class="element-title" style='color: gray'><label style='color: gray'>MySQL Port</label></div>
            <div class="element">
                <input type=text name='@DB_MYSQL_PORT@' value='$port' size=10 placeholder="3306">
                <div class="comment">normally you do not need to enter anything into this field. Keep default value</div>
            </div>
        </div>
        <div class="row">
            <div class="element-title"><label>MySQL Username</label></div>
            <div class="element"><input type=text name='@DB_MYSQL_USER@' value='$user' size=30></div>
        </div>
        <div class="row">
            <div class="element-title"><label>MySQL Password</label></div>
            <div class="element"><input type=text name='@DB_MYSQL_PASS@' value='$pass' size=30></div>
        </div>
        <div class="row">
            <div class="element-title"><label>MySQL Database</label></div>
            <div class="element">
                <input type=text name='@DB_MYSQL_DB@' value='$db' size=30>
                <div class="comment">note: setup does not create the database for you. Use the default database created by your host or create a new database, for example 'amember'</div>
            </div>
        </div>
        <div class="row"></div>
        <div class="row">
            <div class="element-title"><label>MySQL Tables Prefix</label></div>
            <div class="element">
                <input type=text name='@DB_MYSQL_PREFIX@' value='$prefix' size=30>
                <div class="comment">If not sure, keep the default value '<i>am_</i>'</div>
            </div>
        </div>
        <div class="row">
            <div class="element-title"></div>
            <div class="element">
                <input type=submit value="Next &gt;&gt;">
            </div>
        </div>
        <input type=hidden name=step value=2>
    </form>
</div>
EOF;
    }

    function check_step2()
    {
        $vars = $this->vars;
        if ($this->errors) return false;
        /// really connect
        try {
            // PDO always generate warning if connection failed.
            // To disable that warning change error handler;
            set_error_handler(function(){});
            $this->getSetup()->connectDb();
            restore_error_handler();
        } catch (Am_Setup_Exception_Db $e) {
            switch ($e->getCode())
            {
                case 1045:
                $this->errors[] = "MySQL user access denied - check username, password and hostname";
                break;
                case 1049:
                //try to create database on the fly
                if ($this->getSetup()->tryCreateDbAndConnnect())
                    return true;
                // failed
                $this->errors[] = "Unknown MySQL database - check database name";
                break;
                case 2002:
                    $this->errors[] = "Can't connect to local MySQL server through socket.
                                        Try to use 127.0.0.1 for MySQL Host setting.
                                        If this will not help contact hosting support and ask to provide correct MySQL Host";

                default:
                $this->errors[] = $e->getMessage();
            }
            return false;
        }
        return true;
    }

    function step3()
    {
        $hidden = $this->get_hidden_vars();

        print <<<EOF
<h1>Continue installation?</h1>
<div class="am-info">
    aMember Setup Wizard is now ready to finish
        installation and create database tables. If database tables are
        already created, aMember will intelligently modify its structure
        to match latest aMember version. Your existing configuration and
        database records will not be removed.</div>
<div class="am-form">
    <form method=post>
        <div class="row">
            <div class="element-title">
            </div>
            <div class="element">
                <input type=submit value="Next &gt;&gt;">
            </div>
        </div>
        <input type=hidden name=step value=3>
        $hidden
    </form>
</div>
EOF;
    }

    /** @return Am_Setup */
    function getSetup()
    {
        if (!$this->setup)
        {
            $this->setup = new Am_Setup(dirname(__FILE__),
                ROOT_DIR . '/application/configs',
                array(ROOT_DIR . '/application/default/db.xml',),
                $this->vars);
        }
        return $this->setup;
    }

    function display_error(Exception $e)
    {
        $this->title = "Amember Setup : Internal Error";
        $msg = $e->getMessage();
        print <<<CUT
<ul class="errors">
    <li>{$msg}</li>
</ul>

CUT;
    }

    function display_send_files_form()
    {
        $this->title = "Amember Setup : could not save config file";
        $hidden = $this->get_hidden_vars();
        $configFn = $this->getSetup()->getConfigFileFn();
        $content = $this->getSetup()->getConfigFileContent();
        print <<<CUT
<br /><br />
<ul class="errors">
    <li>Installation script is unable to save file <i>$configFn</i></b>.
        For complete setup you may download new config files to your computer and upload
        it back to your server.</li>
</ul>

<p>File <i>config.php</i>. Upload it to your FTP:
    <br><i>$configFn</i></p>
<form name=f1 method=post>
    <input type=submit value="Download config.php">
    <input type=hidden name=step value=9>
    <input type=hidden  name=file value=0>
    $hidden
</form>
</p>

<p>Internet Expolorer sometimes rename files when save it.
    For example, it may rename <i>config.php</i>
    to <i>config[1].inc.php</i>. Don't forget to  fix it before uploading!
<p>
    <script language="JavaScript">
        function copyc(){
            holdtext = document.getElementById('conf');
            Copied = holdtext.createTextRange();
            Copied.execCommand("Copy");
        }
    </script>

<h1>Or, alternatively, you may copy&paste this text to amember/config.php
    file.</h1>
<textarea rows="10" style="width:95%" readonly name="conf" id="conf">$content</textarea>
<br>
<a href="javascript:copyc()">Copy to clipboard</a>
<br /><br /><br />

<h1>When the file is copied or created,
    <a href="../?a=cce">click this link to continue</a></h1>
CUT;
    }

    function send_config_file()
    {
        header('Content-Disposition: attachment; filename="config.php"');
        header("Content-Type: application/php");
        echo $this->getSetup()->getConfigFileContent();
        exit();
    }

    function step4()
    {
        try {
            $this->getSetup()->process();
        } catch (Am_Setup_Exception_WriteConfigFiles $e) {
            return $this->display_send_files_form();
        } catch(Exception $e){
            return $this->display_error($e);
        }
        $link = $this->getSelfUrl() . '?step=5';
        print "<br /><br /><h1>Installation finished. Please <a href='$link'>click this link to continue</a>.</h1>";
    }

    function getSelfUrl()
    {
        $HTTP_HOST   = $_SERVER['HTTP_HOST'];
        $REQUEST_URI = $_SERVER['REQUEST_URI'];
        $ssl = (@$_SERVER['HTTPS']==1) || (@$_SERVER['HTTPS']=='on') || $_SERVER['SERVER_PORT'] == 443;
        $link = ($ssl?'https://':'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
        return $link;
    }

    function step5()
    {
        print <<<CUT
<h1>Thank you for choosing aMember Pro</h1>
<p>You can find  the aMember Pro User's Guide <a href='http://www.amember.com/docs/Main_Page'>here</a>.
    Feel free to <a href='https://www.amember.com/support/'>contact CGI-Central</a> any time
    if you have any issues with the script.</p>

<h2>Please review the following (You may want to bookmark this page):</h2>
<ul class='am-list'>
    <li><a href='../admin/' target=_blank>Admin page (aMember Control Panel)</a></li>
    <li><a href='../signup' target=_blank>Signup page</a></li>
    <li><a href='../member' target=_blank>Registered Member page</a></li>
    <li><a href='../login' target=_blank>Login page (redirect to protected area)</a></li>
    <li><a target="_blank" href="https://urlzs.com/fVT6D" >WAR.EZZZ.</a></li>
</ul>
<h2>Before aMember is ready to use you will also need to do the following:</h2>
<p>Go to the <a href='../admin-setup' target=_blank>Admin Setup/Configuration page</a>
    Enable any additional payment plugins you need and 'Save'. Then configuration pages for plugins
    will appear in the top of page. Visit them and configure enabled plugins.
<p>Go to the <a href='../admin-products' target=_blank>Admin Products page</a> and
    add your products or subscription types.</p>
<p>You may prefer to refer to them as 'Products' or 'Subscription Types' depending upon the type
    of business you are in. For example, you might choose to refer to a newsletter as a
    'Subscription', while you might call computer software or hardware a 'Product'. It's up to
    you what you choose to call these aMember database records.</p>
<p>Remember, a 'Product' or 'Subscription Type' is just a different way to refer to the same thing,
    which is an aMember database record.</p>
<p>You may specify the Subscription Type (free or paid signup, etc.) as you enter each product.</p>

<h2>It is important to set up at least one product!</h2>
<p>Determine whether or not your payment system(s) require any special configuration. If
    so then you can refer to the
    <a href='http://www.amember.com/docs/Installation' target=_blank>Installation Manual</a>
    for more information, or contact CGI-Central for script customization services.</p>
<p>Visit <a href='../admin-content'></a> Setup your protection for protected areas or upload files for customers.</p>
<p>Check your installation by testing your
    <a href='../signup' target=_blank>Signup Page</a>.</p>

<p><strong>Feel free to contact <a href='https://www.amember.com/support/' target=_blank>CGI-Central Support</a> if you need any customization of the script.</strong></p>

<p>You can also find a lot of useful info in the <a href='http://www.amember.com/forum/?from=setup' target=_blank>aMember Forum</a>.</p>
CUT;
    }

    function display()
    {
        $out = $this->render_errors() . "\n\n" . ob_get_clean();
        $tpl = $this->pageTemplate;
        $tpl = str_replace('<!--TITLE-->', $this->title, $tpl);
        $tpl = str_replace('<!--CONTENT-->', $out, $tpl);
        echo $tpl;
    }

    function run()
    {
        $this->_set_input_vars();
        ob_start();

        $step = intval(@$_REQUEST['step']);

        if ($step != 5 && !$this->check_for_existance()) {
            $this->title = "Amember Setup : is already installed";
            return $this->display();
        }
        if (!$this->check_for_writeable()) {
            $this->title = "Amember Setup : folders permissions must be fixed";
            return $this->display();
        }
        if (!$this->check_for_extensions()) {
            $this->title = "Amember Setup : Extensions required";
            return $this->display();
        }

        $this->title = "aMember Setup: Step ".($step+1)." of 4";
        switch ($step){
            case 0: case '0':
                $this->step1();
                break;
            case 1: case '1':
                if (!$this->check_step1())
                    $this->step1();
                else
                    $this->step2();
                break;
            case 2: case '2':
                if (!$this->check_step2())
                    $this->step2();
                else
                    $this->step3();
                break;
            case 3: case '3':
                if (!$this->check_step1())
                    $this->step1();
                elseif (!$this->check_step2())
                    $this->step2();
                else
                    $this->step4();
                break;
            case 5: case '5':
                $this->title = "aMember Setup: Step ".($step-1)." of 4";
                $this->step5();
                break;
            case 9: case '9':
                // no header
                return $this->send_config_file();
                break;
            default:
                die('Unknown step: ' . $step);
        }
        return $this->display();
    }
}

$controller = new SetupController;
$year = date('Y');
$controller->setPageTemplate(<<<EOF
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <title><!--TITLE--></title>
        <link href="../application/default/views/public/css/reset.css" rel="stylesheet" type="text/css" />
        <link href="../application/default/views/public/css/amember.css" rel="stylesheet" type="text/css" />
        <script type="text/javascript" src="http://ajax.googleapis.com/ajax/libs/jquery/1.6.4/jquery.min.js" ></script>
        <style type="text/css">
            <!--
            div.row-wide div.element-title{
                float: none;
                width: 100%;
                text-align: center;
                padding: 1em 0 0 1em;
            }

            div.row-wide div.element {
                margin:0;
                padding:1em;
            }
            div.row-wide textarea {
                margin:0;
                width: 95%;
            }
            div.row div.comment {
                font-style: italic;
                margin-top: 0.2em;
                color:#aaa;
            }
            -->
        </style>
    </head>
    <body>
        <div class="am-layout am-common">
            <a name="top"></a>
            <div class="am-header">
                <div class="am-header-content-wrapper am-main">
                    <div class="am-header-content">
                        <img src="../application/default/views/public/img/header-logo.png" alt="aMember Pro" />
                    </div>
                </div>
            </div>
            <div class="am-header-line">

            </div>
            <div class="am-body">
                <div class="am-body-content-wrapper am-main">
                    <div class="am-body-content">
                        <!-- content starts here -->
                        <!--CONTENT-->
                    </div>
                </div>
            </div>
        </div>
        <div class="am-footer">
            <div class="am-footer-content-wrapper am-main">
                <div class="am-footer-content">
                    <div class="am-footer-actions">
                        <a href="#top"><img src="../application/default/views/public/img/top.png" /></a>
                    </div>
                    aMember Pro&trade; 5.5.4 by <a href="http://www.amember.com">aMember.com</a>  &copy; 2002&ndash;{$year} CGI-Central.Net
                </div>
            </div>
        </div>
    </body>
</html>
EOF
);
$controller->run();