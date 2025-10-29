<?php
/**
 * CSS Tokenizer Test Cases generated from @rmenke/css-tokenizer-tests.
 * Indices are UTF-8 byte offsets.
 * String and URL values are already decoded.
 */

return array (
  'tests/at-keyword/0001' => 
  array (
    'css' => '@foo
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'at-keyword-token',
        'raw' => '@foo',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0002' => 
  array (
    'css' => '@--
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'at-keyword-token',
        'raw' => '@--',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '--',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0003' => 
  array (
    'css' => '@-1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '@',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '@',
        ),
      ),
      1 => 
      array (
        'type' => 'number-token',
        'raw' => '-1',
        'startIndex' => 1,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -1,
          'type' => 'integer',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0004' => 
  array (
    'css' => '@--1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'at-keyword-token',
        'raw' => '@--1',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => '--1',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0005' => 
  array (
    'css' => '@\\@
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'at-keyword-token',
        'raw' => '@\\@',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '@',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0006' => 
  array (
    'css' => '@_
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'at-keyword-token',
        'raw' => '@_',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '_',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0007' => 
  array (
    'css' => '@
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '@',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '@',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0008' => 
  array (
    'css' => 'pvA3@\\
eBnP
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => 'pvA3',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'pvA3',
        ),
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '@',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '@',
        ),
      ),
      2 => 
      array (
        'type' => 'delim-token',
        'raw' => '\\',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => '\\',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
      4 => 
      array (
        'type' => 'ident-token',
        'raw' => 'eBnP',
        'startIndex' => 7,
        'endIndex' => 11,
        'structured' => 
        array (
          'value' => 'eBnP',
        ),
      ),
      5 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/at-keyword/0009' => 
  array (
    'css' => '@aa𐀀
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'at-keyword-token',
        'raw' => '@aa𐀀',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'aa𐀀',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-string/0001' => 
  array (
    'css' => '"foo
"
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-string-token',
        'raw' => '"foo',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'bad-string-token',
        'raw' => '"',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-string/0002' => 
  array (
    'css' => '"foo\\
"
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"foo\\
"',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-string/0003' => 
  array (
    'css' => '"foo
"
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-string-token',
        'raw' => '"foo',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'bad-string-token',
        'raw' => '"',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-string/0004' => 
  array (
    'css' => '"foo\\
"
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"foo\\
"',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 8,
        'endIndex' => 9,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-string/0005' => 
  array (
    'css' => '"aa𐀀
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-string-token',
        'raw' => '"aa𐀀',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-url/0001' => 
  array (
    'css' => 'url(
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url(
',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '',
        ),
      ),
    ),
  ),
  'tests/bad-url/0002' => 
  array (
    'css' => 'url( a
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url( a
',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
    ),
  ),
  'tests/bad-url/0003' => 
  array (
    'css' => 'url( a a
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-url-token',
        'raw' => 'url( a a
',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-url/0004' => 
  array (
    'css' => 'url( a a)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-url-token',
        'raw' => 'url( a a)',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-url/0005' => 
  array (
    'css' => 'url( a a\\)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-url-token',
        'raw' => 'url( a a\\)
',
        'startIndex' => 0,
        'endIndex' => 11,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-url/0006' => 
  array (
    'css' => 'url( \\
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-url-token',
        'raw' => 'url( \\
',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-url/0007' => 
  array (
    'css' => 'url(a\'\')
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-url-token',
        'raw' => 'url(a\'\')',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 8,
        'endIndex' => 9,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/bad-url/0008' => 
  array (
    'css' => 'url(a")
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-url-token',
        'raw' => 'url(a")',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/colon/0001' => 
  array (
    'css' => ':
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'colon-token',
        'raw' => ':',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/comma/0001' => 
  array (
    'css' => ',
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'comma-token',
        'raw' => ',',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/comment/0001' => 
  array (
    'css' => '/* a comment */
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'comment',
        'raw' => '/* a comment */',
        'startIndex' => 0,
        'endIndex' => 15,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 15,
        'endIndex' => 16,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/comment/0002' => 
  array (
    'css' => '/* a comment ',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'comment',
        'raw' => '/* a comment ',
        'startIndex' => 0,
        'endIndex' => 13,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/comment/0003' => 
  array (
    'css' => 'a/**/b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => 'a',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'comment',
        'raw' => '/**/',
        'startIndex' => 1,
        'endIndex' => 5,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'ident-token',
        'raw' => 'b',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 'b',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/comment/0004' => 
  array (
    'css' => '/*\\*/*/
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'comment',
        'raw' => '/*\\*/',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '*',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => '*',
        ),
      ),
      2 => 
      array (
        'type' => 'delim-token',
        'raw' => '/',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => '/',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/comment/0005' => 
  array (
    'css' => '/* a comment *',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'comment',
        'raw' => '/* a comment *',
        'startIndex' => 0,
        'endIndex' => 14,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/comment/0006' => 
  array (
    'css' => '/*a𐀀*/
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'comment',
        'raw' => '/*a𐀀*/',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/digit/0001' => 
  array (
    'css' => '0
1
2
3
4
5
6
7
8
9
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '0',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 0,
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'number-token',
        'raw' => '1',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 1,
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
      4 => 
      array (
        'type' => 'number-token',
        'raw' => '2',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 2,
        ),
      ),
      5 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      6 => 
      array (
        'type' => 'number-token',
        'raw' => '3',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 3,
        ),
      ),
      7 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
      8 => 
      array (
        'type' => 'number-token',
        'raw' => '4',
        'startIndex' => 8,
        'endIndex' => 9,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 4,
        ),
      ),
      9 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
      10 => 
      array (
        'type' => 'number-token',
        'raw' => '5',
        'startIndex' => 10,
        'endIndex' => 11,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 5,
        ),
      ),
      11 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
      12 => 
      array (
        'type' => 'number-token',
        'raw' => '6',
        'startIndex' => 12,
        'endIndex' => 13,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 6,
        ),
      ),
      13 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 13,
        'endIndex' => 14,
        'structured' => NULL,
      ),
      14 => 
      array (
        'type' => 'number-token',
        'raw' => '7',
        'startIndex' => 14,
        'endIndex' => 15,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 7,
        ),
      ),
      15 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 15,
        'endIndex' => 16,
        'structured' => NULL,
      ),
      16 => 
      array (
        'type' => 'number-token',
        'raw' => '8',
        'startIndex' => 16,
        'endIndex' => 17,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 8,
        ),
      ),
      17 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 17,
        'endIndex' => 18,
        'structured' => NULL,
      ),
      18 => 
      array (
        'type' => 'number-token',
        'raw' => '9',
        'startIndex' => 18,
        'endIndex' => 19,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 9,
        ),
      ),
      19 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 19,
        'endIndex' => 20,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/dimension/0001' => 
  array (
    'css' => '10px
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10px',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 10,
          'type' => 'integer',
          'unit' => 'px',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/dimension/0002' => 
  array (
    'css' => '10\\70 x
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10\\70 x',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 10,
          'type' => 'integer',
          'unit' => 'px',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/dimension/0003' => 
  array (
    'css' => '10--custom-px
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10--custom-px',
        'startIndex' => 0,
        'endIndex' => 13,
        'structured' => 
        array (
          'value' => 10,
          'type' => 'integer',
          'unit' => '--custom-px',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 13,
        'endIndex' => 14,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/dimension/0004' => 
  array (
    'css' => '10e2px
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10e2px',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 1000,
          'type' => 'number',
          'unit' => 'px',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/dimension/0005' => 
  array (
    'css' => '10E2PX
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10E2PX',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 1000,
          'type' => 'number',
          'unit' => 'PX',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/dimension/0006' => 
  array (
    'css' => '10\\0
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10\\0
',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 10,
          'type' => 'integer',
          'unit' => '�',
        ),
      ),
    ),
  ),
  'tests/dimension/0007' => 
  array (
    'css' => '10a𐀀
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10a𐀀',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 10,
          'type' => 'integer',
          'unit' => 'a𐀀',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/dimension/0008' => 
  array (
    'css' => '10a' . "\0" . '',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '10a' . "\0" . '',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 10,
          'type' => 'integer',
          'unit' => 'a�',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0001' => 
  array (
    'css' => '\\',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0002' => 
  array (
    'css' => '\\0',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\0',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0003' => 
  array (
    'css' => '\\\\',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\\\',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '\\',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0004' => 
  array (
    'css' => '\\0a b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\0a b',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '
b',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0005' => 
  array (
    'css' => '\\0ab 
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\0ab ',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '«',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0006' => 
  array (
    'css' => '\\0ab (foo)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => '\\0ab (',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => '«',
        ),
      ),
      1 => 
      array (
        'type' => 'ident-token',
        'raw' => 'foo',
        'startIndex' => 6,
        'endIndex' => 9,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      2 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 10,
        'endIndex' => 11,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0007' => 
  array (
    'css' => '\\0ab  (foo)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\0ab ',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '«',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => ' ',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => '(-token',
        'raw' => '(',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
      3 => 
      array (
        'type' => 'ident-token',
        'raw' => 'foo',
        'startIndex' => 7,
        'endIndex' => 10,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      4 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 10,
        'endIndex' => 11,
        'structured' => NULL,
      ),
      5 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0008' => 
  array (
    'css' => '\\0000ab
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\0000ab
',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => '«',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0009' => 
  array (
    'css' => '\\00000ab
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\00000ab',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => '
b',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 8,
        'endIndex' => 9,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0010' => 
  array (
    'css' => '\\110000
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\110000
',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0011' => 
  array (
    'css' => '\\00D800
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\00D800
',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0012' => 
  array (
    'css' => '\\00DFFF
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\00DFFF
',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
    ),
  ),
  'tests/escaped-code-point/0013' => 
  array (
    'css' => '\\
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '\\',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '\\',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0014' => 
  array (
    'css' => '\\' . "\0" . '
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\' . "\0" . '',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0015' => 
  array (
    'css' => '\\' . "\0" . '
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\' . "\0" . '',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/escaped-code-point/0016' => 
  array (
    'css' => '"a\\12
b"',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"a\\12
b"',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => 
        array (
          'value' => 'ab',
        ),
      ),
    ),
  ),
  'tests/full-stop/0001' => 
  array (
    'css' => '.
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '.',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '.',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/full-stop/0002' => 
  array (
    'css' => '.a
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '.',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '.',
        ),
      ),
      1 => 
      array (
        'type' => 'ident-token',
        'raw' => 'a',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/full-stop/0003' => 
  array (
    'css' => '.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '.1',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => 0.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0001' => 
  array (
    'css' => '#1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#1',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '1',
          'type' => 'unrestricted',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0002' => 
  array (
    'css' => '#-2
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#-2',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '-2',
          'type' => 'unrestricted',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0003' => 
  array (
    'css' => '#--3
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#--3',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => '--3',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0004' => 
  array (
    'css' => '#---4
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#---4',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '---4',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0005' => 
  array (
    'css' => '#a
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#a',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => 'a',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0006' => 
  array (
    'css' => '#-b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#-b',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '-b',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0007' => 
  array (
    'css' => '#--c
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#--c',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => '--c',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0008' => 
  array (
    'css' => '#---d
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#---d',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '---d',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0009' => 
  array (
    'css' => '#_
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#_',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '_',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0010' => 
  array (
    'css' => '#_1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#_1',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '_1',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0011' => 
  array (
    'css' => '#-
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#-',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '-',
          'type' => 'unrestricted',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0012' => 
  array (
    'css' => '#+
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '#',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '#',
        ),
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '+',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '+',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0013' => 
  array (
    'css' => '##
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '#',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '#',
        ),
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '#',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '#',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hash/0014' => 
  array (
    'css' => '#',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '#',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '#',
        ),
      ),
    ),
  ),
  'tests/hash/0015' => 
  array (
    'css' => '#aa𐀀
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'hash-token',
        'raw' => '#aa𐀀',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'aa𐀀',
          'type' => 'id',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hyphen-minus/0001' => 
  array (
    'css' => '-
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '-',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '-',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hyphen-minus/0002' => 
  array (
    'css' => '-1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-1',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -1,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hyphen-minus/0003' => 
  array (
    'css' => '-.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-.1',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -0.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hyphen-minus/0004' => 
  array (
    'css' => '--1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '--1',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '--1',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hyphen-minus/0005' => 
  array (
    'css' => '-0
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-0',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => 0,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/hyphen-minus/0006' => 
  array (
    'css' => '-->
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'CDC-token',
        'raw' => '-->',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0001' => 
  array (
    'css' => 'url(foo)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url(foo)',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 8,
        'endIndex' => 9,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0002' => 
  array (
    'css' => '\\75 Rl(foo)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => '\\75 Rl(foo)',
        'startIndex' => 0,
        'endIndex' => 11,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0003' => 
  array (
    'css' => 'uR\\6c (foo)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'uR\\6c (foo)',
        'startIndex' => 0,
        'endIndex' => 11,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0004' => 
  array (
    'css' => 'url(\'foo\')
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => 'url(',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'url',
        ),
      ),
      1 => 
      array (
        'type' => 'string-token',
        'raw' => '\'foo\'',
        'startIndex' => 4,
        'endIndex' => 9,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      2 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 10,
        'endIndex' => 11,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0005' => 
  array (
    'css' => 'url( \'foo\')
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => 'url(',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'url',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => ' ',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'string-token',
        'raw' => '\'foo\'',
        'startIndex' => 5,
        'endIndex' => 10,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      3 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 10,
        'endIndex' => 11,
        'structured' => NULL,
      ),
      4 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0006' => 
  array (
    'css' => 'url(  \'foo\')
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => 'url(',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'url',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '  ',
        'startIndex' => 4,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'string-token',
        'raw' => '\'foo\'',
        'startIndex' => 6,
        'endIndex' => 11,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      3 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
      4 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 12,
        'endIndex' => 13,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0007' => 
  array (
    'css' => 'url(   \'foo\')
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => 'url(',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'url',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '   ',
        'startIndex' => 4,
        'endIndex' => 7,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'string-token',
        'raw' => '\'foo\'',
        'startIndex' => 7,
        'endIndex' => 12,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      3 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 12,
        'endIndex' => 13,
        'structured' => NULL,
      ),
      4 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 13,
        'endIndex' => 14,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0008' => 
  array (
    'css' => 'not-url(   \'foo\')
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => 'not-url(',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => 'not-url',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '   ',
        'startIndex' => 8,
        'endIndex' => 11,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'string-token',
        'raw' => '\'foo\'',
        'startIndex' => 11,
        'endIndex' => 16,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      3 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 16,
        'endIndex' => 17,
        'structured' => NULL,
      ),
      4 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 17,
        'endIndex' => 18,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident-like/0009' => 
  array (
    'css' => 'url(   foo)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url(   foo)',
        'startIndex' => 0,
        'endIndex' => 11,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0001' => 
  array (
    'css' => 'foo
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => 'foo',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0002' => 
  array (
    'css' => '--
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '--',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '--',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0003' => 
  array (
    'css' => '--0
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '--0',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '--0',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0004' => 
  array (
    'css' => '-\\
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '-',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '-',
        ),
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '\\',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '\\',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0005' => 
  array (
    'css' => '-\\ 
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '-\\ ',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '- ',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0006' => 
  array (
    'css' => '--💅
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '--💅',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => '--💅',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0008' => 
  array (
    'css' => '-×
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '-×',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '-×',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/ident/0009' => 
  array (
    'css' => '--a𐀀
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '--a𐀀',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => '--a𐀀',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/left-curly-bracket/0001' => 
  array (
    'css' => '{
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => '{-token',
        'raw' => '{',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/left-parenthesis/0001' => 
  array (
    'css' => '(
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => '(-token',
        'raw' => '(',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/left-square-bracket/0001' => 
  array (
    'css' => '[
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => '[-token',
        'raw' => '[',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/less-than/0001' => 
  array (
    'css' => '<
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '<',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '<',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/less-than/0002' => 
  array (
    'css' => '<!--
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'CDO-token',
        'raw' => '<!--',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/less-than/0003' => 
  array (
    'css' => '<--
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '<',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '<',
        ),
      ),
      1 => 
      array (
        'type' => 'ident-token',
        'raw' => '--',
        'startIndex' => 1,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '--',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/less-than/0004' => 
  array (
    'css' => '<!-
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '<',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '<',
        ),
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '!',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '!',
        ),
      ),
      2 => 
      array (
        'type' => 'delim-token',
        'raw' => '-',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '-',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0001' => 
  array (
    'css' => '10
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '10',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => 10,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0002' => 
  array (
    'css' => '+10
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '+10',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 10,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0003' => 
  array (
    'css' => '-10
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-10',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -10,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0004' => 
  array (
    'css' => '0
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '0',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => 0,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0005' => 
  array (
    'css' => '+0
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '+0',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 0,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0006' => 
  array (
    'css' => '-0
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-0',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => 0,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0007' => 
  array (
    'css' => '.0
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '.0',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => 0,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0008' => 
  array (
    'css' => '.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '.1',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => 0.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0009' => 
  array (
    'css' => '+.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '+.1',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 0.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0010' => 
  array (
    'css' => '-.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-.1',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -0.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0011' => 
  array (
    'css' => '1.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '1.1',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => 1.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0012' => 
  array (
    'css' => '+1.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '+1.1',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 1.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0013' => 
  array (
    'css' => '-1.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-1.1',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -1.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0014' => 
  array (
    'css' => '1.1e2
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '1.1e2',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 110,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0015' => 
  array (
    'css' => '+1.1e+2
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '+1.1e+2',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 110,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0016' => 
  array (
    'css' => '-1.1e-2
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-1.1e-2',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -0.011,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0017' => 
  array (
    'css' => '-1.1e-22
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-1.1e-22',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -1.1E-22,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 8,
        'endIndex' => 9,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0018' => 
  array (
    'css' => '-1.1e-22e
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '-1.1e-22e',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -1.1E-22,
          'type' => 'number',
          'unit' => 'e',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0019' => 
  array (
    'css' => '1e+
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '1e',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'type' => 'integer',
          'value' => 1,
          'unit' => 'e',
        ),
      ),
      1 => 
      array (
        'type' => 'delim-token',
        'raw' => '+',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => 
        array (
          'value' => '+',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/number/0020' => 
  array (
    'css' => '.2.7
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '.2',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'type' => 'number',
          'value' => 0.2,
        ),
      ),
      1 => 
      array (
        'type' => 'number-token',
        'raw' => '.7',
        'startIndex' => 2,
        'endIndex' => 4,
        'structured' => 
        array (
          'type' => 'number',
          'value' => 0.7,
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/numeric/0001' => 
  array (
    'css' => '-123.753e-2
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '-123.753e-2',
        'startIndex' => 0,
        'endIndex' => 11,
        'structured' => 
        array (
          'signCharacter' => '-',
          'type' => 'number',
          'value' => -1.23753,
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/numeric/0002' => 
  array (
    'css' => '-123.753e-2px
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'dimension-token',
        'raw' => '-123.753e-2px',
        'startIndex' => 0,
        'endIndex' => 13,
        'structured' => 
        array (
          'signCharacter' => '-',
          'type' => 'number',
          'unit' => 'px',
          'value' => -1.23753,
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 13,
        'endIndex' => 14,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/numeric/0003' => 
  array (
    'css' => '-123.753e-2%
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'percentage-token',
        'raw' => '-123.753e-2%',
        'startIndex' => 0,
        'endIndex' => 12,
        'structured' => 
        array (
          'signCharacter' => '-',
          'value' => -1.23753,
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 12,
        'endIndex' => 13,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/numeric/0004' => 
  array (
    'css' => '1.2.3
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '1.2',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'type' => 'number',
          'value' => 1.2,
        ),
      ),
      1 => 
      array (
        'type' => 'number-token',
        'raw' => '.3',
        'startIndex' => 3,
        'endIndex' => 5,
        'structured' => 
        array (
          'type' => 'number',
          'value' => 0.3,
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/plus/0001' => 
  array (
    'css' => '+
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '+',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '+',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/plus/0002' => 
  array (
    'css' => '+1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '+1',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 1,
          'type' => 'integer',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/plus/0003' => 
  array (
    'css' => '+.1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'number-token',
        'raw' => '+.1',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 0.1,
          'type' => 'number',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/plus/0004' => 
  array (
    'css' => '++1
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '+',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '+',
        ),
      ),
      1 => 
      array (
        'type' => 'number-token',
        'raw' => '+1',
        'startIndex' => 1,
        'endIndex' => 3,
        'structured' => 
        array (
          'signCharacter' => '+',
          'value' => 1,
          'type' => 'integer',
        ),
      ),
      2 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/reverse-solidus/0001' => 
  array (
    'css' => '\\
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'delim-token',
        'raw' => '\\',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '\\',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/reverse-solidus/0002' => 
  array (
    'css' => '\\#
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\#',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => '#',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/reverse-solidus/0003' => 
  array (
    'css' => '\\ 
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\ ',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => 
        array (
          'value' => ' ',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 2,
        'endIndex' => 3,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/reverse-solidus/0004' => 
  array (
    'css' => '\\61 b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\61 b',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 'ab',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/reverse-solidus/0005' => 
  array (
    'css' => '\\',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => '�',
        ),
      ),
    ),
  ),
  'tests/right-curly-bracket/0001' => 
  array (
    'css' => '}
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => '}-token',
        'raw' => '}',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/right-parenthesis/0001' => 
  array (
    'css' => ')
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/right-square-bracket/0001' => 
  array (
    'css' => ']
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => ']-token',
        'raw' => ']',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/semi-colon/0001' => 
  array (
    'css' => ';
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'semicolon-token',
        'raw' => ';',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 1,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/string/0001' => 
  array (
    'css' => '"foo"
\'foo\'
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"foo"',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'string-token',
        'raw' => '\'foo\'',
        'startIndex' => 6,
        'endIndex' => 11,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 11,
        'endIndex' => 12,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/string/0002' => 
  array (
    'css' => '"foo',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"foo',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
    ),
  ),
  'tests/string/0003' => 
  array (
    'css' => '"fo
o"',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'bad-string-token',
        'raw' => '"fo',
        'startIndex' => 0,
        'endIndex' => 3,
        'structured' => NULL,
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'ident-token',
        'raw' => 'o',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 'o',
        ),
      ),
      3 => 
      array (
        'type' => 'string-token',
        'raw' => '"',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => '',
        ),
      ),
    ),
  ),
  'tests/string/0004' => 
  array (
    'css' => '"fo\\
o"
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"fo\\
o"',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'foo',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/string/0005' => 
  array (
    'css' => '"fo\\',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"fo\\',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'fo',
        ),
      ),
    ),
  ),
  'tests/string/0006' => 
  array (
    'css' => '"esc\\61 ped"
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"esc\\61 ped"',
        'startIndex' => 0,
        'endIndex' => 12,
        'structured' => 
        array (
          'value' => 'escaped',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 12,
        'endIndex' => 13,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/string/0007' => 
  array (
    'css' => '"foo\\"',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"foo\\"',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 'foo"',
        ),
      ),
    ),
  ),
  'tests/string/0008' => 
  array (
    'css' => '"\'foo\'"
\'"foo"\'
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"\'foo\'"',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => '\'foo\'',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'string-token',
        'raw' => '\'"foo"\'',
        'startIndex' => 8,
        'endIndex' => 15,
        'structured' => 
        array (
          'value' => '"foo"',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 15,
        'endIndex' => 16,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/string/0009' => 
  array (
    'css' => '"\\"foo\\""
\'\\\'foo\\\'\'
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'string-token',
        'raw' => '"\\"foo\\""',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => 
        array (
          'value' => '"foo"',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'string-token',
        'raw' => '\'\\\'foo\\\'\'',
        'startIndex' => 10,
        'endIndex' => 19,
        'structured' => 
        array (
          'value' => '\'foo\'',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 19,
        'endIndex' => 20,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0001' => 
  array (
    'css' => 'url(
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url(
',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '',
        ),
      ),
    ),
  ),
  'tests/url/0002' => 
  array (
    'css' => 'url(a
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url(a
',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
    ),
  ),
  'tests/url/0003' => 
  array (
    'css' => 'url( a
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url( a
',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
    ),
  ),
  'tests/url/0004' => 
  array (
    'css' => 'url()
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url()',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => '',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0005' => 
  array (
    'css' => 'url( )
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url( )',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => '',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0006' => 
  array (
    'css' => 'url( a)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url( a)',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0007' => 
  array (
    'css' => 'url( a )
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url( a )',
        'startIndex' => 0,
        'endIndex' => 8,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 8,
        'endIndex' => 9,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0008' => 
  array (
    'css' => 'url( \\) )
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url( \\) )',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => 
        array (
          'value' => ')',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0009' => 
  array (
    'css' => 'url(https://https:⁄⁄www.netmeister.org@https://www.netmeister.org/https:⁄⁄www.netmeister.org⁄?https://www.netmeister.org=https://www.netmeister.org;https://www.netmeister.org#https://www.netmeister.org)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url(https://https:⁄⁄www.netmeister.org@https://www.netmeister.org/https:⁄⁄www.netmeister.org⁄?https://www.netmeister.org=https://www.netmeister.org;https://www.netmeister.org#https://www.netmeister.org)',
        'startIndex' => 0,
        'endIndex' => 212,
        'structured' => 
        array (
          'value' => 'https://https:⁄⁄www.netmeister.org@https://www.netmeister.org/https:⁄⁄www.netmeister.org⁄?https://www.netmeister.org=https://www.netmeister.org;https://www.netmeister.org#https://www.netmeister.org',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 212,
        'endIndex' => 213,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0010' => 
  array (
    'css' => 'url(https://www.netmeister.org/%62%6C%6F%67/%75%72%6C%73%2E%68%74%6D%6C?!@#$%25=+_\\)\\(*&^#top)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'url(https://www.netmeister.org/%62%6C%6F%67/%75%72%6C%73%2E%68%74%6D%6C?!@#$%25=+_\\)\\(*&^#top)',
        'startIndex' => 0,
        'endIndex' => 94,
        'structured' => 
        array (
          'value' => 'https://www.netmeister.org/%62%6C%6F%67/%75%72%6C%73%2E%68%74%6D%6C?!@#$%25=+_)(*&^#top',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 94,
        'endIndex' => 95,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0011' => 
  array (
    'css' => 'Url(a)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'Url(a)',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0012' => 
  array (
    'css' => 'uRl(a)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'uRl(a)',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0013' => 
  array (
    'css' => 'urL(a)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'url-token',
        'raw' => 'urL(a)',
        'startIndex' => 0,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0014' => 
  array (
    'css' => 'uri(a)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => 'uri(',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'uri',
        ),
      ),
      1 => 
      array (
        'type' => 'ident-token',
        'raw' => 'a',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      2 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/url/0015' => 
  array (
    'css' => 'uul(a)
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'function-token',
        'raw' => 'uul(',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'uul',
        ),
      ),
      1 => 
      array (
        'type' => 'ident-token',
        'raw' => 'a',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      2 => 
      array (
        'type' => ')-token',
        'raw' => ')',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0001' => 
  array (
    'css' => '
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0002' => 
  array (
    'css' => ' 
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'whitespace-token',
        'raw' => ' 
',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0003' => 
  array (
    'css' => 'a  b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => 'a',
        'startIndex' => 0,
        'endIndex' => 1,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '  ',
        'startIndex' => 1,
        'endIndex' => 3,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'ident-token',
        'raw' => 'b',
        'startIndex' => 3,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'b',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0004' => 
  array (
    'css' => '\\61 b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\61 b',
        'startIndex' => 0,
        'endIndex' => 5,
        'structured' => 
        array (
          'value' => 'ab',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0005' => 
  array (
    'css' => '\\000061 b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\000061 b',
        'startIndex' => 0,
        'endIndex' => 9,
        'structured' => 
        array (
          'value' => 'ab',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 9,
        'endIndex' => 10,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0006' => 
  array (
    'css' => '\\61  b
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => '\\61 ',
        'startIndex' => 0,
        'endIndex' => 4,
        'structured' => 
        array (
          'value' => 'a',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => ' ',
        'startIndex' => 4,
        'endIndex' => 5,
        'structured' => NULL,
      ),
      2 => 
      array (
        'type' => 'ident-token',
        'raw' => 'b',
        'startIndex' => 5,
        'endIndex' => 6,
        'structured' => 
        array (
          'value' => 'b',
        ),
      ),
      3 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 6,
        'endIndex' => 7,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0007' => 
  array (
    'css' => '	
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '	
',
        'startIndex' => 0,
        'endIndex' => 2,
        'structured' => NULL,
      ),
    ),
  ),
  'tests/whitespace/0008' => 
  array (
    'css' => 'f\\ o\\	o
',
    'tokens' => 
    array (
      0 => 
      array (
        'type' => 'ident-token',
        'raw' => 'f\\ o\\	o',
        'startIndex' => 0,
        'endIndex' => 7,
        'structured' => 
        array (
          'value' => 'f o	o',
        ),
      ),
      1 => 
      array (
        'type' => 'whitespace-token',
        'raw' => '
',
        'startIndex' => 7,
        'endIndex' => 8,
        'structured' => NULL,
      ),
    ),
  ),
);
