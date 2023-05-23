<?php
/**
 * @table paysystems
 * @id bankart
 * @title Bankart
 * @recurring none
 */
class Am_Paysystem_Bankart extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'Bankart';
    protected $defaultDescription = 'Credit Card Payment';
    
    const URL = "https://payment.architrade.com/paymentweb/start.action";
    
    protected $currency_codes = array(
        'EUR' => '978',
        'USD' => '840');
    
    var $key = array (1416130419, 1696626536, 1864396914, 1868981619, 1931506799, 543580534, 1869967904,
        1718773093, 1685024032, 1634624544, 2036692000, 1684369522, 1701013857, 1952784481, 1734964321,
        1953066862, 543257189, 544040302, 544696431, 544694638, 1948283489, 1768824951, 1769236591,
        1970544756, 1752526436, 1701978209, 1852055660, 1768384628, 1852403303);
    
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('terminal_id', array('size' => 20))
            ->setLabel('Terminal Alias');

    }
	function odpakiraj($stringData)
	{
		while (strlen($stringData) % 4 != 0)
			$stringData .= ' ';
		for ($i = 0, $j = 0; $i < strlen($stringData); $i = $i + 4, $j++)
		{
			$y = unpack("Nx", substr($stringData, $i, 4));
			$data[$j] = $y["x"];
 		}
		return $data;
	}
    
    function simpleXOR($byteInput)
    {
        $k = 0;
        for ($m = 0; $m < count($byteInput); $m++)
        {
            if ($k >= count($this->key))
                $k = 0;
            $result[$m] = $byteInput[$m] ^ $this->key[$k];
            $k++;
        }
        return $result;
    }
    
	function zapakiraj($data)
	{
		$stringData = '';
		for ($i = 0; $i < count($data); $i++)
		{
            $bin = pack("N", $data[$i]);
			$stringData = $stringData.$bin;
		}
		return $stringData;
	}
    
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $encoded = file_get_contents(dirname(__FILE__).'/resource.cgn');
        $decoded = $this->zapakiraj($this->simpleXOR($this->odpakiraj($encoded)));
        $temp = tempnam($this->getDi()->data_dir,'bnk');
        file_put_contents($temp, $decoded);
        
        $zipFile = zip_open($temp);
        
        while ($zipEntry = zip_read($zipFile))
        {
            if (zip_entry_name($zipEntry) == $this->getConfig('terminal_id').'.xml')
            {
                $zip_entry_exist = true;
                if (zip_entry_open($zipFile, $zipEntry))
                {
                    $readStream = zip_entry_read($zipEntry);
                    $data = unpack("N*", $readStream);
                    for ($i=1; $i<count($data)+1; $i++)
                        $data1[$i-1] = $data[$i];
                    $xorData = $this->simpleXOR($data1);
                    $bin = null;
                    for ($i=0; $i<count($xorData); $i++)
                        $bin .= pack("N", $xorData[$i]);
                    $decoded = unpack("C*", $bin);
                    $xmlString = "";
                    for ($i=1; $i<count($decoded)+1; $i++)
                        $xmlString .=  chr($decoded[$i]);
                    $strData = $xmlString;
                    zip_entry_close($zipEntry);
                }
            }   
        }
        zip_close($zipFile);
        if(!$zip_entry_exist)
        {
            $this->getDi()->errorLogTable->log("BANKART API ERROR : terminal xml file is not found in cgn file");
            throw new Am_Exception_InputError(___('Error happened during payment process. '));        
        }
        //for some reasone xml is broken in bankart cgn file
        $strData = preg_replace("/\<\/term[a-z]+$/",'</terminal>',$strData);
        $terminal = new SimpleXMLElement($strData);
        $port = (string)$terminal->port[0];
        $context = (string)$terminal->context[0];
        if($port == "443" )
            $url = "https://";
        else
            $url = "http://";
        $url.=(string)$terminal->webaddress[0];
        if(strlen($port) > 0)
            $url.= ":" . $port;
        if(strlen($context) > 0)
        {
            if ($context[0] != "/")
                $url.="/";
            $url.=$context;
            if (!$context[strlen($context)-1] != "/")
                $url.="/";
        }
        else
        {
                $url.="/";
        }
        $url.="servlet/PaymentInitHTTPServlet";

        $vars = array(
            'id' => (string)$terminal->id[0],
            'password' => (string)$terminal->password[0],
            'passwordhash' => (string)$terminal->passwordhash[0],
            'action' => 4,
            'amt' => $invoice->first_total,
            'currency' => $this->currency_codes[$invoice->currency],
            'responseURL' => $this->getPluginUrl('ipn'),
            //strange bankart requirements
            'errorURL' => $this->getRootUrl() . "/cancel",
            'trackId' => $invoice->public_id,
            'udf1' => $invoice->public_id,
        );
        $req = new Am_HttpRequest($url, Am_HttpRequest::METHOD_POST);
        $req->addPostParameter($vars);
        $res = $req->send();
        $body = $res->getBody();
        if(strpos($body, 'ERROR')>0)
        {
            $this->getDi()->errorLogTable->log("BANKART API ERROR : $body");
            throw new Am_Exception_InputError(___('Error happened during payment process. '));                    
        }
        list($payment_id,$url) = explode(':', $body, 2);
        $invoice->data()->set('bankart_payment_id', $payment_id)->update();
        $a = new Am_Paysystem_Action_Redirect($url. '?PaymentID=' .$payment_id);
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        if($request->get('Error') || $request->get('result') != 'APPROVED')
        {
            $invoice = $this->getDi()->invoiceTable->findFirstByData('bankart_payment_id', $request->get('paymentid'));
            echo "REDIRECT=".$this->getRootUrl() . "/cancel?id=" . $invoice->getSecureId("CANCEL");
            die;            
        }
        return new Am_Paysystem_Transaction_Bankart($this, $request, $response, $invokeArgs);
    }
    
    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }
    
    public function getSupportedCurrencies()
    {
        return array_keys($this->currency_codes);
    }
    public function getReadme()
    {
        $rootURL = $this->getDi()->config->get('root_url');
        return <<<CUT
<b>Bankart payment plugin configuration</b>
        
1. Enable "bankart" payment plugin at aMember CP->Setup->Plugins

2. Configure "Bankart" payment plugin at aMember CP -> Setup/Configuration -> Bankart
   
3. Download resource.cgn from your Bankart merchant account
   and upload it into /amember/application/default/plugins/payment/bankart
   
CUT;
    }
}

class Am_Paysystem_Transaction_Bankart extends Am_Paysystem_Transaction_Incoming
{
    public function findInvoiceId()
    {
        return $this->request->get('udf1');
    }

    public function getUniqId()
    {
        return $this->request->get('paymentid');
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return ($this->request->get('result') == 'APPROVED');
    }

    public function validateTerms()
    {
        return true;
    }
    public function processValidated()
    {
        parent::processValidated();
        echo "REDIRECT=".$this->plugin->getRootUrl() . "/thanks?id=" . $this->invoice->getSecureId("THANKS");
    }    
}