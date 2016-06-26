<?php
/*
 * Copyright 2005-2014 the original author or authors.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

$dbtables = array(
	"${mysqlprefix}chatdevices" => array(
		"deviceid" => "int NOT NULL auto_increment PRIMARY KEY",
		"clientdeviceid" => "varchar(50) COLLATE utf8_unicode_ci NOT NULL",
		"devicename" => "varchar(50) COLLATE utf8_unicode_ci DEFAULT NULL",
	),
	
	"${mysqlprefix}chatmessagesfromdevice" => array(
		"id" => "int NOT NULL auto_increment PRIMARY KEY",
		"deviceid" => "int NOT NULL",
		"messageid" => "int NOT NULL",
		"devicemessageid" => "int NOT NULL",
		"msgtimestamp" => "datetime NOT NULL",
	),
	
	"${mysqlprefix}chatsyncedthreads" => array(
		"syncthreadid" => "int NOT NULL auto_increment PRIMARY KEY",
		"threadid" => "int NOT NULL",
		"deviceid" => "int NOT NULL",
		"state" => "int NOT NULL",
		"shownmessageid" => "int DEFAULT NULL",
		"agentid" => "int NOT NULL DEFAULT '0'",
	),

	"${mysqlprefix}chatsyncedmessages" => array(
		"syncmessageid" => "int NOT NULL auto_increment PRIMARY KEY",
		"messageid" => "int NOT NULL",
		"deviceid" => "int NOT NULL",
	),

	"${mysqlprefix}chatoperatorsession" => array(
		"id" => "int NOT NULL auto_increment PRIMARY KEY",
		"oprtoken" => "varchar(20) NOT NULL",
		"dtmexpires" => "datetime DEFAULT NULL",
		"operatorid" => "int NOT NULL",
		"deviceid" => "int NOT NULL",
		"inprogress" => "tinyint NOT NULL DEFAULT '1'",
		"dtmstarted" => "timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP",
	)
);

$dbtables_indexes = array();

$dbtables_unique_keys = array(
	"${mysqlprefix}chatdevices" => array(
		"CLIENTDEVICEIDX" => "clientdeviceid"
	),

	"${mysqlprefix}chatsyncedthreads" => array(
		"device_thread" => "threadid, deviceid"
	),

	"${mysqlprefix}chatoperatorsession" => array(
		"oprtoken" => "oprtoken"
	)
);

$memtables = array();

$dbtables_can_update = array(
	"${mysqlprefix}chatdevices" => array(),
	"${mysqlprefix}chatmessagesfromdevice" => array(),
	"${mysqlprefix}chatsyncedthreads" => array(),
	"${mysqlprefix}chatsyncedmessages" => array(),
	"${mysqlprefix}chatoperatorsession" => array()
);

function show_install_err($text)
{
	global $page, $version, $errors, $mibewroot, $cwd;
	$page = array(
		'version' => $version,
		'localeLinks' => get_locale_links("$mibewroot/mobile/install/index.php")
	);
	$errors = array($text);
	start_html_output();
	chdir('..');
	require('../view/install_err.php');
	chdir($cwd);
	exit;
}

function create_table($id, $link)
{
	global $dbtables, $dbtables_indexes, $dbtables_unique_keys, $memtables, $dbencoding, $mysqlprefix;

	if (!isset($dbtables[$id])) {
		show_install_err("Unknown table: $id, " . mysql_error($link));
	}

	$query =
			"CREATE TABLE $id\n" .
			"(\n";
	foreach ($dbtables[$id] as $k => $v) {
		$query .= "	$k $v,\n";
	}

	if (isset($dbtables_indexes[$id])) {
	    foreach ($dbtables_indexes[$id] as $k => $v) {
		    $query .= "	INDEX $k ($v),\n";
	    }
	}

	if (isset($dbtables_unique_keys[$id])) {
	    foreach ($dbtables_unique_keys[$id] as $k => $v) {
		    $query .= "	UNIQUE KEY $k ($v),\n";
	    }
	}

	$query = preg_replace("/,\n$/", "", $query);
	$query .= ") charset $dbencoding";
	if (in_array($id, $memtables)) {
		$query .= " ENGINE=MEMORY";
	} else {
		$query .= " ENGINE=InnoDb";
	}

	mysql_query($query, $link) or show_install_err(' Query failed: ' . mysql_error($link));
}

function get_tables($link)
{
	global $mysqldb, $errors;
	$result = mysql_query("SHOW TABLES FROM `$mysqldb`", $link);
	if ($result) {
		$arr = array();
		while ($row = mysql_fetch_array($result, MYSQL_NUM)) {
			$arr[] = $row[0];
		}
		mysql_free_result($result);
		return $arr;

	} else {
		$errors[] = "Cannot get tables from database. Error: " . mysql_error($link);
		return false;
	}
}

function get_columns($tablename, $link)
{
	global $errors;
	$result = mysql_query("SHOW COLUMNS FROM $tablename", $link);
	if ($result) {
		$arr = array();
		while ($row = mysql_fetch_array($result, MYSQL_NUM)) {
			$arr[] = $row[0];
		}
		mysql_free_result($result);
		return $arr;

	} else {
		$errors[] = "Cannot get columns from table \"$tablename\". Error: " . mysql_error($link);
		return false;
	}
}

?>