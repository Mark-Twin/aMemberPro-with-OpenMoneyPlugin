<?php


class AdminUserConsentController extends Am_Mvc_Controller_Grid
{
    protected $layout = 'admin/user-layout.phtml';
    public function preDispatch()
    {
        require_once AM_APPLICATION_PATH . '/default/controllers/AdminUsersController.php';
        $this->setActiveMenu('users-browse');

        $this->user_id = $this->getInt('user_id');
        if (!$this->user_id)
            throw new Am_Exception_InputError("Wrong URL specified: no member# passed");

        parent::preDispatch();
    }

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission('grid_u');
    }

    public
        function createGrid()
    {
        
        $query = new Am_Query($this->getDi()->userConsentTable);
        
        $query->leftJoin("?_agreement", 'a', "a.agreement_revision_id = t.revision_id");
        $query->addField('t.revision_id');
        $query->addField('t.type');
        
        $query->addField('IFNULL(t.title, a.title)', 'title');
        $query->addField('a.body');
        $query->addField('a.is_current');
        $query->addField('t.dattm', 'consent_date');
        
        $query->addWhere('user_id=?', $this->user_id);
        
        
        $query->setOrderRaw("IFNULL(is_current, '') desc, consent_date desc, cancel_dattm is null desc");
        
        
        $grid = new Am_Grid_ReadOnly('_user_consent', ___('Consent Obtained from User'), $query, $this->getRequest(), $this->view);
        $grid->setPermissionId('grid_u');
        
        $grid->addField(new Am_Grid_Field_Expandable('body', ___('Document Title')))
            ->setSafeHtml(true)
            ->setPlaceholder(function($val, $record){
                return sprintf(
                    "<span style='%s'>%s <i>%s</i></span>", 
                    $record->is_current? "font-weight:bold": "", $record->title?:___('Default Agreement'), $record->revision_id ? ($record->is_current?___('Current Revision') : ___('Previous Revision')):""
                    ) ;
            })
            ->setGetFunction(function($record){
                return sprintf("<div style='max-width: 800px; max-height: 300px; overflow: auto;'>%s</div>", $record->body);
            });
        
        $grid->addField('type', ___('Document Type'))->setRenderFunction(function($record) use ($grid){
            return $grid->renderTd(
                $record->revision_id?sprintf(
                    "<a href='%s' target='_blank'>%s</a>", 
                    $this->getDi()->url('admin-agreement/revisions/type/'.$record->type), 
                    $record->type
                    ):$record->type,false);
        });
        
        $grid->addField('t.dattm', 'Obtained at')->setRenderFunction(function($record) use($grid){
            return $grid->renderTd(amDatetime($record->consent_date));
        });
        
        $grid->addField('remote_addr', ___('IP Address'));
        $grid->addField('source', ___('Source of Consent'));
        $grid->addField('status', ___('Status'))->setRenderFunction(function ($record) use ($grid){
            return $grid->renderTd($record->isActual() ? ___('Actual') : ___('Revoked'));
        });
        
        $grid->addField('t.cancel_dattm', 'Revoked at')->setRenderFunction(function($record) use($grid){
            return $grid->renderTd(amDatetime($record->cancel_dattm));
        });
        
        $grid->addField('cancel_remote_addr', ___('Revoked from IP Address'));
        $grid->addField('cancel_source', ___('Revoked Source'));
        
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, function(&$ret, $record){
            if(!$record->isActual()) $ret['style'] = 'color: gray';
        });
        
        
        return $grid;
    }

}
