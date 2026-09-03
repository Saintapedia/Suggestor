# Changelog

Releases are tagged. Pin a production wiki to a tag, not to floating `main`.

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
