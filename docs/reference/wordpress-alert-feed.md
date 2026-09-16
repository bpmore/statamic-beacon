# The WordPress alert feed, as Beacon reads it

The feed contract Beacon's `http` driver defaults to: a WordPress.com
site where staff publish one post per alert, read through WordPress.com's
public API. This is the shape the "WordPress.com alert site" block on the
settings screen expects, and the shape the local `alerts` collection is
modelled on. Assembled on 2026-09-16 from a live site of this kind and
the API's own output.

## The parts

```
comms staff  -->  a WordPress.com site  -->  WordPress.com public API
                                                      |
                                    (optional) a caching proxy
                                                      |
                                                   Beacon
```

**The source site** is a WordPress.com site. Communications staff publish
one post per alert. The post's categories say how serious it is and
which sites show it.

**The public API** serves a public site's posts as JSON with no key and
no login:
`https://public-api.wordpress.com/rest/v1.1/sites/{SITE}/posts/`, where
`{SITE}` is the numeric site id or the site's address. This is what
Beacon reads by default.

**A proxy**, if the institution has one, is a script on its own web
server that reads the same API, caches the answer, and returns it wrapped
as JSONP (`callback({...})`) for an older script-tag client. Beacon can
read that too; it unwraps the wrapper as text and never runs it. The
direct address is recommended because it does not depend on the
institution's own server: a banner whose job includes announcing that
the server is down should not be served by that server.

**The legacy client**, where one exists, loaded the proxy with a
`<script>` tag on every page and injected a banner. Beacon replaces it.
The README lists what such a client did and Beacon does not.

## The category vocabulary

A category slug is `{severity}-{audience}`:

| Slug prefix | Means | Beacon severity |
|---|---|---|
| `urgent-` | An emergency | `emergency` |
| `alert-` | A warning | `warning` |
| `fyi-` | Information | `info` |

The suffix is an audience name: a short lowercase token shared by the
sites that should show the alert, such as `campus`, `clinic`, `intranet`
or `north`. Each consuming site declares exactly one audience in Beacon's
settings.

So `urgent-clinic` is an emergency for the clinical sites, and a post
carrying both `urgent-clinic` and `fyi-campus` is an emergency on the
clinical sites and an emergency on the campus sites too, because the
highest severity on a post wins everywhere it shows.

A category that does not fit the pattern carries no meaning to Beacon
and is ignored: `general-information`, a bare audience name, anything
else. A post with no meaningful category is not an alert.

The prefix words (`urgent`, `alert`, `fyi`) are the defaults and can be
changed per source with `severity_map`; the pattern itself with
`pattern`.

## The post shape

What the API returns, trimmed to what Beacon reads:

```json
{
  "found": 1,
  "posts": [
    {
      "ID": 400,
      "date": "2026-01-25T15:00:00-06:00",
      "title": "Inclement Weather Update",
      "URL": "https://alerts.example.wordpress.com/2026/01/25/inclement-weather-update/",
      "content": "<p>Short version.</p><!--noteaser--><p>Long version.</p>",
      "categories": {
        "Urgent Campus": { "slug": "urgent-campus", "name": "Urgent Campus" }
      }
    }
  ]
}
```

Things to know about it:

- **`categories` is an object keyed by category name**, not a list.
  Beacon iterates its values. A reader that assumed a list would find
  nothing.
- **`found` is authoritative.** `found: 0` means no alerts, whatever
  `posts` holds.
- **`title` may contain HTML entities** such as `&#8217;`. Beacon decodes
  them and renders the title as text.
- **`content` is HTML** from the WordPress editor. Beacon sanitizes it.
- **`<!--noteaser-->` in `content`** marks the fold: what comes before it
  shows in the banner with a Read more link to `URL`; without it the whole
  body shows inline.
- **`date` is the publish date.** Beacon uses it as the alert's start.
  There is no end date; an alert ends when the post is unpublished or
  deleted.
- A proxy may add fields of its own (`cache_state`, `cache_age`). Beacon
  ignores anything it does not map, and a test proves such fields do not
  cause a page-cache flush.

## Fields Beacon reads, and nothing else

| Beacon field | From |
|---|---|
| id | `ID` |
| title | `title`, entities decoded |
| body, teaser | `content`, split at `<!--noteaser-->`, each part sanitized |
| url | `URL`, scheme checked |
| starts_at | `date` |
| severity, audiences | `categories.*.slug` through the pattern `^(urgent|alert|fyi)-([a-z0-9]+)$` |

Everything else in the payload (`author`, `excerpt`, `meta`, `tags`,
`attachments`, and so on) is never read.

## Addresses

| Purpose | Address |
|---|---|
| Posts | `https://public-api.wordpress.com/rest/v1.1/sites/{SITE}/posts/?number=20` |
| Site id from address | `https://public-api.wordpress.com/rest/v1.1/sites/{your-site}.wordpress.com/` (the `ID` field) |
| Categories | `https://public-api.wordpress.com/rest/v1.1/sites/{SITE}/categories/` |

`number=20` reads the twenty newest posts, which is plenty for a site
that holds alerts and nothing else. Raise it if the site also holds
other posts, or better, point the address at a category feed.

## Self-hosted WordPress

A self-hosted site's REST API (`/wp-json/wp/v2/posts?_embed=wp:term`) has
a different shape: `title.rendered`, `content.rendered`, `link`, and
categories under `_embedded['wp:term']`. The
[WordPress guide](../how-to/wordpress.md) gives the map.
