# Contributing to mailkube-laravel

Thanks for helping improve **mailkube-laravel**, the Laravel integration for
[mailkube](https://mailkube.com).
Contributions of all kinds are welcome: bug reports, fixes, docs, and features.

By contributing you agree that your contributions are licensed under the project's
[Apache License 2.0](LICENSE) (inbound = outbound). **No CLA and no sign-off are required.**
Please also read our [Code of Conduct](CODE_OF_CONDUCT.md).

## Development setup

Requires PHP 8.3+ (with the `pcov` extension for coverage),
[Composer](https://getcomposer.org/), and Node.js (for the `jscpd` duplication check).

```bash
git clone https://github.com/mailkube/mailkube-laravel
cd mailkube-laravel

composer install
pre-commit install                            # php-cs-fixer + phpcs + phpstan + phpmd + jscpd hooks
pre-commit install --hook-type commit-msg     # Conventional Commits hook
```

## Quality gates

Every change must pass the same checks CI runs (see [.rules/SOLID_DRY_KISS.md](.rules/SOLID_DRY_KISS.md)):

```bash
composer format:check                                 # PSR-12 formatting (php-cs-fixer)
composer cs                                            # docs + type hints (phpcs)
composer analyse                                       # strict static analysis (phpstan max + larastan)
composer mess                                          # complexity (KISS) + design (SOLID) (phpmd)
composer test -- --coverage-clover=coverage.clover     # tests + coverage
./scripts/check-coverage.sh coverage.clover            # 90% coverage gate
npx --yes jscpd@4 --config .jscpd.json .               # duplication (DRY) gate, blocks at > 1%
./scripts/check-rule-index.sh                          # every .rules/*.md indexed in AGENTS.md
```

`pre-commit run --all-files` runs the format/lint/analysis/jscpd hooks in one shot.

**Run the suite against more than one Laravel major** before pushing anything that touches the mail
stack. CI runs a matrix; locally you get whatever your lockfile resolved, and the surprises live in
Symfony Mailer's internals rather than in Laravel's own API:

```bash
composer update --with="laravel/framework:^12.0" && composer test
```

This package wraps an SDK, so there is one more thing to know before changing behaviour: **the
capability has to exist in `mailkube/mailkube-php` first.** If it does not, the change
starts in that repository, not this one. See [.rules/INTEGRATION_CONTRACT.md](.rules/INTEGRATION_CONTRACT.md).

## Branches

`develop` is the integration branch: open pull requests against it, and CI runs on every push to
it. `main` is the release branch — merging `develop` into it is what cuts a version, so nothing
lands there except through that merge. See [.rules/RELEASE.md](.rules/RELEASE.md).

Dependency updates target `develop` for the same reason. Their configuration names the branch
explicitly, and a branch that does not resolve produces no pull requests at all, with no error —
so if updates go quiet, check that `develop` still exists before looking anywhere else.

## Commit & PR conventions

This project follows **[Conventional Commits](https://www.conventionalcommits.org/)**. A CI check
enforces the **PR title** (PRs are **squash-merged** using it), and it drives releases: only
`feat:`, `fix:`, and `perf:` cut a new version. See [.rules/RELEASE.md](.rules/RELEASE.md).

Suggested scopes: `transport`, `webhooks`, `config`, `ci`, `deps`, `docs`.

```
feat(transport): map inline attachments
fix(models): correct optional field serialization
docs: document the pagination helper
```

## Reporting bugs / requesting features

Open an issue using the templates. For **security vulnerabilities**, do not open a public
issue — follow [SECURITY.md](SECURITY.md) instead.
