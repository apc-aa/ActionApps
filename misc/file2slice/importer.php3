<?php
/**
  * @version $Id$
  * @author Honza Malik
*/

/** APC-AA configuration file */
require_once "../../include/config.php3";
#require_once "../../../include/config.php3";
/** Main include file for using session management function on a page */
require_once AA_INC_PATH."locsess.php3";
/** Set of useful functions used on most pages */
require_once AA_INC_PATH."util.php3";
require_once AA_INC_PATH."formutil.php3";
require_once AA_INC_PATH."grabber.class.php3";

//set_time_limit(600);
//ini_set('memory_limit', '128M');

//only for testing
/*
$grabber = new AA_Grabber_Pohoda_Stocks('/data/www/sasov/pohoda/output/stock-'. $_GET['version'] .'.xml');
$saver   = new AA_Saver($grabber, null, '3c22334440ded336375a6206c85baf1e', 'by_grabber');
$saver->run();

$grabber = new AA_Grabber_Pohoda_Orders_Result('/data/webs/sasov/pohoda/output/order-'. $_GET['version'] .'.xml');
$saver   = new AA_Saver($grabber, null, '2d81635d44bb552a766eb779808f3cfb', 'update');
$saver->run();
*/

if ($_POST['save'] == 'nahraj') {
  #$grabber = new AA_Grabber_CTK('/data/www/htdocs/aa.ecn.cz/CTK_export.xml');
  $grabber = new AA_Grabber_CTK($_FILES['xmlfile']['tmp_name']);
  $saver   = new AA_Saver($grabber, null, '9b2023b8a4e8d6c1ef76f58c81578399', 'by_grabber');
  $saver->run();
  @unlink($_FILES['xmlfile']['tmp_name']);
  echo 'Soubor je naimportovan do zasobniku';
}
?>

<form name="xml_import" method="post" enctype="multipart/form-data">
<input name="xmlfile" type="file" />
<input name="save" type="submit" value="nahraj" />
</form>
