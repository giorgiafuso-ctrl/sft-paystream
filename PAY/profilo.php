<?php
ob_start();
session_name('PAY_SESSION'); session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';

if (!isset($_SESSION['pay_id']) || $_SESSION['pay_tipo'] !== 'CONSUMATORE') {
    header("Location: /gio.fuso/PAY/login.php");
    exit();
}

$id_cons = intval($_SESSION['pay_id']);
$msg = '';

// ELIMINA CARTA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['elimina_carta'])) {
    $num_del = $con->real_escape_string(preg_replace('/\s+/', '', $_POST['elimina_carta']));
    //dissocia l'utente dalla carta
    $con->query("DELETE FROM PAY_MEMORIZZA WHERE IDUTENTE = $id_cons AND NUMERO = '$num_del'");
    //se nessun altro la possiede, elimina anche la carta
    $con->query("DELETE FROM PAY_CARTA 
                 WHERE NUMERO = '$num_del' 
                 AND NOT EXISTS (SELECT 1 FROM PAY_MEMORIZZA WHERE NUMERO = '$num_del')");
    $msg = 'eliminata';
}
// AGGIUNGI NUOVA CARTA
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero   = preg_replace('/\s+/', '', $_POST['numero'] ?? '');
    $scadenza = trim($_POST['scadenza'] ?? '');
    $cvv      = trim($_POST['cvv'] ?? '');

    if (strlen($numero) !== 16 || !is_numeric($numero)) {
        $msg = 'errore_numero';
    } elseif (!preg_match('/^\d{2}\/\d{2}$/', $scadenza)) {
        $msg = 'errore_scadenza';
    } elseif (strlen($cvv) < 3 || !is_numeric($cvv)) {
        $msg = 'errore_cvv';
    } else {
         $n = $con->real_escape_string($numero);
        $s = $con->real_escape_string($scadenza);
        $c = $con->real_escape_string($cvv);
        // inserimento carta se nuova
        $con->query("INSERT IGNORE INTO PAY_CARTA (NUMERO, SCADENZA, CVV)
                     VALUES ('$n', '$s', '$c')");
        // aggiorna scadenza/cvv se esisteva già 
        $con->query("UPDATE PAY_CARTA SET SCADENZA='$s', CVV='$c' WHERE NUMERO='$n'");
        //associa la carta all'utente
        $con->query("INSERT IGNORE INTO PAY_MEMORIZZA (NUMERO, IDUTENTE)
                     VALUES ('$n', $id_cons)");
        $msg = 'ok';
    }
}

// Carica le carte salvate
$carte = [];
$res_carte = $con->query("
    SELECT c.NUMERO, c.SCADENZA
    FROM PAY_CARTA c
    JOIN PAY_MEMORIZZA m ON m.NUMERO = c.NUMERO
    WHERE m.IDUTENTE = $id_cons
    ORDER BY c.NUMERO
");
while ($row = $res_carte->fetch_assoc()) { $carte[] = $row; }
$ha_carte = count($carte) > 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PaySteam – Profilo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sft-blu: #1a3a6b; --sft-blu-scuro: #0d1f3c; --sft-azzurro: #2563eb;
            --sft-accent: #d4066d; --sft-verde: #16a34a; --sft-bordo: #cbd5e1; --sft-muted: #64748b;
        }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%); min-height: 100vh; }
        .navbar { background: linear-gradient(90deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%) !important; box-shadow: 0 2px 15px rgba(13,31,60,0.25); }
        .hero-profilo { background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 60%, var(--sft-azzurro) 100%); color: white; padding: 48px 0 90px; position: relative; }
        .hero-profilo::after { content: ""; position: absolute; bottom: 0; left: 0; right: 0; height: 45px; background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 100%); clip-path: ellipse(55% 100% at 50% 100%); }
        .card-profilo { border: none; border-radius: 18px; box-shadow: 0 12px 40px rgba(13,31,60,0.12); margin-top: -55px; overflow: hidden; }
        .carta-preview { background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 50%, var(--sft-accent) 100%); border-radius: 16px; color: white; padding: 1.2rem; position: relative; overflow: hidden; }
        .carta-preview::before { content: ""; position: absolute; top: -30px; right: -30px; width: 120px; height: 120px; background: rgba(255,255,255,0.08); border-radius: 50%; }
        .carta-numero { font-size: 1.1rem; letter-spacing: 0.2em; font-weight: 700; text-shadow: 0 2px 4px rgba(0,0,0,0.2); }
        .form-label { font-size: 0.75rem; font-weight: 700; color: var(--sft-muted); text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 0.4rem; }
        .form-control { border: 2px solid #e2e8f0; border-radius: 10px; padding: 0.7rem 1rem; font-size: 0.95rem; background: #f8fafc; transition: all 0.2s; }
        .form-control:focus { border-color: var(--sft-azzurro); box-shadow: 0 0 0 4px rgba(37,99,235,0.12); background: white; }
        .input-group-text { background: linear-gradient(135deg, #f0f5ff 0%, #e8f0fe 100%); border: 2px solid #e2e8f0; border-right: none; border-radius: 10px 0 0 10px; color: var(--sft-azzurro); }
        .input-group .form-control { border-radius: 0 10px 10px 0; }
        .btn-salva { background: linear-gradient(90deg, var(--sft-azzurro) 0%, #1d4ed8 100%); border: none; color: white; padding: 0.85rem 1.5rem; font-weight: 700; border-radius: 12px; font-size: 1rem; box-shadow: 0 6px 20px rgba(37,99,235,0.3); transition: all 0.2s; }
        .btn-salva:hover { transform: translateY(-2px); color: white; }
        .btn-outline-back { border: 2px solid var(--sft-bordo); color: var(--sft-muted); border-radius: 10px; font-weight: 600; }
        .btn-outline-back:hover { background: #f1f5f9; color: var(--sft-blu); }
        .alert { border: none; border-radius: 12px; border-left: 4px solid; font-size: 0.9rem; }
        .alert-success { background: linear-gradient(90deg, #f0fdf4 0%, #dcfce7 100%); border-left-color: var(--sft-verde); color: #166534; }
        .alert-danger { background: linear-gradient(90deg, #fef2f2 0%, #fee2e2 100%); border-left-color: #dc2626; color: #991b1b; }
        .alert-warning { background: linear-gradient(90deg, #fffbeb 0%, #fef3c7 100%); border-left-color: var(--sft-accent); color: #92400e; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/gio.fuso/PAY/index.php"><i class="bi bi-credit-card-2-front me-2"></i>PaySteam</a>
        <div class="d-flex align-items-center gap-3">
            <span class="text-white-50" style="font-size:0.85rem;"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['pay_nome']) ?></span>
            <a href="/gio.fuso/PAY/logout.php" class="btn btn-outline-light btn-sm"><i class="bi bi-box-arrow-right"></i></a>
        </div>
    </div>
</nav>

<header class="hero-profilo text-center">
    <div class="container">
        <i class="bi bi-person-gear display-4 mb-3 d-block" style="opacity:0.85;"></i>
        <h1 class="display-6 fw-bold mb-2">Il mio profilo</h1>
        <p class="lead mb-0" style="opacity:0.8;">Gestisci le tue carte di pagamento</p>
    </div>
</header>

<main class="container pb-5">
<div class="row justify-content-center">
<div class="col-md-5">
<div class="card card-profilo p-4">

    <?php if ($msg === 'ok'): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-check-circle-fill fs-5"></i><span>Carta aggiunta con successo!</span>
    </div>
    <?php elseif ($msg === 'eliminata'): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-trash-fill fs-5"></i><span>Carta rimossa.</span>
    </div>
    <?php elseif (strpos($msg, 'errore') === 0): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <span>
        <?php
        if ($msg === 'errore_numero')   echo "Numero carta non valido (16 cifre richieste).";
        if ($msg === 'errore_scadenza') echo "Scadenza non valida (formato MM/AA).";
        if ($msg === 'errore_cvv')      echo "CVV non valido (minimo 3 cifre).";
        ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- LISTA CARTE SALVATE -->
    <h6 class="form-label mb-3"><i class="bi bi-wallet-fill me-1"></i> Le mie carte</h6>

    <?php if ($ha_carte): ?>
        <?php foreach ($carte as $c): ?>
        <div class="carta-preview mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="carta-numero">•••• •••• •••• <?= substr(htmlspecialchars($c['NUMERO']), -4) ?></div>
                    <div style="font-size:0.75rem; opacity:0.8; margin-top:0.3rem;">
                        <i class="bi bi-calendar3 me-1"></i>Scad. <?= htmlspecialchars($c['SCADENZA']) ?>
                    </div>
                </div>
                <form method="POST" onsubmit="return confirm('Rimuovere questa carta?');" class="m-0">
                    <input type="hidden" name="elimina_carta" value="<?= htmlspecialchars($c['NUMERO']) ?>">
                    <button type="submit" class="btn btn-sm btn-light" title="Rimuovi carta">
                        <i class="bi bi-trash text-danger"></i>
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
    <div class="alert alert-warning mb-4">
        <i class="bi bi-info-circle me-2"></i>
        Nessuna carta salvata. Puoi aggiungerne una qui sotto, oppure inserirla "al volo" direttamente in fase di ricarica.
    </div>
    <?php endif; ?>

    <hr class="my-4">
    <h6 class="form-label mb-3"><i class="bi bi-plus-circle me-1"></i> Aggiungi nuova carta</h6>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Numero carta</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-credit-card"></i></span>
                <input type="text" class="form-control" name="numero" placeholder="1234 5678 9012 3456" maxlength="19" required
                       oninput="this.value=this.value.replace(/\D/g,'').replace(/(.{4})/g,'$1 ').trim()">
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-7">
                <label class="form-label">Scadenza</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                    <input type="text" class="form-control" name="scadenza" placeholder="MM/AA" maxlength="5" required
                           oninput="if(this.value.length===2&&!this.value.includes('/'))this.value+='/'">
                </div>
            </div>
            <div class="col-5">
                <label class="form-label">CVV</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                    <input type="password" class="form-control" name="cvv" placeholder="•••" maxlength="4" required>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-salva w-100 mb-3">
            <i class="bi bi-check2-circle me-2"></i> Salva carta
        </button>
    </form>

    <hr class="my-3">

    <div class="d-flex justify-content-center gap-2 flex-wrap">
        <?php if (isset($_GET['token'])): ?>
        <a href="/gio.fuso/PAY/ricarica.php?token=<?= urlencode($_GET['token']) ?>" class="btn btn-outline-back btn-sm">
            <i class="bi bi-wallet2 me-1"></i> Ricarica
        </a>
        <?php endif; ?>
        <?php $token_dash = $_GET['token'] ?? ''; ?>
        <a href="/gio.fuso/PAY/dashboard.php<?= $token_dash ? '?token='.urlencode($token_dash) : '' ?>" class="btn btn-outline-back btn-sm">
            <i class="bi bi-grid me-1"></i> Dashboard
        </a>
    </div>

</div>
</div>
</div>
</main>

<div class="text-center py-4" style="font-size:0.8rem; color:var(--sft-muted);">
    <i class="bi bi-shield-lock me-1"></i> I tuoi dati sono protetti da PaySteam
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>