# Tainacan core OAI-PMH fix — proposal for issue #1029

This folder stages the **Tainacan core** change that pairs with this plugin. It
does **not** ship in the plugin — the code belongs in
[`tainacan/tainacan`](https://github.com/tainacan/tainacan). It is staged here so
the two deliverables (this plugin + the core fix) can be reviewed together, and
the Tainacan team can decide when to merge the core part into the main repository.

- **Issue:** [tainacan/tainacan#1029 — Refactor OAI-PMH endpoint](https://github.com/tainacan/tainacan/issues/1029)
- **Target branch:** `develop`
- **Patch:** [`tainacan-core-1029.patch`](./tainacan-core-1029.patch)
- **Status:** implemented and tested at runtime against a live repository
  (6 verbs, selective harvesting, resumption tokens, error cases).

## What it does

Rewrites the native OAI-PMH provider at `/wp-json/tainacan/v2/oai` from the legacy
script-style implementation (which echoed XML and called `die()` inside the verb
classes) into a REST-native provider, and fixes the bugs reported in #1029.

Highlights:

- **Selective harvesting works.** `from` / `until` are applied as a
  `post_date_gmt` `date_query`. Previously they were read but never used, so a
  date-bounded harvest returned the whole repository.
- **REST-native.** Returns a `WP_REST_Response` served through
  `rest_pre_serve_request`; no more `die()` / `header()` / `exit()` inside the
  verb flow.
- **Modern, correct mapping.** Datestamps use `post_modified_gmt` (UTC); Dublin
  Core mapping is multi-value, de-duplicated, and includes the item permalink
  (`dc:identifier`) and `dc:date`; `earliestDatestamp` is a single cached `MIN()`
  aggregate instead of a hardcoded date.
- **Safer resumption tokens.** Stored in transients (auto-expiring, no custom
  table, no filesystem / predictable-token / path-traversal surface).
- **PHP 8.1+ ready.** Removes `gmstrftime()` (removed in 8.1) and direct `date()`
  calls in favour of `gmdate()`.
- **Plugin Check / WPCS clean** on the security, forbidden-function, date and
  PHP-compatibility sniffs.

## Extension hooks introduced

These are what let an add-on (this **Tainacan OAI-PMH Enhanced** plugin) layer
features onto the core endpoint instead of shipping a competing provider:

| Hook | Type | Purpose |
|---|---|---|
| `tainacan-oai-permission` | filter | Gate access (the plugin returns HTTP 429 for rate limiting). |
| `tainacan-oai-pre-dispatch` | filter | Return a body to short-circuit the response (response cache hit). |
| `tainacan-oai-response` | action | Fires after the body is built (cache storage, request logging). |
| `tainacan-oai-maxrecords` | filter | Page size for `ListRecords` / `ListIdentifiers`. |
| `tainacan-oai-token-valid` | filter | resumptionToken lifetime (seconds). |
| `tainacan-oai-earliest-datestamp` | filter | Override the advertised `earliestDatestamp`. |
| `tainacan-oai-repository-name`, `tainacan-oai-admin-email` | filter | Override Identify fields. |

> The Enhanced plugin's caching / rate-limiting / logging only take effect once
> this core change is merged, because those features attach to the hooks above.

## How to apply

From a clean `tainacan/tainacan` checkout on `develop`:

```bash
git checkout develop
git apply --index /path/to/core-1029/tainacan-core-1029.patch
# or, to review without staging:
git apply --stat /path/to/core-1029/tainacan-core-1029.patch
git apply --check /path/to/core-1029/tainacan-core-1029.patch
```

The patch adds three classes
(`OAIPMH_Data_Provider`, `OAIPMH_Xml_Generator`, `OAIPMH_Token_Manager`),
rewrites the REST controller, and removes ten legacy classes
(`OAIPMH_Expose` and the per-verb classes plus `Xml_Create` / `Xml_Response` /
`XML_Error`).

A full-history branch (`fix/1029-oai-pmh-refactor`) also exists; the intended flow
is to open a PR to `tainacan/tainacan:develop` from a personal fork.
