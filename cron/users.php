<?php

header('Content-type: text/plain;charset=utf8');

require_once ('../codebird.php');
require_once ('../db.php');
require_once ('../'.$_GET['city'].'/config.php');

//Initiate Codebird with consumer-key and -secret
\Codebird\Codebird::setConsumerKey($key, $secret);
$cb = \Codebird\Codebird::getInstance();

//load user-token and -secret from database
$sql = 'SELECT `token`, `secret` FROM `twitter_token` WHERE city = "'.$city.'" LIMIT 1';
$result = query_mysql($sql, $link);
while ($row = mysql_fetch_assoc($result)) {
	$cb->setToken($row["token"], $row["secret"]);
}
mysql_free_result($result);

//load request-id, current page and check if we are already done here
$sql = 'SELECT `id`, `page`, `done`, `pause` FROM `twitter_requests` WHERE city = "'.$city.'" LIMIT 1';
$result = query_mysql($sql, $link);
while ($row = mysql_fetch_assoc($result)) {
	$request_id = $row["id"];
	$since = $row["page"];
	$done = $row["done"];
	$pause = $row["pause"];
}
mysql_free_result($result);

$g = 0;

if($pause<1){
	//Depending on limitation we can run multiple rounds
	//As we are running a couple of other queries at the
	//same time, we can only do it once
	for($i=0; $i<3; $i++){
		//Create the user-id string
		$users = "";

		$sql = 'SELECT `id` FROM `'.$city.'_twitter_users` WHERE done = 0 LIMIT 100';
		$result = query_mysql($sql, $link);
		while ($row = mysql_fetch_assoc($result)) {
			if($users != ""){ $users .= ","; }
			$users .= $row["id"];
		}
		mysql_free_result($result);

		$reply = $cb->users_lookup(array('user_id'=>$users, 'include_entities'=>'true'));

		$g += processResults($reply, $link, $request_id, $city);
	}

	echo 'users:'.$city.':'.$g;
}else{
	echo 'pause';
}

function processResults($results, $link, $request_id, $city){
	$c = 0;

	foreach ($results as $result) {
		if(isset($result->id_str)){
	
			//Store all values of the object as metadata, beside the user-object
			foreach($result as $key => $value){
				goDeeper("", $key, $value, $link, $request_id, $city, $result);
			}

			$sql = 'UPDATE `'.$city.'_twitter_users` SET `done` = 1 WHERE id = '.$result->id_str;
			$update = query_mysql($sql, $link);
			mysql_free_result($update);
			$c++;

		}
	}

	return $c;
}

function goDeeper($parent, $key, $value, $link, $request_id, $city, $result){
	if(!is_object($value) && !is_array($value) && $key != "status"){
		$sql = 'INSERT INTO `'.$city.'_twitter_usermetadata` (`twitter_user_id`, `field_key`, `field_value`)VALUES("'.$result->id_str.'", "'.$parent.$key.'", "'.str_replace('"', "'", $value).' ")';
		$insert = query_mysql($sql, $link);
		mysql_free_result($insert);
	}else if((is_object($value) || is_array($value)) && $key != "status"){
		foreach ($value as $inner_key => $inner_value) {
			goDeeper($key." ", $inner_key, $inner_value, $link, $request_id, $city, $result);
		}
	}
}

?>