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
use Exception;
use Psr\Log\LoggerInterface;
use React\EventLoop\Factory as LoopFactory;
use Wikimedia\AtEase\AtEase;
use Hexmode\PhpGerrit\GerritRestAPI;
use Hexmode\PhpGerrit\Entity\BranchInput;
use Hexmode\PhpGerrit\Entity\BranchInfo;

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
	/** @var ?string */
	protected $gerritURL = null;
	/** @var ?GerritRestAPI */
	protected $gerrit = null;
	/** @var array */
	protected $branchCache;

	public function __construct(
		LoggerInterface $logger,
		bool $dryRun,
		Branch $brancher
	) {
		$this->logger = $logger;
		$this->dryRun = $dryRun;
		$this->dirs = [];
		$this->output = "";
		$this->storeOutput = false;
		$this->brancher = $brancher;
		$this->branchCache = [];
	}

	/**
	 * Set up a gerrit repo
	 *
	 * @param string $url
	 */
	public function setGerritURL( string $url ) :void {
		$this->gerritURL = $url;
		$this->gerrit = new GerritRestAPI( $url );
		if ( $this->dryRun ) {
			$this->gerrit->setReadOnly();
		}
	}

	/**
	 * Display error message and die
	 *
	 * @return never-return
	 */
	protected function croak( string $msg ) :void {
		$this->brancher->croak( $msg );
	}

	/**
	 * Return the output of git status --porcelain
	 * be dealt with
	 *
	 * @return string
	 */
	public function getChanges() :string {
		return $this->cmdOutNoTrim( 'git', 'status', '--porcelain' );
	}

	/**
	 * Like cmdOut, but the output isn't put through trim.
	 *
	 * @return string
	 */
	public function cmdOutNoTrim( /*...*/ ) :string {
		$this->storeOutput = true;
		$this->output = '';
		$this->cmd( func_get_args() );
		$this->storeOutput = false;
		return $this->output;
	}

	/**
	 * Determine if a branch already exists for this repository.
	 *
	 * @param string $repo to check
	 * @param string $branch to check for
	 * @return bool
	 */
	public function hasBranch( string $repo, string $branch ) :bool {
		$branchList = $this->getBranches( $repo );
		$res = in_array( $branch, $branchList );
		if ( $res ) {
			$this->logger->debug( "Branch ($branch) exists in ($repo)" );
		} else {
			$this->logger->debug( "Branch ($branch) does not "
								. "exist in ($repo)" );
		}
		return $res;
	}

	/** Return the list of branches for this repository.
	 *
	 * @param string $repo to get
	 * @return array<int|string> of branches
	 */
	public function getBranches( string $repo ) :array {
		$branchInfo = $this->getBranchInfo( [ $repo ] );
		return $branchInfo[$repo];
	}

	/**
	 * Get branch info for a list of repositories
	 *
	 * @param array $repo
	 * @param array $branches to get info on
	 * @return array
	 */
	public function getBranchInfo( array $repo ) :array {
		if ( !$this->gerrit ) {
			$this->croak( "Please set up the Gerrit remote first!" );
		}
		$ret = [];
		foreach ( array_filter( $repo ) as $project ) {
			if ( !isset( $this->branchCache[$project] ) ) {
				$this->logger->info( "Get branch information on $project." );
				$this->branchCache[$project]
					= $this->gerrit->listBranches( $project );
			}
			$ret[$project] = $this->branchCache[$project];
		}
		return $ret;
	}

	/**
	 * Handle server-side branching
	 *
	 * @param string $repo
	 * @param string $branchFrom where to base the branch from
	 * @param string $newBranch name of new branch
	 * @return BranchInfo
	 */
	public function createBranch(
		string $repo,
		string $branchFrom,
		string $newBranch
	) :BranchInfo {
		if ( !$this->gerrit ) {
			$this->croak( "Please set up the Gerrit remote first!" );
		}
		$branch = new BranchInput(
			[ 'ref' => $newBranch, 'revision' => $branchFrom ]
		);
		return $this->gerrit->createBranch( $repo, $branch );
	}

	/**
	 * Checkout a branch of the reposistory
	 *
	 * @param string $repo primary repo
	 * @param string $branch submodule'd repo
	 * @param string $loc is sthe location on disk
	 */
	public function clone(
		string $repo,
		string $branch,
		string $loc
	) :void {
		if ( $this->cmd(
				 "git", "clone", "-b", $branch, $this->makeRepoURL( $repo ),
				 $loc
		) ) {
			$this->croak( "Trouble cloning!" );
		}
	}

	/**
	 * Ensure that the given directory is completely empty by removing
	 * it and recreating it.
	 *
	 * @param string $dir
	 * @throws Exception
	 */
	public function ensureEmptyDir( string $dir ) :void {
		if ( $this->cmd( "rm", "-rf", $dir ) ) {
			throw new Exception( "Could not erase $dir!" );
		}
		if ( !mkdir( $dir, 0722, true ) ) {
			throw new Exception( "Could not create $dir!" );
		}
	}

	/**
	 * Get a fully qualified URL for the gerrit repo.
	 *
	 * @param string $repo in gerrit
	 * @return string URL
	 */
	public function makeRepoURL( string $repo ) : string {
		if ( !$this->gerritURL ) {
			$this->croak( "Please set up the Gerrit URL first!" );
		}
		return $this->gerritURL . "/" . $repo;
	}

	/**
	 * Determine if a submodule exists
	 *
	 * @param string $repo primary repo
	 * @param string $branch to look in
	 * @param string $subRepo submodule'd repo
	 * @param string $loc location repo should be as a subdir
	 * @return bool
	 */
	public function hasSubmodule(
		string $repo,
		string $subRepo,
		string $loc
	) :bool {
	}

	/**
	 * Set up a submodule to a repo
	 *
	 * @param string $repo primary repo
	 * @param string $subRepo submodule'd repo
	 * @param string $loc location repo should be as a subdir
	 * @return bool
	 */
	public function addSubmodule(
		string $repo,
		string $subRepo,
		string $loc
	) :bool {
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

		$this->logger->debug( "$ " . implode( ' ', $args ) );

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
					$this->logger->debug( $out );
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
}
