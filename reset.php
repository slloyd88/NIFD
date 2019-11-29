<?php
/*
	get client key from niftyns.key
	get IP address and mac address from device
	post IP address and mac address to niftyns.com

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
//only allow this to be run from CLI
if(!isCLI()){
	echo "niftyns_reset.php is a command line tool only.";
	exit;
}
global $ifconfig;
$ifconfig=array();
//force dnsmasq to update
$_SERVER['HTTP_HOST']='niftyns.local';
include_once("/var/www/wasql/php/config.php");
if(isset($CONFIG['timezone'])){
	@date_default_timezone_set($CONFIG['timezone']);
}
include_once("/var/www/wasql/php/wasql.php");
include_once("/var/www/wasql/php/database.php");
//drive space
$out=cmdResults("df|grep /dev/root");
$lines=preg_split('/[\r\n]+/',$out['stdout']);
foreach($lines as $line){
	$parts=preg_split('/[\s\t]+/',trim($line),6);
	$filesystem=trim($parts[0]);
	if(!isset($usb[$filesystem])){$usb[$filesystem]=array('filesystem'=>$filesystem);}
	$total_space	= (integer)trim($parts[1])*1024;
	$used_space	= (integer)trim($parts[2])*1024;
	$free_space	= (integer)trim($parts[3])*1024;
	$percent_used	= trim($parts[4]);
	$percent_used=(integer)str_replace('%','',$percent_used);
	$percent_free=100-$percent_used;
	if($percent_free < 20){$class='danger';}
	elseif($percent_free < 30){$class='warning';}
	elseif($percent_free < 40){$class='info';}
	else{$class='success';}
	break;
}
if($total_space < 20000000000){
	//set expand.txt since the drive space is not full
	setFileContents('/etc/expand.txt',time());
	echo "Set expand.txt".PHP_EOL;

}

//update apikey in _settings table
$ok=executeSQL("update _settings set key_value='' where key_name in ('niftyns_apikey','nifd_plex_token')");
echo "apikey cleared".PHP_EOL;
$ok=executeSQL("update dnsmasq set dirty=0");
echo "dirty flag cleared".PHP_EOL;
//clear dnsmasq.log
$ok=setFileContents('/etc/dnsmasq.log','');
echo "cleared dnsmasq.log: {$ok}".PHP_EOL;
//clear dnsmasq.log
$ok=setFileContents('/etc/nifd/cmd.dnsmasq','2');
echo "set dnsmasq to restart: {$ok}".PHP_EOL;
//truncate tables
$truncate_tables=array(
	'devices','dnsmasq_log','dnsmasq_schedule','dnsmasq_ignore','dhcp_reservations','_sessions',
	'_minify','_queries','_changelog','_posteditlog','_errors','_cronlog','_synchronize',
	'speedtest','speedtest_dns',
	'ebook','ebook_files','ebook_pages',
	'resumable','devices','temperature','media_play','plex_rss_queue'
);
foreach($truncate_tables as $truncate_table){
	$ok=executeSQL("truncate table {$truncate_table}");
	echo "truncated {$truncate_table} table".PHP_EOL;
}
//clear out sites blocked by a specific filter and any PAS
$ok=executeSQL("delete from dnsmasq where type like 'D%' or type in ('PAS','LOC')");
echo "dnsmasq table cleared from PAS and blocked by filter records".PHP_EOL;
//reset _settings table
$ok=executeSQL("update _settings set key_value='' where key_name like 'niftyns_dhcp%' and key_name != 'niftyns_dhcp'");
$ok=executeSQL("update _settings set key_value=0 where key_name = 'niftyns_dhcp'");
$ok=executeSQL("update _settings set key_value=1 where key_name = 'niftyns_plex'");
$ok=executeSQL("update _settings set key_value=0 where key_name = 'niftyns_speedtest'");
$ok=executeSQL("update _settings set key_value=0 where key_name = 'niftyns_speedtest_dns'");
$ok=executeSQL("update _settings set key_value=0 where key_name = 'nifd_report'");
$ok=executeSQL("update _settings set key_value='6:22' where key_name = 'nifd_report_times'");
$ok=executeSQL("update _settings set key_value='mon:tue:wed:thu:fri' where key_name = 'nifd_report_days'");
$ok=executeSQL("update _settings set key_value=null where key_name in ('nifd_report_users','nifd_report_sent','nifd_report_exclude')");
echo "_settings table values reset".PHP_EOL;
//reset _users back
$ok=executeSQL("truncate table _users");
$ok=executeSQL("insert into _users (_cuser,_cdate,active,utype,username,password,email) values (1,now(),1,0,'wadmin','~!mrluioiDi5aUibWXZmuMnYOCfIhsbIqJmA==^-','wadmin@gidgetgadget.com')");
echo printValue($ok).PHP_EOL;
$ok=executeSQL("insert into _users (_cuser,_cdate,active,utype,username,password,email) values (1,now(),1,1,'nifduser','~!o8Cet8GtyKg=^-','nifd@gidgetgadget.com')");
echo printValue($ok).PHP_EOL;
//reset plex_rss back
$ok=executeSQL("truncate table plex_rss");
$ok=executeSQL("insert into plex_rss (_cuser,_cdate,active,name,url,category,check_frequency,bandwidth_limit,filter) values (1,now(),0,'LDS General Conference','https://www.gidgetgadget.com/rss/general_conference/en','tv',30,250,'ext:mp4')");
echo printValue($ok).PHP_EOL;
$ok=executeSQL("update plex_rss set active=0,basepath=null,check_date=null where _id=1");
echo printValue($ok).PHP_EOL;
//
//Reset PLEX
$path='/var/lib/plexmediaserver/Library/Application Support/Plex Media Server';
if(file_exists("{$path}/plexmediaserver.pid")){
	unlink("{$path}/plexmediaserver.pid");
	unlink("{$path}/Preferences.xml");
	echo "Plex preferences reset".PHP_EOL;
}
//remove cached images
$cmd='rm -fR "/var/lib/plexmediaserver/Library/Application Support/Plex Media Server/Cache/PhotoTranscoder/*"';
$ok=cmdResults($cmd);
$cmd='rm -fR "/var/lib/plexmediaserver/Library/Application Support/Plex Media Server/Cache/Transcode/*"';
$ok=cmdResults($cmd);
echo "Plex cache removed".PHP_EOL;
//remove fam and WHI from dnsmasq
$ok=executeSQL("delete from dnsmasq where type in ('FAM','WHI')");
echo "removed FAM, WHI from dnsmasq".PHP_EOL;

$ok=executeSQL("update _cron set active=1,running=0,run_date=NULL where _id != 1");
echo "initialized crons".PHP_EOL;
//clear our backups
$ok=cleanupDirectory('/var/www/wasql/sh/backups',1,'min');
echo "cleared backup directory: {$ok}".PHP_EOL;
//dirty one record in dsnmasq so the conf file updates
$ok=executeSQL("update dnsmasq set dirty=1 limit 1");
//Media Server CLEANUP
$lines=file('/etc/fstab');
//remove one if it exists
foreach($lines as $i=>$line){
	if(stringContains($line,"/mnt/media_server")){unset($lines[$i]);}
}
$content=implode('',$lines);
file_put_contents('/etc/fstab',$content);
echo "Cleared fstab".PHP_EOL;
//sync
$ok=cmdResults("sudo sync");
//remove old logs
$ok=cmdResults("sudo rm -f /var/log/*.gz");
$ok=cmdResults("sudo rm -f /var/log/apache2/*.gz");
$ok=cmdResults("sudo rm -f /var/log/samba/*.gz");
$ok=cmdResults("sudo rm -f /var/log/apt/*.gz");
$ok=cmdResults("sudo rm -f /var/log/mysql/*.gz");
echo "log files cleaned up".PHP_EOL;
//clear Ip address in the cloud
$opts=array(
	'mac_address'=>getMacAddress(),
	'ip_v4'=>'',
	'ip_v6'=>''
);
$json=json_encode($opts);
$b64=encodeBase64($json);
$url="https://www.gidgetgadget.com/api/device";
$post=postJSON($url,$b64,array('-ssl_cert'=>'missing.crt'));
echo "Cleared IP in the cloud".PHP_EOL;
//remove nifd.json
unlink('/etc/nifd.json');
//remove nifd.json
unlink('/etc/fing.csv');
//shutdown
//setFileContents('/etc/niftyns_cmd.shutdown',time());
exit;

function logMessage($msg){
	$file='/etc/nifd/reset.log';
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
function getMacAddress(){
	global $ifconfig;
	if(!count($ifconfig)){
		$cmd=cmdResults('ifconfig');
		$ifconfig=preg_split('/[\r\n]+/',$cmd['stdout']);
	}
	//echo printValue($lines);
	$nic=0;
	foreach($ifconfig as $line){
		if(preg_match('/^eth0/is',$line)){$nic=1;}
		elseif(preg_match('/^lo\ /is',$line)){$nic=0;}
		if($nic==0){continue;}
		//echo "{$line}".PHP_EOL;
		if(preg_match('/HWaddr(.+)/is',$line,$m)){
			return trim($m[1]);
		}
	}
	return '';
}
