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
				"endIndex" => 5,
				"structured" => array(
					"value" => "aa𐀀"
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
				"endIndex" => 5,
				"structured" => null
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
				"endIndex" => 5,
				"structured" => array(
					"value" => 10,
					"type" => "integer",
					"unit" => "a𐀀"
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
	"tests/fuzz/01a166c0-ca20-43a5-9ab0-0984e4a5362b" => array(
		'css' => "4waPtwEEGH\\\0jV3zM6hh6w30N0PC 7m8KM0HcWGOPw28Gt(r19",
		'tokens' => array(
			array(
				"type" => "dimension-token",
				"raw" => "4waPtwEEGH\\\0jV3zM6hh6w30N0PC",
				"startIndex" => 0,
				"endIndex" => 28,
				"structured" => array(
					"value" => 4,
					"type" => "integer",
					"unit" => "waPtwEEGH�jV3zM6hh6w30N0PC"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 28,
				"endIndex" => 29,
				"structured" => null
			),
			array(
				"type" => "dimension-token",
				"raw" => "7m8KM0HcWGOPw28Gt",
				"startIndex" => 29,
				"endIndex" => 46,
				"structured" => array(
					"value" => 7,
					"type" => "integer",
					"unit" => "m8KM0HcWGOPw28Gt"
				)
			),
			array(
				"type" => "(-token",
				"raw" => "(",
				"startIndex" => 46,
				"endIndex" => 47,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "r19",
				"startIndex" => 47,
				"endIndex" => 50,
				"structured" => array(
					"value" => "r19"
				)
			)
		)
	) 
,
	"tests/fuzz/2abe9406-c063-4e9a-85ac-b13660671553" => array(
		'css' => "ak]P0A}808G\"lQh{R5M!QyOWE}oC2{2K TIa9}zb2oXWREY]0aj5J\\\r\nBJ5CO-16W5H7noF 19䀹41H3e8Z9%tg[O5AHEY24xh'9\"\"c34Q\"iiC0e45Da5f\"F5X3\"o(",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "ak",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "ak"
				)
			),
			array(
				"type" => "]-token",
				"raw" => "]",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "P0A",
				"startIndex" => 3,
				"endIndex" => 6,
				"structured" => array(
					"value" => "P0A"
				)
			),
			array(
				"type" => "}-token",
				"raw" => "}",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "dimension-token",
				"raw" => "808G",
				"startIndex" => 7,
				"endIndex" => 11,
				"structured" => array(
					"value" => 808,
					"type" => "integer",
					"unit" => "G"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "\"lQh{R5M!QyOWE}oC2{2K TIa9}zb2oXWREY]0aj5J\\\r\nBJ5CO-16W5H7noF 19䀹41H3e8Z9%tg[O5AHEY24xh'9\"",
				"startIndex" => 11,
				"endIndex" => 100,
				"structured" => array(
					"value" => "lQh{R5M!QyOWE}oC2{2K TIa9}zb2oXWREY]0aj5JBJ5CO-16W5H7noF 19䀹41H3e8Z9%tg[O5AHEY24xh'9"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "\"c34Q\"",
				"startIndex" => 100,
				"endIndex" => 106,
				"structured" => array(
					"value" => "c34Q"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "iiC0e45Da5f",
				"startIndex" => 106,
				"endIndex" => 117,
				"structured" => array(
					"value" => "iiC0e45Da5f"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "\"F5X3\"",
				"startIndex" => 117,
				"endIndex" => 123,
				"structured" => array(
					"value" => "F5X3"
				)
			),
			array(
				"type" => "function-token",
				"raw" => "o(",
				"startIndex" => 123,
				"endIndex" => 125,
				"structured" => array(
					"value" => "o"
				)
			)
		)
	) 
,
	"tests/fuzz/4e630a47-507b-4b79-b00f-57f7dc1cc79d" => array(
		'css' => "7rSD6I5L1lglVRlL2X7BbEk\\3HCd\r94 \\\0skoW25d4%l64UUskN\"pHun\"!",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "dimension-token",
				"raw" => "7rSD6I5L1lglVRlL2X7BbEk\\3HCd",
				"startIndex" => 1,
				"endIndex" => 29,
				"structured" => array(
					"value" => 7,
					"type" => "integer",
					"unit" => "rSD6I5L1lglVRlL2X7BbEkHCd"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\r",
				"startIndex" => 29,
				"endIndex" => 30,
				"structured" => null
			),
			array(
				"type" => "number-token",
				"raw" => "94",
				"startIndex" => 30,
				"endIndex" => 32,
				"structured" => array(
					"value" => 94,
					"type" => "integer"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 32,
				"endIndex" => 33,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "\\\0skoW25d4",
				"startIndex" => 33,
				"endIndex" => 43,
				"structured" => array(
					"value" => "�skoW25d4"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "%",
				"startIndex" => 43,
				"endIndex" => 44,
				"structured" => array(
					"value" => "%"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "l64UUskN",
				"startIndex" => 44,
				"endIndex" => 52,
				"structured" => array(
					"value" => "l64UUskN"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "\"pHun\"",
				"startIndex" => 52,
				"endIndex" => 58,
				"structured" => array(
					"value" => "pHun"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "!",
				"startIndex" => 58,
				"endIndex" => 59,
				"structured" => array(
					"value" => "!"
				)
			)
		)
	) 
,
	"tests/fuzz/4f865903-e4dd-4a0b-83ed-e630cfa9dcca" => array(
		'css' => "gzO0{(p{DzQ7\0(a1;r1iN7w)",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "gzO0",
				"startIndex" => 0,
				"endIndex" => 4,
				"structured" => array(
					"value" => "gzO0"
				)
			),
			array(
				"type" => "{-token",
				"raw" => "{",
				"startIndex" => 4,
				"endIndex" => 5,
				"structured" => null
			),
			array(
				"type" => "(-token",
				"raw" => "(",
				"startIndex" => 5,
				"endIndex" => 6,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "p",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => array(
					"value" => "p"
				)
			),
			array(
				"type" => "{-token",
				"raw" => "{",
				"startIndex" => 7,
				"endIndex" => 8,
				"structured" => null
			),
			array(
				"type" => "function-token",
				"raw" => "DzQ7\0(",
				"startIndex" => 8,
				"endIndex" => 14,
				"structured" => array(
					"value" => "DzQ7�"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "a1",
				"startIndex" => 14,
				"endIndex" => 16,
				"structured" => array(
					"value" => "a1"
				)
			),
			array(
				"type" => "semicolon-token",
				"raw" => ";",
				"startIndex" => 16,
				"endIndex" => 17,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "r1iN7w",
				"startIndex" => 17,
				"endIndex" => 23,
				"structured" => array(
					"value" => "r1iN7w"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 23,
				"endIndex" => 24,
				"structured" => null
			)
		)
	) 
,
	"tests/fuzz/5181013c-60ab-483b-9c06-fb32c7e1e7e8" => array(
		'css' => "565'E{z\0UEG2}2Verb>nj3TVk3mu7wX1J.Hi1Ga8f5 dserqydJ3\"xj398xy.W\" uHQbv7Bw1NtF;N3PwNY7Vx00BF o\"4CXzvP\"{594 6r}8QQKNQw135i1\\\r\nrey\thg7[5%rBK8RUC64Lu␌17O{E\\90873u}1O3vx4gHTC55Q9i4\"V3Vx4\"7r(34L]F\"ns2pPf\"V7b)EOBGH8rdC7\"VJ4OQ[ 9jtoMdINgS7o�206vo72kTcKkZR9wl30G'vK\ndhCEs3tValX ",
		'tokens' => array(
			array(
				"type" => "number-token",
				"raw" => "565",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => 565,
					"type" => "integer"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "'E{z\0UEG2}2Verb>nj3TVk3mu7wX1J.Hi1Ga8f5 dserqydJ3\"xj398xy.W\" uHQbv7Bw1NtF;N3PwNY7Vx00BF o\"4CXzvP\"{594 6r}8QQKNQw135i1\\\r\nrey\thg7[5%rBK8RUC64Lu␌17O{E\\90873u}1O3vx4gHTC55Q9i4\"V3Vx4\"7r(34L]F\"ns2pPf\"V7b)EOBGH8rdC7\"VJ4OQ[ 9jtoMdINgS7o�206vo72kTcKkZR9wl30G'",
				"startIndex" => 3,
				"endIndex" => 259,
				"structured" => array(
					"value" => "E{z�UEG2}2Verb>nj3TVk3mu7wX1J.Hi1Ga8f5 dserqydJ3\"xj398xy.W\" uHQbv7Bw1NtF;N3PwNY7Vx00BF o\"4CXzvP\"{594 6r}8QQKNQw135i1rey\thg7[5%rBK8RUC64Lu␌17O{E򐡳u}1O3vx4gHTC55Q9i4\"V3Vx4\"7r(34L]F\"ns2pPf\"V7b)EOBGH8rdC7\"VJ4OQ[ 9jtoMdINgS7o�206vo72kTcKkZR9wl30G"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "vK",
				"startIndex" => 259,
				"endIndex" => 261,
				"structured" => array(
					"value" => "vK"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 261,
				"endIndex" => 262,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "dhCEs3tValX",
				"startIndex" => 262,
				"endIndex" => 273,
				"structured" => array(
					"value" => "dhCEs3tValX"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 273,
				"endIndex" => 274,
				"structured" => null
			)
		)
	) 
,
	"tests/fuzz/6d07fc79-586f-4efa-a0a2-37d4dd3beb09" => array(
		'css' => "FWUNqr7uv8300nz,8lU0j6B186kh \09 GZafxf2GIhL9%",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "FWUNqr7uv8300nz",
				"startIndex" => 0,
				"endIndex" => 15,
				"structured" => array(
					"value" => "FWUNqr7uv8300nz"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 15,
				"endIndex" => 16,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "comma-token",
				"raw" => ",",
				"startIndex" => 16,
				"endIndex" => 17,
				"structured" => null
			),
			array(
				"type" => "dimension-token",
				"raw" => "8lU0j6B186kh",
				"startIndex" => 17,
				"endIndex" => 29,
				"structured" => array(
					"value" => 8,
					"type" => "integer",
					"unit" => "lU0j6B186kh"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 29,
				"endIndex" => 30,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "\09",
				"startIndex" => 30,
				"endIndex" => 32,
				"structured" => array(
					"value" => "�9"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 32,
				"endIndex" => 33,
				"structured" => null
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 33,
				"endIndex" => 34,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "GZafxf2GIhL9",
				"startIndex" => 34,
				"endIndex" => 46,
				"structured" => array(
					"value" => "GZafxf2GIhL9"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "%",
				"startIndex" => 46,
				"endIndex" => 47,
				"structured" => array(
					"value" => "%"
				)
			)
		)
	) 
,
	"tests/fuzz/7f49c8fc-8292-4a3e-828b-b5d028a80d5f" => array(
		'css' => "FZ 0B120h5QUbNbmTD2K8mAD傿i+Yv9V0KS14Ng18ag'\\\r\n{X<E/9b}0nIa%eSz-vapw2GqeMsri#e1BQf3bPlEnFQg{ofB)L9b571J4!{mCN㐥a\\BgK48qgu9'jVRS䎟ROYRbe0m5508k,O0C",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "FZ",
				"startIndex" => 0,
				"endIndex" => 2,
				"structured" => array(
					"value" => "FZ"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			),
			array(
				"type" => "dimension-token",
				"raw" => "0B120h5QUbNbmTD2K8mAD傿i",
				"startIndex" => 3,
				"endIndex" => 26,
				"structured" => array(
					"value" => 0,
					"type" => "integer",
					"unit" => "B120h5QUbNbmTD2K8mAD傿i"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "+",
				"startIndex" => 26,
				"endIndex" => 27,
				"structured" => array(
					"value" => "+"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "Yv9V0KS14Ng18ag",
				"startIndex" => 27,
				"endIndex" => 42,
				"structured" => array(
					"value" => "Yv9V0KS14Ng18ag"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "'\\\r\n{X<E/9b}0nIa%eSz-vapw2GqeMsri#e1BQf3bPlEnFQg{ofB)L9b571J4!{mCN㐥a\\BgK48qgu9'",
				"startIndex" => 42,
				"endIndex" => 122,
				"structured" => array(
					"value" => "{X<E/9b}0nIa%eSz-vapw2GqeMsri#e1BQf3bPlEnFQg{ofB)L9b571J4!{mCN㐥agK48qgu9"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "jVRS䎟ROYRbe0m5508k",
				"startIndex" => 122,
				"endIndex" => 140,
				"structured" => array(
					"value" => "jVRS䎟ROYRbe0m5508k"
				)
			),
			array(
				"type" => "comma-token",
				"raw" => ",",
				"startIndex" => 140,
				"endIndex" => 141,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "O0C",
				"startIndex" => 141,
				"endIndex" => 144,
				"structured" => array(
					"value" => "O0C"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 144,
				"endIndex" => 145,
				"structured" => array(
					"value" => ""
				)
			)
		)
	) 
,
	"tests/fuzz/864d7812-b82f-47c2-94e4-8402ba6ba94a" => array(
		'css' => "'TR(:5RN)_e3w<gD5iL1EO-3zZw ntX3@0<KP9}V0 3Q80{Tqp}7dkUykEm ks �(ZnXhqsp3)G4JKqbWAfx7sPRW\"zKg19p\"Gcoi22xb\"3h5WQan.I4EUmЕ1IBofxvJ73hTA2EmA97(qOU)1\0rV7P'5528LZ14)䓑gqcRX\"aiu� \"z3i74FJ3\04x8F-V5b1f(U bUc",
		'tokens' => array(
			array(
				"type" => "string-token",
				"raw" => "'TR(:5RN)_e3w<gD5iL1EO-3zZw ntX3@0<KP9}V0 3Q80{Tqp}7dkUykEm ks �(ZnXhqsp3)G4JKqbWAfx7sPRW\"zKg19p\"Gcoi22xb\"3h5WQan.I4EUmЕ1IBofxvJ73hTA2EmA97(qOU)1\0rV7P'",
				"startIndex" => 0,
				"endIndex" => 153,
				"structured" => array(
					"value" => "TR(:5RN)_e3w<gD5iL1EO-3zZw ntX3@0<KP9}V0 3Q80{Tqp}7dkUykEm ks �(ZnXhqsp3)G4JKqbWAfx7sPRW\"zKg19p\"Gcoi22xb\"3h5WQan.I4EUmЕ1IBofxvJ73hTA2EmA97(qOU)1�rV7P"
				)
			),
			array(
				"type" => "dimension-token",
				"raw" => "5528LZ14",
				"startIndex" => 153,
				"endIndex" => 161,
				"structured" => array(
					"value" => 5528,
					"type" => "integer",
					"unit" => "LZ14"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 161,
				"endIndex" => 162,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "䓑gqcRX",
				"startIndex" => 162,
				"endIndex" => 168,
				"structured" => array(
					"value" => "䓑gqcRX"
				)
			),
			array(
				"type" => "string-token",
				"raw" => "\"aiu� \"",
				"startIndex" => 168,
				"endIndex" => 175,
				"structured" => array(
					"value" => "aiu� "
				)
			),
			array(
				"type" => "function-token",
				"raw" => "z3i74FJ3\04x8F-V5b1f(",
				"startIndex" => 175,
				"endIndex" => 195,
				"structured" => array(
					"value" => "z3i74FJ3�4x8F-V5b1f"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "U",
				"startIndex" => 195,
				"endIndex" => 196,
				"structured" => array(
					"value" => "U"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 196,
				"endIndex" => 197,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 197,
				"endIndex" => 198,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "bUc",
				"startIndex" => 198,
				"endIndex" => 201,
				"structured" => array(
					"value" => "bUc"
				)
			)
		)
	) 
,
	"tests/fuzz/91de56d3-d1c7-41c9-93e2-4b0770e36e79" => array(
		'css' => "\tb6SUejoqAEDa9,kYO\\",
		'tokens' => array(
			array(
				"type" => "whitespace-token",
				"raw" => "\t",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "b6SUejoqAEDa",
				"startIndex" => 1,
				"endIndex" => 13,
				"structured" => array(
					"value" => "b6SUejoqAEDa"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 13,
				"endIndex" => 14,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "number-token",
				"raw" => "9",
				"startIndex" => 14,
				"endIndex" => 15,
				"structured" => array(
					"value" => 9,
					"type" => "integer"
				)
			),
			array(
				"type" => "comma-token",
				"raw" => ",",
				"startIndex" => 15,
				"endIndex" => 16,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "kYO\\",
				"startIndex" => 16,
				"endIndex" => 20,
				"structured" => array(
					"value" => "kYO�"
				)
			)
		)
	) 
,
	"tests/fuzz/b69ece36-057f-4450-9423-a1661787bce6" => array(
		'css' => "Iv1\0B}1E+X9oON3G",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "Iv1",
				"startIndex" => 0,
				"endIndex" => 3,
				"structured" => array(
					"value" => "Iv1"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 3,
				"endIndex" => 4,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "\0B",
				"startIndex" => 4,
				"endIndex" => 6,
				"structured" => array(
					"value" => "�B"
				)
			),
			array(
				"type" => "}-token",
				"raw" => "}",
				"startIndex" => 6,
				"endIndex" => 7,
				"structured" => null
			),
			array(
				"type" => "dimension-token",
				"raw" => "1E",
				"startIndex" => 7,
				"endIndex" => 9,
				"structured" => array(
					"value" => 1,
					"type" => "integer",
					"unit" => "E"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "+",
				"startIndex" => 9,
				"endIndex" => 10,
				"structured" => array(
					"value" => "+"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "X9oO",
				"startIndex" => 10,
				"endIndex" => 14,
				"structured" => array(
					"value" => "X9oO"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "",
				"startIndex" => 14,
				"endIndex" => 15,
				"structured" => array(
					"value" => ""
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "N3G",
				"startIndex" => 15,
				"endIndex" => 18,
				"structured" => array(
					"value" => "N3G"
				)
			)
		)
	) 
,
	"tests/fuzz/ccfaf86d-7471-465b-bbc8-5b65be03e9cf" => array(
		'css' => "H%7Zkc0P17 m2cqKMI5Cz34YPit.2.7,oP ",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "H",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => "H"
				)
			),
			array(
				"type" => "delim-token",
				"raw" => "%",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "%"
				)
			),
			array(
				"type" => "dimension-token",
				"raw" => "7Zkc0P17",
				"startIndex" => 2,
				"endIndex" => 10,
				"structured" => array(
					"value" => 7,
					"type" => "integer",
					"unit" => "Zkc0P17"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 10,
				"endIndex" => 11,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "m2cqKMI5Cz34YPit",
				"startIndex" => 11,
				"endIndex" => 27,
				"structured" => array(
					"value" => "m2cqKMI5Cz34YPit"
				)
			),
			array(
				"type" => "number-token",
				"raw" => ".2",
				"startIndex" => 27,
				"endIndex" => 29,
				"structured" => array(
					"value" => 0.2,
					"type" => "number"
				)
			),
			array(
				"type" => "number-token",
				"raw" => ".7",
				"startIndex" => 29,
				"endIndex" => 31,
				"structured" => array(
					"value" => 0.7,
					"type" => "number"
				)
			),
			array(
				"type" => "comma-token",
				"raw" => ",",
				"startIndex" => 31,
				"endIndex" => 32,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "oP",
				"startIndex" => 32,
				"endIndex" => 34,
				"structured" => array(
					"value" => "oP"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => " ",
				"startIndex" => 34,
				"endIndex" => 35,
				"structured" => null
			)
		)
	) 
,
	"tests/fuzz/eb11f9d4-f8ef-4e11-88dc-2cbf7f56e537" => array(
		'css' => ">u)k2a76}y4\\6fb9ONI\\",
		'tokens' => array(
			array(
				"type" => "delim-token",
				"raw" => ">",
				"startIndex" => 0,
				"endIndex" => 1,
				"structured" => array(
					"value" => ">"
				)
			),
			array(
				"type" => "ident-token",
				"raw" => "u",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "u"
				)
			),
			array(
				"type" => ")-token",
				"raw" => ")",
				"startIndex" => 2,
				"endIndex" => 3,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "k2a76",
				"startIndex" => 3,
				"endIndex" => 8,
				"structured" => array(
					"value" => "k2a76"
				)
			),
			array(
				"type" => "}-token",
				"raw" => "}",
				"startIndex" => 8,
				"endIndex" => 9,
				"structured" => null
			),
			array(
				"type" => "ident-token",
				"raw" => "y4\\6fb9ONI\\",
				"startIndex" => 9,
				"endIndex" => 20,
				"structured" => array(
					"value" => "y4澹ONI�"
				)
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
				"endIndex" => 5,
				"structured" => array(
					"value" => "aa𐀀",
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
				"endIndex" => 4,
				"structured" => array(
					"value" => "--💅"
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
	"tests/ident/0007" => array(
		'css' => "-§\n",
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
				"raw" => "§",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "§"
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
	"tests/ident/0008" => array(
		'css' => "-×\n",
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
				"raw" => "×",
				"startIndex" => 1,
				"endIndex" => 2,
				"structured" => array(
					"value" => "×"
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
	"tests/ident/0009" => array(
		'css' => "--a𐀀\n",
		'tokens' => array(
			array(
				"type" => "ident-token",
				"raw" => "--a𐀀",
				"startIndex" => 0,
				"endIndex" => 5,
				"structured" => array(
					"value" => "--a𐀀"
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
				"endIndex" => 202,
				"structured" => array(
					"value" => "https://https:⁄⁄www.netmeister.org@https://www.netmeister.org/https:⁄⁄www.netmeister.org⁄?https://www.netmeister.org=https://www.netmeister.org;https://www.netmeister.org#https://www.netmeister.org"
				)
			),
			array(
				"type" => "whitespace-token",
				"raw" => "\n",
				"startIndex" => 202,
				"endIndex" => 203,
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
