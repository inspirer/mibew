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
 * Author:	Eyong Nsoesie (eyong.ns@gmail.com)
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

// For testing
// C74BEDBF52

// Log every request that comes in.
$outfile = fopen("requestfile.txt", "a");
$request = date('Y-d-m G:i:s {');
foreach($_REQUEST as $key => $value) {
	$request .= "$key: $value, ";
}
$request .= "END}\r\n";

fwrite($outfile, $request);
fclose($outfile);


header("Content-Type: application/json");

// Mobile client command processor
if ($_GET['cmd'] == 'isalive') {
	$out = chat_server_status();
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if($_GET['cmd'] == 'login') {
	$username = $_GET['username'];
	$password = $_GET['password'];
	$deviceuuid = $_GET['deviceuuid'];
	
	$out = mobile_login($username, $password, $deviceuuid);
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if($_GET['cmd'] == 'logout') {
	$oprtoken = $_GET['oprtoken'];

	$out = mobile_logout($oprtoken);
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if ($_GET['cmd'] == 'visitorlist') {
	$oprtoken = $_GET['oprtoken'];
	$deviceVisitors = $_GET['activevisitors'];
	$stealthMode = isset($_GET['stealth']);
	
	$out = get_active_visitors($oprtoken, $deviceVisitors, $stealthMode);

	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if ($_GET['cmd'] == 'visitornotification') {
	$oprtoken = $_GET['oprtoken'];
	$deviceVisitors = $_GET['activevisitors'];
	$stealthMode = isset($_GET['stealth']);

	$out = get_active_visitors_notification($oprtoken, $deviceVisitors, $stealthMode);

	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if ($_GET['cmd'] == 'startchat') {
	$oprtoken = $_GET['oprtoken'];
	$threadid = $_GET['threadid'];
	
	$out = start_chat($oprtoken, $threadid);
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if ($_GET['cmd'] == 'newmessages') {
	$oprtoken = $_GET['oprtoken'];
	$threadid = $_GET['threadid'];
	$chattoken = $_GET['token'];
	$istyping = verifyparam2("typed", "/^1$/", "") == '1';
	
	$out = get_new_messages($oprtoken, $threadid, $chattoken, $istyping);
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if ($_GET['cmd'] == 'ack-messages') {
	$oprtoken = $_GET['oprtoken'];
	$msgList = $_GET['messageids'];
	
	$out = ack_messages($oprtoken, $msgList);
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if ($_GET['cmd'] == 'postmessage') {
	$oprtoken = $_GET['oprtoken'];
	$threadid = $_GET['threadid'];
	$chattoken = $_GET['token'];
	$opMsgIdL = $_GET['messageidl'];
	$opMsg = $_GET['message'];
	
	$out = msg_from_mobile_op($oprtoken, $threadid, $chattoken, $opMsgIdL, $opMsg);
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
else if ($_GET['cmd'] == 'closethread') {
	$oprtoken = $_GET['oprtoken'];
	$threadid = $_GET['threadid'];

	$out = close_thread_mobile($oprtoken, $threadid);
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

else
{
	$out = invalid_command();
	$jsonOut = json_encode($out);
	echo $jsonOut;
}
?>
