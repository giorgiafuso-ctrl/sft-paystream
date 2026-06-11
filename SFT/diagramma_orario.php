<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Esercizio') {
    header("Location: index.php?errore=non_autorizzato");
    exit();
}

$giorni_it = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
$mesi_it = ['', 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 
            'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];

$data_selezionata = $_GET['data'] ?? date('Y-m-d');
$ts_data = strtotime($data_selezionata);
$giorno_nome = $giorni_it[(int)date('w', $ts_data)];
$giorno_num = date('d', $ts_data);
$mese_nome = $mesi_it[(int)date('n', $ts_data)];
$anno = date('Y', $ts_data);
$data_formattata = "$giorno_nome $giorno_num $mese_nome $anno";

// Data del giorno dopo per etichetta mezzanotte
$ts_domani = strtotime('+1 day', $ts_data);
$giorno_domani = date('d', $ts_domani);
$mese_domani = $mesi_it[(int)date('n', $ts_domani)];

// Carica stazioni
$r_stazioni = $con->query("SELECT IDSTAZIONE, NOME, KMPROGRESSIVO FROM SFT_STAZIONE ORDER BY KMPROGRESSIVO ASC");
$stazioni = $r_stazioni->fetch_all(MYSQLI_ASSOC);
$km_min = $stazioni[0]['KMPROGRESSIVO'];
$km_max = $stazioni[count($stazioni)-1]['KMPROGRESSIVO'];

// Carica corse del giorno (INCLUSE quelle che passano mezzanotte)
$sql_corse = "
    SELECT C.IDCORSA, C.ORA, C.DIREZIONE, C.STATO, C.IDCONVOGLIO,
           CO.NOME AS TRENO, C.RITARDI_DETTAGLIO
    FROM SFT_CORSA C
    JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
    WHERE C.DATA = '$data_selezionata'
    AND C.STATO NOT IN ('Annullata')
    ORDER BY C.ORA ASC
";

$r_corse = $con->query($sql_corse);

$corse_data = [];
$passa_mezzanotte = false;

while ($c = $r_corse->fetch_assoc()) {
    $id_corsa = $c['IDCORSA'];
    $r_fermate = $con->query("
        SELECT F.PROGRESSIVO, F.ORAP, F.ORAA, S.NOME, S.KMPROGRESSIVO, S.IDSTAZIONE
        FROM SFT_FERMATA F
        JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE
        WHERE F.IDCORSA = $id_corsa
        ORDER BY F.PROGRESSIVO ASC
    ");
    $fermate_raw = $r_fermate->fetch_all(MYSQLI_ASSOC);
    
    // Determina se la corsa passa mezzanotte
    $ora_partenza_corsa = $c['ORA'];
    $ora_partenza_minuti = (int)substr($ora_partenza_corsa, 0, 2) * 60 + (int)substr($ora_partenza_corsa, 3, 2);
    
    $corsa_passa_mezzanotte = false;
    foreach ($fermate_raw as &$f) {
        $arr_minuti = $f['ORAA'] ? ((int)substr($f['ORAA'], 0, 2) * 60 + (int)substr($f['ORAA'], 3, 2)) : null;
        $par_minuti = $f['ORAP'] ? ((int)substr($f['ORAP'], 0, 2) * 60 + (int)substr($f['ORAP'], 3, 2)) : null;
        
        // Se partenza corsa >= 22:00 e fermata < 06:00 → è dopo mezzanotte
        $f['dopo_mezzanotte'] = false;
        if ($ora_partenza_minuti >= 22 * 60) {
            if (($arr_minuti !== null && $arr_minuti < 6 * 60) || 
                ($par_minuti !== null && $par_minuti < 6 * 60)) {
                $f['dopo_mezzanotte'] = true;
                $corsa_passa_mezzanotte = true;
            }
        }
    }
    unset($f);
    
    if ($corsa_passa_mezzanotte) $passa_mezzanotte = true;
    
    $c['fermate'] = $fermate_raw;
    $c['passa_mezzanotte'] = $corsa_passa_mezzanotte;
    $c['ritardi'] = $c['RITARDI_DETTAGLIO'] ? json_decode($c['RITARDI_DETTAGLIO'], true) : [];
    
    if (count($fermate_raw) >= 2) {
        $corse_data[] = $c;
    }
}

// Calcola range orari
$ora_min = 24;
$ora_max = 0;
foreach ($corse_data as $c) {
    foreach ($c['fermate'] as $f) {
        if (!empty($f['ORAP'])) {
            $h = (int)substr($f['ORAP'], 0, 2);
            if ($f['dopo_mezzanotte']) $h += 24; // Estendi oltre mezzanotte
            $ora_min = min($ora_min, $h);
            $ora_max = max($ora_max, $h + 1);
        }
        if (!empty($f['ORAA'])) {
            $h = (int)substr($f['ORAA'], 0, 2);
            if ($f['dopo_mezzanotte']) $h += 24;
            $ora_min = min($ora_min, $h);
            $ora_max = max($ora_max, $h + 1);
        }
    }
}
if ($ora_min > $ora_max) { $ora_min = 8; $ora_max = 18; }
$ora_min = max(0, $ora_min - 1);
$ora_max = min(30, $ora_max + 1); 

$colori_treni = ['#dc2626', '#059669', '#7c3aed', '#ea580c', '#0284c7', '#db2777', '#65a30d', '#0891b2'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Diagramma Orario</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root { --sft-blu: #1e3a5f; --sft-blu-scuro: #0f172a; --sft-azzurro: #3b82f6; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; min-height: 100vh; }
        .navbar-sft { background: linear-gradient(135deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%); }
        .card-filter { background: white; border: none; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .diagramma-wrapper {background: white; border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); padding: 30px; overflow-x: auto;}
        .grafico-orario { position: relative; min-width: 1100px; height: 520px; border: 2px solid #e2e8f0; border-radius: 12px; background: linear-gradient(180deg, #fefefe 0%, #f8fafc 100%);}
        .griglia-ora { position: absolute;top: 0; bottom: 0; width: 2px; background: #cbd5e1; }
        .griglia-ora.mezz-ora { width: 1px; background: #e2e8f0; }
        .griglia-ora.quarto-ora { width: 1px; background: #f1f5f9; }
        .griglia-ora.mezzanotte {  background: linear-gradient(180deg, #f59e0b, #d97706);  width: 3px;  z-index: 5;}
        .label-ora {  position: absolute;  top: -32px;  transform: translateX(-50%);  font-size: 0.72rem;  font-weight: 700;  color: var(--sft-blu);  background: #f1f5f9;  padding: 3px 8px;border-radius: 5px; border: 1px solid #e2e8f0;}
        .label-ora.mezzanotte-label {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #78350f;
            font-weight: 800;
            font-size: 0.68rem;
            padding: 4px 8px;
        }
        .label-minuti {
            position: absolute;
            top: -18px;
            transform: translateX(-50%);
            font-size: 0.6rem;
            color: #94a3b8;
        }
        
        .linea-stazione { position: absolute; left: 0; right: 0; height: 1px; background: #e2e8f0; }
        .linea-stazione.capolinea { background: var(--sft-blu); height: 3px; }
        
        .label-stazione {
            position: absolute;
            left: -145px;
            width: 140px;
            text-align: right;
            font-size: 0.78rem;
            font-weight: 500;
            color: #475569;
            transform: translateY(-50%);
            padding-right: 8px;
        }
        .label-stazione.capolinea { font-weight: 800; color: var(--sft-blu); font-size: 0.85rem; }
        
        .label-km {
            position: absolute;
            right: -55px;
            font-size: 0.7rem;
            color: #94a3b8;
            transform: translateY(-50%);
            font-family: 'Courier New', monospace;
            background: #f8fafc;
            padding: 2px 6px;
            border-radius: 4px;
        }
        
        .traccia-treno {
            stroke-width: 4;
            fill: none;
            stroke-linecap: round;
            stroke-linejoin: round;
            filter: drop-shadow(0 2px 3px rgba(0,0,0,0.15));
            transition: all 0.2s;
        }
        .traccia-treno:hover { stroke-width: 7; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.25)); }
          /* === SOSTA EVIDENZIATA === */
        .traccia-sosta {
            stroke-width: 10;
            stroke-linecap: round;
            opacity: 1;
            pointer-events: stroke;
            cursor: help;
            transition: stroke-width 0.15s;
        }
        .traccia-sosta:hover { stroke-width: 13; }
        .etichetta-sosta {
            position: absolute;
            transform: translate(-50%, -120%);
            font-size: 0.62rem;
            font-weight: 800;
            color: white;
            padding: 2px 7px;
            border-radius: 10px;
            white-space: nowrap;
            z-index: 25;
            pointer-events: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.25);
            letter-spacing: 0.3px;
        }    
        .punto-fermata {
            position: absolute; width: 10px; height: 10px; border-radius: 50%;
            transform: translate(-50%, -50%); cursor: pointer; z-index: 10;
            border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            transition: all 0.15s;
        }
        .punto-fermata:hover { transform: translate(-50%, -50%) scale(1.4); z-index: 100; }
        
        .tooltip-diagramma {
            position: absolute; background: var(--sft-blu-scuro); color: white;
            padding: 12px 16px; border-radius: 10px; font-size: 0.8rem;
            pointer-events: none; z-index: 1000; display: none; min-width: 180px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        .legenda-container {
            background: white; border-radius: 12px; padding: 14px 18px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;
        }
        .legenda-item {
            display: inline-flex; align-items: center; gap: 8px;
            margin-right: 20px; font-size: 0.82rem; font-weight: 500;
        }
        .legenda-linea { width: 28px; height: 4px; border-radius: 2px; }
        .legenda-sosta { width: 28px; height: 9px; border-radius: 4px; background: #64748b; opacity: 0.9; }
        
        .simbolo-incrocio {
            position: absolute; width: 14px; height: 14px;
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            border: 2px solid white; border-radius: 50%;
            transform: translate(-50%, -50%); z-index: 30; cursor: help;
            box-shadow: 0 2px 6px rgba(245, 158, 11, 0.5);
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 9px; color: #78350f;
        }
        
        .guida-mini {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 14px 20px; font-size: 0.78rem; color: #64748b;
        }
        .guida-mini strong { color: var(--sft-blu); }
        .guida-mini .sep { 
            display: inline-block; width: 1px; height: 12px; background: #cbd5e1; 
            margin: 0 12px; vertical-align: middle;
        }
        .guida-mini i { margin-right: 4px; }
        
        .zona-domani {
            position: absolute; top: 0; bottom: 0;
            background: repeating-linear-gradient(
                -45deg,
                rgba(251, 191, 36, 0.05),
                rgba(251, 191, 36, 0.05) 10px,
                rgba(251, 191, 36, 0.1) 10px,
                rgba(251, 191, 36, 0.1) 20px
            );
            border-left: 3px solid #f59e0b; z-index: 1;
        }
        .label-domani {
            position: absolute; top: 8px; left: 8px;
            background: #fbbf24; color: #78350f;
            padding: 3px 10px; border-radius: 4px;
            font-size: 0.68rem; font-weight: 700; z-index: 2;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-dark navbar-sft py-3">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <a href="admin_esercizio.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i></a>
           <div class="text-white">
                <h1 class="h5 fw-bold mb-0">Diagramma Orario</h1>
                <small class="text-white">Grafico spazio-tempo</small>
            </div>
        </div>
        <a href="tabella_orari.php?data=<?= $data_selezionata ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-table me-1"></i> Tabella
        </a>
    </div>
</nav>

<div class="container py-4">
    
    <!-- FILTRO DATA -->
    <div class="card-filter p-3 mb-4">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-semibold text-muted small mb-1">Data</label>
                <input type="date" name="data" class="form-control" value="<?= $data_selezionata ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i></button>
            </div>
            <div class="col-md-7 text-end">
                <span class="text-muted"><?= $data_formattata ?> — <?= count($corse_data) ?> corse</span>
            </div>
        </form>
    </div>

    <?php if (count($corse_data) > 0): ?>
     <!-- LEGENDA -->
    <?php
    // Deduplica per convoglio
    $legenda_convogli = [];
    $idx_col = 0;
    foreach ($corse_data as $c) {
        $idc = $c['IDCONVOGLIO'];
        if (!isset($legenda_convogli[$idc])) {
            $legenda_convogli[$idc] = [
                'TRENO' => $c['TRENO'],
                'colore' => $colori_treni[$idx_col % count($colori_treni)],
                'n_corse' => 1,
                'passa_mezzanotte' => $c['passa_mezzanotte'],
            ];
            $idx_col++;
        } else {
            $legenda_convogli[$idc]['n_corse']++;
            if ($c['passa_mezzanotte']) $legenda_convogli[$idc]['passa_mezzanotte'] = true;
        }
    }
    ?>
    <div class="legenda-container mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="text-muted fw-semibold small text-uppercase me-2">Treni:</span>
            <?php foreach ($legenda_convogli as $lc): ?>
            <span class="legenda-item">
                <span class="legenda-linea" style="background: <?= $lc['colore'] ?>"></span>
                <span>
                    <strong><?= htmlspecialchars($lc['TRENO']) ?></strong>
                    <small class="text-muted">
                        (<?= $lc['n_corse'] ?> cors<?= $lc['n_corse'] > 1 ? 'e' : 'a' ?>)
                    </small>
                    <?php if ($lc['passa_mezzanotte']): ?>
                    <span class="badge bg-warning text-dark ms-1" style="font-size:0.6rem;">→00:00</span>
                    <?php endif; ?>
                </span>
            </span>
            <?php endforeach; ?>
              <span class="legenda-item">
                <span class="legenda-sosta"></span>
                <span><strong>Sosta</strong> <small class="text-muted">(treno fermo in stazione)</small></span>
            </span>

        </div>
    </div>
    <?php endif; ?>

    
    <!-- DIAGRAMMA -->
    <div class="diagramma-wrapper mb-4">
        <?php if (count($corse_data) === 0): ?>
        <div class="text-center py-5">
            <i class="bi bi-calendar-x display-1 text-muted opacity-25"></i>
            <h4 class="text-muted mt-4">Nessuna corsa programmata</h4>
            <p class="text-muted">Non ci sono corse per <?= $data_formattata ?></p>
        </div>
        <?php else: ?>
        
        <div class="grafico-orario" id="grafico" style="margin-left: 155px; margin-right: 65px; margin-top: 45px;">
            <svg id="svg-tracce" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:20;"></svg>
            <div class="tooltip-diagramma" id="tooltip"></div>
        </div>
        
       <script>
        document.addEventListener('DOMContentLoaded', function() {
            const grafico = document.getElementById('grafico');
            const svg = document.getElementById('svg-tracce');
            const tooltip = document.getElementById('tooltip');

            const width = grafico.offsetWidth;
            const height = grafico.offsetHeight;

            const stazioni = <?= json_encode($stazioni) ?>;
            const kmMin = <?= $km_min ?>;
            const kmMax = <?= $km_max ?>;
            const oraMin = <?= $ora_min ?>;
            const oraMax = <?= $ora_max ?>;
            const totMinuti = (oraMax - oraMin) * 60;
            const passaMezzanotte = <?= $passa_mezzanotte ? 'true' : 'false' ?>;
            const dataDomani = "<?= $giorno_domani ?> <?= $mese_domani ?>";

            function oraToX(oraStr, dopoMezzanotte) {
                if (!oraStr) return 0;
                const parts = oraStr.split(':');
                let h = parseInt(parts[0]) || 0;
                const m = parseInt(parts[1]) || 0;
                if (dopoMezzanotte && h < 12) h += 24;
                const minuti = (h - oraMin) * 60 + m;
                return Math.max(0, Math.min(width, (minuti / totMinuti) * width));
            }

            function kmToY(km) {
                return ((km - kmMin) / (kmMax - kmMin)) * height;
            }

            function minutiSosta(oraA, oraP) {
                if (!oraA || !oraP) return 0;
                const a = oraA.split(':').map(n => parseInt(n) || 0);
                const p = oraP.split(':').map(n => parseInt(n) || 0);
                return (p[0] * 60 + p[1]) - (a[0] * 60 + a[1]);
            }

            // Zona giorno dopo
            if (oraMax > 24) {
                const xMezzanotte = oraToX('24:00', false);
                const zona = document.createElement('div');
                zona.className = 'zona-domani';
                zona.style.left = xMezzanotte + 'px';
                zona.style.right = '0';
                grafico.appendChild(zona);

                const labelDom = document.createElement('div');
                labelDom.className = 'label-domani';
                labelDom.innerHTML = '<i class="bi bi-calendar2-plus"></i> ' + dataDomani;
                labelDom.style.left = (xMezzanotte + 8) + 'px';
                grafico.appendChild(labelDom);
            }

            // Griglia oraria
            for (let ora = oraMin; ora <= oraMax; ora++) {
                const oraReale = ora % 24;
                const oraStr = String(oraReale).padStart(2, '0') + ':00';
                const x = oraToX(oraStr, ora >= 24);
                const isMezzanotte = (ora === 24);

                const div = document.createElement('div');
                div.className = 'griglia-ora' + (isMezzanotte ? ' mezzanotte' : '');
                div.style.left = x + 'px';
                grafico.appendChild(div);

                const label = document.createElement('div');
                label.className = 'label-ora' + (isMezzanotte ? ' mezzanotte-label' : '');
                label.style.left = x + 'px';
                label.textContent = isMezzanotte ? '00:00' : oraStr;
                grafico.appendChild(label);

                if (ora < oraMax) {
                    const x15 = oraToX(String(oraReale).padStart(2, '0') + ':15', ora >= 24);
                    const div15 = document.createElement('div');
                    div15.className = 'griglia-ora quarto-ora';
                    div15.style.left = x15 + 'px';
                    grafico.appendChild(div15);

                    const x30 = oraToX(String(oraReale).padStart(2, '0') + ':30', ora >= 24);
                    const div30 = document.createElement('div');
                    div30.className = 'griglia-ora mezz-ora';
                    div30.style.left = x30 + 'px';
                    grafico.appendChild(div30);

                    const lbl30 = document.createElement('div');
                    lbl30.className = 'label-minuti';
                    lbl30.style.left = x30 + 'px';
                    lbl30.textContent = ':30';
                    grafico.appendChild(lbl30);

                    const x45 = oraToX(String(oraReale).padStart(2, '0') + ':45', ora >= 24);
                    const div45 = document.createElement('div');
                    div45.className = 'griglia-ora quarto-ora';
                    div45.style.left = x45 + 'px';
                    grafico.appendChild(div45);
                }
            }

            // Linee stazioni
            stazioni.forEach((staz, idx) => {
                const y = kmToY(parseFloat(staz.KMPROGRESSIVO));
                const isCapolinea = idx === 0 || idx === stazioni.length - 1;

                const linea = document.createElement('div');
                linea.className = 'linea-stazione' + (isCapolinea ? ' capolinea' : '');
                linea.style.top = y + 'px';
                grafico.appendChild(linea);

                const label = document.createElement('div');
                label.className = 'label-stazione' + (isCapolinea ? ' capolinea' : '');
                label.style.top = y + 'px';
                label.textContent = staz.NOME;
                grafico.appendChild(label);

                const labelKm = document.createElement('div');
                labelKm.className = 'label-km';
                labelKm.style.top = y + 'px';
                labelKm.textContent = parseFloat(staz.KMPROGRESSIVO).toFixed(1);
                grafico.appendChild(labelKm);
            });

            const corse = <?= json_encode($corse_data) ?>;
            const coloriPalette = <?= json_encode($colori_treni) ?>;

            const coloriPerConvoglio = {};
            let indiceColore = 0;
            corse.forEach(c => {
                const k = c.IDCONVOGLIO;
                if (!(k in coloriPerConvoglio)) {
                    coloriPerConvoglio[k] = coloriPalette[indiceColore % coloriPalette.length];
                    indiceColore++;
                }
            });

            let tuttiSegmenti = [];

            corse.forEach((corsa, idx) => {
                const colore = coloriPerConvoglio[corsa.IDCONVOGLIO];
                const fermate = corsa.fermate;
                if (!fermate || fermate.length < 2) return;

                let punti = [];
                fermate.forEach((f) => {
                    const km = parseFloat(f.KMPROGRESSIVO);
                    const y = kmToY(km);
                    const dm = f.dopo_mezzanotte || false;
                    if (f.ORAA) punti.push({ x: oraToX(f.ORAA, dm), y: y });
                    if (f.ORAP) punti.push({ x: oraToX(f.ORAP, dm), y: y });
                });

                if (punti.length < 2) return;

                for (let i = 0; i < punti.length - 1; i++) {
                    tuttiSegmenti.push({
                        x1: punti[i].x, y1: punti[i].y,
                        x2: punti[i + 1].x, y2: punti[i + 1].y,
                        corsa: corsa.TRENO,
                        direzione: corsa.DIREZIONE,
                        colore: colore,
                        idx: idx,
                        idConvoglio: corsa.IDCONVOGLIO
                    });
                }

                // Traccia principale
                let pathD = punti.map((p, i) => (i === 0 ? 'M ' : 'L ') + p.x + ' ' + p.y).join(' ');
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', pathD);
                path.setAttribute('stroke', colore);
                path.setAttribute('class', 'traccia-treno');
                path.style.pointerEvents = 'stroke';

                path.addEventListener('mouseenter', function () {
                    const prima = fermate[0];
                    const ultima = fermate[fermate.length - 1];
                    tooltip.innerHTML =
                        '<div style="font-size:1rem; font-weight:700; margin-bottom:4px;">' + corsa.TRENO + '</div>' +
                        '<div style="opacity:0.8; margin-bottom:4px;">Dir. ' + corsa.DIREZIONE + '</div>' +
                        '<div><i class="bi bi-geo-alt"></i> ' + prima.NOME + ' → ' + ultima.NOME + '</div>' +
                        '<div><i class="bi bi-clock"></i> ' +
                            ((prima.ORAP || prima.ORAA || '').substring(0, 5)) + ' - ' +
                            ((ultima.ORAA || ultima.ORAP || '').substring(0, 5)) + '</div>' +
                        (corsa.passa_mezzanotte ? '<div class="mt-1"><span class="badge bg-warning text-dark">Prosegue dopo mezzanotte</span></div>' : '');
                    tooltip.style.display = 'block';
                });
                path.addEventListener('mousemove', function (e) {
                    const rect = grafico.getBoundingClientRect();
                    tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
                    tooltip.style.top = (e.clientY - rect.top - 10) + 'px';
                });
                path.addEventListener('mouseleave', function () { tooltip.style.display = 'none'; });

                svg.appendChild(path);

                // Soste: linea spessa sopra la traccia
                fermate.forEach((f) => {
                    if (f.ORAA && f.ORAP && f.ORAA !== f.ORAP) {
                        const km = parseFloat(f.KMPROGRESSIVO);
                        const y = kmToY(km);
                        const dm = f.dopo_mezzanotte || false;
                        const xArr = oraToX(f.ORAA, dm);
                        const xPar = oraToX(f.ORAP, dm);
                        const mSosta = minutiSosta(f.ORAA, f.ORAP);

                        const sosta = document.createElementNS('http://www.w3.org/2000/svg', 'line');
                        sosta.setAttribute('x1', xArr);
                        sosta.setAttribute('y1', y);
                        sosta.setAttribute('x2', xPar);
                        sosta.setAttribute('y2', y);
                        sosta.setAttribute('stroke', colore);
                        sosta.setAttribute('class', 'traccia-sosta');

                        sosta.addEventListener('mouseenter', function () {
                            tooltip.innerHTML =
                                '<div style="font-size:0.95rem; font-weight:700; margin-bottom:4px;"><i class="bi bi-pause-circle-fill"></i> Sosta ' + corsa.TRENO + '</div>' +
                                '<div><i class="bi bi-geo-alt"></i> ' + f.NOME + '</div>' +
                                '<div><i class="bi bi-clock"></i> ' + f.ORAA.substring(0, 5) + ' → ' + f.ORAP.substring(0, 5) + '</div>' +
                                '<div class="mt-1"><span class="badge bg-light text-dark">Durata: ' + mSosta + ' min</span></div>';
                            tooltip.style.display = 'block';
                        });
                        sosta.addEventListener('mousemove', function (e) {
                            const rect = grafico.getBoundingClientRect();
                            tooltip.style.left = (e.clientX - rect.left + 15) + 'px';
                            tooltip.style.top = (e.clientY - rect.top - 10) + 'px';
                        });
                        sosta.addEventListener('mouseleave', function () { tooltip.style.display = 'none'; });

                        svg.appendChild(sosta);
                    }
                });

                // Punti fermata
                fermate.forEach((f) => {
                    const km = parseFloat(f.KMPROGRESSIVO);
                    const y = kmToY(km);
                    const dm = f.dopo_mezzanotte || false;

                    let x;
                    if (f.ORAA && f.ORAP) x = (oraToX(f.ORAA, dm) + oraToX(f.ORAP, dm)) / 2;
                    else if (f.ORAP) x = oraToX(f.ORAP, dm);
                    else x = oraToX(f.ORAA, dm);

                    const punto = document.createElement('div');
                    punto.className = 'punto-fermata';
                    punto.style.left = x + 'px';
                    punto.style.top = y + 'px';
                    punto.style.backgroundColor = colore;

                    let title = f.NOME;
                    if (f.ORAA && f.ORAP) title += ' (arr ' + f.ORAA.substring(0, 5) + ' → par ' + f.ORAP.substring(0, 5) + ')';
                    else if (f.ORAP) title += ' (par ' + f.ORAP.substring(0, 5) + ')';
                    else if (f.ORAA) title += ' (arr ' + f.ORAA.substring(0, 5) + ')';
                    if (dm) title += ' [giorno dopo]';

                    punto.title = title;
                    grafico.appendChild(punto);
                });
            });

            // Incroci
            function lineIntersection(x1, y1, x2, y2, x3, y3, x4, y4) {
                const denom = (x1 - x2) * (y3 - y4) - (y1 - y2) * (x3 - x4);
                if (Math.abs(denom) < 0.0001) return null;
                const t = ((x1 - x3) * (y3 - y4) - (y1 - y3) * (x3 - x4)) / denom;
                const u = -((x1 - x2) * (y1 - y3) - (y1 - y2) * (x1 - x3)) / denom;
                if (t >= 0 && t <= 1 && u >= 0 && u <= 1) {
                    return { x: x1 + t * (x2 - x1), y: y1 + t * (y2 - y1) };
                }
                return null;
            }

            let incrociTrovati = [];
            for (let i = 0; i < tuttiSegmenti.length; i++) {
                for (let j = i + 1; j < tuttiSegmenti.length; j++) {
                    const s1 = tuttiSegmenti[i];
                    const s2 = tuttiSegmenti[j];
                    if (s1.idx === s2.idx) continue;
                    if (s1.idConvoglio === s2.idConvoglio) continue;
                    if (s1.direzione === s2.direzione) continue;

                    const inter = lineIntersection(s1.x1, s1.y1, s1.x2, s1.y2, s2.x1, s2.y1, s2.x2, s2.y2);
                    if (inter) {
                        const duplicato = incrociTrovati.some(ic => Math.abs(ic.x - inter.x) < 15 && Math.abs(ic.y - inter.y) < 15);
                        if (!duplicato) {
                            incrociTrovati.push({ x: inter.x, y: inter.y, treno1: s1.corsa, treno2: s2.corsa });
                        }
                    }
                }
            }

            incrociTrovati.forEach(inc => {
                const simbolo = document.createElement('div');
                simbolo.className = 'simbolo-incrocio';
                simbolo.style.left = inc.x + 'px';
                simbolo.style.top = inc.y + 'px';
                simbolo.innerHTML = '×';
                simbolo.title = 'Incrocio: ' + inc.treno1 + ' ↔ ' + inc.treno2;
                grafico.appendChild(simbolo);
            });

            console.log('Incroci trovati:', incrociTrovati.length);
        });
</script>

        
        <?php endif; ?>
    </div>

    <!-- GUIDA MINIMAL -->
    <div class="guida-mini">
        <i class="bi bi-info-circle"></i>
        <strong>↘</strong> treno verso Sud 
        <span class="sep"></span>
        <strong>↗</strong> treno verso Nord 
        <span class="sep"></span>
        <span style="display:inline-block;width:22px;height:7px;background:#64748b;border-radius:3px;vertical-align:middle;box-shadow:0 0 4px #64748b;"></span>
        <strong>sosta</strong> (treno fermo in stazione, spesso in attesa di incrocio)
       <span class="sep"></span>
        <span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;background:#fbbf24;border-radius:50%;font-size:9px;font-weight:bold;color:#78350f;">×</span> incrocio programmato
        <?php if ($passa_mezzanotte): ?>
        <span class="sep"></span>
        <span class="badge bg-warning text-dark" style="font-size:0.7rem;">Area gialla</span> = giorno successivo (<?= $giorno_domani ?> <?= $mese_domani ?>)
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>