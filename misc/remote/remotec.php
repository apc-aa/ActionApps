#!/usr/bin/perl -w

#$Id$

# NOTE: you need to change these variables
$host  = 'http://127.0.0.1';
$cachedir= '/tmp/cache/';
$cache4secs = 1000; #

if (! $host ) die "Error -- you need to set \$host";

# this program relies on Cache.php, in the standard PEAR library, 
# and the Snoopy.class.inc file (included in this distribution)

# Index: 
# 1. usage
# 2. build URL
# 3. figure out if the cachefile exists for this URL
# 4. if the cachefile does not exist or is not new enough, 
#    run the query, cache the result, and print the result
# 5. else, print the cached version

# -------------------------------------------------
# 1. usage

# if not called correctly, echo a usage statement
if ( ! $PATH_INFO ) {

echo '
=================================================
scriptname: remotec
  Include the output of a script on a remote server into a local webpage.
  Cache the output, and use it again until it expires  

author    : madebeer\@igc.org
license   : released GPL - see http://www.gnu.org/license.html

=================================================
Strategic usage: 

  The APC Action Applications is an easy-to-use content-management system.
    http://www.apc.org/actionapps/

  Imagine a nonprofit wants to start using this system for the news section of 
  their site, but does not want to move their site to a webhost that has
  action applications installed.

  The webmaster could:
   1. Ask a server with the action applications to setup a slice for them
   2. Include their news items from their slice with remote, pulling
      these items into news.html, on their local webhost.

  Note: For high performance, frequently updated sites, set 
   \$cache4days = ".01"; # about 15 minutes
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

"remotec" is used as an SSI inside an html page. 
For example, if you wanted to include the page
   http://www.gn.apc.org/slice.php3
And your page was not on www.gn.apc.org, you would put this 
HTML code in your page (setting the \$host variable first):
   <!--#include virtual=/cgi/remotec/slice.php3 -->

remote only works with GET (not POST) commands. 
You can use URL parameters, like:
<!--#include 
       virtual="/cgi-bin/remotec/apc-aa/view.php3?vid=11&als[MY_ALIAS]=3"-->

';
    exit;
}

# -------------------------------------------------
# 2. build URL

# we need a the next line, or get an HTTP error. 
# it goes first, so if there is an error, we see it in the web browser
#print "Content-type:text/html\n\n";

# build the URL of the page we are going to request
$url = $host . $PATH_INFO . "?". $QUERY_STRING;

# -------------------------------------------------
# 3. figure out if a recent cache exists for this URL
#    if there is, print it.

require_once("Cache.php");
$cache = new Cache("file", array("cache_dir" => $cachedir) );
$id = $cache->generateID($url);

if ( $data = $cache->get($id)) {
  echo $data;
} else { 

# -------------------------------------------------
# 4. if the cachefile does not exist or is not new enough, 
# run the query, cache the result, and print the result

  include "Snoopy.class.inc";
  $snoopy = new Snoopy;
  $snoopy->fetchtext($url);
  $data = $snoopy->results;
  $cache->save($id,$data,$cache4seconds);
  print $data;
}
/*
$Log$
Revision 1.1  2001/10/19 06:16:44  madebeer
added helper scripts - different versions of remote.
remote allows remote servers to use apc-aa content in SSIs

*/
