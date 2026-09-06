<?php
/**
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace tasks;

require_once __DIR__ . '/../../vendor/autoload.php';

use Composer\Semver\Comparator;

/**
 * Git latest tree hash to Phing property
 *
 * @version   $Id$
 * @package   akeebabuilder
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU GPL version 3 or, at your option, any later version
 * @author    nicholas
 */
class AutoVersionTask extends \Phing\Task
{
	/**
	 * The path to the CHANGELOG file
	 *
	 * @var string
	 */
	private $changelog;

	/**
	 * The name of the Phing property to set
	 *
	 * @var   string
	 */
	private $propertyName = 'auto.version';

	/**
	 * The working copy of the Git repository.
	 *
	 * @var   string
	 */
	private $workingCopy;

	private $useCommitHash = true;

	private $autoBump = true;

	public function getChangelog(): string
	{
		return $this->changelog;
	}

	public function setChangelog(string $path): void
	{
		$this->changelog = $path;
	}

	function getPropertyName(): string
	{
		return $this->propertyName ?: 'auto.version';
	}

	function setPropertyName(string $propertyName): void
	{
		$this->propertyName = $propertyName;
	}

	public function getWorkingCopy(): string
	{
		return $this->workingCopy;
	}

	public function setWorkingCopy(string $workingCopy): void
	{
		$this->workingCopy = $workingCopy;
	}

	/**
	 * Populates the Phing property with the most appropriate dev release number.
	 *
	 * @throws  \Phing\Exception\BuildException
	 */
	public function main()
	{
		/**
		 * Get the version numbers from the two sources:
		 *
		 * * The version stated in the topmost changelog entry
		 * * The latest Git tag on the current branch
		 *
		 * We will decide on how to version the dev release depending on the contents of these two sources.
		 */
		$changelogVersion = $this->getChangelogVersion();
		$latestGitTag     = $this->getLatestGitTag();

		/**
		 * Both version sources are empty.
		 *
		 * This repository does not follow our conventions or it is brand new software with no release information yet.
		 *
		 * Get a fake version (0.0.0-dev<DATE>-rev<COMMIT_HASH>)
		 */
		if (empty($changelogVersion) && empty($latestGitTag))
		{
			$version = $this->getFakeVersion();
		}
		/**
		 * No Git tag, just a changelog version.
		 *
		 * This branch does not have a release yet. This is either new software or a new branch for an upcoming major
		 * version of existing software.
		 *
		 * Either way, take the changelog version and add a dev suffix without bumping the version number.
		 */
		elseif (empty($latestGitTag) && !empty($changelogVersion))
		{
			$version = $this->autoBump
				? $this->bumpVersion($changelogVersion, true)
				: $changelogVersion;
		}
		/**
		 * There are three cases where we need to bump the version number:
		 *
		 * 1. No changelog version, just Git tag. Missing changelog?
		 * 2. Both versions present but the Git tag is newer than the changelog version. Out of date changelog?
		 * 3. Both versions present and identical. We made changes without updating the changelog.
		 *
		 * Either way, take the Git tag version and bump the least sub–minor version (if the Git version was stable) or
		 * the stability level revision (e.g. alpha1 to alpha2, only applies if the Git version was unstable).
		 */
		elseif (
			(!empty($latestGitTag) && empty($changelogVersion))
			|| $this->isLessThanOrEqualTo($changelogVersion, $latestGitTag)
		)
		{
			$version = $this->autoBump
				? $this->bumpVersion($latestGitTag ?: $changelogVersion)
				: ($latestGitTag ?: $changelogVersion);
		}
		/**
		 * The Git tag is an older version to the changelog version.
		 *
		 * We have continued developing after the last release. We have already decided on a version number for the next
		 * version, that's what we have in the changelog.
		 *
		 * Add a dev suffix to the changelog version, do not bump the version.
		 */
		else
		{
			$version = $this->autoBump
				? $this->bumpVersion($changelogVersion, true)
				: $changelogVersion;
		}

		$this->project->setProperty($this->getPropertyName(), $version);
	}

	/**
	 * @return mixed
	 */
	public function getUseCommitHash()
	{
		return $this->useCommitHash;
	}

	/**
	 * @param   mixed  $useCommitHash
	 */
	public function setUseCommitHash($useCommitHash): void
	{
		$this->useCommitHash = $useCommitHash;
	}

	public function getAutoBump(): bool
	{
		return $this->autoBump;
	}

	public function setAutoBump(bool $autoBump): void
	{
		$this->autoBump = $autoBump;
	}

	/**
	 * Compares two version strings using composer/semver, falling back to version_compare() for values that
	 * aren't valid semantic versions (e.g. a changelog entry with an unexpected format).
	 */
	private function isLessThanOrEqualTo(string $version1, string $version2): bool
	{
		try
		{
			return Comparator::lessThanOrEqualTo($version1, $version2);
		}
		catch (\UnexpectedValueException $e)
		{
			return version_compare($version1, $version2, 'le');
		}
	}

	/**
	 * Resolves the branch being built, preferring CI-provided environment variables (which are also correct for
	 * pull request builds, where the working copy is a detached HEAD / merge ref) over asking Git directly.
	 */
	private function getCurrentBranch(): string
	{
		$branch = getenv('GITHUB_HEAD_REF')
			?: getenv('GITHUB_REF_NAME')
			?: getenv('CI_MERGE_REQUEST_SOURCE_BRANCH_NAME')
			?: getenv('CI_COMMIT_BRANCH')
			?: getenv('CI_COMMIT_REF_NAME')
			?: '';

		if (!empty($branch))
		{
			return trim($branch);
		}

		$workingCopy = $this->workingCopy ?: $this->project->getProperty('dirs.root') ?: '../';

		if ($workingCopy == '..')
		{
			$workingCopy = '../';
		}

		$cwd         = getcwd();
		$workingCopy = realpath($workingCopy);

		chdir($workingCopy);
		exec('git rev-parse --abbrev-ref HEAD', $out);
		chdir($cwd);

		return empty($out) ? '' : trim($out[0]);
	}

	/**
	 * Reads a comma-separated list property (e.g. `branchversion.stable`), falling back to sensible defaults so
	 * repositories that don't set these properties still get correct behaviour.
	 */
	private function getBranchList(string $property, array $default): array
	{
		$value = $this->project->getProperty($property);

		if (empty($value))
		{
			return $default;
		}

		return array_values(array_filter(array_map('trim', explode(',', $value))));
	}

	/**
	 * Classifies a branch name as 'stable' (numbered releases are cut from here; dev builds keep today's
	 * timestamp + commit suffix) or 'branch' (dev builds are suffixed with the branch name instead, so builds
	 * from `development` and every `feature/*`, `bugfix/*`, `hotfix/*` branch never collide with one another or
	 * with a stable dev build). Anything unrecognised — an empty/detached HEAD, or a branch naming convention
	 * this hasn't been configured for — falls back to 'stable' so behaviour is unchanged by default.
	 */
	private function classifyBranch(string $branch): string
	{
		if ($branch === '' || $branch === 'HEAD')
		{
			return 'stable';
		}

		if (in_array($branch, $this->getBranchList('branchversion.stable', ['master', 'main']), true))
		{
			return 'stable';
		}

		if (in_array($branch, $this->getBranchList('branchversion.integration', ['development', 'develop']), true))
		{
			return 'branch';
		}

		foreach ($this->getBranchList('branchversion.prefixes', ['feature', 'bugfix', 'hotfix']) as $prefix)
		{
			if (preg_match('#^' . preg_quote($prefix, '#') . '[-/]#i', $branch))
			{
				return 'branch';
			}
		}

		return 'stable';
	}

	/**
	 * Turns a branch name into a version-string-safe identifier, e.g. `feature/Cache Artifact-Dedup` becomes
	 * `feature-cache-artifact-dedup`.
	 */
	private function slugifyBranch(string $branch): string
	{
		$slug = strtolower($branch);
		$slug = preg_replace('#[^a-z0-9]+#', '-', $slug);

		return trim($slug, '-');
	}

	private function bumpVersion(string $version, bool $onlyAddDev = false): string
	{
		$commitHash = $this->getLatestCommitHash();
		$branch     = $this->getCurrentBranch();

		if ($this->classifyBranch($branch) === 'stable')
		{
			$devSuffix = '-dev' . gmdate('YmdHi')
				. (empty($commitHash) || !$this->useCommitHash ? '' : ('-rev' . $commitHash));
		}
		else
		{
			$identifiers = array_filter([
				$this->slugifyBranch($branch),
				(empty($commitHash) || !$this->useCommitHash) ? '' : $commitHash,
			]);

			$devSuffix = '-dev+' . implode('.', $identifiers);
		}

		if (!preg_match('/((\d+\.?)+)(((a|alpha|b|beta|rc|dev)\d)*(-[^\s]*)?)?/', $version, $matches))
		{
			return $version . $devSuffix;
		}

		$mainVersion = rtrim($matches[1], '.');
		$stability   = $matches[4];
		$patch       = ltrim($matches[6], '-');

		if (empty($stability) && preg_match('/(a|alpha|b|beta|rc|dev)\d/', $patch))
		{
			$stability = $patch;
			$patch     = '';
		}

		// If the patch starts with dev, rev, git, svn replace it and return
		if (!empty($patch) && (strlen($patch) >= 3) && in_array(substr($patch, 0, 3), ['dev', 'rev', 'git', 'svn']))
		{
			return $mainVersion .
				(empty($stability) ? '' : ('.' . $stability)) .
				$devSuffix;
		}

		// If we have an unstable release bump the alpha/beta/rc level and remove the patch level
		if (!empty($stability) && !$onlyAddDev)
		{
			preg_match('/(a|alpha|b|beta|rc|dev)(\d)/', $stability, $matches);
			$prefix    = $matches[1];
			$revision  = (int) ($matches[2] ?: 0);
			$stability = $prefix . ++$revision;
		}
		// Otherwise, increase the sub–minor version
		elseif (!$onlyAddDev)
		{
			$bits = explode('.', $mainVersion);

			while (count($bits) < 3)
			{
				$bits[] = 0;
			}

			$bits[2]++;

			$mainVersion = implode('.', $bits);
		}

		return $mainVersion .
			(empty($stability) ? '' : ('.' . $stability)) .
			$devSuffix;
	}

	private function getChangelogVersion(): ?string
	{
		// If no CHANGELOG is set up try to detect the correct one.
		if (empty($this->changelog))
		{
			$rootDir    = rtrim($this->project->getProperty('dirs.root'), '/' . DIRECTORY_SEPARATOR);
			$changeLogs = [
				'CHANGELOG',
				'CHANGELOG.md',
				'CHANGELOG.php',
				'CHANGELOG.txt',
			];

			foreach ($changeLogs as $possibleFile)
			{
				$possibleFile = $rootDir . '/' . $possibleFile;

				if (@file_exists($possibleFile))
				{
					$this->changelog = $possibleFile;
				}
			}
		}

		// No changelog specified? Bummer.
		if (empty($this->changelog))
		{
			return null;
		}

		// Get the contents of the changelog.
		$content = @file_get_contents($this->changelog);

		if (empty($content))
		{
			return null;
		}

		// Remove a leading die() statement
		$lines = array_map('trim', explode("\n", $content));

		if (strpos($lines[0], '<?') !== false)
		{
			array_shift($lines);
		}

		// Remove empty lines
		$lines = array_filter($lines, function ($x) {
			return !empty($x);
		});

		// The first line should be "Something something something VERSION" or just "VERSION"
		$firstLine = array_shift($lines);
		$parts     = explode(' ', $firstLine);
		$firstLine = array_pop($parts);

		// The first line should be "Something something something VERSION" or just "VERSION"

		if (!preg_match('/((\d+\.?)+)(((a|alpha|b|beta|rc|dev)\d)*(-[^\s]*)?)?/', $firstLine, $matches))
		{
			return null;
		}

		$version = $matches[0];

		if (is_array($version))
		{
			$version = array_shift($version);
		}

		return $version;
	}

	private function getFakeVersion(): string
	{
		$commitHash = $this->getLatestCommitHash();

		return '0.0.0-dev' . gmdate('YmdHi') . (empty($commitHash) ? '' : ('-rev' . $commitHash));
	}

	private function getLatestCommitHash(): string
	{
		$workingCopy = $this->workingCopy ?: $this->project->getProperty('dirs.root') ?: '../';

		if ($workingCopy == '..')
		{
			$workingCopy = '../';
		}

		$cwd         = getcwd();
		$workingCopy = realpath($workingCopy);

		chdir($workingCopy);
		exec('git log --format=%h -n1', $out);
		chdir($cwd);

		return empty($out) ? '' : trim($out[0]);
	}

	private function getLatestGitTag(): ?string
	{
		$workingCopy = $this->workingCopy ?: $this->project->getProperty('dirs.root') ?: '../';

		if ($workingCopy == '..')
		{
			$workingCopy = '../';
		}

		$cwd         = getcwd();
		$workingCopy = realpath($workingCopy);

		chdir($workingCopy);
		exec('git describe --abbrev=0 --tags', $out);
		chdir($cwd);

		if (empty($out))
		{
			return null;
		}

		return ltrim(trim($out[0]), 'v.');
	}
}
