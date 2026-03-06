<?php
/**
 * PSR-4 style autoloader for the WPT namespace.
 *
 * Maps WPT\* classes from the src/ directory.
 *
 * @package WooCommerce\ProductTables
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPT_Autoloader
 *
 * Handles autoloading of plugin classes using a PSR-4 style mapping.
 */
class WPT_Autoloader {

	/**
	 * Namespace prefix for PSR-4 autoloading.
	 *
	 * @var string
	 */
	private static $namespace = 'WPT\\';

	/**
	 * Base directory for the namespace prefix.
	 *
	 * @var string
	 */
	private static $src_dir = '';

	/**
	 * Whether the autoloader has been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$src_dir = WPT_PLUGIN_DIR . 'src/';
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
		self::$registered = true;
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class Fully qualified class name.
	 */
	public static function autoload( $class ) {
		// Only handle our namespace.
		$len = strlen( self::$namespace );
		if ( strncmp( self::$namespace, $class, $len ) !== 0 ) {
			return;
		}

		// Get the relative class name.
		$relative_class = substr( $class, $len );

		// Convert namespace separators to directory separators.
		$file = self::$src_dir . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
}

// Auto-register on load.
WPT_Autoloader::register();
