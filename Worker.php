<?php

class Worker
{

    public static $log_file = '';

    //master进程的进程ID保存文件
    public static $pid_file = '';

    //worker进程的进程状态保存文件
    public static $status_file = '';

    //是否守护进程模式
    public static $deamonize  = false;

    public static $master_pid = 0;

    //linux的垃圾筒
    public static $stdoutFile = '/dev/null';

    public static $workers = [];

    //worker实例
    public static $instance = null;

    //worker数量
    public $count = 2;

    //worker启动时的回调方法
    public $onWorkerStart = null;

    public function __construct(){
        static::$instance = $this;
    }

    public static function runAll()
    {
        static::checkEnv();
        static::init();
        static::parseCommand();
        static::deamonize();
        static::saveMasterPid();
        static::installSignal();

        //非调试模式，不是守护进程模式启动的时候，把标准输出重定向
        static::resetStd();

        static::forkWorker();//fork 子进程
    }

    public static function checkEnv()
    {
        if (php_sapi_name() !== 'cli') {
            exit('请使用命令行模式运行php!');
        }

        if (!function_exists('pcntl_fork')) {
            exit('请先安装pcntl扩展');
        }

        if (!function_exists('posix_kill')) {
            exit('请先安装posix扩展');
        }
    }

    public static function init()
    {
        $tmp_dir = __DIR__.'/tmp/';

        if (!is_dir($tmp_dir) && !mkdir($tmp_dir) ) {
            exit('启动失败, 没有权限创建运行时文件！');
        }

        $test_file = $tmp_dir.'test';

        if (touch($test_file)) {
            @unlink($test_file);
        } else {
            exit('你没有在'.$tmp_dir.'文件夹下创建文件的权限!');
        }

        if (empty(self::$status_file)) {
            static::$status_file = $tmp_dir . 'status_file.status';
        }

        if (empty(self::$log_file)) {
            static::$log_file = $tmp_dir . 'worker.log';
        }

        if (empty(self::$pid_file)) {
            static::$pid_file = $tmp_dir . 'master.pid';
        }

        static::log('初始化成功!');
    }

    public static function log($message)
    {
        $message = '['.date('Y-m-d H:i:s '). $message ."]\n";
        file_put_contents((string)self::$log_file, $message, FILE_APPEND|LOCK_EX);
    }

    public static function parseCommand()
    {
        global $argv;

        if (!isset($argv[1]) ||
            !in_array($argv[1], ['start', 'stop', 'status'])
        ) {
            exit('你缺省了进程启动的参数 start|status|stop|' . PHP_EOL);
        }

        $command1 = $argv[1]; //start, stop, status.....
        $command2 = $argv[2]; //-d,或者其他

        //检测master是不是在运行
        $master_id = @file_get_contents(static::$pid_file);

        $master_alive = $master_id && posix_kill($master_id,0);

        if ($master_alive) {
            if ($command1 == 'start' && posix_getpid() != $master_id) {
                exit('请不要重复启动谢谢!'.PHP_EOL);
            } else {
                if ($command1 !== 'start') {
                    exit('进程还没有启动呢!');
                }
            }
        }

        switch ($command1) {
            case 'start':

                $info = '你启动了程序';

                if ($command2 === '-d') {
                    static::$deamonize = true;
                    $info .= ',以守护进程模式';
                }

                echo $info.PHP_EOL;
                break;

            case 'stop':
                echo '请补全stop进程部分的逻辑!';
                exit(0);
                break;

            case 'status':
                echo '进程状态不可以查看，因为你还没有写查看进程状态的代码!';
                exit(0);
                break;

            default:
                exit('你只可以输入 start | stop | status');
                break;
        }

    }

    public static function deamonize()
    {
        if (static::$deamonize === false) {
            return true;
        }

        umask(0);

        $pid = pcntl_fork();

        if ($pid > 0 ) {
            exit(0);
        } elseif ($pid == 0) {
            if ( -1 === posix_setsid()) {
                throw new Exception('SET SID FAIL');
            }
            static::setProcessTitle('halwegworker:master');

        } else {
            throw new Exception('fork fail!');
        }

    }

    public static function setProcessTitle($title){
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }

    public static function saveMasterPid()
    {
        static::$master_pid = posix_getpid();
        if (false === @file_put_contents(static::$pid_file, static::$master_pid)) {
            throw new Exception('保存主进程 pid 失败!');
        }
    }

    public static function installSignal()
    {
        pcntl_signal(SIGINT, array(__CLASS__, 'signalHandler'), false);
        pcntl_signal(SIGUSR2, array(__CLASS__, 'signalHandler'), false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }

    public static function signalHandler($signal)
    {
        switch ($signal) {
            case SIGUSR2:
                //
                break;
            case SIGINT:
                //
                break;
            case SIGPIPE:
                //
                break;
        }

    }

    public static function resetStd()
    {
        if(static::$deamonize == false){
            return;
        }
        global $STDOUT, $STDERR;
        $handle = fopen(self::$stdoutFile, "a");
        if ($handle) {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(self::$stdoutFile, "a");
            $STDERR = fopen(self::$stdoutFile, "a");
        } else {
            throw new Exception('can not open stdoutFile ' . self::$stdoutFile);
        }
    }

    public static function forkWorkers()
    {
        $worker_count = static::$instance->count;
        var_dump($worker_count);
        exit();
    }

}