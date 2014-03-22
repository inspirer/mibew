<?php

/*
 * Copyright 2013 the original author or authors.
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
 *
 * Author:	Eyong Nsoesie (eyongn@scalior.com)
 * Date: 	September 3, 2013
 */
 
require_once('../libs/common.php');
require_once('../libs/operator.php');
require_once('../libs/chat.php');
require_once('../libs/userinfo.php');
require_once('../libs/groups.php');

// Mobile client error codes
define('ERROR_SUCCESS',				 0);
define('ERROR_LOGIN_FAILED',		 1);
define('ERROR_INVALID_OPR_TOKEN',	 2);
define('ERROR_INVALID_THREAD',		 3);
define('ERROR_CANT_TAKEOVER',		 4);
define('ERROR_CONFIRM_TAKEOVER',	 5);
define('ERROR_CANT_VIEW_THREAD',	 6);
define('ERROR_WRONG_THREAD',		 7);
define('ERROR_INVALID_CHAT_TOKEN',	 8);
define('ERROR_INVALID_COMMAND',		 9);
define('ERROR_UNKNOWN',				10);
define('ERROR_THREAD_CLOSED',		11);

// Operator status codes. From inspection I can see that these are currently
// implied as 0 for availabe, 1 for away
define('OPR_STATUS_ON',		 0);
define('OPR_STATUS_AWAY',	 1);


$url = "";
$logoURL = "";
$mibewMobVersion = "";
$serverName = "Scalior Test Server";

// Todo: These two arrays are from operator/upate.php.
// They need to be put in a common place to be shared rather than duplicated
$threadstate_to_string = array(
	$state_queue => "wait",
	$state_waiting => "prio",
	$state_chatting => "chat",
	$state_closed => "closed",
	$state_loading => "wait",
	$state_left => "closed"
);

$threadstate_key = array(
	$state_queue => "chat.thread.state_wait",
	$state_waiting => "chat.thread.state_wait_for_another_agent",
	$state_chatting => "chat.thread.state_chatting_with_agent",
	$state_closed => "chat.thread.state_closed",
	$state_loading => "chat.thread.state_loading"
);

//------------------
// Database resource bug:
// There are times when php will return warnings/errors stating that
// "X is not a valid MySQL-Link resource"
// This is possibly caused by the fact that mysql_close is being called on the database handle
// and the handle is being used subsequently. 
// See http://stackoverflow.com/questions/2851420/warning-mysql-query-3-is-not-a-valid-mysql-link-resource 
// To mitigate this issue, the database handle will be created in entry-point functions, and the handle passed
// to internal functions. There will be some exceptions and I will comment them accordingly.
//
// EN 3/22/2014
//---------------------------


/***********
 * Method:	
 *		chat_server_status
 * Description:
 *	  	Determines some server status information
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 *	Remarks:
 *		Returns server settings needed by the client
 ***********/
function chat_server_status() {
	global $mysqlprefix, $version, $url, $settings;

	$link = connect();
	loadmibewmobsettings($link);

	$row = select_one_row("SELECT * FROM ${mysqlprefix}chatmibewmobserverinfo", $link);
	mysql_close($link);

	if ($row != NULL) {
		return array('name' => $settings['title'],
					 'URL' => $url,		// TODO: Need to infer this either from the request or during installation
					 'version' => $version,	// TODO: This is the version as reported by mibew
					 'logoURL' => $settings['logo'],
					 'mibewMobVersion' => $row['apiversion'],
					 'installationid' => $row['installationid'],
					 'propertyrevision' => (int)$row['propertyrevision'],
					 'server_status' => 'on',
					 'errorCode' => ERROR_SUCCESS);
	} else {
		return array('errorCode' => ERROR_UNKNOWN);
	}
}

/***********
 * Method:	
 *		mobile_login
 * Description:
 *	  	Logs in the client
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function mobile_login($username, $password, $deviceuuid) {
	if (isset($username) && isset($password) && isset($deviceuuid)) {
		// Note: Blank passwords not currently allowed.
		
		// This function creates and closes a database connection, so if a connection is needed,
		// open it below the function.
		$op = operator_by_login($username);
	
		if (isset($op) && check_password_hash($username, $password, $op['vcpassword'])) {
			$link = connect();
			$oprtoken = create_operator_session($op, $deviceuuid, $link);
			$out = array('oprtoken' => $oprtoken,
						 'operatorid' => $op['operatorid'],
						 'localename' => $op['vclocalename'],
						 'commonname' => $op['vccommonname'],
						 'permissions' => $op['iperm'],
						 'username' => $op['vclogin'],
						 'email' => $op['vcemail'],
						 'status' => $op['istatus'],
						 'lastvisited' => $op['dtmlastvisited'],
						 'errorCode' => ERROR_SUCCESS);
			mysql_close($link);
			return $out;
		}
	}
	
	$out = array('errorCode' => ERROR_LOGIN_FAILED);
	
	return $out;
}


/***********
 * Method:	
 *		mobile_logout
 * Description:
 *	  	Logs out the client
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function mobile_logout($oprtoken) {
	global $mysqlprefix;

	$link = connect();

	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];

	if ($operatorId == NULL) {
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id_($operatorId, $link);
	
		
	$query = "UPDATE ${mysqlprefix}chatoperatorsession 
			  SET inprogress = 0, dtmexpires = CURRENT_TIMESTAMP
			  WHERE oprtoken = '$oprtoken'";
	
	perform_query($query, $link);
	mysql_close($link);
		
	$out = array('errorCode' => ERROR_SUCCESS);
	return $out;
}

/***********
 * Method:	
 *		create_operator_session
 * Description:
 *	  	Create a session for the operator if this is the first time they
 *		are logging in with that device. Otherwise returns the existing token
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function create_operator_session($op, $deviceuuid, $link) {
	global $mysqlprefix;
	
	$row = select_one_row("SELECT deviceid FROM ${mysqlprefix}chatdevices " . 
						  "WHERE clientdeviceid = '$deviceuuid'", $link);

	if ($row != NULL) {
		$deviceid = $row['deviceid'];	
		
		// Return the current unexpired token if the user is already logged in.
		$loggedInOp = select_one_row("SELECT * FROM ${mysqlprefix}chatoperatorsession " . 
									 "WHERE operatorid = " . $op['operatorid'] . 
									 " AND deviceid = $deviceid AND inprogress = 1", $link);
	}

	if ($loggedInOp != NULL) {
		return $loggedInOp['oprtoken'];
	}
	
	
	// If we get here, this is a new session.

	// Insert the device id into the database only if it is not yet in the database
	if ($deviceid == NULL) {
		$query = "INSERT INTO ${mysqlprefix}chatdevices (clientdeviceid) VALUES ('$deviceuuid')";
		perform_query($query, $link);
		$deviceid = mysql_insert_id($link);
	}
	
	// Token is the first 10 characters of the md5 of the current time
	$oprtoken = strtoupper(substr(md5(time()), 0, 10));
	
	$query = "INSERT INTO ${mysqlprefix}chatoperatorsession (operatorid, oprtoken, deviceid) VALUES " .
			 "(" . $op['operatorid'] . ", '$oprtoken', $deviceid)";
	perform_query($query, $link);

	return $oprtoken;
}


/***********
 * Method:	
 *		operator_from_token
 * Description:
 *	  	Checks whether the token is valid, i.e, exists and (todo) not expired.
 *		Returns the associated operator id if it is valid
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function operator_from_token($oprtoken, $link) {
	global $mysqlprefix;
	
	$query = "SELECT operatorid, deviceid FROM ${mysqlprefix}chatoperatorsession " .
			 "WHERE oprtoken = '$oprtoken'";
	$row = select_one_row($query, $link);

	return $row;
}

/***********
 * Method:	
 *		get_active_visitors
 * Description:
 *	  	Returns a list of active visitors.
 *	  	i.e, those that are waiting for an operator as well as
 *	    those who already have a chat in session
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function get_active_visitors($oprtoken, $deviceVisitors, $stealthMode) {
	$link = connect();

	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];

	if ($operatorId != NULL) {
		$out = get_pending_threads($deviceVisitors, $deviceid, $link);
		$out['errorCode'] = ERROR_SUCCESS;
		if (!$stealthMode) {
			notify_operator_alive2($operatorId, OPR_STATUS_ON, $link);
		}
	}
	else {
		$out = array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	mysql_close($link);	
	return $out;
}


/***********
 * Method:	
 *		get_pending_threads
 * Description:
 *	  	This is just like print_pending_threads from operator/update.php, 
 * 		except that the output is an array, with mobile friendly data
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function get_pending_threads($deviceVisitors, $deviceid, $link)
{
	global $webim_encoding, $settings, $state_closed, $state_left, $mysqlprefix;

	$output = array();
	$query = "select threadid, userName, agentName, unix_timestamp(dtmcreated), userTyping, " .
			 "unix_timestamp(dtmmodified), lrevision, istate, remote, nextagent, agentId, userid, shownmessageid, userAgent, (select vclocalname from ${mysqlprefix}chatgroup where ${mysqlprefix}chatgroup.groupid = ${mysqlprefix}chatthread.groupid) as groupname " .
			 "from ${mysqlprefix}chatthread where istate <> $state_closed AND istate <> $state_left " . 
			 "ORDER BY threadid DESC";
	$rows = select_multi_assoc($query, $link);

	/*if (strlen($deviceVisitors) > 0) {
		json_decode($deviceVisitors);
		$deviceVisitorArray = explode(",", $deviceVisitors);
	}*/
	
	if (strlen($deviceVisitors) > 0) {
		$deviceVisitorArray = json_decode($deviceVisitors, true);
		$deviceVisitorCommaSepList = implode(',', $deviceVisitorArray);
	}
	
	// Query the last status for the threads sent to the device
	if (count($deviceVisitorArray) > 0) {
		$query = "select threadid, state, shownmessageid, agentid " .
				 "from ${mysqlprefix}chatsyncedthreads ". 
				 "where deviceid = $deviceid and threadid in ($deviceVisitorCommaSepList) " .
				 "order by threadid desc";
		
		$syncedthreads = select_multi_assoc($query, $link);
		
		
	}
	
	$threadList = array();
	if (count($rows) > 0) {
		foreach ($rows as $row) {
			// If this visitor has already been sent to the client, 
			// do not send it again
			if (!isset($deviceVisitorArray) || 
				($key = array_search($row['threadid'], $deviceVisitorArray)) === false) {
				// New thread
				$thread = thread_to_array($row, $link);
				$threadList[] = $thread;
			} else {
				// Thread that is still active on device. Check if anything has changed
				foreach($syncedthreads as $deviceThread) {
					if ($deviceThread['threadid'] == $row['threadid']) {
						if ($deviceThread['state'] != $row['istate'] ||
							$deviceThread['shownmessageid'] != $row['shownmessageid'] ||
							$deviceThread['agentid'] != $row['agentId']) {

							// Something changed. Update only the necessary fields
							$thread = array('threadid' => $row['threadid'],
											'state' => $row['istate'],
											'agentid' => $row['agentId']);
							if ($row['shownmessageid'] != 0) {
								$thread['message'] = get_sanitized_message($row['shownmessageid'], $link);
								$thread['shownmessageid'] = $row['shownmessageid'];
							}
							$threadList[] = $thread;
						}
						
						// Remove the thread from the array marking it as serviced. If at the end of
						// this exercise there are threads left in this array, then these are threads
						// that have been closed.
						unset($deviceVisitorArray[$key]);
						break;
					}
				}
			}
		}
	}

	// Mark any unserviced threads as closed.
	if ($deviceVisitorArray != null) {
		foreach($deviceVisitorArray as $visitorid) {
			$threadList[] = array('threadid' => $visitorid,
									'state' => $state_closed);	
		}
	}
	
	if (($output['threadCount'] = count($threadList))> 0) {
		$output['threadList'] = $threadList;

		foreach ($output['threadList'] as $listItem) {
			$query = "INSERT INTO ${mysqlprefix}chatsyncedthreads " .
					 "(threadid, deviceid, state, shownmessageid, agentid) VALUES (" .
					 $listItem['threadid'] . ", $deviceid, " . $listItem['state'] . ", " .
					 (isset($listItem['shownmessageid']) ? $listItem['shownmessageid'] : '0') . ", " .
					 (isset($listItem['agentid']) ? $listItem['agentid'] : '0') . ") " .
					 " ON DUPLICATE KEY UPDATE state = " . $listItem['state'] . ", shownmessageid = " .
					 (isset($listItem['shownmessageid']) ? $listItem['shownmessageid'] : '0') . ", agentid = " .
					 (isset($listItem['agentid']) ? $listItem['agentid'] : '0') . ";";
					 
			perform_query($query, $link);
		}
	}
	return $output;

/*		if (count($threadListInsert) > 0) {
			// Create $data to be inserted of the form "(threadid, deviceid, istate, shownmessageid),..."
			$firstDataElement = true;
			$data = NULL;
		
			foreach($threadListInsert as $insertItem) {
				if (!$firstDataElement) {
					$data.= ", ";
				}
				else {
					$firstDataElement = false;
				}
				
				$data.= "(" . $insertItem['threadid']. ", ". $deviceid . ", " . $insertItem['state'] . ", " .
						(isset($insertItem['shownmessageid']) ? $insertItem['shownmessageid'] : '0') . ")";
			}
		
			// Create the query
			$query = "INSERT INTO ${mysqlprefix}chatsyncedthreads " .
					 "(threadid, deviceid, istate, shownmessageid) VALUES ";
			$query.= $data;
			
			$newlink = connect();
			perform_query($query, $newlink);
			mysql_close($newlink);
		}

		foreach($threadListUpdate as $updateItem) {
			// Create the query
			$query = "UPDATE ${mysqlprefix}chatsyncedthreads " .
					 "SET istate = " . $updateItem['istate'] . ", " .
					 (isset($updateItem['shownmessageid']) ? $updateItem['shownmessageid'] : '0') .
					 " WHERE threadid = " . $updateItem['threadid'] . " AND deviceid = $deviceid";
			$newlink = connect();
			perform_query($query, $link);
			mysql_close($newlink);
		}
	}*/


	//foreach ($output as $thr) {
		//print myiconv($webim_encoding, "utf-8", $thr);
	//}

}

/***********
 * Method:	
 *		thread_to_array
 * Description:
 *	  	This is just like thread_to_xml from operator/update.php, 
 * 		except that the output is an array, with mobile friendly data
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function thread_to_array($thread, $link)
{
	global $state_chatting, $threadstate_to_string, $threadstate_key,
$webim_encoding, $operator, $settings,
$can_viewthreads, $can_takeover, $mysqlprefix;
	$state = $threadstate_to_string[$thread['istate']];

	$result = array();
	$result['threadid'] = $thread['threadid'];
	
	if ($state == "closed") {
		$result['state'] = $thread['istate'];
		return $result;
	}

	$state = getstring($threadstate_key[$thread['istate']]);
	$nextagent = $thread['nextagent'] != 0 ? operator_by_id_($thread['nextagent'], $link) : null;
	$threadoperator = $nextagent ? get_operator_name($nextagent)
			: ($thread['agentName'] ? $thread['agentName'] : "-");

	if ($threadoperator == "-" && $thread['groupname']) {
		$threadoperator = "- " . $thread['groupname'] . " -";
	}

	if (!($thread['istate'] == $state_chatting && $thread['agentId'] != $operator['operatorid'] && !is_capable($can_takeover, $operator))) {
		$result['canopen'] = "true";
	}
	if ($thread['agentId'] != $operator['operatorid'] && $thread['nextagent'] != $operator['operatorid']
		&& is_capable($can_viewthreads, $operator)) {
		$result['canview'] = "true";
	}
	if ($settings['enableban'] == "1") {
		$result['canban'] = "true";
	}

	$banForThread = $settings['enableban'] == "1" ? ban_for_addr_($thread['remote'], $link) : false;
	if ($banForThread) {
		$result['ban'] = "blocked";
		$result['banid'] = $banForThread['banid'];
	}

	$result['state'] = $thread['istate'];
	$result['typing'] = $thread['userTyping'];
	
	$name = "";
	if ($banForThread) {
		$name = htmlspecialchars(getstring('chat.client.spam.prefix'));
	}
	$name .= htmlspecialchars(htmlspecialchars(get_user_name2($thread['userName'], $thread['remote'], $thread['userid'], $link)));
	
	$result['name'] = $name;
	
//	$result['addr'] = htmlspecialchars(get_user_addr($thread['remote']));
	$result['agent'] = htmlspecialchars(htmlspecialchars($threadoperator));
	$result['agentid'] = $thread['agentId'];
	$result['time'] = $thread['unix_timestamp(dtmcreated)'] . "000";
	$result['modified'] = $thread['unix_timestamp(dtmmodified)'] . "000";

	if ($banForThread) {
		$result['reason'] = $banForThread['comment'];
	}

	$userAgent = get_useragent_version($thread['userAgent']);
	$result['useragent'] = $userAgent;

	if ($thread["shownmessageid"] != 0) {
		$line = get_sanitized_message($thread['shownmessageid'], $link);
		if ($line) {
			$result['message'] = $line;
			$result['shownmessageid'] = $thread['shownmessageid'];
		}
	}

	return $result;
}



/***********
 * Method:	
 *		get_sanitized_message
 * Description:
 *	  	Helper method to get a message given its message id.
 * Author:
 * 		ENsoesie 	11/27/2013	Creation
 ***********/
function get_sanitized_message($messageid, $link) {
	global $mysqlprefix;
	if ($messageid != 0) {
		$query = "select tmessage from ${mysqlprefix}chatmessage where messageid = $messageid";
		
		$line = select_one_row($query, $link);

		if ($line) {
			$result = preg_replace("/[\r\n\t]+/", " ", $line["tmessage"]);
			//$result = htmlspecialchars(htmlspecialchars($message));
		}
	}
	
	return $result;
}
	

/***********
 * Method:	
 *		start_chat
 * Description:
 *	  	Start a chat with a visitor. This constitutes a new chat, continuing the chat, 
 * 		or taking over the chat.
 * Author:
 * 		ENsoesie 	9/4/2013	Creation
 ***********/
function start_chat($oprtoken, $threadid) {
	global $state_chatting, $state_closed;
	$link = connect();
	
	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];

	if ($operatorId == NULL) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id_($operatorId, $link);
	$chattoken = $_GET['token'];

	$thread = thread_by_id_($threadid, $link);
	if (!$thread || !isset($thread['ltoken'])) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_THREAD);
	}

	// If the thread is already closed, then return error indicating so.
	if ($thread['istate'] == $state_closed) {
		mysql_close($link);
		return array('errorCode' => ERROR_THREAD_CLOSED);
	}
	
	// If token is not set, this is a new chat session for this operator
	if (!isset($chattoken)) {
		$viewonly = filter_var($_GET['viewonly'], FILTER_VALIDATE_BOOLEAN);
		$forcetake = filter_var($_GET['force'], FILTER_VALIDATE_BOOLEAN);

		if (!$viewonly && $thread['istate'] == $state_chatting && 
			$operator['operatorid'] != $thread['agentId']) {
			
			if (!is_capable($can_takeover, $operator)) {
				mysql_close($link);
				return array('errorCode' => ERROR_CANT_TAKEOVER);
			}
			
			if ($forcetake == false) {
				// Todo. Confirm that you want to force the takeover of the conversation
				// 1 month later and I'm not sure what this should do. This is a potential
				// bug that needs to be reviewed.
				// Update: On the web, there is a prompt for user to confirm takeover.
				//			Need to implement similar prompt in app
				mysql_close($link);
				return array('errorCode' => ERROR_CONFIRM_TAKEOVER);
			}
		}

		if (!$viewonly) {
			take_thread2($thread, $operator, $link);
		} else if (!is_capable($can_viewthreads, $operator)) {
			mysql_close($link);
			return array('errorCode' => ERROR_CANT_VIEW_THREAD);
		}
		
		$chattoken = $thread['ltoken'];
	}

	// Chat token may be different if token was supplied from the http request
	if ($chattoken != $thread['ltoken']) {
		mysql_close($link);
		return array('errorCode' => ERROR_WRONG_THREAD);
	}
	
	if ($thread['agentId'] != $operator['operatorid'] && 
		!is_capable($can_viewthreads, $operator)) {
		mysql_close($link);
		return array('errorCode' => ERROR_CANT_VIEW_THREAD);
	}
	
	$out = array('errorCode' => ERROR_SUCCESS,
				 'threadid' => $threadid,
				 'chattoken' => $chattoken);

	mysql_close($link);
	return $out;
}

/***********
 * Method:	
 *		get_new_messages
 * Description:
 *	  	Get messages that have not yet been sync'ed for the current chat session
 * Author:
 * 		ENsoesie 	9/7/2013	Creation
 ***********/
function get_new_messages($oprtoken, $threadid, $chattoken, $istyping) {
	$link = connect();
	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];

	if ($operatorId == NULL) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id_($operatorId, $link);
	$thread = thread_by_id_($threadid, $link);
	if (!$thread || !isset($thread['ltoken'])) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_THREAD);
	}
	
	if ($chattoken != $thread['ltoken']) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_CHAT_TOKEN);
	}
	
	ping_thread2($thread, false, $istyping, $link);
	check_for_reassign2($thread, $operator, $link);

	
	return get_unsynced_messages($threadid, $deviceid, $link);
}

/***********
 * Method:	
 *		get_unsynced_messages
 * Description:
 *	  	Helper method to get messages that have not 
 *		yet been sync'ed for the current chat session
 * Author:
 * 		ENsoesie 	9/7/2013	Creation
 ***********/
function get_unsynced_messages($threadid, $deviceid, $link) {
	global $mysqlprefix;

	$query = "select messageid, tmessage, unix_timestamp(dtmcreated) as timestamp, threadid, 
			  agentId, tname, ikind 
			  from ${mysqlprefix}chatmessage as cm
			  where threadid = $threadid
			  and not exists (
			  	select 1 from ${mysqlprefix}chatsyncedmessages as csm
				where csm.messageid = cm.messageid
				and csm.deviceid = $deviceid)";
	
	$rows = select_multi_assoc($query, $link);

	$out = array('errorCode' => ERROR_SUCCESS);
				 
	$out['messageCount'] = count($rows);

	// Make sure there is at least one result
	if (count($rows) > 0) {
		$out['messageList'] = $rows;
	}

	return $out;
}


/***********
 * Method:	
 *		msg_from_mobile_op
 * Description:
 *	  	Post a message from the mobile operator
 * Author:
 * 		ENsoesie 	9/7/2013	Creation
 * Notes:
 *		In a weird line of thinking, id variables that end with "L" represent the local
 * 		version of the id (client) while the corresponding id ending with "R" represent the
 *		remote version (server). This naming convention should be changed to more 
 *		meaningful variable names.
 ***********/
function msg_from_mobile_op($oprtoken, $threadid, $chattoken, $opMsgIdL, $opMsg) {
	global $mysqlprefix;
	$link = connect();
	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];

	if ($operatorId == NULL) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id_($operatorId, $link);
	$thread = thread_by_id_($threadid, $link);
	if (!$thread || !isset($thread['ltoken'])) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_THREAD);
	}
	
	if ($chattoken != $thread['ltoken']) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_CHAT_TOKEN);
	}

	// Steps needed to post a message
	// 1 - Check if it is in the devicemessages table
	// 2 - If so, send back the devicemessageid, messageid, timestamp, then done
	// 3 - If not post the message and get the messageid and timestamp
	// 4 - Add the message metadata to the devicemessages table
	// 5 - Send back the devicemessageid, messageid, timestamp, then done


	// 1 - Check if it is in the devicemessages table
	$result = select_one_row("select messageid, unix_timestamp(msgtimestamp)
							 from ${mysqlprefix}chatmessagesfromdevice
							 where deviceid = $deviceid and devicemessageid = $opMsgIdL", $link);
	
	// 2 - If so, send back the devicemessageid, messageid, timestamp, then done
	if ($result != NULL) {
		mysql_close($link);
		return array('errorCode' => ERROR_SUCCESS,
					 'messageidr' => $result['messageid'],
					 'messageidl' => $opMsgIdL,
					 'timestamp' => $result['unix_timestamp(msgtimestamp)']);
	}
	
	// 3 - If not post the message and get the messageid and timestamp
	global $kind_agent;
	$from = $thread['agentName'];

	$postedid = post_message_($threadid, $kind_agent, $opMsg, $link, $from, null, $operatorId);
	
	// Get the timestamp when the message was posted.
	$result = select_one_row("select dtmcreated, unix_timestamp(dtmcreated) from ${mysqlprefix}chatmessage
								 where messageid = ". $postedid, $link);
	
	// 4 - Add the message metadata to the devicemessages table
	$query = "INSERT INTO ${mysqlprefix}chatmessagesfromdevice 
			  (deviceid, messageid, devicemessageid, msgtimestamp) VALUES 
			  ($deviceid, $postedid, $opMsgIdL, '" . $result['dtmcreated']. "')";

	perform_query($query, $link);
	mysql_close($link);
	
	
	// Also add this message to the sync'ed messages table.
	// Although this is like a "reverse sync", we are doing this so that we 
	// don't have to search both the sync'ed messages and the device messages tables
	// when searching for unsync'ed messages. 
	// This also allows for a shorter purge interval for the device messages table, while
	// the purge interval on the sync'ed messages table can be longer.
	ack_messages($oprtoken, "$postedid");
	
	return array('errorCode' => ERROR_SUCCESS,
				 'messageidr' => $postedid,
				 'messageidl' => $opMsgIdL,
				 'timestamp' => $result['unix_timestamp(dtmcreated)']);
}

/***********
 * Method:	
 *		ack_messages
 * Description:
 *	  	Acknowledgmenet for messages that have been received
 *		by client
 * Author:
 * 		ENsoesie 	11/6/2013	Creation
 ***********/
function ack_messages($oprtoken, $msgList) {
	global $mysqlprefix;
	$link = connect();
	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];

	$msgListArray = explode(",", $msgList);
	
	// Create $data of the form "(messageid, deviceid), (messageid, deviceid),..."
	$firstDataElement = true;
	$data = "";
	foreach($msgListArray as $msgID) {
		if (!$firstDataElement) {
			$data.= ", ";
		}
		else {
			$firstDataElement = false;
		}
		
		$data.= "(" . $msgID. ", ". $deviceid .")";
	}

	// Create the query
	$query = "INSERT INTO ${mysqlprefix}chatsyncedmessages (messageid, deviceid) VALUES ";
	$query.= $data;
	
	perform_query($query, $link);
	mysql_close($link);

	return array('errorCode' => ERROR_SUCCESS);
}
	
/***********
 * Method:	
 *		close_thread_mobile
 * Description:
 *	  	Close the thread with given thread id
 * Author:
 * 		ENsoesie 	11/13/2013	Creation
 ***********/
function close_thread_mobile($oprtoken, $threadid) {
	$link = connect();
	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];

	if ($operatorId == NULL) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$thread = thread_by_id_($threadid, $link);
	if (!$thread || !isset($thread['ltoken'])) {
		mysql_close($link);
		return array('errorCode' => ERROR_INVALID_THREAD);
	}
	
	// close_thread() below opens and closes a database connection. This is fine as we don't 
	// do anything with the connection beyond this point
	mysql_close($link);
	
	close_thread($thread, false);
	
	return array('errorCode' => ERROR_SUCCESS);
}
	
/***********
 * Method:	
 *		invalid_command
 * Description:
 *	  	Returns an invalid command error message
 * Author:
 * 		ENsoesie 	10/19/2013	Creation
 ***********/
 function invalid_command() {
	 return array('errorCode' => ERROR_INVALID_COMMAND);
 }
	 
/***********
 * Method:	
 *		batch_op_messages
 * Description:
 *	  	Post a batch of messages from the mobile operator
 * Author:
 * 		ENsoesie 	11/7/2013	Creation
 ***********
function batch_op_messages($oprtoken, $oprtoken, $opMessages) {
	$operatorId = operator_from_token($oprtoken);
	if ($operatorId == NULL) {
		return array('errorCode' => 2,
					 'errorMsg' => 'invalid operator token');
	}

	$operator = operator_by_id($operatorId);
	
	*/
	
/**************
 *	 Method:	
 *		get_active_visitors_notification
 * Description:
 *	  	Returns true if there is a new visitor.
 *	  	i.e, one that the operator is not yet aware off.
 * Author:
 * 		ENsoesie 	12/4/2013	Creation
 ***********/
function get_active_visitors_notification($oprtoken, $deviceVisitors, $stealthMode) {
	global $webim_encoding, $settings, $state_closed, $state_left, $mysqlprefix;
	
	$link = connect();
	
	$oprSession = operator_from_token($oprtoken, $link);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];
	
	$hasVisitorChange = false; // Assume no visitor

	if ($operatorId != NULL) {
		if (!$stealthMode) {
			notify_operator_alive2($operatorId, OPR_STATUS_ON, $link);
		}
		
		$output = array();
		$query = "select threadid, istate, shownmessageid " .
				 "from ${mysqlprefix}chatthread where istate <> $state_closed AND istate <> $state_left ";
		$rows = select_multi_assoc($query, $link);


		if (strlen($deviceVisitors) > 0) {
			$deviceVisitorArray = json_decode($deviceVisitors, true);
			$deviceVisitorCommaSepList = implode(',', $deviceVisitorArray);
		}
		
		// Query the last status for the threads sent to the device
		if (count($deviceVisitorArray) > 0) {
			$query = "select threadid, state, shownmessageid " .
					 "from ${mysqlprefix}chatsyncedthreads ". 
					 "where deviceid = $deviceid and threadid in ($deviceVisitorCommaSepList) " .
					 "order by threadid desc";
			
			$syncedthreads = select_multi_assoc($query, $link);		
		}
	
		if (count($rows) > 0) {
			foreach ($rows as $row) {
				if ($hasVisitorChange) {
					break;
				}
				if (!isset($deviceVisitorArray) || 
					($key = array_search($row['threadid'], $deviceVisitorArray)) === false) {
					// New thread
					$hasVisitorChange = true;
				} else {
					// Thread that is still active on device. Check if anything has changed
					foreach($syncedthreads as $deviceThread) {
						if ($deviceThread['threadid'] == $row['threadid']) {
							if ($deviceThread['state'] != $row['istate'] ||
								$deviceThread['shownmessageid'] != $row['shownmessageid']) {
	
								// Something changed.
								$hasVisitorChange = true;
							}
							
							// Remove the thread from the array marking it as serviced. If at the end of
							// this exercise there are threads left in this array, then these are threads
							// that have been closed.
							unset($deviceVisitorArray[$key]);
							break;
						}
					}
				}
			}
		}

		// Any unserviced threads need to be closed. on the device
		if ($deviceVisitorArray != null && count($deviceVisitorArray) > 0) {
			$hasVisitorChange = true;
		}
	
		$out = array('errorCode' => ERROR_SUCCESS,
					 'hasvisitorchange' => $hasVisitorChange);
	}
	else {
		$out = array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	mysql_close($link);

	return $out;
}


/**************
 *	 Method:	
 *		verifyparam2
 * Description:
 *	  	Verifies that a parameter is set with the expected 
 * 		value. 
 * Author:
 * 		ENsoesie 	1/20/2014	Creation
 * Remark: 
 *		Borrowed from "common.php", but with the html removed.
 ***********/
function verifyparam2($name, $regexp, $default = null)
{
		if (isset($_GET[$name])) {
		$val = $_GET[$name];
		if (preg_match($regexp, $val))
			return $val;

	} else if (isset($_POST[$name])) {
		$val = $_POST[$name];
		if (preg_match($regexp, $val))
			return $val;

	} else {
		if (isset($default))
			return $default;
	}
	
	// Parameter not validated.
	return false;
}


/**************
 *	 Method:	
 *		loadmibewmobsettings
 * Description:
 *		Loads mibewmob settings as well as general settings
 * Author:
 * 		ENsoesie 	2/17/2014	Creation
 ***********/
function loadmibewmobsettings($link)
{
	global $settings;
	
	loadsettings_($link);
	
}


/**************
 *	 Method:	
 *		get_user_name2
 * Description:
 *	  	Gets the chat user's username given the username pattern 
 * Author:
 * 		ENsoesie 	3/22/2014	Creation
 * Remark: 
 *		Borrowed from "chat.php", with loadsettings that takes a database handle. 
 * 		See the comment up top about the database resource bug.
 ***********/
function get_user_name2($username, $addr, $id, $link)
{
	global $settings;
	loadmibewmobsettings($link);
	return str_replace("{addr}", $addr,
					   str_replace("{id}", $id,
								   str_replace("{name}", $username, $settings['usernamepattern'])));
}

/**************
 *	 Method:	
 *		ping_thread2
 * Description:
 *	  	Lets the operator ping the thread 
 * Author:
 * 		ENsoesie 	3/22/2014	Creation
 * Remark: 
 *		Borrowed from "chat.php", letting it use the already created database handle. 
 * 		See the comment up top about the database resource bug.
 ***********/
function ping_thread2($thread, $isuser, $istyping, $link)
{
	global $kind_for_agent, $state_queue, $state_loading, $state_chatting, $state_waiting, $kind_conn, $connection_timeout;
	$params = array(($isuser ? "lastpinguser" : "lastpingagent") => "CURRENT_TIMESTAMP",
					($isuser ? "userTyping" : "agentTyping") => ($istyping ? "1" : "0"));

	$lastping = $thread[$isuser ? "lpagent" : "lpuser"];
	$current = $thread['current'];

	if ($thread['istate'] == $state_loading && $isuser) {
		$params['istate'] = intval($state_queue);
		commit_thread($thread['threadid'], $params, $link);
		return;
	}

	if ($lastping > 0 && abs($current - $lastping) > $connection_timeout) {
		$params[$isuser ? "lastpingagent" : "lastpinguser"] = "0";
		if (!$isuser) {
			$message_to_post = getstring_("chat.status.user.dead", $thread['locale']);
			post_message_($thread['threadid'], $kind_for_agent, $message_to_post, $link, null, $lastping + $connection_timeout);
		} else if ($thread['istate'] == $state_chatting) {

			$message_to_post = getstring_("chat.status.operator.dead", $thread['locale']);
			post_message_($thread['threadid'], $kind_conn, $message_to_post, $link, null, $lastping + $connection_timeout);
			$params['istate'] = intval($state_waiting);
			$params['nextagent'] = 0;
			commit_thread($thread['threadid'], $params, $link);
			return;
		}
	}

	update_thread_access($thread['threadid'], $params, $link);
}


/**************
 *	 Method:	
 *		check_for_reassign2
 * Description:
 *	  	Checks whether re-assigning the thread to this user has been initiated. 
 * Author:
 * 		ENsoesie 	3/22/2014	Creation
 * Remark: 
 *		Borrowed from "chat.php", letting it use the already created database handle. 
 * 		See the comment up top about the database resource bug.
 ***********/
function check_for_reassign2($thread, $operator, $link)
{
	global $state_waiting, $home_locale, $kind_events, $kind_avatar;
	$operatorName = ($thread['locale'] == $home_locale) ? $operator['vclocalename'] : $operator['vccommonname'];
	if ($thread['istate'] == $state_waiting &&
		($thread['nextagent'] == $operator['operatorid']
		 || $thread['agentId'] == $operator['operatorid'])) {
		do_take_thread2($thread['threadid'], $operator['operatorid'], $operatorName, $link);
		if ($operatorName != $thread['agentName']) {
			$message_to_post = getstring2_("chat.status.operator.changed", array($operatorName, $thread['agentName']), $thread['locale'], true);
		} else {
			$message_to_post = getstring2_("chat.status.operator.returned", array($operatorName), $thread['locale'], true);
		}

		post_message_($thread['threadid'], $kind_events, $message_to_post, $link);
		post_message_($thread['threadid'], $kind_avatar, $operator['vcavatar'] ? $operator['vcavatar'] : "", $link);
	}
}


/**************
 *	 Method:	
 *		do_take_thread2
 * Description:
 *	  	Takes over the thread. 
 * Author:
 * 		ENsoesie 	3/22/2014	Creation
 * Remark: 
 *		Borrowed from "chat.php", letting it use the already created database handle. 
 * 		See the comment up top about the database resource bug.
 ***********/
function do_take_thread2($threadid, $operatorId, $operatorName, $link)
{
	global $state_chatting;
	commit_thread($threadid,
				  array("istate" => intval($state_chatting),
					   "nextagent" => 0,
					   "agentId" => intval($operatorId),
					   "agentName" => "'" . mysql_real_escape_string($operatorName, $link) . "'"), $link);
}

/**************
 *	 Method:	
 *		notify_operator_alive2
 * Description:
 *	  	Notify that the operator is still online, like a ping. 
 * Author:
 * 		ENsoesie 	3/22/2014	Creation
 * Remark: 
 *		Borrowed from "operator.php", letting it use the already created database handle. 
 * 		See the comment up top about the database resource bug.
 ***********/
function notify_operator_alive2($operatorid, $istatus, $link)
{
	global $mysqlprefix;
	perform_query(sprintf("update ${mysqlprefix}chatoperator set istatus = %s, dtmlastvisited = CURRENT_TIMESTAMP where operatorid = %s", intval($istatus), intval($operatorid)), $link);
}


/**************
 *	 Method:	
 *		take_thread2
 * Description:
 *	  	Let this operator take over the thread. 
 * Author:
 * 		ENsoesie 	3/22/2014	Creation
 * Remark: 
 *		Borrowed from "chat.php", letting it use the already created database handle. 
 * 		See the comment up top about the database resource bug.
 ***********/
function take_thread2($thread, $operator, $link)
{
	global $state_queue, $state_loading, $state_waiting, $state_chatting, $kind_events, $kind_avatar, $home_locale;

	$state = $thread['istate'];
	$threadid = $thread['threadid'];
	$message_to_post = "";

	$operatorName = ($thread['locale'] == $home_locale) ? $operator['vclocalename'] : $operator['vccommonname'];

	if ($state == $state_queue || $state == $state_waiting || $state == $state_loading) {
		do_take_thread2($threadid, $operator['operatorid'], $operatorName, $link);

		if ($state == $state_waiting) {
			if ($operatorName != $thread['agentName']) {
				$message_to_post = getstring2_("chat.status.operator.changed", array($operatorName, $thread['agentName']), $thread['locale'], true);
			} else {
				$message_to_post = getstring2_("chat.status.operator.returned", array($operatorName), $thread['locale'], true);
			}
		} else {
			$message_to_post = getstring2_("chat.status.operator.joined", array($operatorName), $thread['locale'], true);
		}
	} else if ($state == $state_chatting) {
		if ($operator['operatorid'] != $thread['agentId']) {
			do_take_thread2($threadid, $operator['operatorid'], $operatorName, $link);
			$message_to_post = getstring2_("chat.status.operator.changed", array($operatorName, $thread['agentName']), $thread['locale'], true);
		}
	} else {
		// DEBUG: This should be an error code and not a die
		die("cannot take thread");
	}

	if ($message_to_post) {
		post_message_($threadid, $kind_events, $message_to_post, $link);
		post_message_($threadid, $kind_avatar, $operator['vcavatar'] ? $operator['vcavatar'] : "", $link);
	}
}



?>
