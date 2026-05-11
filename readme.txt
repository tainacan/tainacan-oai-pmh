=== Tainacan OAI-PMH Enhanced ===
Contributors: tainacanteam
Tags: oai-pmh, tainacan, dspace, harvester, dublin-core
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.6.1
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
