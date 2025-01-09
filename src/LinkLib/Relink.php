<?php
/*
 * @package   buildfiles
 * @copyright (c)2025 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\BuildFiles\LinkLib;

use Akeeba\BuildFiles\LinkLib\Scanner\AbstractScanner;
use Akeeba\BuildFiles\LinkLib\Scanner\Component;
use Akeeba\BuildFiles\LinkLib\Scanner\File;
use Akeeba\BuildFiles\LinkLib\Scanner\Library;
use Akeeba\BuildFiles\LinkLib\Scanner\Module;
use Akeeba\BuildFiles\LinkLib\Scanner\Package;
use Akeeba\BuildFiles\LinkLib\Scanner\Plugin;
use Akeeba\BuildFiles\LinkLib\Scanner\Template;
use RuntimeException;

/**
 * Handles relinking Joomla! extensions from inside a repository to the Joomla! site. This allows for faster development
 * without the need to deploy every changed file on your local computer.
 */
class Relink
{
	/**
	 * List of extensions in this repository
	 *
	 * @var   ScannerInterface[]
	 */
	private $extensions = [];

	/**
	 * The root folder to the repository / working copy being scanned
	 *
	 * @var   string
	 */
	private $repositoryRoot = '';

	/**
	 * Turn on verbose output?
	 *
	 * @var   bool
	 */
	private $verbose = false;

	/**
	 * Dry run mode (no filesystem changes)?
	 *
	 * @var   bool
	 */
	private $dryRun = false;

	/**
	 * Relink constructor.
	 *
	 * @param   string  $repositoryRoot  The root of the repository to use
	 */
	public function __construct($repositoryRoot)
	{
		if (!is_dir($repositoryRoot))
		{
			throw new RuntimeException("Repository root $repositoryRoot does not exist");
		}

		$this->repositoryRoot = $repositoryRoot;
		$this->extensions     = [];

		// Detect extensions
		$this->extensions = array_merge($this->extensions, Package::detect($this->repositoryRoot));
		$this->extensions = array_merge($this->extensions, Component::detect($this->repositoryRoot));
		$this->extensions = array_merge($this->extensions, Library::detect($this->repositoryRoot));
		$this->extensions = array_merge($this->extensions, Module::detect($this->repositoryRoot));
		$this->extensions = array_merge($this->extensions, Plugin::detect($this->repositoryRoot));
		$this->extensions = array_merge($this->extensions, Template::detect($this->repositoryRoot));
		$this->extensions = array_merge($this->extensions, File::detect($this->repositoryRoot));
	}

	/**
	 * Unlink all detected extensions from the given site
	 *
	 * @param   string  $siteRoot  The absolute path to the site's root
	 */
	public function unlink($siteRoot)
	{
		foreach ($this->extensions as $extension)
		{
			if ($this->verbose)
			{
				$extensionTag = $extension->getKeyName();
				echo "UNLINK $extensionTag\n";
			}

			$extension->setSiteRoot($siteRoot);
			$extension->unlink();
		}
	}

	/**
	 * Relink all detected extensions to the given site
	 *
	 * @param   string  $siteRoot  The absolute path to the site's root
	 */
	public function relink($siteRoot)
	{
		/** @var AbstractScanner $extension */
		foreach ($this->extensions as $extension)
		{
			if ($this->verbose)
			{
				$extensionTag = $extension->getKeyName();
				echo "RELINK $extensionTag\n";
			}

			$extension->setSiteRoot($siteRoot);
			$extension->setVerbose($this->dryRun && $this->verbose);
			$extension->setVerbose($this->verbose);
			$extension->setDryRun($this->dryRun);
			$extension->relink();
		}
	}

	/**
	 * Set the verbosity flag
	 *
	 * @param   bool  $verbose
	 */
	public function setVerbose(bool $verbose)
	{
		$this->verbose = $verbose;
	}

	/**
	 * Set the Dry Run mode flag
	 *
	 * @param   bool  $dryRun
	 */
	public function setDryRun(bool $dryRun): void
	{
		$this->dryRun = $dryRun;
	}
}
