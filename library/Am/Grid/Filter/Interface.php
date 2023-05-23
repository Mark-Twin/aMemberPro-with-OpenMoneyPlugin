<?php
/*
*     Author: Alex Scott
*      Email: alex@cgi-central.net
*        Web: http://www.amember.com/
*    Release: 5.5.4
*    License: LGPL http://www.gnu.org/copyleft/lesser.html
*/

/**
 * Interface to create "filter" UI in grids
 * @package Am_Grid
 */
interface Am_Grid_Filter_Interface
{
    /**
     * Init filter and apply it to $dataSource
     * Am_Mvc_Request comes without 
     */
    function initFilter(Am_Grid_ReadOnly $grid);

    /**
     * @return array list of variables - without gridId_ !
     */
    function getVariablesList();
    
    /**
     * @return bool
     */
    function isFiltered();
    
    /**
     * render filter with surrounding DIV
     */
    function renderFilter();
    /**
     * @return string html/js/css that must not be reloaded between requests
     */
    function renderStatic();
}
