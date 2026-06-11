<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Esercizio') {
    header("Location: index.php?errore=non_autorizzato");
    exit();
}

// aggiorn stati
$con->query("UPDATE SFT_CORSA SET STATO = 'Conclusa' WHERE DATA < CURDATE() AND STATO NOT IN ('Annullata','Conclusa')");
$con->query("UPDATE SFT_CORSA SET STATO = 'In Viaggio' WHERE DATA = CURDATE() AND STATO NOT IN ('Annullata','Conclusa') AND ORA BETWEEN SUBTIME(CURTIME(), '01:00:00') AND ADDTIME(CURTIME(), '00:20:00')");
$con->query("UPDATE SFT_CORSA SET STATO = 'Programmata' WHERE DATA > CURDATE() AND STATO NOT IN ('Annullata')");
$con->query("UPDATE SFT_CORSA SET STATO = 'Conclusa' WHERE DATA = CURDATE() AND STATO NOT IN ('Annullata','Conclusa') AND ORA < SUBTIME(CURTIME(), '01:00:00')");


$sql_corse_attive = "
    SELECT C.IDCORSA, C.DATA, C.ORA, C.STATO, C.DIREZIONE, C.IS_FESTIVO,
           CO.NOME AS TRENO,
           (SELECT SUM(MR.CAPACITA_POSTO) 
            FROM SFT_MATERIALEROTABILE MR
            JOIN SFT_COMPOSTO COMP ON MR.MATRICOLA = COMP.MATRICOLAMAT
            WHERE COMP.IDCONVOGLIO = CO.IDCONVOGLIO) AS POSTI_ATTUALI
    FROM SFT_CORSA C
    JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
    WHERE C.STATO NOT IN ('Conclusa','Annullata')
    ORDER BY C.DATA ASC, C.ORA ASC";
    

$sql_corse_concluse = "
    SELECT C.IDCORSA, C.DATA, C.ORA, C.STATO, C.DIREZIONE, C.IS_FESTIVO, CO.NOME AS TRENO,
           (SELECT SUM(CAPACITA_POSTO) FROM SFT_MATERIALEROTABILE MR
            JOIN SFT_COMPOSTO COMP ON MR.MATRICOLA = COMP.MATRICOLAMAT
            WHERE COMP.IDCONVOGLIO = CO.IDCONVOGLIO) AS POSTI_ATTUALI
    FROM SFT_CORSA C
    JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
    WHERE C.STATO IN ('Conclusa','Annullata')
    ORDER BY C.DATA DESC, C.ORA ASC";

$res_corse_attive   = $con->query($sql_corse_attive);
$res_corse_concluse = $con->query($sql_corse_concluse);

// Salva convogli + conteggio materiale rotabile per disabilitare i vuoti
$res_convogli = $con->query("
    SELECT CO.IDCONVOGLIO, CO.NOME,
           COUNT(COMP.MATRICOLAMAT) AS N_MAT
    FROM SFT_CONVOGLIO CO
    LEFT JOIN SFT_COMPOSTO COMP ON COMP.IDCONVOGLIO = CO.IDCONVOGLIO
    GROUP BY CO.IDCONVOGLIO, CO.NOME
    ORDER BY CO.NOME ASC
");
$lista_convogli = $res_convogli->fetch_all(MYSQLI_ASSOC);

// Messaggi interni
$file_msg  = __DIR__ . '/messaggi_interni.json';
$messaggi  = file_exists($file_msg) ? json_decode(file_get_contents($file_msg), true) : [];
$non_letti = array_filter($messaggi, fn($m) => !$m['letto']);
?>
<!DOCTYPE html>
<html lang="it">
    
<head>
    <meta charset="UTF-8">
    <title>SFT - Gestione Esercizio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/stile.css">
    <style>
    body { font-family: 'Inter', sans-serif; font-size: 1,05 rem; background: #f0f4ff; }

    .table thead tr th {
        background: #1a3a6b;
        color: #ffffff;
        font-size: 1.08 rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-color: #15306b;
        white-space: nowrap;
        padding: 8px 12px;
    }
    .table tbody td {
        border-color: #dee2e6;
        padding: 7px 12px;
        vertical-align: middle;
        background: #ffffff;
    }
    .table thead tr th {
        background: #1a3a6b;
        color: #ffffff;
        font-size: 0.70rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-color: #15306b;
        white-space: nowrap;
        padding: 6px 10px;
    }
   
    .table-hover tbody tr:hover td {
        background: #e8f0fe !important;
    }
    /* ID corsa */
    .table tbody td:first-child {
        font-weight: bold;
        color: #004a99;
        background: #f8f9fa;
        font-family: 'Courier New', monospace;
    }
    /* Badge convoglio */
    .badge-treno {
        background: #1a3a6b;
        color: white;
        font-family: 'Courier New', monospace;
        font-size: 0.78rem;
        padding: 3px 8px;
        border-radius: 3px;
    }
    .badge-ora {
        background: #004a99;
        color: white;
        font-weight: bold;
        font-size: 0.82rem;
        padding: 3px 8px;
        border-radius: 3px;
        font-family: 'Courier New', monospace;
    }
    tr.riga-inviaggio td { background: #fff8e1 !important; }
    tr.riga-annullata td { background: #fff0f0 !important; opacity: 0.75; }

    /* LISTA CONVOGLI  */
    .list-group-item {
        border-left: 3px solid #1a3a6b;
        background: #ffffff;
        font-size: 0.72rem;     
    }
    .list-group-item .fw-bold {
        color: #1a3a6b !important;
        font-family: 'Courier New', monospace;
        font-size: 0.82 rem;
    }
    .list-group-item small.text-muted {
        color: #6c757d !important;
        font-size: 0.70rem !important; 
    }

    /*  TITOLI SEZIONE */
    h4.fw-bold {
        color: #1a3a6b;
        font-size: 1rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* CARD MESSAGGI */
    .card.border-warning {
        border-color: #856404 !important;
    }
    .card-header.bg-warning {
        background: #fff3cd !important;
        color: #856404 !important;
        border-bottom: 1px solid #ffe69c;
    }

    /* TABLE RESPONSIVE*/
    .table-responsive {
        overflow-x: auto;
        border-radius: 6px;
        box-shadow: 0 1px 4px rgba(0,0,0,0.08);
    }
</style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            SFT - Società Ferroviarie Turistiche
            <span class="text-white-50 fw-normal small ms-2 border-start border-secondary ps-2">
                Gestione Esercizio
            </span>
        </a>       
         <div class="d-flex align-items-center gap-2">
            <i class="bi bi-person-badge text-white-50 me-1"></i>
            <span class="text-white-50 small me-2 d-none d-md-inline">
                Ciao, <strong class="text-white"><?php echo htmlspecialchars($_SESSION['sft_nome']); ?></strong>
                <span class="badge bg-primary ms-1">Esercizio</span>
            </span>
            <a href="index.php" class="btn btn-outline-light btn-sm px-2" title="Home">
                <i class="bi bi-house"></i>
            </a>
            <a href="logout.php" class="btn btn-danger btn-sm px-3">
                <i class="bi bi-box-arrow-right"></i> Esci
            </a>
        </div>
    </div>
</nav>


<div class="border-bottom bg-white shadow-sm mb-4">
    </div>
</div>


</div>
<?php if (!empty($non_letti)): ?>
<div class="container mt-3">
    <div class="card border-warning shadow-sm">
        <div class="card-header bg-warning text-dark fw-bold">
            <i class="bi bi-bell-fill"></i> Comunicazioni dal Backoffice Amministrativo
            <span class="badge bg-dark ms-2"><?php echo count($non_letti); ?></span>
        </div>
        <ul class="list-group list-group-flush">
            <?php foreach ($non_letti as $m): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                    <span class="badge bg-warning text-dark me-2"><?php echo $m['tipo']; ?></span>
                    <?php echo htmlspecialchars($m['testo']); ?>
                    <small class="text-muted d-block mt-1"><i class="bi bi-clock"></i> <?php echo $m['data']; ?></small>
                </div>
                <a href="segna_letto.php?id=<?php echo $m['id']; ?>"
                   class="btn btn-sm btn-outline-secondary ms-3 text-nowrap">
                    <i class="bi bi-check2"></i> Letto
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_GET['msg'])): ?>
<div class="container mt-3">
    <div class="alert alert-success alert-dismissible fade show shadow-sm">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($_GET['msg']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<div class="container mb-5">
    <div class="row">


    <!-- TIMELINE VISIVA TRENI OGGI-->
            <?php
            $oggi = date('Y-m-d');
            $r_corse_oggi = $con->query("
                SELECT C.IDCORSA, C.ORA, C.DIREZIONE, C.STATO, CO.NOME AS TRENO,
                    (SELECT S.NOME FROM SFT_FERMATA F 
                        JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE 
                        WHERE F.IDCORSA = C.IDCORSA ORDER BY F.PROGRESSIVO ASC LIMIT 1) AS STAZ_PARTENZA,
                    (SELECT S.NOME FROM SFT_FERMATA F 
                        JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE 
                        WHERE F.IDCORSA = C.IDCORSA ORDER BY F.PROGRESSIVO DESC LIMIT 1) AS STAZ_ARRIVO
                FROM SFT_CORSA C
                JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
                WHERE C.DATA = '$oggi'
                AND C.STATO NOT IN ('Annullata')
                ORDER BY C.ORA ASC
            ");
            $corse_oggi = $r_corse_oggi->fetch_all(MYSQLI_ASSOC);

            if (count($corse_oggi) > 0):
            ?>
            <div class="container mb-4">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center" style="background: linear-gradient(90deg, #0d1f3c 0%, #1a3a6b 100%); color: white;">
                        <span>
                            <i class="bi bi-clock-history me-2"></i>
                            <strong>Timeline Corse Oggi</strong>
                            <span class="badge bg-light text-dark ms-2"><?= count($corse_oggi) ?> corse</span>
                        </span>
                        <a href="diagramma_orario.php?data=<?= $oggi ?>" class="btn btn-sm btn-outline-light">
                            <i class="bi bi-graph-up"></i> Diagramma Completo
                        </a>
                    </div>
                    <div class="card-body p-3">
                        <!-- Timeline -->
                        <div class="position-relative" style="height: 120px; background: #f8fafc; border-radius: 8px; overflow: hidden;">
                            <!-- Ore -->
                            <?php 
                            $ora_min = 6;
                            $ora_max = 22;
                            for ($h = $ora_min; $h <= $ora_max; $h++): 
                                $perc = (($h - $ora_min) / ($ora_max - $ora_min)) * 100;
                            ?>
                            <div style="position: absolute; left: <?= $perc ?>%; top: 0; bottom: 0; width: 1px; background: #e2e8f0;"></div>
                            <div style="position: absolute; left: <?= $perc ?>%; top: 5px; transform: translateX(-50%); font-size: 0.65rem; color: #94a3b8;"><?= $h ?>:00</div>
                            <?php endfor; ?>
                            
                            <!-- Linea tempo attuale -->
                            <?php 
                            $now = date('H:i');
                            $now_h = (int)date('H');
                            $now_m = (int)date('i');
                            if ($now_h >= $ora_min && $now_h <= $ora_max):
                                $perc_now = (($now_h - $ora_min + $now_m/60) / ($ora_max - $ora_min)) * 100;
                            ?>
                            <div style="position: absolute; left: <?= $perc_now ?>%; top: 20px; bottom: 0; width: 2px; background: #ef4444; z-index: 10;">
                                <div style="position: absolute; top: -5px; left: 50%; transform: translateX(-50%); background: #ef4444; color: white; padding: 1px 4px; border-radius: 3px; font-size: 0.6rem; white-space: nowrap;">ORA</div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Treni -->
                            <?php 
                            $colori = ['#e63946', '#2a9d8f', '#e9c46a', '#264653', '#f4a261', '#a855f7'];
                            $row_height = 25;
                            $top_offset = 35;
                            
                            foreach ($corse_oggi as $idx => $corsa):
                                $ora_parts = explode(':', $corsa['ORA']);
                                $h = (int)$ora_parts[0];
                                $m = (int)$ora_parts[1];
                                
                                if ($h < $ora_min || $h > $ora_max) continue;
                                
                                $perc_start = (($h - $ora_min + $m/60) / ($ora_max - $ora_min)) * 100;
                                $durata_min = 85; // ~85 minuti per corsa
                                $perc_width = ($durata_min / 60 / ($ora_max - $ora_min)) * 100;
                                
                                $colore = $colori[$idx % count($colori)];
                                $top = $top_offset + ($idx % 3) * $row_height;
                                
                                // Icona direzione
                                $icon_dir = $corsa['DIREZIONE'] === 'Nord' ? '↑' : '↓';
                            ?>
                            <div style="position: absolute; left: <?= $perc_start ?>%; top: <?= $top ?>px; width: <?= $perc_width ?>%; height: 20px; background: <?= $colore ?>; border-radius: 4px; display: flex; align-items: center; padding: 0 6px; cursor: pointer; z-index: 5; box-shadow: 0 2px 4px rgba(0,0,0,0.15);"
                                title="<?= $corsa['TRENO'] ?> - <?= substr($corsa['ORA'], 0, 5) ?> - <?= $corsa['DIREZIONE'] ?>&#10;<?= $corsa['STAZ_PARTENZA'] ?> → <?= $corsa['STAZ_ARRIVO'] ?>">
                                <span style="color: white; font-size: 0.65rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= $icon_dir ?> <?= $corsa['TRENO'] ?> <small style="opacity:0.8"><?= substr($corsa['ORA'], 0, 5) ?></small>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Mini legenda -->
                        <div class="d-flex flex-wrap gap-3 mt-2">
                            <?php foreach ($corse_oggi as $idx => $c): ?>
                            <div class="d-flex align-items-center gap-1" style="font-size: 0.75rem;">
                                <span style="width: 12px; height: 12px; background: <?= $colori[$idx % count($colori)] ?>; border-radius: 2px;"></span>
                                <strong><?= $c['TRENO'] ?></strong>
                                <span class="text-muted"><?= substr($c['ORA'], 0, 5) ?> <?= $c['DIREZIONE'] === 'Nord' ? '↑N' : '↓S' ?></span>
                                <small class="text-muted">(<?= $c['STAZ_PARTENZA'] ?> → <?= $c['STAZ_ARRIVO'] ?>)</small>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>


        <!--COLONNA CORSE -->
        <div class="col-lg-9">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="fw-bold">
                    <i class="bi bi-calendar2-week me-1" style="color:#004a99;"></i>
                    Programmazione Corse
                    <span class="ms-1 px-2 py-1 rounded" 
                        style="background:#e8f0fe; color:#004a99; font-size:0.75rem; font-weight:700; font-family:'Courier New',monospace; border:1px solid #c0d0f0;">
                        <?php echo $res_corse_attive->num_rows; ?>
                    </span>
                </h4>
                <div class="d-flex gap-2">

                    <!--commento storico
                       <button class="btn btn-sm fw-bold shadow-sm" 
                            style="background:#f0f4ff; color:#1a3a6b; border:1px solid #c0d0f0;"
                            data-bs-toggle="modal" data-bs-target="#modalStorico">
                        <i class="bi bi-archive"></i> Storico
                        <span class="ms-1 px-1" 
                            style="background:#1a3a6b; color:white; font-size:0.68rem; border-radius:3px; font-family:'Courier New',monospace;">
                            <?php echo $res_corse_concluse->num_rows; ?>
                        </span>
                    </button>            
-->
                    <a href="diagramma_orario.php" class="btn btn-sm fw-bold shadow-sm"
                    style="background:#004a99; color:white; border:none;">
                        <i class="bi bi-graph-up"></i> Diagramma
                    </a>

                    <button class="btn btn-sm fw-bold shadow-sm" 
                            style="background:#1a3a6b; color:white; border:none;"
                            data-bs-toggle="modal" data-bs-target="#modalNuovaCorsa">
                        <i class="bi bi-plus-lg"></i> Nuova Corsa
                    </button>
                </div>
            </div>

            <div class="table-responsive bg-white shadow-sm rounded p-3">
                <table class="table table-hover align-middle">
                    <thead class="small text-uppercase" style="background:#1a3a6b; color:white;">
                        <tr>
                            <th>ID</th>
                            <th>Data</th>
                            <th>Orario</th>
                            <th>Convoglio</th>
                            <th>Capacità</th>
                            <th>Stato</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($res_corse_attive->num_rows === 0): ?>
                            <tr><td colspan="7" class="text-center text-muted p-4">
                                <i class="bi bi-calendar-x"></i> Nessuna corsa programmata.
                            </td></tr>
                        <?php else: ?>
                            <?php while ($c = $res_corse_attive->fetch_assoc()): ?>
                            <tr>
                               <td class="fw-bold" style="color:#004a99;">#<?php echo $c['IDCORSA']; ?></td>
                                <td><?php echo date("d/m/Y", strtotime($c['DATA'])); ?></td>
                                <td>
                                    <span class="badge bg-primary"><?php echo substr($c['ORA'], 0, 5); ?></span>
                                    <small class="text-muted d-block"><?php echo $c['DIREZIONE']; ?></small>
                                </td>
                                <td><span class="badge" style="background:#1a3a6b;"><?php echo $c['TRENO']; ?></span></td>
                                <td><?php echo $c['POSTI_ATTUALI'] ?? 0; ?> posti</td>
                                <td>
                                    <?php
                                    $colori = ['Programmata'=>'secondary','In Viaggio'=>'primary','Conclusa'=>'success','Annullata'=>'danger'];
                                    $col = $colori[$c['STATO']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $col; ?>"><?php echo $c['STATO']; ?></span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalModificaOrario"
                                                data-id="<?php echo $c['IDCORSA']; ?>"
                                                data-data="<?php echo $c['DATA']; ?>"
                                                data-ora="<?php echo $c['ORA']; ?>"
                                                title="Modifica orario">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        
                                        
                                        <a href="elimina_corsa.php?id=<?php echo $c['IDCORSA']; ?>"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Elimina corsa">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!--COLONNA CONVOGLI -->
        <div class="col-lg-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="fw-bold mb-0">
                    <i class="bi bi-train-front me-1" style="color:#004a99;"></i>
                    Composizione Treni
                </h4>
                <button class="btn btn-sm fw-bold shadow-sm"
                        style="background:#1a3a6b; color:white; border:none;"
                        data-bs-toggle="modal" data-bs-target="#modalNuovoConvoglio">
                    <i class="bi bi-plus-lg"></i> Nuovo
                </button>
            </div>

            <div class="list-group shadow-sm">
                 <?php foreach ($lista_convogli as $cv): ?>
                    <?php $vuoto = ((int)$cv['N_MAT'] === 0); ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center"
                         <?php if ($vuoto): ?>style="border-left-color:#d97706; background:#fffbeb;"<?php endif; ?>>
                        <div>
                            <div class="fw-bold text-primary">
                                <?php echo htmlspecialchars($cv['NOME']); ?>
                                <?php if ($vuoto): ?>
                                    <span class="badge ms-1" style="background:#d97706; color:white; font-size:0.6rem;">
                                        <i class="bi bi-exclamation-triangle"></i> VUOTO
                                    </span>
                                <?php else: ?>
                                    <span class="badge ms-1" style="background:#059669; color:white; font-size:0.6rem;">
                                        <?php echo $cv['N_MAT']; ?> mat.
                                    </span>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted">ID: <?php echo $cv['IDCONVOGLIO']; ?></small>
                        </div>
                        <a href="composizione.php?id=<?php echo $cv['IDCONVOGLIO']; ?>"
                           class="btn btn-sm fw-bold"
                           style="background:#f0f4ff; color:#1a3a6b; border:1px solid #c0d0f0;">
                            <i class="bi bi-pencil-square"></i> Modifica
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

           <div class="mt-3 p-2 rounded small" style="background:#f0f4ff; border-left:3px solid #1a3a6b; color:#1a3a6b;">
                <i class="bi bi-info-circle me-1"></i> Clicca "Modifica" per aggiungere/rimuovere materiale rotabile o eliminare il convoglio.
            </div>
        </div>

    </div>
</div>

<!--MODAL NUOVA CORSA -->
<div class="modal fade" id="modalNuovaCorsa" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="esercizio.php" method="POST">
            <input type="hidden" name="azione" value="inserisci">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar-plus"></i> Nuova Programmazione Corsa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="small fw-bold">Data Corsa</label>
                <input type="date" name="data" class="form-control mb-3"
                       min="<?php echo date('Y-m-d'); ?>" required>

                <label class="small fw-bold">Orario partenza</label>
                <input type="time" name="ora" class="form-control mb-3" required>

                <label class="small fw-bold">Direzione</label>
                <select name="direzione" class="form-select mb-3" required>
                    <option value="" disabled selected>Seleziona direzione...</option>
                    <option value="Nord">Nord — Villa San Felice → Torre Spaventa</option>
                    <option value="Sud">Sud — Torre Spaventa → Villa San Felice</option>
                </select>

                <label class="small fw-bold">Assegna Convoglio</label>
                <select name="id_convoglio" class="form-select" required>
                    <option value="" disabled selected>Seleziona convoglio...</option>
                    <?php foreach ($lista_convogli as $cv): ?>
                        <?php $vuoto = ((int)$cv['N_MAT'] === 0); ?>
                        <option value="<?php echo $cv['IDCONVOGLIO']; ?>"
                                <?php echo $vuoto ? 'disabled' : ''; ?>>
                            <?php echo htmlspecialchars($cv['NOME']); ?>
                            <?php if ($vuoto): ?>
                                — vuoto, non utilizzabile
                            <?php else: ?>
                                (<?php echo $cv['N_MAT']; ?> rot.)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted d-block mt-1">
                    <i class="bi bi-info-circle"></i>
                    I convogli senza materiale rotabile sono disabilitati.
                </small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Salva nel Database
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL NUOVO CONVOGLIO -->
<div class="modal fade" id="modalNuovoConvoglio" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="esercizio.php" method="POST">
            <input type="hidden" name="azione" value="nuovo_convoglio">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-train-front"></i> Nuovo Convoglio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="small fw-bold">Nome Convoglio</label>
                <input type="text" name="nome_convoglio" class="form-control"
                       placeholder="Es: SFT.4" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-plus-lg"></i> Crea Convoglio
                </button>
            </div>
        </form>
    </div>
</div>

<!--MODAL MODIFICA ORARIO-->

<div class="modal fade" id="modalModificaOrario" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" action="esercizio.php" method="POST">
            <input type="hidden" name="azione" value="modifica_orario">
            <input type="hidden" name="id_corsa" id="input_id_corsa">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Modifica Orario Corsa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="small fw-bold">Nuova Data</label>
                <input type="date" name="nuova_data" id="input_nuova_data"
                       class="form-control mb-3" required>

                <label class="small fw-bold">Nuovo Orario</label>
                <input type="time" name="nuova_ora" class="form-control" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save"></i> Salva Modifica
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL STORICO CORSE -->
<div class="modal fade" id="modalStorico" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-secondary text-white">
                <h5 class="modal-title"><i class="bi bi-archive"></i> Storico Corse Concluse / Annullate</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-secondary small text-uppercase">
                            <tr>
                                <th>ID</th><th>Data</th><th>Orario</th>
                                <th>Convoglio</th><th>Stato</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($res_corse_concluse->num_rows === 0): ?>
                                <tr><td colspan="6" class="text-center text-muted p-4">
                                    Nessuna corsa conclusa o annullata.
                                </td></tr>
                            <?php else: ?>
                                <?php while ($cc = $res_corse_concluse->fetch_assoc()): ?>
                                <tr class="text-muted">
                                    <td class="fw-bold">#<?php echo $cc['IDCORSA']; ?></td>
                                    <td><?php echo date("d/m/Y", strtotime($cc['DATA'])); ?></td>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo substr($cc['ORA'], 0, 5); ?></span>
                                        <small class="d-block"><?php echo $cc['DIREZIONE']; ?></small>
                                    </td>
                                    <td><span class="badge bg-dark"><?php echo $cc['TRENO']; ?></span></td>
                                    <td>
                                        <span class="badge bg-<?php echo $cc['STATO'] === 'Annullata' ? 'danger' : 'success'; ?>">
                                            <?php echo $cc['STATO']; ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <a href="elimina_corsa.php?id=<?php echo $c['IDCORSA']; ?>"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Elimina corsa">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('modalModificaOrario').addEventListener('show.bs.modal', function(e) {
    var btn = e.relatedTarget;
    document.getElementById('input_id_corsa').value   = btn.dataset.id;
    document.getElementById('input_nuova_data').value = btn.dataset.data;
});

// Rimuove il parametro msg dall'URL senza ricaricare la pagina
if (window.location.search.includes('msg=')) {
    window.history.replaceState({}, document.title, window.location.pathname);
}

</script>
</body>
</html>
