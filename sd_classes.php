<?php

class simpleDRM_paradimage {

	public $errmsg;				// Error message (if the download function response were null)

	function __construct() {
	
		/*--------------------------------------------------
		  Adds the plugin filters
		--------------------------------------------------*/

		// Adds the settings menus for the plugin

		add_action( 'admin_init', [ $this, 'simpleDRM_settings_init' ] );
		add_action( 'admin_menu', [ $this, 'simpleDRM_add_menus' ] );

		// Loads the translations for the plugin

		add_action( 'plugins_loaded', [ $this, 'simpledrm_plugins_loaded' ], 0 );

		// Customize the Thank You page

		add_filter( 'woocommerce_thankyou_order_received_text', [ $this, 'simpledrm_thank_you_title' ], 20, 2 );
		add_action( 'woocommerce_account_downloads_column_download-remaining', [ $this, 'simpledrm_remaining' ], 20, 1 );
		add_action( 'woocommerce_account_downloads_column_download-file', [ $this, 'simpledrm_file' ], 20, 1 );
		add_action( 'woocommerce_account_downloads_column_download-expires', [ $this, 'simpledrm_expires' ], 20, 1 );

		// Rewrite rules for the API

		add_action( 'init', [ $this, 'simpleDRM_API_rewrites' ] );
		add_action( 'template_redirect', [ $this, 'simpleDRM_API_catch_json' ] );
	}

	/*---------------------------------------------------
		Manages the API endpoints
		They must have the form:
			/simpleDRM/v1/function/parm1=val1&parm2=val2...
		Where "function" = "authenticate" or "download"
	---------------------------------------------------*/
	
	public function simpleDRM_API_rewrites()
	{    
		add_rewrite_endpoint( 'simpleDRM', EP_ALL );
	}


	function simpleDRM_API_catch_json()
	{

		if( $parms = get_query_var( 'simpleDRM' ) )
		{
			// Retrieves the query parameters

			preg_match( '/^(v\d+)\/(\w+)\/?\??(.*)$/', $parms, $lector_parms );
			parse_str( $lector_parms[3], $p );

			// Processes the endpoints

			switch( $lector_parms[2] )
			{
				case 'authenticate':
				
					// Authenticates the user.
					// Returns the session parameters or an error message

					header('Content-Type: text/plain');
					echo( $this->authenticate( $lector_parms[1], $p ) );
					exit();

				case 'download':
				
					// Encrypts a file to download.
					// Returns an http 400 error, or the encrypted file

					$sdrm_response = $this->download( $lector_parms[1], $p );
					if ( ! $sdrm_response ) {

						// Returns an error
						header( "{$_SERVER['SERVER_PROTOCOL']} 400 Bad Request" );
						header( "Status: 400 Bad Request" );
						echo( $this->errmsg );

					} else {

						header( 'Content-Description: File Transfer' );
						header( 'Content-Type: application/zip' );
						header( "Pragma: public" );
						header( "Expires: 0" );
						header( "Cache-Control:must-revalidate, post-check=0, pre-check=0" );
						header( "Content-Type: application/force-download" );
						header( "Content-Type: application/download" );
						header( "Content-Disposition: attachment;filename=" . $sdrm_response[0] . "" );
						header( "Content-Transfer-Encoding: binary " );
						header( 'Content-Length: ' . filesize($sdrm_response[1]) );
						while( ob_get_level() ) ob_end_clean();
						flush();
						readfile( $sdrm_response[1] );
						unlink( $sdrm_response[1] );

					}
					exit();

				default:

					// This should not happen
					header('Content-Type: text/plain');
					echo( 'API Invalid parameters: ' . $sdrm_parms );
					exit();

			}
		}
	}


	/*---------------------------------------------------
			API endpoints (authenticate or download)
	---------------------------------------------------*/

	public function download( $version, $params ) {
	
		/*--------------------------------------------------
		  Returns an encrypted product for download. It is
		  called by the Reader App.
		  
			Parameters, received from the mobile app:
				- aes		An encrypted AES key, in HEX
				- sesionid	Session Id
				- url		URL of the downloadable file, in HEX
			
		  * The URL is coded in HEX to avoid conflicts with
			special characters like "/" when parsing the endpoint
		  * The AES key is encrypted with the app RSA key for security.
		  
		  The function calls PlazaAPI to decrypt the RSA key
		  if the session is valid
		--------------------------------------------------*/

		// Sanitizes and validates the parameters

		$aes       = sanitize_text_field( $params['aes'] );
		$targeturl = esc_url_raw( hex2bin( $params['url'] ) );
		$sesionid  = sanitize_text_field( $params['sesionid'] );

		if ( !( preg_match( '/^[0-9A-F]+/' , $aes ) ) ) {
			$this->errmsg = 'Invalid AES key';
			return null;
		}
		if ( !( preg_match( '/^[0-9]+@\S+$/' , $sesionid ) ) ) {
			$this->errmsg = 'Invalid session id';
			return null;
		}
		
		// Validates that the requested file exists and is accessible

		$path     = wp_parse_url( get_site_url() );							// wordpress home url
		$qpath    = wp_parse_url( $targeturl );								// requested file url
		$file     = ABSPATH . substr( $qpath[ "path" ], 1 );				// file
		$filename = substr( $targeturl, strrpos( $targeturl, '/' ) + 1 );	// file name

		if ( $path[ "host" ] != $qpath[ "host" ] ) {
			$this->errmsg = 'Invalid domain, file should be hosted in the shop domain';
			return null;
		}
		if( !is_file( $file ) ){
			$this->errmsg = 'File not found! ' . $file;
			return null;
		}
		if( !is_readable( $file ) ){
			$this->errmsg = 'File not accessible! ' . $file;
			return null;	
		}
			
		// Everything looks fine
		// Sends a query to the App Server to validate the session code and decrypt the AES key
		// If the session id is correct, the app server returns the decrypted AES key

		$response  = wp_remote_get( SDRM_APPSERVER . '/plazaAPI/v1/download/' . 
					  http_build_query(array('aes'      => $aes,
											 'sesionid' => $sesionid,
											 'url'		=> bin2hex($targeturl) ) ) );
		$httpcode = wp_remote_retrieve_response_code( $response );

		if ( $httpcode < 200 || $httpcode > 299 ) {
			$this->errmsg = 'Request to app server failed, error: ' . $httpcode;
			return null;
		}

		$data_str  = wp_remote_retrieve_body( $response );
		
		// The app server returns:
		//		.OK + the decrypted key, or
		//		.NO + an error code

		if ( strpos( $data_str, ".OK" ) < 0 ) {
			$this->errmsg = 'Invalid session: ' . $data_str;
			return null;
		}

		$clave = substr( $data_str, strpos( $data_str, ".OK" ) + 3 );
		$aesdecrypted = hex2bin( $clave );


		// Uses the AES key to encrypt the file

		define( 'SIMPLEDRM_ENCRYPTION_BLOCKS', 10000 );
		$iv = openssl_random_pseudo_bytes( 16 );

		$error = false;
		$seed  = rand( 10000, 99999 );
		$dest  = 'temp' . DIRECTORY_SEPARATOR . $seed . '_' . $filename;

		if ( !is_dir( "temp" ) ) { mkdir( "temp" ); }
		if ( $fpOut = fopen( $dest, 'w' ) ) {

			// Put the initialzation vector to the beginning of the file
			fwrite( $fpOut, $iv );
			if ( $fpIn = fopen( $file, 'rb' ) ) {

				while ( !feof( $fpIn ) ) {
					$plaintext  = fread( $fpIn, 16 * SIMPLEDRM_ENCRYPTION_BLOCKS );
					$ciphertext = openssl_encrypt( $plaintext, 'AES-128-CBC', $aesdecrypted, OPENSSL_RAW_DATA, $iv );
					// Use the first 16 bytes of the ciphertext as the next initialization vector
					$iv = substr( $ciphertext, 0, 16 );
					fwrite( $fpOut, $ciphertext );
				}
				fclose( $fpIn );

			} else {

				$this->errmsg = 'Error opening input file';
				return null;

			}
			fclose( $fpOut );
		} else {

			$this->errmsg = 'Error opening output file';
			return null;

		}
		return( array( $filename, $dest ) );
	}
	
	
	public function authenticate( $version, $params ) {
	
		/*--------------------------------------------------
			Validates a user/password pair and initiates a 
			new session for the device in the App Server.

			Parameters (GET):
				- tienda	integer		Shop id
				- email    	string		User Email
				- password	string		User Password
				- rsa		string		Public RSA key from the device, in HEX
				- device	string		A string uniquely identifying the device, provided by Android

			Returns .OK + json with: sesionid, tienda, email
				 Or .NO + any error message			
		--------------------------------------------------*/

		global $wpdb;

		$tiendaid = sanitize_text_field($params['tienda']);
		$email    = sanitize_email($params['email']);
		$password = sanitize_text_field( $params['password'] );
		$rsa      = sanitize_text_field( strtoupper( $params['rsa'] ) );
		$device   = sanitize_text_field($params['device']);

		if (! is_numeric( $tiendaid ) ) { return '.NO Invalid shop'; }
		if (! $email = is_email( $email ) ) { return '.NO Invalid email'; }
		if ( $password == "" ) { return '.NO Invalid password'; }
		if (! preg_match( "/^[A-F0-9]{2,}$/i", $rsa ) ) { return '.NO Invalid RSA'; }

		$usuario = get_user_by( 'email', $email );
		if ( ! $usuario ) {
			return ('.NO Invalid user email ' . $email );
		}
		if ( ! wp_check_password( $password, $usuario->user_pass, $usuario->ID ) ) {
			return ('.NO Invalid password' );
		}
/*
		// Authenticates the user. It actually logs in the user.

		$creds = array();
		$creds['user_login'] = $email;
		$creds['user_password'] = $password;
		$creds['remember'] = false;

		$usuario = wp_signon( $creds, false );

		if ( is_wp_error($usuario) ) {
			return ('.NO ' . $usuario->get_error_message());
		}
*/
		// Now we have to start a new session in the App Server.
		//----------------------------------------------------------------------------
		// First, que ask the app server for the Woocommerce API Customer Key
		// Then we retrieve the corresponding consumer_secret, which will be the aes key for encryption

		$response  = wp_remote_get( SDRM_APPSERVER . '/plazaAPI/v1/claves/tienda=' . base64_encode( home_url() ) );

		$data_str  = wp_remote_retrieve_body( $response );

		$http_code = wp_remote_retrieve_response_code( $response );

		if ($http_code < 200 || $http_code > 299) {
			return ( '.NO Error retrieving keys: ' . $http_code );
		}

		// The response is the Customer Key. We need the Customer Secret.
		$key = $wpdb->get_var( $wpdb->prepare("
								SELECT consumer_secret
								FROM {$wpdb->prefix}woocommerce_api_keys
								WHERE consumer_key = %s", wc_api_hash($data_str)));

		if ( !$key ) {
			return ( '.NO Wrong consumer key: ' . $http_code);
		}

		// Retrieves the first 128 bytes
		$aes = substr( base64_decode( substr( $key, strpos( $key, "_" ) + 1 ) ), 0, 16 );
		//-------------------------------------------------------------------------------

		// Starts a new session in the App Server
		// The parameters are encrypted, to avoid anyone starting user
		// sessions. The encryption is AES-128.

		$parametros = array( "email" => $email, "rsa" => $rsa, "device" => $device );
		$json = $this->encriptarAES( json_encode( $parametros ), bin2hex( $aes ) );

		// Asks the App Server to open a session

		$response  = wp_remote_get( SDRM_APPSERVER . '/plazaAPI/v1/login/' . 
					  http_build_query( array( 'usuario' => $usuario->ID, 
											   'tienda' => $tiendaid, 
											   'json' => $json ) ) );
		$data_str  = wp_remote_retrieve_body( $response );
		$http_code = wp_remote_retrieve_response_code( $response );

		if ($http_code < 200 || $http_code > 299) {
			return ( '.NO Error opening session: ' . $http_code);
		}

		//----------------------------------------------------------------------------
		// A return code .OK and session id, means the session is open.
		// Sends back the json adding a few other parameters

		if ( strpos( $data_str, '.OK' ) >= 0 ) {

			$jjs = json_decode( strstr( $data_str, '{' ) );

			if ( isset( $jjs->sesionid ) ) { 

				// Session open. Sends it back to the device
				// Encrypts the session id
				$encsesid = $this->encriptarRSAHEX($jjs->sesionid, $rsa );

				$parametros = array( "tienda" => $tiendaid, "usuario" => $usuario->ID, "email" =>$email, "sesionid" => bin2hex( $encsesid ) );
				return('.OK' . json_encode($parametros) );

			} else {

				return( $data_str );

			}
		}
		return( $data_str );
	}


	/*---------------------------------------------------
		PLUGIN SETTINGS MENU
	---------------------------------------------------*/

	function simpleDRM_settings_init(){

		// register new settings for "simpleDRM_options_page" page
		register_setting('simpleDRM_options_page', 'simpleDRM_thank_you');
	}

	function simpleDRM_add_menus() {

		// Adds the plugin settings page
		add_options_page( 'SimpleDRM Settings', 
						  'SimpleDRM', 
						  'manage_options', 
						  'simpleDRM_options_page', 
						  [ $this, 'simpleDRM_options' ] );
	}

	function simpleDRM_options() {

	   // Draws the plugin settings page
	   // Check that the user has the required capability 

	   if (!current_user_can('manage_options')) {
		  wp_die( __('You do not have sufficient permissions to access this page.', 'simple-drm') );
	   }

	   // Read in existing option value from database
	   $simpleDRM_opt_val = get_option( 'simpleDRM_thank_you' );

	   // See if the user has posted us some information
	   if( isset( $_POST[ 'simpleDRM_actualizado' ] ) && $_POST[ 'simpleDRM_actualizado' ] == 'Y' ) {

		  // Read and save the posted values
		  
		  if ( isset( $_POST[ 'simpleDRM_thank_you' ] ) ) {
			$simpleDRM_opt_val = 1;
		  } else {
			$simpleDRM_opt_val = 0;
		  }
		  update_option( 'simpleDRM_thank_you', $simpleDRM_opt_val );

		  // Put a "settings saved" message on the screen
?>
		<div class="updated"><p><strong><?php _e('Saved'); ?></strong></p></div>
<?php
		}

		  // Displays the settings
?>
		<div class="wrap">
			<h2>Simple DRM Settings</h2>
			<form name="form1" method="post" action="">
				<input type="hidden" name="simpleDRM_actualizado" value="Y">
				<p><input type="checkbox" id="simpleDRM_thank_you" name="simpleDRM_thank_you" <?php if( $simpleDRM_opt_val == 1 ) echo( 'checked' ); ?>>
				<?php _e('Customize Woocommerce Thank You page', 'simple-drm'); ?>
				</p><hr />

				<p class="submit">
				<input type="submit" name="simpleDRM_Submit" class="button-primary" value="<?php _e('Save Changes', 'simple-drm'); ?>" />
				</p>
			</form>
		</div>
<?php

		// Displays the licenses
		$response  = wp_remote_get( SDRM_APPSERVER . '/plazaAPI/v2/licencias/tienda=' . base64_encode( home_url() ) );
		$http_code = wp_remote_retrieve_response_code( $response );
		if ($http_code >= 200 && $http_code <= 299) {
			echo( wp_remote_retrieve_body( $response ) );
		}
	}


	/*------------------------------------------------------------
		LANGUAGE HOOKS
	------------------------------------------------------------*/

	function simpledrm_plugins_loaded() {
		load_plugin_textdomain( 'simple-drm', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}


	/*------------------------------------------------------------
		WOOCOMMERCE THANKYOU PAGE CUSTOMISATION
	------------------------------------------------------------*/

	// Changes the banner if there are downloadable products
	function simpledrm_thank_you_title( $thank_you_title, $order ){

		if ( get_option( 'simpleDRM_thank_you' ) == 1 ) {
			// Check if there is any downloadable product
			$sd_downloadable = false;
			$line_items = $order->get_items();
			foreach ( $line_items as $item ) {
				$product = $order->get_product_from_item( $item );
				if ($product->downloadable) {
					$sd_downloadable = true;
					break;
				}
			}
	 
			if ($sd_downloadable ) {
/*
				return ( '<p>' . __('Thank you for your purchase, ', 'simple-drm') . $order->get_billing_first_name() . '.</p>
						<p>' . __('To download the products you purchased, download the Paradimage Reader app and install it in your phone or laptop', 'simple-drm') . ':</p>
						<div class="wp-block-image"><figure class="aligncenter size-large"><a target="_blank" href="https://play.google.com/store/apps/details?id=com.paradimage.reader"><img loading="lazy" width="180" height="69" src="https://paradimage.es/wp-content/uploads/2021/04/DispGooglePlay.png" alt="" class="wp-image-1821" srcset="https://paradimage.es/wp-content/uploads/2021/04/DispGooglePlay.png 361w, https://paradimage.es/wp-content/uploads/2021/04/DispGooglePlay-300x116.png 300w" sizes="(max-width: 361px) 100vw, 361px" /></a></figure></div>');
*/
				return ( __('Thank you for your purchase, ', 'simple-drm') . $order->get_billing_first_name() . '.');
			} else {
				return ('<p>' . __('Thank you for your purchase, ', 'simple-drm') . $order->get_billing_first_name() . '.</p>');
			}
		} else {
			return ('<p>' . __('Thank you for your purchase, ', 'simple-drm') . $order->get_billing_first_name() . '.</p>');
		}

	}

	/* Cambios en la tabla de descargas.
	 * Son 4 columnas: el nombre, descargas restantes, fecha límite para descargar y botón de descarga.
	 * Se descargan todos los archivos del producto.
	 * 
	 * Contenido del parámetro $download:
	 * array(11) { 
		["download_url"]=> string(180) "https://paradimage.es/?download_file=2033&order=wc_order_3zNfY4f5G7U28&uid=ad9c400e77482bd80b996124f8a145f668456af46705b149d7d776b699e63420&key=9788ca6c-9800-48ba-a6a3-c7c0da7f654e"
		["download_id"]=> string(36) "9788ca6c-9800-48ba-a6a3-c7c0da7f654e" 
		["product_id"]=> int(2033) 
		["product_name"]=> string(35) "Eloísa está debajo de un almendro" 
		["product_url"]=> string(65) "https://paradimage.es/producto/eloisa-esta-debajo-de-un-almendro/" 
		["download_name"]=> string(6) "Eloisa" 
		["order_id"]=> int(2090) 
		["order_key"]=> string(22) "wc_order_3zNfY4f5G7U28" 
		["downloads_remaining"]=> int(0) 
		["access_expires"]=> NULL 
		["file"]=> array(2) { ["name"]=> string(6) "Eloisa" 
							  ["file"]=> string(52) "https://paradimage.es/epub/9788416564583_Eloisa.epub" } }

	Link del botón de descarga
		https://paradimage.es/?download_file=2033&order=wc_order_3zNfY4f5G7U28&uid=ad9c400e77482bd80b996124f8a145f668456af46705b149d7d776b699e63420&key=9788ca6c-9800-48ba-a6a3-c7c0da7f654e
	*/

	function simpledrm_remaining ($download) {
		if ( get_option( 'simpleDRM_thank_you' ) == 1 
				&& (str_contains( $download['file']['file'], '.epub') 
				||  str_contains( $download['file']['file'], '.pdf') 
				||  str_contains( $download['file']['file'], '.mp4') )
			) {
			echo( esc_html__('Download the file in the app ', 'simple-drm') . '<a href="https://play.google.com/store/apps/details?id=com.paradimage.reader">' . esc_html__('Paradimage Reader', 'simple-drm') . '</a>' );
		} else {
			echo is_numeric( $download['downloads_remaining'] ) ? esc_html( $download['downloads_remaining'] ) : esc_html__( 'Ilimitadas', 'simple-drm' );
		}
	}

	function simpledrm_file ($download) {
		if ( get_option( 'simpleDRM_thank_you' ) == 1 
			&& (str_contains( $download['file']['file'], '.epub') 
			||  str_contains( $download['file']['file'], '.pdf') 
			||  str_contains( $download['file']['file'], '.mp4') )
		) {
			echo( esc_html( $download['download_name'] ) );
		} else {
			echo '<a href="' . esc_url( $download['download_url'] ) . '" class="woocommerce-MyAccount-downloads-file button alt">' . esc_html( $download['download_name'] ) . '</a>';
		}
	}

	function simpledrm_expires ($download) {
		if ( get_option( 'simpleDRM_thank_you' ) == 1 
			&& (str_contains( $download['file']['file'], '.epub') 
			||  str_contains( $download['file']['file'], '.pdf') 
			||  str_contains( $download['file']['file'], '.mp4') )
		) {
			echo( '' );
		} else {
			if ( ! empty( $download['access_expires'] ) ) {
				echo '<time datetime="' . esc_attr( date( 'Y-m-d', strtotime( $download['access_expires'] ) ) ) . '" title="' . esc_attr( strtotime( $download['access_expires'] ) ) . '">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $download['access_expires'] ) ) ) . '</time>';
			} else {
				esc_html_e( 'Never', 'woocommerce' );
			}
		}
	}


	/*------------------------------------------------------------------
		CRYPTOGRAPIC FUNCTIONS
		Aux functions to encrypt and decrypt easily RSA and AES.
	------------------------------------------------------------------*/

	// Decrypts data using a PUBLIC RSA Key. Returns a decrypted string, or NULL
	private function desencriptarRSA($data, $publicRSA){
		if (! $publicKey = openssl_pkey_get_public( $publicRSA ) ) return null;
		if (! openssl_public_decrypt( $data, $decrypt, $publicKey, OPENSSL_PKCS1_PADDING ) ) {
			return null;
		} else {
			return $decrypt;
		}
	}

 
	// Encrypts data using a PUBLIC RSA Key. Returns an encrypted string, or NULL
	private function encriptarRSA($data, $publicRSA){
		if (! $publicKey = openssl_pkey_get_public( $publicRSA ) ) return null;
		if (! openssl_public_encrypt( $data, $encrypt, $publicKey, OPENSSL_PKCS1_PADDING ) ) {
			return null;
		} else {
			return $encrypt;
		}
	}


	// Encrypts raw data using a PUBLIC RSA Key in HEX. Returns an encrypted string, or NULL
	private function encriptarRSAHEX($data, $publicRSA){

		$rsab = base64_encode(hex2bin($publicRSA));
		$tex = '-----BEGIN PUBLIC KEY-----
';
		while(strlen($rsab) > 0) {
		 $tex .= substr($rsab, 0, 64) . '
';
		 $rsab = substr($rsab, 64);
		}
		$tex .= '-----END PUBLIC KEY-----';

		if (! $publicKey = openssl_pkey_get_public( $tex ) ) return null;
		if (! openssl_public_encrypt( $data, $encrypt, $publicKey, OPENSSL_PKCS1_PADDING ) ) {
			return null;
		} else {
			return $encrypt;
		}
	}


	// Encrypts RAW data using an AES key in HEX. It returns an HEX encrypted string or NULL
	private function encriptarAES($data, $aes) {
		$iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-128-cbc'));
		if (! $encrypt = openssl_encrypt ( $iv . $data, "aes-128-cbc", hex2bin($aes), OPENSSL_RAW_DATA, $iv ) ) {
			return null;
		} else {
			return bin2hex( $encrypt );
		}
	}


	// Decrypts RAW data using an AES key in HEX. It returns an HEX encrypted string or NULL
	private function desencriptarAES($data, $aes) {

		if (!$json = openssl_decrypt($data, "aes-128-cbc", hex2bin($aes), OPENSSL_RAW_DATA)) {
			return null;
		} else {
			return $json;
		}
	}
}
?>