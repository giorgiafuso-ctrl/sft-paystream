<?php
ob_start();
session_name('PAY_SESSION'); session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';

if (!isset($_SESSION['pay_id'])) {
    header("Location: /gio.fuso/PAY/login.php");
    exit();
}
if ($_SESSION['pay_tipo'] !== 'CONSUMATORE') {
    header("Location: /gio.fuso/PAY/esercente.php");
    exit();
}

$id = $_SESSION['pay_id'];
$stato_attivo = $_GET['stato'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['elimina_carta'])) {
    $numero_carta = $con->real_escape_string($_POST['numero_carta'] ?? '');

    // dissocia l'utente dalla carta
    $con->query("DELETE FROM PAY_MEMORIZZA WHERE IDUTENTE = $id AND NUMERO = '$numero_carta'");
    //se nessun altro la possiede, elimina la carta
    $con->query("DELETE FROM PAY_CARTA 
                 WHERE NUMERO = '$numero_carta' 
                 AND NOT EXISTS (SELECT 1 FROM PAY_MEMORIZZA WHERE NUMERO = '$numero_carta')");

    $redirect = 'dashboard.php';
    if (!empty($stato_attivo)) {
        $redirect .= '?stato=' . urlencode($stato_attivo) . '#movimenti';
    }
    header("Location: " . $redirect);
    exit();
}

$filtro_stato = $_GET['stato'] ?? '';
$where_stato = '';
if ($filtro_stato !== '') {
    $filtro_stato = $con->real_escape_string($filtro_stato);
    $where_stato = " AND t.STATO = '$filtro_stato' ";
}

$conto    = $con->query("SELECT * FROM PAY_CONTO WHERE IDUTENTE = $id")->fetch_assoc();
$res_carte = $con->query("
    SELECT c.NUMERO, c.SCADENZA, c.CVV
    FROM PAY_CARTA c
    JOIN PAY_MEMORIZZA m ON m.NUMERO = c.NUMERO
    WHERE m.IDUTENTE = $id
");
$res_mov  = $con->query("
    SELECT t.*, u.NOME AS NOME_ESERCENTE
    FROM PAY_TRANSAZIONE t
    JOIN PAY_UTENTE u ON t.IDESERCENTE = u.IDUTENTE
    WHERE t.IDCONSUMATORE = $id
    $where_stato
    ORDER BY t.DATAORA DESC
    LIMIT 20
");
$tot_out  = $con->query("SELECT COUNT(*) as n, SUM(IMPORTO) as tot FROM PAY_TRANSAZIONE WHERE IDCONSUMATORE=$id AND STATO='COMPLETED'")->fetch_assoc();
$n_carte  = $con->query("SELECT COUNT(*) as n FROM PAY_MEMORIZZA WHERE IDUTENTE=$id")->fetch_assoc();
$msg_carta = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aggiungi_carta'])) {
    $numero   = $con->real_escape_string(trim($_POST['numero']));
    $scadenza = $con->real_escape_string(trim($_POST['scadenza']));
    $cvv      = $con->real_escape_string(trim($_POST['cvv']));
    if (empty($numero) || empty($scadenza) || empty($cvv)) {
        $msg_carta = "danger|Compila tutti i campi della carta.";
    } else {
       //se no esiste, inserisci la carta 
        $con->query("INSERT IGNORE INTO PAY_CARTA (NUMERO, SCADENZA, CVV) VALUES ('$numero', '$scadenza', '$cvv')");
        //aggiorna dati esistenti
        $con->query("UPDATE PAY_CARTA SET SCADENZA='$scadenza', CVV='$cvv' WHERE NUMERO='$numero'");
        //associa all'utente
        $sql_assoc = "INSERT IGNORE INTO PAY_MEMORIZZA (NUMERO, IDUTENTE) VALUES ('$numero', $id)";
        if ($con->query($sql_assoc)) {
            if ($con->affected_rows > 0) {
                $msg_carta = "success|Carta aggiunta!";
            } else {
                $msg_carta = "danger|Questa carta è già presente nel tuo wallet.";
            }
            $res_carte = $con->query("
                SELECT c.NUMERO, c.SCADENZA, c.CVV
                FROM PAY_CARTA c
                JOIN PAY_MEMORIZZA m ON m.NUMERO = c.NUMERO
                WHERE m.IDUTENTE = $id
            ");
            $n_carte = $con->query("SELECT COUNT(*) as n FROM PAY_MEMORIZZA WHERE IDUTENTE=$id")->fetch_assoc();
        } else {
            $msg_carta = "danger|Errore durante il salvataggio.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PaySteam – Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sft-blu:       #1a3a6b;
            --sft-blu-scuro: #0d1f3c;
            --sft-azzurro:   #2563eb;
            --sft-accent:    #d4066d;
        }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }
        .navbar { background: var(--sft-blu-scuro) !important; }
        .hero-bg {
            background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 60%, var(--sft-azzurro) 100%);
            color: white;
            padding: 40px 0 60px;
            position: relative;
        }
        .hero-bg::after {
            content: "";
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 3px;
            background: var(--sft-accent);
        }
        .card-saldo {
            background: linear-gradient(135deg, var(--sft-blu-scuro), var(--sft-azzurro));
            color: white;
            border: none;
            border-radius: 16px;
            margin-top: -40px;
        }
        .saldo-importo { font-size: 2.8rem; font-weight: 800; letter-spacing: -1px; }
        .card-carta {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            color: white;
            border-radius: 12px;
            padding: 1.2rem 1.4rem;
            min-height: 110px;
        }
        .section-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--sft-blu-scuro);
            border-left: 4px solid var(--sft-azzurro);
            padding-left: 0.75rem;
            margin-bottom: 1rem;
        }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-pending   { background: #fef3c7; color: #92400e; }
        .badge-failed    { background: #fee2e2; color: #991b1b; }
        .badge-refunded  { background: #d1fae5; color: #065f46; }
        .importo-uscita  { color: #dc3545; font-weight: 600; }
        .importo-entrata { color: #198754; font-weight: 600; }

        .alert-pagamento-return {
            position: relative;
            z-index: 10;
            margin-bottom: 1.5rem;
            padding: 1rem 1.25rem;
            border-radius: 12px;
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            border: 1px solid #7dd3fc;
            box-shadow: 0 4px 12px rgba(14, 165, 233, 0.15);
        }
        .alert-pagamento-return .alert-text {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            line-height: 1.5;
        }
        .alert-pagamento-return .alert-text strong {
            color: var(--sft-blu-scuro);
        }
        @media (max-width: 576px) {
            .alert-pagamento-return {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 0.75rem !important;
                text-align: center;
            }
            .alert-pagamento-return .btn {
                width: 100%;
            }
        }

        /* x no sovrapposizione */
        .content-area-with-alert {
            padding-top: 0;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/gio.fuso/PAY/index.php">
            <i class="bi bi-credit-card-2-front me-1"></i> PaySteam
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <li class="nav-item text-white d-flex align-items-center">
                    <i class="bi bi-person-badge me-1"></i>
                    Ciao, <strong class="ms-1"><?= htmlspecialchars($_SESSION['pay_nome']) ?></strong>
                    <span class="badge bg-info text-dark ms-2">Consumatore</span>
                </li>
                <li class="nav-item">
                    <a href="/gio.fuso/PAY/logout.php" class="btn btn-danger btn-sm px-3">
                        <i class="bi bi-box-arrow-right"></i> Esci
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- HERO -->
<header class="hero-bg">
    <div class="container">
        <h1 class="fw-bold"><i class="bi bi-wallet2 me-2"></i>Benvenuto, <?= htmlspecialchars($_SESSION['pay_nome']) ?></h1>
        <p class="lead mb-0 opacity-75">Gestisci il tuo conto e le tue transazioni</p>
    </div>
</header>

<main class="container pb-5">

    <!-- Alert PRIMA della card saldo -->
    <?php if (!empty($_GET['token'])): ?>
    <div class="alert-pagamento-return d-flex align-items-center justify-content-between flex-wrap gap-2" style="margin-top: -20px;">
        <div class="alert-text">
            <i class="bi bi-arrow-left-circle text-primary fs-5"></i>
            <span><strong>Hai ricaricato il saldo</strong> — vuoi completare il pagamento?</span>
        </div>
        <a href="/gio.fuso/PAY/pay.php?token=<?= urlencode($_GET['token']) ?>"
           class="btn btn-primary btn-sm px-4 fw-semibold">
            <i class="bi bi-credit-card me-1"></i> Torna al pagamento
        </a>
    </div>
    <?php endif; ?>

    <!-- SALDO -->
    <div class="row g-4 mb-4">
        <div class="col-md-5">
            <div class="card card-saldo shadow-lg p-4">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <p class="mb-1 opacity-75" style="font-size:0.8rem; text-transform:uppercase; letter-spacing:1px;">Saldo disponibile</p>
                        <div class="saldo-importo">€ <?= number_format($conto['SALDO'] ?? 0, 2, ',', '.') ?></div>
                    </div>
                    <i class="bi bi-wallet2" style="font-size:2.5rem; opacity:0.4;"></i>
                </div>
                <hr class="border-white opacity-25 my-3">
                <small class="opacity-75"><i class="bi bi-bank me-1"></i> <?= htmlspecialchars($conto['IBAN'] ?? 'N/D') ?></small>
            </div>
        </div>
        <div class="col-md-7 d-flex flex-column gap-3 mt-md-4">
            <div class="card border-0 shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                    <i class="bi bi-arrow-up-circle text-primary fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold fs-5">€ <?= number_format($tot_out['tot'] ?? 0, 2, ',', '.') ?></div>
                    <div class="text-muted" style="font-size:0.82rem;"><?= $tot_out['n'] ?> pagamenti completati</div>
                </div>
            </div>
            <div class="card border-0 shadow-sm p-3 d-flex flex-row align-items-center gap-3">
                <div class="rounded-circle bg-success bg-opacity-10 p-3">
                    <i class="bi bi-credit-card text-success fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold fs-5"><?= $n_carte['n'] ?> carte</div>
                    <div class="text-muted" style="font-size:0.82rem;">salvate nel tuo wallet</div>
                </div>
            </div>
        </div>
    </div>

    <!-- CARTE -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="section-title mb-0"><i class="bi bi-credit-card me-1"></i> Le mie carte</div>
                <button class="btn btn-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#modalCarta">
                    <i class="bi bi-plus-lg me-1"></i> Aggiungi carta
                </button>
            </div>

            <?php if (!empty($msg_carta)):
                [$tipo_msg, $testo_msg] = explode('|', $msg_carta, 2); ?>
                <div class="alert alert-<?= $tipo_msg ?> d-flex align-items-center gap-2 py-2">
                    <i class="bi bi-<?= $tipo_msg === 'success' ? 'check-circle' : 'exclamation-triangle' ?>-fill"></i>
                    <?= htmlspecialchars($testo_msg) ?>
                </div>
            <?php endif; ?>

            <?php if ($res_carte->num_rows === 0): ?>
                <p class="text-muted text-center py-3"><i class="bi bi-credit-card me-1"></i> Nessuna carta salvata.</p>
            <?php else: ?>
                <div class="row g-3">
                  <?php while ($carta = $res_carte->fetch_assoc()): ?>
                    <div class="col-md-4">
                    <div class="card-carta d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start">
                                <div style="width:32px;height:24px;background:linear-gradient(135deg,#f0c040,#c8860a);border-radius:4px;"></div>
                                <span style="font-size:0.75rem; opacity:0.7; text-transform:uppercase; letter-spacing:1px;">
                                    <?= htmlspecialchars($carta['CIRCUITO'] ?? '') ?>
                                </span>
                                <i class="bi bi-wifi opacity-50"></i>
                            </div>

                            <div style="letter-spacing:2px; font-size:0.95rem; margin-bottom:0.75rem;">
                                **** **** **** <?= htmlspecialchars(substr($carta['NUMERO'], -4)) ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-end">
                                <small class="opacity-75">
                                    Scad. <?= htmlspecialchars($carta['SCADENZA'] ?? '') ?>
                                </small>

                                <form method="POST" class="m-0">
                                    <input type="hidden" name="elimina_carta" value="1">
                                    <input type="hidden" name="numero_carta" value="<?= htmlspecialchars($carta['NUMERO']) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm px-2 py-1">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<!-- FILTRI MOVIMENTI -->
<div class="d-flex gap-2 mb-3" id="movimenti">
    <form method="GET" action="#movimenti" class="d-flex gap-2">
        <select name="stato" class="form-select form-select-sm" style="min-width: 150px;">
            <option value="">Tutti gli stati</option>
            <option value="COMPLETED" <?= ($_GET['stato'] ?? '') === 'COMPLETED' ? 'selected' : '' ?>>Completati</option>
            <option value="REFUNDED" <?= ($_GET['stato'] ?? '') === 'REFUNDED' ? 'selected' : '' ?>>Rimborsati</option>
            <option value="FAILED" <?= ($_GET['stato'] ?? '') === 'FAILED' ? 'selected' : '' ?>>Falliti</option>
            <option value="PENDING" <?= ($_GET['stato'] ?? '') === 'PENDING' ? 'selected' : '' ?>>In attesa</option>
        </select>
        <button type="submit" class="btn btn-outline-primary btn-sm">Filtra</button>
        <?php if (!empty($_GET['stato'])): ?>
            <a href="dashboard.php#movimenti" class="btn btn-sm btn-outline-secondary">Reset</a>
        <?php endif; ?>
    </form>
</div>

    <!-- MOVIMENTI -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="section-title"><i class="bi bi-clock-history me-1"></i> Movimenti recenti</div>
            <?php if ($res_mov->num_rows === 0): ?>
                <p class="text-muted text-center py-3"><i class="bi bi-inbox me-1"></i> Nessuna transazione ancora.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Esercente</th>
                                <th>Descrizione</th>
                                <th>Importo</th>
                                <th>Stato</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($mov = $res_mov->fetch_assoc()):
                                $stato = $mov['STATO'];

                                if ($stato === 'REFUNDED') {
                                    $badge_class   = 'badge-refunded';
                                    $importo_sign  = '+';
                                    $importo_class = 'importo-entrata';   
                                    $label_stato   = 'RIMBORSO';
                                } elseif ($stato === 'COMPLETED') {
                                    $badge_class   = 'badge-completed';
                                    $importo_sign  = '-';
                                    $importo_class = 'importo-uscita';   
                                    $label_stato   = 'COMPLETATO';
                                } elseif ($stato === 'PENDING') {
                                    $badge_class   = 'badge-pending';
                                    $importo_sign  = '-';
                                    $importo_class = 'text-warning';
                                    $label_stato   = 'IN ATTESA';
                                } elseif ($stato === 'FAILED') {
                                    $badge_class   = 'badge-failed';
                                    $importo_sign  = '-';
                                    $importo_class = 'text-muted';
                                    $label_stato   = 'FALLITO';
                                } else {
                                    $badge_class   = 'badge-secondary';
                                    $importo_sign  = '';
                                    $importo_class = 'text-muted';
                                    $label_stato   = htmlspecialchars($stato);
                                }
                            ?>
                                <tr>
                                    <td><?= date('d/m/Y H:i', strtotime($mov['DATAORA'])) ?></td>
                                    <td><?= htmlspecialchars($mov['NOME_ESERCENTE']) ?></td>
                                    <td><?= htmlspecialchars($mov['DESCRIZIONE'] ?? '-') ?></td>
                                    <td class="<?= $importo_class ?>">
                                        <?= $importo_sign ?> € <?= number_format($mov['IMPORTO'], 2, ',', '.') ?>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill <?= $badge_class ?>">
                                            <?= $label_stato ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<!-- MODAL AGGIUNGI CARTA -->
<div class="modal fade" id="modalCarta" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--sft-blu-scuro); color:white;">
                <h5 class="modal-title"><i class="bi bi-credit-card me-2"></i>Aggiungi carta</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Numero carta</label>
                        <input type="text" name="numero" class="form-control" placeholder="1234567890123456" maxlength="16" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Scadenza (MM/AA)</label>
                            <input type="text" name="scadenza" class="form-control" placeholder="12/27" maxlength="5" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">CVV</label>
                            <input type="text" name="cvv" class="form-control" placeholder="123" maxlength="3" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" name="aggiungi_carta" class="btn btn-primary fw-semibold">
                        <i class="bi bi-plus-lg me-1"></i> Salva carta
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

