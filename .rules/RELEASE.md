# Release & Publishing

Load this when touching `release.yml`, `.releaserc.json`, versioning, or Packagist publishing.

## The contract

1. **Conventional Commits drive the version.** On push to `main`, `semantic-release` reads the commit
   history since the last tag: `fix:` → patch, `feat:` → minor, `feat!:`/`BREAKING CHANGE:` → major.
   `perf:` also releases. Anything else (`chore`, `docs`, `ci`, `refactor`, `test`) does **not** release.
2. **It creates the tag `vX.Y.Z` and the GitHub Release, and writes nothing else.** No commit, no
   `CHANGELOG.md`, no version bump in the tree. See "Why nothing is committed back to `main`".
3. **The tag IS the version, with no wiring at all.** `composer.json` carries no `version` field on
   purpose: Packagist derives the version from the tag, Composer records it in the installed
   metadata, and `Config` reads it back through `InstalledVersions::getPrettyVersion()` for the
   `User-Agent`. There is no version literal anywhere in this repo and none is needed.
4. **There is no publish job.** Packagist does not support OIDC/token upload from CI — instead it ingests
   new tags through a one-time **GitHub service hook**. Once configured, every pushed `vX.Y.Z` tag
   appears on Packagist automatically. No token or environment secret is stored in the repo.

## Why nothing is committed back to `main`

`main` is covered by a ruleset requiring a pull request and the gated checks. A `chore(release):`
commit pushed straight to `main` by the workflow violates it, and the obvious fix does not exist:
**`github-actions[bot]` cannot be added to a ruleset bypass list.** Bypass is available to admins,
the maintain/write role, teams, GitHub Apps and Dependabot, and the built-in Actions identity is none
of those. Making the commit work would mean introducing a separate identity — a GitHub App or a
deploy key — purely to write a version number that the tag already carries.

So `.releaserc.json` loads neither `@semantic-release/git` nor `@semantic-release/changelog`. The
release writes one tag and one GitHub Release. **The generated release notes are the changelog**;
there is no `CHANGELOG.md` in this repo, and adding one back would reintroduce the commit.

## Required setup (one-time, per repo)

- GitHub **environment** `release` should exist (Settings → Environments) with protection rules; the
  `release` job runs in it. No registry environment is needed.
- **Register the package on [Packagist](https://packagist.org/packages/submit)** (`mailkube/mailkube-laravel`)
  and enable the **GitHub service hook** (Packagist shows the exact webhook to add) so tags auto-publish.

## Do not

- Do not add a `CHANGELOG.md`, a `version` field in `composer.json`, a `@semantic-release/git`
  plugin, or a version literal in PHP source, and do not move tags.
- Do not commit `composer.lock` — this is a library; consumers resolve their own versions.
- Do not gate `release.yml` on anything weaker than the full `ci.yml` (`test` + `package` +
  `deps-floor` + `dry` + `docs`).
- Do not relax `.gitattributes` without re-reading the `package` job. Packagist publishes a
  `git archive` of the tag, so that file is the packaging manifest: dropping `config/` from the
  archive ships a package whose service provider cannot boot, and the failure surfaces on a
  consumer's machine after the tag exists.
- Do not raise `sdk_min_version` without checking what the integration actually calls. The floor is
  a promise about which SDK releases this package works against, and the `deps-floor` job is the
  only thing that tests it.
