<?php
if ( !class_exists('InputValidator') )
	require(dirname(__FILE__).'/class-InputValidator.php');

class NephilaClavata_Admin {
	private static $instance;
	private static $options;

	const OPTION_KEY  = 'nephila_clavata';
	const OPTION_PAGE = 'nephila-clavata';

	private $plugin_basename;
	private $admin_hook, $admin_action;
	private $regions = array(
		'US_EAST_1',
		'US_WEST_1',
		'US_WEST_2',
		'EU_WEST_1',
		'AP_SOUTHEAST_1',
		'AP_SOUTHEAST_2',
		'AP_NORTHEAST_1',
		'SA_EAST_1',
		'US_GOV_WEST_1'
		);

	private $storage_classes = array(
		'STANDARD',
		'REDUCED_REDUNDANCY',
	);

	private function __construct() {}

	public static function get_instance() {
		if( !isset( self::$instance ) ) {
			$c = __CLASS__;
			self::$instance = new $c();
		}

		return self::$instance;
	}

	public function init(){
		self::$options = $this->get_option();
		$this->plugin_basename = NephilaClavata::plugin_basename();
	}

	public function add_hook(){
		add_action('admin_menu', array($this, 'admin_menu'));
		add_filter('plugin_action_links', array($this, 'plugin_setting_links'), 10, 2 );
	}

	static public function option_keys(){
		return array(
			'access_key' => __('AWS Access Key',  NephilaClavata::TEXT_DOMAIN),
			'secret_key' => __('AWS Secret Key',  NephilaClavata::TEXT_DOMAIN),
			'region'     => __('AWS Region',  NephilaClavata::TEXT_DOMAIN),
			'bucket'     => __('S3 Bucket',  NephilaClavata::TEXT_DOMAIN),
			's3_url'     => __('S3 URL', NephilaClavata::TEXT_DOMAIN),
			'storage_class' => __('Storage Class', NephilaClavata::TEXT_DOMAIN),
			);
	}

	static public function get_option(){
		$options = get_option(self::OPTION_KEY);
		foreach (array_keys(self::option_keys()) as $key) {
			if (!isset($options[$key]) || is_wp_error($options[$key]))
				$options[$key] = '';
		}
		return $options;
	}

	//**************************************************************************************
	// Add Admin Menu
	//**************************************************************************************
	public function admin_menu() {
		global $wp_version;

		$title = __('Nephila clavata', NephilaClavata::TEXT_DOMAIN);
		$this->admin_hook = add_options_page($title, $title, 'manage_options', self::OPTION_PAGE, array($this, 'options_page'));
		$this->admin_action = admin_url( apply_filters( 'nephila_clavata_admin_url', '/options-general.php') ) . '?page=' . self::OPTION_PAGE;
	}

	public function options_page(){
		$nonce_action  = 'update_options';
		$nonce_name    = '_wpnonce_update_options';

		$option_keys   = $this->option_keys();
		$option_keys   = apply_filters( 'nephila_clavata_option_keys', $option_keys );
		self::$options = $this->get_option();
		$title = __('Nephila clavata', NephilaClavata::TEXT_DOMAIN);

		$iv = new InputValidator('POST');
		$iv->set_rules($nonce_name, 'required');

		// Update options
		if (!is_wp_error($iv->input($nonce_name)) && check_admin_referer($nonce_action, $nonce_name)) {
			// Get posted options
			$fields = array_keys($option_keys);
			foreach ($fields as $field) {
				switch ($field) {
				case 'access_key':
				case 'secret_key':
					$iv->set_rules($field, array('trim','esc_html','required'));
					break;
				default:
					$iv->set_rules($field, array('trim','esc_html'));
					break;
				}
			}
			$options = $iv->input($fields);
			$err_message = '';
			foreach ($option_keys as $key => $field) {
				if (is_wp_error($options[$key])) {
					$error_data = $options[$key];
					$err = '';
					foreach ($error_data->errors as $errors) {
						foreach ($errors as $error) {
							$err .= (!empty($err) ? '<br />' : '') . __('Error! : ', NephilaClavata::TEXT_DOMAIN);
							$err .= sprintf(
								__(str_replace($key, '%s', $error), NephilaClavata::TEXT_DOMAIN),
								$field
								);
						}
					}
					$err_message .= (!empty($err_message) ? '<br />' : '') . $err;
				}
				if (!isset($options[$key]) || is_wp_error($options[$key]))
					$options[$key] = '';
			}
			if (empty($options['s3_url']) && !empty($options['bucket'])) {
				$options['s3_url'] = sprintf(
					'http://%1$s.s3-website-%2$s.amazonaws.com',
					strtolower($options['bucket']),
					strtolower(str_replace('_','-',$options['region']))
					);
			}
			if ( !empty($options['s3_url']) && !preg_match('#^https?://#i', $options['s3_url']) ) {
				$options['s3_url'] = 'http://' . preg_replace('#^//?#', '', $options['s3_url']);
			}
			$options['s3_url'] = untrailingslashit($options['s3_url']);
			if (NephilaClavata::DEBUG_MODE && function_exists('dbgx_trace_var')) {
				dbgx_trace_var($options);
			}

			// Update options
			if (self::$options !== $options) {
				update_option(self::OPTION_KEY, $options);
				if (self::$options['s3_url'] !== $options['s3_url']) {
					global $wpdb;
					$sql = $wpdb->prepare(
						"delete from {$wpdb->postmeta} where meta_key in (%s, %s)",
						NephilaClavata::META_KEY,
						NephilaClavata::META_KEY.'-replace'
						);
					$wpdb->query($sql);
				}
				printf(
					'<div id="message" class="updated fade"><p><strong>%s</strong></p></div>'."\n",
					empty($err_message) ? __('Done!', NephilaClavata::TEXT_DOMAIN) : $err_message
					);
				self::$options = $options;
			}
			unset($options);
		}

		// Get S3 Object
		$s3 = S3_helper::get_instance();
		$s3->init(
			isset(self::$options['access_key']) ? self::$options['access_key'] : null,
			isset(self::$options['secret_key']) ? self::$options['secret_key'] : null,
			isset(self::$options['region']) ? self::$options['region'] : null
			);
		$regions = $this->regions;
		$buckets = false;
		if ($s3) {
			$regions = $s3->get_regions();
			$buckets = $s3->list_buckets();
		}
		if (!$buckets) {
			unset($option_keys['bucket']);
			unset($option_keys['s3_url']);
		}
		$storage_classes = $this->storage_classes;

?>
		<div class="wrap">
		<?php screen_icon(); ?>
		<h2><?php echo esc_html( $title ); ?></h2>
		<form method="post" action="<?php echo $this->admin_action;?>">
		<?php echo wp_nonce_field($nonce_action, $nonce_name, true, false) . "\n"; ?>
		<table class="wp-list-table fixed"><tbody>
		<?php foreach ($option_keys as $field => $label) { $this->input_field($field, $label, array('regions' => $regions, 'buckets' => $buckets, 'storage_classes' => $storage_classes)); } ?>
		</tbody></table>
		<?php submit_button(); ?>
		</form>
		</div>
<?php
	}

	private function input_field($field, $label, $args = array()){
		extract($args);

		$label = sprintf('<th><label for="%1$s">%2$s</label></th>'."\n", $field, $label);

		$input_field = sprintf('<td><input type="text" name="%1$s" value="%2$s" id="%1$s" size=100 /></td>'."\n", $field, esc_attr(self::$options[$field]));
		switch ($field) {
		case 'region':
			if ($regions && count($regions) > 0) {
				$input_field  = sprintf('<td><select name="%1$s">', $field);
				$input_field .= '<option value=""></option>';
				foreach ($regions as $region) {
					$input_field .= sprintf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr($region),
						$region == self::$options[$field] ? ' selected' : '',
						__($region, NephilaClavata::TEXT_DOMAIN));
				}
				$input_field .= '</select></td>';
			}
			break;
		case 'bucket':
			if ($buckets && count($buckets) > 0) {
				$input_field  = sprintf('<td><select name="%1$s">', $field);
				$input_field .= '<option value=""></option>';
				foreach ($buckets as $bucket) {
					$input_field .= sprintf(
						'<option value="%1$s"%2$s>%1$s</option>',
						esc_attr($bucket['Name']),
						$bucket['Name'] == self::$options[$field] ? ' selected' : '');
				}
				$input_field .= '</select></td>';
			}
			break;
		case 'storage_class':
			if ($storage_classes && count($storage_classes) > 0) {
				$input_field  = sprintf('<td><select name="%1$s">', $field);
				$input_field .= '<option value=""></option>';
				foreach ($storage_classes as $storage_class) {
					$input_field .= sprintf(
						'<option value="%1$s"%2$s>%1$s</option>',
						esc_attr($storage_class),
						$storage_class == self::$options[$field] ? ' selected' : '');
				}
				$input_field .= '</select></td>';
			}
			break;
		}

		echo "<tr>\n{$label}{$input_field}</tr>\n";
	}

	//**************************************************************************************
	// Add setting link
	//**************************************************************************************
	public function plugin_setting_links($links, $file) {
		if ($file === $this->plugin_basename) {
			$settings_link = '<a href="' . $this->admin_action . '">' . __('Settings') . '</a>';
			array_unshift($links, $settings_link); // before other links
		}

		return $links;
	}
}
