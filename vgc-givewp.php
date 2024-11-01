<?php
/**
 * Plugin Name: VGC for GiveWP
 * Plugin URI:  http://verygoodcollection.com/
 * Description: Very Good Collection add-on gateway for GiveWP.
 * Version:     1.0
 * Author:      Very Good Collection
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Give\Helpers\Form\Utils as FormUtils;

/**
 * VGC Gateway form output
 *
 * VGC Gateway does not use a CC form
 *
 * @return bool
 **/
function waf_vgc_for_give_form_output( $form_id ) {

	if ( FormUtils::isLegacyForm( $form_id ) ) {
		return false;
	}

	printf(
		'
		<fieldset class="no-fields">
			<div style="display: flex; justify-content: center;">
				<img src="'. plugin_dir_url( __FILE__ ) .'assets/images/very-good-collection-logo.png" alt="Very Good Collection" style="width: 100%%;">
			</div>
			<p style="text-align: center;"><b>%1$s</b></p>
			<p style="text-align: center;">
				<b>%2$s</b> %3$s
			</p>
		</fieldset>
	',
		__( 'Make your donation quickly and securely with Very Good Collection', 'give' ),
		__( 'How it works:', 'give' ),
		__( 'You will be redirected to Very Good Collection to pay using your Very Good Collection account or credit/debit card. You will then be brought back to this page to view your receipt.', 'give' )
	);

	return true;

}
add_action( 'give_vgc_cc_form', 'waf_vgc_for_give_form_output' );


/**
 * Register payment method.
 *
 * @since 1.0.0
 *
 * @param array $gateways List of registered gateways.
 *
 * @return array
 */
function waf_vgc_for_give_register_payment_method( $gateways ) {
  
    // Duplicate this section to add support for multiple payment method from a custom payment gateway.
    $gateways['vgc'] = array(
      'admin_label'    => 'Very Good Collection', 
      'checkout_label' => 'Very Good Collection',
    );
    
    return $gateways;
  }
  
add_filter( 'give_payment_gateways', 'waf_vgc_for_give_register_payment_method' );

/**
 * Register Section for Payment Gateway Settings.
 *
 * @param array $sections List of payment gateway sections.
 *
 * @since 1.0.0
 *
 * @return array
 */
function waf_vgc_for_give_register_payment_gateway_sections( $sections ) {
	
	// `vgc-settings` is the name/slug of the payment gateway section.
	$sections['vgc-settings'] = 'Very Good Collection';

	return $sections;
}

add_filter( 'give_get_sections_gateways', 'waf_vgc_for_give_register_payment_gateway_sections' );

// Get currently supported currencies from very good collection endpoint
function waf_vgc_for_give_get_supported_currencies($string = false){
	$currency_request = wp_remote_get("https://verygoodcollection.com/api/currency-supported2");
	$currency_array = array();
	if ( ! is_wp_error( $currency_request ) && 200 == wp_remote_retrieve_response_code( $currency_request ) ){
		$currencies = json_decode(wp_remote_retrieve_body($currency_request));
		if($currencies->currency_code && $currencies->currency_name){
			foreach ($currencies->currency_code as $index => $item){
				if($string === true){
					$currency_array[] = $currencies->currency_name[$index];
				}else{
					$currency_array[$currencies->currency_code[$index]] = $currencies->currency_name[$index];
				}
			}
		}
	}
	if($string === true){
		return implode(", ", $currency_array);
	}
	return $currency_array;
}

/**
 * Register Admin Settings.
 *
 * @param array $settings List of admin settings.
 *
 * @since 1.0.0
 *
 * @return array
 */
function waf_vgc_for_give_register_payment_gateway_setting_fields( $settings ) {

	switch ( give_get_current_setting_section() ) {

		case 'vgc-settings':
			$settings = array(
				array(
					'id'   => 'give_title_vgc',
                    'desc' => 'Our Supported Currencies: <strong>'.waf_vgc_for_give_get_supported_currencies(true).'.</strong>',
					'type' => 'title',
				),
				array(
					'id'   => 'vgc-invoicePrefix',
					'name' => 'Invoice Prefix',
					'desc' => 'Please enter a prefix for your invoice numbers. If you use your Very Good Collection account for multiple stores ensure this prefix is unique as Very Good Collection will not allow orders with the same invoice number.',
					'type' => 'text',
				),
				array(
					'id'   => 'vgc-merchantKey',
					'name' => 'Merchant Key',
					'desc' => 'Required: Enter your Merchant Key here. You can get your Public Key from <a href="https://verygoodcollection.com/user/merchant">here</a>',
					'type' => 'text',
				),
                array(
					'id'   => 'vgc-publicKey',
					'name' => 'Public Key',
					'desc' => 'Required: Enter your Public Key here. You can get your Public Key from <a href="https://verygoodcollection.com/user/api">here</a>',
					'type' => 'text',
				),
                array(
					'id'   => 'vgc-secretKey',
					'name' => 'Secret Key',
					'desc' => 'Required: Enter your Secret Key here. You can get your Secret Key from <a href="https://verygoodcollection.com/user/api">here</a>',
					'type' => 'text',
				),
                array(
                    'id'   => 'give_title_vgc',
                    'type' => 'sectionend',
                )
			);

			break;

	} // End switch().

	return $settings;
}

add_filter( 'give_get_settings_gateways', 'waf_vgc_for_give_register_payment_gateway_setting_fields' );


/**
 * Process Very Good Collection checkout submission.
 *
 * @param array $posted_data List of posted data.
 *
 * @since  1.0.0
 * @access public
 *
 * @return void
 */
function waf_vgc_for_give_process( $posted_data ) {

	// Make sure we don't have any left over errors present.
	give_clear_errors();

	// Any errors?
	$errors = give_get_errors();

	// No errors, proceed.
	if ( ! $errors ) {

		$form_id         = intval( $posted_data['post_data']['give-form-id'] );
		$price_id        = ! empty( $posted_data['post_data']['give-price-id'] ) ? $posted_data['post_data']['give-price-id'] : 0;
		$donation_amount = ! empty( $posted_data['price'] ) ? $posted_data['price'] : 0;
		$payment_mode = !empty( $posted_data['post_data']['give-gateway'] ) ? $posted_data['post_data']['give-gateway'] : '';
		$redirect_to_url  = ! empty( $posted_data['post_data']['give-current-url'] ) ? $posted_data['post_data']['give-current-url'] : site_url();

		// Setup the payment details.
		$donation_data = array(
			'price'           => $donation_amount,
			'give_form_title' => $posted_data['post_data']['give-form-title'],
			'give_form_id'    => $form_id,
			'give_price_id'   => $price_id,
			'date'            => $posted_data['date'],
			'user_email'      => $posted_data['user_email'],
			'purchase_key'    => $posted_data['purchase_key'],
			'currency'        => give_get_currency( $form_id ),
			'user_info'       => $posted_data['user_info'],
			'status'          => 'pending',
			'gateway'         => 'vgc',
		);

		// Record the pending donation.
		$donation_id = give_insert_payment( $donation_data );

		if ( ! $donation_id ) {

			// Record Gateway Error as Pending Donation in Give is not created.
			give_record_gateway_error(
				__( 'Instamojo Error', 'instamojo-for-give' ),
				sprintf(
				/* translators: %s Exception error message. */
					__( 'Unable to create a pending donation with Give.', 'instamojo-for-give' )
				)
			);

			// Send user back to checkout.
			give_send_back_to_checkout( '?payment-mode=vgc' );
			return;
		}

        // Vgc args
        $merchant_key = give_get_option( 'vgc-merchantKey' );
        $public_key = give_get_option( 'vgc-publicKey' );
		$secret_key = give_get_option( 'vgc-secretKey' );
        $tx_ref = give_get_option( 'vgc-invoicePrefix' ) . '_' . $donation_id;
        $currency_array = waf_vgc_for_give_get_supported_currencies();
        $currency_code = array_search( give_get_currency( $form_id ), $currency_array );
        $first_name = $donation_data['user_info']['first_name'];
        $last_name = $donation_data['user_info']['last_name'];
        $email = $donation_data['user_email'];
        $title = "Payment For Items on " . get_bloginfo('name');
        $callback_url = get_site_url() . "/wp-json/waf-vgc-for-give/v1/process-success?donation_id=". $donation_id ."&tx_ref=" . $tx_ref . 
		"&secret_key=" . $secret_key . "&form_id=" . $form_id . "&redirect_to_url=" . rawurlencode($redirect_to_url) . "&price_id=" . $price_id;

		// Validate data before send payment VGC request
		$invalid = 0;
		$error_msg = array();
        if ( !empty($merchant_key) && !empty($public_key) && !empty($secret_key) && wp_http_validate_url($callback_url) ) {
            $merchant_key = sanitize_text_field($merchant_key);
            $public_key = sanitize_text_field($public_key);
			$secret_key = sanitize_text_field($secret_key);
            $callback_url = esc_url($callback_url);
        } else {
			array_push($error_msg, 'The payment setting of this website is not correct, please contact Administrator');
            $invalid++;
        }
        if ( !empty($tx_ref) ) {
            $tx_ref = sanitize_text_field($tx_ref);
        } else {
			array_push($error_msg, 'It seems that something is wrong with your order. Please try again');
            $invalid++;
        }
        if ( !empty($donation_amount) && is_numeric($donation_amount) ) {
            $donation_amount = floatval(sanitize_text_field($donation_amount));
        } else {
			array_push($error_msg, 'It seems that you have submitted an invalid donation amount for this order. Please try again');
            $invalid++;
        }
        if ( !empty($email) && is_email($email) ) {
            $email = sanitize_email($email);
        } else {
			array_push($error_msg, 'Your email is empty or not valid. Please check and try again');
            $invalid++;
        }
        if ( !empty($first_name) ) {
            $first_name = sanitize_text_field($first_name);
        } else {
			array_push($error_msg, 'Your first name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if ( !empty($last_name) ) {
            $last_name = sanitize_text_field($last_name);
        } else {
			array_push($error_msg, 'Your last name is empty or not valid. Please check and try again');
            $invalid++;
        }
        if ( !empty($title) ) {
            $title = sanitize_text_field($title);
        } else {
			array_push($error_msg, 'The order title is empty or not valid. Please check and try again');
            $invalid++;
        }
        if ( !empty($currency_code) && is_numeric($currency_code) ) {
            $currency_code = sanitize_text_field($currency_code);
        } else {
			array_push($error_msg, 'The currency code is not valid. Please check and try again');
            $invalid++;
        }

		$target = '_top';
		$redirect_message = 'We are redirecting to Very Good Collection in new tab. You can close this tab now...';

		if ( FormUtils::isLegacyForm( $form_id ) ) {
			$target = '';
			$redirect_message = 'We are redirecting to Very Good Collection, please wait ...';
		}

		if ( $invalid === 0 ) {
			?>	

			<!DOCTYPE html>
			<html>
			<head>
				<title>Very Good Collection Secure Verification</title>
				<script language="Javascript">
					window.onload = function(){
						document.forms['waf_vgc_payment_post_form'].submit();
					}
				</script>
			</head>
			<body>
				<div>
				</div>
				<h3><?php echo esc_html($redirect_message); ?></h3>
				<form id="waf_vgc_payment_post_form" target="<?php echo $target; ?>" name="waf_vgc_payment_post_form" method="POST" action="https://verygoodcollection.com/ext_transfer" >
					<input type="hidden" name="merchant_key" value="<?php esc_attr_e($merchant_key); ?>" />
					<input type="hidden" name="public_key" value="<?php esc_attr_e($public_key);  ?>" />
					<input type="hidden" name="callback_url" value="<?php echo esc_url($callback_url);  ?>" />
					<input type="hidden" name="return_url" value="<?php echo esc_url($callback_url);  ?>" />
					<input type="hidden" name="tx_ref" value="<?php esc_attr_e($tx_ref);  ?>" />
					<input type="hidden" name="amount" value="<?php esc_attr_e($donation_amount);  ?>" />
					<input type="hidden" name="email" value="<?php esc_attr_e($email); ?>" />
					<input type="hidden" name="first_name" value="<?php esc_attr_e($first_name); ?>" />
					<input type="hidden" name="last_name" value="<?php esc_attr_e($last_name); ?>" />
					<input type="hidden" name="title" value="<?php esc_attr_e($title); ?>" />
					<input type="hidden" name="description" value="<?php esc_attr_e($title); ?>" />
					<input type="hidden" name="quantity" value="1" />
					<input type="hidden" name="currency" value="<?php esc_attr_e($currency_code); ?>" />
					<input type="submit" value="submit" style="display: none"/>
				</form>
			</body>
			</html>

			<?php

			die();
			
		} else {
			give_set_error( 'vgc_validate_error', implode("<br>", $error_msg) );
			give_send_back_to_checkout( '?payment-mode=vgc' );
		}


	} else {

		give_send_back_to_checkout( '?payment-mode=vgc' );

		die();

	}
}
add_action( 'give_gateway_vgc', 'waf_vgc_for_give_process' );


// Register process success rest api
add_action('rest_api_init', 'waf_vgc_for_give_add_callback_url_endpoint_process_success');

function waf_vgc_for_give_add_callback_url_endpoint_process_success() {
	register_rest_route(
		'waf-vgc-for-give/v1/',
		'process-success',
		array(
			'methods' => 'GET',
			'callback' => 'waf_vgc_for_give_process_success'
		)
	);
}

// Callback function of process success rest api
function waf_vgc_for_give_process_success($request_data) {

	$parameters = $request_data->get_params();
    $tx_ref = $parameters['tx_ref'];
    $secret_key = $parameters['secret_key'];
	$payment_mode = $parameters['payment_mode'];
    $donation_id = intval(sanitize_text_field($parameters['donation_id']));
	$price_id = $parameters['price_id'];
	$form_id = $parameters['form_id'];
	$redirect_to_url = $parameters['redirect_to_url'];

	if ( $donation_id ) {

		// Verify VGC payment
		$vgc_request = wp_remote_get("https://verygoodcollection.com/api/verify-payment/{$tx_ref}/{$secret_key}");

		if (!is_wp_error($vgc_request) && 200 == wp_remote_retrieve_response_code($vgc_request)) {
			$vgc_payment = json_decode(wp_remote_retrieve_body($vgc_request));
			$status = $vgc_payment->status;
			$reference_id = $vgc_payment->data->reference;

			if ( $status === "success" ) {

                give_update_payment_status( $donation_id, 'publish' );
				give_set_payment_transaction_id( $donation_id, $reference_id );
                give_insert_payment_note( $donation_id, "Payment via Very Good Collection successful with Reference ID: " . $reference_id );
				give_send_to_success_page();

				die();

			} elseif ($status === "cancelled") {

                give_update_payment_status( $donation_id, 'failed' );
                give_insert_payment_note( $donation_id, "Payment was canceled.");
                give_set_error( 'vgc_request_error', "Payment was canceled." );
				wp_redirect( $redirect_to_url . "?form-id=" . $form_id . "&level-id=" . $price_id . "&payment-mode=vgc#give-form-" . $form_id . "-wrap" );

				die();

			} else {

                give_update_payment_status( $donation_id, 'failed' );
                give_insert_payment_note( $donation_id, "Payment was declined by Very Good Collection.");
				give_set_error( 'vgc_request_error', "Payment was declined by Very Good Collection." );
				wp_redirect( $redirect_to_url . "?form-id=" . $form_id . "&level-id=" . $price_id . "&payment-mode=vgc#give-form-" . $form_id . "-wrap" );
				
				die();

			}
		}
	}
	die();
}