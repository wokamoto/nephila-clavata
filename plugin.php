<?php
/*
Plugin Name: Nephila clavata
Version: 0.0.1
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
if ( !class_exists('NephilaClavataAdmin') )
	require_once(dirname(__FILE__).'/includes/class-admin-menu.php');
if ( !class_exists('S3_helper') )
	require_once(dirname(__FILE__).'/includes/class-s3-helper.php');

class NephilaClavata {
	const META_KEY   = '_s3_media_files';
	const DEBUG_MODE = true;

	private $s3;
	private $options = array();

	function __construct(){
		$admin = new NephilaClavataAdmin($this);
		$this->options = $admin->get_options();
		if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
			dbgx_trace_var($this->options, 'Nephila clavata option');
		}

		add_filter('the_content', array(&$this, 'the_content'));
		add_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
	}

	public function the_content($content){
		$post_id = get_the_ID();
		remove_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
		$content = $this->replace_s3_url($content, $post_id);
		add_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
		return $content;
	}

	public function get_attachment_url($url, $post_id = null) {
		static $urls = array();
		if (!isset($post_id))
			$post_id = get_the_ID();
		if (isset($urls[$post_id]))
			return $urls[$post_id];
		remove_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
		$url = $this->replace_s3_url($url, $post_id);
		$urls[$post_id] = $url;
		add_filter('wp_get_attachment_url', array(&$this, 'get_attachment_url'), 10, 2);
		return $url;
	}

	private function replace_s3_url($content, $post_id){
		if (empty($content))
			return $content;

		$attachments = $this->get_post_attachments($post_id);
		if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
			dbgx_trace_var($attachments);
		}
		if (!$attachments)
			return $content;

		$attachments_meta = array();
		$search = array();
		$replace = array();
		$s3_url = isset($this->options['s3_url']) ? $this->options['s3_url'] : '/';
		foreach ($attachments as $attachment) {
			$S3_medias_new = $this->get_s3_media_info($attachment->ID);
			$S3_medias_old = (array)get_post_meta($attachment->ID, self::META_KEY, true);
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($S3_medias_new);
				dbgx_trace_var($S3_medias_old);
			}

			if ($S3_medias_new !== $S3_medias_old) {
				$S3_medias = array();
				foreach($S3_medias_new as $size => $val ) {
					$replace_flag = true;
					$search_url = $val['url'];
					$replace_url = $s3_url . $val['s3_key'];
					if (!isset($S3_medias_old[$size])) {
						$result = $this->s3_upload($val['file'], $val['s3_key']);
						$replace_flag = !$result ? false : true;
						if (self::DEBUG_MODE && function_exists('dbgx_trace_var'))
							dbgx_trace_var($result);
					}
					if ($replace_flag && $search_url && $replace_url) {
						$S3_medias[$size] = $val;
						$search[] = $search_url;
						$replace[] = $replace_url;
					}
				}
				if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
					dbgx_trace_var($S3_medias);
				}
				if (count($S3_medias) > 0)
					add_post_meta($attachment->ID, self::META_KEY, $S3_medias, true);
			} else {
				foreach($S3_medias_new as $size => $val ) {
					$search[]  = $val['url'];
					$replace[] = $s3_url . $val['s3_key'];
				}
			}
		}

		if (count($search) > 0 && count($replace) > 0)
			$content = str_replace($search, $replace, $content);
		if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
			dbgx_trace_var($search);
			dbgx_trace_var($replace);
			dbgx_trace_var($content);
		}
		return $content;
	}

	private function s3_init(){
		if (isset($this->s3))
			return $this->s3;
		if ($this->options) {
			$s3 = new S3_helper(
				isset($this->options['access_key']) ? $this->options['access_key'] : null,
				isset($this->options['secret_key']) ? $this->options['secret_key'] : null,
				isset($this->options['region']) ? $this->options['region'] : null
				);
			if ($s3) {
				$s3_bucket = isset($this->options['bucket']) ? $this->options['bucket'] : null;
				$s3->set_option(array('Bucket' => $s3_bucket));
			}
			$this->s3 = $s3;
			return $s3;
		} else {
			return false;
		}
	}

	private function s3_upload($filename, $s3_key){
		if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
			dbgx_trace_var($filename);
			dbgx_trace_var($s3_key);
		}
		if (!file_exists($filename))
			return false;

		$s3 = $this->s3_init();
		if ( $s3 !== false) {
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($s3->object_exists($s3_key));
			}
			if ($s3->object_exists($s3_key))
				return true;
			$upload_result = $s3->upload($filename, $s3_key);
			if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($upload_result);
			}
			return $upload_result;
		} else {
			return false;
		}
	}

	private function get_post_attachments($post) {
		static $post_meta = array();
		global $wpdb;

		if (is_numeric($post)) {
			$post_id = $post;
			$post = get_post($post_id);
			$post_type = $post->post_type;
		} else {
			$post_id = $post->ID;
			$post_type = $post->post_type;
		}
		if ( !isset($post_meta[$post_id])) {
			if ($post_type === 'attachment') {
				$attachments = array(0 => $post);
			} else {
				$attachments = (array)get_posts(array('post_type' => 'attachment', 'post_parent' => $post_id));
				$upload_dir = wp_upload_dir();
				$medias = array();
				foreach($attachments as $attachment) {
					$medias[] = str_replace($upload_dir['baseurl'].'/', '', $attachment->guid);
				}

				$pattern = '#(<a [^>]*href=[\'"])('.preg_quote($upload_dir['baseurl']).'/)([^\'"]*)([\'"][^>]*><img [^>]*></a>)#uism';
			    if ( preg_match_all($pattern, $post->post_content, $matches, PREG_SET_ORDER) ) {
					foreach ( $matches as $match ) {
						if (in_array($match[3], $medias))
							continue;
						$medias[] = $match[3];
						$post_id = $wpdb->get_var($wpdb->prepare("
							select p.ID from {$wpdb->posts} as p inner join {$wpdb->postmeta} as m on p.ID = m.post_id
							where m.meta_key = %s and m.meta_value = %s limit 1",
							'_wp_attached_file',
							$match[3]));
						$post = get_post($post_id);
						if ( $post ) {
							$attachments[] = $post;
            			}
        			}
			    }
			    unset($matches);
			    unset($medias);
			}

			$post_meta[$post_id] = count($attachments) > 0 ? $attachments : false;
		}
		if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
			dbgx_trace_var($post_meta[$post_id]);
		}
		return $post_meta[$post_id];
	}

	private function get_attachment_sizes($attachment_id){
		$imagedata = wp_get_attachment_metadata($attachment_id);
		$sizes = array_merge(array('normal'), isset($imagedata['sizes']) ? array_keys($imagedata['sizes']) : array());
		return $sizes;
	}

	private function get_s3_media_info($attachment_id) {
		$sizes = $this->get_attachment_sizes($attachment_id);
		$images = array();
		foreach ($sizes as $size) {
			$image_src = wp_get_attachment_image_src($attachment_id, $size);
			$images[$size] = array(
				'url' => $image_src[0],
				'file' => str_replace(home_url('/'), get_home_path(), $image_src[0]),
				's3_key' => str_replace(home_url('/'), '/', $image_src[0]),
			);
		}
		return $images;
	}

}
new NephilaClavata();