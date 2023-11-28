<!DOCTYPE html>
<html lang="en">
<head>
    <title>ActionApps - database upgrade script</title>
</head>
<body>

<h1>ActionApps - database upgrade script</h1>

<p>
The script for Install / Update / Restore / Fix database is now located in
/service/ directory: <a href="./service/sql_update.php">/service/sql_update.php</a>
</p>
<br>
<p>
The main purpose for moving the script there is, that whole service directory
is completely standalone - you can copy this directory to your old ActionApps and check
the database, if everything is OK. It also allows easier upgrade of multiple ActionApps
databases from the ActionApps "Central".
</p>

<p>Honza Malik 20.5.2009</p>

</body>
</html>
