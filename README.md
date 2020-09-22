# 简介

** php-log-replication 是一个基于 [Workerman](http://github.com/walkor/Workerman) 的 分布式日志复制工具 **

类似于 Mysql 的 Binlog 同步模式


## Server
##### 建立一个日志接收服务器
通过这个服务器暴露的端口，可以使 Client 连接并认证
> 类似于 Mysql 中的 Master 的 Binlog_Dump_Thread

    <?php
	use Workerman\Worker;
	use Workerman\Timer;

	require 'Workerman/Autoloader.php';

	require_once __DIR__ . '/src/Server.php';

	// 不传参数默认是监听0.0.0.0:18512
	$server = new Replication\Server();
	// 设置认证密码
	$server->password = 'password';
	
	Worker::runAll();
    ?>
## Client
##### 使用 Client 与 Server 进行通讯，可以通过设置 allowPull 参数设置为 仅上报日志 或 日志拉取 模式
> ###### allowPull = true
会自动从 Server 拉取最新日志，类似于 Mysql 中的 Slaver 的 IO_Thread
###### allowPull = false
不会从 Server 拉取日志

任意模式都可以通过 API 进行日志上报 ，类似于 Mysql 中 在 Master 中 执行SQL语句 记录至 日志文件

	__construct($serverId, $ip, $port = 19288, $password = 'password', $serverSide = false)
Client 的构造参数如上
>需要特别注明的是 serverSide 模式 如果为 true，则忽略本地 logSN 和 logPos，而通过 Server端 来远程获取上一次成功拉取信息
logSN: 日志序列号 1 - 999999（对应为Server端的）默认为 1
logPos: 上一次日志写入位置（对应为Server端的）默认为 0

    <?php
	use Workerman\Worker;
	use Workerman\Timer;

	require 'Workerman/Autoloader.php';

	require_once __DIR__ . '/src/Client.php';

	$worker = new Worker();
	$worker->count = 1;
	$worker->name = 'IO Thread';
	$worker->onWorkerStart = function ($worker) {
		$client = new Replication\Client(2, '127.0.0.1');
		$client->allowPull = true;
		$client->connect();
	};
	
	
	$worker2 = new Worker();
	$worker2->count = 4;
	$worker2->name = 'Logger';
	$worker2->onWorkerStart = function ($worker) {
		$client = new Replication\Client(2, '127.0.0.1');
		$client->connect();
	     Timer::add(0.001, function () use ($client) {
	         global $i;
	         $i++;
	         $client->log($i);
	     });
	};
	
	Worker::runAll();
    ?>
	
## Relay
##### 使用 Relay 来回放日志内容，可以通过设置 onLog 属性来进行回调，！！需配合 allowPull = true 的 Client 使用

    <?php
	use Workerman\Worker;
	use Workerman\Timer;

	require 'Workerman/Autoloader.php';

	require_once __DIR__ . '/src/Client.php';

	$worker = new Worker();
	$worker->count = 1;
	$worker->name = 'IO Thread';
	$worker->onWorkerStart = function ($worker) {
		$client = new Replication\Client(2, '127.0.0.1');
		$client->allowPull = true;
		$client->connect();
	};
	
	
	$worker3 = new Worker();
	$worker3->count = 1;
	$worker3->name = 'Logger';
	$worker3->onWorkerStart = function ($worker) {
		$relay = new Replication\Relay(2);
		$relay->onLog = function ($data) {
			 var_dump($data);
		};
		$relay->start();
	};
	
	Worker::runAll();
    ?>

### 使用愉快 欢迎PR