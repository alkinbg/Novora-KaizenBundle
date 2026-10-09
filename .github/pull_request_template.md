## Problem being solved

Describe the reproducible problem or wasted work (Muda). Explain why the change is needed.

## Proposed change

Explain the smallest implementation that resolves the problem. Describe what was deliberately left out.

## Verification

- [ ] Tests were added or updated for behavior changes
- [ ] `vendor/bin/phpunit` passes locally
- [ ] `composer validate --strict --no-check-publish` passes
- [ ] PHP syntax checks pass for changed files
- [ ] README / CHANGELOG / SECURITY updated where relevant
- [ ] No real credentials, identifiers, customer data or diagnostic secrets are included

## Compatibility and safety

- [ ] Existing Symfony 7.4 / 8.x and PHP 8.2+ behavior is preserved or explicitly documented
- [ ] No unsupported root-cause or resolution claims are introduced
- [ ] Log processing remains bounded and UI/admin protections remain intact
- [ ] No unnecessary services, background jobs, instrumentation or configuration were added

## Notes for reviewers

Add focused context, trade-offs, and any remaining limitations.
