<?php

class Cc_AdminConvertController extends Am_Mvc_Controller
{

    public
        function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }

    /**
     *
     * @return Am_Crypt
     */
    function getCurrentCryptObject()
    {
        $cname = $this->getDi()->getCryptClass();
        return (new $cname);
    }

    /**
     *
     * @return Am_Crypt
     */
    function getOldCryptObject()
    {
        $crypt = $this->getCurrentCryptObject();

        $oldCrypt = $crypt;

        $oldSig = $oldCrypt->loadKeySignature();

        $cname = Am_Crypt::getClassByMethod($oldSig);
        if (get_class($oldCrypt) != $cname)
        {
            $oldCrypt = new $cname;
        }


        if ($oldCrypt->getKeySignature() != $oldSig)
        {
            // Now try to load old key file;

            $path = APPLICATION_PATH . '/configs/key-old.php';

            if (!file_exists($path))
                throw new Am_Exception_Crypt_Key(___('Unable to find old keyfile. '
                    . 'Please upload it to %s '
                    . 'Then refresh page', $path)); // @todo comment


            $key = include $path;
            if (!strlen($key))
                throw new Am_Exception_Crypt_Key('Key file has incorrect format or the key is empty'); // @todo comment

            if ($key == 'REPLACE THIS STRING TO YOUR KEYSTRING')
                throw new Am_Exception_Crypt_Key("You must define a valid key in the file [$path] instead of default");

            $oldCrypt = new $cname($key);
        }
        return $oldCrypt;
    }

    function createBackup()
    {
        try
        {
            $this->getDi()->db->query("CREATE TABLE ?_cc_backup LIKE ?_cc");
            $this->getDi()->db->query("INSERT INTO ?_cc_backup SELECT * FROM ?_cc");

            $this->getDi()->db->query("CREATE TABLE ?_config_backup LIKE ?_config");
            $this->getDi()->db->query("INSERT INTO ?_config_backup SELECT * FROM ?_config");
        }
        catch (Exception $ex)
        {
            $this->getDi()->errorLogTable->logException($ex);
            throw new Am_Exception_InputError(
            ___('Unable to backup cc and config tables. '
                . 'Check "<a href="%s">aMember CP -> Error Log</a>" for more info', $this->getDi()->url('admin-logs'))
            );
        }
    }

    function dropBackup()
    {
        try
        {
            $this->getDi()->db->query('DROP TABLE ?_config_backup');
            $this->getDi()->db->query('DROP TABLE ?_cc_backup');
        }
        catch (Exception $ex)
        {
            $this->getDi()->errorLogTable->logException($ex);
            throw new Am_Exception_InputError(
            ___('Unable to delete backup tables. '
                . 'Please delete cc_backup and config_backup tables manually '
                . 'Check "<a href="%s">aMember CP -> Error Log</a>" for more info ', $this->getDi()->url('admin-logs'))
            );
        }
    }

    function restoreBackup()
    {
        try
        {
            $this->getDi()->db->query('TRUNCATE TABLE ?_config');
            $this->getDi()->db->query("INSERT INTO ?_config SELECT * FROM ?_config_backup");

            $this->getDi()->db->query('TRUNCATE TABLE ?_cc');
            $this->getDi()->db->query("INSERT INTO ?_cc SELECT * FROM ?_cc_backup");
        }
        catch (Exception $ex)
        {
            $this->getDi()->errorLogTable->logException($ex);
            throw new Am_Exception_InputError(
            ___('Unable to restore back in cc_backup and config_backup tables'
                . 'Please restore it manually. '
                . 'Check "<a href="%s">aMember CP -> Error Log</a>" for more info ', $this->getDi()->url('admin-logs'))
            );
        }
    }

    function indexAction()
    {

        $crypt = $this->getCurrentCryptObject();
        
        if(!$this->getDi()->db->selectCell('select cc_id  from ?_cc limit 1')){
            $crypt->saveKeySigunature();
        }
        
        
        $oldCrypt = $this->getOldCryptObject();

        $this->view->title = "Convert CC Database";

        $startUrl = $this->getDi()->url('cc/admin-convert/do');

        if (get_class($oldCrypt) != get_class($crypt))
        {
            $this->view->content = <<<CUT1
<div>
    Encryption method was changed. You have to encrypt  database using new encryption method.
</div><br/>
<div style='color:#F44336;'>You must backup your database before you'll run this tool. GGI-Central is not responsible for any damage
this script may result to. If you have no backup saved, you may loose your data.  <br/>
Make backup first, then return to this page.
</div><br/>
<div>
    <a href='{$startUrl}' onClick="return confirm('I confirm that I have created  backup of database and key file.')">
        Start to convert  CC database
    </a>
</div>


CUT1;
        }
        else if ($crypt->getKeySignature() != $crypt->loadKeySignature())
        {
            $path = $path = APPLICATION_PATH . '/configs/key-old.php';
            $this->view->content = <<<CUT2
<div>
                Encryption key file was changed. Please upload old key file to {$path} and re-encrypt CC table.
</div><br/>
<div style='color:#F44336;'>You must backup your database before you'll run this tool. GGI-Central is not responsible for any damage
this script may result to. If you have no backup saved, you may loose your data.  <br/>
Make backup first, then return to this page.
</div><br/>
<div>
    <a href='{$startUrl}' onClick="return confirm('I confirm that I have created  backup of database and key file.')">
        Start to convert  CC database
    </a>
</div>
CUT2;
        }
        else
        {
            $this->view->content = <<<CUT
<div style='color:green'>Key signature stored in database and key signature that was generated are equal. No further actions are required.</div>
CUT;
        }
        $this->view->display('admin/layout.phtml');
    }

    function doAction()
    {
        $this->crypt = $this->getCurrentCryptObject();
        $this->oldCrypt = $this->getOldCryptObject();
        $this->context = $this->getParam('context');
        if (!$this->context)
            $this->createBackup();

        $batch = new Am_BatchProcessor(array($this, 'doWork'));
        $breaked = !$batch->run($this->context);
        $breaked ? $this->convertRedirect() : $this->convertComplete();
    }

    function doWork(& $context, Am_BatchProcessor $batch)
    {
        $db = $this->getDi()->db;
        try
        {
            $this->done = $db->selectCell("select count(*) from ?_cc where cc_id<?d", (int) $context);
            $this->total = $db->selectCell("select count(*) from ?_cc");
            $q = $db->queryResultOnly("SELECT * FROM ?_cc WHERE cc_id > ?d order by cc_id", (int) $context);
            while ($r = $db->fetchRow($q))
            {
                $context = $r['cc_id'];
                $ccRecord = $this->getDi()->CcRecordRecord;
                $ccRecord->getTable()->setCrypt($this->oldCrypt);
                $ccRecord->fromRow($r);
                if (preg_match('/[^\s\d-]/', $ccRecord->cc_number)) {
                    throw new Am_Exception_InternalError(
                        "Problem with converting to new encryption key.  "
                        . "cc record# {$ccRecord->cc_id} could not be converted, "
                        . "it seems the old key has been specified incorrectly. Conversion cancelled.");
                return;
            }

                $ccRecord->getTable()->setCrypt($this->crypt);
                $ccRecord->update();
                $this->done++;
                if (!$batch->checkLimits())
                    return;
            }
        }
        catch (Exception $ex)
        {
            $this->getDi()->errorLogTable->logException($ex);
            $this->restoreBackup();
            $this->dropBackup();
            throw new Am_Exception_InputError(
            ___('Got an error when attempting to re-encode CC record. '
                . 'CC and config tables were restored from backup. '
                . 'Check "<a href="%s">aMember CP -> Error Log</a>" for more info ', $this->getDi()->url('admin-logs'))
            );
        }
        return true;
    }

    function convertRedirect()
    {
        $done = $this->done;
        $total = $this->total;
        $url = $this->getUrl('admin-convert', 'do', 'cc', array('context' => $this->context));
        $text = $total > 0 ? (___('Converting  CC info (%d from %d)', $done, $total) . '. ' . ___('Please wait')) :
            ___('Converting started');
        $text .= '...';
        $this->redirectHtml($url, $text, ___('Converting') . '...', false, $done, $total);
    }

    function convertComplete()
    {
        $this->crypt->saveKeySigunature();
        $this->view->assign('title', ___('Done'));
        ob_start();
        print '<div class="info">';
        print 'Records were re-encoded using new key/encryption method';
        print '. ';
        $url = $this->getUrl('admin-convert', 'index', 'cc');
        print '<a href="' . $url . '">' . ___('Back') . '</a></div>';
        $this->view->assign('content', ob_get_clean());
        $this->view->display('admin/layout.phtml');

        $this->getDi()->db->query("OPTIMIZE TABLE ?_cc");
        $this->dropBackup();
    }
}