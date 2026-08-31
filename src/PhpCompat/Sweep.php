<?php
/*
 * @package   buildfiles
 * @copyright Copyright (c)2010-2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\BuildFiles\PhpCompat;

use Akeeba\BuildFiles\VersionLimit\DeclaredLimits;
use Akeeba\BuildFiles\VersionLimit\Version;
use RuntimeException;
use Throwable;

/**
 * Runs a PHP cross-version compatibility sweep over a repository.
 *
 * The whole point of this class is that the PHP version range is *not* something you pass in by hand. It is read from
 * the repository's own composer.json — the same declaration `all vlimits` reports — so that a sweep across three dozen
 * repositories checks each one against the range it actually claims to support, without a per-project configuration
 * file to keep in sync.
 *
 * This file doubles as an executable script, the same way VersionLimit\Reporter does:
 *
 * ```
 * php /path/to/buildfiles/src/PhpCompat/Sweep.php /path/to/repository [--summary] [--baseline] [--report=REPORT]
 * ```
 */
class Sweep
{
	/**
	 * The highest PHP version the installed PHPCompatibility sniffs know anything about.
	 *
	 * PHPCompatibility does not validate the `testVersion` you give it. Ask it about a PHP version it has no data for
	 * and it will cheerfully report nothing at all, which reads exactly like a clean bill of health. That failure mode
	 * is worse than an error, so we clamp the range to this ceiling and say out loud that we did.
	 *
	 * BUMP THIS when PHPCompatibility gains support for a new PHP version. As of 10.0.0-alpha2 the sniffs carry data up
	 * to PHP 8.5; PHP 8.6 support exists only on unmerged `php-8.6/*` branches upstream.
	 *
	 * Anything above the ceiling has to be covered by actually running the test suite on that PHP version — no static
	 * analyser can do it for you.
	 */
	public const SNIFF_CEILING = '8.5';

	/**
	 * Directory names, relative to the repository root, which are scanned when they exist.
	 *
	 * A repository may override this with `extra.akcompat.phpcompat.paths` in its composer.json.
	 */
	/**
	 * Path fragments never worth sniffing, whichever ruleset is in play.
	 *
	 * These are passed on the command line rather than left to the ruleset, because a non-Joomla! project is checked
	 * against the stock PHPCompatibility standard, which carries no exclusions of its own. Third-party code under
	 * vendor/ is not ours to fix, and the IDE's file templates are not valid PHP at all.
	 */
	private const IGNORE_PATTERNS = [
		'*/vendor/*', '*/node_modules/*', '*/.idea/*', '*/media/*', '*/assets/*', '*/language/*',
		'*/tmpl/ErrorPages/*', '*/tests/*', '*/UnitTest/*',
	];

	private const DEFAULT_PATHS = [
		'component', 'components', 'plugins', 'modules', 'templates', 'libraries', 'cli', 'src', 'app', 'includes',
		'administrator', 'wp-content', 'fof', 'engine',
	];

	/**
	 * @param   string  $repositoryRoot  The root of the repository, i.e. the folder containing composer.json.
	 */
	public function __construct(readonly private string $repositoryRoot)
	{
		if (!@is_dir($this->repositoryRoot))
		{
			throw new RuntimeException(sprintf('No such directory: %s', $this->repositoryRoot));
		}
	}

	/**
	 * The entry point used when this file is executed as a script.
	 *
	 * @param   array  $argv  The command line arguments.
	 *
	 * @return  int  The exit status: 0 when clean, 1 when there are findings, 2 on error.
	 */
	public static function main(array $argv): int
	{
		$positional = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with((string) $a, '--')));
		$options    = array_values(array_filter(array_slice($argv, 1), fn($a) => str_starts_with((string) $a, '--')));
		$root       = $positional[0] ?? getcwd();
		$summary    = in_array('--summary', $options, true);
		$write      = in_array('--baseline', $options, true);
		$report     = 'full';

		foreach ($options as $option)
		{
			if (str_starts_with($option, '--report='))
			{
				$report = substr($option, 9);
			}
		}

		try
		{
			$sweep = new self((string) $root);

			if ($write)
			{
				return $sweep->writeBaseline();
			}

			return $summary ? $sweep->summarise() : $sweep->report($report);
		}
		catch (Throwable $e)
		{
			fwrite(STDERR, $e->getMessage() . "\n");

			return 2;
		}
	}

	/**
	 * Returns the `testVersion` range to check this repository against, e.g. `7.4-8.5`.
	 *
	 * Both ends come from `require.php` in composer.json. The upper end is clamped to what the sniffs actually know
	 * about; see {@see self::SNIFF_CEILING}.
	 *
	 * @return  string
	 */
	public function getTestVersion(): string
	{
		$limits = new DeclaredLimits($this->getComposerFile());
		$min    = $limits->getMinPHP();
		$max    = $limits->getMaxSupportedPHP();

		$minFamily = $min === DeclaredLimits::NO_LOWER_LIMIT ? '' : Version::create($min)->versionFamily();
		$maxFamily = $max === null ? '' : Version::create($max)->versionFamily();

		if ($maxFamily !== '' && version_compare($maxFamily, self::SNIFF_CEILING, '>'))
		{
			$maxFamily = self::SNIFF_CEILING;
		}

		if ($minFamily === '' && $maxFamily === '')
		{
			throw new RuntimeException('composer.json declares no PHP version constraint; nothing to check against.');
		}

		return $minFamily . '-' . $maxFamily;
	}

	/**
	 * Returns the PHP version family the project claims to support but which the sniffs cannot check, if any.
	 *
	 * @return  string|null
	 */
	public function getUncheckedCeiling(): ?string
	{
		$max = (new DeclaredLimits($this->getComposerFile()))->getMaxSupportedPHP();

		if ($max === null)
		{
			return null;
		}

		$maxFamily = Version::create($max)->versionFamily();

		return version_compare($maxFamily, self::SNIFF_CEILING, '>') ? $maxFamily : null;
	}

	/**
	 * Runs the sweep and prints a full human readable report. Returns the exit status.
	 *
	 * @param   string  $report  The PHP_CodeSniffer report format to use.
	 *
	 * @return  int
	 */
	public function report(string $report = 'full'): int
	{
		$findings = $this->getNewFindings();
		$ceiling  = $this->getUncheckedCeiling();

		printf("PHP compatibility sweep: %s\n", basename($this->repositoryRoot));
		printf("Checked against PHP %s\n", str_replace('-', ' – ', $this->getTestVersion()));

		if ($ceiling !== null)
		{
			printf(
				"WARNING: this project declares support up to PHP %s, but the installed PHPCompatibility sniffs only\n"
				. "         have data up to PHP %s. PHP %s can only be covered by running the test suite on it.\n",
				$ceiling, self::SNIFF_CEILING, $ceiling
			);
		}

		echo "\n";

		if (empty($findings))
		{
			echo "No new findings.\n";

			return 0;
		}

		$byFile = [];

		foreach ($findings as $finding)
		{
			$byFile[$finding['file']][] = $finding;
		}

		foreach ($byFile as $file => $fileFindings)
		{
			printf("%s\n", $file);

			foreach ($fileFindings as $finding)
			{
				printf("  %5d  %-12s %s\n     %s\n", $finding['line'], $finding['type'], $finding['source'], $finding['message']);
			}

			echo "\n";
		}

		printf("%d new finding(s) in %d file(s).\n", count($findings), count($byFile));

		return 1;
	}

	/**
	 * Runs the sweep and prints a single line, for use in a multi-repository loop. Returns the exit status.
	 *
	 * @return  int
	 */
	public function summarise(): int
	{
		$findings = $this->getNewFindings();
		$ceiling  = $this->getUncheckedCeiling();
		$suffix   = $ceiling === null ? '' : sprintf(' (PHP %s unchecked)', $ceiling);

		if (empty($findings))
		{
			printf("PHP %s: clean%s\n", $this->getTestVersion(), $suffix);

			return 0;
		}

		$bySource = array_count_values(array_map($this->shortSource(...), array_column($findings, 'source')));
		arsort($bySource);

		printf(
			"PHP %s: %d finding(s) — %s%s\n",
			$this->getTestVersion(),
			count($findings),
			implode(', ', array_map(
				fn($source, $count) => sprintf('%s ×%d', $source, $count),
				array_keys($bySource), $bySource
			)),
			$suffix
		);

		return 1;
	}

	/**
	 * Records the current findings as the baseline, so that future sweeps report only what is new.
	 *
	 * @return  int
	 */
	public function writeBaseline(): int
	{
		$counts = $this->tally($this->runSniffer());
		$file   = $this->getBaselineFile();

		if (empty($counts))
		{
			@unlink($file);

			printf("No findings; baseline removed.\n");

			return 0;
		}

		ksort($counts);

		file_put_contents(
			$file,
			json_encode(
				[
					'_readme'     => 'Pre-existing PHP compatibility findings, recorded so that sweeps report only NEW '
						. 'problems. Regenerate with `phing php-compat-baseline`. Shrinking this file is the goal; it '
						. 'must never grow.',
					'testVersion' => $this->getTestVersion(),
					'generated'   => gmdate('Y-m-d'),
					'findings'    => $counts,
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			) . "\n"
		);

		printf("Baseline written to %s (%d entries, %d findings).\n", $file, count($counts), array_sum($counts));

		return 0;
	}

	/**
	 * Returns the findings which are not already accounted for by the baseline.
	 *
	 * @return  array<array{file: string, line: int, type: string, source: string, message: string}>
	 */
	public function getNewFindings(): array
	{
		$findings = $this->runSniffer();
		$baseline = $this->readBaseline();

		if (empty($baseline))
		{
			return $findings;
		}

		/**
		 * Findings are matched against the baseline by (file, sniff) pair and *counted*, never by line number. Line
		 * numbers move every time anyone edits the file above the finding, which would make a line-keyed baseline go
		 * stale on the first unrelated commit.
		 */
		$seen = [];
		$new  = [];

		foreach ($findings as $finding)
		{
			$key        = $finding['file'] . '|' . $finding['source'];
			$seen[$key] = ($seen[$key] ?? 0) + 1;

			if ($seen[$key] > ($baseline[$key] ?? 0))
			{
				$new[] = $finding;
			}
		}

		return $new;
	}

	/**
	 * Runs PHP_CodeSniffer and returns the findings, normalised and sorted.
	 *
	 * @return  array<array{file: string, line: int, type: string, source: string, message: string}>
	 */
	private function runSniffer(): array
	{
		$paths = $this->getScanPaths();

		if (empty($paths))
		{
			throw new RuntimeException(
				sprintf('Found nothing to scan under %s.', $this->repositoryRoot)
			);
		}

		$command = sprintf(
			'%s --standard=%s --runtime-set testVersion %s --report=json --basepath=%s --extensions=php --ignore=%s -q %s',
			escapeshellarg($this->getPhpCsBinary()),
			escapeshellarg($this->getStandard()),
			escapeshellarg($this->getTestVersion()),
			escapeshellarg($this->repositoryRoot),
			escapeshellarg(implode(',', self::IGNORE_PATTERNS)),
			implode(' ', array_map(escapeshellarg(...), $paths))
		);

		$output = shell_exec($command . ' 2>/dev/null');
		$json   = json_decode((string) $output, true);

		if (!is_array($json) || !isset($json['files']))
		{
			throw new RuntimeException(
				"PHP_CodeSniffer produced no usable output. Is Composer initialised in the buildfiles repository?"
			);
		}

		$findings = [];

		foreach ($json['files'] as $file => $data)
		{
			foreach ($data['messages'] ?? [] as $message)
			{
				/**
				 * Internal.* are PHP_CodeSniffer's own diagnostics — "file appears to be minified", "no PHP code in
				 * this file" — not compatibility findings. They say something about the sweep's file selection, never
				 * about the code's PHP compatibility, so they have no business in the report.
				 */
				if (str_starts_with((string) $message['source'], 'Internal.'))
				{
					continue;
				}

				$findings[] = [
					'file'    => $file,
					'line'    => (int) $message['line'],
					'type'    => $message['type'],
					'source'  => $message['source'],
					'message' => $message['message'],
				];
			}
		}

		usort($findings, fn($a, $b) => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

		return $findings;
	}

	/**
	 * Counts findings per (file, sniff) pair — the shape the baseline file is stored in.
	 *
	 * @param   array  $findings
	 *
	 * @return  array<string, int>
	 */
	private function tally(array $findings): array
	{
		$counts = [];

		foreach ($findings as $finding)
		{
			$key          = $finding['file'] . '|' . $finding['source'];
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}

		return $counts;
	}

	/**
	 * Reads the baseline file, if there is one.
	 *
	 * @return  array<string, int>
	 */
	private function readBaseline(): array
	{
		$file = $this->getBaselineFile();

		if (!@is_file($file))
		{
			return [];
		}

		$data = json_decode((string) file_get_contents($file), true);

		return is_array($data['findings'] ?? null) ? $data['findings'] : [];
	}

	private function getBaselineFile(): string
	{
		return $this->repositoryRoot . '/build/phpcompat-baseline.json';
	}

	private function getComposerFile(): string
	{
		$file = $this->repositoryRoot . '/composer.json';

		if (!@is_file($file))
		{
			throw new RuntimeException(sprintf('No composer.json in %s.', $this->repositoryRoot));
		}

		return $file;
	}

	/**
	 * Returns the ruleset to check against: the Joomla!-aware one for Joomla! extensions, plain PHPCompatibility for
	 * everything else. Only Joomla! ships the Symfony polyfills the Joomla! ruleset accounts for; applying it to a
	 * standalone application would hide real breakage.
	 *
	 * @return  string
	 */
	private function getStandard(): string
	{
		$limitType = (new DeclaredLimits($this->getComposerFile()))->getLimitType();

		return $limitType === 'joomla'
			? dirname(__DIR__, 2) . '/phpcompat/joomla.xml'
			: 'PHPCompatibility';
	}

	/**
	 * Returns the directories to scan, relative to the repository root.
	 *
	 * @return  string[]
	 */
	private function getScanPaths(): array
	{
		$composer = json_decode((string) file_get_contents($this->getComposerFile()), true);
		$declared = $composer['extra']['akcompat']['phpcompat']['paths'] ?? null;
		$candidates = is_array($declared) ? $declared : self::DEFAULT_PATHS;

		return array_values(
			array_filter(
				array_map(fn($path) => $this->repositoryRoot . '/' . $path, $candidates),
				fn($path) => @is_dir($path)
			)
		);
	}

	private function getPhpCsBinary(): string
	{
		$binary = dirname(__DIR__, 2) . '/vendor/bin/phpcs';

		if (!@is_file($binary))
		{
			throw new RuntimeException(
				'PHP_CodeSniffer is not installed. Run `composer install` in the buildfiles repository.'
			);
		}

		return $binary;
	}

	/**
	 * Shortens a sniff source for the one-line summary: the category and the sniff name are what you need to recognise
	 * it, the standard name and the sub-code are just noise at that width.
	 */
	private function shortSource(string $source): string
	{
		$parts = explode('.', $source);

		return $parts[2] ?? $source;
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

		exit(2);
	}

	require_once $autoloadFile;

	exit(Sweep::main($argv));
}
