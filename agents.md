# Agent Notes

## Project Summary

Weather Worm is a self-contained WordPress plugin that displays WeatherLink v2
current conditions through configurable shortcodes. The default shortcode is:

```text
[weather_worm id="stone-tower-current"]
```

Public plugin identity:

- Plugin name: `Weather Worm`
- Text domain/menu slug: `weather-worm`
- Main plugin file: `weather-worm.php`
- Current version: `1.0.0`
- License: GPLv2 or later

## Repository Layout

- `weather-worm.php`: Plugin header, constants, includes, activation/deactivation
  hooks, and singleton bootstrap.
- `includes/class-weather-worm-plugin.php`: Main plugin class. Owns settings,
  admin pages, form handlers, shortcode CRUD, shortcode rendering, and local
  `.env` prefill on activation.
- `includes/class-weather-worm-client.php`: WeatherLink v2 client. Owns
  credentials, encrypted secret helpers, HTTP requests, transient cache keys,
  cache clearing, current-condition normalization, and visitor-safe metric
  extraction.
- `assets/css/admin.css`: Main admin styling patterned after CreditPigg,
  Loyalty Squirrel, and Catalog Canary.
- `assets/css/menu-icon.css`, `assets/icon.svg`: WordPress sidebar and admin
  heading icon assets.
- `assets/css/frontend.css`: Front-end shortcode card styling.
- `assets/js/admin.js`: Small admin behaviors for decorated inputs, shortcode
  copy buttons, and delete confirmation.
- `assets/fontawesome/`: Bundled Font Awesome CSS/webfonts copied from the
  sibling plugins for admin icons.
- `README.md`: User-facing setup and development notes.

There is no Composer config, npm package, build step, or automated test suite in
this repo.

## Runtime Behavior

Settings are stored in the WordPress option `weather_worm_settings`. Important
defaults:

- `default_station_id`: `117715`
- `default_station_label`: `Stone Tower Winery`
- `cache_ttl`: `300`
- default shortcode config: `stone-tower-current`

The WeatherLink API secret is stored in the settings option with a Catalog
Canary-style encrypted value using the `wwenc:` prefix and `wp_salt('auth')`.
The admin password field intentionally stays blank when a secret is saved; leave
it blank to preserve the saved secret or use the clear checkbox to remove it.

On activation, if a plugin-root `.env` exists and settings are missing, the
plugin reads `WL_API_KEY`, `WL_API_SECRET`, and `WL_STATION` as local development
defaults. Never print or commit `.env` values.

WeatherLink responses are cached in transients. Cache keys are also tracked in
the `weather_worm_cache_keys` option so **Clear Cache** and deactivation can
delete known Weather Worm transients.

## Admin Surface

The plugin adds a top-level WordPress admin menu for users with
`manage_options`.

Admin tabs:

- Overview: API status, default station, shortcode count, current conditions
  preview, and stations accessible to the configured API key.
- Shortcodes: Add, edit, delete, and copy shortcode configurations.
- Settings: WeatherLink API key, API secret, default station, station label,
  cache TTL, Test Connection, and Clear Cache.
- About: Plugin details and WeatherLink docs link.

Shortcode configs include an ID slug, title, station ID, station label, optional
sensor LSID override, show-station toggle, and selected metric keys.

## WeatherLink Integration

Weather Worm uses WeatherLink v2:

- `GET https://api.weatherlink.com/v2/stations?api-key=...`
- `GET https://api.weatherlink.com/v2/current/{station_id}?api-key=...`

Requests send the secret in the `X-Api-Secret` header. The current payload is
sensor-oriented; Weather Worm auto-picks the weather sensor record with the most
usable current-condition fields unless a shortcode config supplies a specific
LSID. Barometer data is merged from the barometer sensor when available.

Visitor-facing metrics are intentionally limited to current conditions:
temperature, humidity, wind, rain today, rain rate, barometer, dew point, heat
index, and last updated.

## Development Checks

Useful local validation commands:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/admin.js
```

For live WeatherLink smoke tests, source `.env` locally and call the documented
WeatherLink endpoints without printing the secret.

## Publishing Notes

Live publish target verified on 2026-06-28:

- SSH target: `alex@100.65.127.83`
- Remote hostname: `stwlsbwb1`
- WordPress root: `/var/www/wordpress`
- Live plugin path: `/var/www/wordpress/wp-content/plugins/weather-worm`
- Plugin directory owner after deploy: `www-data:www-data`
- `.env` is not deployed; live credentials were seeded into
  `weather_worm_settings` through WP-CLI and the API secret is stored with the
  `wwenc:` encrypted prefix.

Working deploy shape from this checkout:

```bash
rsync -rltz --delete \
  --exclude '.env' \
  --exclude '.git' \
  --exclude '.DS_Store' \
  --exclude 'worm-duotone-regular.svg' \
  --rsync-path 'sudo rsync' \
  -e 'ssh -i /Users/alexburgess/.ssh/Github -o IdentitiesOnly=yes -o UseKeychain=yes -o AddKeysToAgent=yes' \
  ./ alex@100.65.127.83:/var/www/wordpress/wp-content/plugins/weather-worm/
```

After deploy, restore ownership and validate:

```bash
ssh -i /Users/alexburgess/.ssh/Github -o IdentitiesOnly=yes -o UseKeychain=yes -o AddKeysToAgent=yes alex@100.65.127.83 \
  'sudo -n chown -R www-data:www-data /var/www/wordpress/wp-content/plugins/weather-worm'

ssh -i /Users/alexburgess/.ssh/Github -o IdentitiesOnly=yes -o UseKeychain=yes -o AddKeysToAgent=yes alex@100.65.127.83 \
  'sudo -n find /var/www/wordpress/wp-content/plugins/weather-worm -name "*.php" -print0 | sudo -n xargs -0 -n1 php -l'

ssh -i /Users/alexburgess/.ssh/Github -o IdentitiesOnly=yes -o UseKeychain=yes -o AddKeysToAgent=yes alex@100.65.127.83 \
  'sudo -n -u www-data wp --path=/var/www/wordpress plugin get weather-worm --field=status'
```

Post-publish checks from 2026-06-28:

- `weather-worm` was active.
- Remote PHP syntax checks passed for all deployed PHP files.
- The live option had an API key present and an encrypted API secret.
- WeatherLink returned one station, seven current sensors, and nine normalized
  metrics for station `117715`.
- `[weather_worm id="stone-tower-current"]` rendered a Weather Worm card with
  the Stone Tower Winery label.
