<?php

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

if(!defined("SIGKILL")) {
    define("SIGKILL", 9); //定义强制结束进程信号ID
}

if(!defined("SIGTERM")) {
    define("SIGTERM", 15); //结束进程信号ID
}

if(!defined("SIGUSR1")) {
    define("SIGUSR1", 10); //重启进程信号ID
}

class HttpServer
{
    protected $config = []; //配置

    protected $host;

    protected $port; //监听端口

    protected $pid_file;

    protected $prefix = ''; //配置前缀

    protected $option = [
    ];

    protected $server = null;

    /**
     * @var \Illuminate\Contracts\Http\Kernel
     */
    protected $kernel;

    protected $app;

    public function __construct($envFile = '', $prefix = '')
    {
        $this->prefix = strtolower($prefix);
        $this->initConfig($envFile);
    }

    public function handle($params)
    {
        if (isset($params[1])) {
            $command = $params[1];
        } else {
            $command = 'status';
        }
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

    protected function initConfig($envFile)
    {
        $this->loadEnv($envFile);
        $this->pid_file = $this->getConfig('pid_file');
        $this->config['host'] = $this->getConfig('host', '127.0.0.1');
        $this->config['port'] = $this->getConfig('port', '9501');

        $daemonize = (bool) $this->getConfig('daemonize', false);
        $this->option['daemonize'] = $daemonize;

        $document_root = $this->getConfig('document_root');
        if ($document_root) {
            $this->option['document_root'] = $document_root;
            $this->option['enable_static_handler'] = true;
        }

        $log_file = $this->getConfig('log_file');
        if ($log_file) {
            $this->option['log_file'] = $log_file;
        }

        $log_level = $this->getConfig('log_level', 0);
        $this->option['log_level'] = $log_level;

        $worker_num = $this->getConfig('worker_num', 4);
        if (!is_null($worker_num)) {
            $this->option['worker_num'] = $worker_num;
        }
    }

    protected function loadEnv($envFile)
    {
        if (file_exists($envFile)) {
            $this->config = array_change_key_case(parse_ini_file($envFile, true, true), CASE_LOWER);
        } else {
            $this->config = [];
        }
    }

    protected function getConfig($key, $default = null)
    {
        $abs_key = $this->prefix . $key;
        return array_key_exists($abs_key, $this->config) ? $this->config[$abs_key] : $default;
    }

    public function start()
    {
        if ($this->isRunning()) {
            echo "运行中 {$this->getConfig('daemonize')}...\n";
            exit(0);
        }

        $tryTimes = 0;
        while (true) {
            $status = $this->checkPort($this->config['host'], $this->config['port']);
            if (!$status || $tryTimes > 3) {
                break;
            }
            $tryTimes++;
            sleep(1);
        }

        $this->server = new Server($this->config['host'], $this->config['port'], SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        if ($this->option) {
            $this->server->set($this->option);
        }

        $this->server->on('request', [$this, 'onRequest']);
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('workerStart', [$this, 'onWorkerStart']);

        $processes = $this->getProcesses($this->server);
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

    public function onStart(Server $server)
    {
        $this->pid($server->master_pid);
        $log = sprintf("Http 服务已启动 pid is %s", $server->master_pid);
        echo $log . PHP_EOL;
    }

    public function onWorkerStart() {
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        file_put_contents('php://stdout', 'worker start' . PHP_EOL, FILE_APPEND);
        $this->app = require __DIR__ . '/bootstrap/app.php';
        \Illuminate\Http\Request::enableHttpMethodParameterOverride();
    }

    public function onRequest(Request $request, Response $response) {
        $laravelRequest = $this->parseRequest($request);
        file_put_contents("php://stdout", sprintf("[%s] %s %s %s\n", date('Y-m-d H:i:s'), $laravelRequest->getClientIp(), $laravelRequest->getMethod(), $laravelRequest->getRequestUri()));
        $sResponse = $this->app->handle($laravelRequest);
        $this->parseResponse($response, $sResponse, $laravelRequest);
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

        $sRequest = new \Symfony\Component\HttpFoundation\Request($get, $post, [], $cookie, $files, $server);

        if (0 === strpos($sRequest->headers->get('CONTENT_TYPE'), 'application/x-www-form-urlencoded')
            && in_array(strtoupper($sRequest->server->get('REQUEST_METHOD', 'GET')), array('PUT', 'DELETE', 'PATCH'))
        ) {
            parse_str($sRequest->getContent(), $data);
            $sRequest->request = new \Symfony\Component\HttpFoundation\ParameterBag($data);
        }
        return \Illuminate\Http\Request::createFromBase($sRequest);
    }

    /**
     * @param Response $response
     * @param Object $request
     * @param \Symfony\Component\HttpFoundation\Response $laravelResponse
     * @throws
     */
    protected function parseResponse(Response $response, $laravelResponse, $request)
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
        } elseif ($laravelResponse instanceof Illuminate\View\View) {
            $content = $laravelResponse->render();
        } else {
            $content = $laravelResponse;
        }
        $response->end($content);
    }

    public function isRunning()
    {
        return file_exists(sprintf('/proc/%s', $this->pid()));
    }

    /**
     * @return \Swoole\Process[]
     */
    protected function getProcesses()
    {
        $processes = [];
        return $processes;
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
     * 检查款口是否可用
     * @param string $host
     * @param int $port
     * @return bool
     */
    protected function checkPort($host, $port)
    {
        try{
            $file = @fsockopen($host, $port, $errno, $errstr);
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
}


$server = new HttpServer(dirname(__FILE__) . '/.env', 'SWOOLE_HTTP_');
$server->handle($argv);