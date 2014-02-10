<?php

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


$url = "http://nsoesie.dyndns-home.com:5242/transmawfoods/webim";
$logoURL = "http://nsoesie.dyndns-home.com:5242/transmawfoods/includes/templates/genesis/images/TFS-95.jpg";
$mibewMobVersion = "0.1";
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


function chat_server_status() {
	global $mysqlprefix, $version, $url;
	$link = connect();
	
	
	$row = select_one_row("SELECT * FROM ${mysqlprefix}chatmibewmobserverinfo", $link);
	if ($row != NULL) {
		return array('name' => $row['servername'],
					 'URL' => $url,		// TODO: Need to infer this either from the request or during installation
					 'version' => $version,	// TODO: This is the version as reported by mibew
					 'logoURL' => $row['logourl'],
					 'mibewMobVersion' => $row['apiversion'],
					 'installationid' => $row['installationid'],
					 'propertyrevision' => (int)$row['propertyrevision'],
					 'server_status' => 'on',
					 'errorCode' => ERROR_SUCCESS);
	} else {
		return array('errorCode' => ERROR_UNKNOWN);
	}
}

function mobile_login($username, $password, $deviceuuid) {
	if (isset($username) && isset($password)) {
		// Note: Blank passwords not currently allowed.
		$op = operator_by_login($username);
	
		if (isset($op) && calculate_password_hash($username, $password) == $op['vcpassword']) {
			$oprtoken = create_operator_session($op, $deviceuuid);
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
			return $out;
		}
	}
	
	$out = array('errorCode' => ERROR_LOGIN_FAILED);
	
	return $out;
}

function mobile_logout($oprtoken) {
	$oprSession = operator_from_token($oprtoken);
	$operatorId = $oprSession['operatorid'];

	if ($operatorId == NULL) {
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id($operatorId);
	
	global $mysqlprefix;
	$link = connect();
	
	$query = "UPDATE ${mysqlprefix}chatoperatorsession 
			  SET inprogress = 0, dtmexpires = CURRENT_TIMESTAMP
			  WHERE oprtoken = '$oprtoken'";
	
	perform_query($query, $link);
	
	$out = array('errorCode' => ERROR_SUCCESS);
	return $out;
}

function create_operator_session($op, $deviceuuid) {
	global $mysqlprefix;
	$link = connect();
	
	
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
		mysql_close($link);
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

	mysql_close($link);
	
	return $oprtoken;
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
	$oprSession = operator_from_token($oprtoken);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];

	if ($operatorId != NULL) {
		$out = get_pending_threads($deviceVisitors, $deviceid);
		$out['errorCode'] = ERROR_SUCCESS;
		if (!$stealthMode) {
			notify_operator_alive($operatorId, OPR_STATUS_ON);
		}
	}
	else {
		$out = array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}
	
	return $out;
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
function operator_from_token($oprtoken) {
	global $mysqlprefix;
	$link = connect();
	
	$query = "SELECT operatorid, deviceid FROM ${mysqlprefix}chatoperatorsession " .
			 "WHERE oprtoken = '$oprtoken'";
	$row = select_one_row($query, $link);
	
	mysql_close($link);
	
	return $row;
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
function get_pending_threads($deviceVisitors, $deviceid)
{
	global $webim_encoding, $settings, $state_closed, $state_left, $mysqlprefix;
	$link = connect();

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
		
		// SECURITY ALERT: We are using $deviceVisitors as provided in the request. There is a 
		// potential of sql injection here.
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
								$thread['message'] = get_sanitized_message($row['shownmessageid']);
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

		$newlink = connect();
		foreach ($output['threadList'] as $listItem) {
			$query = "INSERT INTO ${mysqlprefix}chatsyncedthreads " .
					 "(threadid, deviceid, state, shownmessageid, agentid) VALUES (" .
					 $listItem['threadid'] . ", $deviceid, " . $listItem['state'] . ", " .
					 (isset($listItem['shownmessageid']) ? $listItem['shownmessageid'] : '0') . ", " .
					 (isset($listItem['agentid']) ? $listItem['agentid'] : '0') . ") " .
					 " ON DUPLICATE KEY UPDATE state = " . $listItem['state'] . ", shownmessageid = " .
					 (isset($listItem['shownmessageid']) ? $listItem['shownmessageid'] : '0') . ", agentid = " .
					 (isset($listItem['agentid']) ? $listItem['agentid'] : '0') . ";";
					 
			perform_query($query, $newlink);
		}
		mysql_close($newlink);
	}
	// mysql_close($link);
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
	$name .= htmlspecialchars(htmlspecialchars(get_user_name($thread['userName'], $thread['remote'], $thread['userid'])));
	
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
		$line = get_sanitized_message($thread['shownmessageid']);
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
function get_sanitized_message($messageid) {
	global $mysqlprefix;
	if ($messageid != 0) {
		$query = "select tmessage from ${mysqlprefix}chatmessage where messageid = $messageid";
		
		$link = connect();
		$line = select_one_row($query, $link);
		mysql_close($link);

		if ($line) {
			$message = preg_replace("/[\r\n\t]+/", " ", $line["tmessage"]);
			$result = htmlspecialchars(htmlspecialchars($message));
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
	
	$oprSession = operator_from_token($oprtoken);
	$operatorId = $oprSession['operatorid'];

	if ($operatorId == NULL) {
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id($operatorId);
	$chattoken = $_GET['token'];

	$thread = thread_by_id($threadid);
	if (!$thread || !isset($thread['ltoken'])) {
		return array('errorCode' => ERROR_INVALID_THREAD);
	}

	// If the thread is already closed, then return error indicating so.
	if ($thread['istate'] == $state_closed) {
		return array('errorCode' => ERROR_THREAD_CLOSED);
	}
	
	// If token is not set, this is a new chat session for this operator
	if (!isset($chattoken)) {
		$viewonly = filter_var($_GET['viewonly'], FILTER_VALIDATE_BOOLEAN);
		$forcetake = filter_var($_GET['force'], FILTER_VALIDATE_BOOLEAN);

		if (!$viewonly && $thread['istate'] == $state_chatting && 
			$operator['operatorid'] != $thread['agentId']) {
			
			if (!is_capable($can_takeover, $operator)) {
				return array('errorCode' => ERROR_CANT_TAKEOVER);
			}
			
			if ($forcetake == false) {
				// Todo. Confirm that you want to force the takeover of the conversation
				// 1 month later and I'm not sure what this should do. This is a potential
				// bug that needs to be reviewed.
				return array('errorCode' => ERROR_CONFIRM_TAKEOVER);
			}
		}

		if (!$viewonly) {
			take_thread($thread, $operator);
		} else if (!is_capable($can_viewthreads, $operator)) {
			return array('errorCode' => ERROR_CANT_VIEW_THREAD);
		}
		
		$chattoken = $thread['ltoken'];
	}

	// Chat token may be different if token was supplied from the http request
	if ($chattoken != $thread['ltoken']) {
		return array('errorCode' => ERROR_WRONG_THREAD);
	}
	
	if ($thread['agentId'] != $operator['operatorid'] && 
		!is_capable($can_viewthreads, $operator)) {
		return array('errorCode' => ERROR_CANT_VIEW_THREAD);
	}
	
	$out = array('errorCode' => ERROR_SUCCESS,
				 'threadid' => $threadid,
				 'chattoken' => $chattoken);
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
	$oprSession = operator_from_token($oprtoken);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];

	if ($operatorId == NULL) {
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id($operatorId);
	$thread = thread_by_id($threadid);
	if (!$thread || !isset($thread['ltoken'])) {
		return array('errorCode' => ERROR_INVALID_THREAD);
	}
	
	if ($chattoken != $thread['ltoken']) {
		return array('errorCode' => ERROR_INVALID_CHAT_TOKEN);
	}
	
	ping_thread($thread, false, $istyping);
	check_for_reassign($thread, $operator);

	
	return get_unsynced_messages($threadid, $deviceid);
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
function get_unsynced_messages($threadid, $deviceid) {
	global $mysqlprefix;
	$link = connect();

	$query = "select messageid, tmessage, unix_timestamp(dtmcreated) as timestamp, threadid, 
			  agentId, tname, ikind 
			  from ${mysqlprefix}chatmessage as cm
			  where threadid = $threadid
			  and not exists (
			  	select 1 from ${mysqlprefix}chatsyncedmessages as csm
				where csm.messageid = cm.messageid
				and csm.deviceid = $deviceid)";
	
	$rows = select_multi_assoc($query, $link);

	mysql_close($link);

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
	$oprSession = operator_from_token($oprtoken);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];

	if ($operatorId == NULL) {
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$operator = operator_by_id($operatorId);
	$thread = thread_by_id($threadid);
	if (!$thread || !isset($thread['ltoken'])) {
		return array('errorCode' => ERROR_INVALID_THREAD);
	}
	
	if ($chattoken != $thread['ltoken']) {
		return array('errorCode' => ERROR_INVALID_CHAT_TOKEN);
	}

	// Steps needed to post a message
	// 1 - Check if it is in the devicemessages table
	// 2 - If so, send back the devicemessageid, messageid, timestamp, then done
	// 3 - If not post the message and get the messageid and timestamp
	// 4 - Add the message metadata to the devicemessages table
	// 5 - Send back the devicemessageid, messageid, timestamp, then done


	// 1 - Check if it is in the devicemessages table
	$link = connect();
	$result = select_one_row("select messageid, unix_timestamp(msgtimestamp)
							 from ${mysqlprefix}chatmessagesfromdevice
							 where deviceid = $deviceid and devicemessageid = $opMsgIdL", $link);
	
	// 2 - If so, send back the devicemessageid, messageid, timestamp, then done
	if ($result != NULL) {
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
	$oprSession = operator_from_token($oprtoken);
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
	
	$link = connect();
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
	$oprSession = operator_from_token($oprtoken);
	$operatorId = $oprSession['operatorid'];

	if ($operatorId == NULL) {
		return array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

	$thread = thread_by_id($threadid);
	if (!$thread || !isset($thread['ltoken'])) {
		return array('errorCode' => ERROR_INVALID_THREAD);
	}
	
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
	
	$oprSession = operator_from_token($oprtoken);
	$operatorId = $oprSession['operatorid'];
	$deviceid = $oprSession['deviceid'];
	
	$hasVisitorChange = false; // Assume no visitor

	if ($operatorId != NULL) {
		if (!$stealthMode) {
			notify_operator_alive($operatorId, OPR_STATUS_ON);
		}
		
		$link = connect();
	
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
	
		mysql_close($link);

		$out = array('errorCode' => ERROR_SUCCESS,
					 'hasvisitorchange' => $hasVisitorChange);
	}
	else {
		$out = array('errorCode' => ERROR_INVALID_OPR_TOKEN);
	}

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

?>
