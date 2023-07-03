<?php
/**
 * Uploadscreen for selecting and uploading new media file
 *
 * @author      M�ns Jonasson  <http://www.mansjonasson.se>
 * @copyright   M�ns Jonasson 13 sep 2010
 * @version     $Revision: 2303 $ | $Date: 2010-09-13 11:12:35 +0200 (ma, 13 sep 2010) $
 * @package     wordpress
 * @subpackage  enable-media-replace
 *
 */

if (!current_user_can('upload_files'))
	wp_die(__('You do not have permission to upload files.', 'enable-media-replace'));

global $wpdb;

$table_name = $wpdb->prefix . "posts";

$sql = "SELECT guid, post_mime_type FROM $table_name WHERE ID = " . (int) $_GET["attachment_id"];

list($current_filename, $current_filetype) = $wpdb->get_row($sql, ARRAY_N);

$current_filename = substr($current_filename, (strrpos($current_filename, "/") + 1));

$method = defined('EMR_METHOD') ? EMR_METHOD : EMR_METHOD_BOTH;

?>
<div class="wrap">
		<div id="icon-upload" class="icon32"><br /></div>
	<h2><?php echo __("Replace Media Upload", "enable-media-replace"); ?></h2>

	<?php
	$url = admin_url( "upload.php?page=enable-media-replace/enable-media-replace.php&noheader=true&action=media_replace_upload&attachment_id=" . (int) $_GET["attachment_id"]);
	$action = "media_replace_upload";
    $formurl = wp_nonce_url( $url, $action );
	if (FORCE_SSL_ADMIN) {
			$formurl = str_replace("http:", "https:", $formurl);
		}
	?>

	<form enctype="multipart/form-data" method="post" action="<?php echo $formurl; ?>">
	<?php
		#wp_nonce_field('enable-media-replace');
	?>
		<input type="hidden" name="ID" value="<?php echo (int) $_GET["attachment_id"]; ?>" />
		<div id="message" class="updated fade"><p><?php echo __("NOTE: You are about to replace the media file", "enable-media-replace"); ?> "<?php echo $current_filename?>". <?php echo __("There is no undo. Think about it!", "enable-media-replace"); ?></p></div>

		<p><?php echo __("Choose a file to upload from your computer", "enable-media-replace"); ?></p>

		<input type="file" name="userfile" />

		<input id="replace_type" type="hidden" name="replace_type" value="replace">
		<p><?php echo __("<strong>Replacement method:</strong> ", "enable-media-replace");  echo __("Just replace the file", "enable-media-replace"); ?></p>
		<p class="howto"><?php echo __("Note: This method requires you to upload a file of the same type (", "enable-media-replace"); ?><?php echo $current_filetype; ?><?php echo __(") as the one you are replacing. The name of the attachment will stay the same (", "enable-media-replace"); ?><?php echo $current_filename; ?><?php echo __(") no matter what the file you upload is called.", "enable-media-replace"); ?></p>

		<input type="submit" class="button" value="<?php echo __("Upload", "enable-media-replace"); ?>" /> <a href="#" onclick="history.back();"><?php echo __("Cancel", "enable-media-replace"); ?></a>

	</form>
</div>
