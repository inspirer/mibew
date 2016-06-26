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

// Save the current working directory and change to one level up
// so that the relative paths work. This is especially necessary if the
// files that are being included also include files with their relative 
// paths
$cwd = getcwd();
chdir('..');

require_once('../libs/common.php');
require_once('../libs/operator.php');

chdir($cwd);   // $cwd defined in index.php
require_once('dbinfo.php');

function runsql($query, $link)
{
	$res = mysql_query($query, $link) or show_install_err(' Query failed: ' . mysql_error($link));
	return $res;
}

$act = verifyparam("act", "/^(silentcreateall|createdb|ct|dt|addcolumns)$/");

$link = @mysql_connect($mysqlhost, $mysqllogin, $mysqlpass)
		 or show_install_err('Could not connect: ' . mysql_error());

if ($act == "silentcreateall") {
	mysql_query("CREATE DATABASE $mysqldb", $link) or show_install_err(' Query failed: ' . mysql_error($link));
	foreach ($dbtables as $id) {
		create_table($id, $link);
	}
} else if ($act == "createdb") {
	mysql_query("CREATE DATABASE $mysqldb", $link) or show_install_err(' Query failed: ' . mysql_error($link));
} else {
	mysql_select_db($mysqldb, $link)
	or show_install_err('Could not select database');
	if ($force_charset_in_connection) {
		mysql_query("SET character set $dbencoding", $link);
	}

	if ($act == "ct") {
		$curr_tables = get_tables($link);
		if ($curr_tables === false) {
			show_install_err($errors[0]);
		}
		$tocreate = array_diff(array_keys($dbtables), $curr_tables);
		foreach ($tocreate as $id) {
			create_table($id, $link);
		}
	} else if ($act == "dt") {

		# comment this line to be able to drop tables
		show_install_err("For security reasons, removing tables is disabled by default");

		foreach (array_keys($dbtables) as $id) {
			mysql_query("DROP TABLE IF EXISTS $id", $link) or show_install_err(' Query failed: ' . mysql_error($link));
		}
	} else if ($act == "addcolumns") {
		$absent = array();
		foreach ($dbtables as $id => $columns) {
			$curr_columns = get_columns($id, $link);
			if ($curr_columns === false) {
				show_install_err($errors[0]);
			}
			$tocreate = array_diff(array_keys($columns), $curr_columns);
			foreach ($tocreate as $v) {
				$absent[] = "$id.$v";
			}
		}

		// Look at this example to add columns when the db schema changes from version to version.
		// It will be better for this to be function(s) in dbinfo.php so that all db schema is isolated in that file.
		
		/*if (in_array("${mysqlprefix}chatmessage.agentId", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatmessage ADD agentId int NOT NULL DEFAULT 0 AFTER ikind", $link);
			runsql("update ${mysqlprefix}chatmessage, ${mysqlprefix}chatoperator set agentId = operatorid where agentId = 0 AND ikind = 2 AND (vclocalename = tname OR vccommonname = tname)", $link);
		}*/
	}
}

mysql_close($link);
header("Location: $mibewroot/mobile/install/index.php");
exit;
?>