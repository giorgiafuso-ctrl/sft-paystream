<?php
ob_start();
session_name('PAY_SESSION'); session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';

if (!isset($_SESSION['pay_id']) || $_SESSION['pay_tipo'] !== 'CONSUMATORE') {
    header("Location: /gio.fuso/PAY/login.php");
    exit();
}

$id_cons = intval($_SESSION['pay_id']);
$token   = $_GET['token'] ?? '';

$res   = $con->query("SELECT SALDO, IBAN FROM PAY_CONTO WHERE IDUTENTE = $id_cons");
$conto = $res->fetch_assoc();
$saldo = floatval($conto['SALDO'] ?? 0);
$iban  = $conto['IBAN'] ?? '—';

// Carica carte salvate dell'utente
$carte_salvate = [];
$res_carte = $con->query("
    SELECT c.NUMERO, c.SCADENZA
    FROM PAY_CARTA c
    JOIN PAY_MEMORIZZA m ON m.NUMERO = c.NUMERO
    WHERE m.IDUTENTE = $id_cons
    ORDER BY c.NUMERO
");
while ($row = $res_carte->fetch_assoc()) {
    $carte_salvate[] = $row;
}
$ha_carte_salvate = count($carte_salvate) > 0;

$msg = $_SESSION['msg_ricarica'] ?? '';
unset($_SESSION['msg_ricarica']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $importo = floatval($_POST['importo'] ?? 0);
    $modo    = $_POST['modo_carta'] ?? 'nuova'; // 'salvata' | 'nuova'

    $carta_valida = false;
    //validazione carta salvata
    if ($modo === 'salvata') {
        $numero_sel = preg_replace('/\s+/', '', $_POST['carta_salvata'] ?? '');
        $chk = $con->query("SELECT NUMERO FROM PAY_MEMORIZZA 
                            WHERE IDUTENTE = $id_cons 
                            AND NUMERO = '".$con->real_escape_string($numero_sel)."'");
        $carta_valida = ($chk && $chk->num_rows > 0);
    } else {
        // Nuova carta fast
        $numero   = preg_replace('/\s+/', '', $_POST['numero'] ?? '');
        $scadenza = trim($_POST['scadenza'] ?? '');
        $cvv      = trim($_POST['cvv'] ?? '');
        $memorizza = isset($_POST['memorizza_carta']);

        if (strlen($numero) === 16 && is_numeric($numero)
            && preg_match('/^\d{2}\/\d{2}$/', $scadenza)
            && strlen($cvv) >= 3 && is_numeric($cvv)) {
            $carta_valida = true;

             if ($memorizza) {
                $n_s = $con->real_escape_string($numero);
                $s_s = $con->real_escape_string($scadenza);
                $c_s = $con->real_escape_string($cvv);
                //inserisci la carta se nuova
                $con->query("INSERT IGNORE INTO PAY_CARTA (NUMERO, SCADENZA, CVV)
                             VALUES ('$n_s', '$s_s', '$c_s')");
                //aggiorna dati se già esisteva
                $con->query("UPDATE PAY_CARTA SET SCADENZA='$s_s', CVV='$c_s' WHERE NUMERO='$n_s'");
                //associa all'utente
                $con->query("INSERT IGNORE INTO PAY_MEMORIZZA (NUMERO, IDUTENTE)
                             VALUES ('$n_s', $id_cons)");
            }
        } else {
            $_SESSION['msg_ricarica'] = "errore|Dati carta non validi. Controlla numero (16 cifre), scadenza (MM/AA) e CVV.";
            header("Location: /gio.fuso/PAY/ricarica.php" . ($token ? "?token=".urlencode($token) : ''));
            exit();
        }
    }

    if (!$carta_valida) {
        $_SESSION['msg_ricarica'] = "errore|Carta non valida o non selezionata.";
        header("Location: /gio.fuso/PAY/ricarica.php" . ($token ? "?token=".urlencode($token) : ''));
        exit();
    }

    if ($importo >= 1 && $importo <= 500) {
        $con->query("UPDATE PAY_CONTO SET SALDO = SALDO + $importo WHERE IDUTENTE = $id_cons");
        $nuovo_saldo = $saldo + $importo;
        $_SESSION['msg_ricarica'] = "ok|Ricarica effettuata! Nuovo saldo: € " . number_format($nuovo_saldo, 2, ',', '.');
    } else {
        $_SESSION['msg_ricarica'] = "errore|Importo non valido. Inserisci un valore tra €1 e €500.";
    }
    header("Location: /gio.fuso/PAY/ricarica.php" . ($token ? "?token=".urlencode($token) : ''));
    exit();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PaySteam – Ricarica</title>
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
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg,#f0f4f8 0%,#e2e8f0 100%); min-height:100vh; }
        .navbar { background: linear-gradient(90deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%) !important; box-shadow: 0 2px 15px rgba(13,31,60,0.25); }
        .hero-ricarica { background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 60%, var(--sft-azzurro) 100%); color:white; padding:48px 0 90px; position:relative; }
        .hero-ricarica::after { content:""; position:absolute; bottom:0;left:0;right:0; height:45px; background:linear-gradient(135deg,#f0f4f8 0%,#e2e8f0 100%); clip-path: ellipse(55% 100% at 50% 100%); }
        .card-ricarica { border:none; border-radius:18px; box-shadow:0 12px 40px rgba(13,31,60,0.12); margin-top:-55px; overflow:hidden; }
        .saldo-box { background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 50%, var(--sft-accent) 100%); border-radius:16px; color:white; padding:1.8rem; text-align:center; position:relative; overflow:hidden; }
        .saldo-box::before { content:""; position:absolute; top:-40px;right:-40px; width:120px;height:120px; background:rgba(255,255,255,0.08); border-radius:50%; }
        .saldo-valore { font-size:2.8rem; font-weight:800; letter-spacing:-0.02em; text-shadow:0 2px 8px rgba(0,0,0,0.2); }
        .saldo-label { font-size:0.7rem; text-transform:uppercase; letter-spacing:0.15em; opacity:0.75; }
        .form-label { font-size:0.75rem; font-weight:700; color:var(--sft-muted); text-transform:uppercase; letter-spacing:0.08em; }
        .form-control, .form-select { border:2px solid #e2e8f0; border-radius:10px; padding:0.8rem 1rem; font-size:1.05rem; background:#f8fafc; transition:all 0.2s; }
        .form-control:focus, .form-select:focus { border-color:var(--sft-azzurro); box-shadow:0 0 0 4px rgba(37,99,235,0.12); background:white; }
        .input-group-text { background: linear-gradient(135deg,#f0f5ff 0%,#e8f0fe 100%); border:2px solid #e2e8f0; border-right:none; border-radius:10px 0 0 10px; color:var(--sft-azzurro); font-weight:700; }
        .input-group .form-control { border-radius:0 10px 10px 0; }
        .btn-importo { background: linear-gradient(135deg, var(--sft-blu) 0%, var(--sft-azzurro) 100%); color:white; border:none; padding:0.6rem 1.2rem; font-weight:700; font-size:0.9rem; border-radius:10px; box-shadow:0 4px 12px rgba(37,99,235,0.25); transition:all 0.2s; }
        .btn-importo:hover { transform:translateY(-2px); color:white; }
        .btn-ricarica { background: linear-gradient(90deg, var(--sft-verde) 0%, #15803d 100%); border:none; color:white; padding:1rem 1.5rem; font-weight:700; border-radius:12px; font-size:1.1rem; box-shadow:0 6px 20px rgba(22,163,74,0.3); transition:all 0.2s; }
        .btn-ricarica:hover { transform:translateY(-2px); color:white; }
        .btn-torna { background: linear-gradient(90deg, var(--sft-azzurro) 0%, #1d4ed8 100%); border:none; color:white; padding:0.85rem 1.5rem; font-weight:600; border-radius:12px; }
        .btn-torna:hover { color:white; }
        .alert { border:none; border-radius:12px; border-left:4px solid; font-size:0.9rem; }
        .alert-success { background:linear-gradient(90deg,#f0fdf4 0%,#dcfce7 100%); border-left-color:var(--sft-verde); color:#166534; }
        .alert-danger { background:linear-gradient(90deg,#fef2f2 0%,#fee2e2 100%); border-left-color:#dc2626; color:#991b1b; }
        .alert-warning { background:linear-gradient(90deg,#fffbeb 0%,#fef3c7 100%); border-left-color:var(--sft-accent); color:#92400e; }
        .btn-outline-back { border:2px solid var(--sft-bordo); color:var(--sft-muted); border-radius:10px; font-weight:600; }
        .btn-outline-back:hover { background:#f1f5f9; color:var(--sft-blu); }
        .carta-box-nuova { background:#f8fafc; border:2px dashed #cbd5e1; border-radius:12px; padding:1rem; }
        .form-check-input:checked { background-color: var(--sft-azzurro); border-color: var(--sft-azzurro); }
    </style>
</head>
<body>

<nav class="navbar navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/gio.fuso/PAY/index.php"><i class="bi bi-credit-card-2-front me-2"></i>PaySteam</a>
        <a href="/gio.fuso/PAY/dashboard.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>
</nav>

<header class="hero-ricarica text-center">
    <div class="container">
        <i class="bi bi-wallet2 display-4 mb-3 d-block" style="opacity:0.85;"></i>
        <h1 class="display-6 fw-bold mb-2">Ricarica il tuo conto</h1>
        <p class="lead mb-0" style="opacity:0.8;">Aggiungi fondi al tuo portafoglio PaySteam</p>
    </div>
</header>

<main class="container pb-5">
<div class="row justify-content-center">
<div class="col-md-6">
<div class="card card-ricarica p-4">

    <?php if ($msg):
        [$tipo_msg, $testo_msg] = explode('|', $msg, 2);
        $is_ok = $tipo_msg === 'ok';
    ?>
    <div class="alert <?= $is_ok ? 'alert-success' : 'alert-danger' ?> d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-<?= $is_ok ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> fs-5"></i>
        <span><?= htmlspecialchars($testo_msg) ?></span>
    </div>
    <?php endif; ?>

    <div class="saldo-box mb-4">
        <div class="saldo-label mb-1">Saldo attuale</div>
        <div class="saldo-valore">€ <?= number_format($saldo, 2, ',', '.') ?></div>
        <div class="mt-2" style="font-size:0.8rem; opacity:0.7;">
            <i class="bi bi-bank me-1"></i><?= htmlspecialchars($iban) ?>
        </div>
    </div>

    <form method="POST" action="ricarica.php<?= $token ? '?token='.urlencode($token) : '' ?>">

        <!-- IMPORTO -->
        <div class="mb-3">
            <label class="form-label">Importo da ricaricare</label>
            <div class="input-group">
                <span class="input-group-text">€</span>
                <input type="number" name="importo" class="form-control form-control-lg"
                       min="1" max="500" step="0.01" placeholder="0.00" required id="importo-input">
            </div>
            <div class="form-text text-muted mt-1">
                <i class="bi bi-info-circle me-1"></i>Min €1 – Max €500 per ricarica
            </div>
        </div>

        <div class="d-flex gap-2 mb-4 flex-wrap justify-content-center">
            <?php foreach ([10, 20, 50, 100] as $q): ?>
            <button type="button" class="btn btn-importo"
                    onclick="document.getElementById('importo-input').value='<?= $q ?>'">+ €<?= $q ?></button>
            <?php endforeach; ?>
        </div>

        <!-- SELETTORE CARTA -->
        <label class="form-label">Metodo di pagamento</label>

        <?php if ($ha_carte_salvate): ?>
        <div class="mb-3">
            <select name="modo_carta" id="modo-carta" class="form-select" onchange="toggleNuovaCarta(this.value)">
                <optgroup label="Le tue carte salvate">
                    <?php foreach ($carte_salvate as $i => $c): ?>
                    <option value="salvata" data-numero="<?= htmlspecialchars($c['NUMERO']) ?>"
                        <?= $i === 0 ? 'selected' : '' ?>>
                        •••• •••• •••• <?= substr(htmlspecialchars($c['NUMERO']), -4) ?> (scad. <?= htmlspecialchars($c['SCADENZA']) ?>)
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <option value="nuova">+ Usa una nuova carta</option>
            </select>
            <!-- hidden field con il numero della carta salvata selezionata -->
            <input type="hidden" name="carta_salvata" id="carta-salvata-hidden"
                   value="<?= htmlspecialchars($carte_salvate[0]['NUMERO']) ?>">
        </div>
        <?php else: ?>
        <input type="hidden" name="modo_carta" value="nuova">
        <?php endif; ?>

        <!-- BLOCCO NUOVA CARTA -->
        <div id="blocco-nuova-carta" class="carta-box-nuova mb-4" style="<?= $ha_carte_salvate ? 'display:none;' : '' ?>">
            <div class="mb-3">
                <label class="form-label">Numero carta</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-credit-card"></i></span>
                    <input type="text" class="form-control" name="numero"
                           placeholder="1234 5678 9012 3456" maxlength="19"
                           oninput="this.value=this.value.replace(/\D/g,'').replace(/(.{4})/g,'$1 ').trim()">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-7">
                    <label class="form-label">Scadenza</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-calendar3"></i></span>
                        <input type="text" class="form-control" name="scadenza"
                               placeholder="MM/AA" maxlength="5"
                               oninput="if(this.value.length===2&&!this.value.includes('/'))this.value+='/'">
                    </div>
                </div>
                <div class="col-5">
                    <label class="form-label">CVV</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" name="cvv" placeholder="•••" maxlength="4">
                    </div>
                </div>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="memorizza_carta" id="memorizza_carta">
                <label class="form-check-label" for="memorizza_carta">
                    <i class="bi bi-bookmark-star me-1"></i> Memorizza per acquisti futuri
                </label>
                <div class="form-text" style="font-size:0.75rem;">Se lasci deselezionato, la carta verrà usata solo per questa ricarica.</div>
            </div>
        </div>

        <button type="submit" class="btn btn-ricarica w-100 mb-3">
            <i class="bi bi-plus-circle me-2"></i> Ricarica ora
        </button>
    </form>

    <?php if ($token): ?>
    <a href="/gio.fuso/PAY/conferma.php?token=<?= urlencode($token) ?>" class="btn btn-torna w-100">
        <i class="bi bi-arrow-right me-2"></i> Torna al pagamento
    </a>
    <?php endif; ?>

    <hr class="my-4">

    <div class="d-flex justify-content-center gap-2">
        <a href="/gio.fuso/PAY/profilo.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="btn btn-outline-back btn-sm">
            <i class="bi bi-credit-card me-1"></i> Gestisci carte
        </a>
        <a href="/gio.fuso/PAY/dashboard.php<?= $token ? '?token='.urlencode($token) : '' ?>" class="btn btn-outline-back btn-sm">
            <i class="bi bi-grid me-1"></i> Dashboard
        </a>
    </div>

</div>
</div>
</div>
</main>

<div class="text-center py-4" style="font-size:0.8rem; color:var(--sft-muted);">
    <i class="bi bi-shield-lock me-1"></i> Transazione sicura PaySteam
</div>

<script>
function toggleNuovaCarta(val) {
    const blocco = document.getElementById('blocco-nuova-carta');
    const hiddenNumero = document.getElementById('carta-salvata-hidden');
    const inputs = blocco.querySelectorAll('input[name="numero"], input[name="scadenza"], input[name="cvv"]');

    if (val === 'nuova') {
        blocco.style.display = 'block';
        inputs.forEach(i => i.required = true);
    } else {
        blocco.style.display = 'none';
        inputs.forEach(i => i.required = false);
        // Prendi il numero dall'option selezionata
        const sel = document.getElementById('modo-carta');
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.numero) hiddenNumero.value = opt.dataset.numero;
    }
}
// Aggiorna hidden quando cambia carta salvata
document.getElementById('modo-carta')?.addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt && opt.dataset.numero) {
        document.getElementById('carta-salvata-hidden').value = opt.dataset.numero;
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>