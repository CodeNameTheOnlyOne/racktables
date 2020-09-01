<?php

# This file is a part of RackTables, a datacenter and server room management
# framework. See accompanying file "COPYING" for the full copyright and
# licensing information.



function ngosReadLLDPStatus ($input)
{
    error_log($line);
	$ret = array();
	$got_header = FALSE;
	foreach (explode ("\n", $input) as $line)
	{
        
		if (preg_match ("/^Device ID/", $line))
            $got_header = TRUE;
            continue;

		if (!$got_header)
			continue;

        $matches = preg_split ('\|', trim ($line));
        list ( $local_port,$remote_mac, $remote_port ,$remote_name, $caps, $ttl) = $matches;
        $ret[$local_port][] = array
			(
				'device' => $remote_name,
				'port' => $remote_port,
			);


	}
	return $ret;
}
function ngosReadInterfaceStatus ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
		case 'headerSearch':
			if (preg_match ('/\s?Port\s+Type\s+/', $line))
			{
				$name_field_borders = getColumnCoordinates ($line, 'Port');
				if (isset ($name_field_borders['from']))
					$state = 'readPort';
			}
			break;
		case 'readPort':
			if (preg_match('/^[0-9]+/', trim (substr ($line, 0, $name_field_borders['length'])), $matches))
				$portname = $matches[0];
			if (! isset($portname))
				$portname = NULL;
			if (preg_match ('/^[0-9]+.+/', trim (substr ($line, $name_field_borders['from'] + $name_field_borders['length'] + 1)), $matches))
				$rest = $matches[0];
			if (! isset($rest))
				$rest = NULL;

			$field_list = preg_split ('/\s+/', $rest);
			if (count ($field_list) < 4)
				break;
			list ($type, $delim, $alert, $adm_status, $status_raw, $mode) = $field_list;
			if ($status_raw == 'Up')
				$status = 'up';
			elseif ($status_raw == 'Down')
				$status = 'down';
			elseif ($adm_status == 'No')
				$status = 'disabled';
			if (preg_match ('/([01]+)/', $mode, $matches))
				$speed = $matches[0];
			if (preg_match ('/([a-zA-Z]+)/', $mode, $matches))
				$duplex = $matches[0];
			$result[$portname] = array
			(
				'status' => $status,
				'speed' => $speed,
				'duplex' => $duplex,
			);
			break;
		}
	}
	return $result;
}

function ngosReadMacList ($text)
{
	$result = array();
	$state = 'headerSearch';
	foreach (explode ("\n", $text) as $line)
	{
		switch ($state)
		{
		case 'headerSearch':
			if (preg_match ('/\s?MAC Address\s+Located on Port\s?/', $line))
				$state = 'readPort_all';
			elseif (preg_match ('/^\s*Status and Counters -\s*Port Address Table -\s*([0-9]+)$/', $line, $portdata))
			{
				$state = 'readPort_single';
				$portname = $portdata[1];
			}
			break;
		case 'readPort_all':
			if (! preg_match ('/^\s*([a-f0-9]{6}\-[a-f0-9]{6})\s*(\S+)$/', trim ($line), $matches))
				break;
			$portname = shortenIfName ($matches[2]);
			$vid = NULL;
			$result[$portname][] = array
			(
				'mac' => implode (":", str_split (str_replace ('-', '', $matches[1]), 2)),
				'vid' => '',
			);
			break;
		case 'readPort_single':
			if (! preg_match ('/^\s*([a-f0-9]{6}\-[a-f0-9]{6})\s*$/', trim ($line), $matches))
				break;
			$vid = NULL;
			$result[$portname][] = array
			(
				'mac' => implode (":", str_split (str_replace ('-', '', $matches[1]), 2)),
				'vid' => '',
			);
			break;
		}
	}
	foreach ($result as $portname => &$maclist)
		sort ($maclist);
	return $result;
}

function ngosRead8021QConfig ($input)
{
	$ret = constructRunning8021QConfig();
	$ret['vlanlist'][] = VLAN_DFL_ID; // HP hides VLAN1 from config text
	$matches = array();
	$vlanlist = array();
	$rawdata = explode ("Status and Counters - VLAN Information - for ports", $input);
	array_shift ($rawdata);

	foreach ($rawdata as $line)
	{
		$port = array
		(
			'port_id' => '',
			'port_name' => '',
			'vlan_data' => array(),
			'port_mode' => FALSE,
		);
		$port_mode = '';
		foreach (explode ("\n", $line) as $vlans)
		{
			if (preg_match ('/^ VLAN ID Name |^\s*-------/', $vlans))
				continue;
			if (preg_match ('/^((?:[0-9]+)|(?:[Tt]rk[0-9]+))$/', trim ($vlans), $matches))
			{
				$port['port_id'] = $matches[1];
				continue;
			}
			if (preg_match ('/^\s+Port name: (.+)$/', $vlans, $matches))
			{
				$port['port_name'] = $matches[1];
				continue;
			}
			if (preg_match ('/^\S*\s*(\d+)\s+(\S+)\s+\S+\s+\S+\s+([T]agged|[U]ntagged).*$/', $vlans, $matches))
			{
				$port['vlan_data'][$matches[1]]['vlan_name'] = $matches[2];
				$port['vlan_data'][$matches[1]]['vlan_mode'] = $matches[3];
				$vlanlist[] = $matches[1];
			}
		}
		// Here we add parsed data into $ret array
		$port_id = $port['port_id'];
		$port_name = $port['port_name'];

		// Port config
		$ret['portconfig'][$port_id][] = array ('type' => 'line-header', 'line' => 'interface ' . $port_id);
		if ($port_name != '')
			$ret['portconfig'][$port_id][] = array ('type' => 'line-other', 'line' => 'name ' . $port_name);

		// Port data
		$allowed_vlans = array();
		if (array_search ('Tagged', array_column ($port['vlan_data'], 'vlan_mode')) === FALSE)
		{
			$port_mode = 'access';
			foreach ($port['vlan_data'] as $vid => $value)
			{
				$allowed_vlans[] = $vid;
				$native = $vid;
			}
		}
		else
		{
			$port_mode = 'trunk';
			foreach ($port['vlan_data'] as $vid => $value)
				if (preg_match ('/\d+/', $vid))
					$allowed_vlans[] = $vid;
			foreach ($port['vlan_data'] as $vid => $value)
				if (preg_match ('/\d+/', $vid) && $value['vlan_mode'] === "Untagged")
				{
					$native = $vid;
					break;
				}
				else
					$native = 0;
		}
		$ret['portdata'][$port_id] = array ('mode' => $port_mode, 'allowed' => $allowed_vlans, 'native' => $native);

		unset ($port);
		unset ($allowed_vlans);
	}
	// Return de-duplicated and sorted list of vlans
	$ret['vlanlist'] = array_merge ($ret['vlanlist'], array_keys (array_flip ($vlanlist)));
	sort ($ret['vlanlist']);
	unset ($vlanlist);
	unset ($matches);
	unset ($rawdata);
	return $ret;
}

function ngosTranslatePushQueue ($dummy_object_id, $queue, $dummy_vlan_names)
{
	$ret = '';
	foreach ($queue as $cmd)
		switch ($cmd['opcode'])
		{
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
			foreach (listToRanges ($cmd['vlans']) as $range)
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
			$ret .=
'show interface switchport | incl Name:|Switchport:
! END OF SWITCHPORTS
show run
! END OF CONFIG
show vlan
! END OF VLAN LIST
';
			break;
		case 'getcdpstatus':
			$ret .= "show cdp neighbors detail\n";
			break;
		case 'getlldpstatus':
			$ret .= "show lldp neighbor\n";
			break;
		case 'getportstatus':
			$ret .= "show int status\n";
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
			throw new InvalidArgException ('opcode', $cmd['opcode']);
		}
	return $ret;
}


function ngosSpotConfigText ($input)
{
	return $input;
}
