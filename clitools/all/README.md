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

Copying a file into PhpStorm project settings directories:

`$ ./all icopy /path/to/reference-file.xml`

This copies the reference file into `.idea/` in every first-level directory that has one, overwriting a file with the same name.
