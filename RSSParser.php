<?php

class RSSParser {
	protected $charset;
	protected $maxheads = 32;
	protected $reversed = false;
	protected $highlight = array();
	protected $filter = array();
	protected $filterOut = array();
	protected $itemTemplate;
	protected $url;
	protected $etag;
	protected $lastModified;
	protected $xml;
	protected $ERROR;
	protected $displayFields = array( 'author', 'title', 'encodedContent', 'description' );

	public $client;

	/**
	 * Convenience function that takes a space-separated string and returns an array of words
	 * @param String list of words
	 * @returns Array words found
	 */
	private static function explodeOnSpaces( $str ) {
		$found = preg_split( '# +#', $str );
		return is_array( $found ) ? $found : array();
	}

	/**
	 * Take a bit of WikiText that looks like
	 *   <rss max=5>http://example.com/</rss>
	 * and return an object that can produce rendered output.
	 */
	function __construct( $url, $args ) {

		$this->url = $url;

		# Get charset from argument array
		# FIXME: not used yet
		if ( isset( $args['charset'] ) ) {
			$this->charset = $args['charset'];
		} else {
			global $wgOutputEncoding;
			$this->charset = $wgOutputEncoding;
		}

		# Get max number of headlines from argument-array
		if ( isset( $args['max'] ) ) {
			$this->maxheads = $args['max'];
		}

		# Get reverse flag from argument array
		if ( isset( $args['reverse'] ) ) {
			$this->reversed = true;
		}

		# Get date format from argument array
		# FIXME: not used yet
		if ( isset( $args['date'] ) ) {
			$this->date = $args['date'];
		}

		# Get highlight terms from argument array
		if ( isset( $args['highlight'] ) ) {
			# mapping to lowercase here so the regex can be case insensitive below.
			$this->highlight = self::explodeOnSpaces( $args['highlight'] );
		}

		# Get filter terms from argument array
		if ( isset( $args['filter'] ) ) {
			$this->filter = self::explodeOnSpaces( $args['filter'] );
		}

		if ( isset( $args['filterout'] ) ) {
			$this->filterOut = self::explodeOnSpaces( $args['filterout'] );

		}

		if ( isset( $args['template'] ) ) {
			$titleObject = Title::newFromText( $args['template'], NS_TEMPLATE );
			$article = new Article( $titleObject, 0 );
			$this->itemTemplate = $article->fetchContent( );
		} else {
			$this->itemTemplate = wfMsgNoTrans( 'rss-item' );
		}
	}

	/**
	* Return RSS object for the given URL, maintaining caching.
	*
	* NOTES ON RETRIEVING REMOTE FILES:
	* No attempt will be made to fetch remote files if there is something in cache.
	*
	* NOTES ON FAILED REQUESTS:
	* If there is an HTTP error while fetching an RSS object, the cached version
	* will be returned, if it exists (and if $wgRSSCacheFreshOnly is false)
	*
	* @param $url String: URL of RSS file
	* @return boolean Status object
	*/
	function fetch( ) {
		global $wgRSSCacheAge, $wgRSSCacheFreshOnly;
		global $wgRSSCacheDirectory, $wgRSSFetchTimeout;
		global $wgRSSOutputEncoding, $wgRSSInputEncoding;
		global $wgRSSDetectEncoding;

		if ( !isset( $this->url ) ) {
			return Status::newFatal( 'rss-fetch-nourl' );
		}

		// Flow
		// 1. check cache
		// 2. if there is a hit, make sure its fresh
		// 3. if cached obj fails freshness check, fetch remote
		// 4. if remote fails, return stale object, or error
		$key = wfMemcKey( $this->url );
		$cachedFeed = $this->loadFromCache( $key );
		if ( $cachedFeed !== false ) {
			wfDebugLog( 'RSS', 'Outputting cached feed for ' . $this->url );
			return Status::newGood();
		}
		wfDebugLog( 'RSS', 'Cache Failed, fetching ' . $this->url. ' from remote.' );

		$status = $this->fetchRemote( $key );
		return $status;
	}

	/**
	 * Retrieve the URL from the cache
	 * @param string $key lookup key to associate with this item
	 * @returns boolean
	 */
	protected function loadFromCache( $key ) {
		global $wgMemc, $wgRSSCacheCompare;

		$data = $wgMemc->get( $key );
		if ( $data === false ) {
			return false;
		}

		list( $etag, $lastModified, $rss ) =
			$data;

		if ( !isset( $rss->items ) ) {
			return false;
		}

		wfDebugLog( 'RSS', "Got '$key' from cache" );

		# Now that we've verified that we got useful data, keep it around.
		$this->rss = $rss;
		$this->etag = $etag;
		$this->lastModified = $lastModified;

		// We only care if $wgRSSCacheCompare is > 0
		if ( $wgRSSCacheCompare && time() - $wgRSSCacheCompare > $lastModified ) {
			wfDebugLog( 'RSS', "Content is old enough that we need to check cached content");
			return false;
		}

		return true;
	}

	/**
	 * Store this objects (e.g. etag, lastModified, and RSS) in the cache.
	 * @param string $key lookup key to associate with this item
	 * @returns boolean
	 */
	protected function storeInCache( $key ) {
		global $wgMemc, $wgRSSCacheAge;

		if ( !isset( $this->rss ) ) {
			return false;
		}
		$r = $wgMemc->set( $key,
			array( $this->etag, $this->lastModified, $this->rss ),
			$wgRSSCacheAge );

		wfDebugLog( 'RSS', "Stored '$key' as in cache? $r");
		return true;
	}

	/**
	 * Retrieve a feed.
	 * @param $url String: URL of the feed.
	 * @param $headers Array: headers to send along with the request
	 * @return Status object
	 */
	protected function fetchRemote( $key, array $headers = array()) {
		global $wgRSSFetchTimeout;
		global $wgRSSUserAgent;

		if ( $this->etag ) {
			wfDebugLog( 'RSS', 'Used etag: ' . $this->etag );
			$headers['If-None-Match'] = $this->etag;
		}
		if ( $this->lastModified ) {
			$lm = gmdate('r', $this->lastModified);
			wfDebugLog( 'RSS', "Used last modified: $lm" );
			$headers['If-Modified-Since'] = $lm;
		}

		$client =
			HttpRequest::factory( $this->url, array( 'timeout' => $wgRSSFetchTimeout ) );
		$client->setUserAgent( $wgRSSUserAgent );
		foreach ( $headers as $header => $value ) {
			$client->setHeader( $header, $value );
		}

		$fetch = $client->execute();
		$this->client = $client;

		if ( !$fetch->isGood() ) {
			wfDebug( 'RSS', 'Request Failed: ' . $fetch->getWikiText() );
			return $fetch;
		}

		$ret = $this->responseToXML( $key );
		return $ret;
	}

	/**
	 * Render the entire feed so that each item is passed to the
	 * template which the MediaWiki then displays.
	 *
	 * @param $parser the parser param to pass to recursiveTagParse()
	 * @param $frame the frame param to pass to recursiveTagParse()
	 */
	function renderFeed( $parser, $frame ) {
		$output = "";
		if ( isset( $this->itemTemplate ) ) {
			$headcnt = 0;
			if ( $this->reversed ) {
				$this->rss->items = array_reverse( $this->rss->items );
			}

			foreach ( $this->rss->items as $item ) {
				if ( $this->maxheads > 0 && $headcnt >= $this->maxheads ) {
					continue;
				}

				if ( $this->canDisplay( $item ) ) {
					$output .= $this->renderItem( $item, $parser, $frame );
					$headcnt++;
				}
			}
		}
		return $output;
	}

	/**
	 * Render each item, filtering it out if necessary, applying any highlighting,
	 * @param $item an array produced by RSSData where keys are the names of the RSS elements
	 * @param $parser the parser param to pass to recursiveTagParse()
	 * @param $frame the frame param to pass to recursiveTagParse()
	 */
	protected function renderItem( $item, $parser, $frame ) {
		$parts = explode( '|', $this->itemTemplate );

		$output = "";
		if ( count( $parts ) > 1 && isset( $parser ) && isset( $frame ) ) {
			$rendered = array();
			foreach ( $this->displayFields as $field ) {
				if ( isset($item[$field] ) ) {
					$item[$field] = $this->highlightTerms( wfEscapeWikiText( $item[$field] ) );
				}
			}

			foreach ( $parts as $part ) {
				$bits = explode( '=', $part );
				$left = null;

				if ( count( $bits ) == 2 ) {
				$left = trim( $bits[0] );
				}

				if ( isset( $item[$left] ) ) {
					$leftValue = str_replace( '{{{' . $left . '}}}', $item[$left], $bits[1] );
					$rendered[] = "$left = $leftValue";
				} else {
					$rendered[] = $part;
				}
			}
			$output .= $parser->recursiveTagParse( implode( " | ", $rendered ), $frame );
		}
		return $output;
	}

	/**
	 * Parse an HTTP response object into an array of relevant RSS data
	 * @param $key the to use to store the parsaed response in the cache
	 * @return parsed RSS object (see RSSParse) or false
	 */
	protected function responseToXML( $key ) {
		wfDebugLog( 'RSS', "Got '".$this->client->getStatus()."', updating cache for $key" );
		if ( $this->client->getStatus() === 304 ) {
			# Not modified, update cache
			wfDebugLog( 'RSS', "Got 304, updating cache for $key" );
			$this->storeInCache( $key );
		} else {
			$this->xml = new DOMDocument;
			$raw_xml = $this->client->getContent();

			if( $raw_xml == "" ) {
				return Status::newFatal( 'rss-parse-error', "No XML content" );	
			}

			wfSuppressWarnings();
			$this->xml->loadXML( $raw_xml );
			wfRestoreWarnings();

			$this->rss = new RSSData( $this->xml );

			// if RSS parsed successfully
			if ( $this->rss && !$this->rss->ERROR ) {
				$this->etag = $this->client->getResponseHeader( 'Etag' );
				$this->lastModified =
					strtotime( $this->client->getResponseHeader( 'Last-Modified' ) );

				wfDebugLog( 'RSS', 'Stored etag (' . $this->etag . ') and Last-Modified (' .
					$this->client->getResponseHeader( 'Last-Modified' ) . ') and items (' .
					count( $this->rss->items ) . ')!' );
				$this->storeInCache( $key );
			} else {
				return Status::newFatal( 'rss-parse-error', $this->rss->ERROR );
			}
		}
		return Status::newGood();
	}

	/**
	 * Determine if a given item should or should not be displayed
	 * @param associative array that RSSData produced for an <item>
	 * @returns boolean
	 */
	protected function canDisplay( array $item ) {
		$check = "";

		/* We're only going to check the displayable fields */
		foreach ( $this->displayFields as $field ) {
			if ( isset( $item[$field] ) ) {
				$check .= $item[$field];
			}
		}

		if ( $this->filter( $check, 'filterOut' ) ) {
			return false;
		}
		if ( $this->filter( $check, 'filter' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Filters items in or out if the match a string we're looking for.
	 * @param String the text to examine
	 * @param String "filterOut" to check for matches in the filterOut member list.  Otherwise, uses the filter member list.
	 * @returns boolean decision to filter or not.
	 */
	protected function filter( $text, $filterType ) {
		if ( $filterType === 'filterOut' ) {
			$filter = $this->filterOut;
		} else {
			$filter = $this->filter;
		}

		if ( count( $filter ) == 0 ) return $filterType !== 'filterOut';

		/* Using : for delimiter here since it'll be quoted automatically. */
		$match = preg_match( ':(' . implode( "|", array_map('preg_quote', $filter ) ) . '):i', $text ) ;
		if ( $match ) {
			return true;
		}
		return false;
	}

	/**
	 * Highlight the words we're supposed to be looking for
	 * @param String the text to look in.
	 * @returns String with matched text highlighted in a <span> element
	 */
	protected function highlightTerms( $text ) {
		if ( count( $this->highlight ) === 0 ) {
			return $text;
		}

		RSSHighlighter::setTerms( $this->highlight );
		$highlight = ':'. implode( "|", array_map( 'preg_quote', array_values( $this->highlight ) ) ) . ':i';
		return preg_replace_callback( $highlight, 'RSSHighlighter::highlightThis', $text );
	}
}


class RSSHighlighter {
	static $terms = array();

	/**
	 * Set the list of terms to match for the next highlighting session
	 * @param List of words to match.
	 */
	static function setTerms( array $terms ) {
		self::$terms = array_flip( array_map( 'strtolower', $terms ) );
	}

	/**
	 * Actually replace the supplied list of words with HTML code to highlight the words.
	 * @param List of matched words to highlight.  The words are assigned colors based upon the order they were supplied in setTerms()
	 * @returns String word wrapped in HTML code.
	 */
	static function highlightThis( $match ) {
		$styleStart = "<span style='font-weight: bold; background: none repeat scroll 0%% 0%% rgb(%s); color: %s;'>";
		$styleEnd   = "</span>";

		# bg colors cribbed from Google's highlighting of search teerms
		$bgcolor = array( '255, 255, 102', '160, 255, 255', '153, 255, 153',
			'255, 153, 153', '255, 102, 255', '136, 0, 0', '0, 170, 0', '136, 104, 0',
			'0, 70, 153', '153, 0, 153' );
		# Spelling out the fg colors instead of using processing time to create this list
		$color = array("black", "black", "black", "black", "black",
			"white", "white", "white", "white", "white" );

		$index = self::$terms[strtolower($match[0])] % count( $bgcolor );

		return sprintf($styleStart, $bgcolor[$index], $color[$index]). $match[0] .$styleEnd;
	}
}
