<?php

/**
 * Ditaa-Plugin: Converts Ascii-Flowcharts into a png-File
 *
 * @license     GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author      Dennis Ploeger <develop [at] dieploegers [dot] de>
 * @author      Christoph Mertins <c [dot] mertins [at] gmail [dot] com>
 * @author      Andreas Gohr <andi@splitbrain.org>
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
     * Connect pattern to lexer
     */

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<ditaa.*?>\n.*?\n</ditaa>',$mode,'plugin_ditaa');
    }



    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler) {
        // prepare default data
        $return = array(
                        'data'      => '',
                        'width'     => 0,
                        'height'    => 0,
                        'antialias' => true,
                        'edgesep'   => true,
                        'round'     => false,
                        'shadow'    => true,
                        'scale'     => 1,
                        'align'     => '',
                       );


        // prepare input
        $lines = explode("\n",$match);
        $conf = array_shift($lines);
        array_pop($lines);

        // match config options
        if(preg_match('/\b(left|center|right)\b/i',$conf,$match)) $return['align'] = $match[1];
        if(preg_match('/\b(\d+)x(\d+)\b/',$conf,$match)){
            $return['width']  = $match[1];
            $return['height'] = $match[2];
        }
        if(preg_match('/\b(\d+)X\b/',$conf,$match)) $return['scale']  = $match[1];
        if(preg_match('/\bwidth=([0-9]+)\b/i', $conf,$match)) $return['width'] = $match[1];
        if(preg_match('/\bheight=([0-9]+)\b/i', $conf,$match)) $return['height'] = $match[1];
        // match boolean toggles
        if(preg_match_all('/\b(no)?(antialias|edgesep|round|shadow)\b/i',$conf,$matches,PREG_SET_ORDER)){
            foreach($matches as $match){
                $return[$match[2]] = ! $match[1];
            }
        }

        $return['data'] = join("\n",$lines);

        return $return;
    }

    /**
     * Create output
     */
    function render($format, &$R, $data) {
        if($format != 'xhtml') return;

        // prepare data for ditaa.org
        $pass = array(
            'grid'  => $data['data'],
            'scale' => $data['scale']
        );
        if(!$data['antialias']) $pass['A'] = 'on';
        if(!$data['shadow'])    $pass['S'] = 'on';
        if($data['round'])      $pass['r'] = 'on';
        if(!$data['edgesep'])   $pass['E'] = 'on';
        $pass['timeout'] = 25;

        $img = 'http://ditaa.org/ditaa/render?'.buildURLparams($pass,'&');
        $img = ml($img,array('w'=>$data['width'],'h'=>$data['height']));

        $R->doc .= '<img src="'.$img.'" alt="x">';

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



