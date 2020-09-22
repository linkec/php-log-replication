<?php

namespace Replication;

use Workerman\Lib\Timer;

/**
 * Replication/Relay
 * @version 1.0.0
 */
class Relay
{
    protected $serverId = null;
    public $logSN = null;
    public $logPos = null;
    public $onLog = null;
    public $logPath = 'logs';
    protected $fileHandle = null;
    protected $lastSaveTime = 0;

    /**
     * Construct.
     * @param string $ip
     * @param int $port
     */
    public function __construct($serverId)
    {
        $this->serverId = $serverId;
        $this->loadRelayInfo();
    }

    public function onMessage($data)
    {
        call_user_func($this->onLog, $data);
    }

    public function start()
    {
        if (!$this->onLog) {
            $this->onLog = function () {
            };
        }
        $this->startMonitor();
    }

    protected function startMonitor()
    {
        Timer::add(1, function () {
            $logFile = $this->logFileName($this->logSN);
            clearstatcache();
            if (file_exists($logFile)) {
                if (filesize($logFile) > $this->logPos) {
                    if (!$this->fileHandle) {
                        $this->fileHandle = fopen($logFile, 'r');
                    }
                    fseek($this->fileHandle, $this->logPos);
                    do {
                        $head = fread($this->fileHandle, 12);
                        if ($head) {
                            $_tmp = unpack('Nlength', substr($head, 8));
                            $size = $_tmp['length'];
                            if ($size == 0) {
                                $this->changeLogFile();
                                return;
                            } else {
                                $logData = fread($this->fileHandle, $size);
                                $this->logPos = ftell($this->fileHandle);
                                $this->saveRelayInfo(false);
                                $this->onMessage($logData);
                            }
                        }
                    } while ($head);
                }
            }
        });
    }
    protected function changeLogFile()
    {
        fclose($this->fileHandle);
        $this->fileHandle = null;
        unlink($this->logFileName($this->logSN));
        if ($this->logSN == 999999) {
            $this->logSN = 1;
        } else {
            $this->logSN++;
        }
        $this->logPos = 0;
        $this->saveRelayInfo();
    }
    protected function logFileName($serialNumber, $prefix = 'Client')
    {
        return $this->logPath . "/$prefix-" . str_pad($serialNumber, 6, 0, STR_PAD_LEFT) . ".log";
    }

    protected function saveRelayInfo($force = true)
    {
        if (!$force && $this->lastSaveTime == time()) {
            return;
        }
        $this->lastSaveTime = time();
        if (!file_exists($this->logPath)) {
            mkdir($this->logPath, 0777, true);
        }
        file_put_contents('logs/info.relay', json_encode(array(
            'logSN' => $this->logSN,
            'logPos' => $this->logPos,
        )));
    }

    /**
     * loadRelayInfo
     * @return void
     */
    protected function loadRelayInfo()
    {
        if (file_exists('logs/info.relay')) {
            try {
                $content = file_get_contents('logs/info.relay');
                $info = json_decode($content, true);
            } catch (\Throwable $th) {
            }
            $this->logSN = isset($info['logSN']) ? $info['logSN'] : 1;
            $this->logPos = isset($info['logPos']) ? $info['logPos'] : 0;
        } else {
            $this->logSN = 1;
            $this->logPos = 0;
        }
        $this->saveRelayInfo();
    }
}
