<?php
file_put_contents(
    '/tmp/pay_log.txt',
    date('H:i:s') . " [4] conferma.php - method=" . $_SERVER['REQUEST_METHOD'] . "\n",
    FILE_APPEND
);

ob_start();
session_name('PAY_SESSION');
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';

// Ripristino pay_pending dal token se la sessione l'ha perso
if (!isset($_SESSION['pay_pending']) && !empty($_GET['token'])) {
    $decoded = json_decode(base64_decode($_GET['token']), true);
    if ($decoded) {
        $_SESSION['pay_pending'] = $decoded;
    }
}

$token = $_GET['token'] ?? '';
if (empty($token) && !empty($_SESSION['pay_pending'])) {
    $token = base64_encode(json_encode($_SESSION['pay_pending']));
}

// Controllo login
if (!isset($_SESSION['pay_id']) || $_SESSION['pay_tipo'] !== 'CONSUMATORE') {
    header("Location: /gio.fuso/PAY/login.php?redirect=pay&token=" . urlencode($token));
    exit();
}

if (!isset($_SESSION['pay_pending'])) {
    die("<pre>ERRORE: dati pagamento non trovati in sessione.</pre>");
}

$id_cons         = intval($_SESSION['pay_id']);
$id_transesterna = $con->real_escape_string($_SESSION['pay_pending']['id_transesterna']);

$res_db = $con->query("
    SELECT t.IMPORTO, t.IDESERCENTE, t.DESCRIZIONE, t.URLOUT, t.IDTRANSESTERNA,
           u.NOME AS NOME_ESERCENTE
    FROM PAY_TRANSAZIONE t
    JOIN PAY_UTENTE u ON t.IDESERCENTE = u.IDUTENTE
    WHERE t.IDTRANSESTERNA = '$id_transesterna'
      AND t.STATO = 'PENDING'
    LIMIT 1
");

if (!$res_db || $res_db->num_rows === 0) {
    die("Transazione non trovata o già processata.");
}

$row     = $res_db->fetch_assoc();
$prezzo  = floatval($row['IMPORTO']);
$url_out = $row['URLOUT'];
$id_ese  = intval($row['IDESERCENTE']);
//idconsumatore not null 
$con->query("UPDATE PAY_TRANSAZIONE 
             SET IDCONSUMATORE = $id_cons 
             WHERE IDTRANSESTERNA = '$id_transesterna' 
               AND IDCONSUMATORE IS NULL");

// Leggi saldo attuale
$res_conto = $con->query("SELECT SALDO FROM PAY_CONTO WHERE IDUTENTE = $id_cons");
if (!$res_conto || $res_conto->num_rows === 0) {
    $iban = 'IT' . strtoupper(substr(md5(uniqid($id_cons, true)), 0, 22));
    $con->query("INSERT INTO PAY_CONTO (IDUTENTE, IBAN, SALDO) VALUES ($id_cons, '$iban', 0.00)");
    $saldo = 0;
} else {
    $saldo = floatval($res_conto->fetch_assoc()['SALDO']);
}

// GET --> mostra pagina di conferma all'utente
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Conferma Pagamento – PaySteam</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sft-blu: #1a3a6b;
            --sft-blu-scuro: #0d1f3c;
            --sft-azzurro: #2563eb;
            --sft-accent: #d4066d;
            --sft-verde: #16a34a;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            min-height: 100vh;
        }
        .hero-pay {
            background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 60%, var(--sft-azzurro) 100%);
            color: white;
            padding: 40px 0 80px;
            position: relative;
        }
        .hero-pay::after {
            content: "";
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 40px;
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%);
            clip-path: ellipse(55% 100% at 50% 100%);
        }
        .card-conferma {
            border: none;
            border-radius: 18px;
            box-shadow: 0 12px 40px rgba(13,31,60,0.15);
            margin-top: -50px;
            overflow: hidden;
        }
        .card-header-custom {
            background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%);
            color: white;
            padding: 1.5rem;
            border-bottom: 3px solid var(--sft-accent);
        }
        .importo-box {
            background: linear-gradient(135deg, var(--sft-blu) 0%, var(--sft-azzurro) 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 14px;
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .importo-valore {
            font-size: 2.5rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 0.8rem 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 0.95rem;
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; }
        .info-value { font-weight: 600; color: #1e293b; }
        .saldo-ok { color: var(--sft-verde); }
        .saldo-ko { color: #dc2626; }
        .btn-conferma {
            background: linear-gradient(90deg, var(--sft-verde) 0%, #15803d 100%);
            border: none;
            color: white;
            padding: 0.9rem 1.5rem;
            font-size: 1.05rem;
            font-weight: 700;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(22,163,74,0.3);
            transition: all 0.2s;
        }
        .btn-conferma:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(22,163,74,0.4);
            color: white;
        }
        .btn-annulla {
            background: transparent;
            border: 2px solid #cbd5e1;
            color: #64748b;
            padding: 0.85rem 1.5rem;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.2s;
        }
        .btn-annulla:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
            color: #475569;
        }
        .alert-saldo {
            background: linear-gradient(90deg, #fef3c7 0%, #fde68a 100%);
            border: none;
            border-left: 4px solid var(--sft-accent);
            border-radius: 12px;
            color: #92400e;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark" style="background: linear-gradient(90deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%);">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/gio.fuso/PAY/index.php">
            <i class="bi bi-credit-card-2-front me-2"></i>PaySteam
        </a>
    </div>
</nav>

<!-- HERO -->
<header class="hero-pay text-center">
    <div class="container">
        <i class="bi bi-shield-lock display-4 mb-3 d-block" style="opacity:0.8;"></i>
        <h1 class="display-6 fw-bold mb-2">Conferma Pagamento</h1>
        <p class="lead mb-0" style="opacity:0.8;">Verifica i dettagli e autorizza la transazione</p>
    </div>
</header>

<!-- CARD PRINCIPALE -->
<main class="container pb-5">
<div class="row justify-content-center">
<div class="col-md-5">
<div class="card card-conferma">
    
    <!-- Header card con esercente -->
    <div class="card-header-custom">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle bg-white bg-opacity-25 p-2">
                <i class="bi bi-train-front fs-4"></i>
            </div>
            <div>
                <div style="font-size:0.75rem; opacity:0.7; text-transform:uppercase; letter-spacing:0.1em;">Pagamento a</div>
                <div class="fw-bold fs-5"><?= htmlspecialchars($row['NOME_ESERCENTE']) ?></div>
            </div>
        </div>
    </div>

    <div class="card-body p-4">
        
        <!-- Box importo -->
        <div class="importo-box">
            <div style="font-size:0.8rem; opacity:0.8; text-transform:uppercase; letter-spacing:0.1em;">Importo totale</div>
            <div class="importo-valore">€<?= number_format($prezzo, 2, ',', '.') ?></div>
        </div>

        <!-- Dettagli -->
        <div class="mb-4">
            <div class="info-row">
                <span class="info-label"><i class="bi bi-receipt me-2"></i>Descrizione</span>
                <span class="info-value"><?= htmlspecialchars($row['DESCRIZIONE']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><i class="bi bi-wallet2 me-2"></i>Saldo disponibile</span>
                <span class="info-value <?= $saldo >= $prezzo ? 'saldo-ok' : 'saldo-ko' ?>">
                    €<?= number_format($saldo, 2, ',', '.') ?>
                    <?php if ($saldo >= $prezzo): ?>
                        <i class="bi bi-check-circle-fill ms-1"></i>
                    <?php else: ?>
                        <i class="bi bi-exclamation-circle-fill ms-1"></i>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if ($saldo < $prezzo): ?>
            <!-- Saldo insufficiente -->
            <div class="alert alert-saldo mb-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <strong>Saldo insufficiente</strong><br>
                <small>Ti servono ancora €<?= number_format($prezzo - $saldo, 2, ',', '.') ?></small>
            </div>
            <a href="/gio.fuso/PAY/ricarica.php?from_pay=1&token=<?= urlencode($token) ?>" 
               class="btn btn-conferma w-100 mb-3">
                <i class="bi bi-plus-circle me-2"></i>Ricarica ora
            </a>
        <?php else: ?>
            <!-- Pulsante conferma -->
            <form method="POST" action="/gio.fuso/PAY/conferma.php?token=<?= urlencode($token) ?>">
                <input type="hidden" name="confermato" value="1">
                <button type="submit" class="btn btn-conferma w-100 mb-3">
                    <i class="bi bi-check-circle me-2"></i>Conferma e Paga
                </button>
            </form>
        <?php endif; ?>

        <!-- Pulsante annulla -->
        <a href="/gio.fuso/SFT/risposta_pay.php?id_transazione=<?= urlencode($id_transesterna) ?>&esito=KO" 
           class="btn btn-annulla w-100">
            <i class="bi bi-x-circle me-2"></i>Annulla
        </a>

    </div>
</div>

<!-- Sicurezza -->
<div class="text-center mt-4" style="font-size:0.8rem; color:#64748b;">
    <i class="bi bi-lock-fill me-1"></i> Transazione protetta da PaySteam
</div>

</div>
</div>
</main>

</body>
</html>

<?php
    exit();
}

// POST -->  esegui il pagamento
if (!isset($_POST['confermato'])) {
    die("Azione non valida.");
}

$con->begin_transaction();

try {
    $res_lock = $con->query("SELECT SALDO FROM PAY_CONTO WHERE IDUTENTE = $id_cons FOR UPDATE");
    if (!$res_lock || $res_lock->num_rows === 0) {
        throw new Exception("Conto consumatore non trovato");
    }

    $saldo = floatval($res_lock->fetch_assoc()['SALDO']);

    if ($saldo < $prezzo) {
        $con->rollback();
        unset($_SESSION['pay_pending']);
        header("Location: /gio.fuso/PAY/ricarica.php?from_pay=1&token=" . urlencode($token));
        exit();
    }

    $q1 = $con->query("
        UPDATE PAY_TRANSAZIONE
        SET STATO = 'COMPLETED',
            IDCONSUMATORE = $id_cons,
            DATAORA = NOW()
        WHERE IDTRANSESTERNA = '$id_transesterna' AND STATO = 'PENDING'
    ");

    file_put_contents('/tmp/pay_log.txt',
        date('H:i:s') . " [4b] update transazione affected_rows=" . $con->affected_rows . " id_trans=$id_transesterna\n",
        FILE_APPEND);

    if (!$q1 || $con->affected_rows !== 1) {
        throw new Exception("Aggiornamento transazione fallito");
    }

    $q2 = $con->query("UPDATE PAY_CONTO SET SALDO = SALDO - $prezzo WHERE IDUTENTE = $id_cons");
    if (!$q2) throw new Exception("Addebito consumatore fallito");

    $q3 = $con->query("UPDATE PAY_CONTO SET SALDO = SALDO + $prezzo WHERE IDUTENTE = $id_ese");
    if (!$q3) throw new Exception("Accredito esercente fallito");

    $con->commit();
    $esito_pagamento = 'OK';

} catch (Exception $e) {
    $con->rollback();
    die("Errore pagamento: " . htmlspecialchars($e->getMessage()));
}

// Salva dati PRIMA di cancellare sessione
$url_inviante = $_SESSION['pay_pending']['url_inviante'] ?? '';
$url_risposta = $_SESSION['pay_pending']['url_risposta'] ?? $url_inviante;
$id_trans_ext = $id_transesterna;

unset($_SESSION['pay_pending']);

//CALLBACK M2M A SFT 
$callback_payload = [
    'url_inviante'   => $url_inviante,
    'id_transazione' => $id_trans_ext,
    'esito'          => $esito_pagamento
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'http://127.0.0.1/gio.fuso/SFT/risposta_pay.php',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($callback_payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
]);
$callback_response = curl_exec($ch);
curl_close($ch);

file_put_contents('/tmp/pay_log.txt',
    date('H:i:s') . " [M2M-CALLBACK] esito=$esito_pagamento response=$callback_response\n",
    FILE_APPEND);

// REDIRECT BROWSER
header("Location: " . $url_risposta . "?esito=" . $esito_pagamento . "&id_transazione=" . urlencode($id_trans_ext));
exit();