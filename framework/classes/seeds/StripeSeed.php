<?php
/**
 * The StripeSeed class speaks to the Stripe API.
 *
 * @package platform.org.cashmusic
 * @author CASH Music
 * @link http://cashmusic.org/
 *
 * began with official Paypal SDK examples, much editing later...
 * original script(s) here:
 * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/library_download_sdks#NVP
 *
 * Copyright (c) 2013, CASH Music
 * Licensed under the GNU Lesser General Public License version 3.
 * See http://www.gnu.org/licenses/lgpl-3.0.html
 *
 *
 * This file is generously sponsored by Justin Miranda
 *
 **/
//namespace Seeds\PaypalSeed;

require CASH_PLATFORM_ROOT  . '/lib/stripe/init.php';




class StripeSeed extends SeedBase {
	protected $client_id, $client_secret, $publishable_key, $error_message;

	public function __construct($user_id, $connection_id) {
		$this->settings_type = 'com.stripe';
		$this->user_id = $user_id;
		$this->connection_id = $connection_id;

		if ($this->getCASHConnection()) {

			$this->client_id  = $this->settings->getSetting('client_id');
			$this->client_secret = $this->settings->getSetting('client_secret');
			$this->publishable_key = $this->settings->getSetting('publishable_key');
			$sandboxed           = $this->settings->getSetting('sandboxed');

			\Stripe\Stripe::setApiKey($this->client_secret);

			if (!$this->client_id || !$this->client_secret || !$this->publishable_key) {
				$connections = CASHSystem::getSystemSettings('system_connections');

				if (isset($connections['com.stripe'])) {
					$this->merchant_email = $this->settings->getSetting('merchant_email'); // present in multi
					$this->account   = $connections['com.stripe']['account'];
					$this->client_id   = $connections['com.stripe']['client_id'];
					$this->secret  = $connections['com.stripe']['secret'];
					$sandboxed            = $connections['com.stripe']['sandboxed'];

					$this->api_context = new \PayPal\Rest\ApiContext(
						new \PayPal\Auth\OAuthTokenCredential(
							$this->client_id,		# ClientID
							$this->secret			# ClientSecret
						)
					);

					if ($sandboxed) {
						$this->api_context->setConfig(
							array("mode" => "sandbox")
						);
					}
				}
			}


		} else {
			$this->error_message = 'could not get connection settings';
		}
	}

	public static function getRedirectMarkup($data=false) {
		$connections = CASHSystem::getSystemSettings('system_connections');

		// I don't like using ADMIN_WWW_BASE_PATH below, but as this call is always called inside the
		// admin I'm just going to do it. Without the full path in the form this gets all fucky
		// and that's no bueno.

		if (isset($connections['com.paypal'])) {
			$return_markup = '<h4>Paypal</h4>'
						   . '<p>You\'ll need a verified Business or Premier Paypal account to connect properly. '
						   . 'Those are free upgrades, so just double-check your address and enter it below. You '
						   . 'can learn more about what they entail <a href="https://cms.paypal.com/cgi-bin/?cmd=_render-content&content_ID=developer/EC_setup_permissions">here</a>.</p>'
						   . '<form accept-charset="UTF-8" method="post" id="paypal_connection_form" action="' . ADMIN_WWW_BASE_PATH . '/settings/connections/add/com.paypal">'
						   . '<input type="hidden" name="dosettingsadd" value="makeitso" />'
						   . '<input type="hidden" name="permission_type" value="accelerated" />'
						   . '<input id="connection_name_input" type="hidden" name="settings_name" value="(Paypal)" />'
						   . '<input type="hidden" name="settings_type" value="com.paypal" />'
						   . '<label for="merchant_email">Your Paypal email address:</label>'
						   . '<input type="text" name="merchant_email" id="merchant_email" value="" />'
						   . '<br />'
						   . '<div><input class="button" type="submit" value="Add The Connection" /></div>'
						   . '</form>'
						   . '<script type="text/javascript">'
						   . '$("#paypal_connection_form").submit(function() {'
						   . '	var newvalue = $("#merchant_email").val() + " (Paypal)";'
						   . '	$("#connection_name_input").val(newvalue);'
						   . '});'
						   . '</script>';
			return $return_markup;
		} else {
			return 'Please add default paypal api credentials.';
		}
	}

	protected function setErrorMessage($msg) {
		$this->error_message = $msg;
	}

	public function getErrorMessage() {
		return $this->error_message;
	}

	protected function postToPaypal($method_name, $nvp_parameters) {
		// Set the API operation, version, and API signature in the request.
		$request_parameters = array (
			'METHOD'    => $method_name,
			'VERSION'   => $this->api_version,
			'PWD'       => $this->api_password,
			'USER'      => $this->api_username,
			'SIGNATURE' => $this->api_signature
		);
		if ($this->merchant_email) {
			$request_parameters['SUBJECT'] = $this->merchant_email;
		}
		$request_parameters = array_merge($request_parameters,$nvp_parameters);

		// Get response from the server.
		$http_response = CASHSystem::getURLContents($this->api_endpoint,$request_parameters,true);
		if ($http_response) {
			// Extract the response details.
			$http_response = explode("&", $http_response);
			$parsed_response = array();
			foreach ($http_response as $i => $value) {
				$tmpAr = explode("=", $value);
				if(sizeof($tmpAr) > 1) {
					$parsed_response[$tmpAr[0]] = urldecode($tmpAr[1]);
				}
			}

			if((0 == sizeof($parsed_response)) || !array_key_exists('ACK', $parsed_response)) {
				$this->setErrorMessage("Invalid HTTP Response for POST (" . $nvpreq . ") to " . $this->api_endpoint);
				return false;
			}

			if("SUCCESS" == strtoupper($parsed_response["ACK"]) || "SUCCESSWITHWARNING" == strtoupper($parsed_response["ACK"])) {
				return $parsed_response;
			} else {
				$this->setErrorMessage(print_r($parsed_response, true));
				return false;
			}
		} else {
			$this->setErrorMessage('could not reach Paypal servers');
			return false;
		}
	}

	public function setCheckout(
		$payment_amount,
		$ordersku,
		$ordername,
		$return_url,
		$cancel_url,
		$request_shipping_info=true,
		$allow_note=false,
		$currency_id='USD', /* 'USD', 'GBP', 'EUR', 'JPY', 'CAD', 'AUD' */
		$payment_type='sale', /* 'Sale', 'Order', or 'Authorization' */
		$invoice=false,
		$shipping_amount=false
	) {

		$payer = new Payer();
		$payer->setPaymentMethod("paypal");



		$amount = new Amount();
		$amount->setCurrency($currency_id)
			->setTotal($payment_amount);

		error_log("shipping + ". $shipping_amount);
		if ($request_shipping_info && $shipping_amount > 0) {
			$shipping = new Details();
			$shipping->setShipping($shipping_amount)
				//->setTax(1.3)
				->setSubtotal($payment_amount - $shipping_amount);
				//TODO: assumes shipping cost is passed in as part of the total $payment_amount

			$amount->setDetails($shipping);
		}

		$transaction = new Transaction();
		$transaction->setAmount($amount)
			->setDescription($ordername)
			->setInvoiceNumber($ordersku."farts");

		$redirectUrls = new RedirectUrls();
		$redirectUrls->setReturnUrl($return_url."&success=true")
					 ->setCancelUrl($cancel_url."&success=false");


		$payment = new Payment();
		$payment->setIntent($payment_type)
			->setPayer($payer)
			->setRedirectUrls($redirectUrls)
			->setTransactions(array($transaction));


		try { $payment->create($this->api_context); } catch (Exception $ex) {

			$error = json_decode($ex->getData());
			$this->setErrorMessage($error->message);
		}

		$approval_url = $payment->getApprovalLink();

		if (!empty($approval_url)) {
			return array(
				'redirect_url' => $approval_url,
				'data_sent' => json_encode($payment->getTransactions() )
			);
		} else {
			// approval link isn't set, return to page and post error
			$this->setErrorMessage('There was an error contacting PayPal for this payment.');
		}
	}

	public function getCheckout() {

		// check if we got a PayPal token in the return url; if not, cheese it!
		if (!empty($_GET['token'])) {

		} else {
			$this->setErrorMessage("No PayPal token was found.");
			return false;
		}

		// Determine if the user approved the payment or not
		if (!empty($_GET['success']) && $_GET['success'] == 'true' &&
			!empty($_GET['paymentId']) && !empty($_GET['PayerID'])
			) {

			// Get the payment Object by passing paymentId
			// payment id was previously stored in session in
			// CreatePaymentUsingPayPal.php
			$this->payment_id = $_GET['paymentId'];
			$payment = Payment::get($this->payment_id, $this->api_context);

			// ### Payment Execute
			// PaymentExecution object includes information necessary
			// to execute a PayPal account payment.
			// The payer_id is added to the request query parameters
			// when the user is redirected from paypal back to your site
			$execution = new PaymentExecution();
			$execution->setPayerId($_GET['PayerID']);

			try {
				// Execute the payment
				$result = $payment->execute($execution, $this->api_context);

				try {
					$payment = Payment::get($this->payment_id, $this->api_context);
				} catch (Exception $ex) {
					return false;
				}
			} catch (Exception $ex) {

				return false;
			}

			// let's return a standardized array to generalize for multiple payment types
			$details = $payment->toArray();
			// nested array for data received, standard across seeds
			//TODO: this is set for single item transactions for now; should be expanded for cart transactions

			$order_details = array(
				'transaction_description' => '',
				'customer_email' => $details['payer']['payer_info']['email'],
				'customer_first_name' => $details['payer']['payer_info']['first_name'],
				'customer_last_name' => $details['payer']['payer_info']['last_name'],
				'customer_name' => $details['payer']['payer_info']['first_name'] . " " . $details['payer']['payer_info']['last_name'],
				'customer_shipping_name' => '',
				'customer_address1' => '',
				'customer_address2' => '',
				'customer_city' => '',
				'customer_region' => '',
				'customer_postalcode' => '',
				'customer_country' => '',
				'customer_countrycode' => '',
				'customer_phone' => '',
				/* 																*/
				'transaction_date' 	=> strtotime($details['create_time']),
				'transaction_id' 	=> $details['id'],
				'sale_id'			=> $details['transactions'][0]['related_resources'][0]['sale']['id'],
				'items' 			=> array(),
				'total' 			=> $details['transactions'][0]['amount']['total'],
				'other_charges' 	=> array(),
				'transaction_fees'  => $details['transactions'][0]['related_resources'][0]['sale']['transaction_fee']['value'],
				);

			return array('total' => $details['transactions'][0]['amount']['total'],
						'payer' => $details['payer']['payer_info'],
						'timestamp' => strtotime($details['create_time']),
						'transaction_id' => $details['id'],
						'transaction_fee' => $details['transactions'][0]['related_resources'][0]['sale']['transaction_fee'],
						'order_details' => json_encode($order_details)
						);
		} else {
			return false;
		}

	}

	public function doRefund($sale_id,$refund_amount=0,$currency_id='USD') {

		$amt = new Amount();
		$amt->setCurrency($currency_id);
		$amt->setTotal($refund_amount);

		$refund = new Refund();
		$refund->setAmount($amt);

		$sale = new Sale();
		$sale->setId($sale_id);

		$refund_response = $sale->refund($refund, $this->api_context);

		if (!$refund_response) {
			$this->setErrorMessage('RefundTransaction failed: ' . $this->getErrorMessage());
			error_log($this->getErrorMessage());
			return false;
		} else {
			return $refund_response;
		}

	}
} // END class
?>