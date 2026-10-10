# Changelog

All notable changes are documented here. Version 1.0.0 is tagged only after public release acceptance checks.

## 1.0.0 — 2026-10-10

- Dashboard distinguishes a missing or unreadable log file from an empty/unrecognized sample; zero parsed records are never presented as proof of no errors.

**Product promise:** help Symfony maintainers prioritize recurring technical problems, document root-cause investigations, and verify whether a correction made a measurable difference, without external APM infrastructure.

### Included

- Bounded Monolog file analysis with actionable Pareto patterns, trends, conservative symptom families, and severity filtering.
- Optional HTTP / CLI / Messenger execution correlation, based on explicit IDs; legacy log entries remain uncorrelated.
- Evidence-backed Gemba observations, Ishikawa hypotheses, 5 Whys, and PDCA investigations.
- Incomplete cause confirmations now show an inline Evidence validation error (HTTP 422), retaining the hypothesis and selection without emitting an uncaught BadRequest exception.
- Reuse an active investigation for the same exact fingerprint; closed cases remain historical.
- Record correction time; view comparable before/after sampled event counts, later recurrences, and evidence limitations.
- CSRF-protected, admin-restricted pages; limited, best-effort redaction of credentials in UI and CLI.
- Private file-backed case storage with per-case locking, atomic replacement, and writable-storage diagnostics.
- Bounded expanded exception details without drowning the analyst in DEBUG lines.
- PHP 8.2-compatible syntax and Symfony 7.4/8 constraints. Validated independent Composer host smoke tests on PHP 8.2.33 / Symfony 7.4 and PHP 8.4.25 / Symfony 8.1.

### Deliberately out of scope

No automatic root-cause claims, AI-generated solutions, error notifications, background collection, cross-node case storage, uptime metrics, retention enforcement, or third-party SaaS integration.

### Known limits

- Analysis uses a bounded log tail and is not a complete history. Comparing event counts is not comparing traffic-adjusted error rates.
- The same fingerprint can cover different underlying causes, and one incident can produce multiple fingerprints.
- Redaction is best effort; applications must prevent credentials or personal information entering logs.
- File-backed investigations require the correct filesystem owner and one shared writer environment.
- Case history and investigations have no built-in retention/archiving policy. Follow the host application's backup and retention practices.
