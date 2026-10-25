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

// Match the hosted embed's bounded local-UNTIL and WKST behavior through the
// WordPress parser's own explicit display-window API and larger retained limit.
$localUntil="DTSTART;TZID=America/New_York:20261031T100000\nDURATION:PT30M\nRRULE:FREQ=DAILY;UNTIL=20261102T100000";
check(dates(parse_window($localUntil,'2026-10-31','2026-11-04'))===['2026-10-31','2026-11-01','2026-11-02'], 'Floating local UNTIL includes its last New York occurrence across fallback.');
check(dates(parse_window(str_replace('UNTIL=20261102T100000','UNTIL=20261102T095959',$localUntil),'2026-10-31','2026-11-04'))===['2026-10-31','2026-11-01'], 'Local UNTIL before the final clock excludes the last occurrence.');
$weeklyBody="DTSTART;TZID=America/New_York:20261025T100000\nDURATION:PT30M\nRRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=SU,TU";
$sundayDates=['2026-10-25','2026-10-27','2026-11-08','2026-11-10'];
$mondayDates=['2026-10-25','2026-11-03','2026-11-08','2026-11-17'];
foreach(['SU'=>$sundayDates,'MO'=>$mondayDates]as$wkst=>$expected){
    check(dates(parse_window($weeklyBody.';WKST='.$wkst,'2026-10-25','2026-11-18'))===$expected, "$wkst WKST preserves the correct alternating weeks.");
    check(dates(parse_window($weeklyBody.';WKST='.$wkst.';COUNT=3','2026-10-25','2026-11-18'))===array_slice($expected,0,3), "$wkst WKST counts the partial first week.");
}
check(dates(parse_window($weeklyBody,'2026-10-25','2026-11-18'))===$mondayDates, 'Omitted WKST keeps Monday as its default.');
foreach([1=>['2026-10-03','2026-10-10','2026-10-17','2026-10-24','2026-10-31'],2=>['2026-10-03','2026-10-17','2026-10-31']]as$interval=>$expected){
    $body="DTSTART;TZID=Pacific/Apia:20111231T100000\nDURATION:PT30M\nRRULE:FREQ=WEEKLY;BYDAY=SA;WKST=FR;COUNT=1000;INTERVAL=$interval";
    check(dates(parse_window($body,'2026-10-01','2026-11-01'))===$expected, "Skipped Apia Friday WKST preserves Saturdays with INTERVAL=$interval.");
    $early=$interval===1?['2011-12-31','2012-01-07','2012-01-14']:['2011-12-31','2012-01-14','2012-01-28'];
    check(dates(parse_window(str_replace('COUNT=1000','COUNT=3',$body),'2011-12-30','2012-02-01'))===$early, "Skipped Apia week boundary preserves COUNT with INTERVAL=$interval.");
    $excluded=$body."\nEXDATE;TZID=Pacific/Apia:".str_replace('-','',$expected[1]).'T100000';
    check(dates(parse_window($excluded,'2026-10-01','2026-11-01'))===array_values(array_diff($expected,[$expected[1]])), "Apia exact EXDATE excludes the correct Saturday with INTERVAL=$interval.");
}
$wallTimes=static fn(array $events):array=>array_map(static fn(Event $e):string=>$e->start->format('Y-m-d H:i P'),$events);
$gap="DTSTART;TZID=America/New_York:20270309T023000\nDURATION:PT15M\nRRULE:FREQ=WEEKLY;BYDAY=TU;WKST=SU;COUNT=3";
check($wallTimes(parse_window($gap,'2027-03-01','2027-04-01'))===['2027-03-09 02:30 -05:00','2027-03-16 02:30 -04:00','2027-03-23 02:30 -04:00'], 'A nonexistent Sunday anchor clock does not shift later Tuesday clocks.');
$fold="DTSTART;TZID=America/New_York:20261027T013000\nDURATION:PT15M\nRRULE:FREQ=WEEKLY;BYDAY=TU,SU;WKST=SU;COUNT=3";
check($wallTimes(parse_window($fold,'2026-10-20','2026-11-15'))===['2026-10-27 01:30 -04:00','2026-11-01 01:30 -04:00','2026-11-03 01:30 -05:00'], 'Repeated-hour target uses the first fold and preserves the following local clock.');

$collisionGenerate=static function(string $start,string $rule,string $floor,string $end):array {
    $parser=new ICal_Parser();$dt=new DateTimeImmutable($start);
    $parsed=(new ReflectionMethod($parser,'parse_rrule'))->invoke($parser,$rule,$dt->getTimezone());
    return (new ReflectionMethod($parser,'generate_starts'))->invoke($parser,$dt,$parsed,new DateTimeImmutable($floor),new DateTimeImmutable($end));
};
$collisionPublic=static fn(string $body,DateTimeImmutable $floor,DateTimeImmutable $end):array=>(new ICal_Parser())->parse(calendar($body),$floor,$end);
// Independent reference: enumerate civil days without production fast-forward,
// resolve exact zone offsets (first fold / pre-gap offset), then form a set.
$collisionOracle=static function(string $start,string $byday,string $wkst,int $interval,?int $count,string $floor,string $end):array {
    if($count===0)return [];
    $dt=new DateTimeImmutable($start);$zone=$dt->getTimezone();$utc=new DateTimeZone('UTC');
    $from=new DateTimeImmutable($floor);$until=new DateTimeImmutable($end);
    $names=['MO'=>1,'TU'=>2,'WE'=>3,'TH'=>4,'FR'=>5,'SA'=>6,'SU'=>7];$selected=array_map(static fn(string $d):int=>$names[$d],explode(',',$byday));
    $first=new DateTimeImmutable($dt->format('Y-m-d').' 00:00:00',$utc);
    $anchorDay=(int)($first->getTimestamp()/86400)-(((int)$first->format('N')-$names[$wkst]+7)%7);
    $seen=[];$result=[];$emitted=0;$limit=$until->setTimezone($zone)->format('Y-m-d');
    for($date=$first;$date->format('Y-m-d')<=$limit;$date=$date->modify('+1 day')){
        $day=(int)($date->getTimestamp()/86400);$week=intdiv($day-$anchorDay,7);
        if($week%$interval!==0||!in_array((int)$date->format('N'),$selected,true))continue;
        $target=$date->format('Y-m-d').' '.$dt->format('H:i:s');$wall=new DateTimeImmutable($target,$utc);$wallEpoch=$wall->getTimestamp();
        $transitions=$zone->getTransitions($wallEpoch-172800,$wallEpoch+172800)?:[];$instants=[];
        foreach(array_unique(array_column($transitions,'offset'))as$offset){
            $candidate=(new DateTimeImmutable('@'.($wallEpoch-$offset)))->setTimezone($zone);
            if($candidate->format('Y-m-d H:i:s')===$target)$instants[]=$candidate->getTimestamp();
        }
        if($instants)$epoch=min($instants);
        else {
            $epoch=null;
            for($i=1;$i<count($transitions);$i++){
                $old=$transitions[$i-1]['offset'];$new=$transitions[$i]['offset'];$t=$transitions[$i]['ts'];
                if($new>$old&&$wallEpoch>=$t+$old&&$wallEpoch<$t+$new){$epoch=$wallEpoch-$old;break;}
            }
            if($epoch===null)$epoch=(new DateTimeImmutable($target,$zone))->getTimestamp();
        }
        $value=(new DateTimeImmutable('@'.$epoch))->setTimezone($zone);
        if($value<$dt||isset($seen[$epoch]))continue;
        $seen[$epoch]=true;$emitted++;
        if($value>=$from&&$value<=$until)$result[]=$value->format('Y-m-d H:i:s P');
        if($count!==null&&$emitted>=$count)break;
    }
    return $result;
};
$collisionFormat=static fn(array $values):array=>array_map(static fn(DateTimeImmutable $d):string=>$d->format('Y-m-d H:i:s P'),$values);
$collisionCases=[['2011-12-24 10:00 Pacific/Apia','FR,SA'],['1993-08-14 10:00 Pacific/Kwajalein','SA,SU'],['1994-12-24 10:00 Pacific/Kiritimati','SA,SU']];
foreach($collisionCases as[$start,$days])foreach(['MO','FR','SA','SU','']as$wkst)foreach([1,2]as$interval)foreach([0,1,2,3,8,50,null]as$count)foreach([0,10,21,55]as$offset){
    $dt=new DateTimeImmutable($start);$floor=$dt->modify('+'.$offset.' days')->setTime(0,0);$end=$floor->modify('+70 days')->setTime(23,59,59);
    $rule="FREQ=WEEKLY;BYDAY=$days;INTERVAL=$interval".($wkst===''?'':';WKST='.$wkst).($count===null?'':';COUNT='.$count);
    $expected=$collisionOracle($start,$days,$wkst===''?'MO':$wkst,$interval,$count,$floor->format('c'),$end->format('c'));
    $actual=$collisionFormat($collisionGenerate($start,$rule,$floor->format('c'),$end->format('c')));
    check($actual===$expected,"Unique weekly collision oracle: $start / $wkst / $interval / ".($count??'open')." / floor+$offset");
}
$collisionRule='FREQ=WEEKLY;BYDAY=FR,SA;WKST=MO';
$collisionExact=$collisionFormat($collisionGenerate('2011-12-24 10:00 Pacific/Apia',$collisionRule.';COUNT=3','2011-12-24 00:00 Pacific/Apia','2012-01-30 23:59 Pacific/Apia'));
check($collisionExact===['2011-12-24 10:00:00 -10:00','2011-12-31 10:00:00 +14:00','2012-01-06 10:00:00 +14:00'], 'Normalized duplicate is omitted before consuming COUNT=3; January 6 remains present.');
$collisionHistorical=$collisionFormat($collisionGenerate('2011-12-24 10:00 Pacific/Apia',$collisionRule.';COUNT=1552','2026-11-01 00:00 Pacific/Apia','2026-11-30 23:59 Pacific/Apia'));
check($collisionHistorical===['2026-11-06 10:00:00 +13:00','2026-11-07 10:00:00 +13:00'], 'Historical unique COUNT=1552 retains both November 6 and 7, 2026.');
$gapActual=$collisionFormat($collisionGenerate('2027-03-07 02:30 America/New_York','FREQ=WEEKLY;BYDAY=SU;COUNT=3','2027-03-01','2027-04-01'));
check($gapActual===['2027-03-07 02:30:00 -05:00','2027-03-14 03:30:00 -04:00','2027-03-21 02:30:00 -04:00'], 'RFC erratum gap normalization remains 02:30 to 03:30 without dropping a unique occurrence.');
// Public parse uses next month to keep its moving horizon regression durable.
$collisionZone=new DateTimeZone('Pacific/Apia');$collisionMonth=(new DateTimeImmutable('first day of next month',$collisionZone))->setTime(0,0);
$collisionLast=$collisionMonth;while($collisionLast->format('N')!=='6')$collisionLast=$collisionLast->modify('+1 day');
$collisionPrefix=$collisionOracle('2011-12-24 10:00 Pacific/Apia','FR,SA','MO',1,null,'2011-12-24 00:00 Pacific/Apia',$collisionLast->setTime(23,59,59)->format('c'));
$collisionPublicCount=count($collisionPrefix);$collisionEnd=$collisionMonth->modify('+1 month');
$collisionExpected=array_values(array_filter($collisionPrefix,static fn(string $d):bool=>str_starts_with($d,$collisionMonth->format('Y-m'))));
$collisionBody="UID:unique-normalized-history\nDTSTART;TZID=Pacific/Apia:20111224T100000\nDURATION:PT30M\nRRULE:$collisionRule;COUNT=$collisionPublicCount";
$collisionEvents=$collisionPublic($collisionBody,$collisionMonth,$collisionEnd);$collisionPublicActual=[];
foreach($collisionEvents as$event)if($event->start>=$collisionMonth&&$event->start<$collisionEnd)$collisionPublicActual[]=$event->start->format('Y-m-d H:i:s P');
check($collisionPublicActual===$collisionExpected, 'Actual public parser retains every final unique busy occurrence after historical fast-forward.');
$collisionExclude=new DateTimeImmutable($collisionExpected[0]);$collisionBody.="\nEXDATE:".$collisionExclude->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
$collisionEvents=$collisionPublic($collisionBody,$collisionMonth,$collisionEnd);$collisionExcludedActual=[];
foreach($collisionEvents as$event)if($event->start>=$collisionMonth&&$event->start<$collisionEnd)$collisionExcludedActual[]=$event->start->format('Y-m-d H:i:s P');
check($collisionExcludedActual===array_slice($collisionExpected,1), 'EXDATE removes a unique occurrence without extending historical COUNT or reviving a duplicate.');

$collisionLarge=$collisionGenerate('2011-12-24 10:00 Pacific/Apia',$collisionRule.';COUNT=800','2011-12-24 00:00 Pacific/Apia','2040-01-01');
check(count($collisionLarge)===800&&$collisionFormat($collisionLarge)===$collisionOracle('2011-12-24 10:00 Pacific/Apia','FR,SA','MO',1,800,'2011-12-24 00:00 Pacific/Apia','2040-01-01'), 'WordPress retains 800 unique occurrences instead of inheriting the hosted cap.');
$ancientRule='FREQ=WEEKLY;BYDAY=FR,SA;WKST=MO';
check($collisionFormat($collisionGenerate('0001-01-06 10:00 Pacific/Apia',$ancientRule.';COUNT=9999999','2026-11-01','2026-12-01'))===$collisionFormat($collisionGenerate('0001-01-06 10:00 Pacific/Apia',$ancientRule,'2026-11-01','2026-12-01')), 'Ancient active weekly COUNT fast-forwards through bounded transition correction without walking history.');

echo 'PASS: ' . $checks . " iCalendar regression checks\n";
