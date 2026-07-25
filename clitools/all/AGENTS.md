# all / all.ps1 — Agent Reference

## Purpose

These tools loop over every Git repository in the current working directory and run a batch operation on each one. They are intended to be placed somewhere on `$PATH` and run from a parent directory that contains many Git repos (e.g. `~/Projects/akeeba`).

Run `./all` with no arguments for the list of commands.

## Repository Discovery

Every qualifying Git repo is processed regardless of remote URL — the old GitHub-only filter is intentionally disabled/removed in both scripts.

## Command Parity

The two scripts are not feature-identical:

- `icopy` is **Bash only** — it is not implemented in `all.ps1`.
- `fixcrlf` is **PowerShell only** — it unsets `core.fileMode`, `core.filemode` and `core.autocrlf` to fix line-ending issues on Windows.

## Script Conventions

### PowerShell (`all.ps1`)
- `cd` / `Set-Location` within a `Push-Location` block is safe — `Pop-Location` uses the stack, not the current location.
- The `Switch` statement uses the PowerShell `default` keyword (not a string `"default"`) for the fallthrough case.

## Phing-based commands (`build`, `link`, `relink`, `update`)

These rely on [Phing](https://www.phing.info/) and the Akeeba Build Files (`build.xml` or `build/build.xml`). They are only useful in repos that use the Akeeba build system.
