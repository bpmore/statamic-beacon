# Decisions

Choices that are not obvious from the code, with the alternatives turned
down. Add an entry when a change settles a question.

## Sanitizer: symfony/html-sanitizer

Chosen over ezyang/htmlpurifier (older, heavier) and a hand-written
allowlist (security-critical code from scratch, for a health system).
Symfony's default for an element nobody mentioned is drop-with-children,
and that default is kept. Common wrappers (`div`, `h3`, `blockquote`,
tables) are blocked, meaning the tag goes and the words stay, so a
WordPress block wrapper does not eat the message. `style` sits in
Symfony's head-element list and is skipped by body-context config, so it
must not be listed at all: listed as dropped it is ignored, unlisted it is
dropped by default. A test names every forbidden element.

## XML: PHP's DOM extension, DOCTYPE refused

No XML library. `LIBXML_NONET`, never `LIBXML_NOENT`, and any document
carrying a DOCTYPE is refused before parsing since a feed has no honest
reason for one and every entity-expansion attack needs one. Elements are
matched by local name because the `cap:` prefix is inconsistent across
publishers.

## Storage: files under storage/beacon, not the cache

`php artisan cache:clear` must not take a live emergency down. Snapshots
are written to a temporary name and renamed into place so a page request
never reads half a file.

## Scheduling: one tick a minute, not delayed jobs

Starts and ends are honoured by a minute tick that compares each alert's
boundaries with the window since the last tick. Queued delayed jobs would
need a queue worker, and a site with static caching and no queue is the
common case. The cost is up to a minute of latency at a boundary, which
is inside the freshness budget already.

## Fingerprint compares what a visitor sees

`changed` is computed against what the previous snapshot would have
shown, not what it stored. A payload past its ceiling was showing
nothing, so the same alerts coming back after a stale spell is a change
and clears the cache.

## Preview bypasses the cache by swapping the cacher

Statamic 6 ignores query strings when keying its static cache, so a
preview URL would be answered with the cached plain page. The addon's
middleware runs in `web`, ahead of Statamic's cache middleware in
`statamic.web`, and binds a `NullCacher` for the request. The
`X-Statamic-Uncacheable` header is set as well.

## Local `link` field, not `url`

An entry's augmented `url` is its own address, which shadows any field
of that name. The brief's `url` field is `link` in the blueprint.

## JSONP unwrapped as text

A JSONP proxy answers `callback({...})` whatever it is asked. The
callback name is discarded by a regular expression that requires the
argument to be a JSON object or list; nothing is executed. That is the
difference between this and the reference's script tag.

## CAP text: single newlines become spaces

The NWS hard-wraps at 65 columns. Blank lines become paragraphs; a single
newline inside a paragraph becomes a space so the text flows to the
reader's width. `<br>` per newline was tried first and read like a
teletype.

## Multi-alert: no cap on remote alerts

The brief's open question 5 asked whether remote mode should cap at one
for parity with the reference. Not capped: the brief's definition of done
requires three simultaneous alerts, and the reference's single-alert
behaviour came from a `break` in a loop, not a decision.

## Settings screen: the file underneath, the screen on top

Statamic's addon settings blueprint, the same mechanism the sibling
addons use. `raw()` not `all()`, so a blank field means "the file
answers". The source list is the one place an empty answer is not an
answer: a person who saves the screen without touching sources must not
switch the banner off, so an empty list falls back to the file and the
"Nothing" block is the explicit off switch. The list is pre-filled with
the local collection block so adding a remote feed does not silently
drop control-panel alerts. Passwords for tokens are `input_type:
password` on the form but land in `resources/addons/statamic-beacon.yaml`
in plain text, as every addon setting does; the form says a token is
optional and why.

## The fast path asks this origin, never the source

Built after the owner asked. The endpoint serves the store the
scheduler fills, rendered with the same `Banner`, so the page script
never holds unsanitized content and never contacts a remote host. It is
off by default because it is a request per open tab per interval, and
because a site whose scheduler runs every minute and whose static cache
is flushed on change gets most of the benefit on the next page load
anyway. The live regions are empty at load and separate from the
landmark, which is what keeps the server-rendered banner from being
announced twice.

## CAP geocodes replace, not extend, the source audiences

When a `geocodes` map is given, an alert matching none of its codes is
dropped rather than falling back to the source-wide audiences. A feed
covering a whole state would otherwise show every county's warning to
every site the moment one row was added.

## Compatibility mode takes a prefix

The legacy client's markup structure is emitted with a configurable
class prefix (`compat_prefix`, default `legacy-alert`) rather than the
original client's own name, so the addon carries no institution's
identifiers and a migrating site sets the prefix its stylesheet expects.

## The build brief is not in the repository

`docs/private/` is ignored. The brief and the conventions document name
the first customer throughout and are working documents, not part of
the addon. `CLAUDE.md` says where they live.
