<?php
/**
 * beanstalk: A minmalistic PHP beanstalk client.
 *
 * Copyright (c) 2009-2011 David Persson
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright  2009-2011 David Persson <nperson@gmx.de>
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 */

/**
 * An interface to the beanstalk queue service. Implements the beanstalk
 * protocol spec 1.2.
 *
 * @link https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt
 */
class Socket_Beanstalk {

	public $connected = false;

	/**
	 * Holds configuration values.
	 *
	 * @var array
	 */
	protected $_config = array();

	protected $_connection;

	protected $_errors = array();

	public function __construct(array $config = array()) {
		$defaults = array(
			'persistent' => true, // the FAQ recommends persistent connections
			'host' => '127.0.0.1',
			'port' => 11300,
			'timeout' => 1 // the timeout when connecting to the server
		);
		$this->_config = $config + $defaults;
	}

	/**
	 * Destructor, used to disconnect from current connection.
	 *
	 */
	public function __destruct() {
		$this->disconnect();
	}

	/**
	 * Creates a connection.
	 *
	 * @return boolean
	 */
	public function connect() {
		if (isset($this->_connection)) {
			$this->disconnect();
		}

		$function = $this->_config['persistent'] ? 'pfsockopen' : 'fsockopen';
		$params = array($this->_config['host'], $this->_config['port'], &$errNum, &$errStr);

		if ($this->_config['timeout']) {
			$params[] = $this->_config['timeout'];
		}
		$this->_connection = @call_user_func_array($function, $params);

		if (!empty($errNum) || !empty($errStr)) {
			$this->_errors[] = "{$errNum}: {$errStr}";
		}

		$this->connected = is_resource($this->_connection);

		if ($this->connected) {
			stream_set_timeout($this->_connection, -1); // no timeout when reading from the socket
		}
		return $this->connected;
	}

	/**
	 * Disconnect the socket from the current connection.
	 *
	 * @return boolean Success
	 */
	public function disconnect() {
		if (!is_resource($this->_connection)) {
			$this->connected = false;
		} else {
			$this->connected = !fclose($this->_connection);

			if (!$this->connected) {
				$this->_connection = null;
			}
		}
		return !$this->connected;
	}

	public function errors() {
		return $this->_errors;
	}

	/**
	 * Writes a packet to the socket
	 *
	 * @param string $data
	 * @return integer|boolean number of written bytes or false on error
	 */
	protected function _write($data) {
		if (!$this->connected && !$this->connect()) {
			return false;
		}

		$data .= "\r\n";
		return fwrite($this->_connection, $data, strlen($data));
	}

	/**
	 * Reads a packet from the socket
	 *
	 * @param int $length Number of bytes to read
	 * @return string|boolean Data or false on error
	 */
	protected function _read($length = null) {
		if (!$this->connected && !$this->connect()) {
			return false;
		}
		if ($length) {
			if (feof($this->_connection)) {
				return false;
			}
			$data = fread($this->_connection, $length + 2);
			$meta = stream_get_meta_data($this->_connection);

			if ($meta['timed_out']) {
				$this->_errors[] = 'Connection timed out.';
				return false;
			}
			$packet = rtrim($data, "\r\n");
		} else {
			$packet = stream_get_line($this->_connection, 16384, "\r\n");
		}
		return $packet;
	}

	/* Producer Commands */

	/**
	 * The "put" command is for any process that wants to insert a job into the queue.
	 *
	 * @param integer $pri Jobs with smaller priority values will be scheduled
	 *                     before jobs with larger priorities.
	 *                     The most urgent priority is 0; the least urgent priority is 4294967295.
	 * @param integer $delay Seconds to wait before putting the job in the ready queue.
	 *                       The job will be in the "delayed" state during this time.
	 * @param integer $ttr Time to run - Number of seconds to allow a worker to run this job.
	 *                     The minimum ttr is 1.
	 * @param string $data The job body
	 * @return integer|boolean False on error otherwise and integer indicating the job id
	 */
	public function put($pri, $delay, $ttr, $data) {
		$this->_write(sprintf('put %d %d %d %d', $pri, $delay, $ttr, strlen($data)));
		$this->_write($data);
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'INSERTED':
			case 'BURIED':
				return (integer)strtok(' '); // job id
			case 'EXPECTED_CRLF':
			case 'JOB_TOO_BIG':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * The "use" command is for producers. Subsequent put commands will put jobs into
	 * the tube specified by this command. If no use command has been issued, jobs
	 * will be put into the tube named "default".
	 *
	 * @param string $tube A name at most 200 bytes. It specifies the tube to use.
	 *                     If the tube does not exist, it will be created.
	 * @return string|boolean False on error otherwise the tube
	 */
	public function choose($tube) {
		$this->_write(sprintf('use %s', $tube));
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'USING':
				return strtok(' ');
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Alias for choose
	 */
	public function useTube($tube) {
		return $this->choose($tube);
	}

	/* Worker Commands */

	/**
	 * Reserve a job (with a timeout)
	 *
	 * @param integer $timeout If given specifies number of seconds to wait for a job. 0 returns immediately.
	 * @return array|false False on error otherwise an array holding job id and body
	 */
	public function reserve($timeout = null) {
		if (isset($timeout)) {
			$this->_write(sprintf('reserve-with-timeout %d', $timeout));
		} else {
			$this->_write('reserve');
		}
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'RESERVED':
				return array(
					'id' => (integer)strtok(' '),
					'body' => $this->_read((integer)strtok(' '))
				);
			case 'DEADLINE_SOON':
			case 'TIMED_OUT':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Removes a job from the server entirely
	 *
	 * @param integer $id The id of the job
	 * @return boolean False on error, true on success
	 */
	public function delete($id) {
		$this->_write(sprintf('delete %d', $id));
		$status = $this->_read();

		switch ($status) {
			case 'DELETED':
				return true;
			case 'NOT_FOUND':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Puts a reserved job back into the ready queue
	 *
	 * @param integer $id The id of the job
	 * @param integer $pri Priority to assign to the job
	 * @param integer $delay Number of seconds to wait before putting the job in the ready queue
	 * @return boolean False on error, true on success
	 */
	public function release($id, $pri, $delay) {
		$this->_write(sprintf('release %d %d %d', $id, $pri, $delay));
		$status = $this->_read();

		switch ($status) {
			case 'RELEASED':
			case 'BURIED':
				return true;
			case 'NOT_FOUND':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Puts a job into the "buried" state
	 *
	 * Buried jobs are put into a FIFO linked list and will not be touched
	 * until a client kicks them.
	 *
	 * @param mixed $id
	 * @param mixed $pri
	 * @return boolean False on error and true on success
	 */
	public function bury($id, $pri) {
		$this->_write(sprintf('bury %d %d', $id, $pri));
		$status = $this->_read();

		switch ($status) {
			case 'BURIED':
				return true;
			case 'NOT_FOUND':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Allows a worker to request more time to work on a job
	 *
	 * @param integer $id The id of the job
	 * @return boolean False on error and true on success
	 */
	public function touch($id) {
		$this->_write(sprintf('touch %d', $id));
		$status = $this->_read();

		switch ($status) {
			case 'TOUCHED':
				return true;
			case 'NOT_TOUCHED':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Adds the named tube to the watch list for the current
	 * connection.
	 *
	 * @param string $tube
	 * @return integer|boolean False on error otherwise number of tubes in watch list
	 */
	public function watch($tube) {
		$this->_write(sprintf('watch %s', $tube));
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'WATCHING':
				return (integer)strtok(' ');
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Remove the named tube from the watch list
	 *
	 * @param string $tube
	 * @return integer|boolean False on error otherwise number of tubes in watch list
	 */
	public function ignore($tube) {
		$this->_write(sprintf('ignore %s', $tube));
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'WATCHING':
				return (integer)strtok(' ');
			case 'NOT_IGNORED':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/* Other Commands */

	/**
	 * Inspect a job by id
	 *
	 * @param integer $id The id of the job
	 * @return string|boolean False on error otherwise the body of the job
	 */
	public function peek($id) {
		$this->_write(sprintf('peek %d', $id));
		return $this->_peekRead();
	}

	/**
	 * Inspect the next ready job
	 *
	 * @return string|boolean False on error otherwise the body of the job
	 */
	public function peekReady() {
		$this->_write('peek-ready');
		return $this->_peekRead();
	}

	/**
	 * Inspect the job with the shortest delay left
	 *
	 * @return string|boolean False on error otherwise the body of the job
	 */
	public function peekDelayed() {
		$this->_write('peek-delayed');
		return $this->_peekRead();
	}

	/**
	 * Inspect the next job in the list of buried jobs
	 *
	 * @return string|boolean False on error otherwise the body of the job
	 */
	public function peekBuried() {
		$this->_write('peek-buried');
		return $this->_peekRead();
	}

	/**
	 * Handles response for all peek methods
	 *
	 * @return string|boolean False on error otherwise the body of the job
	 */
	protected function _peekRead() {
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'FOUND':
				return array(
					'id' => (integer)strtok(' '),
					'body' => $this->_read((integer)strtok(' '))
				);
			case 'NOT_FOUND':
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Moves jobs into the ready queue (applies to the current tube)
	 *
	 * If there are buried jobs those get kicked only otherwise
	 * delayed jobs get kicked.
	 *
	 * @param integer $bound Upper bound on the number of jobs to kick
	 * @return integer|boolean False on error otherwise number of job kicked
	 */
	public function kick($bound) {
		$this->_write(sprintf('kick %d', $bound));
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'KICKED':
				return (integer)strtok(' ');
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/* Stats Commands */

	/**
	 * Gives statistical information about the specified job if it exists
	 *
	 * @param integer $id The job id
	 * @return string|boolean False on error otherwise a string with a yaml formatted dictionary
	 */
	public function statsJob($id) {}

	/**
	 * Gives statistical information about the specified tube if it exists
	 *
	 * @param string $tube Name of the tube
	 * @return string|boolean False on error otherwise a string with a yaml formatted dictionary
	 */
	public function statsTube($tube) {}

	/**
	 * Gives statistical information about the system as a whole
	 *
	 * @return string|boolean False on error otherwise a string with a yaml formatted dictionary
	 */
	public function stats() {
		$this->_write('stats');
		$status = strtok($this->_read(), ' ');

		switch ($status) {
			case 'OK':
				return $this->_read((integer)strtok(' '));
			default:
				$this->_errors[] = $status;
				return false;
		}
	}

	/**
	 * Returns a list of all existing tubes
	 *
	 * @return string|boolean False on error otherwise a string with a yaml formatted list
	 */
	public function listTubes() {}

	/**
	 * Returns the tube currently being used by the producer
	 *
	 * @return string|boolean False on error otherwise a string with the name of the tube
	 */
	public function listTubeUsed() {}

	/**
	 * Alias for listTubeUsed
	 */
	public function listTubeChosen() {
		return $this->listTubeUsed();
	}

	/**
	 * Returns a list of tubes currently being watched by the worker
	 *
	 * @return string|boolean False on error otherwise a string with a yaml formatted list
	 */
	public function listTubesWatched() {}
}

?>