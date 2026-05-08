=== Tainacan OAI-PMH Enhanced ===
Contributors: tainacanteam
Tags: oai-pmh, tainacan, dspace, harvester, dublin-core
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.5.1
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
