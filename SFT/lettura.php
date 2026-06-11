<?php
session_name('SFT_SESSION'); session_start();

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Esercizio') {
    header("Location: index.php");
    exit();
}

$id       = $_GET['id'] ?? '';
$file_msg = __DIR__ . '/messaggi_interni.json';

if (file_exists($file_msg)) {
    $messaggi = json_decode(file_get_contents($file_msg), true);
    foreach ($messaggi as &$m) {
        if ($m['id'] === $id) $m['letto'] = true;
    }
    file_put_contents($file_msg, json_encode($messaggi, JSON_PRETTY_PRINT));
}

header("Location: admin_esercizio.php?msg=Comunicazione segnata come letta");
exit();
?>
