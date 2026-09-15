# Changelog

Releases are tagged. Pin a production wiki to a tag, not to floating `main`.

## 0.8.0 — 2026-09-15

### Security / access control

- **Dashboard access, contact-email visibility, and export access are now
  LocalSettings.php-only.** `SuggestAccess` used to also read
  `MediaWiki:SaintapediaSuggest-access`, `-email-access`, and
  `-export-access` — pages editable by anyone holding `editinterface`, with
  no deploy or code review. A stray edit to `-email-access` alone could add
  someone to the group that sees readers' submitted contact emails. That
  on-wiki override is removed entirely; only `$wgSaintapediaSuggestAccessGroups`
  / `EmailAccessGroups` / `ExportAccessGroups` are consulted now. Matches
  SaintapediaFeedback's identical F-08 fix in its 2026-09-10 review.
- **Rate limit and require-captcha are also LocalSettings.php-only now**,
  for the same reason: both are abuse controls, not content curation, and a
  wiki-page override could zero out the daily submission cap or turn
  captcha off wiki-wide. `MediaWiki:SaintapediaSuggest-ratelimit` and
  `-require-captcha` (added in 0.6.0/earlier) no longer do anything.
- **Email-access can no longer be set to "everyone."** `getAllowedEmailGroups()`
  now drops a `*` token from `$wgSaintapediaSuggestEmailAccessGroups`
  (logging a warning) before the access check runs, mirroring the same
  hardening in SaintapediaFeedback 1.9.0 — contact email can never be made
  public, regardless of configuration.

This shipped before Suggestor's first production deploy, so there is no
upgrade population relying on the removed pages — unlike Feedback's 1.9.0,
this needs no migration warning.

### Upgrade notes

No `update.php` required — no schema change. If you were relying on
`MediaWiki:SaintapediaSuggest-access`, `-email-access`, `-export-access`,
`-ratelimit`, or `-require-captcha` (only possible if you deployed an
unreleased `main` checkout before this tag), move the equivalent settings
to `LocalSettings.php`: `$wgSaintapediaSuggestAccessGroups`,
`EmailAccessGroups`, `ExportAccessGroups`, `RateLimit`/`EnterpriseRateLimit`,
`RequireCaptcha`.

### Install pin

```bash
git clone --branch v0.8.0 --depth 1 \
  https://github.com/Saintapedia/Suggestor.git SaintapediaSuggest
```

## 0.7.0 — 2026-09-14

### Features

- **The Cargo table/field allow-list is now wiki-editable.** New
  `MediaWiki:SaintapediaSuggest-tables` page, one `Table: Field1, Field2` /
  `Table: *` / bare `Table` line per table, joins the settings that were
  already on-wiki-overridable (rate limit, notify-users, require-captcha,
  enabled). Missing or empty page falls back to `$wgSaintapediaSuggestTables`
  unchanged.

  This is a narrower exposure than the access-control settings
  SaintapediaFeedback 1.9.0 deliberately pulled *out* of the wiki: the
  allow-list only offers a "suggest a correction" affordance on Cargo fields
  already rendered publicly on the page, and every submission still lands in
  the review queue rather than writing anywhere — an over-broad wiki edit
  here produces an odd-looking suggestion for a triager to reject, not a
  data or privacy exposure.

### Upgrade notes

No `update.php` required — no schema change. Existing `$wgSaintapediaSuggestTables`
installs are unaffected: the on-wiki page is additive and does nothing until
someone creates it.

### Install pin

```bash
git clone --branch v0.7.0 --depth 1 \
  https://github.com/Saintapedia/Suggestor.git SaintapediaSuggest
```

## 0.6.1 — 2026-09-13

Fixes from a pre-production code review, ahead of the first deploy to
saintapedia.org.

### Fixes

- **A revoked user could still receive an Echo notification carrying the
  reader's proposed value.** `locateNotifiedUsers()` re-checked only whether
  the recipient was a persistent account, not whether they still had
  dashboard access — its own docblock claimed the latter, but nothing
  enforced it. Now also calls `SuggestAccess::userCanManage()`, so access
  pulled between submit and notification delivery actually blocks it.
- **The 0.6.0 ambiguous-rowid fix had a gap: two unsynchronized reads of the
  same table in one request.** The submit API validates row ids by reading
  a table once (`getRowIds()`), then reads it again independently
  (`getCurrentValue()`/`getRowLabel()`) to snapshot the value. If a
  concurrent Cargo write added rows between those two reads, an omitted
  `rowid` — correctly resolved as unambiguous against the first, single-row
  read — could land on whichever row the second read returned first instead
  of being refused. `CargoFieldRegistry::findRow()` now re-checks the row
  count on its own read before accepting a null id, so a table that turns
  out to have more than one row is refused rather than guessed at.
- **Switching the suggestion field could carry the previous field's typed
  text over as the new field's proposed value.** The widget only pre-filled
  the value box when it was empty, so text typed for "Phone" stayed in the
  box — attributed to "Address" — after switching the dropdown. It now
  always repopulates on a field switch.

### Config

- `$wgSaintapediaSuggestMaxRowsPerTable` default raised 25 → 50. A row past
  the cap is invisible to row-existence checks, so any page whose
  allow-listed table can hold more rows than the cap needs it raised
  further.

### Upgrade notes

No `update.php` required — no schema change.

### Install pin

```bash
git clone --branch v0.6.1 --depth 1 \
  https://github.com/Saintapedia/Suggestor.git SaintapediaSuggest
```

## 0.6.0 — 2026-09-04

### Fixes

- **Clicking a row's action button could act on the wrong suggestion.** The
  bulk-select form wrapped every row in the dashboard's suggestion list, so
  each row's hidden `sps_id` field shared one name across the whole form.
  Submitting is a single POST, and PHP keeps only the last value for a
  repeated field name — so "Mark actioned" on any row could silently apply to
  whichever row happened to render last, not the one actually clicked. The
  row id is now encoded directly in the clicked button's value
  (`sps_status=12:actioned`) instead of a separate hidden field, so the
  browser can only ever submit the id of the button that was pressed. The
  per-row reviewer note (`sps_worknote`) had the identical bug and is fixed
  the same way, via PHP array-keyed field names (`sps_worknote[12]`).
- **Omitting `rowid` on a multi-row Cargo table silently snapshotted row
  one.** The API's own comment claimed "the API always passes an explicit
  id," but nothing enforced that — an omitted id fell through to whichever
  row the database returned first. A submission against an ambiguous table
  is now refused (`sps-norow`) unless the table holds exactly one row, in
  which case the id is filled in automatically so the stored snapshot still
  names a specific row.
- **Two different readers reporting the same value could both become
  canonical.** Duplicate folding ran under the per-IP rate-limit lock, which
  does nothing for two different IPs racing each other — both could pass
  `findOpenDuplicate()` before either inserted, producing two "canonical"
  rows for one problem. A second lock keyed on the target
  (page, table, field, row) now serializes duplicate folding independent of
  who is submitting.

### Features

- **Copy** and **Edit article** on each dashboard row. Copy puts the
  proposed value on the clipboard (falls back to `execCommand` on older
  browsers; the value stays selectable with scripting off either way).
  Edit article opens the page in edit mode in a new tab. Neither writes
  anything — the reviewer still copies, edits by hand, saves, then marks
  the suggestion actioned.

### Tests

- Regression tests for all three fixes, including a live-POST style test
  that reproduces the shared-form field collision.

### Upgrade notes

No `update.php` required — no schema change. If anything outside the widget
calls the submit API directly and previously relied on an omitted `rowid`
resolving to row one of a multi-row table, that call now gets `sps-norow`
and must send the id.

### Install pin

```bash
git clone --branch v0.6.0 --depth 1 \
  https://github.com/Saintapedia/Suggestor.git SaintapediaSuggest
```

## 0.5.0 — 2026-09-02

First tagged release.

### Features

- **Reader widget.** Anonymous readers can propose a correction to one specific
  Cargo field stored for a page, behind hCaptcha, a per-IP daily cap and a block
  check. Nothing is written to Cargo or to wikitext: this collects and triages
  only.
- **Allow-list.** `$wgSaintapediaSuggestTables` decides which tables and fields
  are suggestable. Empty by default, so installing exposes nothing until an
  admin opts a table in. Cargo's internal columns (`_pageID`, `_pageName`, …)
  are never suggestable, not even under `'*'`.
- **Reviewer dashboard.** `Special:SaintapediaSuggest` with status chips
  (new / reviewed / actioned / dismissed), search, field filter, bulk actions,
  a per-article view, a per-suggestion detail view with full status history,
  and a JSON export.
- **Duplicate folding.** Repeat reports of the same value for the same field
  become one queue item carrying a count, rather than ten.
- **Multi-row support.** A page may hold several rows in one Cargo table; each
  row is offered separately, named by a configurable label field, and the
  suggestion records which row it targets.
- **Freshness flags.** The dashboard compares each suggestion against what Cargo
  holds now and flags *Already applied*, *Data changed*, and — most usefully —
  *Actioned, but unchanged*, which catches an item closed without the edit
  actually landing.
- **One entry point.** `$wgSaintapediaSuggestEntryPoint = 'none'` plus
  `mw.hook( 'saintapediasuggest.open' )` lets another extension open the panel,
  so a wiki running this alongside SaintapediaFeedback shows one button rather
  than two.
- **Notifications.** Echo alerts and an optional email address, both opt-in.
- **Offline batch export.** `maintenance/ProcessSuggestions.php` posts open
  suggestions to an HTTPS endpoint for external triage. Carries the suggestion,
  never the submitter.

### Security

- Contact email and export are separate rights from dashboard access, so
  neither grants the other.
- List and export queries never materialize the contact email or the IP hash.
- Rate limiting keys on a salted SHA-256 of the IP; the raw address is never
  stored.
- The submit API re-derives both the target and the current-value snapshot
  server-side, so a client cannot fabricate the before-state a reviewer sees.

### Compatibility

Runs on MediaWiki 1.39–1.43 from one codebase, using the un-namespaced core
class names that core still provides via `class_alias`.

### Install pin

```bash
git clone --branch v0.5.0 --depth 1 \
  https://github.com/Saintapedia/Suggestor.git SaintapediaSuggest
```

### Why 0.5.0 and not 1.0.0

The feature set is complete and covered by 118 unit and 78 integration tests,
but it has never run on a wiki with real reader traffic. A 1.0.0 should be
earned by real use, not asserted at first deploy.
