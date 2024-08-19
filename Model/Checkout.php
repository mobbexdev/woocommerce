<?php

namespace Mobbex\WP\Checkout\Model;

class Checkout
{
    public $total = 0;

    public $reference = '';

    public $relation = 0;

    public $webhooksType = 'all';

    public $customer = [];

    public $addresses = [];

    public $items = [];

    public $installments = [];

    public $endpoints = [];

    public $signup_fee = 0;

    public $discount = 0;

    /** @var \Mobbex\WP\Checkout\Model\Config */
    public $config;

    /** Name of hook to execute when body is filtered */
    public $filter = '';

    /**
     * Constructor.
     * @param string $filter Name of hook to execute when body is filtered.
     */
    public function __construct($filter = 'mobbex_checkout_custom_data')
    {
        $this->config = new \Mobbex\WP\Checkout\Model\Config;
        $this->filter = $filter;
    }

    /**
     * Create the checkout.
     * 
     * @return array Checkout response
     */
    public function create()
    {
        $checkout = new \Mobbex\Modules\Checkout(
            $this->relation,
            $this->total,
            $this->endpoints['return'],
            $this->endpoints['webhook'],
            $this->items,
            $this->installments,
            $this->customer,
            $this->addresses,
            $this->webhooksType,
            $this->filter
        );

        return $checkout->response;
    }

    /**
     * Set total to pay.
     * 
     * @param int|string $total
     */
    public function set_total($total)
    {
        $this->total = $total;
    }

    /**
     * Set the reference.
     * 
     * @param string|int $id Unique ID of the instance that will be related to the checkout.
     */
    public function set_reference($id)
    {
        // First, set the relation instance id
        $this->relation = $id;

        $reference = [
            'wc_id:' . $id,
        ];
        // Add site id
        if (!empty($this->config->site_id))
            $reference[] = 'site_id:' . str_replace(' ', '-', trim($this->config->site_id));

        // Add reseller id
        if (!empty($this->config->reseller_id))
            $reference[] = 'reseller:' . str_replace(' ', '-', trim($this->config->reseller_id));

        $this->reference = implode('_', $reference);
    }

    /**
     * Set customer data.
     * 
     * @param string $name
     * @param string $email
     * @param string $identification
     * @param string|null $phone
     * @param string|int|null $uid
     */
    public function set_customer($name, $email, $identification = '12123123', $phone = null, $uid = null)
    {
        $this->customer = compact('name', 'email', 'identification', 'phone', 'uid');
    }

    /**
     * Set address data.
     * 
     * @param Class $object Order or Customer class.
     * 
     */
    public function set_addresses($object)
    {
        foreach (['billing', 'shipping'] as $type) {
            
            foreach (['address_1', 'address_2', 'city', 'state', 'postcode', 'country'] as $method)
                ${$method} = "get_".$type."_".$method;

            // Force address 1 type to string and trim spaces
            $street = trim((string) $object->$address_1());

            $this->addresses[] = [
                'type'         => $type,
                'country'      => \Mobbex\Repository::convertCountryCode($object->$country()),
                'state'        => $object->$state(),
                'city'         => $object->$city(),
                'zipCode'      => $object->$postcode(),
                'street'       => trim(preg_replace('/(\D{0})+(\d*)+$/', '', $street)),
                'streetNumber' => str_replace(preg_replace('/(\D{0})+(\d*)+$/', '', $street), '', $street),
                'streetNotes'  => $object->$address_2()
            ];

        }
    }

    /**
     * Set notification endpoints.
     * 
     * @param mixed $return Post-payment redirect URL
     * @param mixed $webhook URL that recieve the Mobbex payment response
     */
    public function set_endpoints($return, $webhook)
    {
        $this->endpoints = compact('return', 'webhook');
    }

    /**
     * Add an item.
     * 
     * @param int|string $total
     * @param int $quantity
     * @param string|null $description
     * @param string|null $image
     * @param string|null $entity
     * @param string|null $subscription
     * @param int $fee
     */
    public function add_item($total, $quantity = 1, $description = null, $image = null, $entity = null, $subscription = null, $fee = 0)
    {
        if($subscription) {
            $this->items[] = [
                'type'       => 'subscription',
                'reference'  => $subscription,
                'total'      => $total + $fee,
            ];
        } else {
            $this->items[] = compact('total', 'quantity', 'description', 'image', 'entity');
        }
    }

    /**
     * Add an installments to show in checkout.
     * @param array $products List of product id's
     * @param array $common_plans List of product common plans
     * @param array $advanced_plans List of product advanced plans
     */
    public function add_installments($products, $common_plans, $advanced_plans)
    {
        $this->installments = \Mobbex\Repository::getInstallments($products, $common_plans, $advanced_plans);
    }

    /**
     * Add sign up fee
     * 
     * @param string|int $signup_fee
     * 
     * @return string|int $signup_fee
     */
    public function set_signup_fee($signup_fee)
    {
        $this->signup_fee = $signup_fee;
    }

    /**
     * Add discount amount
     * 
     * @param string|int $discount
     * 
     * @return string|int $discount
     */
    public function set_discount($discount)
    {
        $this->discount += $discount;
    }
}