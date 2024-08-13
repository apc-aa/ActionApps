<?php
/**
*
* PHP version 7.2+
*
* LICENSE: This program is free software; you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation; either version 2 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program (LICENSE); if not, write to the Free Software
* Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*
* @package   Include
* @version   $Id$
* @author    Honza Malik <honza.malik@ecn.cz>
* @license   http://opensource.org/licenses/gpl-license.php GNU Public License
* @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
* @link      https://www.apc.org/ APC
*
*/

//lass AA_Debug {
//   protected $_starttime;
//
//   function __construct() {
//       $this->_starttime = array('main' => microtime(true));
//   }
//
//   function log()      {$v=func_get_args(); $this->_do('log',     $v);}
//   function info()     {$v=func_get_args(); $this->_do('info',    $v);}
//   function warn()     {$v=func_get_args(); $this->_do('warn',    $v);}
//   function error()    {$v=func_get_args(); $this->_do('error',   $v);}
//
//   function group()    {
//       $v=func_get_args();
//       $group = reset($v);
//       $this->_starttime[$group] = microtime(true);
//       echo "\n<div style='border: 1px #AAA solid; margin: 6px 1px 6px 12px'>";
//       $this->_do('log', $v);
//   }
//
//   function groupend() {
//       $v=func_get_args();
//       $group = array_shift($v);
//       $this->_do('log', $v);
//       $this->_logtime($group);
//       echo "\n</div>";
//   }
//
//   function _do($func, $params) {
//       foreach ($params as $a) {
//           if (is_object($a) && is_callable(array($a,"printobj"))) {
//               $a->printobj();
//           } else {
//               print_r($a);
//           }
//           echo "<br>\n";
//       }
//   }
//   function _logtime($group) {
//       $time = microtime(true) - $this->_starttime[$group];
//       $this->_do(($time > 1.0) ? 'warn' : 'log', array("$group time: $time"));
//   }
//

class AA_Debug_Firephp extends AA_Debug {
    private $_console;

    function __construct() {
        define('INSIGHT_IPS', '*');
        define('INSIGHT_AUTHKEYS', '*');
        define('INSIGHT_PATHS', __DIR__);
        define('INSIGHT_SERVER_PATH', '/aaa/test.php');
        require_once(__DIR__."/../misc/firephp/lib/FirePHP/Init.php");
        $inspector = FirePHP::to('page');
        $this->_console = $inspector->console();
        $this->_console->log('ActionApps - console initiated');
        parent::__construct();
    }

    function _groupstart($group) {
        $this->_console->group($group)->open();
        $this->_console->log($group);
    }

    function _groupend($group) {
        $this->_console->group($group)->close();
    }

    function _do($func, $params) {
        $this->_console->log(microtime(true) - $this->_starttime['main']);
        foreach ($params as $var) {
           call_user_func_array([$this->_console, $func], [$var]);
        }
    }
}


class AA_Debug_Console extends AA_Debug {
    function _do($func, $params) {
        $code = '';
        foreach ($params as $a) {
            if (is_array($a) OR (is_object($a) && !is_callable([$a,"__toString"]))) {
                $a = print_r($a, true);
            }
            // we used escape4js, but it is sometimes not defined yet
            $a = str_replace( ["\\", "'","\r\n","\n","\r",'<script','</script'], ["\\\\", "\\'",'\n','\n','\n','\x3Cscript','\x3C/script'], $a );
            $code .= "console.$func('$a');\n";  //str_replace("'", "\'", str_replace('\\', '\\\\', $a)).
        }
        $this->_script($code);
    }

    function _groupstart($group) {
        $this->_script("console.group();");
        $this->_do('log', [$group]);
    }

    function _groupend($group) {
        $this->_script("console.groupEnd();");
        $this->_do('log', [$group]);
    }

    function _script($code) {
        //  static $used = false;
        //  if (!$used) {
        //      $used = true;
        //      // init
        //      $code = '
        // if (!window.console) {
        //   var names = ["log", "debug", "info", "warn", "error", "assert", "dir", "dirxml", "group", "groupEnd", "time", "timeEnd", "count", "trace", "profile", "profileEnd"];
        //   window.console = {};
        //   for (var i = 0; i < names.length; ++i)  window.console[names[i]] = function() {}
        // }
        // ' .$code;
        //  }
        echo  "\n<script>\n  $code\n</script>\n";
    }
}

class AA_Debug_Phpconsole extends AA_Debug {
    function __construct() {
        PhpConsole::start(true, true, AA_SITE_PATH);
        parent::__construct();
    }

    function _groupstart($group) {
        $this->_do($group, $group);
    }

    function _groupend($group) {
    }

    function _do($func, $params) {
        PhpConsole::debug(microtime(true) - $this->_starttime['main'], 'log');
        foreach ($params as $var) {
            PhpConsole::debug($var, $func);
        }
    }
}

/**
 *
 * @see http://code.google.com/p/php-console
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 * @version 1.1
 *
 * @desc Sending messages to Google Chrome console
 *
 * You need to install Google Chrome extension:
 * https://chrome.google.com/extensions/detail/nfhmhhlpfleoednkpnnnkolmclajemef
 *
 * All class properties and methods are static because it's required to let
 * them work on script shutdown when FATAL error occurs.
 *
 */
class PhpConsole {

        public static $ignoreRepeatedEvents = false;
        public static $callOldErrorHandler = true;
        public static $callOldExceptionsHandler = true;

        /**
         * @var PhpConsole
         */
        protected static $instance;

        protected $handledMessagesHashes = [];
        protected $sourceBasePath;

        protected function __construct($handleErrors, $handleExceptions, $sourceBasePath) {
                if($handleErrors) {
                        $this->initErrorsHandler();
                }
                if($handleExceptions) {
                        $this->initExceptionsHandler();
                }
                if($sourceBasePath) {
                        $this->sourceBasePath = realpath($sourceBasePath);
                }
                $this->initClient();
        }

        public static function start($handleErrors = true, $handleExceptions = true, $sourceBasePath = null) {
                if(self::$instance) {
                        die('PhpConsole already started');
                }
                self::$instance = new PhpConsole($handleErrors, $handleExceptions, $sourceBasePath);
        }

        public static function getInstance() {
                if(!self::$instance) {
                        die('PhpConsole not started');
                }
                return self::$instance;
        }

        protected function handle(PhpConsoleEvent $event) {
                if(self::$ignoreRepeatedEvents) {
                        $eventHash = md5($event->message . $event->file . $event->line);
                        if(in_array($eventHash, $this->handledMessagesHashes)) {
                                return;
                        }
                        else {
                                $this->handledMessagesHashes[] = $eventHash;
                        }
                }
                $this->sendEventToClient($event);
        }

        public function __destruct() {
                self::flushMessagesBuffer();
        }

        /***************************************************************
        CLIENT
         **************************************************************/

        const clientProtocolCookie = 'phpcslc';
        const serverProtocolCookie = 'phpcsls';
        const serverProtocol = 4;
        const messagesCookiePrefix = 'phpcsl_';
        const cookiesLimit = 50;
        const cookieSizeLimit = 4000;
        const messageLengthLimit = 2500;

        protected static $isEnabledOnClient;
        protected static $isDisabled;
        protected static $messagesBuffer = [];
        protected static $bufferLength = 0;
        protected static $messagesSent = 0;
        protected static $cookiesSent = 0;
        protected static $index = 0;

        protected function initClient() {
                if(self::$isEnabledOnClient === null) {
                        self::setEnabledOnServer();
                        self::$isEnabledOnClient = self::isEnabledOnClient();
                        if(self::$isEnabledOnClient) {
                                ob_start();
                        }
                }
        }

        protected static function isEnabledOnClient() {
                return isset($_COOKIE[self::clientProtocolCookie]) && $_COOKIE[self::clientProtocolCookie] == self::serverProtocol;
        }

        protected static function setEnabledOnServer() {
                if(!isset($_COOKIE[self::serverProtocolCookie]) || $_COOKIE[self::serverProtocolCookie] != self::serverProtocol) {
                        self::setCookie(self::serverProtocolCookie, self::serverProtocol);
                }
        }

        protected function sendEventToClient(PhpConsoleEvent $event) {
                if(!self::$isEnabledOnClient || self::$isDisabled) {
                        return;
                }
                $message = [];
                $message['type'] = str_starts_with($event->tags, 'error,') ? 'error' : 'debug';
                $message['subject'] = $event->type;
                $message['text'] = substr($event->message, 0, self::messageLengthLimit);

                if($event->file) {
                        $message['source'] = ($this->sourceBasePath ? preg_replace('!^' . preg_quote($this->sourceBasePath, '!') . '!', '', $event->file) : $event->file) . ($event->line ? ':' . $event->line : '');
                }
                if($event->trace) {
                        $traceArray = $this->convertTraceToArray($event->trace, $event->file, $event->line);
                        if($traceArray) {
                                $message['trace'] = $traceArray;
                        }
                }

                self::pushMessageToBuffer($message);

                if(strpos($event->tags, ',fatal')) {
                        self::flushMessagesBuffer();
                }
        }

        protected function convertTraceToArray($traceData, $eventFile = null, $eventLine = null) {
                $trace = [];
                foreach($traceData as $call) {
                        if((isset($call['class']) && $call['class'] == __CLASS__) || (!$trace && isset($call['file']) && $call['file'] == $eventFile && $call['line'] == $eventLine)) {
                                $trace = [];
                                continue;
                        }
                        $args = [];
                        if(isset($call['args'])) {
                                foreach($call['args'] as $arg) {
                                        if(is_object($arg)) {
                                                $args[] = get_class($arg);
                                        }
                                        elseif (is_array($arg)) {
                                                $args[] = 'Array';
                                        }
                                        else {
                                                $arg = var_export($arg, 1);
                                                $args[] = strlen($arg) > 12 ? substr($arg, 0, 8) . '...\'' : $arg;
                                        }
                                }
                        }
                        if(isset($call['file']) && $this->sourceBasePath) {
                                $call['file'] = preg_replace('!^' . preg_quote($this->sourceBasePath, '!') . '!', '', $call['file']);
                        }
                        $trace[] = (isset($call['file']) ? ($call['file'] . ':' . $call['line']) : '[internal call]') . ' - ' . (isset($call['class']) ? $call['class'] . $call['type'] : '') . $call['function'] . '(' . implode(', ', $args) . ')';
                }
                $trace = array_reverse($trace);
                foreach($trace as $i => &$call) {
                        $call = '#' . ($i + 1) . ' ' . $call;
                }
                return $trace;
        }

        protected static function pushMessageToBuffer($message) {
                $encodedMessageLength = strlen(rawurlencode(json_encode($message)));
                if(self::$bufferLength + $encodedMessageLength > self::cookieSizeLimit) {
                        self::flushMessagesBuffer();
                }
                self::$messagesBuffer[] = $message;
                self::$bufferLength += $encodedMessageLength;
        }

        protected static function getNextIndex() {
                return substr(number_format(microtime(1), 3, '', ''), -6) + self::$index++;
        }

        public static function flushMessagesBuffer() {
                if(self::$messagesBuffer) {
                        self::sendMessages(self::$messagesBuffer);
                        self::$bufferLength = 0;
                        self::$messagesSent += count(self::$messagesBuffer);
                        self::$messagesBuffer = [];
                        self::$cookiesSent++;
                        if(self::$cookiesSent == self::cookiesLimit) {
                                self::$isDisabled = true;
                                $message = ['type' => 'error', 'subject' => 'PHP CONSOLE', 'text' => 'MESSAGES LIMIT EXCEEDED BECAUSE OF COOKIES STORAGE LIMIT. TOTAL MESSAGES SENT: ' . self::$messagesSent, 'source' => __FILE__, 'notify' => 3];
                                self::sendMessages([$message]);
                        }
                }
        }

        protected static function setCookie($name, $value) {
                if(headers_sent($file, $line)) {
                        die('PhpConsole ERROR: setcookie() failed because haders are sent (' . $file . ':' . $line . '). Try to use ob_start()');
                }
                setcookie($name, $value, null, '/');
        }

        protected static function sendMessages($messages) {
                self::setCookie(self::messagesCookiePrefix . self::getNextIndex(), json_encode($messages));
        }

        /***************************************************************
        ERRORS
         **************************************************************/

        protected $codesTags = [E_ERROR => 'fatal', E_WARNING => 'warning', E_PARSE => 'fatal', E_NOTICE => 'notice', E_CORE_ERROR => 'fatal', E_CORE_WARNING => 'warning', E_COMPILE_ERROR => 'fatal', E_COMPILE_WARNING => 'warning', E_USER_ERROR => 'fatal', E_USER_WARNING => 'warning', E_USER_NOTICE => 'notice', E_STRICT => 'warning'];
        protected $codesNames = [E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE', E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR', E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING', E_USER_NOTICE => 'E_USER_NOTICE', E_STRICT => 'E_STRICT'];
        protected $notCompitableCodes = ['E_RECOVERABLE_ERROR' => 'warning', 'E_DEPRECATED' => 'warning'];
        protected $oldErrorHandler;

        protected function initErrorsHandler() {
                ini_set('display_errors', false);
                ini_set('html_errors', false);
                ini_set('ignore_repeated_errors', self::$ignoreRepeatedEvents);
                ini_set('ignore_repeated_source', self::$ignoreRepeatedEvents);

                foreach($this->notCompitableCodes as $code => $tag) {
                        if(defined($code)) {
                                $this->codesTags[constant($code)] = $tag;
                                $this->codesNames[constant($code)] = $code;
                        }
                }

                $this->oldErrorHandler = set_error_handler([$this, 'handleError']);
                register_shutdown_function([$this, 'checkFatalError']);
        }

        public function checkFatalError() {
                $error = error_get_last();
                if($error) {
                        $this->handleError($error['type'], $error['message'], $error['file'], $error['line']);
                }
        }

        public function handleError($code = null, $message = null, $file = null, $line = null) {
                if(error_reporting() == 0) { // if error has been supressed with an @
                        return;
                }
                if(!$code) {
                        $code = E_USER_ERROR;
                }

                $event = new PhpConsoleEvent();
                $event->tags = 'error,' . ($this->codesTags[$code] ?? 'warning');
                $event->message = $message;
                $event->type = $this->codesNames[$code] ?? $code;
                $event->file = $file;
                $event->line = $line;
                $event->trace = debug_backtrace();

                $this->handle($event);

                if(self::$callOldErrorHandler && $this->oldErrorHandler) {
                        call_user_func_array($this->oldErrorHandler, [$code, $message, $file, $line]);
                }
        }

        /***************************************************************
        EXCEPTIONS
         **************************************************************/

        protected $oldExceptionsHandler;

        protected function initExceptionsHandler() {
                $this->oldExceptionsHandler = set_exception_handler([$this, 'handleException']);
        }

        public function handleException(Exception $exception) {
                $event = new PhpConsoleEvent();
                $event->message = $exception->getMessage();
                $event->tags = 'error,fatal,exception,' . get_class($exception);
                $event->type = get_class($exception);
                $event->file = $exception->getFile();
                $event->line = $exception->getLine();
                $event->trace = $exception->getTrace();

                $this->handle($event);

                // TODO: check if need to throw
                if(self::$callOldExceptionsHandler && $this->oldExceptionsHandler) {
                        call_user_func($this->oldExceptionsHandler, $exception);
                }
        }

        /***************************************************************
        DEBUG
         **************************************************************/

        public static function debug($message, $tags = 'debug') {
                if(self::$instance) {
                        $event = new PhpConsoleEvent();
                        $event->message = $message;
                        $event->tags = $tags;
                        $event->type = $tags;
                        self::$instance->handle($event);
                }
        }
}

class PhpConsoleEvent {

        public $message;
        public $type;
        public $tags;
        public $trace;
        public $file;
        public $line;
}


