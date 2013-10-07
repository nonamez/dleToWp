<?php
/*
Plugin Name: DLE to WP
Description: This module provides the ability to transfer users, posts, comments and etc. from "DataLife Engine" to "Wordpress". 
Version: 0.1 (Beta)
Author: Kiril Calkin 
Email: nonamez123[doggyStyle]gmail{dot}com
Author URI: http://nonamez.name
License: Beerware
*/

/*
In future:
	* files
	* other versions
	* errors
	* optimize BBtoHTML function
*/

define('DATALIFEENGINE', true);

register_activation_hook(__FILE__, array('dleToWp', 'activation'));
register_deactivation_hook(__FILE__, array('dleToWp', 'deactivation'));

new dleToWp;

class dleToWp {

	private $wpdb;
	private $logs;

	function __construct()
	{
		if (is_admin()) {
			Global $wpdb;

			$this->wpdb = $wpdb;

			$log_path = dirname(__FILE__) . '/logs';

			$this->logs = array(
				'users' => $log_path . '/users.txt',
				'categories' => $log_path . '/categories.txt',
				'posts' => $log_path . '/posts.txt',
				'posts_names' => $log_path . '/posts_names.txt',
				'comments' => $log_path . '/comments.txt'
			);

			add_action('wp_ajax_my_action', array($this, 'ajax_respone'));
			add_action('admin_menu', array($this, 'admin_menu'));
			add_action('admin_init', array($this, 'plugin_admin_init'));
			add_action('plugins_loaded', array($this, 'load_language'));
			add_action('admin_head', array($this, 'add_to_header'));
		}
	}

	public static function activation()
	{
		if (is_admin()) {
			add_option(__CLASS__ . '_users_config', array('progress' => 'notstarted', 'limit' => 0));
			add_option(__CLASS__ . '_categories_config');
			add_option(__CLASS__ . '_posts_config', array('progress' => 'notstarted', 'limit' => 0));
			add_option(__CLASS__ . '_comments_config', array('progress' => 'notstarted', 'limit' => 0));
			add_option(__CLASS__ . '_upload_images', 0);
			add_option(__CLASS__ . '_resize_images', 0);
			add_option(__CLASS__ . '_split_news', 1);
			add_option(__CLASS__ . '_bb_parser', 0);
		}
	}

	public static function deactivation()
	{
		if (is_admin()) {
			delete_option(__CLASS__ . '_users_config');
			delete_option(__CLASS__ . '_categories_config');
			delete_option(__CLASS__ . '_posts_config');
			delete_option(__CLASS__ . '_comments_config');
			delete_option(__CLASS__ . '_database_name');
			delete_option(__CLASS__ . '_database_prefix');
			delete_option(__CLASS__ . '_upload_images');
			delete_option(__CLASS__ . '_resize_images');
			delete_option(__CLASS__ . '_split_news');
			delete_option(__CLASS__ . '_bb_parser');
		}
	}

	public function admin_menu()
	{
		add_management_page(__('Database converter', 'dleToWp'), __('Database converter', 'dleToWp'), 'manage_options', 'database_convert', array($this, 'settings_page'));
	}

	/**
	* Remove standard image sizes so that these sizes are not created during the Media Upload process
	*
	* Tested with WP 3.2.1
	*
	* Hooked to intermediate_image_sizes_advanced filter
	* See wp_generate_attachment_metadata( $attachment_id, $file ) in wp-admin/includes/image.php
	*
	* @param $sizes, array of default and added image sizes
	* @return $sizes, modified array of image sizes
	* @author Ade Walker http://www.studiograsshopper.ch
	*/

	public function sgr_filter_image_sizes($sizes)
	{
		unset($sizes['thumbnail']);
		unset($sizes['medium']);
		unset($sizes['large']);

		return $sizes;
	}

	public function plugin_admin_init()
	{

		register_setting(__CLASS__ . '_options', __CLASS__ . '_database_name', array($this, 'database_name_check'));
		register_setting(__CLASS__ . '_options', __CLASS__ . '_database_prefix', array($this, 'database_prefix_check'));
		register_setting(__CLASS__ . '_options', __CLASS__ . '_version');
		register_setting(__CLASS__ . '_options', __CLASS__ . '_upload_images');
		register_setting(__CLASS__ . '_options', __CLASS__ . '_resize_images');
		register_setting(__CLASS__ . '_options', __CLASS__ . '_split_news');
		register_setting(__CLASS__ . '_options', __CLASS__ . '_bb_parser');

		add_settings_section(__CLASS__.'_options', __('Settings', 'dleToWp'), NULL, 'dleToWp');

		add_settings_field(__CLASS__ . '_database_name', __('Database', 'dleToWp'), array($this, 'database_name_field'), 'dleToWp', __CLASS__ . '_options');
		add_settings_field(__CLASS__ . '_database_prefix', __('Database prefix', 'dleToWp'), array($this, 'database_prefix_field'), 'dleToWp', __CLASS__ . '_options');
		add_settings_field(__CLASS__ . '_version', __('Version', 'dleToWp'), array($this, 'version_field'), 'dleToWp', __CLASS__ . '_options');
		add_settings_field(__CLASS__ . '_upload_images', __('Upload images', 'dleToWp'), array($this, 'upload_images_field'), 'dleToWp', __CLASS__ . '_options');
		add_settings_field(__CLASS__ . '_resize_images', __('Resize images', 'dleToWp'), array($this, 'resize_images_field'), 'dleToWp', __CLASS__ . '_options');
		add_settings_field(__CLASS__ . '_bb_parser', __('Parse BB Codes', 'dleToWp'), array($this, 'bb_parser_field'), 'dleToWp', __CLASS__ . '_options');
		add_settings_field(__CLASS__ . '_split_news', __('Split news', 'dleToWp'), array($this, 'split_news_field'), 'dleToWp', __CLASS__ . '_options');
	}

	public function database_name_check($name)
	{
		if ($this->wpdb->get_var($this->wpdb->prepare('SHOW DATABASES LIKE %s', $name)) == $name)
			return $name;
		else {
			add_settings_error(__CLASS__ . '_database_name', __CLASS__ . '_database_name', __('Database not found.', 'dleToWp'), 'error');
			return FALSE;
		}
	}

	public function database_prefix_check($prefix)
	{
		$tables = $this->wpdb->get_col('SHOW TABLES IN `'.$this->wpdb->escape(get_option(__CLASS__ . '_database_name')) . '`');
		$important_tables = array($prefix . '_category', $prefix . '_comments', $prefix . '_email', $prefix . '_images', $prefix . '_post', $prefix . '_users');

		if (count(array_intersect($important_tables, $tables)) !== count($important_tables)) {
			add_settings_error(__CLASS__.'_database_name', __CLASS__.'_database_name', __('Required tables with the specified prefix is not found.', 'dleToWp'), 'error');
			return FALSE;
		}

		return $prefix;
	}

	public function database_name_field()
	{
		echo '<input type="text" name="'.__CLASS__.'_database_name" value="'.get_option(__CLASS__.'_database_name').'" />';
	}

	public function database_prefix_field()
	{
		echo '<input type="text" name="'.__CLASS__.'_database_prefix" value="'.get_option(__CLASS__.'_database_prefix').'" />';
	}

	public function version_field()
	{
		echo '8.5';
		echo '<p class="description">'.__('Currently tested on version 8.5, in development are the following versions. Wait for updates.', 'dleToWp').'</p>';
	}

	public function upload_images_field()
	{
		echo '<select name="'.__CLASS__.'_upload_images">';
		echo '<option value="1" '.selected(get_option(__CLASS__.'_upload_images'), 1).'>'.__('Yes').'</option>';
		echo '<option value="0" '.selected(get_option(__CLASS__.'_upload_images'), 0).'>'.__('No').'</option>';
		echo '</select>';
		echo '<p class="description">'.__('Uploads all images to Wordpress.', 'dleToWp').' '.__('<strong>Important: </strong>it will take more time.', 'dleToWp').'</p>';
	}

	public function resize_images_field()
	{
		echo '<select name="'.__CLASS__.'_resize_images">';
		echo '<option value="1" '.selected(get_option(__CLASS__.'_resize_images'), 1).'>'.__('Yes').'</option>';
		echo '<option value="0" '.selected(get_option(__CLASS__.'_resize_images'), 0).'>'.__('No').'</option>';
		echo '</select>';
		echo '<p class="description">'.__('Uploaded images will be resized by Wordpress.', 'dleToWp').' '.__('<strong>Important: </strong>it will take more time.', 'dleToWp').'</p>';
	}

	public function split_news_field()
	{
		echo '<select name="'.__CLASS__.'_split_news">';
		echo '<option value="1" '.selected(get_option(__CLASS__.'_split_news'), 1).'>'.__('Yes').'</option>';
		echo '<option value="0" '.selected(get_option(__CLASS__.'_split_news'), 0).'>'.__('No').'</option>';
		echo '</select>';
		echo '<p class="description">'.__('Splits each post. <strong>Important: </strong>it uses short post length and adds <em>&lt;!--more--&gt;</em> tag after it in full post', 'dleToWp').'</p>';
	}

	public function bb_parser_field()
	{
		echo '<select name="'.__CLASS__.'_bb_parser">';
		echo '<option value="1" '.selected(get_option(__CLASS__.'_bb_parser'), 1).'>'.__('DLE Parser', 'dleToWp').'</option>';
		echo '<option value="0" '.selected(get_option(__CLASS__.'_bb_parser'), 0).'>'.__('Simple Parser', 'dleToWp').'</option>';
		echo '</select>';
		echo '<p class="description">'.__('Parses BB codes to HTML entities.<br>
			<strong>Important: </strong><br>
			<span style="text-decoration: underline;">DLE Parser</span> method uses original DLE method, so i parses all codes, but leaves some extra stuff in HTML like <span style="text-decoration: underline;">&lt;!--TBegin--&gt</span> or <span style="text-decoration: underline;">&lt;!--dle_image_begin:http://.....</span><br>
			<span style="text-decoration: underline;">Simple Parser</span> method uses own small parser wich currently parses only <span style="text-decoration: underline;">b, i, u , s, quote, code, url, (left|center|right), font, size, color, PAGEBREAK</span> tags<br>
			', 'dleToWp').'</p>';
	}

	public function settings_page()
	{
		$parse_file = file_exists(dirname(__FILE__) . 'parse.class.php');

		if (!$parse_file)
			add_settings_error(__CLASS__ . 'parse_file', __CLASS__ . 'parse_file', __('You need to include parse class file from "DataLife Engine". Please copy "/engine/classes/parse.class.php" to this plugin directory.', 'dleToWp'), 'error');

		echo '<div class="wrap">';
		settings_errors();
		echo '<div class="icon32" id="icon-tools"></div>
			<h2>'.__('Database converter', 'dleToWp').'</h2>
			<div id="db_backup" class="updated"><p>'.__('Before starting transfer is highly recommended to do a full backup of the database in order to avoid any future problems. The author assumes no responsibility.', 'dleToWp').'</p></div>
			<form action="options.php" method="post">';
		settings_fields(__CLASS__.'_options');
		do_settings_sections('dleToWp');
		submit_button();
		echo '</form>';
		
		if ($this->check_values($parse_file)) {
			// <li>'.__('File transfer', 'dleToWp').'<span></span> <img src="images/wpspin_light.gif"></li>
			echo '
		<div class="updated" style="padding: 5px; width: 240px !important;">
			<input type="button" value="'.__('Start transfer', 'dleToWp').'" class="button" id="start_dleToWp">
			<input type="button" value="'.__('Stop transfer', 'dleToWp').'" class="button" id="stop_dleToWp">
			<ul id="dleToWp_transfer">
				<li id="user_transfer">'.__('Members transferred', 'dleToWp').': <span>0</span> <img src="images/wpspin_light.gif"></li>
				<li id="category_transfer">'.__('Category transfer', 'dleToWp').': <span>0</span> <img src="images/wpspin_light.gif"></li>
				<li id="post_transfer">'.__('News transfer', 'dleToWp').': <span>0</span> <img src="images/wpspin_light.gif"></li>
				<li id="comments_transfer">'.__('Comments transfer', 'dleToWp').': <span>0</span> <img src="images/wpspin_light.gif"></li>
			</ul>
		</div>';
		}
		echo '</div>';
	}

	public function load_language()
	{
		load_plugin_textdomain('dleToWp', FALSE, dirname(plugin_basename( __FILE__ )).'/languages/');
	}

	public function add_to_header()
	{
		echo '
		<style type="text/css">
			#dleToWp_transfer{
				margin-left: 20px;
				display: none;
			}
			#dleToWp_transfer li{
				display: none;
			}
			#dleToWp_transfer li img{
				vertical-align:-3px !important;
			}
			#db_backup{
				border-color: red;
			}
			#stop_dleToWp{
				display: none;
			}
		</style>';
		echo '<script type="text/javascript" src="'.plugin_dir_url(__FILE__).'js/js.js"></script>';
	}

	private function check_values($parse_file = FALSE)
	{
		return get_option(__CLASS__.'_database_name') && get_option(__CLASS__.'_database_prefix') && $parse_file;
	}

	public function ajax_respone()
	{
		if (method_exists($this, $_POST['method_name'])) {

			set_time_limit(0);

			$database = $this->wpdb->escape(get_option(__CLASS__.'_database_name'));
			$prefix = $this->wpdb->escape(get_option(__CLASS__.'_database_prefix'));

			$this->$_POST['method_name']($database, $prefix);
		}

		die();
	}

	public function user_transfer($database, $prefix){

		$old_users = $this->loadLog('users');
		$user_configuration = get_option(__CLASS__ . '_users_config');

		if ($user_configuration['progress'] === 'completed')
			$this->nextAction(__FUNCTION__, 'category_transfer', TRUE);

		$query = 'SELECT
					`'.$database.'`.`'.$prefix.'_users`.`user_id`,
					`'.$database.'`.`'.$prefix.'_users`.`name`,
					`'.$database.'`.`'.$prefix.'_users`.`password`,
					`'.$database.'`.`'.$prefix.'_users`.`email`,
					FROM_UNIXTIME(`'.$database.'`.`'.$prefix.'_users`.`reg_date`) AS reg_date,
					`'.$database.'`.`'.$prefix.'_users`.`name`,
					`'.$database.'`.`'.$prefix.'_users`.`fullname`
				FROM
					`'.$database.'`.`'.$prefix.'_users`
				WHERE
					`'.$database.'`.`'.$prefix.'_users`.`user_id` > \'1\'
				AND
					`'.$database.'`.`'.$prefix.'_users`.`banned` = \'\'
				ORDER BY
					`'.$database.'`.`'.$prefix.'_users`.`user_id`
				LIMIT '. $this->wpdb->escape($user_configuration['limit']).', 100;';

		$users = $this->wpdb->get_results($query);

		if (count($users) <= 0) {
			$user_configuration['progress'] = 'completed';
			update_option(__CLASS__ . '_users_config', $user_configuration);
			$this->nextAction(__FUNCTION__, 'category_transfer', TRUE);
		}

		foreach ($users as $user) {
			$user_info = array(
				'user_login' => $user->name,
				'user_pass' => $user->password,
				'user_email' => $user->email,
				'user_registered' => $user->reg_date,
				'role' => 'Contributor'
				);

			$new_user = wp_insert_user($user_info);

			if (is_numeric($new_user))
				$old_users[$user->user_id] = $new_user;
			else
				$error = $new_user;
		}

		$user_configuration['limit'] = $user_configuration['limit'] + 100;
		$user_configuration['progress'] = 'in_progress';

		update_option(__CLASS__ . '_users_config', $user_configuration);
		$this->saveLog('users', $old_users);

		$this->nextAction(__FUNCTION__, __FUNCTION__, FALSE, count($old_users));
	}

	public function category_transfer($database, $prefix)
	{
		$category_configuration = get_option(__CLASS__ . '_categories_config');

		if ($category_configuration === 'completed')
			$this->nextAction(__FUNCTION__, 'post_transfer', TRUE);

		$query = 'SELECT
					`'.$database.'`.`'.$prefix.'_category`.`id`,
					`'.$database.'`.`'.$prefix.'_category`.`name`
				FROM
					`'.$database.'`.`'.$prefix.'_category`
				ORDER BY
					`'.$database.'`.`'.$prefix.'_category`.`id`';

		$categories = $this->wpdb->get_results($query);

		$new_categories = array();

		for ($i = 0, $len = count($categories); $i < $len; $i++)
			$new_categories[$categories[$i]->id] = wp_create_category($categories[$i]->name);

		$this->saveLog('categories', $new_categories);
		update_option(__CLASS__ . '_categories_config', 'completed');

		$this->nextAction(__FUNCTION__, 'post_transfer', TRUE, count($new_categories));
	}

	public function post_transfer($database, $prefix)
	{
		$news_configuration = get_option(__CLASS__ . '_posts_config');

		if ((bool) get_option(__CLASS__.'_resize_images') == FALSE)
			add_filter('intermediate_image_sizes_advanced', array($this, 'sgr_filter_image_sizes'));

		if ($news_configuration['progress'] === 'completed')
			$this->nextAction(__FUNCTION__, 'comments_transfer', TRUE);

		require_once 'parse.class.php';
		
		$parse = new ParseFilter(array(), array(), 1, 1);

		$old_posts = $this->loadLog('posts');
		$old_posts_names = $this->loadLog('posts_names');
		$old_users = $this->loadLog('users');
		$old_categories = $this->loadLog('categories');

		$upload_images = (bool) get_option(__CLASS__ . '_upload_images');
		$split_news = (bool) get_option(__CLASS__ . '_split_news');
		$bb_parse = (bool) get_option(__CLASS__ . '_bb_parser');

		$query = 'SELECT
					`'.$database.'`.`'.$prefix.'_post`.`id` AS `post_id`,
					`'.$database.'`.`'.$prefix.'_post`.`category`,
					`'.$database.'`.`'.$prefix.'_post`.`title`,
					`'.$database.'`.`'.$prefix.'_post`.`full_story` AS post,
					`'.$database.'`.`'.$prefix.'_post`.`short_story` AS short_post,
					`'.$database.'`.`'.$prefix.'_post`.`tags`,
					`'.$database.'`.`'.$prefix.'_users`.`user_id`,
					`'.$database.'`.`'.$prefix.'_post`.`date`,
					`'.$database.'`.`'.$prefix.'_post`.`allow_comm`,
					`'.$database.'`.`'.$prefix.'_post`.`alt_name`
				FROM
					`'.$database.'`.`'.$prefix.'_post`
				JOIN `'.$database.'`.`'.$prefix.'_users` ON `'.$database.'`.`'.$prefix.'_users`.`name` = `'.$database.'`.`'.$prefix.'_post`.`autor`
				ORDER BY `'.$database.'`.`'.$prefix.'_post`.`date`
				LIMIT '.$news_configuration['limit'].', 5;';

		$posts = $this->wpdb->get_results($query);

		if (count($posts) <= 0) {
			$news_configuration['progress'] = 'completed';
			update_option(__CLASS__ . '_posts_config', $news_configuration);
			$this->nextAction(__FUNCTION__, 'comments_transfer', TRUE);
		}

		foreach ($posts as $value) {
			if ($split_news) {
				// In DLE short news may differ from the full, so surely script can not determine where to put the break.
				// Because of this script suppose that the short news still be a part of the full like in Wordpress.

				$post_break_ofset = strlen($value->short_post);

				if ($value->short_post != $value->post) { 
					// Start to search for the first </div> after the short news lenght ofset
					preg_match('/<\/div?>/', $value->post, $matches, PREG_OFFSET_CAPTURE, $post_break_ofset);
					// Now summ the offset of the </div> and it's length to insert "<!--more-->" tag
					$post_break_ofset = is_array($matches[0]) ? intval($matches[0][1] + strlen($matches[0][0])) : $post_break_ofset;

					// $value->post = substr($value->post, 0, $post_break_ofset) . "\n<!--more-->\n" . substr($value->post, $post_break_ofset);
					$value->post = substr_replace($value->post, "\n<!--more-->", $post_break_ofset, 0);
				} else
					$value->post .= "\n<!--more-->";
			}

			// Parsing post via DataLife Engine class (Need to be included)
			$value->title = @$parse->decodeBBCodes($value->title, FALSE);
			$value->post = @$parse->decodeBBCodes($value->post, FALSE);

			if ($upload_images) {

				// Getting image urls from the post
				// old '/.*?(http.+?)\[\/.*+/'
				preg_match_all('@\[(img|thumb)\](.+?)\[/\\1]@', $value->post, $matches);

				$image_urls = $matches[2];
				$image_ids = array();
				$image_count = count($image_urls);

				// Test for bad files
				// $image_urls[2] = 'https://dl.dropbox.com/u/14396564/screens/parse.class.php';

				// Uploading each image as a new one in WP
				for ($i=0; $i < $image_count; $i++) { 
					// Downloading image in to the local folder
					$tmp = download_url($image_urls[$i]);

					$file['name'] = basename($image_urls[$i]);
					$file['tmp_name'] = $tmp;

					// Unlinking file if download error
					if (is_wp_error($tmp)) {
						@unlink($file['tmp_name']);
						$image_urls[$i] = NULL;
					} else {
						// Uploading to WP and saving image ID for future
						$image_ids[$i] = media_handle_sideload($file, 0, $this->wpdb->escape($value->title));
						// Unlinking file if upload error
						if (is_wp_error($image_ids[$i])){
							@unlink($file_array['tmp_name']);
							// Setting NULL instead of bad image
							$image_urls[$i] = NULL;
						} else {
							// Replacing the old link to the new
							$image_urls[$i] = wp_get_attachment_url($image_ids[$i]);
						}
					}
				}

				// Inserting new image in to the post
				for ($i=0; $i < $image_count; $i++)
					$value->post = preg_replace('@\[(img|thumb)\](.+?)\[/\\1]@', (is_null($image_urls[$i]) ? NULL : '<img src="'.$image_urls[$i].'" alt="'.$value->title.'" />'), $value->post, 1);
			}

			if ($bb_parse)
				$value->post = @$parse->BB_Parse($value->post);
			else
				$value->post = $this->BBtoHTML($value->post);

			// Creating post (Beta)

			$new_post = array(
				'post_type' => 'post',
				'post_status' => 'publish',
				'comment_status' => (is_numeric($value->allow_comm) && $value->allow_comm == 1 ? 'open' : 'closed'),
				'post_author' => (isset($old_users[$value->user_id]) ? $old_users[$value->user_id] : 1),
				'post_category' => array((isset($old_categories[$value->category]) ? $old_categories[$value->category] : 1)),
				'tags_input' => $value->tags,
				'post_title' => $value->title,
				'post_content' => html_entity_decode($value->post),
				'post_date' => $value->date
			);

			$new_post_id = wp_insert_post($new_post);

			$name = preg_replace('/[^a-zA-Z0-9-]+/i', '', $value->alt_name);

			$old_posts[$value->post_id] = $new_post_id;
			$old_posts_names[$name] = $new_post_id;

			// Assign each image to the post
			if ($upload_images)
				foreach ($image_ids as  $image_id)
					wp_update_post(array('ID' => $image_id, 'post_parent' => $new_post_id));
		}

		$news_configuration['limit'] = $news_configuration['limit'] + 5;
		$news_configuration['progress'] = 'in_progress';

		update_option(__CLASS__ . '_posts_config', $news_configuration);
		$this->saveLog('posts', $old_posts);
		$this->saveLog('posts_names', $old_posts_names);

		$this->nextAction(__FUNCTION__, __FUNCTION__, FALSE, count($old_posts));
	}

	public function comments_transfer($database, $prefix)
	{
		$comments_configuration = get_option(__CLASS__ . '_comments_config');

		if ($comments_configuration['progress'] === 'completed')
			$this->nextAction(__FUNCTION__, __FUNCTION__, TRUE, FALSE, TRUE);

		$old_posts = $this->loadLog('posts');
		$old_users = $this->loadLog('users');
		$old_comments = $this->loadLog('comments');

		$query = 'SELECT
						`'.$database.'`.`'.$prefix.'_comments`.`id`,
						`'.$database.'`.`'.$prefix.'_comments`.`post_id`,
						`'.$database.'`.`'.$prefix.'_comments`.`user_id`,
						`'.$database.'`.`'.$prefix.'_comments`.`text`,
						`'.$database.'`.`'.$prefix.'_comments`.`autor`,
						`'.$database.'`.`'.$prefix.'_comments`.`email`,
						`'.$database.'`.`'.$prefix.'_comments`.`date`
					FROM
						`'.$database.'`.`'.$prefix.'_comments`
					LIMIT '.$comments_configuration['limit'].', 100;';

		$comments = $this->wpdb->get_results($query);

		if (count($comments) <= 0) {
			$comments_configuration['progress'] = 'completed';
			update_option(__CLASS__.'_comments_config', $comments_configuration);
			$this->nextAction(__FUNCTION__, __FUNCTION__, TRUE, FALSE, TRUE);
		}

		foreach ($comments as $comment) {
			if (isset($old_posts[$comment->post_id])) {
				$new_comment = array(
					'comment_post_ID' => $old_posts[$comment->post_id],
					'comment_author' => $comment->autor,
					'comment_author_email' => $comment->email,
					'comment_content' => $comment->text,
					'user_id' => (isset($old_users[$comment->user_id]) ? $old_users[$comment->user_id] : 0),
					'comment_date' => $comment->date,
					'comment_approved' => 1,
				);

				$new_comment_id = wp_insert_comment($new_comment);
				$old_comments[$comment->id] = $new_comment_id;
			}
		}

		$comments_configuration['limit'] = $comments_configuration['limit'] + 100;
		$comments_configuration['progress'] = 'in_progress';

		update_option(__CLASS__.'_comments_config', $comments_configuration);
		$this->saveLog('comments', $old_comments);

		$this->nextAction(__FUNCTION__, __FUNCTION__, FALSE, count($old_comments));
	}

	private function nextAction($current_method, $next_method, $hide_image_method = FALSE, $count = FALSE, $stop = FALSE)
	{
		die(
			json_encode(
				array(
					'current_method' => $current_method, 
					'next_method' => $next_method,
					'hide_image' => $hide_image_method,
					'count' => $count, 
					'stop' => $stop
				)
			)
		);
	}

	private function BBtoHTML($input_string)
	{
		$search = array(
			'/\[b\](.*?)\[\/b\]/is',
			'/\[i\](.*?)\[\/i\]/is',
			'/\[u\](.*?)\[\/u\]/is',
			'/\[s\](.*?)\[\/s\]/is',
			'/\[quote\](.*?)\[\/quote\]/is',
			'/\[code\](.*?)\[\/code\]/is',
			'/\[url\=(.*?)\](.*?)\[\/url\]/is',
			'/\[(left|center|right)\](.*?)\[\/(left|center|right)\]/is',
			'/\[font\=(.*?)\](.*?)\[\/font\]/is',
			'/\[size\=(.*?)\](.*?)\[\/size\]/is',
			'/\[color\=(.*?)\](.*?)\[\/color\]/is',
			'/\{PAGEBREAK\}/',
			'@\[(img|thumb)\](.+?)\[/\\1]@'
		); 

		$replace = array(
			'<strong>$1</strong>',
			'<em>$1</em>',
			'<span style="text-decoration: underline;">$1</span>',
			'<del>$1</del>',
			'<blockquote>$1</blockquote>',
			'<code>$1</code>',
			'<a href="$1" target="_blank">$2</a>',
			'<div style="text-align: $1;">$2</div>',
			'<span style="font-family: $1;">$2</span>',
			'<span style="font-size: $1;">$2</span>',
			'<span style="color: $1;">$2</span>',
			'<!--nextpage-->',
			'<img src="$2">'
		);

		return preg_replace($search, $replace, $input_string);
	}

	private function saveLog($log_id, $log_data)
	{
		return @file_put_contents($this->logs[$log_id], json_encode($log_data));
	}

	private function loadLog($log_id)
	{
		$logs = @json_decode(file_get_contents($this->logs[$log_id]), TRUE);

		return is_array($logs) ? $logs : array();
	}
}
?>