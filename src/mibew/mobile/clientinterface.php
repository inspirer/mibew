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


/*
 * This is the entry point of the mobile client API. 
 * It is a JSON-based API. All the supporting methods
 * will return an array where appropriate and will be JSON
 * encoded here. This allows for easy migration to other standards
 */
 
 
require_once('../libs/common.php');
require_once('functions.php');

// Log every request that comes in.

/* Uncomment this block for debugging

$outfile = fopen("requestfile-".date('Y-m-d').".txt", "a");
$request = date('Y-m-d G:i:s {');
foreach($_REQUEST as $key => $value) {
	$request .= "$key: $value, ";
}
$request .= "END}\r\n";

fwrite($outfile, $request);
fclose($outfile);
*/


header("Content-Type: application/json");


$g_clientAPIVer = verifyparam2("apiver", "/.+/");
$g_postInput = null;
$g_postCmd = null;
if ($g_clientAPIVer == null) {
	$g_postInput = json_decode(file_get_contents('php://input'), true);
	$g_clientAPIVer = verifyparam3('apiver', $g_postInput, '/.+/');
	$g_postCmd = verifyparam3('cmd', $g_postInput, '/.+/');
}

/* Uncomment this block for debugging

$outfile = fopen("requestfile-".date('Y-m-d').".txt", "a");
$request = date('Y-m-d G:i:s {');
$request .= file_get_contents('php://input') . " -- ";
foreach($g_postInput as $key => $value) {
	$request .= "$key: $value, ";
}
$request .= "END}\r\n";

fwrite($outfile, $request);
fclose($outfile);
*/

process_client_command();


// Mobile client command processor
function process_client_command() {
	global $g_postCmd;
	
	$cmd = verifyparam2("cmd", "/.+/");
	
	if ($cmd == null && $g_postCmd == null) {
		$out = invalid_command();
		$jsonOut = json_encode($out);
		echo $jsonOut;
		return;
	}

	if ($cmd == 'isalive') {
		$out = chat_server_status();
		$jsonOut = json_encode($out);
		echo $jsonOut;
		return;
	}
	
	global $g_clientAPIVer, $g_postInput;
	
	if (!validateAPIVersion($g_clientAPIVer)) {
		$out = invalid_apiversion();
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	else if($cmd == 'login') {
		$username = verifyparam2('username', '/.+/');
		$password = verifyparam2('password', '/.+/');
		$deviceuuid = verifyparam2('deviceuuid', '/.+/');
		
		$out = mobile_login($username, $password, $deviceuuid);
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	else if($cmd == 'logout') {
		$oprtoken = verifyparam2('oprtoken', '/.+/');
	
		$out = mobile_logout($oprtoken);
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	else if ($cmd == 'visitorlist') {
		$oprtoken = verifyparam2('oprtoken', '/.+/');
		$deviceVisitors = verifyparam2('activevisitors', '/.+/');
		$stealthMode = isset($_GET['stealth']);
		
		$out = get_active_visitors($oprtoken, $deviceVisitors, $stealthMode);
	
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	else if ($cmd == 'visitornotification') {
		$oprtoken = verifyparam2('oprtoken', '/.+/');
		$deviceVisitors = verifyparam2('activevisitors', '/.+/');
		$stealthMode = isset($_GET['stealth']);
	
		$out = get_active_visitors_notification($oprtoken, $deviceVisitors, $stealthMode);
	
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	else if ($cmd == 'startchat') {
		$oprtoken = verifyparam2('oprtoken', '/.+/');
		$threadid = verifyparam2('threadid', '/.+/');
		
		$out = start_chat($oprtoken, $threadid);
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	else if ($cmd == 'newmessages') {
		$oprtoken = verifyparam2('oprtoken', '/.+/');
		$threadid = verifyparam2('threadid', '/.+/');
		$chattoken = verifyparam2('token', '/.+/');
		$istyping = verifyparam2("typed", "/^1$/", "") == '1';
		
		$out = get_new_messages($oprtoken, $threadid, $chattoken, $istyping);
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	else if($cmd == 'syncserveroperator') {
		$oprtoken = verifyparam2('oprtoken', '/.+/');
	
		$out = sync_server_and_operator_details($oprtoken);
		$jsonOut = json_encode($out);
		echo $jsonOut;
	} else if ($cmd == 'synccannedmessages') {
		$oprtoken = verifyparam2('oprtoken', '/.+/');
		$deviceCannedMsgHashes = verifyparam2('cannedmsghashes', '/.+/');
	
		$out = sync_canned_messages($oprtoken, $deviceCannedMsgHashes);
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
	/*else if ($_GET['cmd'] == 'postmessages') {
		$oprtoken = $_GET['oprtoken'];
		$opMessages = $_GET['messages'];
		
		$out = batch_op_messages($oprtoken, json_decode($opMessages, true));
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}*/
	
	// Strictly to get info about the hosting server.
	// Should be commented out for production
	/*else if ($_GET['cmd'] == 'phpinfo') {
		header("Content-Type: text/html");
		phpinfo();
	}*/
	
	else if ($g_postInput != null)
	{
		// This may be JSON data that is POSTed. Try to parse it as such.
		if ($g_postCmd == 'postmessage') {
			$oprtoken = verifyparam3('oprtoken', $g_postInput, '/.+/');
			$threadid = verifyparam3('threadid', $g_postInput, '/.+/');
			$chattoken = verifyparam3('token', $g_postInput, '/.+/');
			$opMsgIdL = verifyparam3('messageidl', $g_postInput, '/.+/');
			$opMsg = verifyparam3('message', $g_postInput, '/.+/');
			
			$out = msg_from_mobile_op($oprtoken, $threadid, $chattoken, $opMsgIdL, $opMsg);
			$jsonOut = json_encode($out);
			echo $jsonOut;
		} else if ($g_postCmd == 'closethread') {
			$oprtoken = verifyparam3('oprtoken', $g_postInput, '/.+/');
			$threadid = verifyparam3('threadid', $g_postInput, '/.+/');
		
			$out = close_thread_mobile($oprtoken, $threadid);
			$jsonOut = json_encode($out);
			echo $jsonOut;
		} else if ($g_postCmd == 'ack-messages') {
			// TODO: This should be a POST instead of a GET 
			$oprtoken = verifyparam3('oprtoken', $g_postInput, '/.+/');
			$msgList = verifyparam3('messageids', $g_postInput, '/.+/');
			
			$out = ack_messages($oprtoken, $msgList);
			$jsonOut = json_encode($out);
			echo $jsonOut;
		} else {
			$out = invalid_command();
			$jsonOut = json_encode($out);
			echo $jsonOut;
		}
	} else {
		$out = invalid_command();
		$jsonOut = json_encode($out);
		echo $jsonOut;
	}
}
?>
