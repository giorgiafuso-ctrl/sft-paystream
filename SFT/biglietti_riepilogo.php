<?php
session_name('SFT_SESSION'); session_start();
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

include('connessione.php');
include('controllo_orario.php'); 

if (!isset($_SESSION['sft_id'])) {
    header("Location: login.php");
    exit();
}

$id_utente = (int)$_SESSION['sft_id'];

// RIMBORSI DELL'UTENTE
$rimborsi_da_mostrare = [];
$sql_rimb ="SELECT IDTRANSAZIONE, IMPORTO, DATAORA, DESCRIZIONE
            FROM PAY_TRANSAZIONE
            WHERE DESCRIZIONE LIKE '%[SFT_UTENTE:$id_utente]%'
            AND ( STATO = 'REFUNDED'
                    OR IDTRANSESTERNA LIKE 'NOTIFY-%' )
            AND DATAORA >= DATE_SUB(NOW(), INTERVAL 10 MINUTE )
            ORDER BY DATAORA DESC";
$r_rimb = $con->query($sql_rimb);
if ($r_rimb) {
    $rimborsi_da_mostrare = $r_rimb->fetch_all(MYSQLI_ASSOC);
}

$num_rimborsi      = count($rimborsi_da_mostrare);
$totale_rimborsato = 0;
foreach ($rimborsi_da_mostrare as $rb) {
    $totale_rimborsato += (float)$rb['IMPORTO'];
}

$firma_rimborsi = $num_rimborsi > 0
    ? md5(implode('|', array_column($rimborsi_da_mostrare, 'IDTRANSAZIONE')))
    : '';

$sql = "SELECT
            B.IDBIGLIETTO,
            B.IDCORSA,
            B.IDPOSTO AS POSTO,
            B.COSTO AS PREZZO,
            C.DATA AS DATABIGLIETTO,
            COALESCE(FP.ORAP, C.ORA) AS ORA_PARTENZA,
            COALESCE(FA.ORAA, C.ORA) AS ORA_ARRIVO,
            CO.NOME AS NOME_TRENO,
            S1.NOME AS PARTENZA,
            S2.NOME AS ARRIVO
        FROM SFT_BIGLIETTO B
        JOIN SFT_CORSA      C  ON B.IDCORSA     = C.IDCORSA
        JOIN SFT_CONVOGLIO  CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
        JOIN SFT_STAZIONE   S1 ON S1.IDSTAZIONE = B.IDSTAZIONEP
        JOIN SFT_STAZIONE   S2 ON S2.IDSTAZIONE = B.IDSTAZIONEA
        LEFT JOIN SFT_FERMATA FP ON FP.IDCORSA  = B.IDCORSA AND FP.IDSTAZIONE = B.IDSTAZIONEP
        LEFT JOIN SFT_FERMATA FA ON FA.IDCORSA  = B.IDCORSA AND FA.IDSTAZIONE = B.IDSTAZIONEA
        WHERE B.CODUTENTE = $id_utente
        ORDER BY C.DATA DESC, ORA_PARTENZA DESC";

$res = $con->query($sql);

$biglietti_attivi  = [];
$biglietti_passati = [];
$oggi = new DateTime();

if ($res && $res->num_rows > 0) {
    while ($b = $res->fetch_assoc()) {
        $data_viaggio = new DateTime($b['DATABIGLIETTO'] . ' ' . $b['ORA_PARTENZA']);
        if ($data_viaggio >= $oggi) {
            $biglietti_attivi[] = $b;
        } else {
            $biglietti_passati[] = $b;
        }
    }
}

usort($biglietti_attivi, function($a, $b) {
    return strtotime($a['DATABIGLIETTO'] . ' ' . $a['ORA_PARTENZA']) - strtotime($b['DATABIGLIETTO'] . ' ' . $b['ORA_PARTENZA']);
});
usort($biglietti_passati, function($a, $b) {
    return strtotime($b['DATABIGLIETTO'] . ' ' . $b['ORA_PARTENZA']) - strtotime($a['DATABIGLIETTO'] . ' ' . $a['ORA_PARTENZA']);
});

$allowed_msg = [
    'cambio_ok'       => '<i class="bi bi-check-circle-fill me-2"></i><strong>Posto aggiornato con successo!</strong> Il tuo biglietto è stato modificato.',
    'cambio_data_ok'  => '<i class="bi bi-check-circle-fill me-2"></i><strong>Data aggiornata!</strong> Il tuo viaggio è stato spostato e una notifica è stata aggiunta al riepilogo.',
    'eliminato'       => 'Viaggio annullato correttamente. Il posto è stato liberato.',
    'rimborsato'      => '<i class="bi bi-check-circle-fill me-2"></i><strong>Rimborso effettuato!</strong> Il biglietto è stato annullato e l\'importo è stato rimborsato sul tuo conto PaySteam.',
    'pagamento_ok'    => '<i class="bi bi-check-circle-fill me-2"></i><strong>Pagamento completato!</strong> Il tuo biglietto è stato acquistato con successo.',
];
$msg_success = $allowed_msg[$_GET['msg'] ?? ''] ?? '';

$msg_warning = '';
if (($_GET['msg'] ?? '') === 'cambio_data_non_consentito') {
    $reason = $_GET['reason'] ?? '';
    switch ($reason) {
        case 'chiusa_15min':
            $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Modifica non consentita.</strong> La corsa parte tra meno di 15 minuti.';
            break;
        case 'corsa_passata':
            $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Modifica non consentita.</strong> La corsa è già partita.';
            break;
        case 'corsa_non_attiva':
            $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Modifica non consentita.</strong> La corsa non è più attiva.';
            break;
        default:
            $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Modifica non consentita</strong> su questo biglietto.';
    }
}
if (($_GET['msg'] ?? '') === 'errore_posto') {
    $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Posto non disponibile.</strong> Il posto scelto è stato prenotato da un altro utente. Riprova.';
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'id_non_valido':
            $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Richiesta non valida.</strong> ID biglietto mancante o errato.';
            break;
        case 'db_error':
            $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Errore tecnico.</strong> Impossibile completare l\'operazione, riprova tra poco.';
            break;
        case 'gia_eliminato':
            $msg_warning = '<i class="bi bi-info-circle-fill me-2"></i><strong>Biglietto già rimosso</strong> oppure la corsa è scaduta nel frattempo.';
            break;
        case 'non_autorizzato_o_scaduto':
            $msg_warning = '<i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Operazione non consentita.</strong> Il biglietto non è tuo oppure la corsa è già passata.';
            break;
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - I Miei Viaggi<?= $num_rimborsi > 0 ? " (🔔 $num_rimborsi)" : "" ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
    <style>
        @keyframes fadeInUp { from { opacity:0; transform:translateY(20px);} to{opacity:1; transform:translateY(0);} }
        @keyframes slideInLeft { from { opacity:0; transform:translateX(-30px);} to{opacity:1; transform:translateX(0);} }
        @keyframes pulse { 0%,100%{transform:scale(1);} 50%{transform:scale(1.05);} }
        @keyframes shake { 0%,100%{transform:translateX(0);} 20%{transform:translateX(-4px);} 40%{transform:translateX(4px);} 60%{transform:translateX(-3px);} 80%{transform:translateX(3px);} }
        @keyframes pulseGlow { 0%,100%{box-shadow:0 0 0 0 rgba(220,53,69,0.6);} 50%{box-shadow:0 0 0 14px rgba(220,53,69,0);} }

        .refund-sticky-banner { position:relative; z-index:1; background:linear-gradient(135deg,#f7fbff 0%,#eef4ff 100%); color:#0d1f3c; padding:14px 18px; border-radius:16px; border:1px solid #d9e6ff; border-left:4px solid #dc3545; box-shadow:0 8px 24px rgba(13,31,60,0.08); animation:fadeInUp 0.35s ease-out; margin-bottom:22px; }
        .refund-banner-wrap { margin-top:6px; margin-bottom:10px; }
        .section-header { display:flex; align-items:center; gap:12px; margin-top:8px; margin-bottom:24px; animation:slideInLeft 0.4s ease-out; }
        .refund-sticky-banner .refund-mini-icon { width:42px; height:42px; border-radius:12px; background:linear-gradient(135deg,#dc3545 0%,#b02a37 100%); color:#fff; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 6px 14px rgba(220,53,69,0.22); }
        .refund-sticky-banner .refund-title { font-size:0.98rem; font-weight:700; line-height:1.2; margin-bottom:2px; color:#1a3a6b; }
        .refund-sticky-banner .refund-subtitle { font-size:0.84rem; color:#4b5d79; line-height:1.35; margin:0; }
        .refund-highlight { color:#b02a37; font-weight:700; }
        .refund-btn-sft { background:linear-gradient(135deg,#1a3a6b 0%,#004a99 100%); color:#fff; border:none; border-radius:999px; padding:8px 16px; font-weight:600; transition:all .25s ease; box-shadow:0 6px 14px rgba(0,74,153,0.18); display:inline-flex; align-items:center; justify-content:center; }
        .refund-btn-sft:hover { transform:translateY(-1px); color:#fff; box-shadow:0 8px 18px rgba(0,74,153,0.26); }
        .refund-card { border-left:4px solid #dc3545; background:linear-gradient(135deg,#ffffff 0%,#f8fbff 100%); border:1px solid #dbe7ff; border-radius:14px; padding:14px 16px; margin-bottom:10px; animation:fadeInUp 0.4s ease-out; box-shadow:0 6px 18px rgba(13,31,60,0.06); }

        .nav-bell { position:relative; color:#fff; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.25); border-radius:50%; width:40px; height:40px; display:inline-flex; align-items:center; justify-content:center; text-decoration:none; transition:all .25s ease; }
        .nav-bell:hover { background:#fff; color:#1a3a6b; }
        .nav-bell.has-alert { animation:shake 0.9s ease-in-out 2, pulseGlow 2s infinite 1.8s; background:#dc3545; border-color:#dc3545; }
        .nav-bell .bell-count { position:absolute; top:-6px; right:-6px; background:#ffc107; color:#212529; font-size:0.7rem; font-weight:700; padding:2px 6px; border-radius:10px; border:2px solid #1a3a6b; min-width:22px; text-align:center; }

        .modal-refund .modal-content { border:none; border-radius:18px; overflow:hidden; box-shadow:0 25px 60px rgba(0,0,0,0.35); }
        .modal-refund .modal-header { background:linear-gradient(135deg,#dc3545 0%,#b02a37 100%); color:#fff; border:none; padding:20px 24px; }
        .modal-refund .refund-amount { font-size:2.5rem; font-weight:800; color:#198754; }

        .ticket-card { border:none; border-radius:16px; overflow:hidden; transition:all .3s cubic-bezier(0.4,0,0.2,1); animation:fadeInUp 0.5s ease-out forwards; position:relative; }
        .ticket-card::before { content:''; position:absolute; top:0; left:0; width:6px; height:100%; background:linear-gradient(180deg,#0d6efd 0%,#0a58ca 100%); border-radius:16px 0 0 16px; }
        .ticket-card:hover { transform:translateY(-8px) scale(1.02); box-shadow:0 20px 40px rgba(13,110,253,0.15); }
        .ticket-card:hover .ticket-arrow { animation:pulse 0.6s ease-in-out infinite; }
        .ticket-card-past { opacity:0.75; }
        .ticket-card-past::before { background:linear-gradient(180deg,#6c757d 0%,#495057 100%); }
        .ticket-card-past:hover { transform:translateY(-4px); box-shadow:0 10px 25px rgba(108,117,125,0.1); }
        .ticket-card-locked::before { background:linear-gradient(180deg,#ffc107 0%,#d39e00 100%); }
        .ticket-id { font-size:0.7rem; letter-spacing:2px; background:linear-gradient(135deg,#f8f9fa 0%,#e9ecef 100%); padding:4px 10px; border-radius:20px; display:inline-block; }
        .ticket-route { font-size:1.25rem; font-weight:700; color:#0d6efd; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .ticket-arrow { color:#0d6efd; font-size:1.5rem; transition:transform 0.3s ease; }
        .ticket-card:hover .ticket-arrow { transform:translateX(5px); }
        .badge-posto { background:linear-gradient(135deg,#17a2b8 0%,#138496 100%); font-size:0.9rem; padding:8px 16px; border-radius:25px; font-weight:600; box-shadow:0 4px 12px rgba(23,162,184,0.3); transition:all 0.3s ease; }
        .ticket-card:hover .badge-posto { transform:scale(1.05); box-shadow:0 6px 16px rgba(23,162,184,0.4); }
        .badge-posto-past { background:linear-gradient(135deg,#6c757d 0%,#5a6268 100%); box-shadow:0 4px 12px rgba(108,117,125,0.2); }
        .info-box { background:linear-gradient(135deg,#f8f9fa 0%,#fff 100%); border-radius:12px; padding:15px; text-align:center; transition:all 0.3s ease; }
        .info-box:hover { background:linear-gradient(135deg,#e7f1ff 0%,#fff 100%); }
        .info-label { font-size:0.65rem; text-transform:uppercase; letter-spacing:1px; color:#6c757d; margin-bottom:4px; }
        .info-value { font-size:1.1rem; font-weight:700; color:#212529; }
        .btn-action { border-radius:25px; padding:10px 20px; font-weight:600; transition:all 0.3s cubic-bezier(0.4,0,0.2,1); position:relative; overflow:hidden; }
        .btn-action:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,0.15); }
        .btn-cambia:hover { background:#0d6efd; color:white; border-color:#0d6efd; }
        .btn-elimina { width:44px; height:44px; padding:0; display:flex; align-items:center; justify-content:center; border-radius:50%; }
        .btn-elimina:hover { background:#dc3545; color:white; border-color:#dc3545; transform:rotate(10deg) scale(1.1); }
        .lock-banner { background:#fff8e1; border:1px solid #ffe082; border-radius:12px; padding:10px 14px; color:#7a5a00; font-size:0.88rem; display:flex; align-items:center; gap:8px; }
        .section-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.5rem; }
        .section-icon-active { background:linear-gradient(135deg,#0d6efd 0%,#0a58ca 100%); color:white; box-shadow:0 8px 20px rgba(13,110,253,0.3); }
        .section-icon-past { background:linear-gradient(135deg,#6c757d 0%,#495057 100%); color:white; box-shadow:0 8px 20px rgba(108,117,125,0.2); }
        .section-title { font-size:1.5rem; font-weight:700; margin:0; }
        .section-count { font-size:0.85rem; color:#6c757d; margin:0; }
        .section-divider { height:2px; background:linear-gradient(90deg,transparent,#dee2e6,transparent); margin:40px 0; }
        .empty-state { text-align:center; padding:60px 20px; animation:fadeInUp 0.5s ease-out; }
        .empty-state i { font-size:4rem; color:#dee2e6; margin-bottom:20px; }
        .collapse-toggle { cursor:pointer; user-select:none; transition:all .3s ease; }
        .collapse-toggle:hover { opacity:0.8; }
        .collapse-toggle .bi-chevron-down { transition:transform .3s ease; }
        .collapse-toggle[aria-expanded="true"] .bi-chevron-down { transform:rotate(180deg); }
        .nav-btn-search { background:rgba(255,255,255,0.12); color:#fff !important; border:1px solid rgba(255,255,255,0.25); border-radius:30px; backdrop-filter:blur(6px); transition:all .25s ease; letter-spacing:0.02em; padding:8px 18px; }
        .nav-btn-search:hover { background:#fff; color:#1a3a6b !important; border-color:#fff; transform:translateY(-1px); box-shadow:0 4px 14px rgba(0,0,0,0.18); }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm mb-4">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php"><i class="bi bi-train-front me-2"></i>SFT</a>
        <div class="navbar-nav ms-auto gap-2 align-items-center flex-row">
            <?php if ($num_rimborsi > 0): ?>
                <a href="#rimborsiBlock" class="nav-bell has-alert" id="bellRefund"
                   data-bs-toggle="modal" data-bs-target="#modalRimborso"
                   title="Hai <?= $num_rimborsi ?> rimborsi recenti">
                    <i class="bi bi-bell-fill"></i>
                    <span class="bell-count"><?= $num_rimborsi ?></span>
                </a>
            <?php endif; ?>
            <a class="btn btn-sm fw-bold px-3 d-flex align-items-center gap-2 nav-btn-search" href="index.php">
                <i class="bi bi-search"></i><span>Nuova Ricerca</span>
            </a>
            <a class="nav-link text-white bg-danger rounded-pill px-3" href="logout.php">
                <i class="bi bi-box-arrow-right me-1"></i> Esci
            </a>
        </div>
    </div>
</nav>

<?php if ($num_rimborsi > 0): ?>
<div class="container refund-banner-wrap">
    <div class="refund-sticky-banner d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="refund-mini-icon"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="refund-title">
                    Avviso: <?= $num_rimborsi ?> <?= $num_rimborsi === 1 ? 'biglietto modificato' : 'biglietti modificati' ?>
                </div>
                <p class="refund-subtitle">
                    Accreditati <span class="refund-highlight">€ <?= number_format($totale_rimborsato, 2, ',', '.') ?></span>
                    sul tuo conto PaySteam.
                </p>
            </div>
        </div>
        <button type="button" class="refund-btn-sft" data-bs-toggle="modal" data-bs-target="#modalRimborso">
            <i class="bi bi-eye-fill me-1"></i> Dettagli
        </button>
    </div>
</div>
<?php endif; ?>

<div class="container pb-5">

    <?php if ($msg_success): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 border-0 mt-3" style="animation: fadeInUp 0.4s ease-out;">
            <div class="d-flex align-items-center"><?= $msg_success; ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($msg_warning): ?>
        <div class="alert alert-warning alert-dismissible fade show shadow-sm rounded-3 border-0 mt-3" style="animation: fadeInUp 0.4s ease-out;">
            <div class="d-flex align-items-center"><?= $msg_warning; ?></div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="section-header">
        <div class="section-icon section-icon-active"><i class="bi bi-calendar-check"></i></div>
        <div>
            <h2 class="section-title text-primary">Viaggi Attivi</h2>
            <p class="section-count"><?= count($biglietti_attivi) ?> bigliett<?= count($biglietti_attivi) == 1 ? 'o' : 'i' ?> in programma</p>
        </div>
    </div>

    <?php if (!empty($biglietti_attivi)): ?>
        <div class="row g-4">
            <?php foreach ($biglietti_attivi as $b): ?>
                <?php
                    $ok_mod = puo_prenotare($con, (int)$b['IDCORSA'], $b['PARTENZA'], 15);
                    $modificabile = $ok_mod['ok'];
                    $lock_reason  = $ok_mod['motivo'];
                ?>
                <div class="col-lg-6">
                    <div class="card ticket-card <?= $modificabile ? '' : 'ticket-card-locked' ?> shadow">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <span class="ticket-id text-muted fw-bold">
                                        <i class="bi bi-ticket-perforated me-1"></i>#<?= $b['IDBIGLIETTO'] ?>
                                    </span>
                                </div>
                                <span class="badge badge-posto">
                                    <i class="bi bi-geo-alt-fill me-1"></i> Posto <?= $b['POSTO'] ?>
                                </span>
                            </div>
                            <div class="ticket-route mb-2">
                                <span><?= htmlspecialchars($b['PARTENZA']) ?></span>
                                <i class="bi bi-arrow-right-circle-fill ticket-arrow"></i>
                                <span><?= htmlspecialchars($b['ARRIVO']) ?></span>
                            </div>
                            <p class="text-muted small mb-3">
                                <i class="bi bi-train-front-fill me-1"></i><?= htmlspecialchars($b['NOME_TRENO']) ?>
                                <span class="mx-2">•</span>Corsa #<?= $b['IDCORSA'] ?>
                            </p>
                            <div class="row g-3 mb-4">
                                <div class="col-4"><div class="info-box"><div class="info-label">Data</div><div class="info-value"><?= date("d/m", strtotime($b['DATABIGLIETTO'])) ?></div></div></div>
                                <div class="col-4"><div class="info-box"><div class="info-label">Partenza</div><div class="info-value"><?= substr($b['ORA_PARTENZA'], 0, 5) ?></div></div></div>
                                <div class="col-4"><div class="info-box"><div class="info-label">Prezzo</div><div class="info-value text-success"><?= number_format($b['PREZZO'], 2) ?>€</div></div></div>
                            </div>

                            <?php if ($modificabile): ?>
                                <div class="d-flex gap-2 flex-wrap">
                                    <a href="cambio_data.php?id=<?= $b['IDBIGLIETTO'] ?>" class="btn btn-outline-warning btn-action flex-grow-1">
                                        <i class="bi bi-calendar-event me-2"></i>Cambia Data
                                    </a>
                                    <a href="conferma_pagamento.php?idcorsa=<?= $b['IDCORSA'] ?>&prezzo=0&modifica=<?= $b['IDBIGLIETTO'] ?>&partenza=<?= urlencode($b['PARTENZA']) ?>&arrivo=<?= urlencode($b['ARRIVO']) ?>"
                                       class="btn btn-outline-primary btn-action btn-cambia flex-grow-1">
                                        <i class="bi bi-pencil-square me-2"></i>Cambia Posto
                                    </a>
                                    <form method="POST" action="elimina_biglietto.php" class="m-0" onsubmit="return confirm('Sei sicuro di voler annullare questo viaggio?')">
                                        <input type="hidden" name="id" value="<?= $b['IDBIGLIETTO'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-action btn-elimina">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <div class="lock-banner">
                                    <i class="bi bi-hourglass-split"></i>
                                    <div>
                                        <?php if ($lock_reason === 'corsa_passata'): ?>
                                            <strong>Corsa già partita.</strong> Il biglietto non è più modificabile.
                                        <?php elseif ($lock_reason === 'corsa_non_attiva'): ?>
                                            <strong>Corsa non attiva</strong> (annullata o conclusa). Modifiche non disponibili.
                                        <?php else: ?>
                                            <strong>Partenza imminente</strong> (meno di 15 minuti): modifiche bloccate per questo biglietto.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h4 class="text-muted">Nessun viaggio in programma</h4>
            <p class="text-muted mb-4">Prenota il tuo prossimo viaggio!</p>
            <a href="index.php" class="btn btn-primary btn-action px-4"><i class="bi bi-search me-2"></i>Cerca Treno</a>
        </div>
    <?php endif; ?>

    <?php if (!empty($biglietti_passati)): ?>
        <div class="section-divider"></div>
        <div class="section-header collapse-toggle" data-bs-toggle="collapse" data-bs-target="#storicoViaggi" aria-expanded="true">
            <div class="section-icon section-icon-past"><i class="bi bi-clock-history"></i></div>
            <div class="flex-grow-1">
                <h2 class="section-title text-secondary">Storico Viaggi</h2>
                <p class="section-count"><?= count($biglietti_passati) ?> cors<?= count($biglietti_passati) == 1 ? 'a passata' : 'e passate' ?></p>
            </div>
            <i class="bi bi-chevron-down fs-4 text-muted"></i>
        </div>
        <div class="collapse show" id="storicoViaggi">
            <div class="row g-4">
                <?php foreach ($biglietti_passati as $b): ?>
                    <div class="col-lg-6">
                        <div class="card ticket-card ticket-card-past shadow-sm">
                            <div class="card-body p-4">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div><span class="ticket-id text-muted fw-bold"><i class="bi bi-ticket-perforated me-1"></i>#<?= $b['IDBIGLIETTO'] ?></span></div>
                                    <span class="badge badge-posto badge-posto-past"><i class="bi bi-geo-alt-fill me-1"></i>Posto <?= $b['POSTO'] ?></span>
                                </div>
                                <div class="ticket-route mb-2" style="color: #6c757d;">
                                    <span><?= htmlspecialchars($b['PARTENZA']) ?></span>
                                    <i class="bi bi-arrow-right-circle-fill ticket-arrow" style="color: #6c757d;"></i>
                                    <span><?= htmlspecialchars($b['ARRIVO']) ?></span>
                                </div>
                                <p class="text-muted small mb-3"><i class="bi bi-train-front-fill me-1"></i><?= htmlspecialchars($b['NOME_TRENO']) ?><span class="mx-2">•</span>Corsa #<?= $b['IDCORSA'] ?></p>
                                <div class="row g-3 mb-3">
                                    <div class="col-4"><div class="info-box"><div class="info-label">Data</div><div class="info-value"><?= date("d/m/Y", strtotime($b['DATABIGLIETTO'])) ?></div></div></div>
                                    <div class="col-4"><div class="info-box"><div class="info-label">Partenza</div><div class="info-value"><?= substr($b['ORA_PARTENZA'], 0, 5) ?></div></div></div>
                                    <div class="col-4"><div class="info-box"><div class="info-label">Prezzo</div><div class="info-value"><?= number_format($b['PREZZO'], 2) ?>€</div></div></div>
                                </div>
                                <div class="text-center">
                                    <span class="badge bg-light text-secondary border px-3 py-2"><i class="bi bi-check-circle-fill me-1"></i>Viaggio Completato</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($biglietti_attivi) && empty($biglietti_passati)): ?>
        <div class="empty-state bg-white rounded-4 shadow-sm mt-4">
            <i class="bi bi-ticket-perforated-fill"></i>
            <h4 class="mt-3">Nessun biglietto trovato</h4>
            <p class="text-muted">Sembra che tu non abbia ancora prenotato dei viaggi.</p>
            <a href="index.php" class="btn btn-primary btn-action px-4 mt-2"><i class="bi bi-search me-2"></i>Inizia ora</a>
        </div>
    <?php endif; ?>

</div>

<?php if ($num_rimborsi > 0): ?>
<div class="modal fade modal-refund" id="modalRimborso" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Notifica viaggio acquistato</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <?php $tipo_avviso = $rimborsi_da_mostrare[0]['TIPO_AVVISO'] ?? 'modifica'; ?>
                <?php if ($tipo_avviso === 'rimborso'): ?>
                    <div class="text-center mb-4">
                        <i class="bi bi-cash-coin text-success" style="font-size: 3rem;"></i>
                        <div class="refund-amount mt-2">+ € <?= number_format($totale_rimborsato, 2, ',', '.') ?></div>
                        <div class="text-muted">accreditati sul tuo conto <strong>PaySteam</strong></div>
                    </div>
                    <div class="alert alert-warning border-0 rounded-3">
                        <strong><i class="bi bi-info-circle-fill me-1"></i> Avviso rimborso</strong>
                        <p class="mb-0 mt-2 small">Biglietto annullato. Importo già riaccreditato su PaySteam.</p>
                    </div>
                <?php else: ?>
                    <div class="text-center mb-4">
                        <i class="bi bi-pencil-square text-warning" style="font-size: 3rem;"></i>
                        <div class="refund-amount mt-2">Avviso modifica biglietto</div>
                        <div class="text-muted">controlla i dettagli aggiornati del viaggio</div>
                    </div>
                    <div class="alert alert-warning border-0 rounded-3">
                        <strong><i class="bi bi-info-circle-fill me-1"></i> Avviso modifica</strong>
                        <p class="mb-0 mt-2 small">Il biglietto ha subito una modifica o uno spostamento. Verifica i dettagli del viaggio.</p>
                    </div>
                <?php endif; ?>

                <h6 class="fw-bold mt-4 mb-3">
                    <?= $tipo_avviso === 'rimborso' ? 'Dettaglio dei rimborsi:' : 'Dettaglio delle modifiche:' ?>
                </h6>

                <?php foreach ($rimborsi_da_mostrare as $rb): ?>
                    <div class="refund-card">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="small text-muted mt-1">
                                      <?= htmlspecialchars(preg_replace('/\s*\[SFT_UTENTE:\d+\]\s*$/', '', $rb['DESCRIZIONE'])) ?> 
                                </div>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-clock"></i> <?= date('d/m/Y H:i', strtotime($rb['DATAORA'])) ?>
                                </div>
                            </div>
                            <div class="fw-bold <?= ($tipo_avviso === 'rimborso') ? 'text-success fs-5' : 'text-warning small' ?>">
                                <?php if ($tipo_avviso === 'rimborso'): ?>
                                    + € <?= number_format($rb['IMPORTO'], 2, ',', '.') ?>
                                <?php else: ?>
                                    Biglietto modificato
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-primary btn-action px-4" data-bs-dismiss="modal">
                    <i class="bi bi-check2 me-1"></i> Ho capito
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php if ($num_rimborsi > 0): ?>
<script>
(function() {
    const firma = <?= json_encode($firma_rimborsi) ?>;
    const key   = 'sft_rimborsi_visti_<?= $id_utente ?>';
    const vista = sessionStorage.getItem(key);
    if (vista !== firma) {
        document.addEventListener('DOMContentLoaded', function() {
            const modalEl = document.getElementById('modalRimborso');
            if (modalEl) {
                const modal = new bootstrap.Modal(modalEl, { backdrop: 'static' });
                modal.show();
                modalEl.addEventListener('hidden.bs.modal', function() {
                    sessionStorage.setItem(key, firma);
                });
            }
        });
    }
})();
</script>
<?php endif; ?>

</body>
</html>

