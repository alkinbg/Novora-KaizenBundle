# Novora KaizenBundle v1.0 — Release acceptance

The v1.0 release must ship a small, dependable loop: **prioritize a recurring log symptom → investigate with evidence → record a correction → assess the observed result**.

A feature is included only if it removes a documented obstacle within that loop.

## Automated release checks

- [x] PHP code syntax check across src/ and tests/ (PHP 8.4.25).
- [x] PHPUnit: 59 passing tests / 299 assertions, including severity filtering, correlation, redaction, store, CLI, and PDCA verification.
- [x] Composer manifest validated, lock synced locally (package itself does not ship a lock file).
- [x] Minimal read-only GitHub Actions workflow added for public contributions (PHP 8.2 / Symfony 7.4; PHP 8.4 / Symfony 8.1).
- [ ] Confirm the public workflow runs successfully on GitHub after this commit is pushed.
- [x] Private storage has bounded input, validated IDs, per-case locks and atomic writes.
- [x] Existing application business logic is not modified by the package.
- [x] Sensitive data redaction applied to UI and CLI; caveat clearly documented.
- [x] Product limitations and setup ownership issues documented.

## Final integration acceptance

- [x] Clean Composer installation from the checked-out path package in Symfony 8.1/PHP 8.4 and Symfony 7.4/PHP 8.2 test hosts. (A fresh install from the final Git tag is a separate release action.)
- [x] Independently installed hosts render the admin controller; anonymous HTTP requests return 401; CSRF-less POST requests are rejected. Production host role mapping must also be checked.
- [ ] Each adopting application must verify that its own PHP-FPM worker can read the configured Monolog file and write var/kaizen without chmod 777. **Deployment check, not a portable library release gate.**
- [x] Synthetic recurring error appears in the Pareto dashboard; expanded exception details covered by regression tests.
- [x] Dashboard clearly distinguishes missing/unreadable log source from an empty or unsupported log sample (regression tests).
- [x] Investigate creates exactly one active case per fingerprint; repeated click reopens it (both test hosts).
- [x] Gemba, Ishikawa, 5 Whys and PDCA persist in both test hosts; case status persistence covered by unit tests.
- [x] Confirming a hypothesis without supporting evidence produces an inline validation message and leaves the case unmodified (both test hosts); valid evidence confirms normally.
- [x] Before/after comparison, later recurrences and insufficient-evidence cases covered by regression tests; real before/after comparison passed in both test hosts.
- [x] CLI produces expected result without displaying sample credentials (unit test).
- [ ] Obtain feedback from at least one developer unfamiliar with the project. **Post-release usability validation; no usability claims until performed.**
- [x] Symfony 7.4 / PHP 8.2.33 runtime smoke performed in separate installed host.

## Release actions (after review)

- [ ] Review the public source snapshot, MIT license, and committed file list.
- [ ] Tag the reviewed initial public commit as v1.0.0 (signed or annotated tag).
- [ ] Publish a public GitHub release and register the repository at packagist.org; verify the package and tag resolve.
- [ ] Reinstall from public Packagist v1.0.0 in a clean host app and re-run smoke checks.

**Release policy:** library tests, compatibility, safety, packaging and GitHub Actions must pass before a stable tag. Host-specific access checks belong to each adopter's installation; external usability feedback is explicitly pending. No automatic root-cause assertions, unseen telemetry claims or fake before/after outcomes.
