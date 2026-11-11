<?php

// The SQL that Syntax (include/sql_parser.php, removed) built for these query values in column c0.text
// (DB_TYPE not MySQL, so identifiers in double quotes) - null where it reported a syntax error; and what
// AA_Dbcondition made of some of them: [join type, where]. Recorded from that code on 2026-11-11.

return [
    'syntax' =>  [
        'abc'
         => [
             '=' => '"c0"."text"= \'abc\'',
             'LIKE' => '"c0"."text"LIKE \'%abc%\'',
             'RLIKE' => '"c0"."text"LIKE \'abc%\'',
             'LLIKE' => '"c0"."text"LIKE \'%abc\'',
             'XLIKE' => '"c0"."text"LIKE \'abc\'',
         ],
        '"a b"'
         => [
             '=' => '"c0"."text"= \'a b\'',
             'LIKE' => '"c0"."text"LIKE \'%a b%\'',
             'RLIKE' => '"c0"."text"LIKE \'a b%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a b\'',
             'XLIKE' => '"c0"."text"LIKE \'a b\'',
         ],
        'a b'
         => [
             '=' => '"c0"."text"= \'a\' and "c0"."text"= \'b\'',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and "c0"."text"LIKE \'%b%\'',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and "c0"."text"LIKE \'b%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and "c0"."text"LIKE \'%b\'',
             'XLIKE' => '"c0"."text"LIKE \'a\' and "c0"."text"LIKE \'b\'',
         ],
        'a OR b'
         => [
             '=' => '"c0"."text"= \'a\' or "c0"."text"= \'b\'',
             'LIKE' => '"c0"."text"LIKE \'%a%\' or "c0"."text"LIKE \'%b%\'',
             'RLIKE' => '"c0"."text"LIKE \'a%\' or "c0"."text"LIKE \'b%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\' or "c0"."text"LIKE \'%b\'',
             'XLIKE' => '"c0"."text"LIKE \'a\' or "c0"."text"LIKE \'b\'',
         ],
        'a or b and c'
         => [
             '=' => '"c0"."text"= \'a\' or "c0"."text"= \'b\' and "c0"."text"= \'c\'',
             'LIKE' => '"c0"."text"LIKE \'%a%\' or "c0"."text"LIKE \'%b%\' and "c0"."text"LIKE \'%c%\'',
             'RLIKE' => '"c0"."text"LIKE \'a%\' or "c0"."text"LIKE \'b%\' and "c0"."text"LIKE \'c%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\' or "c0"."text"LIKE \'%b\' and "c0"."text"LIKE \'%c\'',
             'XLIKE' => '"c0"."text"LIKE \'a\' or "c0"."text"LIKE \'b\' and "c0"."text"LIKE \'c\'',
         ],
        '-a'
         => [
             '=' => 'not ("c0"."text"= \'a\')',
             'LIKE' => 'not ("c0"."text"LIKE \'%a%\')',
             'RLIKE' => 'not ("c0"."text"LIKE \'a%\')',
             'LLIKE' => 'not ("c0"."text"LIKE \'%a\')',
             'XLIKE' => 'not ("c0"."text"LIKE \'a\')',
         ],
        'a -b'
         => [
             '=' => '"c0"."text"= \'a\' and not ("c0"."text"= \'b\')',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and not ("c0"."text"LIKE \'%b%\')',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and not ("c0"."text"LIKE \'b%\')',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and not ("c0"."text"LIKE \'%b\')',
             'XLIKE' => '"c0"."text"LIKE \'a\' and not ("c0"."text"LIKE \'b\')',
         ],
        '+a +b'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '(a OR b) c'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        'NOT (a)'
         => [
             '=' => 'not (("c0"."text"= \'a\'))',
             'LIKE' => 'not (("c0"."text"LIKE \'%a%\'))',
             'RLIKE' => 'not (("c0"."text"LIKE \'a%\'))',
             'LLIKE' => 'not (("c0"."text"LIKE \'%a\'))',
             'XLIKE' => 'not (("c0"."text"LIKE \'a\'))',
         ],
        'x-y'
         => [
             '=' => '"c0"."text"= \'x-y\'',
             'LIKE' => '"c0"."text"LIKE \'%x-y%\'',
             'RLIKE' => '"c0"."text"LIKE \'x-y%\'',
             'LLIKE' => '"c0"."text"LIKE \'%x-y\'',
             'XLIKE' => '"c0"."text"LIKE \'x-y\'',
         ],
        'it\'s'
         => [
             '=' => '"c0"."text"= \'it\\\'s\'',
             'LIKE' => '"c0"."text"LIKE \'%it\\\'s%\'',
             'RLIKE' => '"c0"."text"LIKE \'it\\\'s%\'',
             'LLIKE' => '"c0"."text"LIKE \'%it\\\'s\'',
             'XLIKE' => '"c0"."text"LIKE \'it\\\'s\'',
         ],
        '\'quoted\''
         => [
             '=' => '"c0"."text"= \'quoted\'',
             'LIKE' => '"c0"."text"LIKE \'%quoted%\'',
             'RLIKE' => '"c0"."text"LIKE \'quoted%\'',
             'LLIKE' => '"c0"."text"LIKE \'%quoted\'',
             'XLIKE' => '"c0"."text"LIKE \'quoted\'',
         ],
        '"unclosed'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        'a OR'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '()'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '""'
         => [
             '=' => '"c0"."text"= \'\'',
             'LIKE' => '"c0"."text"LIKE \'%%\'',
             'RLIKE' => '"c0"."text"LIKE \'%\'',
             'LLIKE' => '"c0"."text"LIKE \'%\'',
             'XLIKE' => '"c0"."text"LIKE \'\'',
         ],
        'a)'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '[a]'
         => [
             '=' => '("c0"."text"= \'a\')',
             'LIKE' => '("c0"."text"LIKE \'%a%\')',
             'RLIKE' => '("c0"."text"LIKE \'a%\')',
             'LLIKE' => '("c0"."text"LIKE \'%a\')',
             'XLIKE' => '("c0"."text"LIKE \'a\')',
         ],
        '{a} OR b'
         => [
             '=' => '("c0"."text"= \'a\') or "c0"."text"= \'b\'',
             'LIKE' => '("c0"."text"LIKE \'%a%\') or "c0"."text"LIKE \'%b%\'',
             'RLIKE' => '("c0"."text"LIKE \'a%\') or "c0"."text"LIKE \'b%\'',
             'LLIKE' => '("c0"."text"LIKE \'%a\') or "c0"."text"LIKE \'%b\'',
             'XLIKE' => '("c0"."text"LIKE \'a\') or "c0"."text"LIKE \'b\'',
         ],
        'a\\\'b'
         => [
             '=' => '"c0"."text"= \'a\\\\\\\'b\'',
             'LIKE' => '"c0"."text"LIKE \'%a\\\\\\\'b%\'',
             'RLIKE' => '"c0"."text"LIKE \'a\\\\\\\'b%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\\\\\\\'b\'',
             'XLIKE' => '"c0"."text"LIKE \'a\\\\\\\'b\'',
         ],
        'ěšč OR "Ž ř"'
         => [
             '=' => '"c0"."text"= \'ěšč\' or "c0"."text"= \'Ž ř\'',
             'LIKE' => '"c0"."text"LIKE \'%ěšč%\' or "c0"."text"LIKE \'%Ž ř%\'',
             'RLIKE' => '"c0"."text"LIKE \'ěšč%\' or "c0"."text"LIKE \'Ž ř%\'',
             'LLIKE' => '"c0"."text"LIKE \'%ěšč\' or "c0"."text"LIKE \'%Ž ř\'',
             'XLIKE' => '"c0"."text"LIKE \'ěšč\' or "c0"."text"LIKE \'Ž ř\'',
         ],
        'a%_'
         => [
             '=' => '"c0"."text"= \'a%_\'',
             'LIKE' => '"c0"."text"LIKE \'%a%_%\'',
             'RLIKE' => '"c0"."text"LIKE \'a%_%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a%_\'',
             'XLIKE' => '"c0"."text"LIKE \'a%_\'',
         ],
        'tab	sep'
         => [
             '=' => '"c0"."text"= \'tab\' and "c0"."text"= \'sep\'',
             'LIKE' => '"c0"."text"LIKE \'%tab%\' and "c0"."text"LIKE \'%sep%\'',
             'RLIKE' => '"c0"."text"LIKE \'tab%\' and "c0"."text"LIKE \'sep%\'',
             'LLIKE' => '"c0"."text"LIKE \'%tab\' and "c0"."text"LIKE \'%sep\'',
             'XLIKE' => '"c0"."text"LIKE \'tab\' and "c0"."text"LIKE \'sep\'',
         ],
        'a - b'
         => [
             '=' => '"c0"."text"= \'a\' and not ("c0"."text"= \'b\')',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and not ("c0"."text"LIKE \'%b%\')',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and not ("c0"."text"LIKE \'b%\')',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and not ("c0"."text"LIKE \'%b\')',
             'XLIKE' => '"c0"."text"LIKE \'a\' and not ("c0"."text"LIKE \'b\')',
         ],
        'a-'
         => [
             '=' => '"c0"."text"= \'a-\'',
             'LIKE' => '"c0"."text"LIKE \'%a-%\'',
             'RLIKE' => '"c0"."text"LIKE \'a-%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a-\'',
             'XLIKE' => '"c0"."text"LIKE \'a-\'',
         ],
        '-'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '"a" "b"'
         => [
             '=' => '"c0"."text"= \'a\' and "c0"."text"= \'b\'',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and "c0"."text"LIKE \'%b%\'',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and "c0"."text"LIKE \'b%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and "c0"."text"LIKE \'%b\'',
             'XLIKE' => '"c0"."text"LIKE \'a\' and "c0"."text"LIKE \'b\'',
         ],
        'NOT'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        'a AND NOT b'
         => [
             '=' => '"c0"."text"= \'a\' and not ("c0"."text"= \'b\')',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and not ("c0"."text"LIKE \'%b%\')',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and not ("c0"."text"LIKE \'b%\')',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and not ("c0"."text"LIKE \'%b\')',
             'XLIKE' => '"c0"."text"LIKE \'a\' and not ("c0"."text"LIKE \'b\')',
         ],
        '(a'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        'a ) b'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        'NOT NOT a'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '"a" OR ("b" AND -c)'
         => [
             '=' => '"c0"."text"= \'a\' or ("c0"."text"= \'b\' and not ("c0"."text"= \'c\'))',
             'LIKE' => '"c0"."text"LIKE \'%a%\' or ("c0"."text"LIKE \'%b%\' and not ("c0"."text"LIKE \'%c%\'))',
             'RLIKE' => '"c0"."text"LIKE \'a%\' or ("c0"."text"LIKE \'b%\' and not ("c0"."text"LIKE \'c%\'))',
             'LLIKE' => '"c0"."text"LIKE \'%a\' or ("c0"."text"LIKE \'%b\' and not ("c0"."text"LIKE \'%c\'))',
             'XLIKE' => '"c0"."text"LIKE \'a\' or ("c0"."text"LIKE \'b\' and not ("c0"."text"LIKE \'c\'))',
         ],
        'a and (b or c) or not d'
         => [
             '=' => '"c0"."text"= \'a\' and ("c0"."text"= \'b\' or "c0"."text"= \'c\') or not ("c0"."text"= \'d\')',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and ("c0"."text"LIKE \'%b%\' or "c0"."text"LIKE \'%c%\') or not ("c0"."text"LIKE \'%d%\')',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and ("c0"."text"LIKE \'b%\' or "c0"."text"LIKE \'c%\') or not ("c0"."text"LIKE \'d%\')',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and ("c0"."text"LIKE \'%b\' or "c0"."text"LIKE \'%c\') or not ("c0"."text"LIKE \'%d\')',
             'XLIKE' => '"c0"."text"LIKE \'a\' and ("c0"."text"LIKE \'b\' or "c0"."text"LIKE \'c\') or not ("c0"."text"LIKE \'d\')',
         ],
        ''
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '  '
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        'O\''
         => [
             '=' => '"c0"."text"= \'O\'',
             'LIKE' => '"c0"."text"LIKE \'%O%\'',
             'RLIKE' => '"c0"."text"LIKE \'O%\'',
             'LLIKE' => '"c0"."text"LIKE \'%O\'',
             'XLIKE' => '"c0"."text"LIKE \'O\'',
         ],
        '\'x'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        '"Internet & ICT"'
         => [
             '=' => '"c0"."text"= \'Internet & ICT\'',
             'LIKE' => '"c0"."text"LIKE \'%Internet & ICT%\'',
             'RLIKE' => '"c0"."text"LIKE \'Internet & ICT%\'',
             'LLIKE' => '"c0"."text"LIKE \'%Internet & ICT\'',
             'XLIKE' => '"c0"."text"LIKE \'Internet & ICT\'',
         ],
        'C:\\dir'
         => [
             '=' => '"c0"."text"= \'C:\\\\dir\'',
             'LIKE' => '"c0"."text"LIKE \'%C:\\\\dir%\'',
             'RLIKE' => '"c0"."text"LIKE \'C:\\\\dir%\'',
             'LLIKE' => '"c0"."text"LIKE \'%C:\\\\dir\'',
             'XLIKE' => '"c0"."text"LIKE \'C:\\\\dir\'',
         ],
        '((a))'
         => [
             '=' => '(("c0"."text"= \'a\'))',
             'LIKE' => '(("c0"."text"LIKE \'%a%\'))',
             'RLIKE' => '(("c0"."text"LIKE \'a%\'))',
             'LLIKE' => '(("c0"."text"LIKE \'%a\'))',
             'XLIKE' => '(("c0"."text"LIKE \'a\'))',
         ],
        'a+b'
         => [
             '=' => '"c0"."text"= \'a\' and "c0"."text"= \'b\'',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and "c0"."text"LIKE \'%b%\'',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and "c0"."text"LIKE \'b%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and "c0"."text"LIKE \'%b\'',
             'XLIKE' => '"c0"."text"LIKE \'a\' and "c0"."text"LIKE \'b\'',
         ],
        'a OR - b'
         => [
             '=' => '"c0"."text"= \'a\' or not ("c0"."text"= \'b\')',
             'LIKE' => '"c0"."text"LIKE \'%a%\' or not ("c0"."text"LIKE \'%b%\')',
             'RLIKE' => '"c0"."text"LIKE \'a%\' or not ("c0"."text"LIKE \'b%\')',
             'LLIKE' => '"c0"."text"LIKE \'%a\' or not ("c0"."text"LIKE \'%b\')',
             'XLIKE' => '"c0"."text"LIKE \'a\' or not ("c0"."text"LIKE \'b\')',
         ],
        'or'
         => [
             '=' => null,
             'LIKE' => null,
             'RLIKE' => null,
             'LLIKE' => null,
             'XLIKE' => null,
         ],
        'a "b c" d'
         => [
             '=' => '"c0"."text"= \'a\' and "c0"."text"= \'b c\' and "c0"."text"= \'d\'',
             'LIKE' => '"c0"."text"LIKE \'%a%\' and "c0"."text"LIKE \'%b c%\' and "c0"."text"LIKE \'%d%\'',
             'RLIKE' => '"c0"."text"LIKE \'a%\' and "c0"."text"LIKE \'b c%\' and "c0"."text"LIKE \'d%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\' and "c0"."text"LIKE \'%b c\' and "c0"."text"LIKE \'%d\'',
             'XLIKE' => '"c0"."text"LIKE \'a\' and "c0"."text"LIKE \'b c\' and "c0"."text"LIKE \'d\'',
         ],
        'x\'y\'z'
         => [
             '=' => '"c0"."text"= \'x\\\'y\\\'z\'',
             'LIKE' => '"c0"."text"LIKE \'%x\\\'y\\\'z%\'',
             'RLIKE' => '"c0"."text"LIKE \'x\\\'y\\\'z%\'',
             'LLIKE' => '"c0"."text"LIKE \'%x\\\'y\\\'z\'',
             'XLIKE' => '"c0"."text"LIKE \'x\\\'y\\\'z\'',
         ],
        'a\'\'b'
         => [
             '=' => '"c0"."text"= \'a\\\'\\\'b\'',
             'LIKE' => '"c0"."text"LIKE \'%a\\\'\\\'b%\'',
             'RLIKE' => '"c0"."text"LIKE \'a\\\'\\\'b%\'',
             'LLIKE' => '"c0"."text"LIKE \'%a\\\'\\\'b\'',
             'XLIKE' => '"c0"."text"LIKE \'a\\\'\\\'b\'',
         ],
    ],
    'dbcondition' =>  [
        'abc'
         => [
             '='
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"= \'abc\')',
              ],
             'LIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%abc%\')',
              ],
             'RLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'abc%\')',
              ],
             'LLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%abc\')',
              ],
             'XLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'abc\')',
              ],
         ],
        'a* OR b?'
         => [
             '='
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"= \'a*\' or "c0"."text"= \'b?\')',
              ],
             'LIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%a%%\' or "c0"."text"LIKE \'%b_%\')',
              ],
             'RLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'a%%\' or "c0"."text"LIKE \'b_%\')',
              ],
             'LLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%a%\' or "c0"."text"LIKE \'%b_\')',
              ],
             'XLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'a*\' or "c0"."text"LIKE \'b?\')',
              ],
         ],
        '"x y" -z'
         => [
             '='
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"= \'x y\' and not ("c0"."text"= \'z\'))',
              ],
             'LIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%x y%\' and not ("c0"."text"LIKE \'%z%\'))',
              ],
             'RLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'x y%\' and not ("c0"."text"LIKE \'z%\'))',
              ],
             'LLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%x y\' and not ("c0"."text"LIKE \'%z\'))',
              ],
             'XLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'x y\' and not ("c0"."text"LIKE \'z\'))',
              ],
         ],
        'a OR'
         => [
             '='
              => [
                  0 => 'ALL',
                  1 => '1=1',
              ],
             'LIKE'
              => [
                  0 => 'ALL',
                  1 => '1=1',
              ],
             'RLIKE'
              => [
                  0 => 'ALL',
                  1 => '1=1',
              ],
             'LLIKE'
              => [
                  0 => 'ALL',
                  1 => '1=1',
              ],
             'XLIKE'
              => [
                  0 => 'ALL',
                  1 => '1=1',
              ],
         ],
        'it\'s'
         => [
             '='
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"= \'it\\\'s\')',
              ],
             'LIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%it\\\'s%\')',
              ],
             'RLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'it\\\'s%\')',
              ],
             'LLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'%it\\\'s\')',
              ],
             'XLIKE'
              => [
                  0 => 'INNER',
                  1 => '("c0"."text"LIKE \'it\\\'s\')',
              ],
         ],
    ],
];
