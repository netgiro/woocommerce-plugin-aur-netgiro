<?php
/*
  Plugin Name: Aur Payments
  Plugin URI: https://aur.is
  Description: Extends WooCommerce with a <a href="https://www.aur.is/" target="_blank">Aur</a> payments.
  Version: 1.5.3
  Author: Avista
  Author URI: https://avista.is

  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html

  WC requires at least: 3.6.0
  WC tested up to: 4.2.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/*
// Update checker
require 'plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://davidhalldorsson@bitbucket.org/avista_is/woocommerce-aur-payments.git',
    __FILE__,
    'woocommerce-aur-payments'
);

//OAuth consumer
$myUpdateChecker->setAuthentication(array(
    'consumer_key' => 'ReRZXExK8uK53gqjzg',
    'consumer_secret' => 'qWWTygBA4vCmp7rvekq8HJtNfx56mtxp',
));

//Optional: Set the branch that contains the stable release.
$myUpdateChecker->setBranch('master');
*/

/**
 * Check if WooCommerce is active
 **/
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    function woocommerce_missing_warning()
    {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php _e('Aur Payments does not work of WooCommerce is not active!', 'woocommerce-aur'); ?></p>
        </div>
        <?php
    }

    add_action('admin_notices', 'woocommerce_missing_warning');
    return;
}

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'aur_add_gateway_class');
function aur_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Aur_Gateway'; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'aur_init_gateway_class');
function aur_init_gateway_class()
{

    class WC_Aur_Gateway extends WC_Payment_Gateway
    {

        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct()
        {
            $this->id = 'woocommerce_aur'; // payment gateway plugin ID
            $this->icon = plugin_dir_url(__FILE__) . 'assets/images/aur-logo.png'; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'Aur';
            $this->method_description = 'Greiða með Aur appinu'; // will be displayed on the options page
            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );
            // Method with all the options fields
            $this->init_form_fields();
            // Load the settings.
            $this->init_settings();

            $this->round_numbers = 'yes';

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->web_key = $this->get_option('web_key');
            $this->web_token = $this->get_option('web_token');
            $this->api_endpoint = $this->testmode ? 'https://test.netgiro.is/api/Checkout/InsertCart' : 'https://api.netgiro.is/v1/Checkout/InsertCart';
            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            // We need custom JavaScript to obtain a token
            add_action('wp_enqueue_scripts', array($this, 'aur_scripts'));
            // Registering a webhook here
            add_action('woocommerce_api_orderupdate', array($this, 'callback_handler'));

            // Custom message on thank you page
            add_action('woocommerce_thankyou_woocommerce_aur', array($this, 'aur_thankyou_message'), 2, 1);
        }

        public function aur_thankyou_message($order_id)
        {
            echo '<div class="aur-message"><h2 class="aur-message-h2">Opnaðu nú Aur appið þitt!</h2><p class="aur-message-p">Pöntun verður ekki afgreidd nema að greiðsla sé staðfest í Aur appinu þínu. Tölvupóstur verður sendur til staðfestingar þegar greiðsla berst.</p></div>';
        }


        /**
         * Plugin options
         */
        public function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Aur',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Aur appið',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Greiða með Aur appinu',
                ),
                'testmode' => array(
                    'title' => 'Test mode',
                    'label' => 'Enable Test Mode',
                    'type' => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default' => 'yes',
                    'desc_tip' => true,
                ),
                'web_key' => array(
                    'title' => 'Live Web Key',
                    'type' => 'text'
                ),
                'web_token' => array(
                    'title' => 'Live Web Token',
                    'type' => 'password'
                )
            );

        }

        /**
         * Create phone number form and field
         */
        public function payment_fields()
        {
            // ok, let's display some description before the payment form
            if ($this->description) {
                // you can instructions for test mode, I mean test card numbers etc.
                if ($this->testmode) {
                    $this->description .= ' TEST MODE ENABLED. In test mode, you can use your phonenumber without beeing charged';
                    $this->description = trim($this->description);
                }
                // display the description with <p> tags etc.
                echo wpautop(wp_kses_post($this->description));
            }

            // echo() the form, but you can close PHP tags and print it directly in HTML
            echo '<fieldset id="wc-' . esc_attr($this->id) . '-phone-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';

            // Add this action hook if you want your custom payment gateway to support it
            do_action('woocommerce_credit_card_form_start', $this->id);

            // Input for phone number
            echo '<div class="form-row form-row-wide"><label>Símanúmer <span class="required">*</span></label>
					<input id="aur_phone_number" name="aur_msisdn" type="text" placeholder="Símanúmer" autocomplete="off">
				</div>
				<div class="clear"></div>';

            do_action('woocommerce_credit_card_form_end', $this->id);

            echo '<div class="clear"></div></fieldset>';

        }

        /*
         * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
         */
        public function aur_scripts()
        {

            // we need JavaScript to process a token only on cart/checkout pages, right?
            if (!is_cart() && !is_checkout()) {
                return;
            }

            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ('no' === $this->enabled) {
                return;
            }

            // no reason to enqueue JavaScript if API keys are not set
            if (empty($this->web_key) || empty($this->web_token)) {
                return;
            }

            // do not work with card detailes without SSL unless your website is in a test mode
            if (!$this->testmode && !is_ssl()) {
                return;
            }

            // Custom CSS in your plugin directory
            wp_enqueue_style('aur_css', plugin_dir_url(__FILE__) . 'assets/css/aur.css');

            // and this is our custom JS in your plugin directory that works with token.js
            // wp_register_script( 'woocommerce_aur', plugins_url( 'assets/js/aur.js', __FILE__ ), array( 'jquery', 'aur_js' ) );

            // wp_localize_script( 'woocommerce_aur', 'aur_params', array(
            // 	'webKey' => $this->web_key
            // ) );

            // wp_enqueue_script( 'woocommerce_aur' );

        }

        /*
          * Fields validation
         */
        public function validate_fields()
        {

            if (empty($_POST['aur_msisdn'])) {
                wc_add_notice('<strong>Phone number</strong> is required for Aur!', 'error');
                return false;
            }
            return true;

        }

        function get_error_message()
        {
            return 'Villa kom upp við vinnslu beiðni þinnar. Vinsamlega reyndu aftur eða hafðu samband við Aur með tölvupósti á aur@aur.is';
        }

        /*
         * Get request body for InsertCart
         */
        private function get_request_body($order_id, $customer_id)
        {
            if (empty($order_id)) {
                return $this->get_error_message();
            }

            $order_id = sanitize_text_field($order_id);
            $order = new WC_Order($order_id);
            $txnid = $order_id . '_' . date("ymds");

            if (!is_numeric($order->get_total())) {
                return $this->get_error_message();
            }

            $round_numbers = $this->round_numbers;
            $payment_Confirmed_url = add_query_arg('wc-api', 'WC_netgiro_callback', home_url('/'));

            $total = round(number_format($order->get_total(), 0, '', ''));

            if ($round_numbers == 'yes') {
                $total = round($total);
            }

            // Get the plugin version
            if (!function_exists('get_plugin_data')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            $plugin_data = get_plugin_data(__FILE__);
            $plugin_version = $plugin_data['Version'];

            // Netgiro arguments
            $netgiro_args = array(
                'amount' => $total,
                'description' => $order_id,
                'reference' => $txnid,
                'customerId' => $customer_id,
                'callbackUrl' => $payment_Confirmed_url,
                'ConfirmationType' => '0',
                'locationId' => '',
                'registerId' => '',
                'clientInfo' => 'System: Woocommerce ' . $plugin_version
            );

            // Woocommerce -> Netgiro Items
            foreach ($order->get_items() as $item) {
                $validationPass = $this->validateItemArray($item);

                if (!$validationPass) {
                    return $this->get_error_message();
                }

                $unitPrice = $order->get_item_subtotal($item, true, $round_numbers == 'yes');
                $amount = $order->get_line_subtotal($item, true, $round_numbers == 'yes');

                if ($round_numbers == 'yes') {
                    $unitPrice = round($unitPrice);
                    $amount = round($amount);
                }

                $items[] = array(
                    'productNo' => $item['product_id'],
                    'name' => $item['name'],
                    'description' => $item['description'],  /* TODO Could not find description */
                    'unitPrice' => $unitPrice,
                    'amount' => $amount,
                    'quantity' => $item['qty'] * 1000
                );
            }

            $netgiro_args['cartItemRequests'] = $items;

            return $netgiro_args;
        }

        /*
         * Create the Netgiro signature
         */
        private function get_signature($nonce, $request_body)
        {
            $secretKey = sanitize_text_field($this->web_token);
            $body = json_encode($request_body);
            $str = $secretKey . $nonce . $this->api_endpoint . $body;
            $signature = hash('sha256', $str);

            return $signature;
        }

        /*
         * Validate the items that are in the Item array
         */
        private function validateItemArray($item): bool
        {
            if (empty($item['line_total'])) {
                $item['line_total'] = 0;
            }

            if (
                empty($item['product_id'])
                || empty($item['name'])
                || empty($item['qty'])
            ) {
                return FALSE;
            }

            if (
                !is_string($item['name'])
                || !is_numeric($item['line_total'])
                || !is_numeric($item['qty'])
            ) {
                return FALSE;
            }

            return TRUE;
        }

        /*
         * Processing the payments
         */
        public function process_payment($order_id)
        {
            global $woocommerce;

            $order_id = sanitize_text_field($order_id);
            $order = new WC_Order($order_id);

            // we need it to get any order detailes
            $aur_msisdn = sanitize_text_field($_POST['aur_msisdn']);
            $nonce = wp_create_nonce();

            $aur_args = $this->get_request_body($order_id, $aur_msisdn);
            $signature = $this->get_signature($nonce, $aur_args);

            if (!is_array($aur_args)) {
                return $aur_args;
            }

            if (!wp_http_validate_url($this->api_endpoint)) {
                return $this->get_error_message();
            }

            /*
            * Your API interaction
            */
            $response = wp_remote_post($this->api_endpoint, array(
                'headers' => [
                    'netgiro_appkey' => $this->web_key,
                    'netgiro_nonce' => $nonce,
                    'netgiro_signature' => $signature,
                    'netgiro_referenceId' => getGUID(),
                    'Content-Type' => 'application/json; charset=utf-8'
                ],
                'body' => json_encode($aur_args),
                'method' => 'POST',
                'data_format' => 'body'
            ));


            if (!is_wp_error($response)) {
                $res_json = json_decode(wp_remote_retrieve_body($response));
                var_dump($res_json);

                var_dump($res_json->ResultCode);

                // Checking response from Aur
                if ($res_json->ResultCode === 200 && $res_json->Success === true) {

                    // Notes to order
                    $order->add_order_note('Payment request sent to customer: ' . $aur_msisdn, false);

                    // Empty cart
                    $woocommerce->cart->empty_cart();

                    // Redirect to the thank you page
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );

                } else {
                    wc_add_notice('Please try again: ' . $res_json['Message'], 'error');
                    $order->update_status('failed');
                    return $this->get_error_message();
                }

            } else {
                wc_add_notice('Connection error.', 'error');
                return $this->get_error_message();
            }
        }


        /*
         * Callback
         */
        public function callback_handler()
        {
            global $woocommerce;

            $logger = wc_get_logger();

            if ((isset($_GET['ng_netgiroSignature']) && $_GET['ng_netgiroSignature'])
                && $_GET['ng_orderid'] && $_GET['ng_transactionid'] && $_GET['ng_signature']
            ) {
                $signature = sanitize_text_field($_GET['ng_netgiroSignature']);
                $orderId = sanitize_text_field($_GET['ng_orderid']);
                $order = new WC_Order($orderId);
                $secret_key = sanitize_text_field($this->web_token);
                $invoice_number = sanitize_text_field($_REQUEST['ng_invoiceNumber']);
                $transactionId = sanitize_text_field($_REQUEST['ng_transactionid']);
                $totalAmount = sanitize_text_field($_REQUEST['ng_totalAmount']);
                $status = sanitize_text_field($_REQUEST['ng_status']);

                $str = $secret_key . $orderId . $transactionId . $invoice_number . $totalAmount . $status;
                $hash = hash('sha256', $str);

                // correct signature and order is success
                // TODO Payment Cancelled
                if ($hash == $signature && is_numeric($invoice_number)) {
                    $order->payment_complete();
                    $order->add_order_note('Payment completed by user in Aur app', false);
                    $woocommerce->cart->empty_cart();
                } else {
                    $failed_message = 'Aur payment failed. Woocommerce order id: ' . $orderId . ' and Aur reference no.: ' . $invoice_number . ' does relate to signature: ' . $signature;

                    // Set order status to failed
                    if (is_bool($order) === false) {
                        $logger->debug($failed_message, array('source' => 'netgiro_response'));
                        $order->update_status('failed');
                        $order->add_order_note($failed_message, false);
                    } else {
                        $logger->debug('error netgiro_response - order not found: ' . $orderId, array('source' => 'netgiro_response'));
                    }

                    wc_add_notice("Ekki tókst að staðfesta Aur greiðslu! Vinsamlega hafðu samband við verslun og athugað stöðuna á pöntun þinni nr. " . $orderId, 'error');
                }

                exit;
            }
        }
    }
}

