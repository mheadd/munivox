<?php

/**
 * Govfresh Voice
 * @copyright 2010 Mark J. Headd (http://www.voiceingov.org)
 * @author Mark Headd
 * 
 */

// Get the submitted caller id
$caller_id = $_REQUEST['caller_id'];
$extension = $_REQUEST['extension'];

// Give the recording a unique name.
$target_path = "voicemail/$extension/rec-$caller_id-".uniqid().".wav";

// Save the file.
move_uploaded_file($_FILES['filename']['tmp_name'], $target_path);

?>