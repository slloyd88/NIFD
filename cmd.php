<?php
echo ".".PHP_EOL;
$progpath=dirname(__FILE__);
for($x=0;$x<28;$x++){
	$cmd='';
	if(file_exists("/etc/nifd/cmd.shutdown")){
		$cmd="sudo /sbin/shutdown -h now";
		unlink("/etc/nifd/cmd.shutdown");
	}
	elseif(file_exists("/etc/nifd/cmd.restart")){
		$cmd="sudo /sbin/shutdown -r now";
		unlink("/etc/nifd/cmd.restart");
	}
	elseif(file_exists("/etc/nifd/cmd.reset")){
		$cmd="sudo /usr/bin/php /etc/nifd/reset.php";
		unlink("/etc/nifd/cmd.reset");
	}
	elseif(file_exists("/etc/nifd/cmd.dnsmasq")){
		$cmd="sudo /usr/sbin/service dnsmasq restart";
		unlink("/etc/nifd/cmd.dnsmasq");
	}
	elseif(file_exists("/etc/nifd/cmd.test")){
		unlink("/etc/nifd/cmd.test");
		echo "test successfull".PHP_EOL;
	}
	elseif(file_exists("/etc/nifd/cmd.apache")){
		unlink("/etc/nifd/cmd.apache");
		$cmd="sudo /usr/sbin/apachectl restart";
	}
	elseif(file_exists("/etc/nifd/cmd.wasql")){
		unlink("/etc/nifd/cmd.wasql");
		$cmd="cd /var/www/wasql && sudo /usr/bin/git pull";
	}
	elseif(file_exists("/etc/nifd/cmd.usb_mount") && file_exists('/etc/usb.json')){
		$key=getFileContents("/etc/nifd/cmd.usb_mount");
		echo "mount command for {$key}".PHP_EOL;
		unlink("/etc/nifd/cmd.usb_mount");
		$usb=json_decode(getFileContents('/etc/nifd/usb.json'),true);
		echo "USB.json".printValue($usb).PHP_EOL;
		if(!empty($usb[$key]) && !empty($usb[$key]['media_path']) && !empty($usb[$key]['uuid'])){
			if(!is_dir($usb[$key]['media_path'])){
				buildDir($usb[$key]['media_path'],0777);
				$cmd="sudo chown nifd:nifd {$usb[$key]['media_path']}";
				echo "running cmd: {$cmd}".PHP_EOL;
				$ok=cmdResults($cmd);
				echo printValue($ok);
			}
			switch(strtolower($usb[$key]['type'])){
				case 'exfat':
					$cmd="sudo mount -t exfat -o rw,defaults --uuid='".$usb[$key]['uuid']."' {$usb[$key]['media_path']}";
				break;
				case 'ntfs':
					$cmd="sudo mount -t ntfs-3g -o rw,defaults --uuid='".$usb[$key]['uuid']."' {$usb[$key]['media_path']}";
				break;
				case 'vfat':
				case 'fat16':
				case 'fat32':
					$cmd="sync && sudo mount -t vfat -o rw,defaults --uuid='".$usb[$key]['uuid']."' {$usb[$key]['media_path']}";
				break;
				default:
					$cmd="sudo mount -o rw,defaults --uuid='".$usb[$key]['uuid']."' {$usb[$key]['media_path']}";
				break;
			}
		}
		else{
			echo "{$key} is missing".printValue($usb).PHP_EOL;
		}		
	}
	elseif(file_exists("/etc/nifd/cmd.usb_unmount") && file_exists('/etc/usb.json')){
		$key=getFileContents("/etc/nifd/cmd.usb_unmount");
		echo "unmount command for {$key}".PHP_EOL;
		unlink("/etc/nifd/cmd.usb_unmount");
		$usb=json_decode(getFileContents('/etc/nifd/usb.json'),true);
		if(!empty($usb[$key]) && !empty($usb[$key]['media_path']) && is_dir($usb[$key]['media_path']) && preg_match('/media/i',$usb[$key]['media_path'])){
			$cmd="sync && sudo umount {$usb[$key]['media_path']} && rm -fR {$usb[$key]['media_path']}";
		}
		else{
			echo "{$key} is missing".printValue($usb).PHP_EOL;
		}
	}
	//run the command
	if(strlen($cmd)){
		echo "running cmd: {$cmd}".PHP_EOL;
		$ok=cmdResults($cmd);
		echo printValue($ok);
	}
	sleep(2);
}
exit;
//---------- begin function isWindows ----------
/**
* @describe returns true if the server is windows
* @return boolean
*	returns true if the server is windows
* @usage if(isWindows()){...}
*/
function isWindows(){
	if(PHP_OS == 'WINNT' || PHP_OS == 'WIN32' || PHP_OS == 'Windows'){return true;}
	return false;
}
//---------- begin function buildDir ----------
/**
* @describe recursive folder generator
* @param dir string
* @param mode number
* @param recurse boolean
* @return boolean
* @usage if(!buildDir('/var/www/test/folder/sub/test')){return 'failed to build dir';}
*/
function buildDir($dir='',$mode=0777,$recursive=true){
	if(is_dir($dir)){return 0;}
	return @mkdir($dir,$mode,$recursive);
}
//---------- begin function getFileContents--------------------
/**
* @describe returns the contents of file - wrapper for file_get_contents
* @param file string - name and path of file
* @return string
* @usage $data=getFileContents($afile);
*/
function getFileContents($file){
	if(!file_exists($file)){return "getFileContents Error: No such file [$file]";}
	return file_get_contents($file);
}
//---------- begin function cmdResults--------------------------------------
/**
* @describe executes command and returns results
* @param cmd string
*	the command to execute
* @param args string
*	arguements to pass to the command
* @return string
*	returns the results of executing the command
*/
function cmdResults($cmd,$args='',$dir='',$timeout=0){
	if(!is_dir($dir)){$dir=realpath('.');}
	if(strlen($args)){$cmd .= ' '.trim($args);}
	//windows OS requires the stderr pipe to be write
	if(isWindows()){
		$proc=proc_open($cmd,
			array(
				0=>array('pipe', 'r'), //stdin
				1=>array('pipe', 'w'), //stdout
				2=>array('pipe', 'w')  //stderr
				),
			$pipes,
			$dir,
			null,
			array('bypass_shell'=>true)
		);
		stream_set_blocking($pipes[1], 0);
		stream_set_blocking($pipes[2], 0);
	}
	else{
		if($timeout != 0 && isNum($timeout)){
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
	}
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
