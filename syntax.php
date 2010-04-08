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
        $info = $this->getInfo();

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
                        'version'   => $info['date'], //forece rebuild of images on update
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
        if(preg_match('/\b(\d+(\.\d+)?)X\b/',$conf,$match)) $return['scale']  = $match[1];
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

        if($this->getConf('java')){
            // run ditaa on our own server
            $img = DOKU_BASE.'lib/plugins/ditaa/ditaa.php?'.buildURLparams($data,'&');
        }else{
            // use ditaa.org for rendering
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
        }

        $R->doc .= '<img src="'.$img.'" alt="x">';
    }


    /**
     * Run the ditaa Java program
     */
    function _run($data,$cache) {
        global $conf;

        $temp = tempnam($conf['tmpdir'],'ditaa_');
        io_saveFile($temp,$data['data']);

        $cmd  = $this->getConf('java');
        $cmd .= ' -Djava.awt.headless=true -jar';
        $cmd .= ' '.escapeshellarg(dirname(__FILE__).'/ditaa/ditaa0_9.jar'); //ditaa jar
        $cmd .= ' '.escapeshellarg($temp); //input
        $cmd .= ' '.escapeshellarg($cache); //output
        $cmd .= ' -s '.escapeshellarg($data['scale']);
        if(!$data['antialias']) $cmd .= ' -A';
        if(!$data['shadow'])    $cmd .= ' -S';
        if($data['round'])      $cmd .= ' -r';
        if(!$data['edgesep'])   $cmd .= ' -E';

        exec($cmd, $output, $error);
        @unlink($temp);

        if ($error != 0) return false;
        return true;
    }

}



