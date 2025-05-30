<?php
require_once '/wordpress/wp-load.php';
/**
 * Do **not** use the trailing slash. It would require
 * a more involved URL rewriting logic. Right now, we
 * can assume that relative URLs between all static files
 * in the same directory should retain the same structure.
 *
 * Imagine the following directory structure:
 * /directory
 *   index.md
 *   subpage.md
 *
 * If the permalink structure is /%postname%, we can reflect
 * the directory structure in the URLs and have two pages:
 * /index and /subpage. Any relative links between the two
 * files will work as expected.
 *
 * However, if the permalink structure is /%postname%/,
 * we end up with /index/ and /subpage/. A relative link
 * from index.md to "./subpage.md" would now point to
 * /index/subpage/ instead of /subpage/.
 *
 * Note we cannot simply structure the new pages as
 * /index/ and /index/subpage/ because the inverse is also true:
 * a link from subpage.md to "./index.md" would point to
 * /index/subpage/index/ instead of /index/.
 *
 * This conundrum could be resolved with an involved URL
 * resolver that is aware of the original URL structure,
 * the target URL structure, the URL of each post, and would
 * apply a transformation to all the URLs. This is not an
 * easy problem to solve – we would need to infer facts about
 * the URL structure of files that haven't been imported yet.
 *
 * It is much easier to just drop the trailing slash, so that's
 * what we're going to do.
 */
update_option( 'permalink_structure', '/%postname%' );
flush_rewrite_rules();
