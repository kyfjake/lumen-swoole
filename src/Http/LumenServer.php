<?php

namespace KYF\LumenSwoole\Http;

use Swoole\Http\Server as SwooleServer;
use Swoole\Http\Request;
use Swoole\Http\Response;
use \Exception;

// define force kill signal
if(!defined("SIGKILL")) {
    define("SIGKILL", 9);
}

// define soft kill signal
if(!defined("SIGTERM")) {
    define("SIGTERM", 15);
}

// define reload signal
if(!defined("SIGUSR1")) {
    define("SIGUSR1", 10);
}

if (!defined('SWOOLE_LOG_INFO')) {
    define('SWOOLE_LOG_INFO', 2);
}

if (!defined('SWOOLE_LOG_NOTICE')) {
    define('SWOOLE_LOG_NOTICE', 3);
}

if (!defined('SWOOLE_LOG_WARNING')) {
    define('SWOOLE_LOG_WARNING', 4);
}

if (!defined('SWOOLE_LOG_ERROR')) {
    define('SWOOLE_LOG_ERROR', 5);
}


class LumenServer
{
    /**
     * @var string Lumen项目根目录
     */
    public static $ROOT_PATH;

    public static $options = 'h:p:n:f:d';

    public static $long_opt = [
        'queue_num:',
        'log_file:',
        'document_root:',
        'start',
    ];

    protected $optMap = [
        'h' => 'host',
        'p' => 'port',
        'n' => 'worker_num',
        'd' => 'daemonize',
        'queue_num' => 'queue_num',
        'log_file' => 'log_file',
        'v' => 'verbose',
    ];

    protected $host = '127.0.0.1';

    protected $port = 8080; //监听端口

    protected $pid_file = '/tmp/swoole_http.pid';

    protected $prefix = ''; //配置前缀

    protected $queue_num = 0;

    protected $option = [
        'worker_num' => 8,
        'daemonize' => false,
        'log_level' => SWOOLE_LOG_WARNING,
        'log_file' => 'swoole.log',
        'max_request' => 1000,
        'document_root' => null,
        'enable_static_handler' => true,
    ];

    protected $server = null;

    /**
     * @var int 最后一次请求时间
     */
    protected $last_init_time = 0;

    /**
     * @var int db连接超时时间 超过1200s重置db连接
     */
    protected $keep_time_alive = 1200;

    /**
     * @var \Illuminate\Contracts\Http\Kernel
     */
    protected $kernel;

    /**
     * @var \Laravel\Lumen\Application
     */
    protected $app;

    /**
     * LumenServer constructor.
     * @param $config
     * @throws Exception
     */
    public function __construct($config)
    {
        if (is_array($config)) {
            $this->parseOptions($config);
        }
    }

    /**
     * 解析参数
     * @param $options
     * @throws Exception
     */
    public function parseOptions($options)
    {
        foreach ($options as $k => $option) {
            if (array_key_exists($k, $this->optMap)) {
                $this->setOption($this->optMap[$k], $option);
            } else {
                throw new Exception(sprintf("Unknown Param %s", $k));
            }
        }
    }

    public function run($command = 'status')
    {
        switch ($command) {
            case 'start':
                $this->start();
                break;
            case 'stop':
                $this->stop();
                break;
            case 'reload':
                $this->reload();
                break;
            case 'restart':
                $this->restart();
                break;
            case 'status':
            default:
                $this->status();
                break;
        }
    }

    public function start()
    {
        if ($this->isRunning()) {
            echo "运行中 Pid: {$this->pid()} ...\n";
            exit(0);
        }

        $tryTimes = 0;
        while (true) {
            $status = $this->checkPort($this->host, $this->port);
            if (!$status || $tryTimes > 3) {
                break;
            }
            $tryTimes++;
            sleep(1);
        }

        $this->server = new SwooleServer($this->host, $this->port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        if ($this->option) {
            $this->server->set($this->option);
        }

        $this->server->on('request', [$this, 'onRequest']);
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('workerStart', [$this, 'onWorkerStart']);

        $processes = $this->getProcesses();
        foreach ($processes as $process) {
            $this->server->addProcess($process);
        }

        $this->server->start();
    }

    public function stop()
    {
        $pid = (int) $this->pid();
        if ($pid) {
            echo sprintf("pid is ... %d\n", $pid);
            if (file_exists(sprintf("/proc/%d", $pid))) {
                $res = posix_kill($pid, SIGTERM);
                echo $res ? "结束运行成功\n" : "结束运行失败\n";
            } else {
                echo "进程已异常结束\n";
            }

            $this->pid(0);
        } else {
            echo "未运行\n";
        }
    }

    public function reload()
    {
        $pid = (int) $this->pid();
        if ($pid) {
            echo sprintf("pid is ... %d\n", $pid);
            if ($this->isRunning()) {
                $res = posix_kill($pid, SIGUSR1);
                echo $res ? "reload成功\n" : "reload失败\n";
            } else {
                echo "进程已异常结束\n";
            }
        }
    }

    public function restart()
    {
        $this->stop();
        $this->start();
    }

    public function status()
    {
        $pid = $this->pid();
        if ($pid) {
            if ($this->isRunning()) {
                echo "运行中 pid " . $pid . "...\n";
                exit(0);
            } else {
                echo $pid . "异常结束!\n";
                exit(1);
            }
        }

        echo "未运行\n";
        exit(1);
    }

    public function onStart(SwooleServer $server)
    {
        $this->pid($server->master_pid);
        $log = sprintf("Http 服务已启动 pid is %s", $server->master_pid);
        echo $log . PHP_EOL;
    }

    /**
     * init worker
     * @param SwooleServer $server
     * @throws Exception
     */
    public function onWorkerStart(SwooleServer $server) {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        swoole_set_process_name("php lumen-server worker " . $server->worker_id);
        $this->app = $this->getApp();
        $this->last_init_time = time();
    }

    /**
     * handle request
     * @param Request $request
     * @param Response $response
     */
    public function onRequest(Request $request, Response $response) {
        $this->clearAuth();
        $this->checkDbConnection();
        \Illuminate\Http\Request::enableHttpMethodParameterOverride();
        $laravelRequest = $this->parseRequest($request);
        $sResponse = $this->app->handle($laravelRequest);
        $this->parseResponse($response, $sResponse);
    }

    protected function parseRequest(Request $request) {
        $get = $request->get ? $request->get : [];
        $post = $request->post ? $request->post : [];
        $files = $request->files ? $request->files : [];
        foreach ($files as $key => $file) {
            if (isset($file['size']) && $file['size'] == 0) {
                unset($files[$key]);
            }
        }
        $cookie = $request->cookie ? $request->cookie : [];
        $server = array_change_key_case($request->server ? $request->server : [], CASE_UPPER);
        $headers = [];
        foreach ($request->header as $key => $value) {
            $headers['HTTP_' . strtoupper($key)] = $value;
        }
        $server = array_merge($server, $headers);

        $sRequest = new \Symfony\Component\HttpFoundation\Request($get, $post, [], $cookie, $files, $server, $request->rawContent());

        if (0 === strpos($sRequest->headers->get('CONTENT_TYPE'), 'application/x-www-form-urlencoded')
            && in_array(strtoupper($sRequest->server->get('REQUEST_METHOD', 'GET')), array('PUT', 'DELETE', 'PATCH'))
        ) {
            parse_str($sRequest->getContent(), $data);
            $sRequest->request = new \Symfony\Component\HttpFoundation\ParameterBag($data);
        }
        return \Illuminate\Http\Request::createFromBase($sRequest);
    }

    /**
     * 解析响应内容
     * @param Response $response
     * @param \Symfony\Component\HttpFoundation\Response $laravelResponse
     * @throws
     */
    protected function parseResponse(Response $response, $laravelResponse)
    {
        if ($laravelResponse instanceof \Symfony\Component\HttpFoundation\Response) {
            $response->status($laravelResponse->getStatusCode());
            foreach ($laravelResponse->headers->allPreserveCaseWithoutCookies() as $key => $values) {
                foreach ($values as $value) {
                    $response->header($key, $value, true);
                }
            }

            foreach ($laravelResponse->headers->getCookies() as $cookie) {
                if ($cookie->isRaw()) {
                    $response->rawCookie($cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly());
                } else {
                    $response->cookie($cookie->getName(), $cookie->getValue(), $cookie->getExpiresTime(), $cookie->getPath(), $cookie->getDomain(), $cookie->isSecure(), $cookie->isHttpOnly());
                }
            }

            $content = $laravelResponse->getContent();
        } elseif ($laravelResponse instanceof \Illuminate\View\View) {
            $content = $laravelResponse->render();
        } else {
            $content = $laravelResponse;
        }
        $response->end($content);
        $this->clearAuth();
    }

    protected function clearAuth()
    {
        /**
         * 每次请求结束重新初始化登录态，解决登录态混乱问题
         */
        if (isset($this->app['auth.loaded']) && $this->app['auth.loaded']) {
            $this->app['auth.loaded'] = false;
            \Illuminate\Support\Facades\Facade::clearResolvedInstance('auth');
            (new \Illuminate\Auth\AuthServiceProvider($this->app))->register();
            (new \App\Providers\AuthServiceProvider($this->app))->boot();
        }
    }

    /**
     * 自动重新连接db
     */
    protected function checkDbConnection()
    {
        if ($this->app && isset($this->app['db']) && time() - $this->last_init_time >= $this->keep_time_alive) {
            $connections = $this->app['db']->getConnections();
            foreach ($connections as $name => $connection) {
                $this->app['db']->reconnect($name);
            }
            $this->last_init_time = time();
        }
    }

    /**
     * 判断是否正在运行
     * @return bool
     */
    public function isRunning()
    {
        return file_exists(sprintf('/proc/%s', $this->pid()));
    }

    /**
     * 获取其它进程
     * @return \Swoole\Process[]
     */
    protected function getProcesses()
    {
        $processes = [];
        if ($this->queue_num > 0) {
            $processes = $this->getQueueProcess($this->queue_num);
        }

        return $processes;
    }

    /**
     * 生成多个队列处理进程
     * @param int $number
     * @return \Swoole\Process[]
     */
    protected function getQueueProcess($number = 1)
    {
        if ($number == 1) {
            return [new \Swoole\Process(function (\Swoole\Process $process) {
                $process->name('queue:work-' . $process->id);
                $app = $this->getApp();
                /**
                 *@var \Laravel\Lumen\Console\Kernel $kernel
                 */
                $kernel = $app->make(\App\Console\Kernel::class);
                echo "Queue Work Start:...\n";
                $kernel->handle(new \Symfony\Component\Console\Input\ArgvInput(['artisan', 'queue:work']), new \Symfony\Component\Console\Output\ConsoleOutput());
            }, false, true)];
        } else {
            $processes = [];
            for ($i = 0; $i < $number; $i++) {
                $processes[] = $this->getQueueProcess()[0];
            }

            return $processes;
        }
    }

    /**
     * set/get server pid
     * @param integer|null $id
     * @return integer | null
     */
    protected function pid($id = NULL)
    {
        $path = $this->pid_file;
        if (!is_null($id) && !empty($path)) {
            file_put_contents($path, (int)$id);
        } else {
            if (file_exists($path)) {
                $id = (int)file_get_contents($path);
            } else {
                $id = 0;
            }
        }

        return $id;
    }

    /**
     * 检查端口是否可用
     * @param string $host
     * @param int $port
     * @return bool
     */
    protected function checkPort($host, $port)
    {
        try{
            $file = @fsockopen($host, $port, $errno, $err_str);
            if (!$file) {
                return false;
            } else {
                fclose($file);
                return true;
            }
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 设置日志输出等级
     */
    public function setVerbose()
    {
        $this->option['log_level'] = SWOOLE_LOG_INFO;
    }

    /**
     * 设置为以daemon方式运行
     */
    public function setDaemonize()
    {
        $this->option['daemonize'] = true;
    }

    /**
     * 设置队列进程数量
     * @param int $number
     */
    public function setQueue_num($number)
    {
        $this->queue_num = $number;
    }

    public function setOption($name, $value)
    {
        if (property_exists($this, $name)) {
            $this->{$name} = $value;
        } elseif (method_exists($this, 'set' . ucfirst($name))) {
            call_user_func([$this, 'set'.ucfirst($name)], $name);
        } elseif (array_key_exists($name, $this->option)) {
            $this->option[$name] = $value;
        }
    }

    /**
     * @return \Laravel\Lumen\Application
     */
    protected function getApp()
    {
        /**
         * @var \Laravel\Lumen\Application $app
         */
        $app = require LUMEN_ROOT . '/bootstrap/app.php';

        return $app;
    }
}