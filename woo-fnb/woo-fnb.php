<?php
/**
 * Plugin Name: Woocommerce FNB Integration
 * Plugin URI: https://localhost
 * Description: Final version
 * Version: 1.4.4
 * Author: Johan
 * Author URI: https://woocommerce.com
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: woocommerce-gateway
 * Domain Path: /languages
 * WC tested up to: 3.2
 * WC requires at least: 2.6
 */

add_action( 'plugins_loaded', 'init_your_gateway_class' );
function init_your_gateway_class() {
    class WC_Gateway_Your_Gateway extends WC_Payment_Gateway {

        public function __construct()
        {
            $this->id = 'fnb';
            $this->medthod_title = 'fnb';
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->apiKey = $this->settings['apiKey'];
            $this->merchantEndPoint = $this->settings['merchantEndPoint'];
            $this->validationEndPoint = $this->settings['validationURL'];



            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action( 'woocommerce_api_'. strtolower( get_class($this) ), array( $this, 'handle_callback' ) );
        }

        function init_form_fields(){

            $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'merch'),
                    'type' => 'checkbox',
                    'label' => __('Enable fnb Payment Module.', 'merch'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'merch'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'merch'),
                    'default' => __('Credit or Debit card', 'merch')),
                'description' => array(
                    'title' => __('Description:', 'merch'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'merch'),
                    'default' => __('Pay securely with any Credit or Debit card using FNB Secure.', 'merch')),
                'apiKey' => array(
                    'title' => __('API Key', 'merch'),
                    'type' => 'text',
                    'description' => __('This is the apiKey provided by fnb merchant."')),
                'merchantEndPoint' => array(
                    'title' => __('Merchant Endpoint', 'merch'),
                    'type' => 'text',
                    'description' =>  __('Given to Merchant by fnb', 'merch')),
                'validationEndPoint' => array(
                    'title' => __('Validation Endpoint', 'merch'),
                    'type' => 'text',
                    'description' =>  __('Given to Merchant by fnb', 'merch'),

                ),
            );
        }



        function process_payment( $order_id ) {
            global $woocommerce;

            //$order = new WC_Order( $order_id );
            $order = wc_get_order( $order_id );


            $merchantOrderNumber = $order_id.'_'.date("ymds");

            $productinfo = "Order $order_id";

            $amount = $woocommerce->cart->total;

            $payload = array(

                "apiKey"              	=> $this -> apiKey,
                "UCN"                   => "011968",
                "merchantOrderNumber" => $merchantOrderNumber,


                // Order total
                "amount"             	=> $amount * 100,

                //success and failure
                "validationURL" => get_site_url()."/wc-api/WC_GATEWAY_YOUR_GATEWAY/?txnToken=".$order->get_order_number(),
                //"successURL" => get_site_url()."/success/?id=".$order->get_order_number()."&",
                //"failureURL" => get_site_url()."/failed/?id=".$order->get_order_number()."&",

                // Description
                "description" => $productinfo

            );

            // Hard coded endpoint to api needs to be changed to production
            $environment_url = $this -> merchantEndPoint;

            // Send the payload to fnb for preparation
            // You can add security here to check if the key is key else exit
            $response = wp_remote_post( $environment_url, array(
                'method'    => 'POST',
                'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
                'body'      => json_encode( $payload ),
                'timeout'   => 90,
                'sslverify' => false,
            ) );

            //get the redirection url
            $response_array = json_decode($response['body'],true);
            //format the result into something useful
            $redirect = $response_array["url"];
            $url_array  = explode("?", $redirect);
            $url = $url_array[0];
            $append_string_array = $url_array[1];
            $append_string = explode("=", $append_string_array);
            $query_string = $append_string[1];


            $redirect = 'https://sandbox.ms.fnb.co.za/eCommerce/v2/getPaymentOptions';

            // Mark as on-hold (we're awaiting the cheque)
            //$order->update_status('failed', __( 'Awaiting card payment', 'woocommerce' ));

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $redirect .'?token=' .$query_string
            );
        }

        function handle_callback( ) {
            global $woocommerce;
            //Handle the thing here!
            //$validationEndPoint = $this -> validationEndPoint;
            if (isset($_GET['txnToken'])){
                $txn = $_GET['txnToken'];
                $pieces = explode("=", $txn);
                $txn = $pieces[1];
                $id_piece = $pieces[0];
                $id = explode("?", $id_piece);
                $id = $id[0];
                //echo $id;
                $order = new WC_Order( $id );
                $check = wp_remote_get( 'https://sandbox.ms.fnb.co.za/eCommerce/v2/validateTransaction'.'?txnToken='.$txn );
                $status = wp_remote_retrieve_body( $check );
            } else {
                echo 'Something went wrong';
                $status = '';
            }

            echo '<title>Payment Verification</title>';
            echo '<div style="width:100%"><img style="width:100%; height:auto" src="http://dev.epicval.co.za/assets/img/imagepaddock.JPG"></div>';

            //get the status
            $parsed_status = json_decode($status, true);
            //var_dump($parsed_status);
            $status = $parsed_status['status'];
            $auth = preg_split("/[\s,]+/", $status);

            //check if successful and display message
            if($auth[0] == 'DECLINED:'){
                $order->update_status('failed', __( 'Payment was declined', 'woocommerce' ));

                //display message
                echo '<br/><h2 align="center">Your payment has been declined!</h2><br/>';
                echo '<hr><br/><br/>';
                echo '<p align="center">Please contact your bank for further information</p>';
                echo '<br/><br/><hr>';
                echo '<br/><br/><br/><table width="100%" border="0" cellspacing="0" cellpadding="0">
                          <tr>
                            <td align="center">
                              <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                  <td>
                                    <a href="'.get_site_url().'" target="_blank" style="font-size: 16px; font-family: Helvetica, Arial, sans-serif; color: #ffffff; text-decoration: none; border-radius: 3px; background-color: #EB7035; border-top: 12px solid #EB7035; border-bottom: 12px solid #EB7035; border-right: 18px solid #EB7035; border-left: 18px solid #EB7035; display: inline-block;">Return to store &rarr;</a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
                        </table>';
            } else if($auth[0] == 'APPROVED:'){
                //$woocommerce->cart->empty_cart();
                $order->update_status('processing', __( 'Payment was accepted', 'woocommerce' ));
                //remove cart and display message
                echo '<br/><h2 align="center">Your payment has been accepted!</h2><br/>';
                echo '<hr><br/><br/>';
                echo '<p align="center">Please visit your account section for further information</p>';
                echo '<br/><br/><hr>';
                echo '<br/><br/><br/><table width="100%" border="0" cellspacing="0" cellpadding="0">
                          <tr>
                            <td align="center">
                              <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                  <td>
                                    <a href="'.get_site_url().'" target="_blank" style="font-size: 16px; font-family: Helvetica, Arial, sans-serif; color: #ffffff; text-decoration: none; border-radius: 3px; background-color: #EB7035; border-top: 12px solid #EB7035; border-bottom: 12px solid #EB7035; border-right: 18px solid #EB7035; border-left: 18px solid #EB7035; display: inline-block;">Return to store &rarr;</a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
                        </table>';
            } else {

                echo '<br/><h2 align="center">Something has gone wrong!</h2><br/>';
                echo '<hr><br/><br/>';
                echo '<p align="center">Please contact support for further information</p>';
                echo '<br/><br/><hr>';
                echo '<br/><br/><br/><table width="100%" border="0" cellspacing="0" cellpadding="0">
                          <tr>
                            <td align="center">
                              <table border="0" cellspacing="0" cellpadding="0">
                                <tr>
                                  <td>
                                    <a href="'.get_site_url().'" target="_blank" style="font-size: 16px; font-family: Helvetica, Arial, sans-serif; color: #ffffff; text-decoration: none; border-radius: 3px; background-color: #EB7035; border-top: 12px solid #EB7035; border-bottom: 12px solid #EB7035; border-right: 18px solid #EB7035; border-left: 18px solid #EB7035; display: inline-block;">Return to store &rarr;</a>
                                  </td>
                                </tr>
                              </table>
                            </td>
                          </tr>
                        </table>';
            }


            wp_die();
        }



    }
}

function add_your_gateway_class( $methods ) {
    $methods[] = 'WC_Gateway_Your_Gateway';
    return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'add_your_gateway_class' );



