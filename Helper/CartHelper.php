<?php

namespace Mobbex\WP\Checkout\Helper;

class CartHelper
{
    /** Cart instance ID */
    public $id;

    /** @var WC_Cart */
    public $cart;

    /** @var \Mobbex\WP\Checkout\Models\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Models\Helper */
    public $helper;

    /** @var \Mobbex\WP\Checkout\Models\Logger */
    public $logger;

    /**
    * Constructor.
    * 
    * @param WC_Cart WooCommerce Cart instance.
    * @param \Mobbex\WP\Checkout\Models\Helper Base plugin helper.
    * @param \Mobbex\WP\Checkout\Models\Logger Base plugin debugger.
    */
    public function __construct($cart, $helper = null)
    {
        $this->id     = $cart->get_cart_hash();
        $this->cart   = $cart;
        $this->config = new \Mobbex\WP\Checkout\Models\Config();
        $this->helper = $helper ?: new \Mobbex\WP\Checkout\Models\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Models\Logger();
    }

    /**
     * Create a checkout using the WooCommerce Cart instance.
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
        $checkout = new \Mobbex\WP\Checkout\Models\Checkout('mobbex_cart_checkout_custom_data');

        $this->add_initial_data($checkout);
        $this->add_items($checkout);
        $this->add_installments($checkout);
        $this->add_customer($checkout);

        try {
            $response = $checkout->create();
        } catch (\Exception $e) {
            $response = null;
            $this->logger->log('Mobbex Checkout Creation Failed: ' . $e->getMessage(), isset($e->data) ? $e->data : '');
        }

        do_action('mobbex_cart_checkout_process', $response, $this->id);

        return $response;
    }

    /**
     * Add cart initial data to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_initial_data($checkout)
    {
        $checkout->webhooksType = 'none';
        $checkout->set_reference($this->id);
        $checkout->set_total($this->cart->get_total(null));
        $checkout->set_endpoints(
            $this->helper->get_api_endpoint('mobbex_return_url', $this->id),
            $this->helper->get_api_endpoint('mobbex_webhook', $this->id)
        );
    }

    /**
     * Add cart items to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_items($checkout)
    {
        $items = $this->cart->get_cart() ?: [];

        foreach ($items as $item)
            $checkout->add_item(
                $item['line_total'],
                $item['quantity'],
                $item['data']->get_name(),
                $this->config->get_product_image($item['product_id']),
                $this->config->get_product_entity($item['product_id'])
            );

        $checkout->add_item($this->cart->get_shipping_total(), 1, __('Shipping: ', 'mobbex-for-woocommerce'));
    }

    /**
     * Add installments configured to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_installments($checkout)
    {
        $product_ids = [];
        $items = $this->cart->get_cart() ?: [];
        
        //Get products id
        foreach ($items as $item)
            $product_ids[] = $item['product_id'];
        
        // Get plans from cart products
        extract($this->config->get_catalog_plans($product_ids));
        
        // Block common plans from installments
        foreach ($common_plans as $plan_ref)
            $checkout->block_installment($plan_ref);

        // Add advanced plans to installments (only if the plan is active on all products)
        foreach (array_count_values($advanced_plans) as $plan_uid => $reps) {
            if ($reps == count($items))
                $checkout->add_installment($plan_uid);
        }
    }

    /**
     * Add cart customer data to checkout.
     * 
     * @param \Mobbex\WP\Checkout\Models\Checkout $checkout
     */
    private function add_customer($checkout)
    {
        $customer = $this->cart->get_customer();

        $checkout->set_customer(
            $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name(),
            $customer->get_billing_email(),
            WC()->session->get('mbbx_billing_dni') ?: (get_user_meta($customer->get_id(), 'billing_dni', true) ?: '12123123'),
            $customer->get_billing_phone() ?: get_user_meta($customer->get_id(), 'phone_number', true),
            $customer->get_id()
        );

        $checkout->set_addresses($customer);
    }

    /**
     * Get Store from cart items multisite configuration.
     * 
     * @return array|null
     */
    public function get_store()
    {
        $stores = get_option('mbbx_stores') ?: [];
        $items  = $this->cart->get_cart() ?: [];

        // Search current store configured
        foreach ($items as $item) {
            $store_id = $this->config->get_store_from_product($item['product_id']);

            if ($store_id && isset($stores[$store_id]))
                return $stores[$store_id];
        }
    }
}