<?php
/**
 * Plugin Name: PM Paypal Tracking
 * Plugin URI: https://woocommerce.com/
 * Description: Push tracking code to Paypal...
 * Version: 0.0.1
 * Author: TruongTuyen
 * Author URI: https://woocommerce.com
 * Text Domain: pm-paypal-tracking
 */

defined( 'ABSPATH' ) || exit;

define( 'PM_PAYPAL_TRACKING_PATH', plugin_dir_path( __FILE__ ) );
define( 'PM_PAYPAL_TRACKING_URL', plugin_dir_url( __FILE__ ) );

class PM_Paypal_Tracking {
	public function __construct() {
		add_action( 'woocommerce_new_customer_note', array( $this, 'on_add_order_note' ), PHP_INT_MAX, 1 );
		add_action(
			'init',
			function() {
				if ( isset( $_GET['dev_submit_paypal_tracking'] ) ) {
					$dev_note = get_option( 'dev_order_note_data', array() );

					$order_id      = 16268;
					$customer_note = $dev_note['customer_note'];

					$result = $this->submit_order_item_tracking( $order_id, $customer_note );
					echo 'Result: ' . $result;
					die;
				}

				if ( isset( $_GET['dev_paypal_tracking'] ) ) {
					$order_id = $_GET['dev_paypal_tracking'];
					$order    = wc_get_order( $order_id );
					if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
						$customer_order_notes = $order->get_customer_order_notes();
						if ( is_array( $customer_order_notes ) && ! empty( $customer_order_notes ) ) {
							foreach ( $customer_order_notes as $note_item ) {
								$note_content = isset( $note_item->comment_content ) ? $note_item->comment_content : '';
								if ( ! empty( $note_content ) ) {
									$result = $this->submit_order_item_tracking( $order_id, $note_content );
									echo 'Result: ' . $result;
								}
							}
						}
						die;
					}
				}

				if ( isset( $_GET['dev_compare_string'] ) ) {
					$str = 'Test new price Unisex T-Shirt - Dark Heather, M';
					$str2 = 'Test new price Unisex T-Shirt - Dark Heather / M';
					echo 'Format string 1: ' . $this->format_string( $str ) . '<br/>';
					echo 'Format string 2: ' . $this->format_string( $str2 ) . '<br/>';
					die;
				}
			},
			PHP_INT_MAX
		);
	}

	public function format_string( $string = '' ) {
		$string = strtolower( $string );
		$string = str_replace( '-', ' ', $string );
		$string = str_replace( '_', ' ', $string );
		$string = str_replace( ',', ' ', $string );
		$string = str_replace( ':', ' ', $string );
		$string = str_replace( ';', ' ', $string );
		$string = str_replace( '.', ' ', $string );
		$string = str_replace( '(', ' ', $string );
		$string = str_replace( ')', ' ', $string );
		$string = str_replace( '[', ' ', $string );
		$string = str_replace( ']', ' ', $string );
		$string = str_replace( '/', ' ', $string );
		$string = preg_replace( '/\s+/', ' ', $string );
		return $string;
	}

	public function submit_order_item_tracking( $order_id, $customer_note ) {
		if ( ! class_exists( 'VI_WOO_ORDERS_TRACKING_ADMIN_IMPORT_CSV' ) ) {
			return 'dependency_plugin_is_not_activated';
		}
		$return_code = 'order_not_valid';
		$order = wc_get_order( $order_id );
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			$paypal_method = $order->get_payment_method();
			if ( false !== strpos( $paypal_method, 'paypal' ) ) {
				$tracking_number = '';
				$item_sku = '';
				$parse_item_name = '';

				preg_match( '/<a(.*?)>(.*?)<\\/a>/si', $customer_note, $match );
				if ( isset( $match[2] ) && ! empty( $match[2] ) ) {
					$tracking_number = $match[2];
				}

				preg_match( '/(.*?)\((.*?)\)/si', $customer_note, $match );
				if ( isset( $match[2] ) && ! empty( $match[2] ) ) {
					$item_sku = $match[2];
				}

				if ( isset( $match[1] ) && ! empty( $match[1] ) ) {
					$parse_item_name = $match[1];
				}

				if ( ! empty( $parse_item_name ) ) {
					$parse_item_name = str_replace( '-', ' ', $parse_item_name );
				}

				if ( ! empty( $tracking_number ) && ! empty( $item_sku ) ) {
					$order_items   = $order->get_items();
					$order_item_id = 0;
					foreach ( $order_items as $item ) {
						$item_product     = $item->get_product();
						$item_product_sku = $item_product->get_sku();
						$item_product_name = $item_product->get_name();
						if ( $item_product_sku == $item_sku || ( $this->format_string( $parse_item_name ) == $this->format_string( $item_product_name ) ) ) {
							$order_item_id = $item->get_id();
							break;
						}
					}

					if ( $order_item_id > 0 ) {
						$transaction_id     = $order->get_transaction_id();
						$item_tracking_data = wc_get_order_item_meta( $order_item_id, '_vi_wot_order_item_tracking_data', true );

						if ( empty( $item_tracking_data ) ) {
							$object       = new VI_WOO_ORDERS_TRACKING_ADMIN_IMPORT_CSV();
							$carrier_slug = $this->detect_tracking_carrier_code( $tracking_number );
							$carrier      = $object->get_shipping_carrier_by_slug( $carrier_slug );
							if ( is_array( $carrier ) && count( $carrier ) ) {
								$carrier_url  = $carrier['url'];
								$carrier_name = $carrier['name'];
								$carrier_type = $carrier['carrier_type'];

								$current_tracking_data = array(
									'tracking_number' => $tracking_number,
									'carrier_slug'    => $carrier_slug,
									'carrier_url'     => $carrier_url,
									'carrier_name'    => $carrier_name,
									'carrier_type'    => $carrier_type,
									'time'            => time(),
								);
								$item_tracking_data    = array(
									$current_tracking_data,
								);
								$item_tracking_data    = json_encode( $item_tracking_data );
								wc_update_order_item_meta( $order_item_id, '_vi_wot_order_item_tracking_data', $item_tracking_data );
							}
						}

						$paypal_added_trackings = get_post_meta( $order_id, 'vi_wot_paypal_added_tracking_numbers', true );
						if ( ! $paypal_added_trackings ) {
							$paypal_added_trackings = array();
						}
						if ( $transaction_id && $paypal_method && $item_tracking_data ) {
							$item_tracking_data    = json_decode( $item_tracking_data, true );
							$current_tracking_data = array_pop( $item_tracking_data );

							if ( ! in_array( $current_tracking_data['tracking_number'], $paypal_added_trackings ) ) {
								$send_paypal       = array(
									array(
										'trans_id'        => $transaction_id,
										'carrier_name'    => $current_tracking_data['carrier_name'],
										'tracking_number' => $current_tracking_data['tracking_number'],
									),
								);
								$paypal_tracking   = new VI_WOO_ORDERS_TRACKING_ADMIN_ORDERS_EDIT_TRACKING();
								$result_add_paypal = $paypal_tracking->add_trackinfo_to_paypal( $send_paypal, $paypal_method );
								if ( 'error' === $result_add_paypal['status'] ) {
									$return_code = 'error';
								} else {
									$paypal_added_trackings[] = $current_tracking_data['tracking_number'];
									update_post_meta( $order_id, 'vi_wot_paypal_added_tracking_numbers', $paypal_added_trackings );
									$return_code = 'success';
								}
							} else {
								$return_code = 'tracking_already_added';
							}
						} else {
							$return_code = 'item_id_not_valid';
						}
					} else {
						$return_code = 'item_id_not_valid';
					}
				} else {
					$return_code = 'tracking_or_item_id_not_valid';
				}
			} else {
				$return_code = 'payment_method_not_paypal';
			}
			if ( ! empty( $return_code ) && method_exists( $order, 'add_order_note' ) ) {
				$private_note = 'Try to push tracking code to paypal: ';
				if ( isset( $tracking_number ) && ! empty( $tracking_number ) ) {
					$private_note = 'Try to push tracking code "' . $tracking_number . '" to paypal: ';
				}
				$order->add_order_note( $private_note . $return_code );
			}
		}
		return $return_code;
	}

	public function detect_tracking_carrier_code( $tracking_code ) {
		$allow_start_codes      = array( 'GM', 'LX', 'RX', 'UV', 'CN', 'SG', 'TH', 'IN', 'HK', 'MY' );
		$china_post_start_codes = array( 'LZ', 'LY', 'RV' );
		$code                   = strtoupper( $tracking_code );
		$start_code             = substr( $code, 0, 2 );
		if ( in_array( $start_code, $allow_start_codes ) ) {
			if ( strlen( $code ) > 12 ) {
				return 'dhlecommerce-asia';
				// return 'dhlglobalmail';
			}
		} elseif ( in_array( $start_code, array( 'UE' ) ) ) {
			return 'YANWEN_CN';
		} elseif ( in_array( $start_code, array( 'SF' ) ) ) {
			return 'sf-express-cn';
		} elseif ( in_array( $start_code, array( '61' ) ) ) {
			return 'fedex';
		} elseif ( in_array( $start_code, array( '94', '92', '42' ) ) && strlen( $code ) != 10 ) {
			return 'usps';
		} elseif ( in_array( $start_code, array( '23' ) ) ) {
			return 'japan-post';
		} elseif ( in_array( $start_code, array( 'YT' ) ) ) {
			return 'yun-express-cn';
		} elseif ( in_array( $start_code, array( '82', '69', '30', '75' ) ) || strlen( $code ) == 10 || strlen( $code ) == 8 ) {
			return 'dhl';
		} elseif ( in_array( $start_code, $china_post_start_codes ) ) {
			return 'china-post';
		}
		return 'china-ems';
	}

	public function on_add_order_note( $arg = array() ) {
		if ( is_array( $arg ) && ! empty( $arg ) ) {
			if ( isset( $arg['order_id'] ) && isset( $arg['customer_note'] ) && $arg['order_id'] > 0 && ! empty( $arg['customer_note'] ) ) {
				$result = $this->submit_order_item_tracking( $arg['order_id'], $arg['customer_note'] );
			}
		}
	}


}
new PM_Paypal_Tracking();

/*
Dev Unisex T-Shirt - Black, S(NT-160970) is added tracking code:<br>
<a href="https://t.17track.net/en#nums=YT2014221272074660" target="_blank">YT2014221272074660</a>
*/
