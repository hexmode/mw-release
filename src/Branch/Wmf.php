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

namespace Wikimedia\Release\Branch;

use Wikimedia\Release\Branch;

class Wmf extends Branch {
	public static function getShortname() :string {
		return 'wmf';
	}

	public static function getDescription() :string {
		return 'Create a WMF Branch';
	}

	public function getWorkDir() :string {
		return sys_get_temp_dir() . '/make-wmf-branch';
	}

	protected function getBranchPrefix() :string {
		return "wmf/";
	}

	public function getRepoPath() :string {
		return 'https://gerrit.wikimedia.org/r/mediawiki';
	}

	protected function getBranchDir() :string {
		return 'wmf';
	}

	protected function getConfigJson( string $dir ) :string {
		return $dir . '/config.json';
	}

	/**
	 * Set up the build directory
	 *
	 * @returns string
	 */
	public function setupBuildDirectory() :string {
		$this->teardownBuildDirectory();
		if ( !mkdir( $this->buildDir ) ) {
			$this->croak(
				"Unable to create build directory {$this->buildDir}"
			);
		}
		return $this->buildDir;
	}

	/**
	 * Remove the build directory if it exists
	 */
	public function teardownBuildDirectory() :void {
		if ( file_exists( $this->buildDir ) ) {
			$this->control->rmdir( $this->buildDir );
		}
	}
}
