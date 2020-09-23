<?php

namespace Replication;

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;

/**
 * Replication/Client
 * @version 1.0.0
 */
class Client
{

    public $allowPull = false;
    public $logLevel = LOG_INFO;
    public $logSN = null;
    public $logPos = null;


    /**
     * Replication server ip.
     * @var string
     */
    protected $serverIP = null;

    /**
     * Replication server port.
     * @var int
     */
    protected $serverPort = null;

    /**
     * Ping timer.
     * @var Timer
     */
    protected $pingTimer = null;

    /**
     * Pull timer.
     * @var Timer
     */
    protected $pullTimer = null;

    protected $connected = false;



    protected $password = 'password';

    protected $serverSide = false;

    protected $pingTime = null;

    protected $serverId = null;
    protected $isPulling = false;
    protected $logPath = 'logs';
    protected $fileHandles = array();

    /**
     * Construct.
     * @param string $ip
     * @param int $port
     */
    public function __construct($serverId, $ip, $port = 19288, $password = 'password', $serverSide = false)
    {
        $this->serverId = $serverId;
        $this->serverIP = $ip;
        $this->serverPort = $port;
        $this->password = $password;
        $this->serverSide = $serverSide;
        $this->loadClientInfo();
    }

    /**
     * Connect to Replication server
     * @param string $ip
     * @param int $port
     * @return void
     */
    public function connect()
    {
        $this->connection = new AsyncTcpConnection('frame://' . $this->serverIP . ':' . $this->serverPort);
        $this->connection->onClose = function () {
            $this->onRemoteClose();
        };
        $this->connection->onConnect = function () {
            $this->onRemoteConnect();
        };
        $this->connection->onMessage = function ($connection, $data) {
            $this->onRemoteMessage($connection, $data);
        };
        $this->connection->connect();
    }

    /**
     * onRemoteClose.
     * @return void
     */
    protected function onRemoteClose()
    {
        $this->connected = false;
        $this->logger(LOG_WARNING, "Warning Replication connection closed and try to reconnect");
        $this->stopPing();
        $this->stopPull();
        $this->connection->reconnect(1);
    }

    /**
     * onRemoteConnect.
     * @return void
     */
    protected function onRemoteConnect()
    {
        $this->pingTime = time();
        $this->authencation();
        $this->startPing();
        $this->startPull();
        $this->connected = true;
    }

    /**
     * onRemoteMessage.
     * @param TcpConnection $connection
     * @param string $data
     * @throws \Exception
     */
    protected function onRemoteMessage($connection, $data)
    {
        $this->pingTime = time();

        $data = unserialize($data);
        $type = $data['type'];
        // var_dump($data);

        switch ($type) {
            case 'setPos':
                if ($this->logSN && $this->logSN != $data['logSN']) {
                    $logFile = $this->logFileName($this->logSN, "Client");
                    fclose($this->fileHandles[$logFile]);
                    unset($this->fileHandles[$logFile]);
                }
                $this->logSN = $data['logSN'];
                $this->logPos = $data['logPos'];
                $this->isPulling = false;
                break;
            case 'push':
                if ($data['bytes']) {
                    $this->logger(LOG_INFO, "获取到 " . $data['pos'] . ' - ' . $data['bytes'] . " 字节 " . $data['count'] . " 条日志");
                    $this->logPos = $data['pos'] + $data['bytes'];
                    $logFile = $this->logFileName($this->logSN, "Client");
                    if (!isset($this->fileHandles[$logFile])) {
                        $this->fileHandles[$logFile] = fopen($logFile, file_exists($logFile) ? 'r+' : 'w');
                    }
                    fseek($this->fileHandles[$logFile], $data['pos']);
                    fwrite($this->fileHandles[$logFile], $data['data']);

                    $this->saveClientInfo();

                    $this->send(array(
                        "type" => "pull",
                        "logSN" => $this->logSN,
                        "logPos" => $this->logPos
                    ));
                } else {
                    $this->isPulling = false;
                }
                break;
        }
    }

    /**
     * Ping.
     * @return void
     */
    protected function ping()
    {
        $this->send(array(
            "type" => "ping"
        ));
    }

    /**
     * Send.
     * @return void
     */
    protected function send($data)
    {
        if ($this->connected) {
            $this->connection->send(serialize($data));
        }
    }

    /**
     * Authencation.
     * @return void
     */
    protected function authencation()
    {
        $this->logger(LOG_INFO, "主控 连接成功，开始认证");
        $this->connection->send(serialize(array(
            "type" => "auth",
            "password" => $this->password,
            "id" => $this->serverId,
            "logSN" => $this->logSN,
            "logPos" => $this->logPos,
            "serverSide" => $this->serverSide,
        )));
    }

    /**
     * Start ping.
     * @return void
     */
    protected function startPing()
    {
        $this->logger(LOG_INFO, "√ 心跳包定时器");
        $this->pingTimer = Timer::add(30, function () {
            $expTime = time() - 65;
            if ($this->pingTime < $expTime) {
                $this->logger(LOG_INFO, "心跳 超时，断开连接");
                $this->connection->close();
            } else {
                $this->logger(LOG_INFO, "发送心跳包");
                $this->ping();
            }
        });
    }

    /**
     * Stop ping.
     * @return void
     */
    protected function stopPing()
    {
        if ($this->pingTimer) {
            Timer::del($this->pingTimer);
            $this->pingTimer = null;
            $this->logger(LOG_INFO, "心跳包计时器 已删除");
        }
    }

    /**
     * Start pull.
     * @return void
     */
    public function startPull()
    {
        $this->logger(LOG_INFO, "√ 请求日志计数器");
        $this->pullTimer = Timer::add(1, function () {
            if ($this->allowPull && !$this->isPulling && $this->logSN != null && $this->logPos !== null) {
                $this->isPulling = true;
                $this->send(array(
                    "type" => "pull",
                    "logSN" => $this->logSN,
                    "logPos" => $this->logPos,
                ));
            }
        });
    }

    /**
     * Stop pull.
     * @return void
     */
    public function stopPull()
    {
        if ($this->pullTimer) {
            Timer::del($this->pullTimer);
            $this->pullTimer = null;
            $this->isPulling = false;
            $this->logger(LOG_INFO, "获取日志计时器 已删除");
        }
    }



    /**
     * Send log.
     * @param string $data
     */
    public function log($data)
    {
        if ($this->connected && $data) {
            $this->send(array('type' => 'log', 'data' => $data));
            return true;
        } else {
            return false;
        }
    }

    /**
     * Print log.
     * @param string $level
     * @param string $str
     */
    protected function logger($level, $str)
    {
        if ($this->logLevel >= $level) {
            echo date("Y-m-d H:i:s ") . "- $str\n";
        }
    }

    protected function logFileName($serialNumber, $prefix = 'Client')
    {
        return $this->logPath . "/$prefix-" . str_pad($serialNumber, 6, 0, STR_PAD_LEFT) . ".log";
    }

    protected function saveClientInfo()
    {
        if (!file_exists($this->logPath)) {
            mkdir($this->logPath, 0777, true);
        }
        file_put_contents('logs/info.client', json_encode(array(
            'logSN' => $this->logSN,
            'logPos' => $this->logPos,
        )));
    }

    /**
     * loadClientInfo
     * @return void
     */
    protected function loadClientInfo()
    {
        if (file_exists('logs/info.client')) {
            try {
                $content = file_get_contents('logs/info.client');
                $info = json_decode($content, true);
            } catch (\Throwable $th) {
            }
            $this->logSN = isset($info['logSN']) ? $info['logSN'] : 1;
            $this->logPos = isset($info['logPos']) ? $info['logPos'] : 0;
        } else {
            $this->logSN = 1;
            $this->logPos = 0;
        }
        $this->saveClientInfo();
    }
}
