MGETTEXT LANGUAGE FILES README
(c) Jakub Adámek, August 2002

This directory contains language files used by the mini gettext environment.

Each file is connected with one language and a series of PHP scripts. You can only use one file for translations at a time, but you can change freely between files by using the bind_mgettext_domain() function in include/mgettext.php3.

The files are maintained by translators and by the PHP function xmgettext() in misc/mgettext/xmgettext.php3. Translators add new translations and xmgettext() adds new strings to be translated by going through the source files.


Notes for Translators

The language files are regular PHP files, you must not use wrong syntax. You must not change the ID string in _m["..."]. Even if it contains bad English, it may be changed only in the source code, not in the language file. You must follow the PHP syntax for strings, using \" and \$ instead of " and $. You must quote the translation with "". 

(You may quote it with '' as well, but next time xmgettext() will be run, it will be changed to "", I  do not recommend it.)

Each language string is preceded by a list of places in source code where it is used. If you are not sure how to translate it, go to source code and have a look.

Some language strings contain parameters %1,%2 etc. which will be replaced by some variable content at run-time. Place the parameters appropriately in the translation.


Notes for Developers

A string becomes language string by enclosing into _m(). It is better to create long strings than to concatenate short ones, because translators may easier understand the meaning.

You must not use variables in language strings. But you can use parameters %1,%2,... with the syntax of _m("... %1 ... %3 ... %2", array ($param1,$param2,$param3)). Always use the array() even when using only one parameter %1.

Before sending new code to CVS, update language files with xmgettext(). Call it by the mgettext/translate_aa.php3 script. Set appropriate directories there. Groups of files associated with language files are described there. If you created new scripts, add them into groups. 

You need to provide read-write access for PHP to language files in order to update them. Copy them into another directory, set permissions and copy the updated files back.

If you don't like this overhead, just remember which overhead it took to create and copy all the L_ language constants to new_news_lang.php3 ...

One more thing to take care of is the run-time switching between languages. If you fill in some include file a variable with translated strings and than change language, it will remain in the old language. You must create a function returning the variable to avoid this.

Hope you will like my mgettext solution,
    
    Jakub
