# Novora KaizenBundle

**Find waste. Understand causes. Improve your Symfony application.**

**v1.0 product scope · MIT open source.** A focused Lean improvement workspace for Symfony applications, **not another log viewer**.

**The problem:** developers find an exception, patch it, and lose the evidence of which issues recur, why they happened, and whether the change actually helped. Kaizen makes that loop traceable: **Prioritize → Investigate → Improve → Verify**. It works on existing Monolog files, without an APM server, external telemetry, a database migration, or AI-generated guesses.

**For:** Symfony teams with a readable Monolog log and an authenticated admin area. **Not for:** enterprise APM, production tracing, cross-host observability, guaranteed automated root-cause detection, or quantitative uptime claims.

[Guided product demo](docs/DEMO.md) · [v1.0 release notes](CHANGELOG.md) · [Release criteria](docs/RELEASE_CHECKLIST.md)

## What is available

- **Waste Radar:** group recurring Monolog events, classify issues and show a proper Pareto chart with descending occurrence bars, cumulative percentages, an 80% reference line and an automatically chosen number of patterns (up to 16) to reach 80% when possible; remaining events are summarized separately.
- **Pareto scopes:** switch between relevant patterns, errors and deprecations without requiring additional instrumentation; click a bar to jump to its issue and open an investigation.
- **Error families:** the Errors overview now plots frequency by conservative symptom family (sessions/headers, database/SQL, Twig/templates, CLI/tooling and other). Every family is shown in the overview; select a bar or row to drill down into its original individual fingerprints and start an investigation. The aggregated family key is **never** stored as an issue fingerprint. Classification does **not** establish a shared root cause.
- **Trends:** compare two consecutive equal 24-hour windows of available events.
- **Execution timeline:** severity filters (Problems by default, Critical, Error, Warning, Info, Debug, All) show counts per level and filter before the 150-row display cap, so routine debug traffic cannot hide errors.
- **Execution correlation (opt-in):** Monolog 3 processor attaches a generated execution ID and an origin (HTTP, CLI, Messenger), counts unique observed executions independently from error occurrences, and allows opening chronological per-execution timelines. Legacy logs without IDs are never retroactively linked.
- **Gemba:** record observed behavior and evidence manually.
- **Ishikawa:** investigate causes under six categories; track hypotheses and evidence.
- **5 Whys:** work backwards to a cause without presenting guesses as facts.
- **PDCA:** Plan, Do, Check, Act notes and tracking status.
- **PDCA Check — Before / After:** record when a correction was applied to a fingerprint-linked investigation (now or an earlier local timestamp), show first/last occurrence in the available log sample, compare identical periods before and after (up to 24 hours), and separately count any later recurrences. This never closes cases automatically.

- **File-backed workspace:** JSON per investigation, per-record lock, atomic replacement and controlled ID validation.
- **No duplicate active investigations:** repeated Investigate actions for the same exact log fingerprint open the existing active case. Closed cases remain historical; a new occurrence can start another. Manually created cases remain independent.
- **CLI:** bounded analysis without separate telemetry infrastructure.

This is a deliberately scoped v1.0 tool, not production-grade APM. Automatic traces, SQL instrumentation, multi-server data aggregation, alerting, AI analysis and automatic impact scoring are not implemented.

## Supported environment

PHP 8.2+, Symfony 7.4 or Symfony 8.x. TwigBundle and SecurityBundle are required.

## Installation

Prerequisites: PHP 8.2+, Symfony 7.4 or Symfony 8.x, TwigBundle, SecurityBundle, a readable Monolog log file, and a private directory writable by the PHP-FPM worker. The acceptance flow was smoke-tested with **PHP 8.2.33 / Symfony 7.4** and **PHP 8.4.25 / Symfony 8.1** in separate Composer-installed host projects.

After the public v1.0.0 tag is published and indexed on Packagist:

~~~bash
composer require 'novora/kaizen-bundle:^1.0'
~~~

Until the public release is available, use a reviewed development commit from the source repository. Composer version discovery is based on Git tags; do not assume a release tag exists merely because it is documented.

### Configuration

Register the bundle in your application's config/bundles.php:

~~~php
Novora\KaizenBundle\NovoraKaizenBundle::class => ['all' => true],
~~~



Import the routes explicitly using config/routes/novora_kaizen.yaml:

~~~yaml
novora_kaizen:
    resource: '@NovoraKaizenBundle/Controller/'
    type: attribute
~~~

This creates a dashboard at /_kaizen and investigation pages below /_kaizen/investigations. Importing routes is optional: the read-only CLI also works without it.

The web UI enforces ROLE_ADMIN at controller level. Configure the application's authentication and authorize this path before permissive access-control rules:

~~~yaml
# config/packages/security.yaml (merge under the existing security key)
security:
    access_control:
        - { path: '^/_kaizen(?:/|$)', roles: ROLE_ADMIN }
~~~

Enable CSRF services if necessary:

~~~yaml
# config/packages/framework.yaml (merge into existing framework configuration)
framework:
    csrf_protection: true
~~~

Configuration (config/packages/novora_kaizen.yaml):

~~~yaml
novora_kaizen:
    log_path: '%kernel.logs_dir%/%kernel.environment%.log'
    storage_dir: '%kernel.project_dir%/var/kaizen'
    max_bytes: 2097152
    correlation_enabled: false # opt in explicitly
~~~

The storage directory must be **outside** the public webroot, readable and writable **by the PHP-FPM worker**, not merely by the CLI user. New directories are created with permission 0700 and data files 0600. A misconfigured storage directory triggers a visible warning on the admin pages and a specific error in the server logs; fix ownership rather than using chmod 777. Do not have the CLI and HTTP processes create/update investigations as different Unix users. This local store supports one host/shared filesystem, not independent multi-node writes.

**Storage preflight** (from the application directory; replace the UID/name with your PHP-FPM worker):

~~~bash
namei -l var/kaizen
sudo -u '#33' test -w var/kaizen && echo 'Kaizen storage writable'
~~~

If it is not writable, create or change **only** var/kaizen to be owned by the actual worker UID (33 is just an example). Do not recursively chmod the project or var/. PHP-FPM worker identities vary by deployment.


### Optional execution correlation

Requires Symfony MonologBundle in the host app and Monolog 3. Enable in config/packages/novora_kaizen.yaml:

~~~yaml
novora_kaizen:
    correlation_enabled: true
~~~

Clear the application's container cache and generate *new* HTTP requests, CLI commands or Messenger jobs. The bundle registers a Monolog processor and emits only two fields in the record's extra context:

- kaizen_execution_id: randomly generated 32-character lowercase hex ID.
- kaizen_origin: http, cli or messenger.

No request payloads, full URLs, cookies, headers, IP addresses or user IDs are collected. The same HTTP request shares one ID (including subrequests). CLI uses one ID per PHP process. When Messenger is installed, each worker-received message gets its own ID; the message scope is cleared after handled, failed and worker-running events.

Open /_kaizen and inspect **Execution correlation**. It shows whether correlation is enabled in the current Symfony environment, the number of tagged log entries (any severity), the number of executions observed in those entries, and separately the executions that contain error-level records. An enabled processor with zero tagged entries is not proof of failure: existing sampled logs may predate activation or handlers may not write successful requests.

Counts and timelines use only IDs present in the current bounded log sample. **Unique observed executions are not necessarily unique incidents or root causes.** All old log records without IDs remain uncorrelated. Enabling this setting does not backfill history.

This requires a log destination/formatter that preserves Monolog extra metadata (the standard line and JSON formatters are supported). Disabling correlation stops writing new IDs without changing existing log history. No database migrations or external infrastructure are needed.

### CLI

~~~bash
php bin/console kaizen:analyze var/log/prod.log --limit=15
php bin/console kaizen:analyze var/log/prod.log --max-bytes=4194304
~~~

The command reads a bounded tail, up to 16 MiB, and applies the same best-effort display redaction as the UI. It ignores unknown formats and multiline continuations. If your Monolog sends logs to php://stderr, systemd or a container log driver, configure a readable file source first: the viewer does not automatically retrieve those streams.

## Lean methodology

1. Observe high-frequency events in Waste Radar. Their count is **not** equivalent to business impact.
2. Start an investigation from a recurring pattern or create a case manually.
3. Record direct observations in Gemba, including timestamps and supporting metrics.
4. Add multiple Ishikawa hypotheses. Evidence is mandatory before confirming a cause.
5. Record 5 Whys answers, then capture a countermeasure, baseline, outcome and standardization in PDCA.
6. In the case's PDCA section, record the **correction time**. Leave the datetime blank to use the current server time, or choose when the fix was applied previously; the displayed timezone is the host application's PHP timezone. A fingerprint-linked case created from the Pareto list is required.
7. Check the before/after numbers, sample coverage, first/last seen, and the overall post-correction recurrence count. These are **event counts from the configured log tail**, not rates normalized by traffic. The equal windows cover up to the first 24 hours after the fix; a separate count includes any later occurrences still in the sample.
8. Only close a case manually after assessing comparable activity and the actual effect. "No recurrence observed" is a candidate, **not verified resolution**. If the baseline has rotated away or there is no post-fix log activity, the analyzer says "Insufficient evidence".

## Security and data

- Web pages require ROLE_ADMIN; POST forms use CSRF checks.
- UI and CLI log summaries redact common bearer/basic tokens, structured passwords, API keys, query-string tokens and email addresses, but **redaction is not comprehensive**. Never log credentials or place personal data in investigation notes.
- The application does not export telemetry to external services.
- The log file and storage directory are trusted server-side configuration values, never user-provided paths.
- Records can contain sensitive operational detail. Protect backups, filesystem permissions, and admin access; no retention policy is implemented yet.
- Trends refer only to the sampled log tail; missing earlier data does not prove there were no failures.

## Local development

~~~bash
composer install
vendor/bin/phpunit
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
~~~

No GitHub Actions or CI is configured. Local test commands and release acceptance criteria are documented in [Release checklist](docs/RELEASE_CHECKLIST.md).
