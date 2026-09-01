<?php
/*
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\BuildFiles\VersionLimit;

use JsonException;
use RuntimeException;
use Composer\Semver\Constraint\Bound;
use Composer\Semver\VersionParser;

/**
 * Finds the version limits imposed by a composer.json file
 */
class DeclaredLimits
{
	/**
	 * Lower limit when none is defined
	 */
	public const NO_LOWER_LIMIT = '0.0.0';

	/**
	 * Upper limit when none is defined
	 */
	public const NO_UPPER_LIMIT = '999999.999999.999999';

	/**
	 * Minimum supported PHP version, as declared in composer.json
	 *
	 * @var string
	 */
	private string $minPHP;

	/**
	 * Maximum supported PHP version limit (one version about supported), as declared in composer.json
	 *
	 * @var string
	 */
	private string $maxPHP;

	/**
	 * Minimum supported CMS version, as declared in composer.json
	 *
	 * @var string
	 */
	private string $minLimit;

	/**
	 * Maximum supported CMS version limit (one version about supported), as declared in composer.json
	 *
	 * @var string
	 */
	private string $maxLimit;

	/**
	 * The type of the CMS the limit applies to, lowercase, as declared in composer.json
	 *
	 * @var string|null
	 */
	private ?string $limitType;

	/**
	 * The version limit declaration locations in the codebase, as declared in composer.json
	 *
	 * @var Location[]
	 */
	private array $locations = [];

	/**
	 * Public constructor.
	 *
	 * @param   string  $composerFile  Filesystem patch to composer.json
	 */
	public function __construct(readonly private string $composerFile)
	{
		// Make sure the composer.json file does exist
		if (!is_file($composerFile) || !is_readable($composerFile))
		{
			throw new RuntimeException(
				sprintf('Cannot read composer.json at %s.', $composerFile)
			);
		}

		// Parse the composer.json file
		try
		{
			$composer = json_decode(
				(string) file_get_contents($composerFile),
				true,
				512,
				JSON_THROW_ON_ERROR
			);
		}
		catch (JsonException $e)
		{
			throw new RuntimeException(sprintf('Invalid JSON in %s: %s', $composerFile, $e->getMessage()), 0, $e);
		}

		$composer = is_array($composer) ? $composer : [];

		// Normalise and parse the CMS version constraints
		$limitString     = $composer['extra']['akcompat']['limit'] ?? null;
		$limitString     = (!is_string($limitString) || trim($limitString) === '') ? '>=0.0.0' : $limitString;
		$limitType       = $composer['extra']['akcompat']['limit_type'] ?? null;
		$this->limitType = is_string($limitType) && trim($limitType) !== ''
			? strtolower(trim($limitType))
			: null;
		$isJoomla        = $this->limitType === 'joomla';
		$limitConstraint = (new VersionParser())->parseConstraints($limitString);

		// Store the normalised CMS lower and upper limits
		$this->minLimit = $this->getMinimumVersion($limitConstraint->getLowerBound());
		$this->maxLimit = $this->getVersionAboveUpperBound($limitConstraint->getUpperBound(), $isJoomla);

		// Normalise and parse the PHP version constraints
		$phpString     = $composer['require']['php'] ?? null;
		$phpString     = (!is_string($phpString) || trim($phpString) === '') ? '>=0.0.0' : $phpString;
		$phpConstraint = (new VersionParser())->parseConstraints($phpString);

		// Store the normalised PHP lower and upper limits
		$this->minPHP = $this->getMinimumVersion($phpConstraint->getLowerBound());
		$this->maxPHP = $this->getVersionAboveUpperBound($phpConstraint->getUpperBound());

		// Parse the declaration locations. This has to happen last; the locations consume the limits set above.
		$this->setLocations($composer);
	}

	/**
	 * Retrieves the version limit declaration locations in the codebase.
	 *
	 * @return  Location[]
	 */
	public function getLocations(): array
	{
		return $this->locations;
	}

	/**
	 * Does any of the declaration locations declare a version limit other than the one we have calculated?
	 *
	 * Locations whose file, or declaration, is missing are not taken into account. There is nothing we could update in
	 * them, therefore reporting them here would promise an update which applyUpdate() cannot deliver.
	 *
	 * @return  bool
	 */
	public function needsUpdate(): bool
	{
		foreach ($this->locations as $location)
		{
			$currentLimit = $location->getCurrentLimit();

			if ($currentLimit !== null && $currentLimit !== $location->getValue())
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Applies the calculated version limits to all declaration locations in the codebase.
	 *
	 * @return  int  The number of locations which were modified.
	 */
	public function applyUpdate(): int
	{
		$applied = 0;

		foreach ($this->locations as $location)
		{
			$applied += $location->applyLimit() ? 1 : 0;
		}

		return $applied;
	}

	/**
	 * Creates the declaration location objects from the parsed composer.json contents.
	 *
	 * @param   array  $composer  The parsed contents of the composer.json file.
	 *
	 * @return  void
	 */
	private function setLocations(array $composer): void
	{
		$definitions = $composer['extra']['akcompat']['locations'] ?? [];

		if (!is_array($definitions))
		{
			throw new RuntimeException(
				sprintf('The version limit declaration locations in %s must be a list.', $this->composerFile)
			);
		}

		foreach ($definitions as $definition)
		{
			$missing = is_array($definition)
				? array_diff(['file', 'type', 'marker', 'value'], array_keys($definition))
				: ['file', 'type', 'marker', 'value'];

			if ($missing)
			{
				throw new RuntimeException(
					sprintf(
						'Incomplete version limit declaration location in %s; missing ‘%s’.',
						$this->composerFile,
						implode('’, ‘', $missing)
					)
				);
			}

			$this->locations[] = new Location($this, $definition);
		}

		$this->addPlatformLocation($composer);
	}

	/**
	 * Adds the composer.json `config.platform.php` key as a declaration location.
	 *
	 * That key repeats the minimum supported PHP version, and drifts out of sync with `require.php` the moment the
	 * latter is updated without remembering the former. It is a declaration location like any other, we just do not
	 * expect anyone to list it by hand in every single project.
	 *
	 * @param   array  $composer  The parsed contents of the composer.json file.
	 *
	 * @return  void
	 */
	private function addPlatformLocation(array $composer): void
	{
		// Nothing to keep in sync if the key is not there. We are fixing drift, not imposing the convention.
		if (!is_string($composer['config']['platform']['php'] ?? null))
		{
			return;
		}

		// We cannot know what the platform version should be if no minimum PHP version is declared.
		if ($this->minPHP === self::NO_LOWER_LIMIT)
		{
			return;
		}

		$this->locations[] = new Location(
			$this,
			[
				'file'   => basename($this->composerFile),
				'type'   => 'json',
				'marker' => 'config.platform.php',
				'value'  => 'PLATFORM_PHP',
			]
		);
	}

	/**
	 * Retrieves the path to the composer.json file.
	 *
	 * @return  string  The path to the composer.json file.
	 */
	public function getComposerFile(): string
	{
		return $this->composerFile;
	}

	/**
	 * Retrieves the minimum PHP version required.
	 *
	 * @return string The minimum PHP version required.
	 */
	public function getMinPHP(): string
	{
		return $this->minPHP;
	}

	/**
	 * Retrieves the maximum PHP version limit.
	 *
	 * @return string The maximum PHP version limit.
	 */
	public function getMaxPHP(): string
	{
		return $this->maxPHP;
	}

	/**
	 * Retrieves the minimum CMS version required.
	 *
	 * @return string The minimum CMS version required.
	 */	public function getMinLimit(): string
	{
		return $this->minLimit;
	}

	/**
	 * Retrieves the maximum CMS version limit.
	 *
	 * @return string The maximum CMS version limit.
	 */
	public function getMaxLimit(): string
	{
		return $this->maxLimit;
	}

	/**
	 * Retrieves the PHP version Composer should resolve dependencies against, e.g. `8.1.999`.
	 *
	 * This is the `config.platform.php` value in composer.json. It has to be the minimum supported PHP version family,
	 * with an artificially high patch version, so that Composer picks packages which run on every PHP version we
	 * support — including the oldest one.
	 *
	 * @return  string
	 */
	public function getPlatformPHP(): string
	{
		return Version::create($this->minPHP)->versionFamily() . '.999';
	}

	/**
	 * Retrieves the maximum supported PHP version family, e.g. `8.3`.
	 *
	 * @return  string|null  The maximum supported PHP version family, NULL if there is no upper limit.
	 */
	public function getMaxSupportedPHP(): ?string
	{
		return $this->getMaxSupportedVersion($this->maxPHP);
	}

	/**
	 * Retrieves the maximum supported CMS version family, e.g. `6.2`.
	 *
	 * @return  string|null  The maximum supported CMS version family, NULL if there is no upper limit.
	 */
	public function getMaxSupportedLimit(): ?string
	{
		return $this->getMaxSupportedVersion($this->maxLimit, $this->limitType === 'joomla');
	}

	/**
	 * Retrieves the type of the CMS the version limit applies to, e.g. `joomla`.
	 *
	 * @return  string|null  The lowercase CMS type, NULL if none is declared.
	 */
	public function getLimitType(): ?string
	{
		return $this->limitType;
	}

	/**
	 * Determines the minimum version based on the provided lower bound.
	 *
	 * @param   Bound  $lowerBound  The lower bound to evaluate for determining the minimum version.
	 *
	 * @return  string The determined minimum version or a constant if unbounded.
	 */
	private function getMinimumVersion(Bound $lowerBound): string
	{
		if ($lowerBound->isZero())
		{
			return self::NO_LOWER_LIMIT;
		}

		return Version::create($lowerBound->getVersion())->shortVersion(true);
	}

	/**
	 * Converts the version above the upper limit into the maximum supported version family.
	 *
	 * This is the inverse of getVersionAboveUpperBound(): given the first unsupported version family it returns the
	 * last supported one, e.g. 8.4 becomes 8.3.
	 *
	 * The x.0 case is the awkward one: the maximum supported version is the last minor version of the previous major
	 * version, and how many minor versions that had depends on the software. We know that Joomla release trains end at
	 * x.4. We cannot know how many minor versions a PHP, or a WordPress, major version will end up having — least of
	 * all for a major version which is not out yet — so we report the whole major version family instead, e.g. `8.x`.
	 *
	 * @param   string  $aboveUpperLimit  The version right above the maximum supported one.
	 * @param   bool    $assumeJoomla     Whether to assume Joomla-style versioning (x.4 is the last of the x train)
	 *
	 * @return  string|null  The maximum supported version family, NULL if there is no upper limit.
	 */
	private function getMaxSupportedVersion(string $aboveUpperLimit, bool $assumeJoomla = false): ?string
	{
		if ($aboveUpperLimit === self::NO_UPPER_LIMIT)
		{
			return null;
		}

		$parsedVersion = Version::create($aboveUpperLimit);
		$major         = $parsedVersion->major();
		$minor         = $parsedVersion->minor();

		if ($minor > 0)
		{
			return sprintf('%d.%d', $major, $minor - 1);
		}

		if ($major < 1)
		{
			return null;
		}

		return $assumeJoomla ? sprintf('%d.4', $major - 1) : sprintf('%d.x', $major - 1);
	}

	/**
	 * Determines the next version above the provided upper bound.
	 *
	 * @param   Bound  $upperBound    The upper bound to evaluate for determining the next version.
	 * @param   bool   $assumeJoomla  Whether to assume Joomla-style versioning (x+1.0 comes after x.4)
	 *
	 * @return  string The next version calculated above the upper bound, or a constant if unbounded.
	 */
	private function getVersionAboveUpperBound(Bound $upperBound, bool $assumeJoomla = false): string
	{
		if ($upperBound->isPositiveInfinity())
		{
			return self::NO_UPPER_LIMIT;
		}

		$parsedVersion = Version::create($upperBound->getVersion());
		$major         = $parsedVersion->major();
		$minor         = $parsedVersion->minor();

		/**
		 * Does the upper bound fall exactly on the start of the x.y version family?
		 *
		 * Composer normalises versions to four numeric components, and marks exclusive upper bounds with a `-dev`
		 * stability suffix. Therefore `<6.3` gives us the exclusive bound 6.3.0.0-dev, whereas `<=6.3` gives us the
		 * inclusive bound 6.3.0.0, and `<6.3.5` gives us the exclusive bound 6.3.5.0-dev.
		 *
		 * An *exclusive* bound on the start of a family means that nothing in that family is supported, therefore the
		 * family itself is the version above the upper bound: `<6.3` means that 6.3 is the first unsupported version.
		 *
		 * In every other case part of the x.y family is supported — the whole of it for an exclusive bound further into
		 * the family such as `<6.3.5`, or at least its first release for an inclusive bound such as `<=6.3` — therefore
		 * the version above the upper bound is x.(y+1).
		 */
		$components    = explode('.', explode('-', $upperBound->getVersion(), 2)[0]);
		$isFamilyStart = array_reduce(
			array_slice($components, 2),
			fn(bool $carry, string $component): bool => $carry && intval($component) === 0,
			true
		);

		if ($upperBound->isInclusive() || !$isFamilyStart)
		{
			$minor++;
		}

		/**
		 * Joomla release trains end at x.4; the version which comes after x.4 is (x+1).0, not x.5.
		 */
		if ($assumeJoomla && $minor > 4)
		{
			$major++;
			$minor = 0;
		}

		return sprintf('%d.%d', $major, $minor);
	}


}