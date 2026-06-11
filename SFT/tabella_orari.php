<?php
session_name('SFT_SESSION'); session_start();
date_default_timezone_set('Europe/Rome');
include('connessione.php');
include('aggiorna_stato.php');

$ruolo = $_SESSION['sft_ruolo'] ?? '';
$nome  = $_SESSION['sft_nome'] ?? '';
$idUtente = $_SESSION['sft_id'] ?? null;
$data = $_GET['data'] ?? date('Y-m-d');

//Stazioni ordinate per KM progressivo (sempre ASC)
$stazioni = $con->query("
    SELECT * FROM SFT_STAZIONE ORDER BY KMPROGRESSIVO ASC
")->fetch_all(MYSQLI_ASSOC);

//Tutte le corse del giorno
$stmt = $con->prepare("
    SELECT c.IDCORSA, c.ORA, c.DIREZIONE, c.STATO, c.IS_FESTIVO,
           conv.NOME AS NOMECONVOGLIO, c.IDCONVOGLIO,
           c.RITARDI_DETTAGLIO
    FROM SFT_CORSA c
    JOIN SFT_CONVOGLIO conv ON conv.IDCONVOGLIO = c.IDCONVOGLIO
    WHERE c.DATA = ?
      AND c.STATO != 'Annullata'
    ORDER BY c.ORA ASC, c.DIREZIONE ASC
");
$stmt->bind_param("s", $data);
$stmt->execute();
$corse = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function isDopoMezzanotte($ora_corsa, $ora_fermata) {
    if (!$ora_fermata) return false;
    $h_corsa = (int)substr($ora_corsa, 0, 2);
    $h_fermata = (int)substr($ora_fermata, 0, 2);
    if ($h_corsa >= 22 && $h_fermata < 6) {
        return true;
    }
    return false;
}

//fermate_map
$fermate_map = [];
foreach ($corse as $corsa) {
    $id  = $corsa['IDCORSA'];
    $dir = strtoupper(trim((string)$corsa['DIREZIONE']));

    $stmt2 = $con->prepare("
        SELECT f.IDSTAZIONE,
               TIME_FORMAT(f.ORAA, '%H:%i') AS arrivo,
               TIME_FORMAT(f.ORAP, '%H:%i') AS partenza,
               f.PROGRESSIVO
        FROM SFT_FERMATA f
        WHERE f.IDCORSA = ?
        ORDER BY f.PROGRESSIVO ASC
    ");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    $rows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $r) {
        $arr = $r['arrivo'];
        $par = $r['partenza'];

        if ($dir === 'NORD') {
            $temp = $arr;
            $arr = $par;
            $par = $temp;
        }

        $fermate_map[$id][$r['IDSTAZIONE']] = [
            'arrivo' => $arr,
            'partenza' => $par,
            'progressivo' => $r['PROGRESSIVO']
        ];
    }
}

// Array degli ID corse attive del giorno
$corse_attive_ids = [];
foreach ($corse as $c) {
    $corse_attive_ids[] = (int)$c['IDCORSA'];
}

// Mappa ritardi con eslc. tamponamenti con corse eliminate
$ritardi_map = [];
foreach ($corse as $corsa) {
    if (!empty($corsa['RITARDI_DETTAGLIO'])) {
        $decoded = json_decode($corsa['RITARDI_DETTAGLIO'], true);
        if ($decoded && is_array($decoded)) {
            $ritardi_filtrati = [];
            foreach ($decoded as $stazione_id => $rit) {
                if (isset($rit['tipo']) && $rit['tipo'] === 'tamponamento') {
                    $corsa_causa = isset($rit['corsa_causa']) ? (int)$rit['corsa_causa'] : 0;
                    if (!in_array($corsa_causa, $corse_attive_ids, true)) {
                        continue;
                    }
                }
                $ritardi_filtrati[$stazione_id] = $rit;
            }
            if (!empty($ritardi_filtrati)) {
                $ritardi_map[$corsa['IDCORSA']] = $ritardi_filtrati;
            }
        }
    }
}

//Incroci
$incroci = [];
for ($i = 0; $i < count($corse); $i++) {
    for ($j = $i + 1; $j < count($corse); $j++) {
        $ca = $corse[$i];
        $cb = $corse[$j];

        if ((int)$ca['IDCONVOGLIO'] === (int)$cb['IDCONVOGLIO']) continue;

        $dir_a = strtoupper(trim((string)$ca['DIREZIONE']));
        $dir_b = strtoupper(trim((string)$cb['DIREZIONE']));
        if ($dir_a === $dir_b) continue;

        foreach ($stazioni as $s) {
            $sid = $s['IDSTAZIONE'];
            if (!isset($fermate_map[$ca['IDCORSA']][$sid])) continue;
            if (!isset($fermate_map[$cb['IDCORSA']][$sid])) continue;

            $fa = $fermate_map[$ca['IDCORSA']][$sid];
            $fb = $fermate_map[$cb['IDCORSA']][$sid];

            $arrA = $fa['arrivo'] ?: $fa['partenza'];
            $parA = $fa['partenza'] ?: $fa['arrivo'];
            $arrB = $fb['arrivo'] ?: $fb['partenza'];
            $parB = $fb['partenza'] ?: $fb['arrivo'];

            if ($arrA <= $parB && $arrB <= $parA) {
                $incroci[] = [
                    'corsa_a' => $ca['IDCORSA'], 'nome_a' => $ca['NOMECONVOGLIO'],
                    'corsa_b' => $cb['IDCORSA'], 'nome_b' => $cb['NOMECONVOGLIO'],
                    'stazione' => $sid, 'nome_s' => $s['NOME'], 'ora' => $parA
                ];
            }
        }
    }
}

$celle_incrocio = [];
foreach ($incroci as $inc) {
    $celle_incrocio["{$inc['corsa_a']}-{$inc['stazione']}"] = true;
    $celle_incrocio["{$inc['corsa_b']}-{$inc['stazione']}"] = true;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Tabellone Orari <?php echo $data; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="stile.css">
    <style>
        body { font-size: 0.82rem; background: linear-gradient(135deg, #f5f7fa 0%, #e4ecf7 100%) !important; }
        .tabellone { border-collapse: separate; border-spacing: 0; width: 100%; font-family: 'Inter', 'Courier New', monospace; background: #fff; border-radius: 12px; overflow: hidden; }
        .tabellone th, .tabellone td { border-bottom: 1px solid #e6ebf2; border-right: 1px solid #eef1f6; text-align: center; padding: 6px 10px; white-space: nowrap; transition: background 0.15s ease; }
        .tabellone thead tr.row-convoglio th { background: linear-gradient(180deg, #1a3a6b 0%, #0d2b55 100%); color: white; padding: 10px 8px; position: sticky; top: 0; z-index: 3; }
        .tabellone thead tr.row-direzione th { background: #2c5ea8; color: #e7f0ff; font-size: 0.72rem; font-weight: 500; position: sticky; top: 54px; z-index: 2; }
        .badge-corsa-id { display: inline-block; background: #ffc107; color: #1a1a1a; font-weight: 800; font-size: 0.72rem; padding: 2px 8px; border-radius: 10px; margin-bottom: 4px; letter-spacing: 0.5px; box-shadow: 0 2px 4px rgba(0,0,0,0.15); border: 1px solid #e0a800; }
        .badge-stato {
            display: inline-block;
            font-size: 0.62rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            margin-top: 3px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        .badge-stato.in-corso {
            background: #28a745;
            color: #fff;
            box-shadow: 0 0 6px rgba(40,167,69,0.6);
            animation: pulse-live 1.8s infinite;
        }
        .badge-stato.conclusa {
            background: #6c757d;
            color: #fff;
            opacity: 0.85;
        }
        @keyframes pulse-live {
            0%,100% { box-shadow: 0 0 6px rgba(40,167,69,0.6); }
            50%     { box-shadow: 0 0 12px rgba(40,167,69,1); }
        }
        /* Colonna intestazione sbiadita se la corsa e' conclusa */
        .tabellone thead th.th-conclusa {
            background: linear-gradient(180deg, #55616e 0%, #3f4853 100%) !important;
            opacity: 0.85;
        }

        .col-stazione { text-align: left !important; font-weight: 600; background: #f0f4ff; min-width: 140px; position: sticky; left: 48px; z-index: 1; color: #1a3a6b; }
        .col-km { background: #eef2f9; color: #6c7a93; font-size: 0.72rem; font-weight: 600; min-width: 48px; position: sticky; left: 0; z-index: 1; }
        .stazione-capolinea .col-stazione { text-transform: uppercase; color: #fff; background: linear-gradient(90deg, #1a3a6b, #2c5ea8); font-size: 0.85rem; font-weight: 700; letter-spacing: 0.5px; }
        .stazione-capolinea .col-km { background: #1a3a6b; color: #ffc107; font-weight: 700; }
        .cella-orario { min-width: 64px; }
        .ora-arrivo { color: #8a95a8; font-size: 0.7rem; display: block; font-weight: 500; }
        .ora-partenza { color: #0d2b55; font-weight: 700; font-size: 0.9rem; display: block; }
        .incrocio-cella { background: linear-gradient(135deg, #fff8dc 0%, #ffe97a 100%) !important; box-shadow: inset 0 0 0 2px #ffc107; }
        .incrocio-cella .ora-partenza { color: #7a5d00; }
        .tabellone tbody tr:hover td { background: #eaf2ff !important; }
        .tabellone tbody tr:hover .col-stazione { background: #d6e4ff !important; }
        .badge-ritardo-admin { display: inline-block; background: linear-gradient(135deg, #dc3545, #a71d2a); color: white; font-size: 0.63rem; font-weight: 700; padding: 2px 6px; border-radius: 4px; margin: 2px 0; box-shadow: 0 1px 3px rgba(220,53,69,0.3); }
        .badge-ritardo-admin small { display: block; font-weight: 500; opacity: 0.9; font-size: 0.9em; }
        .table-responsive::-webkit-scrollbar { height: 10px; width: 10px; }
        .table-responsive::-webkit-scrollbar-track { background: #f1f3f7; }
        .table-responsive::-webkit-scrollbar-thumb { background: #1a3a6b; border-radius: 5px; }
        .table-responsive::-webkit-scrollbar-thumb:hover { background: #2c5ea8; }
        .table-responsive { border-radius: 12px !important; box-shadow: 0 4px 20px rgba(26,58,107,0.08) !important; background: white; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">SFT - Societa Ferroviarie Turistiche</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto align-items-center gap-1">
                <?php if (isset($_SESSION['sft_id'])): ?>
                    <li class="nav-item me-2 text-white d-flex align-items-center">
                        <i class="bi bi-person-badge me-1"></i>
                        Ciao, <strong class="ms-1"><?php echo htmlspecialchars($_SESSION['sft_nome']); ?></strong>
                        <span class="badge bg-warning text-dark ms-2"><?php echo $ruolo ?: 'Registrato'; ?></span>
                    </li>
                    <li class="nav-item"><a href="tabella_orari.php" class="btn btn-outline-light btn-sm px-2" title="Orari"><i class="bi bi-clock"></i></a></li>
                    <li class="nav-item"><a href="info.php" class="btn btn-outline-light btn-sm px-2" title="Info"><i class="bi bi-info-circle"></i></a></li>
                    <?php if ($ruolo === 'Amministrativo'): ?>
                        <li class="nav-item"><a class="btn btn-warning btn-sm fw-bold shadow-sm" href="admin_amministrativo.php"><i class="bi bi-graph-up-arrow"></i> Amministrazione</a></li>
                    <?php elseif ($ruolo === 'Esercizio'): ?>
                        <li class="nav-item"><a class="btn btn-primary btn-sm fw-bold shadow-sm" href="admin_esercizio.php"><i class="bi bi-train-front"></i> Esercizio</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a href="biglietti_riepilogo.php" class="btn btn-outline-info btn-sm text-white border-white"><i class="bi bi-ticket-perforated"></i> I miei Biglietti</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a href="logout.php" class="btn btn-danger btn-sm px-3"><i class="bi bi-box-arrow-right"></i> Esci</a></li>
                <?php else: ?>
                    <li class="nav-item"><a href="tabella_orari.php" class="btn btn-outline-light btn-sm px-2" title="Orari"><i class="bi bi-clock"></i> Orari</a></li>
                    <li class="nav-item"><a href="info.php" class="btn btn-outline-light btn-sm px-2" title="Info"><i class="bi bi-info-circle"></i> Info</a></li>
                    <li class="nav-item"><a href="login.php" class="btn btn-outline-light btn-sm px-3"><i class="bi bi-box-arrow-in-right"></i> Accedi</a></li>
                    <li class="nav-item"><a href="registrazione.php" class="btn btn-primary btn-sm px-3"><i class="bi bi-person-plus"></i> Registrati</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="border-bottom bg-white shadow-sm">
    <div class="container-fluid px-4 py-3 d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="mb-0 fw-bold" style="color:#1a3a6b;">
                <i class="bi bi-table me-2 text-primary"></i>Tabellone Orari
            </h4>
            <small class="text-muted">
                Circolazione del <strong><?php echo date('d/m/Y', strtotime($data)); ?></strong>
                &mdash; <?php echo count($corse); ?> corse in servizio
                <?php
                    // Conteggio stati per il sommario
                    $tot_in_viaggio = 0; $tot_conclusa = 0;
                    foreach ($corse as $cc) {
                        if ($cc['STATO'] === 'In Viaggio') $tot_in_viaggio++;
                        elseif ($cc['STATO'] === 'Conclusa') $tot_conclusa++;
                    }
                    if ($tot_in_viaggio > 0): ?>
                        &mdash; <span class="fw-bold text-success"><i class="bi bi-broadcast"></i> <?php echo $tot_in_viaggio; ?> in corso</span>
                    <?php endif; ?>
                    <?php if ($tot_conclusa > 0): ?>
                        &mdash; <span class="fw-bold text-secondary"><i class="bi bi-check2-circle"></i> <?php echo $tot_conclusa; ?> concluse</span>
                    <?php endif; ?>
                <?php if (!empty($incroci)): ?>
                    &mdash; <span class="fw-bold text-warning">
                        <i class="bi bi-lightning-fill"></i> <?php echo count($incroci); ?> incroci
                    </span>
                <?php endif; ?>
            </small>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <input type="date" id="cambia_data" class="form-control form-control-sm w-auto" value="<?php echo $data; ?>">
            <button class="btn btn-primary btn-sm" onclick="cambia()"><i class="bi bi-arrow-clockwise"></i> Aggiorna</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-house"></i></a>
        </div>
    </div>
</div>

    <?php if (empty($corse)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-2"></i>
            Nessuna corsa programmata per il <strong><?php echo date('d/m/Y', strtotime($data)); ?></strong>.
        </div>
    <?php else: ?>

    <div class="d-flex gap-4 mb-3 align-items-center flex-wrap" style="font-size:0.78rem;">
        <span><i class="bi bi-arrow-right text-primary"></i> Andata</span>
        <span><i class="bi bi-arrow-left text-success"></i> Ritorno</span>
        <span><span class="badge-stato in-corso">In corso</span> Treno in circolazione</span>
        <span><span class="badge-stato conclusa">Conclusa</span> Corsa terminata</span>
        <span style="font-family:monospace;">
            <span style="color:#777; font-size:0.7rem;">09:10</span><br>
            <strong>09:12</strong>
        </span> Arrivo / Partenza
    </div>

   <div class="table-responsive shadow rounded" style="overflow-x: auto; overflow-y: auto; max-height: 72vh;">
    <table class="tabellone">
        <thead>
            <tr class="row-convoglio">
                <th style="background:#1a3a6b;">KM</th>
                <th style="background:#1a3a6b; text-align:left;">Stazione</th>
                <?php foreach ($corse as $c):
                    $stato_c = $c['STATO'];
                    $th_class = ($stato_c === 'Conclusa') ? 'th-conclusa' : '';
                ?>
                <th class="<?php echo $th_class; ?>">
                    <?php if ($ruolo === 'Esercizio'): ?>
                        <div class="badge-corsa-id" title="ID Corsa (riferimento tamponamenti)">
                            #<?php echo $c['IDCORSA']; ?>
                        </div>
                    <?php endif; ?>
                    <div class="fw-bold" style="font-size:0.95rem;">
                        <?php echo htmlspecialchars($c['NOMECONVOGLIO']); ?>
                    </div>
                    <div style="font-size:0.7rem; opacity:0.85;">
                        <i class="bi bi-clock"></i> <?php echo substr($c['ORA'], 0, 5); ?>
                    </div>
                    <?php if ($stato_c === 'In Viaggio'): ?>
                        <div>
                            <span class="badge-stato in-corso" title="Treno attualmente in circolazione">
                                <i class="bi bi-broadcast"></i> In corso
                            </span>
                        </div>
                    <?php elseif ($stato_c === 'Conclusa'): ?>
                        <div>
                            <span class="badge-stato conclusa" title="Corsa terminata">
                                <i class="bi bi-check2-circle"></i> Conclusa
                            </span>
                        </div>
                    <?php endif; ?>
                </th>
                <?php endforeach; ?>
            </tr>
            <tr class="row-direzione">
                <th></th>
                <th></th>
                <?php foreach ($corse as $c): ?>
                <th>
                    <?php if ($c['DIREZIONE'] === 'Sud'): ?>
                        <i class="bi bi-arrow-right"></i> Andata
                    <?php else: ?>
                        <i class="bi bi-arrow-left"></i> Ritorno
                    <?php endif; ?>
                    <?php if ($c['IS_FESTIVO']): ?>
                        <i class="bi bi-balloon-fill text-warning" title="Festivo"></i>
                    <?php endif; ?>
                </th>
                <?php endforeach; ?>
            </tr>
        </thead>

        <tbody>
        <?php
        $prima  = $stazioni[0]['IDSTAZIONE'];
        $ultima = end($stazioni)['IDSTAZIONE'];

        foreach ($stazioni as $s):
            $sid = $s['IDSTAZIONE'];
            $isCapolinea = ($sid == $prima || $sid == $ultima);

            $has_incrocio = false;
            foreach ($incroci as $inc) {
                if ($inc['stazione'] == $sid) { $has_incrocio = true; break; }
            }
            $sosta_minuti = 0;
        ?>
        <tr class="<?php echo $isCapolinea ? 'stazione-capolinea' : ''; ?>">
            <td class="col-km"><?php echo $s['KMPROGRESSIVO']; ?></td>
            <td class="col-stazione">
                <?php echo htmlspecialchars($s['NOME']); ?>
                <?php if ($has_incrocio): ?>
                    <i class="bi bi-lightning-fill text-warning ms-1" title="Stazione di incrocio"></i>
                <?php endif; ?>
            </td>
            <?php foreach ($corse as $c):
                $cid = $c['IDCORSA'];
                $fermata = $fermate_map[$cid][$sid] ?? null;
                $key = "{$cid}-{$sid}";
                $highlight = isset($celle_incrocio[$key]) ? 'incrocio-cella' : '';
                $sosta_minuti = 0;
                if ($fermata) {
                    $arr = $fermata['arrivo'];
                    $par = $fermata['partenza'];
                    if ($arr && $par && $arr !== $par) {
                        $ts_arr = strtotime("1970-01-01 $arr");
                        $ts_par = strtotime("1970-01-01 $par");
                        $diff_sec = $ts_par - $ts_arr;
                        $sosta_minuti = ceil($diff_sec / 60);
                    }
                }
            ?>
            <td class="cella-orario <?php echo $highlight; ?>" style="<?php echo ($c['STATO'] === 'Conclusa') ? 'opacity:0.55;' : ''; ?>">
            <?php if ($fermata): ?>
                <?php
                    $arr = $fermata['arrivo'];
                    $par = $fermata['partenza'];
                ?>
                <?php if (!empty($arr) && !empty($par) && $arr !== $par): ?>
                    <span class="ora-arrivo"><?php echo $arr; ?></span>
                    <?php
                    $ha_ritardo = isset($ritardi_map[$cid][$sid]);
                    if ($sosta_minuti > 2 || $ha_ritardo):
                    ?>
                        <?php if ($ruolo === 'Esercizio' && $ha_ritardo):
                        $rit = $ritardi_map[$cid][$sid]; ?>
                        <span class="badge-ritardo-admin">
                            <i class="bi bi-exclamation-triangle-fill"></i> +<?php echo $rit['minuti']; ?> min
                            <small><?php echo $rit['tipo']; ?> &rarr; #<?php echo $rit['corsa_causa']; ?></small>
                        </span>
                        <?php elseif ($ha_ritardo):
                            $rit = $ritardi_map[$cid][$sid]; ?>
                            <span class="d-block text-warning" style="font-size:0.6rem;">
                                +<?php echo $rit['minuti']; ?> min
                            </span>
                        <?php elseif ($sosta_minuti > 2): ?>
                            <span class="d-block text-success" style="font-size:0.6rem;">
                                +<?php echo $sosta_minuti; ?> min
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>
                    <span class="ora-partenza"><?php echo $par; ?></span>

                <?php elseif (empty($arr) && !empty($par)): ?>
                    <span class="ora-partenza"><?php echo $par; ?></span>
                <?php elseif (!empty($arr) && empty($par)): ?>
                    <span class="ora-partenza"><?php echo $arr; ?></span>
                <?php elseif (!empty($arr) && !empty($par) && $arr === $par): ?>
                    <span class="ora-partenza"><?php echo $par; ?></span>
                <?php else: ?>
                    <span class="text-muted">-</span>
                <?php endif; ?>

            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <?php if (!empty($incroci)): ?>
    <div class="mt-4">
        <h6 class="fw-bold">
            <i class="bi bi-lightning-fill text-warning"></i>
            Riepilogo Incroci (<?php echo count($incroci); ?>)
        </h6>
        <table class="table table-sm table-bordered bg-white shadow-sm" style="width:auto;">
            <thead class="table-warning">
                <tr>
                    <th>Stazione</th>
                    <th>Corsa A (Andata)</th>
                    <th>Corsa B (Ritorno)</th>
                    <th>Ora incrocio</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($incroci as $inc): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($inc['nome_s']); ?></strong></td>
                    <td><?php echo htmlspecialchars($inc['nome_a']); ?></td>
                    <td><?php echo htmlspecialchars($inc['nome_b']); ?></td>
                    <td><span class="badge bg-warning text-dark"><?php echo $inc['ora']; ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<footer class="bg-dark text-white py-3 mt-5">
    <div class="container text-center">
        <p class="mb-0">(c) 2026 SFT - Societa Ferroviaria Turistica</p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function cambia() {
    var d = document.getElementById('cambia_data').value;
    if (d) window.location.href = 'tabella_orari.php?data=' + d;
}
// Auto-refresh ogni 60s se stai guardando la data di oggi 
<?php if ($data === date('Y-m-d')): ?>
setTimeout(function() { window.location.reload(); }, 60000);
<?php endif; ?>
</script>
</body>
</html>
