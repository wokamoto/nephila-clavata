<?php
class NephilaClavata {
	private static $instance;
	private static $options; // this plugin options
	private static $s3;                // S3 Object

	const META_KEY    = '_s3_media_files';
	const DEBUG_MODE  = false;
	const TEXT_DOMAIN = 'nephila-clavata';
	const LIMIT       = 100;

	private function __construct() {}

	public static function get_instance() {
		if( !isset( self::$instance ) ) {
			$c = __CLASS__;
			self::$instance = new $c();
		}

		return self::$instance;
	}

	public function init($options){
		self::$options = $options;
	}

	public function add_hook(){
		// replace url
		add_filter('the_content', array($this, 'the_content'));
		add_filter('widget_text', array($this, 'widget_text'));
		add_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);

		// update S3 object
		add_action('edit_attachment', array($this, 'edit_attachment'));
		add_action('add_attachment', array($this, 'add_attachment'));
		// delete S3 object
		add_action('delete_attachment', array($this, 'delete_attachment'));
	}

	static public function plugin_basename() {
		return plugin_basename(dirname(dirname(__FILE__)).'/plugin.php');
	}

	// the_content filter hook
	public function the_content($content){
		$post_id = intval(get_the_ID());

		remove_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);
		$content = $this->replace_s3_url($content, $post_id);
		add_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);

		return $content;
	}

	// widget_text filter hook
	public function widget_text($content){
		remove_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);
		$content = $this->replace_s3_url($content, false);
		add_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);

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

		remove_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);
		$url = $this->replace_s3_url($url, $post_id);
		add_filter('wp_get_attachment_url', array($this, 'get_attachment_url'), 10, 2);

		$urls[$post_id] = $url;
		return $url;
	}

	public function edit_attachment($attachment_id){
		return $this->add_attachment($attachment_id, true);
	}

	public function add_attachment($attachment_id, $force = false){
		$post = get_post($attachment_id);
		if ($post->post_type !== 'attachment')
			return;

		$attachment_meta_key = self::META_KEY;
		$s3_bucket = isset(self::$options['bucket']) ? self::$options['bucket'] : false;
		$s3_url = isset(self::$options['s3_url']) ? self::$options['s3_url'] : false;
		$storage_class = isset(self::$options['storage_class']) ? self::$options['storage_class'] : 'STANDARD';

		// object upload to S3
		if ( !$force ) {
			$S3_medias_new = $this->get_s3_media_info($attachment_id);
			$S3_medias_old = get_post_meta($attachment_id, $attachment_meta_key, true);
			if (is_array($S3_medias_old)) {
				if (isset($S3_medias_old[$s3_bucket]))
					$S3_medias_old = (array)$S3_medias_old[$s3_bucket];
			} else {
				$S3_medias_old = array();
			}
		} else {
			$S3_medias_new = $this->get_s3_media_info($post_id);
			$S3_medias_old = array();
		}

		if ($force || $S3_medias_new !== $S3_medias_old) {
			$S3_medias = array();
			foreach($S3_medias_new as $size => $val ) {
				if ($force || !isset($S3_medias_old[$size])) {
					$result = $this->s3_upload($val['file'], $s3_bucket, $val['s3_key'], $storage_class);
					$S3_medias[$size] = $val;
				}
				if (!file_exists($val['file'])) {
					$this->s3_download($val['file'], $s3_bucket, $val['s3_key']);
				}
			}

			$S3_medias_old = get_post_meta($attachment_id, $attachment_meta_key, true);
			if (is_array($S3_medias_old)) {
				$S3_medias_old[$s3_bucket] = $S3_medias;
				$S3_medias = $S3_medias_old;
			} else {
				$S3_medias = array($s3_bucket => $S3_medias);
			}

			update_post_meta($attachment_id, $attachment_meta_key, $S3_medias);
		}
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

		$s3_bucket = isset(self::$options['bucket']) ? self::$options['bucket'] : false;
		$s3_url = isset(self::$options['s3_url']) ? self::$options['s3_url'] : false;
		$storage_class = isset(self::$options['storage_class']) ? self::$options['storage_class'] : false;
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
						$result = $this->s3_upload($val['file'], $s3_bucket, $val['s3_key'], $storage_class);
						$replace_flag = !$result ? false : true;
						if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
							dbgx_trace_var($result);
							dbgx_trace_var($val);
						}
					}
					if ($replace_flag && $search_url && $replace_url) {
						$S3_medias[$size] = $val;
						if (!isset($replace_data[$search_url]) && (!is_admin() || $pagenow === 'upload.php')) {
							$replace_data[$search_url] = $replace_url;
							$content = str_replace($search_url, $replace_url, $content);
						}
					}

					if (!file_exists($val['file']))
						$this->s3_download($val['file'], $s3_bucket, $val['s3_key']);
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
					if (!file_exists($val['file']))
						$this->s3_download($val['file'], $s3_bucket, $val['s3_key']);
				}
			}
			$replace_count++;
		}

		if (self::DEBUG_MODE && function_exists('dbgx_trace_var')) {
			dbgx_trace_var($replace_data);
			dbgx_trace_var($replace_data_org);
		}
		if ($post_id && ($replace_data !== $replace_data_org))
			update_post_meta($post_id, $post_meta_key, array($s3_bucket => $replace_data));
		unset($S3_medias_new);
		unset($S3_medias_old);
		unset($S3_medias);

		return $content;
	}

	// Initializing S3 object
	private function s3($S3_bucket = null){
		if (isset(self::$s3)) {
			if (isset($S3_bucket) && self::$s3->current_bucket() !== $S3_bucket)
				self::$s3->set_current_bucket($S3_bucket);
			return self::$s3;
		}
		if (self::$options) {
			$s3 = S3_helper::get_instance();
			$s3->init(
				isset(self::$options['access_key']) ? self::$options['access_key'] : null,
				isset(self::$options['secret_key']) ? self::$options['secret_key'] : null,
				isset(self::$options['region'])     ? self::$options['region']     : null
				);
			if ($s3 && isset($S3_bucket))
				$s3->set_current_bucket($S3_bucket);
			self::$s3 = $s3;
			return $s3;
		}
		return false;
	}

	// Upload file to S3
	private function s3_upload($filename, $S3_bucket, $S3_key, $storage_class){
		if (!file_exists($filename))
			return false;

		$upload_result = false;
		if ($s3 = $this->s3($S3_bucket)) {
			if ($s3->object_exists($S3_key))
				return true;
			$upload_result = $s3->upload($filename, $S3_key, null, $storage_class);
		}
		return $upload_result;
	}

	// Download file to S3
	private function s3_download($filename, $S3_bucket, $S3_key){
		$download_result = false;
		if ($s3 = $this->s3($S3_bucket)) {
			if (!$s3->object_exists($S3_key))
				return false;
			$download_result = $s3->download($S3_key, $filename);
		}
		return $download_result;
	}

	// Delete S3 object
	private function s3_delete($S3_bucket, $S3_key){
		$delete_result = false;
		if ($s3 = $this->s3($S3_bucket)) {
			$delete_result =
				$s3->object_exists($S3_key)
				? $s3->delete($S3_key)
				: true;
		}
		return $delete_result;
	}

	// Get post attachments
	private function get_post_attachments($post, $content = false) {
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

		$attachments = array();
		$attachments_count = 0;
		if ($post_type === 'attachment') {
			$attachments[] = $post;
			$attachments_count = 1;
		} else {
			// Get post attachments from media library
			$medias = array();
			if ($attachments = get_posts(array('post_type' => 'attachment', 'post_parent' => $post_id))) {
				$upload_dir = wp_upload_dir();
				foreach($attachments as $attachment) {
					$attachment_file = str_replace($upload_dir['baseurl'].'/', '', $attachment->guid);
					$medias[] = $attachment_file;
				}
			} else {
				$attachments = array();
			}

			// Get post attachments from post content
			if ($content) {
				$wk_attachments = $this->get_post_attachments_from_content($content, $attachments_count, $medias);
				$attachments = array_merge($attachments, $wk_attachments);
				unset($wk_attachments);
			}
			unset($medias);
			$attachments_count = count($attachments);
		}
		$post_meta[$post_id] = $attachments_count > 0 ? $attachments : false;

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

		if (!isset($medias))
			$medias = array();
		if (!is_array($medias))
			$medias = (array)$medias;
		$attachment_file = preg_replace('#\.(png|gif|jpe?g)\?[^\?]*$#uism','.$1',$attachment_file);
		$attachment_file = preg_replace('#^(.*[^/])\-[0-9]+x[0-9]+\.(png|gif|jpe?g)([\?]?.*)$#uism','$1.$2',$attachment_file);
		if ($attachment_file && in_array($attachment_file, $medias))
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
            $upload_dir_config = wp_upload_dir();
            $content_path = $upload_dir_config['basedir'];
            $content_url = $upload_dir_config['baseurl'];

            if ( $image_src = wp_get_attachment_image_src($attachment_id, $size) ) {
                $images[$size] = array(
                    'url'    => $image_src[0],
                    'file'   => str_replace($content_url, $content_path, $image_src[0]),
                    's3_key' => preg_replace('#https?://[^/]*/#i', '/', $image_src[0]),
                );
            } elseif ($attachment_url = wp_get_attachment_url($attachment_id, $size) ) {
                $images[$size] = array(
                    'url'    => $attachment_url,
                    'file'   => str_replace($content_url, $content_path, $attachment_url),
                    's3_key' => preg_replace('#https?://[^/]*/#i', '/', $attachment_url),
                );
            }
        }
        return $images;
	}
}
