<?php
/*
 * @package   buildfiles
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License, version 3 or later
 */

namespace Akeeba\BuildFiles\VersionLimit;

/**
 * A limit declaration location in the codebase.
 *
 * This allows us to find currently declared limits, and change them to their up-to-date values. There are two goals.
 * One is to simplify the reporting of the currently declared PHP and CMS version limits in every codebase. Two is
 * ensuring that all version limit declarations can be mass-updated without costing developer time, without being
 * subject to human error.
 */
class Location
{
	/**
	 * The path to the file where the version constraints will be applied to.
	 *
	 * @var string
	 */
	private string $filePath;

	/**
	 * The constraint declaration type in the file. Currently supported:
	 *
	 * - `variable` A named PHP variable or property.
	 *
	 * @var string
	 */
	private string $type = 'variable';

	/**
	 * The marker in the file to let us find and change the version limit declaration.
	 *
	 * @var string
	 */
	private string $marker;

	/**
	 * The value to be applied to the declaration in the file.
	 *
	 * The composer.json file contains one of the following value types:
	 *
	 * - `PHP_MIN` Minimum supported PHP version
	 * - `PHP_MAX` One minor version above the maximum supported PHP version
	 * - `LIMIT_MIN` Minimum supported CMS version
	 * - `LIMIT_MAX` One minor version above the maximum supported CMS version
	 *
	 * The value type is parsed and this property is set to the actual version expression we will be using in the file.
	 *
	 * Note: When the CMS is Joomla and the maximum supported version is an x.4 release the LIMIT_MAX returns the next
	 * major version dot 0 release. For example, if the maximum supported Joomla version is 4.4, then the LIMIT_MAX will
	 * be replaced with 5.0 (the version that comes after 4.4).
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Public constructor
	 *
	 * @param   DeclaredLimits  $limits  The declared limits object, used to find the composer path and limit values
	 * @param   array           $definition  The definition of this limit declaration location
	 */
	public function __construct(private DeclaredLimits $limits, array $definition)
	{
		$this->setFilePath($definition['file']);
		$this->setType($definition['type']);
		$this->setMarker($definition['marker']);
		$this->setValue($definition['value']);
	}

	public function getFilePath(): string
	{
		return $this->filePath;
	}

	public function fileExists(): bool
	{
		return @file_exists($this->filePath) && is_file($this->filePath);
	}

	private function setFilePath(string $filePath): void
	{
		$baseDir  = dirname($this->limits->getComposerFile());
		$filePath = ltrim($filePath, DIRECTORY_SEPARATOR . '/');

		$this->filePath = $baseDir . DIRECTORY_SEPARATOR . $filePath;
	}

	/**
	 * Returns the version limit currently declared in the file.
	 *
	 * @return  string|null  The declared version, NULL if the file does not exist or has no such declaration.
	 */
	public function getCurrentLimit(): ?string
	{
		if (!$this->fileExists())
		{
			return null;
		}

		$contents = @file_get_contents($this->filePath);

		if ($contents === false)
		{
			return null;
		}

		return match ($this->type)
		{
			'variable' => $this->getCurrentVariableLimit($contents),
		};
	}

	/**
	 * Applies the version limit to the file.
	 *
	 * All declarations of the marker in the file are updated, not just the first one, to make sure we do not leave a
	 * stale declaration behind.
	 *
	 * @return  bool  True if the file was modified; false if there was nothing to do.
	 *
	 * @throws  \RuntimeException  If the file exists, needs to be modified, but cannot be written to.
	 */
	public function applyLimit(): bool
	{
		if (!$this->fileExists())
		{
			return false;
		}

		$contents = @file_get_contents($this->filePath);

		if ($contents === false)
		{
			return false;
		}

		$newContents = match ($this->type)
		{
			'variable' => $this->applyVariableLimit($contents),
		};

		// The declaration was not found, or it already has the correct value.
		if ($newContents === null || $newContents === $contents)
		{
			return false;
		}

		if (@file_put_contents($this->filePath, $newContents) === false)
		{
			throw new \RuntimeException(
				sprintf(
					'Cannot write the version limit to ‘%s’',
					$this->filePath
				)
			);
		}

		return true;
	}

	/**
	 * Extracts the value assigned to the marker variable, or property, in the file contents.
	 *
	 * @param   string  $contents  The contents of the file to search into.
	 *
	 * @return  string|null  The declared version, NULL if there is no such declaration.
	 */
	private function getCurrentVariableLimit(string $contents): ?string
	{
		if (!preg_match($this->getVariableRegEx(), $contents, $matches))
		{
			return null;
		}

		return stripcslashes($matches['value']);
	}

	/**
	 * Assigns the version limit to the marker variable, or property, in the file contents.
	 *
	 * @param   string  $contents  The contents of the file to modify.
	 *
	 * @return  string|null  The modified contents, NULL if there is no such declaration.
	 */
	private function applyVariableLimit(string $contents): ?string
	{
		$found = false;

		$newContents = preg_replace_callback(
			$this->getVariableRegEx(),
			function (array $matches) use (&$found): string {
				$found = true;
				$quote = $matches['quote'];

				/**
				 * Escape the backslash and the quote character in use. Double quoted strings additionally need the
				 * dollar sign escaped, lest PHP tries to interpolate a variable into our version number.
				 */
				$escaped = addcslashes($this->value, $quote === '"' ? '\\"$' : '\\\'');

				return $matches['prefix'] . $quote . $escaped . $quote;
			},
			$contents
		);

		if ($newContents === null || !$found)
		{
			return null;
		}

		return $newContents;
	}

	/**
	 * Returns the regular expression matching the assignment of a quoted string to the marker variable, or property.
	 *
	 * It has three named subpatterns: `prefix` (everything up to and including the opening quote character), `quote`
	 * (the quote character in use), and `value` (the raw, still escaped, declared value).
	 *
	 * @return  string
	 */
	private function getVariableRegEx(): string
	{
		$variable = '$' . ltrim($this->marker, '$');

		return '/(?<prefix>(?<![\\w$>])' . preg_quote($variable, '/')
			. '\\s*=\\s*)(?<quote>[\'"])(?<value>(?:\\\\.|(?!\\k<quote>).)*)\\k<quote>/s';
	}

	public function getType(): string
	{
		return $this->type;
	}

	private function setType(string $type): void
	{
		$type = strtolower(trim($type));

		if (empty($type))
		{
			throw new \RuntimeException('Version limit constraints in files must have a type');
		}

		$this->type = match ($type)
		{
			'variable' => $type,
			default => throw new \RuntimeException(
				sprintf(
					'Unknown version limit constraint type ‘%s’',
					$type
				)
			),
		};
	}

	public function getMarker(): string
	{
		return $this->marker;
	}

	private function setMarker(string $marker): void
	{
		$this->marker = $marker;
	}

	public function getValue(): string
	{
		return $this->value;
	}

	private function setValue(string $value): void
	{
		$value = strtoupper(trim($value));

		if (empty($value))
		{
			throw new \RuntimeException('Version limit constraints in files must have a value');
		}

		$this->value = match ($value)
		{
			'PHP_MIN' => $this->limits->getMinPHP(),
			'PHP_MAX' => $this->limits->getMaxPHP(),
			'LIMIT_MIN' => $this->limits->getMinLimit(),
			'LIMIT_MAX' => $this->limits->getMaxLimit(),
			default => throw new \RuntimeException(
				sprintf(
					'Unknown version limit value type ‘%s’',
					$value
				)
			),
		};
	}
}