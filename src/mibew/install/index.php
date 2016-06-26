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
require_once('../libs/settings.php');
require_once('dbinfo.php');

$page = array(
	'version' => $version,
	'localeLinks' => get_locale_links("$mibewroot/install/index.php")
);

$page['done'] = array();
$page['nextstep'] = false;
$page['nextnotice'] = false;
$page['soundcheck'] = false;
$errors = array();

function check_mibewroot()
{
	global $page, $errors, $mibewroot;
	$requestUri = $_SERVER["REQUEST_URI"];
	if (!preg_match('/^(.*)\\/install(\\/[^\\/\\\\]*)?$/', $requestUri, $matches)) {
		$errors[] = "Cannot detect application location: $requestUri";
		return false;
	}
	$applocation = $matches[1];

	if ($applocation != $mibewroot) {
		$errors[] = "Please, check file ${applocation}/libs/config.php<br/>Wrong value of \$mibewroot variable, should be \"$applocation\"";
		$mibewroot = $applocation;
		return false;
	}

	$page['done'][] = getlocal2("install.0.app", array($applocation));
	return true;
}

function fpermissions($file)
{
	$perms = fileperms($file);
	if (($perms & 0x8000) == 0x8000) {
		$info = '-';
	} elseif (($perms & 0x4000) == 0x4000) {
		$info = 'd';
	} else {
		$info = '?';
	}

	// Owner
	$info .= (($perms & 0x0100) ? 'r' : '-');
	$info .= (($perms & 0x0080) ? 'w' : '-');
	$info .= (($perms & 0x0040) ?
			(($perms & 0x0800) ? 's' : 'x') :
			(($perms & 0x0800) ? 'S' : '-'));

	// Group
	$info .= (($perms & 0x0020) ? 'r' : '-');
	$info .= (($perms & 0x0010) ? 'w' : '-');
	$info .= (($perms & 0x0008) ?
			(($perms & 0x0400) ? 's' : 'x') :
			(($perms & 0x0400) ? 'S' : '-'));

	// World
	$info .= (($perms & 0x0004) ? 'r' : '-');
	$info .= (($perms & 0x0002) ? 'w' : '-');
	$info .= (($perms & 0x0001) ?
			(($perms & 0x0200) ? 't' : 'x') :
			(($perms & 0x0200) ? 'T' : '-'));

	return $info;
}

function check_files()
{
	global $page, $errors, $mibewroot;

	$packageFile = dirname(__FILE__) . "/package";
	$fp = @fopen($packageFile, "r");
	if ($fp === FALSE) {
		$errors[] = getlocal2("install.cannot_read", array("$mibewroot/install/package"));
		if (file_exists($packageFile)) {
			$errors[] = getlocal2("install.check_permissions", array(fpermissions($packageFile)));
		}
		return false;
	}

	$knownFiles = array();
	while (!feof($fp)) {
		$line = fgets($fp, 4096);
		$keyval = preg_split("/ /", $line, 2);
		if (isset($keyval[1])) {
			$knownFiles[$keyval[0]] = trim($keyval[1]);
		}
	}
	fclose($fp);

	foreach ($knownFiles as $file => $sum) {
		$relativeName = dirname(__FILE__) . "/../$file";
		if (!is_readable($relativeName)) {
			if (file_exists($relativeName)) {
				$errors[] = getlocal2("install.cannot_read", array("$mibewroot/$file"));
				$errors[] = getlocal2("install.check_permissions", array(fpermissions($relativeName)));
			} else {
				$errors[] = getlocal2("install.no_file", array("$mibewroot/$file"));
			}
			return false;
		}
		if ($sum != "-") {
			$result = md5_file($relativeName);
			if ($result != $sum) {
				// try without \r
				$result = md5(str_replace("\r", "", file_get_contents($relativeName)));
			}
			if ($result != $sum) {
				$errors[] = getlocal2("install.bad_checksum", array("$mibewroot/$file"));
				$errors[] = getlocal("install.check_files");
				return false;
			}
		}
	}

	$page['done'][] = getlocal("install.0.package");
	return true;
}

function check_connection()
{
	global $mysqlhost, $mysqllogin, $mysqlpass, $page, $errors, $mibewroot;
	$link = @mysql_connect($mysqlhost, $mysqllogin, $mysqlpass);
	if ($link) {
		$result = mysql_query("SELECT VERSION() as c", $link);
		if ($result && $ver = mysql_fetch_array($result, MYSQL_ASSOC)) {
			$page['done'][] = getlocal2("install.1.connected", array($ver['c']));
			mysql_free_result($result);
		} else {
			$errors[] = "Version of your SQL server is unknown. Please check. Error: " . mysql_error($link);
			mysql_close($link);
			return null;
		}
		return $link;
	} else {
		$errors[] = getlocal2("install.connection.error", array(mysql_error()));
		return null;
	}
}

function check_database($link)
{
	global $mysqldb, $force_charset_in_connection, $dbencoding, $page, $mibewroot;
	if (mysql_select_db($mysqldb, $link)) {
		$page['done'][] = getlocal2("install.2.db_exists", array($mysqldb));
		if ($force_charset_in_connection) {
			mysql_query("SET character set $dbencoding", $link);
		}
		return true;
	} else {
		$page['nextstep'] = getlocal2("install.2.create", array($mysqldb));
		$page['nextnotice'] = getlocal("install.2.notice");
		$page['nextstepurl'] = "$mibewroot/install/dbperform.php?act=createdb";
	}
	return false;
}

function check_tables($link)
{
	global $dbtables, $page, $mibewroot;
	$curr_tables = get_tables($link);
	if ($curr_tables !== false) {
		$tocreate = array_diff(array_keys($dbtables), $curr_tables);
		if (count($tocreate) == 0) {
			$page['done'][] = getlocal("install.3.tables_exist");
			return true;
		} else {
			$page['nextstep'] = getlocal("install.3.create");
			$page['nextstepurl'] = "$mibewroot/install/dbperform.php?act=ct";
		}
	}
	return false;
}

function check_columns($link)
{
	global $dbtables, $dbtables_can_update, $errors, $page, $mibewroot;

	$need_to_create_columns = false;
	foreach ($dbtables as $id => $columns) {
		$curr_columns = get_columns($id, $link);
		if ($curr_columns === false) {
			return false;
		}
		$tocreate = array_diff(array_keys($columns), $curr_columns);
		if (count($tocreate) != 0) {
			$cannot_update = array_diff($tocreate, $dbtables_can_update[$id]);
			if (count($cannot_update) != 0) {
				$errors[] = "Key columns are absent in table `$id'. Unable to continue installation.";
				$page['nextstep'] = getlocal("install.kill_tables");
				$page['nextstepurl'] = "$mibewroot/install/dbperform.php?act=dt";
				$page['nextnotice'] = getlocal("install.kill_tables.notice");
				return false;
			}
			$need_to_create_columns = true;
		}
	}

	if ($need_to_create_columns) {
		$page['nextstep'] = getlocal("install.4.create");
		$page['nextstepurl'] = "$mibewroot/install/dbperform.php?act=addcolumns";
		$page['nextnotice'] = getlocal("install.4.notice");
		return false;
	}

	$page['done'][] = getlocal("install.4.done");
	return true;
}

function check_sound()
{
	global $page;

	$page['soundcheck'] = true;
	$page['done'][] = getlocal2("install.5.text", array(
													   "<a id=\"check-nv\" href=\"javascript:void(0)\">" . getlocal("install.5.newvisitor") . "</a>",
													   "<a id=\"check-nm\" href=\"javascript:void(0)\">" . getlocal("install.5.newmessage") . "</a>"
												  ));
}

function check_admin($link)
{
	global $mysqlprefix;
	$result = mysql_query("select * from ${mysqlprefix}chatoperator where vclogin = 'admin'", $link);
	if ($result) {
		$line = mysql_fetch_array($result, MYSQL_ASSOC);
		mysql_free_result($result);
		return $line['vcpassword'] != md5('');
	}

	return false;
}

function remove_low_level_settings($link)
{
	global $mysqlprefix, $low_level_settings;
	foreach ($low_level_settings as $key) {
		if (!mysql_query("delete from ${mysqlprefix}chatconfig where vckey = '" . mysql_real_escape_string($key, $link) . "'", $link)) {
			$errors[] = "Unable to remove low level setting " . htmlspecialchars($key) . " from the database. Error: " . mysql_error($link);
			return false;
		}
	}
	return true;
}

function check_status()
{
	global $page, $mibewroot, $settings, $dbversion;

	$page['done'][] = getlocal2("install.0.php", array(phpversion()));

	if (!check_mibewroot()) {
		return;
	}

	if (!check_files()) {
		return;
	}

	$link = check_connection();
	if (!$link) {
		return;
	}

	if (!check_database($link)) {
		mysql_close($link);
		return;
	}

	if (!check_tables($link)) {
		mysql_close($link);
		return;
	}

	if (!check_columns($link)) {
		mysql_close($link);
		return;
	}

	if (!remove_low_level_settings($link)) {
		mysql_close($link);
		return;
	}

	check_sound();

	$page['done'][] = getlocal("installed.message");

	if (!check_admin($link)) {
		$page['nextstep'] = getlocal("installed.login_link");
		$page['nextnotice'] = getlocal2("installed.notice", array("${mibewroot}/install/"));
		$page['nextstepurl'] = "$mibewroot/operator/login.php?login=admin";
	}

	$page['show_small_login'] = true;

	mysql_close($link);

	loadsettings();
	$settings['dbversion'] = $dbversion;
	update_settings();
}

check_status();

start_html_output();
require('../view/install_index.php');
?>