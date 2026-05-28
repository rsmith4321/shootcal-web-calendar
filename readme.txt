=== ShootCal Availability ===
Contributors: rsmith4321
Tags: calendar, google calendar, availability, booking, ical
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show Google Calendar availability on your WordPress site as a month grid - busy days only, never event details.

== Description ==

ShootCal Availability lets you embed a privacy-respecting view of your Google Calendar on any WordPress page or post. Visitors see which days are **busy** and which are **available**, without ever seeing your event titles, locations, attendees, or descriptions.

Built for photographers and other service providers who want to show clients when they can book - without manually updating a calendar on their website every week.

= How it works =

You can read your availability from either source:

**Option 1: Google Calendar (works for anyone)**
1. In Google Calendar, open your calendar's settings, scroll to **Integrate calendar**, and copy the **Secret address in iCal format**.
2. In WP admin, go to Settings > ShootCal Availability, choose **Google Calendar** as the source, and paste the URL.

**Option 2: ShootCal app (for ShootCal users, tighter integration)**
1. Open the ShootCal Mac app, go to Settings > Website > Connect to your website, and click Copy URL.
2. In WP admin, go to Settings > ShootCal Availability, choose **ShootCal** as the source, and paste the URL.

Then add the shortcode `[shootcal_availability]` to any page or post.

The plugin fetches the iCal feed, throws away every detail except start/end times, caches the result for 10 minutes, and renders a clean month grid.

= Privacy =

The plugin only reads the iCal feed server-side. It never sends event titles or locations to the browser. The cached data lives in your WordPress site's database (transient) and is purged on plugin deactivation and on settings changes.

The secret iCal URL itself is stored in your site's options table - treat it like a password.

= Features =

* Two source options: Google Calendar secret iCal URL OR ShootCal app feed URL
* Month grid showing 1-12 months ahead
* Busy / Available status per day
* Optional busy time ranges (off by default for privacy)
* Sunday or Monday week start
* Per-site display timezone
* Manual cache clear button
* Conditional CSS loading (no styles enqueued on pages without the shortcode)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install through the WordPress Plugins screen.
2. Activate through the Plugins screen.
3. Go to **Settings > ShootCal Availability** to paste your iCal URL.
4. Embed the calendar one of these ways (in order of recommended):

   * **Block editor (recommended):** insert the "ShootCal Availability" block from the inserter. Live preview in the editor + sidebar override controls for months / first day / timezone.
   * **Shortcode block:** if you prefer raw shortcodes, use the built-in "Shortcode" block and paste `[shootcal_availability]`. This stores it without any text formatting.
   * **Inline shortcode in a Paragraph block:** works, but be careful not to apply inline-code formatting (Cmd/Ctrl+E) to the shortcode text. If you do, WordPress will wrap the output in `<code>` and the calendar layout will collapse. The plugin defends against this with CSS, but the block-editor patterns above are safer.

== Frequently Asked Questions ==

= Where do I find my secret iCal URL? =

In Google Calendar: open the calendar's three-dot menu > **Settings and sharing** > scroll down to **Integrate calendar** > copy **Secret address in iCal format**.

= Can I show multiple calendars merged together? =

Not yet. Planned for a future release.

= Does it support recurring events? =

Google's iCal feed pre-expands recurring events for the visible window, so weekly/monthly bookings show up correctly without the plugin needing to handle RRULE expansion itself.

= How fresh is the data? =

The iCal feed is cached for 10 minutes. You can force an immediate refresh from the settings page.

== Shortcode attributes ==

* `months` - how many months to display (1-12). Default: setting value.
* `show_times` - "1" to show busy time ranges, "0" to hide. Default: setting value.
* `first_day` - "0" for Sunday, "1" for Monday. Default: setting value.
* `timezone` - IANA timezone for display. Default: setting value.

Example: `[shootcal_availability months="2" show_times="1"]`

== Changelog ==

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
* Added a native Gutenberg block: search "ShootCal Availability" in the block inserter (under Widgets). The block server-renders the same calendar as the `[shootcal_availability]` shortcode, so output is identical and there is no duplicate caching logic. Live preview in the editor uses WP core's ServerSideRender component.
* Block inspector sidebar lets you override months, first day of week, or timezone per-embed without changing the global settings.
* Shortcode keeps working unchanged. Use whichever feels right - the block for visual editing, the shortcode for classic editor / page builders.
* Fixed: timezone priority bug when the saved plugin timezone setting was non-empty and a ShootCal feed embedded `X-WR-TIMEZONE`. The feed's timezone now correctly wins over the saved fallback. Affected sites whose WordPress timezone was misconfigured at UTC even though the photographer's local timezone differed.

= 0.4.0 =
* Calendar view is now paginated like a real calendar app: a small toolbar shows the current month label with Previous / Next arrows and a Today button. One month is visible at a time instead of all months stacked. Familiar pattern from Google Calendar, Apple Calendar, etc.
* Year tabs removed - the new toolbar covers cross-year navigation cleanly with the arrows.
* Keyboard navigation: Left / Right arrow keys when focused inside the calendar move between months. "T" key jumps to today's month.
* New three-state busy logic: days with an all-day event show "Booked" (red), days with only timed events show "Limited" (amber, signaling "could still fit another client at a different time of day"), and days with no events show "Available" (green). Photographers who book sunsets and mornings on the same day get a much more accurate picture.
* New "Sessions per day" setting: check the "I can fit more than one client per day" box (default) to enable the Limited state, or uncheck it to roll timed events into "Booked" alongside all-day events. Wedding-only photographers and others who lock the whole day per session will uncheck.
* Past dates are now visually muted (grey, no busy/available pill). Visitors care about future bookable days; past days are just orientation context.
* Today's date is highlighted with a subtle ring so you can spot it at a glance.
* Calendar wrapped in a card chrome (light border, rounded corners, subtle shadow) for a polished standalone-widget look. All chrome colors themable via CSS custom properties.
* Underlying layout switched from HTML table to CSS Grid (with proper ARIA grid semantics preserved) for cleaner styling and equal-width columns regardless of cell content.
* Cell height bumped from 4.5rem to 5.5rem for better legibility.
* No regression for single-month embeds (the toolbar is suppressed when there's nothing to navigate).
* No-JS fallback keeps all months visible (degraded but readable).

= 0.3.0 =
* Raised the months-to-show cap from 12 to 36, so wedding photographers and other long-lead-time bookers can show 2 or 3 years of availability.
* Added year tabs: when the visible range spans more than one calendar year, year tabs appear above the grid (2026 / 2027 / 2028). The current year is selected by default. Tabs are keyboard-accessible (Left/Right/Home/End arrows).
* Single-year ranges render exactly as before (no tab chrome).
* JS is small, vanilla, deferred, and only loaded on pages that use the shortcode.
* Performance: pre-bucket events by day so each cell does an O(1) lookup instead of iterating every event. Measured ~150x faster on a 36-month / 200+ event render.
* Fixed: all-day events stored as midnight-UTC no longer shift into the previous calendar day for viewers in negative-offset timezones (e.g., the Americas). Per RFC 5545, all-day events are floating dates with no timezone.
* When the source is ShootCal, the "Months to show" setting is hidden and the visible range is auto-detected from the feed itself (current month through the month containing the latest event). The push horizon is configured in the ShootCal app instead. The `months` shortcode attribute still works as a cap for compact widgets like sidebars.
* When the source is ShootCal, a small attribution line appears beneath the grid: "Calendar provided by ShootCal by Ryan Smith Photography" with a link to the app page. Not shown for Google source.
* When the source is ShootCal, the "Display timezone" setting is hidden and the timezone is auto-detected from the feed (the ShootCal Mac app embeds an X-WR-TIMEZONE header in its iCal output). Falls back to your WordPress general timezone setting if the feed does not specify one. This means a ShootCal user does not need to set the timezone in two places.

= 0.2.0 =
* Add source toggle: choose between Google Calendar (secret iCal URL) and ShootCal app feed URL.
* Add Test connection button on the settings page for either source.
* Conditional field display: only the field for the active source is shown.
* Settings sections reorganized: Calendar source vs Display.
* Soft host validation warns when a URL doesn't match the chosen source.

= 0.1.0 =
* Initial release: settings, iCal fetcher with transient cache, RFC 5545 parser (events only), month grid shortcode.
