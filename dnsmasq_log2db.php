<?php
//set time limit to a large number so the cron does not time out
ini_set('max_execution_time', 7200);
set_time_limit(7200);
error_reporting(E_ALL & ~E_NOTICE);
$progpath=dirname(__FILE__);
//set the default time zone
date_default_timezone_set('UTC');
//includes
include_once("/var/www/wasql/php/common.php");
//only allow this to be run from CLI
if(!isCLI()){
	echo "dnsmasq_log2db.php is a command line tool only.";
	exit;
}
$_SERVER['HTTP_HOST']='niftyns.local';
include_once("/var/www/wasql/php/config.php");
if(isset($CONFIG['timezone'])){
	@date_default_timezone_set($CONFIG['timezone']);
}
include_once("/var/www/wasql/php/wasql.php");
include_once("/var/www/wasql/php/database.php");
require('/etc/nifd/dnsmasq_functions.php');
// Last read position
$last = 0;
global $parse;
$parse=array();
global $macmap;
$macmap=dnsmasqGetMacMap();
// Path to the updating log file
$source = '/etc/dnsmasq.log';
$pid=getmypid(); 
$dest="/etc/dnsmasq_{$pid}.importing";

$ok=copyFile($source,$dest);
if(file_exists($dest) && filesize($dest)){
	setFileContents($source,'');
}
$num=processFileLines($dest,'dnsmasqLog2dbParseLine');
unlink($dest);
exit;
function dnsmasqLog2dbParseLine($line){
	global $parse;
	global $macmap;
	$line=$line['line'];
	echo "-- {$line}".PHP_EOL;
	if(preg_match('/^(.+?)dnsmasq\[(.+?)\]\:\ query\[(A|AAAA)\]\ (.+?)\ from\ (.+)$/i',$line,$m)){
		$parse['name']=$m[4];
		$parse['from']=$m[5];
		return ' - set name,from';
	}
	if(!isset($parse['name']) || !strlen($parse['name'])){
		return '';
	}
	if($parse['from']=='127.0.0.1'){
        $parse=array();
        return ' - local request';
	}

	if(preg_match('/^(.+?)dnsmasq\[(.+?)\]\:\ (reply|config|cached)\ (.+?)\ is\ (.+)$/',$line,$m)){
		$timestamp=strtotime(trim($m[1]));
		$record=1;
		$type='PAS';
		if($parse['name']==$m[4] || $parse['cname']==1){
			$to_ip=$m[5];
			if($to_ip=='<CNAME>'){
				$parse['cname']=1;
				return ' - cname set';
			}
			$host=preg_replace('/\.Home$/i','',$m[4]);
			if(!preg_match('/\./',$host)){
				//invalid domain
				return;
			}
			$unique_host=dnsmasqUniqueHostName($host);
			//try to determine type
			$type=dnsmasqGetScheduleType($host);
			if(!strlen($type)){$type=dnsmasqGetServerType($host,$to_ip);}
			if(!strlen($type)){$type='PAS';}
			if($type=='INV'){return;}
			$opts=array(
				'-table'	=> 'dnsmasq_log',
				'-ignore'	=> 1,
				'logdate'	=> date('Y-m-d H:i',strtotime($m[1])),
				'host'		=> $host,
				'unique_host'=>$unique_host,
				'type'		=> $type,
  				'cloud'		=> 0,
				'from_ip'	=> $parse['from'],
				'from_mac'	=> $macmap[$parse['from']]['mac_address'],
				'from_os'	=> dnsmasqGetHostOS($parse['from'])
			);
			$crc=encodeCRC(json_encode($opts));
			$opts['to_ip']=$to_ip;
			$opts['crc']=$crc;
			$opts['from_device']=$macmap[$parse['from']]['name'];
			$logid=addDBRecord($opts);
			if($type=='PAS' && !preg_match('/\.(local|google|amazon|microsoft)/i,',$host)){
				$cdate=date('Y-m-d H:i:s');
				$host=str_replace('https://','',$host);
				$host=str_replace('http://','',$host);
				$host=str_replace('/','',$host);
				$opts=array(
					'-table'=>'dnsmasq',
					'-ignore'	=> 1,
					'name'=>$host,
					'type'=>$type,
					'dirty'=>0
				);
				$ok=addDBRecord($opts);
			}
			//reset parse
			$parse=array();
			if($logid==0){return " - ignored [{$crc}]";}
			return " - DB ID={$logid}";
		}
	}
}