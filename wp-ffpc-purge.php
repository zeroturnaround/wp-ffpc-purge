<?php
/*
Plugin Name: WP-FFPC-Purge
Plugin URI: https://github.com/zeroturnaround/wp-ffpc-purge
Description: Adds ability to clear <a href="https://wordpress.org/plugins/wp-ffpc/">WP-FFPC</a> cache via admin-ajax call.
Version: 1.0
Author: Anton Pelešev <anton.peleshev@zeroturnaround.com>
Author URI: https://github.com/plescheff/
License: Apache 2.0
*/

defined('ABSPATH') or die("Yippee ki-yay!");

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

class WP_FFPC_Purge {

	private static $optionKeyName = 'wp-ffpc-purge-api-key';

	private static $actionName = 'ffpc_purge';

	private static $keys = array();

	private static $pluginFile;

	private static $ffpcPluginFile = 'wp-ffpc/wp-ffpc.php';

	private static $ffpcRequiredVersion = '1.7.8';

	private $key;	//Access key provided by consumer.

	private $clear;	//Cache URI to clear.

	private $flush;	//Flush all cache (overrides $clear).


	public function __construct() {

		self::$pluginFile = plugin_basename(__FILE__);

		$ffpcData = self::getFfpcData();

		if ($ffpcData['_enabled'] && $ffpcData['_supported']) {

			self::$keys[] = get_option(self::$optionKeyName);

			$this->key = filter_input(INPUT_GET, 'key');
			$this->clear = filter_input(INPUT_GET, 'clear');
			$this->flush = filter_input(INPUT_GET, 'flush');

			add_action('admin_init', array($this, 'actionAdminInit'));
			add_action('wp_ajax_' . self::$actionName, array($this, 'actionPurge'));
			add_action('wp_ajax_nopriv_' . self::$actionName, array($this, 'actionPurge'));

		} else {

			add_filter('plugin_action_links_' . self::$pluginFile, function($links) use($ffpcData) {

				if ($ffpcData['_supported'] && !$ffpcData['_enabled']) {

					$links['ffpc_required'] = 'Activate WP-FFPC';

				} else {

					$links['ffpc_required'] = '<a href="https://wordpress.org/plugins/wp-ffpc/" target="_blank">Install WP-FFPC</a>';

					if ($ffpcData['_exists'] && !$ffpcData['_supported']) {
						$links['ffpc_required'] .= sprintf(' (%s or newer)', self::$ffpcRequiredVersion);
					}

				}

				return $links;
			});
		}
	}

	public function actionAdminInit() {
		$isAdminFrontend = is_admin() && !(defined('DOING_AJAX') && DOING_AJAX);

		if ($isAdminFrontend && current_user_can('manage_options')) {

			if (filter_input(INPUT_SERVER, 'REQUEST_METHOD') === 'POST') {
				update_option(self::$optionKeyName, filter_input(INPUT_POST, self::$optionKeyName));
			}

			add_filter('plugin_action_links_' . self::$pluginFile, function($links) {
				array_unshift($links, '<a href="'. get_admin_url(null, 'options-general.php?page=wp-ffpc-settings#wp-ffpc-purge') .'">Settings</a>');
				return $links;
			});

//			add_filter('plugin_row_meta', function($links, $file) {
//				if ($file === self::$pluginFile) {
//					$links['ffpc'] = 'Fork me';
//				}
//				return $links;
//			}, 10, 2);

			add_filter('wp_ffpc_admin_panel_tabs', function($tabs) {
				$tabs['purge'] = __('Purge');
				return $tabs;
			});

			$endpoint = 'admin-ajax.php?action=' . self::$actionName;
			$optionKeyName = self::$optionKeyName;
			add_action('wp_ffpc_admin_panel_tabs_extra_content', function($pluginConstant) use($endpoint, $optionKeyName) {
				?>
				<fieldset id="<?php echo $pluginConstant ?>-purge">
					<dl>
						<dt>Endpoint</dt>
							<dd><?= admin_url($endpoint) ?></dd>
						<dt>Params</dt>
							<dd><code><strong>&key</strong>=API-KEY</code> [REQUIRED - API key from below]</dd>
							<dd><code><strong>&clear</strong>=URL</code> xor <code><strong>&flush</strong>=1</code> [REQUIRED one of them; URL to clear or flush all cache]</code></dd>
						<dt>API-KEY</dt>
							<dd>
								<input type="text" name="<?= $optionKeyName ?>" value="<?= get_option($optionKeyName, str_shuffle(uniqid())) ?>">
								<span class="description">You know the drill - make it hard to guess&hellip;</span>
							</dd>
					</dl>
				</fieldset>
				<?php
			});
		}
	}

	private function accessAllowed() {

		return in_array($this->key, self::$keys) || function_exists('current_user_can') && current_user_can('manage_options');

	}

	public function actionPurge() {

		if ($this->accessAllowed()) {

			$this->purge();

			wp_die('1');

		} else {

			header('HTTP/1.0 403 Forbidden');

			wp_die('-1');

		}
	}

	private function purge() {

		$adapter = self::getFfpcAdapter();

		if ($adapter && $this->flush) {

			$adapter->clear(null, true);

		} else if ($adapter && $this->clear) {

			$clearKeys = self::getClearKeys($this->clear, $adapter);

			add_filter('wp_ffpc_clear_keys_array', function($to_clear) use($clearKeys) {
				return $clearKeys + $to_clear;
			});

			$adapter->clear_keys($clearKeys);
		}
	}

	private static function getClearKeys($rawClearKey, $adapter) {

		$urimap = WP_FFPC_Backend::parse_urimap($rawClearKey);

		$clearKey = $adapter->key('', $urimap);

		$clearKeys = array(
			$clearKey => true,
			$rawClearKey => true
		);

		return $clearKeys;
	}

	private static function getFfpcData() {
		$pluginDir = plugin_dir_path( __FILE__ ) . '../';

		$pluginPath = $pluginDir . self::$ffpcPluginFile;

		$pluginData = is_readable($pluginPath) ? get_plugin_data($pluginPath) : array();

		$pluginData['_exists'] = file_exists($pluginPath);

		$pluginData['_enabled'] = $pluginData['_exists'] && is_plugin_active(self::$ffpcPluginFile);

		$pluginData['_supported'] = $pluginData['_exists'] && version_compare($pluginData['Version'], self::$ffpcRequiredVersion, '>=');

		return $pluginData;

	}

	private static function getFfpcAdapter() {
		global $wp_ffpc;

		if ($wp_ffpc instanceof WP_FFPC && method_exists($wp_ffpc, 'getBackend')) {

			return $wp_ffpc->getBackend();

		}

		return null;
	}

	public static function actionUninstall() {

		delete_option(self::$optionKeyName);

	}
}

$wpFfpcPurge = new WP_FFPC_Purge();
