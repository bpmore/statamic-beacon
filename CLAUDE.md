# CLAUDE.md

Rules for any model working in this repository.

## The product

- A site-wide alert banner for Statamic. Free, MIT. It replaces a
  script-tag alert client at its first deployment. The build brief and
  the conventions document live in `docs/private/`, which is ignored by
  git on purpose: they name the first customer and this repository does
  not. They are the authority when present.
- Everything that decides what an alert is or whether it shows lives in
  the pure namespaces (`Alert`, `Sanitize`, `Mapping`, `Source`, `Fetch`,
  `Render`) and knows nothing of Statamic. `Bpmore\Beacon\Beacon`, the
  tag, the commands and `Statamic\*` are the wiring. Keep it that way.
- No renderer may know where an alert came from.

## Non-negotiables

- Remote content is hostile. Sanitize on fetch, never on render. Split
  the teaser before sanitizing. Titles are text. URLs pass the scheme
  check or become `#`.
- CAP `status` must be `Actual`. Never render a test message.
- Audience matching is an exact string comparison. No substring, prefix
  or pattern matching, ever.
- Severity precedence is `emergency` > `warning` > `info`, order of input
  irrelevant.
- A broken feed renders nothing and never throws to a page. A blip never
  clears a live alert; an empty feed does.
- Clear the page cache only when what a visitor sees changed.
- Server output is a landmark, never a live region. No `h1`. No dismiss
  control without script.
- Preview needs a signed-in user with the permission and a value from
  the enum, and bypasses the static cache both ways.

## Testing

- Pest. The core is tested with nothing booted; only `tests/Feature`
  boots Statamic.
- Mutation-test a guard before trusting it: break the thing it protects
  and confirm the test fails.
- `expect($x)->not->toContain(...)` on the wrong variable is vacuously
  true. Prefer `str_contains(...)` with `toBeFalse($message)` for the
  claims that matter.
- Verify against a real site before calling something done. A throwaway
  Statamic site with the addon installed by path, a live WordPress.com
  alert feed and the NWS feed found the JSONP wrapper, the `url` field
  shadowing and the query-string cache key, none of which the suite had.

## Writing

- No em dashes anywhere: code, comments, docs, config, copy. Use a colon,
  a comma, parentheses or two sentences.
- Comments say why and name the failure that motivated the rule.
- Read `docs/DECISIONS.md` before choosing an approach; add an entry when
  a change settles a question.

## Process

- Do not add a dependency the brief does not name without asking.
- Do not build the emergency fast path or CAP geocode filtering without
  asking; the brief says so.

## Names

- No institution's name, domain, hostname, or WordPress.com site id
  appears anywhere in this repository: not in code, comments, config,
  docs, tests, fixtures, screenshots or commit messages. Examples use
  `campus`, `clinic`, `example.edu` and `YOUR_SITE_ID`. The history was
  rewritten once to enforce this; do not put it back.
