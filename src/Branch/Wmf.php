<?php

namespace Wikimedia\Release\Branch;

use Wikimedia\Release\Branch;

class Wmf extends Branch {
	public static function getDescription() :string {
		return 'Create a WMF Branch';
	}

	protected function getWorkDir() :string {
		return sys_get_temp_dir() . '/make-wmf-branch';
	}

	protected function getBranchPrefix() :string {
		return "wmf/";
	}

	protected function getRepoPath() :string {
		return 'https://gerrit.wikimedia.org/r/mediawiki';
	}

	protected function getBranchDir() :string {
		return 'wmf';
	}
}
