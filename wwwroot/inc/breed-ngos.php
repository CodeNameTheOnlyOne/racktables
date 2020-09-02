<?php

# This file is a part of RackTables, a datacenter and server room management
# framework. See accompanying file "COPYING" for the full copyright and
# licensing information.



function ngosReadLLDPStatus($input)
{
	error_log($input);
	$ret = array();
	$got_header = FALSE;
	foreach (explode("\n", $input) as $line) {

		if (preg_match("/Device ID/", $line)) {
			$got_header = TRUE;
			continue;
		}



		if (!$got_header)
			continue;

		$matches = preg_split("/\|/", trim($line));
		switch (count($matches)) {
			case 6:
				list($local_port, $remote_mac, $remote_port, $remote_name, $caps, $ttl) = $matches;

				$ret[trim($local_port)][] = array(
					'device' => trim($remote_name),
					'port' => trim($remote_port),
				);
		}
	}
	return $ret;
}
function ngosReadInterfaceStatus($text)
{
	$result = array();
	$state = 'headerSearch';
	$port_id=null;
	foreach (explode("\n", $text) as $line) {
		switch(true){
			case (preg_match("/GigabitEthernet/", $line)):
				$port_id=preg_replace('/(\d+)|\D+/m','$1',$line);
				
				
				


			break;
		}

	}
	return $result;
}

function ngosReadMacList($input)
{

	$ret = array();
	$got_header = FALSE;
	foreach (explode("\n", $input) as $line) {

		if (preg_match("/VID/", $line)) {
			$got_header = TRUE;
			continue;
		}



		if (!$got_header)
			continue;

		$matches = preg_split("/\|/", trim($line));
		switch (count($matches)) {
			case 4:
				list($vid, $mac, $type, $local_port) = $matches;

				$ret[trim($local_port)][] = array(
					'mac' => trim($mac),
					'vid' => trim($vid),
				);
		}
	}
	return $ret;
}

function ngosRead8021QConfig($input)
{
	$return_vlan = FALSE;
	$vlan_done = false;
	$return_if = false;
	$ret = constructRunning8021QConfig();
	$vid = 0;
	$ret['vlanlist'][] = VLAN_DFL_ID;
	$port_id=0;
	foreach (explode("\n", $input) as $line) {

		if (preg_match("/^vlan \d+/", $line) && !$vlan_done) {
			$matches = preg_split("/\s/", trim($line));
			list($vlan, $vid) = $matches;
			$vid = intval(trim($vid));
			$ret['vlanlist'][] = $vid;
			$return_vlan = true;
			continue;
		}
		if ($return_vlan) {
			if (preg_match("/^!/", $line)) continue;
			$matches = preg_split("/\s/", trim($line));
			list($name, $vlanName) = $matches;
			$ret['vlannames'][] = array(

				$vid => trim($vlanName)
			);
			$return_vlan = false;
			continue;
		}
		
		if (preg_match("/^interface/", $line)) {
			$matches = preg_split("/\s/", trim($line));
			list($header, $port_id) = $matches;
			$port_id = trim($port_id);
			$ret['portconfig'][$port_id][] = array('type' => 'line-header', 'line' => 'interface ' . $port_id);
			$return_if = true;
			continue;
		}
		if ($return_if) {
			$ret['portconfig'][$port_id][] = array('type' => 'line', 'line' => $line);
			
			if (preg_match("/^!/", $line)) {
				$return_if = false;
				continue;
			} else {
				if (preg_match("/switchport/", $line)) {					
					switch (true) {
						case preg_match("/hybrid/", $line):
							$matches = preg_split("/\s/", trim($line));
							if($matches[6]=="tagged"  )
								$mode="trunk";
								else {
									$mode ="access";
								}
							$vlans = preg_split("/,/", trim($matches[5]));
							$vlanarray = array();
							foreach ($vlans as $vlan) {
								if (preg_match("/-/", $vlan)) {
									$splitrange = preg_split("/-/", $vlan);
									foreach (range($splitrange[0], $splitrange[1]) as $range) {
										$vlanarray[] = $range;
									}
								}
								$vlan = intval(trim($vlan));
								$vlanarray[] = $vlan;
							}
							$ret['portdata'][$port_id] = array('mode' => $mode, 'allowed' => $vlanarray, 'native' => 1);
						break;
							case preg_match("/pvid/", $line):



						break;

						default:
					}
				} else {
					
				}
			}
		}
	}


	return $ret;
}

function ngosTranslatePushQueue($dummy_object_id, $queue, $dummy_vlan_names)
{
	xdebug_break();
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode']) {
			case 'create VLAN':
				$ret .= "vlan ${cmd['arg1']}\nexit\n";
				break;
			case 'destroy VLAN':
				$ret .= "no vlan ${cmd['arg1']}\n";
				break;
			case 'add allowed':
			case 'rem allowed':
				$clause = $cmd['opcode'] == 'add allowed' ? 'add' : 'remove';
				$ret .= "interface ${cmd['port']}\n";
				foreach (listToRanges($cmd['vlans']) as $range)
					$ret .= "switchport trunk allowed vlan ${clause} " .
						($range['from'] == $range['to'] ? $range['to'] : "${range['from']}-${range['to']}") .
						"\n";
				$ret .= "exit\n";
				break;
			case 'set native':
				$ret .= "interface ${cmd['arg1']}\nswitchport trunk native vlan ${cmd['arg2']}\nexit\n";
				break;
			case 'unset native':
				$ret .= "interface ${cmd['arg1']}\nno switchport trunk native vlan ${cmd['arg2']}\nexit\n";
				break;
			case 'set access':
				$ret .= "interface ${cmd['arg1']}\nswitchport access vlan ${cmd['arg2']}\nexit\n";
				break;
			case 'unset access':
				$ret .= "interface ${cmd['arg1']}\nno switchport access vlan\nexit\n";
				break;
			case 'set mode':
				$ret .= "interface ${cmd['arg1']}\n";
				if ($cmd['arg2'] == 'trunk')
					$ret .= "switchport trunk encapsulation dot1q\n";
				$ret .= "switchport mode ${cmd['arg2']}\n";
				if ($cmd['arg2'] == 'trunk')
					$ret .= "no switchport trunk native vlan\nswitchport trunk allowed vlan none\n";
				$ret .= "exit\n";
				break;
			case 'begin configuration':
				$ret .= "configure terminal\n";
				break;
			case 'end configuration':
				$ret .= "end\n";
				break;
			case 'save configuration':
				$ret .= "copy running-config startup-config\n\n";
				break;
			case 'cite':
				$ret .= $cmd['arg1'];
				break;
				// query list
			case 'get8021q':
				$ret .= 'show run';
				break;
			case 'getcdpstatus':
				$ret .= "show cdp neighbors detail\n";
				break;
			case 'getlldpstatus':
				$ret .= "show lldp neighbor\n";
				break;
			case 'getportstatus':
				$ret .= "show interfaces GigabitEthernet 1-28\n";
				break;
			case 'getmaclist':
				$ret .= "show mac address-table dynamic\n";
				break;
			case 'getportmaclist':
				$ret .= "show mac address-table dynamic interface {$cmd['arg1']}\n";
				break;
			case 'getallconf':
				$ret .= "show running-config\n";
				break;
			default:
				throw new InvalidArgException('opcode', $cmd['opcode']);
		}
	return $ret;
}


function ngosSpotConfigText($input)
{
	return $input;
}
