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

## Phing-based commands (`build`, `link`, `relink`, `update`)

These rely on [Phing](https://www.phing.info/) and the Akeeba Build Files (`build.xml` or `build/build.xml`). They are only useful in repos that use the Akeeba build system.
