<?php
define('MIN_PHP_VERSION', '5.2.0');

/**
 * The class which responds to installer actions
 */
class InstallHandler extends ActionHandler {

	/**
	 * Entry point for installation.  The reason there is a begin_install
	 * method to handle is that conceivably, the user can stop installation
	 * mid-install and need an alternate entry point action at a later time.
	 */
	public function act_begin_install()
	{
		// Revert magic quotes, normally Controller calls this.
		Utils::revert_magic_quotes_gpc();

		// Create a new theme to handle the display of the installer
		$this->theme= Themes::create('installer', 'RawPHPEngine', HABARI_PATH . '/system/installer/');

		/*
		 * Check .htaccess first because ajax doesn't work without it.
		*/
		if ( ! $this->check_htaccess() ) {
			$this->handler_vars['file_contents']= implode( "\n", $this->htaccess() );
			$this->display('htaccess');
		}

		// Dispatch AJAX requests.
		if ( isset( $_POST['ajax_action'] ) ) {
			switch ( $_POST['ajax_action'] ) {
				case 'check_mysql_credentials':
					self::ajax_check_mysql_credentials();
					exit;
					break;
				case 'check_sqlite_credentials':
					self::ajax_check_sqlite_credentials();
					exit;
					break;
			}
		}
		// set the default values now, which will be overriden as we go
		$this->form_defaults();

		if (! $this->meets_all_requirements()) {
			$this->display('requirements');
		}

		/*
		 * Add the AJAX hooks
		 */
		Plugins::register( array('InstallHandler', 'ajax_check_mysql_credentials'), 'ajax_', 'check_mysql_credentials' );

		/*
		 * Let's check the config.php file if no POST data was submitted
		 */
		if ( (! file_exists(Site::get_dir('config_file') ) ) && ( ! isset($_POST['admin_username']) ) ) {
			// no config file, and no HTTP POST
			$this->display('db_setup');
		}

		// try to load any values that might be defined in config.php
		if ( file_exists( Site::get_dir('config_file') ) ) {
			include( Site::get_dir('config_file') );
			if ( isset( $db_connection ) ) {
				list( $this->handler_vars['db_type'], $remainder )= explode( ':', $db_connection['connection_string'] );
				switch( $this->handler_vars['db_type'] ) {
				case 'sqlite':
					// SQLite uses less info.
					// we stick the path in db_host
					$this->handler_vars['db_file']= $remainder;
					break;
				case 'mysql':
					list($host,$name)= explode(';', $remainder);
					list($discard, $this->handler_vars['db_host'])= explode('=', $host);
					list($discard, $this->handler_vars['db_schema'])= explode('=', $name);
					break;
				}
				$this->handler_vars['db_user']= $db_connection['username'];
				$this->handler_vars['db_pass']= $db_connection['password'];
				$this->handler_vars['table_prefix']= $db_connection['prefix'];
			}
			// if a $blog_data array exists in config.php, use it
			// to pre-load values for the installer
			// ** this is completely optional **
			if ( isset( $blog_data ) ) {
				foreach ( $blog_data as $blog_datum => $value ) {
					$this->handler_vars[$blog_datum]= $value;
				}
			}
		}

		// now merge in any HTTP POST values that might have been sent
		// these will override the defaults and the config.php values
		$this->handler_vars= array_merge($this->handler_vars, $_POST);

		// we need details for the admin user to install
		if ( ( '' == $this->handler_vars['admin_username'] )
			|| ( '' == $this->handler_vars['admin_pass1'] )
			|| ( '' == $this->handler_vars['admin_pass2'] )
			|| ( '' == $this->handler_vars['admin_email'])
		) {
			// if none of the above are set, display the form
			$this->display('db_setup');
		}

		// we got here, so we have all the info we need to install

		// make sure the admin password is correct
		if ( $this->handler_vars['admin_pass1'] !== $this->handler_vars['admin_pass2'] ) {
			$this->theme->assign( 'form_errors', array('password_mismatch'=>'Password mismatch!') );
			$this->display('db_setup');
		}

		// try to write the config file
		if (! $this->write_config_file()) {
			$this->theme->assign('form_errors', array('write_file'=>'Could not write config.php file...'));
			$this->display('db_setup');
		}

		// try to install the database
		if (! $this->install_db()) {
			// the installation failed for some reason.
			// re-display the form
			$this->display('db_setup');
		}

		// Install a theme.
		$themes= Utils::glob( Site::get_dir( 'user' ) . '/themes/*', GLOB_ONLYDIR );
		if ( 1 === count( $themes ) ) {
			// only one theme exists in /user/themes
			// assume the user wants that one activated.
			$theme= basename( $themes[0] );
			Themes::activate_theme( $theme, $theme );
		} elseif ( 1 < count( $themes ) ) {
			// we have multiple user themes installed
			// select one at random to use
			$random= rand( 1, count( $themes ) );
			$theme= basename( $themes[ $random ] );
			Themes::activate_theme( $theme, $theme );
		} else {
			// no user themes installed
			// activate a random system theme
			$themes= Utils::glob( HABARI_PATH . '/system/themes/*', GLOB_ONLYDIR );
			$random= rand( 1, count( $themes ) );
			$theme= basename( $themes[ $random ] );
			Themes::activate_theme( $theme, $theme );
		}

		EventLog::log('Habari successfully installed.', 'info', 'default', 'habari');
		return true;
	}

	/**
	 * Helper function to remove code repetition
	 *
	 * @param template_name Name of template to use
	 */
	private function display($template_name)
	{
		foreach ($this->handler_vars as $key=>$value) {
			$this->theme->assign($key, $value);
		}
		$this->theme->display($template_name);
		exit;
	}

	/*
	 * sets default values for the form
	 */
	public function form_defaults()
	{
		$formdefaults['db_type'] = 'mysql';
		$formdefaults['db_host'] = 'localhost';
		$formdefaults['db_user'] = '';
		$formdefaults['db_pass'] = '';
		$formdefaults['db_file'] = 'habari.db';
		$formdefaults['db_schema'] = 'habari';
		$formdefaults['table_prefix'] = isset($GLOBALS['db_connection']['prefix']) ? $GLOBALS['db_connection']['prefix'] : 'habari__';
		$formdefaults['admin_username'] = 'admin';
		$formdefaults['admin_pass1'] = '';
		$formdefaults['admin_pass2'] = '';
		$formdefaults['blog_title'] = 'My Habari';
		$formdefaults['admin_email'] = '';

		foreach( $formdefaults as $key => $value ) {
			if ( !isset( $this->handler_vars[$key] ) ) {
				$this->handler_vars[$key] = $value;
			}
		}
	}

	/**
	 * Gathers information about the system in order to make sure
	 * requirements for install are met
	 *
	 * @returns bool  are all requirements met?
	 */
	private function meets_all_requirements()
	{
		// Required extensions, this list will augment with time
		// Even if they are enabled by default, it seems some install turn them off
		// We use the URL in the Installer template to link to the installation page
		$required_extensions= array(
			'pdo' => 'http://php.net/pdo',
			'hash' => 'http://php.net/hash',
			'iconv' => 'http://php.net/iconv',
			'tokenizer' => 'http://php.net/tokenizer',
			'simplexml' => 'http://php.net/simplexml',
			'mbstring' => 'http://php.net/mbstring',
			);
		$requirements_met= true;

		/* Check versions of PHP */
		$php_version_ok= version_compare(phpversion(), MIN_PHP_VERSION, '>=');
		$this->theme->assign('php_version_ok', $php_version_ok);
		$this->theme->assign('PHP_OS', PHP_OS);;
		$this->theme->assign('PHP_VERSION',  phpversion());
		if (! $php_version_ok) {
			$requirements_met= false;
		}
		/* Check for required extensions */
		$missing_extensions= array();
		foreach ($required_extensions as $ext_name => $ext_url) {
			if (!extension_loaded($ext_name)) {
				$missing_extensions[$ext_name]= $ext_url;
				$requirements_met= false;
			}
		}
		$this->theme->assign('missing_extensions',  $missing_extensions);
		/* Check for PDO drivers */
		$pdo_drivers= PDO::getAvailableDrivers();
		if ( ! empty( $pdo_drivers ) ) {
			$pdo_drivers= array_combine( $pdo_drivers, $pdo_drivers );
			// Include only those drivers that we include database support for
			$pdo_schemas= array_map( 'basename', Utils::glob( HABARI_PATH . '/system/schema/*' ) );
			$pdo_schemas= array_combine( $pdo_schemas, $pdo_schemas );

			$pdo_drivers= array_intersect_key(
				$pdo_drivers,
				$pdo_schemas
			);
		}

		$pdo_drivers_ok= count( $pdo_drivers );
		$this->theme->assign( 'pdo_drivers_ok', $pdo_drivers_ok );
		$this->theme->assign( 'pdo_drivers', $pdo_drivers );
		if ( ! $pdo_drivers_ok ) {
			$requirements_met= false;
		}
		
		/**
		 * $local_writable is used in the template, but never set in Habari
		 * Won't remove the template code since it looks like it should be there
		 *
		 * This will only meet the requirement so there's no "undefined variable" exception
		 */
		$this->theme->assign( 'local_writable', true );
		
		return $requirements_met;
	}

	/**
	 * Attempts to install the database.  Returns the result of
	 * the installation, adding errors to the theme if any
	 * occur
	 *
	 * @return bool result of installation
	 */
	private function install_db()
	{
		$db_host= $this->handler_vars['db_host'];
		$db_type= $this->handler_vars['db_type'];
		$db_schema= $this->handler_vars['db_schema'];
		$db_user= $this->handler_vars['db_user'];
		$db_pass= $this->handler_vars['db_pass'];

		switch($db_type) {
		case 'mysql':
			// MySQL requires specific connection information
			if (empty($db_user)) {
				$this->theme->assign('form_errors', array('db_user'=>'User is required.'));
				return false;
			}
			if (empty($db_schema)) {
				$this->theme->assign('form_errors', array('db_schema'=>'Name for database is required.'));
				return false;
			}
			if (empty($db_host)) {
				$this->theme->assign('form_errors', array('db_host'=>'Host is required.'));
				return false;
			}
			break;
		case 'sqlite':
			// If this is a SQLite database, let's check that the file
			// exists and that we can access it.
			if ( ! $this->check_sqlite() ) {
				return false;
			}
			break;
		}

		if (! $this->connect_to_existing_db()) {
			$this->theme->assign('form_errors', array('db_user'=>'Problem connecting to supplied database credentials'));
			return false;
		}

		DB::begin_transaction();
		/* Let's install the DB tables now. */
		$create_table_queries= $this->get_create_table_queries(
			$this->handler_vars['db_type'],
			$this->handler_vars['table_prefix'],
			$this->handler_vars['db_schema']
		);
		DB::clear_errors();
		DB::dbdelta($create_table_queries, true, true, true);

		if(DB::has_errors()) {
			$error= DB::get_last_error();
			$this->theme->assign('form_errors', array('db_host'=>'Could not create schema tables...' . $error['message']));
			DB::rollback();
			return false;
		}

		// Cool.  DB installed. Create the default options
		// but check first, to make sure
		if ( ! Options::get('installed') ) {
			if (! $this->create_default_options()) {
				$this->theme->assign('form_errors', array('options'=>'Problem creating default options'));
				DB::rollback();
				return false;
			}
		}

		// Let's setup the admin user now.
		// But first, let's make sure that no users exist
		$all_users= Users::get_all();
		if ( count( $all_users ) < 1 ) {
			if (! $this->create_admin_user()) {
				$this->theme->assign('form_errors', array('admin_user'=>'Problem creating admin user.'));
				DB::rollback();
				return false;
			}
		}

		// create a first post, if none exists
		if ( ! Posts::get( array( 'count' => 1 ) ) ) {
			if ( ! $this->create_first_post()) {
				$this->theme->assign('form_errors',array('post'=>'Problem creating first post.'));
				DB::rollback();
				return false;
			}
		}

		/* Store current DB version so we don't immediately run dbdelta. */
		Version::save_dbversion();

		/* Ready to roll. */
		DB::commit();
		return true;
	}

	/**
	 * Checks for the existance of a SQLite datafile
	 * tries to create it if it does not exist
	**/
	private function check_sqlite() {
		$db_file = $this->handler_vars['db_host'];
		if ( file_exists( $db_file ) && is_writable( $db_file ) && is_writable( dirname( $db_file ) ) ) {
			// the file exists, and it writable.  We're all set
			return true;
		}

		// try to figure out what the problem is.
		if ( file_exists( $db_file ) ) {
			// the DB file exists, why can't we access it?
			if ( ! is_writable( $db_file ) ) {
				$this->theme->assign('form_errors', array('db_file'=>'The SQLite data file is not writable.') );
				return false;
			}
			if ( ! is_writable( dirname( $db_file ) ) ) {
				$this->theme->assign('form_errors', array('db_file'=>'The directory in which the SQLite data file resides must be writable by the web server.  See <a href="http://us3.php.net/manual/en/ref.sqlite.php#37875">here</a> and <a href="http://us3.php.net/manual/en/ref.pdo-sqlite.php#57356">here</a> for details.') );
				return false;
			}
		}

		if ( ! file_exists( $db_file ) ) {
			// let's see if the directory is writable
			// so that we could create the file
			if ( ! is_writable( dirname( $db_file ) ) ) {
				$this->theme->assign('form_errors', array('db_file'=>'The SQLite data file does not exist, and it cannot be created in the specified directory.  SQLite requires that the directory containing the database file be writable by the web server.') );
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks that there is a database matching the supplied
	 * arguments.
	 *
	 * @return  bool  Database exists with credentials?
	 */
	private function connect_to_existing_db()
	{
		global $db_connection;
		if($config= $this->get_config_file()) {
			$config = preg_replace('/<\\?php(.*)\\?'.'>/ims', '$1', $config);
			// Update the $db_connection global from the config that is aobut to be written:
			eval($config);

			/* Attempt to connect to the database host */
			return DB::connect();
		}
		// If we couldn't create the config from the template, return an error
		return false;
	}

	/**
	 * Creates the administrator user from form information
	 *
	 * @return  bool  Creation successful?
	 */
	private function create_admin_user()
	{
		$admin_username= $this->handler_vars['admin_username'];
		$admin_email= $this->handler_vars['admin_email'];
		$admin_pass= $this->handler_vars['admin_pass1'];

		if ($admin_pass{0} == '{') {
			// looks like we might have a crypted password
			$password= $admin_pass;

			// but let's double-check
			$algo = strtolower( substr( $admin_pass, 1, 3) );
			if ( ('ssh' != $algo) && ( 'sha' != $algo) ) {
				// we do not have a crypted password
				// so let's encrypt it
				$password= Utils::crypt($admin_pass);
			}
		}
		else {
			$password= Utils::crypt($admin_pass);
		}

		// Insert the admin user
		User::create(array (
			'username'=>$admin_username,
			'email'=>$admin_email,
			'password'=>$password
		));

		return true;
	}

	/**
	 * Write the default options
	 */
	private function create_default_options()
	{
		// Create the default options

		Options::set('installed', true);

		Options::set('title', $this->handler_vars['blog_title']);
		Options::set('base_url', substr($_SERVER['REQUEST_URI'], 0, strrpos($_SERVER['REQUEST_URI'], '/') + 1));
		Options::set('pagination', '5');
		Options::set( 'theme_name', 'k2' );
		Options::set( 'theme_dir' , 'k2' );
		Options::set( 'comments_require_id', 1 );
		// generate a random-ish number to use as the salt for
		// a SHA1 hash that will serve as the unique identifier for
		// this installation.  Also for use in cookies
		Options::set('GUID', sha1(Options::get('base_url') . Utils::nonce()));

		// Let's prepare the EventLog here, as well
		EventLog::register_type('default', 'habari');
		EventLog::register_type('user', 'habari');
		EventLog::register_type('authentication', 'habari');
		EventLog::register_type('content', 'habari');
		EventLog::register_type('comment', 'habari');

		// Add the cronjob to truncate the log so that it doesn't get too big
		CronTab::add_daily_cron( 'truncate_log', array( 'Utils', 'truncate_log' ), 'Truncate the log table' );

		return true;
	}

	/**
	 * Create the first post
	**/
	private function create_first_post()
	{
		// first, let's create our default post types of
		// "entry" and "page"
		Post::add_new_type('entry');
		Post::add_new_type('page');

		// now create post statuses for
		// "published" and "draft"
		// Should "private" status be added here, or through a plugin?
		Post::add_new_status('draft');
		Post::add_new_status('published');
		Post::add_new_status( 'scheduled', true );

		// Now create the first post
		Post::create(array(
			'title' => 'Habari',
			'content' => 'This site is running <a href="http://habariproject.org/">Habari</a>, a state-of-the-art publishing platform!  Habari is a community-driven project created and supported by people from all over the world.  Please visit <a href="http://habariproject.org/">http://habariproject.org/</a> to find out more!',
			'user_id' => 1,
			'status' => Post::status('published'),
			'content_type' => Post::type('entry'),
			'tags' => 'habari',
		));

		return true;
	}

	/**
	 * Install schema tables from the respective RDBMS schema
	 * @param $db_type string The schema string for the database
	 * @param $table_prefix string The prefix to use on each table name
	 * @param $db_schema string The database name
	 * @return array Array of queries to execute
	 */
	private function get_create_table_queries($db_type, $table_prefix, $db_schema)
	{
		/* Grab the queries from the RDBMS schema file */
		$file_path= HABARI_PATH . "/system/schema/{$db_type}/schema.sql";
		$schema_sql= trim(file_get_contents($file_path), "\r\n ");
		$schema_sql= str_replace('{$schema}',$db_schema, $schema_sql);
		$schema_sql= str_replace('{$prefix}',$table_prefix, $schema_sql);

		/*
		 * Just in case anyone creates a schema file with separate statements
		 * not separated by two newlines, let's clean it here...
		 * Likewise, let's clean up any separations of *more* than two newlines
		 */
		$schema_sql= str_replace( array( "\r\n", "\r", ), array( "\n", "\n" ), $schema_sql );
		$schema_sql= preg_replace("/;\n([^\n])/", ";\n\n$1", $schema_sql);
		$schema_sql= preg_replace("/\n{3,}/","\n\n", $schema_sql);
		$queries= preg_split('/(\\r\\n|\\r|\\n)\\1/', $schema_sql);
		return $queries;
	}


	/**
	 * Returns an RDMBS-specific CREATE SCHEMA plus user SQL expression(s)
	 *
	 * @return  string[]  array of SQL queries to execute
	 */
	private function get_create_schema_and_user_queries()
	{
		$db_host= $this->handler_vars['db_host'];
		$db_type= $this->handler_vars['db_type'];
		$db_schema= $this->handler_vars['db_schema'];
		$db_user= $this->handler_vars['db_user'];
		$db_pass= $this->handler_vars['db_pass'];

		$queries= array();
		switch ($db_type) {
			case 'mysql':
				$queries[]= 'CREATE DATABASE `' . $db_schema . '`;';
				$queries[]= 'GRANT ALL ON `' . $db_schema . '`.* TO \'' . $db_user . '\'@\'' . $db_host . '\' ' .
				'IDENTIFIED BY \'' . $db_pass . '\';';
				break;
			default:
				die('currently unsupported.');
		}
		return $queries;
	}

	/**
	* Gets the configuration template, inserts the variables into it, and returns it as a string
	*
	* @return string The config.php template for the db_type schema
	*/
	private function get_config_file()
	{
		if (! ($file_contents= file_get_contents(HABARI_PATH . "/system/schema/" . $this->handler_vars['db_type'] . "/config.php"))) {
			return false;
		}
		$vars= array_map('addslashes', $this->handler_vars);
		$file_contents= str_replace(
			array_map(array('Utils', 'map_array'), array_keys($vars)),
			$vars,
			$file_contents
		);
		return $file_contents;
	}

	/**
	 * Writes the configuration file with the variables needed for
	 * initialization of the application
	 *
	 * @return  bool  Did the file get written?
	 */
	private function write_config_file()
	{
		// first, check if a config.php file exists
		if ( file_exists( Site::get_dir('config_file' ) ) ) {
			// set the defaults for comprison
			$db_host= $this->handler_vars['db_host'];
			$db_file= $this->handler_vars['db_file'];
			$db_type= $this->handler_vars['db_type'];
			$db_schema= $this->handler_vars['db_schema'];
			$db_user= $this->handler_vars['db_user'];
			$db_pass= $this->handler_vars['db_pass'];
			$table_prefix= $this->handler_vars['table_prefix'];

			// set the connection string
			switch ( $db_type ) {
				case 'mysql':
					$connection_string= "$db_type:host=$db_host;dbname=$db_schema";
					break;
				case 'sqlite':
					$connection_string= "$db_type:$db_file";
					break;
			}

			// load the config.php file
			include( Site::get_dir('config_file') );

			// and now we compare the values defined there to
			// the values POSTed to the installer
			if ( isset($db_connection) &&
				( $db_connection['connection_string'] == $connection_string )
				&& ( $db_connection['username'] == $db_user )
				&& ( $db_connection['password'] == $db_pass )
				&& ( $db_connection['prefix'] == $table_prefix )
			) {
				// the values are the same, so don't bother
				// trying to write to config.php
				return true;
			}
		}
		if (! ($file_contents= file_get_contents(HABARI_PATH . "/system/schema/" . $this->handler_vars['db_type'] . "/config.php"))) {
			return false;
		}
		if($file_contents= $this->get_config_file()) {
			if ($file= @fopen(Site::get_dir('config_file'), 'w')) {
				if (fwrite($file, $file_contents, strlen($file_contents))) {
					fclose($file);
					return true;
				}
			}
			$this->handler_vars['config_file']= Site::get_dir('config_file');
			$this->handler_vars['file_contents']= htmlspecialchars($file_contents);
			$this->display('config');
			return false;
		}
		return false;  // Only happens when config.php template does not exist.
	}

	/**
	 * returns an array of .htaccess declarations used by Habari
	 */
	public function htaccess()
	{
		$htaccess= array(
			'open_block' => '### HABARI START',
			'engine_on' => 'RewriteEngine On',
			'rewrite_cond_f' => 'RewriteCond %{REQUEST_FILENAME} !-f',
			'rewrite_cond_d' => 'RewriteCond %{REQUEST_FILENAME} !-d',
			'rewrite_base' => '#RewriteBase /',
			'rewrite_rule' => 'RewriteRule . index.php [PT]',
			'close_block' => '### HABARI END',
		);
		$rewrite_base= trim( dirname( $_SERVER['SCRIPT_NAME'] ), '/\\' );
		if ( $rewrite_base != '' ) {
			$htaccess['rewrite_base']= 'RewriteBase /' . $rewrite_base;
		}

		return $htaccess;
	}

	/**
	 * checks for the presence of an .htaccess file
	 * invokes write_htaccess() as needed
	 */
	public function check_htaccess()
	{
		// If this is the mod_rewrite check request, then bounce it as a success.
		if( strpos( $_SERVER['REQUEST_URI'], 'check_mod_rewrite' ) !== false ) {
			echo 'ok';
			exit;
		}

		if ( FALSE === strpos( $_SERVER['SERVER_SOFTWARE'], 'Apache' ) ) {
			// .htaccess is only needed on Apache
			// @TODO: add support for IIS and lighttpd rewrites
			return true;
		}

		$result= false;
		if ( file_exists( HABARI_PATH . '/.htaccess') ) {
			$htaccess= file_get_contents( HABARI_PATH . '/.htaccess');
			if ( false === strpos( $htaccess, 'HABARI' ) ) {
				// the Habari block does not exist in this file
				// so try to create it
				$result= $this->write_htaccess( true );
			} else {
				// the Habari block exists
				$result= true;
			}
		}
		else {
			// no .htaccess exists.  Try to create one
			$result= $this->write_htaccess( false );
		}
		if ( $result ) {
			// the Habari block exists, but we need to make sure
			// it is correct.
			// Check that the rewrite rules actually do the job.
			$test_ajax_url= Site::get_url( 'habari' ) . '/check_mod_rewrite';
			$rr= new RemoteRequest( $test_ajax_url, 'POST', 20 );
			$rr_result= $rr->execute();
			if ( ! $rr->executed() ) {
				$result= $this->write_htaccess( true, true, true );
			}
		}

		return $result;
	}

	/**
	 * attempts to write the .htaccess file if none exists
	 * or to write the Habari-specific portions to an existing .htaccess
	 * @param bool whether an .htaccess file already exists or not
	 * @param bool whether to remove and re-create any existing Habari block
	 * @param bool whether to try a rewritebase in the .htaccess
	**/
	public function write_htaccess( $exists = FALSE, $update = FALSE, $rewritebase = TRUE )
	{
		$htaccess = $this->htaccess();
		if($rewritebase) {
			$rewrite_base= trim( dirname( $_SERVER['SCRIPT_NAME'] ), '/\\' );
			$htaccess['rewrite_base']= 'RewriteBase /' . $rewrite_base;
		}
		$file_contents= "\n" . implode( "\n", $htaccess ) . "\n";

		if ( ! $exists ) {
			if ( ! is_writable( HABARI_PATH ) ) {
				// we can't create the file
				return false;
			}
		}
		else {
			if ( ! is_writable( HABARI_PATH . '/.htaccess' ) ) {
				// we can't update the file
				return false;
			}
		}
		if ( $update ) {
			// we're updating an existing but incomplete .htaccess
			// care must be take only to remove the Habari bits
			$htaccess = file_get_contents(HABARI_PATH . '/.htaccess');
			$file_contents = preg_replace('%### HABARI START.*?### HABARI END%ims', $file_contents, $htaccess);
			// Overwrite the existing htaccess with one that includes the modified Habari rewrite block
			$fmode = 'w';
		}
		else {
			// Append the Habari rewrite block to the existing file.
			$fmode = 'a';
		}
		//Save the htaccess
		if ( $fh= fopen( HABARI_PATH . '/.htaccess', $fmode ) ) {
			if ( FALSE === fwrite( $fh, $file_contents ) ) {
				return false;
			}
		}
		else {
			return false;
		}

		return true;
	}

	/**
	 * Upgrade the database when the database version stored is lower than the one in source
	 * @todo Make more db-independent
	 */
	public function upgrade_db()
	{
		global $db_connection;

		// This database-specific code needs to be moved into the schema-specific functions
		list( $schema, $remainder )= explode( ':', $db_connection['connection_string'] );
		switch( $schema ) {
		case 'sqlite':
			$db_name = '';
			break;
		case 'mysql':
			list($host,$name)= explode(';', $remainder);
			list($discard, $db_name)= explode('=', $name);
			break;
		}

		// Get the queries for this database and apply the changes to the structure
		$queries= $this->get_create_table_queries($schema, $db_connection['prefix'], $db_name);
		DB::dbdelta($queries);

		// Apply data changes to the database based on version, call the db-specific upgrades, too.
		$version = Options::get('db_version');
		switch(true) {
			case $version < 1310:
				// Auto-truncate the log table
				if ( ! CronTab::get_cronjob( 'truncate_log' ) ) {
					CronTab::add_daily_cron( 'truncate_log', array( 'Utils', 'truncate_log' ), 'Truncate the log table' );
				}
		}
		DB::upgrade( $version );

		Version::save_dbversion();
	}

	/**
	 * Validate database credentials for MySQL
	 * Try to connect and verify if database name exists
	 */
	public function ajax_check_mysql_credentials() {
		$xml= new SimpleXMLElement('<response></response>');
		// Missing anything?
		if ( !isset( $_POST['host'] ) ) {
			$xml->addChild( 'status', 0 );
			$xml_error= $xml->addChild( 'error' );
			$xml_error->addChild( 'id', '#databasehost' );
			$xml_error->addChild( 'message', 'The database host field was left empty.' );
		}
		if ( !isset( $_POST['database'] ) ) {
			$xml->addChild( 'status', 0 );
			$xml_error= $xml->addChild( 'error' );
			$xml_error->addChild( 'id', '#databasename' );
			$xml_error->addChild( 'message', 'The database name field was left empty.' );
		}
		if ( !isset( $_POST['user'] ) ) {
			$xml->addChild( 'status', 0 );
			$xml_error= $xml->addChild( 'error' );
			$xml_error->addChild( 'id', '#databaseuser' );
			$xml_error->addChild( 'message', 'The database user field was left empty.' );
		}
		if ( !isset( $xml_error ) ) {
			// Can we connect to the DB?
			$pdo= 'mysql:host=' . $_POST['host'] . ';dbname=' . $_POST['database'];
			try {
				$connect= DB::connect( $pdo, $_POST['user'], $_POST['pass'] );
				$xml->addChild( 'status', 1 );
			}
			catch(Exception $e) {
				$xml->addChild( 'status', 0 );
				$xml_error= $xml->addChild( 'error' );
				if ( strpos( $e->getMessage(), '[1045]' ) ) {
					$xml_error->addChild( 'id', '#databaseuser' );
					$xml_error->addChild( 'id', '#databasepass' );
					$xml_error->addChild( 'message', 'Access denied. Make sure these credentials are valid.' );
				}
				else if ( strpos( $e->getMessage(), '[1049]' ) ) {
					$xml_error->addChild( 'id', '#databasename' );
					$xml_error->addChild( 'message', 'That database does not exist.' );
				}
				else if ( strpos( $e->getMessage(), '[2005]' ) ) {
					$xml_error->addChild( 'id', '#databasehost' );
					$xml_error->addChild( 'message', 'Could not connect to host.' );
				}
				else {
					$xml_error->addChild( 'id', '#databaseuser' );
					$xml_error->addChild( 'id', '#databasepass' );
					$xml_error->addChild( 'id', '#databasename' );
					$xml_error->addChild( 'id', '#databasehost' );
					$xml_error->addChild( 'message', $e->getMessage() );
				}
			}
		}
		$xml= $xml->asXML();
		ob_clean();
		header("Content-type: text/xml");
		header("Cache-Control: no-cache");
		print $xml;
	}

	/**
	 * Validate database credentials for SQLite
	 * Try to connect and verify if database name exists
	 */
	public function ajax_check_sqlite_credentials() {
		$db_file= $_POST['file'];
		$xml= new SimpleXMLElement('<response></response>');
		// Missing anything?
		if ( !isset( $db_file ) ) {
			$xml->addChild( 'status', 0 );
			$xml_error= $xml->addChild( 'error' );
			$xml_error->addChild( 'id', '#databasefile' );
			$xml_error->addChild( 'message', _t('The database file was left empty.') );
		}
		if ( !isset( $xml_error ) ) {
			if ( ! is_writable( dirname( $db_file ) ) ) {
				$xml->addChild( 'status', 0 );
				$xml_error= $xml->addChild( 'error' );
				$xml_error->addChild( 'id', '#databasefile' );
				$xml_error->addChild( 'message', _t('SQLite requires that the directory that holds the DB file be writable by the web server.') );
			} elseif ( file_exists( $db_file ) && ( ! is_writable( $db_file ) ) ) {
				$xml->addChild( 'status', 0 );
				$xml_error= $xml->addChild( 'error' );
				$xml_error->addChild( 'id', '#databasefile' );

				$xml_error->addChild( 'message', _t('The SQLite data file is not writable by the web server.') );
			} else {
				// Can we connect to the DB?
				$pdo= 'sqlite:' . $db_file;
				$connect= DB::connect( $pdo, null, null );

				// Don't leave empty files laying around
				DB::disconnect();
				if ( file_exists( $db_file ) ) {
					unlink($db_file);
				}

				switch ($connect) {
					case true:
						// We were able to connect to an existing database file.
						$xml->addChild( 'status', 1 );
						break;
					default:
						// We can't create the database file, send an error message.
						$xml->addChild( 'status', 0 );
						$xml_error= $xml->addChild( 'error' );
						// TODO: Add error codes handling for user-friendly messages
						$xml_error->addChild( 'id', '#databasefile' );
						$xml_error->addChild( 'message', $connect->getMessage() );
				}
			}
		}
		$xml= $xml->asXML();
		ob_clean();
		header("Content-type: text/xml");
		header("Cache-Control: no-cache");
		print $xml;
	}

}
?>
