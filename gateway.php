<?php

class WC_Gateway_Mobbex extends WC_Payment_Gateway
{
    public $supports = array(
        'products',
        'refunds',
    );
    public $id;

    /** @var \Mobbex\WP\Checkout\Model\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Model\Helper */
    public $helper;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public $logger;

    /* Available cards */
    public $cards;

    /* Available methods*/
    public $methods;

    public function __construct()
    {
        $this->id     = MOBBEX_WC_GATEWAY_ID;
        $this->config = new \Mobbex\WP\Checkout\Model\Config();
        $this->helper = new \Mobbex\WP\Checkout\Model\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Model\Logger();

        if ($this->config->integration == 'wcs')
            // Add wcs supports
            $this->supports = apply_filters('mobbex_subs_support', $this->supports);

        // String variables. That's used on checkout view
        $this->icon        = apply_filters('mobbex_icon', plugin_dir_url(__FILE__) . 'icon.png');
        $this->title       = $this->config->title;
        $this->description = $this->config->description;

        $this->method_title       = 'Mobbex';
        $this->method_description = __('Mobbex Payment Gateway redirects customers to Mobbex to enter their payment information.', 'mobbex-for-woocommerce');

        // Generate admin fields
        $this->init_form_fields();
        $this->init_settings();


        // Always Required
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        // Add wcs scheduled subscription payment support
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id , [$this, 'execute_scheduled_subscription_payment'], 10, 2);
    }

    /**
     * Define form fields of setting page.
     * 
     */
    public function init_form_fields()
    {
        $form_fields = include 'utils/config-options.php';
        $this->form_fields = $this->config->enable_subscription == 'yes'
            ? array_merge($form_fields, include( MOBBEX_SUBS_DIR . '/utils/config-options.php'))
            : $form_fields;
    }

    public function process_admin_options()
    {
        $saved = parent::process_admin_options();

        // Both fields cannot be filled at the same time
        if ($this->get_option('own_dni') === 'yes' && $this->config->custom_dni != '')
            $this->update_option('custom_dni');

        return $saved;
    }

    /**
     * Process the payment & return the checkout data to mobbex-bootstrap.js 
     * 
     * @param string $order_id
     * @return array
     * 
     */
    public function process_payment($order_id)
    {
        $this->logger->log('debug', 'gateway > process_payment | Creating payment', compact('order_id'));

        if (!$this->helper->isReady())
            return ['result' => 'error'];

        $order = wc_get_order($order_id);

        // Create checkout from order
        $order_helper  = new \Mobbex\WP\Checkout\Helper\Order($order);
        $checkout_data = $order_helper->create_checkout();

        $this->logger->log('debug', 'gateway > process_payment | Checkout response', $checkout_data);

        if (!$checkout_data)
            return ['result' => 'error'];

        $order->update_status('pending', __('Awaiting Mobbex Webhook', 'mobbex-for-woocommerce'));

        $result = [
            'result'      => 'success',
            'data'        => $checkout_data,
            'checkout_id' => $checkout_data['id'],
            'return_url'  => $this->helper->get_api_endpoint('mobbex_return_url', $order_id),
            'redirect'    => $this->config->button == 'yes' ? false : $checkout_data['url'],
        ];

        // Make sure to use json in pay for order page
        if (isset($_GET['pay_for_order']))
            wp_send_json($result) && exit;

        return $result;
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        try {
            // Get parent and child transactions
            $helper   = new \Mobbex\WP\Checkout\Helper\Order($order_id);
            $parent   = $helper->get_parent_transaction();
            $children = $helper->get_approved_children();

            // Try to get child transaction from reason field
            $child = isset($children[$reason]) ? $children[$reason] : (sizeof($children) == 1 ? reset($children) : null);

            if (!$parent)
                throw new \Mobbex\Exception('No se encontró información de la transacción padre.', 596);

            // If use multicard and is not a total refund
            if ($helper->has_childs($parent) && !$child && (float) $helper->order->get_remaining_refund_amount())
                throw new \Mobbex\Exception('Para realizar una devolución parcial en este pedido, especifique el id de la transacción en el campo "Razón".', 596);

            // Make request
            return $this->helper->api->request([
                'method' => 'POST',
                'uri'    => 'operations/' . ($child ?: $parent)['payment_id'] . '/refund',
                'body'   => [
                    'total'     => (float) $helper->order->get_remaining_refund_amount() ? $amount : null,
                    'emitEvent' => false,
                ]
            ]);
        } catch (\Exception $e) {
            return new \WP_Error($e->getCode(), $e->getMessage(), isset($e->data) ? $e->data : '');
        }
    }

    /**
     * Execute scheduled subscription payment via Mobbex Subscriptions
     * 
     * @param float $renewal_total
     * @param WC_Order $renewal_order
     * 
     */
    public function execute_scheduled_subscription_payment($renewal_total, $renewal_order)
    {
        $this->logger->log('debug', 'gateway > execute_scheduled_subscription_payment | Creating payment', compact('renewal_total', 'renewal_order'));
        return apply_filters('mobbex_subs_scheduled_payment', $renewal_total, $renewal_order);
    }
}
