<?php

/**
 * Class to handle the OS and git interface.
 *
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
use Psr\Log\LoggerInterface;
use Wikimedia\AtEase\AtEase;
use React\EventLoop\Factory as LoopFactory;

class Control {
	/** @var LoggerInterface */
	protected $logger;
	/** @var array */
	protected $dirs;
	/** @var bool */
	protected $dryRun;
	/** @var string */
	protected $output;
	/** @var bool */
	protected $storeOutput;
	/** @var Branch */
	protected $brancher;

	public function __construct(
		LoggerInterface $logger,
		bool $dryRun,
		Branch $brancher
	) {
		$this->logger = $logger;
		$this->dryRun = $dryRun;

		$this->output = "";
		$this->storeOutput = false;
		$this->brancher = $brancher;
	}

	/**
	 * Try to run a command and die if it fails
	 *
	 * @return integer exit code
	 */
	public function runCmd( /*...*/ ) :int {
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
		} while ( $ret !== 0 && ++$attempts <= 5 );
		return $ret;
	}

	/**
	 * Run a command
	 *
	 * return int (exit code)
	 */
	public function cmd( /*...*/ ) :int {
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
			/** @param resource $stream */
			function ( $stream ) use ( $loop ) :void {
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
			/** @param resource $stream */
			function ( $stream ) :void {
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
		return trim( $this->output );
	}

	/**
	 * Conditionally (if not a dry run) run a command.
	 *
	 * @return int
	 */
	public function runWriteCmd( /*...*/ ) :int {
		$args = func_get_args();
		$ret = 0;
		if ( $this->dryRun ) {
			$this->logger->notice( "[dry-run] " . implode( ' ', $args ) );
		} else {
			$ret = $this->runCmd( $args );
		}
		return $ret;
	}

	protected function croak( string $msg ) :void {
		$this->brancher->croak( $msg );
	}

	/**
	 * Change dir or die if there is a problem.
	 *
	 * @param string $dir
	 */
	public function chdir( string $dir ) :void {
		$this->dirs[] = getcwd();
		if ( !chdir( $dir ) ) {
			$this->croak( "Unable to change working directory to $dir" );
		}
		$this->logger->notice( "cd $dir" );
	}

	/**
	 * Change dir to the previous directory.
	 */
	public function popdir() :void {
		$dir = array_pop( $this->dirs );
		if ( !chdir( $dir ) ) {
			$this->croak( "Unable to return to the $dir directory" );
		}
	}

	/**
	 * Determine if this directory is a git clone of the repository
	 *
	 * @param string $dir to check for repo
	 * @param string $repoUrl url to ensure that is a remote for this repository
	 * @return bool
	 */
	public function isGitDir( $dir, $repoUrl ) :bool {
		$ret = true;
		if ( !( is_dir( $dir ) && file_exists( "$dir/.git" ) ) ) {
			return false;
		}
		$this->chdir( $dir );

		# Assume the remote we want is named origin
		if ( $repoUrl !== $this->cmdOut(
				 "git", "remote", "get-url", "origin"
		) ) {
			$ret = false;
		}
		$this->popdir();
		return $ret;
	}

	/**
	 * Handle cloning to a particular destination
	 *
	 * @param string $oldVersion
	 * @param string $dest
	 */
	public function cloneAndEnterDest( $oldVersion, $dest ) :void {
		$ret = false;
		AtEase::suppressWarnings();
		if ( lstat( $dest ) !== false ) {
			AtEase::restoreWarnings();
			$this->logger->warning(
				"Destination ($dest) already exists, not cloning"
			);
		} else {
			AtEase::restoreWarnings();
			$this->clone( $dest, $this->clonePath, $oldVersion );
		}
		$this->chdir( $dest );
		$ret = $this->runCmd(
			'git', 'remote', 'set-url', 'origin', $this->getRepoPath()
		);

		if ( $ret !== 0 ) {
			$this->croak( "Please fix the problems before continuing" );
		}
	}

	/**
	 * Handle a submodule updae
	 *
	 * @param string $submodule
	 */
	public function initSubmodule( string $submodule ) :void {
		$ret = $this->cmd( 'git', 'submodule', 'update', '--init', $submodule );
		if ( $ret !== 0 ) {
			$this->croak(
				"Problem creating with submodule ($submodule)!"
			);
		}
	}

	/**
	 * Get current branch
	 *
	 * @return string
	 */
	public function getCurrentBranch() :string {
		return $this->cmdOut( 'git', 'rev-parse', '--abbrev-ref', 'HEAD' );
	}

	/**
	 * Get a list of current branches
	 *
	 * @return array
	 */
	public function getLocalBranches() :array {
		return explode(
			"\n", $this->cmdOut(
				'git', 'for-each-ref', '--format=%(refname:short)',
				'refs/heads/'
			)
		);
	}

	/**
	 * Find out if the remote has this branch
	 *
	 * @param string $repoUrl
	 * @param string $branch
	 * @return bool
	 */
	public function hasRemoteBranch( string $repoUrl, string $branch ) :bool {
		return $this->cmd(
			'git', 'ls-remote', '--exit-code', '--heads', $repoUrl, $branch
		) !== 2;
	}

	/**
	 * Find what remote branch the local branch is tracking
	 *
	 * @param string $branch
	 * @return string
	 */
	public function getTrackingBranch( string $branch ) :string {
		return $this->cmdOut(
			'git', 'for-each-ref', '--format=%(upstream:short)', 'refs/heads/'
			. $branch
		);
	}

	/**
	 * Check out a the local version of the branch
	 *
	 * @param string $branch
	 */
	public function checkout( string $branch ) :void {
		if ( $this->cmd( 'git', 'checkout', $branch ) ) {
			$this->croak( "Failed to check out $branch!" );
		}
	}

	/**
	 * Update the current branch
	 *
	 * @param string $dir
	 */
	public function pull( string $dir ) :void {
		$this->chdir( $dir );
		if ( $this->cmd( 'git', 'pull' ) ) {
			$this->croak( "Failed to update current branch!" );
		}
		$this->popdir();
	}

	/**
	 * Checkout a new branch
	 * @param string $branch
	 * @param string $sourceBranch
	 */
	public function checkoutNewBranch(
		string $branch,
		string $sourceBranch = null
	) :void {
		if ( $this->cmd(
			'git', 'checkout', '-b', $branch, $sourceBranch
		) ) {
			$this->croak(
				"Failed checkout branch ($branch) from origin/master!"
			);
		}
	}

	/**
	 * Set origin to URL
	 *
	 * @param string $url
	 */
	public function setOrigin( string $url ) :void {
		if ( $this->cmd( 'git', 'remote', 'set-url', 'origin', $url ) ) {
			$this->croak( "Somehow there was a problem setting the remote" );
		}
	}

	/**
	 * Set url as the origin
	 *
	 * @param string $url
	 * @return string
	 */
	public function getCurrentLogToOrigin( string $branch ) :string {
		if ( $this->cmd( 'git', 'fetch' ) ) {
			$this->croak(
				"Somehow there was a problem fetching from the remote"
			);
		}
		return $this->cmdOut(
			'git', 'log', "HEAD..origin/$branch", '--pretty=oneline'
		);
	}

	/**
	 * Return true if the local checkout has any changes that need to
	 * be dealt with
	 *
	 * @return bool
	 */
	public function hasChanges() :bool {
		return strlen( $this->cmdOut( 'git', 'status', '--porcelain' ) ) > 0;
	}

	/**
	 * Attempt to push the to the remote branch
	 *
	 * @param $remoteName
	 */
	public function pushRemote( $remoteName ) :void {
		if ( $this->runWriteCmd(
				 'git', 'push', '-u', 'origin', $remoteName
		) ) {
			$this->croak( "Problems pushing to origin!" );
		}
	}

	/**
	 * Actually clone the repository
	 *
	 * @param string $repo dir to put things
	 * @param string $repoPath to clone from
	 * @param string $branch
	 */
	public function clone(
		string $repo,
		string $repoPath,
		string $branch = 'master'
	) :void {
		if ( $this->cmd(
			'git', 'clone', '--branch', $branch, '--depth', 1, $repoPath, $repo
		) ) {
			$this->croak(
				"Problem creating branch ($branch) on repo ($repo)!"
			);
		}
	}

	/**
	 * Remove a directory
	 *
	 * @param string $dir
	 */
	public function rmdir( string $dir ) :void {
		if ( $this->runCmd( 'rm', '-rf', '--', $dir ) ) {
			$this->croak( "Problem removing $dir!" );
		}
	}

	/**
	 * Add a submodule to the current git repo
	 *
	 * @param string $branch to use for submodule
	 * @param string $repo to use as the remote
	 * @param string $dir relative to root of current repo
	 */
	public function addSubmodule(
		string $branch,
		string $repo,
		string $dir
	) {
		if (
			$this->runCmd(
				'git', 'submodule', 'add', '-f', '-b', $branch, $repo,
				$dir
			)
		) {
			$this->croak( "Adding submodule from repository ($repo) failed!" );
		}
	}

	/**
	 * Push branch to remote.
	 *
	 * @param string $remote
	 * @param string $branch
	 */
	public function push( string $remote, string $branch ) :void {
		if ( $this->control->runWriteCmd( 'git', 'push', $remote, $branch ) ) {
			$this->croak(
				"Couldn't push branch ($branch) to remote ($remote)!"
			);
		}
	}

}
