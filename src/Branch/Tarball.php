<?php

namespace Wikimedia\Release\Branch;

use Wikimedia\Release\Branch;

class Tarball extends Branch {
	public static function getDescription() :string {
		return 'Prepare the tree for a tarball release';
	}

	protected function getWorkDir() :string {
		$dir = getenv( "mwDir" );
		if ( !$dir ) {
			$dir = sys_get_temp_dir() . '/make-tarball-branch';
		}
		return $dir;
	}

	protected function getBranchPrefix() :string {
		return "";
	}

	protected function getRepoPath() :string {
		$path = getenv( "gerritHead" );
		if ( !$path ) {
			$path = 'https://gerrit.wikimedia.org/r';
		}
		return "$path/mediawiki";
	}

	protected function getBranchDir() :string {
		$branch = getenv( "relBranch" );
		if ( !$branch ) {
			$this->croak( "The environment variable relBranch must be set" );
		}
		return $branch;
	}
}
