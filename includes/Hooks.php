<?php

namespace MediaWiki\Extension\RSS;

use MWHttpRequest;
use Parser;
use PPFrame;
use Status;

class Hooks {

	/**
	 * Tell the parser how to handle <rss> elements
	 * @param Parser $parser Parser Object
	 * @return bool
	 */
	public static function onParserFirstCallInit( $parser ) {
		// Install parser hook for <rss> tags
		$parser->setHook( 'rss', [ __CLASS__, 'renderRss' ] );
		return true;
	}

	/**
	 * Static function wrapping RSSParser to handle rendering of RSS elements
	 * @param string $input text inside the tags.
	 * @param array $args value associative list of the element attributes and
	 * 						their values.
	 * @param Parser $parser
	 * @param PPFrame $frame parser context
	 * @return string
	 */
	public static function renderRss( $input, array $args, Parser $parser, PPFrame $frame ) {
		global $wgRSSCacheAge, $wgRSSCacheCompare, $wgRSSNamespaces,
			$wgRSSUrlWhitelist,$wgRSSAllowedFeeds;

		if ( is_array( $wgRSSNamespaces ) && count( $wgRSSNamespaces ) ) {
			$nsUsed = $parser->getTitle()->getNamespace();
			$authorizedNamespace = array_flip( $wgRSSNamespaces );

			if ( !isset( $authorizedNamespace[$nsUsed] ) ) {
				return Utils::getErrorHtml( 'rss-ns-permission' );
			}
		}

		if ( isset( $wgRSSAllowedFeeds ) ) {
			return Utils::getErrorHtml( 'rss-deprecated-wgrssallowedfeeds-found' );
		}

		# disallow because there is no whitelist at all or an empty whitelist

		if ( !isset( $wgRSSUrlWhitelist )
			|| !is_array( $wgRSSUrlWhitelist )
			|| ( count( $wgRSSUrlWhitelist ) === 0 )
		) {
			return Utils::getErrorHtml( 'rss-empty-allow-list',
				$input
			);
		}

		# disallow the feed url because the url is not allowed;  or
		# disallow because the wildcard joker is not present to allow any feed url
		# which can be dangerous

		if ( !( in_array( $input, $wgRSSUrlWhitelist ) )
			&& !( in_array( "*", $wgRSSUrlWhitelist ) ) ) {
			$listOfAllowed = $parser->getFunctionLang()->listToText( $wgRSSUrlWhitelist );
			$numberAllowed = $parser->getFunctionLang()->formatNum( count( $wgRSSUrlWhitelist ) );

			return Utils::getErrorHtml( 'rss-url-is-not-allowed',
				[ $input, $listOfAllowed, $numberAllowed ]
			);

		}

		if ( !MWHttpRequest::isValidURI( $input ) ) {
			return Utils::getErrorHtml( 'rss-invalid-url', htmlspecialchars( $input ) );
		}

		if ( $wgRSSCacheCompare ) {
			$timeout = $wgRSSCacheCompare;
		} else {
			$timeout = $wgRSSCacheAge;
		}

		$parser->getOutput()->updateCacheExpiry( $timeout );

		$rss = new RSSParser( $input, $args );

		$status = $rss->fetch();

		# Check for errors.
		if ( !$status->isGood() ) {
			return wfMessage(
				'rss-error', htmlspecialchars( $input ), Status::wrap( $status )->getWikitext()
			)->escaped();
		}

		if ( !is_object( $rss->rss ) || !is_array( $rss->rss->items ) ) {
			return Utils::getErrorHtml( 'rss-empty', htmlspecialchars( $input ) );
		}

		return $rss->renderFeed( $parser, $frame );
	}

}
