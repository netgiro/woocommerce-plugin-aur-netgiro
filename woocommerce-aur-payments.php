<?php
/*
  Plugin Name: Aur Payments
  Plugin URI: https://aur.is
  Description: Extends WooCommerce with a <a href="https://www.aur.is/" target="_blank">Aur</a> payments.
  Version: 1.0.2
  Author: Aur
  Author URI: https://aur.is

  License: GNU General Public License v3.0
  License URI: http://www.gnu.org/licenses/gpl-3.0.html

  WC requires at least: 3.6.0
  WC tested up to: 5.8.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Update checker
require 'plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://github.com/netgiro/woocommerce-plugin-aur-netgiro.git',
    __FILE__,
    'woocommerce-aur-payments'
);

//Optional: Set the branch that contains the stable release.
$myUpdateChecker->setBranch('stable');


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
            $this->api_endpoint = $this->testmode ? 'https://greidsla-test.aur.is/Checkout/InsertCart' : 'https://greidsla.aur.is/Checkout/InsertCart';
            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            // We need custom JavaScript to obtain a token
            add_action('wp_enqueue_scripts', array($this, 'aur_scripts'));
            // Registering a webhook here
            //add_action('woocommerce_api_orderupdate', array($this, 'callback_handler'));
            add_action('init', 'callback_handler');
            add_action('woocommerce_api_wc_' . $this->id . "_callback", array($this, 'callback_handler'));

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

            if (!is_numeric($order->get_total())) {
                return $this->get_error_message();
            }

            $round_numbers = $this->round_numbers;
            $payment_Confirmed_url = add_query_arg('wc-api', 'WC_' . $this->id . '_callback', home_url('/'));

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
                'description' => 'Order number ' . $order_id . '.  Time:' . date("h:i:s d-m-Y"),
                'reference' => $order_id,
                'customerId' => $customer_id,
                'callbackUrl' => $payment_Confirmed_url,
                'ConfirmationType' => '0',
                'locationId' => '',
                'registerId' => '',
                'clientInfo' => 'System: Woocommerce ' . $plugin_version
            );

            // Woocommerce -> Netgiro Items
            $items = array();
            foreach ($order->get_items() as $item) {
                $i = 0;
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

                $items[$i] = array(
                    'productNo' => $item['product_id'],
                    'name' => $item['name'],
                    'description' => $item['description'],  /* TODO Could not find description */
                    'unitPrice' => $unitPrice,
                    'amount' => $amount,
                    'quantity' => $item['qty']
                );
                $i++;
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
                    'netgiro_referenceId' => $this->getGUID(),
                    'Content-Type' => 'application/json; charset=utf-8'
                ],
                'body' => json_encode($aur_args),
                'method' => 'POST',
                'data_format' => 'body'
            ));

            echo var_dump($response);

            if (!is_wp_error($response)) {
                $res_json = json_decode(wp_remote_retrieve_body($response));

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

        private function getGUID(): string
        {
            if (function_exists('com_create_guid')) {
                return com_create_guid();
            } else {
                mt_srand((double)microtime() * 10000);//optional for php 4.2.0 and up.
                $charid = strtoupper(md5(uniqid(rand(), true)));
                $hyphen = chr(45);// "-"
                $uuid = chr(123)// "{"
                    . substr($charid, 0, 8) . $hyphen
                    . substr($charid, 8, 4) . $hyphen
                    . substr($charid, 12, 4) . $hyphen
                    . substr($charid, 16, 4) . $hyphen
                    . substr($charid, 20, 12)
                    . chr(125);// "}"
                return $uuid;
            }
        }


        /*
         * Callback
         */
        public function callback_handler()
        {
            global $woocommerce;

            $logger = wc_get_logger();

            $data = file_get_contents('php://input');
            $json_data = json_decode($data, true);

            // Get headers from request
            $headers = getallheaders();
            $app_key = $headers['Netgiro-Appkey'];
            $ng_signature = $headers['Netgiro-Signature'];
            $nonce = $headers['Netgiro-Nonce'];
            $referenceid = $headers['Netgiro-Referenceid'];

            // Get parameters from POST
            $success = $json_data['Success'];
            $order_id = $json_data['PaymentInfo']['ReferenceNumber'];
            $secret_key = sanitize_text_field($this->web_token);
            $payment_successful = $json_data['PaymentSuccessful'];
            $result_code = $json_data['ResultCode'];
            $invoice_number = $json_data['PaymentInfo']['InvoiceNumber'];
            $status_id = $json_data['PaymentInfo']['StatusId'];
            $total_amount = $json_data['PaymentInfo']['TotalAmount'];
            $transaction_id = $data['PaymentInfo']['TransactionId'];

            $order = new WC_Order($order_id);

            // Create signature
            $uri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
            $str = $secret_key . $nonce . trim($uri) . trim($data);
            $signature = hash('sha256', $str);

            if ($ng_signature == $signature && is_numeric($invoice_number)) {
                $order->payment_complete();
                $order->add_order_note( 'Payment completed by user in Aur app', false );
                $woocommerce->cart->empty_cart();
            } else {
                $failed_message = 'Aur payment failed. Woocommerce order id: ' . $order_id . ' and Netgiro reference no.: ' . $invoice_number . ' does relate to signature: ' . $signature;

                // Set order status to failed
                if (is_bool($order) === false) {
                    $logger->debug($failed_message, array('source' => 'netgiro_response'));
                    $order->update_status('failed');
                    $order->add_order_note($failed_message);
                } else {
                    $logger->debug('error netgiro_response - order not found: ' . $order_id, array('source' => 'callback_handler'));
                }

                wc_add_notice("Ekki tókst að staðfesta Aur greiðslu! Vinsamlega hafðu samband við verslun og athugað stöðuna á pöntun þinni nr. " . $order_id, 'error');
            }

            exit();
        }
    }
}