<?php


crash !

The script will be removed in the next AA release (for security reasons with readfile())
Just checking, if it is not used anymore.
If you need this script, write me on actionapps@ecn.cz
Honza Malik, 2012-02-10



//$Id$
// NOTE: you need to change these variables
//$host  = 'http://127.0.0.1';
$host  = 'http://www.apc.org';
$cachedir= '/tmp/cache';
$cache4secs = 1000;

if (! $host ){
   echo "Error -- you need to set \$host";
   exit;
}

// if you want URLs rewritten, set this to true and
// put the Snoopy.class.inc file in your include path (require php > 3.0.9)
$useSnoopy = 0; // set to either 0 or 'true'. it is on sourceforge

// TODO: better decision making about caching.
// for example, if there is an expired cache, but connections to the remote URL fail,
// there should be a configurable option to use the local copy, like how caching DNS systems work

// Index:
// 1. usage
// 2. build URL
// 3. figure out if the cachefile exists for this URL
// 4. if the cachefile does not exist or is not new enough,
//    run the query, cache the result, and print the result
// 5. else, print the cached version

// -------------------------------------------------
// 1. usage

// if not called correctly, echo a usage statement
if ( ! $PATH_INFO ) {

echo '<PRE>' . htmlspecialchars ('

=================================================
scriptname: remotec.php
  Include the output of a script on a remote server into a local webpage.
  Cache the output, and use it again until it expires

author    : madebeer@igc.org
license   : released GPL - see http://www.gnu.org/license.html

=================================================
Strategic usage:

  The ActionApps is an easy-to-use content-management system.
    http://www.apc.org/actionapps/

  Imagine a nonprofit wants to start using this system for the news section of
  their site, but does not want to move their site to a webhost that has
  action applications installed.

  The webmaster could:
   1. Ask a server with the action applications to setup a slice for them
   2. Include their news items from their slice with remote, pulling
      these items into news.html, on their local webhost.

  Note: For high performance, frequently updated sites, set
   $cache4secs = "1000"; // about 15 minutes
  and then have a cronjob visit the page every 10 minutes
  to keep the cache relevant.
  0,10,20,30,40,50 * * * lynx -dump http://www./page.html > /dev/null

  Note: if you and the remote server have excellent bandwidth
        to each other, you may want to use "remote" to not use
        caching.

=================================================
Technical usage:

Look at the top of this script, and set the variables there.
Make sure cachedir is writable by the webserver

"remotec.php" is used as an SSI inside an html page.
For example, if you wanted to include the page
   http://www.gn.apc.org/slice.php3
And your page was not on www.gn.apc.org, you would put this
HTML code in your page (setting the $host variable first):
   <!--#include virtual=/remotec.php/slice.php3 -->

remote only works with GET (not POST) commands.
You can use URL parameters, like:
<!--#include
       virtual="/remotec.php/apc-aa/view.php3?vid=11&als[MY_ALIAS]=3"-->

'). '</PRE>';
    exit;
}

// -------------------------------------------------
// 2. build URL

// we need a the next line, or get an HTTP error.
// it goes first, so if there is an error, we see it in the web browser
//print "Content-type:text/html\n\n";

// build the URL of the page we are going to request
$url = $host . $PATH_INFO . "?". $QUERY_STRING;
//echo $url; //debug
// -------------------------------------------------
// 3. figure out if a recent cache exists for this URL
//    if there is, print it.

// I would use the PEAR Cache.php file, but it only works with php4,
// so I am handling caching in the code below.

$id = md5($url);
$target = "$cachedir/$id";
$age = time() - filemtime( $target ) ;

if ( ( file_exists($target) ) and ( $age < $cache4secs ) ) {
   readfile ($target);
} else {

// -------------------------------------------------
// 4. if the cachefile does not exist or is not new enough,
// run the query, cache the result, and print the result

  if ($useSnoopy) {
    include "Snoopy.class.inc";  // will rewrite URLs
    $snoopy = new Snoopy;
    $snoopy->fetch($url);
    $data = $snoopy->results;
  } else {
    $data = join ('', file ($url));
  }

  if (! $data ) exit;

  $fp = fopen ($target, "w");
  // use flock so that simultaneous requests to an expired $id will not mangle the cachefile
  if (flock($fp,2)){
     fwrite($fp, $data);
     if (! fclose($fp)) echo "error closing $target";
  };
  echo $data;
}
/*
$Log: remotec.php,v $
Revision 1.7  2005/06/03 00:36:33  honzam
strings in AA uses "ActionApps" name instead of "APC Action Apps"

Revision 1.6  2005/04/25 11:46:21  honzam
a bit more beauty code - some coding standards setting applied

Revision 1.5  2001/10/19 08:24:17  madebeer
fixed typo in cache4secs

Revision 1.4  2001/10/19 08:00:31  madebeer
fixed typo in cachedir

Revision 1.3  2001/10/19 07:37:46  madebeer
new remotec.php does not rely on any libraries

*/
?>

