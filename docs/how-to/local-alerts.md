# Alerts written on this site

For editors. The Alerts collection is the simplest source: an entry is
an alert.

## What you need

- Beacon installed with `php please beacon:install`, which creates the
  collection and its blueprint.
- Permission to edit the Alerts collection.
- The "Alerts written on this site" block on the settings screen (it is
  there by default).

## Writing an alert

Content, Collections, Alerts, Create Entry.

| Field | What it does |
|---|---|
| Title | The banner heading |
| Severity | Information, Warning or Emergency. Emergency alerts cannot be dismissed unless you turn that on below |
| Message | The full alert. Bold, italic and links only |
| Teaser | Optional. When filled, the banner shows the teaser instead of the message, with a Read more link to the address below |
| Read more link | Where Read more goes. Only used with a teaser |
| Starts at | Leave empty to start when published. Set a time to publish now and have it appear later |
| Ends at | Leave empty to show until you unpublish. Set a time and it comes down by itself |
| Audiences | Leave empty for every site. Otherwise the exact audience names from the settings screen |
| Dismissible | Whether a visitor can close it |

Publish it. The banner appears on the next page load; the page cache is
cleared for you.

## Teaser or no teaser

Without a teaser the whole message shows in the banner. With one, the
teaser shows and a Read more link leads to the full story. Keep the
teaser to a sentence.

## Ending an alert

Unpublish or delete the entry, or let Ends at pass. All three clear the
page cache.

## Correcting an alert

Edit it and save. Visitors who dismissed the old version see the
corrected one, because the dismissal is tied to the content.

## Preview before publishing

Save as a draft, then open any page of the site with
`?beacon-preview=warning` (or `emergency`, `info`) while signed in. You
see the newest alert, drafts included, at that severity with a preview
marker. Nobody else sees it. You need the "View Beacon previews"
permission.

## Testing

Publish an Information alert titled "Test", look at the home page, then
unpublish it. Done.

## How it fails

- **Nothing shows.** Is it published? Has Starts at passed? Has Ends at
  not passed? Does the Audiences field include this site's audience, or
  is it empty? Is the "Alerts written on this site" block on the settings
  screen and switched on?
- **Read more is missing.** It needs both a teaser and a link.
- **Dismiss is missing.** Emergency alerts have none by default, and no
  alert has one when JavaScript is off.

## Checklist for publishers

- [ ] Title says what happened in a few words
- [ ] Severity matches: emergency means act now
- [ ] Ends at set if you know when it stops mattering
- [ ] Audiences empty unless it is really only for some sites
- [ ] Previewed once before publishing
