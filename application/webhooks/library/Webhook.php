<?php

class Webhook extends Am_Record
{
}

class WebhookTable extends Am_Table 
{
    protected $_key = 'webhook_id';
    protected $_table = '?_webhook';
    protected $_recordClass = 'Webhook';
}