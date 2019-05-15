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

use Christiaan\StreamProcess\StreamProcess;
use Exception;
use hanneskod\classtools\Iterator\ClassIterator;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as LoopFactory;
use splitbrain\phpcli\Options;
use Symfony\Component\Finder\Finder;
use Wikimedia\AtEase\AtEase;

abstract class Branch {
	public $dryRun;
	public $newVersion, $oldVersion, $buildDir;
	public $specialExtensions, $branchedExtensions;
	public $repoPath;
	public $noisy;
	private $output, $storeOutput;

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
	 * Get the list of available branches.
	 *
	 * @return array
	 */
	public static function getAvailableBranchTypes() :array {
		$finder = new Finder;
		$iter = new ClassIterator( $finder->in( __DIR__ ) );
		$ret = [];

		foreach ( array_keys( $iter->getClassMap() ) as $classname ) {
			if ( $classname !== __CLASS__ ) {
				$ret[$classname::getShortname()] = $classname::getDescription();
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
	) :Branch {
		$class = __CLASS__ . "\\" . ucFirst( $type );
		if ( !class_exists( $class ) ) {
			throw new Exception( "$type is not a proper brancher!" );
		}

		$branch = new $class();
		$branch->newVersion = $opt->getOpt( "new" );
		$branch->oldVersion = $opt->getOpt( "old" ) ?: 'master';
		$branch->branchPrefix = $opt->getOpt( 'branch-prefix' );
		$branch->clonePath = $opt->getOpt( 'path' );
		if ( $opt->getOpt( 'dry-run' ) ) {
			$branch->dryRun = true;
		}
		$branch->alreadyBranched = [];
		$branch->logger = $logger;

		return $branch;
	}

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
	 */
	abstract public function setupBuildDirectory();

	/**
	 * Set up the defaults for this branch type
	 *
	 * @param string $dir
	 */
	protected function setDefaults( string $dir ) {
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
		$this->dryRun = isset( $this->dryRun ) ?: $dryRun;
		$this->noisy = $noisy;
		$this->clonePath = $this->clonePath ?: "{$this->repoPath}/core";
		$this->buildDir = $buildDir;
	}

	/**
	 * Get a different branch types
	 *
	 * @param string $dir
	 */
	protected function setBranchLists( string $dir ) {
		if ( is_readable( $dir . '/config.json' ) ) {
			$branchLists = json_decode(
				file_get_contents( $dir . '/config.json' ),
				true
			);
		}

		// This comes after we load all the default configuration
		// so it is possible to override default.conf and $branchLists
		if ( is_readable( $dir . '/local.conf' ) ) {
			require $dir . '/local.conf';
		}

		$this->branchedExtensions = isset( $branchLists['extensions'] ) ?: [];
		$this->branchedSubmodules = isset( $branchLists['submodules'] ) ?: [];
		$this->specialExtensions
			= isset( $branchLists['special_extensions'] ) ?: [];
	}

	/**
	 * Check that everything is ready.
	 *
	 * @param string $dir
	 */
	public function check( string $dir ) {
		$cwd = getcwd();
		$this->chdir( $dir );

		$current = trim( $this->cmdOut( 'git', 'symbolic-ref',  'HEAD' ) );
		if ( $current === 'refs/heads/master' ) {
			$this->logger->notice(
				"Verifying that make-branch is up to date with "
				. "origin/master..."
			);
			$this->cmd( 'git', 'fetch' );
			$log = $this->cmdOut( 'git', 'log', 'HEAD..origin/master', '--pretty=oneline' );
			$logsize = strlen( $log );
			if ( $logsize > 0 ) {
				$this->croak(
					"Out of date, you need to integrate these commits "
					. "from origin/master:\n$log",
				);
			} else {
				$this->logger->success( "ok" );
			}
		} else {
			$this->croak(
				"Wrong branch: $current. Please run this "
				. "command from the latest master revision.",
			);
		}

		$changes = $this->cmdOut( 'git', 'status', '--porcelain' );
		if ( $changes ) {
			$this->logger->warning( "You have local changes in your tools/release checkout" );
		}

		chdir($cwd);
	}

	/**
	 * Handle brancher initialization
	 */
	public function initialize() {
		// Best way to get the full path to the file being executed.
		list( $arg0 ) = get_included_files();
		$dir = dirname( $arg0 );
		$this->check( $dir );
		$this->setDefaults( $dir );
		$this->setBranchLists( $dir );
	}

	/**
	 * setup an alreadyBranched array that has the names of all extensions
	 * up-to the extension from which we would like to start branching
	 *
	 * @param string/null $extName - name of extension from which to
	 * start branching
	 */
	public function setStartExtension( string $extName = null ) {
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
	 * Try to run a command and die if it fails
	 */
	public function runCmd( /*...*/ ) {
		$args = func_get_args();
		if ( is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$attempts = 0;
		do {
			if ( $attempts ) {
				$this->logger->info( "sleeping for 5s" );
				sleep( 5 );
			}
			$ret = $this->cmd( $args );

			if ( !$ret ) {
				// It worked!
				return;
			}
		} while ( ++$attempts <= 5 );
		$this->croak( $args[0] . " exit with status $ret" );
	}

	/**
	 * Run a command
	 *
	 * return int (exit code)
	 */
	public function cmd( /*...*/ ): int {
		$args = func_get_args();
		if ( is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$this->logger->notice( implode( ' ', $args ) );

		$loop = LoopFactory::create();
		$proc = new StreamProcess(
			implode( ' ', array_map( 'escapeshellarg', $args ) )
		);

		$loop->addReadStream(
			$proc->getReadStream(),
			function ( $stream ) use ( $loop ) {
				$out = fgets( $stream );
				if ( $out !== false ) {
					if ( $this->storeOutput ) {
						$this->output .= $out;
					}
					$this->logger->info( $out );
				} else {
					$loop->stop();
				}
			}
		);
		$loop->addReadStream(
			$proc->getErrorStream(),
			function ($stream) {
				$out = fgets( $stream );
				if ( $out !== false ) {
					$this->logger->warning( $out );
				}
			}
		);

		$loop->run();
		return $proc->close();
	}

	/**
	 * Return what the command sends to stdout instead of just
	 * printing it. Stderr is still printed.
	 *
	 * @return string
	 */
	public function cmdOut( /*...*/ ) :string {
		$this->storeOutput = true;
		$this->output = '';
		$this->cmd( func_get_args() );
		$this->storeOutput = false;
		return $this->output;
	}

	/**
	 * Conditionally (if not a dry run) run a command.
	 */
	public function runWriteCmd( /*...*/ ) {
		$args = func_get_args();
		if ( $this->dryRun ) {
			$this->logger->notice( "[dry-run] " . implode( ' ', $args ) );
		} else {
			return $this->runCmd( $args );
		}
	}

	/**
	 * Change dir or die if there is a problem.
	 *
	 * @param string $dir
	 */
	public function chdir( string $dir ) {
		if ( !chdir( $dir ) ) {
			$this->croak( "Unable to change working directory" );
		}
		$this->logger->notice( "cd $dir" );
	}

	/**
	 * Print an error and die
	 *
	 * @param string $msg
	 */
	public function croak( string $msg ) {
		$this->logger->error( $msg );
		exit( 1 );
	}

	/**
	 * Entry point to branching
	 */
	public function execute() {
		$this->setupBuildDirectory();
		foreach ( $this->branchedExtensions as $ext ) {
			$this->branchRepo( $ext );
		}
		foreach ( $this->specialExtensions as $ext => $branch ) {
			$this->branchRepo( $ext, $branch );
		}
		$this->branch();
	}

	/**
	 * Create this branch
	 *
	 * @param string $branchName
	 */
	public function createBranch( string $branchName ) {
		$this->runCmd( 'git', 'checkout', '-b', $branchName );
		$this->runWriteCmd( 'git', 'push', 'origin', $branchName );
	}

	/**
	 * Entry point to branch
	 *
	 * @param string $path where the git checkout is
	 * @param string $branch
	 */
	public function branchRepo( string $path, string $branch = 'master' ) {
		$repo = basename( $path );

		// repo has already been branched, so just bail out
		if ( isset( $this->alreadyBranched[$repo] ) ) {
			return;
		}

		$this->runCmd(
			'git', 'clone', '--branch', $branch, '--depth', '1',
			"{$this->repoPath}/{$path}", $repo
		);

		$this->chdir( $repo );
		$newVersion = $this->branchPrefix . $this->newVersion;

		if ( isset( $this->branchedSubmodules[$path] ) ) {
			foreach ( (array)$this->branchedSubmodules[$path] as $submodule ) {
				$this->runCmd(
					'git', 'submodule', 'update', '--init', $submodule
				);
				$this->chdir( $submodule );
				$this->createBranch( $newVersion );
				// Get us back to the repo directory by first going to
				// the build directory, then into the repo from
				// there. chdir( '..' ) doesn't work because the
				// submodule may be inside a subdirectory
				$this->chdir( $this->buildDir );
				$this->chdir( $repo );
			}
		}
		$this->createBranch( $newVersion );
		$this->chdir( $this->buildDir );
	}

	/**
	 * Handle cloning to a particular destination
	 *
	 * @param string $oldVersion
	 * @param string $des
	 */
	public function cloneAndEnterDest( $oldVersion, $dest ) {
		$ret = false;
		AtEase::suppressWarnings();
		if ( lstat( $dest ) !== false ) {
			AtEase::restoreWarnings();
			$this->logger->warning( "Destination ($dest) already exists, not cloning" );
		} else {
			AtEase::restoreWarnings();
			$ret = $this->runCmd(
				'git', 'clone', $this->clonePath, '-b', $oldVersion, $dest
			);
			$this->chdir( $dest );
			if ( !$ret ) {
				$ret = $this->runCmd(
					'git', 'remote', 'set-url', 'origin', $this->getRepoPath()
				);
			}
		}

		if ( $ret ) {
			$this->croak( "Please fix the problems before continuing" );
		}
	}

	/**
	 * Create (if necessary) or pull from remote a branch and switch to it.
	 *
	 * @param string $oldVersion
	 */
	public function createAndUseNew( string $oldVersion ) {
		$newVersion = $this->branchPrefix . $this->newVersion;

		$remoteNewVersion = 'origin/' . $newVersion;
		$onBranch = trim(
			$this->cmdOut( 'git', 'rev-parse', '--abbrev-ref', 'HEAD' )
		);
		$localBranches = explode(
			"\n", trim(
				$this->cmdOut( 'git', 'for-each-ref', '--format=%(refname:short)',
							  'refs/heads/' ) )
		);
		$hasLocalBranch = in_array( $newVersion, $localBranches );
		$hasRemoteBranch = $this->cmd(
			'git', 'ls-remote', '--exit-code', '--heads', $this->clonePath, $newVersion
		) !== 2;
		$tracking = trim(
			$this->cmdOut( 'git', 'for-each-ref', '--format=%(upstream:short)',
					   'refs/heads/' . $newVersion
			)
		);
		$localTracksRemote = $tracking === $remoteNewVersion;

		$ret = true;
		if ( $hasRemoteBranch && !$hasLocalBranch ) {
			$this->logger->warning( "Remote already has $newVersion. Using that" );
			$ret = $this->cmd( 'git', 'checkout', $newVersion );
		} elseif ( $onBranch === $newVersion && $hasRemoteBranch && $localTracksRemote ) {
			$this->logger->warning(
				"Already on local branch that tracks remote."
			);
			$ret = $this->cmd( 'git', 'pull' );
		} elseif ( $onBranch !== $newVersion && $hasLocalBranch && $localTracksRemote ) {
			$this->logger->warning(
				"Already have local branch. Switching to that and updating"
			);
			$this->cmd( 'git', 'checkout', $newVersion );
			$ret = $this->cmd( 'git', 'pull' );
		} elseif ( !$hasLocalBranch ) {
			# Create a new branch from master and switch to it
			$ret = $this->cmd( 'git', 'checkout', '-b', $newVersion, 'origin/master' );
			if ( !$ret ) {
				$ret = $this->cmd(
					'git', 'remote', 'set-url', 'origin', $this->clonePath
				);
			}
		}
		if ( $ret ) {
			$this->croak( "Please fix the problems before continuing" );
		}
	}

	/**
	 * Take care of updating the version variagble
	 */
	public function handleVersionUpdate() {
		# Fix $wgVersion
		if ( $this->fixVersion( "includes/DefaultSettings.php" ) ) {
			# Do intermediate commit
			$this->runCmd(
				'git', 'commit', '-a', '-m',
				"Creating new " . $this->getShortname() . " {$this->newVersion} branch"
			);
		} else {
			$this->logger->warning( '$wgVersion already updated, but continuing anyway' );
		}
	}

	/**
	 * Take care of any other git checkouts
	 */
	public function handleSubmodules() {
		# Add extensions/skins/vendor
		foreach ( $this->branchedExtensions as $name ) {
			$this->runCmd(
				'git', 'submodule', 'add', '-f', '-b', $newVersion,
				"{$this->repoPath}/{$name}", $name
			);
		}

		# Add extension submodules
		foreach ( array_keys( $this->specialExtensions ) as $name ) {
			$this->runCmd(
				'git', 'submodule', 'add', '-f', '-b', $newVersion,
				"{$this->repoPath}/{$name}", $name
			);
		}
	}

	/**
	 * Push the branch
	 */
	public function branch() {
		# Clone the repository
		$oldVersion = $this->oldVersion === 'master'
					? 'master'
					: $this->branchPrefix . $this->oldVersion;
		$dest = $this->getBranchDir();

		$this->cloneAndEnterDest( $oldVersion, $dest );
		$this->createAndUseNew( $oldVersion );
		$this->handleSubmodules();
		$this->handleVersionUpdate();

		$this->runWriteCmd(
			'git', 'push', '-u', 'origin', $this->getBranchPrefix() . $this->newVersion
		);
	}

	/**
	 * Fix the version number ($wgVersion) in the given file.
	 *
	 * @param string $fileName
	 */
	public function fixVersion( string $fileName ) {
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
			$this->logger->info( "Replaced $count instance of wgVersion in $fileName" );
		}

		if ( $count === 0 ) {
			$this->croak( "Could not find wgVersion in $fileName" );
		}
		return $ret;
	}
}
