<?php

class AmLogger extends \Psr\Log\AbstractLogger
{
    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = array())
    {
        if(in_array($level, array('debug')))
            return;
        Am_Di::getInstance()->errorLogTable->log("[Amazon Istant Access " . strtoupper($level) . "-log]: $message.");
    }
}
