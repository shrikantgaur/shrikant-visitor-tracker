# Shrikant Visitor Tracker

Self-hosted visitor analytics for WordPress that still counts correctly behind a
page cache, and that can take over from an existing view counter without losing
its history.

**WordPress.org:** https://wordpress.org/plugins/shrikant-visitor-tracker/ *(pending review)*

## Why it exists

Most view counters increment in PHP while the page is being built. On a site
with a page cache that means readers served from the cache are never counted,
while the crawlers that bypass the cache are counted every time — the number
drifts away from reality in both directions at once.

This one counts from the browser after the page has loaded, and filters known
bots before recording anything. The figure it reports is usually far lower than
the plugin it replaces, and far closer to what Search Console says.

## Switching from another counter

Deleting a view counter normally throws its history away. **Views → Import**
reads the totals out of Post Views Counter or WP-PostViews first. Imported
counts are stored separately and never mixed into this plugin's own statistics:
the two measure different things, and blending them would make both
untrustworthy.

For the same reason, deleting this plugin does not delete its data unless that
is switched on in Settings.

## Requirements

- WordPress 6.3+
- PHP 8.2+

## Development

`readme.txt` is the file WordPress.org reads; this file is for GitHub and is
excluded from the plugin ZIP by `.distignore`.

## License

GPL-2.0-or-later. Chart.js 4.4.1 is bundled under the MIT license.
