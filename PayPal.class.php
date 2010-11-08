<?php

/**
 * A PHP5 implementation of the PayPal NVP (Name-Value Pairs) API
 * @internal This wrapper is incomplete and subject to change.
 * @author Ben Tadiar <ben@bentadiar.co.uk>
 * @package PayPalPHP
 * @version 0.2
 */

class PayPal
{
	// Forward declare API credentials private
	private $username   = null;
	private $password   = null;
	private $signature  = null;
	
	// API configuration (API version, endpoints, URLs)
	private $debug		 = true;
	private $version     = '64.0';
	private $endpoints   = array('LIVE' => 'https://api-3t.paypal.com/nvp', 'SANDBOX' => 'https://api-3t.sandbox.paypal.com/nvp');
	private $paypalURLs  = array('LIVE' => 'https://www.paypal.com/', 'SANDBOX' => 'https://www.sandbox.paypal.com/');
	
	// Forward declare other variables
	public  $response	 = null;
	private $items	 	 = null;
	private $recipients  = null;
	private $currency	 = null;
	private $environment = null;
	
	// Supported currencies in ISO-4217 format
	private $currencies = array('AUD', 'BRL', 'CAD', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 
								'ILS', 'JPY', 'MYR', 'MXN', 'NOK', 'NZD', 'PHP', 'PLN', 
								'GBP', 'SGD', 'SEK', 'CHF', 'TWD', 'THB', 'USD');
	
	/**
	 * Check and set API credentials and environment
	 * @param string $env
	 * @return void
	 */
	public function __construct($env = 'LIVE', $username, $password, $signature)
	{
		if($env != 'LIVE' && $env != 'SANDBOX') $this->error('INVALID_ENVIRONMENT');
		$this->username = $username;
		$this->password = $password;
		$this->signature = $signature;
		$this->environment = $env;
	}
	
	/**
	 * Obtain the available balance for a PayPal account
	 * @return array|bool
	 */
	public function getBalance()
	{
		$this->buildRequest('getBalance');
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Obtain information about a PayPal account (merchant account number, locale etc.)
	 * @return array|bool array
	 */
	public function getPalDetails()
	{
		$this->buildRequest('getPalDetails');
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Initiates an Express Checkout transaction
	 * @param array $items Items to include in the transaction
	 * @param string $currency Supported ISO-4217 currency code
	 * @param string $returnURL URL redirect after confirmed payment
	 * @param string $cancelURL URL redirect after cancelled payment
	 * @return void Redirect on success, else return false
	 */
	public function setExpressCheckout($returnURL, $cancelURL)
	{
		if(!isset($_GET['token'])){
			$array = array('RETURNURL' => $returnURL, 'CANCELURL' => $cancelURL);
			$array = array_merge($array, $this->getItemTotals());
			$this->buildRequest('setExpressCheckout', $array);
			if($this->execute()){
				header('Location: '.$this->paypalURLs[$this->environment].'webscr?cmd=_express-checkout&token='.$this->response['TOKEN']);
				exit;
			}
			if($this->debug) $this->debug();
			return false;
		}
	}
	
	/**
	 * Obtain information about an Express Checkout transaction
	 * @return array Transaction details
	 */
	private function getExpressCheckoutDetails()
	{
		$array = array('TOKEN' => $_GET['token']);
		$this->buildRequest('getExpressCheckoutDetails', $array);
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Completes an Express Checkout transaction.
	 * @return bool
	 */
	public function doExpressCheckoutPayment()
	{
		$d = $this->getExpressCheckoutDetails();
		$array = array('TOKEN' => $d['TOKEN'],
					   'PAYMENTREQUEST_0_PAYMENTACTION' => 'Sale',
					   'PAYMENTREQUEST_0_AMT' => $d['PAYMENTREQUEST_0_AMT'].' '.$d['PAYMENTREQUEST_0_CURRENCYCODE'],
					   'PAYERID' => $d['PAYERID']);
		$array = array_merge($array, $d);
		$this->buildRequest('doExpressCheckoutPayment', $array);
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Verifies a postal address/code against the email address of a PayPal account holder
	 * @param string Email address to match against
	 * @param string First line of the billing or shipping postal address to verify
	 * @param string Postal code to verify
	 * @return array
	 */
	public function addressVerify($email, $street, $zip)
	{
		$array = array('EMAIL' => $email, 'STREET' => $street, 'ZIP' => $zip);
		$this->buildRequest('addressVerify', $array);
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Reverse a transaction
	 * @param $transaction 17 character alphanumeric transaction ID
	 * @return array
	 */
	public function reverseTransaction($transaction)
	{
		if(!ctype_alnum($transaction) || strlen($transaction) != 17) $this->error('INVALID_TRANSACTIONID');
		$array = array('TRANSACTIONID' => $transaction);
		$this->buildRequest('reverseTransaction', $array);
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Obtain information about a specific transaction
	 * @param string $transaction 17 character alphanumeric transaction ID
	 * @return array
	 */
	public function getTransactionDetails($transaction)
	{
		if(!ctype_alnum($transaction) || strlen($transaction) != 17) $this->error('INVALID_TRANSACTIONID');
		$array = array('TRANSACTIONID' => $transaction);
		$this->buildRequest('getTransactionDetails', $array);
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Make a payment to one or more PayPal account holders
	 * @todo Implement User ID receiver type
	 * @return array
	 */
	public function massPay()
	{
		$currency = $this->getCurrencyCode();
		$array = array('RECEIVERTYPE' => 'EmailAddress', 'CURRENCYCODE' => $currency);
		$array = array_merge($array, $this->getRecipients());
		$this->buildRequest('massPay', $array);
		if($this->execute()) return $this->response;
		if($this->debug) $this->debug();
		return false;
	}
	
	/**
	 * Add a mass payment recipient
	 * @param string $email
	 * @param string|int|float $amt
	 * @return void
	 */
	public function addRecipient($email, $amt)
	{
		$amt = str_replace(',', '', $amt);
		if(!is_numeric($amt)) $this->error('INVALID_PRICE');
		if(count($this->recipients) >= 250) $this->error('MAX_RECIPIENTS');
		$amt = number_format($amt, 2, '.', '');
		$this->recipients[] = array('email' => $email, 'amt' => $amt);
	}
	
	/**
	 * Return an array of recipients to be merged with current NVP array
	 * @return array
	 */
	private function getRecipients()
	{
		$i = 0;
		if(is_null($this->recipients)) $this->error('INVALID_RECIPIENTS');
		foreach($this->recipients as $recipient){
			$array['L_EMAIL'.$i] = $recipient['email'];
			$array['L_AMT'.$i] = $recipient['amt'];
			$i++;
		}
		$this->recipients = null;
		return $array;
	}
	
	/**
	 * Add an item to the array of items for a transaction
	 * Note: Thousands separator must be ','
	 * @param string $name Item name
	 * @param string $desc Item Description
	 * @param string|int|float $amt Numeric price value
	 * @param int $qty Item quantity
	 */
	public function addItem($name, $desc, $amt, $qty)
	{
		$amt = str_replace(',', '', $amt);
		if(!is_numeric($amt)) $this->error('INVALID_AMT');
		$amt = number_format($amt, 2, '.', '');
		$this->items[$name] = array('desc' => $desc, 'price' => $amt, 'qty' => $qty);
	}
	
	/**
	 * Return an array of items to be merged with current NVP array
	 * @param array $items Items to include in the transaction
	 * @param string $currency Supported ISO-4217 currency code
	 * @return array
	 */
	private function getItemTotals()
	{
		$i = 0;
		$total = 0;
		$currency = $this->getCurrencyCode();
		if(is_null($this->items)) $this->error('INVALID_ITEMS');
		foreach($this->items as $key => $val){
			$array['L_PAYMENTREQUEST_0_NAME'.$i] = $key;
			$array['L_PAYMENTREQUEST_0_DESC'.$i] = $val['desc'];
			$array['L_PAYMENTREQUEST_0_AMT'.$i] = $val['price'].' '.$currency;
			$array['L_PAYMENTREQUEST_0_QTY'.$i] = $val['qty'];
			$total = $total + ($val['price'] * $val['qty']);
			$i++;
		}
		$array['PAYMENTREQUEST_0_AMT'] = $total.' '.$currency;
		$array['PAYMENTREQUEST_0_CURRENCYCODE'] = $currency;
		$this->items = null;
		return $array;
	}
	
	/**
	 * Set the transaction currency code
	 * @param string $currency
	 * @return void
	 */
	public function setCurrencyCode($currency)
	{
		if(!in_array($currency, $this->currencies)) $this->error('INVALID_CURRENCY_CODE');
		$this->currency = $currency;
	}
	
	/**
	 * Return the currency code
	 * @return string
	 */
	private function getCurrencyCode()
	{
		if(is_null($this->currency)) $this->error('UNDEFINED_CURRENCY_CODE');
		$currency = $this->currency;
		return $currency;
	}
	
	/**
	 * Merge 2 arrays and create a name-value pair string from
	 * the resulting array using http_build_query()
	 * @param string $method API method name
	 * @param array $methodArray Name-value pair array
	 * @return void
	 */
	private function buildRequest($method, $nvpArray = array())
	{
		$array = array('USER' => $this->username, 'PWD' => $this->password, 'SIGNATURE' => $this->signature, 'VERSION' => $this->version, 'METHOD' => $method);
		$array = array_merge($array, $nvpArray);
		$this->request = http_build_query($array, '', '&');
	}
	
	/**
	 * Execute an API call via cURL
	 * @return bool|array Return false on failure, response array on success
	 */
	public function execute()
	{
		$ch = curl_init($this->endpoints[$this->environment]);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		$certificate = dirname(__FILE__).'/CARootCerts.pem';
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_CAINFO, $certificate);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $this->request);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$output = curl_exec($ch);
		curl_close($ch);
		$this->response = $this->processOutput($output);
		if($this->response['ACK'] == 'Failure') return false;
		return true;
	}
	
	/**
	 * Convert an NVP response to an associative array
	 * @param string $output
	 * @return array
	 */
	private function processOutput($output)
	{
		$outArray = explode('&', $output);
		foreach($outArray as $val){
			$assoc = explode('=', $val);
			$return[$assoc[0]] = urldecode($assoc[1]);
		}
		return $return;
	}
	
	/**
	 * Simple response debugger
	 * var_dump $this->response so we can debug
	 * @return void
	 */
	private function debug()
	{
		$this->response['REQUEST'] = $this->request;
		var_dump($this->response);
	}
	
	/**
	 * PayPalPHP error handler
	 * @param string $code
	 */
	private function error($code)
	{
		$trace = debug_backtrace();
		$errors['INVALID_ENVIRONMENT']	   = "PayPal environment must be either 'LIVE' or 'SANDBOX'";
		$errors['INVALID_TOKEN']		   = 'Invalid token supplied';
		$errors['INVALID_ITEMS']		   = 'No items added to this transaction. Add at least 1 item using addItem()';
		$errors['INVALID_AMT']		 	   = "Amount must be a numeric value";
		$errors['INVALID_CURRENCY_CODE']   = 'Currency must be a PayPal supported ISO-4217 currency code';
		$errors['UNDEFINED_CURRENCY_CODE'] = 'Currency code is undefined. Set currency code using setCurrencyCode()';
		$errors['INVALID_TRANSACTIONID']   = 'Transaction ID must be an alphanumeric string and contain 17 characters';
		$errors['INVALID_RECIPIENTS']	   = 'No recipients added. Add at least 1 recipient using addRecipient()';
		$errors['MAX_RECIPIENTS']		   = 'Maximum of 250 recipients per Mass Payment transaction';
		echo 'PayPal API Error: '.$errors[$code].' in '.$trace[0]['file'].' on line '.$trace[0]['line'];
	}
}

?>