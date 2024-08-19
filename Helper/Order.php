<?php

namespace Mobbex\WP\Checkout\Helper;

class Order
{
    /** Order instance ID */
    public $id;

    /** @var \WC_Order */
    public $order;

    /** @var \Mobbex\WP\Checkout\Model\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Model\Helper */
    public $helper;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public $logger;

    /** @var wpdb */
    public $db;

    /* product subscription */
    public $subscription;

    /* cart fee */
    public $cart_fee;

    /* shipping */
    public $shipping = 0;

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
    * @param \Mobbex\WP\Checkout\Model\Helper Base plugin helper.
    * @param \Mobbex\WP\Checkout\Model\Logger Base plugin debugger.
    */
    public function __construct($order, $helper = null)
    {
        $this->id     = is_int($order) ? $order : $order->get_id();
        $this->order  = is_int($order) ? wc_get_order($order) : $order;
        $this->config = new \Mobbex\WP\Checkout\Model\Config();
        $this->helper = $helper ?: new \Mobbex\WP\Checkout\Model\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Model\Logger();
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
        $checkout = new \Mobbex\WP\Checkout\Model\Checkout();

        $this->add_initial_data($checkout);
        $this->add_items($checkout);
        $this->add_installments($checkout);
        $this->add_customer($checkout);
        $this->maybe_add_signup_fee($checkout);

        if (isset($this->subscription))
            $this->set_gross_total($checkout);

        try {
            $response = $checkout->create();
        } catch (\Exception $e) {
            $response = null;

            $this->logger->log('error', 'class-order-helper > create_checkout | Mobbex Checkout Creation Failed: ' . $e->getMessage(), isset($e->data) ? $e->data : '');
        }

        do_action('mobbex_checkout_process', $response, $this->id);

        return $response;
    }

    /**
     * Add order initial data to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Model\Checkout $checkout
     */
    private function add_initial_data($checkout)
    {
        $checkout->set_reference($this->id);
        $checkout->set_total($this->calculate_total());
        $checkout->set_endpoints(
            $this->helper->get_api_endpoint('mobbex_return_url', $this->id),
            $this->helper->get_api_endpoint('mobbex_webhook', $this->id)
        );
    }


    /**
     * Calculate the total for the mobbex checkout.
     * 
     * @return string
     */
    private function calculate_total()
    {
        //If discounts are allowed return cart total.
        if($this->config->disable_discounts !== 'yes')
            return $this->order->get_total();

        //Get total without discounts
        $subtotal = $this->order->get_subtotal();

        //Get products discounts
        $items_discounts = $this->get_discount_total($this->order->get_items() ?: []);

        //Add taxes, shipping & fees
        $total = $subtotal + $this->order->get_total_tax() + $this->order->get_total_fees() + $this->order->get_shipping_total() + $items_discounts;

        //return formated woocommerce total without discounts
        return $total;
    }

    /**
     * Add order items to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Model\Checkout $checkout
     */
    private function add_items($checkout)
    {
        $order_items = array(
            $this->order->get_items() ?: [], 
            $this->order->get_items('fee') ?: [],
            $this->order->get_items('coupon') ?: [],
            $this->order->get_items('shipping') ?: []
        );

        if(empty($order_items))
            return;

        foreach($order_items as $items)
            $this->manage_items($items, $checkout);
    }

    /**
     * Calculate the item price for the mobbex checkout.
     * 
     * @return string
     */
    public function calculate_item_price($item)
    {
        //If discounts are allowed return item price.
        if ($this->config->disable_discounts !== 'yes')
            return $this->config->get_product_subscription_uid($item->get_product_id()) ?
                $item->get_subtotal() :
                $item->get_total();

        // Warning: Use get_product instead of get_product_id to support variations
        $product = $item->get_product();

        //Return product price if discounts are disabled
        return (float) $product->get_regular_price() * (int) $item->get_quantity();
    }

    /**
     * Return the total disocunted from items.
     * 
     * @param WC_Order_Item[] $items
     * 
     * @return float
     */
    private function get_discount_total($items) {
        return array_sum(array_map(function($item) {
            $product = $item->get_product();

            return !$product->is_on_sale() ? null : (
                (float) $product->get_regular_price() -
                (float) $product->get_sale_price() *
                (int) $item->get_quantity()
            );
        }, $items));
    }

    /**
     * Add installments configured to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Model\Checkout $checkout
     */
    private function add_installments($checkout)
    {
        $products_ids = $this->helper::get_product_ids($this->order);

        // Get plans from order products
        extract($this->config->get_products_plans($products_ids));

        //Add installments
        $checkout->add_installments($products_ids, $common_plans, $advanced_plans);
    }

    /**
     * Add order customer data to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Model\Checkout $checkout
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
            $store_id = $this->helper::get_store_from_product($item->get_product_id());

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
     * Retrieve the latest parent transaction for the order loaded.
     * 
     * @return array|null An asociative array with transaction values.
     */
    public function get_parent_transaction()
    {
        // Generate query params
        $query = [
            'operation' => 'SELECT *',
            'table'     => $this->db->prefix . 'mobbex_transaction',
            'condition' => "WHERE `order_id`='{$this->id}' AND `parent`='yes'",
            'order'     => 'ORDER BY `id` DESC',
            'limit'     => 'LIMIT 1',
        ];

        // Make request to db
        $result = $this->db->get_results(
            "$query[operation] FROM $query[table] $query[condition] $query[order] $query[limit];",
            ARRAY_A
        );

        return isset($result[0]) ? $result[0] : null;
    }

    /**
     * Retrieve all child transactions for the order loaded.
     * 
     * @return array[] A list of asociative arrays with transaction values.
     */
    public function get_child_transactions()
    {
        // Generate query params
        $query = [
            'operation' => 'SELECT *',
            'table'     => $this->db->prefix . 'mobbex_transaction',
            'condition' => "WHERE `order_id`='{$this->id}' AND `parent`='no'",
            'order'     => 'ORDER BY `id` ASC',
            'limit'     => 'LIMIT 50',
        ];

        // Make request to db
        $result = $this->db->get_results(
            "$query[operation] FROM $query[table] $query[condition] $query[order] $query[limit];",
            ARRAY_A
        );

        return $result ?: [];
    }

    /**
     * Formats the childs data
     * 
     * @param int $order_id
     * @param array $childsData
     * 
     */
    public function format_childs($order_id, $childsData)
    {
        $childs = [];
        foreach ($childsData as $child)
            $childs[] = $this->helper->format_webhook_data($order_id, $child);
        return $childs;
    }

    /**
     * Get approved child transactions from the order loaded (multicard only).
     * 
     * @return array[] Associative array with payment_id as key.
     */
    public function get_approved_children()
    {
        $methods = [];

        foreach ($this->get_child_transactions() as $child) {
            // Filter cash methods and failed status
            if (!$child['source_number'] || !in_array($child['status_code'], $this->status_codes['approved']))
                continue;

            $methods[$child['payment_id']] = $child;
        }

        return $methods;
    }

    /**
     * Check if the transaction given has multicard childs.
     * 
     * @param array $parent An asociative array with transaction values.
     * 
     * @return bool
     */
    public function has_childs($parent)
    {
        return isset($parent['operation_type']) && $parent['operation_type'] == 'payment.multiple-sources';
    }

    /**
     * Maybe add product subscriptions sign-up fee 
     * 
     * @param object $checkout used to get items and total
     * 
     * @return int|string total cleared
     */
    public function maybe_add_signup_fee($checkout)
    { 
        $signup_fee_totals = 0;
        
        foreach ($checkout->items as $item)
            if($item['type'] == 'subscription'){
                $subscription       = \Mobbex\Repository::getProductSubscription($item['reference'], true);
                $signup_fee_totals += $subscription['setupFee'];
            }

        $checkout->total = $checkout->total - $signup_fee_totals;
    }

    /**
     * Get subscription for product subscription
     * 
     * @param object $item
     * 
     * @return array $subscription
     */
    public function maybe_get_subscription_product($item)
    {
        $product_observer = new \Mobbex\WP\Checkout\Observer\Product;

        if(!$product_observer->is_subscription($item->get_product_id()))
            return null;

        return $this->config->get_product_subscription($item->get_product_id());
    }

    /**
     * Manage and configure order item data to add them to checkout
     * 
     * @param array $order_items 
     * @param object $checkout
     */
    public function manage_items($order_items = [], $checkout)
    {
        foreach($order_items as $item) {
            switch ($item->get_type()) {
                case 'line_item':
                    // Sets subscription property if an item is subscription product
                    $this->subscription = $this->maybe_get_subscription_product($item);
                    $checkout->add_item(
                        $this->calculate_item_price($item),
                        $item->get_quantity(),
                        $item->get_name(),
                        $this->helper->get_product_image($item->get_product_id()),
                        $this->config->get_product_entity($item->get_product_id()),
                        $this->config->get_product_subscription_uid($item->get_product_id()),
                        $this->config->get_product_subscription_signup_fee($item->get_product_id())
                    );
                    break;
                case 'fee':
                    $this->cart_fee = $item->get_total();
                    break;
                case 'shipping':
                    $this->shipping = $item->get_total();
                    $checkout->add_item($item->get_total(), 1, __('Shipping: ', 'mobbex-for-woocommerce') . $item->get_name());
                    break;
                case 'coupon':
                    $discount = $this->subscription ? $checkout->total - $this->subscription['total'] - $this->subscription['setupFee'] : $item->get_discount();
                    $checkout->set_discount($discount);
                    $checkout->add_item($discount, 1, __('Coupon: ', 'mobbex-for-woocommerce') . $item->get_name());
                    break;
            }
        }
    }

    /**
     * Calculates and sets the gross total of the payment order
     * Required to calculate the true total of a subscription product
     * 
     * @param object $checkout
     */
    public function set_gross_total($checkout)
    {
        $gross_total = 0;

        foreach ($checkout->items as $item)
            $gross_total += isset($item['type']) == 'subscription' ? $item['total'] : 0;

        $gross_total += $checkout->discount + $this->shipping;
            
        $checkout->total = $gross_total;
    }
}