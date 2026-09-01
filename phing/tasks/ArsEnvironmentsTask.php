<?php
/**
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace tasks;

use Akeeba\BuildFiles\Ars\ArsApiClient;
use Akeeba\BuildFiles\Ars\EnvironmentSynchroniser;
use Akeeba\BuildFiles\Ars\VersionFamilies;
use Akeeba\BuildFiles\VersionLimit\DeclaredLimits;
use Phing\Exception\BuildException;
use Phing\Project;
use Phing\Task;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Makes the compatibility information our business site advertises agree with our declared version limits.
 *
 * The PHP and CMS versions a released package is marked compatible with do not come from the release: they come from
 * the Automatic Item Descriptions sitting in the Akeeba Release System category this repository releases into. This
 * task reads the packages which have just been built, finds the Automatic Item Descriptions ARS will apply to them,
 * and repoints those records at the Environments our composer.json version limits actually call for — creating any
 * Environment the site does not have yet.
 *
 * <code>
 *     <arsenvironments repositoryRoot="${dirs.root}" releaseDir="${dirs.release}"
 *                      endpoint="${release.api.endpoint}" token="${release.api.token}" />
 * </code>
 *
 * It must run after the packages have been built, because the file names of the built packages are what decides which
 * Automatic Item Descriptions apply.
 */
class ArsEnvironmentsTask extends Task
{
	/**
	 * The repository root; the folder which contains the composer.json file.
	 *
	 * @var  string|null
	 */
	private ?string $repositoryRoot = null;

	/**
	 * The folder the built packages ended up in.
	 *
	 * @var  string|null
	 */
	private ?string $releaseDir = null;

	/**
	 * The Akeeba Release Maker configuration template, which tells us the ARS category to look at.
	 *
	 * @var  string|null
	 */
	private ?string $releaseYaml = null;

	/**
	 * The ARS category this repository releases into; overrides whatever the release.yaml file says.
	 *
	 * @var  int|null
	 */
	private ?int $category = null;

	/**
	 * The base URL of the business site.
	 *
	 * @var  string|null
	 */
	private ?string $endpoint = null;

	/**
	 * The Joomla API token to authenticate with.
	 *
	 * @var  string|null
	 */
	private ?string $token = null;

	/**
	 * Custom CA bundle to verify the site's certificate against, if any.
	 *
	 * @var  string|null
	 */
	private ?string $caCert = null;

	/**
	 * Report what would change, but change nothing.
	 *
	 * @var  bool
	 */
	private bool $dryRun = false;

	public function setRepositoryRoot(string $repositoryRoot): void
	{
		$this->repositoryRoot = $repositoryRoot;
	}

	public function setReleaseDir(string $releaseDir): void
	{
		$this->releaseDir = $releaseDir;
	}

	public function setReleaseYaml(string $releaseYaml): void
	{
		$this->releaseYaml = $releaseYaml;
	}

	public function setCategory(string $category): void
	{
		$category = trim($category);

		// An unresolved Phing property, or an empty one, means “not set”, not “category zero”.
		$this->category = is_numeric($category) && (int) $category > 0 ? (int) $category : null;
	}

	public function setEndpoint(string $endpoint): void
	{
		$this->endpoint = $endpoint;
	}

	public function setToken(string $token): void
	{
		$this->token = $token;
	}

	public function setCacert(string $caCert): void
	{
		$caCert = trim($caCert);

		$this->caCert = $caCert === '' ? null : $caCert;
	}

	public function setDryRun(bool $dryRun): void
	{
		$this->dryRun = $dryRun;
	}

	/**
	 * The main entry point.
	 *
	 * @throws  BuildException
	 */
	public function main()
	{
		$repositoryRoot = $this->repositoryRoot ?: $this->project->getProperty('dirs.root');
		$releaseDir     = $this->releaseDir ?: $this->project->getProperty('dirs.release');

		if (empty($repositoryRoot) || empty($releaseDir))
		{
			throw new BuildException('arsenvironments: the repository root, or the release directory, is not set.');
		}

		$composerFile = rtrim($repositoryRoot, '/' . DIRECTORY_SEPARATOR) . '/composer.json';

		/**
		 * No composer.json means no declared version limits, which means we have nothing to say about which versions
		 * the next release supports. Most of our repositories are in that position; it is not an error.
		 */
		if (!@is_file($composerFile))
		{
			$this->log(
				sprintf('No composer.json in %s; not synchronising any environments.', $repositoryRoot),
				Project::MSG_VERBOSE
			);

			return;
		}

		try
		{
			$category = $this->getCategoryId();

			$this->log(
				sprintf(
					'Synchronising the environments of ARS category %d on %s%s.',
					$category,
					$this->endpoint,
					$this->dryRun ? ' (dry run)' : ''
				),
				Project::MSG_INFO
			);

			$synchroniser = new EnvironmentSynchroniser(
				new ArsApiClient((string) $this->endpoint, (string) $this->token, $this->caCert),
				new VersionFamilies(realpath(__DIR__ . '/../..') . '/cache', $this->caCert),
				new DeclaredLimits($composerFile),
				$category,
				$this->dryRun,
				fn(string $message, bool $important) => $this->log(
					$message, $important ? Project::MSG_INFO : Project::MSG_VERBOSE
				)
			);

			$changed = $synchroniser->synchronise($this->getBuiltPackages($releaseDir));
		}
		catch (BuildException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			throw new BuildException(sprintf('arsenvironments: %s', $e->getMessage()), $e);
		}

		$this->log(
			match (true)
			{
				$changed === 0 => 'The advertised compatibility information is already correct.',
				$this->dryRun  => sprintf('%d automatic item description(s) would be updated.', $changed),
				default        => sprintf('Updated %d automatic item description(s).', $changed),
			},
			Project::MSG_INFO
		);
	}

	/**
	 * Retrieves the names of the packages which have just been built.
	 *
	 * @param   string  $releaseDir  The folder the built packages ended up in.
	 *
	 * @return  string[]
	 */
	private function getBuiltPackages(string $releaseDir): array
	{
		if (!@is_dir($releaseDir))
		{
			throw new BuildException(
				sprintf(
					'arsenvironments: the release directory %s does not exist. This target has to run after `git`.',
					$releaseDir
				)
			);
		}

		return array_values(
			array_filter(
				(array) @scandir($releaseDir),
				fn(string $fileName): bool => @is_file($releaseDir . '/' . $fileName)
			)
		);
	}

	/**
	 * Retrieves the ARS category this repository releases into.
	 *
	 * The authoritative answer is in the repository's Akeeba Release Maker configuration template, which is what the
	 * actual release will use. The `release.category` Phing property is the fallback, for a repository whose template
	 * leaves the category as a build token.
	 *
	 * @return  int
	 */
	private function getCategoryId(): int
	{
		$fromYaml = $this->getCategoryFromReleaseYaml();

		if ($fromYaml !== null)
		{
			return $fromYaml;
		}

		if ($this->category !== null)
		{
			return $this->category;
		}

		throw new BuildException(
			'arsenvironments: cannot tell which ARS category this repository releases into. Set `release.category`, or the `release.category` key of build/templates/release.yaml.'
		);
	}

	/**
	 * Reads the ARS category out of the Akeeba Release Maker configuration template.
	 *
	 * The file is a *template* for a YAML file, not a YAML file: most of its values are still `%%TOKEN%%` build
	 * placeholders, which a YAML parser rightly refuses to read. We are after exactly one scalar, so we scan for it
	 * ourselves — the `category` key of the top level `release` mapping.
	 *
	 * @return  int|null  The category ID, NULL if the file does not exist or does not name a literal category.
	 */
	private function getCategoryFromReleaseYaml(): ?int
	{
		$releaseYaml = $this->releaseYaml
			?: rtrim((string) $this->project->getProperty('dirs.templates'), '/' . DIRECTORY_SEPARATOR)
			. '/release.yaml';

		if (!@is_file($releaseYaml))
		{
			return null;
		}

		$inRelease = false;

		foreach (file($releaseYaml, FILE_IGNORE_NEW_LINES) ?: [] as $line)
		{
			if (trim($line) === '' || str_starts_with(ltrim($line), '#'))
			{
				continue;
			}

			// An unindented line starts a new top level key, which ends the `release` mapping if we were in it.
			if (!in_array(substr($line, 0, 1), [' ', "\t"], true))
			{
				$inRelease = rtrim($line) === 'release:';

				continue;
			}

			if (!$inRelease || !preg_match('/^\s+category\s*:\s*(\S+)\s*$/', $line, $matches))
			{
				continue;
			}

			$category = trim($matches[1], '\'"');

			/**
			 * The category may still be the `%%RELEASECATEGORY%%` token which the release-yaml target substitutes. In
			 * that case the Phing property is the answer, not the file.
			 */
			if (!is_numeric($category) || (int) $category <= 0)
			{
				return null;
			}

			$this->log(sprintf('ARS category %d, per %s.', $category, $releaseYaml), Project::MSG_VERBOSE);

			return (int) $category;
		}

		return null;
	}
}
