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
	/** @var array */
	protected $localBranches;
	/** @var ?GerritRestAPI */
	protected $gerrit = null;

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
		$this->localBranches = [];
	}

	/**
	 * Set up a gerrit repo
	 *
	 * @param string $url
	 */
	public function setGerritURL( string $url ) :void {
		$this->gerrit = new GerritRestAPI( $url );
	}

	protected function croak( string $msg ) :void {
		$this->brancher->croak( $msg );
	}

	/**
	 * Find out if the remote has this branch
	 *
	 * @param string $repoUrl
	 * @param string $branch
	 * @return bool
	 */
	public function hasRemoteBranch(
		string $repoUrl,
		string $branch
	) :bool {
		/**
		 *  From manpage for git-ls-remote:
		 *
		 *   --exit-code
         * Exit with status "2" when no matching refs are found in
         * the remote repository. Usually the command exits with
         * status "0" to indicate it successfully talked with the
         * remote repository, whether it found any matching refs.
		 */
		return $this->cmd(
			'git', 'ls-remote', '--exit-code', '--heads', $repoUrl, $branch
		) !== 2;
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
	) :void {
		throw new Exception( "Implement addSubmodule!" );
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
	 * Get branch info for a list of repositories
	 *
	 * @param array $repo
	 * @param array $branch to get info on
	 * @return array
	 */
	public function getBranchInfo( array $repo, array $branch ) :array {
		if ( !$this->gerrit ) {
			$this->croak( "Please set up the Gerrit remote first!" );
		}

		$ret = [];
		$lookFor = array_flip( $branch );
		foreach( $repo as $project ) {
			$branch = $this->gerrit->getProjectBranches( $project );
			$ret[$project] = array_filter(
				$branch,
				function ( $branch ) use ( $lookFor ) {
					return isset( $lookFor[$branch] );
				},
				ARRAY_FILTER_USE_KEY
			);
		}
		return [];
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
