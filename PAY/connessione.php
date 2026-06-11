<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('max_execution_time', '5');


 
$host = "INSERIRE_HOST";
$user = "INSERIRE_USER";
$password = "INSERIRE_PASSWORD";
$database = "INSERIRE_NOME_DB";

$con = new mysqli($host, $user, $pass, $dbname);
if ($con->connect_error) {
    die("Connessione fallita: " . $con->connect_error);
}
$con->set_charset("utf8mb4");
?>

