<?php

namespace AA\Util;

class LibUpdater {
    public function getUpdatedsystemDefinitions() {
        $msg     = array();
        $newdefs = array();
        $defs  = \AA_Requires::systemDefinitions();
        foreach ($defs as $name => $req) {
            huhl($name, $req, $req->getUrl());
            if ( strpos($url = $req->getUrl(),'cdn.jsdelivr.net') === false ) {
                $msg[$name]     = "$name - untested - non jsdelivr ($url)";
                $newdefs[$name] =  "'$name' => new ". $req->getConstructor().',';
                continue;
            }

            if (!$reqinfo = $req->parseUrl()) {
                $msg[$name] = "$name - untested - non npm ($url)";
                $newdefs[$name] =  "'$name' => new ". $req->getConstructor().',';
                continue;
            }
            $json    = json_decode(file_get_contents(JS_UPDATE_API_URL.$reqinfo['package']), true);
            $latest  =  $json['tags']['latest'];
            if ( ((int)$latest == $reqinfo['major']) ) {
                if ( $latest == $reqinfo['version']) {
                    $msg[$name] = "$name - $reqinfo[version] is the latest: $latest";
                    $newdefs[$name] =  "'$name' => new ". $req->getConstructor().',';
                } else {
                    $msg[$name] = "$name - $reqinfo[version] should be upgradad to: $latest";
                    $req->tryUpdate($latest);
                    $newdefs[$name] =  "<span style='color:darkgreen'>'$name' => new ". $req->getConstructor().',</span>';
                }
            } else {
                $found = false;
                foreach ($json['versions'] as $ver) {
                    if ((int)$ver == $reqinfo['major']) {
                        if ( $ver == $reqinfo['version']) {
                            $msg[$name] = "$name - $reqinfo[version] is the latest of this version: $ver";
                            $newdefs[$name] =  "'$name' => new ". $req->getConstructor().',';
                        } else {
                            $msg[$name] = "$name - $reqinfo[version] should be upgradad to: $ver";
                            $req->tryUpdate($ver);
                            $newdefs[$name] =  "<span style='color:darkgreen'>'$name' => new ". $req->getConstructor().',</span>';
                        }
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $msg[$name] = "$name - strange - $reqinfo[version] not found";
                    $newdefs[$name] =  "'$name' => new ". $req->getConstructor().',';
                }
            }
        }
        return join('<br>',$msg). '<br><br>'. join('<br>',$newdefs);
    }
}