<?php
namespace Mobbex\WP\Checkout\Models;


class Helper
{
    /** @var Config */
    public $config;

    /**
     * Load plugin settings.
     */
    public function __construct()
    {
        $this->config = new Config();
    }

    public function get_api_endpoint($endpoint, $order_id)
    {
        $query = [
            'mobbex_token' => $this->generate_token(),
            'platform' => "woocommerce",
            "version" => MOBBEX_VERSION,
        ];

        if ($order_id)
            $query['mobbex_order_id'] = $order_id;
    
        if ($endpoint === 'mobbex_webhook') {
            if ($this->config->debug_mode != 'no')
                $query['XDEBUG_SESSION_START'] = 'PHPSTORM';
            return add_query_arg($query, get_rest_url(null, 'mobbex/v1/webhook'));
        } else 
            $query['wc-api'] = $endpoint;
        return add_query_arg($query, home_url('/'));
    }

    public function valid_mobbex_token($token)
    {
        return $token == $this->generate_token();
    }

    public function generate_token()
    {
        return md5($this->config->api_key . '|' . $this->config->access_token);
    }

    /**
     * Retrieve a checkout created from current Cart|Order as appropriate.
     * 
     * @uses Only to show payment options.
     * 
     * @return array|null
     */
    public function get_context_checkout()
    {
        $order = wc_get_order(get_query_var('order-pay'));
        $cart  = WC()->cart;

        $helper = $order ? new \Mobbex\WP\Checkout\Helper\OrderHelper($order) : new \Mobbex\WP\Checkout\Helper\CartHelper($cart);

        // If is pending order page create checkout from order and return
        if ($order)
            return $helper->create_checkout();

        // Try to get previous cart checkout data
        $cart_checkout = WC()->session->get('mobbex_cart_checkout');
        $cart_hash     = $cart->get_cart_hash();

        $response = isset($cart_checkout[$cart_hash]) ? $cart_checkout[$cart_hash] : $helper->create_checkout();

        if ($response)
            WC()->session->set('mobbex_cart_checkout', [$cart_hash => $response]);

        return $response;
    }

}