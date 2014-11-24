<?php
/**
 * Siteexport SendFile Plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     i-net software <tools@inetsoftware.de>
 * @author     Gerry Weissbach <gweissbach@inetsoftware.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once(DOKU_PLUGIN.'action.php');

class action_plugin_ditaa extends DokuWiki_Action_Plugin {
    
    public function register(Doku_Event_Handler $controller) {
        // Download of a file
        
        $controller->register_hook('MEDIA_SENDFILE', 'BEFORE', $this, 'ditaa_sendfile');
        $controller->register_hook('FETCH_MEDIA_STATUS', 'BEFORE', $this, 'ditaa_sendfile_not_found');
    }

    /*
     * Redirect File to real File
     */
    function ditaa_sendfile(&$event, $args) {
        global $conf;

        if ( empty( $_REQUEST['ditaa'] ) ) {
            return;
        }
        
        $plugin = plugin_load( 'syntax', 'ditaa' );
        $data = p_get_metadata( $event->data['media'], 'ditaa' );
        $event->data['file'] = $plugin->_imgfile($event->data['media'], $data[$_REQUEST['ditaa']]);
        $event->data['mime'] = 'image/png';
        
        if( !$event->data['file'] ) {
            $event->data['file'] = dirname(__FILE__) . '/broken.png';
            $event->data['status'] = 404;
            $event->data['statusmessage'] = 'Not Found';
        }

        header('Expires: '.gmdate("D, d M Y H:i:s", time()+max($conf['cachetime'], 3600)).' GMT');
        header('Cache-Control: public, proxy-revalidate, no-transform, max-age='.max($conf['cachetime'], 3600));
        header('Pragma: public');
    }

    /*
     * If a file has not been found yet, we should try to check if this can be solved
     * via the ditaa renderer
     */
    function ditaa_sendfile_not_found(&$event, $args)
    {
        if ( $event->data['status'] >= 500 || empty( $_REQUEST['ditaa'] ) ) { return true; }
        $event->data['status'] = 200;
        $event->data['statusmessage'] = 'OK';
        return true;
    }
}