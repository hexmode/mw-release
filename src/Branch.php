<?php

/*
 * Copyright (C) 2019  Wikimedia Foundation, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Mark A. Hershberger <mah@nichework.com>
 */

namespace Wikimedia\Release;

use Exception;
use hanneskod\classtools\Iterator\ClassIterator;
use Psr\Log\LoggerInterface;
use splitbrain\phpcli\Options;
use Symfony\Component\Finder\Finder;

abstract class Branch {
	/** @var string */
	protected $newVersion;
	/** @var string */
	protected $oldVersion;
	/** @var string */
	protected $buildDir;
	/** @var array */
	protected $specialExtensions;
	/** @var array */
	protected $branchedSubmodules;
	/** @var array */
	protected $branchedExtensions;
	/** @var string */
	protected $repoPath;
	/** @var bool */
	protected $noisy;
	/** @var LoggerInterface */
	protected $logger;
	/** @var string */
	protected $branchPrefix;
	/** @var string */
	protected $clonePath;
	/** @var array */
	protected $alreadyBranched;
	/** @var Control */
	protected $control;
	/** @var string */
	protected $branchFrom;

	/**
	 * How will we refer to this branch
	 *
	 * @return string
	 */
	abstract public static function getShortname() :string;

	/**
	 * Tell the user what kind of branches this class handles
	 *
	 * @return string
	 */
	abstract public static function getDescription() :string;

	/**
	 * Get a directory where we can work.
	 *
	 * @return string
	 */
	abstract protected function getWorkDir() :string;

	/**
	 * Get the branch prefix default;
	 *
	 * @return string
	 */
	abstract protected function getBranchPrefix() :string;

	/**
	 * Get the git repo path
	 *
	 * @return string
	 */
	abstract protected function getRepoPath() :string;

	/**
	 * Get the directory to put the branch in
	 *
	 * @return string
	 */
	abstract protected function getBranchDir() :string;

	/**
	 * Set up the build directory
	 *
	 * @return string dir
	 */
	abstract public function setupBuildDirectory() :string;

	/**
	 * Get the config.json file to use for this branch type
	 *
	 * @return string
	 */
	abstract protected function getConfigJson( string $dir ) :string;

	/**
	 * Set up the options for the command line program
	 */
	public static function setupOptions( Options $opt ) {
		$opt->setHelp( "Create Branches" );
		$opt->setCommandHelp( "Specify one of the following branch styles:" );
		$opt->setCompactHelp();

		$types = self::getAvailableBranchTypes();
		foreach ( $types as $name => $desc ) {
			$opt->registerCommand( $name, $desc );
		}

		$opt->registerOption( 'new', 'New branch name.', 'n', 'name' );
		$opt->registerOption(
			'old', 'Old branch name. (default: master)', 'o', 'name'
		);
		$opt->registerOption( 'dry-run', 'Do everything but push.', 'd' );
		$opt->registerOption(
			'path', 'Path on Local disk from which to branch mediawiki-core.',
			'p', 'path'
		);
		$opt->registerOption(
			'continue-from', 'Extension from which to resume branching. '
			. 'Mainly useful in the case where initial branching fails.',
			'c', 'ext'
		);
		$opt->registerOption(
			'keep-tmp', 'Whether to keep files in /tmp after finishing. '
			. 'Default is to remove', 'k'
		);
	}

	/**
	 * Get the list of available branches.
	 *
	 * @return array
	 */
	public static function getAvailableBranchTypes() :array {
		$finder = new Finder;
		$iter = new ClassIterator( $finder->in( __DIR__ ) );
		$thisClass = __CLASS__;
		$ret = [];

		foreach ( array_keys( $iter->getClassMap() ) as $classname ) {
			if (
				$classname !== $thisClass &&
				get_parent_class( $classname ) === $thisClass
			) {
				$shortname = $classname::getShortname();
				$desc = $classname::getDescription();
				$ret[$shortname] = $desc;
			}
		}

		return $ret;
	}

	/**
	 * Factory of branches
	 *
	 * @param string $type of brancher to create
	 * @param Options $opt the user gave
	 * @return Wikimedia\Release\Branch
	 */
	public static function getBrancher(
		string $type,
		Options $opt,
		LoggerInterface $logger
	) {
		if ( !$type ) {
			return "Please specify a branch type!\n";
		}

		$class = __CLASS__ . "\\" . ucFirst( $type );
		if ( !class_exists( $class ) ) {
			return "$type is not a proper brancher!";
		}

		if ( !$opt->getOpt( 'new' ) ) {
			return "'-n' or '--new' must be set.\n";
		}
		if ( !$opt->getCmd() ) {
			return "Please provide a branch type.\n";
		}

		return new $class(
			$opt->getOpt( "old", 'master' ),
			$opt->getOpt( 'branch-prefix', '' ),
			$opt->getOpt( 'new' ),
			$opt->getOpt( 'path', '' ),
			$opt->getOpt( 'dryRun', true ),
			$opt->getOpt( 'branchFrom', 'master' ), // Should this just use old?
			$logger
		);
	}

	public function __construct(
		string $oldVersion,
		string $branchPrefix,
		string $newVersion,
		string $clonePath,
		string $branchFrom,
		bool $dryRun,
		LoggerInterface $logger
	) {
		$this->oldVersion = $oldVersion;
		$this->branchPrefix = $branchPrefix;
		$this->newVersion = $newVersion;
		$this->clonePath = $clonePath;
		$this->logger = $logger;
		$this->branchFrom = $branchFrom;

		$this->repoPath = "";
		$this->buildDir = "";
		$this->noisy = false;
		$this->branchedExtensions = [];
		$this->branchedSubmodules = [];
		$this->alreadyBranched = [];
		$this->specialExtensions = [];
		$this->control = new Control( $logger, $dryRun, $this );
	}

	/**
	 * Set up the defaults for this branch type
	 *
	 * @param string $dir
	 */
	protected function setDefaults( string $dir ) :void {
		$repoPath = $this->getRepoPath();
		$branchPrefix = $this->getBranchPrefix();
		$buildDir = $this->getWorkDir();
		$dryRun = false; // Push stuff or not
		$noisy = false; // Output git commands or not

		if ( is_readable( $dir . '/default.conf' ) ) {
			require $dir . '/default.conf';
		}

		// This comes after we load all the default configuration
		// so it is possible to override default.conf and $branchLists
		if ( is_readable( $dir . '/local.conf' ) ) {
			require $dir . '/local.conf';
		}

		$this->repoPath = $repoPath;
		$this->branchPrefix = $branchPrefix;
		$this->dryRun = $this->dryRun ?? $dryRun;
		$this->noisy = $noisy;
		$this->clonePath = $this->clonePath ?? "{$this->repoPath}/core";
		$this->buildDir = $buildDir;
	}

	protected function stupidSchemaCheck(
		string $text, array &$var, string $file
	) :void {
		foreach(
			[ 'extensions', 'submodules', 'special_extensions' ] as $key
		) {
			if ( !isset( $var[$key] ) ) {
				$var[$key] = [];
				$this->logger->notice(
					"The $text '$key' is missing from $file"
				);
			}
		}
	}

	/**
	 * Get a different branch types
	 *
	 * @param string $dir
	 */
	protected function setBranchLists( string $dir ) :void {
		$branchLists = [];
		$configJson = $this->getConfigJson( $dir );

		if ( is_readable( $configJson ) ) {
			$branchLists = json_decode(
				file_get_contents( $configJson ),
				true
			);
		}

		$this->stupidSchemaCheck( 'key', $branchLists, $configJson );

		// This comes after we load all the default configuration
		// so it is possible to override default.conf and $branchLists
		if ( is_readable( $dir . '/local.conf' ) ) {
			require $dir . '/local.conf';
		}

		$this->stupidSchemaCheck(
			'index is null for', $branchLists, 'local.conf'
		);

		$this->branchedExtensions = $branchLists['extensions'] ?? [];
		$this->branchedSubmodules = $branchLists['submodules'] ?? [];
		$this->specialExtensions = $branchLists['special_extensions'] ?? [];
	}

	/**
	 * Check that everything is up to date with origin
	 *
	 * @param string $dir
	 * @param string $branch
	 */
	public function check( string $dir, string $branch = "master" ) :void {
		$this->control->chdir( $dir );

		$current = $this->control->getCurrentBranch();
		if ( $current === $branch ) {
			$this->logger->notice(
				"Verifying that make-branch is up to date with "
				. "origin/$branch..."
			);
			$log = $this->control->getCurrentLogToOrigin( $branch );
			if ( strlen( $log ) > 0 ) {
				$this->croak(
					"Out of date, you need to integrate these commits "
					. "from origin/$branch:\n$log",
				);
			} else {
				$this->logger->notice( "ok" );
			}
		} else {
			$this->croak(
				"Wrong branch: $current. Please run this "
				. "command from the latest $branch revision.",
			);
		}
		$changes = $this->control->getChanges();
		if ( $changes ) {
			$this->logger->notice(
				"You have local changes in your tools/release checkout:\n"
				. $changes
			);
		}

		$this->control->popdir();
	}

	/**
	 * Handle brancher initialization
	 */
	public function initialize() :void {
		// Best way to get the full path to the file being executed.
		list( $arg0 ) = get_included_files();
		$dir = dirname( $arg0 );
		$this->check( $dir, "master" );
		$this->setDefaults( $dir );
		$this->setBranchLists( $dir );
	}

	/**
	 * setup an alreadyBranched array that has the names of all extensions
	 * up-to the extension from which we would like to start branching
	 *
	 * @param string|null $extName - name of extension from which to
	 * start branching
	 */
	public function setStartExtension( string $extName = null ) :void {
		if ( $extName === null ) {
			return;
		}

		$foundKey = false;
		foreach ( [ $this->branchedExtensions ] as $branchedArr ) {
			$key = array_search( $extName, $branchedArr );

			if ( $key !== false ) {
				array_splice( $branchedArr, $key );
				$foundKey = true;
			}

			$this->alreadyBranched = array_merge(
				$this->alreadyBranched,
				$branchedArr
			);

			if ( $foundKey ) {
				break;
			}
		}

		if ( !$foundKey ) {
			$this->croak(
				"Could not find extension '{$extName}' in any branched "
				. "Extension list"
			);
		}

		// Should make searching array easier
		$this->alreadyBranched = array_flip( $this->alreadyBranched );
	}

	/**
	 * Print an error and die
	 *
	 * @param string $msg
	 */
	public function croak( string $msg ) :void {
		$this->logger->error( $msg );
		exit( 1 );
	}

	/**
	 * Entry point to branching
	 */
	public function execute() :void {
		$this->control->chdir( $this->setupBuildDirectory() );
		foreach ( $this->branchedExtensions as $ext ) {
			$this->branchRepo( $ext );
		}
		foreach ( $this->specialExtensions as $ext => $branch ) {
			$this->branchRepo( $ext, $branch );
		}
		$this->branch( $this->branchFrom );
	}

	/**
	 * Create this branch
	 *
	 * @param string $branchName
	 */
	public function createBranch( string $branchName ) :void {
		$this->control->checkoutNewBranch( $branchName );
		$this->control->push( 'origin', $branchName );
	}

	/**
	 * Clone a repository into a new dir or update it
	 *
	 * @param string $repo dir to put things
	 * @param string $repoPath to clone from
	 * @param string $branch
	 */
	public function cloneOrUpdate(
		string $repo,
		string $repoPath,
		string $branch = 'master'
	) :void {
		if ( $this->control->isGitDir( $repo, $repoPath ) ) {
			$this->control->pull( $repo );
		} elseif ( !file_exists( $repo ) ) {
			$this->control->clone( $repo, $repoPath, $branch );
		} else {
			$this->croak(
				"Something is in the way, cannot update or checkout $repo!"
			);
		}
	}

	/**
	 * Entry point to branch
	 *
	 * @param string $path where the git checkout is
	 * @param string $branch
	 */
	public function branchRepo(
		string $path,
		string $branch = 'master'
	) :void {
		$repo = basename( $path );
		$repoPath = "{$this->repoPath}/{$path}";

		// repo has already been branched, so just bail out
		if ( isset( $this->alreadyBranched[$repo] ) ) {
			return;
		}

		$this->cloneOrUpdate( $repo, $repoPath, $branch );
		$this->control->chdir( $repo );

		if ( isset( $this->branchedSubmodules[$path] ) ) {
			foreach ( (array)$this->branchedSubmodules[$path] as $submodule ) {
				$this->control->initSubmodule( $submodule );
				$this->control->chdir( $submodule );
				$this->createBranch( $this->newVersion );
				$this->control->popdir();
			}
		}
		$this->createBranch( $this->newVersion );
		$this->control->popdir();
	}

	/**
	 * Create (if necessary) or pull from remote a branch and switch to it.
	 */
	public function createAndUseNew() :void {
		$onBranch = $this->control->getCurrentBranch();
		$hasLocalBranch = $this->control->hasLocalBranch( $this->newVersion );
		$hasRemoteBranch = $this->control->hasRemoteBranch(
			$this->clonePath, $this->newVersion
		);
		$tracking = $this->control->getTrackingBranch( $this->newVersion );
		$localTracksRemote = $tracking === 'origin/' . $this->newVersion;

		if ( $hasRemoteBranch && !$hasLocalBranch ) {
			$this->logger->notice(
				"Remote already has {$this->newVersion}. Using that."
			);
			$this->control->checkout( $this->newVersion );
		} elseif (
			$onBranch === $this->newVersion &&
			$hasRemoteBranch &&
			$localTracksRemote
		) {
			$this->logger->notice(
				"Already on local branch that tracks remote."
			);
			$this->control->pull();
		} elseif (
			$onBranch !== $this->newVersion &&
			$hasLocalBranch &&
			$localTracksRemote
		) {
			$this->logger->notice(
				"Already have local branch. Switching to that and updating"
			);
			$this->control->checkout( $this->newVersion );
			$this->control->pull();
		} elseif ( !$hasLocalBranch ) {
			$this->control->checkoutNewBranch( $this->newVersion, "origin/master" );
			$this->setOrigin( $this->clonePath );
		}
	}

	/**
	 * Take care of updating the version variagble
	 */
	public function handleVersionUpdate() :void {
		# Fix $wgVersion
		if ( $this->fixVersion( "includes/DefaultSettings.php" ) ) {
			# Do intermediate commit
			$ret = $this->control->runCmd(
				'git', 'commit', '-a', '-m',
				"Creating new " . $this->getShortname()
				. " {$this->newVersion} branch"
			);
			if ( $ret !== 0 ) {
				$this->croak( "Intermediate commit failed!" );
			}
		} else {
			$this->logger->notice(
				'$wgVersion already updated, but continuing anyway'
			);
		}
	}

	/**
	 * Take care of any other git checkouts
	 */
	public function handleSubmodules() :void {
		# Add extensions/skins/vendor
		foreach ( $this->branchedExtensions as $name ) {
			$this->control->addSubmodule(
				"{$this->repoPath}/{$name}", $name
			);
		}

		# Add extension submodules
		foreach ( array_keys( $this->specialExtensions ) as $name ) {
			$ret = $this->control->runCmd(
				'git', 'submodule', 'add', '-f', '-b', $this->newVersion,
				"{$this->repoPath}/{$name}", $name
			);
			if ( $ret !== 0 ) {
				$this->croak( "Adding submodule ($name) failed!" );
			}
		}
	}

	/**
	 * Push the branch
	 *
	 * @param string $branchName
	 */
	public function branch( string $branchName ) :void {
		# Clone the repository
		$oldVersion = $this->oldVersion === $branchName
					? $branchName
					: $this->branchPrefix . $this->oldVersion;
		$dest = $this->getBranchDir();

		$this->cloneAndEnterDest( $oldVersion, $dest );
		$this->createAndUseNew();
		$this->handleSubmodules();
		$this->handleVersionUpdate();

		$this->control->push(
			"origin", $this->getBranchPrefix() . $this->newVersion
		);

	}

	/**
	 * Fix the version number ($wgVersion) in the given file.
	 *
	 * @param string $fileName
	 */
	public function fixVersion( string $fileName ) :bool {
		$ret = false;
		$before = file_get_contents( $fileName );
		if ( $before === false ) {
			$this->croak( "Error reading $fileName" );
		}

		$after = preg_replace(
			'/^( \$wgVersion \s+ = \s+ )  [^;]*  ( ; \s* ) $/xm',
			"\\1'{$this->newVersion}'\\2", $before, -1, $count
		);
		if ( $before !== $after ) {
			$ret = file_put_contents( $fileName, $after );
			if ( $ret === false ) {
				$this->croak( "Error writing $fileName" );
			}
			$this->logger->notice(
				"Replaced $count instance of wgVersion in $fileName"
			);
		}

		if ( $count === 0 ) {
			$this->croak( "Could not find wgVersion in $fileName" );
		}
		return !( $ret === false );
	}
}
