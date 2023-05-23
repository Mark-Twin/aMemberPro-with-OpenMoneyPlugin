<?php

class ErrorController extends Am_Mvc_Controller
{
    public function errorAction()
    {
        $this->render('error.phtml');
    }
}