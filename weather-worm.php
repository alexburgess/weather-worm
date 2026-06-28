<?php
/**
 * Plugin Name: Weather Worm
 * Plugin URI: https://example.com/
 * Description: Display WeatherLink current conditions through configurable WordPress shortcodes.
 * Version: 1.0.1
 * Author: Alex Burgess
 * License: GPLv2 or later
 * Text Domain: weather-worm
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WEATHER_WORM_VERSION', '1.0.1');
define('WEATHER_WORM_PLUGIN_FILE', __FILE__);
define('WEATHER_WORM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WEATHER_WORM_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WEATHER_WORM_PLUGIN_DIR . 'includes/class-weather-worm-client.php';
require_once WEATHER_WORM_PLUGIN_DIR . 'includes/class-weather-worm-plugin.php';

function weather_worm_plugin() {
    return Weather_Worm_Plugin::instance();
}

register_activation_hook(WEATHER_WORM_PLUGIN_FILE, array('Weather_Worm_Plugin', 'activate'));
register_deactivation_hook(WEATHER_WORM_PLUGIN_FILE, array('Weather_Worm_Plugin', 'deactivate'));

add_action('plugins_loaded', 'weather_worm_plugin');
