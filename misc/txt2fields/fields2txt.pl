#!/usr/bin/perl -w

$usage = "USAGE: fields2txt.pl sliceid > datafile";
$slice_id = shift;
$slice_id || die $usage;

# INDEX
# 1. connects to database and does
#    select * from field where slice_id = ? order by input_pri
# 2. prints result in a format for use by fields_txt2slice.pl
#    sub print_field($)
#$Id$

$host     = 'localhost';
$user     = 'aadbuser';
$password = 'bob';
$database = 'www_apc_org';

$p_slice_id = pack 'H*', $slice_id;

# =================================
# 1. connects to database, do query

# create connection to mysql, using perl DBI module
use DBI;
$dsn = "DBI:mysql:$database:$host";  
$dbh = DBI->connect($dsn, $user, $password);

$sth = $dbh->prepare(
   q{select * from field where slice_id = ? order by input_pri }
		       )  or die $dbh->errstr;
$sth->execute($p_slice_id) or die $dbh->errstr;

# =================================
# 2. prints result in a format for use by txt2fields.pl

# input is a pointer to hash
sub print_field($){
    my $p_hash = shift;
    print "field ", $$p_hash{name} , "\n";
    delete $$p_hash{name};
    for (keys %$p_hash){
        next if /slice_id/;
        next unless (defined ($$p_hash{$_}));
        next unless ($$p_hash{$_} =~ /\S/);
	print $_, ' ', $$p_hash{$_}, "\n";
    }
    print "\n";
}

# loop through all results, using the print_field on each one
while ($ptrResult = $sth->fetchrow_hashref) {
   print_field($ptrResult);
}

# Close up shop
$sth->finish;
$dbh->disconnect;

__END__
