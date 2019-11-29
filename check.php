<?php
/*
	apt-get update
	apt-get install dnsutils
*/
//make sure dnsmasq is running
$cmd=strtolower("pgrep -cf dnsmasq");
$out=cmdResults($cmd);
$pcnt=(integer)$out['stdout'];
if($pcnt ==0){
	file_put_contents("/etc/nifd/cmd.dnsmasq",time());
	exit(0);
}
//---------- begin function cmdResults--------------------------------------
/**
* @describe executes command and returns results
* @param cmd string - the command to execute
* @param [args] string - arguements to pass to the command
* @param [dir] string - directory
* @param [timeout] integer - seconds to let process run for. Defaults to 0 - unlimited
* @return string
*	returns the results of executing the command
*/
function cmdResults($cmd,$args='',$dir='',$timeout=0){
	if(!is_dir($dir)){$dir=null;}
	if(strlen($args)){$cmd .= ' '.trim($args);}
	if($timeout != 0 && isNum($timeout) && !isWindows()){
		//this will kill the process if it goes longer than timeout
    	$cmd="($cmd) & WPID=\$!; sleep {$timeout} && kill \$WPID > /dev/null 2>&1 & wait \$WPID";
	}
	$proc=proc_open($cmd,
		array(
			0=>array('pipe', 'r'), //stdin
			1=>array('pipe', 'w'), //stdout
			2=>array('pipe', 'a')  //stderr
			),
		$pipes,
		$dir
		);
	stream_set_blocking($pipes[2], 0);
    //fwrite($pipes[0], $args);
	fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $rtncode=proc_close($proc);
    $rtn=array(
    	'cmd'	=> $cmd,
    	'args'	=> $args,
    	'dir'	=> $dir,
		'stdout'=>$stdout,
        'stderr'=>$stderr,
        'rtncode'=>$rtncode
    );
    //remove blank vals
    foreach($rtn as $k=>$v){
    	if(!is_array($v)){
			if(!strlen($v)){unset($rtn[$k]);}
    		else{$rtn[$k]=trim($v);}
		}
	}
	return $rtn;
}
//---------- begin function printValue
/**
* @describe returns an html block showing the contents of the object,array,or variable specified
* @param $v mixed The Variable to be examined.
 * @param [$exit] boolean - if set to true, then it will echo the result and exit. defaults to false
* @return string
*	returns an html block showing the contents of the object,array,or variable specified.
* @usage
*	echo printValue($sampleArray);
 * printValue($str,1);
* @author slloyd
* @history bbarten 2014-01-07 added documentation
*/
function printValue($v='',$exit=0){
	$type=strtolower(gettype($v));
	$plaintypes=array('string','integer');
	if(in_array($type,$plaintypes)){return $v;}
	$rtn='';
	if($exit != -1){$rtn .= '<pre class="w_times" type="'.$type.'">'."\n";}
	ob_start();
	print_r($v);
	$rtn .= ob_get_contents();
	ob_clean();
	if($exit != -1){$rtn .= "\n</pre>\n";}
    if($exit){echo $rtn;exit;}
	return $rtn;
	}
