<?php
/**
 * beanstalk: A minimalistic PHP beanstalk client.
 *
 * Copyright (c) 2009-2013 David Persson
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

spl_autoload_register(function($class) {
	if (strpos($class, 'beanstalk\\') === false) {
		return;
	}
	$subdir = strpos($class, 'beanstalk\\tests\\') === false ? 'src/' : '';
	$file = __DIR__ . "/" . $subdir . str_replace(['beanstalk\\', '\\'], ['', '/'], $class) . '.php';

	if (file_exists($file)) {
		include $file;
	}
});

?>