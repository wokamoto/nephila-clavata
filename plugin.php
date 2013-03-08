<?php
/*
Plugin Name: Nephila clavata
Version: 0.1.4
Plugin URI: https://github.com/wokamoto/nephila-clavata
Description: Media uploader for AWS S3.Allows you to mirror your WordPress media uploads over to Amazon S3 for storage and delivery. 
Author: wokamoto
Author URI: http://dogmap.jp/
Text Domain: nephila-clavata
Domain Path: /languages/

License:
 Released under the GPL license
  http://www.gnu.org/copyleft/gpl.html
  Copyright 2013 wokamoto (email : wokamoto1973@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
if ( !class_exists('S3_helper') )
        require_once(dirname(__FILE__).'/includes/class-s3-helper.php');
if ( !class_exists('NephilaClavataAdmin') )
	require_once(dirname(__FILE__).'/includes/class-admin-menu.php');

load_plugin_textdomain(NephilaClavata::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages/');

class NephilaClavata {
	const META_KEY    = '_s3_media_files';
	const DEBUG_MODE  = false;
	const TEXT_DOMAIN = 'nephila-clavata';
	const LIMIT       = 100;

	private $s3;                // S3 Object
	private $options = array(); // this plugin options

	function __construct(){
		$this->options = NephilaClavataAdmin::get_option();

		// replace url
		add_filter('the_content', array(&$this, 'the_content'));
		add_filter('widget_text', array(&$this, 'widget_text'));
		add_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);

		// delete S3 object
		add_action('delete_attachment', array(&$this, 'delete_attachment'));
	}

	static public function plugin_basename() {
		return plugin_basename(__FILE__);
	}

	// the_content filter hook
	public function the_content($content){
		$post_id = intval(get_the_ID());

		remove_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
		$content = $this->replace_s3_url($content, $post_id);
		add_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);

		return $content;
	}

	// widget_text filter hook
	public function widget_text($content){
		remove_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
		$content = $this->replace_s3_url($content, false);
		add_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);

		return $content;
	}

	// wp_get_attachment_url filter hook
	public function get_attachment_url($url, $post_id = null) {
		static $urls = array();

		if (!isset($post_id))
			$post_id = get_the_ID();
		$post_id = intval($post_id);
		if (isset($urls[$post_id]))
			return $urls[$post_id];

		remove_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
		$url = $this->replace_s3_url($url, $post_id);
		add_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);

		$urls[$post_id] = $url;
		return $url;
	}

	// delete_attachment action hook
	public function delete_attachment($post_id){
		$post = get_post($post_id);
		if ($post->post_type !== 'attachment')
			return;

		if ($all_S3_medias = get_post_meta($post_id, self::META_KEY, true)){
			foreach ($all_S3_medias as $S3_bucket => $S3_medias) {
				foreach ($S3_medias as $S3_media) {
					$this->s3_delete($S3_bucket, $S3_media['s3_key']);
				}
			}
		}
		unset($all_S3_medias);
	}

	// Replace URL
	private function replace_s3_url($content, $post_id){
		global $pagenow;

		if (empty($content))
			return $content;

		$s3_bucket = isset($this->options['bucket']) ? $this->options['bucket'] : false;
		$s3_url = isset($this->options['s3_url']) ? $this->options['s3_url'] : false;
		if (!$s3_bucket || !$s3_url)
			return $content;

		// get attachments
		$attachment_meta_key = self::META_KEY;
		$post_meta_key = self::META_KEY.'-replace';
		if ($post_id) {
			if ($replace_data = get_post_meta($post_id, $post_meta_key, true)) {
				$replace_data_org = isset($replace_data[$s3_bucket]) ? $replace_data[$s3_bucket] : array();
				foreach($replace_data_org as $search_url => $replace_url){
					$content = str_replace($search_url, $replace_url, $content);
				}
			}

			$attachments = $this->get_post_attachments($post_id, $content);
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($post_id);
				dbgx_trace_var($attachments);
			}
			if (!$attachments) {
				return $content;
			}
		} else {
			$attachments_count = 0;
			$medias = array();
			$attachments = $this->get_post_attachments_from_content($content, $attachments_count, $medias);
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($attachments);
			}
			if (!$attachments) {
				return $content;
			}
		}

		// object upload to S3, and replace url
		$replace_count = 0;
		$replace_data = array();
		$attachments_meta = array();
		foreach ($attachments as $attachment) {
			$S3_medias_new = $this->get_s3_media_info($attachment->ID);
			$S3_medias_old = get_post_meta($attachment->ID, $attachment_meta_key, true);
			if (is_array($S3_medias_old)) {
				if (isset($S3_medias_old[$s3_bucket]))
					$S3_medias_old = (array)$S3_medias_old[$s3_bucket];
			} else {
				$S3_medias_old = array();
			}
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($S3_medias_new);
				dbgx_trace_var($S3_medias_old);
			}

			if ($S3_medias_new !== $S3_medias_old) {
				$S3_medias = array();
				foreach($S3_medias_new as $size => $val ) {
					$replace_flag = true;
					$search_url  = $val['url'];
					$replace_url = $s3_url . $val['s3_key'];
					if (!isset($S3_medias_old[$size])) {
						$result = $this->s3_upload($val['file'], $s3_bucket, $val['s3_key']);
						$replace_flag = !$result ? false : true;
						if (self::DEBUG_MODE && function_exists('dbgx_trace_var'))
							dbgx_trace_var($result);
					}
					if ($replace_flag && $search_url && $replace_url) {
						$S3_medias[$size] = $val;
						if (!isset($replace_data[$search_url]) && (!is_admin() || $pagenow === 'upload.php')) {
							$replace_data[$search_url] = $replace_url;
							$content = str_replace($search_url, $replace_url, $content);
						}
					}
				}
				if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
					dbgx_trace_var($S3_medias);
				}
				if (count($S3_medias) > 0 && $S3_medias !== $S3_medias_old)
					update_post_meta($attachment->ID, $attachment_meta_key, array($s3_bucket => $S3_medias));
			} else if (!is_admin() || $pagenow === 'upload.php') {
				foreach($S3_medias_new as $size => $val ) {
					$search_url  = $val['url'];
					$replace_url = $s3_url . $val['s3_key'];
					if (!isset($replace_data[$search_url])) {
						$replace_data[$search_url] = $replace_url;
						$content = str_replace($search_url, $replace_url, $content);
					}
				}
			}
			$replace_count++;
		}

		if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
			dbgx_trace_var($replace_data);
			dbgx_trace_var($replace_data_org);
		}
		if ($post_id && ($replace_data !== $replace_data_org))
			update_post_meta($post_id, $post_meta_key, array($s3_bucket => $replace_data), true);
		unset($S3_medias_new);
		unset($S3_medias_old);
		unset($S3_medias);

		return $content;
	}

	// Initializing S3 object
	private function s3($S3_bucket = null){
		if (isset($this->s3)) {
			if (isset($S3_bucket) && $this->s3->current_bucket() !== $S3_bucket)
				$this->s3->set_current_bucket($S3_bucket);
			return $this->s3;
		}
		if ($this->options) {
			$s3 = new S3_helper(
				isset($this->options['access_key']) ? $this->options['access_key'] : null,
				isset($this->options['secret_key']) ? $this->options['secret_key'] : null,
				isset($this->options['region'])     ? $this->options['region']     : null
				);
			if ($s3 && isset($S3_bucket))
				$s3->set_current_bucket($S3_bucket);
			$this->s3 = $s3;
			return $s3;
		}
		return false;
	}

	// Upload file to S3
	private function s3_upload($filename, $S3_bucket, $S3_key){
		if (!file_exists($filename))
			return false;

		$upload_result = false;
		if ($s3 = $this->s3($S3_bucket)) {
			if ($s3->object_exists($S3_key))
				return true;
			$upload_result = $s3->upload($filename, $S3_key);
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($upload_result);
			}
		}
		return $upload_result;
	}

	// Delete S3 object
	private function s3_delete($S3_bucket, $S3_key){
		$delete_result = false;
		if ($s3 = $this->s3($S3_bucket)) {
			$delete_result =
				$s3->object_exists($S3_key)
				? $s3->delete($S3_key)
				: true;
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($delete_result);
			}
		}
		return $delete_result;
	}

	// Get post attachments
	private function get_post_attachments($post, $content) {
		static $post_meta = array();

		if (is_numeric($post)) {
			$post_id = $post;
			$post = get_post($post_id);
			$post_type = $post->post_type;
		} else if (is_object($post)) {
			$post_id = $post->ID;
			$post_type = $post->post_type;
		} else {
			return false;
		}

		$attachments_count = 0;
		if (!isset($post_meta[$post_id])) {
			if ($post_type === 'attachment') {
				$attachments = array(0 => $post);
				$attachments_count++;
			} else {
				// Get post attachments from media library
				if ($attachments = get_posts(array('post_type' => 'attachment', 'post_parent' => $post_id))) {
					$medias = array();
					$upload_dir = wp_upload_dir();
					foreach($attachments as $attachment) {
						$attachment_file = str_replace($upload_dir['baseurl'].'/', '', $attachment->guid);
						$medias[] = $attachment_file;
						$attachments_count++;
					}
				} else {
					$attachments = array();
				}

				// Get post attachments from post content
				$wk_attachments = $this->get_post_attachments_from_content($content, $attachments_count, $medias);
				if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
					dbgx_trace_var($attachments);
					dbgx_trace_var($wk_attachments);
				}
				$attachments = array_merge($attachments, $wk_attachments);
				unset($medias);
				unset($wk_attachments);
			}
			$post_meta[$post_id] = $attachments_count > 0 ? $attachments : false;
		}

		return $post_meta[$post_id];
	}

	// Get post attachments from post content
	private function get_post_attachments_from_content($content, &$attachments_count, &$medias) {
		$attachments = array();
		$upload_dir = wp_upload_dir();

		$img_src_pattern = array(
			'#<a [^>]*href=[\'"]'.preg_quote($upload_dir['baseurl']).'/([^\'"]*)[\'"][^>]*><img [^>]*></a>#ism',
			'#<img [^>]*src=[\'"]'.preg_quote($upload_dir['baseurl']).'/([^\'"]*)[\'"][^>]*>#ism',
			);
		$img_src_pattern = (array)apply_filters('NephilaClavata/img_src_pattern', $img_src_pattern);

		foreach ($img_src_pattern as $pattern) {
			if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					if ($attachments_count > self::LIMIT)
						break;
					if ($attachment = $this->get_attachment_from_filename($match[1], $medias)) {
						$attachments[] = $attachment;
						$attachments_count++;
					}
				}
			}
			unset($matches);
		}

		return count($attachments) > 0 ? $attachments : array();
	}

	// Get attachment from file name
	private function get_attachment_from_filename($attachment_file, &$medias = array()){
		global $wpdb;

		$attachment_file = preg_replace('#\.(png|gif|jpe?g)\?[^\?]*$#uism','.$1',$attachment_file);
		$attachment_file = preg_replace('#^(.*[^/])\-[0-9]+x[0-9]+\.(png|gif|jpe?g)([\?]?.*)$#uism','$1.$2',$attachment_file);
		if (in_array($attachment_file, $medias))
			return false;

		$medias[] = $attachment_file;
		$attachment  = $wpdb->get_row($wpdb->prepare("
			select p.* from {$wpdb->posts} as p inner join {$wpdb->postmeta} as m on p.ID = m.post_id
			where m.meta_key = %s and m.meta_value = %s limit 1",
			'_wp_attached_file',
			$attachment_file));
		return $attachment;
	}

	// Get attachment sizes 
	private function get_attachment_sizes($attachment_id){
		$imagedata = wp_get_attachment_metadata($attachment_id);
		$sizes = array_merge(array('normal'), isset($imagedata['sizes']) ? array_keys($imagedata['sizes']) : array());
		return $sizes;
	}

	// Get media file info
	private function get_s3_media_info($attachment_id) {
		$sizes = $this->get_attachment_sizes($attachment_id);
		$images = array();
		foreach ($sizes as $size) {
			$home_path = function_exists('get_home_path') ? get_home_path() : ABSPATH;
			$image_src = wp_get_attachment_image_src($attachment_id, $size);
			$images[$size] = array(
				'url' => $image_src[0],
				'file' => str_replace(home_url('/'), $home_path, $image_src[0]),
				's3_key' => str_replace(home_url('/'), '/', $image_src[0]),
			);
		}
		return $images;
	}
}

// Go Go Go!
new NephilaClavata();
new NephilaClavataAdmin();
