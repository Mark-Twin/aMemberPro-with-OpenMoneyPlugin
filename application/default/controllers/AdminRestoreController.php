<?php

/*
 *
 *
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Admin Info /
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class AdminRestoreController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_BACKUP_RESTORE);
    }

    public function preDispatch()
    {
        if (in_array('cc', $this->getDi()->modules->getEnabled()))
            throw new Am_Exception_AccessDenied(___('Online backup is disabled if you have CC payment plugins enabled. Use offline backup instead'));
    }

    function indexAction()
    {
        $url = $this->url('admin-restore/restore');
        ob_start();
        echo '
<div class="info">
    <p>' . ___('To restore the aMember database please pick a previously saved aMember Pro backup.') . '</p>
    <p><strong><span style="color:#ba2727">' . ___('WARNING! ALL YOUR CURRENT AMEMBER TABLES
    AND RECORDS WILL BE REPLACED WITH THE CONTENTS OF THE BACKUP!') . '</span></strong></p>
</div>
<div class="am-form">
    <form action="' . $url . '" method="post" enctype="multipart/form-data"
    onsubmit="return confirm(\'' . ___('It will replace all your exising database with backup. Do you really want to proceed?') . '\')">
    <div class="row">
        <div class="element-title">
            <label>File</label>
        </div>
        <div class="element">
            <input type="file" name="file" class="styled">
        </div>
    </div>
    <div class="row">
        <div class="element-title"></div>
        <div class="element">
            <input type="submit" value="'. ___('Restore') . '" />
        </div>
    </div>
    </form>
</div>';
        $this->view->title = ___('Restore Database from Backup');
        $this->view->content = ob_get_clean();
        $this->view->display('admin/layout.phtml');
    }

    function restoreAction()
    {
        check_demo();
        if (!$this->_request->isPost())
            throw new Am_Exception_InputError('Only POST requests allowed here');

        $db = $this->getDi()->db;
        $f = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$f)
            throw new Am_Exception_InputError('Can not open uploaded file. ' . Am_Upload::errorMessage($_FILES['file']['error']));

        if (substr($_FILES['file']['name'], -3) == '.gz') {
            throw new Am_Exception_InputError('It seems you use archive with backup file. Please extract backup file from archive and then use it.');
        }

        $first_line = trim(fgets($f));
        $second_line = trim(fgets($f));

        if (!$first_line || !$second_line)
            throw new Am_Exception_InputError('Uploaded file has wrong format or empty');

        $this->view->assign('backup_header', "$first_line<br />$second_line");

        if (!preg_match('/^### aMember Pro .+? database backup/', $first_line))
            throw new Am_Exception_InputError(___('Uploaded file is not valid aMember Pro backup'));

        $query = null;
        while ($query || !feof($f)) {
            if ($query && (substr($query, -1) == ';')) {
                $db->query($query);
                $query = null;
            }
            if ($line = fgets($f))
                $query .= "\r\n" . trim($line);
        }
        fclose($f);

        $this->getDi()->adminLogTable->log("Restored from $first_line");
        $this->displayRestoreOk();
    }

    function displayRestoreOk()
    {
        ob_start();
        $this->view->title = ___('Restored Successfully');

        echo '<div class="info">' . ___('aMember database has been successfully restored from backup.') . '</div>
<h2>' . ___('Backup file header') . "</h2>
<pre>
{$this->view->backup_header}
</pre>";
        $this->view->content = ob_get_clean();
        $this->view->display('admin/layout.phtml');
    }
}