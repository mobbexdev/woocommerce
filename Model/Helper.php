<?php
namespace Mobbex\WP\Checkout\Model;


class Helper
{
    /**
     * All 'ahora' plans.
     */
    public static $ahora = ['ahora_3', 'ahora_6', 'ahora_12', 'ahora_18'];

    /** @var Config */
    public $config;

    /** @var MobbexApi */
    public $api;

    /**
     * Load plugin settings.
     */
    public function __construct()
    {
        $this->config = new Config();
    }

    public function isReady()
    {
        return ($this->config->enabled === 'yes' && !empty($this->config->api_key) && !empty($this->config->access_token));
    }

    /**
     * Checks if Mobbex Subscription Extension is ready
     * 
     * @return bool
     */
    public function is_extension_ready()
    {
        return $this->config->enable_subscription == 'yes';
    }


    /**
     * Get all product IDs from Order.
     * 
     * @param WP_Order $order
     * @return array $products
     */
    public static function get_product_ids($order)
    {
        $products = [];

        foreach ($order->get_items() as $item)
            $products[] = $item->get_product_id();

        return $products;
    }

    /**
     * Get all category IDs from Order.
     * Duplicates are removed.
     * 
     * @param WP_Order $order
     * @return array $categories
     */
    public static function get_category_ids($order)
    {
        $categories = [];

        // Get Products Ids
        $products = self::get_product_ids($order);

        foreach ($products as $product)
            $categories = array_merge($categories, wp_get_post_terms($product, 'product_cat', ['fields' => 'ids']));

        // Remove duplicated IDs and return
        return array_unique($categories);
    }

    /**
     * Get Store ID from product and its categories.
     * 
     * @param int|string $product_id
     * 
     * @return string|null $store_id
     */
    public static function get_store_from_product($product_id)
    {
        $stores     = get_option('mbbx_stores') ?: [];
        $store      = get_post_meta($product_id, 'mbbx_store', true);
        $ms_enabled = get_post_meta($product_id, 'mbbx_enable_multisite', true);

        if ($ms_enabled && !empty($store) && !empty($stores[$store]))
            return $store;

        // Get stores from product categories
        $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);

        foreach ($categories as $cat_id) {
            $store      = get_term_meta($cat_id, 'mbbx_store', true);
            $ms_enabled = get_term_meta($cat_id, 'mbbx_enable_multisite', true);

            if ($ms_enabled && !empty($store) && !empty($stores[$store]))
                return $store;
        }
    }

    /**
     * Capture 'authorized' payment using Mobbex API.
     * 
     * @param string|int $payment_id
     * @param string|int $total
     * 
     * @return bool $result
     */
    public function capture_payment($payment_id, $total)
    {
        if (!$this->isReady())
            throw new Exception(__('Plugin is not ready', 'mobbex-for-woocommerce'));

        if (empty($payment_id) || empty($total))
            throw new Exception(__('Empty Payment UID or params', 'mobbex-for-woocommerce'));

        // Capture payment
        return \Mobbex\Api::request([
            'method' => 'POST',
            'uri'    => "operations/$payment_id/capture",
            'body'   => compact('total'),
        ]);
    }
    
    /* WEBHOOK METHODS */

    /**
     * Format the webhook data in an array.
     * 
     * @param int $order_id
     * @param array $res
     * @return array $data
     * 
     */
    public static function format_webhook_data($order_id, $res)
    {
        $data = [
            'order_id'           => $order_id,
            'parent'             => isset($res['payment']['id']) ? (self::is_parent_webhook($res['payment']['id']) ? 'yes' : 'no') : '',
            'childs'             => isset($res['childs']) ? json_encode($res['childs']) : '',
            'operation_type'     => isset($res['payment']['operation']['type']) ? $res['payment']['operation']['type'] : '',
            'payment_id'         => isset($res['payment']['id']) ? $res['payment']['id'] : '',
            'description'        => isset($res['payment']['description']) ? $res['payment']['description'] : '',
            'status_code'        => isset($res['payment']['status']['code']) ? $res['payment']['status']['code'] : '',
            'status_message'     => isset($res['payment']['status']['message']) ? $res['payment']['status']['message'] : '',
            'source_name'        => isset($res['payment']['source']['name']) ? $res['payment']['source']['name'] : 'Mobbex',
            'source_type'        => isset($res['payment']['source']['type']) ? $res['payment']['source']['type'] : 'Mobbex',
            'source_reference'   => isset($res['payment']['source']['reference']) ? $res['payment']['source']['reference'] : '',
            'source_number'      => isset($res['payment']['source']['number']) ? $res['payment']['source']['number'] : '',
            'source_expiration'  => isset($res['payment']['source']['expiration']) ? json_encode($res['payment']['source']['expiration']) : '',
            'source_installment' => isset($res['payment']['source']['installment']) ? json_encode($res['payment']['source']['installment']) : '',
            'installment_name'   => isset($res['payment']['source']['installment']['description']) ? json_encode($res['payment']['source']['installment']['description']) : '',
            'installment_amount' => isset($res['payment']['source']['installment']['amount']) ? $res['payment']['source']['installment']['amount'] : '',
            'installment_count'  => isset($res['payment']['source']['installment']['count'] ) ? $res['payment']['source']['installment']['count']  : '',
            'source_url'         => isset($res['payment']['source']['url']) ? json_encode($res['payment']['source']['url']) : '',
            'cardholder'         => isset($res['payment']['source']['cardholder']) ? json_encode(($res['payment']['source']['cardholder'])) : '',
            'entity_name'        => isset($res['entity']['name']) ? $res['entity']['name'] : '',
            'entity_uid'         => isset($res['entity']['uid']) ? $res['entity']['uid'] : '',
            'customer'           => isset($res['customer']) ? json_encode($res['customer']) : '',
            'checkout_uid'       => isset($res['checkout']['uid']) ? $res['checkout']['uid'] : '',
            'total'              => isset($res['payment']['total']) ? $res['payment']['total'] : '',
            'currency'           => isset($res['checkout']['currency']) ? $res['checkout']['currency'] : '',
            'risk_analysis'      => isset($res['payment']['riskAnalysis']['level']) ? $res['payment']['riskAnalysis']['level'] : '',
            'data'               => isset($res) ? json_encode($res) : '',
            'created'            => isset($res['payment']['created']) ? $res['payment']['created'] : '',
            'updated'            => isset($res['payment']['updated']) ? $res['payment']['created'] : '',
        ];
        return $data;
    }

    /**
     * Check if webhook is parent type using him payment id.
     * 
     * @param string $payment_id
     * 
     * @return bool
     */
    public static function is_parent_webhook($payment_id)
    {
        return strpos($payment_id, 'CHD-') !== 0;
    }

    /**
     * Receives an array and returns an array with the data format for the 'insert' method
     * 
     * @param array $array
     * @return array $format
     * 
     */
    public static function db_column_format($array)
    {
        $format = [];

        foreach ($array as $value) {
            switch (gettype($value)) {
                case "bolean":
                    $format[] = '%s';
                    break;
                case "integer":
                    $format[] = '%d';
                    break;
                case "double":
                    $format[] = '%f';
                    break;
                case "string":
                    $format[] = '%s';
                    break;
                case "array":
                    $format[] = '%s';
                    break;
                case "object":
                    $format[] = '%s';
                    break;
                case "resource":
                    $format[] = '%s';
                    break;
                case "NULL":
                    $format[] = '%s';
                    break;
                case "unknown type":
                    $format[] = '%s';
                    break;
                case "bolean":
                    $format[] = '%s';
                    break;
            }
        }
        return $format;
    }

    /**
     * Create a url passing the endpoint and order_id
     * 
     * @param string $endpoint 
     * @param mixed  $order_id 
     * 
     * @return string url
     */
    public function get_api_endpoint($endpoint, $order_id = '')
    {
        // Create necessary query array
        $query = [
            'wc-api'       => $endpoint,
            'platform'     => "woocommerce",
            'version'      => MOBBEX_VERSION,
            'mobbex_token' => \Mobbex\Repository::generateToken(),
        ];

        if ($order_id)
            $query['mobbex_order_id'] = $order_id;
    
        if ($endpoint === 'mobbex_webhook' || $endpoint == 'mobbex_subs_webhook') {
            if ($this->config->debug_mode != 'no')
                $query['XDEBUG_SESSION_START'] = 'PHPSTORM';

            return add_query_arg(
                $query,
                $endpoint === 'mobbex_webhook' ?
                get_rest_url(null, 'mobbex/v1/webhook') :
                home_url('/')
            );
        }
        
        // Add woocommerce api to query
        return add_query_arg($query, home_url('/'));
    }

    /**
     * Get product image passing product id
     * 
     * @param mixed $product_id
     * 
     * @return string $image || $default
     */
    public function get_product_image($product_id)
    {
        // Returns the image of the product that matches the product id
        $product = wc_get_product($product_id);

        if (!$product)
            return;

        $image   = wp_get_attachment_image_url($product->get_image_id(), 'thumbnail');
        $default = wc_placeholder_img_src('thumbnail');

        return $image ?: $default;
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

        $helper = $order ? new \Mobbex\WP\Checkout\Helper\Order($order) : ($cart ? new \Mobbex\WP\Checkout\Helper\Cart($cart) : null);

        // If is pending order page create checkout from order and return
        if ($order)
            return $helper->create_checkout();

        // Try to get previous cart checkout data
        if ($helper){
            $cart_checkout = WC()->session->get('mobbex_cart_checkout');
            $cart_hash     = $cart->get_cart_hash();

            $response = isset($cart_checkout[$cart_hash]) ? $cart_checkout[$cart_hash] : $helper->create_checkout();

            if ($response)
                WC()->session->set('mobbex_cart_checkout', [$cart_hash => $response]);

            return $response;
        }
        return null;
    }

}