<?php

namespace Mobbex\WP\Checkout\Observer;

class Order
{
    /** @var \Mobbex\WP\Checkout\Models\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Models\Helper */
    public $helper;

    public function __construct()
    {
        $this->config = new \Mobbex\WP\Checkout\Models\Config();
        $this->helper = new \Mobbex\WP\Checkout\Models\Helper();

        // Register 'Authorized' Order status for 2-step payment mode
        $this->register_authorized_order_status();
    }

    /**
     * Create and register 'Authorized' order status.
     */
    public function register_authorized_order_status()
    {
        $order_status = [
            'label'                     => __('Authorized', 'mobbex-for-woocommerce'),
            'public'                    => true,
            'show_in_admin_status_list' => true,
            'show_in_admin_all_list'    => true,
            'exclude_from_search'       => false,
            'label_count'               => _n_noop('Authorized <span class="count">(%s)</span>', 'Authorized <span class="count">(%s)</span>', 'mobbex-for-woocommerce'),
        ];

        register_post_status('wc-authorized', $order_status);
    }

    /**
     * Add 'Authorized' order status to order status select.
     *
     * @param array $order_statuses
     * @return array $order_statuses
     */
    public function add_authorized_order_status($order_statuses)
    {
        return array_merge($order_statuses, ['wc-authorized' => __('Authorized', 'mobbex-for-woocommerce')]);
    }

    /**
     * Mark Mobbex order statuses as valid for payment complete.
     *
     * @param array $order_statuses
     * @return array $order_statuses
     */
    public function valid_statuses_for_payment_complete($order_statuses)
    {
        return array_merge($order_statuses, ['authorized']);
    }

    /**
     * Add capture action to order actions select.
     * For use with 'Authorized' Orders.
     *
     * @param array $actions
     * @return array $actions
     */
    public function add_capture_action($actions)
    {
        global $theorder;

        // Only add actions if order has 'Authorized' status
        if ($theorder->get_payment_method() == 'mobbex' && $theorder->has_status('authorized'))
            $actions['mbbx_capture_payment'] = __('Capture payment', 'mobbex-for-woocommerce');

        return $actions;
    }

    /**
     * Caputure 'Authorized' orders endpoint.
     * 
     * Endpoint called by order action.
     * 
     * @param WC_Order $order
     */
    public function capture_payment_endpoint($order)
    {
        try {
            // Get "new total" value from post data
            $post_data     = wp_unslash($_POST);
            $capture_total = !empty($post_data['mbbx_capture_total']) ? $post_data['mbbx_capture_total'] : $order->get_total();

            // If data look fine
            if (is_numeric($capture_total)) {
                $order_id   = $order->get_id();

                $transaction = new \Mobbex\WP\Checkout\Models\Transaction($order_id);
                $result = $transaction->capture_payment($capture_total);

                if ($result) {
                    update_post_meta($order_id, 'mbbx_total_captured', $capture_total);
                    $order->add_order_note(__('Payment Total Captured: $ ', 'mobbex-for-woocommerce') . $capture_total);
                }
            }
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $order->add_order_note(__('Payment Capture ERROR: ', 'mobbex-for-woocommerce') . $msg);
        }
    }

    /**
     * Notify the customer that the order was authorized.
     * 
     * @param mixed $order_id
     */
    public function authorize_notification($order_id)
    {
        $mailer = WC()->mailer()->get_emails();

        // Send new order mail
        $mailer['WC_Email_New_Order']->trigger($order_id);

        // If configured, also send processing mail
        if ($this->config->two_step_processing_mail == 'authorize')
            $mailer['WC_Email_Customer_Processing_Order']->trigger($order_id);
    }

    /**
     * Notify the customer that the order was captured.
     * 
     * @param mixed $order_id
     */
    public function capture_notification($order_id)
    {
        $mailer = WC()->mailer()->get_emails();

        // By default send processing mail on capture
        if ($this->config->two_step_processing_mail != 'authorize')
            $mailer['WC_Email_Customer_Processing_Order']->trigger($order_id);
    }

    /**
     * Display payment information panel
     */
    public function add_payment_info_panel()
    {
        global $post;

        //Only displayed if a payment was made with Mobbex.
        if ($post->post_type == 'shop_order' && wc_get_order($post->ID)->get_payment_method() == 'mobbex')
            add_meta_box('mbbx_order_panel', __('Mobbex Payment Information', 'mobbex-for-woocommerce'), [$this, 'show_payment_info_panel'], 'shop_order', 'side', 'core');
    }

    /**
     * Show payment information panel
     */
    public function show_payment_info_panel()
    {
        global $post;

        // Get transaction data
        $transaction = new \Mobbex\WP\Checkout\Models\Transaction($post->ID);

        echo "<table><th colspan='2' class = 'mbbx-info-panel-th'><h4><b>" . __('Payment Information') . "</b></h4></th>";

        $paymentInfo = [
            'Transaction ID' => 'payment_id',
            'Risk Analysis'  => 'risk_analysis',
            'Currency'       => 'currency',
            'Total'          => 'total',
            'Status'         => 'status_message'
        ];

        //Create payment info panel 
        echo self::create_panel($paymentInfo, $transaction->format_webhook($transaction->data));

        echo "<th colspan='2' class = 'mbbx-info-panel-th'><h4><b>" . __('Payment Method') . "</b></h4></th>";

        //Create sources info panel
        self::create_sources_panel($transaction->format_webhook($transaction->data), $transaction->childs);

        echo "<th colspan='2' class = 'mbbx-info-panel-th'><h4><b>" . __('Entities') . "</b></h4></th>";

        //Create entities info panel
        self::create_entities_panel($transaction->format_webhook($transaction->data), $transaction->childs);

        //Create Coupon
        self::create_coupon($transaction);
?>
        <style>
            .mbbx-color-column {
                background-color: #f8f8f8;
            }

            .mbbx-info-panel-th {
                text-align: left;
            }
        </style>
<?php
        echo "</table>";
    }

    /**
     * Create payment source panel section.
     * 
     * @param array $parent
     * @param array $childs
     */
    public static function create_sources_panel($parent, $childs)
    {
        if (isset($parent["operation_type"]) && $parent["operation_type"] === "payment.multiple-sources") {
            $multipleCardArray = [
                'Card'        => 'source_name',
                'Number'      => 'source_number',
                'Installment' => 'installment_count',
                'Amount'      => 'installment_amount'
            ];
            foreach ($childs as $card) :
                self::create_panel($multipleCardArray, $card);
                echo "<tr class='mobbex-color-column'><td></td><td></td></tr>";
            endforeach;
        } else {
            $simpleCardArray = [
                'Payment Method' => 'source_type',
                'Payment Source' => 'source_name',
                'Source Number'  => 'source_number'
            ];
            self::create_panel($simpleCardArray, $parent);
            if (!empty($parent['source_installment']))
                echo "<tr class='mobbex-color-column'><td>" . __('Source Installment:') . "</td><td>" . $parent['installment_count'] . ' cuota/s de $' . $parent['installment_amount'] . "</td></tr>";
        }
    }

    /**
     * Create entities panel section.
     * 
     * @param array $parent
     * @param array $childs
     */
    public static function create_entities_panel($parent, $childs)
    {
        $vendorArray = [
            'Name' => 'entity_name',
            'UID'  => 'entity_uid'
        ];
        if (isset($parent["operation_type"]) && $parent["operation_type"] === "payment.multiple-vendor")
            if ($childs) :
                foreach ($childs as $entity)
                    self::create_panel($vendorArray, $entity);
            endif;
        else
            self::create_panel($vendorArray, $parent);
    }

    /**
     * Create Coupon section
     * 
     * @param \Mobbex\WP\Checkout\Models\Transaction $transaction
     */
     public static function create_coupon($transaction)
     {
        $mbbxCouponUrl = "https://mobbex.com/console/" . $transaction->entity_uid . "/operations/?oid=" . $transaction->payment_id;
        echo "<tr><td>" . __('Coupon:') . "</td><td>" . (isset($transaction->entity_uid) && isset($transaction->payment_id) ? "<a href=" . $mbbxCouponUrl . ">VER</a>" : 'NO COUPON') . "</td></tr>";
     }

    /**
     * Create panel
     * 
     * @param array $labelsArray
     * @param array $transaction
     */
    public static function create_panel($labelsArray, $transaction)
    {
        $i = 1;
        foreach ($labelsArray as $label => $value) :
            echo "<tr class=" . ($i % 2 == 0 ? 'mbbx-color-column' : '') . "><td>" . __($label . ':') . "</td><td>" . (isset($transaction[$value]) ? $transaction[$value] : '') . "</td></tr>";
            $i++;
        endforeach;
    }
}
