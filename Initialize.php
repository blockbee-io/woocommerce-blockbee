<?php

namespace BlockBee;

require_once 'controllers/BlockBee.php';

class Initialize {
    public function initialize()
    {
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'schedule_cron_job']);
    }

    public function load_textdomain()
    {
        $plugin_dir = plugin_dir_path(__FILE__);
        $mo_file_path = $plugin_dir . '../languages/blockbee-payment-gateway-for-woocommerce-' . get_locale() . '.mo';

        if (file_exists($mo_file_path)) {
            load_textdomain('blockbee-cryptocurrency-payment-gateway', $mo_file_path);
        }
    }

    public function schedule_cron_job()
    {
        if (!wp_next_scheduled('blockbee_cronjob')) {
            wp_schedule_event(time(), 'hourly', 'blockbee_cronjob');
        }
    }
}
