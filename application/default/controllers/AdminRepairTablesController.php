<?php
/*
*
*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.cgi-central.net
*    Details: Fix corrupted MySQL tables
*    FileName $RCSfile$
*    Release: 5.5.4 ($Revision$)
*
* Please direct bug reports,suggestions or feedback to the cgi-central forums.
* http://www.cgi-central.net/forum/
*
* aMember PRO is a commercial software. Any distribution is strictly prohibited.
*
*/

class AdminRepairTablesController extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->isSuper();
    }

    function indexAction()
    {
        $label = ___('Do Repair');
        $msg = ___('You can use this tool to repair your tables in event of one of it is marked as crashed.');
        $url = $this->getDi()->url('admin-repair-tables/repair');
        $this->view->title = ___('Tables Repair');
        $this->view->content = <<<CUT
<p>$msg</p>
<a href="$url" class="button">$label</a>
CUT;
        $this->view->display('admin/layout.phtml');
    }

    function repairAction()
    {
        $url = json_encode($this->getDi()->url('admin-repair-tables/do', false));
        $this->view->title = ___('Tables Repair');
        $msg = ___('Tables Repairing');
        $this->view->content = <<<CUT
<div class="info" id="repair-tables-output">$msg<span id="repair-tables-load"></span></div>
<script type="text/javascript">
    var id = setInterval(function(){
        jQuery("#repair-tables-load").text().length >= 3 ?
            jQuery("#repair-tables-load").empty() :
            jQuery("#repair-tables-load").append('.')
    }, 500);
    jQuery('#repair-tables-output').load($url, function(){
        clearInterval(id);
    });
</script>
CUT;
        $this->view->display('admin/layout.phtml');
    }

    function doAction()
    {
        $tables = $this->getDi()->db->selectCol("SHOW TABLES LIKE ?", $this->getDi()->config->get('db.mysql.prefix').'%');
        $prefix = $this->getDi()->config->get('db.mysql.prefix');
        foreach ($tables as $t){
            print ___('Reparing') . " {$prefix}$t...";
            $this->getDi()->db->query("REPAIR TABLE {$prefix}$t");
            print ___('OK') . '<br />';
        }
        print ___('tables restored.');
    }
}