<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');
include('controllo_orario.php'); 

if (!isset($_SESSION['sft_id'])) {
    header("Location: login.php");
    exit();
}

$files = glob(__DIR__ . '/cache/*.json');
foreach ($files as $file) {
    if (is_file($file)) unlink($file);
}


$is_estensione      = isset($_GET['estensione']) && $_GET['estensione'] == 1;
$posto_preesistente = $is_estensione ? (int)($_GET['posto'] ?? 0) : 0;

$id_corsa         = (int)($_GET['idcorsa'] ?? 0);
$prezzo           = (float)($_GET['prezzo'] ?? 0);
$partenza         = $con->real_escape_string($_GET['partenza'] ?? '');
$arrivo           = $con->real_escape_string($_GET['arrivo'] ?? '');
$id_biglietto_old = isset($_GET['idbiglietto']) ? (int)$_GET['idbiglietto'] : 0;
$modifica_id      = isset($_GET['modifica']) ? (int)$_GET['modifica'] : 0;
$is_cambio_posto  = ($modifica_id > 0);

$posto_attuale     = 0;
$matricola_attuale = '';
if ($is_cambio_posto) {
    $uid = (int)$_SESSION['sft_id'];
    $sql_b = "SELECT B.IDPOSTO, B.MATRICOLAMATERIALE,
                     S1.NOME AS NOME_P, S2.NOME AS NOME_A
              FROM SFT_BIGLIETTO B
              JOIN SFT_STAZIONE S1 ON S1.IDSTAZIONE = B.IDSTAZIONEP
              JOIN SFT_STAZIONE S2 ON S2.IDSTAZIONE = B.IDSTAZIONEA
              WHERE B.IDBIGLIETTO = $modifica_id AND B.CODUTENTE = $uid";
    $r_b = $con->query($sql_b);
    if ($r_b && $r_b->num_rows === 1) {
        $row_b = $r_b->fetch_assoc();
        if ($partenza === '') $partenza = $con->real_escape_string($row_b['NOME_P']);
        if ($arrivo   === '') $arrivo   = $con->real_escape_string($row_b['NOME_A']);
        $posto_attuale     = (int)$row_b['IDPOSTO'];
        $matricola_attuale = $row_b['MATRICOLAMATERIALE'];
        if ($id_biglietto_old === 0) $id_biglietto_old = $modifica_id;
    } else {
        header("Location: biglietti_riepilogo.php");
        exit();
    }
}
$stazione_check = $partenza;
blocca_se_chiusa($con, $id_corsa, $stazione_check, 'index.php?reload=1', 15);

 //POSTI DEL CONVOGLIO raggruppati per carrozza
$carrozze = [];
$sql_posti_reali = "SELECT P.IDPOSTO, P.MATRICOLAMATERIALE, MR.NOME AS NOME_CARROZZA, MR.TIPO
    FROM SFT_POSTO P
    JOIN SFT_MATERIALEROTABILE MR ON P.MATRICOLAMATERIALE = MR.MATRICOLA
    JOIN SFT_COMPOSTO COMP        ON P.MATRICOLAMATERIALE = COMP.MATRICOLAMAT
    JOIN SFT_CORSA C              ON C.IDCONVOGLIO        = COMP.IDCONVOGLIO
    WHERE C.IDCORSA = $id_corsa
      AND MR.CAPACITA_POSTO > 0
    ORDER BY P.MATRICOLAMATERIALE, P.IDPOSTO ASC";
$res_reali = $con->query($sql_posti_reali);
if ($res_reali) {
    while ($row = $res_reali->fetch_assoc()) {
        $mat = $row['MATRICOLAMATERIALE'];
        if (!isset($carrozze[$mat])) {
            $carrozze[$mat] = [
                'nome'  => $row['NOME_CARROZZA'],
                'tipo'  => $row['TIPO'],
                'posti' => []
            ];
        }
        $carrozze[$mat]['posti'][] = $row['IDPOSTO'];
    }
}

$prog_p = 0; $prog_a = 0;
if ($partenza !== '' && $arrivo !== '') {
    $sql_prog_p = "SELECT F.PROGRESSIVO FROM SFT_FERMATA F
                   JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE
                   WHERE F.IDCORSA = $id_corsa AND S.NOME = '$partenza'";
    $sql_prog_a = "SELECT F.PROGRESSIVO FROM SFT_FERMATA F
                   JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE
                   WHERE F.IDCORSA = $id_corsa AND S.NOME = '$arrivo'";
    $res_p = $con->query($sql_prog_p);
    $res_a = $con->query($sql_prog_a);
    if ($res_p && $res_p->num_rows > 0) $prog_p = (int)$res_p->fetch_assoc()['PROGRESSIVO'];
    if ($res_a && $res_a->num_rows > 0) $prog_a = (int)$res_a->fetch_assoc()['PROGRESSIVO'];
}
$min_new = min($prog_p, $prog_a);
$max_new = max($prog_p, $prog_a);

  //POSTI OCCUPATI: solo prenotazioni con tratta SOVRAPPOSTA
$posti_occupati = [];
if ($min_new > 0 && $max_new > 0 && $min_new !== $max_new) {
    $sql_posti = "SELECT B.IDBIGLIETTO, B.IDPOSTO, B.MATRICOLAMATERIALE, B.CODUTENTE,
                         FP.PROGRESSIVO AS PROG_P, FA.PROGRESSIVO AS PROG_A
                  FROM SFT_BIGLIETTO B
                  JOIN SFT_FERMATA FP ON FP.IDCORSA = B.IDCORSA AND FP.IDSTAZIONE = B.IDSTAZIONEP
                  JOIN SFT_FERMATA FA ON FA.IDCORSA = B.IDCORSA AND FA.IDSTAZIONE = B.IDSTAZIONEA
                  WHERE B.IDCORSA = $id_corsa";
    $res_posti = $con->query($sql_posti);
    if ($res_posti) {
        while ($row = $res_posti->fetch_assoc()) {
            if ($is_cambio_posto && (int)$row['IDBIGLIETTO'] === $modifica_id) continue;
            if ($is_estensione
                && (int)$row['CODUTENTE'] == $_SESSION['sft_id']
                && (int)$row['IDPOSTO']   === $posto_preesistente) continue;

            $min_old = min((int)$row['PROG_P'], (int)$row['PROG_A']);
            $max_old = max((int)$row['PROG_P'], (int)$row['PROG_A']);
            $sovrappone = ($max_new > $min_old && $min_new < $max_old);
            if ($sovrappone) {
                $posti_occupati[$row['IDPOSTO'] . '_' . $row['MATRICOLAMATERIALE']] = true;
            }
        }
    }
}

$sql  = "SELECT C.*, CO.NOME AS NOME_TRENO,
                COALESCE(FP.ORAP, C.ORA) AS ORA_STAZIONE_P
         FROM SFT_CORSA C
         JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
         LEFT JOIN SFT_STAZIONE SP ON SP.NOME = '$partenza'
         LEFT JOIN SFT_FERMATA  FP ON FP.IDCORSA = C.IDCORSA AND FP.IDSTAZIONE = SP.IDSTAZIONE
         WHERE C.IDCORSA = $id_corsa";
$res  = $con->query($sql);
$dati = $res->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - <?= $is_cambio_posto ? 'Cambio Posto' : 'Conferma e Scelta posto' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
</head>
<body class="bg-light">

<?php $ruolo = $_SESSION['sft_ruolo'] ?? ''; ?>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">SFT - Società Ferroviarie Turistiche</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center gap-1">
                <?php if (isset($_SESSION['sft_id'])): ?>
                    <li class="nav-item me-2 text-white d-flex align-items-center">
                        <i class="bi bi-person-badge me-1"></i>
                        Ciao, <strong class="ms-1"><?= htmlspecialchars($_SESSION['sft_nome']); ?></strong>
                        <span class="badge bg-warning text-dark ms-2"><?= $ruolo ?: 'Registrato'; ?></span>
                    </li>
                    <?php if ($ruolo === 'Amministrativo'): ?>
                        <li class="nav-item"><a class="btn btn-warning btn-sm fw-bold" href="admin_amministrativo.php"><i class="bi bi-graph-up-arrow"></i> Amministrazione</a></li>
                    <?php elseif ($ruolo === 'Esercizio'): ?>
                        <li class="nav-item"><a class="btn btn-primary btn-sm fw-bold" href="admin_esercizio.php"><i class="bi bi-train-front"></i> Esercizio</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a href="biglietti_riepilogo.php" class="btn btn-outline-info btn-sm text-white border-white"><i class="bi bi-ticket-perforated"></i> I miei Biglietti</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a href="index.php" class="btn btn-outline-light btn-sm px-2" title="Home"><i class="bi bi-house"></i></a></li>
                    <li class="nav-item"><a href="logout.php" class="btn btn-danger btn-sm px-3"><i class="bi bi-box-arrow-right"></i> Esci</a></li>
                <?php else: ?>
                    <li class="nav-item"><a href="login.php" class="btn btn-outline-light btn-sm px-3">Accedi</a></li>
                    <li class="nav-item"><a href="registrazione.php" class="btn btn-primary btn-sm px-3">Registrati</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<header class="hero-bg text-center shadow">
    <div class="container">
        <h1 class="display-5 fw-bold">
            <i class="bi bi-<?= $is_cambio_posto ? 'pencil-square' : 'ticket-perforated' ?> me-2"></i>
            <?= $is_cambio_posto ? 'Cambio Posto' : 'Conferma Prenotazione' ?>
        </h1>
        <p class="lead mb-1">
            <strong><?= htmlspecialchars($partenza); ?></strong>
            <i class="bi bi-arrow-right mx-2"></i>
            <strong><?= htmlspecialchars($arrivo); ?></strong>
        </p>
        <p class="mb-0" style="opacity:0.85; font-size:0.92rem;">
            <i class="bi bi-train-front me-1"></i>
            <?= htmlspecialchars($dati['NOME_TRENO']); ?>
            &nbsp;·&nbsp;
            <i class="bi bi-calendar3 me-1"></i>
            <?php
            $giorni = ['Sunday'=>'Domenica','Monday'=>'Lunedì','Tuesday'=>'Martedì',
                       'Wednesday'=>'Mercoledì','Thursday'=>'Giovedì',
                       'Friday'=>'Venerdì','Saturday'=>'Sabato'];
            echo $giorni[date('l', strtotime($dati['DATA']))];
            echo date(" d/m/Y", strtotime($dati['DATA']));
            ?>
            &nbsp;·&nbsp;
            <i class="bi bi-clock me-1"></i>
            Partenza ore <?= substr($dati['ORA_STAZIONE_P'] ?? $dati['ORA'], 0, 5) ?>
        </p>
    </div>
</header>

<main>
    <div class="container py-4" style="max-width: 860px;">
        <?php if ($is_cambio_posto): ?>
            <div class="alert alert-info d-flex align-items-center">
                <i class="bi bi-info-circle-fill me-2 fs-5"></i>
                <div>
                    Stai modificando il posto del biglietto <strong>#<?= $modifica_id ?></strong>.
                    Posto attuale: <strong><?= $posto_attuale ?></strong>. Seleziona un nuovo posto disponibile.
                </div>
            </div>
        <?php endif; ?>

        <form action="api_paysteam.php" method="POST" id="formPagamento">
            <input type="hidden" name="modifica_id" value="<?= $modifica_id ?>">
            <input type="hidden" name="idcorsa"     value="<?= $id_corsa ?>">
            <input type="hidden" name="prezzo"      value="<?= $prezzo ?>">
            <input type="hidden" name="idutente"    value="<?= $_SESSION['sft_id'] ?>">
            <input type="hidden" name="stazione_p"  value="<?= htmlspecialchars($_GET['partenza'] ?? $partenza) ?>">
            <input type="hidden" name="stazione_a"  value="<?= htmlspecialchars($_GET['arrivo']   ?? $arrivo) ?>">
            <input type="hidden" name="estensione"  value="<?= $_GET['estensione'] ?? 0 ?>">
            <input type="hidden" name="idbiglietto" value="<?= $id_biglietto_old ?>">

            <?php if ($is_estensione && $posto_preesistente > 0): ?>
                <input type="hidden" name="posto" value="<?= $posto_preesistente ?>">
                <div class="alert alert-info text-center">
                    <i class="bi bi-arrows-expand me-2"></i>
                    <strong>Estensione biglietto</strong> — La tua copertura diventerà
                    <strong><?= htmlspecialchars($partenza) ?></strong>
                    <i class="bi bi-arrow-right mx-1"></i>
                    <strong><?= htmlspecialchars($arrivo) ?></strong>.
                    Manterrai il <strong>Posto <?= $posto_preesistente ?></strong>.
                </div>
            <?php else: ?>
                <div class="mb-4">
                    <label class="form-label fw-bold d-block text-center mb-4">
                        <?= $is_cambio_posto ? 'Scegli il nuovo posto' : 'Scegli il tuo posto a sedere' ?>
                    </label>

                    <?php foreach ($carrozze as $matricola => $carrozza): ?>
                    <div class="mb-4">
                        <div class="text-center mb-2">
                            <span class="badge bg-secondary px-3 py-2"><?= htmlspecialchars($carrozza['nome']); ?></span>
                        </div>
                        <div class="vagone-container bg-dark p-3 rounded-4 shadow">
                            <div class="d-flex justify-content-between align-items-center mb-3 text-white-50 small fw-bold px-2">
                                <div style="flex:1; text-align:left;"><span title="Finestrino">F</span></div>
                                <div style="flex:0 0 auto; width:80px; text-align:center; font-size:9px; letter-spacing:1px; color:#adb5bd;">CORRIDOIO</div>
                                <div style="flex:1; text-align:right;"><span title="Finestrino">F</span></div>
                            </div>

                            <?php
                            $posti  = $carrozza['posti'];
                            $totale = count($posti);
                            $file   = ceil($totale / 4);
                            for ($f = 0; $f < $file; $f++):
                                $base = $f * 4;
                            ?>
                            <div class="d-flex justify-content-between mb-2">
                                <div class="d-flex gap-2">
                                    <?php for ($p = 1; $p <= 2; $p++):
                                        $idx = $base + $p - 1;
                                        if (!isset($posti[$idx])) continue;
                                        $i = $posti[$idx];
                                        $occupato    = isset($posti_occupati[$i . '_' . $matricola]);
                                        $is_attuale  = ($is_cambio_posto && $i == $posto_attuale && $matricola == $matricola_attuale);
                                        $num_display = $idx + 1;
                                        $btn_class   = $occupato ? 'btn-danger disabled'
                                                       : ($is_attuale ? 'btn-warning' : 'btn-outline-success');
                                    ?>
                                    <div>
                                        <input type="radio" class="btn-check" name="posto"
                                               id="posto<?= $matricola.$i ?>"
                                               value="<?= $i . '|' . $matricola ?>"
                                               required autocomplete="off"
                                               <?= $occupato ? 'disabled' : '' ?>>
                                        <label class="btn <?= $btn_class ?> btn-posto"
                                               for="posto<?= $matricola.$i ?>"
                                               <?= $is_attuale ? 'title="Posto attuale"' : '' ?>>
                                            <?= $num_display ?>
                                        </label>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php for ($p = 3; $p <= 4; $p++):
                                        $idx = $base + $p - 1;
                                        if (!isset($posti[$idx])) continue;
                                        $i = $posti[$idx];
                                        $occupato    = isset($posti_occupati[$i . '_' . $matricola]);
                                        $is_attuale  = ($is_cambio_posto && $i == $posto_attuale && $matricola == $matricola_attuale);
                                        $num_display = $idx + 1;
                                        $btn_class   = $occupato ? 'btn-danger disabled'
                                                       : ($is_attuale ? 'btn-warning' : 'btn-outline-success');
                                    ?>
                                    <div>
                                        <input type="radio" class="btn-check" name="posto"
                                               id="posto<?= $matricola.$i ?>"
                                               value="<?= $i . '|' . $matricola ?>"
                                               required autocomplete="off"
                                               <?= $occupato ? 'disabled' : '' ?>>
                                        <label class="btn <?= $btn_class ?> btn-posto"
                                               for="posto<?= $matricola.$i ?>"
                                               <?= $is_attuale ? 'title="Posto attuale"' : '' ?>>
                                            <?= $num_display ?>
                                        </label>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="text-center mt-3 small text-muted">
                        <span class="me-3"><span class="seat-sample bg-danger"></span> Occupato</span>
                        <span class="me-3"><span class="seat-sample bg-success"></span> Libero</span>
                        <span class="me-3"><span class="seat-sample bg-primary"></span> Selezionato</span>
                        <?php if ($is_cambio_posto): ?>
                            <span><span class="seat-sample bg-warning"></span> Posto attuale</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="alert alert-warning text-center shadow-sm">
                <span class="small d-block text-uppercase">
                    <?= $is_cambio_posto ? 'Cambio posto (gratuito)' : 'Totale da pagare' ?>
                </span>
                <h2 class="mb-0 fw-bold"><?= number_format($prezzo, 2) ?> €</h2>
            </div>

            <button type="submit" class="btn btn-success w-100 py-3 fw-bold shadow mb-3">
                <?= $is_cambio_posto ? 'CONFERMA CAMBIO POSTO' : 'VAI AL PAGAMENTO (PAY STEAM)' ?>
            </button>
        </form>

        <div class="text-center">
            <a href="<?= $is_cambio_posto ? 'biglietti_riepilogo.php' : 'index.php' ?>" class="text-muted small">
                Annulla e torna <?= $is_cambio_posto ? 'ai miei biglietti' : 'alla Home' ?>
            </a>
        </div>
    </div>
</main>

<footer class="bg-dark text-white py-4 mt-5">
    <div class="container text-center">
        <p class="mb-0">&copy; 2026 SFT - Società Ferroviaria Turistica</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    document.querySelectorAll('.btn-check').forEach(radio => {
        radio.addEventListener('change', function () {
            if (this.checked) {
                const btn = document.querySelector('button[type="submit"]');
                const parts = this.value.split('|');
                const isCambio = <?= $is_cambio_posto ? 'true' : 'false' ?>;
                btn.innerHTML = (isCambio ? "CONFERMA POSTO " : "POSTO ") + parts[0] + " - PROCEDI";
                btn.classList.replace('btn-success', 'btn-primary');
            }
        });
    });
</script>
</body>
</html>

