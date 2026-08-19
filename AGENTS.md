# Project Rules

`mailkube-laravel` is a public (Apache-2.0) Laravel mail transport for
mailkube, distributed as `mailkube/mailkube-laravel` on packagist. It
wraps the `mailkube/mailkube-php` SDK and talks to no API of its own. Load the relevant rule
file from `.rules/` based on the task.

## Rule Index

> **Index every rule (required).** Every file in `.rules/` MUST have a row in the table below. When you
> add or rename a `.rules/` file, add or update its row in the **same change** — an unindexed rule is
> invisible, because this index is what drives progressive disclosure. The `docs` CI job (`scripts/check-rule-index.sh`)
> fails the build if `.rules/` and this index drift. This convention holds for every mailkube repo.

| Rule File | Load When |
|---|---|
| `.rules/SOLID_DRY_KISS.md` | Writing or changing any code — the enforced engineering standards (SOLID, DRY, KISS, coverage, docs) and how to run each gate locally. |
| `.rules/INTEGRATION_CONTRACT.md` | Touching the transport, the payload mapping, the settings surface, the webhook entry point, or anything tempted to talk to the API directly: the decisions every mailkube framework integration implements identically. Shared verbatim across every integration; changes are made centrally. |
| `.rules/LARAVEL_INTEGRATION.md` | The same tasks, for the **Laravel realization**: the file-to-responsibility map, why the transport extends `AbstractTransport`, the two-source recipient split, the webhook route's defaults, and the two recorded deviations. |
| `.rules/SDK_CONTRACT.md` | Understanding what the SDK underneath guarantees — config resolution, errors, pagination, webhook signatures — before assuming this package has to do any of it. Shared verbatim across every mailkube repo; changes are made centrally. |
| `.rules/RELEASE.md` | Touching `release.yml`, `.releaserc.json`, `.gitattributes`, versioning, or the Packagist publish flow. |
| `.rules/CI_GATES.md` | Adding, removing or weakening a CI job, or when a release fails after the tag was already pushed: why the publish-readiness, dependency-floor, example-compilation and release-permission gates exist. Shared verbatim across every mailkube repo; changes are made centrally. |

## Key Conventions (always apply)

- **This is an adapter, not an SDK.** No HTTP client, no request serialization, no HMAC, no error
  envelope parsing. If you are writing one of those, you are in the wrong repository.
- **One payload module and one config module** — `src/Payload.php` and `src/Config.php`. Every entry
  point calls them; nothing else maps a message or builds a client.
- **An unset setting is omitted, never passed as null**, so the SDK's own environment fallbacks
  survive.
- **The version is never a literal.** `Config::userAgentSuffix()` reads it from the installed package
  metadata, which is what the release updates.
- **No models, no migrations, no tables.** This package persists nothing, which is also what keeps
  the test suite free of a database.
- **SDK errors are translated at the boundary** into `TransportException`, because Symfony's failover
  and Laravel's queued-mail failure handling key off that interface and nothing else.
- **Webhooks verify against the raw request body.** Never a parsed-then-re-encoded one.
- **Synchronous throughout**, inherited from `doSend()`. Never start an event loop; an async entry
  point would be a new class alongside.
- Strict types in every file (`declare(strict_types=1)`), PSR-12, phpstan level max with larastan.
- Coverage ≥ 90% line; complexity ≤ 10; jscpd ≤ 1%.
- Docblocks on every class and public method; update them when behaviour changes.
- **Conventional Commits** for PR titles — only `feat:`, `fix:` and `perf:` release.
- **No `CHANGELOG.md`**: the GitHub Release notes are the changelog.
- **No secrets in the repo.** Local configuration goes in a git-ignored `.env`.
- Keep `README.md` current: it is the only documentation of the wiring, because this package ships
  no `examples/`.
