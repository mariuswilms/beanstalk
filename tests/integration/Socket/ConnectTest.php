<?php

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