<?php
function dnsmasqGetScheduleType($host){
	//check for dnsmasq_schedules
	$cday=strtoupper(date('D'));
	$opts=array(
		'-table'=>'dnsmasq_schedule',
		'shost'=>$host
		//'-where'=>"sday='{$cday}' and (allday=1 or ((sbegin is null or sbegin >= time(now())) and (send is null or send <= time(now()))))"
	);
	$recs=getDBRecords($opts);
	//echo printValue($recs);exit;
	if(isset($recs[0])){
		$ctime=time()-strtotime("00:00");
		foreach($recs as $rec){
			//allow or no?
			$match=0;
			$daymatch=0;
			if(strtoupper($rec['sday'])=='EVE'){$daymatch=1;}
			elseif(strtoupper($rec['sday'])=='WKD' && in_array($cday,array('MON','TUE','WED','THU','FRI'))){$daymatch=1;}
			elseif(strtoupper($rec['sday'])=='WKE' && in_array($cday,array('SAT','SUN'))){$daymatch=1;}
			if($daymatch==1 || strtoupper($rec['sday'])==$cday){
				$d=date('Y-m-d');
            	if($rec['allday']==1){return $rec['type'];}
            	$sbegin=time()-strtotime("{$d} {$rec['sbegin']}");
            	$send=time()-strtotime("{$d} {$rec['send']}");
            	if(strlen($rec['sbegin']) && $sbegin > 0 && $ctime >= $sbegin){
					//echo "{$rec['shost']},ctime:{$ctime}, sbegin:{$sbegin}, send:{$send}<br>\n";
					if(!strlen($rec['send'])){return $rec['type'];}
					elseif($send > 0 && $ctime <= $send){return $rec['type'];}
				}
				elseif(strlen($rec['send']) & $send > 0 && $ctime <= $send){return $rec['type'];}
			}
		}
	}
	return '';
}
function dnsmasqGetMacMap(){
	//load any new devices from arp
	$arpfile='/var/www/wasql/php/temp/arp.txt';
	if(file_exists($arpfile)){unlink($arpfile);}
	$cmd=cmdResults("sudo arp -n >{$arpfile}");
	$cmdlines=file($arpfile);
	unlink($arpfile);
	$cnt=count($cmdlines);
	if($cnt==0){
		echo " - arp had {$cnt} lines".PHP_EOL;
		echo printValue($cmd);
		return;
	}
	foreach($cmdlines as $cmdline){
		$cmdline=trim($cmdline);
		$parts=preg_split('/[^a-z0-9\:\.]+/',$cmdline);
		if(count($parts) > 4){continue;}
		echo printValue($parts).PHP_EOL;
		if(!preg_match('/\./',$parts[0])){continue;}
		if(!preg_match('/\:/',$parts[2])){continue;}
		$ip=$parts[0];
		$mac=$parts[2];
		//make sure these are in the devices list
		$device=getDBRecord(array(
			'-table'=>'devices',
			'mac_address'=>$mac,
			'-fields'=>'_id'
		));
		if(!isset($device['_id'])){
		    $nodes=preg_split('/\./',$ip);
			$id=addDBRecord(array(
				'-table'=>'devices',
				'mac_address'=>$mac,
				'ip_address'=>$ip,
				'ip_node'=>array_pop($nodes),
				'name'=>$ip
			));
		}
	}
	$recs=getDBRecords(array(
		'-table'=>'devices',
		'-fields'=>'_id,mac_address,ip_address,name',
		'-index'=>'ip_address'
	));
	return $recs;
}
function templateGetNiftySettings(){
	global $templateGetNiftySettings;
	if(is_array($templateGetNiftySettings)){return $templateGetNiftySettings;}
	$recs=getDBRecords(array(
		'-table'=>'_settings',
		'-where'=>"key_name like 'niftyns%'",
		'-fields'=>'key_name,key_value,description,_id',
		'-index'=>'key_name'
	));
	$templateGetNiftySettings=array();
	foreach($recs as $key=>$rec){
    	$templateGetNiftySettings[$key]=$rec['key_value'];
	}
	return $templateGetNiftySettings;
}
function dnsmasqLog2db(){
	$query_name='';
	$query_from='';
	$niftyns=templateGetNiftySettings();
	if(isset($niftyns['niftyns_history_limit']) && isNum($niftyns['niftyns_history_limit'])){
		echo "Purging dnsmasq_log table to {$niftyns['niftyns_history_limit']} days\n";
		$ok=cleanupDBRecords('dnsmasq_log',$niftyns['niftyns_history_limit'],'logdate');
	}
	//clean up dnsmasq_log
	$macmap=dnsmasqGetMacMap();
	$recs=getDBRecords("select distinct(from_ip) as ip from dnsmasq_log where from_mac is null or from_mac=''");
	if(is_array($recs)){
		$rcnt=count($recs);
		echo "Fixing {$rcnt} null MAC addresses\n";
		foreach($recs as $rec){
	    	if(isset($macmap[$rec['ip']])){
				$from_device=str_replace("'","''",$macmap[$rec['ip']]['name']);
	        	$query="update dnsmasq_log set from_device='{$from_device}',from_mac='{$macmap[$rec['ip']]['mac_address']}' where from_ip='{$rec['ip']}' and (from_mac is null or from_mac='')";
				$ok=executeSQL($query);
			}
		}
	}
	//echo printValue($recs);exit;
	//echo printValue($niftyns);exit;
	if(is_file('/etc/dnsmasq.loading')){
		echo "Removing old dnsmasq.loading file\n";
		unlink('/etc/dnsmasq.loading');
	}
	//copy the log file to .loading
	echo "Copying /etc/dnsmasq.log to /etc/dnsmasq.loading\n";
	$b=copyFile('/etc/dnsmasq.log','/etc/dnsmasq.loading');

	//clear the log
	setFileContents('/etc/dnsmasq.log','');
	///
	$file='/etc/dnsmasq.loading';
	echo "Reading {$file}\n";
	$cnt=0;
	if ($fh = fopen($file,'r')) {
		while (!feof($fh)) {
			//stream_get_line is significantly faster than fgets
			$line = stream_get_line($fh, 1000000, "\n");
			//echo "{$line}<br>\n";
			if(preg_match('/^(.+?)dnsmasq\[(.+?)\]\:\ query\[(A|AAAA)\]\ (.+?)\ from\ (.+)$/i',$line,$m)){
				$query_name=$m[4];
				$query_from=$m[5];
			}
			if(!strlen($query_name)){
				//$newlines[]=$line;
				continue;
			}
			if($query_from=='127.0.0.1'){
            	continue;
			}
			if($macmap[$query_from]=='p1p1'){
            	continue;
			}

			if(preg_match('/^(.+?)dnsmasq\[(.+?)\]\:\ (reply|config|cached)\ (.+?)\ is\ (.+)$/',$line,$m)){
				$timestamp=strtotime(trim($m[1]));
				$record=1;
				$type='PAS';
				if($query_name==$m[4]){
					$to_ip=$m[5];
					$host=preg_replace('/\.Home$/i','',$m[4]);
					$unique_host=dnsmasqUniqueHostName($host);
					//try to determine type
					$type=dnsmasqGetScheduleType($host);
					if(!strlen($type)){$type=dnsmasqGetServerType($host,$to_ip);}

					if(!strlen($type)){$type='PAS';}

					$opts=array(
						'-table'	=> 'dnsmasq_log',
						'logdate'	=> date('Y-m-d H:i',strtotime($m[1])),
						'host'		=> $host,
						'unique_host'=>$unique_host,
						'type'		=> $type,
	  					'cloud'		=> 0,
						'from_ip'	=> $query_from,
						'from_mac'	=> $macmap[$query_from]['mac_address'],
						'from_os'	=> dnsmasqGetHostOS($query_from)
					);
					$crc=encodeCRC(printValue($opts));
					if(!getDBCount(array('-table'=>'dnsmasq_log','crc'=>$crc))){
						$opts['to_ip']=$to_ip;
						$opts['crc']=$crc;
						$opts['from_device']=$macmap[$query_from]['name'];
						$ok=addDBRecord($opts);
						$cnt++;
					}
					if($type=='PAS' && !preg_match('/\.(local|google|amazon|microsoft)/i,',$host)){
						$cdate=date('Y-m-d H:i:s');
						$q="insert ignore into dnsmasq (name,type,dirty,_cdate) values('{$host}','{$type}',0,'{$cdate}')";
						executeSQL($q);
					}
				}
			}
		}
		fclose($fh);
	}
	else{
    	echo "No file:{$file}<br>\n";
	}
	unlink($file);
	return $cnt;
}
function dnsmasqUniqueHostName($str){
	global $dnsmasqUniqueHostNameCache;
	if(isset($dnsmasqUniqueHostNameCache[$str])){return $dnsmasqUniqueHostNameCache[$str];}
	$dnsmasqUniqueHostNameCache[$str]=getUniqueHost($str);
	return $dnsmasqUniqueHostNameCache[$str];
}
function dnsmasqGetServerType($host,$to_ip=''){
	global $dnsmasqGetServerTypeCache;
	if(isset($dnsmasqGetServerTypeCache[$host])){return $dnsmasqGetServerTypeCache[$host];}
	//check
	$rec=getDBRecord(array(
		'-table'=>'dnsmasq',
		'name'=>$host,
		'-fields'=>'_id,type'
	));
	//echo printValue($rec);
	if(isset($rec['type'])){
		$dnsmasqGetServerTypeCache[$host]=$rec['type'];
	}
	else{
		$unique_host=dnsmasqUniqueHostName($host);
		$rec=getDBRecord(array(
			'-table'=>'dnsmasq',
			'name'=>$unique_host,
			'-fields'=>'_id,type'
		));
		if(isset($rec['type'])){
			$dnsmasqGetServerTypeCache[$host]=$rec['type'];
		}
		else{
			//check for norton blocked sites
			if($to_ip=='156.154.176.217'){
                $dnsmasqGetServerTypeCache[$host]='D2';
                $id=addDBRecord(array(
					'-table'=>'dnsmasq',
					'name'=>$host,
					'type'=>'D2',
					'dirty'=>1
				));
				return $dnsmasqGetServerTypeCache[$host];
			}
    		$dnsmasqGetServerTypeCache[$host]='PAS';
		}
	}
	return $dnsmasqGetServerTypeCache[$host];
}
function dnsmasqGetHostOS($ip){
	global $dnsmasqGetHostOSCache;
	if(isset($dnsmasqGetHostOSCache[$ip])){
    	return $dnsmasqGetHostOSCache[$ip];
	}
	$cmd=cmdResults("ping -c 1 {$ip}");
	$dnsmasqGetHostOSCache[$ip]='Unknown';
	preg_match("/ttl=([0-9]+?)\ time/s", $cmd['stdout'], $m);
	if(isset($m[1])){
		switch($m[1]){
	    	case 64:$dnsmasqGetHostOSCache[$ip]='Unix';break;
	    	case 128:$dnsmasqGetHostOSCache[$ip]='Windows';break;
	    	case 256:$dnsmasqGetHostOSCache[$ip]='Solaris/AIX';break;
		}
	}
	return $dnsmasqGetHostOSCache[$ip];
}
function dnsmasqSendToCloud(){
	$niftyns=templateGetNiftySettings();
	echo "Send2Cloud:{$niftyns['niftyns_send2cloud']}\n";
	if(isset($niftyns['niftyns_send2cloud']) && $niftyns['niftyns_send2cloud']==1){
		$recs=getDBRecords(array(
			'-table'=>'dnsmasq_log',
			'cloud'=> 0
		));
		if(!is_array($recs) || !count($recs)){exit;}
		echo "Rec Count:".count($recs)."\n";
		$url="http://www.niftyns.com/niftyapi/logs/".$niftyns['niftyns_apikey'];
		echo "Post URL:{$url}\n";
		$device_key=rtrim(getFileContents('/etc/niftyns.key'));
		foreach($recs as $i=>$rec){
			$query="update dnsmasq_log set cloud=1 where _id={$rec['_id']}";
			$ok=executeSQL($query);
			unset($recs[$i]['_id']);
			unset($recs[$i]['_euser']);
			unset($recs[$i]['_edate']);
			$recs[$i]['device_key']=$device_key;
		}
		//group recs into groups of 1000
		$groups=array_chunk($recs,500);
		foreach($groups as $recs){
			$json=json_encode($recs);
			$b64=base64_encode(base64_encode($json));
			$p=postJSON($url,$b64,array('-ssl'=>1));
			echo "{$p['body']}\n";
		}
		return count($recs);
	}
 	return 0;
}
