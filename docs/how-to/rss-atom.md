# An RSS or Atom feed

For any platform that publishes a feed, which is most of them: a blog, a
news system, a status page, a Google Sites page, a SharePoint list with
RSS on. If you can tag or categorise a post, you can make it an alert.

## What you need

- The feed address. Usually `/feed/`, `/rss`, `/atom.xml`, or a link in
  the page's `<head>`. Paste it in a browser: you should see XML.
- A way to put a category or tag on a post. The category name (or its
  slug, for RSS) must be `severity-audience`: `urgent-campus`,
  `alert-clinic`, `fyi-everyone`.
- Beacon installed and the scheduler running ([operating.md](operating.md)).

## On the settings screen

1. Alert sources, Add a source, "RSS or Atom feed".
2. Feed address: paste it.
3. Name: something short for the Tools page.
4. Leave "Check every" at 300.
5. "Never higher than": set a cap if the feed is not yours.
6. "If no category names a severity": leave empty so untagged posts are
   ignored. Set it to Information only if every post in the feed is
   meant to be an alert.
7. Save.

## In the config file

```php
['driver' => 'feed', 'key' => 'news',
 'url' => 'https://news.example.edu/category/alerts/feed/',
 'poll' => 300],
```

Optional keys: `pattern` to change how a category is read (default
`^(?<severity>urgent|alert|fyi|emergency|warning|info)-(?<audience>[a-z0-9]+)$`),
`severity_map` to translate words (default `urgent`, `alert`, `fyi`),
`default_severity`, `max_severity`, `audiences`, `max_age`.

## What Beacon reads

| Alert field | RSS 2.0 | Atom |
|---|---|---|
| id | `guid`, else `link` | `id` |
| title | `title` | `title` |
| body | `content:encoded`, else `description` | `content`, else `summary` |
| url | `link` | `link rel="alternate"` |
| starts_at | `pubDate` | `published`, else `updated` |
| severity, audiences | `category` text | `category term=""` |

The body may contain `<!--noteaser-->` to split a teaser from the rest,
exactly as in the WordPress guide. Titles are decoded and shown as
text. Bodies are sanitized.

Tip: many platforms offer a feed per category. Point Beacon at the
alerts category's feed rather than the whole site's, so it reads twenty
items of alerts rather than twenty items of news.

## Test it

```
php please beacon:fetch
```

`ok N alert(s)`. If N is zero and you expect one, the category text is
not in the `severity-audience` shape. Open the feed XML and look at the
`<category>` elements.

## How it fails

- **`Not a feed: root element is <html>`**: the address is a web page,
  not the feed. Find the feed link.
- **`not well-formed XML`**: the platform's feed is broken. Beacon keeps
  the last good alerts. Tell the platform's owner.
- **Categories present but nothing shows**: WordPress puts the
  category's display name in RSS, not its slug. Name the category
  `urgent-campus`, not `Urgent (Campus)`.

## Checklist for publishers

- [ ] The alert category exists with the exact `severity-audience` name
- [ ] A test post in it appeared on the site and was then removed
- [ ] Everyone knows that the category is what makes a post an alert
