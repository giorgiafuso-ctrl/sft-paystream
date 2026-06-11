<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');

if (!isset($_SESSION['sft_id'])) {
    header("Location: login.php");
    exit();
}

// Solo POST: non si elimina via GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    header("Location: biglietti_riepilogo.php");
    exit();
}

$id_biglietto = (int)$_POST['id'];
$id_utente    = (int)$_SESSION['sft_id'];
$oggi         = date('Y-m-d');

if ($id_biglietto <= 0) {
    header("Location: biglietti_riepilogo.php?error=id_non_valido");
    exit();
}

$check_sql = "SELECT B.IDBIGLIETTO
              FROM SFT_BIGLIETTO B
              JOIN SFT_CORSA C ON B.IDCORSA = C.IDCORSA
              WHERE B.IDBIGLIETTO = ?
                AND B.CODUTENTE   = ?
                AND C.DATA       >= ?";

$stmt = $con->prepare($check_sql);
if (!$stmt) {
    header("Location: biglietti_riepilogo.php?error=db_error");
    exit();
}
$stmt->bind_param('iis', $id_biglietto, $id_utente, $oggi);
$stmt->execute();
$stmt->store_result();
$found = $stmt->num_rows;
$stmt->close();

if ($found === 0) {
    // Non esiste, non è dell'utente, oppure corsa già passata
    header("Location: biglietti_riepilogo.php?error=non_autorizzato_o_scaduto");
    exit();
}

$del_sql = "DELETE B FROM SFT_BIGLIETTO B
            JOIN SFT_CORSA C ON B.IDCORSA = C.IDCORSA
            WHERE B.IDBIGLIETTO = ?
              AND B.CODUTENTE   = ?
              AND C.DATA       >= ?";

$stmt = $con->prepare($del_sql);
if (!$stmt) {
    header("Location: biglietti_riepilogo.php?error=db_error");
    exit();
}
$stmt->bind_param('iis', $id_biglietto, $id_utente, $oggi);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$ok) {
    header("Location: biglietti_riepilogo.php?error=db_error");
    exit();
}

if ($affected === 0) {
    header("Location: biglietti_riepilogo.php?error=gia_eliminato");
    exit();
}

$files = glob(__DIR__ . '/cache/*.json');
foreach ($files as $f) { if (is_file($f)) @unlink($f); }

header("Location: biglietti_riepilogo.php?msg=eliminato");
exit();

