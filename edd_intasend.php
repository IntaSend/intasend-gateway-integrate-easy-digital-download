<?php
/*
Plugin Name: Easy Digital Downloads - Intasend Gateway
Description: A Intasend gateway for Easy Digital Downloads
Version: 1.0
Author: Kishan Patel
Contributors: Kishan Patel
*/
 
 
// registers the gateway
function pw_edd_register_gateway($gateways) {
	$gateways['intasend_gateway'] = array('admin_label' => 'Intasend Gateway', 'checkout_label' => __('Intasend Gateway', 'pw_edd'));
	return $gateways;
}
add_filter('edd_payment_gateways', 'pw_edd_register_gateway');
 
function pw_edd_intasend_gateway_cc_form() {
	// register the action to remove default CC form
	return;
}
add_action('edd_intasend_gateway_cc_form', 'pw_edd_intasend_gateway_cc_form');
 
// processes the payment
function pw_edd_process_payment($purchase_data) {
	global $edd_options;
 
	/**********************************
	* set transaction mode
	**********************************/
	if(edd_is_test_mode()) {
		// set test credentials here
		$secret_key = trim( $edd_options['intasend_gateway_test_api_key'] );
        $checkout_url='https://sandbox.intasend.com';
	} else {
		// set live credentials here
		$secret_key = trim( $edd_options['intasend_gateway_live_api_key'] );
        $checkout_url='https://payment.intasend.com';
	}
 
	/**********************************
	* check for errors here
	**********************************/
 
	/*
	// errors can be set like this
	if(!isset($_POST['card_number'])) {
		// error code followed by error message
		edd_set_error('empty_card', __('You must enter a card number', 'edd'));
	}
	*/
 
	// check for any stored errors
	$errors = edd_get_errors();
	if(!$errors) {
 
		$purchase_summary = edd_get_purchase_summary($purchase_data);
 
		/**********************************
		* setup the payment details
		**********************************/
 
		$payment = array( 
			'price' => $purchase_data['price'], 
			'date' => $purchase_data['date'], 
			'user_email' => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency' => $edd_options['currency'],
			'downloads' => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info' => $purchase_data['user_info'],
			'status' => 'pending'
		);
 
		// record the pending payment
		$payment = edd_insert_payment($payment);
 
		$merchant_payment_confirmed = false;
 
		/**********************************
		* Process the credit card here.
		* If not using a credit card
		* then redirect to merchant
		* and verify payment with an IPN
		**********************************/
        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $checkout_url.'/api/v1/checkout/',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        curl_setopt($curl,CURLOPT_RETURNTRANSFER,TRUE),
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'public_key' => $secret_key,
            'redirect_url'=>get_site_url().'/?payment='.$payment,
            'api_ref'=>$payment,
            'amount' => $purchase_data['price'],
            'currency' => $edd_options['currency'],
            'email' => $purchase_data['user_info']['user_email'],
            'first_name' => $purchase_data['user_info']['first_name'],
            'last_name' => $purchase_data['user_info']['last_name'],
            'country' => 'US'
        ),
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json'
        ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $resp=json_decode($response,true);
        echo '<script>window.location.replace("'.$resp['url'].'");</script>';
        die();
 
 
	} else {
		$fail = true; // errors were detected
	}
 
	if( $fail !== false ) {
		// if errors are present, send the user back to the purchase page so they can be corrected
		edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
	}
}
add_action('edd_gateway_intasend_gateway', 'pw_edd_process_payment');

function my_custom_url_handler() {
    global $edd_options;
    if( isset($_GET['tracking_id']) ) {
        $payment=$_GET['payment'];
        $tracking_id=$_GET['tracking_id'];
        $signature=$_GET['signature'];
        $checkout_id=$_GET['checkout_id'];

        if(edd_is_test_mode()) {
            // set test credentials here
            $secret_key = trim( $edd_options['intasend_gateway_test_api_key'] );
            $checkout_url='https://sandbox.intasend.com';
        } else {
            // set live credentials here
            $secret_key = trim( $edd_options['intasend_gateway_live_api_key'] );
            $checkout_url='https://payment.intasend.com';
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
        CURLOPT_URL => $checkout_url . "/api/v1/payment/status/",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => array(
            'public_key' => $secret_key,
            'invoice_id' => $tracking_id,
            'signature' => $signature,
            'checkout_id' => $checkout_id,
        ),
        CURLOPT_HTTPHEADER => array(
            'Accept: application/json'
        ),
        ));

        $response = curl_exec($curl);

        curl_close($curl);
        $resp=json_decode($response,true);
        
        $merchant_payment_confirmed = false;	
        
        // if the merchant payment is complete, set a flag
        if($resp['invoice']['state'] == 'COMPLETE'){
            $merchant_payment_confirmed = true;	
        }
    
        if($merchant_payment_confirmed) { // this is used when processing credit cards on site
    
            // once a transaction is successful, set the purchase to complete
            edd_update_payment_status($payment, 'complete');
    
            // go to the success page			
            edd_send_to_success_page();
    
        } else {
            $fail = true; // payment wasn't recorded
        }
    
        if( $fail !== false ) {
            // if errors are present, send the user back to the purchase page so they can be corrected
            edd_send_back_to_checkout();
        }
        exit();
    }
}
add_action('parse_request', 'my_custom_url_handler');
 
// adds the settings to the Payment Gateways section
function pw_edd_add_settings($settings) {
 
	$intasend_gateway_settings = array(
		array(
			'id' => 'intasend_gateway_settings',
			'name' => '<strong>' . __('Intasend Gateway Settings', 'pw_edd') . '</strong>',
			'desc' => __('Configure the gateway settings', 'pw_edd'),
			'type' => 'header'
		),
		array(
			'id' => 'intasend_gateway_live_api_key',
			'name' => __('Live API Key', 'pw_edd'),
			'desc' => __('Enter your live API key, found in your gateway Account Settins', 'pw_edd'),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'intasend_gateway_test_api_key',
			'name' => __('Test API Key', 'pw_edd'),
			'desc' => __('Enter your test API key, found in your Intasend Account Settins', 'pw_edd'),
			'type' => 'text',
			'size' => 'regular'
        )
	);
 
	return array_merge($settings, $intasend_gateway_settings);	
}
add_filter('edd_settings_gateways', 'pw_edd_add_settings');