<!--
PR titles MUST follow Conventional Commits (e.g. `fix(client): ...`) — it is CI-enforced and
becomes the squash-merge commit message. Only feat/fix/perf trigger a release.
-->

## What

<!-- Describe the change in 1–2 sentences. -->

## Why

<!-- The user-visible problem this solves, or the motivation. -->

## Quality checklist

- [ ] `composer format:check` passes (php-cs-fixer, PSR-12)
- [ ] `composer cs` passes (phpcs — docs + type hints)
- [ ] `composer analyse` passes (phpstan, level max)
- [ ] `composer mess` passes (phpmd — complexity + design)
- [ ] `composer test` passes and `./scripts/check-coverage.sh coverage.clover` reports ≥ 90%
- [ ] `npx jscpd --config .jscpd.json .` clean (no new duplication)
- [ ] Docs updated (`README.md`) if user-visible

## Engineering standards (SOLID / DRY / KISS)

- [ ] Single-responsibility: new/changed units do one thing; no god-classes
- [ ] No duplication introduced; shared logic extracted (DRY)
- [ ] Public APIs documented (docblock present — phpcs passes)
- [ ] Complexity within limit (no `@SuppressWarnings(PHPMD.*)` complexity waivers added)
- [ ] Depends on abstractions/interfaces where a boundary is crossed (DIP)

## Notes

<!-- Optional: screenshots, follow-ups, breaking-change details. -->
