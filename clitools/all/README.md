# The `all` tool

This script can be used under any bash shell such as the one commonly used in Linux and Mac OS X, or the bash shell provided in Windows by Git Bash, Cygwin etc. It is meant to iterate through each of the subdirectories, figure out if it contains a Git repository and then pull or push it.

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

Copying a file into PhpStorm project settings directories:

`$ ./all icopy /path/to/reference-file.xml`

This copies the reference file into `.idea/` in every first-level directory that has one, overwriting a file with the same name.
