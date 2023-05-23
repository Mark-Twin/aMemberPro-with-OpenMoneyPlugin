<?php
namespace iDEALConnector\Log;

//use DateTime;
use iDEALConnector\Exceptions\ConnectorException;
use iDEALConnector\Exceptions\iDEALException;
use iDEALConnector\Entities\AbstractResponse;
use iDEALConnector\Entities\AbstractRequest;

class DefaultLog implements IConnectorLog
{
    private $debugLog = false;
//    private $logPath;
//    private $logLevel;

//    function __construct($logLevel, $logPath)
//    {
//        $this->logLevel = $logLevel;
//        $this->logPath = $logPath;
//    }

    function __construct($debugLog)
    {
        $this->debugLog = $debugLog;
    }

    public function logAPICall($method, AbstractRequest $request)
    {
//        if ($this->logLevel === 0)
        if ($this->debugLog)
            $this->log("Entering[".$method."]", $request);
    }

    public function logAPIReturn($method, AbstractResponse $response)
    {
//        if ($this->logLevel === 0)
        if ($this->debugLog)
            $this->log("Exiting[".$method."]", $response);
    }

    public function logRequest($xml)
    {
//        if ($this->logLevel === 0)
        if ($this->debugLog)
            $this->log("Request", $xml);
    }

    public function logResponse($xml)
    {
//        if ($this->logLevel === 0)
        if ($this->debugLog)
            $this->log("Response", $xml);
    }

    public function logErrorResponse(iDEALException $exception)
    {
        $this->log("ErrorResponse", $exception);
    }

    public function logException(ConnectorException $exception)
    {
        $this->log("Exception", $exception);
    }

    private function log($message, $value)
    {
//        $now = new DateTime();
//        file_put_contents($this->logPath, $now->format('Y-m-d H:i:s').' '.$message."\n".serialize($value)."\n\n", FILE_APPEND);
        
        \Am_Di::getInstance()->errorLogTable->log("iDEAL debug-log: " . $message."[".serialize($value)."]");
    }
}
