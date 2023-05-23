<?php

/** 
 * Editable data source for grid
 * @package Am_Grid 
 */
interface Am_Grid_DataSource_Interface_Editable extends Am_Grid_DataSource_Interface_ReadOnly
{
    public function getRecord($id);
    public function insertRecord($record, $valuesFromForm);
    public function updateRecord($record, $valuesFromForm);
    public function deleteRecord($id, $record);
    public function createRecord();
    public function getIdForRecord($record);
    
}
