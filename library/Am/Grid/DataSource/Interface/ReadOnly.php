<?php

/** 
 * Read-only data source for grid
 * @package Am_Grid 
 */
interface Am_Grid_DataSource_Interface_ReadOnly
{
    public function selectPageRecords($page, $itemCountPerPage);
    public function getFoundRows();
    public function setOrder($fieldNameOrRaw, $desc=null);
    /** @return null|Am_Query */
    public function getDataSourceQuery();
}