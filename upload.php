<?php
if (!current_user_can('upload_files'))
	wp_die(__('You do not have permission to upload files.'));

// Define DB table names
global $wpdb;
$table_name = $wpdb->prefix . "posts";
$postmeta_table_name = $wpdb->prefix . "postmeta";

function emr_delete_current_files($current_file) {
	// Delete old file

	// Find path of current file
	$current_path = substr($current_file, 0, (strrpos($current_file, "/")));
	
	// Check if old file exists first
	if (file_exists($current_file)) {
		// Now check for correct file permissions for old file
		clearstatcache();
		if (is_writable($current_file)) {
			// Everything OK; delete the file
			unlink($current_file);
		}
		else {
			// File exists, but has wrong permissions. Let the user know.
			printf(__('The file %1$s can not be deleted by the web server, most likely because the permissions on the file are wrong.', "enable-media-replace"), $current_file);
			exit;	
		}
	}
	
	// Delete old resized versions if this was an image
	$suffix = substr($current_file, (strlen($current_file)-4));
	$prefix = substr($current_file, 0, (strlen($current_file)-4));
	$imgAr = array(".png", ".gif", ".jpg");
	if (in_array($suffix, $imgAr)) { 
		// It's a png/gif/jpg based on file name
		// Get thumbnail filenames from metadata
		$metadata = wp_get_attachment_metadata($_POST["ID"]);
		if (is_array($metadata)) { // Added fix for error messages when there is no metadata (but WHY would there not be? I don't know…)
			foreach($metadata["sizes"] AS $thissize) {
				// Get all filenames and do an unlink() on each one;
				$thisfile = $thissize["file"];
				if (strlen($thisfile)) {
					$thisfile = $current_path . "/" . $thissize["file"];
					if (file_exists($thisfile)) {
						unlink($thisfile);
					}
				}
			}
		}
		// Old (brutal) method, left here for now
		//$mask = $prefix . "-*x*" . $suffix;
		//array_map( "unlink", glob( $mask ) );
	}

}

/**
 * Given old and new metadata about a post, this will identify changes in
 * filenames for sized files (thumbnail, etc) and return them as an array of
 * <old url, new url>.  If $new_meta does not have a size whose name matches up
 * with those in $original_meta, the closest size by dimension is used.
 *
 * $original_base_url and $new_base_url should point be *URLS* referring to the
 * *directories* containing the old file and new file respectively.
 */
function emr_get_sized_rewrites($original_meta, $original_url, $new_meta, $new_url) {
	// Map of <from, to> url replacements
	$rewrites = array();
	foreach ($original_meta['sizes'] as $size => $s) {
		// Original dimensions
		$oh = $s['height'];
		$ow = $s['width'];

		// Will hold the best-match new filename and dimensions
		$new_filename = '';
		$nh = -1;
		$nw = -1;

		if (isset($new_meta['sizes']) and count($new_meta['sizes'])) {
			// Find best-matching new size, using $size if possible and
			// closest dimensions otherwise.
			if (array_key_exists($size, $new_meta['sizes'])) {
				$new_filename = $new_meta['sizes'][$size]['file'];
				$nh = $new_meta['sizes'][$size]['height'];
				$nw = $new_meta['sizes'][$size]['width'];
			}
			else {
				$diffs  = array();
				foreach ($new_meta['sizes'] as $new_size => $new_s) {
					$diffs[$new_size] = (int) abs( ($ow - $new_s['width']) * ($oh - $new_s['height']) );
				}
				$diffs_flipped = array_flip($diffs);
				$new_meta_size_key = $diffs_flipped[min($diffs)];
			}
		}
		else {
			// No sizes for new file, fallback on full size
			$new_filename = $new_url;
		}

		$ourl = sprintf('%s/%s', dirname($original_url), $s['file']);
		$nurl = sprintf('%s/%s', dirname($new_url), $new_filename);
		$rewrites[$ourl] = $nurl;
	}

	return $rewrites;
}

function emr_perform_rewrites($rewrites, $table_name) {
	global $wpdb;
	// ["post_content LIKE "%url1%", ...]
	$likes = array();

	// Will hold from/to targets for str_replace
	$from_list = $to_list = array();
	
	foreach ($rewrites as $url_from => $url_to) {
		$path_from = parse_url($url_from, PHP_URL_PATH);
		$path_to = parse_url($url_to, PHP_URL_PATH);
		$likes[] = sprintf('post_content LIKE "%%%s%%"',$wpdb->esc_like($path_from));
		$from_list[] = $path_from;
		$to_list[] = $path_to;
	}
	
	$sql = "SELECT ID, post_content, post_type FROM $table_name WHERE " . implode(' OR ', $likes);
	
	$results = $wpdb->get_results($sql, ARRAY_A);

	foreach($results as $row) {

		// replace old guid with new guid
		$post_content = $row["post_content"];
		$replacements = null;
		$post_content = str_replace($from_list, $to_list, $post_content, $replacements);

		if ($replacements) {
			$post_content = esc_sql($post_content);
			$wpdb->query(sprintf("UPDATE $table_name SET post_content = '%s' WHERE ID = %d", $post_content, $row['ID']));

			// Clear the post cache for the rewritten post.
			if(function_exists('bu_clean_post_cache_single')){
				// BU Cache expects a post object with an ID and post_type.
				$row_obj = new stdClass;
				$row_obj->ID = $row['ID'];
				$row_obj->post_type = $row['post_type'];

				bu_clean_post_cache_single($row_obj);
			}
		}
	}
}

// Get old guid and filetype from DB
$sql = "SELECT guid, post_mime_type FROM $table_name WHERE ID = '" . (int) $_POST["ID"] . "'";
list($current_filename, $current_filetype) = $wpdb->get_row($sql, ARRAY_N);

// Massage a bunch of vars
$current_guid = $current_filename;
$current_filename = substr($current_filename, (strrpos($current_filename, "/") + 1));

$current_file = get_attached_file((int) $_POST["ID"], true);
$current_path = substr($current_file, 0, (strrpos($current_file, "/")));
$current_file = str_replace("//", "/", $current_file);
$current_filename = basename($current_file);

$replace_type = $_POST["replace_type"];

// We have two types: replace / replace_and_search

if (is_uploaded_file($_FILES["userfile"]["tmp_name"])) {

	// New method for validating that the uploaded file is allowed, using WP:s internal wp_check_filetype_and_ext() function.
	$filedata = wp_check_filetype_and_ext($_FILES["userfile"]["tmp_name"], $_FILES["userfile"]["name"]);
	
	if ($filedata["ext"] == "") {
		echo __("File type does not meet security guidelines. Try another.");
		exit;
	}
	
	$new_filename = $_FILES["userfile"]["name"];
	$new_filesize = $_FILES["userfile"]["size"];
	$new_filetype = $filedata["type"];
	
	if ($replace_type == "replace") {
		// Drop-in replace and we don't even care if you uploaded something that is the wrong file-type.
		// That's your own fault, because we warned you!

		emr_delete_current_files($current_file);

		// Move new file to old location/name
		move_uploaded_file($_FILES["userfile"]["tmp_name"], $current_file);

		// Chmod new file to 644
		chmod($current_file, 0644);

		// Make thumb and/or update metadata
		wp_update_attachment_metadata( (int) $_POST["ID"], wp_generate_attachment_metadata( (int) $_POST["ID"], $current_file ) );

		// Trigger possible updates on CDN and other plugins 
		update_attached_file( (int) $_POST["ID"], $current_file);
	}

	else {
		// Replace file, replace file name, update meta data, replace links pointing to old file name

		emr_delete_current_files($current_file);

		// Move new file to old location, new name
		$new_file = $current_path . "/" . $new_filename;
		move_uploaded_file($_FILES["userfile"]["tmp_name"], $new_file);

		// Chmod new file to 644
		chmod($new_file, 0644);

		$new_filetitle = preg_replace('/\.[^.]+$/', '', basename($new_file));
		$new_filetitle = apply_filters( 'enable_media_replace_title', $new_filetitle ); // Thanks Jonas Lundman (http://wordpress.org/support/topic/add-filter-hook-suggestion-to)
		$new_guid = str_replace($current_filename, $new_filename, $current_guid);

		// Update database file name
		$sql = $wpdb->prepare(
			"UPDATE $table_name SET post_title = '$new_filetitle', post_name = '$new_filetitle', guid = '$new_guid', post_mime_type = '$new_filetype' WHERE ID = %d;",
			(int) $_POST["ID"]
		);
		$wpdb->query($sql);

		// Update the postmeta file name

		// Get old postmeta _wp_attached_file
		$sql = $wpdb->prepare(
			"SELECT meta_value FROM $postmeta_table_name WHERE meta_key = '_wp_attached_file' AND post_id = %d;",
			(int) $_POST["ID"]
		);
		
		$old_meta_name = $wpdb->get_row($sql, ARRAY_A);
		$old_meta_name = $old_meta_name["meta_value"];

		// Make new postmeta _wp_attached_file
		$new_meta_name = str_replace($current_filename, $new_filename, $old_meta_name);
		$sql = $wpdb->prepare(
			"UPDATE $postmeta_table_name SET meta_value = '$new_meta_name' WHERE meta_key = '_wp_attached_file' AND post_id = %d;",
			(int) $_POST["ID"]
		);
		$wpdb->query($sql);

		// Make thumb and/or update metadata.  Capture original meta for later.
		$original_meta = wp_get_attachment_metadata($_POST["ID"]);
		wp_update_attachment_metadata( (int) $_POST["ID"], wp_generate_attachment_metadata( (int) $_POST["ID"], $new_file) );
		$new_meta = wp_get_attachment_metadata($_POST["ID"]);

		// Search-and-replace filename in post database
		$wud = wp_upload_dir();
		$baseurl_rel = parse_url($wud['baseurl'], PHP_URL_PATH); // "/some-site/files"
		$url_new = wp_get_attachment_url($_POST["ID"]);
		$url_old = sprintf('%s/%s', $baseurl_rel, $old_meta_name); // "/files/2010/08/test-image.jpg"
		
		// Build up a list of rewrites to perform.  Start with direct file URLs.
		$rewrites = array($url_old => $url_new);
		
		// If the original file had alternate sizes, add those too.
		if (array_key_exists('sizes', $original_meta) && $original_meta['sizes']) {
			$sized_rewrites = emr_get_sized_rewrites($original_meta, $url_old, $new_meta, $url_new);
			$rewrites = array_merge($rewrites, $sized_rewrites);
		}
	
		/** Perform rewrites **/
		emr_perform_rewrites($rewrites, $table_name);
	
		// Trigger possible updates on CDN and other plugins 

		update_attached_file( (int) $_POST["ID"], $new_file);
		
		if(function_exists('bu_clean_post_cache_single')){
			// BU Cache expects a post object, but only uses ID and post_type.  Don't bother fetching the full post object, just make a new object to pass to BU Cache.
			$post_obj = new stdClass;
			$post_obj->ID = $_POST["ID"];
			$post_obj->post_type = 'attachment';

			bu_clean_post_cache_single($post_obj);
		}
	}

	$returnurl = get_bloginfo("wpurl") . "/wp-admin/upload.php?posted=3";
	$returnurl = get_bloginfo("wpurl") . "/wp-admin/post.php?post={$_POST["ID"]}&action=edit&message=1";
	
	// Execute hook actions - thanks rubious for the suggestion!
	if (isset($new_guid)) { do_action("enable-media-replace-upload-done", ($new_guid ? $new_guid : $current_guid)); }
	
} else {
	//TODO Better error handling when no file is selected.
	//For now just go back to media management
	$returnurl = get_bloginfo("wpurl") . "/wp-admin/upload.php";
}

if (FORCE_SSL_ADMIN) {
	$returnurl = str_replace("http:", "https:", $returnurl);
}

//save redirection
wp_redirect($returnurl);
?>	
