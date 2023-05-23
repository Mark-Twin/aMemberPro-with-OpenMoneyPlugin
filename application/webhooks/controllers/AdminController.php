<?php

class Webhooks_AdminController extends Am_Mvc_Controller_Grid
{

    protected $layout = 'admin/layout.phtml';

    public function checkAdminPermissions(Admin $admin)
    {
        return $admin->hasPermission(Bootstrap_Webhooks::ADMIN_PERM_ID);
    }

    function createGrid()
    {
        $ds = new Am_Query($this->getDi()->webhookTable);
        $grid = new Am_Grid_Editable('_w', ___('Browse Webhooks'), $ds, $this->getRequest(), $this->getView(), $this->getDi());
        $grid->setPermissionId(Bootstrap_Webhooks::ADMIN_PERM_ID);
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'getTrAttribs'));
        $grid->addField(new Am_Grid_Field('webhook_id', '#', true, '', null, '1%'));
        $grid->addField('event_id', ___('Event'));
        $grid->addField('url', ___('Url'));
        $grid->setForm(array($this, 'createForm'));
        $grid->setRecordTitle('WebHook');

        $cron_url = $this->getDi()->rurl('webhooks/cron', true);
        $grid->addCallback(Am_Grid_Editable::CB_RENDER_CONTENT, function(& $out, Am_Grid_Editable $grid) use ($cron_url) {
            $out = '<div class="info">' . "It is required to setup a cron job to run each minute to trigger sending of webhooks<br><br>
            <pre>* * * * * /usr/bin/curl $cron_url</pre>" . '</div>' . $out;
        });
        return $grid;
    }

    public function getTrAttribs(& $ret, $record)
    {
        if ($record->is_disabled) {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' disabled' : 'disabled';
        }
    }

    function renderStatus(Webhook $webhook)
    {
        return $webhook->is_disabled ? 'Disabled' : 'Active';
    }

    function createForm()
    {
        $keys = array_keys($this->getModule()->getTypes());
        $form = new Am_Form_Admin('webhooks');

        $gr = $form->addGroup()->setLabel(___('Event'));
        $sel = $gr->addSelect('event_id');
        $sel->setLabel(___('Event'))
            ->loadOptions(array_merge(array('' => ___('-- Please select --')),array_combine($keys,$keys)));
        $sel->addRule('required');
        $gr->addElement('static')->setContent('<br/><br/><div id="webhook_info"></div>');
        $js_ = '';
        foreach ($this->getModule()->getTypes() as $k => $v)
        {
            $desc = array($v['title']);
            if(isset($v['description']))
                $desc[] = $v['description'];
            $params = array();
            if(!is_array($v['params']))
                $params = array($v['params']);
            else
                $params = $v['params'];
            if(isset($v['nested']))
            {
                if(!is_array($v['nested']))
                    $params = array_merge($params,array($v['nested']));
                else
                    $params = array_merge($params,$v['nested']);
            }
            $desc[] = 'List of parameters:['.implode(',', $params).']';
            $js_.="webhooksCache.{$k} = '". implode('<br/>', $desc) ."';
";
        }
        $id_ = $sel->getId();
        $gr->addElement('static')->setContent(
            <<<EOF
<script type='text/javascript'>
var webhooksCache = {"" : {} };
$js_
jQuery(document).ready(function($) {
    function onWebhookChange() {
        jQuery("#webhook_info").html(webhooksCache[$(this).val()]);
    }
    jQuery("#$id_").change(onWebhookChange);
});
</script>
EOF
            );

        $form->addText('url',array('class'=>'el-wide'))->setLabel(___("Url\n" .
            'url of the page POST data will be sent to'))->addRule('required');

        $form->addAdvcheckbox('is_disabled')->setLabel(___('Is Disabled?'));

        return $form;
    }

}
