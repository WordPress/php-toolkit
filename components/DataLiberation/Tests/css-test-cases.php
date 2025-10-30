<?php

/**
 * CSS Tokenizer Test Cases
 * Generated from @csstools/css-tokenizer-tests
 * DO NOT EDIT MANUALLY - regenerate using generate-css-tests.mjs
 */

return array(
	"tests/at-keyword/0001" => array(
		'css' => "@foo\n",
		'tokens' => array(
			array(
				"type" => "at-keyword-token",
				"raw" => "@foo",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0002" => array(
		'css' => "@--\n",
		'tokens' => array(
			array(
				"type" => "at-keyword-token",
				"raw" => "@--",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "--"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0003" => array(
		'css' => "@-1\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "@",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "@"
				)
			),
			array(
				"type" => "number-token",
				"raw" => "-1",
				"startIndex" => 1,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -1,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0004" => array(
		'css' => "@--1\n",
		'tokens' => array(
			array(
				"type" => "at-keyword-token",
				"raw" => "@--1",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "--1"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0005" => array(
		'css' => "@\\@\n",
		'tokens' => array(
			array(
				"type" => "at-keyword-token",
				"raw" => "@\\@",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "@"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0006" => array(
		'css' => "@_\n",
		'tokens' => array(
			array(
				"type" => "at-keyword-token",
				"raw" => "@_",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "_"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0007" => array(
		'css' => "@\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "@",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "@"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0008" => array(
		'css' => "pvA3@\\\neBnP\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "pvA3",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "pvA3"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "@",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => array(
					"value" => "@"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "\\",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => array(
					"value" => "\\"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "eBnP",
				"startIndex" => 7,
				"endIndex" => 11,
				"structured" => array(
					"value" => "eBnP"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/at-keyword/0009" => array(
		'css' => "@aa𐀀\n",
		'tokens' => array(
			array(
				"type" => "at-keyword-token",
				"raw" => "@aa𐀀",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "aa𐀀"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-string/0001" => array(
		'css' => "\"foo\n\"\n",
		'tokens' => array(
			array(
				"type" => "bad-string-token",
				"raw" => "\"foo",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			),
			array(
				"type" => "bad-string-token",
				"raw" => "\"",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-string/0002" => array(
		'css' => "\"foo\\\n\"\n",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"foo\\\n\"",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-string/0003" => array(
		'css' => "\"foo\r\n\"\n",
		'tokens' => array(
			array(
				"type" => "bad-string-token",
				"raw" => "\"foo",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\r\n",
				"startIndex" => 4,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "bad-string-token",
				"raw" => "\"",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-string/0004" => array(
		'css' => "\"foo\\\r\n\"\n",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"foo\\\r\n\"",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-string/0005" => array(
		'css' => "\"aa𐀀\n",
		'tokens' => array(
			array(
				"type" => "bad-string-token",
				"raw" => "\"aa𐀀",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-url/0001" => array(
		'css' => "url(\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url(\n",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => ""
				)
			)
		)
	) 
,
	"tests/bad-url/0002" => array(
		'css' => "url( a\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url( a\n",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "a"
				)
			)
		)
	) 
,
	"tests/bad-url/0003" => array(
		'css' => "url( a a\n",
		'tokens' => array(
			array(
				"type" => "bad-url-token",
				"raw" => "url( a a\n",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-url/0004" => array(
		'css' => "url( a a)\n",
		'tokens' => array(
			array(
				"type" => "bad-url-token",
				"raw" => "url( a a)",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-url/0005" => array(
		'css' => "url( a a\\)\n",
		'tokens' => array(
			array(
				"type" => "bad-url-token",
				"raw" => "url( a a\\)\n",
				"startIndex" => 0,
				"endIndex" => 11,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-url/0006" => array(
		'css' => "url( \\\n",
		'tokens' => array(
			array(
				"type" => "bad-url-token",
				"raw" => "url( \\\n",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-url/0007" => array(
		'css' => "url(a'')\n",
		'tokens' => array(
			array(
				"type" => "bad-url-token",
				"raw" => "url(a'')",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => null
			)
		)
	) 
,
	"tests/bad-url/0008" => array(
		'css' => "url(a\")\n",
		'tokens' => array(
			array(
				"type" => "bad-url-token",
				"raw" => "url(a\")",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/colon/0001" => array(
		'css' => ":\n",
		'tokens' => array(
			array(
				"type" => "colon-token",
				"raw" => ":",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/comma/0001" => array(
		'css' => ",\n",
		'tokens' => array(
			array(
				"type" => "comma-token",
				"raw" => ",",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/comment/0001" => array(
		'css' => "/* a comment */\n",
		'tokens' => array(
			array(
				"type" => "comment",
				"raw" => "/* a comment */",
				"startIndex" => 0,
				"endIndex" => 15,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 15,
				"endIndex" => 16,
				"structured" => null
			)
		)
	) 
,
	"tests/comment/0002" => array(
		'css' => "/* a comment ",
		'tokens' => array(
			array(
				"type" => "comment",
				"raw" => "/* a comment ",
				"startIndex" => 0,
				"endIndex" => 13,
				"structured" => null
			)
		)
	) 
,
	"tests/comment/0003" => array(
		'css' => "a/**/b\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "a",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "comment",
				"raw" => "/**/",
				"startIndex" => 1,
				"endIndex" => 5,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "b",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => array(
					"value" => "b"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/comment/0004" => array(
		'css' => "/*\\*/*/\n",
		'tokens' => array(
			array(
				"type" => "comment",
				"raw" => "/*\\*/",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => null
			),
			array(
				"type" => "delim-token",
				"raw" => "*",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => array(
					"value" => "*"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "/",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => array(
					"value" => "/"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/comment/0005" => array(
		'css' => "/* a comment *",
		'tokens' => array(
			array(
				"type" => "comment",
				"raw" => "/* a comment *",
				"startIndex" => 0,
				"endIndex" => 14,
				"structured" => null
			)
		)
	) 
,
	"tests/comment/0006" => array(
		'css' => "/*a𐀀*/\n",
		'tokens' => array(
			array(
				"type" => "comment",
				"raw" => "/*a𐀀*/",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			)
		)
	) 
,
	"tests/digit/0001" => array(
		'css' => "0\n1\n2\n3\n4\n5\n6\n7\n8\n9\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "0",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"type" => "integer",
					"value" => 0
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "1",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => array(
					"type" => "integer",
					"value" => 1
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "2",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => array(
					"type" => "integer",
					"value" => 2
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "3",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => array(
					"type" => "integer",
					"value" => 3
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "4",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => array(
					"type" => "integer",
					"value" => 4
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "5",
				"startIndex" => 10,
				"endIndex" => 11,
				"structured" => array(
					"type" => "integer",
					"value" => 5
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "6",
				"startIndex" => 12,
				"endIndex" => 13,
				"structured" => array(
					"type" => "integer",
					"value" => 6
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 13,
				"endIndex" => 14,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "7",
				"startIndex" => 14,
				"endIndex" => 15,
				"structured" => array(
					"type" => "integer",
					"value" => 7
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 15,
				"endIndex" => 16,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "8",
				"startIndex" => 16,
				"endIndex" => 17,
				"structured" => array(
					"type" => "integer",
					"value" => 8
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 17,
				"endIndex" => 18,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "9",
				"startIndex" => 18,
				"endIndex" => 19,
				"structured" => array(
					"type" => "integer",
					"value" => 9
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 19,
				"endIndex" => 20,
				"structured" => null
			)
		)
	) 
,
	"tests/dimension/0001" => array(
		'css' => "10px\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10px",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => 10,
					"type" => "integer",
					"unit" => "px"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/dimension/0002" => array(
		'css' => "10\\70 x\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10\\70 x",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => 10,
					"type" => "integer",
					"unit" => "px"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/dimension/0003" => array(
		'css' => "10--custom-px\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10--custom-px",
				"startIndex" => 0,
				"endIndex" => 13,
				"structured" => array(
					"value" => 10,
					"type" => "integer",
					"unit" => "--custom-px"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 13,
				"endIndex" => 14,
				"structured" => null
			)
		)
	) 
,
	"tests/dimension/0004" => array(
		'css' => "10e2px\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10e2px",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => 1000,
					"type" => "number",
					"unit" => "px"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/dimension/0005" => array(
		'css' => "10E2PX\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10E2PX",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => 1000,
					"type" => "number",
					"unit" => "PX"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/dimension/0006" => array(
		'css' => "10\\0\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10\\0\n",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => 10,
					"type" => "integer",
					"unit" => "�"
				)
			)
		)
	) 
,
	"tests/dimension/0007" => array(
		'css' => "10a𐀀\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10a𐀀",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => 10,
					"type" => "integer",
					"unit" => "a𐀀"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/dimension/0008" => array(
		'css' => "10a\0",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "10a\0",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => 10,
					"type" => "integer",
					"unit" => "a�"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0001" => array(
		'css' => "\\",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "�"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0002" => array(
		'css' => "\\0",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\0",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "�"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0003" => array(
		'css' => "\\\\",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\\\",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "\\"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0004" => array(
		'css' => "\\0a b\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\0a b",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "\nb"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0005" => array(
		'css' => "\\0ab \n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\0ab ",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "«"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0006" => array(
		'css' => "\\0ab (foo)\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "\\0ab (",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => "«"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "foo",
				"startIndex" => 6,
				"endIndex" => 9,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 10,
				"endIndex" => 11,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0007" => array(
		'css' => "\\0ab  (foo)\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\0ab ",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "«"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "(-token",
				"raw" => "(",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "foo",
				"startIndex" => 7,
				"endIndex" => 10,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 10,
				"endIndex" => 11,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0008" => array(
		'css' => "\\0000ab\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\0000ab\n",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "«"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0009" => array(
		'css' => "\\00000ab\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\00000ab",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "\nb"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0010" => array(
		'css' => "\\110000\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\110000\n",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "�"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0011" => array(
		'css' => "\\00D800\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\00D800\n",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "�"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0012" => array(
		'css' => "\\00DFFF\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\00DFFF\n",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "�"
				)
			)
		)
	) 
,
	"tests/escaped-code-point/0013" => array(
		'css' => "\\\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "\\",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "\\"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0014" => array(
		'css' => "\\\0\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\\0",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "�"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0015" => array(
		'css' => "\\\0\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\\0",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "�"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/escaped-code-point/0016" => array(
		'css' => "\"a\\12\r\nb\"",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"a\\12\r\nb\"",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => array(
					"value" => "ab"
				)
			)
		)
	) 
,
	"tests/full-stop/0001" => array(
		'css' => ".\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => ".",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "."
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/full-stop/0002" => array(
		'css' => ".a\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => ".",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "."
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "a",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/full-stop/0003" => array(
		'css' => ".1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => ".1",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => 0.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0001" => array(
		'css' => "#1\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#1",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "1",
					"type" => "unrestricted"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0002" => array(
		'css' => "#-2\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#-2",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "-2",
					"type" => "unrestricted"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0003" => array(
		'css' => "#--3\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#--3",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "--3",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0004" => array(
		'css' => "#---4\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#---4",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "---4",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0005" => array(
		'css' => "#a\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#a",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "a",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0006" => array(
		'css' => "#-b\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#-b",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "-b",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0007" => array(
		'css' => "#--c\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#--c",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "--c",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0008" => array(
		'css' => "#---d\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#---d",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "---d",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0009" => array(
		'css' => "#_\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#_",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "_",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0010" => array(
		'css' => "#_1\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#_1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "_1",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0011" => array(
		'css' => "#-\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#-",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "-",
					"type" => "unrestricted"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0012" => array(
		'css' => "#+\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "#",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "#"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "+",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "+"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0013" => array(
		'css' => "##\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "#",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "#"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "#",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "#"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hash/0014" => array(
		'css' => "#",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "#",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "#"
				)
			)
		)
	) 
,
	"tests/hash/0015" => array(
		'css' => "#aa𐀀\n",
		'tokens' => array(
			array(
				"type" => "hash-token",
				"raw" => "#aa𐀀",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "aa𐀀",
					"type" => "id"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/hyphen-minus/0001" => array(
		'css' => "-\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "-",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "-"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/hyphen-minus/0002" => array(
		'css' => "-1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-1",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -1,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hyphen-minus/0003" => array(
		'css' => "-.1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-.1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -0.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/hyphen-minus/0004" => array(
		'css' => "--1\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "--1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "--1"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/hyphen-minus/0005" => array(
		'css' => "-0\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-0",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"signCharacter" => "-",
					"value" => 0,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/hyphen-minus/0006" => array(
		'css' => "-->\n",
		'tokens' => array(
			array(
				"type" => "CDC-token",
				"raw" => "-->",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0001" => array(
		'css' => "url(foo)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url(foo)",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0002" => array(
		'css' => "\\75 Rl(foo)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "\\75 Rl(foo)",
				"startIndex" => 0,
				"endIndex" => 11,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0003" => array(
		'css' => "uR\\6c (foo)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "uR\\6c (foo)",
				"startIndex" => 0,
				"endIndex" => 11,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0004" => array(
		'css' => "url('foo')\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "url(",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "url"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "'foo'",
				"startIndex" => 4,
				"endIndex" => 9,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 10,
				"endIndex" => 11,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0005" => array(
		'css' => "url( 'foo')\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "url(",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "url"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			),
			array(
				"type" => "string-token",
				"raw" => "'foo'",
				"startIndex" => 5,
				"endIndex" => 10,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 10,
				"endIndex" => 11,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0006" => array(
		'css' => "url(  'foo')\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "url(",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "url"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "  ",
				"startIndex" => 4,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "string-token",
				"raw" => "'foo'",
				"startIndex" => 6,
				"endIndex" => 11,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 12,
				"endIndex" => 13,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0007" => array(
		'css' => "url(   'foo')\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "url(",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "url"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "   ",
				"startIndex" => 4,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "string-token",
				"raw" => "'foo'",
				"startIndex" => 7,
				"endIndex" => 12,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 12,
				"endIndex" => 13,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 13,
				"endIndex" => 14,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0008" => array(
		'css' => "not-url(   'foo')\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "not-url(",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "not-url"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "   ",
				"startIndex" => 8,
				"endIndex" => 11,
				"structured" => null
			),
			array(
				"type" => "string-token",
				"raw" => "'foo'",
				"startIndex" => 11,
				"endIndex" => 16,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 16,
				"endIndex" => 17,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 17,
				"endIndex" => 18,
				"structured" => null
			)
		)
	) 
,
	"tests/ident-like/0009" => array(
		'css' => "url(   foo)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url(   foo)",
				"startIndex" => 0,
				"endIndex" => 11,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/ident/0001" => array(
		'css' => "foo\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "foo",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/ident/0002" => array(
		'css' => "--\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "--",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "--"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/ident/0003" => array(
		'css' => "--0\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "--0",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "--0"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/ident/0004" => array(
		'css' => "-\\\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "-",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "-"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "\\",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "\\"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/ident/0005" => array(
		'css' => "-\\ \n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "-\\ ",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "- "
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/ident/0006" => array(
		'css' => "--💅\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "--💅",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => "--💅"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/ident/0009" => array(
		'css' => "--a𐀀\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "--a𐀀",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "--a𐀀"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/left-curly-bracket/0001" => array(
		'css' => "{\n",
		'tokens' => array(
			array(
				"type" => "{-token",
				"raw" => "{",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/left-parenthesis/0001" => array(
		'css' => "(\n",
		'tokens' => array(
			array(
				"type" => "(-token",
				"raw" => "(",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/left-square-bracket/0001" => array(
		'css' => "[\n",
		'tokens' => array(
			array(
				"type" => "[-token",
				"raw" => "[",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/less-than/0001" => array(
		'css' => "<\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "<",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "<"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/less-than/0002" => array(
		'css' => "<!--\n",
		'tokens' => array(
			array(
				"type" => "CDO-token",
				"raw" => "<!--",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/less-than/0003" => array(
		'css' => "<--\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "<",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "<"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "--",
				"startIndex" => 1,
				"endIndex" => 3,
				"structured" => array(
					"value" => "--"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/less-than/0004" => array(
		'css' => "<!-\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "<",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "<"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "!",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "!"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "-",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => array(
					"value" => "-"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0001" => array(
		'css' => "10\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "10",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => 10,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0002" => array(
		'css' => "+10\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "+10",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 10,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0003" => array(
		'css' => "-10\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-10",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -10,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0004" => array(
		'css' => "0\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "0",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => 0,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0005" => array(
		'css' => "+0\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "+0",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 0,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0006" => array(
		'css' => "-0\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-0",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"signCharacter" => "-",
					"value" => 0,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0007" => array(
		'css' => ".0\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => ".0",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => 0,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0008" => array(
		'css' => ".1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => ".1",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => 0.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0009" => array(
		'css' => "+.1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "+.1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 0.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0010" => array(
		'css' => "-.1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-.1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -0.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0011" => array(
		'css' => "1.1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "1.1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => 1.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0012" => array(
		'css' => "+1.1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "+1.1",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 1.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0013" => array(
		'css' => "-1.1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-1.1",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -1.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0014" => array(
		'css' => "1.1e2\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "1.1e2",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => 110,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0015" => array(
		'css' => "+1.1e+2\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "+1.1e+2",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 110,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0016" => array(
		'css' => "-1.1e-2\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-1.1e-2",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -0.011,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0017" => array(
		'css' => "-1.1e-22\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-1.1e-22",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -1.1e-22,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0018" => array(
		'css' => "-1.1e-22e\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "-1.1e-22e",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -1.1e-22,
					"type" => "number",
					"unit" => "e"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0019" => array(
		'css' => "1e+\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "1e",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"type" => "integer",
					"value" => 1,
					"unit" => "e"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "+",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => array(
					"value" => "+"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/number/0020" => array(
		'css' => ".2.7\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => ".2",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"type" => "number",
					"value" => 0.2
				)
			),
			array(
				"type" => "number-token",
				"raw" => ".7",
				"startIndex" => 2,
				"endIndex" => 4,
				"structured" => array(
					"type" => "number",
					"value" => 0.7
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/numeric/0001" => array(
		'css' => "-123.753e-2\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "-123.753e-2",
				"startIndex" => 0,
				"endIndex" => 11,
				"structured" => array(
					"signCharacter" => "-",
					"type" => "number",
					"value" => -1.23753
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/numeric/0002" => array(
		'css' => "-123.753e-2px\n",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "-123.753e-2px",
				"startIndex" => 0,
				"endIndex" => 13,
				"structured" => array(
					"signCharacter" => "-",
					"type" => "number",
					"unit" => "px",
					"value" => -1.23753
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 13,
				"endIndex" => 14,
				"structured" => null
			)
		)
	) 
,
	"tests/numeric/0003" => array(
		'css' => "-123.753e-2%\n",
		'tokens' => array(
			array(
				"type" => "percentage-token",
				"raw" => "-123.753e-2%",
				"startIndex" => 0,
				"endIndex" => 12,
				"structured" => array(
					"signCharacter" => "-",
					"value" => -1.23753
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 12,
				"endIndex" => 13,
				"structured" => null
			)
		)
	) 
,
	"tests/numeric/0004" => array(
		'css' => "1.2.3\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "1.2",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"type" => "number",
					"value" => 1.2
				)
			),
			array(
				"type" => "number-token",
				"raw" => ".3",
				"startIndex" => 3,
				"endIndex" => 5,
				"structured" => array(
					"type" => "number",
					"value" => 0.3
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/plus/0001" => array(
		'css' => "+\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "+",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "+"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/plus/0002" => array(
		'css' => "+1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "+1",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 1,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/plus/0003" => array(
		'css' => "+.1\n",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "+.1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 0.1,
					"type" => "number"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/plus/0004" => array(
		'css' => "++1\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "+",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "+"
				)
			),
			array(
				"type" => "number-token",
				"raw" => "+1",
				"startIndex" => 1,
				"endIndex" => 3,
				"structured" => array(
					"signCharacter" => "+",
					"value" => 1,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			)
		)
	) 
,
	"tests/reverse-solidus/0001" => array(
		'css' => "\\\n",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "\\",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "\\"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/reverse-solidus/0002" => array(
		'css' => "\\#\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\#",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "#"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/reverse-solidus/0003" => array(
		'css' => "\\ \n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\ ",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => " "
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			)
		)
	) 
,
	"tests/reverse-solidus/0004" => array(
		'css' => "\\61 b\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\61 b",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "ab"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/reverse-solidus/0005" => array(
		'css' => "\\",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "�"
				)
			)
		)
	) 
,
	"tests/right-curly-bracket/0001" => array(
		'css' => "}\n",
		'tokens' => array(
			array(
				"type" => "}-token",
				"raw" => "}",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/right-parenthesis/0001" => array(
		'css' => ")\n",
		'tokens' => array(
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/right-square-bracket/0001" => array(
		'css' => "]\n",
		'tokens' => array(
			array(
				"type" => "]-token",
				"raw" => "]",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/semi-colon/0001" => array(
		'css' => ";\n",
		'tokens' => array(
			array(
				"type" => "semicolon-token",
				"raw" => ";",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/string/0001" => array(
		'css' => "\"foo\"\n'foo'\n",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"foo\"",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "string-token",
				"raw" => "'foo'",
				"startIndex" => 6,
				"endIndex" => 11,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 11,
				"endIndex" => 12,
				"structured" => null
			)
		)
	) 
,
	"tests/string/0002" => array(
		'css' => "\"foo",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"foo",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "foo"
				)
			)
		)
	) 
,
	"tests/string/0003" => array(
		'css' => "\"fo\no\"",
		'tokens' => array(
			array(
				"type" => "bad-string-token",
				"raw" => "\"fo",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "o",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => array(
					"value" => "o"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "\"",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => array(
					"value" => ""
				)
			)
		)
	) 
,
	"tests/string/0004" => array(
		'css' => "\"fo\\\no\"\n",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"fo\\\no\"",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "foo"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/string/0005" => array(
		'css' => "\"fo\\",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"fo\\",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "fo"
				)
			)
		)
	) 
,
	"tests/string/0006" => array(
		'css' => "\"esc\\61 ped\"\n",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"esc\\61 ped\"",
				"startIndex" => 0,
				"endIndex" => 12,
				"structured" => array(
					"value" => "escaped"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 12,
				"endIndex" => 13,
				"structured" => null
			)
		)
	) 
,
	"tests/string/0007" => array(
		'css' => "\"foo\\\"",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"foo\\\"",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => "foo\""
				)
			)
		)
	) 
,
	"tests/string/0008" => array(
		'css' => "\"'foo'\"\n'\"foo\"'\n",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"'foo'\"",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "'foo'"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			),
			array(
				"type" => "string-token",
				"raw" => "'\"foo\"'",
				"startIndex" => 8,
				"endIndex" => 15,
				"structured" => array(
					"value" => "\"foo\""
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 15,
				"endIndex" => 16,
				"structured" => null
			)
		)
	) 
,
	"tests/string/0009" => array(
		'css' => "\"\\\"foo\\\"\"\n'\\'foo\\''\n",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "\"\\\"foo\\\"\"",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => array(
					"value" => "\"foo\""
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			),
			array(
				"type" => "string-token",
				"raw" => "'\\'foo\\''",
				"startIndex" => 10,
				"endIndex" => 19,
				"structured" => array(
					"value" => "'foo'"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 19,
				"endIndex" => 20,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0001" => array(
		'css' => "url(\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url(\n",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => ""
				)
			)
		)
	) 
,
	"tests/url/0002" => array(
		'css' => "url(a\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url(a\n",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => "a"
				)
			)
		)
	) 
,
	"tests/url/0003" => array(
		'css' => "url( a\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url( a\n",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "a"
				)
			)
		)
	) 
,
	"tests/url/0004" => array(
		'css' => "url()\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url()",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0005" => array(
		'css' => "url( )\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url( )",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0006" => array(
		'css' => "url( a)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url( a)",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0007" => array(
		'css' => "url( a )\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url( a )",
				"startIndex" => 0,
				"endIndex" => 8,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0008" => array(
		'css' => "url( \\) )\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url( \\) )",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => array(
					"value" => ")"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0009" => array(
		'css' => "url(https://https:⁄⁄www.netmeister.org@https://www.netmeister.org/https:⁄⁄www.netmeister.org⁄?https://www.netmeister.org=https://www.netmeister.org;https://www.netmeister.org#https://www.netmeister.org)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url(https://https:⁄⁄www.netmeister.org@https://www.netmeister.org/https:⁄⁄www.netmeister.org⁄?https://www.netmeister.org=https://www.netmeister.org;https://www.netmeister.org#https://www.netmeister.org)",
				"startIndex" => 0,
				"endIndex" => 212,
				"structured" => array(
					"value" => "https://https:⁄⁄www.netmeister.org@https://www.netmeister.org/https:⁄⁄www.netmeister.org⁄?https://www.netmeister.org=https://www.netmeister.org;https://www.netmeister.org#https://www.netmeister.org"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 212,
				"endIndex" => 213,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0010" => array(
		'css' => "url(https://www.netmeister.org/%62%6C%6F%67/%75%72%6C%73%2E%68%74%6D%6C?!@#\$%25=+_\\)\\(*&^#top)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "url(https://www.netmeister.org/%62%6C%6F%67/%75%72%6C%73%2E%68%74%6D%6C?!@#\$%25=+_\\)\\(*&^#top)",
				"startIndex" => 0,
				"endIndex" => 94,
				"structured" => array(
					"value" => "https://www.netmeister.org/%62%6C%6F%67/%75%72%6C%73%2E%68%74%6D%6C?!@#\$%25=+_)(*&^#top"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 94,
				"endIndex" => 95,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0011" => array(
		'css' => "Url(a)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "Url(a)",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0012" => array(
		'css' => "uRl(a)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "uRl(a)",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0013" => array(
		'css' => "urL(a)\n",
		'tokens' => array(
			array(
				"type" => "url-token",
				"raw" => "urL(a)",
				"startIndex" => 0,
				"endIndex" => 6,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0014" => array(
		'css' => "uri(a)\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "uri(",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "uri"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "a",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/url/0015" => array(
		'css' => "uul(a)\n",
		'tokens' => array(
			array(
				"type" => "function-token",
				"raw" => "uul(",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "uul"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "a",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0001" => array(
		'css' => "\n",
		'tokens' => array(
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0002" => array(
		'css' => " \n",
		'tokens' => array(
			array(
				"type" => "whitespace-token",
				"raw" => " \n",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0003" => array(
		'css' => "a  b\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "a",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "  ",
				"startIndex" => 1,
				"endIndex" => 3,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "b",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => array(
					"value" => "b"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0004" => array(
		'css' => "\\61 b\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\61 b",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "ab"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0005" => array(
		'css' => "\\000061 b\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\000061 b",
				"startIndex" => 0,
				"endIndex" => 9,
				"structured" => array(
					"value" => "ab"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0006" => array(
		'css' => "\\61  b\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "\\61 ",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "a"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "b",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => array(
					"value" => "b"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0007" => array(
		'css' => "\t\n",
		'tokens' => array(
			array(
				"type" => "whitespace-token",
				"raw" => "\t\n",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => null
			)
		)
	) 
,
	"tests/whitespace/0008" => array(
		'css' => "f\\ o\\\to\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "f\\ o\\\to",
				"startIndex" => 0,
				"endIndex" => 7,
				"structured" => array(
					"value" => "f o\to"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			)
		)
	) 
);
