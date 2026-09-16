# Weather and emergency agencies (CAP)

Common Alerting Protocol is the format national weather and emergency
agencies publish warnings in. The US National Weather Service serves it
free, with no key, filtered to a point on the map. Around 130 other
authorities publish CAP; this guide covers the NWS and says how to
approach the rest.

## What you need

- Your latitude and longitude, to four decimals. Right-click the campus
  on a map. Downtown Little Rock, for example, is `34.7465,-92.2896`.
- A way to identify yourself. The NWS requires a User-Agent that says
  who you are and how to reach you, such as `Example Hospital
  (webteam@example.org)`. Requests without one are refused.
- Beacon installed and the scheduler running ([operating.md](operating.md)).

## On the settings screen

1. Alert sources, Add a source, "Weather and emergency agency (CAP)".
2. Feed address: `https://api.weather.gov/alerts/active?point=LAT,LON`.
3. Who to say you are: your name and contact, as above.
4. "Never higher than": Warning. This is the default and the right one.
   A heat advisory should not look like a campus emergency; if a tornado
   warning warrants an emergency banner, a person publishes one.
5. "Show to these audiences": weather alerts are by place, not by
   category, so name the audiences here. Leave empty for every site.
6. Save.

## In the config file

```php
['driver' => 'cap', 'key' => 'nws',
 'url' => 'https://api.weather.gov/alerts/active?point=34.7465,-92.2896',
 'headers' => ['User-Agent' => 'Example Hospital (webteam@example.org)', 'Accept' => 'application/geo+json'],
 'max_severity' => 'warning',
 'audiences' => ['campus', 'clinic']],
```

## What Beacon does with a CAP alert

- Shows only alerts whose `status` is `Actual`. Test, exercise, system
  and draft messages never render, whatever else they say.
- Works out the severity from the CAP `severity` and `urgency` together:
  Extreme, or Severe and Immediate, is emergency; Severe or Moderate is
  warning; everything else information. Then applies your cap. Override
  the matrix in the config file with `matrix` if you must.
- Uses `headline` as the title, `description` and `instruction` as the
  body (plain text, escaped, paragraphed), `effective` as the start,
  `expires` as the end, `web` as the link.
- Honours `expires` even if the next fetch fails, so a warning that
  expired at 4pm is down at 4pm.
- Replaces an alert with its update rather than showing both.
- Reads both the JSON the NWS API serves and CAP XML from any publisher,
  with or without the `cap:` prefix.

## Test it

```
php please beacon:fetch
```

`nws ok N alert(s)`. Zero on a calm day. To see the path work, pick a
point with weather: the NWS site shows a map of active alerts.

## How it fails

- **`HTTP 403`**: no User-Agent. Fill in "Who to say you are".
- **`not well-formed XML` or `not JSON`**: the API had a bad moment.
  The last good alerts stay up; `expires` still takes them down on time.
- **Alerts for the wrong place**: check the point. `point=LAT,LON`, not
  `LON,LAT`.

## Other CAP publishers

Environment Canada, the UK Met Office, MeteoAlarm (Europe) and many
national agencies publish CAP, usually as an Atom feed of CAP documents
or as CAP XML directly. Beacon reads both, but each publisher has its
own way of filtering by area and its own idea of `severity`, so:

1. Find the publisher's feed address for your area.
2. Add it with the CAP block, capped at Warning.
3. Run `beacon:fetch` and read what came back on the Tools page before
   trusting it on a live site.

## Checklist

- [ ] User-Agent filled in
- [ ] Cap at Warning
- [ ] Audiences named
- [ ] One fetch run and the result read
