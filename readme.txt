=== Reloom for Human Design ===
Contributors: reloom, raybogman
Tags: human design, bodygraph, chart, reloom, ai
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Human Design for WordPress, powered by Reloom. Pull Bodygraph charts and AI readings through your Reloom account â no API keys needed here.

== Description ==

This plugin connects WordPress to your **Reloom** account (reloom.life). It does not call the Bodygraph or AI providers directly. Instead, one click connects the site to Reloom, and the plugin pulls data live through Reloom's API. Your Reloom plan governs which readings are available.

* **One-click Connect** â approve the site in your Reloom dashboard and you're connected; the token is delivered securely and never appears in the browser URL. Manual connect (API base URL + token) is also available.
* **Profiles** â keep a local roster of people (Birth Data form). Open a person to see their chart and readings in tabs.
* **Plan-aware** â the tab strip renders only the scopes your Reloom plan unlocks: Bodygraph chart plus the readings included in your plan.
* **Export to PDF** â a "Download PDF" button generates a real PDF (server-side) with the person's name, Bodygraph chart and readings.
* **Sync back** â optionally add profiles created here to your Reloom dashboard (subject to your plan's profile limit).

All API calls run server-side, so the token is never exposed to the browser. Reloom caches results per birth, so repeat lookups for the same person are cheap.

== External services ==

This plugin relies on **Reloom** (https://reloom.life), a hosted Human Design service, to compute Bodygraph charts and generate AI readings. This processing happens on Reloom's servers and cannot be performed locally by the plugin.

Data sent to Reloom, and when:

* **Connecting** â when you click "Connect to Reloom" (or connect manually), the plugin exchanges an authorization code for an access token with your Reloom account. Your site's settings page URL is sent as the return address.
* **Charts and readings** â when you open a profile or click Refresh, the profile's birth data (name, gender, birth date, birth time, place of birth, timezone) is sent to Reloom to compute the chart and generate readings.
* **Place lookup** â as you type a place of birth, the typed text is sent to Reloom to suggest matching cities and resolve the timezone.
* **Profile sync (optional)** â if you enable "sync back", profiles you create here (the same birth data, plus the optional email and notes) are added to your Reloom dashboard.

No data is sent to Reloom until you connect the site to a Reloom account. Reloom terms of service: https://reloom.life/terms â privacy policy: https://reloom.life/privacy

== Screenshots ==

1. Connect your site to reloom.life in one click — a secure OAuth-style flow (PKCE), no API keys to copy.
2. The moment you connect, the profiles you already have on reloom — your own first, marked "You" — appear automatically, so no one is entered twice.
3. Add a person's birth details; the place of birth is verified against reloom's location database.
4. Each profile shows the full reloom Bodygraph chart alongside an AI reading in two voices — Plain by default, Human Design a click away.
5. Your roster of people, with cached charts and readings that load instantly and persist between visits.

== Changelog ==

= 1.4.1 =
* Fixed: connecting could fail to return to WordPress when you had to sign in or create a Reloom account first — you'd land in Reloom instead of back on the settings page. The connect link's return address is now correctly URL-encoded (WordPress's add_query_arg does not encode values, which left a raw "?" in the URL and dropped the return path).

= 1.4.0 =
* Auto-sync on connect: right after you approve the connection, the plugin pulls the profiles you already have on Reloom — above all your own "self" profile created at sign-up — so they appear here immediately and are never re-added. This removes the duplicate that happened when the same person was created on both sides.
* Profiles are now linked to their Reloom profile by id (remote_id), so editing or re-syncing updates the same profile instead of creating a copy. Your own profile is pinned first and marked "You".

= 1.3.4 =
* The PDF stylesheet (assets/css/pdf.css) is now handed to the bundled Dompdf engine through its Stylesheet API instead of a link tag in the generated document. No stylesheet markup remains anywhere in the plugin's PHP; output is unchanged.

= 1.3.3 =
* The PDF export's stylesheet moved from an inline block in PHP to a proper stylesheet file (assets/css/pdf.css), loaded by the bundled Dompdf engine via its base path. Identical output; no CSS is embedded in PHP anymore.

= 1.3.2 =
* The profile-quota pill now counts the profiles in this roster against the plan's own limit (e.g. 8/25) and the Settings page shows the same numbers. The internal primary-profile allowance is no longer surfaced.

= 1.3.1 =
* Profile quota is now consistent everywhere and counts your primary profile: every account includes you, plus the plan's additional profiles (Practitioner: you + 25 = 26 total). The Settings connection line now shows the proper plan name (e.g. "Practitioner" instead of "Reseller 25") plus the same x/y numbers as the Profiles page pill.

= 1.3.0 =
* New: plan usage at a glance. The Profiles page title now shows a pill with your Reloom plan and profile quota (e.g. "Personal · 2/3 profiles", red when full), and Settings → Test connection lists the same numbers. Requires Reloom with the updated /meta endpoint; the pill simply hides on older servers.

= 1.2.3 =
* The PDF now exports only the readings included in your Reloom plan, matching the on-screen tabs (stale cached readings from a previous plan no longer appear).
* One-time cleanup on upgrade: cached readings from before the Plain/Human Design voices feature are cleared, since they could hold the wrong voice. Readings re-pull automatically when you open a profile; Reloom serves previously generated voices from its own cache, so nothing is re-billed.

= 1.2.2 =
* Fixed: exporting right after switching the Plain/Human Design toggle could produce a PDF with mixed voices. Readings not yet cached in the selected voice are now fetched from Reloom during the export (fast when that voice was generated before), with a time budget; only past that budget does the export fall back to the other cached voice instead of dropping the section.

= 1.2.1 =
* Fixed: exported PDFs contained only the bodygraph cover â readings were skipped because they are cached per voice (Plain/Human Design) and the export still looked them up under the old un-suffixed keys. The PDF now exports readings in the voice selected on screen, falling back to the other voice (or a legacy cache entry) when only that one exists.

= 1.2.0 =
* New reloom-style profile view: reading content on the left, bodygraph pinned on the right, with a draggable divider to resize (remembered per browser).
* Reading voices: every reading now offers a Plain (layman) voice by default and a Human Design voice a click away, cached separately so switching is instant.
* New About and FAQ admin pages â the Reloom story, the author, and how to get the most from the plugin.
* Settings now shows your connected Reloom plan and how many readings it unlocks (read live from Reloom â the plan always decides what's available).

= 1.1.0 =
* The "Powered by" footer in exported PDFs is now strictly opt-in: off by default, enabled only via a new checkbox in Settings â PDF branding.
* Profile input (Birth Data form and JSON import) is now sanitized field-by-field immediately at intake.
* Upgraded the bundled Dompdf PDF library from 2.0.8 to 3.1.5.

= 1.0.0 =
* First release. One-click Connect to Reloom (PKCE, token never in the browser URL), local Profiles with a Birth Data form and place-of-birth verification, plan-aware tabs (Bodygraph chart + AI readings), server-side PDF export (bundled Dompdf), and optional profile sync back to your Reloom dashboard.
