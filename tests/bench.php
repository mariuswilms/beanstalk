<?php
/**
 * beanstalk: A minimalistic PHP beanstalk client.
 *
 * Copyright (c) 2009-2014 David Persson
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */

namespace beanstalk\tests;

use beanstalk\Client;

/**
 * A small benchmark to test throughput.
 */
$connection = new Client();
for ($i = 0; $i < 100000; $i++) {
	$connection->put(1024, 0, 60, $i);
}

?>