<?php

namespace Mobbex\WP\Checkout\Observer;

class Init
{
    /** @var \Mobbex\WP\Checkout\Model\Config */
    public $config;

    /** @var \Mobbex\WP\Checkout\Model\Helper */
    public $helper;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
    public $logger;

    public function __construct()
    {
        //Set classes
        $this->config = new \Mobbex\WP\Checkout\Model\Config();
        $this->helper = new \Mobbex\WP\Checkout\Model\Helper();
        $this->logger = new \Mobbex\WP\Checkout\Model\Logger();
    }

    public function mobbex_assets_enqueue()
    {
        global $post;

        $dir_url = str_replace('/Observer', '', plugin_dir_url(__FILE__));

        // Only if directory url looks good
        if (empty($dir_url) || substr($dir_url, -1) != '/')
            return $this->logger->log('Mobbex Enqueue Error: Invalid directory URL', $dir_url, is_checkout() || is_product());

        // Finance Widget
        if ((is_product() && $this->config->financial_info_active) ||
            (is_cart() && $this->config->financial_widget_on_cart && $this->config->financial_info_active) ||
            (has_shortcode($post->post_content, 'mobbex_button') && $this->config->financial_info_active)
        ) {
            wp_enqueue_style('mbbx-finance-widget-styles', $dir_url . 'assets/css/product.css', null, MOBBEX_VERSION);
            wp_enqueue_script('mbbx-finance-widget', $dir_url . "assets/components/FinanceWidget.js", ['react', "react-dom"], MOBBEX_VERSION);
            wp_localize_script('mbbx-finance-widget', 'mobbexWidget', [
                'updateUrl'   => get_rest_url(null, 'mobbex/v1/widget/update'),
                'sourcesUrl'  => get_rest_url(null, 'mobbex/v1/widget/sources'),
                'show_button' => isset($params['show_button']) ? $params['show_button'] : true,
                'theme'       => $this->config->theme,
                'type'        => $this->config->financial_widget_type,
                'styles'      => $this->config->financial_widget_styles,
                'text'        => $this->config->financial_widget_button_text,
                'logo'        => $this->config->financial_widget_button_logo
            ]);
        }

        // Checkout page
        if (is_checkout()) {
            // Exclude scripts from cache plugins minification
            !defined('DONOTCACHEPAGE') && define('DONOTCACHEPAGE', true);
            !defined('DONOTMINIFY') && define('DONOTMINIFY', true);

            wp_enqueue_script('mobbex-embed', 'https://res.mobbex.com/js/embed/mobbex.embed@' . MOBBEX_EMBED_VERSION . '.js', null, MOBBEX_VERSION);
            wp_enqueue_script('mobbex-sdk', 'https://res.mobbex.com/js/sdk/mobbex@' . MOBBEX_SDK_VERSION . '.js', null, MOBBEX_VERSION);

            // Enqueue payment asset files
            wp_enqueue_style('mobbex-checkout-style', $dir_url . 'assets/css/checkout.css', null, MOBBEX_VERSION);
            wp_register_script('mobbex-checkout-script', $dir_url . 'assets/js/mobbex.bootstrap.js', ['jquery'], MOBBEX_VERSION);

            wp_localize_script('mobbex-checkout-script', 'mobbex_data', [
                'is_pay_for_order' => !empty($_GET['pay_for_order']),
            ]);
            wp_enqueue_script('mobbex-checkout-script');
        }
    }

    /**
     * Load all admin scripts and styles.
     * 
     * @param string $hook
     */
    public function load_admin_scripts($hook)
    {
        global $post, $current_screen;

        $dir_url = str_replace('/Observer', '', plugin_dir_url(__FILE__));

        // Product admin page
        if (($hook == 'post-new.php' || $hook == 'post.php') && $post->post_type == 'product') {
            wp_enqueue_style('mbbx-product-style', $dir_url . 'assets/css/product-admin.css', null, MOBBEX_VERSION);
            wp_enqueue_script('mbbx-product-js', $dir_url . 'assets/js/product-admin.js', null, MOBBEX_VERSION);
        }

        // Category admin page
        if (isset($current_screen->id) && $current_screen->id == 'edit-product_cat') {
            wp_enqueue_style('mbbx-category-style', $dir_url . 'assets/css/category-admin.css', null, MOBBEX_VERSION);
            wp_enqueue_script('mbbx-category-js', $dir_url . 'assets/js/category-admin.js', null, MOBBEX_VERSION);
        }

        // Plugin config page
        if ($hook == 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] == 'mobbex') {
            wp_enqueue_style('mbbx-plugin-style', $dir_url . 'assets/css/plugin-config.css', null, MOBBEX_VERSION);
            wp_enqueue_script('mbbx-plugin-js', $dir_url . 'assets/js/plugin-config.js', null, MOBBEX_VERSION);
        }
    }

    /**
     * Load styles and scripts for dynamic options.
     */
    public static function load_order_scripts($hook)
    {
        global $post;

        if (!isset($post->ID) && !isset($_REQUEST['id']))
            return;

        $id = $post ? $post->ID : $_REQUEST['id'];

        if (($hook == 'post-new.php' || $hook == 'post.php') && ($post->post_type == 'shop_order' || \Automattic\WooCommerce\Utilities\OrderUtil::is_order($id, wc_get_order_types()))) {
            wp_enqueue_style('mbbx-order-style', plugin_dir_url(__FILE__) . '../../assets/css/order-admin.css');
            wp_enqueue_script('mbbx-order', plugin_dir_url(__FILE__) . '../../assets/js/order-admin.js');

            $order       = wc_get_order($id);
            $order_total = get_post_meta($id, 'mbbxs_sub_total', true) ?: $order->get_total();

            // Add retry endpoint URL to script
            $mobbex_data = [
                'order_id'    => $id,
                'order_total' => $order_total,
                'capture_url' => home_url('/wc-api/mbbx_capture_payment')
            ];
            wp_localize_script('mbbx-order', 'mobbex_data', $mobbex_data);
            wp_enqueue_script('mbbx-order');
        }
    }

    public function add_action_links($links)
    {
        $plugin_links = [
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=mobbex') . '">' . __('Settings', 'mobbex-for-woocommerce') . '</a>',
        ];

        $links = array_merge($plugin_links, $links);

        return $links;
    }

    /**
     * Plugin row meta links
     *
     * @access public
     * @param  array $input already defined meta links
     * @param  string $file plugin file path and name being processed
     * @return array $input
     */
    public function plugin_row_meta($links, $file)
    {
        if (strpos($file, plugin_basename(__FILE__)) !== false) {
            $plugin_links = [
                '<a href="' . esc_url(\MobbexGateway::$site_url) . '" target="_blank">' . __('Website', 'mobbex-for-woocommerce') . '</a>',
                '<a href="' . esc_url(\MobbexGateway::$doc_url) . '" target="_blank">' . __('Documentation', 'mobbex-for-woocommerce') . '</a>',
                '<a href="' . esc_url(\MobbexGateway::$github_url) . '" target="_blank">' . __('Contribute', 'mobbex-for-woocommerce') . '</a>',
                '<a href="' . esc_url(\MobbexGateway::$github_issues_url) . '" target="_blank">' . __('Report Issues', 'mobbex-for-woocommerce') . '</a>',
            ];

            $links = array_merge($links, $plugin_links);
        }

        return $links;
    }

    /**
     * Load payment options on gateway to show in checkout.
     *  
     * @param array $options
     * 
     * @return array 
     */
    public function load_payment_options($options)
    {
        if (is_cart() || is_order_received_page() || !is_checkout() || !$this->helper->isReady() || empty($options['mobbex']))
            return $options;

        // Get checkout from context loaded object
        $response = $this->helper->get_context_checkout();

        // Add cards and payment methods to gateway
        $options['mobbex']->cards   = isset($response['wallet']) ? $response['wallet'] : [];
        $options['mobbex']->methods = isset($response['paymentMethods']) ? $response['paymentMethods'] : [];

        return $options;
    }

    /**
     * Load own template to show payment options in checkout.
     *  
     * @param array $options
     * 
     * @return array 
     */
    public function load_payment_template($template, $template_name, $args)
    {
        if (
            !$this->helper->isReady()
            || $template_name != 'checkout/payment-method.php'
            || !isset($args['gateway'])
            || $args['gateway']->id != 'mobbex'
            || $this->config->disable_template == 'yes'
        )
            return $template;

        return __DIR__ . '/../templates/payment-options.php';
    }

    /** 
     * Calls create mobbex tables method when the plugin is activated
     * 
     * @return bool creation result. 
     */
    public function create_mobbex_tables()
    {
        return \MobbexGateway::create_mobbex_tables();
    }

    /**
     * Adds a Mobbex log table tab to the network admin
     *
     */
    public function add_mobbex_admin_bar_network_menu_item()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wp_admin_bar;
        $menu_id = 'mobbexlogger';

        $wp_admin_bar->add_menu(array(
            'id'    => $menu_id,
            'parent' => false,
            'group'  => null,
            'title' => 'Mobbex Log Table',
            'href'  => admin_url('admin.php?page=wc-status&tab=mobbex_slug'),
            'meta' => [
                'title' => __('mobbexlogger', 'textdomain'),
            ]
        ));
    }

    /**
     * Add Mobbex slug to woocommerce status panel
     * 
     * @return mixed $tabs mobbex tab
     */
    public function display_mobbex_log($tabs)
    {
        $tabs['mobbex_slug'] = __('Mobbex Log Table', 'woocommerce');
        return $tabs;
    }

    /**
     * Display mobbex logs table in mobbex slug
     */
    public function display_mobbex_log_content()
    {
        include_once plugin_dir_path(__FILE__) . "../templates/mobbex-log-table.php";
    }

    /**
     * Registers the route where the controller that invoke a callback function 
     */
    public function register_route()
    {
        register_rest_route('mobbex/v1', '/download_logs', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'init_mobbex_export_data'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Calls mobbex export data method as a callback. Manages download data
     */
    public function init_mobbex_export_data()
    {
        (new \Mobbex\WP\Checkout\Controller\LogTable($_POST))->mobbex_export_data();
    }
}
