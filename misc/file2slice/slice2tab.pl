#!/usr/bin/perl -w

$usage = "USAGE: slice2tab.pl sliceid > datafile";
$slice_id = shift;
$slice_id || die $usage;

# INDEX
# 1. connects to database 
# 2. Get @field_order and print it on STDOUT
#       select id from field where slice_id = ? order by input_pri
# 3. Get @item_ids
#       select id where slice_id = ? and status_code = '0'
# 4. For each item, get all the content for that item, print in tab format
#       select field_id, text from content where item_id = ?

#$Id$

$host     = 'localhost';
$user     = 'aadbuser';
$password = 'bob';
$database = 'www_apc_org';

$p_slice_id = pack 'H*', $slice_id;

sub print_line ($){
    my $p_array = shift;
    # get rid of tabs or newlinesin the data
    for (@$p_array) { next unless $_; s/\t/ TAB /gm ; s/\n/ NEWLINE /gm; s/\r//gm };
    print join "\t", @$p_array;
    print "\n";
}

# =================================
# 1. connects to database 

use DBI;
$dsn = "DBI:mysql:$database:$host";  
$dbh = DBI->connect($dsn, $user, $password);

# =================================
# 2. Get @field_order and print it on STDOUT
#       select id, name from field where slice_id = ? and input_show = 1 
#       order by input_pri
#    We print both the 'common names' of the fields (on one line) AND
#    the Action Apps names of the field, like headline......1, (on the next line)
#  If editing the data in excel, you can choose which one you want to use.
#  This select statement only selects data where the input_show is set to 1,
#  so it will not get 'hidden' fields -- that may not be what you want.

$sth = $dbh->prepare(
   q{select id, name from field where slice_id = ? and input_show = 1 
       order by input_pri})  or die $dbh->errstr;
$sth->execute($p_slice_id) or die $dbh->errstr;

while ($ptrResult = $sth->fetchrow_hashref) {
    push @field_ids_ordered, $$ptrResult{id};
    push @field_names_ordered, $$ptrResult{name};
}

#push @field_order, 'status_code';
print_line (\@field_names_ordered);
print_line (\@field_ids_ordered);

# =================================
# 3. Get @item_ids
#       select id where slice_id = ? and status_code = 1

$sth = $dbh->prepare(
   q{select id from item where slice_id = ? and status_code = 1})
or die $dbh->errstr;
$sth->execute($p_slice_id) or die $dbh->errstr;

while ($ptrResult = $sth->fetchrow_hashref) {
    push @item_ids, $$ptrResult{id};
};

#debug: print_line (\@item_ids);

# =================================
# 4. For each item, get all the content for that item, print in tab format
#       select field_id, text from content where item_id = ?


$sth = $dbh->prepare(
   q{select field_id, text from content where item_id = ?})
or die $dbh->errstr;

for (@item_ids) {

    # get the data for the item and put it in %item
    %item = ();
    $sth->execute($_) or die $dbh->errstr;
    while ($ptrResult = $sth->fetchrow_hashref) {
	$item{$$ptrResult{field_id}} = $$ptrResult{text};
    };

    # put the data in the order we want it
    @data_row = ();
    for (@field_ids_ordered){
        # CAREFUL, what happens when it is blank?
        # CAREFUL, what about when it is not a text value
	push @data_row, $item{$_};
    }
    
    # print the data
    print_line (\@data_row);
}

# Close up shop
$sth->finish;
$dbh->disconnect;

__END__
