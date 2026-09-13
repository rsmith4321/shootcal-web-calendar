<?php
/** Run with wp --skip-plugins --skip-themes eval-file tests/wordpress-regression.php on a disposable local WordPress site. */
require_once dirname( __DIR__ ) . '/shootcal-web-calendar.php';
\ShootCalWebCalendar\bootstrap();
( new \ShootCalWebCalendar\Assets() )->register_frontend();

function scwc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
	echo "PASS: $message\n";
}

$id = 'ReviewFixture123';
$url = 'https://api.shootcal.com/embed/' . $id;
$params = '?months=12&first_day=1&view=calendar&theme=dark';
foreach ( array( $id, $url, 'https://feed.shootcal.com/' . $id . '.ics', '<iframe src="'.$url.$params.'"></iframe>',
	'<script src="https://api.shootcal.com/embed.js" data-src="'.$url.$params.'" async></script>',
	'<script src="https://api.shootcal.com/embed.js" data-shootcal="'.$id.'" async></script>' ) as $input ) {
	$reference = \ShootCalWebCalendar\Embed_Reference::parse( $input );
	scwc_assert( isset( $reference['token'] ) && $id === $reference['token'], 'Accept supported reference: ' . substr( $input, 0, 75 ) );
}
foreach ( array( 'tiny', $url.'/junk', 'https://evil.example/'.$url, 'https://api.shootcal.com.evil.example/embed/'.$id,
	'https://user@api.shootcal.com/embed/'.$id, 'https://api.shootcal.com:444/embed/'.$id,
	'<script src="https://evil.example/embed.js" data-src="'.$url.'"></script>', 'https://api.shootcal.com/book/'.$id ) as $input ) {
	scwc_assert( null === \ShootCalWebCalendar\Embed_Reference::parse( $input ), 'Reject unsupported reference: ' . substr( $input, 0, 75 ) );
}
$script = '<script src="https://api.shootcal.com/embed.js" data-src="'.$url.$params.'" async></script>';
$reference = \ShootCalWebCalendar\Embed_Reference::parse( $script );
scwc_assert( '1' === $reference['query']['first_day'] && 'calendar' === $reference['query']['view'] && 'dark' === $reference['query']['theme'], 'Script parameters survive normalization' );
$shortcode = new \ShootCalWebCalendar\Shortcode();
$hosted = do_shortcode( '[shootcal_web_calendar calendar_id="'.$id.'" view="calendar" theme="dark"]' );
scwc_assert( str_contains( $hosted, 'data-shootcal-embed' ) && str_contains( $hosted, 'view=calendar' ) && str_contains( $hosted, 'theme=dark' ), 'ID shortcode renders chosen hosted view' );
scwc_assert( ! str_contains( $hosted, '<script' ) && ! str_contains( $hosted, ' id=' ), 'Hosted output needs neither pasted scripts nor duplicated DOM IDs' );
scwc_assert( wp_script_is( 'shootcal-web-calendar-embed', 'enqueued' ), 'Hosted resize script is enqueued' );
$imported = $shortcode->render( array( 'url' => $script ) );
scwc_assert( str_contains( $imported, 'first_day=1' ) && str_contains( $imported, 'view=calendar' ), 'Legacy script input preserves presentation in render' );

$options = static fn() => array( 'ajax_render'=>true, 'months_ahead'=>12, 'first_day_of_week'=>1, 'show_credit'=>true );
add_filter( 'pre_option_shootcal_web_calendar_options', $options );
$private_url = 'https://calendar.example.test/private/SCWC_REVIEW_SECRET_' . wp_generate_password( 12, false ) . '/basic.ics';
$switched = ( new \ShootCalWebCalendar\Block() )->render( array('source'=>'ical', 'calendarId'=>$id, 'url'=>$private_url, 'mode'=>'availability', 'embedParams'=>array('mode'=>'full')) );
preg_match('/data-shootcal-payload="([^"]+)"/', $switched, $switched_match);
$switched_attributes = \ShootCalWebCalendar\Feed_Payload::decode( html_entity_decode($switched_match[1] ?? '', ENT_QUOTES) );
scwc_assert( 'availability' === $switched_attributes['mode'], 'Switching from hosted to iCal does not carry over full-event mode' );
$lazy = $shortcode->render( array( 'source'=>'ical', 'url'=>$private_url, 'mode'=>'availability', 'first_day'=>'0' ) );
scwc_assert( ! str_contains( $lazy, $private_url ) && ! str_contains( $lazy, 'SCWC_REVIEW_SECRET' ), 'Private feed URL is absent from public markup' );
preg_match( '/data-shootcal-payload="([^"]+)"/', $lazy, $match );
$payload = html_entity_decode( $match[1] ?? '', ENT_QUOTES );
$decoded = \ShootCalWebCalendar\Feed_Payload::decode( $payload );
scwc_assert( is_array( $decoded ) && $private_url === $decoded['url'], 'Authenticated payload round-trips feed URL' );
scwc_assert( '0' === $decoded['first_day'], 'Explicit Sunday survives Monday site default in AJAX mode' );
$tampered = $payload;
$tampered[20] = 'A' === $tampered[20] ? 'B' : 'A';
scwc_assert( null === \ShootCalWebCalendar\Feed_Payload::decode( $tampered ), 'Tampered payload is rejected' );
$different_site = static fn( $salt ) => $salt . '-different-site';
add_filter( 'salt', $different_site );
scwc_assert( null === \ShootCalWebCalendar\Feed_Payload::decode( $payload ), 'Payload cannot be replayed on another site key' );
remove_filter( 'salt', $different_site );

$fixture_http = static function( $response, $args, $requested_url ) use ( $private_url ) {
	if ( $private_url !== $requested_url ) return $response;
	$today = gmdate( 'Ymd' );
	return array( 'response'=>array('code'=>200), 'headers'=>array(), 'body'=>"BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:review\r\nDTSTART:".$today."T180000Z\r\nDTEND:".$today."T190000Z\r\nSUMMARY:PRIVATE_REVIEW_EVENT\r\nRRULE:FREQ=DAILY;COUNT=2000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n" );
};
add_filter( 'pre_http_request', $fixture_http, 10, 3 );
$die_handler = static fn() => static function( $message, $title = '', $args = array() ) { throw new RuntimeException( 'scwc-stop:' . ( is_array($args) ? ($args['response'] ?? 200) : $args ) ); };
add_filter( 'wp_die_handler', $die_handler );
add_filter( 'wp_die_ajax_handler', $die_handler );
$_POST = array( 'payload'=>$payload );
ob_start();
try { $shortcode->handle_ajax_render(); } catch ( RuntimeException $e ) { scwc_assert( str_starts_with( $e->getMessage(), 'scwc-stop:' ), 'AJAX terminated normally' ); }
$html = ob_get_clean();
scwc_assert( str_contains( $html, 'shootcal-web-calendar__month-panel' ) && ! str_contains( $html, 'PRIVATE_REVIEW_EVENT' ), 'Encrypted AJAX renders availability without event title' );
$final_month = ( new DateTimeImmutable( 'first day of this month', wp_timezone() ) )->setTime(0,0)->modify('+11 months');
$final_leading = (int) $final_month->format('w');
$final_cell = $final_month->modify('-'.$final_leading.' days')->modify('+41 days');
scwc_assert( str_contains( $html, wp_date('l, F j, Y', $final_cell->getTimestamp(), wp_timezone()) . ' - Limited' ), 'Final off-month grid cell retains recurring availability' );
$_POST = array( 'payload'=>$tampered );
ob_start();
$bad_status = '';
try { $shortcode->handle_ajax_render(); } catch ( RuntimeException $e ) { $bad_status = $e->getMessage(); }
ob_end_clean();
scwc_assert( 'scwc-stop:400' === $bad_status, 'AJAX rejects tampered payload before rendering' );
$_POST = array();
remove_filter( 'wp_die_handler', $die_handler );
remove_filter( 'wp_die_ajax_handler', $die_handler );
remove_filter( 'pre_http_request', $fixture_http, 10 );
remove_filter( 'pre_option_shootcal_web_calendar_options', $options );
echo "All WordPress regression checks passed.\n";
