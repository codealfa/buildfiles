<?php
/*
 * @package   buildfiles
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License, version 3 or later
 */

namespace Akeeba\BuildFiles\VersionLimit;

/**
 * Reports the version constraints declared in a repository, in human readable form.
 *
 * This file doubles as an executable script, so that shell scripts — the `all` CLI tool, most notably — can ask for the
 * constraints of a repository without having to reimplement Composer's version constraint parsing in Bash:
 *
 * ```
 * php /path/to/buildfiles/src/VersionLimit/Reporter.php /path/to/repository
 * ```
 *
 * It always prints exactly one line, and always exits with a zero status; a repository without a composer.json file, or
 * without any declared constraints, is perfectly normal, not an error.
 */
class Reporter
{
	/**
	 * Returns the version constraints declared in a repository, in human readable form.
	 *
	 * @param   string  $repositoryRoot  The root of the repository, i.e. the folder containing composer.json.
	 *
	 * @return  string
	 */
	public function describe(string $repositoryRoot): string
	{
		$composerFile = rtrim($repositoryRoot, '/' . DIRECTORY_SEPARATOR) . '/composer.json';

		if (!@is_file($composerFile))
		{
			return 'No constraints';
		}

		$limits = new DeclaredLimits($composerFile);
		$parts  = [];

		$phpRange = $this->formatRange($limits->getMinPHP(), $limits->getMaxPHP());

		if ($phpRange !== null)
		{
			$parts[] = 'PHP ' . $phpRange;
		}

		/**
		 * Skip the CMS constraints when no limit expression is declared. Standalone and CLI applications run outside of
		 * a CMS; reporting an unbounded CMS version range for them would be nonsense.
		 */
		$limitRange = $this->formatRange($limits->getMinLimit(), $limits->getMaxLimit());

		if ($limitRange !== null)
		{
			$parts[] = $this->getLimitLabel($limits->getLimitType()) . ' ' . $limitRange;
		}

		return empty($parts) ? 'No constraints' : implode(', ', $parts);
	}

	/**
	 * The entry point used when this file is executed as a script.
	 *
	 * @param   array  $argv  The command line arguments; the repository root is expected in the second one.
	 *
	 * @return  void
	 */
	public static function main(array $argv): void
	{
		$repositoryRoot = $argv[1] ?? getcwd();

		try
		{
			echo (new self())->describe((string) $repositoryRoot) . "\n";
		}
		catch (\Throwable $e)
		{
			echo $e->getMessage() . "\n";
		}
	}

	/**
	 * Formats a lower and an upper version limit as a Composer–like version constraint.
	 *
	 * @param   string  $min  The lower limit, inclusive.
	 * @param   string  $max  The upper limit, exclusive.
	 *
	 * @return  string|null  The formatted constraint, NULL if neither limit is declared.
	 */
	private function formatRange(string $min, string $max): ?string
	{
		$parts = [];

		if ($min !== DeclaredLimits::NO_LOWER_LIMIT)
		{
			$parts[] = '>=' . $min;
		}

		if ($max !== DeclaredLimits::NO_UPPER_LIMIT)
		{
			$parts[] = '<' . $max;
		}

		return empty($parts) ? null : implode(' ', $parts);
	}

	/**
	 * Returns the human readable name of the CMS the version limit applies to.
	 *
	 * @param   string|null  $limitType  The declared limit type, lowercase.
	 *
	 * @return  string
	 */
	private function getLimitLabel(?string $limitType): string
	{
		return match ($limitType)
		{
			'joomla' => 'Joomla!',
			'wordpress', 'wp' => 'WordPress',
			default => 'CMS',
		};
	}
}

/**
 * Run as a script, but only when executed directly. Requiring, or autoloading, this file must have no side effects.
 */
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__))
{
	$autoloadFile = __DIR__ . '/../../vendor/autoload.php';

	if (!@is_file($autoloadFile))
	{
		fwrite(
			STDERR,
			"Composer is not initialised in the buildfiles repository. Please run `composer install` in it.\n"
		);

		exit(1);
	}

	require_once $autoloadFile;

	Reporter::main($argv);
}
