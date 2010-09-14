<?php

/**
 * Govfresh Voice
 * @copyright 2010 Mark J. Headd (http://www.voiceingov.org)
 * @package TropoPHP
 * @author Mark Headd
 * 
 */

// Include required classes.
require('classes/tropo.class.php');
require('classes/limonade/lib/limonade.php');

// Parse the settings file
$settings = parse_ini_file('config/settings.ini', true);

// A helper variable used to make absolute references to scripts in the same directory (reference made from Tropo platform).
$server_name = 'http://'.$_SERVER['SERVER_NAME'].substr($_SERVER['PHP_SELF'],0,strrpos($_SERVER['PHP_SELF'],'/')).'/';

// The starting point for the application, user selects an extension to transfer to.
dispatch_post('/start', 'transfer_start');
function transfer_start() {
		
	global $settings;
	$retry = $_REQUEST['retry'];
	
	try {
		$session = new Session();
		$from_info = $session->getFrom();
		$caller_id = $from_info["id"];
	}
	catch (Exception $ex) {
		$caller_id = $_REQUEST['caller_id'];
	}	
		
	$tropo = new Tropo();
	
	if($settings["global"]["play_system_meessage"]) {
		$tropo->say($settings["audio"]["system_messsage"]);
		$tropo->on(array("event" => "continue", "next" => "index.php?uri=end"));
	}
	else {
		
		if($retry) {
			$tropo->say($settings["audio"]["main_menu_retry"]);	
		}
		else {
			$tropo->say($settings["audio"]["greeting_message"]);
			$tropo->say($settings["audio"]["main_menu"]);
		}

		foreach($settings["services"] as $key => $service) {
			$service_details = explode(",", $service);
			$services_to_say .= "For the $service_details[2], enter $key. ";
		}
		$options = array("attempts" => 3, "bargein" => true, "choices" => "[4 DIGITS]", "name" => "extension", "timeout" => 5);
		$tropo->ask($services_to_say, $options);
		
		$tropo->on(array("event" => "continue", "next" => "index.php?uri=action&caller_id=$caller_id"));
		$tropo->on(array("event" => "error", "next" => "index.php?uri=error"));
	}
	
	return $tropo->renderJSON();
	
}

// Process the user selection.
dispatch_post('/action', 'transfer_action');
function transfer_action() {
	
	global $settings;
	$caller_id = $_REQUEST['caller_id'];
	$service_extensions = explode(",", $settings["services"]["extensions"]);
	
	try {
		$result = new Result();
		$extension = (int) trim($result->getInterpretation());
	}
	catch (Exception $ex) {
		$extension = (int) $_REQUEST['extension'];
	}
	
	$tropo = new Tropo();
	
	// Check to see if entered extension is valid.
	if((is_null($settings["extensions"][$extension])) && (is_null($settings["services"][$extension]))) {
			$tropo->say($settings["audio"]["invalid_extension"]);
			$tropo->on(array("event" => "continue", "next" => "index.php?uri=start&caller_id=$caller_id&retry=true"));
	}
	else {
		if($settings["always_attempt_transfer"]) {
			$tropo->say($settings["audio"]["transfer_hold"]);
			$tropo->on(array("event" => "continue", "next" => "index.php?uri=call&extension=$extension"));
		}
		else {
			$options = array("attempts" => 3, "bargein" => true, "choices" => "[1 DIGITS]", "name" => "selection", "timeout" => 5);
			$tropo->ask($settings["audio"]["transfer_menu"], $options);
			$tropo->on(array("event" => "continue", "next" => "index.php?uri=result&extension=$extension&caller_id=$caller_id"));
		}
	}
	
	return $tropo->renderJSON();
	
}

// If the user has the option of leaving a voicemail, process their selection.
dispatch_post('/result', 'transfer_result');
function transfer_result() {
	
	global $settings;
	$extension = $_REQUEST['extension'];
	$caller_id = $_REQUEST['caller_id'];
	
	$result = new Result();
	$choice = trim($result->getInterpretation());
	
	$tropo = new Tropo();
	
	if($choice == 1) {
		$tropo->say($settings["audio"]["transfer_hold"]);
		$tropo->on(array("event" => "continue", "next" => "index.php?uri=call&extension=$extension&caller_id=$caller_id"));
	}
	else if($choice == 2) {
		$tropo->say($settings["audio"]["record_length_message"]);
		$tropo->on(array("event" => "continue", "next" => "index.php?uri=voicemail&extension=$extension&caller_id=$caller_id"));
	}
	else {
		$tropo->say($settings["audio"]["invalid_entry"]);
		$tropo->on(array("event" => "continue", "next" => "index.php?uri=action&extension=$extension"));
	}
	
	return $tropo->renderJSON();
}

// Transfer the user to the selected extension.
dispatch_post('/call', 'transfer_call');
function transfer_call() {
	
	global $settings;
	$extension = $_REQUEST['extension'];
	$caller_id = $_REQUEST['caller_id'];
	$extension_info = explode(",", $settings["extensions"][$extension]);
	$number_to_call = $extension_info[0];
	
	$tropo = new Tropo();
	
	// Send the screen pop with the calling party's number.
	if($settings["global"]["screen_pop"]) {
		$im_to_pop = $extension_info[2];
		$message_text = str_replace("%caller_id%", $caller_id, $settings["global"]["screen_pop_message_text"]);
		$tropo->message($message_text, array("to" => $im_to_pop,"channel" => Channel::$text, "network" => $settings["global"]["screen_pop_network"]));
	}
	
	$tropo->transfer("+1$number_to_call", array("timeout" => 60, "ringRepeat" => 5));
	$tropo->hangup();

	return $tropo->renderJSON();
	
}

// Allow the user to leave a voicemail, and send transcription to called party.
dispatch_post('/voicemail', 'transfer_voicemail');
function transfer_voicemail() {
	
	global $settings, $server_name;
	$extension = $_REQUEST['extension'];
	$caller_id = $_REQUEST['caller_id'];
	$extension_info = explode(",", $settings["extensions"][$extension]);
	$email_to_send = $extension_info[1];
	
	$tropo = new Tropo();

	$say = new Say($settings["audio"]["record_message"]);
	$choices = new Choices(null, null, "#");
	$transcription = new Transcription("mailto:".$email_to_send, $caller_id, "omit");
	$record = new Record(3, true, true, $choices, AudioFormat::$wav, 5, 60, "POST", null, true, $say, 10, $transcription, null, $server_name."saveRecording.php?caller_id=$caller_id");
	$tropo->record($record);
	$tropo->on(array("event" => "continue", "next" => "index.php?uri=end"));
	$tropo->on(array("event" => "incomplete", "next" => "index.php?uri=voicemail&extension=$extension"));
	$tropo->on(array("event" => "error", "next" => "index.php?uri=error"));
	
	return $tropo->renderJSON();
	
}

// An error handler.
dispatch_post('/error', 'handle_error');
function handle_error() {
	
	global $settings;
	
	$tropo = new Tropo();
	$tropo->say($settings["audio"]["error_message"]);
	$tropo->hangup();
	return $tropo->renderJSON();
	
}

// End the user session.
dispatch_post('/end', 'the_end');
function the_end() {
	
	global $settings;
	
	$tropo = new Tropo();
	$tropo->say($settings["audio"]["goodbye_message"]);
	$tropo->hangup();
	return $tropo->renderJSON();
	
}

// Let's get jiggy!
run();

?>