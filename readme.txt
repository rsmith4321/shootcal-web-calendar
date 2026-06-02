=== ShootCal Web Calendar ===
Contributors: rsmith4321
Tags: calendar, google calendar, availability, booking, ical
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show a calendar on your WordPress site as a month grid: free/busy availability (no event details) or a full calendar with event titles and times.

== Description ==

ShootCal Web Calendar embeds an iCal (.ics) calendar on any WordPress page or post, in one of two modes. **Availability** mode shows which days are **available**, **limited**, or **booked** without ever exposing your event titles, locations, attendees, or descriptions. **Full calendar** mode shows each event's title and time on the grid - handy for a public schedule like sunrise/sunset times, office hours, or class times.

Built for photographers and other service providers who want to show clients when they can book, without manually updating a calendar on their website every week.

= How it works =

Each calendar's feed URL lives on the embed itself - the block's **Calendar feed URL** field, or the shortcode's `url` attribute - so different pages can show different calendars. In WP admin, **Settings > ShootCal Web Calendar** has a shortcode generator: paste a feed URL, pick a mode, and it checks the feed and hands you a ready-to-paste shortcode. Block users just add the "ShootCal Web Calendar" block and paste the URL in its sidebar.

Any iCal feed works:

* **Google Calendar** - open your calendar's settings, scroll to **Integrate calendar**, and copy the **Secret address in iCal format**.
* **Apple, Outlook, or other iCal feeds** - paste the feed's iCal URL.
* **ShootCal app** (for ShootCal users) - open the Mac app, go to Settings > Website > Connect to your website, and click Copy URL. A ShootCal feed additionally hides your personal events, builds each day's availability from your session types, and auto-detects your timezone and how many months to show.

The plugin fetches the feed server-side, caches it for 10 minutes, and renders a clean month grid. In availability mode it keeps only busy start/end times and discards all event detail; in full calendar mode it shows the event titles and times.

= Privacy =

The plugin only reads the iCal feed server-side. The cached data lives in your WordPress site's object cache or database (transient) and is purged when you clear the cache or change settings.

The feed URL lives in the shortcode or block where you use it - treat it like a password. Note that **full calendar** mode intentionally displays event titles and times, so only point it at a feed whose contents are meant to be public.

= Features =

* Two display modes: availability (free/busy shading, no detail) or full calendar (event titles + times)
* Per-embed feed URL (shortcode `url` or block field) plus a built-in shortcode generator - show different calendars on different pages
* Works with any iCal feed: Google Calendar, Apple, Outlook, or a ShootCal app feed
* Month grid showing up to 36 months ahead, with previous / today / next navigation and keyboard arrows
* Available / Limited / Booked status per day, with booked time windows shown on Limited days and a color legend
* Tap-to-expand booking times, plus a tap indicator on phones
* Per-embed Limited / Booked day colors (availability mode) and Sunday or Monday week start (the display timezone follows your WordPress site setting)
* Optional "Page caching" mode that loads the calendar via JavaScript so it stays fresh behind Varnish or page-cache plugins
* Privacy-first, accessible markup (ARIA grid, keyboard nav, AA contrast), and assets that load only on pages that use the calendar

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install through the WordPress Plugins screen, then Activate.
2. Embed the calendar one of these ways (recommended first):

   * **Block editor (recommended):** insert the "ShootCal Web Calendar" block, then in its sidebar choose the display mode and paste the **Calendar feed URL**. The editor shows a compact placeholder; the calendar renders on the published page.
   * **Shortcode:** go to **Settings > ShootCal Web Calendar**, use the **shortcode generator** (paste a feed URL, pick a mode, generate), and paste the result into a Shortcode block. Example: `[shootcal_web_calendar url="https://example.com/feed.ics"]`.
   * **Inline shortcode in a Paragraph block:** works, but do not apply inline-code formatting (Cmd/Ctrl+E) to the shortcode text, or WordPress wraps the output in `<code>`. The plugin defends against this in CSS, but the patterns above are safer.

== Frequently Asked Questions ==

= Where do I find my calendar URL? =

For Google Calendar: open the calendar's three-dot menu > **Settings and sharing** > scroll to **Integrate calendar** > copy **Secret address in iCal format**. For ShootCal: open the Mac app > Settings > Website > Connect to your website > Copy URL. Apple, Outlook, and other apps also publish iCal URLs you can paste.

= What does a ShootCal feed add over a plain calendar? =

A ShootCal feed (from the ShootCal Mac app) hides your personal events, builds each day's availability from your session types, and auto-detects your timezone and how many months to show. Any other iCal feed simply shows busy days.

= Can I show multiple calendars merged together? =

Not yet. Planned for a future release.

= Does it support recurring events? =

Yes. Some feeds (like Google Calendar) pre-expand recurring events; for feeds that don't (such as Apple or Outlook), the plugin expands the common recurrence rules itself - daily, weekly, monthly, and yearly, including interval, count, end date, weekly by-weekday, and excluded dates - so a recurring booking shows on every occurrence within the visible window. Unusual rules (for example "the second Monday of each month") fall back to showing the first occurrence.

= How fresh is the data? =

The feed is cached for 10 minutes. You can force an immediate refresh from the settings page. If your site uses full-page caching, turn on "Page caching" mode so the calendar is fetched fresh on each visit instead of being frozen in the cached page.

== Shortcode attributes ==

* `url` - the iCal (.ics) feed URL to display (required).
* `mode` - "availability" (free/busy shading, default) or "full" (show event titles + times).
* `multi_session_day` - availability mode only. "1" (default): a day with only timed sessions shows as "Limited". "0": any event marks the whole day "Booked".
* `limited_color` - availability mode only. Hex color (e.g. #fce3a8) for the "Limited" day shading. Default: the built-in soft gold.
* `booked_color` - availability mode only. Hex color (e.g. #f6b9a3) for the "Booked" day shading. Default: the built-in soft coral.
* `months` - how many months to display (1-36). Default: setting value (auto-detected for ShootCal feeds).
* `first_day` - "0" for Sunday, "1" for Monday. Default: setting value.
* `timezone` - IANA timezone override for this embed. Default: your WordPress site timezone (or, for a ShootCal feed, the feed's own).

Example: `[shootcal_web_calendar mode="full" url="https://example.com/feed.ics" months="3"]`

Tip: build a shortcode under Settings > ShootCal Web Calendar - paste a feed URL, pick a mode, and it validates the feed and outputs the shortcode for you.

== External Services ==

This plugin reads a calendar from the iCal feed URL(s) you put in your shortcodes or blocks. Each request is made from your web server (not your visitor's browser) whenever a month grid is rendered and the 10 minute cache has expired. The plugin only ever contacts the URLs you configure; with none set, it makes no external requests. Only the request itself is sent - no data from your site or your visitors is transmitted. In availability mode the plugin keeps only busy start/end times, discarding titles, locations, attendees, and descriptions; in full calendar mode it also keeps event titles and times (by design, to display them).

The service contacted depends on the URL you use:

**Google Calendar**

If you use a Google Calendar secret iCal address, the request goes to Google's calendar servers (calendar.google.com). This service is provided by Google. See Google's Terms of Service (https://policies.google.com/terms) and Privacy Policy (https://policies.google.com/privacy).

**ShootCal**

If you use a ShootCal feed URL from the ShootCal Mac app, the request goes to the ShootCal feed service (feed.shootcal.com). This service is provided by Ryan Smith Photography. See the terms and privacy information at https://shootcal.com.

**Other iCal feeds**

If you use any other iCal URL (for example from Apple or Outlook), the request goes to whichever service hosts that feed. Refer to that provider's own terms and privacy policy.

== Screenshots ==

1. The availability month grid on a page. Open days are uncolored, gold marks Limited days (with the booked time windows shown), and coral marks fully Booked days. A legend below the grid explains the colors.

== Changelog ==

= 2.1.1 =
* Nicer loading state in Page caching mode: the placeholder now expands to the calendar's size with a centered spinner instead of a small line of text, so the swap-in doesn't jump the page. If the calendar can't be fetched, it now shows a short message instead of spinning forever.

= 2.1.0 =
* New setting: "Show ShootCal credit" (Settings > ShootCal Web Calendar, on by default). The small "Calendar provided by ShootCal" line under a ShootCal-fed calendar can now be turned off if you would rather not show it.

= 2.0.3 =
* Compliance and code-quality pass for the WordPress.org Plugin Directory: added translators comments to two strings with placeholders, reworded a settings description so it no longer reads as a format placeholder, hardened the page-caching AJAX endpoint to fully sanitize its inputs (the request was already HMAC-verified), and removed the manual translation loader (WordPress loads directory translations automatically). No change to how the calendar looks or works.

= 2.0.2 =
* Compliance: the per-embed availability colors are now applied through an inline style attribute (CSS custom properties) on the calendar element instead of a `<style>` block, so the plugin no longer prints any inline `<style>`/`<script>` tags. No visual change.

= 2.0.1 =
* Availability colors are now set per calendar, in the block and the shortcode generator, instead of one site-wide setting. Each embed can have its own Limited and Booked colors; leave them at the defaults for the built-in look.
* The display timezone now always follows your WordPress site timezone (Settings > General). The plugin's own timezone setting has been removed, which also fixes a case where that field could reset to "Select a city". You can still override the timezone per embed with the timezone attribute.
* "Months to show" now defaults to 12 (was 3). ShootCal feeds still auto-detect their range from the feed.
* Housekeeping: removed leftover migration code from the 2.0.0 rename.

= 2.0.0 =
* Renamed to ShootCal Web Calendar - it now shows any iCal calendar, not just availability. Because the plugin folder changed, WordPress treats this as a new plugin: after installing, activate "ShootCal Web Calendar" and update your embeds to the new [shootcal_web_calendar] shortcode. Your display settings carry over automatically.
* New: "Full calendar" display mode shows each event's title and time on the month grid, alongside the original availability (free/busy) view. Set it in the block settings, or with mode="full" in the shortcode.
* New: each shortcode or block carries its own iCal feed URL (the shortcode url attribute, or the block's "Calendar feed URL" field), so different pages can show different calendars.
* Changed: the single Calendar URL setting has been removed in favor of a shortcode generator on the settings page - paste a feed URL, pick a mode, and it validates the feed and builds the shortcode for you.

= 1.2.1 =
* Hardened the GitHub auto-updater so it only installs update packages hosted on GitHub.
* The public calendar-render endpoint now validates the time-zone parameter, closing a way to bloat the cache with junk entries.
* The time-zone setting now rejects an unrecognized value and keeps your previous valid zone (with a notice) instead of silently ignoring the entry.

= 1.2.0 =
* Recurring events now expand for feeds that do not pre-expand them (such as Apple or Outlook). Previously a recurring booking from those feeds showed only on its first date, which could make booked days look available. The plugin now expands the common recurrence rules - daily, weekly, monthly, and yearly, with interval, count, end date, weekly by-weekday, and excluded dates - across the visible window. Google feeds, which already pre-expand, are unaffected, and unusual rules fall back to the first occurrence.
* Performance: the rendered calendar is now cached for 10 minutes, keyed to the feed, your settings, and the day. The "Page caching" mode and uncached page views no longer rebuild the whole grid on every request - they serve the cached render and refresh every 10 minutes, or immediately when you clear the cache or change settings. No calendar data is held longer than that window.
* Fixed: a recurring event using a negative DURATION is no longer silently dropped.
* Housekeeping: fresh installs seed the current single Calendar URL settings layout, and deactivating the plugin now correctly clears the cached feed.

= 1.1.3 =
* Rounded corners fix: the card's top and bottom corners now stay cleanly rounded. The calendar keeps its corner clip off so the tap-to-expand booking popover can extend below the grid, and a side effect of that was the toolbar and footer corners poking past the rounded border. The first and last elements now round to match the card.

= 1.1.2 =
* Grid lines hardened: the divider lines between weeks and day columns, and the lines that close the bottom of the card (above and below the color legend), now stay visible under themes that zero out borders (for example a CSS reset like `* { border: 0 }`). They were previously stripped on such themes, leaving the grid and legend without their separating lines.

= 1.1.1 =
* Style isolation: the color legend and the "Calendar provided by" footer now hold their own layout against themes that style lists and paragraphs in the content area. Previously a theme's `ul` / `li` / `p` rules could strip the footer's right padding, indent the legend, or add stray bullets. The box model for both is now locked the same way the toolbar and grid already were, while colors and fonts stay overridable.

= 1.1.0 =
* Single Calendar URL: one setting field now holds the calendar URL, and the plugin auto-detects whether it is a ShootCal feed or a plain iCal feed. The old Google / ShootCal source toggle is gone. Existing setups are migrated automatically.
* Works with any iCal feed - Google Calendar, Apple, Outlook, or a ShootCal feed. A ShootCal feed additionally hides your personal events, builds availability from your session types, and auto-detects your timezone and visible months. The block and settings now explain this.
* New "Page caching" mode (optional): the calendar loads via JavaScript after the page loads, so it stays fresh even behind full-page caching like Varnish or a page-cache plugin, while the page itself stays cacheable.
* Visual refresh: a color legend below the grid, more distinct Limited (gold) and Booked (coral) colors, the redundant "Limited" word removed from cells (the times and legend convey it), a "+N more" indicator on days with several bookings, a softer drop shadow that lifts the card, a subtle fade-in (respecting reduced-motion), and AA-contrast text.
* Booking-times popover: more padding, centered text, and it now opens downward - on the bottom row it extends just below the calendar instead of covering the row above. On phones, Limited days show a small dot so visitors know to tap for the times.
* Block editor: the block shows a compact placeholder instead of trying to render the live calendar (which collapsed without the frontend stylesheet). The published page is unaffected.
* Under the hood: feeds are fetched with WordPress's safe HTTP API (blocks requests to internal addresses) and cached per URL.

= 1.0.1 =
* Style hardening so the calendar looks the same across themes: the month and year label now stays sans-serif, the toolbar buttons stay light instead of inheriting a theme's dark button style, the weekday letters no longer show an underline or help cursor, and the booked times stay left-aligned. The month grid columns are also locked in so a theme cannot reflow them.

= 1.0.0 =
* First stable release of ShootCal Web Calendar.
* No changes to the calendar, settings, or block from the prior build; existing embeds keep working unchanged.

= 0.5.3 =
* Plugin icon: the ShootCal app's shutter + calendar + sunset-gradient icon now shows next to the plugin in WP admin's Plugins list and update modal, matching the desktop app's identity. Icons are served from the GitHub repo so existing installs pick them up on the next update check (no new download needed beyond this one).

= 0.5.2 =
* Added GitHub Releases auto-updater. While the plugin is installed from GitHub (not yet on the WordPress.org directory), newer releases now show up in wp-admin > Plugins with the standard "Update available" banner and one-click upgrade flow. Same UX as a directory-listed plugin. Checks GitHub once every 12 hours; gracefully degrades when offline.
* Bumped "Tested up to" to WordPress 6.8.

= 0.5.1 =
* Booking-times popover (tap a Limited cell) now grows wider than the cell so windows like "Booked 7:30 pm - 8:30 pm" stay on a single line. Centered under the cell, with edge-detection that nudges horizontally so popovers on the leftmost/rightmost columns never spill past the calendar card.
* Popover styled as a standalone floating rounded card with a small gap from the cell, instead of trying to "join" the cell with matching radii (which broke alignment once the popover could be wider than the cell).
* Toolbar typography: month name now uses a heavier weight (800) and slightly tighter tracking; year is rendered ~82% size in the sunset accent color, matching the desktop app.
* Settings: added color pickers for the Limited and Booked cell tints. Cells render at 80% opacity at rest and lift to the picked color at full opacity on hover or when the popover is open, so the hover state is always the "full strength" version of the chosen color.
* Calendar grid now always renders a full 6-week month view (matching the macOS app), and surfaces events on off-month days. Only the date number is muted to signal which month they actually belong to.

= 0.5.0 =
* Added a native Gutenberg block: search "ShootCal Web Calendar" in the block inserter (under Widgets). The block server-renders the same calendar as the `[shootcal_web_calendar]` shortcode, so output is identical and there is no duplicate caching logic.
* Block inspector sidebar lets you override months, first day of week, or timezone per-embed without changing the global settings.
* Shortcode keeps working unchanged. Use whichever feels right - the block for visual editing, the shortcode for classic editor / page builders.
* Fixed: timezone priority bug when the saved plugin timezone setting was non-empty and a ShootCal feed embedded `X-WR-TIMEZONE`. The feed's timezone now correctly wins over the saved fallback.

= 0.4.0 =
* Calendar view is now paginated like a real calendar app: a small toolbar shows the current month label with Previous / Next arrows and a Today button. One month is visible at a time instead of all months stacked.
* Keyboard navigation: Left / Right arrow keys when focused inside the calendar move between months. "T" key jumps to today's month.
* New three-state busy logic: days with an all-day event show "Booked", days with only timed events show "Limited" (signaling another client could still book a different time of day), and days with no events show "Available".
* New "Sessions per day" setting: check the "I can fit more than one client per day" box (default) to enable the Limited state, or uncheck it to roll timed events into "Booked" alongside all-day events.
* Past dates are now visually muted. Today's date is highlighted with a subtle ring.
* Calendar wrapped in a card chrome for a polished standalone-widget look. All chrome colors themable via CSS custom properties.
* Underlying layout switched from HTML table to CSS Grid (with proper ARIA grid semantics preserved).
* No-JS fallback keeps all months visible (degraded but readable).

= 0.3.0 =
* Raised the months-to-show cap from 12 to 36, so wedding photographers and other long-lead-time bookers can show 2 or 3 years of availability.
* Performance: pre-bucket events by day so each cell does an O(1) lookup instead of iterating every event.
* Fixed: all-day events stored as midnight-UTC no longer shift into the previous calendar day for viewers in negative-offset timezones. Per RFC 5545, all-day events are floating dates with no timezone.
* When the source is ShootCal, the visible range and timezone auto-detect from the feed, and a small "Calendar provided by ShootCal by Ryan Smith Photography" attribution appears beneath the grid.

= 0.2.0 =
* Add source toggle: choose between Google Calendar (secret iCal URL) and ShootCal app feed URL.
* Add Test connection button on the settings page.
* Settings sections reorganized: Calendar source vs Display.

= 0.1.0 =
* Initial release: settings, iCal fetcher with transient cache, RFC 5545 parser (events only), month grid shortcode.
