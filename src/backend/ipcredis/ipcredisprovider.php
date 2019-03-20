<?php
/***********************************************
* File      :   ipcredisprovider.php
* Project   :   Z-Push
* Descr     :   IPC provider using Redis PHP extension
*               and redis server defined in
*               $zpush_ipc_redis_server
*
* Created   :   14.03.2019 by Evandrofisico <evandrofisico@gmail.com>
*
* Copyright 2007 - 2016 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/
//include own config file
require_once("backend/ipcredis/config.php");

class IpcRedisProvider implements IIpcProvider {
    protected $type;
    protected $serverKey;
    private $typeMutex;
    private $maxWaitCycles;
    private $logWaitCycles;
    private $isDownUntil;
    private $wasDown;
    private $reconnectCount;

    /**
     * Instance of redis class
     *
     * @var redis
     */
    protected $redis;


    /**
     * Constructor
     *
     * @param int $type
     * @param int $allocate
     * @param string $class
     * @param string $serverKey
     */
    public function __construct($type, $allocate, $class) {
        $this->type = $type;
        $this->typeMutex = $type . "MX";
        //$this->serverKey = $serverKey;
        $this->maxWaitCycles = round(REDIS_MUTEX_TIMEOUT / REDIS_BLOCK_WAIT)+1;
        $this->logWaitCycles = round($this->maxWaitCycles/5);

        // not used, but required by function signature
        unset($allocate, $class);

        if (!class_exists('Redis')) {
            throw new FatalMisconfigurationException("IpcRedisProvider failure: can not find class Redis. Please make sure the php redis extension is installed.");
        }

        $this->reconnectCount = 0;
        $this->init();

        // check if redis was down recently
        $this->isDownUntil = $this->getIsDownUntil();
        $this->wasDown = ! $this->IsActive();
    }

    /**
     * Initializes the Redis object & connection.
     *
     * @access private
     * @return void
     */
    private function init() {
        $this->redis= new Redis();
        $this->redis->connect(REDIS_SERVER,REDIS_PORT,REDIS_TIMEOUT);
	$this->redis->auth(REDIS_PASSWORD);
	$this->redis->select(REDIS_DB);
	$this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
	$this->redis->setOption(Redis::OPT_PREFIX, REDIS_PREFIX);
    }

    /**
     * Reinitializes the IPC data. If the provider has no way of performing
     * this action, it should return 'false'.
     *
     * @access public
     * @return boolean
     */
    public function ReInitIPC() {
        return $this->redis->flushDB();
    }

    /**
     * Cleans up the IPC data block.
     *
     * @access public
     * @return boolean
     */
    public function Clean() {
	$this->redis->del($this->redis->keys(REDIS_PREFIX . '*'));
        return true;
    }

    /**
     * Indicates if the IPC is active.
     *
     * @access public
     * @return boolean
     */
    public function IsActive() {
        $down = $this->isDownUntil > time();
        // reconnect if we were down but should retry now
        if (!$down && $this->wasDown) {
            ZLog::Write(LOGLEVEL_DEBUG, "IpcRedisProvider->IsActive(): redis was down, trying to reconnect");
            $this->init();
            $this->wasDown = false;
        }
        return !$down;
    }

    /**
     * Blocks the class mutex.
     * Method blocks until mutex is available!
     * ATTENTION: make sure that you *always* release a blocked mutex!
     *
     * We try to add mutex to our cache, until we succeed.
     * It will fail as long other client has stored it or the
     * REDIS_MUTEX_TIMEOUT is reached.
     *
     * @access public
     * @return boolean
     */
    public function BlockMutex() {
        if (!$this->IsActive()) {
            return false;
        }

        $n = 0;
        while(!$this->redis->set($this->typeMutex, true, REDIS_MUTEX_TIMEOUT)) {
            if (++$n % $this->logWaitCycles == 0) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("IpcRedisProvider->BlockMutex() waiting to aquire mutex for type: %s ", $this->typeMutex));
            }
            // wait before retrying
            usleep(REDIS_BLOCK_WAIT * 1000);
            if ($n > $this->maxWaitCycles) {
                ZLog::Write(LOGLEVEL_ERROR, sprintf("IpcRedisProvider->BlockMutex() could not aquire mutex for type: %s. Check redis service!", $this->typeMutex));
                $this->markAsDown();
                return false;
            }
        }
        if ($n*REDIS_BLOCK_WAIT > 50) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("IpcRedisProvider->BlockMutex() mutex aquired after waiting for %sms for type: %s", ($n*REDIS_BLOCK_WAIT), $this->typeMutex));
        }
        return true;
    }

    /**
     * Releases the class mutex.
     * After the release other processes are able to block the mutex themselves.
     *
     * @access public
     * @return boolean
     */
    public function ReleaseMutex() {
        return $this->redis->delete($this->typeMutex);
    }

    /**
     * Indicates if the requested variable is available in IPC data.
     *
     * @param int   $id     int indicating the variable
     *
     * @access public
     * @return boolean
     */
    public function HasData($id = 2) {
        $exists = $this->redis->exists($this->type.':'.$id);
	if ($exists < 1)
		return false;
	else 
		return true;
    }

    /**
     * Returns the requested variable from IPC data.
     *
     * @param int   $id     int indicating the variable
     *
     * @access public
     * @return mixed
     */
    public function GetData($id = 2) {
        return $this->redis->get($this->type.':'.$id);
    }

    /**
     * Writes the transmitted variable to IPC data.
     * Subclasses may never use an id < 2!
     *
     * @param mixed $data   data which should be saved into IPC data
     * @param int   $id     int indicating the variable (bigger than 2!)
     *
     * @access public
     * @return boolean
     */
    public function SetData($data, $id = 2) {
        return $this->redis->set($this->type.':'.$id, $data);
    }

    /**
     * Gets the epoch time until the redis server should not be retried.
     * If there is no data available, 0 is returned.
     *
     * @access private
     * @return long
     */
    private function getIsDownUntil() {
        if (file_exists(REDIS_DOWN_LOCK_FILE)) {
            $timestamp = file_get_contents(REDIS_DOWN_LOCK_FILE);
            // is the lock file expired?
            if ($timestamp > time()) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("IpcRedisProvider(): Redis service is marked as down until %s.", strftime("%d.%m.%Y %H:%M:%S", $timestamp)));
                return $timestamp;
            }
            else {
                @unlink(REDIS_DOWN_LOCK_FILE);
            }
        }
        return 0;
    }

    /**
     * Indicates that redis is not available and that it should not be retried.
     *
     * @access private
     * @return boolean
     */
    private function markAsDown() {
        ZLog::Write(LOGLEVEL_WARN, sprintf("IpcRedisProvider(): Marking Redis service as down for %d seconds.", REDIS_DOWN_LOCK_EXPIRATION));
        $downUntil = time() + REDIS_DOWN_LOCK_EXPIRATION;
        $this->isDownUntil = $downUntil;
        $this->wasDown = true;
        return !!file_put_contents(REDIS_DOWN_LOCK_FILE, $downUntil);
    }
}
