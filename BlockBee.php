<?php
/*
Plugin Name: BlockBee Cryptocurrency Payment Gateway
Plugin URI: https://blockbee.io/resources/woocommerce/
Description: Accept cryptocurrency payments on your WooCommerce website
Version: 1.2.5
Requires at least: 5.8
Tested up to: 6.6.2
WC requires at least: 5.8
WC tested up to: 9.3.3
Requires PHP: 7.2
Author: BlockBee
Author URI: https://blockbee.io/
License: MIT
*/

require_once 'define.php';

function blockbee_missing_wc_notice()
{
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('BlockBee requires WooCommerce to be installed and active. You can download %s here.', 'blockbee-cryptocurrency-payment-gateway'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

function blockbee_missing_bcmath()
{
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('BlockBee requires PHP\'s BCMath extension. You can know more about it %s.', 'blockbee-cryptocurrency-payment-gateway'), '<a href="https://www.php.net/manual/en/book.bc.php" target="_blank">here</a>') . '</strong></p></div>';
}

function blockbee_include_gateway($methods)
{
    $methods[] = 'WC_BlockBee_Gateway';
    return $methods;
}

function blockbee_loader()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'blockbee_missing_wc_notice');
        return;
    }

    if (!extension_loaded('bcmath')) {
        add_action('admin_notices', 'blockbee_missing_bcmath');
        return;
    }

    $dirs = [
        BLOCKBEE_PLUGIN_PATH . 'controllers/',
        BLOCKBEE_PLUGIN_PATH . 'utils/',
        BLOCKBEE_PLUGIN_PATH . 'languages/',
    ];

    blockbee_include_dirs($dirs);

    $language_dir = BLOCKBEE_PLUGIN_PATH . 'languages/';
    $mo_file_path = $language_dir . 'blockbee-payment-gateway-for-woocommerce-' . get_locale() . '.mo';

    if (file_exists($mo_file_path)) {
        load_textdomain('blockbee-cryptocurrency-payment-gateway', $mo_file_path);
    } else {
        // error_log('Translation file not found: ' . $mo_file_path);
    }

    $blockbee = new WC_BlockBee_Gateway();
}

add_action('plugins_loaded', 'blockbee_loader');
add_filter('woocommerce_payment_gateways', 'blockbee_include_gateway');

function blockbee_include_dirs($dirs)
{
    foreach ($dirs as $dir) {
        $files = blockbee_scan_dir($dir);
        if ($files === false) continue;

        foreach ($files as $f) {
            blockbee_include_file($dir . $f);
        }
    }
}

function blockbee_include_file($file)
{
    if (blockbee_is_includable($file)) {
        require_once $file;
        return true;
    }

    return false;
}

function blockbee_scan_dir($dir)
{
    if (!is_dir($dir)) return false;
    $file = scandir($dir);
    unset($file[0], $file[1]);

    return $file;
}

function blockbee_is_includable($file)
{
    if (!is_file($file)) return false;
    if (!file_exists($file)) return false;
    if (strtolower(substr($file, -3, 3)) != 'php') return false;

    return true;
}

add_filter('cron_schedules', function ($blockbee_interval) {
    $blockbee_interval['blockbee_interval'] = array(
        'interval' => 60,
        'display' => esc_html__('BlockBee Interval'),
    );

    return $blockbee_interval;
});

register_activation_hook(__FILE__, 'blockbee_activation');

function blockbee_activation()
{
    if (!wp_next_scheduled('blockbee_cronjob')) {
        wp_schedule_event(time(), 'blockbee_interval', 'blockbee_cronjob');
    }
}

register_deactivation_hook(__FILE__, 'blockbee_deactivation');

function blockbee_deactivation()
{
    wp_clear_scheduled_hook('blockbee_cronjob');
}

add_action('wp_upgrade', 'blockbee_update_checker', 10, 2);

if (!wp_next_scheduled('blockbee_cronjob')) {
    wp_schedule_event(time(), 'blockbee_interval', 'blockbee_cronjob');
}

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
