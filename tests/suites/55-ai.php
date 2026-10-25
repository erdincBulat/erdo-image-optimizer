<?php
/**
 * AI suggestions (PLAN-3.0 Faz 5). Contract: uses WordPress' own AI Client
 * (no key stored by the plugin), sends a small image plus page context, and
 * never writes anything until the user accepts the suggestion.
 * The provider is stubbed (see adapter): the Studio site has no AI key.
 */

$m = et_fixtures();
et_index_rebuild();

$answer = array(
    'decorative'        => false,
    'alt'               => 'Blue running shoes on a wooden gym floor',
    'filename'          => 'Blue Running Shoes Wooden Floor',
    'title'             => 'Blue running shoes',
    'caption'           => 'Lightweight shoes for daily runs.',
    'current_alt_ok'    => true,
    'current_alt_issue' => '',
);

/* --- Availability ---------------------------------------------------- */
et_test( 'AI availability matches the connected provider and links to Connectors', function () {
    $s = et_ai_status();
    if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
        et_eq( 'wp', $s['reason'] );
        return;
    }
    // A real provider may be connected on the test site (Settings > Connectors); then it must report ready.
    $connected = wp_ai_client_prompt( 'Describe this image.' )->with_file( ERDO_AI::PROBE_PNG, 'image/png' )->is_supported_for_text_generation();
    et_eq( ! $connected, ! $s['ready'] );
    et_eq( $connected ? '' : 'provider', $s['reason'] );
    et_assert( false !== strpos( $s['setup_url'], 'options-connectors.php' ), 'no link to Connectors' );
} );

et_test( 'suggest refuses when AI is unavailable and stores nothing', function () use ( $m ) {
    et_ai_unstub();
    delete_transient( 'erdo_ai_status' );
    // Forced off: a real provider may be connected, and this must not make a paid call.
    $off = function ( $s ) {
        return array_merge( $s, array( 'ready' => false, 'reason' => 'provider' ) );
    };
    add_filter( 'erdo_ai_status', $off );
    $r = et_ai_suggest( $m['img']['badname'] );
    remove_filter( 'erdo_ai_status', $off );
    et_assert( is_wp_error( $r ), 'no error' );
    et_eq( null, et_ai_suggestion( $m['img']['badname'] ) );
} );

/* --- Suggestion is stored, not applied ------------------------------- */
et_ai_stub( $answer );
update_post_meta( $m['post']['good'], '_yoast_wpseo_focuskw', 'running shoes' );
$alt_before = get_post_meta( $m['img']['good'], '_wp_attachment_image_alt', true );
$sugg       = et_ai_suggest( $m['img']['good'] );
$req        = et_ai_last_request();

et_test( 'suggestion is stored for review and nothing on the image changes', function () use ( $m, $sugg, $alt_before ) {
    et_not_error( $sugg );
    et_eq( 'Blue running shoes on a wooden gym floor', et_ai_suggestion( $m['img']['good'] )['alt'] ?? null );
    et_eq( $alt_before, get_post_meta( $m['img']['good'], '_wp_attachment_image_alt', true ), 'alt changed before review' );
    et_eq( 'blue-running-shoes.jpg', basename( get_attached_file( $m['img']['good'] ) ), 'file renamed before review' );
    et_assert( in_array( $m['img']['good'], et_ai_pending(), true ), 'not in the review queue' );
} );

et_test( 'suggested filename is sanitised (lowercase, hyphens)', function () use ( $sugg ) {
    et_eq( 'blue-running-shoes-wooden-floor', $sugg['filename'] );
} );

et_test( 'context: page title, focus keyword, nearby text and language are sent', function () use ( $req ) {
    et_assert( $req, 'no request captured' );
    et_eq( 'FX Good', $req['context']['page_title'] );
    et_eq( 'running shoes', $req['context']['focus_keyword'] );
    et_assert( false !== strpos( $req['context']['nearby_text'], 'Shoes.' ), 'nearby text: ' . $req['context']['nearby_text'] );
    et_assert( false !== strpos( $req['context']['language'], get_locale() ), 'language: ' . $req['context']['language'] );
    et_assert( false !== strpos( $req['prompt'], 'running shoes' ), 'keyword not in prompt' );
} );

et_test( 'instructions: no people names, no "image of", alt length limit, JSON schema', function () use ( $req ) {
    et_assert( false !== stripos( $req['system'], 'never guess or name real people' ), 'people rule missing' );
    et_assert( false !== stripos( $req['system'], 'image of' ), '"image of" rule missing' );
    et_assert( false !== strpos( $req['system'], '125' ), 'length limit missing' );
    et_eq( array( 'decorative', 'alt', 'filename', 'title', 'caption', 'current_alt_ok', 'current_alt_issue' ), $req['schema']['required'] );
} );

et_test( 'privacy: no author name or e-mail goes to the provider', function () use ( $req ) {
    $blob = wp_json_encode( array( $req['system'], $req['prompt'], $req['context'] ) );
    $user = wp_get_current_user();
    et_assert( false === strpos( $blob, (string) get_option( 'admin_email' ) ), 'admin e-mail sent' );
    if ( $user->user_email ) {
        et_assert( false === strpos( $blob, $user->user_email ), 'user e-mail sent' );
    }
} );

/* --- Image that is sent ---------------------------------------------- */
et_test( 'image sent is at most 1024 px and a format every provider reads', function () use ( $m ) {
    foreach ( array( 'oversized', 'good', 'logo', 'anim' ) as $key ) {
        $img = et_not_error( et_ai_image( $m['img'][ $key ] ), $key );
        et_assert( max( $img['width'], $img['height'] ) <= 1024, "$key sent at {$img['width']}x{$img['height']}" );
        et_assert( in_array( $img['mime'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ), "$key sent as {$img['mime']}" );
        et_assert( 0 === strpos( $img['data'], 'data:' . $img['mime'] . ';base64,' ), "$key data URI" );
    }
} );

et_test( 'large images are not sent as tiny thumbnails', function () use ( $m ) {
    $img = et_ai_image( $m['img']['oversized'] );
    et_assert( max( $img['width'], $img['height'] ) >= 512, "only {$img['width']}x{$img['height']}" );
} );

et_test( 'AVIF is converted before sending (Claude does not read AVIF)', function () use ( $m ) {
    if ( ! function_exists( 'imageavif' ) ) {
        et_skip( 'GD without AVIF' );
    }
    $src = dirname( get_attached_file( $m['img']['good'] ) ) . '/fx-ai-avif.avif';
    $im  = imagecreatefromjpeg( get_attached_file( $m['img']['good'] ) );
    if ( ! @imageavif( $im, $src, 50, 9 ) || ! filesize( $src ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
        et_skip( 'AVIF encode failed on this host' );
    }
    $id  = et_add_attachment( $src, 'fx ai avif', 'AVIF' );
    $img = et_not_error( et_ai_image( $id ) );
    et_assert( 'image/avif' !== $img['mime'], 'sent as AVIF' );
} );

/* --- Review: accept / edit / dismiss --------------------------------- */
et_test( 'accept alt: saved to the image and the suggestion leaves the queue', function () use ( $m ) {
    et_not_error( et_ai_accept( $m['img']['good'], array( 'alt' ) ) );
    et_eq( 'Blue running shoes on a wooden gym floor', get_post_meta( $m['img']['good'], '_wp_attachment_image_alt', true ) );
    et_eq( null, et_ai_suggestion( $m['img']['good'] ) );
    et_assert( ! in_array( $m['img']['good'], et_ai_pending(), true ), 'still pending' );
    et_eq( 'blue-running-shoes.jpg', basename( get_attached_file( $m['img']['good'] ) ), 'filename applied without being selected' );
} );

et_test( 'accept with the user\'s edit saves the edited text', function () use ( $m ) {
    $id = $m['img']['block'];
    et_not_error( et_ai_suggest( $id ) );
    et_not_error( et_ai_accept( $id, array( 'alt', 'caption' ), array( 'alt' => 'Edited <b>alt</b> text' ) ) );
    et_eq( 'Edited alt text', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
    et_eq( 'Lightweight shoes for daily runs.', get_post_field( 'post_excerpt', $id ) );
} );

et_test( 'accept filename: renames safely, content follows', function () use ( $m, $answer ) {
    $id = $m['img']['featured'];
    et_ai_stub( array_merge( $answer, array( 'filename' => 'featured-hero-photo' ) ) );
    et_not_error( et_ai_suggest( $id ) );
    et_not_error( et_ai_accept( $id, array( 'filename' ) ) );
    et_eq( 'featured-hero-photo.jpg', basename( get_attached_file( $id ) ) );
    et_assert_page_ok( $m['post']['featured'], '', array( 'broken' ) );
} );

et_test( 'decorative suggestion: empty alt + decorative flag, alt check passes', function () use ( $m, $answer ) {
    $id = $m['img']['siteicon'];
    et_ai_stub( array_merge( $answer, array( 'decorative' => true, 'alt' => 'should be dropped' ) ) );
    $s = et_not_error( et_ai_suggest( $id ) );
    et_eq( '', $s['alt'], 'decorative alt not emptied' );
    et_not_error( et_ai_accept( $id, array( 'alt' ) ) );
    et_eq( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
    et_eq( true, et_audit( $id )['checks']['alt'] );
    et_set_decorative( $id, false );
} );

et_test( 'dismiss: suggestion removed, image unchanged', function () use ( $m, $answer ) {
    $id = $m['img']['header'];
    et_ai_stub( $answer );
    $before = get_post_meta( $id, '_wp_attachment_image_alt', true );
    et_not_error( et_ai_suggest( $id ) );
    et_ai_reject( $id );
    et_eq( null, et_ai_suggestion( $id ) );
    et_eq( $before, get_post_meta( $id, '_wp_attachment_image_alt', true ) );
} );

et_test( 'existing weak alt: the model\'s objection is kept for the reviewer', function () use ( $m, $answer ) {
    $id = $m['img']['dscalt'];
    et_ai_stub( array_merge( $answer, array( 'current_alt_ok' => false, 'current_alt_issue' => 'The current alt is just a camera file name.' ) ) );
    $s = et_not_error( et_ai_suggest( $id ) );
    et_eq( false, $s['current_alt_ok'] );
    et_eq( 'The current alt is just a camera file name.', $s['current_alt_issue'] );
    et_ai_reject( $id );
} );

/* --- Robustness ------------------------------------------------------ */
et_test( 'unreadable answer is an error and nothing is stored', function () use ( $m ) {
    et_ai_stub( 'Sorry, I cannot help with that.' );
    $r = et_ai_suggest( $m['img']['elementor'] );
    et_assert( is_wp_error( $r ), 'no error' );
    et_eq( null, et_ai_suggestion( $m['img']['elementor'] ) );
} );

et_test( 'JSON wrapped in a ``` code fence is still read; alt is trimmed', function () use ( $m, $answer ) {
    et_ai_stub( "```json\n" . wp_json_encode( array_merge( $answer, array( 'alt' => '  ' . str_repeat( 'long ', 60 ) ) ) ) . "\n```" );
    $s = et_not_error( et_ai_suggest( $m['img']['elementor'] ) );
    et_assert( mb_strlen( $s['alt'] ) <= 150, 'alt not trimmed: ' . mb_strlen( $s['alt'] ) );
    et_ai_reject( $m['img']['elementor'] );
} );

/* --- Bulk candidates --------------------------------------------------- */
et_test( 'bulk candidates: images missing alt, minus decorative and already-suggested ones', function () use ( $m, $answer ) {
    et_ai_stub( $answer );
    et_not_error( et_ai_suggest( $m['img']['css_bg'] ) ); // pending → excluded
    $ids = et_ai_candidates( 'missing' );
    et_assert( in_array( $m['img']['badname'], $ids, true ), 'image without alt missing' );
    et_assert( ! in_array( $m['img']['logo'], $ids, true ), 'image with alt included' );
    et_assert( ! in_array( $m['img']['css_bg'], $ids, true ), 'pending suggestion included' );
    $weak = et_ai_candidates( 'weak' );
    et_assert( in_array( $m['img']['dscalt'], $weak, true ), 'weak alt ("Dsc 0042") not offered in weak scope' );
    et_ai_reject( $m['img']['css_bg'] );
} );

/* --- Permissions (REST, per attachment) ------------------------------ */
et_test( 'REST: a subscriber cannot ask for or accept suggestions', function () use ( $m ) {
    $sub = wp_insert_user( array( 'user_login' => 'et_ai_sub', 'user_pass' => wp_generate_password( 24 ), 'role' => 'subscriber', 'user_email' => 'et_ai_sub@example.test' ) );
    $me  = get_current_user_id();
    wp_set_current_user( $sub );
    foreach ( array( 'suggest', 'accept', 'reject' ) as $action ) {
        $res = rest_do_request( new WP_REST_Request( 'POST', '/erdo/v1/ai/' . $m['img']['badname'] . '/' . $action ) );
        et_assert( in_array( $res->get_status(), array( 401, 403 ), true ), "$action answered " . $res->get_status() );
    }
    wp_set_current_user( $me );
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user( $sub );
} );

et_test( 'REST: an editor of the image gets a suggestion', function () use ( $m ) {
    $res = rest_do_request( new WP_REST_Request( 'POST', '/erdo/v1/ai/' . $m['img']['badname'] . '/suggest' ) );
    et_eq( 200, $res->get_status() );
    et_ai_reject( $m['img']['badname'] );
} );

et_ai_unstub();
