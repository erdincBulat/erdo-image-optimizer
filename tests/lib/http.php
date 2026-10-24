<?php
/**
 * Front-end checks over real HTTP against the running Studio site.
 * These are what catch "the page shows broken images" — the class of bug
 * unit-level checks missed in 2.2.x.
 */

function et_site_up() {
    $r = wp_remote_head( home_url( '/' ), array( 'timeout' => 10 ) );
    return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 500;
}

function et_fetch( $url ) {
    $r = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Accept' => 'text/html' ) ) );
    if ( is_wp_error( $r ) ) {
        throw new ET_Fail( 'fetch failed: ' . $r->get_error_message() . ' ' . $url );
    }
    return wp_remote_retrieve_body( $r );
}

/**
 * HTTP status of a URL. Redirects are not followed so 301s can be asserted;
 * $accept lets us simulate a WebP/AVIF-capable browser for rewrite rules.
 */
function et_status( $url, $accept = 'image/avif,image/webp,image/*,*/*' ) {
    static $cache = array();
    $key = $url . '|' . $accept;
    if ( ! isset( $cache[ $key ] ) ) {
        $r = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Accept' => $accept ) ) );
        $cache[ $key ] = is_wp_error( $r ) ? 0 : array(
            'code'     => (int) wp_remote_retrieve_response_code( $r ),
            'location' => (string) wp_remote_retrieve_header( $r, 'location' ),
            'type'     => (string) wp_remote_retrieve_header( $r, 'content-type' ),
        );
    }
    return $cache[ $key ];
}

function et_srcset_urls( $srcset ) {
    $urls = array();
    foreach ( explode( ',', (string) $srcset ) as $part ) {
        $u = strtok( trim( $part ), ' ' );
        if ( $u ) {
            $urls[] = html_entity_decode( $u );
        }
    }
    return $urls;
}

/** Every <img> and <source> in the page with the attributes we care about. */
function et_image_tags( $html ) {
    $tags = array();
    $p    = new WP_HTML_Tag_Processor( $html );
    while ( $p->next_tag() ) {
        $tag = $p->get_tag();
        if ( 'IMG' !== $tag && 'SOURCE' !== $tag ) {
            continue;
        }
        $t = array( 'tag' => strtolower( $tag ) );
        foreach ( array( 'src', 'srcset', 'type', 'loading', 'fetchpriority', 'class', 'width', 'height' ) as $a ) {
            $v = $p->get_attribute( $a );
            if ( null !== $v && true !== $v ) {
                $t[ $a ] = $v;
            }
        }
        $tags[] = $t;
    }
    return $tags;
}

/**
 * Audits a rendered page's images. Returns human-readable problems, each
 * prefixed with its kind:
 *  - broken: an uploads URL in img[src], img[srcset] or source[srcset] not answering 200;
 *  - markup: <source src> (invalid inside <picture>, browsers ignore it);
 *  - lcp:    an image that is both fetchpriority=high and loading=lazy.
 */
function et_page_image_problems( $url ) {
    $html     = et_fetch( $url );
    $uploads  = wp_upload_dir()['baseurl'];
    $problems = array();
    foreach ( et_image_tags( $html ) as $t ) {
        if ( 'source' === $t['tag'] && isset( $t['src'] ) ) {
            $problems[] = 'markup: invalid <source src> ' . basename( $t['src'] );
        }
        $urls = et_srcset_urls( $t['srcset'] ?? '' );
        if ( 'img' === $t['tag'] && isset( $t['src'] ) ) {
            $urls[] = html_entity_decode( $t['src'] );
        }
        foreach ( $urls as $u ) {
            if ( 0 !== strpos( $u, $uploads ) ) {
                continue;
            }
            $s = et_status( $u );
            if ( ! $s || 200 !== $s['code'] ) {
                $problems[] = 'broken: HTTP ' . ( $s ? $s['code'] : 'ERR' ) . ' ' . basename( $u ) . ' (' . $t['tag'] . ')';
            }
        }
        if ( 'img' === $t['tag'] && 'high' === ( $t['fetchpriority'] ?? '' ) && 'lazy' === ( $t['loading'] ?? '' ) ) {
            $problems[] = 'lcp: image lazy-loaded ' . basename( $t['src'] ?? '' );
        }
    }
    return array_values( array_unique( $problems ) );
}

/**
 * Fails the current test if the page has an image problem of the given kinds
 * (default: all). Lets a rename test assert "nothing broken" without also
 * failing on the unrelated LCP gap.
 */
function et_assert_page_ok( $post_id, $label = '', $kinds = array( 'broken', 'markup', 'lcp' ) ) {
    $problems = array_filter( et_page_image_problems( get_permalink( $post_id ) ), function ( $p ) use ( $kinds ) {
        return in_array( strtok( $p, ':' ), $kinds, true );
    } );
    et_assert( empty( $problems ), ( $label ? $label . ': ' : '' ) . implode( '; ', $problems ) );
}
