<?php
/**
* Filename.......: class.html.mime.mail.inc
* Project........: HTML Mime mail class
* Last Modified..: Date: 2002/07/24 13:14:10
* CVS Revision...: Revision: 1.4
* Copyright......: 2001, 2002 Richard Heyes
*
* AA CVS: $Id$
*/

require_once(dirname(__FILE__) . '/mimePart.php');
require_once AA_INC_PATH."files.class.php3";  // file wrapper;

class htmlMimeMail {
    /**
    * The html part of the message
    * @var string
    */
    var $html;

    /**
    * The text part of the message(only used in TEXT only messages)
    * @var string
    */
    var $text;

    /**
    * The main body of the message after building
    * @var string
    */
    var $output;

    /**
    * The alternative text to the HTML part (only used in HTML messages)
    * @var string
    */
    var $html_text;

    /**
    * An array of embedded images/objects
    * @var array
    */
    var $html_images;

    /**
    * An array of recognised image types for the findHtmlImages() method
    * @var array
    */
    var $image_types;

    /**
    * Parameters that affect the build process
    * @var array
    */
    var $build_params;

    /**
    * Array of attachments
    * @var array
    */
    var $attachments;

    /**
    * The main message headers
    * @var array
    */
    var $headers;

    /**
    * Whether the message has been built or not
    * @var boolean
    */
    var $is_built;

    /**
    * The return path address. If not set the From:
    * address is used instead
    * @var string
    */
    var $return_path;

    /**
    * Array of information needed for smtp sending
    * @var array
    */
    var $smtp_params;

    /**
    * Constructor function. Sets the headers
    * if supplied.
    */
    function __construct() {
        /**
        * Initialise some variables.
        */
        $this->html_images = array();
        $this->headers     = array();
        $this->is_built    = false;

        /**
        * If you want the auto load functionality
        * to find other image/file types, add the
        * extension and content type here.
        */
        $this->image_types = array(
                                    'gif'    => 'image/gif',
                                    'jpg'    => 'image/jpeg',
                                    'jpeg'    => 'image/jpeg',
                                    'jpe'    => 'image/jpeg',
                                    'bmp'    => 'image/bmp',
                                    'png'    => 'image/png',
                                    'tif'    => 'image/tiff',
                                    'tiff'    => 'image/tiff',
                                    'swf'    => 'application/x-shockwave-flash'
                                  );

        /**
        * Set these up
        */
        $this->build_params['html_encoding'] = 'quoted-printable';
        $this->build_params['text_encoding'] = '7bit';
        $this->build_params['html_charset']  = 'ISO-8859-1';
        $this->build_params['text_charset']  = 'ISO-8859-1';
        $this->build_params['head_charset']  = 'ISO-8859-1';
        $this->build_params['text_wrap']     = 998;

        /**
        * Defaults for smtp sending
        */
        if (!empty($_SERVER['HTTP_HOST'])) {
            $helo = $_SERVER['HTTP_HOST'];

        } elseif (!empty($_SERVER['SERVER_NAME'])) {
            $helo = $_SERVER['SERVER_NAME'];

        } else {
            $helo = 'localhost';
        }

        $this->smtp_params['host'] = 'localhost';
        $this->smtp_params['port'] = 25;
        $this->smtp_params['helo'] = $helo;
        $this->smtp_params['auth'] = false;
        $this->smtp_params['user'] = '';
        $this->smtp_params['pass'] = '';

        /**
        * Make sure the MIME version header is first.
        */
        $this->headers['MIME-Version'] = '1.0';
    }

/**
* This function will read a file in
* from a supplied filename and return
* it. This can then be given as the first
* argument of the the functions
* add_html_image() or add_attachment().
*/
    function getFile($filename) {
        $file = &AA_File_Wrapper::wrapper($filename);
        // $file->contents(); opens the stream, reads the data and close the stream
        return $file->contents();
    }

/**
* Accessor to set the CRLF style
*/
    function setCrlf($crlf = "\n")
    {
        if (!defined('CRLF')) {
            define('CRLF', $crlf, true);
        }

        if (!defined('MAIL_MIMEPART_CRLF')) {
            define('MAIL_MIMEPART_CRLF', $crlf, true);
        }
    }

/**
* Accessor to set the SMTP parameters
*/
    function setSMTPParams($host = null, $port = null, $helo = null, $auth = null, $user = null, $pass = null)
    {
        if (!is_null($host)) $this->smtp_params['host'] = $host;
        if (!is_null($port)) $this->smtp_params['port'] = $port;
        if (!is_null($helo)) $this->smtp_params['helo'] = $helo;
        if (!is_null($auth)) $this->smtp_params['auth'] = $auth;
        if (!is_null($user)) $this->smtp_params['user'] = $user;
        if (!is_null($pass)) $this->smtp_params['pass'] = $pass;
    }

/**
* Accessor function to set the text encoding
*/
    function setTextEncoding($encoding = '7bit')
    {
        $this->build_params['text_encoding'] = $encoding;
    }

/**
* Accessor function to set the HTML encoding
*/
    function setHtmlEncoding($encoding = 'quoted-printable')
    {
        $this->build_params['html_encoding'] = $encoding;
    }

/**
* Accessor function to set the text charset
*/
    function setTextCharset($charset = 'ISO-8859-1')
    {
        $this->build_params['text_charset'] = $charset;
    }

/**
* Accessor function to set the HTML charset
*/
    function setHtmlCharset($charset = 'ISO-8859-1')
    {
        $this->build_params['html_charset'] = $charset;
    }

/**
* Accessor function to set the header encoding charset
*/
    function setHeadCharset($charset = 'ISO-8859-1')
    {
        $this->build_params['head_charset'] = $charset;
    }

/**
* Accessor function to set the text wrap count
*/
    function setTextWrap($count = 998)
    {
        $this->build_params['text_wrap'] = $count;
    }

/**
* Accessor to set a header
*/
    function setHeader($name, $value)
    {
        $this->headers[$name] = $value;
    }

/**
* Accessor to add a Subject: header
*/
    function setSubject($subject)
    {
        $this->headers['Subject'] = $subject;
    }

/**
* Accessor to add a From: header
*/
    function setFrom($from)
    {
        $this->headers['From'] = $from;
    }

/**
    * Accessor to set priority. Priority given should be either
    * high, normal or low. Can also be specified numerically,
    * being 1, 3 or 5 (respectively).
    *
    * @param mixed $priority The priority to use.
    */
    function setPriority($priority = 'normal')
    {
        switch (strtolower($priority)) {
            case 'high':
            case '1':
                $this->headers['X-Priority'] = '1';
                $this->headers['X-MSMail-Priority'] = 'High';
                break;

            case 'normal':
            case '3':
                $this->headers['X-Priority'] = '3';
                $this->headers['X-MSMail-Priority'] = 'Normal';
                break;

            case 'low':
            case '5':
                $this->headers['X-Priority'] = '5';
                $this->headers['X-MSMail-Priority'] = 'Low';
                break;
        }
    }

    /**
* Accessor to set the return path
*/
    function setReturnPath($return_path)
    {
        $this->return_path = $return_path;
    }

/**
* Accessor to add a Cc: header
*/
    function setCc($cc)
    {
        $this->headers['Cc'] = $cc;
    }

/**
* Accessor to add a Bcc: header
*/
    function setBcc($bcc)
    {
        $this->headers['Bcc'] = $bcc;
    }

/**
* Adds plain text. Use this function
* when NOT sending html email
*/
    function setText($text = '')
    {
        $this->text = $text;
    }

/**
* Adds a html part to the mail.
* Also replaces image names with
* content-id's.
*/
    function setHtml($html, $text = null, $images_dir = null)
    {
        $this->html      = $html;
        $this->html_text = $text;
        $this->_findHtmlImages($images_dir);
    }

/**
* Function for extracting images from
* html source. This function will look
* through the html code supplied by add_html()
* and find any file that ends in one of the
* extensions defined in $obj->image_types.
* If the file exists it will read it in and
* embed it, (not an attachment).
*
* @author Dan Allen
*/
    function _findHtmlImages($images_dir) {

        // Build the list of image extensions
        $extensions = array_keys($this->image_types);

        // str_ireplace make the regexp not match <a href="some-image.jpg"> (otherwise it add such image into the mail)
        $sanit_text = str_ireplace(array('href="', "href='"), array('', ''), $this->html);
        preg_match_all('/(?:"|\')([^"\']+\.('.implode('|', $extensions).'))(?:"|\')/Ui', $sanit_text, $images); // (?:x) - non capturing parenthes - matches, but not in the result
        preg_match_all('~(?:"|\')([^"\']+/img\.php\?src\=[^"\']+)(?:"|\')~Ui', $sanit_text, $images2);

        $images      = array_unique(array_merge($images[1], $images2[1]));

        $img_content = array();
        $img_name    = array();
        $html_images = array();

        foreach ($images as $img) {

            if (strtolower(substr($img,0,4))=='http') {
                if ($pos = strpos($img,'/img.php?src=')) {
                    $image_path            = $img;
                    $img_name[$image_path] = basename( parse_url( substr($image_path, $pos+13, strpos($image_path,'&')-$pos-13), PHP_URL_PATH) );
                } else {
                    $image_path            = $img;
                    $img_name[$image_path] = basename( parse_url($image_path, PHP_URL_PATH) );
                }
            } else {
                if (false !== ($pos = strpos($img,'/img.php?src='))) {
                    // this one is used in new versions of AA - Honza 14.8.2013
                    $image_path            = AA_INSTAL_URL . substr($img,$pos+1);
                    $pos                   = strpos($image_path,'/img.php?src='); // recompute for full url and use the same approach as above
                    $img_name[$image_path] = basename( parse_url( substr($image_path, $pos+13, strpos($image_path,'&')-$pos-13), PHP_URL_PATH) );
                } else {
                    $image_path            = $images_dir . $img;
                    $img_name[$image_path] = basename( $image_path );
                }
            }

            if (!isset($img_content[$image_path])) {
                $img_content[$image_path] = $this->getFile(str_replace('&amp;', '&', $image_path));
            }
            if ($img_content[$image_path]) {
                $html_images[] = $image_path;
                $this->html = str_replace($img, $img_name[$image_path], $this->html);
            }
        }

        if (!empty($html_images)) {
            // // If duplicate images are embedded, they may show up as attachments, so remove them.
            // $html_images = array_unique($html_images);
            sort($html_images);

            for ( $i=0, $ino=count($html_images); $i<$ino; ++$i) {
                $image        = $img_content[$html_images[$i]];
                $ext          = strpos($html_images[$i],'/img.php?src=') ? 'jpg' : substr($html_images[$i], strrpos($html_images[$i], '.') + 1);
                $content_type = $this->image_types[strtolower($ext)];
                $this->addHtmlImage($image, $img_name[$html_images[$i]], $content_type);
            }
        }

    }

/**
* Adds an image to the list of embedded
* images.
*/
    function addHtmlImage($file, $name = '', $c_type='application/octet-stream')
    {
        $this->html_images[] = array(
                                        'body'   => $file,
                                        'name'   => $name,
                                        'c_type' => $c_type,
                                        'cid'    => md5(uniqid(time()))
                                    );
    }


/**
* Adds a file to the list of attachments.
*/
    function addAttachment($file, $name = '', $c_type='application/octet-stream', $encoding = 'base64')
    {
        $this->attachments[] = array(
                                    'body'        => $file,
                                    'name'        => $name,
                                    'c_type'    => $c_type,
                                    'encoding'    => $encoding
                                  );
    }

/**
* Adds a text subpart to a mime_part object
*/
    function &_addTextPart(&$obj, $text)
    {
        $params['content_type'] = 'text/plain';
        $params['encoding']     = $this->build_params['text_encoding'];
        $params['charset']      = $this->build_params['text_charset'];
        if (is_object($obj)) {
            $return = $obj->addSubpart($text, $params);
        } else {
            $return = new Mail_mimePart($text, $params);
        }

        return $return;
    }

/**
* Adds a html subpart to a mime_part object
*/
    function &_addHtmlPart(&$obj)
    {
        $params['content_type'] = 'text/html';
        $params['encoding']     = $this->build_params['html_encoding'];
        $params['charset']      = $this->build_params['html_charset'];
        if (is_object($obj)) {
            $return = $obj->addSubpart($this->html, $params);
        } else {
            $return = new Mail_mimePart($this->html, $params);
        }

        return $return;
    }

/**
* Starts a message with a mixed part
*/
    function &_addMixedPart()
    {
        $params['content_type'] = 'multipart/mixed';
        $return = new Mail_mimePart('', $params);

        return $return;
    }

/**
* Adds an alternative part to a mime_part object
*/
    function &_addAlternativePart(&$obj)
    {
        $params['content_type'] = 'multipart/alternative';
        if (is_object($obj)) {
            $return = $obj->addSubpart('', $params);
        } else {
            $return = new Mail_mimePart('', $params);
        }

        return $return;
    }

/**
* Adds a html subpart to a mime_part object
*/
    function &_addRelatedPart(&$obj)
    {
        $params['content_type'] = 'multipart/related';
        if (is_object($obj)) {
            $return = $obj->addSubpart('', $params);
        } else {
            $return = new Mail_mimePart('', $params);
        }

        return $return;
    }

/**
* Adds an html image subpart to a mime_part object
*/
    function _addHtmlImagePart(&$obj, $value)
    {
        $params['content_type'] = $value['c_type'];
        $params['encoding']     = 'base64';
        $params['disposition']  = 'inline';
        $params['dfilename']    = $value['name'];
        $params['cid']          = $value['cid'];
        $obj->addSubpart($value['body'], $params);
    }

/**
* Adds an attachment subpart to a mime_part object
*/
    function _addAttachmentPart(&$obj, $value)
    {
        $params['content_type'] = $value['c_type'];
        $params['encoding']     = $value['encoding'];
        $params['disposition']  = 'attachment';
        $params['dfilename']    = $value['name'];
        $obj->addSubpart($value['body'], $params);
    }

/**
* Builds the multipart message from the
* list ($this->_parts). $params is an
* array of parameters that shape the building
* of the message. Currently supported are:
*
* $params['html_encoding'] - The type of encoding to use on html. Valid options are
*                            "7bit", "quoted-printable" or "base64" (all without quotes).
*                            7bit is EXPRESSLY NOT RECOMMENDED. Default is quoted-printable
* $params['text_encoding'] - The type of encoding to use on plain text Valid options are
*                            "7bit", "quoted-printable" or "base64" (all without quotes).
*                            Default is 7bit
* $params['text_wrap']     - The character count at which to wrap 7bit encoded data.
*                            Default this is 998.
* $params['html_charset']  - The character set to use for a html section.
*                            Default is ISO-8859-1
* $params['text_charset']  - The character set to use for a text section.
*                          - Default is ISO-8859-1
* $params['head_charset']  - The character set to use for header encoding should it be needed.
*                          - Default is ISO-8859-1
*/
    function buildMessage($params = array())
    {
        if (!empty($params)) {
            while (list($key, $value) = each($params)) {
                $this->build_params[$key] = $value;
            }
        }

        if (!empty($this->html_images)) {
            foreach ($this->html_images as $value) {
                $this->html = str_replace($value['name'], 'cid:'.$value['cid'], $this->html);
            }
        }
        if (!empty($this->html_images)) {
            foreach ($this->html_images as $value) {
                $quoted = preg_quote($value['name']);
                $cid    = preg_quote($value['cid']);

                $this->html = preg_replace("#src=\"$quoted\"|src='$quoted'#", "src=\"cid:$cid\"", $this->html);
                $this->html = preg_replace("#background=\"$quoted\"|background='$quoted'#", "background=\"cid:$cid\"", $this->html);
            }
        }

        $null        = null;
        $attachments = !empty($this->attachments) ? true : false;
        $html_images = !empty($this->html_images) ? true : false;
        $html        = !empty($this->html)        ? true : false;
        $text        = isset($this->text)         ? true : false;

        switch (true) {
            case $text AND !$attachments:
                $message = &$this->_addTextPart($null, $this->text);
                break;

            case !$text AND $attachments AND !$html:
                $message = &$this->_addMixedPart();

                for ( $i=0, $ino=count($this->attachments); $i<$ino; ++$i) {
                    $this->_addAttachmentPart($message, $this->attachments[$i]);
                }
                break;

            case $text AND $attachments:
                $message = &$this->_addMixedPart();
                $this->_addTextPart($message, $this->text);

                for ( $i=0, $ino=count($this->attachments); $i<$ino; ++$i) {
                    $this->_addAttachmentPart($message, $this->attachments[$i]);
                }
                break;

            case $html AND !$attachments AND !$html_images:
                if (!is_null($this->html_text)) {
                    $message = &$this->_addAlternativePart($null);
                    $this->_addTextPart($message, $this->html_text);
                    $this->_addHtmlPart($message);
                } else {
                    $message = &$this->_addHtmlPart($null);
                }
                break;

            case $html AND !$attachments AND $html_images:
                if (!is_null($this->html_text)) {
                    $message = &$this->_addAlternativePart($null);
                    $this->_addTextPart($message, $this->html_text);
                    $related = &$this->_addRelatedPart($message);
                } else {
                    $message = &$this->_addRelatedPart($null);
                    $related = &$message;
                }
                $this->_addHtmlPart($related);
                for ( $i=0, $ino=count($this->html_images); $i<$ino; ++$i) {
                    $this->_addHtmlImagePart($related, $this->html_images[$i]);
                }
                break;

            case $html AND $attachments AND !$html_images:
                $message = &$this->_addMixedPart();
                if (!is_null($this->html_text)) {
                    $alt = &$this->_addAlternativePart($message);
                    $this->_addTextPart($alt, $this->html_text);
                    $this->_addHtmlPart($alt);
                } else {
                    $this->_addHtmlPart($message);
                }
                for ( $i=0, $ino=count($this->attachments); $i<$ino; ++$i) {
                    $this->_addAttachmentPart($message, $this->attachments[$i]);
                }
                break;

            case $html AND $attachments AND $html_images:
                $message = &$this->_addMixedPart();
                if (!is_null($this->html_text)) {
                    $alt = &$this->_addAlternativePart($message);
                    $this->_addTextPart($alt, $this->html_text);
                    $rel = &$this->_addRelatedPart($alt);
                } else {
                    $rel = &$this->_addRelatedPart($message);
                }
                $this->_addHtmlPart($rel);
                for ( $i=0, $ino=count($this->html_images); $i<$ino; ++$i) {
                    $this->_addHtmlImagePart($rel, $this->html_images[$i]);
                }
                for ( $i=0, $ino=count($this->attachments); $i<$ino; ++$i) {
                    $this->_addAttachmentPart($message, $this->attachments[$i]);
                }
                break;

        }

        if (isset($message)) {
            $output = $message->encode();
            $this->output   = $output['body'];
            $this->headers  = array_merge($this->headers, $output['headers']);

            // Figure out hostname
            if (!empty($_SERVER['HTTP_HOST'])) {
                $hostname = $_SERVER['HTTP_HOST'];

            } else if (!empty($_SERVER['SERVER_NAME'])) {
                $hostname = $_SERVER['SERVER_NAME'];

            } else if (!empty($_ENV['HOSTNAME'])) {
                $hostname = $_ENV['HOSTNAME'];

            } else {
                $hostname = 'localhost';
            }

            $message_id = sprintf('<%s.%s@%s>', base_convert(time(), 10, 36), base_convert(rand(), 10, 36), $hostname);
            $this->headers['Message-ID'] = $message_id;

            $this->is_built = true;
            return true;
        } else {
            return false;
        }
    }

/**
* Function to encode a header if necessary
* according to RFC2047
*/
    function _encodeHeader($input, $charset = 'ISO-8859-1')
    {
        preg_match_all('/(\s?\w*[\x80-\xFF]+\w*\s?)/', $input, $matches);
        foreach ($matches[1] as $value) {
            //$replacement = preg_replace('/([\x20\x80-\xFF])/e', '"=" . strtoupper(dechex(ord("\1")))', $value);
            $replacement = preg_replace_callback('/([\x20\x80-\xFF])/', function ($ms) { return '='.strtoupper(dechex(ord($ms[0]))); }, $value);
            $input = str_replace($value, '=?' . $charset . '?Q?' . $replacement . '?=', $input);
        }
        return $input;
    }

/**
* Sends the mail.
*
* @param  array  $recipients
* @return mixed
*/
    function send($recipients) {
        if ( !(isset($recipients) AND is_array($recipients)) ) {
            return false;
        }

        if (!defined('CRLF')) {
            $this->setCrlf("\n");
        }

        if (!$this->is_built) {
            $this->buildMessage();
        }

        $subject = '';
        if (!empty($this->headers['Subject'])) {
            $subject = $this->_encodeHeader($this->headers['Subject'], $this->build_params['head_charset']);
            unset($this->headers['Subject']);
        }

        // Get flat representation of headers
        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $this->_encodeHeader($value, $this->build_params['head_charset']);
        }

        $to = $this->_encodeHeader(implode(', ', $recipients), $this->build_params['head_charset']);

        if ( !empty($this->return_path) AND !ini_get('safe_mode')) {
            $result = mail($to, $subject, $this->output, implode(CRLF, $headers), '-f' . $this->return_path);
        } else {
            $result = mail($to, $subject, $this->output, implode(CRLF, $headers));
        }

        // Reset the subject in case mail is resent
        if ($subject !== '') {
            $this->headers['Subject'] = $subject;
        }

        return $result;
    }

    /**
    * Use this method to return the email
    * in message/rfc822 format. Useful for
    * adding an email to another email as
    * an attachment. there's a commented
    * out example in example.php.
    */
    function getRFC822($recipients, $type = 'mail') {

        // Make up the date header as according to RFC822
        $this->setHeader('Date', date('D, d M y H:i:s O'));

        if (!defined('CRLF')) {
            $this->setCrlf($type == 'mail' ? "\n" : "\r\n");
        }

        if (!$this->is_built) {
            $this->buildMessage();
        }

        // Return path ?
        if (isset($this->return_path)) {
            $headers[] = 'Return-Path: ' . $this->return_path;
        }

        // Get flat representation of headers
        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $headers[] = 'To: ' . implode(', ', $recipients);

        return implode(CRLF, $headers) . CRLF . CRLF . $this->output;
    }
} // End of class.
?>
