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
require('/nifd/etc/dnsmasq_functions.php');
include_once("/var/www/wasql/php/common.php");
//only allow this to be run from CLI
if(!isCLI()){
	echo "dnsmasq_send2cloud.php is a command line tool only.";
	exit;
}
$_SERVER['HTTP_HOST']='niftyns.local';
include_once("/var/www/wasql/php/config.php");
if(isset($CONFIG['timezone'])){
	@date_default_timezone_set($CONFIG['timezone']);
}
include_once("/var/www/wasql/php/wasql.php");
include_once("/var/www/wasql/php/database.php");
//exit;
$cnt=dnsmasqSendToCloud();
echo "{$cnt} records sent to cloud<br>\n";
exit;
/******************************************************/
