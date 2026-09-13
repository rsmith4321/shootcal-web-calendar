<?php
/** Standalone regression coverage: php tests/ical-regression.php */
declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
function wp_timezone(): DateTimeZone {
	return new DateTimeZone( 'America/New_York' );
}
require_once __DIR__ . '/../includes/class-event.php';
require_once __DIR__ . '/../includes/class-ical-parser.php';

use ShootCalWebCalendar\Event;
use ShootCalWebCalendar\ICal_Parser;

$checks = 0;
function check( bool $condition, string $message ): void {
	global $checks;
	++$checks;
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		exit( 1 );
	}
}
function dt( string $date, string $timezone = 'UTC' ): DateTimeImmutable {
	return new DateTimeImmutable( $date, new DateTimeZone( $timezone ) );
}
function calendar( string $properties ): string {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n" . str_replace( "\n", "\r\n", $properties ) . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
}
function parse_window( string $properties, string $from = '2026-09-01', string $until = '2026-10-01', string $timezone = 'UTC' ): array {
	return ( new ICal_Parser() )->parse( calendar( $properties ), dt( $from, $timezone ), dt( $until, $timezone ) );
}
function dates( array $events ): array {
	return array_map( static fn( Event $event ): string => $event->start->format( 'Y-m-d' ), $events );
}

$daily = parse_window( "DTSTART;VALUE=DATE:20230901\nRRULE:FREQ=DAILY;COUNT=2000" );
check( count( $daily ) === 30, 'Historical COUNT=2000 keeps all 30 visible September occurrences.' );
check( dates( $daily )[0] === '2026-09-01' && dates( $daily )[29] === '2026-09-30', 'Daily window has exact inclusive start and exclusive end.' );
check( count( parse_window( "DTSTART;VALUE=DATE:19500101\nRRULE:FREQ=DAILY" ) ) === 30, 'A daily series older than 20000 days fast-forwards to today.' );
check( count( parse_window( "DTSTART;VALUE=DATE:19500101\nRRULE:FREQ=DAILY;COUNT=40000" ) ) === 30, 'Very old counted daily history does not consume the expansion guard.' );
check( parse_window( "DTSTART;VALUE=DATE:20200101\nRRULE:FREQ=DAILY;COUNT=750" ) === array(), 'Expired counted series remains expired.' );
check( parse_window( "DTSTART;VALUE=DATE:20260901\nRRULE:FREQ=DAILY;COUNT=0" ) === array(), 'COUNT=0 produces no occurrence.' );
check( count( parse_window( "DTSTART;VALUE=DATE:20200101\nRRULE:FREQ=DAILY", '2026-01-01', '2029-01-01' ) ) === 1096, 'A complete 36-month daily window includes its leap day and exceeds the old 750 cap.' );
check( count( ( new ICal_Parser() )->parse( calendar( "DTSTART;VALUE=DATE:19500101\nRRULE:FREQ=DAILY" ) ) ) > 1100, 'Existing parser callers retain a complete default horizon.' );

$excluded = parse_window( "DTSTART;VALUE=DATE:20260901\nRRULE:FREQ=DAILY;COUNT=4\nEXDATE;VALUE=DATE:20260902" );
check( dates( $excluded ) === array( '2026-09-01', '2026-09-03', '2026-09-04' ), 'EXDATE consumes COUNT rather than extending the series.' );
$exact_exdate = parse_window( "DTSTART;TZID=America/New_York:20260901T090000\nDTEND;TZID=America/New_York:20260901T100000\nRRULE:FREQ=DAILY;COUNT=4\nEXDATE:20260902T130000Z\nEXDATE;TZID=America/New_York:20260903T090000,20260904T100000" );
check( dates( $exact_exdate ) === array( '2026-09-01', '2026-09-04' ), 'Repeated, comma-separated, and UTC/TZID EXDATEs match exact instants, not an unrelated time on that date.' );
check( dates( parse_window( "DTSTART;VALUE=DATE:20260901\nRRULE:FREQ=DAILY;UNTIL=20260903" ) ) === array( '2026-09-01', '2026-09-02', '2026-09-03' ), 'UNTIL date is inclusive.' );
check( count( parse_window( "DTSTART:20260901T090000Z\nRRULE:FREQ=DAILY;UNTIL=20260903T090000Z" ) ) === 3, 'UNTIL datetime includes an occurrence at the exact boundary.' );

$weekly = parse_window( "DTSTART;VALUE=DATE:20230906\nRRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR;COUNT=300" );
check( dates( $weekly ) === array( '2026-09-02', '2026-09-04', '2026-09-14', '2026-09-16', '2026-09-18', '2026-09-28', '2026-09-30' ), 'Weekly INTERVAL/BYDAY remains anchored to the original partial week.' );
check( dates( parse_window( "DTSTART;VALUE=DATE:20230906\nRRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR;COUNT=236\nEXDATE;VALUE=DATE:20260902" ) ) === array( '2026-09-04' ), 'Weekly COUNT includes the partial first week and excluded visible instances.' );
check( parse_window( "DTSTART;VALUE=DATE:19900103\nRRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE,FR;COUNT=2000" ) === array(), 'Expired weekly BYDAY count remains expired after fast-forward.' );
check( dates( parse_window( "DTSTART;VALUE=DATE:19500101\nRRULE:FREQ=WEEKLY;COUNT=6000" ) ) === array( '2026-09-06', '2026-09-13', '2026-09-20', '2026-09-27' ), 'Old weekly rules without BYDAY keep their weekday.' );
check( dates( parse_window( "DTSTART;VALUE=DATE:20260907\nRRULE:FREQ=WEEKLY;BYDAY=MO,MO,WE;COUNT=3" ) ) === array( '2026-09-07', '2026-09-09', '2026-09-14' ), 'Duplicate BYDAY tokens cannot consume COUNT twice.' );
check( count( parse_window( "DTSTART;VALUE=DATE:20260901\nRRULE:FREQ=DAILY;INTERVAL=999999999999999999" ) ) === 1, 'Huge INTERVAL does not cause overflow or runaway date modification.' );

$monthly = parse_window( "DTSTART;VALUE=DATE:20250131\nRRULE:FREQ=MONTHLY;COUNT=10", '2026-01-01', '2027-01-01' );
check( dates( $monthly ) === array( '2026-01-31', '2026-03-31', '2026-05-31' ), 'Invalid short-month dates do not consume monthly COUNT.' );
check( count( parse_window( "DTSTART;VALUE=DATE:00010131\nRRULE:FREQ=MONTHLY;COUNT=15000", '2026-01-01', '2027-01-01' ) ) === 7, 'Ancient monthly history uses Gregorian-cycle counting instead of the iteration cap.' );
check( dates( parse_window( "DTSTART;VALUE=DATE:20000229\nRRULE:FREQ=YEARLY;COUNT=10", '2025-01-01', '2030-01-01' ) ) === array( '2028-02-29' ), 'Leap-day yearly rule preserves skipped-year COUNT semantics.' );
check( parse_window( "DTSTART;VALUE=DATE:20000229\nRRULE:FREQ=YEARLY;COUNT=7", '2025-01-01', '2030-01-01' ) === array(), 'Expired leap-day series is not revived by fast-forward.' );

$ny = new DateTimeZone( 'America/New_York' );
$from = dt( '2026-01-01', 'America/New_York' );
$end = dt( '2029-01-01', 'America/New_York' );
$long = new Event( dt( '1970-01-01T00:00:00Z' ), dt( '2099-01-01T00:00:00Z' ), false );
$long_days = $long->days_covered( $ny, $from, $end );
check( count( $long_days ) === 1096 && $long_days[0] === '2026-01-01' && $long_days[1095] === '2028-12-31', 'Century-long timed event buckets the requested 36 months, not its first 1000 historical days.' );
$all_day = new Event( dt( '1970-01-01' ), dt( '2099-01-01' ), true );
check( $all_day->days_covered( $ny, $from, $end ) === $long_days, 'Century-long DATE event clips to display dates without shifting into the previous day.' );
check( count( $long->days_covered( $ny ) ) === 1000, 'Unbounded legacy event callers retain their safety cap.' );
check( $long->days_covered( $ny, $end, $from ) === array(), 'Reversed display window is empty.' );
$ended = new Event( dt( '2020-01-01' ), dt( '2021-01-01' ), true );
check( $ended->days_covered( $ny, $from, $end ) === array(), 'Expired one-off never leaks day keys into the requested window.' );
$midnight = new Event( dt( '2026-09-01', 'America/New_York' ), dt( '2026-09-01', 'America/New_York' ), false );
check( $midnight->days_covered( $ny, dt( '2026-09-01', 'America/New_York' ), dt( '2026-09-02', 'America/New_York' ) ) === array( '2026-09-01' ), 'Zero-duration midnight event still renders within the requested window.' );
check( $midnight->days_covered( $ny, dt( '2026-08-01', 'America/New_York' ), dt( '2026-09-01', 'America/New_York' ) ) === array(), 'Zero-duration event at exclusive window end stays outside.' );

$spring = parse_window( "DTSTART;TZID=America/New_York:20260307T090000\nDTEND;TZID=America/New_York:20260307T100000\nRRULE:FREQ=DAILY;COUNT=3", '2026-03-07', '2026-03-10', 'America/New_York' );
check( array_map( static fn( Event $event ): string => $event->start->format( 'H:i P' ), $spring ) === array( '09:00 -05:00', '09:00 -04:00', '09:00 -04:00' ), 'Daily recurrence preserves local time through spring DST.' );
$span = new Event( dt( '2026-03-07 23:00', 'America/New_York' ), dt( '2026-03-09 00:00', 'America/New_York' ), false );
check( $span->days_covered( $ny, dt( '2026-03-01', 'America/New_York' ), dt( '2026-04-01', 'America/New_York' ) ) === array( '2026-03-07', '2026-03-08' ), 'Spring-DST multi-day bucketing respects exclusive midnight end.' );
$span = new Event( dt( '2026-10-31 23:00', 'America/New_York' ), dt( '2026-11-02 00:00', 'America/New_York' ), false );
check( $span->days_covered( $ny, dt( '2026-10-01', 'America/New_York' ), dt( '2026-12-01', 'America/New_York' ) ) === array( '2026-10-31', '2026-11-01' ), 'Fall-DST multi-day bucketing respects exclusive midnight end.' );
$floating = parse_window( "DTSTART;VALUE=DATE:20260901\nRRULE:FREQ=DAILY;COUNT=2", '2026-09-01', '2026-09-03', 'Pacific/Auckland' );
check( dates( $floating ) === array( '2026-09-01', '2026-09-02' ), 'All-day recurrence window uses floating display dates in positive-offset zones.' );
$overlap = parse_window( "DTSTART;VALUE=DATE:20200101\nDTEND;VALUE=DATE:20220101\nRRULE:FREQ=YEARLY;COUNT=7" );
check( dates( $overlap ) === array( '2025-01-01', '2026-01-01' ), 'Recurring events beginning before the window but still spanning today are included.' );

check( count( parse_window( "DTSTART;VALUE=DATE:20260901\nRRULE:FREQ=MONTHLY;BYSETPOS=1" ) ) === 1, 'Unsupported recurrence retains the single base instance.' );
check( parse_window( "DTSTART;VALUE=DATE:20260901\nSTATUS:CANCELLED\nRRULE:FREQ=DAILY" ) === array(), 'Cancelled recurrence remains absent.' );
check( parse_window( "DTSTART;VALUE=DATE:20260901\nTRANSP:TRANSPARENT\nRRULE:FREQ=DAILY" ) === array(), 'Free/transparent recurrence remains absent.' );

echo 'PASS: ' . $checks . " iCalendar regression checks\n";
