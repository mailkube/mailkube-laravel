# Engineering Standards: SOLID · DRY · KISS · Coverage · Docs

These are **enforced by CI** — a PR that violates them cannot merge. This file tells you the exact
thresholds and how to satisfy each gate locally *before* pushing.

## The gates

| Gate | Rule | Enforced by |
|---|---|---|
| **Coverage** | ≥ 90% line | `phpunit --coverage-clover` + `scripts/check-coverage.sh` (the `test` CI job) |
| **DRY** | ≤ 1% duplicated code | `jscpd` (the `dry` CI job) |
| **KISS** | cyclomatic complexity ≤ 10 per unit | `phpmd` `codesize` ruleset (the `test` CI job) |
| **Documentation** | every class + public method has a docblock | `phpcs` (`Squiz.Commenting.*`) + slevomat type hints |
| **SOLID** | see below — approximated by analysis + review | `phpmd` `design`/`unusedcode` + phpstan strict + PR checklist |
| **Strict typing** | no findings at phpstan level max | `phpstan` + `larastan` + `phpstan-strict-rules` (the `test` CI job) |
| **Formatting** | PSR-12 clean | `php-cs-fixer fix --dry-run` (the `test` CI job) |

> **Coverage is line only.** PHPUnit/pcov report covered-vs-total statements but no reliable branch
> metric, so there is nothing further to gate on. Keep tests exercising every meaningful path
> anyway — the number is a floor.

> **Larastan is part of the typing gate, not an optional extra.** Laravel's facades, `config()` and
> container helpers are dynamic by design and PHPStan cannot see through them unaided. Without the
> extension, level max reports hundreds of false positives, and a gate nobody can satisfy is a gate
> that gets switched off.

## Run the gates locally

```bash
composer format:check                                        # PSR-12 formatting (php-cs-fixer)
composer cs                                                  # docs + type hints (phpcs)
composer analyse                                             # strict static analysis (phpstan max + larastan)
composer mess                                                # complexity (KISS) + design (SOLID) (phpmd)
composer test -- --coverage-clover=coverage.clover           # tests + coverage report
./scripts/check-coverage.sh coverage.clover                  # 90% coverage gate
npx --yes jscpd@4 --config .jscpd.json .                     # duplication (DRY) gate
./scripts/check-rule-index.sh                                # every .rules/*.md indexed in AGENTS.md
```

`pre-commit run --all-files` runs the php-cs-fixer + phpcs + phpstan + phpmd + jscpd hooks in one
shot.

**Run them against more than one Laravel major before pushing anything that touches the mail stack.**
CI does; locally you get whatever your lockfile resolved. `composer update --with="laravel/framework:^12.0"`
switches the axis, and the surprises live in Symfony Mailer's internals rather than in Laravel's own
API.

## SOLID, concretely (paradigm-neutral guidance)

SOLID is not a single analyzer rule; keep these in mind and confirm them in the PR checklist:

- **S**ingle responsibility — a class/method does one thing; if you need "and" to describe it, split it.
- **O**pen/closed — extend via new classes/strategies, not by editing stable call sites.
- **L**iskov — subtypes honor their base's contract (types, exceptions, invariants).
- **I**nterface segregation — small, focused `interface`s; unused parameters (phpmd `UnusedFormalParameter`) are a smell.
- **D**ependency inversion — depend on an `interface` at I/O and network boundaries, and inject it.

In this package specifically, the single-responsibility line is drawn by
`.rules/INTEGRATION_CONTRACT.md`: one payload module, one config module, and no third place that
knows how to build either. When a change wants to add one, that is the rule it is arguing with.

## Requesting a waiver

If a threshold is genuinely wrong for a specific spot, add a **scoped, commented** ignore
(e.g. `@SuppressWarnings(PHPMD.CyclomaticComplexity)` with a `// reason`, or a `@phpstan-ignore-line`)
and call it out in the PR. Blanket relaxations (lowering the coverage threshold, dropping a phpstan
level, disabling a ruleset globally) require maintainer sign-off.
