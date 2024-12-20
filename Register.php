<?php

namespace BlockBee;

require_once 'blocks/BlockBee.php';

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

use BlockBee\Blocks\WC_BlockBee_Payments;

class Register {
    public function register() {
        // Register Payment Gateway for legacy WooCommerce
        add_filter('woocommerce_payment_gateways', [$this, 'register_payment_gateway']);

        // Register Payment Gateway for WooCommerce Blocks
        if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            add_action('woocommerce_blocks_payment_method_type_registration', [$this, 'register_blocks_payment_gateway']);
        }
    }

    public function register_payment_gateway($methods) {
        $methods[] = \BlockBee\Controllers\WC_BlockBee_Gateway::class;
        return $methods;
    }

    public function register_blocks_payment_gateway(PaymentMethodRegistry $registry) {
        $registry->register(new \BlockBee\Blocks\WC_BlockBee_Payments());
    }
}
