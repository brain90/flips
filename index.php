<?php

/*
Plugin Name: Flips
Plugin URI: http://gibrain.wordpress.com
Description: Terima uang via flip payment gateway
Version: 1.0
Author: Gibrain
Author URI: https://gibrain.wordpress.com
License: GPLv3
 */

if (!defined('ABSPATH')) exit;

add_action('plugins_loaded', 'woocommerce_flip_init', 0);
function woocommerce_flip_init()
{

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_flip extends WC_Payment_Gateway
    {

        public function __construct()
        {

            $this->id = 'flip';
            $this->method_title = 'Flip Payment Gateway';
            $this->has_fields = false;
            $this->icon = plugins_url('/flip.png', __FILE__);
            $returnUrl = home_url('/checkout/order-received/');

            //Load settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->enabled      = $this->settings['enabled'] ?? '';
            $this->sandbox_mode = $this->settings['sandbox_mode'] ?? 'no';
            $this->auto_redirect= $this->settings['auto_redirect'] ?? '60';
            $this->return_url   = $this->settings['return_url'] ?? $returnUrl;
            $this->expired_time = $this->settings['expired_time'] ?? '24';
            $this->title        = "Flip Payment";
            $this->description  = $this->settings['description'] ?? '';
            $this->endpoint     = $this->settings['endpoint'] ?? '';
            $this->apikey       = $this->settings['apikey'] . ":" ?? '';
            $this->order_tag    = $this->settings['order_tag'] ?? '';

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . 
                        $this->id, array(&$this, 'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_flip', array($this, 'payment_callback'));
        }

        function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woothemes'),
                    'label' => __('Enable flip', 'woothemes'),
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'woothemes'),
                    'type' => 'text',
                    'description' => __('', 'woothemes'),
                    'default' => __('Pembayaran flip', 'woothemes')
                ),
                'description' => array(
                    'title' => __('Description', 'woothemes'),
                    'type' => 'textarea',
                    'description' => __('', 'woothemes'),
                    'default' => 'Sistem pembayaran menggunakan flip.'
                ),
                'endpoint' => array(
                    'title' => __('End point url', 'woothemes'),
                    'type' => 'textarea',
                    'description' => __('', 'woothemes'),
                    'default' => 'End point url. sesuaikan dengan environment. production / development'
                ),
                'apikey' => array(
                    'title' => __('API KEY', 'woothemes'),
                    'type' => 'textarea',
                    'description' => __('', 'woothemes'),
                    'default' => 'API KEY. sesuaikan dengan environment. production / development'
                ),
                'order_tag' => array(
                    'title' => __('Order Tag', 'woothemes'),
                    'type' => 'textarea',
                    'description' => __('', 'woothemes'),
                    'default' => 'Digunakan sebagai penanda transaksi antara flip & toko anda. misal: TOKO_SAYA'
                ),
                'redirect_url' => array(
                    'title' => __('Redirect URL', 'woothemes'),
                    'type' => 'textarea',
                    'description' => __('', 'woothemes'),
                    'default' => 'Halaman setelah pembuatan Virtual Account sukses'
                ),
 
           );
        }

        public function admin_options()
        {
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        function payment_fields()
        {
            if ($this->description)
                echo wpautop(wptexturize($this->description));
        }


        function process_payment($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);

            $url = $this->endpoint;
            $key = $this->apikey;

            $buyer_name = $order->get_billing_first_name() . $order->get_billing_last_name();
            $buyer_email = $order->get_billing_email();
            $buyer_phone = $order->get_billing_phone();

            $params = [
                "title"                    => "{$this->order_tag} OrderID: #{$order_id}",
                "amount"                   => $order->get_total(),
                "type"                     => "SINGLE",
                "redirect_url"             => $this->redirect_url,
                "is_address_required"      => 0,
                "is_phone_number_required" => 0,
                "sender_name"              => $buyer_name,
                "sender_email"             => $buyer_email,
                "step"                     => 2,
            ];

            // request
            $params_string = http_build_query($params);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_USERPWD, $key);
            curl_setopt($ch, CURLOPT_POST, TRUE);
            curl_setopt($ch, CURLOPT_HEADER, FALSE);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params_string);
            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                array("Content-Type: application/x-www-form-urlencoded")
            );

            // execute post
            $res = curl_exec($ch);
            curl_close($ch);

            // get result
            $res = json_decode($res, true);
            $payment_page = 'https://' . $res['link_url'];

            // failed action
            if (empty($res)) {
                die('Terjadi kesalahan: silahkan coba beberapa saat kembali');
                exit;
            }

            // stock adjustment
            $order->reduce_order_stock();
            WC()->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' =>  $payment_page
            );
        }

        // callback listener.
        // triggered when payment status changed at the flip side
        function payment_callback()
        {
            global $woocommerce;

            // data from flip
            $res = stripslashes($_REQUEST['data']);
            $res = json_decode($res, true);

            // get order
            $tag = $this->settings['order_tag'];
            $x = preg_replace('/^.*' . $tag . '/', '', $res['bill_link']);
            $id = explode('-', $x)[0];
            $order = new WC_Order($id);

            // allow only post
            if ($_SERVER["REQUEST_METHOD"] != "POST") {
                echo 'invalid request method';
                exit;
            }

            // update order status
            if ($res['status'] == 'SUCCESSFUL') {
                $order->add_order_note('Pembayaran diterima !. flip ID: ' . $res['id']);
                $order->update_status('processing');
                $order->payment_complete();
                echo 'completed';
                exit;
            } else if ($res['status'] == 'FAILED') {
                $order->add_order_note('Menunggu Pembayaran (flip failed) . flip ID: ' . $res['id']);
                $order->update_status('on-hold');
                echo 'on-hold';
                exit;
            } else if ($res['status'] == 'CANCELLED') {
                $order->add_order_note('Payment Expired. flip ID ' . $res['id'] . ' expired');
                $order->update_status('cancelled');
                echo 'cancelled';
                exit;
            } else {
                echo 'invalid status';
                exit;
            }
        }
    }

    function add_flip_gateway($methods)
    {
        $methods[] = 'WC_Gateway_flip';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_flip_gateway');
}
