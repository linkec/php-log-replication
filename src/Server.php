<?php

namespace Replication;

use Workerman\Worker;
use Workerman\Lib\Timer;

class Server
{
    /**
     * Worker instance.
     * @var Worker
     */
    protected $_worker = null;

    /**
     * Log level
     * LOG_CRIT
     * LOG_ERR
     * LOG_INFO
     * LOG_DEBUG
     * @var Worker
     */
    protected $logLevel = LOG_DEBUG;

    /**
     * Log serial number
     * @var Worker
     */
    protected $logSerial = 1;

    /**
     * Log file handles
     * @var Worker
     */
    protected $logFileHandles = array();

    /**
     * Log Path
     */
    protected $logPath = 'logs';

    /**
     * Log Path
     */
    protected $logSizeLimit = 200 * 1024 * 1024;

    protected $password = 'password';

    /**
     * Construct.
     * @param string $ip
     * @param int $port
     */
    public function __construct($ip = '0.0.0.0', $port = 19288)
    {
        $worker = new Worker("frame://$ip:$port");
        $worker->count = 1;
        $worker->name = 'Replication Server';
        $worker->onWorkerStart = array($this, 'onWorkerStart');
        $worker->onConnect = array($this, 'onConnect');
        $worker->onMessage = array($this, 'onMessage');
        $worker->onClose = array($this, 'onClose');
        $this->_worker = $worker;
    }

    /**
     * onClose
     * @return void
     */
    public function onWorkerStart($worker)
    {
        // 恢复上一次的日志记录文件
        $this->loadServerInfo();
        // 检查日志文件
        $this->checkLogFile();
        // 初始化日志句柄
        $this->initLogFile();
        // 定时检测心跳包
        Timer::add(30, function () {
            $expTime = time() - 65;
            foreach ($this->_worker->connections as $connection) {
                if ($connection->pingTime < $expTime) {
                    $connection->close();
                }
            }
        });
        // 执行日志清理
        Timer::add(3600, function () {
            $this->checkLogFile();
            $this->cleanLogFile();
        });
    }

    /**
     * onConnect
     * @return void
     */
    public function onConnect($connection)
    {
        // 心跳时间
        $connection->pingTime = time();
        // 认证类型
        $connection->auth = false;
        $connection->serverId = null;
        $connection->fileHandles = [];
        $connection->logSN = null;
        $connection->logPos = null;
    }

    public function onMessage($connection, $data)
    {

        if (!$data) {
            return;
        }
        $connection->pingTime = time();

        $data = unserialize($data);
        $type = $data['type'];

        if (!$connection->auth && !in_array($type, ['auth', 'ping'])) {
            return;
        }
        switch ($type) {
            case 'ping':
                $this->log(LOG_INFO, "接收到心跳包");
                $connection->send(serialize(array("type" => "pong")));
                break;
            case 'auth': // 认证
                $this->log(LOG_INFO, "进行节点认证");
                if (isset($data['password']) && $data['password'] == $this->password) {
                    $connection->auth = true;
                    $connection->serverId = $data['id'];
                    if ($data['serverSide'] && isset($this->clientInfo[$data['id']])) { // 主服 控制同步信息
                        $connection->logSN = $this->clientInfo[$data['id']]['logSN'];
                        $connection->logPos = $this->clientInfo[$data['id']]['logPos'];
                    } else {
                        $connection->logSN = $data['logSN'];
                        $connection->logPos = $data['logPos'];
                    }
                    $connection->send(serialize(array(
                        "type" => "setPos",
                        "logSN" => $connection->logSN,
                        "logPos" => $connection->logPos
                    )));
                } else {
                    $this->log(LOG_INFO, "节点认证失败");
                    $connection->close(serialize(array(
                        "type" => "authFailed"
                    )));
                }
                break;
            case 'pull':
                $this->log(LOG_INFO, "节点请求推送日志 {$data['logSN']} , {$data['logPos']}");
                $logFile = $this->logFileName($data['logSN']);
                $_logFile = $this->logFileName($this->logSerial);
                if (!file_exists($logFile)) {
                    $this->log(LOG_INFO, "节点请求的日志文件不存在");
                    $connection->close(serialize(array(
                        "type" => "logNotExists"
                    )));
                    return;
                }
                if ($logFile != $_logFile  && $data['logPos'] == filesize($logFile)) {
                    $this->log(LOG_INFO, "切换日志文件");
                    $connection->logSN = $connection->logSN == 999999 ? 1 : $connection->logSN + 1;
                    $connection->logPos = 0;
                    $connection->send(serialize(array(
                        "type" => "setPos",
                        "logSN" => $connection->logSN,
                        "logPos" => $connection->logPos
                    )));

                    if (isset($connection->fileHandles[$logFile])) {
                        fclose($connection->fileHandles[$logFile]);
                        unset($connection->fileHandles[$logFile]);
                    }
                    return;
                }
                if (!isset($connection->fileHandles[$logFile])) {
                    $connection->fileHandles[$logFile] = fopen($logFile, 'r');
                }
                fseek($connection->fileHandles[$logFile], $data['logPos']);

                $count = 0;
                $bytes = 0;
                $pkg = "";

                do {
                    $head = fread($connection->fileHandles[$logFile], 12);
                    // var_dump(bin2hex($head));
                    if ($head) {
                        $_tmp = unpack('Nlength', substr($head, 8));
                        $size = $_tmp['length'];
                        $pkg .= $head . ($size == 0 ? '' : fread($connection->fileHandles[$logFile], $size));
                        $bytes += (12 + $size);
                        $count++;
                    }
                } while ($head && $bytes < 512 * 1024);
                $this->log(LOG_INFO, "$bytes Bytes 将被发送");
                $connection->send(serialize(array(
                    "type" => "push",
                    "pos" => $data['logPos'],
                    "count" => $count,
                    "data" => $pkg,
                    "bytes" => $bytes
                )));
                break;
            case 'log':
                // $this->log(LOG_INFO, "写入日志");
                $time = time();
                $serverId = $connection->serverId;
                $length = strlen($data['data']);
                if ($length == 0) {
                    return;
                }
                $pkg = pack('N', $time) . pack('N', $serverId) . pack('N', $length) . $data['data'];
                // $pkg = pack('N', $time) . pack('N', $serverId) . pack('N', $length) . $data['data'];

                $logFile =  $this->logFileName($this->logSerial);

                $seek = ftell($this->logFileHandles[$logFile]);
                if ($seek + $length > $this->logSizeLimit) {
                    $this->checkLogFile();
                    $logFile =  $this->logFileName($this->logSerial);
                }
                fwrite($this->logFileHandles[$logFile], $pkg);
                break;
        }
    }
    /**
     * onClose
     * @return void
     */
    public function onClose($connection)
    {
    }

    /**
     * checkLogFile
     * @return void
     */
    protected function checkLogFile()
    {

        $logFile = $this->logFileName($this->logSerial);

        clearstatcache();

        if (file_exists($logFile)) {

            if (filesize($logFile) >= $this->logSizeLimit) {

                if ($this->logSerial == 999999) {
                    $oldLogFile = $this->logFileName($this->logSerial);
                    $this->logSerial = 1;
                    $newLogFile = $this->logFileName($this->logSerial);
                } else {
                    $oldLogFile = $this->logFileName($this->logSerial);
                    $logSerial = $this->logSerial + 1;
                    $newLogFile = $this->logFileName($logSerial);
                }

                // 打开新的日志文件
                $this->logFileHandles[$newLogFile] = fopen($newLogFile, 'a+');
                $this->logSerial++;

                // 关闭旧的日志文件
                $endTag = pack('N', time()) . pack('N', 0) . pack('N', 0);
                fwrite($this->logFileHandles[$oldLogFile], $endTag);
                fclose($this->logFileHandles[$oldLogFile]);
                unset($this->logFileHandles[$oldLogFile]);

                $this->saveServerInfo();
            }
        } else {
            $this->logFileHandles[$logFile] = fopen($logFile, 'a+');
        }
    }

    /**
     * saveServerInfo
     * @return void
     */
    protected function saveServerInfo()
    {
        if (!file_exists($this->logPath)) {
            mkdir($this->logPath, 0777, true);
        }
        file_put_contents('logs/info.server', json_encode(array(
            'logSerial' => $this->logSerial
        )));
    }

    /**
     * loadServerInfo
     * @return void
     */
    protected function loadServerInfo()
    {
        if (file_exists('logs/info.server')) {
            try {
                $content = file_get_contents('logs/info.server');
                $info = json_decode($content, true);
            } catch (\Throwable $th) {
            }
            $this->logSerial = isset($info['logSerial']) ? $info['logSerial'] : 1;
        } else {
            $this->logSerial = 1;
        }
        $this->saveServerInfo();
    }

    /**
     * initLogFile
     * @return void
     */
    protected function initLogFile()
    {
        $logFile = $this->logFileName($this->logSerial);
        $this->logFileHandles[$logFile] = fopen($logFile, 'a+');
    }

    protected function cleanLogFile()
    {
        $cleanFiles = array();
        $keepLogFiles = 7;
        // if ($this->logSerial > $keepLogFiles) {
        //     for ($i = $this->logSerial - $keepLogFiles; $i > $this->logSerial - $keepLogFiles - $keepLogFiles + 1; $i--) {
        //         $logFile = $this->logFileName($i);
        //         $cleanFiles[] =  $logFile;
        //     }
        // } elseif ($this->logSerial <= $keepLogFiles) {
        //     for ($i = $this->logSerial + 999999; $i > $this->logSerial + 999999 - $keepLogFiles - $keepLogFiles + 1; $i--) {
        //         $logFile = $this->logFileName($i);
        //         $cleanFiles[] =  $logFile;
        //     }
        // }
        foreach (glob($this->logPath . "/Server-*.log") as $logFile) {
            if (time() - filemtime($logFile) > $keepLogFiles * 86400) {
                $cleanFiles[] =  $logFile;
            }
        }
        foreach ($cleanFiles as $logFile) {
            // 轮询连接池中的日志文件，关闭之
            foreach ($this->_worker->connections as $connection) {
                if (isset($connection->fileHandles[$logFile])) {
                    fclose($connection->fileHandles[$logFile]);
                }
            }
            file_exists($logFile) && unlink($logFile);
        }
    }

    protected function logFileName($serialNumber, $prefix = 'Server')
    {
        return $this->logPath . "/$prefix-" . str_pad($serialNumber, 6, 0, STR_PAD_LEFT) . ".log";
    }

    protected function log($level, $str)
    {
        if ($this->logLevel >= $level) {
            echo date("Y-m-d H:i:s ") . "- $str\n";
        }
    }
}
