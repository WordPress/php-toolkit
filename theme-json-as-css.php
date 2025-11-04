<?php
use WordPress\DataLiberation\CSS\CSSParser;
require_once __DIR__ . '/theme-json-as-css-functions.php';

$css = <<<CSS
@version {
	version: 3;
}

@font-family {
	fontFamily: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen-Sans,Ubuntu,Cantarell, "Helvetica Neue", sans-serif;
	slug: system-fonts;
	name: "System Fonts";
}

@color {
	slug: base;
	color: #111;
	name: Base;
}

@color {
	slug: contrast;
	color: #fefefe;
	name: Contrast;
}

@font-size {
	name: "Extra large";
	slug: "extra-large";
	size: 42px;
}

@elements {
	link {
		color: var(--wp--preset--color--contrast);
		background-color: var(--wp--preset--color--base);
	}
}

@blocks {
	core/paragraph {
		font-family: "Trickster";
		font-size: var(--wp--preset--font-size--extra-large);
		line-height: 1.3;
		color: var(--wp--preset--color--contrast);
		background-color: var(--wp--preset--color--base);
	}
}
CSS;

$expected_theme_json = <<<'JSON'
{
	"$schema": "https://schemas.wp.org/trunk/theme.json",
	"version": 3,
	"settings": {
		"typography": {
			"fontFamilies": [
				{
					"fontFamily": "-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Oxygen-Sans,Ubuntu,Cantarell,\"Helvetica Neue\",sans-serif",
					"slug": "system-fonts",
					"name": "System Fonts"
				}
			],
			"fontSizes": [
				{
					"name": "Extra large",
					"slug": "extra-large",
					"size": "42px"
				}
			]
		},
		"color": {
			"palette": [
				{
					"slug": "base",
					"color": "#111",
					"name": "Base"
				},
				{
					"slug": "contrast",
					"color": "#fefefe",
					"name": "Contrast"
				}
			]
		}
	},
	"styles": {
		"elements": {
			"link": {
				"color": {
					"text": "var(--wp--preset--color--contrast)",
					"background": "var(--wp--preset--color--base)"
				}
			}
		},
		"blocks": {
			"core/paragraph": {
				"typography": {
					"fontFamily": "Trickster",
					"fontSize": "var(--wp--preset--font-size--extra-large)",
					"lineHeight": "1.3"
				},
				"color": {
					"text": "var(--wp--preset--color--contrast)",
					"background": "var(--wp--preset--color--base)"
				}
			}
		}
	}
}
JSON;
$expected_theme_json = json_decode( $expected_theme_json, true );

// Parse CSS to theme.json format
$parser = CSSParser::create( $css );
$stylesheet = $parser->parse_stylesheet();
$generated_theme_json = css_to_theme_json( $stylesheet );

echo "=== Parsed CSS as theme.json ===" . PHP_EOL . PHP_EOL;
echo json_encode( $generated_theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
file_put_contents( __DIR__ . '/theme-json-from-css.json', json_encode( $generated_theme_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

echo PHP_EOL;
echo "Parsed data matches expected data: ";
var_dump( $generated_theme_json === $expected_theme_json );
echo PHP_EOL;