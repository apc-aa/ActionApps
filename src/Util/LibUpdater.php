<?php

namespace AA\Util;

class LibUpdater {
    public function getUpdatedsystemDefinitions() {
        $msg     = [];
        $newdefs = [];
        $defs  = \AA_Requires::systemDefinitions();
        foreach ($defs as $name => $req) {
            $pad_name = str_pad("'$name'",29);
            if ( strpos($url = $req->getUrl(),'cdn.jsdelivr.net') === false ) {
                $msg[$name]     = "$name - <span style='color:darkred'>untested - non jsdelivr</span> ($url)";
                $newdefs[$name] =  "$pad_name => new ". $req->getConstructor().',';
                continue;
            }

            if (!$reqinfo = $req->parseUrl()) {
                $msg[$name] = "$name - <span style='color:darkred'>untested - non npm or gh</span> ($url)";
                $newdefs[$name] =  "$pad_name => new ". $req->getConstructor().',';
                continue;
            }
            $json    = json_decode(file_get_contents(JS_UPDATE_API_URL.$reqinfo['path'].$reqinfo['package']), true);
            $latest  =  $json['tags']['latest'];
            if ( ((int)$latest == $reqinfo['major']) ) {
                if ( $latest == $reqinfo['version']) {
                    $msg[$name] = "$name - $reqinfo[version] is the latest: $latest";
                    $req->tryUpdate($latest); // not necessary, but it recounts SRI at least...
                    $newdefs[$name] =  "$pad_name => new ". $req->getConstructor().',';
                } else {                                                                            
                    $msg[$name] = "$name - $reqinfo[version] <span style='color:darkgreen'>should be upgradad to:</span> $latest";
                    $req->tryUpdate($latest);
                    $newdefs[$name] =  "<span style='color:darkgreen'>$pad_name => new ". $req->getConstructor().',</span>';
                }
            } else {
                $found = false;
                foreach ($json['versions'] as $ver) {
                    if ((int)$ver == $reqinfo['major']) {
                        if ( $ver == $reqinfo['version']) {
                            $msg[$name] = "$name - $reqinfo[version] is the latest of this version: $ver";
                            //$req->tryUpdate($latest); // not necessary, but it recounts SRI at least...
                            $newdefs[$name] =  "$pad_name => new ". $req->getConstructor().',';
                        } else {
                            $msg[$name] = "$name - $reqinfo[version] should be upgradad to: $ver";
                            $req->tryUpdate($ver);
                            $newdefs[$name] =  "<span style='color:darkgreen'>$pad_name => new ". $req->getConstructor().',</span>';
                        }
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $msg[$name] = "$name - strange - $reqinfo[version] not found";
                    $newdefs[$name] =  "$pad_name => new ". $req->getConstructor().',';
                }
            }
        }
        return join('<br>',$msg). '<br><br><small></small><pre>'. join('<br>',$newdefs).'</pre></small>';
    }
}