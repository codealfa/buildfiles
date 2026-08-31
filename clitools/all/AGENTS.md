# all / all.ps1 — Agent Reference

## Purpose

These tools loop over every Git repository in the current working directory and run a batch operation on each one. They are intended to be placed somewhere on `$PATH` and run from a parent directory that contains many Git repos (e.g. `~/Projects/akeeba`).

Run `./all` with no arguments for the list of commands.

## Repository Discovery

Every qualifying Git repo is processed regardless of remote URL — the old GitHub-only filter is intentionally disabled/removed in both scripts.

The Bash script qualifies a first-level subdirectory as a Git repository only when `.git` is a directory. This deliberately excludes Git worktrees and submodules, whose `.git` entry is normally a file.

## Command Parity

The two scripts are not feature-identical:

- `icopy` is **Bash only** — it is not implemented in `all.ps1`.
- `vlimits` is **Bash only** — it is not implemented in `all.ps1`.
- `phpcompat` is **Bash only** — it is not implemented in `all.ps1`.
- `fixcrlf` is **PowerShell only** — it unsets `core.fileMode`, `core.filemode` and `core.autocrlf` to fix line-ending issues on Windows.

## Script Conventions

### PowerShell (`all.ps1`)
- `cd` / `Set-Location` within a `Push-Location` block is safe — `Pop-Location` uses the stack, not the current location.
- The `Switch` statement uses the PowerShell `default` keyword (not a string `"default"`) for the fallthrough case.

## `vlimits`

Reports the version constraints declared in each repository's `composer.json` file.

The parsing is done in PHP, by `Akeeba\BuildFiles\VersionLimit\Reporter`, which doubles as an executable script;
Composer's version constraint syntax is not something to reimplement in Bash.

The script is hard-linked into place by `install`, so it cannot assume it lives inside the buildfiles repository. It
looks for a `buildfiles` subdirectory next to itself — which is what the linked copies see — and only if that is missing
does it fall back to `../../`, which is where the original script lives. Do not replace this with a hardcoded path.

## `phpcompat`

Reports PHP cross-version compatibility findings per repository, checked against the PHP range declared in that
repository's `composer.json`.

Implemented in PHP by `Akeeba\BuildFiles\PhpCompat\Sweep`, which — like `VersionLimit\Reporter` — doubles as an
executable script. It locates the buildfiles repository exactly the way `vlimits` does, and for the same reason: the
`all` script is hard-linked into place by `install`, so it cannot assume it lives inside buildfiles.

Three things about it are load-bearing and easy to break:

1. **The PHP range is derived, never passed in.** It comes from `require.php` via `VersionLimit\DeclaredLimits`. Do not
   add a flag to override it; a sweep whose range does not match the repository's own claim tells you nothing.
2. **The upper end is clamped to `Sweep::SNIFF_CEILING`.** PHPCompatibility does not validate `testVersion` — ask it
   about a PHP version it has no data for and it reports nothing, which is indistinguishable from a clean result. The
   clamp plus the printed warning is what stops that from reading as good news. Bump the constant when
   PHPCompatibility ships support for a new PHP version, and not before.
3. **Joomla! extensions use `phpcompat/joomla.xml`, everything else uses stock `PHPCompatibility`.** The Joomla ruleset
   suppresses findings for symbols the Symfony polyfills bundled with Joomla! provide. Applying it to a standalone
   application would hide real breakage, so the choice is made from `extra.akcompat.limit_type`, not by hand.

Baselines live in each repository at `build/phpcompat-baseline.json` and are keyed by (file, sniff) *count*, never by
line number — line numbers move on every unrelated edit above a finding.

## Phing-based commands (`build`, `link`, `relink`, `update`)

These rely on [Phing](https://www.phing.info/) and the Akeeba Build Files (`build.xml` or `build/build.xml`). They are only useful in repos that use the Akeeba build system.
