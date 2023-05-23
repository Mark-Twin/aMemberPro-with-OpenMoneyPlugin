<?php

class UserDeleteRequest extends Am_Record
{
    
}

class UserDeleteRequestTable extends Am_Table
{

    protected
        $_key = 'request_id';
    protected
        $_table = '?_user_delete_request';
    protected
        $_recordClass = 'UserDeleteRequest';

}
