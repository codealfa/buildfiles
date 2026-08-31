# The `all` tool

This script can be used under any bash shell such as the one commonly used in Linux and Mac OS X, or the bash shell provided in Windows by Git Bash, Cygwin etc. It is meant to iterate through each of the subdirectories, figure out if it contains a Git repository and then pull or push it.

A first-level subdirectory qualifies as a repository only when its `.git` entry is a directory. Git worktrees and submodules, whose `.git` entry is normally a file, are intentionally not processed.

Usage: ./all <command>

Pulling all repositories:

`$ ./all pull`

This runs a `git pull --all` on all first level subdirectories containing a Git working copy

Pushing all repositories:

`$ ./all push`

This runs a `git push; git push --tags` on all first level subdirectories containing a Git working copy

Showing dirty repositories and their changed files:

`$ ./all status --files`

By default, `status` prints only dirty repository names. With `--files`, it also prints each changed file with an emoji showing whether it is new, deleted, modified, or untracked.

Reporting the declared version constraints:

`$ ./all vlimits`

This prints the range of PHP and CMS versions each repository supports, as declared in its `composer.json` file —
`require.php` for PHP, and `extra.akcompat.limit` / `extra.akcompat.limit_type` for the CMS. Both ends of the range are
major.minor version families, e.g. `PHP 7.4 – 8.3`. Repositories which declare neither print "No constraints";
standalone and CLI projects, which run outside a CMS, print their PHP range only.

When the constraint ends at a major version, e.g. `<9.0`, the last supported minor version is unknowable — nobody knows
how many minor versions PHP 8 will end up having — so the whole major version family is reported instead: `7.4 – 8.x`.
Joomla is the exception: its release trains end at x.4, so `<6.0` is correctly reported as `4.4 – 5.4`.

This command needs PHP, and the buildfiles repository: either a `buildfiles` subdirectory next to the `all` script, or
the script's original location inside the buildfiles repository itself.

Checking PHP cross-version compatibility:

`$ ./all phpcompat`

This reports, for each repository, the PHP features it uses which are deprecated, removed, or not yet available
anywhere in the PHP version range that repository declares in its `composer.json` — the same range `vlimits` reports.
The range is never passed in by hand; each repository is checked against what it actually claims to support.

Green means clean, amber means there are findings, red means the sweep could not run there.

Findings already recorded in a repository's `build/phpcompat-baseline.json` are not reported, so the sweep surfaces
what is *new* rather than re-reading a known backlog at you every time. Run `phing php-compat` inside a repository for
the full per-file report, and `phing php-compat-baseline` to record its current state.

One caveat is printed on every line it applies to: the PHPCompatibility sniffs only carry data up to a certain PHP
version, and a declared range which extends past it is silently clamped. When you see `(PHP 8.6 unchecked)`, that
version is genuinely unchecked — no static analyser has the data yet, and the only way to cover it is to run the test
suite on it.

Like `vlimits`, this command needs PHP and the buildfiles repository.

Copying a file into PhpStorm project settings directories:

`$ ./all icopy /path/to/reference-file.xml`

This copies the reference file into `.idea/` in every first-level directory that has one, overwriting a file with the same name.
