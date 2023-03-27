<?php


namespace AA\Doit;

use AA;
use AA\IO\DB\DB_AA;


/** request monitor - developer temporary tool for catching all the REQUEST variables in order we can remove
 * foreach addslashesdeep() calls
 */
class RequestMonitor
{
    protected $pages = [];

    public function record(): void {
        if (count($_REQUEST)) {
            $request_monitorow_row   = ['page'=>$_SERVER["REQUEST_URI"]];
            $request_monitorow_row['userinfo'] = time(). ':' .$_SERVER['REMOTE_ADDR'];
            if (count($_GET))                   { $request_monitorow_row['get']     = serialize($_GET); }
            if (count($_POST))                  { $request_monitorow_row['post']    = serialize($_POST); }
            if (count($_COOKIE))                { $request_monitorow_row['cookie']  = serialize($_COOKIE); }
            if ($_SESSION AND count($_SESSION)) { $request_monitorow_row['session'] = serialize($_SESSION); }
            if (DB_AA::exists_table('tmp_request_monitor')) {
                AA::Metabase()->loadFromDb(false,['tmp_request_monitor']);  // it is not standard table - we have to read it
                AA::Metabase()->doInsert('tmp_request_monitor',$request_monitorow_row);
            }
        }
    }

    public function analyze(): void {
        $request = DB_AA::select([], 'SELECT * FROM `tmp_request_monitor`');
        foreach ($request as $req) {
            [$page] = explode('?', $req['page'], 2);
            $page = preg_replace(['~^/[^/]+/~','~\.php3$~'], ['','.php'], $page);  // remove first directory and standardize extensions
            if ($page) {
                if (!is_array($this->pages[$page])) {
                    $this->pages[$page] = ['count' => 0];
                }
                $this->pages[$page]['count']++;
                $this->join2pages($page, 'G', $req['get']);
                $this->join2pages($page, 'P', $req['post']);
                $this->join2pages($page, 'C', $req['cookie']);
                $this->join2pages($page, 'S', $req['session']);
            }
        }
    }

    public function report(): string {
        $ret = '';
        $SUPERARRS = ['P'=>'POST', 'G'=>'GET'];
        foreach ($this->pages as $page => $type) {

            $table = [];
            $detail = ''; //safe(var_export($type));
            $code   = "";
            foreach ($type as $t => $vars) {
                if (($t != 'P') AND ($t != 'G')) {  // just for POST and GET
                    continue;
                }
                $detail .= "<h4>$t</h4>";
                foreach ($vars as $name => $vals) {
                    if (in_array($name, ['slice_id', 'module_id', 'username', 'password', 'code2fa'])) {
                        continue;
                    }
                    $detail .= "<br><b>$name</b>";
                    foreach ($vals as $val) {
                        $detail .= "<br> &nbsp; = ". safe(is_array($val) ? var_export(array_count_values($val), true) : $val);
                    }
                    $detail .= '<br><em>'. safe(var_export(array_count_values($vals), true)). '</em>';
                    $vartype = $this->analyze_variable($vals, $this->pages[$page]['count']);
                    $detail .= '<br>'. $vartype. '</em>';
                    $table[$name]['type'][$SUPERARRS[$t]] = 1;
                    $table[$name]['valmain'][] = $vartype['main'];
                    $table[$name]['valsub'][] = $vartype['sub'];
                    $table[$name]['valempty'][] = $vartype['empty'];
                    $table[$name]['valreq'][] = $vartype['req'];
                    $table[$name]['vals'][] = join('|', $vals);
                    $table[$name]['valsum'][] = var_export(array_count_values(array_map( function ($str) { return ((is_string($str) AND (strlen($str)) > 12) ? substr($str,0,11).'…' : $str); }, $vals)), true);
                }
            }
            ksort($table);
            foreach ($table as $name => $def) {
                $ftype = array_keys($def['type']);
                sort($ftype);
                $ftype = join('',$ftype);
                $sum   = safe(join('-',$def['valsum']));
                $com   = join(', ',array_filter([join('-',$def['valsub']), ((join('-',$def['valempty'])=='1') ? 'empty' :'')]));
                $f     = "// $sum<br>  &nbsp;   &nbsp;\$$name = InputVars::".join('-',array_unique($def['valmain'])) ."$ftype('$name'". ((join('-',$def['valreq'])=='1') ? ', true' :'') .");". ($com ? "     // $com" : '');
                $code .= "<br>  &nbsp;   &nbsp;$f";
                $detail  .= "<br><b>$f</b>";
                $detail  .= "<br><small><em>".safe(join('|',$def['vals']))."$sum</em></small>";
            }
            $code .= '<br>  &nbsp;   &nbsp;$cancel = InputVars::boolPOST(\'cancel\');';
            $code .= '<br>  &nbsp;   &nbsp;$err = InputVars::arrayGETPOST(\'err\');';
            $code .= '<br>  &nbsp;   &nbsp;$Msg = InputVars::stringGETPOST(\'Msg\');';
            $ret .= "<h3 style=\"background-color:#FCE19A\">$page **************************************</h3>try { $code<br>} catch (Exception \$e) {}<br><hr><br><div style=\"background-color:#ccc\">$detail</div>";
        }
        return $ret;
    }

    /**
     * @return array
     */
    public function analyze_variable(array $vals, int $visits=0): array
    {
        if (!$vals) {
            return [];
        }
        $first = true;
        $array    = false;
        $subarray = [];
        $empty = false;
        foreach ($vals as $v) {
            if (is_array($v)) {
                $array = true;
                $subarray = array_merge($subarray,$v);
                continue;
            }
            $vs = (string)$v;
            if (!strlen($vs)) {
                $empty = true;
                continue;
            }
            if ($first) {
                $first = false;
                $bool  = true;
                $int   = true;
                $float = true;
                $ids   = true;
                $alpha = true;
                $alnum = true;
                $string = true;
            }
            if (($vs != '1') and ($vs != '0') and ($vs != 'on')) {
                $bool = false;
            }
            if (!ctype_digit((string)$vs)) {
                $int = false;
            }
            if (!is_numeric($vs)) {
                $float = false;
            }
            if (!is_long_id($vs)) {
                $ids = false;
            }
            if (!ctype_alpha($vs)) {
                $alpha = false;
            }
            if (!ctype_alnum($vs)) {
                $alnum = false;
            }
        }
        $ret = ['main'=>'', 'sub'=>'', 'empty'=> $empty, 'req' => (count($vals)==$visits)];
        if ( $array ) {
            $ret['main'] = 'array';
            $ret['sub']  = $this->analyze_variable($subarray)['sub'];
        } elseif ( $bool ) {
            $ret['main'] = 'bool';
        } elseif ($int) {
            $ret['main'] = 'int';
        } elseif ($float) {
            $ret['main'] = 'float';
        } elseif ($ids) {
            $ret['main'] = 'id';
        } elseif ($alpha) {
            $ret['main'] = 'stringSlashed';
            $ret['sub']  = 'alpha';
        } elseif ($alnum) {
            $ret['main'] = 'stringSlashed';
            $ret['sub']  = 'alnum';
        } elseif ($string) {
            $ret['main'] = 'stringSlashed';
        } else {
            $ret['main'] = 'none';
        }
        return $ret;
    }

    protected function join2pages(string $page, string $type, ?string $serialized_arr): bool {
        if (!$serialized_arr or !is_array($arr = unserialize($serialized_arr))) {
            return false;
        }

        if (!is_array($this->pages[$page][$type])) {
            $this->pages[$page][$type] = [];
        }

        foreach ($arr as $name => $value) {
            $this->pages[$page][$type][$name][] = $value;
        }
        return true;
    }

}