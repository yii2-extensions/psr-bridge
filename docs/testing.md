# Testing

Install development dependencies from the chosen package revision, then run these checks from the package root:

```bash
composer tests
composer static
vendor/bin/ecs check
composer check-dependencies
```

`composer ecs` applies fixes; use the direct ECS check above for a read-only style check. PHPStan analyzes source
and tests at the configured maximum level. PHPUnit fails on risky tests and warnings.

CI runs PHP 8.3, 8.4, and 8.5 separately with Yii `2.0.55` (the minimum supported release) and `22.0.x-dev`. To repeat
either resolution locally, use separate disposable checkouts so their dependencies and lock files cannot affect each other:

```bash
composer update --with yiisoft/yii2:2.0.55 --with-all-dependencies
composer tests
composer audit
```

Repeat in the other checkout with `--with yiisoft/yii2:22.0.x-dev`. Keep advisory blocking enabled for both runs.

To focus on worker session and response lifecycle regressions:

```bash
vendor/bin/phpunit tests/http/stateless/ApplicationSessionTest.php
vendor/bin/phpunit tests/http/stateless/ApplicationFinalizeTest.php
```

Validate the application's actual worker integration too: alternate two visitors, omit cookies on the next request,
send malformed session cookie values, exercise an upload followed by no upload, and force emission failure. Confirm
after-send runs only after successful emission. Repeat with `useSession=false` when using lazy sessions.

Mutation testing is a separate, slower check that needs a supported coverage driver:

```bash
composer mutation
```

`composer rector` modifies source; do not run it as a read-only validation command. Report the PHP version, resolved
dependencies, passed checks, and unavailable coverage/runtime tests rather than treating a skipped check as passed.

See [agent validation stages](agents.md#verify-each-stage) for skill installation and native discovery checks.

## Coding standards (ECS)

Check standards without changing files:

```bash
composer ecs-check
```

`composer ecs` applies fixes. Review its changes before keeping them.

## Repository quality checks

Make the CLI tools used by the shared quality workflow available on `PATH`:
actionlint with its ShellCheck helper, codespell, EditorConfig Checker, markdownlint-cli2, Prettier and
yamllint. The command reads the repository's linter, formatter and ignore files. It skips ignored local dependency, runtime and private artifact trees that are absent from a clean CI checkout.

```bash
composer quality
```

## Workflow security checks

Make zizmor and Gitleaks available on `PATH`. Audit the current workflow files
with the same pedantic persona used by the shared security workflow:

```bash
composer zizmor
```

Run that audit and scan Git history with the repository's Gitleaks configuration:

```bash
composer security
```

Zizmor's online audits require GitHub API access. Report offline limitations separately; do not count a skipped audit as passed.

[Back to documentation](../README.md#documentation)
