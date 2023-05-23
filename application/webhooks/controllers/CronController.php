<?php

class Webhooks_CronController extends Am_Mvc_Controller
{
    public function indexAction()
    {
        $this->getModule()->runCron();
    }
}