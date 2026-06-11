<?php
ob_start();
session_name('PAY_SESSION');
session_start();
include $_SERVER['DOCUMENT_ROOT'] . '/gio.fuso/PAY/connessione.php';

if (!isset($_SESSION['pay_id'])) {
    header("Location: /gio.fuso/PAY/login.php");
    exit();
}
if ($_SESSION['pay_tipo'] !== 'ESERCENTE') {
    header("Location: /gio.fuso/PAY/dashboard.php");
    exit();
}

$id = (int)$_SESSION['pay_id'];

$conto_q = $con->query("SELECT * FROM PAY_CONTO WHERE IDUTENTE = $id");
$conto = ($conto_q && $conto_q->num_rows > 0) ? $conto_q->fetch_assoc() : null;

$kpi_q = $con->query("
    SELECT 
        COUNT(*) as n_tot,
        SUM(CASE WHEN STATO='COMPLETED' THEN IMPORTO ELSE 0 END) as incassato,
        SUM(CASE WHEN STATO='REFUNDED'  THEN IMPORTO ELSE 0 END) as rimborsato,
        SUM(CASE WHEN STATO='PENDING'   THEN 1 ELSE 0 END) as n_pending,
        SUM(CASE WHEN STATO='FAILED'    THEN 1 ELSE 0 END) as n_failed,
        SUM(CASE WHEN STATO='COMPLETED' THEN 1 ELSE 0 END) as n_completed,
        SUM(CASE WHEN STATO='REFUNDED'  THEN 1 ELSE 0 END) as n_refunded
    FROM PAY_TRANSAZIONE
    WHERE IDESERCENTE = $id
");
$kpi = ($kpi_q && $kpi_q->num_rows > 0) ? $kpi_q->fetch_assoc() : [
    'n_tot' => 0,
    'incassato' => 0,
    'rimborsato' => 0,
    'n_pending' => 0,
    'n_failed' => 0,
    'n_completed' => 0,
    'n_refunded' => 0
];

// Tot mov dell'esercente
$tot_mov_q = $con->query("
    SELECT COUNT(*) AS tot
    FROM PAY_TRANSAZIONE
    WHERE IDESERCENTE = $id
");
$tot_mov = ($tot_mov_q && $tot_mov_q->num_rows > 0)
    ? (int)$tot_mov_q->fetch_assoc()['tot']
    : 0;

// FILTRI 
$f_stato = $_GET['stato'] ?? '';
$f_da    = $_GET['da'] ?? '';
$f_a     = $_GET['a'] ?? '';
$f_cons  = trim($_GET['cons'] ?? '');

$where = ["t.IDESERCENTE = $id"];

if (in_array($f_stato, ['COMPLETED', 'PENDING', 'FAILED', 'REFUNDED'], true)) {
    $where[] = "t.STATO = '" . $con->real_escape_string($f_stato) . "'";
}

if ($f_da !== '') {
    $where[] = "DATE(t.DATAORA) >= '" . $con->real_escape_string($f_da) . "'";
}

if ($f_a !== '') {
    $where[] = "DATE(t.DATAORA) <= '" . $con->real_escape_string($f_a) . "'";
}

if ($f_cons !== '') {
    $s = $con->real_escape_string($f_cons);
    $where[] = "(u.NOME LIKE '%$s%' OR t.DESCRIZIONE LIKE '%$s%' OR t.IDTRANSESTERNA LIKE '%$s%')";
}

$sql_where = implode(' AND ', $where);

$sql_mov = "
    SELECT 
        t.*,
        u.NOME AS NOME_CONSUMATORE
    FROM PAY_TRANSAZIONE t
    LEFT JOIN PAY_UTENTE u ON t.IDCONSUMATORE = u.IDUTENTE
    WHERE $sql_where
    ORDER BY t.DATAORA DESC, t.IDTRANSAZIONE DESC
";

$res_mov = $con->query($sql_mov);
$errore_movimenti = '';

if (!$res_mov) {
    $errore_movimenti = $con->error;
    $n_mostrati = 0;
} else {
    $n_mostrati = $res_mov->num_rows;
}

$filtri_attivi = ($f_stato !== '' || $f_da !== '' || $f_a !== '' || $f_cons !== '');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PaySteam – Area Esercente</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; }
        .navbar { background: #0d1f3c !important; }
        .hero-bg { background: linear-gradient(135deg,#1a1a2e 0%,#16213e 60%,#198754 100%); color:#fff; padding: 40px 0 60px; }
        .card-saldo { background: linear-gradient(135deg,#198754,#20c997); color:#fff; border:none; border-radius:16px; margin-top:-40px; }
        .saldo-importo { font-size:2.8rem; font-weight:800; letter-spacing:-1px; }
        .kpi-card { border:none; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,0.07); }
        .kpi-icon { width:48px; height:48px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.3rem; }
        .badge-COMPLETED { background:#d1fae5; color:#065f46; }
        .badge-PENDING   { background:#fef3c7; color:#92400e; }
        .badge-FAILED    { background:#fee2e2; color:#991b1b; }
        .badge-REFUNDED  { background:#fee2e2; color:#991b1b; }
        .importo-entrata { color:#198754; font-weight:600; }
        .importo-uscita  { color:#dc3545; font-weight:600; }
        .section-title { font-weight:700; font-size:1.1rem; color:#1a1a2e; border-left:4px solid #198754; padding-left:.75rem; margin-bottom:1rem; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/gio.fuso/PAY/index.php">
            <i class="bi bi-credit-card-2-front me-1"></i> PaySteam
        </a>
        <div class="ms-auto d-flex align-items-center gap-2">
            <span class="text-white">
                <i class="bi bi-person-badge me-1"></i>
                Ciao, <strong><?= htmlspecialchars($_SESSION['pay_nome']) ?></strong>
                <span class="badge bg-warning text-dark ms-2">Esercente</span>
            </span>
            <a href="/gio.fuso/PAY/logout.php" class="btn btn-danger btn-sm px-3">
                <i class="bi bi-box-arrow-right"></i> Esci
            </a>
        </div>
    </div>
</nav>

<header class="hero-bg">
    <div class="container">
        <h1 class="fw-bold"><i class="bi bi-shop me-2"></i>Area Esercente — <?= htmlspecialchars($_SESSION['pay_nome']) ?></h1>
        <p class="lead mb-0 opacity-75">Monitora i tuoi incassi e le transazioni ricevute</p>
    </div>
</header>

<main class="container pb-5">

    <div class="row g-4 mb-4">
        <div class="col-md-5">
            <div class="card card-saldo shadow-lg p-4">
                <p class="mb-1 opacity-75" style="font-size:.8rem; text-transform:uppercase; letter-spacing:1px;">Saldo conto</p>
                <div class="saldo-importo">€ <?= number_format($conto['SALDO'] ?? 0, 2, ',', '.') ?></div>
                <hr class="border-white opacity-25 my-3">
                <small class="opacity-75"><i class="bi bi-bank me-1"></i> <?= htmlspecialchars($conto['IBAN'] ?? 'N/D') ?></small>
            </div>
        </div>

        <div class="col-md-7 mt-md-4">
            <div class="row g-3">
                <div class="col-6">
                    <div class="card kpi-card p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon bg-success bg-opacity-10"><i class="bi bi-arrow-down-circle text-success"></i></div>
                            <div>
                                <div class="fw-bold fs-5">€ <?= number_format($kpi['incassato'] ?? 0, 2, ',', '.') ?></div>
                                <div class="text-muted" style="font-size:.8rem;">Totale incassato</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6">
                    <div class="card kpi-card p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon bg-danger bg-opacity-10"><i class="bi bi-arrow-up-circle text-danger"></i></div>
                            <div>
                                <div class="fw-bold fs-5 text-danger">€ <?= number_format($kpi['rimborsato'] ?? 0, 2, ',', '.') ?></div>
                                <div class="text-muted" style="font-size:.8rem;">Totale rimborsato</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6">
                    <div class="card kpi-card p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon bg-warning bg-opacity-10"><i class="bi bi-hourglass-split text-warning"></i></div>
                            <div>
                                <div class="fw-bold fs-5"><?= (int)($kpi['n_pending'] ?? 0) ?></div>
                                <div class="text-muted" style="font-size:.8rem;">In attesa</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6">
                    <div class="card kpi-card p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="kpi-icon bg-danger bg-opacity-10"><i class="bi bi-x-circle text-danger"></i></div>
                            <div>
                                <div class="fw-bold fs-5"><?= (int)($kpi['n_failed'] ?? 0) ?></div>
                                <div class="text-muted" style="font-size:.8rem;">Fallite</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($errore_movimenti !== ''): ?>
        <div class="alert alert-danger shadow-sm">
            <strong>Errore query movimenti:</strong> <?= htmlspecialchars($errore_movimenti) ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="section-title">
                <i class="bi bi-clock-history me-1"></i> Movimenti in ingresso e uscita
            </div>

            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-3">
                    <select name="stato" class="form-select form-select-sm">
                        <option value="">— Tutti gli stati —</option>
                        <?php foreach (['COMPLETED'=>'Incassati','PENDING'=>'In attesa','FAILED'=>'Falliti','REFUNDED'=>'Rimborsati'] as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $f_stato === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="da" value="<?= htmlspecialchars($f_da) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <input type="date" name="a" value="<?= htmlspecialchars($f_a) ?>" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <input type="text" name="cons" value="<?= htmlspecialchars($f_cons) ?>" class="form-control form-control-sm" placeholder="Nome / descrizione / ID esterno">
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button class="btn btn-sm btn-primary flex-grow-1">
                        <i class="bi bi-funnel"></i> Filtra
                    </button>
                    <a href="?" class="btn btn-sm btn-outline-secondary" title="Reset">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </form>

            <div class="d-flex justify-content-between align-items-center mb-3 small text-muted">
                <div>
                    Mostrati <strong><?= $n_mostrati ?></strong> movimenti su <strong><?= $tot_mov ?></strong> totali
                    <?php if ($filtri_attivi): ?>
                        <span class="badge bg-primary-subtle text-primary border ms-2">Filtri attivi</span>
                    <?php endif; ?>
                </div>
                <div><span class="badge bg-light text-dark border">Visualizzazione completa</span></div>
            </div>

            <?php if ($n_mostrati === 0): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-inbox display-5 d-block mb-3 opacity-25"></i>
                    <p class="mb-0">Nessuna transazione trovata per questo esercente.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Consumatore</th>
                                <th>Descrizione</th>
                                <th>Importo</th>
                                <th>Stato</th>
                                <th>ID Esterno</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php while ($mov = $res_mov->fetch_assoc()): ?>
                            <?php
                            $stato = $mov['STATO'];

                            if ($stato === 'REFUNDED') {
                                $sg = '-';
                                $ic = 'importo-uscita';
                                $bc = 'badge-REFUNDED';
                                $ls = 'RIMBORSO';
                            } elseif ($stato === 'COMPLETED') {
                                $sg = '+';
                                $ic = 'importo-entrata';
                                $bc = 'badge-COMPLETED';
                                $ls = 'INCASSO';
                            } elseif ($stato === 'PENDING') {
                                $sg = '';
                                $ic = 'text-warning';
                                $bc = 'badge-PENDING';
                                $ls = 'IN ATTESA';
                            } elseif ($stato === 'FAILED') {
                                $sg = '';
                                $ic = 'text-muted';
                                $bc = 'badge-FAILED';
                                $ls = 'FALLITO';
                            } else {
                                $sg = '';
                                $ic = 'text-muted';
                                $bc = 'badge-FAILED';
                                $ls = htmlspecialchars($stato);
                            }

                            $nome_cons = trim($mov['NOME_CONSUMATORE'] ?? '');
                            if ($nome_cons === '') {
                                $nome_cons = '<span class="text-muted fst-italic">— non assegnato —</span>';
                            } else {
                                $nome_cons = htmlspecialchars($nome_cons);
                            }
                            ?>
                            <tr>
                                <td style="font-size:.85rem;"><?= date('d/m/Y H:i', strtotime($mov['DATAORA'])) ?></td>
                                <td><?= $nome_cons ?></td>
                                <td><?= htmlspecialchars($mov['DESCRIZIONE'] ?? '-') ?></td>
                                <td class="<?= $ic ?>"><?= $sg ?> € <?= number_format((float)$mov['IMPORTO'], 2, ',', '.') ?></td>
                                <td><span class="badge rounded-pill <?= $bc ?>"><?= $ls ?></span></td>
                                <td><code style="font-size:.8rem;"><?= htmlspecialchars($mov['IDTRANSESTERNA'] ?? '-') ?></code></td>
                            </tr>
                        <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>