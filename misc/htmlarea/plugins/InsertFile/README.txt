This is a plug-in for HTMLArea 3.0

The PHP Insert File Dialog + File Manager provides an interface to 
browse for files on your web server and insert links to them. 
The File Manager allows some basic file manipulations.

Instalation
1) Unpack and move InsertFile directory to plugins subdirectory of your htmlarea3 installation.
2) Edit config.php
3) Verify if your _editor_url is set properly. (see htmlarea3 documentation and examples)
	 Insert	following lines to appropriate place in your html/php/whatever source file:

	 ------ snip ------ 
	<script type="text/javascript">
	// load the plugin files
	 	HTMLArea.loadPlugin("InsertFile");
	
	 	function initEditor() {
  		// create an editor for the "ta" textbox
  		editor = new HTMLArea("ta");
			editor.registerPlugin(InsertFile);
  		editor.generate();
  		return false;
		}
	</script>
	------ snip ------

See also:
http://alrashid.klokan.sk/insFile/

Al
alrashid@klokan.sk