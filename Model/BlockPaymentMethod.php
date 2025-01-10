<?php

namespace Mobbex\WP\Checkout\Model;

/**
 * Mobbex Payments Blocks integration
 *
 * @since 3.15
 */
final class BlockPaymentMethod extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
{

    /**
     * Mobbex gateway instance.
     * @var \WC_Gateway_Mobbex
     */
    private $gateway;

    /**
     * Payment method name.
     * @var string
     */
    protected $name = 'mobbex';

    /** 
     * Config model instance.
     * @var \Mobbex\WP\Checkout\Model\Config 
     */
    public $config;

    /**
     * Initializes the payment method type.
     */
    public function initialize()
    {
        $this->config   = new Config();
        $this->settings = $this->config->settings;
        $gateways       = \WC()->payment_gateways->payment_gateways();
        $this->gateway  = $gateways['mobbex'];
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active()
    {
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'wc-mobbex-payments-blocks',
            plugin_dir_url(__FILE__) . '../assets/components/CheckoutBlock.js',
            ['react'],
            MOBBEX_VERSION,
            true
        );

        return ['wc-mobbex-payments-blocks'];
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data()
    {
        return [
            'title'                => $this->config->title,
            'description'          => $this->config->description,
            'supports'             => array_filter($this->gateway->supports, [$this->gateway, 'supports']),
            'checkout_banner'      => $this->config->checkout_banner,
            'payment_method_image' => $this->config->payment_method_image,
        ];
    }
}
