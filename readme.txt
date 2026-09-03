=== Woo Exports ===
Contributors: biscuitstudios
Tags: woocommerce, export, csv, xlsx, reports
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.12.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Scheduled CSV and XLSX exports for WooCommerce. Build named export configurations and have them emailed on a schedule.

== Description ==

See the README on GitHub for the full description, requirements, and known
limitations: https://github.com/biscuitstudios/woo-exports

Built and maintained by Biscuit Studios for our own client sites. Published
as-is, with no support. Forks welcome.

== Installation ==

1. Download the zip from the Releases page on GitHub.
2. Plugins > Add New > Upload Plugin.
3. Activate.

== Changelog ==

= 0.12.0 =
* New: the plugin now offers its own updates on the Plugins screen. Until now
  the Update URI header pointed at GitHub and nothing answered, so no site was
  ever told a new version existed and every release had to be uploaded by hand.
  Updates are read from the repo's published releases.
* Note: this only starts working once a build containing it is installed. A site
  on an older version has no code to ask with, so the first install of this
  release is still a manual upload.

= 0.11.0 =
* Change: the plugin is now called "Woo Exports" rather than "WooExports", so it
  sorts next to the studio's other Woo plugins in the WordPress plugins list and
  in the admin menu. The folder slug, the text domain and every internal prefix
  are unchanged, so this is a display change only and updates in place.

= 0.10.2 =
* New: the resolved-window note under Date Range refreshes as you change the
  range, the day boundary, the custom dates or the schedule. It used to be
  computed once on page load and then go stale until the next save, so checking
  what a range meant took a save and a reload. Still resolved in PHP, over a
  small AJAX call, rather than reimplemented in JavaScript where it could drift
  from what the export actually does.

= 0.10.1 =
* Fix: Preview Export now resolves its date range against the next scheduled
  run, the same moment the window printed under Date Range is calculated for.
  Previewing a boundary-shifted report before the boundary had passed returned
  the previous day's window, one day off from what the note said the next email
  would cover. Both now agree, and the preview states the window it used.
* Fix: the Products filter could not find products whose names sort late among
  their matches. WooCommerce's product search returns 30 rows ordered
  alphabetically with no notion of relevance, so a search for "Rhodes" on a site
  with 30+ "Legends and Lore at Rhodes Hall" tickets never reached "Rhodes Hall
  Tour". Replaced with a search that ranks exact and leading matches first and
  returns up to 100. Drafts and private products are now findable too, since
  reports often run over products a past event sold through.
* Change: Sunday now leads the Days of Week row in the schedule.
* Change: toast notifications appear at the top of the screen instead of the
  bottom.

= 0.10.0 =
* New: "Day starts at" filter. Set a report's day boundary to something other
  than midnight and Today, Yesterday and Custom run boundary to boundary, so a
  19:00 setting makes "Yesterday" mean 7:00 pm the previous day through
  6:59 pm today. Built for reconciling against a payment processor's
  settlement cutoff. Week, month and year ranges are named calendar periods
  and ignore the setting, so "Last Month (July)" is always July. Defaults to
  00:00, which behaves exactly as before.
* Fix: date ranges are now sent to WooCommerce as timestamps rather than date
  strings. WooCommerce discards the time component of a string range and
  queries at whole-day precision, so any window narrower than a full day was
  silently widened. Affects no existing report, all of which used midnight
  boundaries, but it is what makes the new boundary work at all.
* Fix: attendee exports were filtered against a date window shifted by the
  site's UTC offset, five hours on a US Eastern site. Attendee row counts will
  change on this release and the new counts are the correct ones. Order,
  customer and product exports were never affected.

= 0.9.0 =
See https://github.com/biscuitstudios/woo-exports/releases
