<?php
/**
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace tasks;

use Akeeba\BuildFiles\VersionLimit\DeclaredLimits;
use Phing\Exception\BuildException;
use Phing\Project;
use Phing\Task;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Applies the version constraints declared in composer.json to the codebase.
 *
 * The minimum and maximum supported PHP and CMS versions are declared, once, in the repository's composer.json file.
 * This task copies them to every declaration location listed in `extra.akcompat.locations`, so that we do not have to
 * remember to update a dozen files by hand every time we change the supported versions.
 *
 * <code>
 *     <ApplyVersionConstraints repositoryRoot="${dirs.root}" />
 * </code>
 *
 * The task does nothing at all unless there is a composer.json file in the repository root. Most of our repositories do
 * not have one, and those which do may not declare any version constraints; neither is an error.
 */
class ApplyVersionConstraintsTask extends Task
{
	/**
	 * The repository root; the folder which contains the composer.json file.
	 *
	 * Defaults to the `dirs.root` Phing property.
	 *
	 * @var   string|null
	 */
	private ?string $repositoryRoot = null;

	public function getRepositoryRoot(): ?string
	{
		return $this->repositoryRoot;
	}

	public function setRepositoryRoot(string $repositoryRoot): void
	{
		$this->repositoryRoot = $repositoryRoot;
	}

	/**
	 * The main entry point.
	 *
	 * @throws  BuildException
	 */
	public function main()
	{
		$repositoryRoot = $this->repositoryRoot ?: $this->project->getProperty('dirs.root');

		if (empty($repositoryRoot))
		{
			throw new BuildException('ApplyVersionConstraints: the repository root is not set.');
		}

		$composerFile = rtrim($repositoryRoot, '/' . DIRECTORY_SEPARATOR) . '/composer.json';

		// If and only if there is a composer.json file in the repository root.
		if (!@is_file($composerFile))
		{
			$this->log(
				sprintf('No composer.json in %s; not applying any version constraints.', $repositoryRoot),
				Project::MSG_VERBOSE
			);

			return;
		}

		try
		{
			$limits = new DeclaredLimits($composerFile);

			$locations = $limits->getLocations();

			if (empty($locations))
			{
				$this->log('No version constraint declaration locations in composer.json.', Project::MSG_VERBOSE);

				return;
			}

			$this->log(
				sprintf(
					'Version constraints: PHP %s to (but not including) %s, CMS %s to (but not including) %s.',
					$limits->getMinPHP(),
					$limits->getMaxPHP(),
					$limits->getMinLimit(),
					$limits->getMaxLimit()
				),
				Project::MSG_VERBOSE
			);

			if (!$limits->needsUpdate())
			{
				$this->log('The declared version constraints are already up–to–date.', Project::MSG_VERBOSE);

				return;
			}

			// Report what is about to change before we change it; applyUpdate() only gives us a count.
			foreach ($locations as $location)
			{
				$currentLimit = $location->getCurrentLimit();

				if ($currentLimit === null || $currentLimit === $location->getValue())
				{
					continue;
				}

				$this->log(
					sprintf(
						'%s: %s ‘%s’ → ‘%s’',
						$location->getFilePath(),
						$location->getMarker(),
						$currentLimit,
						$location->getValue()
					),
					Project::MSG_INFO
				);
			}

			$applied = $limits->applyUpdate();
		}
		catch (BuildException $e)
		{
			throw $e;
		}
		catch (\Throwable $e)
		{
			throw new BuildException(
				sprintf('ApplyVersionConstraints: %s', $e->getMessage()), $e
			);
		}

		$this->log(
			sprintf('Applied the version constraints to %d location(s).', $applied),
			Project::MSG_INFO
		);
	}
}
