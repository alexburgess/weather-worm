# Weather Worm

Weather Worm is a self-contained WordPress plugin for displaying WeatherLink v2
current conditions through configurable shortcodes.

Default shortcode:

```text
[weather_worm id="stone-tower-current"]
```

## Features

- WordPress admin dashboard under **Weather Worm**.
- WeatherLink API key/secret settings with password-style secret preservation.
- Configurable shortcode definitions stored in the `weather_worm_settings` option.
- Current conditions card with temperature, humidity, wind, rainfall, barometer,
  dew point, heat index, and last updated time.
- WeatherLink response caching through WordPress transients.
- Admin styling patterned after the sibling Alex Burgess WordPress plugins.

## Setup

1. Activate the plugin in WordPress.
2. Open **Weather Worm > Settings**.
3. Enter the WeatherLink API key, API secret, default station ID, and station label.
4. Use **Test Connection** to confirm WeatherLink access.
5. Add `[weather_worm id="stone-tower-current"]` to a page, post, block, or template.

During local development only, activation will prefill missing settings from a
plugin-root `.env` file with these keys:

```text
WL_API_KEY=
WL_API_SECRET=
WL_STATION=
```

Do not commit `.env`; it is ignored by this repo.

## Development Checks

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/admin.js
```

There is no Composer, npm build step, or local WordPress test fixture in this
repo.

## WeatherLink Notes

Weather Worm uses WeatherLink v2 endpoints:

- `GET /stations`
- `GET /current/{station_id}`

Requests send the API key as the `api-key` query parameter and the API secret in
the `X-Api-Secret` header.
