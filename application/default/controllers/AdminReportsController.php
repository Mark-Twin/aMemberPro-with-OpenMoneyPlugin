<?php

/**
 *     Author: Alex Scott
 *      Email: alex@cgi-central.net
 *        Web: http://www.cgi-central.net
 *    Details: Admin index
 *    FileName $RCSfile$
 *    Release: 5.5.4 ($Revision$)
 *
 * Please direct bug reports,suggestions or feedback to the cgi-central forums.
 * http://www.cgi-central.net/forum/
 *
 * aMember PRO is a commercial software. Any distribution is strictly prohibited.
 *
 */

class AdminReportsController_Index extends Am_Mvc_Controller
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_REPORT);
    }

    public function saveAction()
    {
        $savedReport = $this->getDi()->savedReportRecord;
        $savedReport->setForInsert(array(
            'request' => $this->getRequest()->getParam('request'),
            'title' => $this->getRequest()->getParam('title'),
            'report_id' => $this->getRequest()->getParam('report_id'),
            'admin_id' => $this->getDi()->authAdmin->getUser()->pk()));
        $savedReport->save();
        if ($this->getRequest()->getParam('add-to-dashboard')) {
            $pref_default = array(
                'top' => array(),
                'bottom' => array(),
                'main' => array('users'),
                'aside' => array('sales')
            );

            $pref = $this->getDi()->authAdmin->getUser()->getPref(Admin::PREF_DASHBOARD_WIDGETS);
            $pref = is_null($pref) ? $pref_default : $pref;
            $pref['main'][] = 'saved-report-' . $savedReport->pk();
            $this->getDi()->authAdmin->getUser()->setPref(Admin::PREF_DASHBOARD_WIDGETS, $pref);
        }
        $this->_response->ajaxResponse(array(
            'status' => 'OK',
            'count' => $this->getDi()->savedReportTable->countByAdminId($this->getDi()->authAdmin->getUser()->pk())));
    }

    function runAction()
    {
        if (!$this->_request->isPost()) {
            throw new Am_Exception_InputError('Only POST accepted');
        }

        if (!$reportId = $this->getFiltered('report_id')) {
            throw new Am_Exception_InternalError("Empty report id passed");
        }

        $r = Am_Report_Abstract::createById($reportId);
        $r->applyConfigForm($this->_request);
        $this->view->form = $r->getForm();
        $this->view->report = $r;
        $this->view->content = '';

        if (!$r->hasConfigErrors())
        {
            $this->view->serializedRequest = serialize($this->_request->toArray());
            $this->view->reportId = $reportId;
            $this->view->saveReportForm = $this->createSaveReportForm($r->getTitle());

            $result = $r->getReport();
            foreach ($r->getOutput($result) as $output) {
                $this->view->content .= $output->render();
            }
            // default
            $default = $r->getForm()->getValue();
            unset($default['_save_']);
            unset($default['save']);
            $this->getSession()->reportDefaults = $default;
        }
        $this->view->display('admin/report_output.phtml');
    }

    function indexAction()
    {
        $reports = Am_Report_Abstract::getAvailableReports();
        $defaults = @$this->getSession()->reportDefaults;
        if ($defaults)
        {
            foreach ($reports as $r)
            {
                $r->getForm()->setDataSources(array(new HTML_QuickForm2_DataSource_Array($defaults)));
            }
        }
        $this->view->assign('reports', $reports);
        $this->view->display('admin/report.phtml');
    }

    function getformAction()
    {
        $id = $this->getParam('id');
        foreach (Am_Report_Abstract::getAvailableReports() as $r) {
            if ($r->getId() == $id) {
                $title = $this->escape($r->getTitle());
                $form = $r->getForm();
                echo <<<CUT
<h2>$title</h2>
$form
CUT;
                throw new Am_Exception_Redirect;
            }
        }
        throw new Am_Exception_InputError(sprintf('Can not find report with id [%s]', $id));
    }

    function savefrequencyAction()
    {
        if ($this->getRequest()->isPost()) {
            $this->getDi()->authAdmin->getUser()->setPref(Admin::PREF_REPORTS_SEND_FREQUENCY, $this->getRequest()->getParam('fr'));
        }
    }

    function createSaveReportForm($title)
    {
        $form = new Am_Form_Admin();
        $form->addText('title', array('class' => 'el-wide'))
            ->setLabel(___('Title of Report for your Reference'))
            ->setValue($title)
            ->addRule('required');
        $form->addAdvCheckbox('add-to-dashboard', null, array(
            'content' => ___('Add Report to My Dashboard')))
            ->setValue(1);

        return $form;
    }
}

class AdminReportsController_Saved extends Am_Mvc_Controller_Grid
{
    protected $layout = null;

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_REPORT);
    }

    function createGrid()
    {
        $ds = new Am_Query($this->getDi()->savedReportTable);
        $ds->addWhere('admin_id=?', $this->getDi()->authAdmin->getUserId());

        $grid = new Am_Grid_Editable('_report', ___('Saved Reports'), $ds, $this->_request, $this->view);
        $grid->setPermissionId(Am_Auth_Admin::PERM_REPORT);
        $grid->setEventId('gridSavedReport');
        $grid->addField(new Am_Grid_Field('title', ___('Title'), true));
        $grid->actionsClear();
        $grid->actionAdd(new Am_Grid_Action_LiveEdit('title'));
        $grid->actionAdd(new Am_Grid_Action_Url('run-report', ___('Run Report'), '__ROOT__/default/admin-reports/p/saved/runsaved/report_id/__ID__'))->setTarget('_top');
        $grid->actionAdd(new Am_Grid_Action_Delete());
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_TABLE, array($this, 'onRenderGridContent'));
        return $grid;
    }

    function runsavedAction()
    {
        if (!$reportId = $this->getFiltered('report_id')) {
            throw new Am_Exception_InternalError('Empty report id passed');
        }

        $report = $this->getDi()->savedReportTable->load($reportId);

        if ($report->admin_id != $this->getDi()->authAdmin->getUserId()) {
            throw new Am_Exception_AccessDenied();
        }

        $r = Am_Report_Abstract::createById($report->report_id);
        $r->applyConfigForm(new Am_Mvc_Request(unserialize($report->request)));
        $result = $r->getReport();
        $content = '';
        foreach ($r->getOutput($result) as $output) {
            $content .= $output->render() . "<br /><br />";
        }
        $url = $this->url('admin-reports/export', array('report_id'=>$report->report_id,'request'=>$report->request));
        $link_title = ___('Download CSV');
        $content .= <<<CUT
<div style="margin-bottom:1em; overflow: hidden">
    <a style="float:right" href="{$url}" class="link">{$link_title}</a>
        </div>
CUT;
        $this->view->enableReports();
        echo sprintf('<h1>%s</h1> %s', $this->escape($report->title), $content);
    }

    function onRenderGridContent(& $out)
    {
        $email = $this->escape($this->getDi()->authAdmin->getUser()->email);
        $txt = $this->escape(___('Send reports to my email'));
        $options = array(
            '' => ___('Never'),
            Am_Event::DAILY => ___('Daily'),
            Am_Event::WEEKLY => ___('Weekly'),
            Am_Event::MONTHLY => ___('Monthly')
        );

        $optionsHtml  = Am_Html::renderOptions($options, $this->getDi()->authAdmin->getUser()->getPref(Admin::PREF_REPORTS_SEND_FREQUENCY));

        $html = <<<CUT
<div> $txt (<strong>$email</strong>)
<select id="reports-send-frequency">
 {$optionsHtml}
</select>
</div>
<script type="text/javascript">
<!--
jQuery('#reports-send-frequency').change(function(){
    var url = amUrl('/default/admin-reports/p/index/savefrequency', 1);
    jQuery.post(url[0], jQuery.merge(url[1], [{name:'fr',value:jQuery(this).val()}]), function(){
        flashMessage('Preference has been updated');
    })
})
-->
</script>
CUT;
        $out .= $html;
    }
}

class AdminReportsController extends Am_Mvc_Controller_Pages
{
    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Am_Auth_Admin::PERM_REPORT);
    }

    public function preDispatch()
    {
        class_exists('Am_Report', true);
        class_exists('Am_Report_Standard', true);
    }

    public function initPages()
    {
        $cnt = $this->getDi()->savedReportTable->countByAdminId($this->getDi()->authAdmin->getUserId());

        $this->addPage(array($this, 'createIndexController'), 'index', ___('Reports'))
            ->addPage(array($this, 'createSavedController'), 'saved', ___('Saved Reports') . ($cnt ? " (($cnt))" : ''));
    }

    public function createIndexController($id, $title, Am_Mvc_Controller $controller)
    {
        return new AdminReportsController_Index($controller->getRequest(), $controller->getResponse(), $this->_invokeArgs);
    }

    public function createSavedController($id, $title, Am_Mvc_Controller $controller)
    {
        return new AdminReportsController_Saved($controller->getRequest(), $controller->getResponse(), $this->_invokeArgs);
    }

    public function exportAction()
    {
        $reportId = $this->getFiltered('report_id');
        $request = unserialize($this->getParam('request'));
        $r = Am_Report_Abstract::createById($reportId);
        $r->applyConfigForm(new Am_Mvc_Request($request));
        $result = $r->getReport();
        $dat = date('YmdHis');
        $output = new Am_Report_Csv($result);
        $data = $output->render();
        $this->_helper->sendFile->sendData($data, 'text/csv', "amember_reports-{$reportId}-{$dat}.csv");
        throw new Am_Exception_Redirect;
    }
}