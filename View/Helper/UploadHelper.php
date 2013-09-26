<?php
App::uses('UploadBehavior', 'UploadPack.Model/Behavior');
/**
 * This file is a part of UploadPack - a plugin that makes file uploads in CakePHP as easy as possible.
 *
 * UploadHelper
 *
 * UploadHelper provides fine access to files uploaded with UploadBehavior. It generates url for those files and can display image tags of uploaded images. For more info read UploadPack documentation.
 *
 * @author Michał Szajbe (michal.szajbe@gmail.com)
 * @link http://github.com/szajbus/uploadpack
 */
class UploadHelper extends AppHelper {

    public $helpers = array('Html');

    public function uploadImage($data, $path, $options = array(), $htmlOptions = array())
    {
        $options += array('urlize' => false);
        return $this->output($this->Html->image($this->uploadUrl($data, $path, $options), $htmlOptions));
    }

    public function uploadLink($title, $data, $field, $urlOptions = array(), $htmlOptions = array())
    {
        $urlOptions += array('style' => 'original', 'urlize' => true);
        return $this->Html->link($title, $this->uploadUrl($data, $field, $urlOptions), $htmlOptions);
    }

    public function uploadUrl($data, $field, $options = array())
    {
        $options += array('style' => 'original', 'urlize' => true);
        list($model, $field) = explode('.', $field);
        if(is_array($data))
        {
            if(isset($data[$model]))
            {
                if(isset($data[$model]['id']))
                {
                    $id = $data[$model]['id'];
                    $filename = $data[$model][$field.'_file_name'];
                }
            }
            elseif(isset($data['id']))
            {
                $id = $data['id'];
                $filename = $data[$field.'_file_name'];
            }
        }

        if(isset($id) && !empty($filename))
        {
            $settings = UploadBehavior::interpolate($model, $id, $field, $filename, $options['style'], array('webroot' => ''));
            $url = isset($settings['url']) ? $settings['url'] : $settings['path'];
        }
        else
        {
            $settings = UploadBehavior::interpolate($model, null, $field, null, $options['style'], array('webroot' => ''));
            $url = isset($settings['default_url']) ? $settings['default_url'] : null;
        }

        return $options['urlize'] ? $this->Html->url($url) : $url;
    }

    /**
     * Returns appropriate extension for given mimetype.
     *
     * @param string $mime Mimetype
     * @return void
     * @author Bjorn Post
     */
    public function extension($mimeType = null)
    {
        $knownMimeTypes = array(
            'ai' => 'application/postscript', 'bcpio' => 'application/x-bcpio', 'bin' => 'application/octet-stream',
            'ccad' => 'application/clariscad', 'cdf' => 'application/x-netcdf', 'class' => 'application/octet-stream',
            'cpio' => 'application/x-cpio', 'cpt' => 'application/mac-compactpro', 'csh' => 'application/x-csh',
            'csv' => 'application/csv', 'dcr' => 'application/x-director', 'dir' => 'application/x-director',
            'dms' => 'application/octet-stream', 'doc' => 'application/msword', 'drw' => 'application/drafting',
            'dvi' => 'application/x-dvi', 'dwg' => 'application/acad', 'dxf' => 'application/dxf', 'dxr' => 'application/x-director',
            'eps' => 'application/postscript', 'exe' => 'application/octet-stream', 'ez' => 'application/andrew-inset',
            'flv' => 'video/x-flv', 'gtar' => 'application/x-gtar', 'gz' => 'application/x-gzip',
            'bz2' => 'application/x-bzip', '7z' => 'application/x-7z-compressed', 'hdf' => 'application/x-hdf',
            'hqx' => 'application/mac-binhex40', 'ips' => 'application/x-ipscript', 'ipx' => 'application/x-ipix',
            'js' => 'application/x-javascript', 'latex' => 'application/x-latex', 'lha' => 'application/octet-stream',
            'lsp' => 'application/x-lisp', 'lzh' => 'application/octet-stream', 'man' => 'application/x-troff-man',
            'me' => 'application/x-troff-me', 'mif' => 'application/vnd.mif', 'ms' => 'application/x-troff-ms',
            'nc' => 'application/x-netcdf', 'oda' => 'application/oda', 'pdf' => 'application/pdf',
            'pgn' => 'application/x-chess-pgn', 'pot' => 'application/mspowerpoint', 'pps' => 'application/mspowerpoint',
            'ppt' => 'application/mspowerpoint', 'ppz' => 'application/mspowerpoint', 'pre' => 'application/x-freelance',
            'prt' => 'application/pro_eng', 'ps' => 'application/postscript', 'roff' => 'application/x-troff',
            'scm' => 'application/x-lotusscreencam', 'set' => 'application/set', 'sh' => 'application/x-sh',
            'shar' => 'application/x-shar', 'sit' => 'application/x-stuffit', 'skd' => 'application/x-koan',
            'skm' => 'application/x-koan', 'skp' => 'application/x-koan', 'skt' => 'application/x-koan',
            'smi' => 'application/smil', 'smil' => 'application/smil', 'sol' => 'application/solids',
            'spl' => 'application/x-futuresplash', 'src' => 'application/x-wais-source', 'step' => 'application/STEP',
            'stl' => 'application/SLA', 'stp' => 'application/STEP', 'sv4cpio' => 'application/x-sv4cpio',
            'sv4crc' => 'application/x-sv4crc', 'svg' => 'image/svg+xml', 'svgz' => 'image/svg+xml',
            'swf' => 'application/x-shockwave-flash', 't' => 'application/x-troff',
            'tar' => 'application/x-tar', 'tcl' => 'application/x-tcl', 'tex' => 'application/x-tex',
            'texi' => 'application/x-texinfo', 'texinfo' => 'application/x-texinfo', 'tr' => 'application/x-troff',
            'tsp' => 'application/dsptype', 'unv' => 'application/i-deas', 'ustar' => 'application/x-ustar',
            'vcd' => 'application/x-cdlink', 'vda' => 'application/vda', 'xlc' => 'application/vnd.ms-excel',
            'xll' => 'application/vnd.ms-excel', 'xlm' => 'application/vnd.ms-excel', 'xls' => 'application/vnd.ms-excel',
            'xlw' => 'application/vnd.ms-excel', 'zip' => 'application/zip', 'aif' => 'audio/x-aiff', 'aifc' => 'audio/x-aiff',
            'aiff' => 'audio/x-aiff', 'au' => 'audio/basic', 'kar' => 'audio/midi', 'mid' => 'audio/midi',
            'midi' => 'audio/midi', 'mp2' => 'audio/mpeg', 'mp3' => 'audio/mpeg', 'mpga' => 'audio/mpeg',
            'ra' => 'audio/x-realaudio', 'ram' => 'audio/x-pn-realaudio', 'rm' => 'audio/x-pn-realaudio',
            'rpm' => 'audio/x-pn-realaudio-plugin', 'snd' => 'audio/basic', 'tsi' => 'audio/TSP-audio', 'wav' => 'audio/x-wav',
            'asc' => 'text/plain', 'c' => 'text/plain', 'cc' => 'text/plain', 'css' => 'text/css', 'etx' => 'text/x-setext',
            'f' => 'text/plain', 'f90' => 'text/plain', 'h' => 'text/plain', 'hh' => 'text/plain', 'htm' => 'text/html',
            'html' => 'text/html', 'm' => 'text/plain', 'rtf' => 'text/rtf', 'rtx' => 'text/richtext', 'sgm' => 'text/sgml',
            'sgml' => 'text/sgml', 'tsv' => 'text/tab-separated-values', 'tpl' => 'text/template', 'txt' => 'text/plain',
            'xml' => 'text/xml', 'avi' => 'video/x-msvideo', 'fli' => 'video/x-fli', 'mov' => 'video/quicktime',
            'movie' => 'video/x-sgi-movie', 'mpe' => 'video/mpeg', 'mpeg' => 'video/mpeg', 'mpg' => 'video/mpeg',
            'qt' => 'video/quicktime', 'viv' => 'video/vnd.vivo', 'vivo' => 'video/vnd.vivo', 'gif' => 'image/gif',
            'ief' => 'image/ief', 'jpe' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg',
            'pbm' => 'image/x-portable-bitmap', 'pgm' => 'image/x-portable-graymap', 'png' => 'image/png',
            'pnm' => 'image/x-portable-anymap', 'ppm' => 'image/x-portable-pixmap', 'ras' => 'image/cmu-raster',
            'rgb' => 'image/x-rgb', 'tif' => 'image/tiff', 'tiff' => 'image/tiff', 'xbm' => 'image/x-xbitmap',
            'xpm' => 'image/x-xpixmap', 'xwd' => 'image/x-xwindowdump', 'ice' => 'x-conference/x-cooltalk',
            'iges' => 'model/iges', 'igs' => 'model/iges', 'mesh' => 'model/mesh', 'msh' => 'model/mesh',
            'silo' => 'model/mesh', 'vrml' => 'model/vrml', 'wrl' => 'model/vrml',
            'mime' => 'www/mime', 'pdb' => 'chemical/x-pdb', 'xyz' => 'chemical/x-pdb'
        );

        return array_search($mimeType, $knownMimeTypes);
    }
}
