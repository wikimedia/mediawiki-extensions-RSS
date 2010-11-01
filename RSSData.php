<?php

class RSSData {
	public $ERROR;
	public $items;

	function __construct( $xml ) {
		if ( !( $xml instanceOf DOMDocument ) ) {
			return null;
		}
		$xpath = new DOMXPath( $xml );
		$items = $xpath->evaluate( '/rss/channel/item' );

		foreach ( $items as $item ) {
			$bit = array();
			foreach ( $item->childNodes as $n ) {
				$name = $this->rssTokenToName( $n->nodeName );
				if ( $name != null ) {
					/* Because for DOMElements the nodeValue is just
					 * the text of the containing element, without any
					 * tags, it makes this a safe, if unattractive,
					 * value to use. If you want to allow people to
					 * mark up their RSS, some more precautions are
					 * needed. */
					$bit[$name] = $n->nodeValue;
				}
			}
			$this->items[] = $bit;
		}
	}

	function rssTokenToName( $n ) {
		switch( $n ) {
			case 'dc:date':
				return 'date';
				# parse "2010-10-18T18:07:00Z"
			case 'pubDate':
				return 'date';
				# parse RFC date
			case 'dc:creator':
				return 'author';
			case 'title':
				return 'title';
			case 'content:encoded':
				return 'encodedContent';

			case 'slash:comments':
			case 'slash:department':
			case 'slash:section':
			case 'slash:hit_parade':
			case 'feedburner:origLink':
			case 'wfw:commentRss':
			case 'comments':
			case 'category':
				return null;

			default:
				return $n;
		}
	}
}