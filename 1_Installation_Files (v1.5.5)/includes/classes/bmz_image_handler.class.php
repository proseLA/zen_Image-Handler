<?php
/**
 * mod Image Handler 5.2.1
 * bmz_image_handler.class.php
 * IH5 class for image manipulation
 *
 * @author  Tim Kroeger (original author)
 * @copyright Copyright 2005-2018
 * @license http://www.gnu.org/licenses/gpl.txt GNU General Public License V2.0
 * @version $Id: bmz_image_handler.class.php,v 2.0 Rev 8 2010-05-31 23:46:5 DerManoMann Exp $
 * modified by yellow1912 (rubikintegration.com)
 * Modified by DerManoMann 2010-05-31 23:40:21
 * Modified by lat9: 2017-07-17, correcting class constructor name, applying PSR-2 formatting.
 * Modified by lat9: 2018-05-19, various refinements (see GitHub #106).
 * Modified by lat9: 2018-05-20, Remove handling for mixed-case file extensions from file_not_found method (see GitHub #89)
 * Modified by lat9: 2018-06-04, Correction for DIR_FS_CATALOG set to '/'.
 * Modified by brittainmark: 2020-10-18, Add Mirrored to mirror original directory structure (see GitHub #72)
 * Modified by lat9/proseLA: 2021-01-19,
 *    - Correcting PHP 8.0 fatal error (see GitHub#212)
 *    - PHP 8.1+ interoperation (changing use of $GLOBALS to simply global), see GitHub#215.
 */

if (!defined('IH_DEBUG_ADMIN')) {
    define('IH_DEBUG_ADMIN', 'false');
}
//note these logging constants are set/defined in includes\extra_datafiles\image_handler_logging.php
if (!defined('IH_DEBUG_STOREFRONT')) {
    define('IH_DEBUG_STOREFRONT', 'false');
}
if (!defined('IS_ADMIN_FLAG')) {
    exit('Illegal access');
}

class ih_image
{
    public $canvas;
    public $debug;
    public $debugLogFile;
    public $extension;
    public $file_exists;
    public $filename;
    public $first_access;
    public $force_canvas;
    public $height;
    public $local = null;  // cached image reference
    public $orig;          // original image source passed to the constructor
    public $sizetype;
    public $src;           // reference to an actual physical image
    public $watermark;
    public $width;

    /**
     * ih_image class constructor
     * @author Tim Kroeger (tim@breakmyzencart.com)
     * @author Cindy Merkin (lat9)
     * @version 5.0.1
     * @param string $src Image source (e.g. - images/productimage.jpg)
     * @param string $width The image's width
     * @param string $height The image's height
     */

    public function __construct($src, $width, $height)
    {
        global $ihConf, $ih_logfile_suffix;

        $this->orig = $src;
        $this->src = $src;
        $this->width = $width;
        $this->height = $height;
        $this->watermark = [];

        // -----
        // Initially, **assume** that the requested file exists.  If not, this flag will be set to
        // false by call to the calculate_size method.
        //
        $this->file_exists = true;

        $this->first_access = false;
        if (!isset($ih_logfile_suffix)) {
            $d = new DateTime();
            $ih_logfile_suffix = $d->format('Ymd-His.u');
            $this->first_access = true;
        }
        $logfile_suffix = $ih_logfile_suffix;

        if (IS_ADMIN_FLAG === true) {
            $this->debug = (IH_DEBUG_ADMIN === 'true');
            $this->debugLogFile = DIR_FS_LOGS . "/ih_debug_admin-$logfile_suffix.log";
        } else {
            $this->debug = (IH_DEBUG_STOREFRONT === 'true');
            $this->debugLogFile = DIR_FS_LOGS . "/ih_debug-$logfile_suffix.log";
        }

        $this->determine_image_sizetype();

        if (($this->sizetype === 'large' || $this->sizetype === 'medium') && $this->file_not_found()) {
            // large or medium image specified but not found: strip superfluous suffix to get base image name referenced in the database.
            $this->src = $this->strip_sizetype_suffix($this->src);
        }

        $this->filename = $ihConf['dir']['docroot'] . $this->src;
        $this->extension = '.' . pathinfo($this->src, PATHINFO_EXTENSION);

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        $caller = $backtrace[0]['file'];
        if (strlen(DIR_FS_CATALOG) > 1) {
            $caller = str_replace(DIR_FS_CATALOG, '', $backtrace[0]['file']);
        }
        $line_num = $backtrace[0]['line'];
        $this->ihLog("__constructor for $this->filename, called by $caller at line number $line_num\n" . var_export($backtrace, true), true);

        list($newwidth, $newheight, $resize) = $this->calculate_size($this->width, $this->height);
        if ($this->file_exists) {
            // set canvas dimensions
            if ($newwidth > 0 && $newheight > 0) {
                $this->canvas['width'] = $newwidth;
                $this->canvas['height'] = $newheight;
            }

            // initialize overlays (watermark overlay)
            $this->initialize_overlays($this->sizetype);
        }
    } // end class constructor

    /**
     * @return bool
     */
    public function file_not_found()
    {
        global $ihConf;

        // -----
        // If the file is found ... it's not "not-found"!
        //
        if (is_file($ihConf['dir']['docroot'] . $this->src)) {
            return false;

        // -----
        // Otherwise, see if the file exists with a capitalized version of the file-extension.
        //
        }

        $pathinfo = pathinfo($this->src);
        $base = $pathinfo['filename'];
        $baseext = strtolower($pathinfo['extension']);
        switch ($baseext) {
            case 'jpg':
                $extensions = ['.jpg', '.JPG', '.jpeg', '.JPEG'];
                break;
            case 'gif':
                $extensions = ['.gif', '.GIF'];
                break;
            case 'png':
                $extensions = ['.png', '.PNG'];
                break;
            default:
                $extensions = [];
                break;
        }

        foreach ($extensions as $extension) {
            if (is_file($ihConf['dir']['docroot'] . $base . $extension)) {
                $this->src = $base . $extension;
                return false;
            }
        }
        // still here? no file found...
        return true;
    }

    /**
     * @return bool
     */
    public function is_real()
    {
        // return true if the source images are really present and medium
        // or large are not just a descendant from the default image.
        // small default images always return true.

        // strip file extensions, they don't matter
        $orig = substr($this->orig, 0, strrpos($this->orig, '.'));
        $src = substr($this->src, 0, strrpos($this->src, '.'));
        return ($orig === $src);
    }

    public function determine_image_sizetype()
    {
        global $ihConf;

        if (!empty($ihConf['large']['suffix']) && strpos($this->src, $ihConf['large']['suffix']) !== false) {
            $this->sizetype = 'large';
        } elseif (!empty($ihConf['medium']['suffix']) && strpos($this->src, $ihConf['medium']['suffix']) !== false) {
            $this->sizetype = 'medium';
        } elseif (((int)$this->width) === ((int)$ihConf['small']['width']) && ((int)$this->height) === ((int)$ihConf['small']['height'])) {
            $this->sizetype = 'small';
        } else {
            $this->sizetype = 'generic';
        }
    }

    /**
     * @param $src
     *
     * @return string
     */
    public function strip_sizetype_suffix($src)
    {
        global $ihConf;
        $src = preg_replace('/' . $ihConf['large']['suffix'] . '\./', '.', $src);
        $src = preg_replace('/' . $ihConf['medium']['suffix'] . '\./', '.', $src);
        $src = str_replace($ihConf['medium']['prefix'] . '/', '/', $src);
        $src = str_replace($ihConf['large']['prefix'] . '/', '/', $src);
        return $src;
    }

    /**
     * @param $sizetype
     */
    public function initialize_overlays($sizetype)
    {
        global $ihConf;

        $image_base_path = $ihConf['dir']['docroot'] . $ihConf['dir']['images'];
        switch ($sizetype) {
            case 'large':
                $this->watermark['file'] = (!empty($ihConf['large']['watermark'])) ? $image_base_path . 'large/watermark' . $ihConf['large']['suffix'] . '.png' : '';
                break;
            case 'medium':
                $this->watermark['file'] = (!empty($ihConf['medium']['watermark'])) ? $image_base_path . 'medium/watermark' . $ihConf['medium']['suffix'] . '.png': '';
                break;
            case 'small':
                $this->watermark['file'] = ($ihConf['small']['watermark']) ? $image_base_path . 'watermark.png' : '';
                break;
            default:
                $this->watermark['file'] = '';
                break;
        }

        if ($this->watermark['file'] !== '' && is_file($this->watermark['file'])) {
            // set watermark parameters
            list($this->watermark['width'], $this->watermark['height']) = getimagesize($this->watermark['file']);
            list($this->watermark['startx'], $this->watermark['starty']) = $this->calculate_gravity($this->canvas['width'], $this->canvas['height'], $this->watermark['width'], $this->watermark['height'], $ihConf['watermark']['gravity']);
        } else {
            $this->watermark['file'] = '';
        }
    }

    /**
     * @return string|null
     */
    public function get_local()
    {
        if ($this->local) {
            return $this->local;
        }
        // check if image handler is available and if we should resize at all
        if ($this->resizing_allowed()) {
            $this->local = $this->get_resized_image($this->width, $this->height);
        } else {
            $this->local = $this->src;
        }
        return $this->local;
    }

    /**
     * @return bool
     */
    public function resizing_allowed()
    {
        global $bmzConf, $ihConf;

        // -----
        // Resize only if
        //
        // - Resizing is turned on AND
        // - The current source-file name does not include the specified 'noresize_key' AND either
        //   - Is in the configured 'images' directory (defaults to DIR_WS_IMAGES) OR
        //   - Is in the configured BMZ cache directory
        //
        // NOTE: This function ASSUMES that $bmzConf['cachedir'] is under the specified
        // $ihConf['dir']['docroot']!
        //
        $allowed = false;
        if ($ihConf['resize'] && !empty($ihConf['noresize_key']) && strpos($this->src, $ihConf['noresize_key']) === false &&
             (strpos($this->src, $ihConf['dir']['images']) === 0 || strpos(DIR_FS_CATALOG . $this->src, $bmzConf['cachedir']) === 0)) {
            $allowed = true;
            foreach ($ihConf['noresize_dirs'] as $noresize_dir) {
                if (strpos($this->src, $ihConf['dir']['images'] . $noresize_dir . '/') === 0) {
                    $allowed = false;
                    break;
                }
            }
        }
        $this->ihLog("resizing is " . (($allowed) ? '' : 'not ') . "allowed.");
        return $allowed;
    }

    /**
     * @param        $width
     * @param        $height
     * @param string $override_sizetype
     * @param string $filetype
     *
     * @return string
     */
    public function get_resized_image($width, $height, $override_sizetype = '', $filetype = '')
    {
        global $ihConf;
        $this->ihLog("get_resized_image($width, $height, $override_sizetype, $filetype)");
        $sizetype = ($override_sizetype === '') ? $this->sizetype : $override_sizetype;
        switch ($sizetype) {
            case 'large':
                $file_extension = (($ihConf['large']['filetype'] === 'no_change') ? $this->extension : '.' . $ihConf['large']['filetype']);
                $background = $ihConf['large']['bg'];
                $quality = $ihConf['large']['quality'];
                $width = $ihConf['large']['width'];
                $height = $ihConf['large']['height'];
                break;
            case 'medium':
                $file_extension = (($ihConf['medium']['filetype'] === 'no_change') ? $this->extension : '.' . $ihConf['medium']['filetype']);
                $background = $ihConf['medium']['bg'];
                $quality = $ihConf['medium']['quality'];
                break;
            case 'small':
                $file_extension = (($ihConf['small']['filetype'] === 'no_change') ? $this->extension : '.' . $ihConf['small']['filetype']);
                $background = $ihConf['small']['bg'];
                $quality = $ihConf['small']['quality'];
                break;
            default:
                $file_extension = $this->extension;
                $background = $ihConf['default']['bg'];
                $quality = $ihConf['default']['quality'];
                break;
        }
        list($newwidth, $newheight, $resize) = $this->calculate_size($width, $height);

        // set canvas dimensions
        if ($newwidth > 0 && $newheight > 0) {
            $this->canvas['width'] = $newwidth;
            $this->canvas['height'] = $newheight;
        }

        $this->initialize_overlays($sizetype);

        // override filetype?
        $file_extension = ($filetype === '') ? $file_extension : $filetype;

        // Do we need to resize, watermark or convert to another filetype?
        if ($this->file_exists && ($resize || $this->watermark['file'] !== '' || $file_extension !== $this->extension)) {
            switch (IH_CACHE_NAMING) {
                case 'Hashed':
                    $local = $this->getCacheName($this->src . $this->watermark['file'] . $quality . $background . $ihConf['watermark']['gravity'], '.image.' . $newwidth . 'x' . $newheight . $file_extension);
                    break;
                case 'Mirrored':
                    // use pathinfo to get full path of an image
                    $image_path = pathinfo($this->src);
                    // get image name from path and clean it up for those who don't know how image files SHOULD be named
                    $image_basename = $this->sanitizeImageNames($image_path['basename']);
                    $image_dirname = ($image_path['dirname']);
                    // Remove Images default directory from path
                    if ($image_dirname === rtrim(DIR_WS_IMAGES, '/')) {
                        $image_dir = '';
                    } else {
                        $image_dir = substr($image_path['dirname'],strlen(DIR_WS_IMAGES)) . '/';
                    }
                    // and now do the magic and create cached image name with the above parameters
                    $local = $this->getCacheName(strtolower($image_dir . $image_basename), '.image.' . $newwidth . 'x' . $newheight . $file_extension);
                    break;
                case 'Readable':
                default:
                    // use pathinfo to get full path of an image
                    $image_path = pathinfo($this->src);

                    // get image name from path and clean it up for those who don't know how image files SHOULD be named
                    $image_basename = $this->sanitizeImageNames($image_path['basename']);

                    // get last directory from path and clean that up just like the image's base name
                    $image_dirname = $this->sanitizeImageNames(basename($image_path['dirname']));

                    // if last directory is images (meaning image is stored in main images folder), do nothing, else append directory name
                    if ($image_dirname === rtrim(DIR_WS_IMAGES, '/')) {
                       $image_dir = '';
                    } else {
                        $image_dir = $image_dirname . '-';
                    }

                    // and now do the magic and create cached image name with the above parameters
                    $local = $this->getCacheName(strtolower($image_dir . $image_basename), '.image.' . $newwidth . 'x' . $newheight . $file_extension);
                    break;
            }

            //echo $local . '<br>';
            $local_mtime = $this->fileModifiedTime($local); // 0 if not exists
            $file_mtime = $this->fileModifiedTime($this->filename);
            $watermark_mtime = $this->fileModifiedTime($this->watermark['file']);
            $this->ihLog("get_resized_image: $local, $local_mtime, $this->filename, $file_mtime, $watermark_mtime");
            $this->ihLog(date("F d Y H:i:s.", $local_mtime) . ', ' . date("F d Y H:i:s.", $file_mtime));
            if (($local_mtime > $file_mtime && $local_mtime > $watermark_mtime) ||
                $this->resize_imageIM($file_extension, $local, $background, $quality) ||
                $this->resize_imageGD($file_extension, $local, $background, $quality) ) {
                if (strpos($local, $ihConf['dir']['docroot']) !== 0) {
                    $return_file = $local;
                } else {
                    $return_file = substr($local, strlen($ihConf['dir']['docroot']));
                }
                $this->ihLog("... returning $return_file");
                return $return_file;
            }
            //still here? resizing failed
        }
        $this->ihLog("... returning $this->src");
        return $this->src;
    }

    /**
     * @param $filename
     *
     * @return int
     */
    protected function fileModifiedTime($filename)
    {
        clearstatcache();
        return (is_file($filename)) ? filemtime($filename) : 0;
    }

    /**
     * @param $name
     *
     * @return string
     */
    protected function sanitizeImageNames($name)
    {
        $name = str_replace(' ', '-', $name); // Replaces all spaces with hyphens
        $name = preg_replace('/[^A-Za-z0-9\-_]/', '', $name); // Removes special chars, keeps hyphen and underscore
        return preg_replace('/-+/', '-', $name); // Replaces multiple hyphens with single one
    }

    /**
     * Returns the name of a cachefile from given data
     *
     * The needed directory is created by this function!
     *
     * @author Andreas Gohr <andi@splitbrain.org>
     * @author Tim Kroeger <tim@breakmyzencart.com>
     *
     * @param string $data  This data is used to create a unique md5 name
     * @param string $ext   This is appended to the filename if given
     * @return string       The filename of the cachefile
     */
    //-NOTE: This function was (for versions prior to 5.0.1) present in /includes/functions/extra_functions/functions_bmz_io.php
    protected function getCacheName($data, $ext='')
    {
        global $bmzConf;
        switch (IH_CACHE_NAMING) {
            case 'Hashed':
            // Hash the name and place in directory using first character of hashed string
                $md5 = md5($data);
                $file = $bmzConf['cachedir'] . '/' . $md5[0] . '/' . $md5 . $ext;
                break;
            case 'Mirrored':
            // Use readable file name and place in mirror of original directory
                $file = $bmzConf['cachedir'] . '/' . $data . $ext;
                break;
            case 'Readable':
            default:
            // Use readable file name and place directory using first character of $data
                $file = $bmzConf['cachedir'] . '/' . $data[0] . '/' . $data . $ext;
                break;
            }
        io_makeFileDir($file);
        $this->ihLog("getCacheName($data, $ext), returning $file.");
        return $file;
    }

    /**
     * Calculate desired image size as set in admin->configuration->images.
     */
    public function calculate_size($pref_width, $pref_height = '')
    {
        if (file_exists($this->filename)) {
            list($width, $height) = getimagesize($this->filename);
            $this->ihLog("calculate_size($pref_width, $pref_height), getimagesize returned $width x $height.");
        } else {
            $this->ihLog('calculate_size: file "' . $this->filename . '" does NOT exist.');
            $height = 0;
            $width = 0;
            $this->file_exists = false;
        }
        // default: nothing happens (preferred dimension = actual dimension)
        $newwidth = $width;
        $newheight = $height;
        if ($width > 0 && $height > 0) {
            if (strpos($pref_width . $pref_height, '%') !== false) {
                // possible scaling to % of original size
                // calculate new dimension in pixels
                if ($pref_width !== '' && $pref_height !== '') {
                    // different factors for width and height
                    $hscale = (int)$pref_width / 100;
                    $vscale = (int)$pref_height / 100;
                } else {
                    // one of the the preferred values has the scaling factor
                    $hscale = (int)($pref_width . $pref_height) / 100;
                    $vscale = $hscale;
                }
                $newwidth = (int)floor($width * $hscale);
                $newheight = (int)floor($height * $vscale);
            } else {
                $this->force_canvas = (strpos($pref_width . $pref_height, '!') !== false);
                // failsafe for old zen-cart configuration one image dimension set to 0
                $pref_width = (int)$pref_width;
                $pref_height = (int)$pref_height;
                if (!$this->force_canvas && $pref_width != 0 && $pref_height != 0) {
                    // if no '!' is appended to dimensions we don't force the canvas size to
                    // match the preferred size. the image will not have the exact specified size.
                    // (we're in fact forcing the old 0-dimension zen-magic trick)
                    $oldratio = $width / $height;
                    $pref_ratio = $pref_width / $pref_height;
                    if ($pref_ratio > $oldratio) {
                        $pref_width = 0;
                    } else {
                        $pref_height = 0;
                    }
                }

                // now deal with the calculated preferred sizes
                if ($pref_width == 0 && $pref_height > 0) {
                    // image dimensions are calculated to fit the preferred height
                    $pref_width = (int)floor($width * ($pref_height / $height));
                } elseif ($pref_width > 0 && $pref_height == 0) {
                    // image dimensions are calculated to fit the preferred width
                    $pref_height = (int)floor($height * ($pref_width / $width));
                }
                if ($pref_width > 0 && $pref_height > 0 && ($this->force_canvas || $pref_width < $width || $pref_height < $height)) {
                    // only calculate new dimensions if we have sane values
                    $newwidth = $pref_width;
                    $newheight = $pref_height;
                }
            }
        }
        $resize = ($newwidth != $width || $newheight != $height);
        $this->ihLog("calculate_size ($width, $height), ($pref_width, $pref_height), returning ($newwidth, $newheight, $resize)");
        return [$newwidth, $newheight, $resize];
    }

    /**
     * @param     $file_ext
     * @param     $dest_name
     * @param     $bg
     * @param int $quality
     *
     * @return bool
     */
    protected function resize_imageIM($file_ext, $dest_name, $bg, $quality = 85)
    {
        global $ihConf;

        // check if convert is configured
        if (!$ihConf['im_convert']) {
            return false;
        }

        $file_ext = strtolower($file_ext);
        $size = $this->canvas['width'] . 'x' . $this->canvas['height'];

        $bg = trim($bg);
        $bg = ($bg === '') ? $ihConf['default']['bg'] : $bg;

        $transparent = (strpos($bg, 'transparent') !== false && ($file_ext === '.gif' || $file_ext === '.png'));

        $color = $this->get_background_rgb($bg);
        if ($color) {
            $bg = 'rgb(' . $color['r'] . ',' .  $color['g'] . ',' . $color['b'] . ')';
            $bg .= $transparent ? ' transparent' : '';
        }
        $gif_treatment = false;
        $temp_name = '';
        if ($transparent && $file_ext === '.gif') {
            // Special treatment for gif files
            $bg = trim(str_replace('transparent', '', $bg));
            $bg = ($bg !== '') ? $bg : 'rgb(255,255,255)';
            $temp_name = substr($dest_name, 0, strrpos($dest_name, '.')) . '-gif_treatment.png';
            $gif_treatment = true;
        } else {
            $bg = (strpos($bg, 'transparent') === false) ? $bg : 'transparent';
        }
        // still no background? default to transparent
        $bg = ($bg !== '') ? $bg : 'transparent';

        $this->ihLog("resize_imageIM($file_ext, $dest_name, $bg, $quality), size = $size, bg = $bg, color = $color, transparent ($transparent), gif_treatment ($gif_treatment), temp_name = $temp_name");

        $command  = $ihConf['im_convert'] . " -size $size ";
        $command .= "xc:none -fill " . ($gif_treatment ? "transparent" : "\"$bg\"") . " -draw 'color 0,0 reset'";
        $size .= $this->force_canvas ? '' : '!';
        $command .= ' "' . $this->filename . '" -compose Over -gravity Center -geometry ' . $size . ' -composite';
        $command .= ($this->watermark['file'] !== '') ? ' "' . $this->watermark['file'] . '" -compose Over -gravity ' . $ihConf['watermark']['gravity'] . " -composite" : '';
        $command .= $gif_treatment ? $temp_name : (preg_match("/\.jp(e)?g/i", $file_ext) ? "-quality $quality " : '') . "\"$dest_name\"";
        exec($command . ' 2>&1', $message, $retval);
        if ($gif_treatment && $retval === 0) {
            $command  = $ihConf['im_convert'] . " -size $size ";
            $command .= "xc:none -fill \"$bg\" -draw 'color 0,0 reset'";
            $command .= " \"$temp_name\" -compose Over -gravity Center -geometry $size -composite";
            $command .= " \"$temp_name\" -channel Alpha -threshold " . $ihConf['trans_threshold'] . " -compose CopyOpacity -gravity Center -geometry $size -composite";
            $command .= " \"$dest_name\"";
            exec($command . ' 2>&1', $message, $retval);
        }
        return ($retval === 0);
    }

    /**
     * @param     $background
     * @param     $overlay
     * @param int $threshold
     *
     * @return array|mixed
     */
    protected function alphablend($background, $overlay, $threshold = -1)
    {
        /* -------------------------------------------------------------------- */
        /*      Simple cases we want to handle fast.                            */
        /* -------------------------------------------------------------------- */
        if ($overlay['alpha'] === 0) {
            return $overlay;
        }
        if ($overlay['alpha'] === 127) {
            return $background;
        }
        if ($background['alpha'] === 127 && $threshold === -1) {
            return $overlay;
        }

        /* -------------------------------------------------------------------- */
        /*      What will the overlay and background alphas be?  Note that      */
        /*      the background weighting is substantially reduced as the        */
        /*      overlay becomes quite opaque.                                   */
        /* -------------------------------------------------------------------- */
        $alpha =  $overlay['alpha'] * $background['alpha'] / 127;
        if ($threshold > -1 && $alpha <= $threshold) {
            $background['alpha'] = 0;
            $alpha = 0;
        }

        $overlay_weight = 127 - $overlay['alpha'];
        $background_weight = (127 - $background['alpha']) * $overlay['alpha'] / 127;
        $total_weight = $overlay_weight + $background_weight;

        $red = (($overlay['red'] * $overlay_weight) + ($background['red'] * $background_weight)) / $total_weight;
        $green = (($overlay['green'] * $overlay_weight) + ($background['green'] * $background_weight)) / $total_weight;
        $blue = (($overlay['blue'] * $overlay_weight) + ($background['blue'] * $background_weight)) / $total_weight;

        return compact('alpha', 'red', 'green', 'blue');
    }

    /**
     * @param        $background
     * @param        $overlay
     * @param        $startwidth
     * @param        $startheight
     * @param        $newwidth
     * @param        $newheight
     * @param string $threshold
     * @param string $background_override
     *
     * @return mixed
     */
    protected function imagemergealpha($background, $overlay, $startwidth, $startheight, $newwidth, $newheight, $threshold = '', $background_override = '')
    {
        global $ihConf;

        //restore the transparency
        if ($ihConf['gdlib'] > 1) {
            imagealphablending($background, false);
        }

        $threshold = ($threshold !== '') ? (int)(127 * ((int)$threshold) / 100) : -1;

        for ($x=0; $x < $newwidth; $x++) {
            for ($y=0; $y < $newheight; $y++) {
                $c = imagecolorat($background, $x + $startwidth, $y + $startheight);
                $background_color = imagecolorsforindex($background, $c);
                // echo "($x/$y): " . $background_color['alpha'] . ':' . $background_color['red'] . ':' . $background_color['green'] . ':' . $background_color['blue'] . ' ++ ';
                $c = imagecolorat($overlay, $x, $y);
                $overlay_color = imagecolorsforindex($overlay, $c);
                //  echo $overlay_color['alpha'] . ':' . $overlay_color['red'] . ':' . $overlay_color['green'] . ':' . $overlay_color['blue'] . ' ==&gt; ';
                $color = $this->alphablend($background_color, $overlay_color, $threshold);
                // echo $color['alpha'] . ':' . $color['red'] . ':' . $color['green'] . ':' . $color['blue'] . '<br />';

                if ($threshold > -1 && $color['alpha'] > $threshold) {
                    $color = $background_override;
                } else {
                    $color = imagecolorallocatealpha($background, $color['red'], $color['green'], $color['blue'], $color['alpha']);
                }
                imagesetpixel($background, $x + $startwidth, $y + $startheight, $color);
            }
        }
        return $background;
    }


    /**
     * @param     $file_ext
     * @param     $dest_name
     * @param     $bg
     * @param int $quality
     *
     * @return bool
     */
    protected function resize_imageGD($file_ext, $dest_name, $bg, $quality = 85)
    {
        global $ihConf;

        if ($ihConf['gdlib'] < 1) {
            $this->ihLog('resize_imageGD: ihConf, gdlib is < 1, GDlib is unavailable or unwanted.');
            return false; //no GDlib available or wanted
        }

        $file_ext = strtolower($file_ext);
        $srcimage = $this->load_imageGD($this->filename);
        if (!$srcimage) {
            return false; // couldn't load image
        }

        $srcwidth = imagesx($srcimage);
        $srcheight = imagesy($srcimage);
        if ($this->force_canvas) {
            if (($srcwidth / $this->canvas['width']) > ($srcheight / $this->canvas['height'])) {
                $newwidth = $this->canvas['width'];
                $newheight = (int)floor(($newwidth / $srcwidth) * $srcheight);
            } else {
                $newheight = $this->canvas['height'];
                $newwidth = (int)floor(($newheight / $srcheight) * $srcwidth);
            }
        } else {
            $newwidth = $this->canvas['width'];
            $newheight = $this->canvas['height'];
        }
        $startwidth = (int)($this->canvas['width'] - $newwidth) / 2;
        $startheight = (int)($this->canvas['height'] - $newheight) / 2;

        if ($ihConf['gdlib'] > 1 && function_exists("imagecreatetruecolor")) {
            $tmpimg = imagecreatetruecolor($newwidth, $newheight);
        } else {
            $tmpimg = false;
        }
        if (!$tmpimg) {
            $tmpimg = imagecreate($newwidth, $newheight);
        }
        if (!$tmpimg) {
            $this->ihLog("resize_imageGD: failed to create temporary image file: $newwidth x $newheight");
            return false;
        }

        //keep alpha channel if possible
        if ($ihConf['gdlib'] > 1 && function_exists('imagesavealpha')) {
            imagealphablending($tmpimg, false);
        }
        //try resampling first
        if (function_exists("imagecopyresampled")) {
            if (!imagecopyresampled($tmpimg, $srcimage, 0, 0, 0, 0, $newwidth, $newheight, $srcwidth, $srcheight)) {
                imagecopyresized($tmpimg, $srcimage, 0, 0, 0, 0, $newheight, $newwidth, $srcwidth, $srcheight);
            }
        } else {
            imagecopyresized($tmpimg, $srcimage, 0, 0, 0, 0, $newwidth, $newheight, $srcwidth, $srcheight);
        }

        imagedestroy($srcimage);

        // initialize FIRST background image (transparent canvas)
        if ($ihConf['gdlib'] > 1 && function_exists("imagecreatetruecolor")) {
            $newimg = imagecreatetruecolor($this->canvas['width'], $this->canvas['height']);
        } else {
            $newimg = false;
        }
        if (!$newimg) {
            $newimg = imagecreate($this->canvas['width'], $this->canvas['height']);
        }
        if (!$newimg) {
            $this->ihLog("resize_imageGD: failed to create new image file: {$this->canvas['width']} x {$this->canvas['height']}");
            return false;
        }

        if ($ihConf['gdlib'] > 1 && function_exists('imagesavealpha')){
            imagealphablending($newimg, false);
        }
        $background_color = imagecolorallocatealpha($newimg, 255, 255, 255, 127);
        imagefilledrectangle($newimg, 0, 0, $this->canvas['width'] - 1, $this->canvas['height'] - 1, $background_color);

        //$newimg = $this->imagemergealpha($newimg, $tmpimg, $startwidth, $startheight, $newwidth, $newheight);
        imagecopy($newimg, $tmpimg, $startwidth, $startheight, 0, 0, $newwidth, $newheight);
        imagedestroy($tmpimg);
        $tmpimg = $newimg;

        if ($ihConf['gdlib'] > 1 && function_exists('imagesavealpha')){
            imagealphablending($tmpimg, true);
        }
        // we need to watermark our images
        if ($this->watermark['file'] !== '') {
            $this->watermark['image'] = $this->load_imageGD($this->watermark['file']);
            imagecopy($tmpimg, $this->watermark['image'], $this->watermark['startx'], $this->watermark['starty'], 0, 0, $this->watermark['width'], $this->watermark['height']);
            //$tmpimg = $this->imagemergealpha($tmpimg, $this->watermark['image'], $this->watermark['startx'], $this->watermark['starty'], $this->watermark['width'], $this->watermark['height']);
            imagedestroy($this->watermark['image']);
        }

        // initialize REAL background image (filled canvas)
        if ($ihConf['gdlib'] > 1 && function_exists("imagecreatetruecolor")){
            $newimg = imagecreatetruecolor($this->canvas['width'], $this->canvas['height']);
        }
        if (!$newimg) {
            $newimg = imagecreate($this->canvas['width'], $this->canvas['height']);
        }
        if (!$newimg) {
            $this->ihLog('resize_imageGD: failed to create new image with background.');
            return false;
        }

        if ($ihConf['gdlib'] > 1 && function_exists('imagesavealpha')){
            imagealphablending($newimg, false);
        }

        // determine background
        // default to white as "background" -> better rendering on bright pages
        // when downsampling to gif with just boolean transparency
        $color = $this->get_background_rgb($bg);
        if ($color) {
            $transparent = (strpos($bg, 'transparent') !== false);
        } else {
            $color = $this->get_background_rgb($ihConf['default']['bg']);
            $transparent = (strpos($ihConf['default']['bg'], 'transparent') !== false);
        }
        $transparent &= ($file_ext === '.gif' || $file_ext === '.png');

        $alpha = $transparent ? 127 : 0;
        if ($color) {
            $background_color = imagecolorallocatealpha($newimg, (int)$color['r'], (int)$color['g'], (int)$color['b'], $alpha);
        } else {
            $background_color = imagecolorallocatealpha($newimg, 255, 255, 255, $alpha);
        }
        imagefilledrectangle($newimg, 0, 0, $this->canvas['width'] - 1, $this->canvas['height'] - 1, $background_color);

        if ($ihConf['gdlib']>1 && function_exists('imagesavealpha')){
            imagealphablending($newimg, true);
        }

        if ($file_ext === '.gif') {
            if ($transparent) {
                $newimg = $this->imagemergealpha($newimg, $tmpimg, 0, 0, $this->canvas['width'], $this->canvas['height'], $ihConf['trans_threshold'], $background_color);
                imagecolortransparent($newimg, $background_color);
            } else {
                imagecopy($newimg, $tmpimg, 0, 0, 0, 0, $this->canvas['width'], $this->canvas['height']);
            }
        } else {
            if ($transparent) {
                $newimg = $this->imagemergealpha($newimg, $tmpimg, 0, 0, $this->canvas['width'], $this->canvas['height']);
            } else {
                imagecopy($newimg, $tmpimg, 0, 0, 0, 0, $this->canvas['width'], $this->canvas['height']);
            }
        }
        imagedestroy($tmpimg);

        if ($ihConf['gdlib']>1 && function_exists('imagesavealpha')) {
            imagesavealpha($newimg, true);
        }

        if ($file_ext === '.gif') {
            if ($ihConf['gdlib']>1 && function_exists('imagetruecolortopalette')) {
                imagetruecolortopalette($newimg, true, 256);
            }
        }

        return $this->save_imageGD($file_ext, $newimg, $dest_name, $quality);
    }

    /**
     * @param $canvaswidth
     * @param $canvasheight
     * @param $overlaywidth
     * @param $overlayheight
     * @param $gravity
     *
     * @return array
     */
    protected function calculate_gravity($canvaswidth, $canvasheight, $overlaywidth, $overlayheight, $gravity)
    {
        $canvaswidth = (int)$canvaswidth;
        $canvasheight = (int)$canvasheight;
        // Calculate overlay position from gravity setting. Center as default.
        $startheight = (int)(($canvasheight - $overlayheight) / 2);
        $startwidth = (int)(($canvaswidth - $overlaywidth) / 2);
        if (strpos($gravity, 'North') !== false) {
            $startheight = 0;
        } elseif (strpos($gravity, 'South') !== false) {
            $startheight = $canvasheight - $overlayheight;
        }
        if (strpos($gravity, 'West') !== false) {
            $startwidth = 0;
        } elseif (strpos($gravity, 'East') !== false) {
            $startwidth = $canvaswidth - $overlaywidth;
        }
        return [$startwidth, $startheight];
    }

    /**
     * @param $src_name
     *
     * @return false|GdImage|mixed|resource
     */
    protected function load_imageGD($src_name)
    {
        // create an image of the given filetype
        $file_ext = '.' . pathinfo($src_name, PATHINFO_EXTENSION);
        switch (strtolower($file_ext)) {
            case '.gif':
                if (!function_exists('imagecreatefromgif')) {
                    return false;
                }
                $image = imagecreatefromgif($src_name);
                break;
            case '.png':
                if (!function_exists('imagecreatefrompng')) {
                    return false;
                }
                $image = imagecreatefrompng($src_name);
                break;
            case '.jpg':
            case '.jpeg':
                if (!function_exists('imagecreatefromjpeg')) {
                    return false;
                }
                $image = imagecreatefromjpeg($src_name);
                break;
            default:
                $image = false;
                break;
        }
        if ($image === false) {
            $php_error_msg = error_get_last();
            $this->ihLog("load_imageGD($src_name), failure loading the image ($php_error_msg); check image validity");
        }
        return $image;
    }

    /**
     * @param     $file_ext
     * @param     $image
     * @param     $dest_name
     * @param int $quality
     *
     * @return bool
     */
    protected function save_imageGD($file_ext, $image, $dest_name, $quality = 75)
    {
        // -----
        // Initially, santitize the quality input for use by imagejpeg; values should
        // be in the range 0-100.
        //
        $quality = (int)$quality;
        if ($quality < 0 || $quality > 100) {
            $quality = 75;
        }
        $this->ihLog("save_imageGD($file_ext, '', $dest_name, $quality)");
        switch (strtolower($file_ext)) {
            case '.gif':
                if (!function_exists('imagegif')) {
                    $this->ihLog("save_imageGD, imagegif function does not exist");
                    return false;
                }
                $ok = imagegif($image, $dest_name);
                break;
            case '.png':
                if (!function_exists('imagepng')) {
                    $this->ihLog("save_imageGD, imagepng function does not exist");
                    return false;
                }

                // -----
                // The quality input for imagepng requires an integer value in the
                // range 0-9.  If the value's out-of-range, use a proportional setting
                // based on the input.
                //
                if ($quality > 9) {
                    $quality = (int)(9 * $quality / 100);
                }
                $ok = imagepng($image, $dest_name, $quality);
                break;
            case '.jpg':
            case '.jpeg':
                if (!function_exists('imagejpeg')) {
                    $this->ihLog("save_imageGD, imagejpeg function does not exist");
                    return false;
                }
                $ok = imagejpeg($image, $dest_name, $quality);
                break;
            default:
                $ok = false;
                break;
        }
        imagedestroy($image);

        return $ok;
    }

    /**
     * @param $bg
     *
     * @return array|false
     */
    protected function get_background_rgb($bg)
    {
        $color = false;

        $bg = trim(str_replace('transparent', '', $bg));
        list($red, $green, $blue)= preg_split('/[, :]/', $bg);
        if (preg_match('/\d+/', $red.$green.$blue)) {
            $red = min((int)$red, 255);
            $green = min((int)$green, 255);
            $blue = min((int)$blue, 255);
            $color = ['r' => $red, 'g' => $green, 'b' => $blue];
        }
        return $color;
    }

    /**
     * @param       $message
     * @param false $first_record
     */
    public function ihLog($message, $first_record = false)
    {
        if ($this->debug) {
            if ($first_record !== false) {
                $record_prefix = PHP_EOL . date('Y-m-d H:i:s: ');
                if ($this->first_access) {
                    if (IS_ADMIN_FLAG) {
                    } else {
                        $record_prefix .= ('(' . $_SERVER['REQUEST_URI'] . ') ');
                    }
                }
            } else {
                $record_prefix = "\t\t";
            }
            error_log($record_prefix . $message . PHP_EOL, 3, $this->debugLogFile);
        }
    }
}
