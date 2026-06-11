<?php
// Timezone Italia — applicata a PHP
date_default_timezone_set('Europe/Rome');
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('max_execution_time', '5');

 
$host = "INSERIRE_HOST";
$user = "INSERIRE_USER";
$password = "INSERIRE_PASSWORD";
$database = "INSERIRE_NOME_DB";

$con = new mysqli($host, $user, $pass, $dbname);

$offset_roma = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('P'); // es. '+02:00'
$con->query("SET time_zone = '$offset_roma'");
if($con->connect_error){
    die("Connessione fallita: " . $con->connect_error);
}
// codifica corretta per evitare errori con accenti
$con->set_charset("utf8");
?>


<?php

