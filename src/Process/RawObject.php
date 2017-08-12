<?php
/**
 * RawObject.php
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 *
 * This file is part of tc-lib-pdf-parser software library.
 */

namespace Com\Tecnick\Pdf\Parser\Process;

use \Com\Tecnick\Pdf\Parser\Exception as PPException;

/**
 * Com\Tecnick\Pdf\Parser\Process\RawObject
 *
 * Process Raw Objects
 *
 * @since       2011-05-23
 * @category    Library
 * @package     PdfParser
 * @author      Nicola Asuni <info@tecnick.com>
 * @copyright   2011-2015 Nicola Asuni - Tecnick.com LTD
 * @license     http://www.gnu.org/copyleft/lesser.html GNU-LGPL v3 (see LICENSE.TXT)
 * @link        https://github.com/tecnickcom/tc-lib-pdf-parser
 */
abstract class RawObject
{
    /**
     * Get object type, raw value and offset to next object
     *
     * @param int $offset Object offset.
     *
     * @return array Array containing object type, raw value and offset to next object
     */
    protected function getRawObject($offset = 0)
    {
        $objtype = ''; // object type to be returned
        $objval = ''; // object value to be returned
        // skip initial white space chars:
        // \x00 null (NUL)
        // \x09 horizontal tab (HT)
        // \x0A line feed (LF)
        // \x0C form feed (FF)
        // \x0D carriage return (CR)
        // \x20 space (SP)
		fseek($this->pdf, $offset);
		$block = fread($this->pdf, 1024);

		while (($length = strspn($block, "\x00\x09\x0a\x0c\x0d\x20")) == 1024)
		{
			$offset += 1024;
			$block = fread($this->pdf, 1024);
		}

		$offset += $length;
		$block = substr($block, $length) . fread($this->pdf, 1024);

        // get first char
        $char = $block{0};
        if ($char == '%') { // \x25 PERCENT SIGN
            // skip comment and search for next token
			$next = 0;
			while (($length = strcspn($block, "\r\n")) == 1024)
			{
				$next += 1024;
				$block = fread($this->pdf, 1024);
			}
			$next += $length;
            if ($next > 0) {
                $offset += $next;
                return $this->getRawObject($offset);
            }
        }
        // map symbols with corresponding processing methods
        $map = array(
            '/' => 'Solidus',     // \x2F SOLIDUS
            '(' => 'Parenthesis', // \x28 LEFT PARENTHESIS
            ')' => 'Parenthesis', // \x29 RIGHT PARENTHESIS
            '[' => 'Bracket',     // \x5B LEFT SQUARE BRACKET
            ']' => 'Bracket',     // \x5D RIGHT SQUARE BRACKET
            '<' => 'Angular',     // \x3C LESS-THAN SIGN
            '>' => 'Angular',     // \x3E GREATER-THAN SIGN
        );
        if (isset($map[$char])) {
            $method = 'process'.$map[$char];
            $this->$method($char, $offset, $objtype, $objval);

        } else if ($this->processDefaultName($offset, $objtype, $objval) === false) {
			$this->processDefault($offset, $objtype, $objval);
        }
        return array($objtype, $objval, $offset);
    }

    /**
     * Process name object
     * \x2F SOLIDUS
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processSolidus($char, &$offset, &$objtype, &$objval)
    {
        $objtype = $char;
        ++$offset;
		fseek($this->pdf, $offset);
		$block = fread($this->pdf, 256);

        if (preg_match(
            '/^([^\x00\x09\x0a\x0c\x0d\x20\s\x28\x29\x3c\x3e\x5b\x5d\x7b\x7d\x2f\x25]+)/',
            $block,
            $matches
        ) == 1) {
            $objval = $matches[1]; // unescaped value
            $offset += strlen($objval);
        }
    }

    /**
     * Process literal string object
     * \x28 LEFT PARENTHESIS and \x29 RIGHT PARENTHESIS
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processParenthesis($char, &$offset, &$objtype, &$objval)
    {
        $objtype = $char;
        ++$offset;
        $strpos = 0;
		$totpos = 0;

        if ($char == '(') {
			$newval = '';
            $open_bracket = 1;
			fseek($this->pdf, $offset);
			$block = fread($this->pdf, 1024);

            while ($open_bracket > 0) {
                if (!isset($block[$strpos])) {
					$newval .= $block;
					$block = fread($this->pdf, 1024);
					$totpos += $strpos;
					$strpos = 0;

					if (!isset($block[0]))
					{
	                    break;
					}
                }
                $chr = $block[$strpos];
                switch ($chr) {
                    case '\\':
                        // REVERSE SOLIDUS (5Ch) (Backslash)
                        // skip next character
                        ++$strpos;
                        break;
                    case '(':
                        // LEFT PARENHESIS (28h)
                        ++$open_bracket;
                        break;
                    case ')':
                        // RIGHT PARENTHESIS (29h)
                        --$open_bracket;
                        break;
                }
                ++$strpos;
            }
            $objval = $newval . substr($block, 0, $strpos - 1);
            $offset += $totpos + $strpos;
        }
    }

    /**
     * Process array content
     * \x5B LEFT SQUARE BRACKET and \x5D RIGHT SQUARE BRACKET
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processBracket($char, &$offset, &$objtype, &$objval)
    {
        // array object
        $objtype = $char;
        ++$offset;
        if ($char == '[') {
            // get array content
            $objval = array();
            do {
                // get element
                $element = $this->getRawObject($offset);

				if (!$element[0])
				{
					break;
				}

                $offset = $element[2];
                $objval[] = $element;
            } while ($element[0] != ']');
            // remove closing delimiter
            array_pop($objval);
        }
    }

    /**
     * Process \x3C LESS-THAN SIGN and \x3E GREATER-THAN SIGN
     *
     * @param string $char    Symbol to process
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processAngular($char, &$offset, &$objtype, &$objval)
    {
		fseek($this->pdf, $offset);
		$block = fread($this->pdf, 2);

        if (isset($block{1}) && ($block{1} == $char)) {
            // dictionary object
            $objtype = $char.$char;
            $offset += 2;
            if ($char == '<') {
                // get array content
                $objval = array();
                do {
                    // get element
                    $element = $this->getRawObject($offset);

					if (!$element[0])
					{
						// EOF
						break;
					}

                    $offset = $element[2];
                    $objval[] = $element;

                } while ($element[0] != '>>');
                // remove closing delimiter
                array_pop($objval);
            }
        } else {
            // hexadecimal string object
            $objtype = $char;

            ++$offset;
			$opening = false;
			$endpos = strpos($block, '>', 1);
			$offpos = 0;
			$failed = false;

			while ($endpos === false)
			{
				$block = fread($this->pdf, 1024);
				$endpos = strpos($block, '>');
				$blen = strlen($block);

				if ($endpos === false)
				{
					$offpos += $blen;
				}
				if ($blen < 1024)
				{
					break;
				}
			}

			if ($endpos !== false)
			{
				$endpos += $offpos;
			}

            if ($char == '<' AND $endpos)
			{
				fseek($this->pdf, $offset);
				$tagcontents = fread($this->pdf, $endpos);
				$tagcontents = str_split($tagcontents, 1024);
				$newval = '';
				$newset = 0;

				foreach ($tagcontents AS $tagchunk)
				{
					if (preg_match(
						'/^([0-9A-Fa-f\x09\x0a\x0c\x0d\x20]+)$/iU',
						$tagchunk,
						$matches
        			) != 1)
                	{
						$failed = true;
						break;
					}

	                // remove white space characters
                	$newval .= strtr($matches[1], "\x09\x0a\x0c\x0d\x20", '');
					$newset += strlen($matches[0]);
				}
				unset($tagcontents);

				if (!$failed)
				{
					$objval = $newval;
					$offset += $newset;
					$opening = true;
				}
            }

			if (!$opening AND $endpos !== false) {
                $offset += $endpos;
            }
        }
    }

    /**
     * Process default
     *
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     *
     * @return bool True in case of match, flase otherwise
     */
    protected function processDefaultName(&$offset, &$objtype, &$objval)
    {
        $status = false;
		fseek($this->pdf, $offset);
		$delimiter = fread($this->pdf, 9);

        if (substr($delimiter, 0, 6) == 'endobj') {
            // indirect object
            $objtype = 'endobj';
            $offset += 6;
            $status = true;
        } elseif (substr($delimiter, 0, 4) == 'null') {
            // null object
            $objtype = 'null';
            $offset += 4;
            $objval = 'null';
            $status = true;
        } elseif (substr($delimiter, 0, 4) == 'true') {
            // boolean true object
            $objtype = 'boolean';
            $offset += 4;
            $objval = 'true';
            $status = true;
        } elseif (substr($delimiter, 0, 5) == 'false') {
            // boolean false object
            $objtype = 'boolean';
            $offset += 5;
            $objval = 'false';
            $status = true;
        } elseif (substr($delimiter, 0, 6) == 'stream') {
            // start stream object
            $objtype = 'stream';
            $offset += 6;
			fseek($this->pdf, $offset);
			$block = fread($this->pdf, 1024);

            if ($block)
			{
				$stream_started = false;
				$startset = 0;

				if ($block{0} == "\n")
				{
					$stream_started = true;
					$offset += 1;
					$startset = 1;
				}
				else if (isset($block{1}) AND $block{0} . $block{1} == "\r\n")
				{
					$stream_started = true;
					$offset += 2;
					$startset = 2;
				}

				if ($stream_started)
				{
					$stopset = strpos($block, 'endstream');
					$rounds = 0;

					while ($stopset === false AND strlen($block) == 1024)
					{
						$block = fread($this->pdf, 1024);
						$stopset = strpos($block, 'endstream');

						if ($stopset === false)
						{
							$stopset += 1024;
						}
					}

					if ($stopset !== false)
					{
						$char = substr($block, $stopset + 9, 1);

						if (preg_match('/[\x09\x0a\x0c\x0d\x20]/', $char, $m))
						{
							fseek($this->pdf, $offset);
		                    $objval = fread($this->pdf, $stopset + $rounds - $startset);
							$offset += 10;
		       	        }
					}
				}
            }
			unset($stream_data);
            $status = true;
        } elseif ($delimiter == 'endstream') {
            // end stream object
            $objtype = 'endstream';
            $offset += 9;
            $status = true;
        }
        return $status;
    }

    /**
     * Process default
     *
     * @param int    $offset  Offset
     * @param string $objtype Object type
     * @param string $objval  Object content
     */
    protected function processDefault(&$offset, &$objtype, &$objval)
    {
		fseek($this->pdf, $offset);
		$thirtythree = fread($this->pdf, 33);
        if (preg_match(
            '/^([0-9]+)[\s]+([0-9]+)[\s]+R/iU',
            $thirtythree,
            $matches
        ) == 1) {
            // indirect object reference
            $objtype = 'objref';
            $offset += strlen($matches[0]);
            $objval = intval($matches[1]).'_'.intval($matches[2]);
        } elseif (preg_match(
            '/^([0-9]+)[\s]+([0-9]+)[\s]+obj/iU',
            $thirtythree,
            $matches
        ) == 1) {
            // object start
            $objtype = 'obj';
            $objval = intval($matches[1]).'_'.intval($matches[2]);
            $offset += strlen($matches[0]);
        } else {
			$numlen = strspn($thirtythree, '+-.0123456789');
			if ($numlen)
			{
				$newval = substr($thirtythree, 0, $numlen);

				if ($numlen == 33)
				{
					$block = fread($this->pdf, 1024);

					while (($length = strspn($block, '+-.0123456789')) === 1024) {
						$newval .= $block;
						$block = fread($this->pdf, 1024);
						$numlen += 1024;
					}

					if ($length)
					{
						$numlen += $length;
						$newval .= substr($block, 0, $length);
					}
				}

		        // numeric object
		        $objtype = 'numeric';
				$objval = $newval;
		        $offset += $numlen;
			}
        }
    }
}
