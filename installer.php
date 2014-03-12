<?php
/**
* 
*/
class PluginInstaller
{
    
    function __construct($opts)
    {
        $this->slug = $opts['slug'];
        $this->name = $opts['name'];
        if (isset($opts['redirect'])) $this->redirect = $opts['redirect'];
    }
    
    function plugin_active() {
        return is_plugin_active( $this->plugin_path() );
    }

    function plugin_path() {
        return $this->slug . '/' . $this->slug . '.php';
    }
    
    function url($base = false) {
        if (!$base) {
            $base = $_SERVER['REQUEST_URI'];
        }

        return add_query_arg(
            array( 
                'plugin-install-action' => 'install',
                '_wpnonce' => wp_create_nonce( 'plugin-install-' . $this->name )
            ),
            $base
        );
    }

    function called() {
        if (isset($_REQUEST['plugin-install-action']) && $_REQUEST['plugin-install-action'] == 'install') {
            if ( $this->plugin_active() )
                return false;
            
            // Bad nonce
            if ( !wp_verify_nonce( $_REQUEST[ '_wpnonce' ], 'plugin-install-' . $this->name ) )
                 wp_die( 'Unauthorized' );

             return true;
        }

        return false;
    }

    function install_and_activate() {

        $plugins = get_plugins();

        if ( !isset( $plugins[ $this->plugin_path() ] ) ) :
            $this->install();
        endif; //end install process

        $activate = activate_plugin( $this->plugin_path() );
        
        if ( is_wp_error( $activate ) ) :
            return $this->add_error($activate->get_error_message());
        else :
            if ($this->redirect) :
                return wp_redirect( $this->redirect );
            else :
                return wp_redirect( 'plugins.php' );
            endif;
        endif;
    }
    
    function install() {
         
        $plugin = array(
            'name' => $this->name,
            'slug' => $this->slug,
        );

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php'; // Need for plugins_api

        $api = plugins_api( 'plugin_information', array( 'slug' => $plugin['slug'], 'fields' => array( 'sections' => false ) ) );

        if ( is_wp_error( $api ) ) :
            return $this->add_error( $api->get_error_message() );
        elseif ( isset( $api->download_link ) ) :
            $plugin['source'] = $api->download_link;
        else :
            return $this->add_error( 'Error trying to download ' . $plugin['name'] );
        endif;

        /** Set type, based on whether the source starts with http:// or https:// */
        $type = preg_match( '|^http(s)?://|', $plugin['source'] ) ? 'web' : 'upload';

        /** Prep variables for Plugin_Installer_Skin class */
        $title = sprintf( 'Installing %s', $plugin['name'] );
        $url   = add_query_arg( array( 'action' => 'install-plugin', 'plugin' => $plugin['slug'] ), 'update.php' );
        if ( isset( $_GET['from'] ) )
            $url .= add_query_arg( 'from', urlencode( stripslashes( $_GET['from'] ) ), $url );

        $nonce = 'install-plugin_' . $plugin['slug'];

        $source = $plugin['source'];

        /** Load the upgrader class that we'll use to upgrade */
        require_once 'upgrader.php';

        /** Create a new instance of Plugin_Upgrader */
        $upgrader = new Plugin_Upgrader( $skin = new Silent_Plugin_Installer_Skin( compact( 'type', 'title', 'url', 'nonce', 'plugin', 'api' ) ) );

        /** Perform the action and install the plugin from the $source urldecode() */
        $upgrader->install( $source );

        if (!empty($skin->errors)) {
            return $this->add_error($skin->errors);
        }

        /** Flush plugins cache so we can make sure that the installed plugins list is always up to date */
        wp_cache_flush();
    }

    function add_error($error) {
        if (!is_array($error)) {
            $error = array( $error );
        }

        $this->install_errors = array( $error );
        add_action( 'admin_notices', array( &$this, 'install_errors' ) );

        return true;
    }

    function install_errors() {
        foreach ($this->install_errors as $error) {
            echo '<div class="error fade"><p>Something went wrong activating ' . $this->name . ': <strong>' . __( $error ) . '</strong></p></div>';
        }
    }
}
