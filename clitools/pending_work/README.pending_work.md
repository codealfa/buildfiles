# pending_work.sh

A Bash script that scans local Git working copies and GitHub issues to produce a consolidated overview of pending work and upcoming releases.

## Requirements

- Bash 4.4+
- Git
- [GitHub CLI (`gh`)](https://cli.github.com/) authenticated with `repo` and `read:org` scopes
- `jq`

## Usage

```bash
# Terminal report (ANSI-colored output)
./pending_work.sh

# HTML report
./pending_work.sh --html > report.html

# Force-refresh the GitHub issues cache
./pending_work.sh --force

# Both flags can be combined
./pending_work.sh --html --force > report.html
```

## Flags

| Flag | Description |
|---|---|
| `--html` | Output the report as an HTML 5 document instead of ANSI terminal output. |
| `--force` | Skip the GitHub issues cache and fetch fresh data from the API. |
| `--help`, `-h` | Print a short usage summary and exit. |

## What it does

The script looks under `~/Projects` for Git repositories belonging to the `akeeba`, `j4-akeeba`, and `dionysopoulos` organisations. A repository is considered actionable if it contains a top-level `CHANGELOG` or `CHANGELOG.md` file and is not in the exclusion list (see below).

For each actionable repository, the script checks three things:

1. **Dirty working copy** -- does `git status` report modified, added, or deleted files?
2. **Changelog vs. tag mismatch** -- does the latest version in the CHANGELOG differ from the latest published Git tag?
3. **Stale tag** -- was the latest Git tag published more than 21 days ago?

Repositories are then classified into four report sections.

### Report sections

#### Dirty Working Copies

Lists repositories with uncommitted changes.

#### Outdated

Repositories whose latest tag is older than 21 days but whose CHANGELOG matches the tag (i.e. no new work has been recorded yet). Each entry shows the tag name and its publish date.

#### Pending Releases

Repositories that are both outdated *and* have a newer version in the CHANGELOG than the latest tag -- meaning there is unreleased work waiting to be tagged and published. Each entry shows:

- The current tag and its date
- The upcoming version from the CHANGELOG
- The full changelog excerpt for that version
- A priority indicator before the repository name:
  - **!!** if the excerpt contains high-priority bug fixes (`# [HIGH] ...`)
  - **ALERT** if the excerpt contains top-priority / breaking changes (`! ...`)

#### Open GitHub Issues

Open issues across `org:akeeba` and `user:nikosdion` in non-archived repositories, fetched via the GitHub CLI. Each entry shows the issue number, title, repository, and open date.

GitHub issue data is cached for 6 hours at `~/.cache/pending_work/github_issues.json` (or `$XDG_CACHE_HOME/pending_work/` if set). Use `--force` to bypass the cache.

## Excluded repositories

The following repository names are skipped during scanning:

`tpl_andromeda`, `angie`, `angie2`, `usagestats`, `fef`, `fef-1.x`, `hexbound`, `tpl_cassiopeia_twentytwo`, `devskills`

## Defaults

| Setting | Value |
|---|---|
| Projects directory | `~/Projects` |
| Stale tag threshold | 21 days |
| GitHub cache lifetime | 6 hours |
| Date display timezone | Europe/Athens |

## HTML output

The `--html` flag produces a standalone HTML 5 document with:

- Dark and light themes via `prefers-color-scheme`
- Responsive layout
- Card-based entries with colour-coded sections
- Collapsible changelog excerpts (using `<details>` / `<summary>`)
- Clickable links to GitHub issues

## Scheduled email reports

The `email_report.sh` wrapper script runs `pending_work.sh --html` and sends the output as an HTML email using [msmtp](https://marlam.de/msmtp/).

### Requirements

- **msmtp** — a lightweight SMTP client. Install with your package manager (e.g. `pacman -S msmtp` on Arch Linux, `apt install msmtp` on Debian/Ubuntu).
- A configured `~/.msmtprc` file with your SMTP credentials (see below).

### msmtp configuration

Create `~/.msmtprc` with permissions `600`:

```
defaults
auth           on
tls            on
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile        ~/.msmtp.log

account        mailbox
host           smtp.example.com
port           587
from           you@example.com
user           you@example.com
password       YOUR_PASSWORD_HERE

account default : mailbox
```

> **Note:** Because `~/.msmtprc` contains credentials, it is best managed with a dotfile management tool such as [chezmoi](https://www.chezmoi.io/) so that secrets are encrypted at rest and the file is deployed with correct permissions across machines.

### Cron setup

Add a cron entry to receive the report every weekday at 8 AM local time:

```
0 8 * * 1-5  /path/to/pending_work/email_report.sh
```

The script resolves its own directory to locate `pending_work.sh`, so it works regardless of the working directory cron uses.
