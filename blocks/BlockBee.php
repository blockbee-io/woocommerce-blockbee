<?php

namespace BlockBee\Blocks;

require_once BLOCKBEE_PLUGIN_PATH . '/utils/Helper.php';

use BlockBee\Controllers\WC_BlockBee_Gateway;
use BlockBee\Utils\Helper;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Exception;

class WC_BlockBee_Payments extends AbstractPaymentMethodType {
    /**
     * @var WC_BlockBee_Gateway
     */
    private $gateway;

    /**
     * @var string
     */
    protected $name = 'blockbee';

    /**
     * @var array<string,mixed>
     */
    protected $settings = [];

    /**
     * @var string
     */
    private string $scriptId = '';

    /**
     * @return void
     */
    public function __construct()
    {
        add_action('woocommerce_blocks_enqueue_checkout_block_scripts_after', [$this, 'register_style']);
    }

    /**
     * @return void
     */
    public function initialize(): void
    {
        $this->settings = get_option("woocommerce_{$this->name}_settings", []);
        $this->gateway = WC()->payment_gateways->payment_gateways()[$this->name];
    }

    /**
     * @return bool
     */
    public function is_active() {
        $is_active = false;

        if (!empty($this->get_setting('api_key')) && filter_var( $this->get_setting( 'enabled', false ), FILTER_VALIDATE_BOOLEAN )) {
            $is_active = true;
        }

        return $is_active;
    }

    /**
     * @return array<string,mixed>
     */
    public function get_payment_method_data(): array
    {
        $load_coins = \BlockBee\Controllers\WC_BlockBee_Gateway::load_coins();
        $output_coins = [];

        foreach ($this->get_setting('coins') as $coin) {
            $output_coins[] = array_merge(
                ['ticker' => $coin],
                $load_coins[$coin]
            );
        }

        return [
            'name'     => $this->name,
            'label'    => $this->get_setting('title'),
            'icons'    => $this->get_payment_method_icons(),
            'content'  => $this->get_setting('description'),
            'button'   => $this->get_setting('order_button_text'),
            'description'   => $this->get_setting('description'),
            'checkout_enabled' => $this-> get_setting('checkout_enabled') === 'yes',
            'coins' => $output_coins,
            'show_branding' => $this-> get_setting('show_branding') === 'yes',
            'show_crypto_logos' => $this-> get_setting('show_crypto_logos') === 'yes',
            'add_blockchain_fee' => $this-> get_setting('add_blockchain_fee') === 'yes',
            'fee_order_percentage' => (float) $this-> get_setting('fee_order_percentage'),
            'supports' => array_filter($this->gateway->supports, [$this->gateway, 'supports']),
            'translations' => [
                'please_select_cryptocurrency' => __('Please select a Cryptocurrency', 'blockbee'),
                'error_ocurred' => __('There was an error with the payment. Please try again.', 'blockbee'),
                'cart_must_be_higher' => __('The cart total must be higher to use this cryptocurrency.', 'blockbee')
            ],
        ];
    }

    /**
     * @return array<array<string,string>>
     */
    public function get_payment_method_icons(): array
    {
        return [
            [
                'id'  => $this->name,
                'alt' => $this->get_setting('title'),
                'src' => $this->get_setting('show_crypto_logos') === 'yes' ? esc_url(BLOCKBEE_PLUGIN_URL) . 'static/files/blockbee_logo.png' : ''
            ]
        ];
    }

    /**
     * @return array<string>
     */
    public function get_payment_method_script_handles(): array
    {
        if (!$this->is_active()) {
            return [];
        }

        $handle = 'blockbee-' . str_replace(['.js', '_', '.'], ['', '-', '-'], 'blocks.js');

        $version = defined('BLOCKBEE_PLUGIN_VERSION') ? BLOCKBEE_PLUGIN_VERSION : false;

        wp_register_script($handle, BLOCKBEE_PLUGIN_URL . 'static/' . 'blocks.js', [
            'wc-blocks-registry',
            'wc-blocks-checkout',
            'wp-element',
            'wp-i18n',
            'wp-components',
            'wp-blocks',
            'wp-hooks',
            'wp-data',
            'wp-api-fetch'
        ], $version, true);
        wp_localize_script($handle, 'blockbeeData', [
            'nonce' => wp_create_nonce('blockbee_rest'),
        ]);

        return [
            $this->scriptId = $handle
        ];
    }

    /**
     * @return string
     */
    public function register_style(): string
    {
        $handle = 'blockbee-' . str_replace(['.css', '_', '.'], ['', '-', '-'], 'blocks-styles.css');
        $version = defined('BLOCKBEE_PLUGIN_VERSION') ? BLOCKBEE_PLUGIN_VERSION : false;

        wp_register_style(
            $handle,
            BLOCKBEE_PLUGIN_URL . 'static/' . 'blocks-styles.css',
            [],
            $version
        );
        wp_enqueue_style($handle);

        return $handle;
    }
}
