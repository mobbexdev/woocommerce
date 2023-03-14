<?php

class WC_Gateway_Mobbex extends WC_Payment_Gateway
{
    public $supports = array(
        'products',
        'refunds',
    );

    /** @var \Mobbex\WP\Checkout\Models\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Models\Helper */
    public $helper;

    /** @var \Mobbex\WP\Checkout\Models\Logger */
    public $logger;

    public function __construct()
    {
        $this->id     = MOBBEX_WC_GATEWAY_ID;
        $this->config = new \Mobbex\WP\Checkout\Models\Config();
        $this->helper = new \Mobbex\WP\Checkout\Models\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Models\Logger();

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
    }

    /**
     * Define form fields of setting page.
     * 
     */
    public function init_form_fields()
    {
        $this->form_fields = include 'utils/config-options.php';
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
        $this->logger->log('Creating payment', compact('order_id'));

        if (!$this->config->isReady())
            return ['result' => 'error'];

        $order = wc_get_order($order_id);

        // Create checkout from order
        $order_helper  = new \Mobbex\WP\Checkout\Helper\OrderHelper($order);
        $checkout_data = $order_helper->create_checkout();

        $this->logger->log('Checkout response', $checkout_data);

        if (!$checkout_data)
            return ['result' => 'error'];

        $order->update_status('pending', __('Awaiting Mobbex Webhook', 'mobbex-for-woocommerce'));

        $result = [
            'result'     => 'success',
            'data'       => $checkout_data,
            'return_url' => $this->helper->get_api_endpoint('mobbex_return_url', $order_id),
            'redirect'   => $this->config->button == 'yes' ? false : $checkout_data['url'],
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
            $order_helper = new \Mobbex\WP\Checkout\Helper\OrderHelper($order_id);
            $transaction  = new \Mobbex\WP\Checkout\Models\Transaction($order_id);
            $children     = $order_helper->get_approved_children($transaction);

            // Try to get child transaction from reason field
            $child = isset($children[$reason]) ? $children[$reason] : (sizeof($children) == 1 ? reset($children) : null);

            if (!empty($transaction->payment_id))
                throw new \Mobbex\Exception('No se encontró información de la transacción padre.', 596);

            // If use multicard and is not a total refund
            if (!empty($transaction->childs) && !$child && (float) $order_helper->order->get_remaining_refund_amount())
                throw new \Mobbex\Exception('Para realizar una devolución parcial en este pedido, especifique el id de la transacción en el campo "Razón".', 596);

            // Make request
            return \Mobbex\Api::request([
                'method' => 'POST',
                'uri'    => 'operations/' . ($child ?: $transaction->payment_id) . '/refund',
                'body'   => [
                    'total'     => (float) $order_helper->order->get_remaining_refund_amount() ? $amount : null,
                    'emitEvent' => false,
                ]
            ]);
        } catch (\Exception $e) {
            return new \WP_Error($e->getCode(), $e->getMessage(), isset($e->data) ? $e->data : '');
        }
    }
}
