<?php

namespace Mobbex\WP\Checkout\Observer;

class Checkout
{
    /** @var \Mobbex\WP\Checkout\Models\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Models\Helper */
    public $helper;

    /** @var \Mobbex\WP\Checkout\Models\Logger */
    public $logger;

    public function __construct()
    {
        $this->config = new \Mobbex\WP\Checkout\Models\Config();
        $this->helper = new \Mobbex\WP\Checkout\Models\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Models\Logger();
    }

    /**
     * Check that the Cart does not have products from different stores.
     * 
     * @param bool $valid
     * @param int $product_id
     * 
     * @return bool $valid
     */
    public function validate_cart_items($valid, $product_id)
    {
        $cart_items = !empty(WC()->cart->get_cart()) ? WC()->cart->get_cart() : [];

        // Get store from current product
        $product_store = $this->config->get_store_from_product($product_id);

        // Get stores from cart items
        foreach ($cart_items as $item) {
            $item_store = $this->config->get_store_from_product($item['product_id']);

            // If there are different stores in the cart items
            if ($product_store != $item_store) {
                wc_add_notice(__('The cart cannot have products from different sellers at the same time.', 'mobbex-for-woocommerce'), 'error');
                return false;
            }
        }

        return $valid;
    }

    /**
     * Add Mobbex extra data fields to woocommerce checkout.
     * 
     * @param array $fields
     * @return array $fields
     * 
     */
    public function add_checkout_fields($fields)
    {
        if(!$this->config->isReady() && $this->config->own_dni !== 'yes')
            return $fields;

        $cutomer_id = WC()->customer ? WC()->customer->get_id() : null;

        $fields['billing_dni'] = [
            'type'        => 'text',
            'required'    => true,
            'clear'       => false,
            'label'       => 'DNI',
            'placeholder' => 'Ingrese su DNI',
            'default'     => WC()->session->get('mbbx_billing_dni') ?: get_user_meta($cutomer_id, 'billing_dni', true),
        ];

        return $fields;
    }

    /**
     * Display the Mobbex extra fields in woocommerce checkout.
     * 
     * @param object $order
     * 
     */
    public function display_checkout_fields_data($order)
    {
?>
        <p>
            <strong>DNI:</strong>
            <?= get_post_meta($order->get_id(), '_billing_dni', true) ?: get_post_meta($order->get_id(), 'billing_dni', true) ?>
        </p>
<?php
    }

    /**
     * Add DNI validation to woocommerce checkout.
     * 
     */
    public function validate_checkout_fields()
    {
        // Get dni field key
        $own_dni = $this->config->own_dni == 'yes' ? 'billing_dni' : false;
        $dni     = $this->config->custom_dni ? $this->config->custom_dni : $own_dni;

        // Exit if field is not configured
        if (!$dni)
            return;

        if (empty($_POST[$dni]))
            return wc_add_notice('Complete el campo DNI', 'error');

        WC()->session->set('mbbx_billing_dni', $_POST[$dni]);
    }

    /**
     * Save the data of DNI field in the woocommmerce checkout.
     * 
     * @param string $order_id
     * 
     */
    public function save_checkout_fields($order_id)
    {
        $customer_id = wc_get_order($order_id)->get_customer_id();

        // Get dni field key
        $own_dni = $this->config->own_dni == 'yes' ? 'billing_dni' : false;
        $dni     = $this->config->custom_dni ? $this->config->custom_dni : $own_dni;

        // Exit if field is not configured
        if (!$dni)
            return;

        // Try to save by customer to show in future purchases
        if ($customer_id)
            update_user_meta($customer_id, 'billing_dni', $_POST[$dni]);

        // Save by order
        update_post_meta($order_id, '_billing_dni', $_POST[$dni]);
    }
}
