<?php
/**
 * beanstalk: A minimalistic PHP beanstalk client.
 *
 * Copyright (c) 2009-2014 David Persson
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 */


$loader = require __DIR__ . "/../vendor/autoload.php";
$loader->addPsr4('Beanstalk\\', __DIR__);

date_default_timezone_set('UTC');

?>