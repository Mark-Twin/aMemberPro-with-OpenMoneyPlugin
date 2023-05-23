<?php

class WebhookQueue extends Am_Record
{
}

class WebhookQueueTable extends Am_Table 
{
    protected $_key = 'webhook_queue_id';
    protected $_table = '?_webhook_queue';
    protected $_recordClass = 'WebhookQueue';
}