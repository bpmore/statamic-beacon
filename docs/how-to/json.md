# A JSON feed with no guide of its own

For a system that offers JSON but is none of the others: a custom API,
a status page, an internal service. You tell Beacon where each field
lives in the response.

## What you need

- The feed address and one sample response. Open it in a browser or run
  `curl -s ADDRESS | head -c 2000`.
- Beacon installed and the scheduler running ([operating.md](operating.md)).

## Read the sample

Find five things in the response:

1. **The list.** Is the response itself a list (`[...]`), or an object
   with the list inside it (`{"items": [...]}`)? If inside, note the path
   to it: `items`, or `data.results`.
2. **Per item, the title, body, link and id.** Note each path from the
   item down: `title`, or `title.rendered`, or `attributes.headline`.
3. **Per item, the severity.** Either a field holding a word (`level:
   "high"`) or a set of tags (`tags: ["urgent-campus"]`).
4. **Per item, the audience**, if the feed has one. Often the same tags.
5. **How the feed says "nothing"**: an empty list, or a count field.

Paths use dots. An asterisk means "each": `tags.*.slug` reads `slug`
from every entry under `tags`, whether `tags` is a list or an object
keyed by name.

## On the settings screen

Alert sources, Add a source, "Another JSON feed". Fill in the paths
from step 2 to 4. For a severity word that is not `info`, `warning` or
`emergency`, add rows to "Severity words": feed says `high`, means
Emergency. For tags that carry both severity and audience, fill in the
audiences field and a tag pattern.

## Two worked examples

### A status page

```json
{"incidents": [
  {"id": "abc", "name": "Patient portal degraded", "impact": "major",
   "shortlink": "https://status.example.org/i/abc",
   "incident_updates": [{"body": "We are investigating."}]}
]}
```

| Field | Value |
|---|---|
| List of alerts is at | `incidents` |
| Id field | `id` |
| Title field | `name` |
| Body field | `incident_updates.0.body` |
| Link field | `shortlink` |
| Severity field | `impact` |
| Severity words | `critical` = Emergency, `major` = Warning, `minor` = Information, `none` = Information |

```php
['driver' => 'http', 'key' => 'status',
 'url' => 'https://status.example.org/api/v2/incidents/unresolved.json',
 'map' => ['root' => 'incidents', 'id' => 'id', 'title' => 'name',
           'body' => 'incident_updates.0.body', 'url' => 'shortlink', 'severity' => 'impact'],
 'severity_map' => ['critical' => 'emergency', 'major' => 'warning', 'minor' => 'info', 'none' => 'info'],
 'empty_when' => []],
```

### A tagged list

```json
[
  {"key": 12, "headline": "Lot B closed", "html": "<p>Use lot C.</p>",
   "tags": ["parking", "fyi-campus"], "more": "https://example.edu/parking"}
]
```

| Field | Value |
|---|---|
| List of alerts is at | (empty) |
| Id field | `key` |
| Title field | `headline` |
| Body field | `html` |
| Link field | `more` |
| Audiences field | `tags.*` |
| Tag pattern | `^(?<severity>urgent|alert|fyi)-(?<audience>[a-z0-9]+)$` |
| Severity words | `urgent` = Emergency, `alert` = Warning, `fyi` = Information |

An item whose tags match nothing is skipped, so `parking` alone is not
an alert. Set "If no severity is found" only if every item should be.

## In the config file

The block's fields map onto `map`, `severity_map`, `default_severity`
and `empty_when`. `empty_when` is a set of path => value pairs that,
when all true, mean "no alerts", such as `['found' => 0]`. The
[WordPress feed reference](../reference/wordpress-alert-feed.md) shows a
complete map for a real feed.

## Test it

```
php please beacon:fetch
```

Then look at the Tools page. `ok 0 alert(s)` with a feed that has items
means the paths are wrong or the severity was not found. Check one path
at a time against the sample.

## How it fails

- **`not JSON`**: the address returns HTML (a login page, an error).
  Open it in a browser.
- **`Nothing iterable at items`**: the list path is wrong.
- **Alerts with no body**: the body path points at an object, not a
  string. Add the last step, such as `.rendered`.

## Checklist

- [ ] Sample response saved somewhere
- [ ] Every path checked against it
- [ ] Severity words cover every value the feed can send
- [ ] One fetch run and the result read
