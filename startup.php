<?php
/*
get client key from nifd.key
get IP address and mac address from device
post IP address and mac address to nifd.com

*/

//set time limit to a large number so the cron does not time out
ini_set('max_execution_time', 7200);
set_time_limit(7200);
error_reporting(E_ALL & ~E_NOTICE);
$progpath=dirname(__FILE__);
//set the default time zone
date_default_timezone_set('UTC');
//includes
include_once("/var/www/wasql/php/common.php");
logMessage("BEGIN --- nifd/startup.php --------------------");
//only allow this to be run from CLI
if(!isCLI()){
	logMessage("nifd/startup.php is a command line tool only.");
	exit;
}
sleep(5);
global $CONFIG;
$_SERVER['HTTP_HOST']='nifd.local';
include_once("/var/www/wasql/php/config.php");
if(isset($CONFIG['timezone'])){
	@date_default_timezone_set($CONFIG['timezone']);
}
//mount media USB drives
$cmd=cmdResults('rm -fR /media/*');
logMessage("Mount USB Drives");
$usb=array();
$out=cmdResults("/sbin/blkid | grep /dev/sd");
echo printValue($out);
$lines=preg_split('/[\r\n]+/',$out['stdout']);
foreach($lines as $line){
	$line=trim($line);
	if(preg_match('/^(.+?)\:/',$line,$m)){
		$filesystem=$m[1];
		if(!isset($usb[$filesystem])){$usb[$filesystem]=array('filesystem'=>$filesystem);}
		if(preg_match('/\ label=\"(.+?)\"/i',$line,$m)){
			$usb[$filesystem]['label']=$m[1];
			$usb[$filesystem]['media_path']='/media/'.preg_replace('/[^a-z0-9\.]+/i','_',$m[1]);
		}
		elseif(preg_match('/\ partlabel=\"(.+?)\"/i',$line,$m)){
			$usb[$filesystem]['label']=$m[1];
			$usb[$filesystem]['media_path']='/media/'.preg_replace('/[^a-z0-9\.]+/i','_',$m[1]);
		}
		if(preg_match('/\ UUID=\"(.+?)\"/i',$line,$m)){
			$usb[$filesystem]['uuid']=$m[1];
		}
		elseif(preg_match('/\ PARTUUID=\"(.+?)\"/i',$line,$m)){
			$usb[$filesystem]['uuid']=$m[1];
		}
		if(preg_match('/\ TYPE=\"(.+?)\"/i',$line,$m)){
			$usb[$filesystem]['type']=$m[1];
		}
		else{
			//no type is specified so we cannot mount to this partition. dont show it
			unset($usb[$filesystem]);
		}
	}				
}
echo printValue($usb).PHP_EOL;
foreach($usb as $rec){
	if(!strlen($rec['media_path']) || !strlen($rec['uuid'])){continue;}
	if(!is_dir($rec['media_path'])){
		buildDir($rec['media_path'],0777);
	}
	switch(strtolower($rec['type'])){
		case 'exfat':
			$cmd="sync && mount -t exfat -o rw,defaults --uuid='".$rec['uuid']."' {$rec['media_path']}";
		break;
		case 'vfat':
		case 'fat16':
		case 'fat32':
			$cmd="sync && mount -t vfat -o rw,defaults --uuid='".$rec['uuid']."' {$rec['media_path']}";
		break;
		case 'ntfs':
			$cmd="sync && mount -t ntfs-3g -o rw,defaults --uuid='".$rec['uuid']."' {$rec['media_path']}";
		break;
		default:
			$cmd="sync && mount -o rw,defaults --uuid='".$rec['uuid']."' {$rec['media_path']}";
		break;
	}
	$out=cmdResults($cmd);
	logMessage($cmd.PHP_EOL.printValue($out));
}
$cmd=cmdResults('ifconfig -s -a');
$lines=preg_split('/[\r\n]+/',$cmd['stdout']);
array_shift($lines);
$nics=array();
foreach($lines as $line){
	list($nic,$junk)=preg_split('/\ /',trim($line));
	if(trim($nic)=='lo'){continue;}
	$nics[]=trim($nic);
}
global $ifconfig;
$ifconfig=array();
foreach($nics as $nic){
	$opts=array(
		'nic'=>$nic,
		'mac_address'=>getMacAddress($nic),
		'ip_v4'=>getIPV4($nic),
		'ip_v6'=>getIPV6($nic)
	);
	if(strlen($opts['ip_v4'])){break;}
}
//echo printValue($opts);exit;
logMessage("NIC: {$opts['nic']}");
logMessage("MAC: {$opts['mac_address']}");
logMessage("IP V4: {$opts['ip_v4']}");
logMessage("IP V6: {$opts['ip_v6']}");
if(isset($opts['ip_v4']) &&  strlen($opts['ip_v4'])){
	updateHostsFile($opts['ip_v4']);
	updateResolvFile($opts['ip_v4']);
	updateResolvConfFile($opts['ip_v4']);
    //add current IP address to command.txt for plexmediaserver
	updateCommandFile($opts['ip_v4']);
}
else{
    $cmd='/usr/bin/espeak "Your nifty device has booted but no I,P address was detected."';
	$out=cmdResults($cmd);
	echo "No IP address".printValue($opts);
	exit;
}
sleep(1);

//force dnsmasq to update
$_SERVER['HTTP_HOST']='nifd.local';
include_once("/var/www/wasql/php/config.php");
if(isset($CONFIG['timezone'])){
@date_default_timezone_set($CONFIG['timezone']);
}
include_once("/var/www/wasql/php/wasql.php");
include_once("/var/www/wasql/php/database.php");
//PLEX.tv
$out=cmdResults("systemctl is-active plexmediaserver");
$plexstatus=strtolower(trim($out['stdout']));
$plex=getDBRecord(array(
	'-table'=>'_settings',
	'key_name'=>'nifd_plex',
	'-fields'=>'_id,key_value,key_name'
	));
logMessage("PLEX".printValue($plex));
if(isset($plex['key_value']) && $plex['key_value']==1){
	switch($plexstatus){
		case 'unknown':
			$out=cmdResults("/usr/sbin/update-rc.d -f plexmediaserver enable");
			$out=cmdResults("/usr/sbin/service plexmediaserver start");
		break;
		case 'inactive':
			$out=cmdResults("/usr/sbin/service plexmediaserver start");
		break;
	}
	logMessage("plexmediaserver is enabled");
}
else{
	switch($plexstatus){
		case 'inactive':
			$out=cmdResults("/usr/sbin/update-rc.d -f plexmediaserver disable");
		break;
		case 'active':
			$out=cmdResults("/usr/sbin/service plexmediaserver stop");
			$out=cmdResults("/usr/sbin/update-rc.d -f plexmediaserver disable");
		break;
	}
	logMessage("plexmediaserver is disabled");
}
//update apikey in _settings table
$ok=executeSQL("update _settings set key_value='{$post['body']}' where key_name='nifd_apikey'");
logMessage("updated nifd_apikey\n".printValue($ok));
$ok=executeSQL("update dnsmasq set dirty=1 limit 3");
file_put_contents("/etc/nifd/cmd.dnsmasq",time());
logMessage("set dnsmasq dirty flag so it reloads");
$ok=executeSQL("update _cron set running=0");
logMessage("reset _crons");
//lastly, call home
$json=json_encode($opts);
$b64=encodeBase64($json);
$url="https://www.gidgetgadget.com/api/device";
$post=postJSON($url,$b64,array('-ssl_cert'=>'missing.crt','-json'=>1));
if(!isset($post['body']) || !strlen($post['body'])){
	setFilecontents('/etc/nifd/startup.err',printValue($post));
	$ok=cmdResults("/usr/bin/dos2unix /etc/nifd/startup.err");
	logMessage("nifd Post error".printValue($post));
	exit;
}
if(isset($post['json_array']['_id'])){
	setFilecontents('/etc/nifd/nifd.json',json_encode($post['json_array']));
	logMessage("Set nifd.json");	
}
logMessage("nifd Post");
logMessage("{$json} {$post['body']}");
setFilecontents('/etc/nifd/startup.post',printValue($post));
$ok=cmdResults("/usr/bin/dos2unix /etc/nifd/startup.post");
//espeak
$ipstr=str_replace(':',', ',$opts['ip_v4']);
$ipstr=str_replace('.',', ',$ipstr);
$cmd='/usr/bin/espeak "Your nifty device is ready. The I,P address is '.$ipstr.'"';
$out=cmdResults($cmd);
logMessage("END --- nifd/startup.php --------------------");
exit;
/* functions */
function updateHostsFile($ip){
//update interfaces file with new iface
	$content=<<<ENDOFSTR
127.0.0.1       localhost
127.0.0.1		raspberrypi
{$ip}   nifd.local
{$ip}   nifd
{$ip}   nifty
{$ip}   nifd
{$ip}   nifd.local

#for cnames to work in dnsmasq.conf
::ffff:216.239.38.120 forcesafesearch.google.com restrict.youtube.com
216.239.38.120 forcesafesearch.google.com restrict.youtube.com
::ffff:216.239.38.119 restrictmoderate.youtube.com
216.239.38.119 restrictmoderate.youtube.com
::ffff:204.79.197.220 strict.bing.com
204.79.197.220 strict.bing.com

# The following lines are desirable for IPv6 capable hosts
::1             localhost ip6-localhost ip6-loopback
ff02::1         ip6-allnodes
ff02::2         ip6-allrouters

ENDOFSTR;

	$ok=setFileContents('/etc/hosts',$content);
	$ok=cmdResults("/usr/bin/dos2unix /etc/hosts");
	logMessage("updated hosts to {$ip}/: {$ok}");
}
function logMessage($msg){
	$file='/etc/nifd/startup.log';
	$msg=trim($msg);
	$cdate=date('Y-m-d H:i:s');
	$msg="{$cdate},{$msg}".PHP_EOL;
	if(file_exists($file) && filesize($file) < 2000000){
		appendFileContents($file,$msg);
	}
	else{
		setFileContents($file,$msg);
	}
	echo $msg;

}
function updateCommandFile($ip){
	$afile='/boot/cmdline.txt';
	if(!file_exists($afile)){return;}
	$lines=file($afile);
	$xlines=array();
	foreach($lines as $i=>$line){
		if(preg_match('/^ip=/i',trim($line))){continue;}
		$xlines[]=trim($line);
	}
	$xlines[]="ip={$ip}".PHP_EOL;
	$content=implode(PHP_EOL,$xlines);
	$ok=setFileContents($afile,$content);
	$ok=cmdResults("/usr/bin/dos2unix \"{$afile}\"");
	logMessage("updated cmdline.txt to {$ip}/");
}
function updateResolvFile($ip){
//update interfaces file with new iface
	$content=<<<ENDOFSTR
# Generated by /etc/nifd/startup.php:
domain Home
nameserver {$ip}
#OpenDNS
nameserver 208.67.222.123
nameserver 208.67.220.123
#Clean Browsing Adult IPV6
nameserver 2a0d:2a00:1::1
nameserver 2a0d:2a00:2::1


ENDOFSTR;
	$ok=setFileContents('/etc/resolv.conf',$content);
	$ok=cmdResults("/usr/bin/dos2unix /etc/resolv.conf");
	logMessage("updated resolv.conf:".printValue($ok).PHP_EOL);
}
function updateResolvConfFile($ip){
//update interfaces file with new iface
	$content=<<<ENDOFSTR
# Configuration for resolvconf(8)
# See resolvconf.conf(5) for details

resolv_conf=/etc/resolv.conf
# If you run a local name server, you should uncomment the below line and
# configure your subscribers configuration files below.
name_servers="{$ip} 208.67.222.123 208.67.220.123 185.228.168.10 185.228.169.11"

# Mirror the Debian package defaults for the below resolvers
# so that resolvconf integrates seemlessly.
dnsmasq_resolv=/var/run/dnsmasq/resolv.conf
pdnsd_conf=/etc/pdnsd.conf
unbound_conf=/var/cache/unbound/resolvconf_resolvers.conf

ENDOFSTR;
	$ok=setFileContents('/etc/resolvconf.conf',$content);
	$ok=cmdResults("/usr/bin/dos2unix /etc/resolvconf.conf");
	logMessage("updated resolvconf.conf:".printValue($ok).PHP_EOL);
}
function getMacAddress($device='eth0'){
	global $ifconfig;
	if(!count($ifconfig)){
		$cmd=cmdResults('/sbin/ifconfig');
		$ifconfig=preg_split('/[\r\n]+/',$cmd['stdout']);
	}
	//echo "getMacAddress".printValue($ifconfig);exit;
	//echo printValue($lines);
	$nic=0;
	foreach($ifconfig as $line){
    	if(preg_match('/^'.$device.'/is',trim($line))){
			$nic=1;
		}
		if(preg_match('/^lo\:/is',$line)){
			$nic=0;
		}
		if($nic==0){continue;}
		if(preg_match('/ether(.+?)txqueuelen/i',$line,$m)){
			return trim($m[1]);
		}
		elseif(preg_match('/HWaddr(.+)/is',$line,$m)){
			return trim($m[1]);
		}
	}
	return '';
}
function getIPV4($device='eth0'){
	global $ifconfig;
	if(!count($ifconfig)){
		$cmd=cmdResults('/sbin/ifconfig');
		$ifconfig=preg_split('/[\r\n]+/',$cmd['stdout']);
	}
	$nic=0;
	foreach($ifconfig as $line){
    	if(preg_match('/^'.$device.'/is',trim($line))){
			$nic=1;
		}
		if(preg_match('/^lo\:/is',$line)){
			$nic=0;
		}
		if($nic==0){continue;}
		if(preg_match('/inet([0-9\.\ ]+?)netmask/i',$line,$m)){
			return trim($m[1]);
		}
		elseif(preg_match('/inet addr\:([0-9\.]+)/',$line,$m)){
			return trim($m[1]);
		}
	}
	return '';
}
function getIPV6($device='eth0'){
	global $ifconfig;
	if(!count($ifconfig)){
		$cmd=cmdResults('/sbin/ifconfig');
		$ifconfig=preg_split('/[\r\n]+/',$cmd['stdout']);
	}
	$nic=0;
	foreach($ifconfig as $line){
    	if(preg_match('/^'.$device.'/is',$line)){
			$nic=1;
		}
		if(preg_match('/^lo\:/is',$line)){
			$nic=0;
		}
		if($nic==0){continue;}
		if(preg_match('/inet6(.+?)prefix/i',$line,$m)){
			return trim($m[1]);
		}
		elseif(preg_match('/inet6 addr\:(.+)$/',$line,$m)){
			$parts=preg_split('/\ +/',trim($m[1]),2);
			return trim($parts[0]);
		}
	}
	return '';
}
