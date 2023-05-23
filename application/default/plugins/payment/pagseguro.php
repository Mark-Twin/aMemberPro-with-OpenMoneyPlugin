<?php
/**
 * @table paysystems
 * @id pagseguro
 * @title PagSeguro
 * @visible_link https://pagseguro.uol.com.br/
 * @recurring none
 * @logo_url pagseguro.gif
 */
class Am_Paysystem_Pagseguro extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_REVISION = '5.5.4';

    protected $defaultTitle = 'PagSeguro';
    protected $defaultDescription = 'Credit Card Payment';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant')->setLabel('Merchant Email');
        $form->addSecretText('token')->setLabel('Security Token');
    }

    function getSupportedCurrencies()
    {
        return array('BRL', 'USD');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Redirect('https://pagseguro.uol.com.br/security/webpagamentos/webpagto.aspx');
        $a->email_cobranca = $this->getConfig('merchant');
        $a->tipo = 'CP';
        $a->moeda = strtoupper($invoice->currency);
        $a->image = 'btnComprarBR.jpg';
        $a->item_id_1 = $invoice->public_id;
        $a->item_descr_1 = $invoice->getLineDescription();
        $a->item_quant_1 = 1;
        $a->item_valor_1 = str_replace('.', '', sprintf('%.2f', $invoice->first_total));
        $result->setAction($a);
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Pagseguro($this, $request, $response, $invokeArgs);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getReadme()
    {
        return <<<CUT

<b>PagSecuro payment plugin configuration <a href='http://pagseguro.uol.com.br' target=blank>http://pagseguro.uol.com.br</a></b>
   Activate "return URL" at your PagSeguro merchant account.
   To activate Automatic Data Return, 
   select the option Ativar and inform 
   the URL to which PagSeguro will redirect 
   your customers after completion  
   of payment. After that, click Salvar.
   You have to set up %root_url%/payment/pagseguro/ipn as "return URL".
CUT;
    }
    function getReturnUrl($invoice)
    {
        $this->invoice = $invoice;
        return parent::getReturnUrl();
    }

}

class Am_Paysystem_Transaction_Pagseguro extends Am_Paysystem_Transaction_Incoming
{

    public function findInvoiceId()
    {
        return $this->request->get('ProdID_1');
    }
    
    public function getUniqId()
    {
        return $this->request->get('TransacaoID');
    }

    public function validateSource()
    {
        $vars = $this->request->getPost();
		$vars['tipo'] = 'CP';
		$vars['Comando'] = 'validar';
		$vars['Token'] = $this->getPlugin()->getConfig('token');
		$vars['email_cobranca']= $this->getPlugin()->getConfig('merchant');
        try{
            $r = new Am_HttpRequest("https://pagseguro.uol.com.br/Security/NPI/Default.aspx?".http_build_query($vars, '', '&'));
            $response = $r->send();
        }catch(Exception $e){
            $this->getPlugin()->getDi()->errorLogTable->logException($e);
        }
        if($response && ($response->getBody() == 'VERIFICADO')){
            return true;
        }
        throw new Am_Exception_Paysystem_TransactionSource('Incorrect transaction received. Please contact webmaster for details');
        
    }

    public function validateStatus()
    {
        if(in_array(strtoupper($this->request->get('StatusTransacao')),array('APROVADO','COMPLETO')))
            return true;
        throw new Am_Exception_Paysystem_TransactionInvalid('Transaction is not approved');
    }

    public function validateTerms()
    {
        if(str_replace(',', '.', $this->request->get('ProdValor_1')) != $this->invoice->first_total) 
            throw new Am_Exception_Paysystem_TransactionInvalid('Incorrect amount received');
        
        return true;
    }
    
    public function processValidated()
    {
        $this->invoice->addPayment($this);
        Am_Mvc_Response::redirectLocation($this->getPlugin()->getReturnUrl($invoice));
    }

}
