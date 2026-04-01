## @package   buildfiles
## @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
## @license   GNU General Public License version 3, or later

Param(
	[string]$operation,
	[string]$sitepath
)

function showUsage()
{
	Write-Host "Usage: all <command>" -Foreground DarkYellow
	Write-Host ""
	Write-Host "All repositories" -Foreground Blue
	Write-Host "pull     Pull from Git"
	Write-Host "push     Push to Git"
	Write-Host "tidy     Perform local Git repo housekeeping"
	Write-Host "status   Report repositories with uncommitted changes"
	Write-Host "branch   Which Git branch am I in?"
	Write-Host "cloneme  Generate git clone commands"
	Write-Host "version  Latest version versus latest tag information"
	Write-Host "tag      Latest Git tag and its date"
	Write-Host "fixcrlf  Fix CRLF under Windows"
	Write-Host "Using Akeeba Build Files" -Foreground Blue
	Write-Host "build    Run the Phing 'git' task to rebuild the software"
	Write-Host "link     Internal relink"
	Write-Host "relink   Relink to a site, e.g. all relink c:\sites\mysite"
	Write-Host "update   Push updates to the CDN (uses Akeeba Release Maker)"
}

if (!$operation)
{
	showUsage
	exit 255
}

Write-Host "All - Loop all repositories" -Foreground White
Write-Host ""

Get-ChildItem -Directory | ForEach-Object {
	# Skip symlinks / junction points
	if ($_.Attributes -band [System.IO.FileAttributes]::ReparsePoint)
	{
		return
	}

	$d = $_.Name
	Push-Location $d

	$hasDotGit = Test-Path .git

	if ($hasDotGit -eq $False)
	{
		Pop-Location

		return
	}

	Switch ($operation)
	{
		"pull" {
			Write-Host "Pulling " -Foreground Blue -NoNewline
			Write-Host $d -Foreground Cyan
			git pull --all -p -t --jobs=4
		}

		"push" {
			Write-Host "Pushing " -Foreground Green -NoNewline
			Write-Host $d -Foreground Cyan
			git push
			git push --tags
		}

		"tidy" {
			Write-Host "Housekeeping " -Foreground Red -NoNewline
			Write-Host $d -Foreground Cyan
			git remote prune origin
			git gc
		}

		"status" {
			if ( (git status --porcelain | Measure-Object -Line).Lines -gt 0)
			{
				Write-Host "Dirty " -Foreground Red -NoNewline
				Write-Host $d -Foreground Cyan
			}
		}

		"link" {
			if (Test-Path build.xml)
			{
				Write-Host "Linking " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				phing link
			}

			if (Test-Path build)
			{
				Write-Host "Linking " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				Set-Location build
				phing link
			}
		}

		"build" {
			if (Test-Path build.xml)
			{
				Write-Host "Building " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				phing git
			}

			if (Test-Path build)
			{
				Write-Host "Building " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				Set-Location build
				phing git
			}
		}

		"relink" {
			if (Test-Path build.xml)
			{
				Write-Host "Relinking " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				phing relink -Dsite="${sitepath}"
			}

			if (Test-Path build)
			{
				Write-Host "Relinking " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				Set-Location build
				phing relink -Dsite="${sitepath}"
			}
		}

		"update" {
			if (Test-Path build.xml)
			{
				Write-Host "Pushing updates for " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				phing update
			}

			if (Test-Path build)
			{
				Write-Host "Pushing updates for " -Foreground DarkYellow -NoNewline
				Write-Host $d -Foreground Cyan

				Set-Location build
				phing update
			}
		}

		"branch" {
			$currentBranch = git rev-parse --abbrev-ref HEAD
			$color = "Red"

			if ($currentBranch -eq "development") {
				$color = "Green"
			} elseif ($currentBranch -eq "master") {
				$color = "Yellow"
			} elseif ($currentBranch -eq "main") {
				$color = "Yellow"
			} elseif ($currentBranch -eq "kyrion") {
				$color = "Yellow"
			}

			Write-Host "Branch " -Foreground Magenta -NoNewline
			"{0,-25}" -f $d | Write-Host -Foreground Cyan -NoNewline
			Write-Host "`t$currentBranch" -Foreground $color
		}

		"cloneme" {
			$currentBranch = git branch --show-current
			$remoteUrl = git config --get remote.origin.url
			$currentTag = git describe --exact-match --tags 2>$null

			Write-Host "git clone --single-branch -b $currentBranch `"$remoteUrl`" $d"

			if ($currentTag)
			{
				Write-Host "git -C $d switch -c $currentBranch"
			}
		}

		"version" {
			$changelogFile = $null

			if (Test-Path CHANGELOG)
			{
				$changelogFile = "CHANGELOG"
			}
			elseif (Test-Path CHANGELOG.md)
			{
				$changelogFile = "CHANGELOG.md"
			}

			if ($null -eq $changelogFile)
			{
				Pop-Location
				return
			}

			$latestVersion = Select-String -Path $changelogFile -Pattern '\s(\d+\.\d+\.\d+)' |
				Select-Object -First 1 |
				ForEach-Object { $_.Matches[0].Groups[1].Value }
			$latestTag = git describe --abbrev=0 --always

			if ($latestVersion -ne $latestTag)
			{
				Write-Host "Unreleased " -Foreground Red -NoNewline
				Write-Host $d -Foreground Cyan -NoNewline
				Write-Host " $latestVersion" -Foreground Red -NoNewline
				Write-Host " ($latestTag)" -Foreground DarkYellow
			}
			else
			{
				Write-Host "Released   " -Foreground Green -NoNewline
				Write-Host $d -Foreground Cyan -NoNewline
				Write-Host " $latestTag" -Foreground DarkYellow
			}
		}

		"tag" {
			$latestTag = git for-each-ref --sort=-taggerdate refs/tags/ --format '%(refname:short) -- %(taggerdate:relative)' | Select-Object -First 1
			Write-Host $d -Foreground Cyan -NoNewline
			Write-Host " $latestTag"
		}

		"fixcrlf" {
			Write-Host "Fixing CRLF " -Foreground DarkGreen -NoNewline
			Write-Host $d -Foreground Cyan

			git config --unset core.fileMode
			git config --unset core.filemode
			git config --unset core.autocrlf
		}

		default {
			Write-Host "Unknown command $operation" -Foreground Magenta

			Pop-Location

			Exit 1
		}
	}

	Pop-Location
}
