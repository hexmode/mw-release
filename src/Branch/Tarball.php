<?php

namespace Wikimedia\Release\Branch;

use Wikimedia\Release\Branch;

class Tarball extends Branch {
	public static function getDescription() {
		return 'Prepare the tree for a tarball release';
	}
}
