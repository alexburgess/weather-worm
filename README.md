# Weather Worm

Weather Worm is a self-contained WordPress plugin for displaying WeatherLink v2
current conditions through configurable shortcodes.

Default shortcode:

```text
[weather_worm id="stone-tower-current"]
```

Raw value examples:

```text
[weather_worm_temperature]
[weather_worm_humidity]
[weather_worm_wind_speed]
[weather_worm_wind_direction]
[weather_worm_rain_today]
[weather_worm_barometer]
[weather_worm_value metric="dew_point"]
[weather_worm_value metric="barometer" format="display"]
```

## Features

- WordPress admin dashboard under **Weather Worm**.
- WeatherLink API key/secret settings with password-style secret preservation.
- Configurable shortcode definitions stored in the `weather_worm_settings` option.
- Current conditions card with temperature, humidity, wind, rainfall, barometer,
  dew point, heat index, and last updated time.
- Raw value shortcodes for theme-controlled markup and styling.
- WeatherLink response caching through WordPress transients.
- Admin styling patterned after the sibling Alex Burgess WordPress plugins.

## Setup

1. Activate the plugin in WordPress.
2. Open **Weather Worm > Settings**.
3. Enter the WeatherLink API key, API secret, default station ID, and station label.
4. Use **Test Connection** to confirm WeatherLink access.
5. Add `[weather_worm id="stone-tower-current"]` to a page, post, block, or template.

Use raw shortcodes when the child theme should own all markup and styling. Named
raw shortcodes output only escaped text, with no wrapper element:

```text
[weather_worm_temperature id="stone-tower-current"]
[weather_worm_temperature_display id="stone-tower-current"]
[weather_worm_humidity id="stone-tower-current"]
[weather_worm_humidity_display id="stone-tower-current"]
[weather_worm_wind_speed id="stone-tower-current"]
[weather_worm_wind_speed_display id="stone-tower-current"]
[weather_worm_wind_direction id="stone-tower-current"]
[weather_worm_wind_direction_degrees id="stone-tower-current"]
[weather_worm_rain_today id="stone-tower-current"]
[weather_worm_rain_rate id="stone-tower-current"]
[weather_worm_barometer id="stone-tower-current"]
[weather_worm_dew_point id="stone-tower-current"]
[weather_worm_heat_index id="stone-tower-current"]
[weather_worm_last_updated id="stone-tower-current"]
[weather_worm_station_name id="stone-tower-current"]
```

The generic raw shortcode is:

```text
[weather_worm_value id="stone-tower-current" metric="temp" format="value"]
```

Supported `format` values are `value`, `display`, `unit`, `label`, `time`,
`direction`, and `direction_degrees`. `value` is the default and returns the
raw normalized number where possible. Add `decimals="1"` when you want WordPress
number formatting with a fixed number of decimals.

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

WeatherLink.com shows a Local Forecast tile, but the official v2 API docs checked
for this plugin expose station metadata and observation endpoints rather than a
forecast endpoint. For Stone Tower Winery, a forecast feature would need a
separate source such as the National Weather Service API using the station
latitude/longitude.
