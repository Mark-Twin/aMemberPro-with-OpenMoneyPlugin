<?php

/**
 * @todo remove NewsletterTable slowdown !
 */

class Am_Core_Rebuild
{
    const NEED_REBUILD = '_need_rebuild';

    function getDi()
    {
        return Am_Di::getInstance();
    }

    function getTitle()
    {
        return ___("Core");
    }

    function onRebuild(Am_Event_Rebuild $event)
    {
        // disable htpasswd from hooks if enabled
        foreach ($this->getDi()->plugins_protect->loadEnabled()->getAllEnabled() as $pl)
        {
            try {
                $pl->destroy();
            } catch (Exception $e) {  }
        }
        ///
        $batch = new Am_BatchProcessor(array($this, 'doWork'));
        $context = $event->getDoneString();
        $batch->run($context) ? $event->setDone() : $event->setDoneString($context);
    }

    function doWork(& $context, Am_BatchProcessor $batch)
    {
        $pageCount = 1000;
        if (!strlen($context))
        {
            $changed = $this->getDi()->userTable->checkAllSubscriptionsFindChanged($pageCount);
            $this->getDi()->db->query("DELETE FROM ?_data WHERE `table`='user' AND `key`=?", self::NEED_REBUILD);
            if (!$changed) return true;
            $this->getDi()->db->query("
                INSERT INTO ?_data
                (`table`, `id`, `key`, `value`)
                SELECT 'user', m.user_id, ?, 1
                FROM ?_user m
                WHERE m.user_id IN (?a)", self::NEED_REBUILD, $changed);
            $context = 0;
            return false;
        }
        // now select all changed users from user table and run checkSubscriptions on each
        $q = $this->getDi()->db->queryResultOnly("
            SELECT m.*
            FROM ?_user m LEFT JOIN ?_data d ON (d.`table`='user' AND m.user_id=d.`id` AND d.`key`=?)
            WHERE d.value > 0
            LIMIT ?d, ?d",
            self::NEED_REBUILD, (int)$context, $pageCount);
        $count = 0;
        while ($row = $this->getDi()->db->fetchRow($q))
        {
            $count++;
            $u = $this->getDi()->userRecord;
            $u->fromRow($row);
            $u->checkSubscriptions(false); // access_cache is batch-updated
            $context++;
            if (!$batch->checkLimits()) return false;
        }
        if (!$count) {
            $changed = $this->getDi()->userTable->checkAllSubscriptionsFindChanged($pageCount);
            $this->getDi()->db->query("DELETE FROM ?_data WHERE `table`='user' AND `key`=?", self::NEED_REBUILD);
            if (!$changed)
            {
                $context = '';
                return true;
            } else {
                $this->getDi()->db->query("
                    INSERT INTO ?_data
                    (`table`, `id`, `key`, `value`)
                    SELECT 'user', m.user_id, ?, 1
                    FROM ?_user m
                    WHERE m.user_id IN (?a)", self::NEED_REBUILD, $changed);
                $context = 0;
                return false;
            }
        }
    }
}

class Am_Invoice_Rebuild
{
    function getDi()
    {
        return Am_Di::getInstance();
    }

    function getTitle()
    {
        return ___('Invoices Information');
    }

    function onRebuild(Am_Event_Rebuild $event)
    {
        $batch = new Am_BatchProcessor(array($this, 'doWork'));
        $context = $event->getDoneString();
        $batch->run($context) ? $event->setDone() : $event->setDoneString($context);
    }

    function doWork(& $context, Am_BatchProcessor $batch)
    {
        $pageCount = 5000;
        $q = $this->getDi()->db->queryResultOnly("
            SELECT *
            FROM ?_invoice
            LIMIT ?d, ?d",
            (int)$context, $pageCount);
        $count = 0;
        while ($row = $this->getDi()->db->fetchRow($q))
        {
            $count++;
            $invoice = $this->getDi()->invoiceRecord;
            $invoice->fromRow($row);
            $invoice->updateStatus();
            try {
                $invoice->recalculateRebillDate();
            } catch (Am_Exception_InternalError $e) {}; // ignore error about empty period
            $context++;
            if (!$batch->checkLimits()) return;
        }
        if (!$count) return true; // finished!
    }
}

class AdminRebuildController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_REBUILD_DB);
    }

    /** @return array id => title */
    function getTargetsList()
    {
        $list = array('core' => ___('Core'), 'invoice' => ___('Invoice'));
        foreach ($this->getDi()->hook->getRegisteredHooks('rebuild') as $hook)
        {
            $hook = $hook->getCallback();
            if (is_array($hook) && is_object($hook[0]))
            {
                $obj = $hook[0];
                $list[$obj->getId()] = $obj->getTitle();
            }
        }
        return $list;
    }

    /** @return callback|null */
    function getTarget($id)
    {
        if ($id == 'core') return array(new Am_Core_Rebuild, 'onRebuild');
        if ($id == 'invoice') return array(new Am_Invoice_Rebuild, 'onRebuild');
        foreach ($this->getDi()->hook->getRegisteredHooks('rebuild') as $hook)
        {
            $hook = $hook->getCallback();
            if (is_array($hook) && is_object($hook[0]))
            {
                $obj = $hook[0];
                if ($obj->getId() == $id) return $hook;
            }
        }
    }

    function indexAction()
    {
        $plugin_buttons = "";
        foreach ($this->getTargetsList() as $id => $title)
        {
            $plugin_buttons .=
                sprintf('<input class="rebuild-button" type="button" name="%s" value="%s"/> ',
                    $id, ___('Rebuild %s Database', $title));
        }
        $this->view->title = ___('Rebuild Users Database');
        $DONE = ___('DONE');
        $CONTINUE = ___('CONTINUE');
        $BEGIN = ___('BEGIN');
        $info = ___('Sometimes, after configuration errors and as result of software problems, aMember users database
and third-party scripts databases becomes out of sync. Then you can run rebuild process manually
to get databases fixed.');
        $this->view->content = <<<CUT
<style type="text/css">
<!--
.process-item {
    color: #ccc;
    padding: 0.2em 0.5em;
}

.process-item-current {
    color: inherit;
}

.process-item-error {
    color: #BA2727;
}
-->
</style>
<div class="info">$info</div>
<form method="post">
    <input type="hidden" name="start" value="core" />
    $plugin_buttons
</form>
<br /><br />
<div id="process" style="width: 100%; height: 20em; overflow: auto; background-color: white; display: none;">
</div>
<script type="text/javascript">
jQuery(function(){

    var btn;

    function onDataReceived(data)
    {
        /// prepend retreived data
        jQuery("#process").find('.process-item-current').removeClass('process-item-current');
        jQuery("#process").append('<div class="process-item process-item-current">' + data + "</div>");
        if (match = data.match(/$CONTINUE\((.+)\)$/))
        {
            doPost(match[1]);
        } else {
            if (data.match(/{$DONE}$/)) {
                btn.val(btn.val().replace("…", "") + " $DONE");
                jQuery(".rebuild-button").prop("disabled", "").removeClass('disabled');
            } else {
                jQuery("#process").append('<div class="process-item process-item-error">Incorrect response received. Stopped!</div>');
            }
        }
        jQuery("#process").scrollTop((jQuery("#process")[0].scrollHeight - jQuery("#process").height()));
    }

    function doPost(doString)
    {
        var url = amUrl('/admin-rebuild/do', 1);
        jQuery.post(url[0], jQuery.merge(url[1],[{name:'do', value:doString}]), onDataReceived);
    }
    jQuery(".rebuild-button[name=invoice]").click(function(e){
        if (!confirm(`
Warning: This action has side effects. It re calculate \
rebill dates for active recurring subscriptions according \
original billing plan. Your custom changes will be discarded. \
Also rebill date for failed/skipped payments (wich occur less \
then 30 days ago) will be moved to tomorrow.

Are you sure you want to run this routine?`)) {
            e.stopImmediatePropagation();
            e.preventDefault();
        }
    });
    jQuery(".rebuild-button").click(function(){
        btn = jQuery(this)
        jQuery("#process").show();
        btn.val(btn.val().replace(" $DONE", "")+"…");
        jQuery("#process").find('.process-item-current').removeClass('process-item-current');
        jQuery("#process").append('<div class="process-item process-item-current">' + btn.val() + " $BEGIN</div>");
        jQuery("#process").scrollTop((jQuery("#process")[0].scrollHeight - jQuery("#process").height()));
        jQuery(".rebuild-button").prop("disabled", "disabled").addClass('disabled');
        doPost(btn.attr('name'));
    });
});
</script>
CUT;
        $this->view->display('admin/layout.phtml');
    }

    function doAction()
    {
        Am_Mail::setDefaultTransport(new Am_Mail_Transport_Null);

        if (!$this->_request->isPost() || !$this->_request->get('do'))
            throw new Am_Exception_InputError("Wrong request");

        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', AM_HEAVY_MEMORY_LIMIT);

        $do = $this->_request->getParam('do');
        @list($do, $doneString) = explode(":", $do, 2);
        $callback = $this->getTarget($do);
        if (!$callback)
            throw new Am_Exception_InputError("Wrong request - plugin [$do] not found");

        $this->printStarted($callback[0]->getTitle() . " Db");
        $this->getDi()->adminLogTable->log("Rebuild ".$callback[0]->getTitle() . " Db started");
        $event = new Am_Event_Rebuild;
        $event->setDoneString($doneString);
        call_user_func($callback, $event);
        $this->getDi()->adminLogTable->log("Rebuild ".$callback[0]->getTitle() . " Db finished");
        $this->printFinished($do, $event);
    }

    function printStarted($what)
    {
        echo "Rebuilding $what&hellip;\n\n";
    }

    function printFinished($plugin, Am_Event_Rebuild $event)
    {
        if ($event->needContinue()) {
            echo ___('CONTINUE') . "($plugin:".htmlentities($event->getDoneString()).")";
        } else {
            echo ___('DONE');
        }
    }
}