=== Tainacan OAI-PMH Enhanced ===
Contributors: tainacanteam
Tags: oai-pmh, tainacan, dspace, harvester, dublin-core
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.6.5
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

OAI-PMH provider and importer for Tainacan with caching, monitoring, and validation. Supports DSpace bitstream download.

== Description ==

Tainacan OAI-PMH Enhanced exposes Tainacan collections via OAI-PMH 2.0 and imports records from external OAI-PMH endpoints into Tainacan collections.

Highlights:

* OAI-PMH 2.0 provider with full verb support (Identify, ListMetadataFormats, ListSets, ListRecords, ListIdentifiers, GetRecord) over Dublin Core.
* Wizard-driven importer for one-shot harvests from any OAI-PMH endpoint.
* Scheduled harvest sources with incremental sync (insert / update / delete diff against the last datestamp seen).
* DSpace bitstream download via a five-tier fallback: ORE → METS → xOAI → DSpace REST → public handle page scraping.
* Five metadata format choices for imports (oai_dc, qdc, xoai) so qualified DSpace field names like dc.contributor.author can be mapped separately.
* Activity log with INFO/WARN/ERROR levels per import run, retained between runs and clearable from the UI.
* Stop button mid-import; per-import concurrency lock preventing double-spawned workers.
* Rate limiting, request logging, harvester tracking, validator suite.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/.
2. Activate Tainacan first if not already active.
3. Activate Tainacan OAI-PMH Enhanced.
4. Configure under Tainacan → Settings → OAI-PMH and use the OAI-PMH menu item.

== Frequently Asked Questions ==

= Does it support DSpace? =
Yes. The bitstream pipeline is built around DSpace conventions and is the primary supported source for image attachments.

= Does it support other OAI-PMH servers? =
Yes for metadata. Bitstream download falls back gracefully when the upstream is not DSpace.

== Changelog ==

= 0.6.5 =

Two small fixes and one new operational tool.

Title de-duplication: when a record's `<dc:title>` (or equivalent xoai
`dc.title`) emits multiple values for the same field — variant
spelling, untranslated alt title, etc. — earlier versions joined them
with a paragraph break and the join leaked into post_title plus the
post slug. Production example: an item landing as
"Pequena Ilustração Pequena Illustração" with slug
"pequena-ilustracaopequena-ilustracao-2".

Record_Parser::lookup_metadata_value() gains a `bool $first_only`
parameter. When true, multi-valued matches return only the first
non-empty entry; multi-value joining is retained as the default
behavior for description/abstract where it's appropriate.
Importer::create_item() and Importer::update_item_from_oai() now
pass first_only=true for title lookups across all three metadata
prefixes (oai_dc / qdc / xoai).

Unit tests added covering: first_only single-value, first_only
skipping empty entries, first_only returning null when every entry
is empty, and a regression guard for the description join behavior.

New WP-CLI subcommand:

    wp tainacan-oai cleanup-legacy-thumbnails [--dry-run] [--repoint] [--force]

Sweeps the database for attachments tagged
`_oai_bitstream_bundle = THUMBNAIL` whose parent item ALSO has a
separate ORIGINAL bundle attachment from this plugin. These are
typically leftovers from pre-0.6.4 imports where the THUMBNAIL was
attached as documento before 0.6.4 fixed the classification.

Safe by default: items whose THUMBNAIL is currently set as Tainacan
documento or WordPress featured image are skipped unless --repoint
is provided. With --repoint, the documento/featured pointer is moved
to the ORIGINAL attachment before the THUMBNAIL is removed, so the
visual representation of the item is preserved.

Default trash behavior (wp_trash_post) — admins can recover from the
WP Media library. --force escalates to wp_delete_attachment(true) for
permanent deletion.

= 0.6.4 =

Hotfix release. Fixes a regression introduced in 0.6.3 where items
whose ORIGINAL bitstream exceeded the size cap (typical full-issue
periodical PDFs at 50–200 MB vs. the 20 MB default) ended up with
empty Documento, Miniatura, and Anexos — even though smaller
THUMBNAIL bundle files were available.

Root cause: 0.6.3 dropped THUMBNAIL bundles preemptively whenever any
ORIGINAL appeared in the bitstream list, before attempting any
download. When the ORIGINAL download then failed (too_large, HTTP
error, MIME refusal), the pipeline had nothing left to fall back to.

Replaced with a two-pass policy in enrich_item_with_bitstreams():

  Pass 1 — Download every ORIGINAL bitstream.
  Pass 2 — IF AND ONLY IF no ORIGINAL attached successfully, download
           the THUMBNAIL bitstreams as a visual fallback.

This keeps the happy path identical (ORIGINAL works → THUMBNAILs are
not even downloaded, Anexos stays clean) while ensuring that items
whose ORIGINAL is unavailable for any reason still get a thumbnail
in Documento + featured image. The user explicitly requested clean
Anexos earlier in development — that's preserved when the ORIGINAL
works. The fallback only kicks in when the ORIGINAL actually fails.

Better diagnostics: too_large WP_Error now includes the actual file
size in MB plus a remediation hint pointing at the
`import_max_size_mb` setting. Previously admins saw "Bitstream is 87
MB, exceeds 20 MB limit." with no clue what to do next. Now they see
"… (raise import_max_size_mb in Tainacan → OAI-PMH settings to
download)."

Recovery for items already empty after 0.6.3: re-run the harvest with
0.6.4. The bitstream-backfill branch in enrich_item_with_bitstreams()
fires because there's no `_oai_bitstream_url` postmeta, and the
two-pass policy now lands a thumbnail at minimum. For full PDFs to
actually be downloaded, raise the `import_max_size_mb` setting
(Tainacan → OAI-PMH) to fit your typical periodical issue size
(200–500 MB covers most cases).

Code cleanup: drop_redundant_thumbnails() removed (dead code with the
new policy).

= 0.6.3 =

Hotfix release. Generalizes the DSpace THUMBNAIL-bundle detection
heuristic so the right ORIGINAL is found even when the original
isn't itself a JPG.

Production observation following 0.6.2: items showing
`uploads/2026/05/Ano3-n.124-1878-com-OCR.pdf.jpg` as Documento —
a blank cover-page image. The actual PDF (`…-com-OCR.pdf`) carries
all the content, but the importer never reached it because the
THUMBNAIL-detection regex only matched `.jpg.jpg` filenames. DSpace
in fact appends `.jpg` to ANY ORIGINAL filename when it generates
thumbnails:

  ORIGINAL `foo.pdf`   → THUMBNAIL `foo.pdf.jpg`
  ORIGINAL `bar.tif`   → THUMBNAIL `bar.tif.jpg`
  ORIGINAL `baz.jpg`   → THUMBNAIL `baz.jpg.jpg`
  ORIGINAL `doc.docx`  → THUMBNAIL `doc.docx.jpg`

0.6.3 centralizes the convention into two helpers used by both the
HTML-scrape fallback and the ORIGINAL probe:

  * Importer::is_dspace_thumbnail_path()   → regex `/\.[a-z0-9]{2,8}\.jpg$/i`
  * Importer::strip_thumbnail_suffix()     → drops the trailing `.jpg`
                                             and any query string

The HTML-scrape classifier (fetch_html_bitstreams) now flags every
double-extension filename as THUMBNAIL so drop_redundant_thumbnails()
removes it from the array — the real PDF / TIF / etc. coming from
the same scrape is what lands in Documento.

The probe (probe_dspace_original) now:
  - Refuses to probe URLs that aren't double-extensions (avoids the
    earlier bug of treating any `.jpg` URL as a probable thumbnail).
  - Builds the ORIGINAL candidate by stripping ONLY the trailing
    `.jpg` (was: stripping `.jpg.jpg` → `.jpg`, which never produced
    a working candidate for `.pdf.jpg` thumbnails).
  - Accepts any media content-type (image/*, application/pdf, …) on
    the HEAD response, not just image/* — the 0.6.2 helper
    `is_acceptable_media_mime` is reused here.

Items already polluted by 0.6.x: same recovery as 0.6.2 — delete the
bad attachment plus its `_oai_bitstream_url` postmeta, then re-run
the harvest. The bitstream-backfill branch now finds and downloads
the actual PDF/TIF/etc.

= 0.6.2 =

Hotfix release. Refuses non-media URLs at the bitstream download gate.
Production observation: a `viewer.html` wrapper served by an upstream
endpoint was being sideloaded into wp-content/uploads/ as a regular
attachment and wired into the item's Tainacan `documento` slot,
replacing the actual PDF/image.

Adds two structural guards in Importer::download_bitstream():
  * URL-level — refuse bitstreams whose path ends in
    .html/.htm/.xhtml/.shtml/.php/.phtml/.asp/.aspx/.jsp/.jspx/.cfm/.cgi
    before the request is even made.
  * Content-Type — after the HEAD pre-flight, refuse anything outside a
    whitelist of media mime prefixes (image/, application/pdf,
    application/postscript, audio/, video/, application/zip,
    application/x-tar, application/gzip, application/octet-stream).
    Catches the case where the upstream URL has an innocuous extension
    but the server actually returns HTML (login walls, error pages,
    soft-redirected viewer wrappers).

Both gates surface a clean WP_Error with code `not_media`; the existing
log path renders this as `bitstream.download_failed` with the bare
mime type for diagnosis.

Items already polluted by 0.6.x do not auto-recover. The bitstream
backfill branch only fires when `_oai_bitstream_url` postmeta is
absent, and these items already have it (pointing at the viewer.html).
To recover: delete the bad attachment + its postmeta, then re-run
the harvest — the backfill branch will then refetch the real media.

= 0.6.1 =

Hotfix release. Restores set_time_limit(0) at the head of both
process_batch() and harvest_loop() — 0.6.0 removed them as part of the
discouraged-function cleanup, but production observation shows hosts
with tight max_execution_time settings (30s default in many shared-
hosting PHP configurations) killing the worker mid-bitstream-download.
The record's metadata commits before the bitstream phase runs, so the
item lands in Tainacan with a broken "documento" attachment: the
bibliographic metadata is correct but the PDF/image viewer comes up
empty.

Each set_time_limit call now carries a focused line-level phpcs:ignore
with a specific technical reason that survives review. wp_raise_memory_limit('admin')
remains for memory headroom.

If your install was affected, retrigger the broken imports after
upgrading: the dedup short-circuit will skip already-imported records,
but the bitstream-backfill path in enrich_item_with_bitstreams() will
fill in the missing attachments on a re-run.

= 0.6.0 =

Architecture & quality release. No runtime behavior changes for the happy path;
six latent bugs fixed during the cleanup.

**Architecture**

* Decomposed the 2,761-line `class-importer.php` monolith into a thin
  orchestrator plus four focused helper classes:
  `OAI_Client` (HTTP + XML + protocol verbs, zero DB),
  `Record_Parser` (oai_dc / qdc / xoai parsing, zero DB),
  `Imports_Table` (custom-table wrapper with line-level suppressions),
  `Item_Resolver` (postmeta dedup queries).
* Replaced raw `CREATE TABLE` + manual `ALTER` migration with `dbDelta()`
  in the activator. Idempotent schema updates for free.
* Removed `set_time_limit(0)` from harvest paths after verifying the
  per-record commit / watermark-on-success / dedup-on-redo resilience
  properties. `wp_raise_memory_limit('admin')` covers the legitimate
  memory concern.

**Tooling & CI**

* `composer.json` with strict WPCS 3.x / PHPCompatibilityWP / PHPCSExtra /
  VIPCS / PHPUnit 9.6 + Yoast polyfills.
* `phpcs.xml.dist` with the strict ruleset (Core + Docs + Extra +
  Security + DB + VIP Security + PHPCompat 8.1+).
* GitHub Actions workflow with 5 jobs: PHPCS security (blocking),
  PHPCS style (advisory), PHP Compatibility, PHPUnit on PHP 8.1
  and 8.3 against WP latest.
* All file-level `phpcs:disable` blocks eliminated from 6 of 7 files.
  The seventh (`class-plugin.php`) retains a narrowed disable for
  NonceVerification with an honest documentation of the PHPCS
  limitation around tracing helper-method nonce checks.

**Tests**

* 48 PHPUnit tests, 86 assertions (2 skipped, see FIXME notes).
* `OAI_Client` unit tests: URL normalization, scheme allow-list,
  loopback / RFC1918 SSRF guards, OAI date format, XML parse + XXE
  rejection, OAI error extraction, ListRecords URL builder.
* `Record_Parser` unit tests: oai_dc / qdc parsing, language-tag
  stripping for xoai paths, DSpace authority/confidence filtering,
  multi-value discovery, multi-paragraph join in
  `lookup_metadata_value`.
* `Item_Resolver` integration tests: hit/miss/empty dedup, collection
  scoping, trashed exclusion, `find_in_other_collections`, untrash
  attachments, `item_has_oai_bitstreams`.

**Bug fixes surfaced by the new test suite**

* `harvest_loop` used `$prefix` in `parse_record()` without defining
  it in scope; effectively always parsed as `oai_dc`. Made the prefix
  explicit.
* `Record_Parser::lookup_metadata_value` lost the multi-value implode
  during extraction (returned only the first paragraph instead of the
  joined block). Restored.
* `OAI_Client::parse_xml` was passing `LIBXML_NOENT`, which ENABLES
  entity substitution — the opposite of XXE-safe. Removed.
* `OAI_Client::validate_url` loopback list missed the bracketed IPv6
  form `[::1]`; brackets are now stripped before comparison.
* Two `$_POST` JSON handlers in `class-plugin.php` had `phpcs:ignore`
  on the wrong source line because the violation was mid-statement;
  corrected to `disable`/`enable` blocks.
* `templates/page.php` and `templates/tab-harvest.php` assigned to
  `$tab`, `$tabs`, `$s` — all WordPress core globals. Renamed to
  `$oai_current_tab`, `$oai_tabs`, `$src`.

= 0.5.1 =
* First public release on the Tainacan organization repo
  (https://github.com/tainacan/tainacan-oai-pmh).
* OAI-PMH 2.0 provider with full verb coverage and oai_dc / qdc / xoai
  metadata formats.
* One-shot importer wizard (Connect → Filter → Collection → Mapping → Start).
* Scheduled harvest sources with incremental insert/update/delete diff.
* DSpace bitstream pipeline with five-tier fallback (ORE → METS → xOAI →
  REST → HTML scrape) and HEAD probe for ORIGINAL bitstreams.
* Concurrency lock per import_id, Stop button mid-run, cooperative
  cancellation, restore-from-Trash on re-import.
* Activity log with INFO / WARN / ERROR levels, clearable per row,
  full-log viewer.
* Wizard state persistence (localStorage), per-import metadataPrefix +
  per-import bitstream override, dedup escopado por collection.
* SSRF guard, atomic UTC timestamps, atomic rate-limiter via
  INSERT … ON DUPLICATE KEY UPDATE.
