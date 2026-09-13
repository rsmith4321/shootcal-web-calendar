# Optimizer compatibility contracts

Verified on 2026-09-13 against public vendor documentation and the source versions
below. This is an API and matcher review, not a claim that each paid optimizer or
combination of settings was installed and tested. No optimizer options are written.

The runtime protects only the frontend calendar stylesheet, two plugin startup
scripts, the official `api.shootcal.com/embed.js` loader where the API can match it,
and the `ShootCalWebCalendarFront` inline configuration where applicable. Existing
list entries, flags, and decisions for other assets remain unchanged.

`compatibility-regression.php` uses WordPress's real filter engine and HTML tag
processor. It checks the vendor data shapes and literal/regex matcher outcomes,
including existing-rule preservation and unrelated-resource negatives. Its SQL
guard rejects mutations. It does not contact vendor optimization services.

## Existing integrations

| Vendor | Filters and contract | Primary evidence |
| --- | --- | --- |
| Perfmatters | `perfmatters_rucss_excluded_stylesheets`, `perfmatters_delay_js_exclusions`, `perfmatters_defer_js_exclusions`, `perfmatters_minify_js_exclusions`: arrays of literal URL/inline fragments | [Filter reference](https://perfmatters.io/docs/filters/), [Remove unused CSS](https://perfmatters.io/docs/remove-unused-css/) |
| WP Rocket | `rocket_rucss_external_exclusions` and `rocket_rucss_safelist`: arrays, full stylesheet path; `rocket_delay_js_exclusions`, `rocket_exclude_defer_js`, `rocket_exclude_js`, `rocket_minify_excluded_external_js`: regex arrays; `rocket_defer_inline_exclusions`, `rocket_excluded_inline_js_content`: literal inline fragments | [Official optimization source](https://github.com/wp-media/wp-rocket/tree/34ae5057acd6bcf9ae9ced63db9eeb2717cee68b/inc/Engine/Optimization) |
| LiteSpeed Cache | `litespeed_optimize_css_excludes`, `litespeed_optimize_js_excludes`, `litespeed_optm_js_defer_exc`, `litespeed_optm_gm_js_exc`: arrays of literal partial strings. CSS exclusion occurs before unused-CSS processing. Guest Mode uses a separate JS list. | [Public API](https://docs.litespeedtech.com/lscache/lscwp/api/), [Optimizer source](https://github.com/litespeedtech/lscache_wp/blob/master/src/optimize.cls.php), [Literal matcher](https://github.com/litespeedtech/lscache_wp/blob/master/src/utility.cls.php) |
| Autoptimize | `autoptimize_filter_css_exclude`: CSV; `autoptimize_filter_js_exclude`: CSV or pattern-keyed array with per-entry flags; `autoptimize_filter_css_defer_excluded`: bool, complete stylesheet tag. Keep the original full stylesheet synchronous. | [Scripts](https://github.com/futtta/autoptimize/blob/beta/classes/autoptimizeScripts.php), [Styles](https://github.com/futtta/autoptimize/blob/beta/classes/autoptimizeStyles.php) |

WP Rocket regex entries are `preg_quote`d with `#` as delimiter. CSS external
exclusions are literal strings because Rocket quotes those itself. Autoptimize's
keyed-array form receives `path => ''`, preserving any pre-existing `remove`,
`async`, or `defer` flags; numeric array values would be ineffective.

## Added integrations

### WP-Optimize 4.6.1

[Minify functions](https://plugins.trac.wordpress.org/browser/wp-optimize/tags/4.6.1/minify/class-wp-optimize-minify-functions.php)
and [frontend processing](https://plugins.trac.wordpress.org/browser/wp-optimize/tags/4.6.1/minify/class-wp-optimize-minify-front-end.php):

- `wp-optimize-minify-default-exclusions` (functions:946) and
  `wp-optimize-minify-blacklist` (functions:1003) receive arrays of literal partial
  asset URLs. `in_arrayi` strips schemes/query strings and matches case-insensitively
  after URL decoding. User rules can explicitly remove a default exclusion.
- The blacklist is necessary for the `async_using_js` mode: `defer_js` checks it at
  frontend:397 before returning a rewritten wrapper at436. The ordinary ignore
  list is checked later at442. Using only default exclusions misses that mode.
- This integration covers the public minify/combine/defer/async implementation.
  Separate delay features in editions without a verified public hook are not
  claimed. Inline JS may still receive ordinary whitespace minification.

### SiteGround Speed Optimizer 7.8.2

[Minifier](https://plugins.trac.wordpress.org/browser/sg-cachepress/tags/7.8.2/core/Minifier/Minifier.php),
[script combining](https://plugins.trac.wordpress.org/browser/sg-cachepress/tags/7.8.2/core/Combinator/Js_Combinator.php),
[style combining](https://plugins.trac.wordpress.org/browser/sg-cachepress/tags/7.8.2/core/Combinator/Css_Combinator.php),
[async processing](https://plugins.trac.wordpress.org/browser/sg-cachepress/tags/7.8.2/core/Front_End_Optimization/Front_End_Optimization.php):

- `sgo_css_minify_exclude`, `sgo_css_combine_exclude`, `sgo_js_minify_exclude`,
  `sgo_javascript_combine_exclude`, `sgo_js_async_exclude`: arrays of exact WordPress
  handles, not paths. Async processing compares handles at frontend:236.
- `sgo_javascript_combine_excluded_external_paths` (JS:888) and
  `sgo_javascript_combine_excluded_internal_paths` (JS:902): arrays of literal URL
  fragments, matched with `strpos`. These also cover the standalone embed loader
  and assets served from another hostname during HTML combination.
- `sgo_javascript_combine_excluded_inline_content` (JS:853): literal inline-code
  fragments; protects the localized configuration separately.

### Hummingbird 3.21.2

[Asset groups](https://plugins.trac.wordpress.org/browser/hummingbird-performance/tags/3.21.2/core/modules/minify/class-minify-group.php),
[minification module](https://plugins.trac.wordpress.org/browser/hummingbird-performance/tags/3.21.2/core/modules/class-minify.php),
[exclusions](https://plugins.trac.wordpress.org/browser/hummingbird-performance/tags/3.21.2/core/modules/class-exclusions.php):

- `wphb_minify_resource`, `wphb_combine_resource`, `wphb_defer_resource`,
  `wphb_async_resource`, `wphb_inline_resource`: bool decision, string handle,
  `scripts`/`styles` type, URL. Group source:417/431/446/462/478 establishes the
  actual argument order, which differs from some docblock ordering. Return false
  only for our handles or asset URLs. Priority20 follows the vendor's saved
  per-handle choices at priority10 (minify module:137–142).
- `wphb_delay_js_exclusions` (exclusions:635): regex array matched against complete
  script tags. Quote literal paths with `preg_quote(..., '#')`.
- `wphb_critical_css_exclusions` (exclusions:715): literal partial stylesheet URLs.
  The critical-CSS module checks it before removing used CSS or deferring the
  original stylesheet until interaction. Keeping the whole file retains states
  absent from a crawler's initial HTML.
- No explicit resource-unload rule is overridden.

### W3 Total Cache 2.10.5

Public source commit `c2859d338000c12f95c193e9272bbacb45ef54d7`:
[automatic JS](https://github.com/BoldGrid/w3-total-cache/blob/c2859d338000c12f95c193e9272bbacb45ef54d7/Minify_AutoJs.php),
[automatic CSS](https://github.com/BoldGrid/w3-total-cache/blob/c2859d338000c12f95c193e9272bbacb45ef54d7/Minify_AutoCss.php),
[Defer Scripts](https://github.com/BoldGrid/w3-total-cache/blob/c2859d338000c12f95c193e9272bbacb45ef54d7/UserExperience_DeferScripts_Mutator.php):

- `w3tc_minify_js_do_tag_minification` (JS:259) and
  `w3tc_minify_css_do_tag_minification` (CSS:245): bool, complete original tag,
  vendor-resolved filename. Returning false preserves queue/tag boundaries and
  allows other resources to remain optimized. Removing tags from the earlier scan
  list could instead combine unrelated scripts across a calendar startup tag.
- W3TC's separate Defer Scripts feature uses a saved explicit inclusion list,
  with no verified narrow exclusion filter. Keep these scripts out of that list.
  This integration does not alter W3TC's manual minification configuration.
- Inline scripts take a separate branch before the per-tag external-script filter.
  Their processing remains vendor-owned; the regression does not claim otherwise.

### FlyingPress

[JS minification](https://docs.flyingpress.com/en/articles/11405981-exclude-files-from-javascript-minification)
and [CSS minification](https://docs.flyingpress.com/en/articles/11405990-exclude-files-from-css-minification):
`flying_press_exclude_from_minify:js` and `flying_press_exclude_from_minify:css`
receive arrays of case-sensitive literal URL fragments. These are minification
exclusions only. The vendor explicitly says defer/delay remains separately
configurable. No verified public narrow delay or unused-CSS filter was found in
the current developer reference, so the plugin does not invent one.

[Delay All JavaScript](https://docs.flyingpress.com/en/articles/11406701-delay-all-javascript)
documents the manual partial-keyword exclusion field (case-insensitive whole-tag
matching). Use the calendar script paths and inline marker there when enabled.

### NitroPack 1.20.0

[AjaxShortcodes.php:167–175](https://plugins.trac.wordpress.org/browser/nitropack/tags/1.20.0/classes/Feature/AjaxShortcodes.php)
uses WordPress's `wp_inline_script_attributes` filter to add the boolean
`nitro-exclude` attribute. NitroPack's own scripts also use it in
`classes/WordPress/Scripts.php` and `functions.php`.
The [NitroPack support team](https://wordpress.org/support/topic/collaborative-effort-to-address-performance-conflict-4/)
also recommends this attribute as its script-level optimization exclusion.

The calendar adds this attribute to its enqueued frontend scripts using the real
WordPress HTML tag processor and to the exact `shootcal-web-calendar-js-extra`
localized configuration ID. Existing attributes, native defer, CSP nonces, inline
code, and other script tags remain intact. An enqueued official embed loader can
also be recognized by its URL. A standalone snippet outside the WordPress enqueue
pipeline does not pass these filters.

No equivalent CSS attribute contract was verified. Use the vendor's
[Excluded Resources](https://support.nitropack.io/en/articles/8390302-excluded-resources)
settings for CSS and standalone snippets: `*` wildcards around partial paths or
inline markers, correct CSS/JS and external/inline resource types. Leaving
Excluded Operations empty excludes the resource from all optimization and loading
changes. Selecting only Minify/Optimize does not exclude the loading strategy;
see [Excluded Operations](https://support.nitropack.io/en/articles/8390303-excluded-operations).

## Operational limits

- These filters affect newly generated output; an existing page, used-CSS, or CDN
  cache must be regenerated after upgrade.
- The hosted ShootCal iframe loads its own CSS from ShootCal. WordPress CSS
  optimization primarily matters for the plugin's native iCal renderer.
- A site can still explicitly unload the plugin with a Script Manager, remove a
  default exclusion, or install another filter that overrides these decisions.
- Do not claim universal optimizer compatibility or real-vendor UI testing from
  the source-contract regression suite alone.
