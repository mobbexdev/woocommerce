<?php

namespace Mobbex\WP\Checkout\Helper;

class OrderHelper
{
    /** Order instance ID */
    public $id;

    /** @var \WC_Order */
    public $order;

    /** @var \Mobbex\WP\Checkout\Models\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Models\Helper */
    public $helper;

    /** @var \Mobbex\WP\Checkout\Models\Logger */
    public $logger;

    /** @var wpdb */
    public $db;

    public $status_codes = [
        'pending'    => [0, 1],
        'on-hold'    => [2, 100, 201],
        'authorized' => [3],
        'approved'   => [200, 210, 300, 301, 302, 303],
        'refunded'   => [602],
        'failed'     => [],
    ];

    /**
    * Constructor.
    * 
    * @param \WC_Order|int WooCommerce order instance or its id.
    * @param \Mobbex\WP\Checkout\Models\Helper Base plugin helper.
    * @param \Mobbex\WP\Checkout\Models\Logger Base plugin debugger.
    */
    public function __construct($order, $helper = null)
    {
        $this->id     = is_int($order) ? $order : $order->get_id();
        $this->order  = is_int($order) ? wc_get_order($order) : $order;
        $this->config = new \Mobbex\WP\Checkout\Models\Config();
        $this->helper = $helper ?: new \Mobbex\WP\Checkout\Models\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Models\Logger();
        $this->db     = $GLOBALS['wpdb'];
    }

    /**
     * Create a checkout using the WooCommerce Order instance.
     * 
     * @return mixed
     */
    public function create_checkout()
    {
        // Try to configure api with order store credentials
        $store = $this->get_store();

        $api_key      = !empty($store['api_key']) ? $store['api_key'] : null;
        $access_token = !empty($store['access_token']) ? $store['access_token'] : null;

        \Mobbex\Api::init($api_key, $access_token);
        $checkout = new \Mobbex\WP\Checkout\Models\Checkout();

        $this->add_initial_data($checkout);
        $this->add_items($checkout);
        $this->add_installments($checkout);
        $this->add_customer($checkout);

        try {
            $response = $checkout->create();
        } catch (\Exception $e) {
            $response = null;
            $this->logger->log('Mobbex Checkout Creation Failed: ' . $e->getMessage(), isset($e->data) ? $e->data : '', true);
        }

        do_action('mobbex_checkout_process', $response, $this->id);

        return $response;
    }

    /**
     * Add order initial data to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_initial_data($checkout)
    {
        $checkout->set_reference($this->id);
        $checkout->set_total($this->order->get_total());
        $checkout->set_endpoints(
            $this->helper->get_api_endpoint('mobbex_return_url', $this->id),
            $this->helper->get_api_endpoint('mobbex_webhook', $this->id)
        );
    }

    /**
     * Add order items to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_items($checkout)
    {
        $order_items    = $this->order->get_items() ?: [];
        $shipping_items = $this->order->get_items('shipping') ?: [];

        foreach ($order_items as $item)
            $checkout->add_item(
                $item->get_total(),
                $item->get_quantity(),
                $item->get_name(),
                $this->config->get_product_image($item->get_product_id()),
                $this->config->get_product_entity($item->get_product_id()),
                $this->config->get_product_subscription($item->get_product_id())
            );

        foreach ($shipping_items as $item)
            $checkout->add_item($item->get_total(), 1, __('Shipping: ', 'mobbex-for-woocommerce') . $item->get_name());
    }

    /**
     * Add installments configured to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_installments($checkout)
    {
        $products_ids = self::get_product_ids($this->order);

        // Get plans from order products
        extract($this->config->get_catalog_plans($products_ids));
        // Block common plans from installments
        foreach ($common_plans as $plan_ref)
            $checkout->block_installment($plan_ref);

        // Add advanced plans to installments (only if the plan is active on all products)
        foreach (array_count_values($advanced_plans) as $plan_uid => $reps) {
            if ($reps == count($products))
                $checkout->add_installment($plan_uid);
        }
    }

    /**
     * Add order customer data to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_customer($checkout)
    {
        $user = new \WP_User($this->order->get_user_id());

        $checkout->set_customer(
            $this->order->get_formatted_billing_full_name() ?: $user->display_name,
            $this->order->get_billing_email() ?: $user->user_email,
            get_post_meta($this->id, '_billing_dni', true) ?: get_user_meta($user->ID, 'billing_dni', true),
            $this->order->get_billing_phone() ?: get_user_meta($user->ID, 'phone_number', true),
            $user->ID
        );

        $checkout->set_addresses($this->order);
    }

    /**
     * Get Store from order items multisite configuration.
     * 
     * @return array|null
     */
    public function get_store()
    {
        $stores = get_option('mbbx_stores') ?: [];
        $items  = $this->order->get_items() ?: [];

        // Search store configured
        foreach ($items as $item) {
            $store_id = $this->config->get_store_from_product($item->get_product_id());

            if ($store_id && !empty($stores[$store_id]))
                return $stores[$store_id];
        }
    }

    /**
     * Get an order status name from an operation status code.
     * 
     * @param int $status_code
     * 
     * @return string
     */
    public function get_status_from_code($status_code)
    {
        // Search status name
        foreach ($this->status_codes as $status_name => $codes)
            if (in_array($status_code, $codes))
                break;

        // Get config name
        $config_name = 'order_status_' . str_replace('-', '_', $status_name);

        // Try to get from config override or return directly
        return isset($this->config->$config_name) ? $this->config->$config_name : "wc-$status_name";
    }

    /**
     * Get approved child transactions from the order loaded (multicard only).
     * 
     * @return array[] Associative array with payment_id as key.
     */
    public function get_approved_children($transaction)
    {
        $methods = [];

        foreach ($transaction->childs as $child) {
            // Filter cash methods and failed status
            if (!$child['source_number'] || !in_array($child['status_code'], $this->status_codes['approved']))
                continue;

            $methods[$child['payment_id']] = $child;
        }

        return $methods;
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
}