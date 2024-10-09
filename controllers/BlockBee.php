<?php

use BlockBee\Helper;

#[AllowDynamicProperties]
class WC_BlockBee_Gateway extends WC_Payment_Gateway
{
    private static $HAS_TRIGGERED = false;

    function __construct()
    {
        $this->id = 'blockbee';
        $this->icon = BLOCKBEE_PLUGIN_URL . 'static/files/blockbee_logo.png';
        $this->has_fields = true;
        $this->method_title = 'BlockBee Cryptocurrency Payment Gateway';
        $this->method_description = __('BlockBee allows customers to pay in cryptocurrency', 'blockbee-cryptocurrency-payment-gateway');

        $this->supports = array(
            'products',
            'tokenization',
            'add_payment_method',
            'subscriptions',
            'subscription_cancellation',
            'subscription_amount_changes',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_date_changes',
            'multiple_subscriptions',
        );
        $this->init_form_fields();
        $this->init_settings();
        $this->blockbee_settings();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'validate_payment'));

        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_mail'), 10, 2);

        add_action('wcs_create_pending_renewal', array($this, 'subscription_send_email'));

        add_action('wp_ajax_nopriv_' . $this->id . '_order_status', array($this, 'order_status'));
        add_action('wp_ajax_' . $this->id . '_order_status', array($this, 'order_status'));

        add_action('wp_ajax_' . $this->id . '_validate_logs', array($this, 'validate_logs'));

        add_action('blockbee_cronjob', array($this, 'cronjob'), 10, 3);

        add_action('woocommerce_cart_calculate_fees', array($this, 'handling_fee'));

        add_action('woocommerce_checkout_update_order_review', array($this, 'chosen_currency_value_to_wc_session'));

        add_action('wp_footer', array($this, 'refresh_checkout'));

        add_action('woocommerce_email_order_details', array($this, 'add_email_link'), 2, 4);

        add_filter('woocommerce_my_account_my_orders_actions', array($this, 'add_order_link'), 10, 2);

        add_action('woocommerce_admin_order_data_after_order_details', array($this, 'order_detail_validate_logs'));

        add_action('admin_footer', array($this, 'hide_checkout_options'));
    }

    function reset_load_coins() {
        delete_transient('blockbee_coins');
        $this->load_coins();
    }

    function load_coins()
    {
        $transient = get_transient('blockbee_coins');

        if (!empty($transient)) {
            $coins = $transient;
        } else {
            $coins = BlockBee\Helper::get_supported_coins();
            set_transient('blockbee_coins', $coins, 86400);

            if (empty($coins)) {
                throw new Exception(__('No cryptocurrencies available at the moment. Please choose a different payment method or try again later.', 'blockbee-cryptocurrency-payment-gateway'));
            }
        }

        # Disabling XMR since it is not supported anymore.
        unset($coins['xmr']);

        return $coins;
    }

    function admin_options()
    {
        parent::admin_options();
        ?>
        <div style='margin-top: 2rem;'>
            <?php echo __("If you need any help or have any suggestion, contact us via the <b>live chat</b> on our <b><a href='https://blockbee.io' target='_blank'>website</a></b> or join our <b><a href='https://discord.gg/cryptapi' target='_blank'>Discord server</a></b>", "blockbee-cryptocurrency-payment-gateway"); ?>
        </div>
        <div style='margin-top: .5rem;'>
            <?php echo __("If you enjoy this plugin please <b><a href='https://wordpress.org/support/plugin/blockbee-cryptocurrency-payment-gateway-for-woocommerce/reviews/#new-post' target='_blank'>rate and review it</a></b>!", "blockbee-cryptocurrency-payment-gateway"); ?>
        </div>
        <?php
    }

    /*
     * Responsible for hidding needless options if Checkout page is enabled.
     */
    function hide_checkout_options() {
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function ($) {
                // Initial check
                toggleCheckoutDependentFields();

                // When the checkbox is changed
                $('input[name="woocommerce_blockbee_checkout_enabled"]').change(function () {
                    toggleCheckoutDependentFields();
                });

                // Function to hide/show fields based on checkout_enabled
                function toggleCheckoutDependentFields() {
                    if ($('input[name="woocommerce_blockbee_checkout_enabled"]').is(':checked')) {
                        $('.checkout-dependent').closest('tr').hide();
                    } else {
                        $('.checkout-dependent').closest('tr').show();
                    }
                }
            });
        </script>
        <?php
    }


    private function blockbee_settings()
    {
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->api_key = $this->get_option('api_key');
        $this->checkout_enabled = $this->get_option('checkout_enabled') === 'yes';
        $this->qrcode_size = $this->get_option('qrcode_size');
        $this->qrcode_default = $this->get_option('qrcode_default') === 'yes';
        $this->qrcode_setting = $this->get_option('qrcode_setting');
        $this->coins = $this->get_option('coins');
        $this->show_branding = $this->get_option('show_branding') === 'yes';
        $this->show_crypto_logos = $this->get_option('show_crypto_logos') === 'yes';
        $this->color_scheme = $this->get_option('color_scheme');
        $this->refresh_value_interval = $this->get_option('refresh_value_interval');
        $this->order_cancellation_timeout = $this->get_option('order_cancellation_timeout');
        $this->add_blockchain_fee = $this->get_option('add_blockchain_fee') === 'yes';
        $this->fee_order_percentage = $this->get_option('fee_order_percentage');
        $this->virtual_complete = $this->get_option('virtual_complete') === 'yes';
        $this->disable_conversion = $this->get_option('disable_conversion') === 'yes';
        $this->icon = '';
    }

    function init_form_fields()
    {
        $load_coins = [];
        try {
            $load_coins = $this->load_coins();
        } catch (Exception $e) {
            // pass
        }

        if (!empty($load_coins)) {
            $coin_options = [];
            foreach ($load_coins as $token => $coin) {
                $coin_options[$token] = $coin['name'];
            }

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enabled', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Enable BlockBee', 'blockbee-cryptocurrency-payment-gateway'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'blockbee-cryptocurrency-payment-gateway'),
                    'default' => __('Pay with BlockBee', 'blockbee-cryptocurrency-payment-gateway'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'textarea',
                    'default' => '',
                    'description' => __('Payment method description that the customer will see on your checkout', 'blockbee-cryptocurrency-payment-gateway')
                ),
                'api_key' => array(
                    'title' => __('API Key', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'text',
                    'default' => '',
                    'description' => sprintf(__('Insert here your BlockBee API Key. You can get one here: %1$s', 'blockbee-cryptocurrency-payment-gateway'), '<a href="https://dash.blockbee.io/" target="_blank">https://dash.blockbee.io/</a>')
                ),
                'checkout_enabled' => array(
                    'title' => __('<span style="color: #c79a05;">(New)</span> Enable Checkout', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'checkbox',
                    'default' => 'no',
                    'description' => __('By enabling this option, your users will be redirected to our secure Checkout page to complete their payment (<a href="https://pay.blockbee.io/payment/demo/" target="_blank">see demo</a>).<br/>You can configure the Checkout page settings in your BlockBee dashboard at <a href="https://dash.blockbee.io/settings/checkout/" target="_blank">https://dash.blockbee.io/settings/checkout/</a>.', 'blockbee-cryptocurrency-payment-gateway'),
                ),
                'show_crypto_logos' => array(
                    'title' => __('Show crypto logos in checkout', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => sprintf(__('Enable this to show the cryptocurrencies logos in the checkout %1$s %2$s Notice: %3$s It may break in some templates. Use at your own risk.', 'blockbee-cryptocurrency-payment-gateway'), '<br/>', '<strong>', '</strong>'),
                    'default' => 'false',
                    'class' => 'checkout-dependent'
                ),
                'add_blockchain_fee' => array(
                    'title' => __('Add the blockchain fee to the order', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __("This will add an estimation of the blockchain fee to the order value", 'blockbee-cryptocurrency-payment-gateway'),
                    'default' => 'no',
                ),
                'fee_order_percentage' => array(
                    'title' => __('Service fee manager', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'select',
                    'default' => '0',
                    'options' => array(
                        '0.05' => '5%',
                        '0.048' => '4.8%',
                        '0.045' => '4.5%',
                        '0.042' => '4.2%',
                        '0.04' => '4%',
                        '0.038' => '3.8%',
                        '0.035' => '3.5%',
                        '0.032' => '3.2%',
                        '0.03' => '3%',
                        '0.028' => '2.8%',
                        '0.025' => '2.5%',
                        '0.022' => '2.2%',
                        '0.02' => '2%',
                        '0.018' => '1.8%',
                        '0.015' => '1.5%',
                        '0.012' => '1.2%',
                        '0.01' => '1%',
                        '0.0090' => '0.90%',
                        '0.0085' => '0.85%',
                        '0.0080' => '0.80%',
                        '0.0075' => '0.75%',
                        '0.0070' => '0.70%',
                        '0.0065' => '0.65%',
                        '0.0060' => '0.60%',
                        '0.0055' => '0.55%',
                        '0.0050' => '0.50%',
                        '0.0040' => '0.40%',
                        '0.0030' => '0.30%',
                        '0.0025' => '0.25%',
                        '0' => '0%',
                    ),
                    'description' => sprintf(__('Set the BlockBee service fee you want to charge the costumer. %1$s %2$s Note: %3$s Fee you want to charge your costumers (to cover BlockBee\'s fees fully or partially).', 'blockbee-cryptocurrency-payment-gateway'), '<br/>', '<strong>', '</strong>')
                ),
                'qrcode_default' => array(
                    'title' => __('QR Code by default', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Show the QR Code by default.', 'blockbee-cryptocurrency-payment-gateway'),
                    'default' => 'yes',
                    'class' => 'checkout-dependent'
                ),
                'qrcode_size' => array(
                    'title' => __('QR Code size', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'number',
                    'default' => 300,
                    'description' => __('QR code image size.', 'blockbee-cryptocurrency-payment-gateway'),
                    'class' => 'checkout-dependent'
                ),
                'qrcode_setting' => array(
                    'title' => __('QR Code to show', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'select',
                    'default' => 'without_amount',
                    'class' => 'checkout-dependent',
                    'options' => array(
                        'without_amount' => __('Default Without Amount', 'blockbee-cryptocurrency-payment-gateway'),
                        'amount' => __('Default Amount', 'blockbee-cryptocurrency-payment-gateway'),
                        'hide_amount' => __('Hide Amount', 'blockbee-cryptocurrency-payment-gateway'),
                        'hide_without_amount' => __('Hide Without Amount', 'blockbee-cryptocurrency-payment-gateway'),
                    ),
                    'description' => __('Select how you want to show the QR Code to the user. Either select a default to show first, or hide one of them.', 'blockbee-cryptocurrency-payment-gateway')
                ),
                'color_scheme' => array(
                    'title' => __('Color Scheme', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'select',
                    'default' => 'light',
                    'class' => 'checkout-dependent',
                    'description' => __('Selects the color scheme of the plugin to match your website (Light, Dark and Auto to automatically detect it).', 'blockbee-cryptocurrency-payment-gateway'),
                    'options' => array(
                        'light' => __('Light', 'blockbee-cryptocurrency-payment-gateway'),
                        'dark' => __('Dark', 'blockbee-cryptocurrency-payment-gateway'),
                        'auto' => __('Auto', 'blockbee-cryptocurrency-payment-gateway'),
                    ),
                ),
                'refresh_value_interval' => array(
                    'title' => __('Refresh converted value', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'select',
                    'default' => '300',
                    'class' => 'checkout-dependent',
                    'options' => array(
                        '300' => __('Every 5 Minutes', 'blockbee-cryptocurrency-payment-gateway'),
                        '600' => __('Every 10 Minutes', 'blockbee-cryptocurrency-payment-gateway'),
                        '900' => __('Every 15 Minutes', 'blockbee-cryptocurrency-payment-gateway'),
                        '1800' => __('Every 30 Minutes', 'blockbee-cryptocurrency-payment-gateway'),
                        '2700' => __('Every 45 Minutes', 'blockbee-cryptocurrency-payment-gateway'),
                        '3600' => __('Every 60 Minutes', 'blockbee-cryptocurrency-payment-gateway'),
                        '0' => __('Never', 'blockbee-cryptocurrency-payment-gateway'),
                    ),
                    'description' => sprintf(__('The system will automatically update the conversion value of the invoices (with real-time data), every X minutes. %1$s This feature is helpful whenever a customer takes long time to pay a generated invoice and the selected crypto a volatile coin/token (not stable coin). %1$s %4$s Warning: %3$s Setting this setting to none might create conversion issues, as we advise you to keep it at 5 minutes. %3$s', 'blockbee-cryptocurrency-payment-gateway'), '<br/>', '<strong>', '</strong>', '<strong style="color: #f44336;">'),
                ),
                'order_cancellation_timeout' => array(
                    'title' => __('Order cancellation timeout', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'select',
                    'default' => '0',
                    'options' => array(
                        '3600' => __('1 Hour', 'blockbee-cryptocurrency-payment-gateway'),
                        '21600' => __('6 Hours', 'blockbee-cryptocurrency-payment-gateway'),
                        '43200' => __('12 Hours', 'blockbee-cryptocurrency-payment-gateway'),
                        '64800' => __('18 Hours', 'blockbee-cryptocurrency-payment-gateway'),
                        '86400' => __('24 Hours', 'blockbee-cryptocurrency-payment-gateway'),
                        '0' => __('Never', 'blockbee-cryptocurrency-payment-gateway'),
                    ),
                    'description' => sprintf(__('Selects the amount of time the user has to  pay for the order. %1$s When this time is over, order will be marked as "Cancelled" and every paid value will be ignored. %1$s %2$s Notice: %3$s If the user still sends money to the generated address, value will still be redirected to you. %1$s %4$s Warning: %3$s We do not advice more than 1 Hour.', 'blockbee-cryptocurrency-payment-gateway'), '<br/>', '<strong>', '</strong>', '<strong style="color: #f44336;">'),
                ),
                'virtual_complete' => array(
                    'title' => __('Completed status for virtual products', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => sprintf(__('When this setting is enabled, the plugin will mark the order as "completed" then payment is received. %1$s Only for virtual products %2$s.', 'blockbee-cryptocurrency-payment-gateway'), '<strong>', '</strong>'),
                    'default' => 'no'
                ),
                'coins' => array(
                    'title' => __('Accepted cryptocurrencies', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'multiselect',
                    'default' => '',
                    'css' => 'height: 300px;',
                    'class' => 'checkout-dependent',
                    'options' => $coin_options,
                    'description' => __("Select which coins do you wish to accept. CTRL + click to select multiple. Addresses must be set on the dashboard.", 'blockbee-cryptocurrency-payment-gateway'),
                ),
                'disable_conversion' => array(
                    'title' => __('Disable price conversion', 'blockbee-cryptocurrency-payment-gateway'),
                    'type' => 'checkbox',
                    'label' => sprintf(__('%2$s Attention: This option will disable the price conversion for ALL cryptocurrencies! %3$s %1$s If you check this, pricing will not be converted from the currency of your shop to the cryptocurrency selected by the user, and users will be requested to pay the same value as shown on your shop, regardless of the cryptocurrency selected', 'blockbee-cryptocurrency-payment-gateway'), '<br/>', '<strong>', '</strong>'),
                    'default' => 'no',
                    'class' => 'checkout-dependent'
                ),
            );
        }
    }

    function needs_setup()
    {
        if (empty($this->coins) || !is_array($this->coins)) {
            return true;
        }

        foreach ($this->coins as $val) {
            if (!empty($this->{$val . '_address'})) {
                return false;
            }
        }

        return true;
    }

    public function get_icon()
    {
        $icon = '<img style="position:relative" width="120" src="' . esc_url(BLOCKBEE_PLUGIN_URL) . 'static/files/blockbee_logo.png' . '" alt="' . esc_attr($this->get_title()) . '" />';

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    function payment_fields()
    {
        try {
            $load_coins = $this->load_coins();
        } catch (Exception $e) {
            ?>
            <div class="woocommerce-error">
                <?php echo __('Sorry, there has been an error.', 'woocommerce'); ?>
            </div>
            <?php
            return;
        }
        ?>
        <div class="form-row form-row-wide">
            <p><?php echo esc_attr($this->description); ?></p>
            <?php
            if (!$this->checkout_enabled) {
                ?>
                <ul style="margin-top: 7px; list-style: none outside;">
                    <?php
                    if (!empty($this->coins) && is_array($this->coins)) {
                        $selected = WC()->session->get('blockbee_coin');
                        ?>
                        <li>
                            <select name="blockbee_coin" id="payment_blockbee_coin" class="input-control"
                                    style="display:block; margin-top: 10px">
                                <option value="none"><?php echo esc_attr(__('Please select a Cryptocurrency', 'blockbee-cryptocurrency-payment-gateway')) ?></option>
                                <?php
                                foreach ($this->coins as $val) {
                                    $apikey = $this->api_key;
                                    if (!empty($apikey)) { ?>
                                        <option data-image="<?php echo esc_url($load_coins[$val]['logo']); ?>"
                                                value="<?php echo esc_attr($val); ?>" <?php
                                        if (!empty($selected) && $selected === $val) {
                                            echo esc_attr("selected='true'");
                                        }
                                        $crypto_name = is_array($load_coins[$val]) ? esc_attr($load_coins[$val]['name']) : esc_attr($load_coins[$val]);
                                        ?>> <?php echo esc_attr(__('Pay with', 'blockbee-cryptocurrency-payment-gateway') . ' ' . $crypto_name); ?></option>
                                        <?php
                                    }
                                }
                                ?>
                            </select>
                        </li>
                        <?php
                    } ?>
                </ul>
                <?php
            }
            ?>
        </div>
        <?php
        if ($this->show_crypto_logos) {
            ?>
            <script>
                if (typeof jQuery.fn.selectWoo !== 'undefined') {
                    jQuery('#payment_blockbee_coin').selectWoo({
                        minimumResultsForSearch: -1,
                        templateResult: formatState
                    })

                    function formatState(opt) {
                        if (!opt.id) {
                            return opt.text
                        }
                        let optImage = jQuery(opt.element).attr('data-image')
                        if (!optImage) {
                            return opt.text
                        } else {
                            return jQuery('<span style="display:flex; align-items:center;"><img style="margin-right: 8px" src="' + optImage + '" width="24px" alt="' + opt.text + '" /> ' + opt.text + '</span>')
                        }
                    }
                }
            </script>
            <?php
        }
    }

    function validate_fields()
    {
        $load_coins = $this->load_coins();
        return array_key_exists(sanitize_text_field($_POST['blockbee_coin']), $load_coins);
    }

    function process_payment($order_id)
    {
        global $woocommerce;

        $selected = sanitize_text_field($_POST['blockbee_coin']);

        if ($selected === 'none') {
            wc_add_notice(__('Payment error: ', 'woocommerce') . ' ' . __('Please choose a cryptocurrency', 'blockbee-cryptocurrency-payment-gateway'), 'error');

            return null;
        }

        $apikey = $this->api_key;

        $nonce = $this->generate_nonce();

        if (!empty($apikey)) {
            $currency = get_woocommerce_currency();

            $callback_url = str_replace('https:', 'http:', add_query_arg(array(
                'wc-api' => 'WC_Gateway_BlockBee',
                'wc_api_type' => $this->checkout_enabled ? 'checkout': "custom_api",
                'order_id' => $order_id,
                'nonce' => $nonce,
            ), home_url('/')));

            try {
                $order = new WC_Order($order_id);

                $total = $order->get_total('edit');

                if (in_array('woocommerce-subscriptions/woocommerce-subscriptions.php', apply_filters('active_plugins', get_option('active_plugins')))) {
                    if (wcs_order_contains_subscription($order_id)) {
                        $sign_up_fee = (WC_Subscriptions_Order::get_sign_up_fee($order)) ? 0 : WC_Subscriptions_Order::get_sign_up_fee($order);
                        $initial_payment = (WC_Subscriptions_Order::get_total_initial_payment($order)) ? 0 : WC_Subscriptions_Order::get_total_initial_payment($order);
                        $price_per_period = (WC_Subscriptions_Order::get_recurring_total($order)) ? 0 : WC_Subscriptions_Order::get_recurring_total($order);

                        $total = $sign_up_fee + $initial_payment + $price_per_period + $order->get_total('edit');

                        if ($total === 0) {
                            $order->add_meta_data('blockbee_currency', $selected);
                            $order->save_meta_data();
                            $order->payment_complete();
                            $woocommerce->cart->empty_cart();

                            return array(
                                'result' => 'success',
                                'redirect' => $this->get_return_url($order)
                            );
                        }
                    }
                }

                // If Checkout is enabled flow is simpler, yet different
                if ($this->checkout_enabled) {
                    $api = new BlockBee\Helper(null, $apikey, $callback_url, []);

                    $payment= $api->payment_request(
                        $this->get_return_url($order),
                        $total,
                        $currency,
                        $order_id,
                        (int) $this->order_cancellation_timeout
                    );

                    if (empty($payment)) {
                        wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('There was an error with the payment. Please try again.', 'blockbee-cryptocurrency-payment-gateway'));

                        return null;
                    }

                    $payment_url = $payment->payment_url;

                    $order->add_meta_data('blockbee_version', BLOCKBEE_PLUGIN_VERSION);
                    $order->add_meta_data('blockbee_checkout', 'true');
                    $order->add_meta_data('blockbee_php_version', PHP_VERSION);
                    $order->add_meta_data('blockbee_success_token', $payment->success_token);
                    $order->add_meta_data('blockbee_payment_url', $payment_url);
                    $order->add_meta_data('blockbee_payment_id', $payment->payment_id);

                    $order->save_meta_data();

                    $order->update_status('on-hold', __('Awaiting payment', 'blockbee-cryptocurrency-payment-gateway'));
                    $woocommerce->cart->empty_cart();

                    return array(
                        'result' => 'success',
                        'redirect' => $payment_url
                    );
                }

                $load_coins = $this->load_coins();

                $info = BlockBee\Helper::get_info($selected);
                $min_tx = BlockBee\Helper::sig_fig($info->minimum_transaction_coin, 8);

                $crypto_total = BlockBee\Helper::get_conversion($currency, $selected, $total, $this->disable_conversion);

                if ($crypto_total < $min_tx) {
                    wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('Value too low, minimum is', 'blockbee-cryptocurrency-payment-gateway') . ' ' . $min_tx . ' ' . strtoupper($selected), 'error');

                    return null;
                }

                $api = new BlockBee\Helper($selected, $apikey, $callback_url, [], true);

                $addr_in = $api->get_address();

                if (empty($addr_in)) {
                    wc_add_notice(__('Payment error:', 'woocommerce') . ' ' . __('There was an error with the payment. Please try again.', 'blockbee-cryptocurrency-payment-gateway'));

                    return null;
                }

                $qr_code_data_value = BlockBee\Helper::get_static_qrcode($addr_in, $selected, $crypto_total, $apikey, $this->qrcode_size);
                $qr_code_data = BlockBee\Helper::get_static_qrcode($addr_in, $selected, '', $apikey, $this->qrcode_size);

                $order->add_meta_data('blockbee_version', BLOCKBEE_PLUGIN_VERSION);
                $order->add_meta_data('blockbee_checkout', 'false');
                $order->add_meta_data('blockbee_php_version', PHP_VERSION);
                $order->add_meta_data('blockbee_nonce', $nonce);
                $order->add_meta_data('blockbee_address', $addr_in);
                $order->add_meta_data('blockbee_total', BlockBee\Helper::sig_fig($crypto_total, 8));
                $order->add_meta_data('blockbee_total_fiat', $total);
                $order->add_meta_data('blockbee_currency', $selected);
                $order->add_meta_data('blockbee_qr_code_value', $qr_code_data_value['qr_code']);
                $order->add_meta_data('blockbee_qr_code', $qr_code_data['qr_code']);
                $order->add_meta_data('blockbee_last_price_update', time());
                $order->add_meta_data('blockbee_cancelled', '0');
                $order->add_meta_data('blockbee_min', $min_tx);
                $order->add_meta_data('blockbee_history', json_encode([]));
                $order->add_meta_data('blockbee_callback_url', $callback_url);
                $order->add_meta_data('blockbee_last_checked', $order->get_date_created()->getTimestamp());
                $order->save_meta_data();

                $order->update_status('on-hold', __('Awaiting payment', 'blockbee-cryptocurrency-payment-gateway') . ': ' . $load_coins[$selected]);
                $woocommerce->cart->empty_cart();

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            } catch (Exception $e) {
                wc_add_notice(__('Payment error:', 'blockbee-cryptocurrency-payment-gateway') . 'Unknown coin', 'error');

                return null;
            }
        }

        wc_add_notice(__('Payment error:', 'woocommerce') . __('Payment could not be processed, please try again', 'blockbee-cryptocurrency-payment-gateway'), 'error');

        return null;
    }

    function validate_payment()
    {
        $data = Helper::process_callback($_GET);

        $order = new WC_Order($data['order_id']);

        $type = $data['wc_api_type'] ?? null;

        if ($type === 'checkout'){
            $saved_success_token=$order->get_meta('blockbee_success_token');
            // If success token provided in IPN isn't the same as the one stored locally will just die here.
            if ($order->is_paid() || $order->get_status() === 'cancelled' || $saved_success_token !== $data['success_token']) {
                die('*ok*');
            }

            $this->process_checkout_ipn($data, $order);
            return;
        }

        if ($order->is_paid() || $order->get_status() === 'cancelled' || $data['nonce'] != $order->get_meta('blockbee_nonce')) {
            die("*ok*");
        }

        $order->update_meta_data('blockbee_last_checked', time());
        $order->save_meta_data();

        // Actually process the callback data
        $this->process_callback_data($data, $order);
    }

    function order_status()
    {
        $order_id = sanitize_text_field($_REQUEST['order_id']);

        try {
            $order = new WC_Order($order_id);
            $counter_calc = (int)$order->get_meta('blockbee_last_price_update') + (int)$this->refresh_value_interval - time();

            if (!$order->is_paid()) {
                if ($counter_calc <= 0) {
                    $updated = $this->refresh_value($order);

                    if ($updated) {
                        $order = new WC_Order($order_id);
                        $counter_calc = (int)$order->get_meta('blockbee_last_price_update') + (int)$this->refresh_value_interval - time();
                    }
                }
            }

            $showMinFee = 0;

            $history = json_decode($order->get_meta('blockbee_history'), true);

            $blockbee_total = $order->get_meta('blockbee_total');
            $order_total = $order->get_total('edit');

            $calc = $this->calc_order($history, $blockbee_total, $order_total);

            $already_paid = $calc['already_paid'];
            $already_paid_fiat = $calc['already_paid_fiat'];

            $min_tx = (float)$order->get_meta('blockbee_min');

            $remaining_pending = $calc['remaining_pending'];
            $remaining_fiat = $calc['remaining_fiat'];

            $blockbee_pending = 0;

            if ($remaining_pending <= 0 && !$order->is_paid() && !empty($history)) {
                $blockbee_pending = 1;
            }

            if (!$order->is_paid()) {
                if ($counter_calc <= 0) {
                    $this->refresh_value($order);
                }
            }

            if (($remaining_pending <= $min_tx && $remaining_pending) > 0 && !$blockbee_pending) {
                $remaining_pending = $min_tx;
                $showMinFee = 1;
            }

            $data = [
                'is_paid' => $order->is_paid(),
                'is_pending' => $blockbee_pending,
                'qr_code_value' => $order->get_meta('blockbee_qr_code_value'),
                'cancelled' => (int)$order->get_meta('blockbee_cancelled'),
                'coin' => strtoupper($order->get_meta('blockbee_currency')),
                'show_min_fee' => $showMinFee,
                'order_history' => json_decode($order->get_meta('blockbee_history'), true),
                'counter' => (string)$counter_calc,
                'crypto_total' => (float)$blockbee_total,
                'already_paid' => $already_paid,
                'remaining' => $remaining_pending <= 0 ? 0 : $remaining_pending,
                'fiat_remaining' => $remaining_fiat <= 0 ? 0 : $remaining_fiat,
                'already_paid_fiat' => $already_paid_fiat <= 0 ? 0 : $already_paid_fiat,
                'fiat_symbol' => get_woocommerce_currency_symbol(),
            ];

            echo json_encode($data);
            die();

        } catch (Exception $e) {
            //
        }

        echo json_encode(['status' => 'error', 'error' => 'Not a valid order_id']);
        die();
    }

    function validate_logs()
    {
        $order_id = sanitize_text_field($_REQUEST['order_id']);
        $order = new WC_Order($order_id);

        $type = (bool)$order->get_meta('blockbee_checkout') ? 'checkout' : 'custom_api';

        $order->update_meta_data('blockbee_last_checked', time());
        $order->save_meta_data();

        try {
            if ($type === 'checkout') {
                $logs = Helper::payment_logs($order->get_meta(('blockbee_payment_id')), $this->api_key);

                if ($logs) {
                    $payload = (array) $logs[0]->payload;
                    $this->process_checkout_ipn($payload, $order);
                }
            } else {
                $callbacks = BlockBee\Helper::check_logs($order->get_meta('blockbee_callback_url'), $order->get_meta('blockbee_currency'));

                if ($callbacks) {
                    foreach ($callbacks as $callback) {
                        $logs = $callback->logs;
                        $request_url = parse_url($logs[0]->request_url);
                        parse_str($request_url['query'], $data);

                        if (empty($history[$data->uuid]) || (!empty($history[$data->uuid]) && (int)$history[$data->uuid]['pending'] === 1 && (int)$data['pending'] === 0)) {
                            $this->process_callback_data($data, $order, true);
                        }
                    }
                }
            }

            die();
        } catch (Exception $e) {
            //
        }
        die();
    }

    function process_checkout_ipn($data, $order) {
        $order->add_order_note(
            '[CONFIRMED] ' . __('User sent a payment of', 'blockbee-cryptocurrency-payment-gateway') . ' ' .
            $data['paid_amount'] . ' ' . $data['paid_coin'] .
            '. TXID(s): ' . $data['txid']
        );

        $order->update_meta_data('blockbee_address', $data['address']);
        $order->update_meta_data('blockbee_txids', $data['txid']);
        $order->update_meta_data('blockbee_fiat_currency', $data['currency']);
        $order->update_meta_data('blockbee_exchange_rate', $data['exchange_rate']);
        $order->update_meta_data('blockbee_currency', $data['paid_coin']);
        $order->update_meta_data('blockbee_currency_amount', $data['paid_amount']);
        $order->save_meta_data();

        $order->payment_complete($data['address']);

        if ($this->virtual_complete) {
            $count_products = count($order->get_items());
            $count_virtual = 0;
            foreach ($order->get_items() as $order_item) {
                $item = wc_get_product($order_item->get_product_id());
                $item_obj = $item->get_type() === 'variable' ? wc_get_product($order_item['variation_id']) : $item;

                if ($item_obj->is_virtual()) {
                    $count_virtual += 1;
                }
            }
            if ($count_virtual === $count_products) {
                $order->update_status('completed');
            }
        }

        // All processed, respond with *ok*
        die('*ok*');
    }

    function process_callback_data($data, $order, $validation = false)
    {
        $coin = $data['coin'];

        $saved_coin = $order->get_meta('blockbee_currency');

        $paid = $data['value_coin'];

        $min_tx = (float)$order->get_meta('blockbee_min');

        $crypto_coin = strtoupper($order->get_meta('blockbee_currency'));

        $history = json_decode($order->get_meta('blockbee_history'), true);

        $apikey = $this->api_key;

        if ($coin !== $saved_coin) {
            $order->add_order_note(
                '[MISSMATCHED PAYMENT] Registered a ' . $paid . ' ' . strtoupper($coin) . '. Order not confirmed because requested currency is ' . $crypto_coin . '. If you wish, you may confirm it manually. (Funds were already forwarded to you).'
            );

            die("*ok*");
        }

        if (!$data['uuid']) {
            if (!$validation) {
                die("*ok*");
            } else {
                return;
            }
        }

        if (empty($history[$data['uuid']])) {
            $conversion = json_decode(stripcslashes($data['value_coin_convert']), true);

            $history[$data['uuid']] = [
                'timestamp' => time(),
                'value_paid' => BlockBee\Helper::sig_fig($paid, 8),
                'value_paid_fiat' => $conversion[strtoupper($order->get_currency())],
                'pending' => $data['pending']
            ];
        } else {
            $history[$data['uuid']]['pending'] = $data['pending'];
        }

        $order->update_meta_data('blockbee_history', json_encode($history));
        $order->save_meta_data();

        $calc = $this->calc_order(json_decode($order->get_meta('blockbee_history'), true), $order->get_meta('blockbee_total'), $order->get_meta('blockbee_total_fiat'));

        $remaining = $calc['remaining'];
        $remaining_pending = $calc['remaining_pending'];

        $order_notes = $this->get_private_order_notes($order);

        $has_pending = false;
        $has_confirmed = false;

        foreach ($order_notes as $note) {
            $note_content = $note['note_content'];

            if (strpos((string)$note_content, 'PENDING') && strpos((string)$note_content, $data['txid_in'])) {
                $has_pending = true;
            }

            if (strpos((string)$note_content, 'CONFIRMED') && strpos((string)$note_content, $data['txid_in'])) {
                $has_confirmed = true;
            }
        }

        if (!$has_pending) {
            $order->add_order_note(
                '[PENDING] ' .
                __('User sent a payment of', 'blockbee-cryptocurrency-payment-gateway') . ' ' .
                $paid . ' ' . $crypto_coin .
                '. TXID: ' . $data['txid_in']
            );
        }

        if (!$has_confirmed && (int)$data['pending'] === 0) {
            $order->add_order_note(
                '[CONFIRMED] ' . __('User sent a payment of', 'blockbee-cryptocurrency-payment-gateway') . ' ' .
                $paid . ' ' . $crypto_coin .
                '. TXID: ' . $data['txid_in']
            );

            if ($remaining > 0) {
                if ($remaining <= $min_tx) {
                    $order->add_order_note(__('Payment detected and confirmed. Customer still need to send', 'blockbee-cryptocurrency-payment-gateway') . ' ' . $min_tx . $crypto_coin, false);
                } else {
                    $order->add_order_note(__('Payment detected and confirmed. Customer still need to send', 'blockbee-cryptocurrency-payment-gateway') . ' ' . $remaining . $crypto_coin, false);
                }
            }
        }

        if ($remaining <= 0) {
            /**
             * Changes the order Status to "completed"
             */
            $order->payment_complete($data['address_in']);
            if ($this->virtual_complete) {
                $count_products = count($order->get_items());
                $count_virtual = 0;
                foreach ($order->get_items() as $order_item) {
                    $item = wc_get_product($order_item->get_product_id());
                    $item_obj = $item->get_type() === 'variable' ? wc_get_product($order_item['variation_id']) : $item;

                    if ($item_obj->is_virtual()) {
                        $count_virtual += 1;
                    }
                }
                if ($count_virtual === $count_products) {
                    $order->update_status('completed');
                }
            }

            $order->save();

            if (!$validation) {
                die("*ok*");
            } else {
                return;
            }
        }

        /**
         * Refreshes the QR Code. If payment is marked as completed, it won't get here.
         */
        if ($remaining_pending < $min_tx) {
            $order->update_meta_data('blockbee_qr_code_value', BlockBee\Helper::get_static_qrcode($order->get_meta('blockbee_address'), $order->get_meta('blockbee_currency'), $min_tx, $apikey, $this->qrcode_size)['qr_code']);
        } else {
            $order->update_meta_data('blockbee_qr_code_value', BlockBee\Helper::get_static_qrcode($order->get_meta('blockbee_address'), $order->get_meta('blockbee_currency'), $remaining_pending, $apikey, $this->qrcode_size)['qr_code']);
        }

        $order->save();

        if (!$validation) {
            die("*ok*");
        }
    }

    function thankyou_page($order_id)
    {
        if (WC_BlockBee_Gateway::$HAS_TRIGGERED) {
            return;
        }
        WC_BlockBee_Gateway::$HAS_TRIGGERED = true;

        if ($this->checkout_enabled) {
            return;
        }

        $order = new WC_Order($order_id);
        // run value conversion
        $updated = $this->refresh_value($order);

        if ($updated) {
            $order = new WC_Order($order_id);
        }

        $total = $order->get_total();
        $coins = $this->load_coins();
        $currency_symbol = get_woocommerce_currency_symbol();
        $address_in = $order->get_meta('blockbee_address');
        $crypto_value = $order->get_meta('blockbee_total');
        $crypto_coin = $order->get_meta('blockbee_currency');
        $qr_code_img_value = $order->get_meta('blockbee_qr_code_value');
        $qr_code_img = $order->get_meta('blockbee_qr_code');
        $qr_code_setting = $this->get_option('qrcode_setting');
        $color_scheme = $this->get_option('color_scheme');
        $min_tx = $order->get_meta('blockbee_min');

        $ajax_url = add_query_arg(array(
            'action' => 'blockbee_order_status',
            'order_id' => $order_id,
        ), home_url('/wp-admin/admin-ajax.php'));

        wp_enqueue_script('blockbee-payment', BLOCKBEE_PLUGIN_URL . 'static/blockbee.js', array(), BLOCKBEE_PLUGIN_VERSION, true);
        wp_add_inline_script('blockbee-payment', "jQuery(function() {let ajax_url = '{$ajax_url}'; setTimeout(function(){check_status(ajax_url)}, 200)})");
        wp_enqueue_style('blockbee-loader-css', BLOCKBEE_PLUGIN_URL . 'static/blockbee.css', false, BLOCKBEE_PLUGIN_VERSION);

        $allowed_to_value = array(
            'btc',
            'eth',
            'bch',
            'ltc',
        );

        $crypto_allowed_value = false;

        $conversion_timer = ((int)$order->get_meta('blockbee_last_price_update') + (int)$this->refresh_value_interval) - time();
        $cancel_timer = $order->get_date_created()->getTimestamp() + (int)$this->order_cancellation_timeout - time();

        if (in_array($crypto_coin, $allowed_to_value, true)) {
            $crypto_allowed_value = true;
        }
        ?>
        <div class="blockbee_payment-panel <?php echo esc_attr($color_scheme) ?>">
            <div class="blockbee_payment_details">
                <?php
                if ($total > 0) {
                    ?>
                    <div class="blockbee_payments_wrapper">
                        <div class="blockbee_qrcode_wrapper" style="<?php
                        if ($this->qrcode_default) {
                            echo esc_attr('display: block');
                        } else {
                            echo esc_attr('display: none');
                        }
                        ?>; width: <?php echo (int)$this->qrcode_size + 20; ?>px;">
                            <?php
                            if ($crypto_allowed_value == true) {
                                ?>
                                <div class="inner-wrapper">
                                    <figure>
                                        <?php
                                        if ($qr_code_setting != 'hide_amount') {
                                            ?>
                                            <img class="blockbee_qrcode no_value" <?php
                                            if ($qr_code_setting == 'amount') {
                                                echo 'style="display:none;"';
                                            }
                                            ?> src="data:image/png;base64,<?php echo $qr_code_img; ?>"
                                                 alt="<?php echo esc_attr(__('QR Code without value', 'blockbee-cryptocurrency-payment-gateway')); ?>"/>
                                            <?php
                                        }
                                        if ($qr_code_setting != 'hide_without_amount') {
                                            ?>
                                            <img class="blockbee_qrcode value" <?php
                                            if ($qr_code_setting == 'without_amount') {
                                                echo 'style="display:none;"';
                                            }
                                            ?> src="data:image/png;base64,<?php echo $qr_code_img_value; ?>"
                                                 alt="<?php echo esc_attr(__('QR Code with value', 'blockbee-cryptocurrency-payment-gateway')); ?>"/>
                                            <?php
                                        }
                                        ?>
                                        <div class="blockbee_qrcode_coin">
                                            <?php echo esc_attr(strtoupper($coins[$crypto_coin]['name'])); ?>
                                        </div>
                                    </figure>
                                    <?php
                                    if ($qr_code_setting != 'hide_amount' && $qr_code_setting != 'hide_without_amount') {
                                        ?>
                                        <div class="blockbee_qrcode_buttons">
                                        <?php
                                        if ($qr_code_setting != 'hide_without_amount') {
                                            ?>
                                            <button class="blockbee_qrcode_btn no_value <?php
                                            if ($qr_code_setting == 'without_amount') {
                                                echo 'active';
                                            }
                                            ?>"
                                                    aria-label="<?php echo esc_attr(__('Show QR Code without value', 'blockbee-cryptocurrency-payment-gateway')); ?>">
                                                <?php echo esc_attr(__('ADDRESS', 'blockbee-cryptocurrency-payment-gateway')); ?>
                                            </button>
                                            <?php
                                        }
                                        if ($qr_code_setting != 'hide_amount') {
                                            ?>
                                            <button class="blockbee_qrcode_btn value <?php
                                            if ($qr_code_setting == 'amount') {
                                                echo 'active';
                                            }
                                            ?>"
                                                    aria-label="<?php echo esc_attr(__('Show QR Code with value', 'blockbee-cryptocurrency-payment-gateway')); ?>">
                                                <?php echo esc_attr(__('WITH AMOUNT', 'blockbee-cryptocurrency-payment-gateway')); ?>
                                            </button>
                                            </div>
                                            <?php
                                        }
                                    }
                                    ?>
                                </div>
                                <?php
                            } else {
                                ?>
                                <div class="inner-wrapper">
                                    <figure>
                                        <img class="blockbee_qrcode no_value"
                                             src="data:image/png;base64,<?php echo $qr_code_img; ?>"
                                             alt="<?php echo esc_attr(__('QR Code without value', 'blockbee-cryptocurrency-payment-gateway')); ?>"/>
                                        <div class="blockbee_qrcode_coin">
                                            <?php echo esc_attr(strtoupper($coins[$crypto_coin]['name'])); ?>
                                        </div>
                                    </figure>
                                    <div class="blockbee_qrcode_buttons">
                                        <button class="blockbee_qrcode_btn no_value active"
                                                aria-label="<?php echo esc_attr(__('Show QR Code without value', 'blockbee-cryptocurrency-payment-gateway')); ?>">
                                            <?php echo esc_attr(__('ADDRESS', 'blockbee-cryptocurrency-payment-gateway')); ?>
                                        </button>
                                    </div>
                                </div>
                                <?php
                            }
                            ?>
                        </div>
                        <div class="blockbee_details_box">
                            <div class="blockbee_details_text">
                                <?php echo esc_attr(__('PLEASE SEND', 'blockbee-cryptocurrency-payment-gateway')); ?>
                                <button class="blockbee_copy blockbee_details_copy"
                                        data-tocopy="<?php echo esc_attr($crypto_value); ?>">
                                    <span><b class="blockbee_value"><?php echo esc_attr($crypto_value); ?></b></span>
                                    <span><b><?php echo esc_attr(strtoupper($crypto_coin)); ?></b></span>
                                    <span class="blockbee_tooltip blockbee_copy_icon_tooltip tip"><?php echo esc_attr(__('COPY', 'blockbee-cryptocurrency-payment-gateway')); ?></span>
                                    <span class="blockbee_tooltip blockbee_copy_icon_tooltip success"
                                          style="display: none"><?php echo esc_attr(__('COPIED!', 'blockbee-cryptocurrency-payment-gateway')); ?></span>
                                </button>
                                <strong>(<?php echo esc_attr($currency_symbol) . "<span class='blockbee_fiat_total'>" . esc_attr($total) . "</span>" ?>
                                    )</strong>
                            </div>
                            <div class="blockbee_payment_notification blockbee_notification_payment_received"
                                 style="display: none;">
                                <?php echo sprintf(__('So far you sent %1s. Please send a new payment to complete the order, as requested above', 'blockbee-cryptocurrency-payment-gateway'),
                                    '<strong><span class="blockbee_notification_amount"></span></strong>'
                                ); ?>
                            </div>
                            <div class="blockbee_payment_notification blockbee_notification_remaining"
                                 style="display: none">
                                <?php echo '<strong>' . __('Notice', 'blockbee-cryptocurrency-payment-gateway') . '</strong>: ' . sprintf(__('For technical reasons, the minimum amount for each transaction is %1s, so we adjusted the value by adding the remaining to it.', 'blockbee-cryptocurrency-payment-gateway'),
                                        esc_attr($min_tx) . ' ' . esc_attr(strtoupper($coins[$crypto_coin]['name'])),
                                        '<span class="blockbee_notification_remaining"></span>'
                                    ); ?>
                            </div>
                            <?php
                            if ((int)$this->refresh_value_interval != 0) {
                                ?>
                                <div class="blockbee_time_refresh">
                                    <?php echo esc_attr(sprintf(__('The %1s conversion rate will be adjusted in', 'blockbee-cryptocurrency-payment-gateway'),
                                        esc_attr(strtoupper($coins[$crypto_coin]['name']))
                                    )); ?>
                                    <span class="blockbee_time_seconds_count"
                                          data-soon="<?php echo esc_attr(__('a moment', 'blockbee-cryptocurrency-payment-gateway')); ?>"
                                          data-seconds="<?php echo esc_attr($conversion_timer); ?>"><?php echo date('i:s', esc_attr($conversion_timer)); ?></span>
                                </div>
                                <?php
                            }
                            ?>
                            <div class="blockbee_details_input">
                                <span><?php echo esc_attr($address_in) ?></span>
                                <button class="blockbee_copy blockbee_copy_icon"
                                        data-tocopy="<?php echo esc_attr($address_in); ?>">
                                    <span class="blockbee_tooltip blockbee_copy_icon_tooltip tip"><?php echo esc_attr(__('COPY', 'blockbee-cryptocurrency-payment-gateway')); ?></span>
                                    <span class="blockbee_tooltip blockbee_copy_icon_tooltip success"
                                          style="display: none"><?php echo esc_attr(__('COPIED!', 'blockbee-cryptocurrency-payment-gateway')); ?></span>
                                </button>
                                <div class="blockbee_loader"></div>
                            </div>
                        </div>
                        <?php
                        if ((int)$this->order_cancellation_timeout != 0) {
                            ?>
                            <span class="blockbee_notification_cancel"
                                  data-text="<?php echo esc_attr(__('Order will be cancelled in less than a minute.', 'blockbee-cryptocurrency-payment-gateway')); ?>">
                                    <?php echo sprintf(__('This order will be valid for %s', 'blockbee-cryptocurrency-payment-gateway'), '<strong><span class="blockbee_cancel_timer" data-timestamp="' . esc_attr($cancel_timer) . '">' . date('H:i', $cancel_timer) . '</span></strong>'); ?>
                                </span>
                            <?php
                        }
                        ?>
                        <div class="blockbee_buttons_container">
                            <a class="blockbee_show_qr" href="#"
                               aria-label="<?php echo esc_attr(__('Show the QR code', 'blockbee-cryptocurrency-payment-gateway')); ?>">
                                <span class="blockbee_show_qr_open <?php
                                if (!$this->qrcode_default) {
                                    echo ' active';
                                }
                                ?>"><?php echo __('Open QR CODE', 'blockbee-cryptocurrency-payment-gateway'); ?></span>
                                <span class="blockbee_show_qr_close<?php
                                if ($this->qrcode_default) {
                                    echo ' active';
                                }
                                ?>"><?php echo esc_attr(__('Close QR CODE', 'blockbee-cryptocurrency-payment-gateway')); ?></span>
                            </a>
                        </div>
                        <?php
                        if ($this->show_branding) {
                            ?>
                            <div class="blockbee_branding">
                                <a href="https://blockbee.io/" target="_blank">
                                    <span>Powered by</span>
                                    <img width="94" class="img-fluid"
                                         src="<?php echo esc_url(BLOCKBEE_PLUGIN_URL . 'static/files/blockbee_logo.png') ?>"
                                         alt="BlockBee Logo"/>
                                </a>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                    <?php
                }
                if ($total == 0) {
                    ?>
                    <style>
                        .blockbee_payment_confirmed {
                            display: block !important;
                            height: 100% !important;
                        }
                    </style>
                    <?php
                }
                ?>
                <div class="blockbee_payment_processing" style="display: none;">
                    <div class="blockbee_payment_processing_icon">
                        <div class="blockbee_loader_payment_processing"></div>
                    </div>
                    <h2><?php echo esc_attr(__('Your payment is being processed!', 'blockbee-cryptocurrency-payment-gateway')); ?></h2>
                    <h5><?php echo esc_attr(__('Processing can take some time depending on the blockchain.', 'blockbee-cryptocurrency-payment-gateway')); ?></h5>
                </div>

                <div class="blockbee_payment_confirmed" style="display: none;">
                    <div class="blockbee_payment_confirmed_icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                            <path fill="#66BB6A"
                                  d="M504 256c0 136.967-111.033 248-248 248S8 392.967 8 256 119.033 8 256 8s248 111.033 248 248zM227.314 387.314l184-184c6.248-6.248 6.248-16.379 0-22.627l-22.627-22.627c-6.248-6.249-16.379-6.249-22.628 0L216 308.118l-70.059-70.059c-6.248-6.248-16.379-6.248-22.628 0l-22.627 22.627c-6.248 6.248-6.248 16.379 0 22.627l104 104c6.249 6.249 16.379 6.249 22.628.001z"></path>
                        </svg>
                    </div>
                    <h2><?php echo esc_attr(__('Your payment has been confirmed!', 'blockbee-cryptocurrency-payment-gateway')); ?></h2>
                </div>

                <div class="blockbee_payment_cancelled" style="display: none;">
                    <div class="blockbee_payment_cancelled_icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                            <path fill="#c62828"
                                  d="M504 256c0 136.997-111.043 248-248 248S8 392.997 8 256C8 119.083 119.043 8 256 8s248 111.083 248 248zm-248 50c-25.405 0-46 20.595-46 46s20.595 46 46 46 46-20.595 46-46-20.595-46-46-46zm-43.673-165.346l7.418 136c.347 6.364 5.609 11.346 11.982 11.346h48.546c6.373 0 11.635-4.982 11.982-11.346l7.418-136c.375-6.874-5.098-12.654-11.982-12.654h-63.383c-6.884 0-12.356 5.78-11.981 12.654z"></path>
                        </svg>
                    </div>
                    <h2><?php echo esc_attr(__('Order has been cancelled due to lack of payment. Please don\'t send any payment to the address.', 'blockbee-cryptocurrency-payment-gateway')); ?></h2>
                </div>
                <div class="blockbee_history" style="display: none;">
                    <table class="blockbee_history_fill">
                        <tr class="blockbee_history_header">
                            <th>
                                <strong><?php echo esc_attr(__('Time', 'blockbee-cryptocurrency-payment-gateway')); ?></strong>
                            </th>
                            <th>
                                <strong><?php echo esc_attr(__('Value Paid', 'blockbee-cryptocurrency-payment-gateway')); ?></strong>
                            </th>
                            <th>
                                <strong><?php echo esc_attr(__('FIAT Value', 'blockbee-cryptocurrency-payment-gateway')); ?></strong>
                            </th>
                        </tr>
                    </table>
                </div>
                <?php
                if ($total > 0) {
                    ?>
                    <div class="blockbee_progress">
                        <div class="blockbee_progress_icon waiting_payment done">
                            <svg width="60" height="60" viewBox="0 0 50 50" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M49.2188 25C49.2188 38.3789 38.3789 49.2188 25 49.2188C11.6211 49.2188 0.78125 38.3789 0.78125 25C0.78125 11.6211 11.6211 0.78125 25 0.78125C38.3789 0.78125 49.2188 11.6211 49.2188 25ZM35.1953 22.1777L28.125 29.5508V11.7188C28.125 10.4199 27.0801 9.375 25.7812 9.375H24.2188C22.9199 9.375 21.875 10.4199 21.875 11.7188V29.5508L14.8047 22.1777C13.8965 21.2305 12.3828 21.2109 11.4551 22.1387L10.3906 23.2129C9.47266 24.1309 9.47266 25.6152 10.3906 26.5234L23.3398 39.4824C24.2578 40.4004 25.7422 40.4004 26.6504 39.4824L39.6094 26.5234C40.5273 25.6055 40.5273 24.1211 39.6094 23.2129L38.5449 22.1387C37.6172 21.2109 36.1035 21.2305 35.1953 22.1777V22.1777Z"
                                      fill="#C79A05"/>
                            </svg>
                            <p><?php echo esc_attr(__('Waiting for payment', 'blockbee-cryptocurrency-payment-gateway')); ?></p>
                        </div>
                        <div class="blockbee_progress_icon waiting_network">
                            <svg width="60" height="60" viewBox="0 0 50 50" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M46.875 15.625H3.125C1.39912 15.625 0 14.2259 0 12.5V6.25C0 4.52412 1.39912 3.125 3.125 3.125H46.875C48.6009 3.125 50 4.52412 50 6.25V12.5C50 14.2259 48.6009 15.625 46.875 15.625ZM42.1875 7.03125C40.8931 7.03125 39.8438 8.08057 39.8438 9.375C39.8438 10.6694 40.8931 11.7188 42.1875 11.7188C43.4819 11.7188 44.5312 10.6694 44.5312 9.375C44.5312 8.08057 43.4819 7.03125 42.1875 7.03125ZM35.9375 7.03125C34.6431 7.03125 33.5938 8.08057 33.5938 9.375C33.5938 10.6694 34.6431 11.7188 35.9375 11.7188C37.2319 11.7188 38.2812 10.6694 38.2812 9.375C38.2812 8.08057 37.2319 7.03125 35.9375 7.03125ZM46.875 31.25H3.125C1.39912 31.25 0 29.8509 0 28.125V21.875C0 20.1491 1.39912 18.75 3.125 18.75H46.875C48.6009 18.75 50 20.1491 50 21.875V28.125C50 29.8509 48.6009 31.25 46.875 31.25ZM42.1875 22.6562C40.8931 22.6562 39.8438 23.7056 39.8438 25C39.8438 26.2944 40.8931 27.3438 42.1875 27.3438C43.4819 27.3438 44.5312 26.2944 44.5312 25C44.5312 23.7056 43.4819 22.6562 42.1875 22.6562ZM35.9375 22.6562C34.6431 22.6562 33.5938 23.7056 33.5938 25C33.5938 26.2944 34.6431 27.3438 35.9375 27.3438C37.2319 27.3438 38.2812 26.2944 38.2812 25C38.2812 23.7056 37.2319 22.6562 35.9375 22.6562ZM46.875 46.875H3.125C1.39912 46.875 0 45.4759 0 43.75V37.5C0 35.7741 1.39912 34.375 3.125 34.375H46.875C48.6009 34.375 50 35.7741 50 37.5V43.75C50 45.4759 48.6009 46.875 46.875 46.875ZM42.1875 38.2812C40.8931 38.2812 39.8438 39.3306 39.8438 40.625C39.8438 41.9194 40.8931 42.9688 42.1875 42.9688C43.4819 42.9688 44.5312 41.9194 44.5312 40.625C44.5312 39.3306 43.4819 38.2812 42.1875 38.2812ZM35.9375 38.2812C34.6431 38.2812 33.5938 39.3306 33.5938 40.625C33.5938 41.9194 34.6431 42.9688 35.9375 42.9688C37.2319 42.9688 38.2812 41.9194 38.2812 40.625C38.2812 39.3306 37.2319 38.2812 35.9375 38.2812Z"
                                      fill="#C79A05"/>
                            </svg>
                            <p><?php echo esc_attr(__('Waiting for network confirmation', 'blockbee-cryptocurrency-payment-gateway')); ?></p>
                        </div>
                        <div class="blockbee_progress_icon payment_done">
                            <svg width="60" height="60" viewBox="0 0 50 50" fill="none"
                                 xmlns="http://www.w3.org/2000/svg">
                                <path d="M45.0391 12.5H7.8125C6.94922 12.5 6.25 11.8008 6.25 10.9375C6.25 10.0742 6.94922 9.375 7.8125 9.375H45.3125C46.1758 9.375 46.875 8.67578 46.875 7.8125C46.875 5.22363 44.7764 3.125 42.1875 3.125H6.25C2.79785 3.125 0 5.92285 0 9.375V40.625C0 44.0771 2.79785 46.875 6.25 46.875H45.0391C47.7754 46.875 50 44.7725 50 42.1875V17.1875C50 14.6025 47.7754 12.5 45.0391 12.5ZM40.625 32.8125C38.8994 32.8125 37.5 31.4131 37.5 29.6875C37.5 27.9619 38.8994 26.5625 40.625 26.5625C42.3506 26.5625 43.75 27.9619 43.75 29.6875C43.75 31.4131 42.3506 32.8125 40.625 32.8125Z"
                                      fill="#C79A05"/>
                            </svg>
                            <p><?php echo esc_attr(__('Payment confirmed', 'blockbee-cryptocurrency-payment-gateway')); ?></p>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
        <?php
    }

    function cronjob()
    {
        $order_timeout = (int)$this->order_cancellation_timeout;

        if ($order_timeout === 0) {
            return;
        }

        $orders = wc_get_orders(array(
            'status' => array('wc-on-hold'),
            'payment_method' => 'blockbee',
            'date_created' => '<' . (time() - $order_timeout),
        ));

        if (empty($orders)) {
            return;
        }

        foreach ($orders as $order) {
            $order->update_status('cancelled', __('Order cancelled due to lack of payment.', 'blockbee-cryptocurrency-payment-gateway'));
            $order->update_meta_data('blockbee_cancelled', '1');
            $order->save();
        }
    }

    function calc_order($history, $total, $total_fiat)
    {
        $already_paid = 0;
        $already_paid_fiat = 0;
        $remaining = $total;
        $remaining_pending = $total;
        $remaining_fiat = $total_fiat;

        if (!empty($history)) {
            foreach ($history as $uuid => $item) {
                if ((int)$item['pending'] === 0) {
                    $remaining = bcsub(BlockBee\Helper::sig_fig($remaining, 8), $item['value_paid'], 8);
                }

                $remaining_pending = bcsub(BlockBee\Helper::sig_fig($remaining_pending, 8), $item['value_paid'], 8);
                $remaining_fiat = bcsub(BlockBee\Helper::sig_fig($remaining_fiat, 8), $item['value_paid_fiat'], 8);

                $already_paid = bcadd(BlockBee\Helper::sig_fig($already_paid, 8), $item['value_paid'], 8);
                $already_paid_fiat = bcadd(BlockBee\Helper::sig_fig($already_paid_fiat, 8), $item['value_paid_fiat'], 8);
            }
        }

        return [
            'already_paid' => (float)$already_paid,
            'already_paid_fiat' => (float)$already_paid_fiat,
            'remaining' => (float)$remaining,
            'remaining_pending' => (float)$remaining_pending,
            'remaining_fiat' => (float)$remaining_fiat
        ];
    }

    /**
     * WooCommerce Subscriptions Integration
     */
    function scheduled_subscription_mail($amount, $renewal_order)
    {

        $order = $renewal_order;

        $costumer_id = get_post_meta($order->get_id(), '_customer_user', true);
        $customer = new WC_Customer($costumer_id);

        if (empty($order->get_meta('blockbee_paid'))) {
            $mailer = WC()->mailer();

            $recipient = $customer->get_email();

            $subject = sprintf('[%s] %s', get_bloginfo('name'), __('Please renew your subscription', 'blockbee-cryptocurrency-payment-gateway'));
            $headers = 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>' . '\r\n';

            $content = wc_get_template_html('emails/renewal-email.php', array(
                'order' => $order,
                'email_heading' => get_bloginfo('name'),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $mailer
            ), plugin_dir_path(dirname(__FILE__)), plugin_dir_path(dirname(__FILE__)));

            $mailer->send($recipient, $subject, $content, $headers);

            $order->add_meta_data('blockbee_paid', '1');
            $order->save_meta_data();
        }
    }

    private function generate_nonce($len = 32)
    {
        $data = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');

        $nonce = [];
        for ($i = 0; $i < $len; $i++) {
            $nonce[] = $data[mt_rand(0, sizeof($data) - 1)];
        }

        return implode('', $nonce);
    }

    function handling_fee()
    {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        $chosen_payment_id = WC()->session->get('chosen_payment_method');

        if ($chosen_payment_id != 'blockbee') {
            return;
        }

        $apikey = $this->get_option('api_key');

        $total_fee = $this->get_option('fee_order_percentage') === '0' ? 0 : (float)$this->get_option('fee_order_percentage');

        $fee_order = 0;

        if ($total_fee !== 0 || $this->add_blockchain_fee) {

            if ($total_fee !== 0) {
                $fee_order = (float)WC()->cart->subtotal * $total_fee;
            }

            $selected = WC()->session->get('blockbee_coin');

            if ($selected === 'none') {
                return;
            }

            if (!empty($selected) && $selected != 'none' && $this->add_blockchain_fee) {
                $est = BlockBee\Helper::get_estimate($selected, $apikey);

                $fee_order += (float)$est->{get_woocommerce_currency()};
            }

            if (empty($fee_order)) {
                return;
            }

            WC()->cart->add_fee(__('Service Fee', 'blockbee-cryptocurrency-payment-gateway'), $fee_order, true);
        }
    }

    function refresh_checkout()
    {
        if (WC_BlockBee_Gateway::$HAS_TRIGGERED) {
            return;
        }
        WC_BlockBee_Gateway::$HAS_TRIGGERED = true;
        if (is_checkout()) {
            wp_register_script('blockbee-checkout', '');
            wp_enqueue_script('blockbee-checkout');
            wp_add_inline_script('blockbee-checkout', "jQuery(function ($) { $('form.checkout').on('change', 'input[name=payment_method], #payment_blockbee_coin', function () { $(document.body).trigger('update_checkout');});});");
        }
    }

    function chosen_currency_value_to_wc_session($posted_data)
    {
        parse_str($posted_data, $fields);

        if (isset($fields['blockbee_coin'])) {
            WC()->session->set('blockbee_coin', $fields['blockbee_coin']);
        }
    }

    public function process_admin_options()
    {
        // parent::update_option('coins', $_POST['coins']);
        parent::process_admin_options();
        try {
            $this->reset_load_coins();
        } catch (Exception $e) {
            // pass
        }
    }

    function add_email_link($order, $sent_to_admin, $plain_text, $email)
    {
        if (WC_BlockBee_Gateway::$HAS_TRIGGERED) {
            return;
        }

        if ($email->id == 'customer_on_hold_order') {
            WC_BlockBee_Gateway::$HAS_TRIGGERED = true;

            $link = (bool) $order->get_meta('blockbee_checkout') ? $order->get_meta('blockbee_payment_url') : $order->get_checkout_payment_url();

            if ($plain_text) {
                echo $link;
            } else {
                echo wp_kses_post('<div style="text-align:center; margin-bottom: 30px;"><a style="display:block;text-align:center;margin: 40px auto; font-size: 16px; font-weight: bold;" href="' . esc_url($link) . '" target="_blank">' . __('Check your payment status', 'blockbee-cryptocurrency-payment-gateway') . '</a></div>');
            }
        }
    }

    function add_order_link($actions, $order)
    {
        if ($order->has_status('on-hold')) {
            $action_slug = 'blockbee_payment_url';
            $link = (bool) $order->get_meta('blockbee_checkout') ? $order->get_meta('blockbee_payment_url') : $order->get_checkout_payment_url();

            $actions[$action_slug] = array(
                'url' => $link,
                'name' => __('Pay', 'blockbee-cryptocurrency-payment-gateway'),
            );
        }

        return $actions;
    }

    function get_private_order_notes($order)
    {
        $results = wc_get_order_notes([
            'order_in' => $order->get_id(),
            'order__in' => $order->get_id()
        ]);

        foreach ($results as $note) {
            if (!$note->customer_note) {
                $order_note[] = array(
                    'note_id' => $note->id,
                    'note_date' => $note->date_created,
                    'note_content' => $note->content,
                );
            }
        }

        return $order_note;
    }

    function order_detail_validate_logs($order)
    {
        if (WC_BlockBee_Gateway::$HAS_TRIGGERED) {
            return;
        }

        if ($order->is_paid()) {
            return;
        }

        if ($order->get_payment_method() !== 'blockbee') {
            return;
        }

        $ajax_url = add_query_arg(array(
            'action' => 'blockbee_validate_logs',
            'order_id' => $order->get_ID(),
        ), home_url('/wp-admin/admin-ajax.php'));
        ?>
        <p class="form-field form-field-wide wc-customer-user">
            <small style="display: block;">
                <?php echo sprintf(esc_attr(__('If the order is not being updated, your ISP is probably blocking our IPs (%1$s and %2$s): please try to get them whitelisted and feel free to contact us anytime to get support (link to our contact page). In the meantime you can refresh the status of any payment by clicking this button below:', 'blockbee-cryptocurrency-payment-gateway')), '145.239.119.223', '135.125.112.47'); ?>
            </small>
        </p>
        <a style="margin-top: 1rem;margin-bottom: 1rem;" id="validate_callbacks" class="button action" href="#">
            <?php echo esc_attr(__('Check for Callbacks', 'blockbee-cryptocurrency-payment-gateway')); ?>
        </a>
        <script>
            jQuery(function () {
                const validate_button = jQuery('#validate_callbacks')

                validate_button.on('click', function (e) {
                    e.preventDefault()
                    validate_callbacks()
                    validate_button.html('<?php echo esc_attr(__('Checking', 'blockbee-cryptocurrency-payment-gateway'));?>')
                })

                function validate_callbacks() {
                    jQuery.getJSON('<?php echo $ajax_url?>').always(function () {
                        window.location.reload()
                    })
                }
            })
        </script>
        <?php
        WC_BlockBee_Gateway::$HAS_TRIGGERED = true;
    }

    function refresh_value($order)
    {
        $value_refresh = (int)$this->refresh_value_interval;

        if ($value_refresh === 0) {
            return false;
        }

        $woocommerce_currency = get_woocommerce_currency();
        $last_price_update = $order->get_meta('blockbee_last_price_update');
        $min_tx = (float)$order->get_meta('blockbee_min');
        $history = json_decode($order->get_meta('blockbee_history'), true);
        $apikey = $this->api_key;
        $blockbee_total = $order->get_meta('blockbee_total');
        $order_total = $order->get_total('edit');

        $calc = $this->calc_order($history, $blockbee_total, $order_total);
        $remaining = $calc['remaining'];
        $remaining_pending = $calc['remaining_pending'];

        if ((int)$last_price_update + $value_refresh < time() && !empty($last_price_update) && $remaining === $remaining_pending && $remaining_pending > 0) {
            $blockbee_coin = $order->get_meta('blockbee_currency');

            $crypto_conversion = (float)BlockBee\Helper::get_conversion($woocommerce_currency, $blockbee_coin, $order_total, $this->disable_conversion);
            $crypto_total = BlockBee\Helper::sig_fig($crypto_conversion, 8);
            $order->update_meta_data('blockbee_total', $crypto_total);

            $calc_cron = $this->calc_order($history, $crypto_total, $order_total);
            $crypto_remaining_total = $calc_cron['remaining_pending'];

            if ($remaining_pending <= $min_tx && !$remaining_pending <= 0) {
                $qr_code_data_value = BlockBee\Helper::get_static_qrcode($order->get_meta('blockbee_address'), $blockbee_coin, $min_tx, $apikey, $this->qrcode_size);
            } else {
                $qr_code_data_value = BlockBee\Helper::get_static_qrcode($order->get_meta('blockbee_address'), $blockbee_coin, $crypto_remaining_total, $apikey, $this->qrcode_size);
            }

            $order->update_meta_data('blockbee_qr_code_value', $qr_code_data_value['qr_code']);
            $order->update_meta_data('blockbee_last_price_update', time());
            $order->save_meta_data();

            return true;
        }

        return false;
    }
}
