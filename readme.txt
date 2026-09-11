=== Woo Exports ===
Contributors: biscuitstudios
Tags: woocommerce, export, csv, xlsx, reports
Requires at least: 6.3
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.16.0
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

= 0.16.0 =
* Fix: removing a status from Order Statuses and saving now sticks. The default
  pair, Completed and Processing, was being merged back into the saved list slot
  by slot, so any export filtered to a single status silently kept a second one
  and put it back in the builder on reload. Reports saved before this release
  keep whatever is stored now; re-save to correct one.
* Fix: an Attendees export gave every ticket in a multi-ticket order the
  purchaser's name and email. The name and email Event Tickets Plus stamps on an
  attendee come from the WooCommerce billing details, not from the attendee, so
  a family of three exported the buyer three times. The export now reads the
  Attendee Information the ticket actually collected, and falls back to the
  purchaser only where no such field was ever asked for.
* Change: an Attendee Information field that exists but was left blank now
  exports blank, where before it borrowed the purchaser's details. Optional
  fields are the normal case, and an empty cell is the true answer.
* New: Purchaser Name and Purchaser Email columns on the Attendees export, after
  Security Code. On a multi-ticket order the attendee columns are somebody other
  than the buyer, so these are the reference for who paid. They read the order's
  billing details rather than the copy Event Tickets Plus stamps on the ticket,
  which goes stale if billing is corrected later.
* New: empty cells export an en dash instead of nothing, on all four export
  types and in the Preview pane. A blank cell reads to a client as missing data.
  A zero is still a zero: 0, 0.00 and the cover-fee columns are untouched.
* New: `wooex_attendee_name_slugs` and `wooex_attendee_email_slugs` filters, for
  a site whose fieldset uses field names the plugin does not recognise.
* Note: attendee names and emails change on this release wherever Attendee
  Information was collected. The new values are the correct ones.

= 0.15.1 =
* Fix: Email Export did nothing on a site whose theme strips the ?ver= query
  string from asset URLs. Both openers returned silently when the dialog was
  not on the page, so a click produced no dialog, no error, and nothing in the
  console. They now say what happened.
* New: the admin script carries its own version and compares it against the
  version PHP reports. A browser or CDN serving a cached copy of an older
  script now says so in a notice instead of quietly binding no handlers. A test
  keeps the two numbers in step, so a release cannot ship them out of step.
* Note: the cause on the site this was found on was not in this plugin. Its
  theme removed ?ver= from every asset URL, so the URL never changed between
  releases and the CDN served the previous version's JavaScript for a year.
  This release cannot fix that. From here on it reports it.

= 0.15.0 =
* New: Email Export. A row action on the Exports list and a button beside
  Preview Export in the builder both open a dialog where you type the addresses
  and send. The list row generates the saved export from live data; the builder
  button sends the export as configured on the page, saved or not, over the same
  window the preview shows. Neither changes the export's saved recipients, and
  neither touches Last Run, which still describes the schedule.
* Note: a manual send goes out even when the window holds no orders. That is
  deliberate. The email is how you show a client that a date range was empty.
* Change: the export email is responsive. It was a fixed 560px table that
  wrapped in three places on a long site name and had no phone layout at all.
  The card is now fluid up to 680px, the meta rows stack label above value
  below 620px, and the padding and type scale come down below 480px. Outlook
  desktop is pinned to the full width by a conditional table, since it neither
  reflows nor honours max-width.
* Fix: a date range now breaks at the dash between the two dates rather than in
  the middle of one, on every width.
* New: the email follows the reader's light or dark system preference. It had
  no dark handling at all, so a client was free to invert half the design on its
  own. There is now a real dark palette rather than an inversion: the card sits
  above the ground by the same step it does in light mode.
* Fix: the quiet grey used for the meta labels and the footer was #8a8a8a,
  which is 3.45:1 on the card and fails WCAG AA. It is now #767676 at 4.54:1.
* Change: the footer line sits inside the card, under a divider matching the one
  above the date range, rather than on the page background below it. #767676
  only reaches 3.81:1 on that background, so the two changes go together. Every
  piece of text in the email now clears AA in both schemes, with no exceptions.
* Change: every table and cell in the email names its own background colour.
  A client that repaints table and td wholesale reaches through any element that
  does not, which cost the card's rounded corners and, before that, most of the
  card.
* Internal: every colour is stated once per scheme, in two palettes, and
  substituted into the inline styles and the dark-mode block from there. The
  tests measure the contrast of both palettes and assert that every colour set
  inline has a dark counterpart, since a role missed in dark mode keeps its
  light value and nobody on a light machine ever sees it.
* Note: a client that rewrites colour values outright rather than through CSS
  will still do its own thing. The Gmail app is the one to check.
* Change: on the edit screen, the Download button and the preview count are now
  invalidated by a change to the schedule as well as to the filters. For a
  scheduled export the window is resolved at the next run, so moving the send
  time moves the window without touching a filter. Download could go stale that
  way and quietly did.
* Change: the Email Export dialog on the edit screen repeats what the last
  preview counted, or says no preview has been run. The button is deliberately
  not hidden behind a preview the way Download is: Download only appears when a
  preview found rows, and a zero-row send is the case this feature exists for.
* Fix: an export named "Nightly Order Export" produced the headline "Your
  Nightly Order Export export is attached." The appended word is dropped when
  the name already ends in export or report.
* Internal: a manual send refuses a Custom range missing either date. An
  incomplete custom range resolves to every order the site has ever taken,
  which is survivable on a preview you download yourself and not on a file
  that leaves the building.

= 0.14.0 =
* New: the plugin now has its own icon on the Plugins and Updates screens.
  Nothing supplied one before. A wordpress.org plugin gets its artwork from the
  .org API, and this one is served from GitHub Releases, so the update response
  had to carry the icon itself or the screens fall back to a generic plug.
* Note: the icon will not appear on this update. The installed version is what
  answers the update check, and the version being replaced has no icon to give.
  It shows from the next update onward.

= 0.13.0 =
* Change: the scheduled export email has been redesigned. It was five plain
  paragraphs; it is now a single card on a neutral gray background, with the
  site name above it and the export name as the headline. The opening "Hi," is
  gone.
* Change: the email and the Preview Export panel now name what was counted
  instead of calling everything rows. An attendees export reads "Attendees:
  1,284" and the preview says "1,284 attendees" rather than "1,284 rows". A
  report with no recognizable type falls back to "Records".
* Internal: the list of export types and their labels lived in five places, all
  of which had to be edited together to add a type. They now read from one
  constant on the exporter, covered by tests.

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
