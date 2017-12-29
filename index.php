<?php 

	require('./vendor/autoload.php');

	// error_reporting(0);

	use hdcms\Worker;

	$worker = new Worker();

	$worker->workerNum = 1;

	class Test{
		function ceshi()
		{
			while(true){
				echo 'ha|';
				sleep(5);
			}
			exit(0);
		}
	}

	$test = new Test();

	$worker->onWorkerStart = function($pin){
		sleep(1000);
		exit(0);
	};

	$worker->onWorkerStart = [
		[[$test , 'ceshi']]
	];

	//$worker->debug = false;

	$worker->run();

 ?>
