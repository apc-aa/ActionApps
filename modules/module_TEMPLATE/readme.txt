How to create new module in APC Action Apps?

1) copy the module_TEMPLATE to the new directory and name it by the name of 
   module (just for clarity).
   
2) open /include/constants.php3 and add new module in  the $MODULES array.

Done - module is created. Optional but probale next steps are:

a) insert new language file to /include/ directory (just like 
   en_site_lang.php3), if you want to use module specific texts
   (you probably will)
   (Note: all language files should be in /include/ directory)
   
b) if you need some database tables for the module, add the definition to both -
   /doc/aadb.sql and /sql_update.php3 script !!!
   (Note: the table names should be prefixed by the module name - for clarity - 
    just like site_spot table)
    
General information about modules
- instance of module is identified by 32-digit hexadecimal number module_id, 
  which is exactly the same as slice_id (in fact slice_id should be called 
  module_id, but from historical reasons it is slice_id).
- if you are creating new module instance, the new module_id should be generated
  by new_id() in /include/util.php3 file.
- the permission to the new instance will use the same functions as the slice 
   - it will use AddPermObject($module_id, "slice") 
   - the "slice" is there from historical reasons and in fact much better name 
     would be "module", but we MUST use the "slice"
   - Note: you can (and in fact you should) redefine the meaning of permission
           letters in permission string for the module 
           - see /include/perm_core.php3
- all modules instances (just like all slices, sites, ..) must have its record 
  in module table. There is list of all used modules. 