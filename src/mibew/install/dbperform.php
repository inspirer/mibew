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

require_once('../libs/common.php');
require_once('../libs/operator.php');
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

		if (in_array("${mysqlprefix}chatmessage.agentId", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatmessage ADD agentId int NOT NULL DEFAULT 0 AFTER ikind", $link);
			runsql("update ${mysqlprefix}chatmessage, ${mysqlprefix}chatoperator set agentId = operatorid where agentId = 0 AND ikind = 2 AND (vclocalename = tname OR vccommonname = tname)", $link);
		}

		if (in_array("${mysqlprefix}chatthread.agentId", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD agentId int NOT NULL DEFAULT 0 AFTER agentName", $link);
			runsql("update ${mysqlprefix}chatthread, ${mysqlprefix}chatoperator set agentId = operatorid where agentId = 0 AND (vclocalename = agentName OR vccommonname = agentName)", $link);
		}

		if (in_array("${mysqlprefix}chatthread.agentTyping", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD agentTyping int DEFAULT 0", $link);
		}

		if (in_array("${mysqlprefix}chatthread.userTyping", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD userTyping int DEFAULT 0", $link);
		}

		if (in_array("${mysqlprefix}chatthread.messageCount", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD messageCount varchar(16)", $link);
			runsql("ALTER TABLE ${mysqlprefix}chatmessage ADD INDEX idx_threadid_ikind (threadid, ikind)", $link);
			runsql("UPDATE ${mysqlprefix}chatthread t SET t.messageCount = (SELECT COUNT(*) FROM ${mysqlprefix}chatmessage WHERE ${mysqlprefix}chatmessage.threadid = t.threadid AND ikind = 1)", $link);
			runsql("ALTER TABLE ${mysqlprefix}chatmessage DROP INDEX idx_threadid_ikind", $link);
		}

		if (in_array("${mysqlprefix}chatthread.nextagent", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD nextagent int NOT NULL DEFAULT 0", $link);
		}

		if (in_array("${mysqlprefix}chatthread.shownmessageid", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD shownmessageid int NOT NULL DEFAULT 0", $link);
		}

		if (in_array("${mysqlprefix}chatthread.userid", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD userid varchar(255) DEFAULT \"\"", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.iperm", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD iperm int DEFAULT 65535", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.istatus", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD istatus int DEFAULT 0", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.inotify", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD inotify int DEFAULT 0", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.vcavatar", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD vcavatar varchar(255)", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.vcjabbername", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD vcjabbername varchar(255)", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.vcemail", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD vcemail varchar(64)", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.dtmrestore", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD dtmrestore datetime DEFAULT 0", $link);
		}

		if (in_array("${mysqlprefix}chatoperator.vcrestoretoken", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatoperator ADD vcrestoretoken varchar(64)", $link);
		}

		if (in_array("${mysqlprefix}chatthread.groupid", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD groupid int references ${mysqlprefix}chatgroup(groupid)", $link);
		}

		if (in_array("${mysqlprefix}chatthread.userAgent", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatthread ADD userAgent varchar(255)", $link);
		}

		if (in_array("${mysqlprefix}chatgroup.vcemail", $absent)) {
			runsql("ALTER TABLE ${mysqlprefix}chatgroup ADD vcemail varchar(64)", $link);
		}

		$res = mysql_query("select null from information_schema.statistics where table_schema = '$mysqldb' and table_name = '${mysqlprefix}chatmessage' and index_name = 'idx_agentid'", $link);
		if ($res && mysql_num_rows($res) == 0) {
			runsql("ALTER TABLE ${mysqlprefix}chatmessage ADD INDEX idx_agentid (agentid)", $link);
		}
	}
}

mysql_close($link);
header("Location: $mibewroot/install/index.php");
exit;
?>