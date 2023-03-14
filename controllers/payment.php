<?php

namespace Mobbex\WP\Checkout\Controllers;

final class Payment
{
    /** @var \Mobbex\WP\Checkout\Models\Config */
    public $config;
    
    /** @var \Mobbex\WP\Checkout\Models\Logger */
    public $logger;

    /** @var \Mobbex\WP\Checkout\Models\Helper */
    public $helper;

    public function __construct()
    {
        $this->config = new \Mobbex\WP\Checkout\Models\Config();
        $this->helper = new \Mobbex\WP\Checkout\Models\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Models\Logger();

        if ($this->config->isReady())
            add_action('woocommerce_api_mobbex_return_url', [$this, 'mobbex_return_url']);

        //Add Mobbex Webhook hook 
        add_action('rest_api_init', function () {
            register_rest_route('mobbex/v1', '/webhook', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'mobbex_webhook'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * Redirect to Mobbex checkout or cart page in error case.
     * 
     */
    public function mobbex_return_url()
    {
        $status = $_GET['status'];
        $id     = $_GET['mobbex_order_id'];
        $token  = $_GET['mobbex_token'];
        $error  = false;

        if (empty($status) || empty($id) || empty($token))
            $error = "No se pudo validar la transacción. Contacte con el administrador de su sitio";

        if (!$this->helper->valid_mobbex_token($token))
            $error = "Token de seguridad inválido.";

        if ($error)
            return $this->_redirect_to_error_endpoint($error);

        $order = wc_get_order($id);

        if ($status > 1 && $status < 400) {
            // Redirect
            $redirect = $order->get_checkout_order_received_url();
        } else {
            return $this->_redirect_to_error_endpoint('Transacción fallida. Reintente con otro método de pago.');
        }

        WC()->session->set('order_id', null);
        WC()->session->set('order_awaiting_payment', null);

        wp_safe_redirect($redirect);
    }

    /**
     * Redirects to the error route or cart
     * 
     * @param string $error_msg
     * @return array
     */
    private function _redirect_to_error_endpoint($error_msg)
    {
        if ($this->helper->settings['error_redirection'])
            $error_msg= 'Transacción Fallida. Redirigido a ruta configurada.';

        $route = $this->helper->settings['error_redirection'] ? home_url('/' . $this->helper->settings['error_redirection']) : wc_get_cart_url();

        wc_add_notice($error_msg, 'error');
        wp_redirect($route);
        
        return array('result' => 'error', 'redirect' => $route);
    }  

    /**
     * Process the Mobbex Webhook.
     * 
     * @param object $request
     * @return array
     */
    public function mobbex_webhook($request)
    {
        try {
            $this->logger->log("REST API > Request", $request->get_params());
            
            $requestData = isset($_SERVER['CONTENT_TYPE']) && $_SERVER['CONTENT_TYPE'] == 'application/json' ? apply_filters('mobbex_order_webhook',  json_decode(file_get_contents('php://input'), true)) : apply_filters('mobbex_order_webhook', $request->get_params());
            $postData    = !empty($requestData['data']) ? $requestData['data'] : [];
            $id          = $request->get_param('mobbex_order_id');
            $token       = $request->get_param('mobbex_token');

            $this->logger->log('Mobbex Webhook: Formating transaction', compact('id', 'token', 'postData'));

            //Load Webhook
            $webhook = new \Mobbex\WP\Checkout\Models\Transaction($id);
            //save data
            $webhook->save($postData);

            // Try to process webhook
            $result = $this->process_webhook($id, $token, $webhook);

            return [
                'result'   => $result,
                'platform' => [
                    'name'      => 'woocommerce',
                    'version'   => MOBBEX_VERSION,
                    'ecommerce' => [
                        'wordpress'   => get_bloginfo('version'),
                        'woocommerce' => WC_VERSION
                        ]
                    ],
                ];
            } catch (\Mobbex\Exception $e) {
                $this->logger->log("REST API > Error", $e->getMessage());
                
                return [
                    "result" => false,
                ];
            }
        }

        /**
         * Process & store the data of the Mobbex webhook.
         * 
     * @param int $order_id
     * @param string $token
     * @param array $data
     * 
     * @return bool 
     */
    public function process_webhook($order_id, $token, $webhook)
    {
        $order  = wc_get_order($order_id);

        $this->logger->log('Mobbex Webhook: Processing data');

        if (empty($webhook->status_code) || !$order_id || !$token || !$this->helper->valid_mobbex_token($token))
            return $this->logger->log('Mobbex Webhook: Invalid mobbex token or empty data');

        // Catch refunds webhooks
        if ($webhook->status_code == 602 || $webhook->status_code == 605)
            return !is_wp_error($this->refund_order($webhook));

        // Bypass any child webhook (except refunds)
        if ($webhook->parent != 'yes')
            return (bool) $this->add_child_note($order, $webhook);

        $order->update_meta_data('mobbex_webhook', $webhook->data);
        $order->update_meta_data('mobbex_payment_id', $webhook->payment_id);

        $source = $webhook->data['payment']['source'];
        $payment_method = $source['name'];

        // TODO: Check the Status and Make a better note here based on the last registered status
        $main_mobbex_note = 'ID de Operación Mobbex: ' . $webhook->payment_id . '. ';

        if (!empty($webhook->entity_uid)) {
            $mobbex_order_url = str_replace(['{entity.uid}', '{payment.id}'], [$webhook->entity_uid, $webhook->payment_id], MOBBEX_COUPON);

            $order->update_meta_data('mobbex_coupon_url', $mobbex_order_url);
            $order->add_order_note('URL al Cupón: ' . $mobbex_order_url);
        }

        if (!empty($source['type']) && $source['type'] == 'card') {
            $mobbex_card_payment_info = $payment_method . ' ( ' . $source['number'] . ' )';
            $mobbex_card_plan = $source['installment']['description'] . '. ' . $source['installment']['count'] . ' Cuota/s' . ' de ' . $source['installment']['amount'];

            $order->update_meta_data('mobbex_card_info', $mobbex_card_payment_info);
            $order->update_meta_data('mobbex_plan', $mobbex_card_plan);

            $main_mobbex_note .= 'Pago realizado con ' . $mobbex_card_payment_info . '. ' . $mobbex_card_plan . '. ';
        } else {
            $main_mobbex_note .= 'Pago realizado con ' . $payment_method . '. ';
        }

        $order->add_order_note($main_mobbex_note);

        if ($webhook->risk_analysis > 0) {
            $order->add_order_note('El riesgo de la operación fue evaluado en: ' . $webhook->risk_analisys);
            $order->update_meta_data('mobbex_risk_analysis', $webhook->risk_analisys);
        }

        if (!empty($payment_method)) {
            $order->set_payment_method_title($payment_method . ' ' . __('a través de Mobbex'));
        }

        $order->save();

        // Update totals
        $this->update_order_total($order, $webhook->total);

        // Change status and send email
        $this->update_order_status($order, $webhook);

        //action with the checkout data
        do_action('mobbex_webhook_process', $order_id, $webhook->data);

        return true;
    }

    /**
     * Update order status using webhook formatted data.
     * 
     * @param WC_Order $order
     * @param \Mobbex\WP\Checkout\Models\Transaction $webhook
     */
    public function update_order_status($order, $webhook)
    {
        $helper = new \Mobbex\WP\Checkout\Helper\OrderHelper($order);

        $order->update_status(
            $helper->get_status_from_code($webhook->status_code),
            $webhook->status_message
        );

        // Complete payment only if it's approved
        if (in_array($webhook->status_code, $helper->status_codes['approved']))
            $order->payment_complete($webhook->payment_id);
    }

    /**
     * Update order total paid using webhook formatted data.
     * 
     * @param WC_Order $order
     * @param float $total
     */
    public function update_order_total($order, $total)
    {
        if ($order->get_total() == $total)
            return;

        // First remove previus fees
        $order->remove_order_items('fee');

        // Add a fee item to order with the difference
        $item = new \WC_Order_Item_Fee;
        $item->set_props([
            'name'   => $total > $order->get_total() ? 'Cargo financiero' : 'Descuento',
            'amount' => $total - $order->get_total(),
            'total'  => $total - $order->get_total(),
        ]);
        $order->add_item($item);

        // Recalculate totals
        $order->calculate_totals();
        $order->set_total($total);
    }

    /**
     * Try to refund an order using webhook formatted data.
     * 
     * @param \Mobbex\WP\Checkout\Models\Transaction $webhook
     * 
     * @return WC_Order_Refund|WP_Error
     */
    public function refund_order($webhook)
    {
        return wc_create_refund([
            'amount'   => $webhook->total,
            'order_id' => $webhook->order_id,
        ]);
    }

    /**
     * Add a note with the child transaction data to the order given.
     * 
     * @param WC_Order $order
     * @param \Mobbex\WP\Checkout\Models\Transaction $webhook Webhook child tansaction.
     * 
     * @return int Comment id.
     */
    public function add_child_note($order, $webhook)
    {
        return $order->add_order_note(sprintf(
            'Transacción Hija Procesada: ID: %s. Estado: %s (%s). Total: $%s. Método: %s %s (%sx$%s). Tarjeta: %s.',
            $webhook->payment_id,
            $webhook->status_code,
            $webhook->status_message,
            $webhook->total,
            $webhook->source_name,
            $webhook->installment_name,
            $webhook->installment_count,
            $webhook->installment_amount,
            $webhook->source_number
        ));
    }
}
