# Contributing to Novora KaizenBundle

Thank you for helping improve the bundle. Bug reports, documentation corrections, focused tests, and small pull requests are welcome.

Our goal is deliberately narrow: **prioritize recurring technical symptoms, investigate causes with evidence, and verify changes using available logs**. More features do not automatically mean more value.

## Before opening an issue

Search existing issues first. For reproducible bugs, include the bundle commit or version, PHP/Symfony versions, expected behavior, actual behavior, and a minimal reproduction. **Never attach real credentials, private log entries, user details, or production data.** Use synthetic examples. For security vulnerabilities, follow [SECURITY.md](SECURITY.md), not public issues.

For a feature request, describe the specific wasted work (**Muda**) it would remove, why the existing workflow is insufficient, and the simplest possible alternative. We may decline proposals that increase complexity without measurable value.

## Local development

~~~bash
composer install
composer validate --strict --no-check-publish
vendor/bin/phpunit
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
~~~

Run on PHP 8.2+; the public CI checks PHP 8.2 and 8.4. The supported Symfony versions are 7.4 and 8.x. No external services or application database are required for the test suite.

## Pull requests

- Open a focused PR addressing a single problem.
- Include a regression test for behavior changes.
- Preserve the documented 1.x behavior and storage format unless an intentional compatibility change is discussed.
- Update README/CHANGELOG/SECURITY when relevant.
- Keep investigation evidence distinct from hypotheses: do not add automatic root-cause or resolution claims.
- Keep the bounded log-reader and privacy safeguards intact.
- Do not modify unrelated application business logic or introduce background infrastructure.

## Maintainer process

Contributions are reviewed for **correctness, security, clarity, and unnecessary operational overhead**. The maintainer may ask for simplification or reject changes that recreate Muda.

All contributions are submitted under the repository's [MIT license](LICENSE).
