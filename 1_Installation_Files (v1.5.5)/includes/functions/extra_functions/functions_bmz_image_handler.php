<?php
/**
 * mod Image Handler 5.2.0
 * functions_bmz_image_handler.php
 * html_output hook function and additional image referencing functions for
 * backwards compatibility, parsing of configuration settings
 *
 * @author  Tim Kroeger (original author)
 * @copyright Copyright 2005-2006
 * @license http://www.gnu.org/licenses/gpl.txt GNU General Public License V2.0
 * @version $Id: functions_bmz_image_handler.php,v 2.0 Rev 8 2010-05-31 23:46:5 DerManoMann Exp $
 * Last modified by DerManoMann 2010-05-31 23:46:50
 * Last modified by lat9, 2021-02-19, see GitHub#213.
 */
require_once DIR_FS_CATALOG . DIR_WS_CLASSES . 'bmz_image_handler.class.php';

$ihConf['version']              = 'v' . (defined('IH_VERSION') ? IH_VERSION : '?.?.?');

$ihConf['dir']['docroot']       = DIR_FS_CATALOG;
$ihConf['dir']['images']        = DIR_WS_IMAGES;

$ihConf['resize']               = defined('IH_RESIZE') && IH_RESIZE === 'yes';

$ihConf['small']['width']       = SMALL_IMAGE_WIDTH;
$ihConf['small']['height']      = SMALL_IMAGE_HEIGHT;
$ihConf['small']['filetype']    = defined('SMALL_IMAGE_FILETYPE') ? SMALL_IMAGE_FILETYPE : 'no_change';
$ihConf['small']['bg']          = defined('SMALL_IMAGE_BACKGROUND') ? SMALL_IMAGE_BACKGROUND : $ihConf['default']['bg'];
$ihConf['small']['quality']     = defined('SMALL_IMAGE_QUALITY') ? (int)SMALL_IMAGE_QUALITY : $ihConf['default']['quality'];
$ihConf['small']['watermark']   = defined('WATERMARK_SMALL_IMAGES') && WATERMARK_SMALL_IMAGES === 'yes';

$ihConf['medium']['prefix']     = '/medium';
$ihConf['medium']['suffix']     = IMAGE_SUFFIX_MEDIUM;
$ihConf['medium']['width']      = MEDIUM_IMAGE_WIDTH;
$ihConf['medium']['height']     = MEDIUM_IMAGE_HEIGHT;
$ihConf['medium']['filetype']   = defined('MEDIUM_IMAGE_FILETYPE') ? MEDIUM_IMAGE_FILETYPE : 'no_change';
$ihConf['medium']['bg']         = defined('MEDIUM_IMAGE_BACKGROUND') ? MEDIUM_IMAGE_BACKGROUND : $ihConf['default']['bg'];
$ihConf['medium']['quality']    = defined('MEDIUM_IMAGE_QUALITY') ? (int)MEDIUM_IMAGE_QUALITY : $ihConf['default']['quality'];
$ihConf['medium']['watermark']  = defined('WATERMARK_MEDIUM_IMAGES') && WATERMARK_MEDIUM_IMAGES === 'yes';

$ihConf['large']['prefix']      = '/large';
$ihConf['large']['suffix']      = IMAGE_SUFFIX_LARGE;
$ihConf['large']['width']       = defined('LARGE_IMAGE_MAX_WIDTH') ? LARGE_IMAGE_MAX_WIDTH : '750';
$ihConf['large']['height']      = defined('LARGE_IMAGE_MAX_HEIGHT') ? LARGE_IMAGE_MAX_HEIGHT : '550';
$ihConf['large']['filetype']    = defined('LARGE_IMAGE_FILETYPE') ? LARGE_IMAGE_FILETYPE : 'no_change';
$ihConf['large']['bg']          = defined('LARGE_IMAGE_BACKGROUND') ? LARGE_IMAGE_BACKGROUND : $ihConf['default']['bg'];
$ihConf['large']['quality']     = defined('LARGE_IMAGE_QUALITY') ? (int)LARGE_IMAGE_QUALITY : $ihConf['default']['quality'];
$ihConf['large']['watermark']   = defined('WATERMARK_LARGE_IMAGES') && WATERMARK_LARGE_IMAGES === 'yes';

$ihConf['watermark']['gravity'] = defined('WATERMARK_GRAVITY') ? WATERMARK_GRAVITY : 'Center';

$ihConf['small']['bg'] = ihValidateBackground('small');
$ihConf['medium']['bg'] = ihValidateBackground('medium');
$ihConf['large']['bg'] = ihValidateBackground('large');

// -----
// A valid background specification looks like either:
//
// [transparent ]rrr:ggg:bbb
//
// or
//
// rrr:ggg:bbb[ transparent]
//
// or
//
// transparent
//
// where the rrr/ggg/bbb values can range from 0 to 255.
//
// If an invalid specification is found, log an error and reset to 'transparent 255:255:255'.
//
/**
 * @param $which_background
 *
 * @return mixed|string
 */
function ihValidateBackground($which_background)
{
    $background_value = $GLOBALS['ihConf'][$which_background]['bg'];

    $transparent = (strpos($background_value, 'transparent') !== false); //todo unused variable
    $background = trim(str_replace('transparent', '', $background_value));
    $rgb_values = preg_split('/[, :]/', $background);

    $background_error = false;
    if (!is_array($rgb_values) || count($rgb_values) !== 3) {
        $background_error = ($background !== '');
    } else {
        foreach ($rgb_values as $rgb_value) {
            if ($rgb_value > 255 || preg_match('/^\d{1,3}$/', $rgb_value) === 0) {
                $background_error = true;
            }
        }
    }
    if ($background_error) {
        error_log("Image Handler: Invalid '$which_background' background specified ($background_value), using default.");
    }

    return ($background_error) ? 'transparent 255:255:255' : $background_value;
}

// -----
// Main Image Handler function ...
//
/**
 * @param $src
 * @param $alt
 * @param $width
 * @param $height
 * @param $parameters
 *
 * @return array
 */
function handle_image($src, $alt, $width, $height, $parameters)
{
    global $ihConf;

    if ($ihConf['resize']) { //Image Handler processing is enabled
        $ih_image = new ih_image($src, $width, $height);
        $ih_image->ihLog('functions_bmz_image_handler ' . __LINE__ . ': $src=' . $src . ', $alt=' . $alt . ', $width=' . $width . ', $height=' . $height . ', $parameters=' . $parameters);
        // override image path, get local image from cache
        if ($ih_image->file_exists) { // check is for existence of source/base file
            $src = $ih_image->get_local();
            $ih_image->ihLog('functions_bmz_image_handler ' . __LINE__ . ':  local/handled $src=' . $src . ', $ih_image->canvas[width]=' .  $ih_image->canvas['width'] . ',  $ih_image->canvas[height]=' . $ih_image->canvas['height'] . ', $parameters=' . $parameters);
        } else {
            $ih_image->ihLog('functions_bmz_image_handler ' . __LINE__ . ': source/base file $src=' . $src . ' MISSING');
        }
    } else {
//-bof-20210219-lat9-GitHub#212: Don't modify input variables if IH isn't enabled.  Uncomment if needed.
/*
        // default to standard Zen-Cart fallback behavior for large -> medium -> small images
        $image_ext = '.' . pathinfo($src, PATHINFO_EXTENSION);
        $image_base = substr($src, strlen(DIR_WS_IMAGES), -strlen($image_ext));
        if (strrpos($src, IMAGE_SUFFIX_LARGE) && !is_file(DIR_FS_CATALOG . $src)) {
            //large image wanted but not found
            $image_base = $ihConf['medium']['prefix'] . substr($image_base, strlen($ihConf['large']['prefix']), -strlen($ihConf['large']['suffix'])) . $ihConf['medium']['suffix'];
            $src = DIR_WS_IMAGES . $image_base . $image_ext;
        }
        if (strrpos($src, IMAGE_SUFFIX_MEDIUM) && !is_file(DIR_FS_CATALOG . $src)) {
            //medium image wanted but not found
            $image_base = substr($image_base, strlen($ihConf['medium']['prefix']), -strlen($ihConf['medium']['suffix']));
            $src = DIR_WS_IMAGES . $image_base . $image_ext;
        }
*/
//-eof-20210219-lat9
    }
    return [$src, $alt, $width, $height, $parameters];
}


/**
 * get_image functions for backwards compatibility with prior image handler releases
 */

function zen_get_small_image($image)
{
    return $image;
}

/**
 * @param $image_base
 * @param $image_extension
 *
 * @return string
 */
function zen_get_medium_image($image_base, $image_extension)
{
    global $ihConf;
    return $ihConf['medium']['prefix'] . $image_base . $ihConf['medium']['suffix'] . $image_extension;
}

/**
 * @param $image_base
 * @param $image_extension
 *
 * @return string
 */
function zen_get_large_image($image_base, $image_extension)
{
    global $ihConf;
    return $ihConf['large']['prefix'] . $image_base . $ihConf['large']['suffix'] . $image_extension;
}