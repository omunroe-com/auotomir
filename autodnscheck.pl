#!/usr/bin/perl

use strict;
use warnings;
use Net::DNS;

my $DEBUG=0;
my $DOMAIN="mirrors.postgresql.org";
my $MASTER="62.65.68.81";

my $errors = '';


# Create two resolvers. One to resolve general names (using the machiens
# default resolver) and one that queries the master.
my $res_generic = Net::DNS::Resolver->new;
my $res_master = Net::DNS::Resolver->new(nameservers => [$MASTER], recurse => 0);


# Load the SOA record with the serial number from the master server
my $qq = $res_master->query($DOMAIN,"SOA");
die "Could not get SOA record from primary!\n" unless ($qq);
my $masterserial = ($qq->answer)[0]->serial;
die "Could not get serial number from primary!\n" unless ($masterserial);

$DEBUG && print "Master serial is: $masterserial\n";


# Load the list of available nameservers from the master server
my $q = $res_master->query($DOMAIN,"NS");
die "No nameservers found!" if (!$q);

my $servercount = $q->answer;
if ($servercount < 4) {
    $errors .= "There are only $servercount DNS servers listed!\n";
}


# Check the serial on each server against the ones on the master
foreach my $rr ($q->answer) {
    my $nsip='';
    $DEBUG && print "Scanning " . $rr->nsdname . "\n";

    my $ns = $res_generic->query($rr->nsdname,'A');
    if (!$ns) {
	$errors .= "Could not find nameserver " . $rr->nsdname . "\n";
	next;
    }

    foreach my $rrr ($ns->answer) {
	$nsip = $rrr->address if ($rrr->type eq "A");
    }
    if ($nsip eq "") {
	$errors .= "Nameserver " . $rr->nsdname . " has no A record!\n";
	next;
    }

    my $res2 = Net::DNS::Resolver->new(nameservers => [$nsip], recurse => 0);
    
    $qq = $res2->query($DOMAIN,"SOA");
    if (!$qq) {
	$errors .= "Failed to query nameserver " . $rr->nsdname . " for SOA record!\n";
	next;
    }

    my $serial = ($qq->answer)[0]->serial;
    
    if (!$serial) {
	$errors .= "Failed to get serial from nameserver " . $rr->nsdname . "\n";
	next;
    }
    $DEBUG && print "Serial for " . $rr->nsdname . " is $serial\n";

    if ($serial != $masterserial) {
	$errors .= "Serial for " . $rr->nsdname . " ($serial) differs from master ($masterserial)\n";
	next;
    }
}

if ($errors ne "") {
    print "** Errors occured **\n";
    print $errors . "\n";
    print "********************\n";
    exit(1);
}
else {
    print "DNS check completed, all $servercount servers in sync.\n";
}
