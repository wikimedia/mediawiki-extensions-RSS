<?php
/**
 * RSS-Feed MediaWiki extension
 *
 * @file
 * @ingroup Extensions
 * @version 1.94
 * @author mutante, Daniel Kinzler, Rdb, Mafs, Thomas Gries, Alxndr, Chris Reigrut, K001
 * @author Kellan Elliott-McCrea <kellan@protest.net> -- author of MagpieRSS
 * @author Jeroen De Dauw
 * @author Jack Phoenix <jack@countervandalism.net>
 * @copyright © Kellan Elliott-McCrea <kellan@protest.net>
 * @copyright © mutante, Daniel Kinzler, Rdb, Mafs, Thomas Gries, Alxndr, Chris Reigrut, K001
 * @link http://www.mediawiki.org/wiki/Extension:RSS Documentation
 */

define( "EXTENSION_RSS_VERSION", "1.94 20120223" );

if ( !defined( 'MEDIAWIKI' ) ) {
	die( "This is not a valid entry point.\n" );
}

// Extension credits that will show up on Special:Version
$wgExtensionCredits['parserhook'][] = array(
	'path' => __FILE__,
	'name' => 'RSS feed',
	'author' => array( 'Kellan Elliott-McCrea', 'mutante', 'Daniel Kinzler',
		'Rdb', 'Mafs', 'Alxndr', 'Thomas Gries', 'Chris Reigrut',
		'K001', 'Jack Phoenix', 'Jeroen De Dauw', 'Mark A. Hershberger'
	),
	'version' => EXTENSION_RSS_VERSION,
	'url' => 'https://www.mediawiki.org/wiki/Extension:RSS',
	'descriptionmsg' => 'rss-desc',
);

// Internationalization file and autoloadable classes
$dir = dirname( __FILE__ ) . '/';
$wgExtensionMessagesFiles['RSS'] = $dir . 'RSS.i18n.php';
$wgAutoloadClasses['RSSHooks'] = $dir . 'RSSHooks.php';
$wgAutoloadClasses['RSSParser'] = $dir . 'RSSParser.php';
$wgAutoloadClasses['RSSUtils'] = $dir . 'RSSParser.php';
$wgAutoloadClasses['RSSData'] = $dir . 'RSSData.php';

$wgHooks['ParserFirstCallInit'][] = 'RSSHooks::parserInit';

// one hour
$wgRSSCacheAge = 3600;

// Check cached content, if available, against remote.
// $wgRSSCacheCompare should be set to false or a timeout
// (less than $wgRSSCacheAge) after which a comparison will be made.
$wgRSSCacheCompare = false;

// 5 second timeout
$wgRSSFetchTimeout = 5;

// Ignore the RSS tag in all but the namespaces listed here.
// null (the default) means the <rss> tag can be used anywhere.
$wgRSSNamespaces = null;

// Whitelist of allowed RSS Urls
//
// If there are items in the array, and the user supplied URL is not in the array,
// the url will not be allowed
//
// Urls are case-sensitively tested against values in the array. 
// They must exactly match including any trailing "/" character.
//
// Warning: Allowing all urls (not setting a whitelist)
// may be a security concern.
//
// an empty or non-existent array means: no whitelist defined
// this is the default: an empty whitelist. No servers are allowed by default.
$wgRSSUrlWhitelist = array();

// include "*" if you expressly want to allow all urls (you should not do this)
// $wgRSSUrlWhitelist = array( "*" );

// Agent to use for fetching feeds
$wgRSSUserAgent = "MediaWikiRSS/" . strtok( EXTENSION_RSS_VERSION, " " ) . " (+http://www.mediawiki.org/wiki/Extension:RSS) / MediaWiki RSS extension";

// Proxy server to use for fetching feeds
$wgRSSProxy = false;

// default date format of item publication dates see http://www.php.net/date
$wgRSSDateDefaultFormat = "(Y-m-d H:i:s)";

// limit the number of characters in the item description
// or set to false for unlimited length.
// $wgRSSItemMaxLength = false;
// $wgRSSItemMaxLength = 100;
