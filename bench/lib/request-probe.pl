#!/usr/bin/env perl
## Usage: request-probe.pl <url> <seconds> <rps> <timeout_s> <out.csv>
## A timestamped request probe for bench/restart-load.sh. It sends GET <url> with curl at a fixed rate for <seconds>,
## each request in its own process so a slow or hanging one never delays the next, and appends one line per request:
##   start_ms,http_code,curl_exit,time_total_s
## start_ms is host wall-clock ms, the clock the restart windows use. A refused or reset connection has http_code 000 and
## a non-zero curl exit; a timeout has curl exit 28. That is what oha's request database cannot give: it stores only
## the requests that got an answer, so connection errors would be invisible inside the restart windows.
use strict;
use warnings;
use Fcntl qw(:flock);
use POSIX ();
use Time::HiRes qw(time sleep);

my ($url, $seconds, $rps, $timeout, $out) = @ARGV;
die "Usage: $0 <url> <seconds> <rps> <timeout_s> <out.csv>\n" unless defined $out && $rps > 0;

my $start = time;
my $sent = 0;
while (1) {
    my $due = $start + $sent / $rps;
    last if $due >= $start + $seconds;
    my $wait = $due - time;
    sleep $wait if $wait > 0;

    my $started_ms = int(time * 1000);
    my $pid = fork;
    die "fork failed: $!\n" unless defined $pid;
    if ($pid == 0) {
        my $answer = '';
        if (open(my $curl, '-|', 'curl', '-ks', '-o', '/dev/null', '--max-time', $timeout, '-w', '%{http_code} %{time_total}', $url)) {
            local $/;
            $answer = <$curl> // '';
            close $curl;
        }
        my $exit = $? >> 8;
        my ($code, $total) = split ' ', $answer;
        $code = '000' unless defined $code && $code =~ /^\d{3}$/;
        $total = 0 unless defined $total && $total =~ /^[0-9.]+$/;
        if (open(my $fh, '>>', $out)) {
            flock($fh, LOCK_EX);
            print {$fh} "$started_ms,$code,$exit,$total\n";
            close $fh;
        }
        POSIX::_exit(0);
    }
    $sent++;
    1 while waitpid(-1, POSIX::WNOHANG()) > 0;
}
1 while wait() != -1;
