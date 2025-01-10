<?php 
namespace Mobbex\WP\Checkout\Model;

class Registrar 
{
    /** @var Config */
    public $config;

    /** @var Helper */
    public $helper;

    /** @var Logger */
    public $logger;

    /** @var \Mobbex\WP\Checkout\Observer\Init */
    public $init;

    /** @var \Mobbex\WP\Checkout\Observer\Product */
    public $product;

    /** @var \Mobbex\WP\Checkout\Observer\Checkout */
    public $checkout;

    /** @var \Mobbex\WP\Checkout\Observer\Order */
    public $order;
    
    public function __construct()
    {
        //Load models
        $this->config   = new Config(); 
        $this->helper   = new Helper(); 
        $this->logger   = new Logger();
        //Load observers
        $this->init     = new \Mobbex\WP\Checkout\Observer\Init();
        $this->product  = new \Mobbex\WP\Checkout\Observer\Product();
        $this->checkout = new \Mobbex\WP\Checkout\Observer\Checkout();
        $this->order    = new \Mobbex\WP\Checkout\Observer\Order();
    }

    /**
     * Register all Mobbex for Woocommerce hooks
     */
    public function register_hooks()
    {
        $this->add_actions();
        $this->add_filters();
    }

    /**
     * Add the Woocommerce filters for Mobbex
     */
    public function add_filters()
    {
        foreach ($this->get_filters() as $filter) 
            add_filter($filter['name'], $filter['callback'], 10, isset($filter['params']) ? $filter['params'] : 1);
    }

    /**
     * Add the Woocomerce actions for Mobbex
     */
    public function add_actions()
    {
        foreach ($this->get_actions() as $action) 
            add_action($action['name'], $action['callback'], isset($action['priority']) ? $action['priority'] : 10, isset($action['params']) ? $action['params'] : 1);

        add_action('rest_api_init', function () {
            register_rest_route('mobbex/v1', '/widget/sources', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this->product, 'get_product_sources'],
                'permission_callback' => '__return_true',
            ]);
        });

        add_action('rest_api_init', function () {
            register_rest_route('mobbex/v1', '/widget/update', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this->product, 'financial_widget_update'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     *  Return a list of filters to be registered.
     * @return array $filters
     */
    public function get_filters()
    {
        $filters = [
            //Init observer
            ['name' => 'plugin_action_links_' . plugin_basename($this->get_plugin_dir()), 'callback' => [$this->init, 'add_action_links']],
            ['name' => 'wc_get_template', 'callback' => [$this->init, 'load_payment_template'], 'params' => 3],
            ['name' => 'plugin_row_meta', 'callback' => [$this->init, 'plugin_row_meta'], 'params' => 2],
            ['name' => 'woocommerce_available_payment_gateways', 'callback' => [$this->init, 'load_payment_options'], 'params' => 3],
            ['name' => 'woocommerce_admin_status_tabs', 'callback' => [$this->init, 'display_mobbex_log']],
            //Cart observer
            ['name' => 'woocommerce_add_to_cart_validation', 'callback' => [$this->checkout, 'validate_cart_items'], 'params' => 2],
            //Order observer
            ['name' => 'wc_order_statuses', 'callback' => [$this->order, 'add_authorized_order_status']],
            ['name' => 'woocommerce_payment_complete_order_status', 'callback' => [$this->order, 'set_approved_statuses'], 'params' => 2],
            //Checkout observer
            ['name' => 'woocommerce_billing_fields', 'callback' => [$this->checkout, 'add_checkout_fields']],
            //Product Observer
            ['name' => 'woocommerce_cart_calculate_fees', 'callback' => [$this->product, 'maybe_add_mobbex_subscription_fee'], 'params' => 2],
            ['name' => 'woocommerce_get_price_html', 'callback' => [$this->product, 'display_sign_up_fee_on_price'], 'params' => 2]
        ];

        return $filters;
    }

    /**
     *  Return a list of action to be registered.
     * @return array $actions
     */
    public function get_actions()
    {
        $actions = [
            //Init observer
            ['name' => 'wp_enqueue_scripts', 'callback' => [$this->init, 'mobbex_assets_enqueue'], 'params' => 2],
            ['name' => 'admin_enqueue_scripts', 'callback' => [$this->init, 'load_admin_scripts'], 'params' => 2],
            ['name' => 'admin_enqueue_scripts', 'callback' => [$this->init, 'load_order_scripts']],
            ['name' => 'activate_' . plugin_basename('mobbex-for-woocommerce.php'), 'callback' => [$this->init, 'create_mobbex_tables']],
            ['name' => 'admin_bar_menu', 'callback' => [$this->init, 'add_mobbex_admin_bar_network_menu_item'], 'priority' => 40],
            ['name' => 'woocommerce_admin_status_content_mobbex_slug', 'callback' => [$this->init, 'display_mobbex_log_content'], 'priority' => 40],
            ['name' => 'rest_api_init', 'callback' => [$this->init, 'register_route'], 'priority' => 40],
            ['name' => 'register_route', 'callback' => [$this->init, 'init_mobbex_export_data'], 'priority' => 40],
            
            //Product observer
            ['name' => 'woocommerce_product_data_tabs', 'callback' => [$this->product, 'add_product_tab']],
            ['name' => 'woocommerce_product_data_panels', 'callback' => [$this->product, 'show']],
            ['name' => 'product_cat_add_form_fields', 'callback' => [$this->product, 'show']],
            ['name' => 'product_cat_edit_form_fields', 'callback' => [$this->product, 'show']],
            ['name' => 'woocommerce_process_product_meta', 'callback' => [$this->product, 'save']],
            ['name' => 'create_product_cat', 'callback' => [$this->product, 'save']],
            ['name' => 'edited_product_cat', 'callback' => [$this->product, 'save']],

            //Order observer
            ['name' => 'woocommerce_valid_order_statuses_for_payment_complete', 'callback' => [$this->order, 'valid_statuses_for_payment_complete']],
            ['name' => 'woocommerce_order_actions', 'callback' => [$this->order, 'add_capture_action']],
            ['name' => 'woocommerce_order_action_mbbx_capture_payment', 'callback' => [$this->order, 'capture_payment_endpoint']],
            ['name' => 'woocommerce_order_status_authorized', 'callback' => [$this->order, 'authorize_notification']],
            ['name' => 'woocommerce_order_status_authorized_to_processing', 'callback' => [$this->order, 'capture_notification'], 'priority' => 10, 'params' => 2],
            ['name' => 'add_meta_boxes', 'callback' => [$this->order, 'add_payment_info_panel']],

        ];

        //Mobbex finance widget actions
        if ($this->config->financial_info_active === 'yes')
            $actions[] = ['name' => 'woocommerce_after_add_to_cart_form', 'callback' => [$this->product, 'display_finnacial_button']];

        if ($this->config->financial_widget_on_cart === 'yes')
            $actions[] = ['name' => 'woocommerce_after_cart_totals', 'callback' => [$this->product, 'display_finnacial_button'], 'priority' => 1];

        //Checkout observer
        if ($this->helper->isReady()){
            $actions[] = ['name' => 'woocommerce_admin_order_data_after_billing_address', 'callback' => [$this->checkout, 'display_checkout_fields_data']];
            $actions[] = ['name' => 'woocommerce_after_checkout_validation', 'callback' => [$this->checkout, 'validate_checkout_fields']];
            $actions[] = ['name' => 'woocommerce_checkout_update_order_meta', 'callback' => [$this->checkout, 'save_checkout_fields']];
        }

        return $actions;
    }

    /**
     * Returns Mobbex plugin dir
     * @return string
     */
    public function get_plugin_dir()
    {
        return str_replace('/Model/Registrar.php', '', __FILE__) . '/mobbex-for-woocommerce.php';
    }

    public function execute_hook($name, $filter, ...$args)
    {
        if($filter)
            return apply_filters($name, ...$args);
        else
            return do_action($name, ...$args);
    }
}