<?php
/*
Plugin Name:  Mobbex for Woocommerce
Description:  A small plugin that provides Woocommerce <-> Mobbex integration.
Version:      3.12.0
WC tested up to: 4.6.1
Author: mobbex.com
Author URI: https://mobbex.com/
Copyright: 2020 mobbex.com
 */

require_once 'vendor/autoload.php';

class MobbexGateway
{
    /** @var \Mobbex\WP\Checkout\Models\Config */
    public static $config;

    /** @var \Mobbex\WP\Checkout\Models\Helper */
    public static $helper;

    /** @var \Mobbex\WP\Checkout\Models\Logger */
    public static $logger;

    /** @var \Mobbex\WP\Checkout\Observer\Registrar */
    public static $registrar;

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
        self::$config    = new \Mobbex\WP\Checkout\Models\Config();
        
        //Init de Mobbex php sdk
        $this->init_sdk();
        
        self::$helper    = new \Mobbex\WP\Checkout\Models\Helper();
        self::$logger    = new \Mobbex\WP\Checkout\Models\Logger();
        self::$registrar = new \Mobbex\WP\Checkout\Models\Registrar();

        MobbexGateway::load_textdomain();
        MobbexGateway::load_update_checker();
        MobbexGateway::check_dependencies();
        MobbexGateway::check_upgrades();

        if (MobbexGateway::$errors) {
            foreach (MobbexGateway::$errors as $error)
                self::$logger->notice($error);

            return;
        }

        self::check_warnings();

        // Add Mobbex gateway
        MobbexGateway::load_gateway();
        MobbexGateway::add_gateway();

        // Init controllers
        new \Mobbex\WP\Checkout\Controllers\Payment;

        //Register hooks
        self::$registrar->register_hooks();
    }

    /**
     * Init the PHP Sdk and configure it with module & plataform data.
     */
    public function init_sdk()
    {
        // Set platform information
        \Mobbex\Platform::init(
            'Woocommerce' . WC_VERSION,
            MOBBEX_VERSION,
            str_replace('www.', '', parse_url(home_url(), PHP_URL_HOST)),
            [
                'Woocommerce'            => WC_VERSION,
                'Mobbex for Woocommerce' => MOBBEX_VERSION,
                'sdk'                    => class_exists('\Composer\InstalledVersions') ? \Composer\InstalledVersions::getVersion('mobbexco/php-plugins-sdk') : '',
            ],
            self::$config->formated_settings(),
            [self::$registrar, 'execut_hook'],
            [self::$logger, 'log']
        );

        // Init api conector
        \Mobbex\Api::init();
    }

    /**
     * Check dependencies.
     *
     * @throws Exception
     */
    public static function check_dependencies()
    {
        if (!class_exists('WooCommerce') || !function_exists('WC') || version_compare(defined('WC_VERSION') ? WC_VERSION : '', '2.6', '<')) {
            MobbexGateway::$errors[] = __('WooCommerce version 2.6 or greater needs to be installed and activated.', 'mobbex-for-woocommerce');
        }

        if (!is_ssl()) {
            MobbexGateway::$errors[] = __('Your site needs to be served via HTTPS to comunicate securely with Mobbex.', 'mobbex-for-woocommerce');
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

    /**
     * Check pending database upgrades and upgrade if is needed.
     */
    public static function check_upgrades()
    {
        try {
            $db_version = get_option('woocommerce-mobbex-version');

            if ($db_version < '3.6.0')
                alt_mobbex_transaction_table();


            // Update db version
            if ($db_version != MOBBEX_VERSION)
                update_option('woocommerce-mobbex-version', MOBBEX_VERSION);
        } catch (\Exception $e) {
            self::$errors[] = 'Mobbex DB Upgrade error';
        }
    }

    /**
     * Check and log minor problems of install.
     */
    public static function check_warnings()
    {
        // Check install directory
        if (basename(__DIR__) == 'woocommerce-master')
            self::$logger->notice(sprintf(
                'El directorio de instalación es incorrecto (<code>%s</code>). Si descargó el zip directamente del repositorio, reinstale el plugin utilizando el archivo <code>%s</code> de <a href="%s">%3$s</a>',
                basename(__DIR__),
                'wc-mobbex.x.y.z.zip',
                'https://github.com/mobbexco/woocommerce/releases/latest'
            ));

        // Check if credentials are configured
        if (self::$config->enabled == 'yes' && (!self::$config->api_key || !self::$config->access_token))
            self::$logger->notice(sprintf(
                'Debe especificar el API Key y Access Token en la <a href="%s">configuración</a>.',
                admin_url('admin.php?page=wc-settings&tab=checkout&section=mobbex')
            ));
    }

    public static function load_textdomain()
    {
        load_plugin_textdomain('mobbex-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
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

    public static function add_assets($type, $name, $route)
    {
        $method = "wp_enqueue_$type";
        
        return $method($name, $route, null, MOBBEX_VERSION);
    }
}

/** 
 * Adds childs column to mobbex transaction table if exists
 * 
 */

 function alt_mobbex_transaction_table()
 {
    global $wpdb;
     
    $tableExist = $wpdb->get_results('SHOW TABLES LIKE ' . "'$wpdb->prefix" . "mobbex_transaction';");
     
    if ($tableExist) :
        $columnExist = $wpdb->get_results('SHOW COLUMNS FROM ' . $wpdb->prefix . 'mobbex_transaction WHERE FIELD = '. "'childs';" );
        if (!$columnExist) :
            $wpdb->get_results("ALTER TABLE " . $wpdb->prefix . 'mobbex_transaction' . " ADD COLUMN childs TEXT NOT NULL;");
        else :
            return;
        endif;
    else :
        create_mobbex_transaction_table();
    endif;
 }

function create_mobbex_transaction_table()
{
    global $wpdb;

    $wpdb->get_results(
        'CREATE TABLE ' . $wpdb->prefix . 'mobbex_transaction('
            . 'id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . 'order_id INT(11) NOT NULL,'
            . 'parent TEXT NOT NULL,'
            . 'childs TEXT NOT NULL,'
            . 'operation_type TEXT NOT NULL,'
            . 'payment_id TEXT NOT NULL,'
            . 'description TEXT NOT NULL,'
            . 'status_code TEXT NOT NULL,'
            . 'status_message TEXT NOT NULL,'
            . 'source_name TEXT NOT NULL,'
            . 'source_type TEXT NOT NULL,'
            . 'source_reference TEXT NOT NULL,'
            . 'source_number TEXT NOT NULL,'
            . 'source_expiration TEXT NOT NULL,'
            . 'source_installment TEXT NOT NULL,'
            . 'installment_name TEXT NOT NULL,'
            . 'installment_amount DECIMAL(18,2) NOT NULL,'
            . 'installment_count TEXT NOT NULL,'
            . 'source_url TEXT NOT NULL,'
            . 'cardholder TEXT NOT NULL,'
            . 'entity_name TEXT NOT NULL,'
            . 'entity_uid TEXT NOT NULL,'
            . 'customer TEXT NOT NULL,'
            . 'checkout_uid TEXT NOT NULL,'
            . 'total DECIMAL(18,2) NOT NULL,'
            . 'currency TEXT NOT NULL,'
            . 'risk_analysis TEXT NOT NULL,'
            . 'data TEXT NOT NULL,'
            . 'created TEXT NOT NULL,'
            . 'updated TEXT NOT NULL'
            . ');'
    );
}

$mobbexGateway = new MobbexGateway;
add_action('plugins_loaded', [&$mobbexGateway, 'init']);
register_activation_hook(__FILE__, 'create_mobbex_transaction_table');

// Remove mbbx entity saved data on uninstall
register_deactivation_hook(__FILE__, function() {
    update_option('mbbx_entity', '');
});
