<?php
/**
 * The core plugin config class.
 *
 * This maintains all the options and settings for this plugin.
 *
 * @since      	1.0.0
 * @since  		1.5 Moved into /inc
 * @package    	LiteSpeed
 * @subpackage 	LiteSpeed/inc
 * @author     	LiteSpeed Technologies <info@litespeedtech.com>
 */
namespace LiteSpeed;

defined( 'WPINC' ) || exit;


class Conf extends Base
{
	protected static $_instance;

	const TYPE_SET = 'set';

	private $_options = array();
	private $_const_options = array();
	private $_site_options = array();

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	protected function __construct()
	{
	}

	/**
	 * Specify init logic to avoid infinite loop when calling conf.cls instance
	 *
	 * @since  3.0
	 * @access public
	 */
	public function init()
	{
		// Check if conf exists or not. If not, create them in DB (won't change version if is converting v2.9- data)
		// Conf may be stale, upgrade later
		$this->_conf_db_init();

		/**
		 * Detect if has quic.cloud set
		 * @since  2.9.7
		 */
		if ( $this->_options[ self::O_CDN_QUIC ] ) {
			! defined( 'LITESPEED_ALLOWED' ) &&  define( 'LITESPEED_ALLOWED', true ) ;
		}

		add_action( 'litespeed_conf_append', array( $this, 'option_append' ), 10, 2 );
		add_action( 'litespeed_conf_force', array( $this, 'force_option' ), 10, 2 );

		$this->define_cache() ;
	}

	/**
	 * Init conf related data
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _conf_db_init()
	{
		/**
		 * Try to load options first, network sites can override this later
		 *
		 * NOTE: Load before run `conf_upgrade()` to avoid infinite loop when getting conf in `conf_upgrade()`
		 */
		$this->load_options() ;

		$ver = $this->_options[ Base::_VER ] ;

		/**
		 * Don't upgrade or run new installations other than from backend visit
		 * In this case, just use default conf
		 */
		if ( ! $ver || $ver != Core::VER ) {
			if ( ! is_admin() && ! defined( 'LITESPEED_CLI' ) ) {
				$this->_options = $this->load_default_vals();
				$this->_try_load_site_options();
				return ;
			}
		}

		/**
		 * Version is less than v3.0, or, is a new installation
		 */
		if ( ! $ver ) {
			// Try upgrade first (network will upgrade inside too)
			Data::get_instance()->try_upgrade_conf_3_0() ;
		}
		else {
			! defined( 'LSCWP_CUR_V' ) && define( 'LSCWP_CUR_V', $ver ) ;
		}

		/**
		 * Upgrade conf
		 */
		if ( $ver && $ver != Core::VER ) {
			// Plugin version will be set inside
			// Site plugin upgrade & version change will do in load_site_conf
			Data::get_instance()->conf_upgrade( $ver ) ;
		}

		/**
		 * Sync latest new options
		 */
		if ( ! $ver || $ver != Core::VER ) {
			// Load default values
			$this->load_default_vals() ;

			// Init new default/missing options
			foreach ( self::$_default_options as $k => $v ) {
				// If the option existed, bypass updating
				// Bcos we may ask clients to deactivate for debug temporarily, we need to keep the current cfg in deactivation, hence we need to only try adding default cfg when activating.
				self::add_option( $k, $v ) ;
			}
		}

		/**
		 * Network sites only
		 *
		 * Override conf if is network subsites and chose `Use Primary Config`
		 */
		$this->_try_load_site_options() ;

	}

	/**
	 * Load all latest options from DB
	 *
	 * @since  3.0
	 * @access public
	 */
	public function load_options( $blog_id = null, $dry_run = false )
	{
		$options = array() ;
		foreach ( self::$_default_options as $k => $v ) {
			if ( ! is_null( $blog_id ) ) {
				$options[ $k ] = self::get_blog_option( $blog_id, $k, $v ) ;
			}
			else {
				$options[ $k ] = self::get_option( $k, $v ) ;
			}
		}

		if ( $dry_run ) {
			return $options ;
		}

		// Bypass site special settings
		if ( $blog_id !== null ) {
			$options = array_diff_key( $options, array_flip( self::$SINGLE_SITE_OPTIONS ) ) ; // These options are network only
		}

		$this->_options = array_merge( $this->_options, $options );

		// Append const options
		if ( defined( 'LITESPEED_CONF' ) && LITESPEED_CONF ) {
			foreach ( self::$_default_options as $k => $v ) {
				$const = Base::conf_const( $k );
				if ( defined( $const ) ) {
					$this->_const_options[ $k ] = constant( $const );
				}
			}
		}
	}

	/**
	 * For multisite installations, the single site options need to be updated with the network wide options.
	 *
	 * @since 1.0.13
	 * @access private
	 */
	private function _try_load_site_options()
	{
		if ( ! $this->_if_need_site_options() ) {
			return ;
		}

		$this->_conf_site_db_init() ;

		// If network set to use primary setting
		if ( ! empty ( $this->_site_options[ self::NETWORK_O_USE_PRIMARY ] ) ) {
			// Get the primary site settings
			// If it's just upgraded, 2nd blog is being visited before primary blog, can just load default config (won't hurt as this could only happen shortly)
			$this->load_options( BLOG_ID_CURRENT_SITE ) ;
		}

		// Overwrite single blog options with site options
		foreach ( self::$_default_options as $k => $v ) {
			if ( isset( $this->_site_options[ $k ] ) ) {
				$this->_options[ $k ] = $this->_site_options[ $k ] ;
			}
		}
	}

	/**
	 * Check if needs to load site_options for network sites
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _if_need_site_options()
	{
		if ( ! is_multisite() ) {
			return false ;
		}

		// Check if needs to use site_options or not
		// todo: check if site settings are separate bcos it will affect .htaccess

		/**
		 * In case this is called outside the admin page
		 * @see  https://codex.wordpress.org/Function_Reference/is_plugin_active_for_network
		 * @since  2.0
		 */
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' ) ;
		}
		// If is not activated on network, it will not have site options
		if ( ! is_plugin_active_for_network( Core::PLUGIN_FILE ) ) {
			if ( $this->_options[ self::O_CACHE ] == self::VAL_ON2 ) { // Default to cache on
				$this->_options[ self::_CACHE ] = true ;
			}
			return false ;
		}

		return true ;
	}

	/**
	 * Init site conf and upgrade if necessary
	 *
	 * @since 3.0
	 * @access private
	 */
	private function _conf_site_db_init()
	{
		$this->load_site_options() ;

		$ver = $this->_site_options[ Base::_VER ] ;

		/**
		 * Don't upgrade or run new installations other than from backend visit
		 * In this case, just use default conf
		 */
		if ( ! $ver || $ver != Core::VER ) {
			if ( ! is_admin() && ! defined( 'LITESPEED_CLI' ) ) {
				$this->_site_options = $this->load_default_site_vals() ;
				return ;
			}
		}

		/**
		 * Upgrade conf
		 */
		if ( $ver && $ver != Core::VER ) {
			// Site plugin versin will change inside
			Data::get_instance()->conf_site_upgrade( $ver ) ;
		}

		/**
		 * Is a new installation
		 */
		if ( ! $ver || $ver != Core::VER ) {
			// Load default values
			$this->load_default_site_vals() ;

			// Init new default/missing options
			foreach ( self::$_default_site_options as $k => $v ) {
				// If the option existed, bypass updating
				self::add_site_option( $k, $v ) ;
			}
		}
	}

	/**
	 * Get the plugin's site wide options.
	 *
	 * If the site wide options are not set yet, set it to default.
	 *
	 * @since 1.0.2
	 * @access public
	 * @return array Returns the current site options.
	 */
	public function load_site_options()
	{
		if ( ! is_multisite() || $this->_site_options ) {
			return $this->_site_options ;
		}

		// Load all site options
		foreach ( self::$_default_site_options as $k => $v ) {
			$this->_site_options[ $k ] = self::get_site_option( $k, $v ) ;
		}

		return $this->_site_options ;
	}

	/**
	 * Append a 3rd party option to default options
	 *
	 * This will not be affected by network use primary site setting.
	 *
	 * @since  3.0
	 * @access public
	 */
	public function option_append( $name, $default )
	{
		self::$_default_options[ $name ] = $default ;
		$this->_options[ $name ] = self::get_option( $name, $default ) ;
	}

	/**
	 * Force an option to a certain value
	 *
	 * @since  2.6
	 * @access public
	 */
	public function force_option( $k, $v )
	{
		if ( ! array_key_exists( $k, $this->_options ) ) {
			return ;
		}

		if ( $this->_options[ $k ] === $v ) {
			return ;
		}

		Debug2::debug( "[Conf] ** $k forced from " . var_export( $this->_options[ $k ], true ) . ' to ' . var_export( $v, true ) ) ;

		$this->_options[ $k ] = $v ;
	}

	/**
	 * Define `_CACHE` const in options ( for both single and network )
	 *
	 * @since  3.0
	 * @access public
	 */
	public function define_cache()
	{
		// Check advanced_cache setting (compatible for both network and single site)
		if ( ! $this->_options[ self::O_UTIL_CHECK_ADVCACHE ] ) {
			! defined( 'LSCACHE_ADV_CACHE' ) && define( 'LSCACHE_ADV_CACHE', true ) ;
		}

		// Init global const cache on setting
		$this->_options[ self::_CACHE ] = false ;
		if ( $this->_options[ self::O_CACHE ] == self::VAL_ON || $this->_options[ self::O_CDN_QUIC ] ) {
			$this->_options[ self::_CACHE ] = true ;
		}

		// Check network
		if ( ! $this->_if_need_site_options() ) {
			// Set cache on
			$this->_define_cache_on() ;
			return ;
		}

		// If use network setting
		if ( $this->_options[ self::O_CACHE ] == self::VAL_ON2 && $this->_site_options[ self::O_CACHE ] ) {
			$this->_options[ self::_CACHE ] = true ;
		}

		$this->_define_cache_on() ;
	}

	/**
	 * Define `LITESPEED_ON`
	 *
	 * @since 2.1
	 * @access private
	 */
	private function _define_cache_on()
	{
		if ( ! $this->_options[ self::_CACHE ] ) {
			return ;
		}

		defined( 'LITESPEED_ALLOWED' ) && defined( 'LSCACHE_ADV_CACHE' ) && ! defined( 'LITESPEED_ON' ) && define( 'LITESPEED_ON', true ) ;

		// Use this for cache enabled setting check
		! defined( 'LITESPEED_ON_IN_SETTING' ) && define( 'LITESPEED_ON_IN_SETTING', true ) ;
	}

	/**
	 * Get the list of configured options for the blog.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return array The list of configured options.
	 */
	public function get_options( $ori = false )
	{
		if ( ! $ori ) {
			return array_merge( $this->_options, $this->_const_options );
		}

		return $this->_options ;
	}

	/**
	 * Get an option value
	 *
	 * @since  3.0
	 * @access public
	 */
	public static function val( $id, $ori = false )
	{
		$instance = self::get_instance();

		if ( isset( $instance->_options[ $id ] ) ) {
			if ( ! $ori ) {
				$val = $instance->const_overwritten( $id );
				if ( $val !== null ) {
					defined( 'LSCWP_LOG' ) && Debug2::debug( '[Conf] 🏛️ const option ' . $id . '=' . var_export( $val, true ) ) ;
					return $val;
				}
			}

			return $instance->_options[ $id ];
		}

		defined( 'LSCWP_LOG' ) && Debug2::debug( '[Conf] Invalid option ID ' . $id );

		return null;
	}

	/**
	 * Check if is overwritten
	 *
	 * @since  3.0
	 */
	public function const_overwritten( $id )
	{
		if ( ! isset( $this->_const_options[ $id ] ) || $this->_const_options[ $id ] == $this->_options[ $id ] ) {
			return null;
		}
		return $this->_const_options[ $id ];
	}

	/**
	 * Save option
	 *
	 * @since  3.0
	 * @access public
	 */
	public function update_confs( $the_matrix = false )
	{
		if ( $the_matrix ) {
			foreach ( $the_matrix as $id => $val ) {
				$this->update( $id, $val ) ;
			}
		}

		do_action( 'litespeed_update_confs', $the_matrix ) ;

		// Update related tables
		Data::get_instance()->correct_tb_existance() ;

		// Update related files
		Activation::get_instance()->update_files() ;

		/**
		 * CDN related actions - Cloudflare
		 */
		CDN\Cloudflare::get_instance()->try_refresh_zone() ;

		/**
		 * CDN related actions - QUIC.cloud
		 * @since 2.3
		 */
		CDN\Quic::try_sync_config() ;

	}

	/**
	 * Save option
	 *
	 * @since  3.0
	 * @access public
	 */
	public function update( $id, $val )
	{
		// Bypassed this bcos $this->_options could be changed by force_option()
		// if ( $this->_options[ $id ] === $val ) {
		// 	return ;
		// }

		if ( $id == Base::_VER ) {
			return ;
		}

		if ( ! array_key_exists( $id, self::$_default_options ) ) {
			defined( 'LSCWP_LOG' ) && Debug2::debug( '[Conf] Invalid option ID ' . $id ) ;
			return ;
		}

		if ( $val && $this->_conf_pswd( $id ) && ! preg_match( '|[^\*]|', $val ) ) {
			return;
		}

		// Validate type
		if ( is_bool( self::$_default_options[ $id ] ) ) {
			$max = $this->_conf_multi_switch( $id ) ;
			if ( $max && $val > 1 ) {
				$val %= $max + 1 ;
			}
			else {
				$val = $val === 'false' ? 0 : (bool) $val ;
			}
		}
		elseif ( is_array( self::$_default_options[ $id ] ) ) {
			// from textarea input
			if ( ! is_array( $val ) ) {
				$val = Utility::sanitize_lines( $val, $this->_conf_filter( $id ) ) ;
			}
		}
		elseif ( ! is_string( self::$_default_options[ $id ] ) ) {
			$val = (int) $val ;
		}
		else {
			// Check if the string has a limit set
			$val = $this->_conf_string_val( $id, $val ) ;
		}

		// Save data
		self::update_option( $id, $val ) ;

		// Handle purge if setting changed
		if ( $this->_options[ $id ] != $val ) {

			// Special handler for QUIC.cloud domain key to clear all existing nodes
			if ( $id == Base::O_API_KEY ) {
				Cloud::get_instance()->clear_cloud();
			}

			// Special handler for crawler: reset sitemap when drop_domain setting changed
			if ( $id == Base::O_CRAWLER_DROP_DOMAIN ) {
				Crawler_Map::get_instance()->empty();
			}

			// Check if need to fire a purge or not
			if ( $this->_conf_purge( $id ) ) {
				$diff = array_diff( $val, $this->_options[ $id ] ) ;
				$diff2 = array_diff( $this->_options[ $id ], $val ) ;
				$diff = array_merge( $diff, $diff2 ) ;
				// If has difference
				foreach ( $diff as $v ) {
					$v = ltrim( $v, '^' ) ;
					$v = rtrim( $v, '$' ) ;
					Purge::purge_url( $v ) ;
				}
			}

			// Check if need to do a purge all or not
			if ( $this->_conf_purge_all( $id ) ) {
				Purge::purge_all( 'conf changed [id] ' . $id ) ;
			}

			// Check if need to purge a tag
			if ( $tag = $this->_conf_purge_tag( $id ) ) {
				Purge::add( $tag ) ;
			}
		}

		// No need to update cron here, Cron will register in each init

		// Update in-memory data
		$this->_options[ $id ] = $val ;
	}

	/**
	 * Save network option
	 *
	 * @since  3.0
	 * @access public
	 */
	public function network_update( $id, $val )
	{

		if ( ! array_key_exists( $id, self::$_default_site_options ) ) {
			defined( 'LSCWP_LOG' ) && Debug2::debug( '[Conf] Invalid network option ID ' . $id ) ;
			return ;
		}

		// Validate type
		if ( is_bool( self::$_default_site_options[ $id ] ) ) {
			$max = $this->_conf_multi_switch( $id ) ;
			if ( $max && $val > 1 ) {
				$val %= $max + 1 ;
			}
			else {
				$val = (bool) $val ;
			}
		}
		elseif ( is_array( self::$_default_site_options[ $id ] ) ) {
			// from textarea input
			if ( ! is_array( $val ) ) {
				$val = Utility::sanitize_lines( $val, $this->_conf_filter( $id ) ) ;
			}
		}
		elseif ( ! is_string( self::$_default_site_options[ $id ] ) ) {
			$val = (int) $val ;
		}
		else {
			// Check if the string has a limit set
			$val = $this->_conf_string_val( $id, $val ) ;
		}

		// Save data
		self::update_site_option( $id, $val ) ;

		// Handle purge if setting changed
		if ( $this->_site_options[ $id ] != $val ) {
			// Check if need to do a purge all or not
			if ( $this->_conf_purge_all( $id ) ) {
				Purge::purge_all( '[Conf] Network conf changed [id] ' . $id ) ;
			}
		}

		// No need to update cron here, Cron will register in each init

		// Update in-memory data
		$this->_site_options[ $id ] = $val ;

		if ( isset( $this->_options[ $id ] ) ) {
			$this->_options[ $id ] = $val ;
		}
	}

	/**
	 * Check if one user role is in exclude optimization group settings
	 *
	 * @since 1.6
	 * @access public
	 * @param  string $role The user role
	 * @return int       The set value if already set
	 */
	public function in_optm_exc_roles( $role = null )
	{
		// Get user role
		if ( $role === null ) {
			$role = Router::get_role() ;
		}

		if ( ! $role ) {
			return false ;
		}

		return in_array( $role, self::val( self::O_OPTM_EXC_ROLES ) ) ? $role : false ;
	}

	/**
	 * Set one config value directly
	 *
	 * @since  2.9
	 * @access private
	 */
	private function _set_conf()
	{exit('');
		if ( empty( $_GET[ self::TYPE_SET ] ) || ! is_array( $_GET[ self::TYPE_SET ] ) ) {
			return ;
		}

		$options = $this->_options ;
		// Get items
		foreach ( $this->stored_items() as $v ) {//xxx
			$options[ $v ] = $this->get_item( $v ) ;
		}

		$changed = false ;
		foreach ( $_GET[ self::TYPE_SET ] as $k => $v ) {
			if ( ! isset( $options[ $k ] ) ) {
				continue ;
			}

			if ( is_bool( $options[ $k ] ) ) {//xx
				$v = (bool) $v ;
			}

			// Change for items
			if ( is_array( $v ) && is_array( $options[ $k ] ) ) {
				$changed = true ;

				$options[ $k ] = array_merge( $options[ $k ], $v ) ;

				Debug2::debug( '[Conf] Appended to item [' . $k . ']: ' . var_export( $v, true ) ) ;
			}

			// Chnage for single option
			if ( ! is_array( $v ) ) {
				$changed = true ;

				$options[ $k ] = $v ;

				Debug2::debug( '[Conf] Changed [' . $k . '] to ' . var_export( $v, true ) ) ;
			}

		}

		if ( ! $changed ) {
			return ;
		}

		$output = Admin_Settings::get_instance()->validate_plugin_settings( $options, true ) ; // Purge will be auto run in validating items when found diff
		// Save settings now (options & items)
		foreach ( $output as $k => $v ) {
			self::update_option( $k, $v ) ;
		}

		$msg = __( 'Changed setting successfully.', 'litespeed-cache' ) ;
		Admin_Display::succeed( $msg ) ;

		// Redirect if changed frontend URL
		if ( ! empty( $_GET[ 'redirect' ] ) ) {
			wp_redirect( $_GET[ 'redirect' ] ) ;
			exit() ;
		}
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  2.9
	 * @access public
	 */
	public static function handler()
	{
		$instance = self::get_instance() ;

		$type = Router::verify_type() ;

		switch ( $type ) {
			case self::TYPE_SET :
				$instance->_set_conf() ;
				break ;

			default:
				break ;
		}

		Admin::redirect() ;
	}
}
