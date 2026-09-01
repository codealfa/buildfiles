<?php
/*
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\BuildFiles\Ars;

use Akeeba\BuildFiles\VersionLimit\DeclaredLimits;
use Akeeba\BuildFiles\VersionLimit\Version;
use Closure;
use RuntimeException;

/**
 * Makes the compatibility information our business site advertises agree with the version limits we actually declare.
 *
 * When Akeeba Release Maker creates an ARS Item for a package it does not say anything about which PHP or CMS versions
 * that package supports. ARS works that out on its own: it looks for a published Automatic Item Description whose
 * `packname` glob matches the file name, and copies that record's list of Environments onto the new Item. The
 * Environments are what the download page shows as compatibility badges, and what a customer reads before deciding
 * whether the download will work on their site.
 *
 * Which means the compatibility information of the *next* release is decided by records sitting on the site right now,
 * long before anybody runs a release — and nothing keeps those records in step with the version limits declared in the
 * repository's composer.json. This class closes that gap: it works out which Automatic Item Descriptions will apply to
 * the packages we have just built, which Environments they ought to be pointing at, creates any Environment which does
 * not exist yet, and repoints the records.
 *
 * Only the platforms we have a declared opinion about are touched — `php`, plus whichever CMS the repository declares
 * a limit for. Every other environment on a record (`pdf/1.4` on a documentation package, say) is left exactly as it
 * was found.
 */
class EnvironmentSynchroniser
{
	/**
	 * The ARS resource name of the automatic item descriptions.
	 */
	private const AUTODESCRIPTIONS = 'autodescriptions';

	/**
	 * The ARS resource name of the environments.
	 */
	private const ENVIRONMENTS = 'environments';

	/**
	 * The environment vocabulary, as `xmltitle` => ID; NULL until it is read from the site.
	 *
	 * @var  array<string, int>|null
	 */
	private ?array $vocabulary = null;

	/**
	 * Public constructor.
	 *
	 * @param   ArsApiClient     $client      Connection to the ARS JSON:API of the business site.
	 * @param   VersionFamilies  $families    Knows which version families each software package has.
	 * @param   DeclaredLimits   $limits      The version limits declared in the repository's composer.json.
	 * @param   int              $categoryId  The ARS category this repository releases into.
	 * @param   bool             $dryRun      Report what would change, but change nothing.
	 * @param   Closure|null     $logger      Called with a message and a boolean “this is important”.
	 */
	public function __construct(
		private readonly ArsApiClient    $client,
		private readonly VersionFamilies $families,
		private readonly DeclaredLimits  $limits,
		private readonly int             $categoryId,
		private readonly bool            $dryRun = false,
		private readonly ?Closure        $logger = null
	) {}

	/**
	 * Brings the automatic item descriptions applying to a set of built packages in line with our version limits.
	 *
	 * @param   string[]  $fileNames  The names of the files built for the release; only `.zip` files are considered.
	 *
	 * @return  int  The number of automatic item descriptions which were changed, or would be in a dry run.
	 */
	public function synchronise(array $fileNames): int
	{
		$packages = array_values(
			array_filter(
				array_map(basename(...), $fileNames),
				fn(string $fileName): bool => strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'zip'
			)
		);

		if (empty($packages))
		{
			throw new RuntimeException(
				'There are no .zip packages to check. Did the `git` target actually build anything?'
			);
		}

		$this->log(sprintf('Checking %d package(s): %s', count($packages), implode(', ', $packages)));

		// What the version limits say the packages are compatible with.
		$wanted = $this->getWantedEnvironments();

		if (empty($wanted))
		{
			$this->log(
				'The repository declares no PHP or CMS version limits; there is nothing to synchronise.', true
			);

			return 0;
		}

		$this->log(sprintf('Declared compatibility: %s', implode(', ', array_keys($wanted))));

		// Which automatic item descriptions ARS will apply to those packages.
		$records = $this->getApplicableAutodescriptions($packages);

		if (empty($records))
		{
			throw new RuntimeException(
				sprintf(
					'No published automatic item description in ARS category %d matches any of the packages we built. The next release would have no compatibility information at all.',
					$this->categoryId
				)
			);
		}

		// Create whatever environment we are missing, so that we have an ID for every wanted environment.
		$wantedIds = $this->resolveEnvironmentIds($wanted);

		$changed = 0;

		foreach ($records as $record)
		{
			$changed += $this->synchroniseRecord($record, $wantedIds) ? 1 : 0;
		}

		return $changed;
	}

	/**
	 * Works out which environments the packages of the next release ought to declare compatibility with.
	 *
	 * @return  array<string, string>  The wanted environments, as `xmltitle` => human readable title.
	 */
	private function getWantedEnvironments(): array
	{
		$wanted = $this->getWantedFor('php', $this->limits->getMinPHP(), $this->limits->getMaxSupportedPHP());

		$cms = $this->getCmsPlatform();

		if ($cms !== null)
		{
			$wanted += $this->getWantedFor($cms, $this->limits->getMinLimit(), $this->limits->getMaxSupportedLimit());
		}

		return $wanted;
	}

	/**
	 * Works out the environments a single software package's supported version range translates into.
	 *
	 * @param   string       $software  The software name: `php`, `joomla`, or `wordpress`.
	 * @param   string       $min       The minimum supported version, as declared.
	 * @param   string|null  $max       The maximum supported version family, NULL if the range is open ended.
	 *
	 * @return  array<string, string>  The environments, as `xmltitle` => human readable title.
	 */
	private function getWantedFor(string $software, string $min, ?string $max): array
	{
		// Without a lower limit there is no range to expand; a project which runs on any version of PHP ever released
		// is not a thing we can, or should, enumerate.
		if ($min === DeclaredLimits::NO_LOWER_LIMIT)
		{
			$this->log(sprintf('No minimum %s version is declared; skipping it.', $software), true);

			return [];
		}

		$minFamily = Version::create($min)->versionFamily();

		/**
		 * WordPress environments are open ended by design: `wordpress/6.0+` means “WordPress 6.0 or any later version,
		 * including later major versions”. One environment therefore covers the whole supported range, and the upper
		 * limit — which no WordPress environment has ever expressed — does not come into it.
		 */
		if ($software === 'wordpress')
		{
			return [
				'wordpress/' . $minFamily . '+' => sprintf('WordPress %s or later', $minFamily),
			];
		}

		/**
		 * An open ended range for PHP or the CMS has to stop somewhere. The newest version family in existence is the
		 * only defensible place: we cannot advertise compatibility with versions nobody has heard of yet.
		 */
		if ($max === null)
		{
			$max = $this->families->newestKnownFamily($software);

			$this->log(
				sprintf(
					'No maximum %s version is declared; going up to %s, the newest one there is.', $software, $max
				),
				true
			);
		}

		$wanted = [];

		foreach ($this->families->expand($software, $minFamily, $max) as $family)
		{
			$wanted[$software . '/' . $family] = $this->getEnvironmentTitle($software, $family);
		}

		return $wanted;
	}

	/**
	 * Retrieves the ARS platform name of the CMS the repository declares a version limit for.
	 *
	 * @return  string|null  `joomla`, `wordpress`, or NULL if the repository declares no CMS limit.
	 */
	private function getCmsPlatform(): ?string
	{
		$platform = match ($this->limits->getLimitType())
		{
			'joomla' => 'joomla',
			'wordpress', 'wp' => 'wordpress',
			default => null,
		};

		if ($platform === null && $this->limits->getLimitType() !== null)
		{
			$this->log(
				sprintf(
					'‘%s’ is not a CMS ARS knows about; its version limits will not be synchronised.',
					$this->limits->getLimitType()
				),
				true
			);
		}

		return $platform;
	}

	/**
	 * Retrieves the human readable title to give a newly created environment.
	 *
	 * @param   string  $software  The software name: `php`, `joomla`, or `wordpress`.
	 * @param   string  $family    The version family, e.g. `8.6`.
	 *
	 * @return  string
	 */
	private function getEnvironmentTitle(string $software, string $family): string
	{
		return match ($software)
		{
			'php' => 'PHP ' . $family,
			'joomla' => 'Joomla! ' . $family,
			default => ucfirst($software) . ' ' . $family,
		};
	}

	/**
	 * Retrieves the published automatic item descriptions ARS will apply to the packages we have built.
	 *
	 * This mirrors what ARS itself does when an Item is created: the `packname` glob is matched against the file's
	 * base name, an empty `packname` never matches anything, and unpublished records are not considered at all.
	 *
	 * @param   string[]  $packages  The base names of the packages built for the release.
	 *
	 * @return  array[]  The matching records.
	 *
	 * @see  \Akeeba\Component\ARS\Administrator\Table\ItemTable::applyAutoDescriptions()
	 */
	private function getApplicableAutodescriptions(array $packages): array
	{
		$all = $this->client->getAll(
			self::AUTODESCRIPTIONS,
			[
				'category_id'    => $this->categoryId,
				'published'      => 1,
				'list_ordering'  => 'a.id',
				'list_direction' => 'asc',
			]
		);

		/**
		 * The `published` filter is applied by the site, but a filter which silently does nothing would hand us
		 * unpublished records to rewrite. Checking is cheaper than trusting.
		 */
		$all = array_filter($all, fn(array $record): bool => !empty($record['published']));

		$matching = [];
		$applied  = [];

		foreach ($packages as $package)
		{
			$matched = false;

			foreach ($all as $record)
			{
				$packname = (string) ($record['packname'] ?? '');

				if ($packname === '' || !fnmatch($packname, $package))
				{
					continue;
				}

				$matching[(int) $record['id']] = $record;

				/**
				 * ARS applies the first matching record, by ID, and ignores the rest. Knowing which one that is makes
				 * the difference between “this file will be described” and “this file will be described by the record
				 * you think it will be”.
				 */
				if (!$matched)
				{
					$applied[] = sprintf('%s → #%d ‘%s’', $package, $record['id'], $record['title'] ?? '');
					$matched   = true;
				}
			}

			if (!$matched)
			{
				$this->log(
					sprintf('No published automatic item description matches ‘%s’.', $package), true
				);
			}
		}

		foreach ($applied as $line)
		{
			$this->log($line);
		}

		ksort($matching);

		return array_values($matching);
	}

	/**
	 * Retrieves the ID of every wanted environment, creating the ones which do not exist yet.
	 *
	 * @param   array<string, string>  $wanted  The wanted environments, as `xmltitle` => human readable title.
	 *
	 * @return  array<string, int>  The environment IDs, keyed by `xmltitle`.
	 */
	private function resolveEnvironmentIds(array $wanted): array
	{
		$ids = [];

		foreach ($wanted as $xmlTitle => $title)
		{
			$existing = $this->getVocabulary()[$xmlTitle] ?? null;

			if ($existing !== null)
			{
				$ids[$xmlTitle] = $existing;

				continue;
			}

			if ($this->dryRun)
			{
				$this->log(sprintf('Would create the environment ‘%s’ (%s).', $title, $xmlTitle), true);

				/**
				 * A negative placeholder ID. It can never collide with a real one, and putting it in the vocabulary
				 * makes the rest of the dry run — the diff, and deciding which platform an entry belongs to — behave
				 * exactly as it would on a real run, without pretending we know what ID the site would assign.
				 */
				$placeholder                 = -1 - count($this->vocabulary ?? []);
				$this->vocabulary[$xmlTitle] = $placeholder;
				$ids[$xmlTitle]              = $placeholder;

				continue;
			}

			$created = $this->client->create(self::ENVIRONMENTS, ['title' => $title, 'xmltitle' => $xmlTitle]);
			$newId   = (int) ($created['id'] ?? 0);

			if ($newId <= 0)
			{
				throw new RuntimeException(
					sprintf('ARS did not return an ID for the newly created environment ‘%s’.', $xmlTitle)
				);
			}

			$this->log(sprintf('Created the environment ‘%s’ (%s) with ID %d.', $title, $xmlTitle, $newId), true);

			$this->vocabulary[$xmlTitle] = $newId;
			$ids[$xmlTitle]              = $newId;
		}

		return $ids;
	}

	/**
	 * Repoints a single automatic item description at the environments it ought to have.
	 *
	 * @param   array               $record     The automatic item description.
	 * @param   array<string, int>  $wantedIds  The IDs of the wanted environments, keyed by `xmltitle`.
	 *
	 * @return  bool  True if the record was changed, or would have been in a dry run.
	 */
	private function synchroniseRecord(array $record, array $wantedIds): bool
	{
		$id        = (int) $record['id'];
		$label     = sprintf('#%d ‘%s’', $id, $record['title'] ?? '');
		$current   = $this->getCurrentEnvironments($record);
		$platforms = $this->getManagedPlatforms($wantedIds);

		/**
		 * Everything on the record which we have no opinion about stays. A documentation package carrying `pdf/1.4`,
		 * or a record which declares a `linux/x86-64` environment, is none of our business.
		 */
		$keep = array_values(
			array_filter(
				$current,
				fn(int $environmentId): bool => !in_array(
					$this->getPlatformOf($environmentId), $platforms, true
				)
			)
		);

		$updated = array_values(array_unique(array_merge($keep, array_values($wantedIds))));

		sort($current);
		$sorted = $updated;
		sort($sorted);

		if ($current === $sorted)
		{
			$this->log(sprintf('%s is already correct.', $label));

			return false;
		}

		$this->logDifference($label, $current, $sorted);

		if ($this->dryRun)
		{
			return true;
		}

		/**
		 * The API wants the environments as a list of ID strings, the same way the back-end form submits them.
		 */
		$this->client->update(
			self::AUTODESCRIPTIONS,
			$id,
			['environments' => array_map(strval(...), $updated)]
		);

		$this->log(sprintf('Updated %s.', $label), true);

		return true;
	}

	/**
	 * Retrieves the environment IDs currently listed on an automatic item description.
	 *
	 * The API returns these as an array of integers on a list response, but the underlying column is a comma separated
	 * string and other code paths hand it over as one. Both shapes have to work.
	 *
	 * @param   array  $record  The automatic item description.
	 *
	 * @return  int[]
	 */
	private function getCurrentEnvironments(array $record): array
	{
		$environments = $record['environments'] ?? [];

		if (is_string($environments))
		{
			$environments = $environments === '' ? [] : explode(',', $environments);
		}

		if (!is_array($environments))
		{
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(intval(...), $environments),
					fn(int $environmentId): bool => $environmentId > 0
				)
			)
		);
	}

	/**
	 * Retrieves the platforms this run is responsible for, i.e. the ones we are prepared to remove entries of.
	 *
	 * @param   array<string, int>  $wantedIds  The IDs of the wanted environments, keyed by `xmltitle`.
	 *
	 * @return  string[]
	 */
	private function getManagedPlatforms(array $wantedIds): array
	{
		return array_values(
			array_unique(
				array_map(
					fn(string $xmlTitle): string => explode('/', $xmlTitle, 2)[0],
					array_keys($wantedIds)
				)
			)
		);
	}

	/**
	 * Retrieves the platform an environment belongs to, e.g. `php` for `php/8.3`.
	 *
	 * @param   int  $environmentId  The environment ID.
	 *
	 * @return  string  The platform name; an empty string for an environment the site does not have.
	 */
	private function getPlatformOf(int $environmentId): string
	{
		$xmlTitle = array_search($environmentId, $this->getVocabulary(), true);

		return $xmlTitle === false ? '' : explode('/', (string) $xmlTitle, 2)[0];
	}

	/**
	 * Logs what is about to change on an automatic item description, in terms a human can check.
	 *
	 * @param   string  $label    How to refer to the record.
	 * @param   int[]   $current  The environment IDs the record has now.
	 * @param   int[]   $updated  The environment IDs it is going to have.
	 *
	 * @return  void
	 */
	private function logDifference(string $label, array $current, array $updated): void
	{
		$added   = array_diff($updated, $current);
		$removed = array_diff($current, $updated);

		if (!empty($added))
		{
			$this->log(sprintf('%s: adding %s', $label, $this->describe($added)), true);
		}

		if (!empty($removed))
		{
			$this->log(sprintf('%s: removing %s', $label, $this->describe($removed)), true);
		}
	}

	/**
	 * Renders a list of environment IDs in a form which says something to a human being.
	 *
	 * @param   int[]  $environmentIds  The environment IDs.
	 *
	 * @return  string
	 */
	private function describe(array $environmentIds): string
	{
		$vocabulary = array_flip($this->getVocabulary());

		return implode(
			', ',
			array_map(
				fn(int $environmentId): string => $vocabulary[$environmentId] ?? ('#' . $environmentId),
				array_values($environmentIds)
			)
		);
	}

	/**
	 * Retrieves the site's environment vocabulary, as `xmltitle` => ID.
	 *
	 * @return  array<string, int>
	 */
	private function getVocabulary(): array
	{
		if ($this->vocabulary !== null)
		{
			return $this->vocabulary;
		}

		$this->vocabulary = [];

		foreach ($this->client->getAll(self::ENVIRONMENTS) as $environment)
		{
			$xmlTitle = trim((string) ($environment['xmltitle'] ?? ''));

			if ($xmlTitle === '')
			{
				continue;
			}

			$this->vocabulary[$xmlTitle] = (int) $environment['id'];
		}

		return $this->vocabulary;
	}

	/**
	 * Passes a message to the logger, if there is one.
	 *
	 * @param   string  $message    The message.
	 * @param   bool    $important  Whether the message should be shown at the default verbosity.
	 *
	 * @return  void
	 */
	private function log(string $message, bool $important = false): void
	{
		($this->logger ?? fn(string $m, bool $i) => null)($message, $important);
	}
}
