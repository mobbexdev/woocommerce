<?php
/*
Plugin Name:  Mobbex for Woocommerce
Description:  A small plugin that provides Woocommerce <-> Mobbex integration.
Version:      3.19.1
WC tested up to: 4.6.1
Author: mobbex.com
Author URI: https://mobbex.com/
Copyright: 2020 mobbex.com
 */

// Only requires autload if the file exists to avoid fatal errors
if (file_exists(__DIR__ . '/vendor/autoload.php'))
    require_once __DIR__ . '/vendor/autoload.php';

class MobbexGateway
{
    /** @var \Mobbex\WP\Checkout\Model\Config */
    public static $config;

    /** @var \Mobbex\WP\Checkout\Model\Helper */
    public static $helper;

    /** @var \Mobbex\WP\Checkout\Model\Logger */
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
        // If autoload file doesn't exist, leaves an error message in admin panel and cuts code flow
        if (!file_exists(__DIR__ . '/vendor/autoload.php')){
            MobbexGateway::check_install_dir();
            return;
        }

        //Declare HPOS compatibility
        add_action('before_woocommerce_init', function () {
            if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });

        self::$config    = new \Mobbex\WP\Checkout\Model\Config();
        self::$helper    = new \Mobbex\WP\Checkout\Model\Helper();
        self::$logger    = new \Mobbex\WP\Checkout\Model\Logger();
        self::$registrar = new \Mobbex\WP\Checkout\Model\Registrar();

        // init de Mobbex php sdk
        $this->init_sdk();

        MobbexGateway::check_dependencies();
        MobbexGateway::load_textdomain();
        MobbexGateway::load_update_checker();
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

        // Load suppport to Checkout Blocks
        MobbexGateway::load_woocommerce_blocks_support();

        // Init controllers
        new \Mobbex\WP\Checkout\Controller\Payment;
        new \Mobbex\WP\Checkout\Controller\LogTable;

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
                'sdk'                    => class_exists('\Composer\InstalledVersions') && \Composer\InstalledVersions::isInstalled('mobbexco/php-plugins-sdk') ? \Composer\InstalledVersions::getVersion('mobbexco/php-plugins-sdk') : '',
            ],
            self::$config->formated_settings(),
            [self::$registrar, 'execute_hook'],
            [self::$logger, 'log']
        );

        //Load Mobbex models in sdk
        \Mobbex\Platform::loadModels(
            new \Mobbex\WP\Checkout\Model\Cache(),
            new \Mobbex\WP\Checkout\Model\Db
        );

        // Init api conector
        \Mobbex\Api::init();
    }
    
    public function init_mobbex_subscription()
    {
        if (!self::$config->enable_subscription)
            return;

        require_once MOBBEX_SUBS_DIR . '/mobbex-subscriptions.php';
        $mobbexSubscriptions = new MobbexSubscriptions;
        $mobbexSubscriptions->init();
    }

    /**
     * Leaves an error message in admin panel that inform of incorrect module installation.
     * 
     * @param bool $autoload
     * 
     * return bool
     */
    public static function check_install_dir()
    {
        // Sets a message to inform about the error and correct version URL
        $message = sprintf(
            'El directorio de instalación es incorrecto (<code>%s</code>). Si descargó el zip directamente del repositorio, reinstale el plugin utilizando el archivo <code>%s</code> de <a href="%s">%3$s</a>',
            basename(__DIR__),
            'wc-mobbex.x.y.z.zip',
            'https://github.com/mobbexco/woocommerce/releases/latest'
        );
        $type = 'error';
        
        // Add notice to admin panel
        add_action('admin_notices', function () use ($message, $type){
?>
        <div class="<?= esc_attr("notice notice-$type") ?>">
            <h2>Mobbex for Woocommerce</h2>
            <p><?= $message ?></p>
        </div>
<?php
            }
        );
    }

    /**
     * Check dependencies.
     *
     * @throws Exception
     */
    public static function check_dependencies()
    {
        if (!class_exists('WooCommerce') || !function_exists('WC') || !defined('WC_VERSION')) {
            MobbexGateway::$errors[] = __('WooCommerce needs to be installed and activated.', 'mobbex-for-woocommerce');
            return;
        }

        if (version_compare(defined('WC_VERSION') ? WC_VERSION : '', '2.6', '<')){
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
            $request_uri = filter_input(INPUT_SERVER, 'REQUEST_URI');
            // Checks current version updated and if it's not installing route
            if (get_option('woocommerce-mobbex-version') == MOBBEX_VERSION && !str_contains($request_uri, 'plugin-install'))
                return;

            // Apply upgrades
            self::create_mobbex_tables();

            // Update db version
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
        $myUpdateChecker = \Puc_v4_Factory::buildUpdateChecker(
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

    /**
     * Registers WooCommerce Blocks integration.
     */
    public static function load_woocommerce_blocks_support()
    {
        add_action('woocommerce_blocks_loaded', function(){
            if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
                add_action(
                    'woocommerce_blocks_payment_method_type_registration',
                    function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
                        $payment_method_registry->register(new \Mobbex\WP\Checkout\Model\BlockPaymentMethod());
                    }
                );
            }
        });
    }

    public static function add_assets($type, $name, $route)
    {
        $method = "wp_enqueue_$type";
        
        return $method($name, $route, null, MOBBEX_VERSION);
    }

    /** 
     * Create Mobbex tables
     * 
     * @return bool creation result. 
     */
    public static function create_mobbex_tables()
    {
        //Load Mobbex models in sdk
        \Mobbex\Platform::loadModels(
            new \Mobbex\WP\Checkout\Model\Cache(),
            new \Mobbex\WP\Checkout\Model\Db
        );
        
        foreach (['log', 'transaction', 'cache' , 'subscription', 'subscriber', 'execution'] as  $tableName) {
            // Create the table or alter table if it exists
            $table = new \Mobbex\Model\Table($tableName);
            // If table creation fails, return false
            if (!$table->result)
                return false;
        }
        
        return true;
    } 
}

$mobbexGateway = new MobbexGateway;
add_action('init', [&$mobbexGateway, 'init']);
add_action('init', [&$mobbexGateway, 'init_mobbex_subscription']);

// Remove mbbx entity saved data on uninstall
register_deactivation_hook(__FILE__, function() {
    update_option('mbbx_entity', '');
});