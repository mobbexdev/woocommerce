<?php 

namespace Mobbex\WP\Checkout\Model;

class Config 
{
    public $settings = [];

    // Settings from config-options.php
    public $enabled;
    public $api_key;
    public $access_token;
    public $test;
    public $button;
    public $wallet;
    public $financial_info_active;
    public $own_dni;
    public $custom_dni;
    public $orders_tab;
    public $order_status_approved;
    public $order_status_on_hold;
    public $order_status_failed;
    public $order_status_refunded;
    public $paid_statuses;
    public $appearance_tab;
    public $title;
    public $payment_method_image;
    public $description;
    public $theme;
    public $header_name;
    public $header_logo;
    public $background;
    public $color;
    public $financial_widget_on_cart;
    public $financial_widget_button_text;
    public $financial_widget_button_logo;
    public $checkout_banner;
    public $financial_widget_styles;
    public $advanced_configuration_tab;
    public $multicard;
    public $multivendor;
    public $payment_mode;
    public $two_step_processing_mail;
    public $reseller_id;
    public $error_redirection;
    public $site_id;
    public $debug_mode;
    public $unified_mode;
    public $disable_discounts;
    public $disable_template;
    public $timeout;
    public $return_timeout;
    public $process_webhook_retries;
    public $method_icon;
    public $show_no_interest_labels;

    public function __construct()
    {
        //Get settings array
        $this->settings = $this->get_settings();

        //Set property for each setting
        $this->setProperties();
    }

    /**
     * Return an array with all Mobbex settings & his values
     * @return array $settings
     */
    public function get_settings()
    {
        //Get saved values from db
        $saved_values = get_option('woocommerce_' . MOBBEX_WC_GATEWAY_ID . '_settings', null);

        //Create settings array
        foreach (include(__DIR__.'/../utils/config-options.php') as $key => $option) {
            $default = isset($option['default']) ? $option['default'] : null;
            $settings[str_replace('-', '_', $key)] = isset($saved_values[$key]) ? $saved_values[$key] : $default;
        }

        // The intent constant overwrite payment mode setting
        if (defined('MOBBEX_CHECKOUT_INTENT'))
            $settings['payment_mode'] = MOBBEX_CHECKOUT_INTENT;
        else if ($settings['payment_mode'] == 'yes')
            $settings['payment_mode'] = 'payment.2-step';
        else
            $settings['payment_mode'] = 'payment.v2';

        return $settings;
    }

    /**
     * Set a property in config class for each mobbex setting
     */
    public function setProperties()
    {
        foreach ($this->settings as $key => $value)
            $this->$key = $value;
    }

    /**
     * Returns formated Mobbex settings to be used in php sdk
     * @return array
     */
    public function formated_settings()
    {
        $formatedSettings = [];

        foreach ($this->settings as $key => $value) {

            switch ($value) {
                case 'yes':
                    $formatedSettings[$key] = true;
                    break;
                case 'no':
                    $formatedSettings[$key] = false;
                    break;
                default:
                    $formatedSettings[$key] = $value;
                    break;
            }

        }

        return $formatedSettings;
    }

    /** CATALOG SETTINGS **/

    /**
     * Retrieve the given product/category option.
     * 
     * @param int|string $id
     * @param string $field_name
     * @param string $catalog_type
     * 
     * @return array|string
     */
    public function get_catalog_settings($id, $field_name, $catalog_type = 'post')
    {
        $data = get_metadata($catalog_type, $id, $field_name, true);

        if (strpos($field_name, '_plans'))
            return $data ? $this->maybe_decode($data) : [];

        return $data ?: '';
    }

    /**
     * This method checks the format of the metadata & transforms it into an array if necessary.
     * 
     * @param string|array $metadata
     * 
     * @return array|null
     */
    private function maybe_decode($metadata)
    {
        if (is_string($metadata) && is_array(json_decode($metadata, true)))
            return json_decode($metadata, true);

        return maybe_unserialize($metadata);
    }

    /**
     * Returns the entity asigned to a product
     * @param string $product_id
     * @return string 
     * 
     */
    public function get_product_entity($product_id)
    {
        if(!$this->multivendor)
            return '';

        if ($this->get_catalog_settings($product_id, 'mbbx_entity'))
            return $this->get_catalog_settings($product_id, 'mbbx_entity');

        foreach (wc_get_product_term_ids($product_id, 'product_cat') as $term_id) {
            if ($this->get_catalog_settings($term_id, 'mbbx_entity', 'term'))
                return $this->get_catalog_settings($term_id, 'mbbx_entity', 'term');
        }

        return apply_filters('filter_mbbx_entity', $product_id);
    }

    /**
     * Return the subscription UID from a product ID.
     * 
     * @param int|string $product_id
     * 
     * @return string|null
     */
    public function get_product_subscription_uid($product_id)
    {
        if ($this->get_catalog_settings($product_id, 'mbbx_sub_enable'))
            return $this->get_catalog_settings($product_id, 'mbbx_sub_uid');
    }

    /*
     * Get product subscription sign-up fee from cache or API
     * 
     * @param int|string $id
     * 
     * @return int|string product subscription sign-up fee
     */
    public function get_product_subscription_signup_fee($id)
    { 
        try {
            // Try to get subscription data from cache; otherwise it get it from API
            $subscription = \Mobbex\Repository::getProductSubscription($this->get_product_subscription_uid($id), true);
            return isset($subscription['setupFee']) ? $subscription['setupFee'] : '';
        } catch (\Exception $e) {
            (new \Mobbex\WP\Checkout\Model\Logger)->log('error', 'Config > get_product_subscription_signup_fee | Failed obtaining setup fee: ' . $e->getMessage(), $subscription);
        }
    }

    /**
     * Get active plans for a given products.
     * @param array $ids Products ids
     * @return array $array
     */
    public function get_products_plans($ids)
    {
        $common_plans = $advanced_plans = [];

        foreach ($ids as $id) {
            $product_plans = $this->get_catalog_plans($id); 
            //Merge all catalog plans
            $common_plans   = array_merge($common_plans, $product_plans['common_plans']);
            $advanced_plans = array_merge($advanced_plans, $product_plans['advanced_plans']);
        }

        return compact('common_plans', 'advanced_plans');
    }

    /**
     * Get all the Mobbex plans from a given product or term id.
     * @param string $id Product/Term id.
     * @param string $catalog_type 
     */
    public function get_catalog_plans($id, $catalog_type = 'post', $admin = false)
    {
        //Get product plans
        $common_plans   = $this->get_catalog_settings($id, 'common_plans', $catalog_type) ?: [];
        $advanced_plans = $this->get_catalog_settings($id, 'advanced_plans', $catalog_type) ?: [];

        //Get plans from categories
        if(!$admin && $catalog_type === 'post') {
            foreach (wc_get_product_term_ids($id, 'product_cat') as $categoryId){
                $common_plans   = array_merge($common_plans, $this->get_catalog_settings($categoryId, 'common_plans', 'term'));
                $advanced_plans = array_merge($advanced_plans, $this->get_catalog_settings($categoryId, 'advanced_plans', 'term'));
            }
        }

        //Avoid duplicated plans
        $common_plans   = array_unique($common_plans);
        $advanced_plans = array_unique($advanced_plans);

       return compact('common_plans', 'advanced_plans');
    }

    /**
     * Get current store data from product or product category.
     * 
     * @param string $meta_type 'post'|'term'.
     * @param int|string $id
     */
    public function get_store_data($meta_type, $id)
    {
        // Get store saved data
        $stores        = get_option('mbbx_stores') ?: [];
        $current_store = $this->get_catalog_settings($id, 'mbbx_store', $meta_type);

        return [
            'enable_ms'   => $this->get_catalog_settings($id, 'mbbx_enable_multisite', $meta_type),
            'store_names' => array_combine(array_keys($stores), array_column($stores, 'name')) ?: [],
            'store'       => [
                'id'           => $current_store,
                'name'         => isset($stores[$current_store]['name']) ? $stores[$current_store]['name'] : '',
                'api_key'      => isset($stores[$current_store]['api_key']) ? $stores[$current_store]['api_key'] : '',
                'access_token' => isset($stores[$current_store]['access_token']) ? $stores[$current_store]['access_token'] : '',
            ],
        ];
    }

    /**
     * Save a store or create a new one as appropriate.
     * 
     * @param string $meta_type 'post'|'term'.
     * @param int|string $id
     * @param string $store ID of the store. Use "new" to create a new one.
     * @param array $new_store_data
     */
    public function save_store($meta_type, $id, $store, $new_store_data)
    {
        // Get current existing stores
        $stores = get_option('mbbx_stores') ?: [];

        // If store already exists, save selection
        if (array_key_exists($store, $stores)) {
            update_metadata($meta_type, $id, 'mbbx_store', $store);
        } else if ($store === 'new' && $new_store_data['name'] && $new_store_data['api_key'] && $new_store_data['access_token']) {
            // Create new store
            $new_store          = md5($new_store_data['api_key'] . '|' . $new_store_data['access_token']);
            $stores[$new_store] = $new_store_data;

            // Save selection and new store data
            update_option('mbbx_stores', $stores) && update_metadata($meta_type, $id, 'mbbx_store', $new_store);
        }
    }
}