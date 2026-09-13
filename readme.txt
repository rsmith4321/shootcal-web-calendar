=== ShootCal Web Calendar ===
Contributors: rsmith4321
Tags: calendar, google calendar, availability, booking, ical
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your live ShootCal calendar with its ID, or display an iCal calendar from Google, Apple, Outlook, or another provider.

== Description ==

**ShootCal: paste your calendar ID.** Add the ShootCal Web Calendar block, choose ShootCal, and paste the WordPress calendar ID from **ShootCal > Clients & Booking > Connect to website**. Your website displays the live ShootCal calendar or booking page. It automatically adjusts its height when visitors navigate or open booking forms, and follows updates made in ShootCal.

Choose **Follow ShootCal settings** to show booking when enabled in your account, or **Calendar only** to always show the month calendar. Your ShootCal timezone, availability, and booking rules remain managed in ShootCal.

**Other calendars: paste an iCal feed URL.** Choose Other calendar (iCal) for Google Calendar, Apple, Outlook, or another provider. WordPress fetches the feed on your server and draws a month grid, with either free/busy availability or event titles and times. Each block or shortcode can use a different calendar.

**Existing embeds keep working.** The ShootCal ID field also accepts a ShootCal embed URL, feed URL, iframe snippet, or the official embed.js script snippet. Recognized references are converted into an ID and validated display options. Pasted JavaScript is never executed. Existing shortcodes and blocks using the url attribute remain supported.

= Why an ID instead of embed code? =

The plugin handles the embedded page and automatic resizing for you. It provides the same iframe and height-listener behavior as the ShootCal script embed, without requiring you to paste HTML or JavaScript into WordPress. The hosted calendar loads from ShootCal when visitors view it, independently of WordPress page caching.

= Other iCal calendars =

* Google Calendar: Settings > Integrate calendar > Secret address in iCal format.
* Apple, Outlook, and other providers: use the published HTTP or HTTPS iCal feed URL.
* Availability mode shows Available, Limited, or Booked days and busy time windows. Event titles and descriptions are not displayed.
* Full calendar mode displays event titles and start times. Use it only for information intended to be public.
* Adjust months, week start, timezone, and availability colors in the block or shortcode. ShootCal > Calendar contains site defaults for locally rendered feeds.
* Feeds are cached for 10 minutes. Optional Page caching mode refreshes the rendered calendar after page load using a protected request that does not expose the private feed URL in the page markup.

= Privacy and external services =

A ShootCal calendar ID is a public embed identifier, not your account password or API key. Visitors load the hosted page from **api.shootcal.com**; ShootCal therefore receives the normal browser request information, including the visitor's IP address. Booking forms send information entered by visitors directly to ShootCal. See ShootCal terms and privacy information at https://shootcal.com.

For other iCal feeds, requests originate from your WordPress server and go to the provider you configure. The plugin stores the feed URL in your WordPress content and caches the raw feed in your site's object cache or database. Treat a private feed URL like a password and grant editing access only to trusted users. The plugin renders selected calendar information for visitors; it does not publish the private feed URL as part of the page-caching request.

After upgrading, clear your full-page/CDN caches so old iCal placeholders are replaced with protected requests. Older versions exposed private iCal addresses in Page caching mode; if you used that mode with a private feed, replace its private address with your provider and update your embeds. ShootCal hosted calendar IDs are public and are not affected.

Google Calendar is provided by Google: https://policies.google.com/terms and https://policies.google.com/privacy. For Apple, Outlook, and other feeds, consult the selected provider's terms and privacy policy. No calendar requests are made until you configure a source.

== Installation ==

1. Install and activate ShootCal Web Calendar. Requires WordPress 6.4 or later and PHP 8.1 or later.
2. Add the **ShootCal Web Calendar** block to a page or post.
3. Choose **ShootCal** and paste your calendar ID, or choose **Other calendar (iCal)** and paste the feed URL.
4. Preview the page to verify the calendar before publishing.

For the classic editor or a page builder that accepts shortcodes, use the generator under **ShootCal > Calendar** and paste its result into a Shortcode block or the builder's shortcode field.

== Frequently Asked Questions ==

= Do I need the plugin to use ShootCal on WordPress? =

No. You can also paste ShootCal's script snippet into a suitable Custom HTML or Embed block. The plugin adds an ID field, a dedicated block, shortcode support, and optional rendering of other iCal feeds.

= Can I paste my existing iframe or script code? =

Yes. Paste it into the ShootCal calendar ID field. The plugin accepts the official ShootCal iframe src, embed.js data-src, and embed.js data-shootcal formats. Existing saved URL-based embeds remain supported. Booking-page IDs from a /book/ URL are different from calendar IDs and are not accepted as calendar embed references.

= Does it support recurring events from iCal feeds? =

The local renderer expands common daily, weekly, monthly, and yearly recurrence rules, including intervals, counts, end dates, weekly weekdays, and excluded dates. Advanced rules such as the second Monday of each month fall back to their first occurrence. Check a representative range of dates before relying on an external feed for availability. Hosted ShootCal embeds use the calendar supplied by ShootCal.

= How do multiple calendars work? =

Add a separate block or shortcode for each calendar. Each embedded ShootCal frame resizes independently. Calendars are displayed separately; merging feeds is not supported.

= Do I need to exclude the calendar in my performance plugin? =

Perfmatters, WP Rocket, LiteSpeed Cache, and Autoptimize receive automatic exclusions for this plugin's frontend stylesheet and calendar startup scripts, including the official standalone ShootCal embed loader. The inline calendar configuration is protected with its script. This keeps dynamically loaded calendar styles available and prevents startup from waiting for a visitor's first interaction. Existing optimizer settings and other files are preserved.

Clear generated/used CSS and page/CDN caches once after updating so old optimized pages are regenerated. The exclusions are supplied through each optimizer's filters and may not appear in its saved settings fields. They do not override an explicit Script Manager rule that unloads the plugin entirely.

For other optimizers, exclude `/shootcal-web-calendar/assets/css/frontend.css` from unused-CSS removal, and exclude `/shootcal-web-calendar/assets/js/`, `ShootCalWebCalendarFront`, and `api.shootcal.com/embed.js` from script delays or combination. Hosted ShootCal content has its own stylesheet inside the iframe.

== Shortcode attributes ==

ShootCal example: `[shootcal_web_calendar calendar_id="YOUR_CALENDAR_ID"]`

Calendar-only example: `[shootcal_web_calendar calendar_id="YOUR_CALENDAR_ID" view="calendar"]`

Other iCal example: `[shootcal_web_calendar source="ical" url="https://example.com/calendar.ics" mode="availability" months="12"]`

* `calendar_id` - ShootCal's public calendar/embed ID. Recommended for ShootCal.
* `source` - Optional: shootcal or ical. Omit to detect existing URL-based embeds automatically.
* `url` - An iCal feed URL, or a legacy ShootCal embed reference. Existing usage remains supported.
* `view` - ShootCal only: default follows ShootCal settings; calendar always shows the month calendar.
* `months` - Calendar display range, 1-36. ShootCal defaults to 12; other iCal feeds use your saved calendar default (initially 12). Imported month counts are preserved.
* `first_day` - Calendar week start: 0 for Sunday, 1 for Monday.
* `mode` - availability (default) or full. Full shows event titles and times from an iCal feed.
* `timezone` - Local iCal renderer only: an IANA identifier, such as America/New_York. Defaults to your WordPress site timezone.
* `multi_session_day` - Local availability renderer only: 1 (default) shows timed bookings as Limited; 0 marks a day with any booking as Booked.
* `limited_color`, `booked_color` - Local availability renderer only: optional hex colors.

Imported ShootCal URLs retain validated presentation parameters for compatibility. Their appearance follows the options currently supported by the hosted ShootCal page. Local iCal display settings do not change hosted ShootCal booking rules.

== Screenshots ==

1. A calendar embedded in a WordPress page.

== Upgrade Notice ==

= 2.5.1 =
Adds automatic performance-plugin exclusions and a 12-month ShootCal default. Clear generated CSS and page/CDN caches once after updating.

= 2.5.0 =
Adds ShootCal calendar IDs and fixes iCal feed privacy. Clear page/CDN caches after upgrading. If you used a private iCal URL with Page caching, replace that URL with your provider.

== Changelog ==

= 2.5.1 =
* Automatically protect calendar styles and startup scripts in Perfmatters, WP Rocket, LiteSpeed Cache, and Autoptimize.
* Group Calendar and Social Feed under one ShootCal sidebar menu while preserving existing settings links.
* Include the official standalone ShootCal embed script and the calendar's inline configuration in script exclusions.
* Default new ShootCal calendars to 12 months while preserving chosen and imported month counts.
* Replace manual optimization warnings with guidance describing automatic support.
* Add a branded calendar setup panel with a direct link to Clients & Booking in ShootCal.

= 2.5.0 =
* Connect ShootCal using a calendar ID in the block or shortcode generator.
* Import existing ShootCal URLs, iframe snippets, and official script snippets without executing pasted code.
* Add separate ShootCal and other iCal source choices, with relevant display controls for each.
* Preserve supported ShootCal embed presentation options and resize repeated embeds independently.
* Protect private iCal feed URLs in page-caching requests.
* Correct the minimum supported PHP version to 8.1.
* Keep recurring and long-running iCal events accurate across the full displayed month grids.
* Preserve explicit Sunday week starts when Page caching is enabled.

= 2.4.1 =
* Verified compatibility with WordPress 7.1. No functional changes.

= 2.4.0 =
* iframe-first: pasting your ShootCal embed is now the primary way to add a calendar. Paste the full embed snippet (the iframe) or its URL into the block, the shortcode generator, or the shortcode `url` - the plugin lifts out the embed and just displays your live calendar.
* A pasted ShootCal embed now keeps the display options it was generated with (months, mode, week start), so it shows exactly as ShootCal made it.
* The shortcode generator recognizes a ShootCal embed and skips the iCal feed test (the embed serves a live calendar, not a feed), so it generates instantly.
* Settings, block, and readme copy reworded to lead with the ShootCal embed; generic iCal feeds (Google, Apple, Outlook) keep the existing local renderer, unchanged.
* ShootCal embeds now fill the full width of their container (removed the built-in max-width cap), matching the standalone embed.
* Readme note: ShootCal app users can now paste a ready-made embed snippet from Settings > Booking into any site builder's HTML block — this plugin is optional and remains for shortcode/block users and generic iCal feeds.

= 2.2.0 =
* ShootCal feeds now render through the hosted, always-current ShootCal embed, so the calendar on your site stays identical to the one on shootcal.com and automatically gains new features — including client self-booking, where visitors can request an open date right from the calendar. Other calendar feeds (Google, Apple, Outlook) are unchanged.

= 2.1.6 =
* Page caching mode now shows a simple "Loading calendar…" line instead of a spinner while the calendar loads. The load is near-instant, and plain text can't be restyled into an odd shape by a theme or CSS optimizer.

= 2.1.5 =
* Removed the bundled self-updater. Updates are now delivered exclusively through the WordPress.org plugin directory, the same as any other directory-listed plugin. Smaller, simpler plugin with one update path.

= 2.1.4 =
* Nicer loading spinner in Page caching mode: replaced the CSS-border spinner (which could look like a lone half-circle) with a crisp inline-SVG ring and a rounded sweeping arc in the sunset accent.

= 2.1.3 =
* Simplified the "Remove Unused CSS" note (Page caching mode): it now tells you to add the single path `/shootcal-web-calendar/` to your optimizer's stylesheet exclusion list, which is the format Perfmatters and WP Rocket expect, and reminds you to clear/regenerate the used CSS and page cache afterward.

= 2.1.2 =
* Added a note on the settings page (shown when Page caching mode is on): if you use a "Remove Unused CSS" optimizer such as Perfmatters or WP Rocket, exclude this plugin's stylesheet from it. In that mode the calendar loads via JavaScript, so those tools don't see its styles in the page HTML and can strip them, leaving the calendar unstyled. The note gives the exact handle/file to exclude.

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
