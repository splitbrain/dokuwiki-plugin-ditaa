<?php

/**
 * Ditaa-Plugin: Converts Ascii-Flowcharts into a png-File
 *
 * @license     GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author      Dennis Ploeger <develop [at] dieploegers [dot] de>
 * @author      Christoph Mertins <c [dot] mertins [at] gmail [dot] com>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_ditaa extends DokuWiki_Syntax_Plugin {

    var $ditaa_name = '';

    var $ditaa_width = -1;

    var $ditaa_height = -1;

    var $ditaa_data = '';

    var $pathToJava = "/opt/blackdown-jdk-1.4.2.02/bin/java"; 

    var $pathToDitaa = "/var/www/sst.intern.editable/dokuwiki/htdocs/ditaa.jar";

    var $tempdir = "/tmp";

    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Dennis Ploeger',
            'email'  => 'develop@dieploegers.de',
            'date'   => '2009-05-19',
            'name'   => 'Ditaa-Plugin',
            'desc'   => 'Renders ascii-flowcharts contained in a dokuwiki-page to a png, that is displayed instead',
            'url'    => 'http://wiki.splitbrain.org/plugin:ditaa',
        );
    }

    /**
     * What about paragraphs?
     */
    function getPType(){
        return 'normal';
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    /**
     * Where to sort in?
     */ 
    function getSort(){
        return 200;
    }


    /**
     * Connect pattern to lexer (Beginning of parsing)
     */

    function connectTo($mode) {
        $this->Lexer->addEntryPattern('<ditaa.*?>(?=.*?\x3C/ditaa\x3E)', $mode, 'plugin_ditaa');
    }

    function postConnect() {
        $this->Lexer->addExitPattern('</ditaa>', 'plugin_ditaa');
    }


    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler)
    {

        switch ($state) {
            case DOKU_LEXER_ENTER:
                    preg_match('/width=([0-9]+)/i', substr($match,6,-1), $match_width);
                    preg_match('/height=([0-9]+)/i', substr($match,6,-1), $match_height);
                    preg_match('/name=([a-zA-Z_0-9]+)/i', substr($match,6,-1), $match_name);
                    return array('begin', $match_name[1], $match_width[1], $match_height[1]);
                    break;
            case DOKU_LEXER_EXIT:
                    return array('end');
                    break;
            case DOKU_LEXER_UNMATCHED:
                    return array('data', $match);
                    break;

        }
    }

    /**
     * Create output
     */
    function render($format, &$renderer, $data) 
    {

        global $conf;

        if ($data[0] == 'begin') {

            list($state, $name, $width, $height) = $data;

        } else if ($data[0] == 'data') {

            list($state, $mydata) = $data;

        } else {

            $state = $data[0];

        }

        switch($state) {

            case 'begin': return $this->_ditaa_begin($renderer, $name, $width, $height);
            case 'data' : return $this->_ditaa_data($mydata);
            case 'end'  : return $this->_ditaa_end($renderer);

        }

    }

    /**
     * Store values for later ditaa-rendering
     *
     * @param object    $renderer The dokuwiki-renderer 
     * @param string    $name   The name for the ditaa-object
     * @param width     $width  The width for the ditaa-object
     * @param height    $height The height for the ditaa-object
     * @return  bool            All parameters are set
     */

    function _ditaa_begin(&$renderer, $name, $width, $height)
    {
        // Check, if name is given

        $name = trim(strtolower($name));

        if ($name == '') {

            $renderer->doc .= '---NO NAME FOR FLOWCHART GIVEN---';
            return true;

        }

        $width = trim($width);
        $height = trim($height);

        if (($width != '') && (settype($width, 'int'))) {

            $this->ditaa_width = $width;

        }

        if (($height != '') && (settype($height, 'int'))) {

            $this->ditaa_height = $height;

        }

        $this->ditaa_name = $name;

        $this->ditaa_data = '';

        return true;

    }

    /**
     * Expand the data for the ditaa-object
     *
     * @param   string  $data   The data for the ditaa-object
     * @return  bool            If everything was right
     */

    function _ditaa_data($data)
    {

        $this->ditaa_data .= $data;

        return true;

    }

    /**
     * Render the ditaa-object
     *
     * @param object    $renderer   The dokuwiki-Renderer
     * @return  bool                If everything was right
     */

    function _ditaa_end(&$renderer)
    {
        global $conf, $INFO;

        // Write a text file for ditaa

        $tempfile = tempnam($this->tempdir, 'ditaa_');

        $file = fopen($tempfile.'.txt', 'w');
        fwrite($file, $this->ditaa_data);
        fclose($file);

        $md5 = md5_file($tempfile.'.txt');

        $mediadir = $conf["mediadir"]."/".str_replace(":", "/",$INFO['namespace'] );
        
        if (!is_dir($mediadir)) {
            umask(002);
            mkdir($mediadir,0777);
        }

        $imagefile = $mediadir.'/ditaa_'.$this->ditaa_name.'_'.$md5.'.png';

        if ( !file_exists($imagefile)) {
            
            $cmd = $this->pathToJava." -Djava.awt.headless=true -jar ".$this->pathToDitaa." ".$tempfile.".txt ".$tempfile.".png";

            exec($cmd, $output, $error);
            
            if ($error != 0) {
                $renderer->doc .= '---ERROR CONVERTING DIAGRAM---';
                   return false;
            }

            if (file_exists($imagefile)) {
                unlink($imagefile);
            }

            if ( !copy($tempfile.'.png', $imagefile) ) {
                return false;
            }
    
            // Remove input file
            unlink($tempfile.'.png');
            unlink($tempfile);
        }

        unlink($tempfile.'.txt');

        // Output Img-Tag

        $width = NULL;

        if ($this->ditaa_width != -1) {
            $width = $this->ditaa_width;
        }

        $height = NULL;

        if ($this->ditaa_height != -1) {
            $height = $this->ditaa_height;
        }

        $renderer->doc .= $renderer->internalmedia($INFO['namespace'].':ditaa_'.$this->ditaa_name.'_'.$md5.'.png', $this->ditaa_name, NULL, $width, $height, false); 

        return true;
        
    }

}




?>
