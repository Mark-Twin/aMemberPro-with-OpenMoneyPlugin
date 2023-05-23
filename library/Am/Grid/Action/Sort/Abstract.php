<?php

abstract class Am_Grid_Action_Sort_Abstract extends Am_Grid_Action_Abstract
{
    protected $type = self::HIDDEN;
    protected $privilege = 'edit';
    /** @var Am_Grid_Decorator_LiveEdit */
    protected $decorator;
    protected static $jsIsAlreadyAdded = false;

    public function setGrid(Am_Grid_Editable $grid)
    {
        parent::setGrid($grid);
        if ($this->hasPermissions()) {
            $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, array($this, 'getTrAttribs'));
            $grid->addCallback(Am_Grid_Editable::CB_RENDER_CONTENT, array($this, 'renderContent'));
            $grid->prependField(new Am_Grid_Field_Sort('_sort'));
        }
    }

    final public function getTrAttribs(array & $attribs, $obj)
    {
        $grid_id = $this->grid->getId();
        $params = array(
            $grid_id . '_' . Am_Grid_ReadOnly::ACTION_KEY => $this->getId(),
            $grid_id . '_' . Am_Grid_ReadOnly::ID_KEY => $this->grid->getDataSource()->getIdForRecord($obj),
        );
        $attribs['data-params'] = json_encode($params);
        $attribs['data-sort-record'] = json_encode($this->getRecordParams($obj));
    }

    public function renderContent(& $out, Am_Grid_Editable $grid)
    {
        $url = $grid->makeUrl();
        $u = parse_url($url);
        $url1 = $u['path'];
        if (!empty($u['host']))
            $url1 = '//' . $u['host'] . $url1;
        if (!empty($u['scheme']))
            $url1 = $u['scheme'] . ':' . $url1;
        parse_str(parse_url($url, PHP_URL_QUERY), $url2);
        
        $url1 = json_encode($url1); // host/path
        $url2 = json_encode((object)$url2); // query string parameters
        
        $grid_id = $this->grid->getId();
        $msg = ___("Drag&Drop rows to change display order. You may want to temporary change setting '%sRecords per Page (for grids)%s' to some big value so all records were on one page and you can arrange all items.",
            '<a class="link" href="' . Am_Di::getInstance()->url('admin-setup') . '" target="_top">','</a>');
        $out .= <<<CUT
<div class="am-grid-drag-sort-message"><i>$msg</i></div>
<script type="text/javascript">
    jQuery(function(){
    jQuery(".grid-wrap").ngrid("onLoad", function(){
        if (jQuery(this).find("th .sorted-asc, th .sorted-desc").length)
        {
            jQuery('.am-grid-drag-sort-message').remove();
            return;
        }

        //prepend mousedown event to td.record-sort
        //the handlers of ancestors are called before
        //the event reaches the element
        var grid = jQuery(this);
        jQuery(this).mousedown(function(event) {
            if (jQuery(event.target).hasClass('record-sort')) {
                var offset = 0;
                grid.find('.expandable-data-row').each(function(){
                    offset += jQuery(this).offset().top > event.pageY ?
                                0 :
                                jQuery(this).outerHeight();
                })
                grid.find('.expanded').click();
                event.pageY -= offset;
            }
        });

        jQuery(this).sortable({
            items: "tbody > tr.grid-row",
            handle: "td.record-sort",
            update: function(event, ui) {
                var item = jQuery(ui.item);
                var url = $url1;
                var params = jQuery.extend($url2, item.data('params'));
                jQuery.each(item.closest('table').find('tr.grid-row'), function(index, el) {
                    jQuery(el).removeClass('odd');
                    ((index+1) % 2) || jQuery(el).addClass('odd');
                })

                params.{$grid_id}_move_item = {};
                jQuery.each(item.data('sort-record'), function(index, value) {
                    params.{$grid_id}_move_item[index] = value;
                })

                if(item.prev().data('sort-record')) {
                    params.{$grid_id}_move_after = {};
                    jQuery.each(item.prev().data('sort-record'), function(index, value) {
                        params.{$grid_id}_move_after[index] = value;
                    })
                }

                if (item.next().data('sort-record')) {
                    params.{$grid_id}_move_before = {};
                    jQuery.each(item.next().data('sort-record'), function(index, value) {
                        params.{$grid_id}_move_before[index] = value;
                    })
                }

                jQuery.post(url, params, function(response){});
            },
        });
    });
    });
</script>
CUT;
    }

    public function run()
    {
        $request = $this->grid->getRequest();
        $id = $request->getFiltered('id');
        $move_before = $request->getParam('move_before', null);
        $move_after = $request->getParam('move_after', null);
        $move_item = $request->getParam('move_item');

        $resp = array(
            'ok' => true,
        );
        if ($this->callback)
            $resp['callback'] = $this->callback;
        try {
            $this->setSortBetween($move_item, $move_after, $move_before);
        } catch (Exception $e) {
            throw $e;
            $resp = array('ok' => false, );
        }
        Am_Di::getInstance()->response->ajaxResponse($resp);
        exit();
    }

    protected function getRecordParams($obj)
    {
        return array(
            'id' => $this->grid->getDataSource()->getIdForRecord($obj),
        );
    }

    abstract protected function setSortBetween($item, $after, $before);
}

class Am_Grid_Field_Sort extends Am_Grid_Field
{
    public function __construct($field='_', $title=null, $sortable = true, $align = null, $renderFunc = null, $width = null)
    {
        parent::__construct($field, '', false);
        $this->addDecorator(new Am_Grid_Field_Decorator_Sort());
    }
    public function render($obj, $grid)
    {
        /* @var $grid Am_Grid_ReadOnly */
        return $grid->getRequest()->getParam('sort') ?
            '' :
            '<td class="record-sort" nowrap width="1%">&nbsp;</td>';
    }
}

class Am_Grid_Field_Decorator_Sort extends Am_Grid_Field_Decorator_Abstract {
    function renderTitle(& $out, $controller)
    {
        /* @var $controller Am_Grid_ReadOnly */
        $out = $controller->getRequest()->getParam('sort') ?
            '' :
            preg_replace('#^(<th)#i', '$1 class="record-sort" ', $out);
    }
}
