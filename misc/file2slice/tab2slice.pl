#!/usr/local/bin/perl

#---------------------------------------------
# 1. Purpose and Index
#
# Parse an tab-delimited file and store it in a slice
#
# usage: tab2slice.pl $filler_URL $sliceid < tab-delimited-data-file
# 
# cat slices.tab | tab2slice.pl \
#  http://127.0.0.1/apc-aa/filler.php3 8212e3cc4b48b078ff223a7e8105f856
# To install and use this script:  
#
# a. install this script somewhere, like /usr/local/bin/tab2slice.pl
#    The first line needs to call perl5.x, and on some systems will be 
#       #!/usr/local/bin/perl5
# c. set the slice to take anonymous postings - choose whether to put them 
#    in the holding bin (recommended) or the main section
# d. find out the fieldids for your slice. you can do this by 
#    viewing the source of a 'add item' page for your slice
#    I use an emacs macro to scan for <b>$match<b>
# e. create a datafile called like 'slices.tab'
#    put the fieldids as the first line.  You can use a # to add comments, 
#    but not on the first line

# Index
#   1. Purpose and Index
#   2. Initialize Environment, get fieldids
#   3. Read the rest of file, sending a POST request for each one.

#---------------------------------------------
# 2. Initialize Environment, Read Msg on STDIN

# we will POST to filler.php3
use LWP::UserAgent;
use HTTP::Request::Common qw(POST);
my $ua = LWP::UserAgent->new;

local $filler_url = shift or die "missing filler_url on commandline";
local $slice_id    = shift or die "missing slice_id on commandline";

# read the field ids
my @fields = split /\t/, <>;

#print @fields; exit;

#---------------------------------------------
# 3. Read the rest of file, sending a POST request for each one.

while (<>) {
    local %config = (); 
    next if /\#/;     # skip comments
    next if /^\s*$/;  # skip blank lines
    my @data = (split /\t/);

    foreach $_ (@fields) {
        $config{$_} = shift (@data);
    }

    $config{ slice_id } = $slice_id;
    
#    for (keys %config) { print $_, $config{$_}, "\n" }; 

    my $req = POST $filler_url, \%config;
    print $ua->request($req)->as_string;
}