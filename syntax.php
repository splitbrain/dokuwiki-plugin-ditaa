<?php
/**
 * Ditaa-Plugin: Converts Ascii-Flowcharts into a png-File
 *
 * @license     GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author      Dennis Ploeger <develop [at] dieploegers [dot] de>
 * @author      Christoph Mertins <c [dot] mertins [at] gmail [dot] com>
 * @author      Gerry Weißbach / i-net software <tools [at] inetsoftware [dot] de>
 * @author      Christian Marg <marg@rz.tu-clausthal.de>
 * @author      Andreas Gohr <andi@splitbrain.org>
 */

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../../') . '/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once(DOKU_PLUGIN . 'syntax.php');

/**
 * Class syntax_plugin_ditaa
 */
class syntax_plugin_ditaa extends DokuWiki_Syntax_Plugin {

    /**
     * What about paragraphs?
     */
    public function getPType() {
        return 'normal';
    }

    /**
     * What kind of syntax are we?
     */
    public function getType() {
        return 'substition';
    }

    /**
     * Where to sort in?
     */
    public function getSort() {
        return 200;
    }

    /**
     * Connect pattern to lexer
     *
     * @param string $mode
     */
    public function connectTo($mode) {
        $this->Lexer->addSpecialPattern('<ditaa.*?>\n.*?\n</ditaa>', $mode, 'plugin_ditaa');
    }

    /**
     * Stores all infor about the diagram in two files. One is the actual ditaa data, the other
     * contains the options.
     *
     * @param   string $match The text matched by the patterns
     * @param   int $state The lexer state for the match
     * @param   int $pos The character position of the matched text
     * @param   Doku_Handler $handler The Doku_Handler object
     * @return  bool|array Return an array with all data you want to use in render, false don't add an instruction
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        $info = $this->getInfo();

        // prepare default data
        $return = array(
            'width' => 0,
            'height' => 0,
            'antialias' => true,
            'edgesep' => true,
            'round' => false,
            'shadow' => true,
            'scale' => 1,
            'align' => '',
            'version' => $info['date'], //force rebuild of images on update
        );

        // prepare input
        $lines = explode("\n", $match);
        $conf = array_shift($lines);
        array_pop($lines);

        // match config options
        if(preg_match('/\b(left|center|right)\b/i', $conf, $match)) $return['align'] = $match[1];
        if(preg_match('/\b(\d+)x(\d+)\b/', $conf, $match)) {
            $return['width'] = $match[1];
            $return['height'] = $match[2];
        }
        if(preg_match('/\b(\d+(\.\d+)?)X\b/', $conf, $match)) $return['scale'] = $match[1];
        if(preg_match('/\bwidth=([0-9]+)\b/i', $conf, $match)) $return['width'] = $match[1];
        if(preg_match('/\bheight=([0-9]+)\b/i', $conf, $match)) $return['height'] = $match[1];
        // match boolean toggles
        if(preg_match_all('/\b(no)?(antialias|edgesep|round|shadow)\b/i', $conf, $matches, PREG_SET_ORDER)) {
            foreach($matches as $match) {
                $return[$match[2]] = !$match[1];
            }
        }

        $input = join("\n", $lines);
        $return['md5'] = md5($input); // we only pass a hash around

        // store input for later use in _imagefile()
        io_saveFile(getCacheName($return['md5'], '.ditaa.txt'), $input);
        io_saveFile(getCacheName($return['md5'], '.ditaa.cfg'), serialize($return));

        return $return;
    }

    /**
     * Output the image
     *
     * @param string $format output format being rendered
     * @param Doku_Renderer $R the current renderer object
     * @param array $data data created by handler()
     * @return  boolean                 rendered correctly?
     */
    public function render($format, Doku_Renderer $R, $data) {
        global $ID;
        if($format == 'xhtml') {
            // Only use the md5 key
            $img = ml($ID, array('ditaa' => $data['md5']));
            $R->doc .= '<img src="' . $img . '" class="media' . $data['align'] . '" alt=""';
            if($data['width']) $R->doc .= ' width="' . $data['width'] . '"';
            if($data['height']) $R->doc .= ' height="' . $data['height'] . '"';
            if($data['align'] == 'right') $R->doc .= ' align="right"';
            if($data['align'] == 'left') $R->doc .= ' align="left"';
            $R->doc .= '/>';
            return true;
        } else if($format == 'odt') {
            $src = $this->_imgfile($data['md5']);
            /** @var  renderer_plugin_odt $R */
            $R->_odtAddImage($src, $data['width'], $data['height'], $data['align']);
            return true;
        }
        return false;
    }

    /**
     * Return path to the rendered image on our local system
     *
     * @param string $md5 MD5 of the input data, used to identify the cache files
     * @return false|string path to file or fals on error
     */
    public function _imgfile($md5) {
        $file_cfg = getCacheName($md5, '.ditaa.cfg'); // configs
        $file_txt = getCacheName($md5, '.ditaa.txt'); // input
        $file_png = getCacheName($md5, '.ditaa.png'); // ouput

        if(!file_exists($file_cfg) || !file_exists($file_txt)) {
            return false;
        }
        $data = unserialize(io_readFile($file_cfg, false));

        // file does not exist or is outdated
        if(@filemtime($file_png) < filemtime($file_cfg)) {

            if($this->getConf('java')) {
                $ok = $this->_runJava($data, $file_txt, $file_png);
            } else {
                $ok = $this->_runGo($data, $file_txt, $file_png);
                #$ok = $this->_remote($data, $in, $cache);
            }
            if(!$ok) return false;

            clearstatcache($file_png);
        }

        // resized version
        if($data['width']) {
            $file_png = media_resize_image($file_png, 'png', $data['width'], $data['height']);
        }

        // something went wrong, we're missing the file
        if(!file_exists($file_png)) return false;

        return $file_png;
    }

    /**
     * Render the output remotely at ditaa.org
     *
     * @deprecated ditaa.org is no longer available, so this defunct
     * @param array $data The config settings
     * @param string $in Path to the ditaa input file (txt)
     * @param string $out Path to the output file (PNG)
     * @return bool true if the image was created, false otherwise
     */
    protected function _remote($data, $in, $out) {
        global $conf;

        if(!file_exists($in)) {
            if($conf['debug']) {
                dbglog($in, 'no such ditaa input file');
            }
            return false;
        }

        $http = new DokuHTTPClient();
        $http->timeout = 30;

        $pass = array();
        $pass['scale'] = $data['scale'];
        $pass['timeout'] = 25;
        $pass['grid'] = io_readFile($in);
        if(!$data['antialias']) $pass['A'] = 'on';
        if(!$data['shadow']) $pass['S'] = 'on';
        if($data['round']) $pass['r'] = 'on';
        if(!$data['edgesep']) $pass['E'] = 'on';

        $img = $http->post('http://ditaa.org/ditaa/render', $pass);
        if(!$img) return false;

        return io_saveFile($out, $img);
    }

    /**
     * Run the ditaa Java program
     *
     * @param array $data The config settings
     * @param string $in Path to the ditaa input file (txt)
     * @param string $out Path to the output file (PNG)
     * @return bool true if the image was created, false otherwise
     */
    protected function _runJava($data, $in, $out) {
        global $conf;

        if(!file_exists($in)) {
            if($conf['debug']) {
                dbglog($in, 'no such ditaa input file');
            }
            return false;
        }

        $cmd = $this->getConf('java');
        $cmd .= ' -Djava.awt.headless=true -Dfile.encoding=UTF-8 -jar';
        $cmd .= ' ' . escapeshellarg(dirname(__FILE__) . '/ditaa/ditaa0_9.jar'); //ditaa jar
        $cmd .= ' --encoding UTF-8';
        $cmd .= ' ' . escapeshellarg($in); //input
        $cmd .= ' ' . escapeshellarg($out); //output
        $cmd .= ' -s ' . escapeshellarg($data['scale']);
        if(!$data['antialias']) $cmd .= ' -A';
        if(!$data['shadow']) $cmd .= ' -S';
        if($data['round']) $cmd .= ' -r';
        if(!$data['edgesep']) $cmd .= ' -E';

        exec($cmd, $output, $error);

        if($error != 0) {
            if($conf['debug']) {
                dbglog(join("\n", $output), 'ditaa command failed: ' . $cmd);
            }
            return false;
        }

        return true;
    }

    /**
     * Run the ditaa Go program
     *
     * @param array $data The config settings - currently not used because the Go relase supports no options
     * @param string $in Path to the ditaa input file (txt)
     * @param string $out Path to the output file (PNG)
     * @return bool true if the image was created, false otherwise
     */
    protected function _runGo($data, $in, $out) {
        global $conf;

        if(!file_exists($in)) {
            if($conf['debug']) {
                dbglog($in, 'no such ditaa input file');
            }
            return false;
        }

        $cmd = $this->getLocalBinary();
        if(!$cmd) return false;
        $cmd .= ' ' . escapeshellarg($in); //input
        $cmd .= ' ' . escapeshellarg($out); //output

        exec($cmd, $output, $error);

        if($error != 0) {
            if($conf['debug']) {
                dbglog(join("\n", $output), 'ditaa command failed: ' . $cmd);
            }
            return false;
        }

        return true;
    }

    /**
     * Detects the platform of the PHP host and constructs the appropriate binary name
     *
     * @return false|string
     */
    protected function getBinaryName() {
        $ext = '';

        $os = php_uname('s');
        if(preg_match('/darwin/i', $os)) {
            $os = 'darwin';
        } elseif(preg_match('/win/i', $os)) {
            $os = 'windows';
            $ext = '.exe';
        } elseif(preg_match('/linux/i', $os)) {
            $os = 'linux';
        } elseif(preg_match('/freebsd/i', $os)) {
            $os = 'freebsd';
        } elseif(preg_match('/openbsd/i', $os)) {
            $os = 'openbsd';
        } elseif(preg_match('/netbsd/i', $os)) {
            $os = 'netbsd';
        } elseif(preg_match('/(solaris|netbsd)/i', $os)) {
            $os = 'freebsd';
        } else {
            return false;
        }

        $arch = php_uname('m');
        if($arch == 'x86_64') {
            $arch = 'amd64';
        } elseif(preg_match('/arm/i', $arch)) {
            $arch = 'amd';
        } else {
            $arch = '386';
        }

        return "ditaa-$os-$arch$ext";
    }

    /**
     * Returns the local binary to use
     *
     * Downloads it if necessary
     *
     * @return bool|string
     */
    protected function getLocalBinary() {
        global $conf;

        $bin = $this->getBinaryName();
        if(!$bin) return false;

        // check distributed files first
        if(file_exists(__DIR__ . '/ditaa/' . $bin)) {
            return __DIR__ . '/ditaa/' . $bin;
        }

        $info = $this->getInfo();
        $cache = getCacheName($info['date'], ".$bin");

        if(file_exists($cache)) return $cache;

        $url = 'https://github.com/akavel/ditaa/releases/download/g1.0.0/' . $bin;
        if(io_download($url, $cache, false, '', 0)) {
            @chmod($cache, $conf['dmode']);
            return $cache;
        }

        return false;
    }
}

