<?php
/**
 * phpwcms content management system
 *
 * @author Oliver Georgi <og@phpwcms.org>
 * @copyright Copyright (c) 2002-2020, Oliver Georgi
 * @license http://opensource.org/licenses/GPL-2.0 GNU GPL-2
 * @link http://www.phpwcms.org
 *
 **/

// ----------------------------------------------------------------
// obligate check for phpwcms constants
if (!defined('PHPWCMS_ROOT')) {
	die("You Cannot Access This Script Directly, Have a Nice Day.");
}
// ----------------------------------------------------------------

// Content Type Text with Image

$SQL .= "acontent_text="._dbEscape($content["text"]).", ";
$SQL .= "acontent_image="._dbEscape($content["image_info"]).", ";
$SQL .= "acontent_form="._dbEscape(serialize($content['cimage'])).", ";
$SQL .= "acontent_template="._dbEscape($content["template"])." ";
