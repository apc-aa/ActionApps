#!/usr/bin/perl -w

$debugF = 0;  #do not really do sql statements 
$quiet = 1;   #print out 'status' message to STDOUT

$host = 'localhost';
$user     = 'aadbuser';
$password = '';
$database = 'www_apc_org';

$usage = "USAGE: txt2fields.pl sliceid < datafile";
$slice_id = shift;
$slice_id || die $usage;

# Use a datafile to drive a process
# that modifies the fields of an ActionApps slice

# INDEX:
# STAGE 1: Parse the datafile        (store results in %fields_raw)
# STAGE 2: Apply rules to raw data   (store results in %fields_calculated)
# STAGE 3: Update the slice in mysql (based on %fields_calculated)
#
#$Id$

use vars (%fields_raw, %fields_calculated);
# convert a Hexadecimal input into a packed string
$p_slice_id = pack 'H*', $slice_id;
$p_slice_id = $p_slice_id;

# 1. parse the datafile, storing information in %fields_raw
# 2. create %fields_calculated, based on our logic and %fields_raw
# 3. run SQL statements, based on %fields_calculated

$d1 = 0;
sub d($){
    $d1++;
    $msg = shift;
    print "debug $d1: $msg\n" unless ($quiet > 5);
}

sub insert_record_fast ($$;$$){
  # if $debugF is defined, print out the sql statement and do insert
  # if $debugF defined and eq 1 print out, but don't insert

  my ($table, $ptrData, $dbh, $debugF) = @_;
  my (@fields, @values, $FieldClause, $ValueClause, $value, $sql);

  #
  # Define the SQL Query
  #
  for (keys %$ptrData) {
    push @fields, $_;
    defined $$ptrData{$_} or ( push @values, '' and next );
    push @values,$dbh->quote($$ptrData{$_})
  }

  # create the sql
  $FieldClause = join (", ", @fields);
  $ValueClause = join (", ", @values);
  $sql = "REPLACE INTO $table \n( $FieldClause )\nVALUES\n( $ValueClause )";

  # Do or Debug
  $debugF and ( print $sql , "\n" and return undef);
  my $sth = $dbh->prepare($sql);
  (!$sth) && die "Error:" . $dbh->errstr . "\n";

  # Execute the Query, checking for problems
  (!$sth->execute) && die "Error:" . $sth->errstr . "\n";

  return $sth->{mysql_insertid};
}

# ==============================================================
d 'STAGE 1: Parse the datafile'; # storing results in %fields_raw

while (<>){
    next unless /\S/;
    next if /^\#/;
    /^(\S+)\s+(\S+.*)/ or die "error in input file -- line is : $_";
    $label = $1; $value=$2;
    if ($label =~ /^field$/){
	$field_id = $value;
        push @items_ordered, $field_id;
    } else {
	if (! $field_id) {
          print "line is: $_\n";
          print "label is $label\n";
          print "value is $value\n";
          die "must define 'field' before adding attributes:";
        }
        $fields_raw{$field_id}{$label} = $value;
    }
}

# ==============================================================
d 'STAGE 2: Apply rules to raw data'; #storing results in %fields_calculated

local $field_counter = 0;

# loop for each field like 'headline' or 'full text'
for (@items_ordered){
    $name = $_;
    local $field_raw = $fields_raw{$name};
    $field_counter++;
    local (%f) = (); # array we will store things in

    # set the defaults
    $f{slice_id} = $p_slice_id;
    $f{name} =  $name;
    $f{input_pri} = $field_counter * 50;
    $f{input_show_func} = 'fld'; # txt chb sel:which one
    $f{input_default} = 'qte'; # txt (if select box) boo fil uid ids now
    $f{required} = 0;
    $f{feed} = 0;
    $f{multiple} = 0; # (unless multiple=1)
    $f{search_pri} = 100;
    $f{search_show} = 1; # (except hidden fields)
    $f{search_ft_show} = 1; # (except hidden fields)
    $f{search_ft_default} = 1; # (except hidden fields)
    $f{alias1} = '_#FLD'.'.'x ( 5 - length($field_counter) ).$field_counter;
    $f{id} = 'field'. '.'x ( 11 - length($field_counter) ).$field_counter;
    $f{alias1_func} = 'f_h';
    $f{alias1_help} = "Alias for $name";
    $f{content_edit} = 0;
    $f{html_default} = 0;
    $f{html_show} = 0;
    $f{input_validate} = 'text'; #date email bool url number
    $f{input_insert_func} = 'qte';
    $f{input_show} = 1;
    $f{text_stored} = 1; # set to 0 when boolean

    # do the calculations based on inputs
#    if ((exists $$field_raw{input_show_func}) && $$field_raw{input_show_func} == 'bool') {
#	$f{text_stored} = 0; # set to 0 when boolean
#    };

    if ( (exists $$field_raw{hidden}) and ($$field_raw{hidden} == '1')) {
	$f{search_show} = 0;
	$f{search_ft_show} = 0;
	$f{search_ft_default} = 0;
    };

    if (exists $$field_raw{input_show_func}){
       if ($$field_raw{input_show_func} =~ /^mch/) {
  	 $f{multiple} = 1;
	 $f{text_stored} = 1;
	 $f{input_default} = 'txt:';
       }

       if ($$field_raw{input_show_func} =~ /^rio/) {
	 $f{text_stored} = 1;
	 $f{input_default} = 'txt:';
       }

    };

    #NOTE set in_item_tbl for special fields like author

    # import manual over-rides from fields_raw;
    @fieldnames = qw/
id type slice_id name input_pri input_help input_morehlp input_default
required feed multiple input_show_func content_id search_pri
search_type search_help search_before search_more_help search_show
search_ft_show search_ft_default alias1 alias1_func alias1_help alias2
alias2_func alias2_help alias3 alias3_func alias3_help input_before
aditional content_edit html_default html_show in_item_tbl
input_validate input_insert_func input_show text_stored/;

    for (@fieldnames){
        if (exists ($$field_raw{$_})) {
	    $f{$_} = $$field_raw{$_};
	}
    }

    # store our 'working variable' for the item (%f) in the array of arrays
    $fields_calculated{$name} = \%f;
}

# ==============================================================
d 'STAGE 3: Update the slice in mysql'; # based on %fields_calculated

# create connection to mysql, using perl DBI module
use DBI;
$dsn = "DBI:mysql:$database:$host";  
$dbh = DBI->connect($dsn, $user, $password);

# wipe out old fields
if ($debugF) { 
d "skipping ... until real data";
d "delete from field where slice_id = $slice_id\n";
} else {
d "working on slice $p_slice_id";
$sth_p = $dbh->prepare(
   q{delete from field where slice_id = ?}
		       )  or die $dbh->errstr;
$sth_p->execute($p_slice_id) or die $dbh->errstr;
}

# create new fields
for (@items_ordered){
    d "inserting ... '$_'";
    insert_record_fast ('field', $fields_calculated{$_}, $dbh, $debugF);
}

$dbh->disconnect();
