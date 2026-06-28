<?php
if (!defined('ABSPATH')) {
    exit;
}

class Weather_Worm_Plugin {
    const OPTION_NAME = 'weather_worm_settings';
    const VERSION = WEATHER_WORM_VERSION;

    private static $instance = null;
    private $settings;
    private $client;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate() {
        self::install_defaults();
    }

    public static function deactivate() {
        Weather_Worm_Client::clear_cache();
    }

    public static function install_defaults() {
        $existing = get_option(self::OPTION_NAME, array());
        $defaults = self::default_settings(self::read_local_env_defaults());
        $settings = self::sanitize_settings(wp_parse_args(is_array($existing) ? $existing : array(), $defaults), $defaults);
        update_option(self::OPTION_NAME, $settings, false);
    }

    public static function default_settings($env = array()) {
        $env = is_array($env) ? $env : array();
        $api_key = isset($env['WL_API_KEY']) ? sanitize_text_field((string) $env['WL_API_KEY']) : '';
        $api_secret = isset($env['WL_API_SECRET']) ? Weather_Worm_Client::encrypt_secret((string) $env['WL_API_SECRET']) : '';
        $station_id = isset($env['WL_STATION']) && preg_match('/^\d+$/', trim((string) $env['WL_STATION']))
            ? absint($env['WL_STATION'])
            : 117715;
        if ($station_id <= 0) {
            $station_id = 117715;
        }

        return array(
            'api_key' => $api_key,
            'api_secret' => $api_secret,
            'default_station_id' => $station_id,
            'default_station_label' => 'Stone Tower Winery',
            'cache_ttl' => 300,
            'shortcodes' => array(
                'stone-tower-current' => array(
                    'id' => 'stone-tower-current',
                    'title' => 'Current Weather',
                    'station_id' => $station_id,
                    'station_label' => 'Stone Tower Winery',
                    'sensor_lsid' => 0,
                    'show_station' => 1,
                    'metrics' => self::default_metric_keys(),
                ),
            ),
        );
    }

    public static function available_metrics() {
        return array(
            'temp' => array('label' => __('Temperature', 'weather-worm'), 'icon' => 'fa-temperature-half'),
            'hum' => array('label' => __('Humidity', 'weather-worm'), 'icon' => 'fa-droplet-percent'),
            'wind' => array('label' => __('Wind', 'weather-worm'), 'icon' => 'fa-wind'),
            'rain_today' => array('label' => __('Rain Today', 'weather-worm'), 'icon' => 'fa-cloud-rain'),
            'rain_rate' => array('label' => __('Rain Rate', 'weather-worm'), 'icon' => 'fa-cloud-showers-heavy'),
            'barometer' => array('label' => __('Barometer', 'weather-worm'), 'icon' => 'fa-gauge-high'),
            'dew_point' => array('label' => __('Dew Point', 'weather-worm'), 'icon' => 'fa-temperature-low'),
            'heat_index' => array('label' => __('Heat Index', 'weather-worm'), 'icon' => 'fa-sun-bright'),
            'last_updated' => array('label' => __('Updated', 'weather-worm'), 'icon' => 'fa-clock'),
        );
    }

    public static function default_metric_keys() {
        return array('temp', 'hum', 'wind', 'rain_today', 'rain_rate', 'barometer', 'dew_point', 'heat_index', 'last_updated');
    }

    public static function raw_value_shortcodes() {
        return array(
            'weather_worm_temperature' => array('metric' => 'temp', 'format' => 'value'),
            'weather_worm_temperature_display' => array('metric' => 'temp', 'format' => 'display'),
            'weather_worm_humidity' => array('metric' => 'hum', 'format' => 'value'),
            'weather_worm_humidity_display' => array('metric' => 'hum', 'format' => 'display'),
            'weather_worm_wind_speed' => array('metric' => 'wind', 'format' => 'value'),
            'weather_worm_wind_speed_display' => array('metric' => 'wind', 'format' => 'display'),
            'weather_worm_wind_direction' => array('metric' => 'wind', 'format' => 'direction'),
            'weather_worm_wind_direction_degrees' => array('metric' => 'wind', 'format' => 'direction_degrees'),
            'weather_worm_rain_today' => array('metric' => 'rain_today', 'format' => 'value'),
            'weather_worm_rain_today_display' => array('metric' => 'rain_today', 'format' => 'display'),
            'weather_worm_rain_rate' => array('metric' => 'rain_rate', 'format' => 'value'),
            'weather_worm_rain_rate_display' => array('metric' => 'rain_rate', 'format' => 'display'),
            'weather_worm_barometer' => array('metric' => 'barometer', 'format' => 'value'),
            'weather_worm_barometer_display' => array('metric' => 'barometer', 'format' => 'display'),
            'weather_worm_dew_point' => array('metric' => 'dew_point', 'format' => 'value'),
            'weather_worm_dew_point_display' => array('metric' => 'dew_point', 'format' => 'display'),
            'weather_worm_heat_index' => array('metric' => 'heat_index', 'format' => 'value'),
            'weather_worm_heat_index_display' => array('metric' => 'heat_index', 'format' => 'display'),
            'weather_worm_last_updated' => array('metric' => 'last_updated', 'format' => 'time'),
            'weather_worm_last_updated_timestamp' => array('metric' => 'last_updated', 'format' => 'value'),
            'weather_worm_station_name' => array('metric' => 'station_label', 'format' => 'value'),
        );
    }

    private function __construct() {
        $this->settings = self::get_settings();
        $this->client = new Weather_Worm_Client($this->settings);

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_filter('admin_footer_text', array($this, 'filter_admin_footer_text'), 20, 1);
        add_shortcode('weather_worm', array($this, 'render_shortcode'));
        add_shortcode('weather_worm_value', array($this, 'render_value_shortcode'));
        foreach (self::raw_value_shortcodes() as $shortcode => $definition) {
            add_shortcode($shortcode, array($this, 'render_named_value_shortcode'));
        }

        add_action('admin_post_weather_worm_save_settings', array($this, 'handle_save_settings'));
        add_action('admin_post_weather_worm_test_connection', array($this, 'handle_test_connection'));
        add_action('admin_post_weather_worm_clear_cache', array($this, 'handle_clear_cache'));
        add_action('admin_post_weather_worm_save_shortcode', array($this, 'handle_save_shortcode'));
        add_action('admin_post_weather_worm_delete_shortcode', array($this, 'handle_delete_shortcode'));
    }

    public static function get_settings() {
        $defaults = self::default_settings();
        $settings = get_option(self::OPTION_NAME, array());
        return self::sanitize_settings(wp_parse_args(is_array($settings) ? $settings : array(), $defaults), $defaults);
    }

    public static function sanitize_settings($settings, $defaults = null) {
        $defaults = is_array($defaults) ? $defaults : self::default_settings();
        $settings = is_array($settings) ? $settings : array();
        $api_secret = isset($settings['api_secret']) ? (string) $settings['api_secret'] : '';
        if ($api_secret !== '' && strpos($api_secret, Weather_Worm_Client::ENCRYPTION_PREFIX) !== 0) {
            $api_secret = Weather_Worm_Client::encrypt_secret($api_secret);
        }

        $station_id = isset($settings['default_station_id']) ? absint($settings['default_station_id']) : absint($defaults['default_station_id']);
        if ($station_id <= 0) {
            $station_id = absint($defaults['default_station_id']);
        }

        $cache_ttl = isset($settings['cache_ttl']) ? absint($settings['cache_ttl']) : absint($defaults['cache_ttl']);
        $cache_ttl = min(3600, max(60, $cache_ttl));

        return array(
            'api_key' => isset($settings['api_key']) ? sanitize_text_field((string) $settings['api_key']) : '',
            'api_secret' => $api_secret,
            'default_station_id' => $station_id,
            'default_station_label' => isset($settings['default_station_label']) && trim((string) $settings['default_station_label']) !== ''
                ? sanitize_text_field((string) $settings['default_station_label'])
                : (string) $defaults['default_station_label'],
            'cache_ttl' => $cache_ttl,
            'shortcodes' => self::sanitize_shortcodes(isset($settings['shortcodes']) ? $settings['shortcodes'] : array(), $defaults),
        );
    }

    private static function sanitize_shortcodes($shortcodes, $defaults) {
        $shortcodes = is_array($shortcodes) ? $shortcodes : array();
        $sanitized = array();
        foreach ($shortcodes as $key => $config) {
            $config = self::sanitize_shortcode_config($config, $key, $defaults);
            if (!empty($config['id'])) {
                $sanitized[$config['id']] = $config;
            }
        }

        return $sanitized;
    }

    private static function sanitize_shortcode_config($config, $fallback_id, $defaults) {
        $config = is_array($config) ? $config : array();
        $id = isset($config['id']) ? sanitize_title((string) $config['id']) : sanitize_title((string) $fallback_id);
        if ($id === '') {
            return array();
        }

        $station_id = isset($config['station_id']) ? absint($config['station_id']) : absint($defaults['default_station_id']);
        if ($station_id <= 0) {
            $station_id = absint($defaults['default_station_id']);
        }

        $allowed_metrics = array_keys(self::available_metrics());
        $metrics = isset($config['metrics']) && is_array($config['metrics']) ? $config['metrics'] : self::default_metric_keys();
        $metrics = array_values(array_intersect($allowed_metrics, array_map('sanitize_key', $metrics)));
        if (empty($metrics)) {
            $metrics = self::default_metric_keys();
        }

        return array(
            'id' => $id,
            'title' => isset($config['title']) && trim((string) $config['title']) !== ''
                ? sanitize_text_field((string) $config['title'])
                : __('Current Weather', 'weather-worm'),
            'station_id' => $station_id,
            'station_label' => isset($config['station_label']) && trim((string) $config['station_label']) !== ''
                ? sanitize_text_field((string) $config['station_label'])
                : (string) $defaults['default_station_label'],
            'sensor_lsid' => isset($config['sensor_lsid']) ? absint($config['sensor_lsid']) : 0,
            'show_station' => !empty($config['show_station']) ? 1 : 0,
            'metrics' => $metrics,
        );
    }

    public function register_admin_menu() {
        add_menu_page(
            __('Weather Worm', 'weather-worm'),
            __('Weather Worm', 'weather-worm'),
            'manage_options',
            'weather-worm',
            array($this, 'render_admin_page'),
            WEATHER_WORM_PLUGIN_URL . 'assets/icon.svg',
            59
        );

        add_submenu_page('weather-worm', __('Overview', 'weather-worm'), __('Overview', 'weather-worm'), 'manage_options', 'weather-worm', array($this, 'render_admin_page'));
        add_submenu_page('weather-worm', __('Weather Worm Shortcodes', 'weather-worm'), __('Shortcodes', 'weather-worm'), 'manage_options', 'weather-worm-shortcodes', array($this, 'render_admin_page'));
        add_submenu_page('weather-worm', __('Weather Worm Settings', 'weather-worm'), __('Settings', 'weather-worm'), 'manage_options', 'weather-worm-settings', array($this, 'render_admin_page'));
        add_submenu_page('weather-worm', __('About Weather Worm', 'weather-worm'), __('About', 'weather-worm'), 'manage_options', 'weather-worm-about', array($this, 'render_admin_page'));
    }

    public function enqueue_admin_assets($hook_suffix) {
        wp_enqueue_style(
            'weather-worm-menu-icon',
            WEATHER_WORM_PLUGIN_URL . 'assets/css/menu-icon.css',
            array(),
            self::VERSION
        );

        if (strpos((string) $hook_suffix, 'weather-worm') === false) {
            return;
        }

        wp_enqueue_style(
            'weather-worm-fontawesome',
            WEATHER_WORM_PLUGIN_URL . 'assets/fontawesome/css/all.min.css',
            array(),
            self::VERSION
        );
        wp_enqueue_style(
            'weather-worm-admin',
            WEATHER_WORM_PLUGIN_URL . 'assets/css/admin.css',
            array('weather-worm-fontawesome'),
            self::VERSION
        );
        wp_enqueue_script(
            'weather-worm-admin',
            WEATHER_WORM_PLUGIN_URL . 'assets/js/admin.js',
            array(),
            self::VERSION,
            true
        );
    }

    public function enqueue_frontend_assets() {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style(
            'weather-worm-frontend',
            WEATHER_WORM_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            self::VERSION
        );
    }

    public function filter_admin_footer_text($footer_text) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (strpos($page, 'weather-worm') !== 0) {
            return $footer_text;
        }

        return '';
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = $this->get_current_admin_tab();

        echo '<div class="wrap weather-worm-wrap">';
        echo '<h1><span class="weather-worm-heading-icon" aria-hidden="true"></span>' . esc_html__('Weather Worm', 'weather-worm') . '</h1>';
        $this->render_admin_notice();
        $this->render_admin_tabs($tab);

        switch ($tab) {
            case 'shortcodes':
                $this->render_shortcodes_tab();
                break;
            case 'settings':
                $this->render_settings_tab();
                break;
            case 'about':
                $this->render_about_tab();
                break;
            case 'overview':
            default:
                $this->render_overview_tab();
                break;
        }

        echo '</div>';
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array('id' => 'stone-tower-current'), $atts, 'weather_worm');
        $id = sanitize_title((string) $atts['id']);
        $shortcodes = isset($this->settings['shortcodes']) && is_array($this->settings['shortcodes']) ? $this->settings['shortcodes'] : array();
        $config = isset($shortcodes[$id]) ? $shortcodes[$id] : null;

        if (empty($config)) {
            return $this->render_frontend_message(__('Weather data is not configured yet.', 'weather-worm'));
        }

        $current = $this->client->get_current($config['station_id']);
        if (is_wp_error($current)) {
            return $this->render_frontend_message(__('Weather data is temporarily unavailable.', 'weather-worm'));
        }

        $normalized = $this->client->normalize_current($current, $config);
        if (empty($normalized['metrics'])) {
            return $this->render_frontend_message(__('Weather data is temporarily unavailable.', 'weather-worm'));
        }

        return $this->render_weather_card($normalized, $config, false);
    }

    public function render_named_value_shortcode($atts, $content = null, $tag = '') {
        $definitions = self::raw_value_shortcodes();
        $definition = isset($definitions[$tag]) ? $definitions[$tag] : array('metric' => 'temp', 'format' => 'value');
        $atts = is_array($atts) ? $atts : array();
        $atts['metric'] = $definition['metric'];
        $has_format = isset($atts['format']) && trim((string) $atts['format']) !== '';
        $has_value_alias = isset($atts['value']) && trim((string) $atts['value']) !== '';
        if (!$has_format && !$has_value_alias) {
            $atts['format'] = $definition['format'];
        }

        return $this->render_value_shortcode($atts, $content, $tag);
    }

    public function render_value_shortcode($atts, $content = null, $tag = '') {
        $atts = shortcode_atts(
            array(
                'id' => 'stone-tower-current',
                'metric' => 'temp',
                'format' => 'value',
                'value' => '',
                'decimals' => '',
                'fallback' => '',
            ),
            $atts,
            $tag !== '' ? $tag : 'weather_worm_value'
        );
        if ($atts['value'] !== '' && $atts['format'] === 'value') {
            $atts['format'] = $atts['value'];
        }

        $config = $this->get_shortcode_config($atts['id']);
        if (is_wp_error($config)) {
            return esc_html((string) $atts['fallback']);
        }

        $current = $this->client->get_current($config['station_id']);
        if (is_wp_error($current)) {
            return esc_html((string) $atts['fallback']);
        }

        $normalized = $this->client->normalize_current($current, $config);
        $value = $this->get_raw_shortcode_value(
            $this->normalize_metric_key($atts['metric']),
            sanitize_key((string) $atts['format']),
            $atts,
            $normalized,
            $config
        );

        if ($value === null || $value === '') {
            return esc_html((string) $atts['fallback']);
        }

        return esc_html((string) $value);
    }

    public function handle_save_settings() {
        $this->assert_admin_post('weather_worm_save_settings_action');

        $current = self::get_settings();
        $secret = isset($current['api_secret']) ? (string) $current['api_secret'] : '';
        if (!empty($_POST['clear_api_secret'])) {
            $secret = '';
        } elseif (isset($_POST['api_secret']) && trim((string) wp_unslash($_POST['api_secret'])) !== '') {
            $secret = Weather_Worm_Client::encrypt_secret(sanitize_text_field(wp_unslash($_POST['api_secret'])));
        }

        $updated = $current;
        $updated['api_key'] = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : $current['api_key'];
        $updated['api_secret'] = $secret;
        $updated['default_station_id'] = isset($_POST['default_station_id']) ? absint($_POST['default_station_id']) : $current['default_station_id'];
        $updated['default_station_label'] = isset($_POST['default_station_label']) ? sanitize_text_field(wp_unslash($_POST['default_station_label'])) : $current['default_station_label'];
        $updated['cache_ttl'] = isset($_POST['cache_ttl']) ? absint($_POST['cache_ttl']) : $current['cache_ttl'];

        $updated = self::sanitize_settings($updated);
        update_option(self::OPTION_NAME, $updated, false);
        Weather_Worm_Client::clear_cache();

        $this->redirect_with_result($this->admin_tab_url('settings'), true, __('Settings saved.', 'weather-worm'));
    }

    public function handle_test_connection() {
        $this->assert_admin_post('weather_worm_test_connection_action');

        $client = new Weather_Worm_Client(self::get_settings());
        $stations = $client->get_stations(false);
        if (is_wp_error($stations)) {
            $this->redirect_with_result($this->admin_tab_url('settings'), false, $stations->get_error_message());
        }

        $settings = self::get_settings();
        $current = $client->get_current($settings['default_station_id'], false);
        if (is_wp_error($current)) {
            $this->redirect_with_result($this->admin_tab_url('settings'), false, $current->get_error_message());
        }

        $normalized = $client->normalize_current($current);
        $station_count = isset($stations['stations']) && is_array($stations['stations']) ? count($stations['stations']) : 0;
        $metric_count = isset($normalized['metrics']) && is_array($normalized['metrics']) ? count($normalized['metrics']) : 0;
        $message = sprintf(
            /* translators: 1: station count, 2: metric count */
            __('WeatherLink connection OK. Found %1$d station(s) and %2$d current metric(s).', 'weather-worm'),
            $station_count,
            $metric_count
        );

        $this->redirect_with_result($this->admin_tab_url('settings'), true, $message);
    }

    public function handle_clear_cache() {
        $this->assert_admin_post('weather_worm_clear_cache_action');
        Weather_Worm_Client::clear_cache();
        $this->redirect_with_result($this->admin_tab_url('settings'), true, __('Weather Worm cache cleared.', 'weather-worm'));
    }

    public function handle_save_shortcode() {
        $this->assert_admin_post('weather_worm_save_shortcode_action');

        $settings = self::get_settings();
        $shortcodes = isset($settings['shortcodes']) && is_array($settings['shortcodes']) ? $settings['shortcodes'] : array();
        $original_id = isset($_POST['original_id']) ? sanitize_title(wp_unslash($_POST['original_id'])) : '';
        $id = isset($_POST['shortcode_id']) ? sanitize_title(wp_unslash($_POST['shortcode_id'])) : '';
        if ($id === '') {
            $this->redirect_with_result($this->admin_tab_url('shortcodes'), false, __('Shortcode ID is required.', 'weather-worm'));
        }

        if (($original_id === '' || $original_id !== $id) && isset($shortcodes[$id])) {
            $this->redirect_with_result($this->admin_tab_url('shortcodes'), false, __('That shortcode ID already exists.', 'weather-worm'));
        }

        $config = self::sanitize_shortcode_config(
            array(
                'id' => $id,
                'title' => isset($_POST['title']) ? wp_unslash($_POST['title']) : '',
                'station_id' => isset($_POST['station_id']) ? $_POST['station_id'] : $settings['default_station_id'],
                'station_label' => isset($_POST['station_label']) ? wp_unslash($_POST['station_label']) : '',
                'sensor_lsid' => isset($_POST['sensor_lsid']) ? $_POST['sensor_lsid'] : 0,
                'show_station' => isset($_POST['show_station']) ? 1 : 0,
                'metrics' => isset($_POST['metrics']) && is_array($_POST['metrics']) ? wp_unslash($_POST['metrics']) : array(),
            ),
            $id,
            $settings
        );

        if ($original_id !== '' && $original_id !== $config['id']) {
            unset($shortcodes[$original_id]);
        }

        $shortcodes[$config['id']] = $config;
        $settings['shortcodes'] = $shortcodes;
        update_option(self::OPTION_NAME, self::sanitize_settings($settings), false);
        Weather_Worm_Client::clear_cache();

        $this->redirect_with_result($this->admin_tab_url('shortcodes'), true, __('Shortcode saved.', 'weather-worm'));
    }

    public function handle_delete_shortcode() {
        $this->assert_admin_post('weather_worm_delete_shortcode_action');

        $settings = self::get_settings();
        $id = isset($_POST['shortcode_id']) ? sanitize_title(wp_unslash($_POST['shortcode_id'])) : '';
        if ($id !== '' && isset($settings['shortcodes'][$id])) {
            unset($settings['shortcodes'][$id]);
            update_option(self::OPTION_NAME, self::sanitize_settings($settings), false);
            Weather_Worm_Client::clear_cache();
        }

        $this->redirect_with_result($this->admin_tab_url('shortcodes'), true, __('Shortcode deleted.', 'weather-worm'));
    }

    private function render_overview_tab() {
        $shortcodes = isset($this->settings['shortcodes']) && is_array($this->settings['shortcodes']) ? $this->settings['shortcodes'] : array();
        $stations = $this->client->is_configured() ? $this->client->get_stations(true) : new WP_Error('weather_worm_not_configured', __('WeatherLink is not configured.', 'weather-worm'));
        $current = $this->client->is_configured() ? $this->client->get_current($this->settings['default_station_id'], true) : $stations;
        $station_label = $this->settings['default_station_label'];

        echo '<div class="weather-worm-grid weather-worm-grid-overview">';
        $this->render_metric_card(__('API', 'weather-worm'), $this->client->is_configured() ? __('Configured', 'weather-worm') : __('Missing', 'weather-worm'), 'fa-plug-circle-check');
        $this->render_metric_card(__('Default Station', 'weather-worm'), (string) $this->settings['default_station_id'], 'fa-tower-broadcast');
        $this->render_metric_card(__('Shortcodes', 'weather-worm'), (string) count($shortcodes), 'fa-brackets-square');
        echo '</div>';

        echo '<div class="weather-worm-card">';
        echo '<h2><i class="fa-duotone fa-cloud-sun" aria-hidden="true"></i> ' . esc_html__('Current Conditions Preview', 'weather-worm') . '</h2>';
        if (is_wp_error($current)) {
            echo '<p class="description">' . esc_html($current->get_error_message()) . '</p>';
        } else {
            $config = array(
                'id' => 'overview',
                'title' => __('Current Weather', 'weather-worm'),
                'station_id' => $this->settings['default_station_id'],
                'station_label' => $station_label,
                'sensor_lsid' => 0,
                'show_station' => 1,
                'metrics' => self::default_metric_keys(),
            );
            echo $this->render_weather_card($this->client->normalize_current($current, $config), $config, true);
        }
        echo '</div>';

        echo '<div class="weather-worm-card">';
        echo '<h2><i class="fa-duotone fa-location-dot" aria-hidden="true"></i> ' . esc_html__('Station Access', 'weather-worm') . '</h2>';
        if (is_wp_error($stations)) {
            echo '<p class="description">' . esc_html($stations->get_error_message()) . '</p>';
        } else {
            $this->render_stations_table(isset($stations['stations']) ? $stations['stations'] : array());
        }
        echo '</div>';
    }

    private function render_shortcodes_tab() {
        $shortcodes = isset($this->settings['shortcodes']) && is_array($this->settings['shortcodes']) ? $this->settings['shortcodes'] : array();

        echo '<div class="weather-worm-card">';
        echo '<h2><i class="fa-duotone fa-brackets-square" aria-hidden="true"></i> ' . esc_html__('Shortcodes', 'weather-worm') . '</h2>';
        if (empty($shortcodes)) {
            echo '<p class="description">' . esc_html__('No shortcode configurations exist yet.', 'weather-worm') . '</p>';
        }

        foreach ($shortcodes as $config) {
            $this->render_shortcode_editor($config, false);
        }
        echo '</div>';

        echo '<div class="weather-worm-card">';
        echo '<h2><i class="fa-duotone fa-circle-plus" aria-hidden="true"></i> ' . esc_html__('Add Shortcode', 'weather-worm') . '</h2>';
        $this->render_shortcode_editor(
            array(
                'id' => '',
                'title' => __('Current Weather', 'weather-worm'),
                'station_id' => $this->settings['default_station_id'],
                'station_label' => $this->settings['default_station_label'],
                'sensor_lsid' => 0,
                'show_station' => 1,
                'metrics' => self::default_metric_keys(),
            ),
            true
        );
        echo '</div>';
    }

    private function render_settings_tab() {
        echo '<div class="weather-worm-card">';
        echo '<h2><i class="fa-duotone fa-gear" aria-hidden="true"></i> ' . esc_html__('Settings', 'weather-worm') . '</h2>';
        echo '<div class="weather-worm-breakdown-grid">';
        $this->render_breakdown_item(__('API key', 'weather-worm'), $this->settings['api_key'] !== '' ? __('Saved', 'weather-worm') : __('Missing', 'weather-worm'));
        $this->render_breakdown_item(__('API secret', 'weather-worm'), Weather_Worm_Client::decrypt_secret($this->settings['api_secret']) !== '' ? __('Saved', 'weather-worm') : __('Missing', 'weather-worm'));
        $this->render_breakdown_item(__('Default station', 'weather-worm'), (string) $this->settings['default_station_id']);
        $this->render_breakdown_item(__('Cache TTL', 'weather-worm'), sprintf(
            /* translators: %d: cache seconds */
            __('%d seconds', 'weather-worm'),
            (int) $this->settings['cache_ttl']
        ));
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="weather-worm-settings-form">';
        wp_nonce_field('weather_worm_save_settings_action', 'weather_worm_nonce');
        echo '<input type="hidden" name="action" value="weather_worm_save_settings" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->render_text_input_row('api_key', __('WeatherLink API key', 'weather-worm'), $this->settings['api_key'], 'fa-key', 'text', __('Paste your WeatherLink v2 API key.', 'weather-worm'));
        $this->render_secret_input_row();
        $this->render_text_input_row('default_station_id', __('Default station ID', 'weather-worm'), (string) $this->settings['default_station_id'], 'fa-tower-broadcast', 'number', __('Used by new shortcodes and the Overview preview.', 'weather-worm'));
        $this->render_text_input_row('default_station_label', __('Default station label', 'weather-worm'), $this->settings['default_station_label'], 'fa-location-dot', 'text', __('Human-friendly station name displayed on cards.', 'weather-worm'));
        $this->render_text_input_row('cache_ttl', __('Cache duration', 'weather-worm'), (string) $this->settings['cache_ttl'], 'fa-clock', 'number', __('Seconds to cache WeatherLink responses. Values are clamped between 60 and 3600.', 'weather-worm'));
        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary"><i class="fa-duotone fa-floppy-disk" aria-hidden="true"></i> ' . esc_html__('Save Settings', 'weather-worm') . '</button></p>';
        echo '</form>';

        echo '<div class="weather-worm-inline-actions">';
        $this->render_simple_action_form('weather_worm_test_connection', 'weather_worm_test_connection_action', __('Test Connection', 'weather-worm'), 'fa-plug-circle-check');
        $this->render_simple_action_form('weather_worm_clear_cache', 'weather_worm_clear_cache_action', __('Clear Cache', 'weather-worm'), 'fa-arrows-rotate');
        echo '</div>';
        echo '</div>';
    }

    private function render_about_tab() {
        echo '<div class="weather-worm-card">';
        echo '<h2><i class="fa-duotone fa-circle-info" aria-hidden="true"></i> ' . esc_html__('About Weather Worm', 'weather-worm') . '</h2>';
        echo '<table class="widefat striped weather-worm-about-table"><tbody>';
        $this->render_about_row(__('Version', 'weather-worm'), WEATHER_WORM_VERSION);
        $this->render_about_row(__('Option', 'weather-worm'), self::OPTION_NAME);
        $this->render_about_row(__('Default shortcode', 'weather-worm'), '[weather_worm id="stone-tower-current"]');
        $this->render_about_row(__('Plugin directory', 'weather-worm'), WEATHER_WORM_PLUGIN_DIR);
        $this->render_about_row(__('WordPress', 'weather-worm'), function_exists('get_bloginfo') ? get_bloginfo('version') : '');
        echo '</tbody></table>';
        echo '<p class="description">' . wp_kses_post(sprintf(
            /* translators: %s: WeatherLink docs URL */
            __('WeatherLink requests use the v2 current conditions API documented at %s.', 'weather-worm'),
            '<a href="https://weatherlink.github.io/v2-api/" target="_blank" rel="noopener noreferrer">weatherlink.github.io/v2-api</a>'
        )) . '</p>';
        echo '</div>';
    }

    private function render_admin_tabs($active_tab) {
        echo '<nav class="nav-tab-wrapper weather-worm-nav-tabs">';
        foreach ($this->get_admin_tabs() as $tab_key => $tab) {
            $classes = array('nav-tab');
            if ($tab_key === $active_tab) {
                $classes[] = 'nav-tab-active';
            }

            echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($this->admin_tab_url($tab_key)) . '">';
            echo '<i class="fa-duotone ' . esc_attr($tab['icon']) . '" aria-hidden="true"></i> ';
            echo esc_html($tab['label']);
            echo '</a>';
        }
        echo '</nav>';
    }

    private function get_admin_tabs() {
        return array(
            'overview' => array('label' => __('Overview', 'weather-worm'), 'icon' => 'fa-chart-line'),
            'shortcodes' => array('label' => __('Shortcodes', 'weather-worm'), 'icon' => 'fa-brackets-square'),
            'settings' => array('label' => __('Settings', 'weather-worm'), 'icon' => 'fa-gear'),
            'about' => array('label' => __('About', 'weather-worm'), 'icon' => 'fa-circle-info'),
        );
    }

    private function get_current_admin_tab() {
        $tabs = $this->get_admin_tabs();
        $requested_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($requested_tab !== '' && isset($tabs[$requested_tab])) {
            return $requested_tab;
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'weather-worm';
        $page_map = array(
            'weather-worm' => 'overview',
            'weather-worm-shortcodes' => 'shortcodes',
            'weather-worm-settings' => 'settings',
            'weather-worm-about' => 'about',
        );

        return isset($page_map[$page]) ? $page_map[$page] : 'overview';
    }

    private function admin_tab_url($tab) {
        return add_query_arg(array('page' => 'weather-worm', 'tab' => sanitize_key($tab)), admin_url('admin.php'));
    }

    private function render_admin_notice() {
        if (!isset($_GET['weather_worm_message'])) {
            return;
        }

        $success = isset($_GET['weather_worm_result']) && sanitize_key(wp_unslash($_GET['weather_worm_result'])) === 'success';
        $message = sanitize_text_field(wp_unslash($_GET['weather_worm_message']));
        echo '<div class="notice ' . esc_attr($success ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function render_metric_card($label, $value, $icon) {
        echo '<div class="weather-worm-card weather-worm-metric">';
        echo '<h2><i class="fa-duotone ' . esc_attr($icon) . '" aria-hidden="true"></i> ' . esc_html($label) . '</h2>';
        echo '<p class="weather-worm-metric-value">' . esc_html($value) . '</p>';
        echo '</div>';
    }

    private function render_stations_table($stations) {
        if (empty($stations)) {
            echo '<p class="description">' . esc_html__('No stations were returned for this API key.', 'weather-worm') . '</p>';
            return;
        }

        echo '<div class="weather-worm-table-wrap"><table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Station', 'weather-worm') . '</th>';
        echo '<th>' . esc_html__('ID', 'weather-worm') . '</th>';
        echo '<th>' . esc_html__('Location', 'weather-worm') . '</th>';
        echo '<th>' . esc_html__('Status', 'weather-worm') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($stations as $station) {
            $name = isset($station['station_name']) ? (string) $station['station_name'] : '';
            $id = isset($station['station_id']) ? absint($station['station_id']) : 0;
            $location = trim(implode(', ', array_filter(array(
                isset($station['city']) ? (string) $station['city'] : '',
                isset($station['region']) ? (string) $station['region'] : '',
            ))));
            $active = !empty($station['active']);

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td>' . esc_html((string) $id) . '</td>';
            echo '<td>' . esc_html($location) . '</td>';
            echo '<td><span class="weather-worm-status-pill ' . esc_attr($active ? 'weather-worm-status-ok' : 'weather-worm-status-muted') . '">' . esc_html($active ? __('Active', 'weather-worm') : __('Inactive', 'weather-worm')) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_shortcode_editor($config, $is_new) {
        $config = is_array($config) ? $config : array();
        $id = isset($config['id']) ? (string) $config['id'] : '';
        $metrics = isset($config['metrics']) && is_array($config['metrics']) ? $config['metrics'] : self::default_metric_keys();

        echo '<div class="weather-worm-shortcode-editor">';
        if (!$is_new) {
            echo '<div class="weather-worm-shortcode-topline">';
            echo '<code>[weather_worm id="' . esc_attr($id) . '"]</code>';
            echo '<button type="button" class="button weather-worm-copy-shortcode" data-shortcode="' . esc_attr('[weather_worm id="' . $id . '"]') . '"><i class="fa-duotone fa-clipboard" aria-hidden="true"></i> ' . esc_html__('Copy', 'weather-worm') . '</button>';
            echo '</div>';
            $this->render_raw_shortcode_examples($id);
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('weather_worm_save_shortcode_action', 'weather_worm_nonce');
        echo '<input type="hidden" name="action" value="weather_worm_save_shortcode" />';
        echo '<input type="hidden" name="original_id" value="' . esc_attr($id) . '" />';
        echo '<div class="weather-worm-shortcode-grid">';
        $this->render_editor_field('shortcode_id', __('Shortcode ID', 'weather-worm'), $id, 'fa-hashtag', 'text', __('Lowercase slug used in the shortcode.', 'weather-worm'));
        $this->render_editor_field('title', __('Title', 'weather-worm'), isset($config['title']) ? (string) $config['title'] : '', 'fa-heading', 'text', '');
        $this->render_editor_field('station_id', __('Station ID', 'weather-worm'), isset($config['station_id']) ? (string) $config['station_id'] : '', 'fa-tower-broadcast', 'number', '');
        $this->render_editor_field('station_label', __('Station Label', 'weather-worm'), isset($config['station_label']) ? (string) $config['station_label'] : '', 'fa-location-dot', 'text', '');
        $this->render_editor_field('sensor_lsid', __('Sensor LSID', 'weather-worm'), !empty($config['sensor_lsid']) ? (string) $config['sensor_lsid'] : '', 'fa-microchip', 'number', __('Optional. Leave blank to auto-pick the best current weather sensor.', 'weather-worm'));
        echo '</div>';

        echo '<div class="weather-worm-checkbox-row">';
        echo '<label><input type="checkbox" name="show_station" value="1"' . checked(!empty($config['show_station']), true, false) . ' /> ' . esc_html__('Show station label', 'weather-worm') . '</label>';
        echo '</div>';

        echo '<fieldset class="weather-worm-metric-picker"><legend>' . esc_html__('Metrics', 'weather-worm') . '</legend>';
        foreach (self::available_metrics() as $metric_key => $metric) {
            echo '<label><input type="checkbox" name="metrics[]" value="' . esc_attr($metric_key) . '"' . checked(in_array($metric_key, $metrics, true), true, false) . ' /> ';
            echo '<i class="fa-duotone ' . esc_attr($metric['icon']) . '" aria-hidden="true"></i> ';
            echo esc_html($metric['label']) . '</label>';
        }
        echo '</fieldset>';

        echo '<p class="submit"><button type="submit" class="button button-primary"><i class="fa-duotone fa-floppy-disk" aria-hidden="true"></i> ' . esc_html($is_new ? __('Add Shortcode', 'weather-worm') : __('Save Shortcode', 'weather-worm')) . '</button></p>';
        echo '</form>';

        if (!$is_new) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="weather-worm-delete-form">';
            wp_nonce_field('weather_worm_delete_shortcode_action', 'weather_worm_nonce');
            echo '<input type="hidden" name="action" value="weather_worm_delete_shortcode" />';
            echo '<input type="hidden" name="shortcode_id" value="' . esc_attr($id) . '" />';
            echo '<button type="submit" class="button weather-worm-button-danger" data-confirm="' . esc_attr__('Delete this shortcode configuration?', 'weather-worm') . '"><i class="fa-duotone fa-trash" aria-hidden="true"></i> ' . esc_html__('Delete', 'weather-worm') . '</button>';
            echo '</form>';
        }
        echo '</div>';
    }

    private function render_raw_shortcode_examples($id) {
        $examples = array(
            '[weather_worm_temperature id="' . $id . '"]',
            '[weather_worm_humidity id="' . $id . '"]',
            '[weather_worm_wind_speed id="' . $id . '"]',
            '[weather_worm_wind_direction id="' . $id . '"]',
            '[weather_worm_rain_today id="' . $id . '"]',
            '[weather_worm_value id="' . $id . '" metric="barometer" format="display"]',
        );

        echo '<div class="weather-worm-raw-examples">';
        echo '<strong>' . esc_html__('Raw value examples', 'weather-worm') . '</strong>';
        echo '<ul>';
        foreach ($examples as $example) {
            echo '<li><code>' . esc_html($example) . '</code></li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    private function render_editor_field($name, $label, $value, $icon, $type, $description) {
        echo '<label class="weather-worm-editor-field">';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<span class="weather-worm-input-decor weather-worm-input-wide weather-worm-tone-search">';
        echo '<span class="weather-worm-input-icon"><i class="fa-duotone ' . esc_attr($icon) . '" aria-hidden="true"></i></span>';
        echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" autocomplete="off" />';
        echo '</span>';
        if ($description !== '') {
            echo '<small>' . esc_html($description) . '</small>';
        }
        echo '</label>';
    }

    private function render_text_input_row($name, $label, $value, $icon, $type, $description) {
        echo '<tr><th scope="row"><label for="weather_worm_' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<span class="weather-worm-input-decor weather-worm-input-wide weather-worm-tone-search">';
        echo '<span class="weather-worm-input-icon"><i class="fa-duotone ' . esc_attr($icon) . '" aria-hidden="true"></i></span>';
        echo '<input id="weather_worm_' . esc_attr($name) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" autocomplete="off" />';
        echo '</span>';
        if ($description !== '') {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function render_secret_input_row() {
        $has_secret = Weather_Worm_Client::decrypt_secret($this->settings['api_secret']) !== '';
        echo '<tr><th scope="row"><label for="weather_worm_api_secret">' . esc_html__('WeatherLink API secret', 'weather-worm') . '</label></th><td>';
        echo '<span class="weather-worm-input-decor weather-worm-input-wide weather-worm-tone-secret">';
        echo '<span class="weather-worm-input-icon"><i class="fa-duotone fa-lock" aria-hidden="true"></i></span>';
        echo '<input id="weather_worm_api_secret" type="password" name="api_secret" value="" autocomplete="new-password" placeholder="' . esc_attr($has_secret ? __('Leave blank to keep saved secret', 'weather-worm') : __('Paste your WeatherLink API secret', 'weather-worm')) . '" />';
        echo '</span>';
        if ($has_secret) {
            echo '<p><label><input type="checkbox" name="clear_api_secret" value="1" /> ' . esc_html__('Clear saved secret', 'weather-worm') . '</label></p>';
        }
        echo '</td></tr>';
    }

    private function render_breakdown_item($label, $value) {
        echo '<div class="weather-worm-breakdown-item">';
        echo '<span>' . esc_html($label) . '</span>';
        echo '<strong>' . esc_html($value) . '</strong>';
        echo '</div>';
    }

    private function render_simple_action_form($action, $nonce_action, $label, $icon) {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field($nonce_action, 'weather_worm_nonce');
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '" />';
        echo '<button type="submit" class="button"><i class="fa-duotone ' . esc_attr($icon) . '" aria-hidden="true"></i> ' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private function render_about_row($label, $value) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><code>' . esc_html((string) $value) . '</code></td></tr>';
    }

    private function get_shortcode_config($id) {
        $id = sanitize_title((string) $id);
        if ($id === '') {
            $id = 'stone-tower-current';
        }

        $shortcodes = isset($this->settings['shortcodes']) && is_array($this->settings['shortcodes']) ? $this->settings['shortcodes'] : array();
        if (!isset($shortcodes[$id]) || !is_array($shortcodes[$id])) {
            return new WP_Error(
                'weather_worm_missing_shortcode_config',
                __('Weather Worm shortcode configuration was not found.', 'weather-worm')
            );
        }

        return $shortcodes[$id];
    }

    private function normalize_metric_key($metric) {
        $metric = sanitize_key((string) $metric);
        $aliases = array(
            'temperature' => 'temp',
            'outside_temp' => 'temp',
            'humidity' => 'hum',
            'wind_speed' => 'wind',
            'wind_direction' => 'wind',
            'wind_dir' => 'wind',
            'rain' => 'rain_today',
            'rainfall' => 'rain_today',
            'daily_rain' => 'rain_today',
            'current_rain' => 'rain_rate',
            'pressure' => 'barometer',
            'bar' => 'barometer',
            'dewpoint' => 'dew_point',
            'updated' => 'last_updated',
            'timestamp' => 'last_updated',
            'station' => 'station_label',
            'station_name' => 'station_label',
        );

        return isset($aliases[$metric]) ? $aliases[$metric] : $metric;
    }

    private function get_raw_shortcode_value($metric_key, $format, $atts, $normalized, $config) {
        if ($metric_key === 'station_label') {
            return isset($config['station_label']) ? (string) $config['station_label'] : '';
        }

        if ($metric_key === 'station_id') {
            return isset($config['station_id']) ? (string) absint($config['station_id']) : '';
        }

        if ($metric_key === 'sensor_lsid') {
            return isset($normalized['lsid']) && absint($normalized['lsid']) > 0 ? (string) absint($normalized['lsid']) : '';
        }

        $metrics = isset($normalized['metrics']) && is_array($normalized['metrics']) ? $normalized['metrics'] : array();
        if (!isset($metrics[$metric_key]) || !is_array($metrics[$metric_key])) {
            return '';
        }

        $metric = $metrics[$metric_key];
        if ($format === '') {
            $format = 'value';
        }

        if ($format === 'display') {
            if ($metric_key === 'last_updated') {
                return $this->format_timestamp(isset($metric['value']) ? (int) $metric['value'] : 0);
            }

            return isset($metric['display']) ? (string) $metric['display'] : '';
        }

        if ($format === 'time') {
            return $this->format_timestamp(isset($metric['value']) ? (int) $metric['value'] : 0);
        }

        if ($format === 'unit') {
            return isset($metric['unit']) ? (string) $metric['unit'] : '';
        }

        if ($format === 'label') {
            return isset($metric['label']) ? (string) $metric['label'] : '';
        }

        if ($format === 'direction') {
            return isset($metric['direction_label']) ? (string) $metric['direction_label'] : '';
        }

        if ($format === 'direction_degrees') {
            return isset($metric['direction']) ? $this->format_raw_number($metric['direction'], $atts) : '';
        }

        if ($metric_key === 'last_updated') {
            return isset($metric['value']) ? (string) absint($metric['value']) : '';
        }

        return isset($metric['value']) ? $this->format_raw_number($metric['value'], $atts) : '';
    }

    private function format_raw_number($value, $atts) {
        if (!is_numeric($value)) {
            return '';
        }

        $decimals = isset($atts['decimals']) && $atts['decimals'] !== '' ? absint($atts['decimals']) : null;
        if ($decimals !== null) {
            return number_format_i18n((float) $value, min(6, $decimals));
        }

        $formatted = rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.');
        return $formatted === '-0' ? '0' : $formatted;
    }

    private function render_weather_card($normalized, $config, $admin_preview) {
        $metrics = isset($normalized['metrics']) && is_array($normalized['metrics']) ? $normalized['metrics'] : array();
        $selected = isset($config['metrics']) && is_array($config['metrics']) ? $config['metrics'] : self::default_metric_keys();
        $title = isset($config['title']) ? (string) $config['title'] : __('Current Weather', 'weather-worm');
        $station_label = isset($config['station_label']) ? (string) $config['station_label'] : '';
        $primary_temp = in_array('temp', $selected, true) && isset($metrics['temp']) ? $metrics['temp'] : null;
        $classes = 'weather-worm-current-card' . ($admin_preview ? ' weather-worm-current-card-admin' : '');

        ob_start();
        echo '<section class="' . esc_attr($classes) . '">';
        echo '<div class="weather-worm-current-header">';
        echo '<div>';
        echo '<h3>' . esc_html($title) . '</h3>';
        if (!empty($config['show_station']) && $station_label !== '') {
            echo '<p>' . esc_html($station_label) . '</p>';
        }
        echo '</div>';
        if ($primary_temp) {
            echo '<div class="weather-worm-current-temp">' . esc_html($primary_temp['display']) . '</div>';
        }
        echo '</div>';

        echo '<dl class="weather-worm-current-metrics">';
        foreach ($selected as $metric_key) {
            if ($metric_key === 'temp' || !isset($metrics[$metric_key])) {
                continue;
            }

            $metric = $metrics[$metric_key];
            $display = $metric_key === 'last_updated'
                ? $this->format_timestamp((int) $metric['value'])
                : (isset($metric['display']) ? (string) $metric['display'] : '');
            if ($display === '') {
                continue;
            }

            echo '<div class="weather-worm-current-metric weather-worm-current-metric-' . esc_attr($metric_key) . '">';
            echo '<dt>' . esc_html(isset($metric['label']) ? $metric['label'] : $metric_key) . '</dt>';
            echo '<dd>' . esc_html($display) . '</dd>';
            echo '</div>';
        }
        echo '</dl>';
        echo '</section>';
        return ob_get_clean();
    }

    private function render_frontend_message($message) {
        return '<div class="weather-worm-current-card weather-worm-current-card-empty">' . esc_html($message) . '</div>';
    }

    private function format_timestamp($timestamp) {
        $timestamp = absint($timestamp);
        if ($timestamp <= 0) {
            return '';
        }

        $format = get_option('date_format') . ' ' . get_option('time_format');
        if (function_exists('wp_date')) {
            return wp_date($format, $timestamp);
        }

        return date_i18n($format, $timestamp);
    }

    private function assert_admin_post($nonce_action) {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'weather-worm'));
        }

        check_admin_referer($nonce_action, 'weather_worm_nonce');
    }

    private function redirect_with_result($url, $success, $message) {
        wp_safe_redirect(add_query_arg(
            array(
                'weather_worm_result' => $success ? 'success' : 'error',
                'weather_worm_message' => (string) $message,
            ),
            $url
        ));
        exit;
    }

    private static function read_local_env_defaults() {
        $path = WEATHER_WORM_PLUGIN_DIR . '.env';
        if (!is_readable($path)) {
            return array();
        }

        $values = array();
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return array();
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = array_map('trim', explode('=', $line, 2));
            $value = trim($value, "\"'");
            if (in_array($key, array('WL_API_KEY', 'WL_API_SECRET', 'WL_STATION'), true)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
