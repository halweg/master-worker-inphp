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

    public static $status = 0;

    //运行中
    const STATUS_RUNNING = 1;
    //停止
    const STATUS_SHUTDOWN = 2;


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

        //fork 子进程
        static::forkWorkers();

        //master监控worker
        static::monitorWorkers();
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

        if($master_alive){
            //不能重复启动
            if($command1 == 'start' && posix_getpid() != $master_id){
                exit('worker is already running !'.PHP_EOL);
            }
        }else{
            //项目未启动的情况下，只有start命令有效
            if ($command1 != 'start') {
                exit('worker not run!' . PHP_EOL);
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
                //SIGINT信号被自定义在signalHandler 里，会以 exit(0) 的方式退出
                $master_id && posix_kill($master_id, SIGINT);

                echo "准备杀死PID是{$master_id}的master进程" . PHP_EOL;

                //如果没有杀死，
                while ($master_id && posix_kill($master_id, 0)) {
                    usleep(300000);
                }
                exit(0);
                break;

            case 'status':
                if(is_file(static::$status_file)){
                    //先删除就得status文件
                    @unlink(static::$status_file);
                }
                //给master发送信号
                posix_kill($master_id,SIGUSR2);
                //等待worker进程往status文件里写入状态
                usleep(300000);
                @readfile(static::$status_file);
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
            static::setProcessTitle('halweg_worker:master');

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
                //show status
                static::writeStatus();
                break;
            case SIGINT:
                //关闭 master和worker
                static::stopAll();
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

        while (count(static::$workers) < $worker_count) {
            static::forkOneWorker(static::$instance);
        }

    }

    public static function forkOneWorker($instance)
    {
        $pid = pcntl_fork();

        if ($pid > 0) {
            static::$workers[$pid] = $pid;
        } elseif ($pid == 0) {
            $worker_pid = posix_getpid();
            static::log("创建了一个pid是 {$worker_pid} 的worker进程");
            static::setProcessTitle('halweg_worker process');

            $instance->run();
        } else {
            throw new Exception('fork on worker fail');
        }
    }

    public function run()
    {
        if ($this->onWorkerStart) {

            try {
                call_user_func($this->onWorkerStart, $this);
            } catch (\Exception $e) {
                static::log($e);
                sleep(1);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                sleep(1);
                exit(250);
            }

        }

        while (1) {
            pcntl_signal_dispatch();
            sleep(1);
        }
    }

    public static function monitorWorkers()
    {
        static::$status = self::STATUS_RUNNING;

        while (1) {
            pcntl_signal_dispatch();
            $status = 0;

            //阻塞（休眠）在这里，直到 子进程退出，
            $pid = pcntl_wait($status, WUNTRACED);

            //阻塞期间如果有未处理的信号，所以要再dispatch一遍
            pcntl_signal_dispatch();

            if ($pid > 0) {
                //意外退出时才重新fork，如果是我们想让worker退出，status = STATUS_SHUTDOWN
                if (static::$status != static::STATUS_SHUTDOWN) {
                    unset(static::$workers[$pid]);
                    static::forkOneWorker(static::$instance);
                }
            }
        }
    }

    public static function stopAll()
    {
        $pid = posix_getpid();

        if ($pid === self::$master_pid) {
            static::$status = self::STATUS_SHUTDOWN;
            foreach (self::$workers as $worker_pid) {
                posix_kill($worker_pid, SIGINT);
            }

            @unlink(self::$pid_file);
            exit(0);
        } else{ //worker进程
            static::log('worker[' . $pid .'] stop');
            exit(0);
        }

    }

    public static function writeStatus()
    {
        $pid = posix_getpid();

        if($pid == static::$master_pid){ //master进程

            $master_alive = static::$master_pid && posix_kill(static::$master_pid,0);
            $master_alive = $master_alive ? 'is running' : 'die';
            $result = file_put_contents(static::$status_file, 'master[' . static::$master_pid . '] ' . $master_alive . PHP_EOL, FILE_APPEND | LOCK_EX);
            //给worker进程发信号
            foreach(static::$workers as $pid){
                posix_kill($pid,SIGUSR2);
            }
        }else{ //worker进程

            $name = 'worker[' . $pid . ']';
            $alive = $pid && posix_kill($pid, 0);
            $alive = $alive ? 'is running' : 'die';
            file_put_contents(static::$status_file, $name . ' ' . $alive . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

}