# Plan: how-to guides for every Beacon source

Written 2026-09-16 and carried out the same day. Kept as the record of
what was decided and why.

## What it was for

Two audiences, two kinds of document:

- **The person setting Beacon up.** One guide per source type, written
  for the control panel screen first and the config file second, ending
  with "here is how you know it is working" and "here is what happens
  when it breaks".
- **Anyone running the WordPress model.** A WordPress.com site where
  communications staff post alerts, read by every consuming site. The
  publishing side needed writing down, and the feed contract Beacon's
  `http` defaults assume needed a reference document.

## What was decided

1. Layout: `docs/how-to/` for the guides, `docs/reference/` for the
   feed contract, `docs/images/` for screenshots.
2. The WordPress guide recommends WordPress.com's public API directly
   over any proxy on the institution's own web server, because a banner
   whose job includes announcing that server's outage should not be
   served by it.
3. The publishing half of the WordPress guide was verified on a fresh
   free WordPress.com site: six categories, three posts (one per
   severity, one with the teaser marker), all seen through Beacon with
   the right audiences. Three corrections came out of it: the Custom
   HTML block needs Edit HTML clicked before typing, the publish panel
   emails subscribers unless Post only is chosen, and Uncategorized
   unticks itself.

## The guides

| Guide | For |
|---|---|
| `local-alerts.md` | Editors using the Alerts collection |
| `wordpress.md` | Joining a WordPress.com alert site, and running one |
| `rss-atom.md` | Any platform with a feed |
| `weather-cap.md` | The NWS and other CAP publishers |
| `github.md` | File, issues and Gist modes |
| `json.md` | A JSON feed with no guide of its own |
| `operating.md` | The scheduler, audiences, preview, monitoring, caching |

Every guide has the same skeleton: who it is for, what you need, the
settings screen, the config file, a test, the failure modes, a checklist.

## How they were verified

- The JSON guide's two worked examples run as tests.
- Both WordPress shapes (WordPress.com v1.1 and self-hosted v2 with
  embedded terms) parse with the maps the guide gives, as tests.
- Every link in every guide resolves, as a test.
- The WordPress publishing half was followed on a real site, as above.
- Screenshots come from a throwaway Statamic 6 site.
