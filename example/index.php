<?php
/**
 * index.php
 *
 * @since       2015-02-21
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2016 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-color
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

// autoloader when using Composer
require ('../vendor/autoload.php');

// autoloader when using RPM or DEB package installation
//require ('/usr/share/php/Com/Tecnick/Pdf/Parser/autoload.php');

$filename = '../resources/test/example_036.pdf';
$pdfhandle = @fopen($filename, 'rb');
if ($pdfhandle === false) {
    die('Unable to read the file: '.$filename);
}
// configuration parameters for parser
$cfg = array('ignore_filter_errors' => true);

// parse PDF data
$pdf = new \Com\Tecnick\Pdf\Parser\Parser($pdfhandle, $cfg);
$data = $pdf->parse();
fclose($pdfhandle);

// display data
var_dump($data);
