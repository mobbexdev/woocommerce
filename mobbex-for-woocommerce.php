<?php
/*
Plugin Name:  Mobbex for Woocommerce
Description:  A small plugin that provides Woocommerce <-> Mobbex integration.
Version:      3.3.3
WC tested up to: 4.6.1
Author: mobbex.com
Author URI: https://mobbex.com/
Copyright: 2020 mobbex.com
 */

require_once 'includes/utils.php';

class MobbexGateway
{
    /**
     * Errors Array
     */
    static $errors = [];

    /**
     * Mobbex URL.
     */
    public static $site_url = "https://www.mobbex.com";

    /**
     * Gateway documentation URL.
     */
    public static $doc_url = "https://mobbex.dev";

    /**
     * Github URLs
     */
    public static $github_url = "https://github.com/mobbexco/woocommerce";
    public static $github_issues_url = "https://github.com/mobbexco/woocommerce/issues";

    public function init()
    {
        MobbexGateway::load_textdomain();
        MobbexGateway::load_update_checker();
        MobbexGateway::check_dependencies();

        if (count(MobbexGateway::$errors)) {

            foreach (MobbexGateway::$errors as $error) {
                MobbexGateway::notice('error', $error);
            }

            return;
        }

        MobbexGateway::load_helper();
        MobbexGateway::load_order_admin();
        MobbexGateway::load_product_admin();
        MobbexGateway::load_gateway();
        MobbexGateway::add_gateway();

        $helper = new MobbexHelper();
        if (!empty($helper->financial_info_active) && $helper->financial_info_active === 'yes') {
            // Add a new button after the "add to cart" button
            add_action('woocommerce_after_add_to_cart_form', [$this, 'additional_button_add_to_cart'], 20 );
        }

        // Enqueue assets
        add_action('wp_enqueue_scripts', [$this, 'mobbex_assets_enqueue']);
        add_action('admin_enqueue_scripts', [$this, 'load_admin_scripts']);

        // Add some useful things
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
        add_filter('plugin_row_meta', [$this, 'plugin_row_meta'], 10, 2);

        // Validate Cart items
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_cart_items'], 10, 2);

        // Checkout update actions
        add_action('woocommerce_api_mobbex_checkout_update', [$this, 'mobbex_checkout_update']);
        add_action('woocommerce_cart_emptied', function(){WC()->session->set('order_id', null);});
        add_action('woocommerce_add_to_cart', function(){WC()->session->set('order_id', null);});

        add_action('rest_api_init', function () {
            register_rest_route('mobbex/v1', '/webhook', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'mobbex_webhook_api'],
                'permission_callback' => '__return_true',
            ]);
        });

        // Create financial widget shortcode
        add_shortcode('mobbex_button', [$this, 'shortcode_mobbex_button']);
    }

    /**
     * Check dependencies.
     *
     * @throws Exception
     */
    public static function check_dependencies()
    {
        if (!class_exists('WooCommerce')) {
            MobbexGateway::$errors[] = __('WooCommerce needs to be installed and activated.', 'mobbex-for-woocommerce');
        }

        if (!function_exists('WC')) {
            MobbexGateway::$errors[] = __('Mobbex requires WooCommerce to be activated', 'mobbex-for-woocommerce');
        }

        if (!is_ssl()) {
            MobbexGateway::$errors[] = __('Your site needs to be served via HTTPS to comunicate securely with Mobbex.', 'mobbex-for-woocommerce');
        }

        if (version_compare(WC_VERSION, '2.6', '<')) {
            MobbexGateway::$errors[] = __('Mobbex requires WooCommerce version 2.6 or greater', 'mobbex-for-woocommerce');
        }

        if (!function_exists('curl_init')) {
            MobbexGateway::$errors[] = __('Mobbex requires the cURL PHP extension to be installed on your server', 'mobbex-for-woocommerce');
        }

        if (!function_exists('json_decode')) {
            MobbexGateway::$errors[] = __('Mobbex requires the JSON PHP extension to be installed on your server', 'mobbex-for-woocommerce');
        }

        $openssl_warning = __('Mobbex requires OpenSSL >= 1.0.1 to be installed on your server', 'mobbex-for-woocommerce');
        if (!defined('OPENSSL_VERSION_TEXT')) {
            MobbexGateway::$errors[] = $openssl_warning;
        }

        preg_match('/^(?:Libre|Open)SSL ([\d.]+)/', OPENSSL_VERSION_TEXT, $matches);
        if (empty($matches[1])) {
            MobbexGateway::$errors[] = $openssl_warning;
        }

        if (!version_compare($matches[1], '1.0.1', '>=')) {
            MobbexGateway::$errors[] = $openssl_warning;
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
                '<a href="' . esc_url(MobbexGateway::$site_url) . '" target="_blank">' . __('Website', 'mobbex-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexGateway::$doc_url) . '" target="_blank">' . __('Documentation', 'mobbex-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexGateway::$github_url) . '" target="_blank">' . __('Contribute', 'mobbex-for-woocommerce') . '</a>',
                '<a href="' . esc_url(MobbexGateway::$github_issues_url) . '" target="_blank">' . __('Report Issues', 'mobbex-for-woocommerce') . '</a>',
            ];

            $links = array_merge($links, $plugin_links);
        }

        return $links;
    }

    public function mobbex_webhook_api($request)
    {
        try {
            mobbex_debug("REST API > Request", $request->get_params());

            $mobbexGateway = WC()->payment_gateways->payment_gateways()[MOBBEX_WC_GATEWAY_ID];

            return $mobbexGateway->mobbex_webhook_api($request);
        } catch (Exception $e) {
            mobbex_debug("REST API > Error", $e);

            return [
                "result" => false,
            ];
        }
    }

    public static function load_textdomain()
    {

        load_plugin_textdomain('mobbex-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    }

    public static function load_helper()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/helper.php';
    }

    public static function load_order_admin()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/admin/order.php';
        Mbbx_Order_Admin::init();
    }

    public static function load_product_admin()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/admin/product.php';
        Mbbx_Product_Admin::init();
    }

    public static function load_update_checker()
    {
        require 'plugin-update-checker/plugin-update-checker.php';
        $myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
            'https://github.com/mobbexco/woocommerce/',
            __FILE__,
            'mobbex-plugin-update-checker'
        );
        $myUpdateChecker->getVcsApi()->enableReleaseAssets();
    }

    public static function load_gateway()
    {

        require_once plugin_dir_path(__FILE__) . 'gateway.php';

    }

    public static function add_gateway()
    {

        add_filter('woocommerce_payment_gateways', function ($methods) {

            $methods[] = MOBBEX_WC_GATEWAY;
            return $methods;

        });

    }

    public static function notice($type, $msg)
    {

        add_action('admin_notices', function () use ($type, $msg) {
            $class = esc_attr("notice notice-$type");
            $msg = esc_html($msg);

            ob_start();

            ?>

            <div class="<?=$class?>">
                <h2>Mobbex for Woocommerce</h2>
                <p><?=$msg?></p>
            </div>

            <?php

            echo ob_get_clean();
        });

    }

    public function mobbex_assets_enqueue()
    {
        global $post;
        $helper = new MobbexHelper();
        $dir_url = plugin_dir_url(__FILE__);
        // If dir url looks good
        if (!empty($dir_url) && substr($dir_url, -1) === '/') {
            // Product page
            if (is_product()) 
            {
                wp_register_script('mmbbx-product-button-js', plugin_dir_url(__FILE__) . 'assets/js/mobbex_product_financing.js');
                wp_enqueue_script('mmbbx-product-button-js');

                if(!empty($helper->financial_info_active)){
                    wp_register_style('mobbex_product_style', $dir_url . 'assets/css/product.css');
                    wp_enqueue_style('mobbex_product_style');
                }
            }
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

        // Product admin page
        if (($hook == 'post-new.php' || $hook == 'post.php') && $post->post_type == 'product') {
            wp_enqueue_style('mbbx-product-style', plugin_dir_url(__FILE__) . 'assets/css/product-admin.css');
            wp_enqueue_script('mbbx-product-js', plugin_dir_url(__FILE__) . 'assets/js/product-admin.js');
        }

        // Category admin page
        if (isset($current_screen->id) && $current_screen->id == 'edit-product_cat') {
            wp_enqueue_style('mbbx-category-style', plugin_dir_url(__FILE__) . 'assets/css/category-admin.css');
            wp_enqueue_script('mbbx-category-js', plugin_dir_url(__FILE__) . 'assets/js/category-admin.js');
        }

        // Plugin config page
        if ($hook == 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] == 'mobbex') {
            wp_enqueue_style('mbbx-plugin-style', plugin_dir_url(__FILE__) . 'assets/css/plugin-config.css');
            wp_enqueue_script('mbbx-plugin-js', plugin_dir_url(__FILE__) . 'assets/js/plugin-config.js');
        }
    }

    /**
     * Add new button to show a modal with financial information
     * only if the checkbox of financial information is checked
     * @access public
     */
    public function additional_button_add_to_cart()
    {
        $helper = new MobbexHelper();

        // Trigger/Open The Modal if the checkbox is true in the plugin settings and tax_id is set
        if ($helper->financial_info_active)
            do_shortcode('[mobbex_button]');
    }

    /**
     * Add new button to show a modal with financial information
     * only if the checkbox of financial information is checked
     * Shortcode function, return button html
     * and a hidden table with plans
     * in woocommerce echo do_shortcode('[mobbex_button]'); in content-single-product.php
     * or [mobbex_button] in wordpress pages
     */
    public function shortcode_mobbex_button()
    {
        global $post;

        // Shortcode only works in product page
        if ($post->post_type != 'product')
            return;

        include_once plugin_dir_path(__FILE__) . 'templates/financing-button.html';

        $helper  = new MobbexHelper();
        $product = wc_get_product($post->ID);
        $sources = $helper->get_list_source($product->get_price(), $product->get_id());

        $this->build_table_html($sources);//list with the payment methods and plans
        $this->build_list_html($sources);
        echo '<button  id="mbbxProductBtn" class="single_add_to_cart_button button alt">Ver Financiación</button>';

        // Send product data to javascript
        $data = [
            'price'        => $product->get_price(),
            'product_id'   => $product->get_id(),
            'product_type' => $product->get_type(),
        ];

        wp_localize_script('mmbbx-product-button-js', 'global_data_assets', $data);
        wp_enqueue_script('mmbbx-product-button-js');
    }

    /**
     * Creates html code for plans table
     */
    private function build_table_html($payment_methods)
    {
        ?>
        <table id="mobbex_payment_plans_list" style="max-width:100%;display:none;border: none;">
        <?php foreach($payment_methods as $method) : ?>
            <?php if (!empty($method['name'])) : ?>
                <tr id="<?= $method['reference'] ?>" class="mobbexPaymentMethod">
                    <th colspan="2">
                        <div>
                            <img src="https://res.mobbex.com/images/sources/<?= $method['reference'] ?>.jpg"><?= $method['name'] ?>
                        </div>
                    </th>
                </tr>
                <?php foreach($method['installments'] as $installment) : ?>
                    <tr id="<?= $method['reference'] ?>">
                        <td><?= $installment['name'] ?> </td>
                        <td style="text-align: center; ">$ <?= $installment['amount'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
        </table>
        <?php
    }

    /**
     * Return the select html element with all the payment methods
     */
    private function build_list_html($payment_methods)
    {
        ?>
        <select name="methods" id="mobbex_methods_list" style="width:100%;display:none;">
            <option id="0" value="0">Todos</option>
            <?php foreach($payment_methods as $method) : ?>
                <?php if (!empty($method['name'])) : ?>
                    <option id="<?= $method['reference'] ?>" value="<?= $method['reference'] ?>"><?= $method['name'] ?></option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public function mobbex_checkout_update()
    {
        // Get Checkout and Order Id
        $checkout = WC()->checkout;
        $order_id = WC()->session->get('order_id');
        WC()->cart->calculate_totals();

        // Get Order info if exists
        if (!$order_id) {
            return false;
        }
        $order = wc_get_order($order_id);

        // If form data is sent
        if (!empty($_REQUEST['payment_method'])) {

            // Get billing and shipping data from Request
            $billing = [];
            $shipping = [];
            foreach ($_REQUEST as $key => $value) {

                if (strpos($key, 'billing_') === 0) {
                    $new_key = str_replace('billing_', '',$key);
                    $billing[$new_key] = $value;
                } elseif (strpos($key, 'shipping_') === 0) {

                    $new_key = str_replace('billing_', '',$key);
                    $shipping[$new_key] = $value;
                }

            }

            // Save data to Order
            $order->set_payment_method($_REQUEST['payment_method']);
            $order->set_address($billing, 'billing');
            $order->set_address($shipping, 'shipping');
            echo ($order->save());
            exit;
        } else {

            // Renew Order Items
            $order->remove_order_items();
            $order->set_cart_hash(WC()->cart->get_cart_hash());
            $checkout->set_data_from_cart($order);

            // Save Order
            $order->save();

            $mobbexGateway = WC()->payment_gateways->payment_gateways()[MOBBEX_WC_GATEWAY_ID];

            echo json_encode($mobbexGateway->process_payment($order_id));
            exit;
        }

    }

    /**
     * Check that the Cart does not have products from different stores.
     * 
     * @param bool $valid
     * @param int $product_id
     * 
     * @return bool $valid
     */
    public static function validate_cart_items($valid, $product_id)
    {
        $cart_items = !empty(WC()->cart->get_cart()) ? WC()->cart->get_cart() : [];

        // Get store from current product
        $product_store = MobbexHelper::get_store_from_product($product_id);

        // Get stores from cart items
        foreach ($cart_items as $item) {
            $item_store = MobbexHelper::get_store_from_product($item['product_id']);

            // If there are different stores in the cart items
            if ($product_store != $item_store) {
                wc_add_notice(__('The cart cannot have products from different sellers at the same time.', 'mobbex-for-woocommerce'), 'error');
                return false;
            }
        }

        return $valid;
    }
}

$mobbexGateway = new MobbexGateway;
add_action('plugins_loaded', [ & $mobbexGateway, 'init']);