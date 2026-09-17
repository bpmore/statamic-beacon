# Changelog

## 0.1.2 (2026-09-16)

- Utility page: no space before the comma in the GitHub rate-limit line.

## 0.1.1 (2026-09-16)

- Settings screen: the sources list and the site audiences map take the
  full row instead of half of it.

## 0.1.0 (2026-09-16)

First build against the v2 brief.

- Pure PHP core: `Alert`, three-level `Severity` with precedence, exact
  audience matching, a selector for what is live now, sanitizer, teaser
  split, URL scheme check, entity-decoded titles, Markdown with HTML
  stripped, plain-text bodies for CAP.
- Sources: local collection, JSON through a field map (WordPress.com
  defaults, JSONP unwrapped as text), RSS and Atom, CAP 1.2 in XML and NWS JSON-LD,
  GitHub file, Gist and issues, null. Sources chain in order with a
  per-source severity ceiling and audience restriction.
- Fetching on the scheduler with retained payloads, a retention ceiling,
  conditional requests, GitHub rate-limit backoff, and a fingerprint that
  clears the page cache only when the banner changed.
- Scheduled starts and ends clear the page cache at the moment they pass.
- Preview by query parameter, permissioned, allowlisted, bypassing the
  static cache both ways.
- Banner rendered as a landmark with a configurable heading level (h2 to
  h6), a severity word, an accessible Read more name, script-added
  dismissal keyed by content, bundled styles, and a compatibility mode
  emitting a legacy client's structure under a configurable class prefix.
- A settings screen under Addons, Beacon: sources as blocks (local
  collection, WordPress.com alert site, JSON, RSS/Atom, CAP, GitHub file, issues, Gist,
  Nothing), audience and site map, banner wording, preview, scheduler
  warning. The screen overlays the config file once saved.
- How-to guides under `docs/how-to` for every source and for operating
  the addon, and a reference for the WordPress.com feed contract under
  `docs/reference`.
- An optional emergency fast path: an endpoint on this origin serving
  the stored emergencies, and a script that injects a new one into a
  `role="alert"` live region on an open page and removes an ended one,
  whether the script or the server put it there.
- CAP geocode filtering: SAME and UGC codes mapped to audiences per source.
- Commands: `beacon:install`, `beacon:tick`, `beacon:fetch`,
  `beacon:status`. A utility page under Tools.
- 208 tests, every discriminating case from the brief among them.
