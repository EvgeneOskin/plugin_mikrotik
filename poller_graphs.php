#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2017 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

$no_http_headers = true;

chdir(dirname(__FILE__));
chdir('../..');
include('./include/global.php');
include_once('./lib/poller.php');
include_once('./lib/data_query.php');
ini_set('memory_limit', '256M');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug, $start, $seed, $forcerun;

$debug    = false;
$forcerun = false;
$start    = time();

foreach($parms as $parameter) {
	@list($arg, $value) = @explode('=', $parameter);

	switch ($arg) {
	case '--debug':
	case '-d':
		$debug = true;
		break;
	case '--force':
	case '-f':
		$forcerun = true;
		break;
	case '--version':
	case '-V':
	case '-v':
		display_version();
		exit;
	case '--help':
	case '-H':
	case '-h':
		display_help();
		exit;
	default:
		print 'ERROR: Invalid Parameter ' . $parameter . "\n\n";
		display_help();
		exit;
	}
}

/* Do not process if not enabled */
if (read_config_option('mikrotik_enabled') == '' || db_fetch_cell("SELECT status FROM plugin_config WHERE directory='mikrotik'") != 1) {
	echo "WARNING: The MiktroTik Collection is Down!  Exiting\n";
	exit(0);
}

/* see if its time to run */
$last_run  = read_config_option('mikrotik_automation_lastrun');
$frequency = read_config_option('mikrotik_automation_frequency') * 60;
debug("Last Run Was '" . date('Y-m-d H:i:s', $last_run) . "', Frequency is '" . ($frequency/60) . "' Minutes");

if ($frequency == 0) {
	echo "NOTE:  Graph Automation is Disabled\n";
}elseif (($frequency > 0 && ($start - $last_run) > $frequency) || ($frequency > 0 && $forcerun)) {
	list($micro,$seconds) = explode(' ', microtime());
	$start = $seconds + $micro;

	echo "NOTE:  Starting Automation Process\n";
	db_execute("REPLACE INTO settings (name,value) VALUES ('mikrotik_automation_lastrun', '$start')");

	add_graphs();

	list($micro,$seconds) = explode(' ', microtime());
	$end = $seconds + $micro;

        $cacti_stats = sprintf('Time:%01.4f ', round($end-$start,2));

        /* log to the database */
        db_execute("REPLACE INTO settings (name,value) VALUES ('stats_mikrotik_graphs', '" . $cacti_stats . "')");

        /* log to the logfile */
        cacti_log('MIKROTIK GRAPH STATS: ' . $cacti_stats , true, 'SYSTEM');
}else{
	echo "NOTE:  Its Not Time to Run Automation\n";
}

exit(0);

function add_graphs() {
	global $config;

//	/* check for summary changes first */
//	$host_template = read_config_option('mikrotik_summary_host_template');
//	$host_type_dq  = read_config_option('mikrotik_dq_host_type');
//	if (!empty($host_template)) {
//		/* check to see if the template exists */
//		debug('Host Template Set');
//
//		if (db_fetch_cell("SELECT count(*) FROM host_template WHERE id=$host_template")) {
//			debug('Host Template Exists');
//
//			$host_id = db_fetch_cell("SELECT id FROM host WHERE host_template_id=$host_template");
//			if (empty($host_id)) {
//				debug('MikroTik Summary Device Not Found, Adding');
//			}else{
//				debug("Host Exists Hostname is '" . db_fetch_cell("SELECT description FROM host WHERE id=$host_id"). "'");
//			}
//
//
//			add_summary_graphs($host_id, $host_template);
//		}else{
//			cacti_log('WARNING: Unable to find MikroTik Summary Host Template', true, 'MIKROTIK');
//		}
//	}else{
//		cacti_log('NOTE: MikroTik Summary Host Template Not Specified', true, 'MIKROTIK');
//	}

	add_host_based_graphs();
}

function add_host_based_graphs() {
	global $config, $device_hashes, $device_query_hashes, $device_health_hashes;

	debug('Adding Host Based Graphs');

	/* check for host level graphs next data queries */
	$host_cpu_dq   = read_config_option('mikrotik_dq_host_cpu');
	$host_users_dq = mikrotik_data_query_by_hash('ce63249e6cc3d52bc69659a3f32194fe');

	$hosts = db_fetch_assoc("SELECT host_id, host.description, host.hostname 
		FROM plugin_mikrotik_system
		INNER JOIN host
		ON host.id=plugin_mikrotik_system.host_id
		WHERE host_status IN(0,3) AND host.disabled=''");

	if (sizeof($hosts)) {
		foreach($hosts as $h) {
			debug('Processing Host: ' . $h['description'] . ' [' . $h['hostname'] . ']');

			foreach($device_hashes as $hash) {
				$template = mikrotik_template_by_hash($hash);
				if (!empty($template)) {
					debug('Processing ' . db_fetch_cell_prepared('SELECT name FROM graph_templates WHERE hash = ?', array($hash)));
					mikrotik_gt_graph($h['host_id'], $template);
				}
			}

			foreach($device_query_hashes as $hash) {
				$query = mikrotik_data_query_by_hash($hash);
				if (!empty($query)) {
					debug('Processing ' . db_fetch_cell_prepared('SELECT name FROM snmp_query WHERE hash = ?', array($hash)));
					if ($hash == '7dd90372956af1dc8ec7b859a678f227') {
						$exclusion = read_config_option('mikrotik_user_exclusion');
						add_host_dq_graphs($h['host_id'], $query, 'userName', $exclusion, false);
					}else{
						add_host_dq_graphs($h['host_id'], $query);
					}
				}
			}

			$health = db_fetch_row_prepared('SELECT * FROM plugin_mikrotik_system_health WHERE host_id = ?', array($h['host_id']));
			debug('Processing Health');
			if (sizeof($health)) {
				foreach($device_health_hashes as $column => $hash) {
					if (!empty($health[$column]) && $health[$column] != 'NULL') {
						$template = mikrotik_template_by_hash($hash);
						if (!empty($template)) {
							debug('Processing ' . db_fetch_cell_prepared('SELECT name FROM graph_templates WHERE hash = ?', array($hash)));
							mikrotik_gt_graph($h['host_id'], $template);
						}
					}
				}
			}
		}
	}else{
		debug('No Hosts Found');
	}
}

function add_host_dq_graphs($host_id, $dq, $field = '', $regex = '', $include = true) {
	global $config;

	/* add entry if it does not exist */
	$exists = db_fetch_cell("SELECT count(*) FROM host_snmp_query WHERE host_id=$host_id AND snmp_query_id=$dq");
	if (!$exists) {
		db_execute("REPLACE INTO host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES ($host_id, $dq, 1)");
	}

	/* recache snmp data */
	debug('Reindexing Host');
	run_data_query($host_id, $dq);

	$graph_templates = db_fetch_assoc('SELECT * 
		FROM snmp_query_graph 
		WHERE snmp_query_id=' . $dq);

	debug('Adding Graphs');
	if (sizeof($graph_templates)) {
	foreach($graph_templates as $gt) {
		mikrotik_dq_graphs($host_id, $dq, $gt['graph_template_id'], $gt['id'], $field, $regex, $include);
	}
	}
}

function mikrotik_gt_graph($host_id, $graph_template_id) {
	global $config;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];
	$name    = db_fetch_cell("SELECT name FROM graph_templates WHERE id=$graph_template_id");
	$assoc   = db_fetch_cell("SELECT count(*) 
		FROM host_graph 
		WHERE graph_template_id=$graph_template_id 
		AND host_id=$host_id");

	if (!$assoc) {
		db_execute("INSERT INTO host_graph (host_id, graph_template_id) VALUES ($host_id, $graph_template_id)");
	}

	$exists = db_fetch_cell("SELECT count(*) 
		FROM graph_local 
		WHERE host_id=$host_id 
		AND graph_template_id=$graph_template_id");

	if (!$exists) {
		echo "NOTE: Adding Graph: '$name' for Host: " . $host_id . "\n";
	
		$command = "$php_bin -q $base/cli/add_graphs.php" .
			" --graph-template-id=$graph_template_id" .
			" --graph-type=cg" .
			" --host-id=" . $host_id;
	
		echo str_replace("\n", " ", passthru($command)) . "\n";
	}
}

function add_summary_graphs($host_id, $host_template) {
	global $config;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];

	$return_code = 0;
	if (empty($host_id)) {
		/* add the host */
		debug('Adding Host');
		$result = exec("$php_bin -q $base/cli/add_device.php --description='Summary Device' --ip=summary --template=$host_template --version=0 --avail=none", $return_code);
	}else{
		debug('Reindexing Host');
		$result = exec("$php_bin -q $base/cli/poller_reindex_hosts.php -id=$host_id -qid=All", $return_code);
	}

	/* data query graphs first */
	debug('Processing Data Queries');
	$data_queries = db_fetch_assoc("SELECT * 
		FROM host_snmp_query 
		WHERE host_id=$host_id");

	if (sizeof($data_queries)) {
	foreach($data_queries as $dq) {
		$graph_templates = db_fetch_assoc("SELECT * 
			FROM snmp_query_graph 
			WHERE snmp_query_id=" . $dq['snmp_query_id']);

		if (sizeof($graph_templates)) {
		foreach($graph_templates as $gt) {
			mikrotik_dq_graphs($host_id, $dq['snmp_query_id'], $gt['graph_template_id'], $gt['id']);
		}
		}
	}
	}

	debug('Processing Graph Templates');
	$graph_templates = db_fetch_assoc("SELECT *
		FROM host_graph
		WHERE host_id=$host_id");

	if (sizeof($graph_templates)) {
	foreach($graph_templates as $gt) {
		/* see if the graph exists already */
		$exists = db_fetch_cell("SELECT count(*) 
			FROM graph_local 
			WHERE host_id=$host_id 
			AND graph_template_id=" . $gt["graph_template_id"]);

		if (!$exists) {
			echo "NOTE: Adding item: '$field_value' for Host: " . $host_id;
	
			$command = "$php_bin -q $base/cli/add_graphs.php" .
				" --graph-template-id=" . $gt["graph_template_id"] . 
				" --graph-type=cg" .
				" --host-id=" . $host_id;
	
			echo str_replace("\n", " ", passthru($command)) . "\n";
		}
	}
	}
}

function mikrotik_dq_graphs($host_id, $query_id, $graph_template_id, $query_type_id, $field = '', $regex = '', $include = true) {
	global $config, $php_bin, $path_grid;

	$php_bin = read_config_option('path_php_binary');
	$base    = $config['base_path'];

	if ($field == '') {
		$field = db_fetch_cell("SELECT sort_field 
			FROM host_snmp_query 
			WHERE host_id=$host_id AND snmp_query_id=" . $query_id);
	}

	$items = db_fetch_assoc("SELECT * 
		FROM host_snmp_cache 
		WHERE field_name='$field' 
		AND host_id=$host_id 
		AND snmp_query_id=$query_id");

	if (sizeof($items)) {
		foreach($items as $item) {
			$field_value = $item['field_value'];
			$index       = $item['snmp_index'];

			if ($regex == '') {
				/* add graph below */
			}else if ($include == false && preg_match("/$regex/", $field_value)) {
				echo "NOTE: Bypassing item due to Regex rule: '$regex', Field Value: '" . $field_value . "' for Host: '" . $host_id . "'\n";
				continue;
			}else if ($include == true && preg_match("/$regex/", $field_value)) {
				/* add graph below, we should never be here */
			}else{
				echo "NOTE: Not Bypassing item due to Regex rule: '$regex', Field Value: '" . $field_value . "' for Host: '" . $host_id . "'\n";
			}
	
			/* check to see if the graph exists or not */
			$exists = db_fetch_cell("SELECT id 
				FROM graph_local 
				WHERE host_id=$host_id 
				AND snmp_query_id=$query_id 
				AND graph_template_id=$graph_template_id 
				AND snmp_index='$index'");
	 
			if (!$exists) {
				$command = "$php_bin -q $base/cli/add_graphs.php" .
					" --graph-template-id=$graph_template_id --graph-type=ds"     .
					" --snmp-query-type-id=$query_type_id --host-id=" . $host_id .
					" --snmp-query-id=$query_id --snmp-field=$field" .
					" --snmp-value=" . cacti_escapeshellarg($field_value);
	
				echo "NOTE: Adding item: '$field_value' " . str_replace("\n", " ", passthru($command)) . "\n";
			}
		}
	}
}

function debug($message) {
	global $debug;

	if ($debug) {
		echo 'DEBUG: ' . trim($message) . "\n";
	}
}

function display_version() {
	global $config;

	if (!function_exists('plugin_mikrotik_version')) {
		include_once($config['base_path'] . '/plugins/mikrotik/setup.php');
	}

	$info = plugin_mikrotik_version();
	echo "MikroTik Graph Automator, Version " . $info['version'] . ", " . COPYRIGHT_YEARS . "\n";
}

function display_help() {
	display_version();

	echo "\nThe MikroTik process that creates graphs for Cacti.\n\n";
	echo "usage: poller_graphs.php [-f] [-d]\n";
}
