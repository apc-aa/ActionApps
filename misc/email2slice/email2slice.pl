#!/usr/local/bin/perl

## FROM: Michael deBeer
## DATE: 06/06/2001 20:39:56
## SUBJECT:  [Apc-aa-general] email2slice.pl 

#---------------------------------------------
# 1. Purpose and Index
#
# Parse an email message store it in a slice

# To install and use this script:  
#
# a. install this script somewhere, like /usr/local/bin/email2slice.pl
#    The first line needs to call perl5.x, and on some systems will be 
#       #!/usr/local/bin/perl5
#    This script also requires readmail.pl, part of http://www.mhonarc.org and LWP
# b. set $config_dir in this script
# c. set the slice to take anonymous postings - choose whether to put them 
#    in the holding bin (recommended) or the main section
# d. find out the fieldids for your slice. you can do this by 
#    viewing the source of a 'add item' page for your slice
# e. create an entry in /etc/aliases like:
#       slice1_email: "|/usr/local/bin/email2slice.pl $mapname"
#    to gateway a mailinglist to your slice, subscribe this address to the 
#    mailinglist
# f. create a file $config_dir/$mapname.pl , 
#    see section 5. of this script to setup fieldid mapping

# Index
#   1. Purpose and Index
#   2. Initialize Environment, Read email message on STDIN
#   3. Parse header, and check to see if we should skip message, based on header
#   4. Look at body - remove bad characters, generate summary of fulltext
#   5. Store message in the database

#---------------------------------------------
# 2. Initialize Environment, Read Msg on STDIN

# arrays for header fields and their original labels
# strings for the original text of the email, and a summary we will generate from it
local (%fields, %l2o, $mesg, $summary);

# this program will be run from /etc/aliases or .forward 
# there is a command line argument, which specifies a config file for this slice
local $map4slice = shift or die "missing field map for slice on commandline";
# $map4slice.pl will be included in ( 5. Store message in the database )
# the script will look for $map4slice in $config_dir
# please set this location to be someplace secure, but readable by the mailserver
# NOTE - it needs a trailing '/'
my $config_dir = '/usr/local/apc-aa/email2slice/';

# slurp the message on STDIN to $mesg
eval { local $/ = undef; $mesg = <>; };

# NEEDED SOFTWARE

# MAILread_header is a function from readmail.pl, part of mhonarc.
# It takes a long string in $mesg and fills out %fields and %l2o
# It shrinks *mesg, leaving only the body of the mesg
require "readmail.pl";

# after parsing the email message, we will POST to filler.php3
use LWP::UserAgent;
use HTTP::Request::Common qw(POST);

#---------------------------------------------
# 3. Read header, and check to see if we should skip message, based on header

# only $mesg will have data before this function.
# this function will fill %fields and %l2o
($header) = &readmail::MAILread_header(*mesg, *fields, *l2o);

# check to see if we should skip this

    ##---------------------------##
    ## Check for no archive flag ##
    ##---------------------------##
if ( $CheckNoArchive &&
     ($fields{'restrict'} =~ /no-external-archive/i ||
      $fields{'x-no-archive'} =~ /yes/i) ) {
  return ("", "", "", "", "");
}

#---------------------------------------------
#4. look at body - remove bad strings, generate summary of full text

# be sure to remove <>"\ or it could mess up HTML
$mesg =~ s (\<|\>|'|") ()g;

# autogenerate a 'summary' of the fulltext mesg

for ( split /\n\n/, $mesg) {
  # quit if we have got enough text for a summary
  last if ( length($summary) gt 300 );
  # remove stupid bylines from summary
  s/---+[^-]*---+//g;
  # skip the paragraph unless it looks like a real piece of text, ie, 
  # it ends with sentence punctuation or the previous paragraph did.
  next unless ( $summary or (! /^[^ ]: /  and /(\.|\?)"?\s*$/ ));
  # at this point, we need text, and this paragraph looks good, so append it
  # if we are adding to a previous paragraph, put the paragraph sepearator back in
  $summary .= $summary ? "\n\n$_" : $_;
}
;

# stash the full body and summary in the fields array
$fields{fulltext} = $mesg;
$fields{summary} = length($summary) lt 400 ? $summary : substr ($summary, 0,300);

#---------------------------------------------
# 5. Store message in the database

# print "subject\t $fields{subject}\n";
# print    "from\t $fields{from}\n";
# map { print "$_ : $fields{$_} : \n" } sort keys %fields;

require $config_dir . $map4slice .'.pl'; # load array %config.
# the file $map4slice.pl will look something like:  
# $filler_url = 'http://127.0.0.1/apc-aa/filler.php3';
# %config = (               slice_id => '916926288bd55bb7804a1216fdb0aa14',
#    v686561646c696e652e2e2e2e2e2e2e2e => $fields{subject},
#    v61627374726163742e2e2e2e2e2e2e2e => $fields{summary},
#    v66756c6c5f746578742e2e2e2e2e2e2e => $fields{fulltext},
# );

my $ua = LWP::UserAgent->new;
my $req = POST $filler_url, \%config;
print $ua->request($req)->as_string;

__END__

