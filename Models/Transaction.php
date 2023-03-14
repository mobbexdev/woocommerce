<?php

namespace Mobbex\WP\Checkout\Models;

class Transaction
{
    /** @var wpdb */
    public $db;

    /** @var Config */
    public $config;

    public $parent = '';
    public $operation_type = '';
    public $payment_id = '';
    public $description = '';
    public $status_code = '';
    public $status_message = '';
    public $source_name = '';
    public $source_type = '';
    public $source_reference = '';
    public $source_number = '';
    public $source_expiration = '';
    public $source_installment = '';
    public $installment_name = '';
    public $installment_count = '';
    public $source_url = '';
    public $cardholder = '';
    public $entity_name = '';
    public $entity_uid = '';
    public $customer = '';
    public $checkout_uid = '';
    public $total = '';
    public $currency = '';
    public $risk_analysis = '';
    public $childs = [];
    public $data = [];

    public function __construct($order_id)
    {
        $this->db       = $GLOBALS['wpdb'];
        $this->order_id = $order_id;
        $this->config   = new Config();
        $this->logger   = new Logger();
        
        $this->load();
    }

    /**
     * Load the transaction in the model.
     */
    private function load()
    {
        $result = $this->get_data(['order_id' => $this->order_id]);

        if(!$result)
            return $this->logger->log('error', 'webhook > load | Failed to obtain the transaction: '. $this->db->last_error, ['order_id' => $this->order_id]);
            
        foreach ($result as $key => $value)
            $this->$key = $key === 'data' ? json_decode($value, true) : $value;
    }

    /**
     * Save the transaction data formated in db.
     * @param array $webhook
     */
    public function save($webhook)
    {
        if(!empty($this->payment_id))
            return;

        $data = $this->format_webhook($webhook);

        //insert data in db
        $this->db->insert($this->db->prefix . 'mobbex_transaction', $data, self::db_column_format($data));

        //Log errors
        if(!empty($this->db->last_error))
            return $this->logger->log('error', 'webhook > save | '. $this->db->last_error, $data);

        $this->load();
    }

    /**
     * Format the webhook data in an array.
     * 
     * @param array $webhook
     * @return array $data
     * 
     */
    public function format_webhook($webhook)
    {
        $data = [
            'order_id'           => $this->order_id,
            'parent'             => isset($webhook['payment']['id']) ? (self::is_parent_webhook($webhook['payment']['id']) ? 'yes' : 'no') : null,
            'operation_type'     => isset($webhook['payment']['operation']['type']) ? $webhook['payment']['operation']['type'] : '',
            'payment_id'         => isset($webhook['payment']['id']) ? $webhook['payment']['id'] : '',
            'description'        => isset($webhook['payment']['description']) ? $webhook['payment']['description'] : '',
            'status_code'        => isset($webhook['payment']['status']['code']) ? $webhook['payment']['status']['code'] : '',
            'status_message'     => isset($webhook['payment']['status']['message']) ? $webhook['payment']['status']['message'] : '',
            'source_name'        => isset($webhook['payment']['source']['name']) ? $webhook['payment']['source']['name'] : 'Mobbex',
            'source_type'        => isset($webhook['payment']['source']['type']) ? $webhook['payment']['source']['type'] : 'Mobbex',
            'source_reference'   => isset($webhook['payment']['source']['reference']) ? $webhook['payment']['source']['reference'] : '',
            'source_number'      => isset($webhook['payment']['source']['number']) ? $webhook['payment']['source']['number'] : '',
            'source_expiration'  => isset($webhook['payment']['source']['expiration']) ? json_encode($webhook['payment']['source']['expiration']) : '',
            'source_installment' => isset($webhook['payment']['source']['installment']) ? json_encode($webhook['payment']['source']['installment']) : '',
            'installment_name'   => isset($webhook['payment']['source']['installment']['description']) ? json_encode($webhook['payment']['source']['installment']['description']) : '',
            'installment_amount' => isset($webhook['payment']['source']['installment']['amount']) ? $webhook['payment']['source']['installment']['amount'] : '',
            'installment_count'  => isset($webhook['payment']['source']['installment']['count']) ? $webhook['payment']['source']['installment']['count']  : '',
            'source_url'         => isset($webhook['payment']['source']['url']) ? json_encode($webhook['payment']['source']['url']) : '',
            'cardholder'         => isset($webhook['payment']['source']['cardholder']) ? json_encode(($webhook['payment']['source']['cardholder'])) : '',
            'entity_name'        => isset($webhook['entity']['name']) ? $webhook['entity']['name'] : '',
            'entity_uid'         => isset($webhook['entity']['uid']) ? $webhook['entity']['uid'] : '',
            'customer'           => isset($webhook['customer']) ? json_encode($webhook['customer']) : '',
            'checkout_uid'       => isset($webhook['checkout']['uid']) ? $webhook['checkout']['uid'] : '',
            'total'              => isset($webhook['payment']['total']) ? $webhook['payment']['total'] : '',
            'currency'           => isset($webhook['checkout']['currency']) ? $webhook['checkout']['currency'] : '',
            'risk_analysis'      => isset($webhook['payment']['riskAnalysis']['level']) ? $webhook['payment']['riskAnalysis']['level'] : '',
            'childs'             => isset($webhook['childs']) ? json_encode($webhook['childs']) : '',
            'data'               => json_encode($webhook),
            'created'            => isset($webhook['payment']['created']) ? $webhook['payment']['created'] : '',
            'updated'            => isset($webhook['payment']['updated']) ? $webhook['payment']['created'] : '',
        ];

        return $data;
    }

    /**
     * Formats the childs data
     * @return array $childs
     */
    public function format_childs()
    {
        $childs = [];

        foreach ($this->childs as $child)
            $childs[] = $this->helper->format_webhook_data($child);

        return $childs;
    }

    /**
     * Retrieve all child transactions for the order loaded.
     * 
     * @return array[] A list of asociative arrays with transaction values.
     */
    public function get_childs()
    {
        //return childs from parent webhook
        if (!empty($this->childs))
            return $this->format_childs();

        //return childs from db column
        $result = $this->get_data(['order_id' => $this->order_id, 'parent' => 'no'], 50);

        return $result ?: [];
    }

    /**
     * Get data from mobbex transaction table.
     * @param array $conditions
     * @param int $limit
     * @return array|null An asociative array with transaction values.
     */
    public static function get_data($conditions, $limit = 1)
    {
        global $wpdb;

        // Generate query params
        $query = [
            'operation' => 'SELECT *',
            'table'     => $wpdb->prefix . 'mobbex_transaction',
            'condition' => self::get_condition($conditions),
            'order'     => 'ORDER BY `id` DESC',
            'limit'     => "LIMIT $limit",
        ];
        // Make request to db
        $result = $wpdb->get_results(
            "$query[operation] FROM $query[table] $query[condition] $query[order] $query[limit];",
            ARRAY_A
        );

        if ($limit <= 1)
            return isset($result[0]) ? $result[0] : null;

        return !empty($result) ? $result : null;
    }

    /**
     * Check if webhook is parent type using him payment id.
     * 
     * @param string $paymentId
     * 
     * @return bool
     */
    public static function is_parent_webhook($payment_id)
    {
        return strpos($payment_id, 'CHD-') !== 0;
    }

    /**
     * Creates sql 'WHERE' statement with an associative array.
     * @param array $conditions
     * @return string $condition
     */
    public static function get_condition($conditions)
    {
        $i = 0;
        $condition = '';

        foreach ($conditions as $key => $value) {
            if($i < 1)
                $condition .= "WHERE `$key`='{$value}'";
            else
                $condition .= " AND `$key`='$value'";
            $i++;
        }

       return $condition;
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
     * Capture 'authorized' payment using Mobbex API.
     * 
     * @param string|int $payment_id
     * @param string|int $total
     * 
     * @return bool $result
     */
    public function capture_payment($total)
    {
        if (!$this->config->isReady())
            throw new \Exception(__('Plugin is not ready', 'mobbex-for-woocommerce'));

        if (empty($this->payment_id) || empty($total))
            throw new \Exception(__('Empty Payment UID or params', 'mobbex-for-woocommerce'));

        // Capture payment
        return \Mobbex\Api::request([
            'method' => 'POST',
            'uri'    => "operations/$this->payment_id/capture",
            'body'   => compact('total'),
        ]);
    }
}