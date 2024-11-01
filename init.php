<?php
 // This disables redirects to the installer
define('WP_INSTALLING', true);

include('../../../wp-config.php');

// Check the secret
$secret = file_get_contents(ABSPATH . '.migration_secret');
if(empty($secret) || $_REQUEST['secret'] != $secret) {
        header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error");
        die();
}

require_once(ABSPATH . 'wp-admin/includes/schema.php');

// Create the WordPress tables
$create_queries = explode(';', $wp_queries);
foreach($create_queries as $query) {
        $wpdb->query($query);
}

// Set the siteurl
$uri = $_SERVER['REQUEST_URI'];
$script_path = 'wp-content/plugins/wp-migration/init.php';
$site_path = substr($uri, 0, strpos($uri, $script_path));
$siteurl = 'http://' . $_SERVER['SERVER_NAME'] . $site_path;
update_option('siteurl', $siteurl);

// Ensure that the migration plugin is enabled
update_option('active_plugins', array('wp-migration/wp-migration.php'));
?>