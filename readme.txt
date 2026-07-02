=== Reloom for Human Design ===
Contributors: raybogman
Tags: human design, bodygraph, chart, reloom, ai
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Human Design for WordPress, powered by Reloom (reloom.life). Pull Bodygraph charts and AI readings through your Reloom account — no Bodygraph or AI keys needed here.

== Description ==

This plugin connects WordPress to your **Reloom** account (reloom.life). It does not call the Bodygraph or AI providers directly. Instead, one click connects the site to Reloom, and the plugin pulls data live through Reloom's API. Your Reloom plan governs which readings are available.

* **One-click Connect** — approve the site in your Reloom dashboard and you're connected; the token is delivered securely and never appears in the browser URL. Manual connect (API base URL + token) is also available.
* **Profiles** — keep a local roster of people (Birth Data form). Open a person to see their chart and readings in tabs.
* **Plan-aware** — the tab strip renders only the scopes your Reloom plan unlocks: Bodygraph chart plus the readings included in your plan.
* **Export to PDF** — a "Download PDF" button generates a real PDF (server-side) with the person's name, Bodygraph chart and readings.
* **Sync back** — optionally add profiles created here to your Reloom dashboard (subject to your plan's profile limit).

All API calls run server-side, so the token is never exposed to the browser. Reloom caches results per birth, so repeat lookups for the same person are cheap.

== Changelog ==

= 2.0.2 =
* Settings: clearer, simpler connection wording (no jargon).

= 2.0.1 =
* Fix: settings page rendered a line of raw PHP (a stray tag). Now clean.
* On upgrade from the HD Suite version, the stale HD Suite API URL + token are cleared automatically so the plugin only talks to Reloom once you connect.

= 2.0.0 =
* **Now powered by Reloom (reloom.life).** Replaces the HD Suite proxy with Reloom's `/api/v1`. Adds one-click **Connect to Reloom** (OAuth-style, PKCE — the token never rides in the browser URL). Bearer-token transport; readings gated by your Reloom plan. Manual connect still available.

= 1.11.5 =
* **PDF footer URL now appears immediately.** The export fetches branding (logo + site URL) fresh from the proxy instead of using the short-lived cache, so a logo or URL change on the HD Suite shows in the very next PDF — no waiting for the cache to expire.

= 1.11.4 =
* **Fixed: "Download PDF" never completed (button stuck on "Preparing…").** The export called the browser's fetch() but a local helper of the same name shadowed it, throwing immediately. Now calls window.fetch explicitly, so the request goes through and the PDF (with the bodygraph) downloads.

= 1.11.3 =
* **Download button can no longer hang.** Added a 45-second abort timeout on the export request so a slow/unresponsive server resolves to a clear error instead of leaving the button on "Preparing…" forever. Errors now show an on-screen message (with a version stamp) so issues are visible without opening the console.

= 1.11.2 =
* **Fixed the "Download PDF" button getting stuck on "Preparing…".** The in-browser chart rasterisation could stall on some SVGs and never call back, leaving the button spinning forever. Added a 6-second watchdog and a single-callback guard so it always proceeds (exporting without the chart if rasterising stalls), plus console diagnostics for the export step.

= 1.11.1 =
* **Bodygraph now appears in the PDF.** The server-side PDF engine couldn't draw the chart's SVG, so the chart was missing. The browser now rasterises the on-screen bodygraph to a crisp PNG (at 2× for sharpness) and sends it with the export, which the PDF engine embeds reliably — so the chart shows on the cover on every host. If rasterising isn't possible, the export still proceeds with the readings.

= 1.11.0 =
* **PDF export is now a real, downloaded PDF file.** Replaced the browser print path with true server-side PDF generation (the Dompdf library is bundled with the plugin — nothing to install on the host). "Download PDF" now downloads a proper .pdf directly: no print dialog, no browser date/URL/page-number chrome, and proper margins on every page including continuation pages of long readings. The "Powered by" footer is larger and shows a clickable site URL (fetched automatically from the connected HD Suite — no extra setting). Bundled fonts (DejaVu) ensure accents and symbols always render. Requires the common dom/mbstring/gd PHP extensions; falls back to omitting the chart if a chart can't be drawn so the readings still export.

= 1.10.4 =
* **PDF footer cleaned up.** The "Powered by" label and logo now sit on a centred, aligned row so they can't overlap, with more space above the footer and more page-bottom margin so it no longer touches the bottom (or top) edge of the page.

= 1.10.3 =
* **PDF reading titles get breathing room.** Each reading page now has generous top padding, so headings like "Quick reading" are no longer flush against the top edge of the page.

= 1.10.2 =
* **Redesigned PDF for comfortable reading.** The export is now a clean cover page (name, key facts — Type / Strategy / Authority / Profile / Definition — and the bodygraph), followed by each reading on its own page with magazine-style typography (serif body, generous line height, accent headings, orphan/widow control). Dropped the long planet/gate tables that were padding the file out to ~10 pages and causing the empty leading page.

= 1.10.1 =
* **PDF export polish.** Removed the browser's own print header/footer (the date, "about:blank" URL and page numbers) by zeroing the page margins and applying margins inside the document. The Bodygraph is now sized to fit the first page alongside the name (no more leading blank page), and the "Powered by" logo sits as a single footer at the end rather than a repeating overlay.

= 1.10.0 =
* **New: Download PDF.** A profile detail page now has a "Download PDF" button that builds a print-optimised document — name → Bodygraph chart → Quick reading → Channels deep dive → Centers deep dive — and opens the browser's print dialog so it can be saved as a PDF. The bottom of the document shows a "Powered by" logo pulled live from the connected HD Suite (Settings → Branding), so the branding can be changed centrally without updating this client. Only content already pulled for the profile is included.

= 1.9.0 =
* **Bodygraph is smaller by default with zoom in/out.** The chart now renders at a compact size with − / + zoom controls in its toolbar (35%–200%). When zoomed past the panel width the chart scrolls within its box, and your preferred zoom level is remembered (localStorage) across profiles.

= 1.8.4 =
* **Responsive split, no clipped text, no wasted space.** The detail page now uses the full available width (was capped at 1200px), so the reading panel fills its column instead of leaving a big empty area on the right. The chart's summary/variables tables now fit and wrap inside the panel instead of being clipped. Both columns scale evenly and stack on narrow screens.

= 1.8.3 =
* **Fixed the Bodygraph chart spilling past its panel border.** The chart SVG kept its intrinsic size and overflowed the left panel's right edge. It's now forced to scale to the panel width (with overflow clipped as a safety), so the chart and summary sit neatly inside the card.

= 1.8.2 =
* **Reading panel stays pinned while the chart scrolls.** The right-hand reading column is now sticky, so it stays in view as you scroll the (taller) Bodygraph chart on the left. If a reading is longer than the screen, it scrolls inside its own panel rather than pushing the page.

= 1.8.1 =
* **Polished the split-screen layout.** Both sides are now aligned tabbed cards (the chart gets its own "Chart" tab header), so the left and right panels line up perfectly — no more middle gap/overlap. Removed the duplicate heading inside each panel (the tab already names it) in favour of a compact "stored • Refresh" toolbar, and let the chart render flush inside its panel instead of nested boxes. Reading text gets a more comfortable measure and spacing.

= 1.8.0 =
* **Split-screen profile view.** The Bodygraph chart is now pinned on the LEFT at all times; the readings (Quick, Channels, Centers) appear on the RIGHT in tabs. The chart stays put while you switch reading tabs.
* **All content auto-pulled at once.** Opening a profile now fetches the chart and every shared reading together (not one tab at a time) and caches them, so everything is ready. Re-opens are instant from cache; each tab still has a Refresh button. (Note: the first open of a new person generates all readings, which uses AI on the provider — visible on the Suite's per-subscription usage/cost.)
* **Removed the Share / "View on Bodygraph" and Raw API response cards** from the client chart view.

= 1.7.2 =
* Removed the temporary diagnostic console logging now that the place-of-birth suggestions are confirmed working. (Kept a single defensive error log that only fires if a module actually fails.)

= 1.7.1 =
* **Suggestion popup now rendered on <body> (cannot be clipped).** Some admin themes/page builders apply CSS transforms or overflow:hidden to wrapper elements, which breaks position:fixed and hides the popup. The dropdown is now attached directly to the page body with the maximum z-index, so it always appears under the field regardless of the surrounding markup.

= 1.7.0 =
* **FIXED: place-of-birth suggestion popup now appears.** Root cause: the dropdown inherited the Suite stylesheet's `.rbhd-suggestions { position: absolute !important }`, which overrode the JavaScript's `position: fixed` viewport positioning, placing the popup far off-screen. The dropdown now has its own `position: fixed` styling, so the city suggestions render directly under the field as you type. (The lookup itself was working the whole time — it was purely a CSS positioning conflict.)

= 1.6.9 =
* **Fixed: the error/empty popup was rendered but never positioned**, so a failed lookup showed nothing instead of the error. The popup is now positioned in all cases. Added request/response console logging ("[rbhdc] RESPONSE …" / "[rbhdc] REQUEST FAILED …") to surface exactly what the location lookup returns.

= 1.6.8 =
* **Diagnostic build markers.** The console now logs "[rbhdc] client.js BUILD 1.6.8 loaded" on every page and "[rbhdc] place input fired" when you type in the place field — so it's possible to tell whether the latest JS is actually loaded (caching) versus a binding problem.

= 1.6.7 =
* **Place typeahead rebound as an isolated, bulletproof module.** The suggestion lookup is now bound independently of the rest of the admin JS, so a failure in any other module (filters, forms, etc.) can no longer stop it from running. Each init module is also isolated with error logging. This fixes "typing does nothing" even when the connection test succeeds.

= 1.6.6 =
* Fixed double URL-encoding of the location query (multi-word cities like "Den Haag" now resolve correctly).

= 1.6.5 =
* **The suggestion popup now always opens while typing** — with city matches, a "no matching place" row, or a loud error row (e.g. "Cannot reach the location service: …") right under the field. This makes it obvious whether the lookup is reaching the provider, instead of silently doing nothing. (If you see a connection/404 error, the provider's HD Suite needs to be 1.90.0+ and the client connected in Settings.)

= 1.6.4 =
* **Suggestion dropdown now appears reliably (fixed positioning).** The popup is pinned to the input with viewport coordinates (position: fixed), the same technique the Suite uses — so it shows correctly regardless of admin theme/overflow quirks that previously hid it. Removed the helper sentence under the field.

= 1.6.3 =
* **Suggestion dropdown now uses the HD Suite's exact mechanism.** Switched the place-of-birth dropdown to the Suite's proven `.rbhd-suggestions` styling + hidden-attribute toggling (z-index 9999, absolute positioning), so the suggestion window reliably pops up under the field as you type — identical behaviour to the Suite's birth form.

= 1.6.2 =
* **Suggestion window opens automatically while typing the place of birth.** The Place field is now full-width and a dropdown of accurate city matches (from the Bodygraph location database) appears as you type and on focus; pick one to verify and auto-fill the timezone.

= 1.6.1 =
* **Place verification now gives live feedback.** As you type a city it shows "checking…", then suggestions, then "✓ verified" / "no match" — and it auto-verifies when you leave the field even without clicking a suggestion (snaps to the best match). If the lookup can't run (not connected, or the HD Suite is older than 1.90.0 and has no /locations endpoint) it now says so instead of silently doing nothing.

= 1.6.0 =
* **Place of birth is verified before saving.** On save, the place is checked against the Bodygraph location database (through the proxy). A picked suggestion shows "✓ verified"; a typed place that can't be resolved is rejected with a clear message (unless you supply a valid IANA timezone as a fallback). Verified places snap to their canonical name + timezone, so charts always use a real, resolvable location.

= 1.5.0 =
* **Auto-sync profiles back to the HD Suite (with consent).** Settings now has a "Share created profiles back to the main environment (HD Suite)" toggle — enabled by default. When on, every profile you create here is pushed to the Suite automatically; the Suite deduplicates, so the same person is never added twice. Turn the toggle off to keep profiles local only.

= 1.4.0 =
* **Clear loading state while content is fetched.** Opening a tab (or clicking Refresh/Generate) now shows a spinner and a "this can take 10–30 seconds, please wait" message, and the button shows a working/spinner state — so it's obvious the AI reading is being generated in the background rather than the button being stuck. Network timeout raised to 90s so long readings don't fail mid-generation.

= 1.3.0 =
* **Place-of-birth city search.** The Birth Data form now has the same city typeahead as the HD Suite — type a city and pick a result; the timezone is filled in automatically (checked against the Bodygraph API through the proxy). No Bodygraph key needed on the client.

= 1.2.0 =
* **Profiles can now be edited.** Every profile has an Edit action (in the list and on the detail page) that opens the Birth Data form pre-filled; saving updates the person and refreshes their content if the birth data changed.
* **Birth Data form mirrors the HD Suite** (First/Family name, Gender, Date, Time, Place, Email, Notes) — Relation is intentionally omitted. Added an optional Email field.

= 1.1.0 =
* **Fetched content is now stored locally + persists.** Charts and readings pulled from the proxy are cached on the client (per profile), so reopening a person shows them instantly. Each tab has a **Refresh from API** button (regenerate) and a "Stored … ago" timestamp. Editing a person's birth data clears their cached content automatically.
* **Suite-style Profiles list.** Added a toolbar (Add new, Export JSON, Import JSON), search + gender + sort filters, and a **Status column** showing a Chart pill and a readings count — just like the HD Suite.
* **All active tabs show.** The detail view renders a tab per shared scope (Chart, Quick reading, Channels deep dive, Centers deep dive) with a green dot when that content is cached. Tabs only appear for scopes the subscription actually shares (enable more on the Suite → profile → Subscription).

= 1.0.0 =
* First release. Settings (API URL + token + test), local Profiles with the Birth Data form, and a suite-style detail view (Chart / Quick reading / Channels deep dive / Centers deep dive) that shows only the scopes the subscription shares — all pulled through the HD Suite proxy API.
