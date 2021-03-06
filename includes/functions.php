<?php

/**
 * @param array $links  Link headers as a string
 * @param string $url URL to use to make absolute
 * @return array $rels rel values as indices to arrays of URLs, empty array if no rels at all
*/
function parse_link_rels( $links, $url ) {
	$rels = array();
	foreach ( $links as $link ) {
		$hrefandrel = explode( '; ', $link );
		$href       = trim( $hrefandrel[0], '<>' );
		$relarray   = '';
		foreach ( $hrefandrel as $p ) {
			if ( ! strncmp( $p, 'rel=', 4 ) ) {
				$relarray = explode( ' ', trim( substr( $p, 4 ), '"\'' ) );
				break;
			}
		}
		if ( ! empty( $relarray ) ) { // ignore Link: headers without rel
			foreach ( $relarray as $rel ) {
				$rel = strtolower( trim( $rel ) );
				if ( ! empty( $rel ) ) {
					$rels[ $rel ] = WP_Http::make_absolute_url( $href, $url );
				}
			}
		}
	}
	return $rels;
}


/**
 * Finds rels on the given URL
 *
 * Checks for specific rel properties in a URL. It does
 * a check for the headers first and returns that, if available
 *
 * @param string $me URL
 * @param string|array $endpoints Specific endpoints to search for
 *
 *
 * @return bool|array|string False on failure, array containing one or both or the headers on success or string if single property
 */
function find_rels( $me, $endpoints = null ) {
	if ( ! $endpoints ) {
		$endpoints = array( 'authorization_endpoint', 'token_endpoint', 'me' );
	}
	/** @todo Should use Filter Extension or custom preg_match instead. */
	$parsed_url = wp_parse_url( $me );
	if ( ! isset( $parsed_url['host'] ) ) { // Not an URL. This should never happen.
		return false;
	}
	// do not search for an Indieauth server on our own uploads
	$uploads_dir = wp_upload_dir();
	if ( 0 === strpos( $me, $uploads_dir['baseurl'] ) ) {
		return false;
	}
	$wp_version = get_bloginfo( 'version' );
	$user_agent = apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . get_bloginfo( 'url' ) );
	$args       = array(
		'timeout'             => 100,
		'limit_response_size' => 1048576,
		'redirection'         => 3,
		'user-agent'          => "$user_agent; finding rel properties",
	);
	$response   = wp_safe_remote_head( $me, $args );
	if ( is_wp_error( $response ) ) {
		return false;
	}
	$return = array();
	// check link header
	$links = wp_remote_retrieve_header( $response, 'link' );
	if ( $links ) {
		if ( is_string( $links ) ) {
			$links = array( $links );
		}
		$return = parse_link_rels( $links, $me );
	}
	if ( $return ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		switch ( $code ) {
			case 301:
			case 308:
				$return['me'] = wp_remote_retrieve_header( $response, 'Location' );
				break;
		}
		if ( isset( $return['me'] ) ) {
			$me = $return['me'];
		}
		if ( is_array( $endpoints ) ) {
			$return = wp_array_slice_assoc( $return, $endpoints );
			if ( ! empty( $return ) ) {
				return $return;
			}
		}
		if ( is_string( $endpoints ) && isset( $return[ $endpoints ] ) ) {
				return $return[ $endpoints ];
		}
	}

	// not an (x)html, sgml, or xml page, no use going further
	if ( preg_match( '#(image|audio|video|model)/#is', wp_remote_retrieve_header( $response, 'content-type' ) ) ) {
		return false;
	}
	// now do a GET since we're going to look in the html headers (and we're sure its not a binary file)
	$response = wp_safe_remote_get( $me, $args );
	if ( is_wp_error( $response ) ) {
		return false;
	}
	$contents = wp_remote_retrieve_body( $response );
	$return   = parse_html_rels( $contents, $me );
	if ( is_array( $endpoints ) ) {
		$return = wp_array_slice_assoc( $return, $endpoints );
		if ( ! empty( $return ) ) {
			return $return;
		}
	} elseif ( is_string( $endpoints ) && isset( $return[ $endpoints ] ) ) {
		return $return[ $endpoints ];
	}
	return false;
}

/**
 * @param array $contents HTML to parse for rel links
 * @param string $url URL to use to make absolute
 * @return array $rels rel values as indices to arrays of URLs, empty array if no rels at all
*/
function parse_html_rels( $contents, $url ) {
	// unicode to HTML entities
	$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );
	libxml_use_internal_errors( true );
	$doc = new DOMDocument();
	$doc->loadHTML( $contents );
	$xpath = new DOMXPath( $doc );
	// check <link> and <a> elements
	foreach ( $xpath->query( '//a[@rel and @href] | //link[@rel and @href]' ) as $hyperlink ) {
		$return[ $hyperlink->getAttribute( 'rel' ) ] = WP_Http::make_absolute_url( $hyperlink->getAttribute( 'href' ), $url );
	}
	return $return;
}


/**
 * Get the user associated with the specified Identifier-URI.
 *
 * @param string $identifier identifier to match
 * @return WP_User $user Associated user, or null if no associated user
 */
function get_user_by_identifier( $identifier ) {
	if ( empty( $identifier ) ) {
		return null;
	}
	// Ensure has trailing slash
	$identifier = trailingslashit( $identifier );
	if ( ( 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ) && ( wp_parse_url( home_url(), PHP_URL_HOST ) === wp_parse_url( $identifier, PHP_URL_HOST ) ) ) {
		$identifier = set_url_scheme( $identifier, 'https' );
	}
	// Try to save the expense of a search query if the URL is the site URL
	if ( home_url( '/' ) === $identifier ) {
		// Use the Indieweb settings to set the default author
		if ( class_exists( 'Indieweb_Plugin' ) && ( get_option( 'iw_single_author' ) || ! is_multi_author() ) ) {
			return get_user_by( 'id', get_option( 'iw_default_author' ) );
		}
		$users = get_users( array( 'who' => 'authors' ) );
		if ( 1 === count( $users ) ) {
			return $users[0];
		}
		return null;

	}
	// Check if this is a author post URL
	$user = url_to_author( $identifier );
	if ( $user ) {
		return $user;
	}
	$args       = array(
		'search'         => $identifier,
		'search_columns' => array( 'user_url' ),
	);
	$users = get_users( $args );
	// check result
	if ( ! empty( $users ) ) {
		return $users[0];
	}
	return null;
}

/**
 * Examine a url and try to determine the author ID it represents.
 *
 *
 * @param string $url Permalink to check.
 *
 * @return WP_User, or null on failure.
 */
function url_to_author( $url ) {
	global $wp_rewrite;
	// check if url hase the same host
	if ( wp_parse_url( site_url(), PHP_URL_HOST ) !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return null;
	}
	// first, check to see if there is a 'author=N' to match against
	if ( preg_match( '/[?&]author=(\d+)/i', $url, $values ) ) {
		$id = absint( $values[1] );
		if ( $id ) {
			return $id;
		}
	}
	// check to see if we are using rewrite rules
	$rewrite = $wp_rewrite->wp_rewrite_rules();
	// not using rewrite rules, and 'author=N' method failed, so we're out of options
	if ( empty( $rewrite ) ) {
		return null;
	}
	// generate rewrite rule for the author url
	$author_rewrite = $wp_rewrite->get_author_permastruct();
	$author_regexp  = str_replace( '%author%', '', $author_rewrite );
	// match the rewrite rule with the passed url
	if ( preg_match( '/https?:\/\/(.+)' . preg_quote( $author_regexp, '/' ) . '([^\/]+)/i', $url, $match ) ) {
		$user = get_user_by( 'slug', $match[2] );
		if ( $user ) {
			return $user->ID;
		}
	}
	return null;
}


/**
 * Returns if valid URL for REST validation
 *
 * @param string $url
 *
 * @return boolean
 */
function rest_is_valid_url( $url, $request = null, $key = null ) {
	if ( ! is_string( $url ) || empty( $url ) ) {
		return false;
	}
	return filter_var( $url, FILTER_VALIDATE_URL );
}

function indieauth_rest_url( $path = '' ) {
	// rest_url is being called too early for wp_rewrite to be set
	// This fallback checks and returns the non rewritten version
	global $wp_rewrite;
	if ( ! $wp_rewrite ) {
		return home_url( 'index.php?rest_route=' . $path );
	}
	return rest_url( $path );
}

// https://github.com/ralouphie/getallheaders
if ( ! function_exists( 'getallheaders' ) ) {

	/**
	 * Get all HTTP header key/values as an associative array for the current request.
	 *
	 * @return string[string] The HTTP header key/value pairs.
	 */
	function getallheaders() {
		$headers = array();

		$copy_server = array(
			'CONTENT_TYPE'   => 'Content-Type',
			'CONTENT_LENGTH' => 'Content-Length',
			'CONTENT_MD5'    => 'Content-Md5',
		);

		foreach ( $_SERVER as $key => $value ) {
			if ( substr( $key, 0, 5 ) === 'HTTP_' ) {
				$key = substr( $key, 5 );
				if ( ! isset( $copy_server[ $key ] ) || ! isset( $_SERVER[ $key ] ) ) {
					$key             = str_replace( ' ', '-', ucwords( strtolower( str_replace( '_', ' ', $key ) ) ) );
					$headers[ $key ] = $value;
				}
			} elseif ( isset( $copy_server[ $key ] ) ) {
				$headers[ $copy_server[ $key ] ] = $value;
			}
		}

		if ( ! isset( $headers['Authorization'] ) ) {
			if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
				$headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
			} elseif ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
				$basic_pass               = isset( $_SERVER['PHP_AUTH_PW'] ) ? $_SERVER['PHP_AUTH_PW'] : '';
				$headers['Authorization'] = 'Basic ' . base64_encode( $_SERVER['PHP_AUTH_USER'] . ':' . $basic_pass );
			} elseif ( isset( $_SERVER['PHP_AUTH_DIGEST'] ) ) {
				$headers['Authorization'] = $_SERVER['PHP_AUTH_DIGEST'];
			}
		}

		return $headers;
	}
}
