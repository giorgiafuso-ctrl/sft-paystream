<?php
session_name('SFT_SESSION'); session_start();

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Amministrativo') {
    header("Location: index.php");
    exit();
}

$azione   = $_GET['azione'] ?? '';
$id_corsa = intval($_GET['id'] ?? 0);
$file_msg = __DIR__ . '/messaggi_interni.json';

// Carica messaggi esistenti
$messaggi = file_exists($file_msg) ? json_decode(file_get_contents($file_msg), true) : [];

switch ($azione) {
    case 'richiesta_straordinario':
        $testo = "Richiesta Treno Straordinario per Corsa #$id_corsa — superamento soglia occupazione 85%.";
        $tipo  = "Treno Straordinario";
        break;
    case 'cessazione':
        $testo = "Richiesta Cessazione Corsa #$id_corsa — assenza prenotazioni.";
        $tipo  = "Cessazione Corsa";
        break;
    default:
        header("Location: admin_amministrativo.php?msg=Azione non riconosciuta");
        exit();
}

$messaggi[] = [
    'id'      => uniqid(),
    'tipo'    => $tipo,
    'testo'   => $testo,
    'data'    => date('d/m/Y H:i'),
    'letto'   => false
];

file_put_contents($file_msg, json_encode($messaggi, JSON_PRETTY_PRINT));

header("Location: conferma_invio.php?tipo=" . urlencode($tipo) . "&msg=" . urlencode($testo));
exit();
?>
