<?php
if (!defined('ABSPATH')) {
    exit;
}

class Weather_Worm_Client {
    const BASE_URL = 'https://api.weatherlink.com/v2';
    const CACHE_INDEX_OPTION = 'weather_worm_cache_keys';
    const ENCRYPTION_PREFIX = 'wwenc:';

    private $settings;

    public function __construct($settings) {
        $this->settings = is_array($settings) ? $settings : array();
    }

    public function is_configured() {
        return $this->get_api_key() !== '' && $this->get_api_secret() !== '';
    }

    public function get_api_key() {
        return isset($this->settings['api_key']) ? trim((string) $this->settings['api_key']) : '';
    }

    public function get_api_secret() {
        $stored_secret = isset($this->settings['api_secret']) ? (string) $this->settings['api_secret'] : '';
        return self::decrypt_secret($stored_secret);
    }

    public function get_cache_ttl() {
        $ttl = isset($this->settings['cache_ttl']) ? absint($this->settings['cache_ttl']) : 300;
        return min(3600, max(60, $ttl));
    }

    public function get_stations($use_cache = true) {
        $cache_key = $this->cache_key('stations', 'all');
        if ($use_cache) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = $this->request('/stations');
        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['stations']) || !is_array($response['stations'])) {
            return new WP_Error(
                'weather_worm_bad_stations_response',
                __('WeatherLink did not return a stations list.', 'weather-worm')
            );
        }

        $stations = array(
            'stations' => array_values($response['stations']),
            'generated_at' => isset($response['generated_at']) ? absint($response['generated_at']) : 0,
        );

        $this->set_cached($cache_key, $stations, $this->get_cache_ttl());
        return $stations;
    }

    public function get_current($station_id, $use_cache = true) {
        $station_id = absint($station_id);
        if ($station_id <= 0) {
            return new WP_Error(
                'weather_worm_missing_station',
                __('A WeatherLink station ID is required.', 'weather-worm')
            );
        }

        $cache_key = $this->cache_key('current', (string) $station_id);
        if ($use_cache) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = $this->request('/current/' . rawurlencode((string) $station_id));
        if (is_wp_error($response)) {
            return $response;
        }

        if (!isset($response['sensors']) || !is_array($response['sensors'])) {
            return new WP_Error(
                'weather_worm_bad_current_response',
                __('WeatherLink did not return current sensor data.', 'weather-worm')
            );
        }

        $this->set_cached($cache_key, $response, $this->get_cache_ttl());
        return $response;
    }

    public function normalize_current($payload, $config = array()) {
        $payload = is_array($payload) ? $payload : array();
        $config = is_array($config) ? $config : array();
        $sensors = isset($payload['sensors']) && is_array($payload['sensors']) ? $payload['sensors'] : array();
        $preferred_lsid = isset($config['sensor_lsid']) ? absint($config['sensor_lsid']) : 0;
        $weather = $this->select_weather_record($sensors, $preferred_lsid);
        $barometer = $this->select_barometer_record($sensors);

        if (empty($weather) && empty($barometer)) {
            return array(
                'station_id' => isset($payload['station_id']) ? absint($payload['station_id']) : 0,
                'station_id_uuid' => isset($payload['station_id_uuid']) ? (string) $payload['station_id_uuid'] : '',
                'generated_at' => isset($payload['generated_at']) ? absint($payload['generated_at']) : 0,
                'lsid' => 0,
                'sensor_type' => 0,
                'data_structure_type' => 0,
                'timestamp' => 0,
                'tz_offset' => null,
                'metrics' => array(),
            );
        }

        $weather_data = isset($weather['data']) && is_array($weather['data']) ? $weather['data'] : array();
        $barometer_data = isset($barometer['data']) && is_array($barometer['data']) ? $barometer['data'] : array();
        $timestamp = max(
            isset($weather_data['ts']) ? absint($weather_data['ts']) : 0,
            isset($barometer_data['ts']) ? absint($barometer_data['ts']) : 0,
            isset($payload['generated_at']) ? absint($payload['generated_at']) : 0
        );

        $metrics = array();
        $this->add_temperature_metric($metrics, 'temp', __('Temperature', 'weather-worm'), $this->first_present($weather_data, array('temp')), 'temperature');
        $this->add_number_metric($metrics, 'hum', __('Humidity', 'weather-worm'), $this->first_present($weather_data, array('hum')), '%', 0, 'humidity');
        $this->add_wind_metric($metrics, $weather_data);
        $this->add_number_metric($metrics, 'rain_today', __('Rain Today', 'weather-worm'), $this->first_present($weather_data, array('rainfall_daily_in')), __('in', 'weather-worm'), 2, 'rain');
        $this->add_number_metric($metrics, 'rain_rate', __('Rain Rate', 'weather-worm'), $this->first_present($weather_data, array('rain_rate_last_in')), __('in/hr', 'weather-worm'), 2, 'rain');
        $this->add_number_metric($metrics, 'barometer', __('Barometer', 'weather-worm'), $this->first_present($barometer_data, array('bar_sea_level', 'bar_absolute')), __('inHg', 'weather-worm'), 2, 'pressure');
        $this->add_temperature_metric($metrics, 'dew_point', __('Dew Point', 'weather-worm'), $this->first_present($weather_data, array('dew_point')), 'temperature');
        $this->add_temperature_metric($metrics, 'heat_index', __('Heat Index', 'weather-worm'), $this->first_present($weather_data, array('heat_index')), 'temperature');
        if ($timestamp > 0) {
            $metrics['last_updated'] = array(
                'key' => 'last_updated',
                'label' => __('Updated', 'weather-worm'),
                'value' => $timestamp,
                'display' => '',
                'unit' => '',
                'kind' => 'time',
            );
        }

        return array(
            'station_id' => isset($payload['station_id']) ? absint($payload['station_id']) : 0,
            'station_id_uuid' => isset($payload['station_id_uuid']) ? (string) $payload['station_id_uuid'] : '',
            'generated_at' => isset($payload['generated_at']) ? absint($payload['generated_at']) : 0,
            'lsid' => isset($weather['lsid']) ? absint($weather['lsid']) : 0,
            'sensor_type' => isset($weather['sensor_type']) ? absint($weather['sensor_type']) : 0,
            'data_structure_type' => isset($weather['data_structure_type']) ? absint($weather['data_structure_type']) : 0,
            'timestamp' => $timestamp,
            'tz_offset' => isset($weather_data['tz_offset']) ? (int) $weather_data['tz_offset'] : null,
            'metrics' => $metrics,
        );
    }

    public static function encrypt_secret($secret) {
        $secret = trim((string) $secret);
        if ($secret === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt') || !function_exists('openssl_cipher_iv_length')) {
            return $secret;
        }

        $cipher = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($cipher);
        if (!$iv_length) {
            return $secret;
        }

        try {
            $iv = function_exists('random_bytes') ? random_bytes($iv_length) : self::fallback_random_bytes($iv_length);
        } catch (\Throwable $e) {
            $iv = self::fallback_random_bytes($iv_length);
        }

        $encrypted = openssl_encrypt($secret, $cipher, self::encryption_key(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return $secret;
        }

        return self::ENCRYPTION_PREFIX . base64_encode($iv . $encrypted);
    }

    public static function decrypt_secret($stored_secret) {
        $stored_secret = (string) $stored_secret;
        if ($stored_secret === '') {
            return '';
        }

        if (strpos($stored_secret, self::ENCRYPTION_PREFIX) !== 0) {
            return $stored_secret;
        }

        if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
            return '';
        }

        $payload = base64_decode(substr($stored_secret, strlen(self::ENCRYPTION_PREFIX)), true);
        $cipher = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($cipher);
        if ($payload === false || !$iv_length || strlen($payload) <= $iv_length) {
            return '';
        }

        $iv = substr($payload, 0, $iv_length);
        $encrypted = substr($payload, $iv_length);
        $decrypted = openssl_decrypt($encrypted, $cipher, self::encryption_key(), OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? '' : (string) $decrypted;
    }

    public static function clear_cache() {
        $keys = get_option(self::CACHE_INDEX_OPTION, array());
        if (is_array($keys)) {
            foreach ($keys as $key) {
                delete_transient((string) $key);
            }
        }

        delete_option(self::CACHE_INDEX_OPTION);
    }

    private function request($path) {
        if (!$this->is_configured()) {
            return new WP_Error(
                'weather_worm_not_configured',
                __('WeatherLink API key and secret are required.', 'weather-worm')
            );
        }

        $url = add_query_arg(
            array('api-key' => $this->get_api_key()),
            rtrim(self::BASE_URL, '/') . '/' . ltrim($path, '/')
        );
        $response = wp_remote_request(
            $url,
            array(
                'method' => 'GET',
                'timeout' => 20,
                'headers' => array(
                    'Accept' => 'application/json',
                    'X-Api-Secret' => $this->get_api_secret(),
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw_body = (string) wp_remote_retrieve_body($response);
        $decoded = $raw_body !== '' ? json_decode($raw_body, true) : array();
        if (!is_array($decoded)) {
            return new WP_Error(
                'weather_worm_bad_json',
                __('WeatherLink returned a response that was not valid JSON.', 'weather-worm'),
                array('status' => $code)
            );
        }

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'weather_worm_api_error',
                $this->format_api_error($decoded, $code),
                array(
                    'status' => $code,
                    'response' => $decoded,
                )
            );
        }

        return $decoded;
    }

    private function format_api_error($decoded, $status_code) {
        $message = '';
        if (isset($decoded['message'])) {
            $message = (string) $decoded['message'];
        } elseif (isset($decoded['error'])) {
            $message = (string) $decoded['error'];
        } elseif (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $message = implode('; ', array_map('strval', $decoded['errors']));
        }

        if ($message === '') {
            $message = sprintf(
                /* translators: %d: HTTP status code */
                __('WeatherLink request failed with HTTP %d.', 'weather-worm'),
                (int) $status_code
            );
        }

        return $message;
    }

    private function cache_key($kind, $scope) {
        return 'weather_worm_' . sanitize_key($kind) . '_' . md5($this->get_api_key() . '|' . (string) $scope);
    }

    private function set_cached($cache_key, $value, $ttl) {
        set_transient($cache_key, $value, $ttl);
        $keys = get_option(self::CACHE_INDEX_OPTION, array());
        $keys = is_array($keys) ? array_map('strval', $keys) : array();
        if (!in_array($cache_key, $keys, true)) {
            $keys[] = $cache_key;
            update_option(self::CACHE_INDEX_OPTION, array_values($keys), false);
        }
    }

    private function select_weather_record($sensors, $preferred_lsid) {
        if ($preferred_lsid > 0) {
            foreach ($sensors as $sensor) {
                if (!is_array($sensor) || absint(isset($sensor['lsid']) ? $sensor['lsid'] : 0) !== $preferred_lsid) {
                    continue;
                }

                $data = $this->first_sensor_data($sensor);
                if (!empty($data)) {
                    $sensor['data'] = $data;
                    return $sensor;
                }
            }
        }

        $best_sensor = array();
        $best_score = 0;
        foreach ($sensors as $sensor) {
            if (!is_array($sensor)) {
                continue;
            }

            $data = $this->first_sensor_data($sensor);
            if (empty($data)) {
                continue;
            }

            $score = $this->score_weather_data($data);
            if ($score > $best_score) {
                $best_score = $score;
                $best_sensor = $sensor;
                $best_sensor['data'] = $data;
            }
        }

        return $best_sensor;
    }

    private function select_barometer_record($sensors) {
        foreach ($sensors as $sensor) {
            if (!is_array($sensor)) {
                continue;
            }

            $data = $this->first_sensor_data($sensor);
            if (empty($data)) {
                continue;
            }

            if ($this->has_value($data, 'bar_sea_level') || $this->has_value($data, 'bar_absolute')) {
                $sensor['data'] = $data;
                return $sensor;
            }
        }

        return array();
    }

    private function first_sensor_data($sensor) {
        if (!isset($sensor['data']) || !is_array($sensor['data']) || empty($sensor['data'])) {
            return array();
        }

        $first = reset($sensor['data']);
        return is_array($first) ? $first : array();
    }

    private function score_weather_data($data) {
        $fields = array(
            'temp',
            'hum',
            'wind_speed_last',
            'wind_speed_avg_last_10_min',
            'wind_dir_last',
            'rainfall_daily_in',
            'rain_rate_last_in',
            'dew_point',
            'heat_index',
        );
        $score = 0;
        foreach ($fields as $field) {
            if ($this->has_value($data, $field)) {
                $score++;
            }
        }

        return $score;
    }

    private function first_present($data, $keys) {
        foreach ($keys as $key) {
            if ($this->has_value($data, $key)) {
                return $data[$key];
            }
        }

        return null;
    }

    private function has_value($data, $key) {
        return is_array($data) && array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '';
    }

    private function add_temperature_metric(&$metrics, $key, $label, $value, $kind) {
        $this->add_number_metric($metrics, $key, $label, $value, 'F', 1, $kind);
    }

    private function add_number_metric(&$metrics, $key, $label, $value, $unit, $decimals, $kind) {
        if ($value === null || $value === '') {
            return;
        }

        $number = (float) $value;
        $display = number_format_i18n($number, $decimals);
        $metrics[$key] = array(
            'key' => $key,
            'label' => $label,
            'value' => $number,
            'display' => $display . ($unit !== '' ? ' ' . $unit : ''),
            'unit' => $unit,
            'kind' => $kind,
        );
    }

    private function add_wind_metric(&$metrics, $data) {
        $speed = $this->first_present($data, array('wind_speed_last', 'wind_speed_avg_last_10_min', 'wind_speed_avg_last_2_min'));
        if ($speed === null || $speed === '') {
            return;
        }

        $direction = $this->first_present($data, array('wind_dir_last', 'wind_dir_scalar_avg_last_10_min', 'wind_dir_scalar_avg_last_2_min'));
        $direction_label = $direction !== null && $direction !== '' ? $this->degrees_to_compass((float) $direction) : '';
        $display = number_format_i18n((float) $speed, 1) . ' ' . __('mph', 'weather-worm');
        if ($direction_label !== '') {
            $display .= ' ' . $direction_label;
        }

        $metrics['wind'] = array(
            'key' => 'wind',
            'label' => __('Wind', 'weather-worm'),
            'value' => (float) $speed,
            'display' => $display,
            'unit' => __('mph', 'weather-worm'),
            'kind' => 'wind',
            'direction' => $direction !== null && $direction !== '' ? (float) $direction : null,
            'direction_label' => $direction_label,
        );
    }

    private function degrees_to_compass($degrees) {
        $directions = array('N', 'NNE', 'NE', 'ENE', 'E', 'ESE', 'SE', 'SSE', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW');
        $normalized = fmod((float) $degrees, 360.0);
        if ($normalized < 0) {
            $normalized += 360.0;
        }
        $index = (int) round($normalized / 22.5);
        return $directions[$index % 16];
    }

    private static function encryption_key() {
        return hash('sha256', wp_salt('auth'), true);
    }

    private static function fallback_random_bytes($length) {
        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(wp_rand(0, 255));
        }

        return $bytes;
    }
}
