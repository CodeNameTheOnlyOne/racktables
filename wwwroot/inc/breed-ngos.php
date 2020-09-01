<?php

# This file is a part of RackTables, a datacenter and server room management
# framework. See accompanying file "COPYING" for the full copyright and
# licensing information.



function ngosReadLLDPStatus ($input)
{
    error_log($input);
	$ret = array();
	$got_header = FALSE;
	foreach (explode ("\n", $input) as $line)
	{
        
		if (preg_match ("/Device ID/", $line)){
            $got_header = TRUE;
            continue;
        }    
            
            

		if (!$got_header)
            continue;
        
        $matches = preg_split ("/\|/", trim ($line));
        switch (count ($matches))
        {
            case 6:
            list ( $local_port,$remote_mac, $remote_port ,$remote_name, $caps, $ttl) = $matches;
            
            $ret[trim($local_port)][] = array
                (
                    'device' => trim($remote_name),
                    'port' => trim($remote_port),
                );
        }


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

function ngosReadMacList ($input)
{
    
	$ret = array();
	$got_header = FALSE;
	foreach (explode ("\n", $input) as $line)
	{
        
		if (preg_match ("/VID/", $line)){
            $got_header = TRUE;
            continue;
        }    
            
            

		if (!$got_header)
            continue;
        
        $matches = preg_split ("/\|/", trim ($line));
        switch (count ($matches))
        {
            case 4:
            list ( $vid,$mac, $type ,$local_port) = $matches;
            
            $ret[trim($local_port)][] = array(
				'mac' => trim($mac),
				'vid' => trim($vid),
			);
        }


	}
	return $ret;
}

function ngosRead8021QConfig ($input)
{
	$got_header = FALSE;
	$ret = constructRunning8021QConfig();
	foreach (explode ("\n", $input) as $line)
	{
        
		if (preg_match ("/VID/", $line)){
            $got_header = TRUE;
            continue;
		}
		if (!$got_header)
		continue;
		error_log($line);
		$matches = preg_split ("/\|/", trim ($line));
        switch (count ($matches))
        {
            case 5:
            list ( $vid,$VlanName, $UtPorts ,$tagPorts, $type) = $matches;
            
            $ret['vlanlist'][] = intval (trim($vid));
        }

	} 
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
			$ret .='show vlan static';
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