<?php
/*
Plugin Name: WordPress Migration
Plugin URI: http://gsoc2010.wordpress.com/brian-mckenna-automatic-migration/
Description: Migrate your blog from this URL to another URL.
Author: Brian McKenna
Author URI: http://brianmckenna.org/
*/

class WP_Migration {

        // XML-RPC client
        var $client;

        // Helpful status messages from the server
        var $responses = array();

        function WP_Migration() {
                $this->__construct();
        }

        function __construct() {
                add_action('admin_menu', array(&$this, 'adminMenu'));
                add_filter('xmlrpc_methods', array(&$this, 'xmlrpcMethods'));
                add_action('wp_ajax_migrate_files', array(&$this, 'ajaxFiles'));
                add_action('wp_ajax_migrate_init', array(&$this, 'ajaxInit'));
                add_action('wp_ajax_migrate_data', array(&$this, 'ajaxData'));
        }

        /**
         * This method adds some XMPRPC calls for migration.
         *
         * @param array $methods Existing XMLRPC calls
         * @return string
         */
        function xmlrpcMethods($methods) {
                $methods['migration.setup'] = array(&$this, 'setup');
                $methods['migration.makePost'] = array(&$this, 'makePost');
                $methods['migration.makePostMeta'] = array(&$this, 'makePostMeta');
                $methods['migration.makePostTerms'] = array(&$this, 'makePostTerms');
                $methods['migration.makeUser'] = array(&$this, 'makeUser');
                $methods['migration.makeComment'] = array(&$this, 'makeComment');
                $methods['migration.makeTaxonomy'] = array(&$this, 'makeTaxonomy');
                $methods['migration.makeLinks'] = array(&$this, 'makeLinks');
                $methods['migration.updateOptions'] = array(&$this, 'updateOptions');
                $methods['migration.finish'] = array(&$this, 'finish');
                return $methods;
        }

        /**
         * Tries to change occurances of original siteurl to the new siteurl.
         *
         * @param string $data Data to translate
         * @return string
         */
        function translateURL($data) {
                $options = get_option('migration_options');
                $from_url = $options['siteurl'];
                $to_url = get_option('siteurl');
                return str_replace($from_url, $to_url, $data);
        }

        /**
         * Check if the sent secret is the same that was stored using FTP.
         *
         * @param string $sent_secret Secret sent from the client
         * @return string
         */
        function checkSecret($sent_secret) {
                $secret = file_get_contents(ABSPATH . '.migration_secret');
                if(empty($sent_secret) || $sent_secret != $secret) {
                        $this->error = new IXR_Error( 403, __( 'Bad secret token' ) );
                        return false;
                }

                return true;
        }

        /**
         * This filter forces the fileystem method to not be direct (local).
         *
         * @param array $method The current filesystem method
         * @return string
         */
        function filesystemMethod($method) {
                // FIXME: Might not have sockets nor ftp extensions installed
                if($method == 'direct') {
                        return 'ftpsockets';
                }

                return $method;
        }

        /**
         * XMLRPC method to setup the remote installation for migration.
         * Stores some migration options and clears the database.
         *
         * @param array $args Method parameters
         * @return string
         */
        function setup($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                add_option('migration_options', $args[1]);

                // Delete all posts
                $query = new WP_Query(array(
                        'post_type' => 'any',
                        'post_status' => 'any',
                        'posts_per_page' => -1,
                ));
                $deleted = array();
                foreach($query->posts as $post) {
                        $deleted[] = $post->ID;
                        wp_delete_post($post->ID, true);
                }

                // Delete all users
                $query = new WP_User_Search();
                foreach($query->get_results() as $userid) {
                        wp_delete_user($userid, $userid);
                }

                // Delete all links
                $links = get_bookmarks();
                foreach($links as $link) {
                        wp_delete_link($link->link_id);
                }

                return _('Started migration');
        }

        /**
         * XMLRPC method called after the migration has finished.
         * Deletes the migration options.
         *
         * @param array $args Method parameters
         * @return string
         */
        function finish($args) {
                $secret = $args;
                if(!$this->checkSecret($secret)) return $this->error;

                delete_option('migration_options');
                unlink(ABSPATH . '.migration_secret');
                return _('Finished migration');
        }

        /**
         * XMLRPC method to make a post from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function makePost($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                global $wpdb;

                $postdata = $args[1];

                $post_ID = $postdata['ID'];

                $postdata['ID'] = NULL;
                $postdata['post_content'] = $this->translateURL($postdata['post_content']);
                $postdata['post_excerpt'] = $this->translateURL($postdata['post_excerpt']);
                $postdata['guid'] = $this->translateURL($postdata['guid']);

                $new_ID = wp_insert_post($postdata, true);

                // Update to the given ID
                $wpdb->update($wpdb->posts, array('ID' => $post_ID), array('ID' => $new_ID));
                $wpdb->update($wpdb->postmeta, array('post_id' => $post_ID), array('post_id' => $new_ID));

                return _('Saved post #') . $post_ID;
        }

        /**
         * XMLRPC method to create post meta from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function makePostMeta($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                $post_ID = $args[1];

                // Out with the old meta
                $old_meta = get_post_meta($post_ID, NULL);
                foreach($old_meta as $meta_key => $values) {
                        delete_post_meta($post_ID, $meta_key);
                }

                // In with the new meta
                $all_meta = $args[2];
                foreach($all_meta as $meta_key => $values) {
                        foreach($values as $value) {
                                $value = maybe_unserialize($this->translateURL($value));
                                add_post_meta($post_ID, $meta_key, $value);
                        }
                }

                return _('Saved meta for post #') . $post_ID;
        }

        /**
         * XMLRPC method to set post terms from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function makePostTerms($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                $post_ID = $args[1];
                $terms = $args[2];

                foreach($terms as $term) {
                        wp_set_object_terms($post_ID, $term['slug'], $term['taxonomy']);
                }

                return _('Saved terms for post #') . $post_ID;
        }

        /**
         * XMLRPC method to make a user from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function makeUser($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                global $wpdb;

                $user = $args[1];
                $userdata = $user['data'];
                $user_ID = $userdata['ID'];

                // Make a new user
                $userdata['ID'] = NULL;
                $new_ID = wp_insert_user($userdata, true);

                // Update to the given ID
                $wpdb->update($wpdb->users, array('ID' => $user_ID), array('ID' => $new_ID));
                $wpdb->update($wpdb->usermeta, array('user_id' => $user_ID), array('user_id' => $new_ID));

                // Make sure the password is the same
                $wpdb->update($wpdb->users, array('user_pass' => $userdata['user_pass']), array('ID' => $user_ID));

                // Out with the old meta
                $old_meta = get_user_meta($user_ID, NULL);
                foreach($old_meta as $meta_key => $values) {
                        delete_user_meta($user_ID, $meta_key);
                }

                // In with the new meta
                $all_meta = $args[2];
                foreach($all_meta as $meta_key => $values) {
                        foreach($values as $value) {
                                $value = maybe_unserialize($this->translateURL($value));
                                add_user_meta($user_ID, $meta_key, $value);
                        }
                }

                return _('Saved user #') . $user_ID;
        }

        /**
         * XMLRPC method to make a comment from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function makeComment($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                $commentdata = $args[1];
                $comment_ID = wp_insert_comment($commentdata);
                return _('Saved comment #') . $comment_ID;
        }

        /**
         * XMLRPC method to make a taxonomy from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function makeTaxonomy($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                $taxonomy = $args[1];
                register_taxonomy($taxonomy['name'], $taxonomy['object_type'], $taxonomy);

                $terms = $args[2];
                foreach($terms as $term) {
                        wp_insert_term($term['name'], $taxonomy['name'], $term);
                }

                return _('Saved taxonomy: ') . $taxonomy['name'];
        }

        /**
         * XMLRPC method to make site links from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function makeLinks($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                $links = $args[1];

                foreach($links as $link) {
                        $link['link_id'] = NULL;
                        wp_insert_link($link);
                }

                return _('Saved links');
        }

        /**
         * XMLRPC method to update site options from the passed in data.
         *
         * @param array $args Method parameters
         * @return string
         */
        function updateOptions($args) {
                $secret = $args[0];
                if(!$this->checkSecret($secret)) return $this->error;

                $options = $args[1];
                foreach($options as $name => $value) {
                        $value = maybe_unserialize($this->translateURL($value));
                        update_option($name, $value);
                }

                return _('Copied site options');
        }

        /**
         * Add a Migrate link to the Tools menu.
         */
        function adminMenu() {
                $page = add_management_page(__('Migrate', 'migrate'), __('Migrate', 'migrate'), 8, 'wp-migration', array(&$this, 'adminPage'));
                add_action('admin_print_scripts-' . $page, array(&$this, 'adminPrintScripts'));
                add_filter('filesystem_method', array(&$this, 'filesystemMethod'));
        }

        /**
         * Print the AJAX migration script only on the final migration page.
         */
        function adminPrintScripts() {
                wp_enqueue_script('migration-ajax', plugins_url('migration-ajax.js', __FILE__), array('jquery'));
        }

        /**
         * Use the FTP credentials to copy accross the provided files.
         *
         * @param array $files The files to copy to the remote server
         * @return array Counts of successes, exists and errors
         */
        function transferFiles($files) {
                global $wp_filesystem;

                $success_count = 0;
                $exists_count = 0;
                $error_count = 0;

                foreach($files as $fullpath => $file) {
                        if($wp_filesystem->exists($fullpath)) {
                                $exists_count++;
                                continue;
                        }

                        if($file['type'] == 'd') {
                                $wp_filesystem->mkdir($fullpath);
                                continue;
                        }

                        $dir = dirname($fullpath);
                        if(!$wp_filesystem->exists($dir)) {
                                $wp_filesystem->mkdir($dir);
                        }

                        $contents = file_get_contents(ABSPATH . $fullpath);
                        $success = $wp_filesystem->put_contents($fullpath, $contents);
                        if($success) {
                                $success_count++;
                        } else {
                                $error_count++;
                        }
                }

                return array(
                        'successes' => $success_count,
                        'exists' => $exists_count,
                        'errors' => $error_count,
                );
        }

        /**
         * Takes a tree of files and flattens them to a single array.
         *
         * @param array $files The file tree
         * @return array Flattened array of files
         */
        function flattenTree($files, $path = '') {
                $flattened = array();

                foreach($files as $file) {
                        $dir = trailingslashit($path);
                        if(empty($path)) {
                                $dir = '';
                        }
                        $fullpath = $dir . $file['name'];

                        // Only keep the info that we need
                        $flattened[$fullpath] = array(
                                'type' => $file['type'],
                                'size' => $file['size'],
                        );

                        if($file['type'] == 'd') {
                                $children = $this->flattenTree($file['files'], $fullpath);
                                foreach($children as $fullpath => $child) {
                                        $flattened[$fullpath] = $child;
                                }
                        }
                }

                if(empty($path)) {
                        // We don't want the wp-config.php file copied over!
                        unset($flattened['wp-config.php']);
                }

                return $flattened;
        }

        /**
         * Store an option with a flat list of all the local WP files.
         */
        function setupFiles() {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

                $direct = new WP_Filesystem_Direct(null);
                $tree = $direct->dirlist(ABSPATH, true, true);
                $files = $this->flattenTree($tree);
                update_option('migration_files', $files);
        }

        /*
         * AJAX method to transfer a chunk of files. Slices from the
         * "migration_files" option based upon file size.
         */
        function ajaxFiles() {
                global $wp_filesystem;
                require_once ABSPATH . 'wp-admin/includes/file.php';

                $path = get_option('migration_path');

                $credentials = get_option('migration_credentials');

                // Ensure the only allowed connection is a remote one.
                add_filter('filesystem_method', array(&$this, 'filesystemMethod'));
                $success = WP_Filesystem($credentials);

                // TODO: Make a better error message.
                if(empty($success)) die('-1');

                $wp_filesystem->chdir($path);

                $files = get_option('migration_files');
                if(empty($files)) {
                        global $table_prefix;

                        // Create a new wp-config.php
                        $new_wpconfig = file_get_contents(ABSPATH . 'wp-config.php');

                        // Redefines statements like:
                        // define('DB_HOST', 'localhost');
                        $config = get_option('migration_config');
                        $const_map = array(
                                'DB_NAME' => $config['dbname'],
                                'DB_USER' => $config['uname'],
                                'DB_PASSWORD' => $config['pwd'],
                                'DB_HOST' => $config['dbhost'],
                        );
                        foreach($const_map as $const => $replace) {
                                $pattern = '/
                                (define\s*\(\s*) # Constant definition
                                ([\'"])          # Quote type
                                (' . $const . ') # Constant name
                                \2               # Previous quote type
                                (\s*,\s*)        # A comma between the args
                                .*?              # Value
                                (\s*\))          # End parenthesis
                                /x';
                                $new_wpconfig = preg_replace($pattern, "\\1\\2\\3\\2\\4'" . addslashes($replace) . "'\\5", $new_wpconfig);
                        }

                        // Redefines the statement like:
                        // $table_prefix  = 'wp_';
                        $pattern = '/
                        (\$table_prefix) # Variable assignment
                        (\s*=\s*)        # An equal sign
                        (.*?)            # Value
                        (\s*;)           # Semicolon
                        /x';
                        $new_wpconfig = preg_replace($pattern, "\\1\\2'" . addslashes($table_prefix) . "'\\4", $new_wpconfig);

                        // Transfer the new wp-config.php
                        $wp_filesystem->put_contents('wp-config.php', $new_wpconfig);

                        // Lastly, transfer over the migration secret
                        $secret = get_option('migration_secret');
                        $wp_filesystem->put_contents('.migration_secret', $secret);

                        die('-1');
                }

                // Keep selecting files to transfer below a threshold
                $max_kbytes = 175;

                $top_files = array();
                $top_files_kbytes = 0;
                foreach($files as $fullpath => $file) {
                        $file_kbytes = $file['size'] / 1024;

                        if(count($top_files) > 1 && ($top_files_kbytes + $file_kbytes) > $max_kbytes) break;

                        $top_files_kbytes += $file_kbytes;
                        $top_files[$fullpath] = $file;
                }
                array_splice($files, 0, count($top_files));

                $info = $this->transferFiles($top_files);

                // We've transferred the top 10 files, remove them from the options
                update_option('migration_files', $files);

                $message = sprintf(__('Transferred %d files. %d files already existed. %d errors.'), $info['successes'], $info['exists'], $info['errors']);
                $json_message = json_encode($message);
                die($json_message);
        }
        
        /*
         * AJAX method to initiate the new installation.
         */
        function ajaxInit() {
                $location = untrailingslashit(get_option('migration_url')) . '/wp-content/plugins/wp-migration/init.php';
                $secret = get_option('migration_secret');

                $url = $location . '?secret=' . urlencode($secret);
                $headers = get_headers($url);
                header($headers[0]);
                die();
        }

        /*
         * AJAX method to transfer the WP data (posts, users, comments, etc).
         */
        function ajaxData() {
                include_once(ABSPATH . WPINC . '/class-IXR.php');

                $location = untrailingslashit(get_option('migration_url')) . '/xmlrpc.php';
                $this->client = new IXR_Client($location);

                $step = get_option('migration_step', 0);
                switch($step) {
                case 0:
                        $this->sendSetup();
                        break;
                case 1:
                        $this->sendTaxonomies();
                        break;
                case 2:
                        $this->sendPosts();
                        break;
                case 3:
                        $this->sendUsers();
                        break;
                case 4:
                        $this->sendComments();
                        break;
                case 5:
                        $this->sendLinks();
                        break;
                case 6:
                        $this->sendOptions();
                        break;
                case 7:
                        $this->sendFinish();
                        break;
                default:
                        delete_option('migration_step');
                        die('-1');
                        break;
                }

                $step++;
                update_option('migration_step', $step);

                $json_response = json_encode($this->responses);
                die($json_response);
        }

        /*
         * Helper function that appends the last XMLRPC message to an array of
         * responses that get sent back the the transfer screen.
         */
        function respond() {
                if($this->client->isError()) {
                        $this->responses[] = $this->client->getErrorMessage();
                } else {
                        $this->responses[] = $this->client->getResponse();
                }
        }

        /*
         * Sends all posts to the new blog one by one.
         */
        function sendPosts() {
                $secret = get_option('migration_secret');

                $taxonomies = get_taxonomies();
                $query = new WP_Query(array(
                        'post_type' => 'any',
                        'post_status' => 'any',
                        'posts_per_page' => -1,
                ));
                foreach($query->posts as $post) {
                        $this->client->query('migration.makePost', $secret, $post);
                        $this->respond();

                        $all_meta = get_post_meta($post->ID, NULL);
                        $this->client->query('migration.makePostMeta', $secret, $post->ID, $all_meta);
                        $this->respond();

                        $terms = wp_get_object_terms($post->ID, $taxonomies);
                        if(!empty($terms)) {
                                $this->client->query('migration.makePostTerms', $secret, $post->ID, $terms);
                                $this->respond();
                        }
                }
        }

        /*
         * Sends all users to the new blog one by one.
         */
        function sendUsers() {
                $secret = get_option('migration_secret');

                $query = new WP_User_Search();
                foreach($query->get_results() as $userid) {
                        $user = new WP_User($userid);
                        $all_meta = get_user_meta($userid, NULL);

                        $this->client->query('migration.makeUser', $secret, $user, $all_meta);
                        $this->respond();
                }
        }

        /*
         * Sends all comments to the new blog one by one.
         */
        function sendComments() {
                $secret = get_option('migration_secret');

                $comments = get_comments();
                foreach($comments as $comment) {
                        $this->client->query('migration.makeComment', $secret, $comment);
                        $this->respond();
                }
        }

        /*
         * Sends all taxonomies to the new blog one by one.
         */
        function sendTaxonomies() {
                $secret = get_option('migration_secret');

                $taxonomies = get_taxonomies();
                foreach($taxonomies as $taxonomy_name) {
                        $taxonomy = get_taxonomy($taxonomy_name);
                        $terms = get_terms($taxonomy_name, array('hide_empty' => false));

                        $this->client->query('migration.makeTaxonomy', $secret, $taxonomy, $terms);
                        $this->respond();
                }
        }

        /*
         * Sends all links to the new blog.
         */
        function sendLinks() {
                $secret = get_option('migration_secret');

                $links = get_bookmarks();

                $this->client->query('migration.makeLinks', $secret, $links);
                $this->respond();
        }

        /*
         * Sends all options to the new blog.
         */
        function sendOptions() {
                $secret = get_option('migration_secret');

                $alloptions = wp_load_alloptions();

                $this->client->query('migration.updateOptions', $secret, $alloptions);
                $this->respond();
        }

        /*
         * Sends migration settings to the new blog.
         */
        function sendSetup() {
                $secret = get_option('migration_secret');

                $options = array(
                        'siteurl' => get_option('siteurl'),
                );
                $this->client->query('migration.setup', $secret, $options);
                $this->respond();
        }

        /*
         * Sends finish message to the new blog. Allows migration clean up.
         */
        function sendFinish() {
                $secret = get_option('migration_secret');

                $this->client->query('migration.finish', $secret);
                $this->respond();
        }

        /*
         * Ask the user for and gets remote FTP path.
         *
         * @returns string Remote FTP path for installation
         */
        function requestPath() {
                global $wp_filesystem;

                $credentials = get_option('migration_credentials');
                WP_Filesystem($credentials);

                $base = '/';
                if(isset($_POST['base']) && isset($_POST['dir'])) {
                        if($_POST['base'] == $base) {
                                $_POST['base'] = '';
                        }

                        $base = $_POST['base'] . '/' . $_POST['dir'];
                }

                if(isset($_POST['path'])) {
                        return $_POST['base'];
                }

                $url = add_query_arg(array('step' => 'setup'));
?>
<form action="<?php echo esc_attr($url); ?>" method="post">
  <p>Current dir is <?php echo esc_attr($base); ?> <input type="submit" name="path" value="Use this path" /></p>
  <input type="hidden" name="base" value="<?php echo esc_attr($base); ?>" />
  <ul>
<?php
                $list = $wp_filesystem->dirlist($base);
                if(!empty($list)) {
                        foreach($list as $file) {
                                if($file['isdir']) {
?>
    <li><input type="submit" name="dir" value="<?php echo esc_attr($file['name']); ?>" /></li>
<?php
                                }
                        }
                }
?>
  </ul>
</form>
<?php
        }

        /*
         * Ask the user for and gets new site config.
         *
         * @returns array New site config (new URL, new database)
         */
        function requestConfig() {
                if(isset($_POST['url']) && isset($_POST['dbname'])) {
                        return array(
                                'url' => $_POST['url'],
                                'dbname' => stripslashes($_POST['dbname']),
                                'uname' => stripslashes($_POST['uname']),
                                'pwd' => stripslashes($_POST['pwd']),
                                'dbhost' => stripslashes($_POST['dbhost']),
                                'prefix' => stripslashes($_POST['prefix']),
                        );
                }

                $wpconfig = file_get_contents(ABSPATH . 'wp-config.php');
                $url = add_query_arg(array('step' => 'migrate'));

                $siteurl = get_option('siteurl');
                $path = get_option('migration_path');
?>
<form action="<?php echo esc_attr($url); ?>" method="post">
  <h3>New settings</h3>
  <table class="form-table">
    <tr>
      <th><label for="url">URL</label></th>
      <td>
        <input type="text" id="url" name="url" size="35" value="" />
        <p class="description">This is the public URL that <code><?php echo esc_attr($path); ?></code> can be accessed from.</p>
      </td>
    </tr>
    <tr>
      <th><label for="dbname">Database Name</label></th>
      <td>
        <input name="dbname" id="dbname" type="text" size="25" value="" />
        <p class="description">The name of the database you want to run WP in.</p>
      </td>
    </tr>
    <tr>
      <th><label for="uname">User Name</label></th>
      <td>
        <input name="uname" id="uname" type="text" size="25" value="" />
        <p class="description">The name of the database you want to run WP in.</p>
      </td>
    </tr>
    <tr>
      <th><label for="pwd">Password</label></th>
      <td>
        <input name="pwd" id="pwd" type="text" size="25" value="" />
        <p class="description">...and MySQL password.</p>
      </td>
    </tr>
    <tr>
      <th><label for="dbhost">Database Host</label></th>
      <td>
        <input name="dbhost" id="dbhost" type="text" size="25" value="localhost" />
        <p class="description">You should be able to get this info from your web host, if <code>localhost</code> does not work.</p>
      </td>
    </tr>
    <tr>
      <th><label for="prefix">Table Prefix</label></th>
      <td>
        <input name="prefix" id="prefix" type="text" id="prefix" value="wp_" size="25" />
        <p class="description">If you want to run multiple WordPress installations in a single database, change this.</p>
      </td>
    </tr>
  </table>
  <p>
  </p>
  <p class="submit">
    <input class="button-primary" type="submit" value="Migrate" />
  </p>
</form>
<?php
        }

        /*
         * Ask the user for and gets remote FTP credentials.
         *
         * @returns array Remote FTP credentials
         */
        function getCredentials($use_option = true) {
                $url = add_query_arg(array('step' => 'setup'));

                $credentials = get_option('migration_credentials');
                if(empty($credentials)) $credentials = request_filesystem_credentials($url);
                if(empty($credentials)) return false;
                if(!WP_Filesystem($credentials)) return request_filesystem_credentials($url, '', true);

                update_option('migration_credentials', $credentials);
                return true;
        }

        /*
         * Ask the user for and gets remote filesystem path.
         *
         * @returns string Remote path
         */
        function getPath() {
                $path = get_option('migration_path');
                if(empty($path)) $path = $this->requestPath();
                if(empty($path)) return false;

                update_option('migration_path', $path);
                return true;
        }

        /*
         * Asks the user for remote URL and wp-config.php data
         *
         * @returns bool True if the config is given
         */
        function getConfig() {
                $config = $this->requestConfig();
                if(empty($config)) return false;

                $url = $config['url'];
                if(strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
                        $url = 'http://' . $url;
                }
                update_option('migration_url', $url);
                unset($config['url']);
                update_option('migration_config', $config);

                // Generate a secret and save it on the remote filesystem.
                // Require the secret for subsequent requests.
                $secret = get_option('migration_secret', wp_generate_password(16, false));
                update_option('migration_secret', $secret);

                return true;
        }

        /*
         * Clears all of the migration options.
         */
        function deleteOptions() {
                delete_option('migration_credentials');
                delete_option('migration_config');
                delete_option('migration_path');
                delete_option('migration_secret');
        }

        /*
         * Shows the migration responses page.
         */
        function migrate() {
                $this->setupFiles();
?>
<div class="wrap">
  <?php screen_icon(); ?>
  <h2><?php _e( 'Migrate Installation' ); ?></h2>

  <div id="message" class="js-hide error"><p><?php _e( 'JavaScript is required for migration.' ); ?></p></div>

  <div class="js-show" style="display: none">
    <h3>Responses</h3>
    <ul id="server-response-list"></ul>
    <div id="server-response-loading" class="loading">Loading...</div>
  </div>
</div>
<?php
        }

        /*
         * Handles the migration admin pages.
         */
        function adminPage() {
                $setup = 'default';
                if(isset($_GET['step'])) {
                        $step = $_GET['step'];
                }

                switch($step) {
                case 'setup':
                        if($this->getCredentials()) {
                                if($this->getPath()) {
                                        $this->getConfig();
                                }
                        }
                        break;
                case 'migrate':
                        if($this->getConfig()) {
                                $this->migrate();
                        }
                        break;
                default:
                        $this->deleteOptions();
                        $this->getCredentials();
                        break;
                }
        }
};

new WP_Migration();
?>