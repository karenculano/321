<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This class represents a payment gateway
 *
 * @since    5.2
 */
class Billrun_PaymentGateway_CreditGuard extends Billrun_PaymentGateway {

	protected $conf;
	protected $billrunName = "CreditGuard";
	protected $subscribers;
	protected $pendingCodes = "/$^/";
	protected $completionCodes = "/^000$/";
	protected $account;

	protected function __construct() {
		parent::__construct();
		$this->EndpointUrl = $this->getGatewayCredentials()['endpoint_url'];
		$this->subscribers = Billrun_Factory::db()->subscribersCollection();
		$this->account = Billrun_Factory::account();
	}

	public function updateSessionTransactionId() {
		$url_array = parse_url($this->redirectUrl);
		$str_response = array();
		parse_str($url_array['query'], $str_response);
		$this->transactionId = $str_response['txId'];
	}

	protected function buildPostArray($aid, $returnUrl, $okPage, $failPage) {
		$credentials = $this->getGatewayCredentials();
		$xmlParams['version'] = '1000';
		$xmlParams['mpiValidation'] = 'Verify';
		$xmlParams['userData2'] = '';
		$xmlParams['aid'] = $aid;
		$xmlParams['ok_page'] = $okPage;
		$xmlParams['return_url'] = $returnUrl;
		$xmlParams['amount'] = (int) Billrun_Factory::config()->getConfigValue('CG.conf.amount', 100);
		$account = Billrun_Factory::account();
		$account->load(array('aid' => (int)$aid));
		$xmlParams['language'] = isset($account->pay_page_lang) ? $account->pay_page_lang : "ENG";
		$xmlParams['addFailPage'] = $failPage ? '<errorUrl>' . $failPage  . '</errorUrl>' : '';

		return $this->getXmlStructureByParams($credentials, $xmlParams);
	}

	protected function updateRedirectUrl($result) {
		if (function_exists("simplexml_load_string")) {
			if (strpos(strtoupper($result), 'HEB')) {
				$result = iconv("utf-8", "iso-8859-8", $result);
			}
			$xmlObj = simplexml_load_string($result);

			if (isset($xmlObj->response->doDeal->mpiHostedPageUrl)) {

				$this->redirectUrl = (string)$xmlObj->response->doDeal->mpiHostedPageUrl;
			} else {
				Billrun_Factory::log("Error: " . 'Error Code: ' . $xmlObj->response->result .
					'Message: ' . $xmlObj->response->message .
					'Addition Info: ' . $xmlObj->response->additionalInfo, Zend_Log::ALERT);
				throw new Exception('Can\'t Create Transaction');
			}
		} else {
			die("simplexml_load_string function is not support, upgrade PHP version!");
		}
	}

	protected function buildTransactionPost($txId, $additionalParams) {
		$params = $this->getGatewayCredentials();
		$params['txId'] = $txId;
		$params['tid'] = $params['redirect_terminal'];

		return $this->buildInquireQuery($params);
	}

	public function getTransactionIdName() {
		return "txId";
	}

	protected function getResponseDetails($result) {
		if (function_exists("simplexml_load_string")) {
			if (strpos(strtoupper($result), 'HEB')) {
				$result = iconv("utf-8", "iso-8859-8", $result);
			}
			$xmlObj = simplexml_load_string($result);
			// Example to print out status text
			if (!isset($xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->result))
				return false;

			$this->saveDetails['card_token'] = (string) $xmlObj->response->inquireTransactions->row->cardId;
			$this->saveDetails['card_expiration'] = (string) $xmlObj->response->inquireTransactions->row->cardExpiration;
			$this->saveDetails['aid'] = (int) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->customerData->userData1;
			$this->saveDetails['personal_id'] = (string) $xmlObj->response->inquireTransactions->row->personalId;
			$this->saveDetails['auth_number'] = (string) $xmlObj->response->inquireTransactions->row->authNumber;
			$cardNum = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->cardNo;
			$retParams['action'] = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->customerData->userData2;
			$retParams['transferred_amount'] = $this->convertReceivedAmount((int) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->total);
			$retParams['transaction_status'] = (string) $xmlObj->response->inquireTransactions->row->cgGatewayResponseXML->ashrait->response->doDeal->status;
			$fourDigits = substr($cardNum, -4);
			$retParams['four_digits'] = $this->saveDetails['four_digits'] = $fourDigits;
			$retParams['expiration_date'] = (string) $xmlObj->response->inquireTransactions->row->cardExpiration;
			if ($retParams['action'] == 'SinglePayment') {
				$this->transactionId = (string) $xmlObj->response->tranId;
			}

			return $retParams;
		} else {
			die("simplexml_load_string function is not support, upgrade PHP version!");
		}
	}

	protected function buildSetQuery() {
		return array(
			'payment_gateway.active' => array(
				'name' => $this->billrunName,
				'card_token' => (string) $this->saveDetails['card_token'],
				'card_expiration' => (string) $this->saveDetails['card_expiration'],
				'personal_id' => (string) $this->saveDetails['personal_id'],
				'transaction_exhausted' => true,
				'generate_token_time' => new MongoDate(time()),
				'auth_number' => (string) $this->saveDetails['auth_number'],
				'four_digits' => (string) $this->saveDetails['four_digits'],
			)
		);
	}

	public function getDefaultParameters() {
		$params = array("user", "password", "redirect_terminal", "charging_terminal", "mid", "endpoint_url");
		return $this->rearrangeParametres($params);
	}
	
	public function getReceiverParameters() {
		$params = array("host", "user", "password", "remote_directory");
		return $this->rearrangeParametres($params);
	}

	public function getExportParameters() {
		$params = array("server", "user", "pw", "dir");
		return $this->rearrangeParametres($params);
	}

	public function authenticateCredentials($params) {
		$params['txId'] = 1;
		$authArray = $this->buildInquireQuery($params);
		$authString = http_build_query($authArray);
		if (function_exists("curl_init")) {
			Billrun_Factory::log("Sending to Credit Guard (authenticateCredentials): " . $params['endpoint_url'] . ' ' . $authString, Zend_Log::DEBUG);
			$result = Billrun_Util::sendRequest($params['endpoint_url'], $authString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		if (strpos(strtoupper($result), 'HEB')) {
			$result = iconv("utf-8", "iso-8859-8", $result);
		}
		$xmlObj = simplexml_load_string($result);
		$codeResult = (string) $xmlObj->response->result;
		Billrun_Factory::log("Credit Guard response (authenticateCredentials):" . print_r($xmlObj, 1), Zend_Log::DEBUG);
		if ($codeResult == "405" || empty($result)) {
			Billrun_Factory::log("Credit Guard error (authenticateCredentials):" . print_r($xmlObj, 1), Zend_Log::ERR);
			return false;
		} else {
			return true;
		}
	}

	public function pay($gatewayDetails) {
		$paymentArray = $this->buildPaymentRequset($gatewayDetails, 'Debit');
		return $this->sendPaymentRequest($paymentArray);
	}

	protected function buildPaymentRequset($gatewayDetails, $transactionType) {
		$credentials = $this->getGatewayCredentials();
		$gatewayDetails['amount'] = $this->convertAmountToSend($gatewayDetails['amount']);

		return $post_array = array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>
								<request>
								<command>doDeal</command>
								<requestId>23468</requestId>
								<version>1001</version>
								<language>Eng</language>
								<mayBeDuplicate>0</mayBeDuplicate>
									<doDeal>
										<terminalNumber>' . $credentials['charging_terminal'] . '</terminalNumber>
										<cardId>' . $gatewayDetails['card_token'] . '</cardId>
										<cardExpiration>' . $gatewayDetails['card_expiration'] . '</cardExpiration>
										<creditType>RegularCredit</creditType>
										<currency>' . $gatewayDetails['currency'] . '</currency>
										<transactionCode>Phone</transactionCode>
										<transactionType>' . $transactionType . '</transactionType>
										<total>' . abs($gatewayDetails['amount']) . '</total>
										<validation>AutoComm</validation>
									</doDeal>
								</request>
						</ashrait>'
		);
	}

	public function verifyPending($txId) {
		
	}

	public function hasPendingStatus() {
		return false;
	}
	
	protected function buildInquireQuery($params){
		return array(
			'user' => $params['user'],
			'password' => $params['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>
							<request>
							 <language>HEB</language>
							 <command>inquireTransactions</command>
							 <inquireTransactions>
							  <terminalNumber>' . $params['redirect_terminal'] . '</terminalNumber>
							  <mainTerminalNumber/>
							  <queryName>mpiTransaction</queryName>
							  <mid>' . (int)$params['mid'] . '</mid>
							  <mpiTransactionId>' . $params['txId'] . '</mpiTransactionId>
							  <mpiValidation>Token</mpiValidation>
							  <userData1/>
							  <userData2/>
							  <userData3/>
							  <userData4/>
							  <userData5/>
							 </inquireTransactions>
							</request>
					   </ashrait>'
		);
	}
	
	protected function isRejected($status) {
		return (!$this->isCompleted($status) && !$this->isPending($status));
	}
	
	protected function convertAmountToSend($amount) {
		$amount = round($amount, 2);
		return $amount * 100;
	}
	
	protected function convertReceivedAmount($amount) {
		return $amount / 100;
	}

	protected function isNeedAdjustingRequest(){
		return true;
	}
	
	protected function isUrlRedirect() {
		return true;
	}
	
	protected function isHtmlRedirect() {
		return false;
	}
		
	protected function needRequestForToken() {
		return true;
	}
	
	public function handleOkPageData($txId) {
		return true;
	}
	
	protected function validateStructureForCharge($structure) {
		return !empty($structure['card_token']) && !empty($structure['card_expiration']) && !empty($structure['personal_id']);
	}
	
	protected function handleTokenRequestError($response, $params) {
		return false;
	}

	protected function credit($gatewayDetails) {
		$paymentArray = $this->buildPaymentRequset($gatewayDetails, 'Credit');
		return $this->sendPaymentRequest($paymentArray);
	}
	
	protected function sendPaymentRequest($paymentArray) {
		$additionalParams = array();
		$paymentString = http_build_query($paymentArray);
		if (function_exists("curl_init")) {
			$result = Billrun_Util::sendRequest($this->EndpointUrl, $paymentString, Zend_Http_Client::POST, array('Accept-encoding' => 'deflate'), null, 0);
		}
		if (strpos(strtoupper($result), 'HEB')) {
			$result = iconv("utf-8", "iso-8859-8", $result);
		}
		$xmlObj = simplexml_load_string($result);
		$codeResult = (string) $xmlObj->response->result;
		$this->transactionId = (string) $xmlObj->response->tranId;
		$slaveNumber = (string) $xmlObj->response->doDeal->slaveTerminalNumber;
		$slaveSequence = (string) $xmlObj->response->doDeal->slaveTerminalSequence;
		$voucherNumber = $slaveNumber . $slaveSequence;
		if (!empty($voucherNumber)) {
			$additionalParams['payment_identifier'] = $voucherNumber;
		}
		return array('status' => $codeResult, 'additional_params' => $additionalParams);
	}
	
	public function handleTransactionRejectionCases($responseFromGateway, $paymentParams) {
		if ($responseFromGateway['stage'] != 'Rejected') {
			return false;
		}
		$cgConfig = Billrun_Factory::config()->getConfigValue('creditguard');
		$gatewayDetails = $paymentParams['gateway_details'];
		$updatedPaymentParams = $paymentParams;
		if ($responseFromGateway['status'] == $cgConfig['card_expiration_rejection_code'] && $this->isCreditCardExpired($gatewayDetails['card_expiration'], $cgConfig['oldest_card_expiration'])) {
			$this->account->load(array('aid' => $paymentParams['aid']));
			$updatedPaymentParams['gateway_details']['card_expiration'] = $gatewayDetails['card_expiration'] = substr($gatewayDetails['card_expiration'], 0, 2) . ((substr($gatewayDetails['card_expiration'], 2, 4) + 3) % 100);
			$accountGateway = $this->account->payment_gateway;
			$accountGateway['active']['card_expiration'] = $gatewayDetails['card_expiration'];
			if (isset($accountGateway['active']['generate_token_time']->sec)) {
				$accountGateway['active']['generate_token_time'] = date("Y-m-d H:i:s", $accountGateway['active']['generate_token_time']->sec);
			}
			$time = date(Billrun_Base::base_datetimeformat);
			$query = array(
				'aid' => $paymentParams['aid'],
				'type' => 'account',
				'effective_date' => $time,
			);
			$update = array(
				'from' => $time,
				'payment_gateway' => $accountGateway,
			);
			Billrun_Factory::log("Updating expiration date for aid=" . $paymentParams['aid'] . " to date " . $gatewayDetails['card_expiration'], Zend_Log::DEBUG);
			try {
				$this->account->permanentChange($query, $update);
				Billrun_Factory::log("Expiration date was updated for aid=" . $paymentParams['aid'] . " to " . $gatewayDetails['card_expiration'], Zend_Log::DEBUG);
			} catch (Exception $ex) {
				Billrun_Factory::log("Expiration date " . $gatewayDetails['card_expiration'] . " was failed to update for aid=" . $paymentParams['aid'], Zend_Log::ALERT);
				return false;
			}
			
			return $updatedPaymentParams;
		}
		
		return false;
	}
	
	protected function isCreditCardExpired($expiration, $oldestCardExpiration) {
		$expires = \DateTime::createFromFormat('my', $expiration);
		$dateTooOld = new DateTime($oldestCardExpiration);
		if ($expires < $dateTooOld) {
			Billrun_Factory::log("Expiration date " . $expires->date . " is too old", Zend_Log::DEBUG);
			return false;
		}
		
		return $expires < new DateTime();
	}

	protected function buildSinglePaymentArray($params) {
		$credentials = $this->getGatewayCredentials();
		$xmlParams['version'] = '1001';
		$xmlParams['mpiValidation'] = 'AutoComm';
		$xmlParams['userData2'] = 'SinglePayment';
		$xmlParams['aid'] = $params['aid'];
		$xmlParams['ok_page'] = $params['ok_page'];
		$xmlParams['return_url'] = $params['return_url'];
		$xmlParams['amount'] = (int) $this->convertAmountToSend($params['amount']);
		$today = new MongoDate();
		$account = $this->subscribers->query(array('aid' => (int) $params['aid'], 'from' => array('$lte' => $today), 'to' => array('$gte' => $today), 'type' => "account"))->cursor()->current();
		$xmlParams['language'] = isset($account['pay_page_lang']) ? $account['pay_page_lang'] : "ENG";
		$xmlParams['addFailPage'] = $params['fail_page'] ? '<errorUrl>' . $params['fail_page']  . '</errorUrl>' : '';
		
		return $this->getXmlStructureByParams($credentials, $xmlParams);
	}
	
	protected function getXmlStructureByParams($credentials, $xmlParams) {
		return array(
			'user' => $credentials['user'],
			'password' => $credentials['password'],
			/* Build Ashrait XML to post */
			'int_in' => '<ashrait>                                      
							<request>
								 <version>' . $xmlParams['version'] . '</version>
								 <language>' . $xmlParams['language'] . '</language>
								 <dateTime/>
								 <command>doDeal</command>
								 <doDeal>
										  <successUrl>' . $xmlParams['ok_page'] . '</successUrl>
										  '. $xmlParams['addFailPage']  .'
										  <terminalNumber>' . $credentials['redirect_terminal'] . '</terminalNumber>
										  <mainTerminalNumber/>
										  <cardNo>CGMPI</cardNo>
										  <total>' . $xmlParams['amount'] . '</total>
										  <transactionType>Debit</transactionType>
										  <creditType>RegularCredit</creditType>
										  <currency>ILS</currency>
										  <transactionCode>Phone</transactionCode>
										  <authNumber/>
										  <numberOfPayments/>
										  <firstPayment/>
										  <periodicalPayment/>
										  <validation>TxnSetup</validation>
										  <dealerNumber/>
										  <user>something</user>
										  <mid>' . (int) $credentials['mid'] . '</mid>
										  <uniqueid>' . time() . rand(100, 1000) . '</uniqueid>
										  <mpiValidation>' . $xmlParams['mpiValidation'] . '</mpiValidation>
										  <customerData>
										   <userData1>' . $xmlParams['aid'] . '</userData1>
										   <userData2>' . $xmlParams['userData2'] . '</userData2>
										   <userData3/>
										   <userData4/>
										   <userData5/>
										   <userData6/>
										   <userData7/>
										   <userData8/>
										   <userData9/>
										   <userData10/>
										  </customerData>
								 </doDeal>
							</request>
						   </ashrait>'
		);
	}
}