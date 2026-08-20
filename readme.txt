=== WooExports ===
Contributors: biscuitstudios
Tags: woocommerce, export, csv, xlsx, reports
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.10.0
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
