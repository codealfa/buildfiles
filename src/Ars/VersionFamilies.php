<?php
/*
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\BuildFiles\Ars;

use JsonException;
use RuntimeException;

/**
 * Expands a supported version range into the list of version families it covers.
 *
 * Turning “PHP 7.4 to 8.6” into the families 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 and 8.6 requires knowing that the PHP 7
 * release train ended at 7.4 — something neither the version range nor the version numbers themselves can tell us. We
 * ask endoflife.date, which tracks exactly that for all three of the software packages we care about.
 *
 * The three products do not report the same shape of data:
 *
 *   * `php` and `wordpress` list one release per version family, e.g. `8.5`. The families are the answer.
 *   * `joomla` lists one release per *major* version, e.g. `6`, because Joomla's own documentation treats a major
 *     version as the release train. The minor versions have to be derived: a train which has reached its end of life
 *     ended at whatever its last release was — Joomla 3 ended at 3.10, not 3.4 — while one which is still alive will
 *     end at x.4, which is where the Joomla project ends every train these days.
 *
 * The responses are cached on disk. A build must not fail because endoflife.date is having a bad day, so a stale cache
 * is used in preference to giving up; only a cold cache and a failed request together are fatal.
 */
class VersionFamilies
{
	/**
	 * How long a cached endoflife.date response is considered fresh, in seconds.
	 */
	private const CACHE_TIME = 604800;

	/**
	 * The minor version every currently maintained Joomla release train ends at.
	 *
	 * Joomla 4 ended at 4.4 and Joomla 5 at 5.4. The project has committed to the same shape for the trains which have
	 * not ended yet, so this is the best answer available for a train whose last version we cannot look up.
	 */
	private const JOOMLA_LAST_MINOR = 4;

	/**
	 * The version families of each software package, keyed by software name; an internal request cache.
	 *
	 * @var  array<string, string[]>
	 */
	private array $families = [];

	/**
	 * Public constructor.
	 *
	 * @param   string       $cacheDirectory  Where to cache the endoflife.date responses.
	 * @param   string|null  $caCert          Custom CA bundle to verify endoflife.date's certificate against, if any.
	 */
	public function __construct(private readonly string $cacheDirectory, private readonly ?string $caCert = null) {}

	/**
	 * Retrieves every version family a software package is known to have had, oldest first.
	 *
	 * @param   string  $software  The software name: `php`, `joomla`, or `wordpress`.
	 *
	 * @return  string[]  The version families, e.g. `['8.0', '8.1', '8.2']`.
	 */
	public function knownFamilies(string $software): array
	{
		$software = strtolower(trim($software));

		if (isset($this->families[$software]))
		{
			return $this->families[$software];
		}

		$families = [];

		foreach ($this->getReleases($software) as $release)
		{
			$name = (string) ($release['name'] ?? '');

			if ($name === '' || !preg_match('/^\d+(\.\d+)?$/', $name))
			{
				continue;
			}

			// A release named `8.5` is a version family. One named `6` is a whole major version; expand it.
			if (str_contains($name, '.'))
			{
				$families[] = $name;

				continue;
			}

			$major = (int) $name;

			for ($minor = 0; $minor <= $this->getLastMinorOfMajor($software, $major, $release); $minor++)
			{
				$families[] = sprintf('%d.%d', $major, $minor);
			}
		}

		$families = array_values(array_unique($families));

		usort($families, $this->compare(...));

		if (empty($families))
		{
			throw new RuntimeException(
				sprintf('endoflife.date knows no version families for ‘%s’.', $software)
			);
		}

		return $this->families[$software] = $families;
	}

	/**
	 * Retrieves the newest version family a software package is known to have.
	 *
	 * @param   string  $software  The software name: `php`, `joomla`, or `wordpress`.
	 *
	 * @return  string  The newest version family, e.g. `8.5`.
	 */
	public function newestKnownFamily(string $software): string
	{
		$families = $this->knownFamilies($software);

		return end($families);
	}

	/**
	 * Expands an inclusive version range into the list of version families it covers.
	 *
	 * The maximum may be given as a whole major version family, e.g. `8.x`, which is how our version limits report a
	 * range whose upper bound falls on a major version boundary. It means “every family of major version 8”.
	 *
	 * @param   string  $software  The software name: `php`, `joomla`, or `wordpress`.
	 * @param   string  $min       The oldest supported version family, e.g. `7.4`.
	 * @param   string  $max       The newest supported version family, e.g. `8.6` or `8.x`.
	 *
	 * @return  string[]  The version families in the range, oldest first.
	 */
	public function expand(string $software, string $min, string $max): array
	{
		$software  = strtolower(trim($software));
		$lastMinor = $this->getLastMinorPerMajor($software);

		[$major, $minor]       = $this->parse($min);
		[$maxMajor, $maxMinor] = $this->parse($max, $lastMinor);

		if ($this->compare($min, sprintf('%d.%d', $maxMajor, $maxMinor)) > 0)
		{
			throw new RuntimeException(
				sprintf('The %s version range %s to %s is empty; the minimum is above the maximum.', $software, $min, $max)
			);
		}

		$result = [];

		while ($major < $maxMajor || ($major === $maxMajor && $minor <= $maxMinor))
		{
			/**
			 * A major version with no families at all never shipped, and a range which spans it does not cover it.
			 * PHP 6 is the standing example: `>=5.3 <9.0` covers PHP 5 and PHP 7 and 8, but there is no PHP 6.
			 */
			if ($major !== $maxMajor && !isset($lastMinor[$major]))
			{
				$major++;
				$minor = 0;

				continue;
			}

			$result[] = sprintf('%d.%d', $major, $minor);

			/**
			 * Inside the last major version of the range we walk the minor versions freely, without consulting the
			 * known families: a range which ends at 8.6 declares support for PHP 8.6 whether or not it has been
			 * released yet, and refusing to create an environment for it would defeat the purpose of the exercise.
			 *
			 * Below it we have to stop at the end of each release train and move on to the next major version.
			 */
			if ($major === $maxMajor || $minor < $lastMinor[$major])
			{
				$minor++;

				continue;
			}

			$major++;
			$minor = 0;
		}

		return $result;
	}

	/**
	 * Compares two version families, the way usort() wants them compared.
	 *
	 * @param   string  $a  The first version family, e.g. `8.10`.
	 * @param   string  $b  The second version family, e.g. `8.9`.
	 *
	 * @return  int  Negative if $a is older, positive if $a is newer, zero if they are the same family.
	 */
	private function compare(string $a, string $b): int
	{
		[$aMajor, $aMinor] = $this->parse($a);
		[$bMajor, $bMinor] = $this->parse($b);

		return [$aMajor, $aMinor] <=> [$bMajor, $bMinor];
	}

	/**
	 * Splits a version family into its major and minor components.
	 *
	 * @param   string      $family     The version family, e.g. `8.6`, `8.x`, or `8`.
	 * @param   array|null  $lastMinor  The last minor version of each major version, to resolve an `x` minor with.
	 *
	 * @return  array{0: int, 1: int}
	 */
	private function parse(string $family, ?array $lastMinor = null): array
	{
		$parts = explode('.', trim($family));
		$major = (int) $parts[0];
		$minor = $parts[1] ?? '0';

		/**
		 * `8.x` means the whole of major version 8, so it resolves to the last family that major version has. We can
		 * only answer that for a release train which has ended; an `x` on the newest major version is a question about
		 * the future, which nobody can answer.
		 */
		if (!is_numeric($minor))
		{
			if (!isset($lastMinor[$major]))
			{
				throw new RuntimeException(
					sprintf('Cannot resolve the version family ‘%s’: it is not known where the %d.x release train ends.', $family, $major)
				);
			}

			return [$major, $lastMinor[$major]];
		}

		return [$major, (int) $minor];
	}

	/**
	 * Retrieves the last minor version of each major version of a software package.
	 *
	 * @param   string  $software  The software name: `php`, `joomla`, or `wordpress`.
	 *
	 * @return  array<int, int>  The last minor version, keyed by major version.
	 */
	private function getLastMinorPerMajor(string $software): array
	{
		$lastMinor = [];

		foreach ($this->knownFamilies($software) as $family)
		{
			[$major, $minor] = $this->parse($family);

			$lastMinor[$major] = max($lastMinor[$major] ?? 0, $minor);
		}

		return $lastMinor;
	}

	/**
	 * Works out the last minor version of a major version reported by endoflife.date as a single release.
	 *
	 * @param   string  $software  The software name; only `joomla` reports major versions this way.
	 * @param   int     $major     The major version.
	 * @param   array   $release   The endoflife.date release record for that major version.
	 *
	 * @return  int
	 */
	private function getLastMinorOfMajor(string $software, int $major, array $release): int
	{
		$latest = (string) ($release['latest']['name'] ?? '');
		$parts  = explode('.', $latest);
		$actual = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 0;

		/**
		 * A train which is over ended at its last release. Joomla 3 is the reason this matters: it ran to 3.10, well
		 * past the x.4 the later trains stop at.
		 */
		if (($release['isEol'] ?? false) === true)
		{
			return $actual;
		}

		// A train which is still going will run at least as far as it has already got.
		if ($software !== 'joomla')
		{
			return $actual;
		}

		return max($actual, self::JOOMLA_LAST_MINOR);
	}

	/**
	 * Retrieves the release records endoflife.date has for a software package.
	 *
	 * @param   string  $software  The software name: `php`, `joomla`, or `wordpress`.
	 *
	 * @return  array[]
	 */
	private function getReleases(string $software): array
	{
		if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $software))
		{
			throw new RuntimeException(sprintf('‘%s’ is not a valid endoflife.date product name.', $software));
		}

		$cacheFile = rtrim($this->cacheDirectory, '/' . DIRECTORY_SEPARATOR) . '/endoflife-' . $software . '.json';
		$isFresh   = @is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < self::CACHE_TIME;
		$raw       = $isFresh ? (string) @file_get_contents($cacheFile) : '';

		if ($raw === '')
		{
			try
			{
				$raw = $this->download($software);

				if (@is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0755, true))
				{
					@file_put_contents($cacheFile, $raw);
				}
			}
			catch (RuntimeException $e)
			{
				// Falling back to a stale cache beats failing the build over a temporary network problem.
				$raw = @is_file($cacheFile) ? (string) @file_get_contents($cacheFile) : '';

				if ($raw === '')
				{
					throw $e;
				}
			}
		}

		try
		{
			$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $e)
		{
			throw new RuntimeException(
				sprintf('endoflife.date returned invalid JSON for ‘%s’: %s', $software, $e->getMessage()), 0, $e
			);
		}

		$releases = $decoded['result']['releases'] ?? null;

		if (!is_array($releases) || empty($releases))
		{
			throw new RuntimeException(sprintf('endoflife.date has no release information for ‘%s’.', $software));
		}

		return $releases;
	}

	/**
	 * Downloads the endoflife.date record of a software package.
	 *
	 * @param   string  $software  The software name: `php`, `joomla`, or `wordpress`.
	 *
	 * @return  string  The raw response body.
	 */
	private function download(string $software): string
	{
		$url  = sprintf('https://endoflife.date/api/v1/products/%s/', rawurlencode($software));
		$curl = curl_init($url);

		if ($curl === false)
		{
			throw new RuntimeException(sprintf('Cannot initialise a cURL request to %s.', $url));
		}

		curl_setopt_array(
			$curl,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: AkeebaBuildFiles/1.0'],
			]
		);

		if (!empty($this->caCert))
		{
			curl_setopt($curl, CURLOPT_CAINFO, $this->caCert);
		}

		$body   = curl_exec($curl);
		$status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error  = curl_error($curl);


		if ($body === false || $status < 200 || $status > 299)
		{
			throw new RuntimeException(
				sprintf('Cannot retrieve %s: %s', $url, $body === false ? $error : 'HTTP ' . $status)
			);
		}

		return (string) $body;
	}
}
