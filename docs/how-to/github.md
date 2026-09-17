# GitHub as an alert source

GitHub earns its own source for one reason: it is the only option with
review, history and branch protection built in. For a banner on every
page of a public institution, "two people must approve" and "who changed
this at 2am" are governance, not developer convenience. It is also
completely independent of the sites that show the banner.

Three modes. Use `file` unless you have a reason not to.

## What you need

- A GitHub account and a **public** repository. Private needs a token,
  and a token expires one day and takes the banner with it.
- Beacon installed and the scheduler running ([operating.md](operating.md)).

## Mode 1: a file in a repository (recommended)

A JSON or YAML file of alerts. Edit it through a pull request; the
review is your approval gate.

### Set up the repository

1. Create a public repository, for example `youruniversity/alerts`.
2. Add `alerts.json`. Start from
   [../alerts.example.json](../alerts.example.json); the shape is in
   [../alerts-file.schema.json](../alerts-file.schema.json). An empty
   file is `{"alerts": []}`.
3. Settings, Branches, add a protection rule for `main`: require a pull
   request, require one approval. Now no alert goes live without a
   second person.
4. Give the alert publishers write access. They can open pull requests
   from the GitHub website or the mobile app; nobody needs git.

### On the settings screen

Alert sources, Add a source, "A file on GitHub". Owner, repository,
branch (`main`), file path (`alerts.json`). Save.

### In the config file

```php
['driver' => 'github', 'key' => 'repo', 'mode' => 'file',
 'owner' => 'youruniversity', 'repo' => 'alerts', 'ref' => 'main', 'path' => 'alerts.json'],
```

### Publishing an alert

Edit `alerts.json` on GitHub, add an entry, open a pull request, get it
approved, merge. Within the poll interval plus a minute plus GitHub's
CDN cache (short, but measure it for your repository before promising a
number) it is on every site with a matching audience. To end it, remove
the entry the same way, or give it an `ends_at`.

An entry:

```json
{
  "id": "boil-water-2026-03",
  "title": "Boil water notice",
  "severity": "emergency",
  "teaser": "<p>Boil tap water before drinking until further notice.</p>",
  "body": "<p>Boil tap water before drinking until further notice.</p><p>Details...</p>",
  "url": "https://example.org/news/boil-water",
  "audiences": ["campus", "clinic"],
  "ends_at": "2026-03-02T09:00:00-06:00"
}
```

Keep `id` stable across edits: dismissals are keyed by id plus content,
so an edit re-shows the alert to people who dismissed it, which is what
you want for a correction. `markdown` can replace `body` if the writer
prefers it.

## Mode 2: open issues

Each open issue with a label is an alert. Closing it ends the alert.
Genuinely usable from a phone at 2am, and the reason it exists.

### The limit you must know about

Without a token, GitHub allows **60 API requests an hour per IP
address**, shared by everything behind that address. One site polling
every five minutes is 12. Five sites behind one university gateway is
60, and the sixth request fails with a 403 and no alert. Beacon sends
conditional requests, watches the remaining count, shows it on the Tools
page, and stops polling before the limit rather than after. An optional
token raises it to 5,000. The file mode has none of this.

### Set up

1. In the repository, create a label `alert` (the marker) and labels
   for severity and audience: `urgent-campus`, `alert-clinic`,
   `fyi-northwest`, and so on. Same vocabulary as the WordPress guide.
2. On the settings screen: Add a source, "Open issues on GitHub". Owner,
   repository, label `alert`. Token if you have one. Save.

   A token typed into the screen is saved in plain text to
   `resources/addons/statamic-beacon.yaml`, which most sites commit. If
   the token must stay out of the repository, leave the field empty and
   put the source in the config file with `env('BEACON_GITHUB_TOKEN')`,
   as below.

```php
['driver' => 'github', 'key' => 'issues', 'mode' => 'issues',
 'owner' => 'youruniversity', 'repo' => 'alerts', 'label' => 'alert',
 'token' => env('BEACON_GITHUB_TOKEN')],
```

### Publishing

New issue. Title is the banner heading. Body is Markdown; raw HTML in it
is stripped. Add the `alert` label and one severity-audience label per
site. Close the issue to end the alert.

## Mode 3: a Gist

Same file shape as mode 1, served from a public Gist's raw address. No
review, no history worth the name. Offered for a single person who
wants the quickest possible setup; not recommended for an institution.

Add a source, "A public Gist", paste the raw address (Raw button on the
Gist, then copy the address bar).

## Test it

```
php please beacon:fetch
```

For issues mode, also look at "GitHub rate limit" on the Tools page
after the first fetch.

## How it fails

- **404 on the file**: wrong owner, repository, branch or path, or the
  repository is private. Check the raw address in a browser:
  `https://raw.githubusercontent.com/OWNER/REPO/BRANCH/PATH`.
- **`The file is not JSON`**: a syntax error in the last merge. The last
  good alerts stay up. Fix and merge again.
- **Issues: `rate limit exhausted`**: too many sites polling from one
  address. Add a token, lengthen the poll, or move to file mode.
- **Issue shows but with no formatting**: it is Markdown, not HTML.
  `**bold**`, not `<b>`.

## Checklist

- [ ] Repository public, branch protected, approval required
- [ ] `alerts.json` valid (`jq . alerts.json` on a laptop)
- [ ] Publishers can open a pull request from the website
- [ ] One test alert merged, seen, and removed
