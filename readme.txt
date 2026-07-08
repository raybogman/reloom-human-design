=== Reloom for Human Design ===
Contributors: raybogman
Tags: human design, bodygraph, chart, reloom, ai
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Human Design for WordPress, powered by Reloom. Pull Bodygraph charts and AI readings through your Reloom account — no API keys needed here.

== Description ==

This plugin connects WordPress to your **Reloom** account (reloom.life). It does not call the Bodygraph or AI providers directly. Instead, one click connects the site to Reloom, and the plugin pulls data live through Reloom's API. Your Reloom plan governs which readings are available.

* **One-click Connect** — approve the site in your Reloom dashboard and you're connected; the token is delivered securely and never appears in the browser URL. Manual connect (API base URL + token) is also available.
* **Profiles** — keep a local roster of people (Birth Data form). Open a person to see their chart and readings in tabs.
* **Plan-aware** — the tab strip renders only the scopes your Reloom plan unlocks: Bodygraph chart plus the readings included in your plan.
* **Export to PDF** — a "Download PDF" button generates a real PDF (server-side) with the person's name, Bodygraph chart and readings.
* **Sync back** — optionally add profiles created here to your Reloom dashboard (subject to your plan's profile limit).

All API calls run server-side, so the token is never exposed to the browser. Reloom caches results per birth, so repeat lookups for the same person are cheap.

== External services ==

This plugin relies on **Reloom** (https://reloom.life), a hosted Human Design service, to compute Bodygraph charts and generate AI readings. This processing happens on Reloom's servers and cannot be performed locally by the plugin.

Data sent to Reloom, and when:

* **Connecting** — when you click "Connect to Reloom" (or connect manually), the plugin exchanges an authorization code for an access token with your Reloom account. Your site's settings page URL is sent as the return address.
* **Charts and readings** — when you open a profile or click Refresh, the profile's birth data (name, gender, birth date, birth time, place of birth, timezone) is sent to Reloom to compute the chart and generate readings.
* **Place lookup** — as you type a place of birth, the typed text is sent to Reloom to suggest matching cities and resolve the timezone.
* **Profile sync (optional)** — if you enable "sync back", profiles you create here (the same birth data, plus the optional email and notes) are added to your Reloom dashboard.

No data is sent to Reloom until you connect the site to a Reloom account. Reloom terms of service: https://reloom.life/terms — privacy policy: https://reloom.life/privacy

== Changelog ==

= 1.2.0 =
* New reloom-style profile view: reading content on the left, bodygraph pinned on the right, with a draggable divider to resize (remembered per browser).
* Reading voices: every reading now offers a Plain (layman) voice by default and a Human Design voice a click away, cached separately so switching is instant.
* New About and FAQ admin pages — the Reloom story, the author, and how to get the most from the plugin.
* Settings now shows your connected Reloom plan and how many readings it unlocks (read live from Reloom — the plan always decides what's available).

= 1.1.0 =
* The "Powered by" footer in exported PDFs is now strictly opt-in: off by default, enabled only via a new checkbox in Settings → PDF branding.
* Profile input (Birth Data form and JSON import) is now sanitized field-by-field immediately at intake.
* Upgraded the bundled Dompdf PDF library from 2.0.8 to 3.1.5.

= 1.0.0 =
* First release. One-click Connect to Reloom (PKCE, token never in the browser URL), local Profiles with a Birth Data form and place-of-birth verification, plan-aware tabs (Bodygraph chart + AI readings), server-side PDF export (bundled Dompdf), and optional profile sync back to your Reloom dashboard.
