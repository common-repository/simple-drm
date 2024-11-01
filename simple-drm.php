<?php
/**
 * Plugin Name: Simple DRM for WooCommerce
 * Description: DRM protection for downloadable goods (ebooks, videos...) sold by Woocommerce sites, based on the apps by Paradimage
 * Author: Paradimage.es
 * Author URI: https://paradimage.es
 * Version: 1.1
 * Plugin URI: https://lector.paradimage.es
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-drm
 * Domain Path: /languages
*/

/*
SimpleDRM is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 2 of the License, or
any later version.
 
SimpleDRM is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
 
You should have received a copy of the GNU General Public License
along with SimpleDRM. If not, see http://www.gnu.org/licenses/gpl-3.0.html.
*/

if ( defined( 'SDRM_PLUGIN_URL' ) ) {
   wp_die( 'It seems that other version of SimpleDRM is active. Please uninstall it before use this version' );
}

define( 'SDRM_APPSERVER', 'https://plaza.paradimage.es' );
define( 'SDRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SDRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once( SDRM_PLUGIN_DIR . '/sd_classes.php' );

$simpleDRM_object = new simpleDRM_paradimage;


/**********************************************************
	ACTIVATION / DEACTIVATION HOOKS
**********************************************************/


function simpleDRM_activate(){
	
	// Sets the rewrite rules for the API

	add_rewrite_endpoint( 'simpleDRM', EP_ALL );
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'simpleDRM_activate' );

function simpleDRM_deactivate(){

	// Borra el setting de SimpleDRM
    unregister_setting('simpleDRM_options_page', 'simpleDRM_rsa');
    unregister_setting('simpleDRM_options_page', 'simpleDRM_thank_you');
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'simpleDRM_deactivate' );
?>