<?php
/*
Plugin Name: BlockBee Cryptocurrency Payment Gateway
Plugin URI: https://blockbee.io/resources/woocommerce/
Description: Accept cryptocurrency payments on your WooCommerce website
Version: 1.4.3
Requires at least: 5.8
Tested up to: 6.7.2
WC requires at least: 5.8
WC tested up to: 9.6.2
Requires PHP: 7.2
Author: BlockBee
Author URI: https://blockbee.io/
License: MIT
*/
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('BLOCKBEE_PLUGIN_VERSION', '1.4.3');
define('BLOCKBEE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BLOCKBEE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Custom Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'BlockBee\\';
    $base_dir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);

    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Check WooCommerce and PHP requirements
add_action('plugins_loaded', function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>' . sprintf(
                    esc_html__('BlockBee requires WooCommerce to be installed and active. You can download %s here.', 'blockbee-cryptocurrency-payment-gateway'),
                    '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
                ) . '</strong></p></div>';
        });
        return;
    }

    if (!extension_loaded('bcmath')) {
        add_action('admin_notices', function () {
            echo '<div class="error"><p><strong>' . sprintf(
                    esc_html__('BlockBee requires PHP\'s BCMath extension. Learn more about it %s.', 'blockbee-cryptocurrency-payment-gateway'),
                    '<a href="https://www.php.net/manual/en/book.bc.php" target="_blank">here</a>'
                ) . '</strong></p></div>';
        });
        return;
    }

    $register = new \BlockBee\Register();
    $register->register();

    $initialize = new \BlockBee\Initialize();
    $initialize->initialize();

    $blockbee = new \BlockBee\Controllers\WC_BlockBee_Gateway();
});

register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('blockbee_cronjob')) {
        wp_schedule_event(time(), 'hourly', 'blockbee_cronjob');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('blockbee_cronjob');
});

use Automattic\WooCommerce\Utilities\FeaturesUtil;
// Declare compatibility with WooCommerce features
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

// Pass BlockBee coin into legacy "process_payment"
add_filter('woocommerce_rest_checkout_process_payment_method_data', function($payment_method_data, $request) {
    if (isset($payment_method_data['blockbee_coin'])) {
        WC()->session->set('blockbee_coin', sanitize_text_field($payment_method_data['blockbee_coin']));
        $_POST['blockbee_coin'] = sanitize_text_field($payment_method_data['blockbee_coin']);
    }
    return $payment_method_data;
}, 10, 2);


// Register minimum endpoint to be used in the blocks
add_action('rest_api_init', function () {
    register_rest_route('blockbee/v1', '/get-minimum', array(
        'methods' => 'POST',
        'callback' => 'blockbee_get_minimum',
        'permission_callback' => 'blockbee_verify_nonce',
    ));
    register_rest_route('blockbee/v1', '/update-coin', array(
        'methods' => 'POST',
        'callback' => 'blockbee_update_coin',
        'permission_callback' => 'blockbee_verify_nonce',
    ));
});

function blockbee_verify_nonce(WP_REST_Request $request) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== home_url()) {
        return false;
    }

    $nonce = $request->get_header('X-WP-Nonce');
    return wp_verify_nonce($nonce, 'wp_rest');
}

function blockbee_get_minimum(WP_REST_Request $request) {
    $coin = sanitize_text_field($request->get_param('coin'));
    $fiat = sanitize_text_field($request->get_param('fiat'));
    $value = sanitize_text_field($request->get_param('value'));

    if (!$coin) {
        return new WP_REST_Response(['status' => 'error'], 400);
    }

    try {
        $convert = (float) \BlockBee\Utils\Api::get_conversion($fiat, $coin, (string) $value, false);
        $minimum = (float) \BlockBee\Utils\Api::get_info($coin)->minimum_transaction_coin;

        if ($convert > $minimum) {
            return new WP_REST_Response(['status' => 'success'], 200);
        } else {
            return new WP_REST_Response(['status' => 'error'], 200);
        }
    } catch (Exception $e) {
        return new WP_REST_Response(['status' => 'error'], 500);
    }
}

function blockbee_update_coin(WP_REST_Request $request) {
    $coin = sanitize_text_field($request->get_param('coin'));
    $selected = $request->get_param('selected', false);

    // Ensure WooCommerce session is available
    if (!WC()->session) {
        $session_handler = new \WC_Session_Handler();
        $session_handler->init();
        WC()->session = $session_handler;
    }

    if (!$selected) {
        WC()->session->set('blockbee_coin', 'none');
        WC()->session->set('chosen_payment_method', '');
        return new WP_REST_Response(['success' => true, 'coin' => $coin], 200);
    }

    if (!$coin) {
        return new WP_REST_Response(['error' => 'Coin not specified'], 400);
    }

    // Set the session value
    WC()->session->set('blockbee_coin', $coin);
    WC()->session->set('chosen_payment_method', 'blockbee');

    return new WP_REST_Response(['success' => true, 'coin' => $coin], 200);
}
