<?php

/*
 * Copyright (C) 2019  Mark A. Hershberger
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
 */

namespace Wikimedia\Release;

use splitbrain\phpcli\Options;
use Exception;

class Branch {
	public $dryRun;
	public $newVersion, $oldVersion, $buildDir;
	public $specialExtensions, $branchedExtensions;
	public $repoPath;
	public $noisy;

	/**
	 * Factory of branches
	 *
	 * @param string $type of brancher to create
	 * @param Options $opt the user gave
	 */
	static function getBrancher( string $type, Options $opt ) {
		$class = __CLASS__ . "\\" . ucFirst( $type );
		if ( !class_exists( $class ) ) {
			throw new Exception( "$type is not a proper brancher!" );
		}

		$branch = new $class();
		$branch->newVersion = $opt->getOpt( "new" );
		$branch->oldVersion = $opt->getOpt( "old" ) ?: 'master';
		$branch->branchPrefix = $opt->getOpt( 'branch-prefix' );
		$branch->clonePath = $opt->getOpt( 'path' );
		$branch->alreadyBranched = [];

		return $branch;
	}

	/**
	 * Handle brancher initialization
	 */
	public function initialize() {
		$buildDir = sys_get_temp_dir() . '/make-wmf-branch';
		list( $arg0 ) = get_included_files();
		$dir = dirname( $arg0 );

		if ( !is_readable( $dir . '/default.conf' ) ) {
			throw new Exception( "Can't read default.conf!" );
		}
		require $dir . '/default.conf';
		$this->repoPath = $repoPath;
		$this->branchPrefix = $branchPrefix;
		$this->dryRun = $dryRun;
		$this->noisy = $noisy;
		$this->clonePath = $this->clonePath ?: "{$this->repoPath}/core";

		$branchLists = [];
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

		$this->buildDir = $buildDir;
		$this->branchedExtensions = isset( $branchLists['extensions'] ) ?: [];
		$this->branchedSubmodules = isset( $branchLists['submodules'] ) ?: [];
		$this->specialExtensions = isset( $branchLists['special_extensions'] )
								?: [];
	}

	/**
	 * setup an alreadyBranched array that has the names of all extensions
	 * up-to the extension from which we would like to start branching
	 *
	 * @param String/null $extName - name of extension from which to
	 * start branching
	 */
	function setStartExtension( $extName = null ) {
		if ( $extName === null ) {
			return;
		}

		$foundKey = false;
		foreach ( array( $this->branchedExtensions ) as $branchedArr ) {
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

	function runCmd( /*...*/ ) {
		$args = func_get_args();
		if ( $this->noisy && in_array( "-q", $args ) ) {
			$args = array_diff( $args, array( "-q" ) );
		}
		$encArgs = array_map( 'escapeshellarg', $args );
		$cmd = implode( ' ', $encArgs );

		$attempts = 0;
		do {
			echo "$cmd\n";
			passthru( $cmd, $ret );

			if ( !$ret ) {
				// It worked!
				return;
			}
			echo "sleeping for 5s\n";
			sleep( 5 );
		} while ( ++$attempts <= 5 );
		$this->croak( $args[0] . " exit with status $ret\n" );
	}

	function runWriteCmd( /*...*/ ) {
		$args = func_get_args();
		if ( $this->dryRun ) {
			$encArgs = array_map( 'escapeshellarg', $args );
			$cmd = implode( ' ', $encArgs );
			echo "[dry-run] $cmd\n";
		} else {
			call_user_func_array( array( $this, 'runCmd' ), $args );
		}
	}

	function chdir( $dir ) {
		if ( !chdir( $dir ) ) {
			$this->croak( "Unable to change working directory\n" );
		}
		echo "cd $dir\n";
	}

	function croak( $msg ) {
		$red = `tput setaf 1`;
		$reset = `tput sgr0`;

		fprintf( STDERR, "[{$red}ERROR{$reset}] %s\n", $msg );
		exit( 1 );
	}

	function execute() {
		$this->setupBuildDirectory();
		foreach ( $this->branchedExtensions as $ext ) {
			$this->branchRepo( $ext );
		}
		foreach ( $this->specialExtensions as $ext => $branch ) {
			$this->branchRepo( $ext, $branch );
		}
		$this->branch();
	}

	function setupBuildDirectory() {
		# Create a temporary build directory
		$this->teardownBuildDirectory();
		if ( !mkdir( $this->buildDir ) ) {
			$this->croak(
				"Unable to create build directory {$this->buildDir}"
			);
		}
		$this->chdir( $this->buildDir );
	}

	function teardownBuildDirectory() {
		if ( file_exists( $this->buildDir ) ) {
			$this->runCmd( 'rm', '-rf', '--', $this->buildDir );
		}
	}

	function createBranch( $branchName ) {
		$this->runCmd( 'git', 'checkout', '-q', '-b', $branchName );
		$this->runWriteCmd( 'git', 'push', 'origin', $branchName );
	}

	function branchRepo( $path , $branch = 'master' ) {
		$repo = basename( $path );

		// repo has already been branched, so just bail out
		if ( isset( $this->alreadyBranched[$repo] ) ) {
			return;
		}

		$this->runCmd(
			'git', 'clone', '-q', '--branch', $branch, '--depth', '1',
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

	function branch() {
		# Clone the repository
		$oldVersion = $this->oldVersion == 'master'
					? 'master'
					: $this->branchPrefix . $this->oldVersion;
		$this->runCmd(
			'git', 'clone', '-q', $this->clonePath, '-b', $oldVersion, 'wmf'
		);

		$this->chdir( 'wmf' );

		# make sure our clone is up to date with origin
		if ( $this->clonePath ) {
			$this->runCmd(
				'git', 'pull', '-q', '--ff-only', 'origin', $oldVersion
			);
		}

		# Create a new branch from master and switch to it
		$newVersion = $this->branchPrefix . $this->newVersion;
		$this->runCmd( 'git', 'checkout', '-q', '-b', $newVersion );

		# Add extensions/skins/vendor
		foreach ( $this->branchedExtensions as $name ) {
			$this->runCmd(
				'git', 'submodule', 'add', '-f', '-b', $newVersion, '-q',
				"{$this->repoPath}/{$name}", $name
			);
		}

		# Add extension submodules
		foreach ( array_keys( $this->specialExtensions ) as $name ) {
			$this->runCmd(
				'git', 'submodule', 'add', '-f', '-b', $newVersion, '-q',
				"{$this->repoPath}/{$name}", $name
			);
		}

		# Fix $wgVersion
		$this->fixVersion( "includes/DefaultSettings.php" );

		# Do intermediate commit
		$this->runCmd(
			'git', 'commit', '-a', '-q', '-m',
			"Creating new WMF {$this->newVersion} branch"
		);

		$this->runWriteCmd(
			'git', 'push', 'origin', 'wmf/' . $this->newVersion
		);
	}

	function fixVersion( $fileName ) {
		$file = file_get_contents( $fileName );
		$file = preg_replace(
			'/^( \$wgVersion \s+ = \s+ )  [^;]*  ( ; \s* ) $/xm',
			"\\1'{$this->newVersion}'\\2", $file
		);
		file_put_contents( $fileName, $file );
	}
}