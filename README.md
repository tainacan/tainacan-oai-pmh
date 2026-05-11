# Tainacan OAI-PMH Enhanced

[![Test & Lint](https://github.com/tainacan/tainacan-oai-pmh/actions/workflows/test.yml/badge.svg)](https://github.com/tainacan/tainacan-oai-pmh/actions/workflows/test.yml)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![Tainacan](https://img.shields.io/badge/Tainacan-1.0.0%2B-228b22)

OAI-PMH 2.0 **provider** _and_ **importer** for [Tainacan](https://tainacan.org).
Exposes Tainacan collections via the Open Archives Initiative Protocol for Metadata Harvesting,
and imports records from any OAI-PMH-compliant endpoint — with first-class support
for DSpace bitstream download.

---

## Features

### As an OAI-PMH provider

- Full OAI-PMH 2.0 verb coverage: `Identify`, `ListMetadataFormats`, `ListSets`,
  `ListRecords`, `ListIdentifiers`, `GetRecord`.
- Dublin Core (`oai_dc`) output mapped from Tainacan metadata via the existing
  exposer-mapping setting.
- Server-side cache table keeps the OAI response fast under harvest load and
  invalidates automatically when items are created / updated / trashed.
- Resumption-token paging, per-IP rate limiting, structured request log.

### As an OAI-PMH harvester / importer

- **One-shot wizard** (Connect → Filter → Collection → Mapping → Start) for ad-hoc
  harvests from any OAI-PMH endpoint.
- **Scheduled sources** with incremental sync: each cron run uses an
  `OAI from=` parameter set to the last datestamp seen, then diffs every
  record back against local items: **create / update / skip / trash**.
- **Three metadata formats** for imports — `oai_dc`, `qdc`, and DSpace-native
  `xoai`. The latter preserves qualified field names like
  `dc.contributor.author` and `dc.title.pt_BR` as distinct keys in the mapping
  table.
- **DSpace bitstream pipeline** with a five-tier fallback so attachments
  arrive even when the upstream's OAI feed is incomplete:
  1. ORE / Atom aggregates
  2. METS file-section
  3. xOAI bundles (DSpace native)
  4. DSpace REST API
  5. Public handle-page scraping
- **High-res ORIGINAL preferred** over THUMBNAIL bundles; auto-promotion to
  Tainacan's main document + WordPress featured image.
- **Restore-from-Trash on re-import** — items soft-deleted by `Delete import`
  are untrashed and updated rather than duplicated.

### Operational hardening

- Concurrency lock per `import_id`; mid-batch `Stop` button with
  cooperative cancellation every 5 records.
- Activity log (INFO / WARN / ERROR) per import run, retained between runs,
  clearable from the UI, capped at 256 KB to avoid runaway log growth.
- SSRF guard on the URL validator (rejects loopback + RFC1918 by default;
  filterable via `tainacan_oai_pmh_allow_local_urls` for development).
- XXE-safe XML parsing (`LIBXML_NONET`, entity expansion off).
- Wizard state persisted to `localStorage` so a refresh mid-flow doesn't
  lose the user's input.

---

## Requirements

| Component | Version |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.1+ |
| [Tainacan](https://github.com/tainacan/tainacan) | 1.0.0+ |
| MySQL / MariaDB | as required by your WP install |

---

## Installation

```bash
# From inside wp-content/plugins/
git clone https://github.com/tainacan/tainacan-oai-pmh.git
cd tainacan-oai-pmh
composer install --no-dev
```

Then activate via the WordPress admin (Plugins → Activate). Tainacan core must
be active first.

The plugin creates its own custom tables (`wp_tainacan_oai_cache`,
`wp_tainacan_oai_imports`, `wp_tainacan_oai_logs`,
`wp_tainacan_oai_harvesters`, `wp_tainacan_oai_sources`,
`wp_tainacan_oai_tokens`, `wp_tainacan_oai_rate_limits`) via `dbDelta()` on
activation; the migration is idempotent and runs again on every version bump.

---

## Usage

After activation a new **OAI-PMH** menu appears under the Tainacan admin.

- **Dashboard** — health check, sync ratio, last indexed timestamps.
- **Importer** — wizard for one-shot harvests.
- **Scheduled Harvest** — saved sources running on a `hourly` / `twicedaily`
  / `daily` / `weekly` schedule.
- **Harvesters** — IP addresses of clients that have called the provider.
- **Logs** — request log with verb / response time / level filtering.
- **Validation** — runs a battery of self-checks against the configured
  provider endpoint.

The OAI provider endpoint lives at:

```
/wp-json/tainacan-oai/v1/oai
```

Example request:

```bash
curl 'https://your-site.example/wp-json/tainacan-oai/v1/oai?verb=Identify'
```

---

## Architecture

```
tainacan-oai-pmh.php          ← bootstrap; loads helpers in dependency order
includes/
  class-activator.php         ← dbDelta() schema for the 7 custom tables
  class-settings.php          ← Tainacan Settings page integration
  class-cache.php             ← OAI response cache (custom table)
  class-data-provider.php     ← Identify / ListSets / etc. payload builder
  class-xml-generator.php     ← OAI-PMH 2.0 XML serializer
  class-rest-controller.php   ← REST routes for the provider endpoint
  class-validator.php         ← self-test suite
  class-record-parser.php     ← XML → array for oai_dc / qdc / xoai
  class-oai-client.php        ← HTTP + XML primitives + protocol verbs
  class-imports-table.php     ← $wpdb wrapper for the imports table
  class-item-resolver.php     ← postmeta dedup queries
  class-importer.php          ← orchestrator (bitstream + mapping)
  class-metadata-mapper.php   ← Dublin Core ↔ Tainacan metadatum mapping
  class-rate-limiter.php      ← per-IP throttle on the provider endpoint
  class-token-manager.php     ← resumption-token issuance and lookup
  class-harvester.php         ← scheduled-source cron loop
  class-plugin.php            ← admin page, AJAX handlers
  class-logger.php            ← request log + IP harvester tracking
  class-cli.php               ← WP-CLI commands (loaded only under WP_CLI)
templates/                    ← admin UI tab partials
tests/
  unit/                       ← OAI_Client + Record_Parser (pure functions)
  integration/                ← Item_Resolver (real WP postmeta)
  bootstrap.php               ← WP test framework loader
  bin/install-wp-tests.sh     ← standard test environment installer
```

---

## Development

### Linting

```bash
composer install
vendor/bin/phpcs --standard=phpcs.xml.dist
```

The ruleset is strict (WordPress-Core + Docs + Extra + Security + DB +
VIP Security + PHPCompatibility 8.1+). Two CI jobs gate the build:

- **PHPCS security (blocking)** — only the `WordPress.Security.*` and
  `WordPress.DB.*` sniffs; an error here fails the build.
- **PHPCS style (advisory)** — the full ruleset; non-blocking, used as a
  burndown signal.

### Tests

```bash
# One-time: install the WP PHPUnit test framework
tests/bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest

# Unit suite (pure-function tests, no DB)
vendor/bin/phpunit --testsuite unit

# Full suite (unit + integration)
vendor/bin/phpunit
```

Requires `svn` on PATH (the WP test framework still ships via SVN) and a
running MySQL/MariaDB on the host/port you pass in.

### Hooks

| Filter / Action | Purpose |
|---|---|
| `tainacan_oai_pmh_allow_local_urls` (filter) | Allow loopback/RFC1918 URLs in `validate_url()`. Default `false`. |
| `tainacan_oai_pmh_request_timeout` (filter) | HTTP timeout in seconds for outbound OAI requests. Default `60`. |
| `tainacan_oai_pmh_sslverify` (filter) | TLS verification on outbound requests. Default `true`. |
| `tainacan_oai_daily_maintenance` (action) | Daily cron event for log cleanup + harvester hostname resolution. |

---

## Contributing

Bug reports and pull requests welcome — open one at
<https://github.com/tainacan/tainacan-oai-pmh/issues>.

Comments on commits and code must be in English (per the
[Tainacan contributing guidelines](https://tainacan.github.io/tainacan-wiki/#/dev/CONTRIBUTING)).
PR descriptions may be in Portuguese as long as an English summary is also
provided.

---

## License

GPL v3 or later. See [LICENSE](LICENSE) (when present in the release ZIP).
