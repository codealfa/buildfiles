# all / all.ps1 — Agent Reference

## Purpose

These tools loop over every Git repository in the current working directory and run a batch operation on each one. They are intended to be placed somewhere on `$PATH` and run from a parent directory that contains many Git repos (e.g. `~/Projects/akeeba`).

## Repository Discovery

Both scripts:
- Iterate over immediate subdirectories only (non-recursive).
- Skip non-directories (files).
- Skip symbolic links / junction points.
- Skip directories that do not contain a `.git` folder.
- Process every qualifying Git repo regardless of remote URL (the old GitHub-only filter is intentionally disabled/removed in both scripts).

## Commands

| Command   | Description |
|-----------|-------------|
| `pull`    | `git pull --all -p -t --jobs=4` — fetch all remotes, prune dead refs, fetch tags, use 4 parallel jobs |
| `push`    | `git push` (current branch) + `git push --tags` (all tags) |
| `tidy`    | `git remote prune origin` + `git gc` |
| `status`  | Print repo name only when there are uncommitted changes; `./all status --files` (or PowerShell `-operation status -files`) also prints each changed file with a status emoji |
| `branch`  | Print repo name + current branch; colour-coded: green = `development`, yellow = `master`/`main`/`kyrion`, red = anything else |
| `cloneme` | Print `git clone` commands to reproduce the current checkout; appends a `git switch` line when HEAD is on a tag |
| `version` | Compare the highest version number found in `CHANGELOG` / `CHANGELOG.md` against the latest Git tag; prints "Unreleased" or "Released" accordingly |
| `tag`     | Print the most recent tag and its relative date (`git for-each-ref --sort=-taggerdate`) |
| `icopy`   | Copy the supplied reference file into the `.idea/` directory of every first-level directory that has one, overwriting a file with the same name |
| `build`   | Run `phing git` from either the repo root (if `build.xml` exists) or the `build/` subdirectory |
| `link`    | Run `phing link` from either the repo root (if `build.xml` exists) or the `build/` subdirectory |
| `relink`  | Run `phing relink -Dsite=<path>` — takes a second argument (the site path) |
| `update`  | Run `phing update` — pushes release artefacts to the CDN via Akeeba Release Maker |
| `fixcrlf` | **PowerShell only.** Unsets `core.fileMode`, `core.filemode`, `core.autocrlf` to fix line-ending issues on Windows |

## Script Conventions

### Bash (`all`)
- Uses ANSI colour constants (`COLOR_*`).
- Uses `pushd` / `popd` for directory navigation.
- The `version` command's grep regex targets `CHANGELOG` files that list versions as `X.Y.Z` preceded by whitespace.

### PowerShell (`all.ps1`)
- Accepts named parameters: `-operation <command>` and `-sitepath <path>` (used by `relink`).
- Uses `Push-Location` / `Pop-Location` for directory navigation.
- `cd` / `Set-Location` within a `Push-Location` block is safe — `Pop-Location` uses the stack, not the current location.
- The `Switch` statement uses the PowerShell `default` keyword (not a string `"default"`) for the fallthrough case.
- The `version` command uses `Select-String` with regex `\s(\d+\.\d+\.\d+)` to extract version numbers from changelog files.
- Symlink/junction detection uses `[System.IO.FileAttributes]::ReparsePoint`.

## Phing-based commands (`build`, `link`, `relink`, `update`)

These rely on [Phing](https://www.phing.info/) and the Akeeba Build Files (`build.xml` or `build/build.xml`). They are only useful in repos that use the Akeeba build system.
