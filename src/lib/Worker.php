<?php
namespace hdcms;

class Worker {
    
    const STATUS_RUNNING=1;
    const STATUS_STOPPING=2;
    
    protected $masterPid = 0;
    protected $workers = [];
    protected $status = 0;
    
    // worker的索引
    protected $PIN = -1;
    
    // worker数量
    public $workerNum = 1;
    
    // 守护进程
    public $deamon = true;
    
    public $onWorkerStart = null;
    
    // debug模式
    public $debug = false;
    
    // 进程正常退出码
    public $normalExitCode = 0;    
   
    // 进程id文件
    public $lockFile = '';    

    // 操作系统
    private $_OS = '';
    

    public function run()
    {        
        $this->checkEnv();
        $this->parseCommand();
        $this->init();        
        $this->daemonize();    
        $this->masterPid = getmypid();
        $this->status = self::STATUS_RUNNING;
        $this->forkWorkers();
        $this->waitWorkers();
    }

    // 环境检查
    protected function checkEnv()
    {
        if (php_sapi_name() != "cli") {
            exit("only run in command line mode \n");
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            exit("only run in command on linux \n");
        }
    }

    // 命令解析
    protected function parseCommand() 
    {
        global $argv;

        $start_file = $argv[0];

        $available_commands = array(
            'start',
            'stop'
        );

        $usage = "Usage: php yourfile.php {" . implode('|', $available_commands) . "} [-d]\n";

        if (!isset($argv[1]) || !in_array($argv[1], $available_commands)) {
            exit($usage);
        }

        $command  = trim($argv[1]);

        $command2 = isset($argv[2]) ? $argv[2] : '';

        $pid_file = explode('.', $start_file);

        $this->lockfile = __DIR__ . '/' . $pid_file[0] . '.pid';

        $master_pid = is_file($this->lockfile) ? file_get_contents($this->lockfile) : 0;

        $master_is_alive = $master_pid && @posix_kill($master_pid, 0) && posix_getpid() != $master_pid;

        switch ($command) {
            case 'start':
                if($master_is_alive){
                    $this->Log("$start_file already running");
                    exit;
                }else{
                    if ($command2 === '-d' || !$this->debug) {
                        $mode = 'in DAEMON mode';
                    } else {
                        $mode = 'in DEBUG mode';
                    }

                    $this->Log("$start_file started $mode \n");
                }

                break;
            
            case 'stop':
                if ($master_is_alive){
                    $graceful = true;
                    $sig = SIGTERM;
                    // 强制停止
                    if ($command2 === '-f'){
                        $graceful = false;
                        $sig = SIGINT;
                    }

                    posix_kill($master_pid, $sig);

                    while (1) {
                        usleep(100);
                        $master_is_alive = $master_pid && posix_kill($master_pid, 0);
                        if (!$master_is_alive) {
                            break;
                        }
                    }
                    $this->Log("$start_file stopped success");
                    exit();
                }else{
                    $this->Log("$start_file is not running");
                    exit;
                }

                break;
        }
    }
    
    // protected function init()
    // {
    //     static $handler = null;
    //     $this->workerNum = max(1, $this->workerNum);        
    //     $this->normalExitCode = (int)$this->normalExitCode;
        
    //     if(!$this->onWorkerStart || !is_callable($this->onWorkerStart)) {
    //         throw new \Exception("onWorkerStart must be a valid callback");
    //     }
    // }

    protected function init()
    {
        static $handler = null;

        if(!$this->onWorkerStart){
            throw new \Exception("onWorkerStart must be a valid callback");
        }

        $this->workerNum = max(1, $this->workerNum);

        $this->normalExitCode = (int)$this->normalExitCode;
    }
    
    protected function createPidFile()
    {
        if(!file_put_contents($this->lockfile, getmypid())){
            throw new \Exception("can not create file $this->lockFile");
        }
    }
    
    protected function call($callback, $params=null)
    {
        return call_user_func($callback,$params);
    }
    
    // protected function forkWorkers($pin = -1)
    // {
    //     static $_counter = null;
    //     if(is_null($_counter)){
    //     	$_counter = $this->workerNum;
    //     }         
        
    //     while(count($this->workers) < $this->workerNum){
    //         $_counter++;
    //         $pid = pcntl_fork();
            
    //         $PIN = $pin >=0 ? $pin : ($_counter % $this->workerNum);
            
    //         if($pid >0){
    //             $this->workers[$PIN] = $pid;
    //         }else if($pid === -1){
    //             $this->exitAll();
    //         }else{
    //             $this->PIN = $PIN;
    //             $this->installSignalHandler();
    //             return $this->call($this->onWorkerStart, $this->PIN);
    //         }
    //     }
    // }

    protected function forkWorkers($pid = -1)
    {
        if($pid < 0){

            // 匿名函数
            if(is_callable($this->onWorkerStart)){

                for($i=0;$i<$this->workerNum;$i++){
                    $_workers[] = ['callback' => $this->onWorkerStart , 'param' => ''];
                }
            }elseif(is_array($this->onWorkerStart)){

                if(!count($this->onWorkerStart)){
                    throw new \Exception("onWorkerStart incorrect");
                }

                foreach ($this->onWorkerStart as $k => $v) {
                	if(!is_array($v)){
                		throw new \Exception("onWorkerStart incorrect");
                	}
                }

                foreach ($this->onWorkerStart as $k => $v) {
                    // 每个方法fork的进程数
                    $_set_count = isset($v[2]) ? $v[2] : 1;
                    $_set_count = max($_set_count , 1);
                    // 函数参数
                    $_param = isset($v[1]) ? $v[1] : null;
                    // 函数名
                    if(is_string($v[0])){

                        $_callback = $v[0];

                        // 数组形式
                    }elseif(is_array($v[0])){

                        if(count($v[0]) < 2){
                            throw new \Exception("onWorkerStart incorrect");
                        }

                        if(is_object($v[0][0]) || is_string($v[0][0])){
                            $_callback = array($v[0][0] , $v[0][1]);
                        }else{
                            throw new \Exception("onWorkerStart incorrect");
                        }

                    }else{
                        throw new \Exception("onWorkerStart incorrect");
                    }

                    for($i=0;$i<$_set_count;$i++){
                        $_workers[] = ['callback' => $_callback , 'param' => $_param];
                    }
                }


            }else{
                throw new \Exception("onWorkerStart incorrect");
            }

            $_callback_workers = $_workers;

        }else{
            $_callback_workers[$pid] = $this->workers[$pid];
        }

        foreach ($_callback_workers as $k => $v) {
            $pid = pcntl_fork();

            if($pid >0){
                $_tmp_worker = $v;
                unset($this->workers[$k]);
                $this->workers[$pid] = $_tmp_worker;
            }else if($pid === -1){
                $this->exitAll();
            }else{
                $this->installSignalHandler();
                return $this->call($v['callback'], $v['param']);
            }
        }   
    }
    
    protected function daemonize()
    {
        if($this->deamon && !$this->debug){
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new \Exception('fork fail');
            } elseif ($pid > 0) {
                exit(0);
            }
        }

        $this->createPidFile();
    }

    protected function exitAll(){
        if($this->masterPid) {
            posix_kill($this->masterPid, SIGTERM);
        }
    }
    
    protected function installSignalHandler() 
    {
        pcntl_signal(SIGHUP, array($this,  'signalHandler'), false);
        pcntl_signal(SIGINT, array($this,  'signalHandler'), false);
        pcntl_signal(SIGTERM, array($this, 'signalHandler'), false);
    }
    
    protected function signalHandler($signal)
    {
        $this->status = self::STATUS_STOPPING;
        if($this->isMaster()) {
        	foreach($this->workers as $pid => $value) {
                posix_kill($pid, SIGKILL);
                // posix_kill($pid, $signal);
            }
            if(file_exists($this->lockfile)){
            	unlink($this->lockfile);
            }
            $this->end();
        }else{
            $this->end();
        }
    }    
    
    public function Log($message)
    {   
        if($this->deamon) {
            echo $message. "\n";
        }
    }
    
    protected function isMaster() 
    {
        return $this->masterPid === getmypid();
    }
    
    protected function end()
    {
        exit($this->normalExitCode);
    }
    
    // protected function waitWorkers() 
    // {
    //     if(!$this->isMaster())  return false;
        
    //     $this->installSignalHandler();
    
    //     while(true) {
    //         $status = 0;
    //         $pid = pcntl_wait($status, WUNTRACED);
    //         pcntl_signal_dispatch();
    //         if($pid >0) {
    //             $i = array_search($pid, $this->workers);                
    //             unset($this->workers[$i]);                
    //             $exitCode = pcntl_wexitstatus($status);
    //             $this->Log("worker $pid($i) exit($exitCode)");
    
    //             if($this->status !== self::STATUS_STOPPING){  
    //                 $this->Log("for worker for PIN: $i");
    //                 $this->forkWorkers($i === false ? -1 : $i);
    //             }
    //         }
    //     }
    // }

    protected function waitWorkers() 
    {
        if(!$this->isMaster())  return false;
        
        $this->installSignalHandler();
    
        while(true) {
            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);
            pcntl_signal_dispatch();
            if($pid >0) {
                $exitCode = pcntl_wexitstatus($status);
                $this->Log("worker $pid exit($exitCode)");
    
                if($this->status !== self::STATUS_STOPPING){  
                    $this->forkWorkers($pid);
                }
            }
        }
    }
}
