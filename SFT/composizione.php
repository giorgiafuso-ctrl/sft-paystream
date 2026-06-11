<?php
session_name('SFT_SESSION'); session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
include('connessione.php');

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Esercizio') {
    header("Location: index.php?errore=non_autorizzato");
    exit();
}

$id_convoglio   = intval($_GET['id'] ?? 0);
$info_convoglio = $con->query("SELECT * FROM SFT_CONVOGLIO WHERE IDCONVOGLIO = $id_convoglio")->fetch_assoc();

if (!$info_convoglio) {
    header("Location: admin_esercizio.php?msg=Convoglio non trovato");
    exit();
}

$ora_filtro  = $con->real_escape_string($_GET['ora']  ?? '');
$data_filtro = $con->real_escape_string($_GET['data'] ?? '');

$msg  = '';
$tipo = 'info';
/*DEBUG 
if (isset($_GET['debug'])) {
    echo "<pre style='background:#ffe;border:2px solid red;padding:10px;margin:10px;position:relative;z-index:99999;font-size:12px'>";
    echo "ID Convoglio: $id_convoglio\n\n";
    echo "--- Corse del convoglio ---\n";
    $r = $con->query("SELECT IDCORSA, DATA, ORA, STATO, DIREZIONE FROM SFT_CORSA WHERE IDCONVOGLIO = $id_convoglio ORDER BY DATA DESC");
    if (!$r) { echo "ERRORE SQL: " . $con->error; }
    else {
        echo "Trovate " . $r->num_rows . " corse\n";
        while ($row = $r->fetch_assoc()) print_r($row);
    }
    echo "\n--- Check STATO='In Viaggio' ---\n";
    $r2 = $con->query("SELECT IDCORSA FROM SFT_CORSA WHERE IDCONVOGLIO = $id_convoglio AND STATO = 'In Viaggio' LIMIT 1");
    echo "Risultati: " . ($r2 ? $r2->num_rows : 'QUERY FALLITA: '.$con->error) . "\n";
    echo "</pre>";
}
FINE DEBUG*/

function convoglioHaMotore($con, $id_convoglio) {
    $check = $con->query("
        SELECT COUNT(*) AS cnt
        FROM SFT_COMPOSTO C
        JOIN SFT_MATERIALEROTABILE MR ON C.MATRICOLAMAT = MR.MATRICOLA
        WHERE C.IDCONVOGLIO = $id_convoglio
        AND MR.TIPO IN ('Locomotiva', 'Automotrice')
    ");
    $row = $check->fetch_assoc();
    return $row['cnt'] > 0;
}

function convoglioHaPosti($con, $id_convoglio) {
    $id_convoglio = (int)$id_convoglio;
    $r = $con->query("
        SELECT COALESCE(SUM(MR.CAPACITA_POSTO), 0) AS tot_posti
        FROM SFT_COMPOSTO C
        JOIN SFT_MATERIALEROTABILE MR ON C.MATRICOLAMAT = MR.MATRICOLA
        WHERE C.IDCONVOGLIO = $id_convoglio
    ");
    $row = $r->fetch_assoc();
    return (int)$row['tot_posti'];
}

// BLOCCO MODIFICA COMPOSIZIONE FUORI DAL DEPOSITO= Torre Spaventa
$ID_DEPOSITO       = 1;
$modifica_bloccata = false;
$motivo_blocco     = '';

// Convoglio in viaggio?
$r_viaggio = $con->query("SELECT IDCORSA FROM SFT_CORSA
    WHERE IDCONVOGLIO = $id_convoglio AND STATO = 'In Viaggio' LIMIT 1");
if ($r_viaggio && $r_viaggio->num_rows > 0) {
    $c_v = $r_viaggio->fetch_assoc();
    $modifica_bloccata = true;
    $motivo_blocco = "Convoglio <strong>in viaggio</strong> (corsa #{$c_v['IDCORSA']}). "
                   . "Le modifiche alla composizione sono consentite solo al deposito di Torre Spaventa.";
}

// Posizione attuale = stazione finale dell'ultima corsa Conclusa
if (!$modifica_bloccata) {
    $r_last = $con->query("
        SELECT C.IDCORSA, C.DATA,
            (SELECT F.IDSTAZIONE FROM SFT_FERMATA F
             WHERE F.IDCORSA = C.IDCORSA ORDER BY F.PROGRESSIVO DESC LIMIT 1) AS STAZ_FINE,
            (SELECT S.NOME FROM SFT_FERMATA F
             JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE
             WHERE F.IDCORSA = C.IDCORSA ORDER BY F.PROGRESSIVO DESC LIMIT 1) AS STAZ_NOME
        FROM SFT_CORSA C
        WHERE C.IDCONVOGLIO = $id_convoglio AND C.STATO = 'Conclusa'
        ORDER BY C.DATA DESC, C.ORA DESC LIMIT 1
    ");
    if ($r_last && $r_last->num_rows > 0) {
        $u = $r_last->fetch_assoc();
        if ((int)$u['STAZ_FINE'] !== $ID_DEPOSITO) {
            $modifica_bloccata = true;
            $motivo_blocco = "Convoglio fermo a <strong>" . htmlspecialchars($u['STAZ_NOME']) . "</strong> "
                           . "(ultima corsa #{$u['IDCORSA']}). "
                           . "La composizione si modifica solo al <strong>deposito di Torre Spaventa</strong>.";
        }
    }
    
}

//Prossima corsa (entro 2 ore) in partenza da stazione != deposito
if (!$modifica_bloccata) {
    $r_prox = $con->query("
        SELECT C.IDCORSA, C.DATA, C.ORA,
            (SELECT F.IDSTAZIONE FROM SFT_FERMATA F
             WHERE F.IDCORSA = C.IDCORSA ORDER BY F.PROGRESSIVO ASC LIMIT 1) AS STAZ_PART,
            (SELECT S.NOME FROM SFT_FERMATA F
             JOIN SFT_STAZIONE S ON F.IDSTAZIONE = S.IDSTAZIONE
             WHERE F.IDCORSA = C.IDCORSA ORDER BY F.PROGRESSIVO ASC LIMIT 1) AS STAZ_NOME
        FROM SFT_CORSA C
        WHERE C.IDCONVOGLIO = $id_convoglio
          AND C.STATO = 'Programmata'
          AND CONCAT(C.DATA,' ',C.ORA) >= NOW()
          AND CONCAT(C.DATA,' ',C.ORA) <= DATE_ADD(NOW(), INTERVAL 2 HOUR)
        ORDER BY C.DATA ASC, C.ORA ASC LIMIT 1
    ");
    if ($r_prox && $r_prox->num_rows > 0) {
        $p = $r_prox->fetch_assoc();
        if ((int)$p['STAZ_PART'] !== $ID_DEPOSITO) {
            $modifica_bloccata = true;
            $motivo_blocco = "Corsa imminente #{$p['IDCORSA']} in partenza da <strong>"
                           . htmlspecialchars($p['STAZ_NOME']) . "</strong> alle "
                           . substr($p['ORA'],0,5) . " del " . date('d/m/Y', strtotime($p['DATA']))
                           . ". Composizione bloccata fino al rientro al deposito.";
        }
    }
}

// Se bloccato, respingi aggiunta/rimozione materiale
if ($modifica_bloccata && (isset($_POST['aggiungi_pezzo']) || isset($_POST['rimuovi']))) {
    $msg  = $motivo_blocco;
    $tipo = 'danger';
    unset($_POST['aggiungi_pezzo'], $_POST['rimuovi']);
}

// Eliminazione convoglio - CON CONFERMA + TRANS sic
$mostra_conferma_elimina = false;
$corse_da_cancellare     = [];
$biglietti_da_rimborsare = [];
$totale_stimato_rimborso = 0;

if (isset($_POST['elimina_convoglio'])) {

    if (empty($_POST['conferma_elimina'])) {

        $r_cc = $con->query("
            SELECT IDCORSA, DATA, ORA, DIREZIONE
            FROM SFT_CORSA
            WHERE IDCONVOGLIO = $id_convoglio
            ORDER BY DATA, ORA
        ");

        if ($r_cc) {
            while ($cc = $r_cc->fetch_assoc()) {
                $idc = (int)$cc['IDCORSA'];

                $r_bg = $con->query("
                    SELECT B.IDBIGLIETTO, B.COSTO, B.STATOPAGAMENTO, B.CODUTENTE,
                           S1.NOME AS PARTENZA, S2.NOME AS ARRIVO
                    FROM SFT_BIGLIETTO B
                    JOIN SFT_STAZIONE S1 ON B.IDSTAZIONEP = S1.IDSTAZIONE
                    JOIN SFT_STAZIONE S2 ON B.IDSTAZIONEA = S2.IDSTAZIONE
                    WHERE B.IDCORSA = $idc
                ");

                $cc['biglietti'] = [];
                if ($r_bg) {
                    while ($b = $r_bg->fetch_assoc()) {
                        $b['NOME']    = '';
                        $b['COGNOME'] = '';
                        $cu = (int)($b['CODUTENTE'] ?? 0);
                        if ($cu > 0) {
                            $r_u = @$con->query("
                                SELECT NOME, COGNOME FROM SFT_UTENTE
                                WHERE IDUTENTE = $cu LIMIT 1
                            ");
                            if ($r_u && $r_u->num_rows > 0) {
                                $u = $r_u->fetch_assoc();
                                $b['NOME']    = $u['NOME']    ?? '';
                                $b['COGNOME'] = $u['COGNOME'] ?? '';
                            }
                        }

                        $cc['biglietti'][] = $b;
                        if ($b['STATOPAGAMENTO'] === 'Pagato') {
                            $biglietti_da_rimborsare[] = $b;
                            $totale_stimato_rimborso  += (float)$b['COSTO'];
                        }
                    }
                }

                $corse_da_cancellare[] = $cc;
            }
        }
        $mostra_conferma_elimina = true;

    } else {

        $con->begin_transaction();
        $n_rimb   = 0;
        $tot_rimb = 0.0;

        try {
            $r_corse = $con->query("SELECT IDCORSA FROM SFT_CORSA WHERE IDCONVOGLIO = $id_convoglio");
            if (!$r_corse) throw new Exception("Errore lettura corse: " . $con->error);

            $ids_corse = [];
            while ($c = $r_corse->fetch_assoc()) $ids_corse[] = (int)$c['IDCORSA'];
            $r_corse->close();

            foreach ($ids_corse as $idc) {
                $r_bg = $con->query("
                    SELECT B.COSTO, B.CODUTENTE, B.STATOPAGAMENTO,
                           S1.NOME AS PARTENZA, S2.NOME AS ARRIVO
                    FROM SFT_BIGLIETTO B
                    JOIN SFT_STAZIONE S1 ON B.IDSTAZIONEP = S1.IDSTAZIONE
                    JOIN SFT_STAZIONE S2 ON B.IDSTAZIONEA = S2.IDSTAZIONE
                    WHERE B.IDCORSA = $idc
                ");
                if (!$r_bg) throw new Exception("Errore lettura biglietti: " . $con->error);

                while ($b = $r_bg->fetch_assoc()) {
                    if ($b['STATOPAGAMENTO'] !== 'Pagato') continue;
                    $imp = floatval($b['COSTO']);
                    $p   = $con->real_escape_string($b['PARTENZA']);
                    $a   = $con->real_escape_string($b['ARRIVO']);

                    $rt = $con->query("
                        SELECT IDCONSUMATORE, IDESERCENTE, IMPORTO
                        FROM PAY_TRANSAZIONE
                        WHERE IDTRANSESTERNA LIKE 'SFT-PAY-%'
                          AND STATO IN ('COMPLETED','SUCCESS')
                          AND ABS(IMPORTO - $imp) < 0.01
                          AND DESCRIZIONE LIKE '%$p%'
                          AND DESCRIZIONE LIKE '%$a%'
                        ORDER BY DATAORA DESC LIMIT 1
                    ");

                    if ($rt && $rt->num_rows > 0) {
                        $t     = $rt->fetch_assoc();
                        $ic    = (int)$t['IDCONSUMATORE'];
                        $ie    = (int)$t['IDESERCENTE'];
                        $idref = 'REFUND-' . time() . '-' . rand(1000, 9999);

                        $cod_utente_sft = (int)($b['CODUTENTE'] ?? 0);
                        $desc_raw = 'RIMBORSO - Convoglio eliminato - Corsa #' . $idc
                                . ' [SFT_UTENTE:' . $cod_utente_sft . ']';

                        $desc = $con->real_escape_string($desc_raw);

                        if (!$con->query("UPDATE PAY_CONTO SET SALDO = SALDO + $imp WHERE IDUTENTE = $ic"))
                            throw new Exception("Errore credito rimborso: " . $con->error);
                        if (!$con->query("UPDATE PAY_CONTO SET SALDO = SALDO - $imp WHERE IDUTENTE = $ie"))
                            throw new Exception("Errore addebito esercente: " . $con->error);
                        if (!$con->query("INSERT INTO PAY_TRANSAZIONE
                            (IMPORTO, DATAORA, STATO, DESCRIZIONE, URLIN, URLOUT, IDTRANSESTERNA, IDCONSUMATORE, IDESERCENTE)
                            VALUES ($imp, NOW(), 'REFUNDED', '$desc', 'SFT','SFT','$idref', $ic, $ie)"))
                            throw new Exception("Errore insert transazione: " . $con->error);

                        $n_rimb++;
                        $tot_rimb += $imp;
                    }
                    if ($rt) $rt->close();
                }
                $r_bg->close();
            }

            foreach ($ids_corse as $idc) {
                if (!$con->query("DELETE FROM SFT_BIGLIETTO WHERE IDCORSA = $idc"))
                    throw new Exception("Errore delete biglietti: " . $con->error);
                if (!$con->query("DELETE FROM SFT_FERMATA   WHERE IDCORSA = $idc"))
                    throw new Exception("Errore delete fermate: " . $con->error);
            }
            if (!$con->query("DELETE FROM SFT_CORSA    WHERE IDCONVOGLIO = $id_convoglio"))
                throw new Exception("Errore delete corse: " . $con->error);
            if (!$con->query("DELETE FROM SFT_COMPOSTO WHERE IDCONVOGLIO = $id_convoglio"))
                throw new Exception("Errore delete composto: " . $con->error);
            if (!$con->query("DELETE FROM SFT_CONVOGLIO WHERE IDCONVOGLIO = $id_convoglio"))
                throw new Exception("Errore delete convoglio: " . $con->error);

            $con->commit();

            $m = "Convoglio '" . $info_convoglio['NOME'] . "' eliminato con "
               . count($ids_corse) . " corse collegate.";
            if ($n_rimb > 0) {
                $m .= " Rimborsati $n_rimb biglietti per € "
                    . number_format($tot_rimb, 2, ',', '.') . ".";
            }
            header("Location: admin_esercizio.php?msg=" . urlencode($m));
            exit();

        } catch (Exception $e) {
            $con->rollback();
            $msg  = "Errore durante l'eliminazione: " . $e->getMessage() . " (nessuna modifica applicata)";
            $tipo = 'danger';
        }
    }
}

// Aggiunta materiale con VINCOLO MOTORE
if (isset($_POST['aggiungi_pezzo'])) {
    if (empty($_POST['matricola'])) {
        $msg  = "Errore: nessun materiale selezionato.";
        $tipo = 'danger';
    } else {
        $matricola = $con->real_escape_string($_POST['matricola']);

        $r_gia_usato = $con->query("
            SELECT C.IDCONVOGLIO, CO.NOME
            FROM SFT_COMPOSTO C
            JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
            WHERE C.MATRICOLAMAT = '$matricola'
        ");
        if ($r_gia_usato && $r_gia_usato->num_rows > 0) {
            $usi = [];
            while ($u = $r_gia_usato->fetch_assoc()) {
                $usi[] = htmlspecialchars($u['NOME']) . " (#" . $u['IDCONVOGLIO'] . ")";
            }
            $msg  = "⚠️ Il materiale <strong>$matricola</strong> è già assegnato al convoglio: "
                  . implode(", ", $usi)
                  . ". Un rotabile non può stare contemporaneamente in più convogli: rimuovilo prima dal convoglio corrente.";
            $tipo = 'danger';
        } else {
            $tipo_materiale = $con->query("SELECT TIPO FROM SFT_MATERIALEROTABILE WHERE MATRICOLA = '$matricola'")->fetch_assoc();
            $count_attuali  = $con->query("SELECT COUNT(*) AS cnt FROM SFT_COMPOSTO WHERE IDCONVOGLIO = $id_convoglio")->fetch_assoc()['cnt'];

            if ($count_attuali == 0 && !in_array($tipo_materiale['TIPO'], ['Locomotiva', 'Automotrice'])) {
                $msg  = "⚠️ Il primo componente deve essere una <strong>Locomotiva</strong> o <strong>Automotrice</strong>.";
                $tipo = 'warning';
            } else {
                if ($con->query("INSERT INTO SFT_COMPOSTO (IDCONVOGLIO, MATRICOLAMAT) VALUES ($id_convoglio, '$matricola')")) {
                    $msg  = "Materiale aggiunto con successo!";
                    $tipo = 'success';
                } else {
                    $msg  = "Errore aggiunta: " . $con->error;
                    $tipo = 'danger';
                }
            }
        }
    }
}

// Rimozione materiale con VINCOLO MOTORE
if (isset($_POST['rimuovi'])) {
    $mat = $con->real_escape_string($_POST['rimuovi']);

    $tipo_pezzo = $con->query("SELECT TIPO FROM SFT_MATERIALEROTABILE WHERE MATRICOLA = '$mat'")->fetch_assoc();
    $is_motore = in_array($tipo_pezzo['TIPO'], ['Locomotiva', 'Automotrice']);

    if ($is_motore) {
        $count_motori = $con->query("
            SELECT COUNT(*) AS cnt
            FROM SFT_COMPOSTO C
            JOIN SFT_MATERIALEROTABILE MR ON C.MATRICOLAMAT = MR.MATRICOLA
            WHERE C.IDCONVOGLIO = $id_convoglio
            AND MR.TIPO IN ('Locomotiva', 'Automotrice')
        ")->fetch_assoc()['cnt'];

        $count_totali = $con->query("SELECT COUNT(*) AS cnt FROM SFT_COMPOSTO WHERE IDCONVOGLIO = $id_convoglio")->fetch_assoc()['cnt'];

        if ($count_motori == 1 && $count_totali > 1) {
            $msg  = "⚠️ Non puoi rimuovere l'unico motore! Il convoglio ha altri componenti che necessitano di trazione. Rimuovi prima le carrozze/bagagliai.";
            $tipo = 'warning';
        } else {
            if ($con->query("DELETE FROM SFT_COMPOSTO WHERE IDCONVOGLIO = $id_convoglio AND MATRICOLAMAT = '$mat'")) {
                $msg  = "Materiale rimosso.";
                $tipo = 'warning';
            } else {
                $msg  = "Errore rimozione: " . $con->error;
                $tipo = 'danger';
            }
        }
    } else {
        if ($con->query("DELETE FROM SFT_COMPOSTO WHERE IDCONVOGLIO = $id_convoglio AND MATRICOLAMAT = '$mat'")) {
            $msg  = "Materiale rimosso.";
            $tipo = 'warning';
        } else {
            $msg  = "Errore rimozione: " . $con->error;
            $tipo = 'danger';
        }
    }
}

$pezzi_attuali = $con->query("
    SELECT MR.*
    FROM SFT_MATERIALEROTABILE MR
    JOIN SFT_COMPOSTO C ON MR.MATRICOLA = C.MATRICOLAMAT
    WHERE C.IDCONVOGLIO = $id_convoglio
    ORDER BY
        CASE MR.TIPO
            WHEN 'Locomotiva' THEN 1
            WHEN 'Automotrice' THEN 2
            ELSE 3
        END,
        MR.NOME
");

$ha_motore        = convoglioHaMotore($con, $id_convoglio);
$count_pezzi      = $pezzi_attuali->num_rows;
$tot_posti        = convoglioHaPosti($con, $id_convoglio);
$convoglio_valido = $ha_motore && $tot_posti >= 1;

if (!empty($ora_filtro) && !empty($data_filtro)) {
    $pezzi_liberi = $con->query("
        SELECT MR.*
        FROM SFT_MATERIALEROTABILE MR
        WHERE MR.MATRICOLA NOT IN (
            SELECT MATRICOLAMAT FROM SFT_COMPOSTO
            WHERE IDCONVOGLIO = $id_convoglio
        )
        AND MR.MATRICOLA NOT IN (
            SELECT COMP.MATRICOLAMAT
            FROM SFT_COMPOSTO COMP
            JOIN SFT_CORSA C ON COMP.IDCONVOGLIO = C.IDCONVOGLIO
            WHERE C.ORA  = '$ora_filtro'
            AND   C.DATA = '$data_filtro'
            AND   C.STATO NOT IN ('Conclusa','Annullata')
        )
        AND MR.MATRICOLA NOT IN (
            SELECT MATRICOLAMAT FROM SFT_COMPOSTO
            WHERE IDCONVOGLIO <> $id_convoglio
        )
        ORDER BY
            CASE MR.TIPO
                WHEN 'Locomotiva' THEN 1
                WHEN 'Automotrice' THEN 2
                ELSE 3
            END,
            MR.NOME
    ");
} else {
    $pezzi_liberi = $con->query("
        SELECT MR.* FROM SFT_MATERIALEROTABILE MR
        WHERE MR.MATRICOLA NOT IN (
            SELECT MATRICOLAMAT FROM SFT_COMPOSTO WHERE IDCONVOGLIO = $id_convoglio
        )
        AND MR.MATRICOLA NOT IN (
            SELECT COMP.MATRICOLAMAT FROM SFT_COMPOSTO COMP
            JOIN SFT_CORSA C ON COMP.IDCONVOGLIO = C.IDCONVOGLIO
            WHERE C.STATO IN ('In Viaggio','In Ritardo')
        )
        AND MR.MATRICOLA NOT IN (
            SELECT MATRICOLAMAT FROM SFT_COMPOSTO
            WHERE IDCONVOGLIO <> $id_convoglio
        )
        ORDER BY
            CASE MR.TIPO
                WHEN 'Locomotiva' THEN 1
                WHEN 'Automotrice' THEN 2
                ELSE 3
            END,
            MR.NOME
    ");
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>SFT - Composizione Convoglio</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
   <style>
    :root {
        --sft-blu-scuro: #0d1f3c;
        --sft-blu:       #1a3a6b;
        --sft-blu-acc:   #004a99;
        --sft-blu-soft:  #e8f0fe;
        --sft-blu-bg:    #f0f4ff;
        --sft-border:    #c0d0f0;
    }
    body { font-family: 'Inter', sans-serif; background: linear-gradient(180deg, #f0f4ff 0%, #ffffff 100%); color: #212529; }
    .navbar.bg-dark { background: linear-gradient(90deg, var(--sft-blu-scuro) 0%, var(--sft-blu) 100%) !important; box-shadow: 0 2px 12px rgba(13,31,60,0.25); }
    h3.fw-bold { color: var(--sft-blu); font-size: 1.4rem; letter-spacing: 0.01em; }
    h5.fw-bold { color: var(--sft-blu); text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.82rem; margin-bottom: 1rem; }
    .card { border: none; border-radius: 14px; transition: all 0.25s ease; }
    .card.shadow-sm { box-shadow: 0 4px 16px rgba(26,58,107,0.08) !important; border-left: none !important; }
    .filtro-card { background: linear-gradient(135deg, #ffffff 0%, var(--sft-blu-bg) 100%); border-radius: 14px; border: 1px solid var(--sft-border); }
    .form-control, .form-select { border-radius: 10px; border: 1px solid var(--sft-border); transition: all 0.2s ease; }
    .form-control:focus, .form-select:focus { border-color: var(--sft-blu-acc); box-shadow: 0 0 0 3px rgba(0,74,153,0.12); }
    .btn-sft-primary { background: linear-gradient(135deg, var(--sft-blu-acc) 0%, var(--sft-blu) 100%); color: #fff; border: none; border-radius: 10px; font-weight: 600; padding: 8px 18px; transition: all 0.25s ease; box-shadow: 0 4px 12px rgba(0,74,153,0.25); }
    .btn-sft-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0,74,153,0.35); color: #fff; }
    .btn-sft-primary:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
    .btn-sft-ghost { background: #fff; color: var(--sft-blu); border: 1px solid var(--sft-border); border-radius: 10px; font-weight: 600; transition: all 0.25s ease; }
    .btn-sft-ghost:hover { background: var(--sft-blu-soft); border-color: var(--sft-blu-acc); color: var(--sft-blu-acc); }
    .btn-sft-danger { background: linear-gradient(135deg, #c81d3c 0%, #8e0d24 100%); color: #fff; border: none; border-radius: 10px; font-weight: 600; padding: 8px 18px; box-shadow: 0 4px 12px rgba(200,29,60,0.25); transition: all 0.25s ease; }
    .btn-sft-danger:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(200,29,60,0.35); color: #fff; }
    .status-box { border-radius: 14px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; border: none; display: flex; align-items: center; gap: 12px; }
    .status-ok { background: linear-gradient(90deg, #f0fdf4 0%, #ffffff 100%); border-left: 4px solid #16a34a; }
    .status-warning { background: linear-gradient(90deg, #fffbeb 0%, #ffffff 100%); border-left: 4px solid #f59e0b; }
    .status-danger { background: linear-gradient(90deg, #fef2f2 0%, #ffffff 100%); border-left: 4px solid #dc2626; color:#991b1b; }
    .list-group { border-radius: 12px; overflow: hidden; }
    .list-group-item { border-left: none; border-right: none; background: #fff; padding: 14px 18px; transition: background 0.2s ease; }
    .list-group-item:hover { background: var(--sft-blu-bg); }
    .list-group-item strong { color: var(--sft-blu-acc); font-family: 'JetBrains Mono', 'Courier New', monospace; font-weight: 700; }
    .list-group-item.motore { background: linear-gradient(90deg, #f0fdf4 0%, #ffffff 40%); border-left: 4px solid #16a34a !important; }
    .badge-motore { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: #fff; border-radius: 20px; padding: 4px 10px; font-weight: 600; }
    .badge-carrozza { background: linear-gradient(135deg, var(--sft-blu-acc) 0%, var(--sft-blu) 100%); color: #fff; border-radius: 20px; padding: 4px 10px; }
    .badge-bagagliaio { background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); color: #fff; border-radius: 20px; padding: 4px 10px; }
    .badge-count { background: var(--sft-blu-soft); color: var(--sft-blu-acc); border: 1px solid var(--sft-border); font-family: 'JetBrains Mono', 'Courier New', monospace; font-weight: 700; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; }
    .alert { border: none; border-radius: 12px; padding: 12px 16px; }
    .alert-info { background: linear-gradient(90deg, var(--sft-blu-bg) 0%, #fff 100%); border-left: 4px solid var(--sft-blu-acc); color: var(--sft-blu); }
    .alert-warning { background: linear-gradient(90deg, #fff8e1 0%, #fff 100%); border-left: 4px solid #d97706; color: #854d0e; }
    .alert-secondary { background: #f8fafc; border-left: 4px solid #94a3b8; color: #475569; }
    .alert-danger { background: linear-gradient(90deg, #fef2f2 0%, #fff 100%); border-left: 4px solid #dc2626; color: #991b1b; }
    select[name="matricola"] option { padding: 8px; }
    select[name="matricola"] option[disabled] { background: var(--sft-blu-soft) !important; color: var(--sft-blu) !important; font-weight: 700; text-align: center; letter-spacing: 0.1em; }
    .modal-content { border: none; border-radius: 16px; overflow: hidden; }
    .modal-header.bg-danger { background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%) !important; }
    .table { border-radius: 10px; overflow: hidden; }
    .table thead.table-light { background: var(--sft-blu-soft) !important; color: var(--sft-blu); }
    .table thead.table-light th { border: none; padding: 10px 12px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .modal-backdrop-sft { position: fixed; inset: 0; background: radial-gradient(circle at 30% 30%, rgba(13,31,60,0.6), rgba(13,31,60,0.85)); z-index: 1055; display: flex; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px); animation: sftFadeIn 0.2s ease; }
    @keyframes sftFadeIn { from {opacity:0;} to {opacity:1;} }
    @keyframes sftSlideUp { from {transform:translateY(20px); opacity:0;} to {transform:translateY(0); opacity:1;} }
    .sft-confirm-card { background: #fff; border-radius: 20px; width: 100%; max-width: 720px; max-height: 92vh; overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 25px 60px rgba(0,0,0,0.4); animation: sftSlideUp 0.3s cubic-bezier(0.4,0,0.2,1); }
    .sft-confirm-header { background: linear-gradient(135deg, #0d1f3c 0%, #1a3a6b 60%, #004a99 100%); color: #fff; padding: 22px 26px; display: flex; align-items: center; gap: 18px; position: relative; }
    .sft-confirm-icon { width: 52px; height: 52px; background: rgba(255,255,255,0.18); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; flex-shrink: 0; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.25); }
    .sft-confirm-header h4 { font-size: 1.15rem; font-weight: 700; letter-spacing: 0.01em; }
    .sft-close-x { position: absolute; top: 14px; right: 14px; width: 34px; height: 34px; background: rgba(255,255,255,0.15); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; text-decoration: none; transition: all 0.2s ease; }
    .sft-close-x:hover { background: rgba(255,255,255,0.3); color:#fff; transform: rotate(90deg); }
    .sft-confirm-body { padding: 26px; overflow-y: auto; }
    .sft-stat-card { background: linear-gradient(135deg, #f0f4ff 0%, #fff 100%); border: 1px solid #c0d0f0; border-radius: 14px; padding: 16px; display: flex; align-items: center; gap: 14px; transition: all 0.25s ease; height: 100%; }
    .sft-stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(26,58,107,0.12); }
    .sft-stat-icon { font-size: 2rem; flex-shrink: 0; }
    .sft-stat-num { font-size: 1.5rem; font-weight: 800; color: #1a3a6b; line-height: 1; font-family: 'JetBrains Mono','Courier New',monospace; }
    .sft-stat-label { font-size: 0.72rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 3px; }
    .sft-stat-accent { background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%); border-color: #bbf7d0; }
    .sft-stat-accent .sft-stat-num { color: #15803d; }
    .sft-section-title { font-size: 0.8rem; font-weight: 700; color: #1a3a6b; text-transform: uppercase; letter-spacing: 0.08em; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 2px solid #e8f0fe; }
    .sft-section-title i { color: #004a99; margin-right: 6px; }
    .sft-corse-list { background: #f8fafc; border-radius: 12px; padding: 8px; max-height: 220px; overflow-y: auto; }
    .sft-corsa-row { display: flex; align-items: center; gap: 14px; background: #fff; padding: 10px 14px; border-radius: 10px; margin-bottom: 6px; border-left: 3px solid #004a99; transition: all 0.2s ease; }
    .sft-corsa-row:hover { transform: translateX(4px); box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .sft-corsa-row:last-child { margin-bottom: 0; }
    .sft-corsa-badge { background: #1a3a6b; color: #fff; padding: 4px 10px; border-radius: 6px; font-family: 'JetBrains Mono','Courier New',monospace; font-weight: 700; font-size: 0.8rem; }
    .sft-corsa-info { flex-grow: 1; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .sft-time-pill { background: #004a99; color: #fff; padding: 2px 10px; border-radius: 20px; font-weight: 600; font-size: 0.8rem; font-family: 'JetBrains Mono','Courier New',monospace; }
    .sft-dir-pill { padding: 2px 12px; border-radius: 20px; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; }
    .sft-dir-nord { background: #dbeafe; color: #1e40af; }
    .sft-dir-sud  { background: #fef3c7; color: #92400e; }
    .sft-ticket-count { background: #fef3c7; color: #92400e; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 0.8rem; }
    .sft-ticket-empty { color: #94a3b8; }
    .sft-rimborsi-list { background: #f0fdf4; border-radius: 12px; padding: 8px; border: 1px solid #bbf7d0; }
    .sft-rimborso-row { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 10px 14px; border-radius: 10px; margin-bottom: 6px; }
    .sft-rimborso-row:last-child { margin-bottom: 0; }
    .sft-rimborso-sub { font-size: 0.75rem; color: #64748b; margin-top: 2px; }
    .sft-rimborso-amount { background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: #fff; padding: 6px 14px; border-radius: 20px; font-weight: 700; font-family: 'JetBrains Mono','Courier New',monospace; }
    .sft-info-note { background: #f0f4ff; border-left: 3px solid #1a3a6b; color: #1a3a6b; padding: 10px 14px; border-radius: 8px; font-size: 0.88rem; }
    .sft-warning-strip { background: linear-gradient(90deg, #fef2f2 0%, #fff 100%); border-left: 4px solid #dc2626; color: #991b1b; padding: 12px 16px; border-radius: 10px; font-size: 0.88rem; display: flex; align-items: center; gap: 10px; }
    .sft-warning-strip i { font-size: 1.3rem; flex-shrink: 0; }
    .sft-confirm-footer { padding: 18px 26px; background: #f8fafc; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 10px; }
</style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">
            SFT - Società Ferroviarie Turistiche
            <span class="text-white-50 fw-normal small ms-2 border-start border-secondary ps-2">
                Composizione Convoglio
            </span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="text-white-50 small me-2 d-none d-md-inline">
                <i class="bi bi-person-badge me-1"></i>
                Ciao, <strong class="text-white"><?= htmlspecialchars($_SESSION['sft_nome']); ?></strong>
                <span class="badge bg-primary ms-1">Esercizio</span>
            </span>
            <a href="admin_esercizio.php" class="btn btn-outline-light btn-sm px-2" title="Esercizio">
                <i class="bi bi-arrow-left"></i>
            </a>
            <a href="logout.php" class="btn btn-danger btn-sm px-3">
                <i class="bi bi-box-arrow-right"></i> Esci
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">

    <!-- FILTRO DISPONIBILITÀ -->
    <div class="card filtro-card shadow-sm p-3 mb-4">
        <form method="GET" action="composizione.php" class="row g-2 align-items-end">
            <input type="hidden" name="id" value="<?= $id_convoglio; ?>">
            <div class="col-md-4">
                <label class="small fw-bold">Data Corsa</label>
                <input type="date" name="data" class="form-control" value="<?= $data_filtro; ?>">
            </div>
            <div class="col-md-4">
                <label class="small fw-bold">Orario (HH:MM)</label>
                <input type="time" name="ora" class="form-control" value="<?= substr($ora_filtro, 0, 5); ?>" placeholder="Es: 09:30">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-sft-primary w-100">
                    <i class="bi bi-funnel"></i> Verifica Disponibilità
                </button>
            </div>
            <?php if (!empty($ora_filtro) && !empty($data_filtro)): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0 py-2 small">
                    <i class="bi bi-clock"></i> Materiale disponibile il
                    <strong><?= date('d/m/Y', strtotime($data_filtro)); ?></strong>
                    alle <strong><?= substr($ora_filtro, 0, 5); ?></strong>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <a href="admin_esercizio.php" class="btn btn-sft-ghost btn-sm">
            <i class="bi bi-arrow-left"></i> Indietro
        </a>
        <h3 class="fw-bold mb-0">
            Gestione: <span style="color:var(--sft-blu-acc); font-family:'JetBrains Mono','Courier New',monospace;"><?= htmlspecialchars($info_convoglio['NOME']); ?></span>
        </h3>
        <form method="POST" action="composizione.php?id=<?= $id_convoglio; ?>"
            onsubmit="return confirm('Proseguire con eliminazione convoglio <?= addslashes($info_convoglio['NOME']); ?>? (verranno cancellate le corse programmate)')">
            <input type="hidden" name="elimina_convoglio" value="1">
            <button type="submit" class="btn btn-sft-danger btn-sm">
                <i class="bi bi-trash"></i> Elimina Convoglio
            </button>
        </form>
    </div>

    <!-- MESSAGGIO OPERAZIONE -->
    <?php if (!empty($msg)): ?>
    <div class="alert alert-<?= $tipo; ?>"><?= $msg; ?></div>
    <?php endif; ?>

    <!-- AVVISO BLOCCO COMPOSIZIONE FUORI DEPOSITO -->
    <?php if ($modifica_bloccata): ?>
    <div class="status-box status-danger">
        <i class="bi bi-lock-fill" style="color:#dc2626; font-size:1.4rem;"></i>
        <div>
            <strong style="color:#991b1b;">Modifiche composizione bloccate</strong><br>
            <span style="color:#7f1d1d;"><?= $motivo_blocco; ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- STATUS BOX motore -->
    <?php if ($count_pezzi > 0): ?>
    <div class="status-box <?= $convoglio_valido ? 'status-ok' : 'status-warning'; ?>">
        <?php if ($convoglio_valido): ?>
            <i class="bi bi-check-circle-fill text-success me-2"></i>
            <strong>Convoglio valido</strong> — Ha almeno un motore e <?= $tot_posti; ?> posti disponibili. Pronto per le corse!
        <?php elseif (!$ha_motore): ?>
            <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
            <strong>Attenzione!</strong> — Manca il motore. Aggiungi una <strong>Locomotiva</strong> o <strong>Automotrice</strong>.
        <?php elseif ($tot_posti < 1): ?>
            <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
            <strong>Attenzione!</strong> — Il convoglio non ha <strong>posti a sedere</strong>.
            Aggiungi almeno una <strong>Carrozza</strong> o <strong>Bagagliaio</strong> con capacità &gt; 0 per poterlo usare.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="row mt-2">
        <!-- COMPONENTI ATTUALI -->
        <div class="col-md-6">
            <h5 class="fw-bold">
                <i class="bi bi-train-front text-primary"></i> Componenti del Convoglio
                <span class="badge-count ms-2"><?= $count_pezzi; ?></span>
            </h5>

            <?php if ($count_pezzi === 0): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> Nessun materiale assegnato.<br>
                    <small>Inizia aggiungendo una <strong>Locomotiva</strong> o <strong>Automotrice</strong>.</small>
                </div>
            <?php else: ?>
            <ul class="list-group shadow-sm">
                <?php
                $pezzi_attuali->data_seek(0);
                while ($p = $pezzi_attuali->fetch_assoc()):
                    $is_motore = in_array($p['TIPO'], ['Locomotiva', 'Automotrice']);
                ?>
                <li class="list-group-item d-flex justify-content-between align-items-center <?= $is_motore ? 'motore' : ''; ?>">
                    <div>
                        <strong><?= $p['MATRICOLA']; ?></strong> —
                        <?= htmlspecialchars($p['NOME']); ?>
                        <div class="mt-1">
                            <?php if ($is_motore): ?>
                                <span class="badge badge-motore">
                                    <i class="bi bi-lightning-fill me-1"></i><?= $p['TIPO']; ?>
                                </span>
                            <?php elseif ($p['TIPO'] === 'Carrozza'): ?>
                                <span class="badge badge-carrozza"><?= $p['TIPO']; ?></span>
                            <?php else: ?>
                                <span class="badge badge-bagagliaio"><?= $p['TIPO']; ?></span>
                            <?php endif; ?>
                            <span class="badge bg-light text-dark border"><?= $p['SERIE']; ?>, <?= $p['ANNO']; ?></span>
                            <?php if ($p['CAPACITA_POSTO']): ?>
                                <span class="badge bg-info text-dark"><?= $p['CAPACITA_POSTO']; ?> posti</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="POST" action="composizione.php?id=<?= $id_convoglio; ?>"
                          onsubmit="return confirm('Rimuovere <?= $p['MATRICOLA']; ?> dal convoglio?')">
                        <input type="hidden" name="rimuovi" value="<?= $p['MATRICOLA']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger ms-2"
                                <?= $modifica_bloccata ? 'disabled title="Modifiche bloccate: convoglio fuori dal deposito"' : ''; ?>>
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </li>
                <?php endwhile; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- MATERIALE DISPONIBILE -->
        <div class="col-md-6">
            <h5 class="fw-bold">
                <i class="bi bi-archive text-success"></i> Materiale in Deposito
                <span class="badge-count ms-2"><?= $count_pezzi; ?></span>
            </h5>

            <?php if ($count_pezzi === 0): ?>
            <div class="alert alert-info py-2 small mb-3">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Regola:</strong> Il primo componente deve essere una <strong>Locomotiva</strong> o <strong>Automotrice</strong>.
            </div>
            <?php endif; ?>

            <?php if ($pezzi_liberi->num_rows === 0): ?>
                <div class="alert alert-secondary">
                    <i class="bi bi-inbox"></i> Nessun materiale libero disponibile.
                </div>
            <?php else: ?>
                <form method="POST" action="composizione.php?id=<?= $id_convoglio; ?>" class="card p-3 shadow-sm">
                    <label class="small fw-bold mb-2">Seleziona materiale da aggiungere</label>
                    <select name="matricola" class="form-select mb-3" size="8" style="height:auto;" <?= $modifica_bloccata ? 'disabled' : ''; ?>>
                        <?php
                        $current_type = '';
                        while ($l = $pezzi_liberi->fetch_assoc()):
                            $is_motore = in_array($l['TIPO'], ['Locomotiva', 'Automotrice']);
                            if ($current_type !== $l['TIPO']) {
                                $current_type = $l['TIPO'];
                                echo '<option disabled style="background:#e8f0fe; font-weight:bold;">── ' . $l['TIPO'] . ' ──</option>';
                            }
                        ?>
                        <option value="<?= $l['MATRICOLA']; ?>" <?= $is_motore ? 'style="color:#16a34a; font-weight:600;"' : ''; ?>>
                            <?= $is_motore ? '⚡ ' : ''; ?><?= htmlspecialchars($l['NOME']); ?>
                            — <?= $l['SERIE']; ?> (<?= $l['ANNO']; ?>)
                            <?= $l['CAPACITA_POSTO'] ? '— ' . $l['CAPACITA_POSTO'] . ' posti' : ''; ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                    <button type="submit" name="aggiungi_pezzo" class="btn btn-sft-primary"
                            <?= $modifica_bloccata ? 'disabled title="Modifiche bloccate: convoglio fuori dal deposito"' : ''; ?>>
                        <i class="bi bi-plus-lg"></i> Aggiungi al Convoglio
                    </button>
                </form>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- MODAL CONFERMA ELIMINAZIONE -->
<?php if ($mostra_conferma_elimina): ?>
<div class="modal-backdrop-sft" role="dialog" aria-modal="true">
    <div class="sft-confirm-card">
        <div class="sft-confirm-header">
            <div class="sft-confirm-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <div>
                <h4 class="mb-1">Conferma eliminazione convoglio</h4>
                <small class="opacity-75">Convoglio <strong><?= htmlspecialchars($info_convoglio['NOME']); ?></strong>
                    · <?= count($corse_da_cancellare); ?> corse in programma</small>
            </div>
            <a href="composizione.php?id=<?= $id_convoglio; ?>" class="sft-close-x" title="Annulla">
                <i class="bi bi-x-lg"></i>
            </a>
        </div>

        <div class="sft-confirm-body">
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <div class="sft-stat-card">
                        <i class="bi bi-calendar-x sft-stat-icon" style="color:#004a99;"></i>
                        <div>
                            <div class="sft-stat-num"><?= count($corse_da_cancellare); ?></div>
                            <div class="sft-stat-label">Corse da cancellare</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="sft-stat-card">
                        <i class="bi bi-people-fill sft-stat-icon" style="color:#7c3aed;"></i>
                        <div>
                            <div class="sft-stat-num"><?= count($biglietti_da_rimborsare); ?></div>
                            <div class="sft-stat-label">Biglietti da rimborsare</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="sft-stat-card sft-stat-accent">
                        <i class="bi bi-cash-coin sft-stat-icon" style="color:#16a34a;"></i>
                        <div>
                            <div class="sft-stat-num">€ <?= number_format($totale_stimato_rimborso, 2, ',', '.'); ?></div>
                            <div class="sft-stat-label">Totale rimborsi</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="sft-section-title">
                <i class="bi bi-list-check"></i> Corse che verranno eliminate
            </div>
            <div class="sft-corse-list mb-4">
                <?php foreach ($corse_da_cancellare as $cc): ?>
                <div class="sft-corsa-row">
                    <div class="sft-corsa-badge">#<?= $cc['IDCORSA']; ?></div>
                    <div class="sft-corsa-info">
                        <strong><?= date('d/m/Y', strtotime($cc['DATA'])); ?></strong>
                        <span class="sft-time-pill"><?= substr($cc['ORA'], 0, 5); ?></span>
                        <span class="sft-dir-pill sft-dir-<?= strtolower($cc['DIREZIONE']); ?>">
                            <?= $cc['DIREZIONE']; ?>
                        </span>
                    </div>
                    <div class="sft-corsa-tickets">
                        <?php if (!empty($cc['biglietti'])): ?>
                            <span class="sft-ticket-count">
                                <i class="bi bi-ticket-perforated"></i> <?= count($cc['biglietti']); ?>
                            </span>
                        <?php else: ?>
                            <span class="sft-ticket-empty">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($biglietti_da_rimborsare)): ?>
            <div class="sft-section-title">
                <i class="bi bi-arrow-return-left"></i> Rimborsi PaySteam
            </div>
            <div class="sft-rimborsi-list mb-3">
                <?php foreach ($biglietti_da_rimborsare as $bg): ?>
                <div class="sft-rimborso-row">
                    <div>
                        <strong><?= htmlspecialchars(($bg['NOME'] ?? '') . ' ' . ($bg['COGNOME'] ?? '')); ?></strong>
                        <div class="sft-rimborso-sub">
                            #<?= $bg['IDBIGLIETTO']; ?> · <?= $bg['PARTENZA']; ?> → <?= $bg['ARRIVO']; ?>
                        </div>
                    </div>
                    <div class="sft-rimborso-amount">€ <?= number_format($bg['COSTO'], 2, ',', '.'); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="sft-info-note">
                <i class="bi bi-info-circle"></i> Nessun biglietto pagato: nessun rimborso da effettuare.
            </div>
            <?php endif; ?>

            <div class="sft-warning-strip">
                <i class="bi bi-shield-exclamation"></i>
                L'operazione è irreversibile: corse, biglietti e convoglio verranno rimossi definitivamente.
            </div>
        </div>

        <div class="sft-confirm-footer">
            <a href="composizione.php?id=<?= $id_convoglio; ?>" class="btn btn-sft-ghost">
                <i class="bi bi-x"></i> Annulla
            </a>
            <form method="POST" action="composizione.php?id=<?= $id_convoglio; ?>" class="d-inline">
                <input type="hidden" name="elimina_convoglio" value="1">
                <input type="hidden" name="conferma_elimina" value="1">
                <button type="submit" class="btn btn-sft-danger">
                    <i class="bi bi-check2-circle"></i> Conferma eliminazione e rimborsi
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>