<?php
/**
 * beanstalk: A minimalistic PHP beanstalk client.
 *
 * Copyright (c) 2009-2013 David Persson
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright  2009-2013 David Persson <nperson@gmx.de>
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link       http://github.com/davidpersson/beanstalk
 */

require_once 'Socket/Beanstalk.php';

class ConnectTest extends PHPUnit_Framework_TestCase {

	public $subject;

	protected function setUp() {
		$this->subject = new Socket_Beanstalk(array(
			'host' => TEST_SERVER_HOST,
			'port' => TEST_SERVER_PORT
		));
		if (!$this->subject->connect()) {
			$message = 'Need a running beanstalk server at ' . TEST_SERVER_HOST . ':' . TEST_SERVER_PORT;
			$this->markTestSkipped($message);
		}
	}

	public function testConnection() {
		$this->subject->disconnect();

		$result = $this->subject->connect();
		$this->assertTrue($result);

		$result = $this->subject->connected;
		$this->assertTrue($result);

		$result = $this->subject->disconnect();
		$this->assertTrue($result);

		$result = $this->subject->connected;
		$this->assertFalse($result);
	}
}

?>