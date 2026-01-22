<?php

namespace BlockBee;

require_once 'controllers/BlockBee.php';

class Initialize {
    public function initialize()
    {
        add_action('init', [$this, 'schedule_cron_job']);
    }

    public function schedule_cron_job()
    {
        if (!wp_next_scheduled('blockbee_cronjob')) {
            wp_schedule_event(time(), 'hourly', 'blockbee_cronjob');
        }
    }
}
