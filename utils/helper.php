<?php

namespace BlockBee;

use Exception;

class Helper
{
    private static $base_url = "https://api.blockbee.io";
    private $callback_url = null;
    private $coin = null;
    private $pending = false;
    private $parameters = [];
    private $apikey = null;

    public function __construct($coin, $apikey, $callback_url, $parameters = [], $pending = false)
    {
        $this->callback_url = $callback_url;
        $this->apikey = $apikey;
        $this->coin = $coin;
        $this->pending = $pending ? 1 : 0;
        $this->parameters = $parameters;
    }

    public function get_address()
    {

        if (empty($this->coin) || empty($this->callback_url)) {
            return null;
        }

        $apikey = $this->apikey;

        if (empty($apikey)) {
            return null;
        }

        $callback_url = $this->callback_url;
        if (!empty($this->parameters)) {
            $req_parameters = http_build_query($this->parameters);
            $callback_url = "{$this->callback_url}?{$req_parameters}";
        }

        $bb_params = [
            'apikey' => $apikey,
            'callback' => $callback_url,
            'pending' => $this->pending,
            'convert' => 1,
        ];

        $response = Helper::_request($this->coin, 'create', $bb_params);

        if ($response->status == 'success') {
            $this->payment_address = $response->address_in;

            return $response->address_in;
        }

        return null;
    }

    public static function check_logs($callback, $coin)
	{

		if (empty($coin) || empty($callback)) {
			return false;
		}

		$params = [
			'callback' => $callback,
		];

		$response = Helper::_request($coin, 'logs', $params);

		if ($response->status == 'success') {
			return $response->callbacks;
		}

		return false;
	}

    public static function get_static_qrcode($address, $coin, $value, $api_key, $size = 300)
    {
        if (empty($address)) {
            return null;
        }

        if (!empty($value)) {
            $params = [
                'address' => $address,
                'value' => $value,
                'size' => $size,
                'apikey' => $api_key,
            ];
        } else {
            $params = [
                'address' => $address,
                'size' => $size,
                'apikey' => $api_key,
            ];
        }

        $response = Helper::_request($coin, 'qrcode', $params);

        if ($response->status == 'success') {
            return ['qr_code' => $response->qr_code, 'uri' => $response->payment_uri];
        }

        return null;
    }

    public static function get_supported_coins()
    {
        $info = Helper::get_info(null, true);

        if (empty($info)) {
            return null;
        }

        unset($info['fee_tiers']);

        $coins = [];

        foreach ($info as $chain => $data) {
            $is_base_coin = in_array('ticker', array_keys($data));
            if ($is_base_coin) {
                $coins[$chain] = [
                    'name' => $data['coin'],
                    'logo' => $data['logo'],
                ];
                continue;
            }

            $base_ticker = "{$chain}_";
            foreach ($data as $token => $subdata) {
                $chain_upper = strtoupper($chain);
                $coins[$base_ticker . $token] = [
                    'name' => "{$subdata['coin']} ({$chain_upper})",
                    'logo' => $subdata['logo']
                ];
            }
        }

        return $coins;
    }

    public static function get_info($coin = null, $assoc = false)
    {

        $params = [];

        if (empty($coin)) {
            $params['prices'] = '0';
        }

        $response = Helper::_request($coin, 'info', $params, $assoc);

        if (empty($coin) || $response->status == 'success') {
            return $response;
        }

        return null;
    }

    public static function get_conversion($from, $to, $value, $disable_conversion)
    {

        if ($disable_conversion) {
            return $value;
        }

        $params = [
            'from' => $from,
            'to' => $to,
            'value' => $value,
        ];

        $response = Helper::_request('', 'convert', $params);

        if ($response->status == 'success') {
            return $response->value_coin;
        }

        return null;
    }

    public static function get_estimate($coin, $api_key)
    {

        $params = [
            'addresses' => 1,
            'priority' => 'default',
            'apikey' => $api_key,
        ];

        $response = Helper::_request($coin, 'estimate', $params);

        if ($response->status == 'success') {
            return $response->estimated_cost_currency;
        }

        return null;
    }

    public static function process_callback($_get)
    {
        $type = $_GET['wc_api_type'] ?? null;

        if ($type === 'checkout') {
            $params = [
                'wc_api_type' => $type,
                'address' => $_get['address'] ?? null,
                'txid' => $_get['txid'] ?? null,
                'currency' => $_get['currency'] ?? null,
                'exchange_rate' => $_get['exchange_rate'] ?? null,
                'paid_coin' => $_get['paid_coin'] ?? null,
                'paid_amount' => $_get['paid_amount'] ?? null,
            ];
        } else {
            $params = [
                'address_in' => $_get['address_in'],
                'address_out' => $_get['address_out'],
                'txid_in' => $_get['txid_in'],
                'txid_out' => isset($_get['txid_out']) ? $_get['txid_out'] : null,
                'confirmations' => $_get['confirmations'],
                'value' => $_get['value'],
                'value_coin' => $_get['value_coin'],
                'value_forwarded' => isset($_get['value_forwarded']) ? $_get['value_forwarded'] : null,
                'value_forwarded_coin' => isset($_get['value_forwarded_coin']) ? $_get['value_forwarded_coin'] : null,
                'coin' => $_get['coin'],
                'pending' => isset($_get['pending']) ? $_get['pending'] : false,
            ];
        }

        foreach ($_get as $k => $v) {
            if (isset($params[$k])) {
                continue;
            }
            $params[$k] = $_get[$k];
        }

        foreach ($params as &$val) {
            $val = sanitize_text_field($val);
        }

        return $params;
    }

    public static function sig_fig($value, $digits)
    {
        $value = (string) $value;
        if (strpos($value, '.') !== false) {
            if ($value[0] != '-') {
                return bcadd($value, '0.' . str_repeat('0', $digits) . '5', $digits);
            }

            return bcsub($value, '0.' . str_repeat('0', $digits) . '5', $digits);
        }

        return $value;
    }

    public function payment_request($redirect_url, $value, $currency, $order_id, $cancellation_timeout)
    {
        if (empty($redirect_url) || empty($value)) {
            return null;
        }

        $notify_url = $this->callback_url;

        if (!empty($this->parameters)) {
            $req_parameters = http_build_query($this->parameters);
            $redirect_url   = "{$redirect_url}?{$req_parameters}";
            $notify_url   = "{$this->callback_url}?{$req_parameters}";
        }

        $bb_parameters = [
            'redirect_url' => $redirect_url,
            'notify_url' => $notify_url,
            'apikey' => $this->apikey,
            'value' => $value,
            'currency' => $currency,
            'item_description' => '#' . $order_id
        ];

        if ($cancellation_timeout) {
            $bb_parameters['expire_at'] = (time() + 60) + $cancellation_timeout;
        }

        $response = Helper::_request(null, 'checkout/request', $bb_parameters);

        if ($response->status === 'success') {
            return $response;
        }

        return null;
    }

    public static function payment_logs($token, $api_key){
        $params = [
            'token' => $token,
            'apikey' => $api_key
        ];

        $response = Helper::_request(null, 'checkout/logs', $params);

        if ($response->status === 'success') {
            return $response->notifications;
        }

        return null;
    }

    private static function _request($coin, $endpoint, $params = [], $assoc = false)
    {
        $base_url = Helper::$base_url;

        if (!empty($params)) {
            $data = http_build_query($params);
        }

        if (!empty($coin)) {
            $coin = str_replace('_', '/', $coin);
            $url = "{$base_url}/{$coin}/{$endpoint}/";
        } else {
            $url = "{$base_url}/{$endpoint}/";
        }

        if (!empty($data)) {
            $url .= "?{$data}";
        }

        for ($y = 0; $y < 5; $y++) {
            try {
                $response = json_decode(wp_remote_retrieve_body(wp_remote_get($url)), $assoc);

                if ($assoc && !empty($response['btc'])) {
                    return $response;
                }

                if ($response && $response->status === 'success') {
                    return $response;
                }
            } catch (Exception $e) {
                //
            }
        }

        return json_decode('{"status": "error"}', $assoc);
    }
}
