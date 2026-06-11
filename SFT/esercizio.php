<?php
session_name('SFT_SESSION'); session_start();
include('connessione.php');
date_default_timezone_set('Europe/Rome');

function ts_time($hhmmss) { return strtotime("1970-01-01" . $hhmmss); }
function overlap($aStart, $aEnd, $bStart, $bEnd) { return ($aStart < $bEnd && $aEnd > $bStart); }

function applicaSostaPerIncrocio(array &$fermate_mem, int $indice_sosta, int $ritardo_sec) {
    if (!empty($fermate_mem[$indice_sosta]['ORAP'])) {
        $fermate_mem[$indice_sosta]['ORAP'] = date('H:i:s', ts_time($fermate_mem[$indice_sosta]['ORAP']) + $ritardo_sec);
    }
    for ($k = $indice_sosta + 1; $k < count($fermate_mem); $k++) {
        if (!empty($fermate_mem[$k]['ORAP'])) {
            $fermate_mem[$k]['ORAP'] = date('H:i:s', ts_time($fermate_mem[$k]['ORAP']) + $ritardo_sec);
        }
        if (!empty($fermate_mem[$k]['ORAA'])) {
            $fermate_mem[$k]['ORAA'] = date('H:i:s', ts_time($fermate_mem[$k]['ORAA']) + $ritardo_sec);
        }
    }
}

function costruisciFermateBase(array $template, int $base_ts) {
    $fermate = [];
    for ($i = 0; $i < count($template); $i++) {
        $orap = $template[$i][1] !== null ? date('H:i:s', $base_ts + $template[$i][1]) : null;
        $oraa = $template[$i][2] !== null ? date('H:i:s', $base_ts + $template[$i][2]) : null;
        $fermate[] = [
            'IDSTAZIONE'  => $template[$i][0],
            'PROGRESSIVO' => $i + 1,
            'ORAP'        => $orap,
            'ORAA'        => $oraa
        ];
    }
    return $fermate;
}

function invertiTemplate($template) {
    $max = 0;
    foreach ($template as $t) {
        if ($t[1] !== null) $max = max($max, $t[1]);
        if ($t[2] !== null) $max = max($max, $t[2]);
    }
    $out = [];
    for ($i = count($template) - 1; $i >= 0; $i--) {
        $dep = $template[$i][1]; $arr = $template[$i][2];
        $out[] = [$template[$i][0],
                  $arr !== null ? $max - $arr : null,
                  $dep !== null ? $max - $dep : null];
    }
    return $out;
}
//   TEMPLATE STANDARD (Sud: 1 -> 10)
function templateSud() {
    return [
        [1, 0*60,  null ],
        [2, 6*60,  4*60 ],
        [3, 14*60, 12*60],
        [4, 22*60, 20*60],
        [5, 29*60, 27*60],
        [6, 40*60, 38*60],
        [7, 52*60, 50*60],
        [8, 64*60, 62*60],
        [9, 74*60, 72*60],
        [10, null, 85*60],
    ];
}

function overlapCorsaConvoglio($con, $data, $id_convoglio, int $ini_ts, int $fin_ts, int $id_corsa_escludi = 0) {
    $excl = $id_corsa_escludi > 0 ? " AND C.IDCORSA != $id_corsa_escludi " : "";
    $r = $con->query("
        SELECT C.IDCORSA, C.ORA, C.DIREZIONE, CO.NOME AS NOME_CONV
        FROM SFT_CORSA C JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO = CO.IDCONVOGLIO
        WHERE C.DATA = '$data' AND C.IDCONVOGLIO = $id_convoglio
          AND C.STATO NOT IN ('Annullata')
          $excl
    ");
    while ($cc = $r->fetch_assoc()) {
        $id_cc = $cc['IDCORSA'];
        $rf = $con->query("SELECT ORAP, ORAA FROM SFT_FERMATA WHERE IDCORSA=$id_cc ORDER BY PROGRESSIVO ASC");
        $fa = $rf->fetch_all(MYSQLI_ASSOC);
        $altra_ini = null; $altra_fin = null;
        foreach ($fa as $f) {
            if (!empty($f['ORAP']) && $altra_ini === null) $altra_ini = $f['ORAP'];
            if (!empty($f['ORAA'])) $altra_fin = $f['ORAA'];
        }
        if (!$altra_ini || !$altra_fin) {
            $altra_ini = $cc['ORA'];
            $altra_fin = date('H:i:s', ts_time($cc['ORA']) + 85*60);
        }
        if (overlap($ini_ts, $fin_ts, ts_time($altra_ini), ts_time($altra_fin))) {
            return [
                'IDCORSA'   => $id_cc,
                'NOME_CONV' => $cc['NOME_CONV'],
                'DIREZIONE' => $cc['DIREZIONE'],
                'ini'       => $altra_ini,
                'fin'       => $altra_fin,
            ];
        }
    }
    return null;
}

function bloccoPerCorsaSuccessiva($posizione, int $fin_nuova_ts) {
    if (empty($posizione['corsa_successiva'])) return null;
    $ora_prox = $posizione['corsa_successiva']['ora_partenza'];
    if (ts_time($ora_prox) < $fin_nuova_ts) {
        return [
            'IDCORSA'     => $posizione['corsa_successiva']['IDCORSA'],
            'ora_partenza'=> $ora_prox,
        ];
    }
    return null;
}

function ritardoTrasferimentoVuoto($con, $data, $ora_partenza_trasf, $id_staz_partenza, $id_staz_arrivo, $stazioni_map, int $id_corsa_escludi = 0) {
    $template_sud = templateSud();
    $template = ($id_staz_partenza > $id_staz_arrivo) ? invertiTemplate($template_sud) : $template_sud;
    $direzione_trasf = ($id_staz_partenza > $id_staz_arrivo) ? 'Nord' : 'Sud';

    $base_ts = ts_time($ora_partenza_trasf);
    $fermate_trasf = costruisciFermateBase($template, $base_ts);

    $excl = $id_corsa_escludi > 0 ? " AND C.IDCORSA != $id_corsa_escludi " : "";
    $r_altre = $con->query("
        SELECT DISTINCT C.IDCORSA, C.DIREZIONE FROM SFT_CORSA C
        WHERE C.DATA = '$data' AND C.STATO NOT IN ('Annullata') $excl
    ");
    $corse_esistenti = [];
    while ($c = $r_altre->fetch_assoc()) {
        $id_c = (int)$c['IDCORSA'];
        $rf = $con->query("
            SELECT F.PROGRESSIVO,F.ORAP,F.ORAA,S.KMPROGRESSIVO,F.IDSTAZIONE
            FROM SFT_FERMATA F JOIN SFT_STAZIONE S ON F.IDSTAZIONE=S.IDSTAZIONE
            WHERE F.IDCORSA=$id_c ORDER BY F.PROGRESSIVO ASC
        ");
        $c['fermate'] = $rf->fetch_all(MYSQLI_ASSOC);
        $corse_esistenti[] = $c;
    }

    $ritardo_tot = 0;
    $soste = [];

    for ($i = 0; $i < count($fermate_trasf) - 1; $i++) {
        $max_iter = 10; $iter = 0;
        while ($iter++ < $max_iter) {
            $trovato = false;
            $da = array_merge($fermate_trasf[$i],  ['KMPROGRESSIVO' => $stazioni_map[$fermate_trasf[$i]['IDSTAZIONE']]['KMPROGRESSIVO'],  'NOME' => $stazioni_map[$fermate_trasf[$i]['IDSTAZIONE']]['NOME']]);
            $a  = array_merge($fermate_trasf[$i+1],['KMPROGRESSIVO' => $stazioni_map[$fermate_trasf[$i+1]['IDSTAZIONE']]['KMPROGRESSIVO'],'NOME' => $stazioni_map[$fermate_trasf[$i+1]['IDSTAZIONE']]['NOME']]);
            if (empty($da['ORAA']) && empty($da['ORAP'])) break;
            if (empty($a['ORAP']) && empty($a['ORAA']))   break;

            $ini_n = !empty($da['ORAP']) ? ts_time($da['ORAP']) : ts_time($da['ORAA']);
            $fin_n = !empty($a['ORAA'])  ? ts_time($a['ORAA'])  : ts_time($a['ORAP']);
            $km_min = min($da['KMPROGRESSIVO'], $a['KMPROGRESSIVO']);
            $km_max = max($da['KMPROGRESSIVO'], $a['KMPROGRESSIVO']);

            foreach ($corse_esistenti as $altra) {
                $fermate_altra = $altra['fermate'];
                for ($j = 0; $j < count($fermate_altra) - 1; $j++) {
                    $fa = $fermate_altra[$j]; $fb = $fermate_altra[$j+1];
                    if ((empty($fa['ORAA']) && empty($fa['ORAP'])) || (empty($fb['ORAP']) && empty($fb['ORAA']))) continue;
                    $km_min_a = min($fa['KMPROGRESSIVO'], $fb['KMPROGRESSIVO']);
                    $km_max_a = max($fa['KMPROGRESSIVO'], $fb['KMPROGRESSIVO']);
                    if ($km_min != $km_min_a || $km_max != $km_max_a) continue;

                    $ini_a = !empty($fa['ORAP']) ? ts_time($fa['ORAP']) : ts_time($fa['ORAA']);
                    $fin_a = !empty($fb['ORAA']) ? ts_time($fb['ORAA']) : ts_time($fb['ORAP']);

                    if (!overlap($ini_n, $fin_n, $ini_a, $fin_a)) continue;

                    $margine = 60;
                    $rit = ($fin_a + $margine) - $ini_n;
                    if ($rit > 0) {
                        $tipo = ($direzione_trasf === $altra['DIREZIONE']) ? 'tamponamento' : 'incrocio';
                        applicaSostaPerIncrocio($fermate_trasf, $i, $rit);
                        $ritardo_tot += $rit;
                        $soste[] = [
                            'stazione' => $da['NOME'],
                            'minuti'   => ceil($rit/60),
                            'tipo'     => $tipo,
                            'corsa_id' => $altra['IDCORSA'],
                            'tratta_verso' => $a['NOME']
                        ];
                        $trovato = true;
                        break 2;
                    }
                }
            }
            if (!$trovato) break;
        }
    }

    return ['ritardo_sec' => $ritardo_tot, 'soste' => $soste, 'fermate_trasf' => $fermate_trasf];
}

function trovaPosizioneConvoglio($con, $id_convoglio, $data, $ora_richiesta, int $id_corsa_escludi = 0) {
    $excl = $id_corsa_escludi > 0 ? " AND C.IDCORSA != $id_corsa_escludi " : "";
    $r = $con->query("
        SELECT C.IDCORSA, C.ORA, C.DIREZIONE
        FROM SFT_CORSA C
        WHERE C.IDCONVOGLIO = $id_convoglio
        AND C.DATA = '$data'
        AND C.STATO NOT IN ('Annullata')
        $excl
        ORDER BY C.ORA ASC
    ");
    $corse = $r->fetch_all(MYSQLI_ASSOC);

    if (count($corse) == 0) {
        return ['stazione_id' => 1, 'stazione_nome' => 'Torre Spaventa (Deposito)',
                'disponibile_da' => '00:00:00', 'in_viaggio' => false,
                'corsa_successiva' => null];
    }

    $ultima_arrivata = null;
    $corsa_in_viaggio = null;
    $corsa_futura = null;

    foreach ($corse as $c) {
        $id_c = $c['IDCORSA'];
        $rf = $con->query("
            SELECT F.PROGRESSIVO, F.ORAP, F.ORAA, F.IDSTAZIONE, S.NOME
            FROM SFT_FERMATA F JOIN SFT_STAZIONE S ON F.IDSTAZIONE=S.IDSTAZIONE
            WHERE F.IDCORSA=$id_c ORDER BY F.PROGRESSIVO ASC
        ");
        $fermate = $rf->fetch_all(MYSQLI_ASSOC);
        if (count($fermate) == 0) continue;

        $prima_p = null; $ultima_a = null;
        foreach ($fermate as $f) {
            if (!empty($f['ORAP']) && $prima_p === null) $prima_p = $f['ORAP'];
            if (!empty($f['ORAA'])) $ultima_a = $f['ORAA'];
        }
        $ultima_staz = $fermate[count($fermate) - 1];

        if ($ultima_a && $ora_richiesta >= $ultima_a) {
            $ultima_arrivata = [
                'IDCORSA' => $id_c,
                'ora_arrivo' => $ultima_a,
                'stazione_id' => (int)$ultima_staz['IDSTAZIONE'],
                'stazione_nome' => $ultima_staz['NOME'],
            ];
        } elseif ($prima_p && $ora_richiesta >= $prima_p) {
            $corsa_in_viaggio = [
                'IDCORSA' => $id_c,
                'ora_arrivo' => $ultima_a,
                'stazione_id' => (int)$ultima_staz['IDSTAZIONE'],
                'stazione_nome' => $ultima_staz['NOME'],
            ];
        } else {
            if ($corsa_futura === null) {
                $corsa_futura = ['IDCORSA' => $id_c, 'ora_partenza' => $prima_p];
            }
        }
    }

    if ($corsa_in_viaggio) {
        return ['stazione_id' => $corsa_in_viaggio['stazione_id'],
                'stazione_nome' => $corsa_in_viaggio['stazione_nome'],
                'disponibile_da' => $corsa_in_viaggio['ora_arrivo'],
                'in_viaggio' => true,
                'corsa_successiva' => $corsa_futura];
    }
    if ($ultima_arrivata) {
        return ['stazione_id' => $ultima_arrivata['stazione_id'],
                'stazione_nome' => $ultima_arrivata['stazione_nome'],
                'disponibile_da' => $ultima_arrivata['ora_arrivo'],
                'in_viaggio' => false,
                'corsa_successiva' => $corsa_futura];
    }
    return ['stazione_id' => 1, 'stazione_nome' => 'Torre Spaventa (Deposito)',
            'disponibile_da' => '00:00:00', 'in_viaggio' => false,
            'corsa_successiva' => $corsa_futura];
}

function calcolaCorsaVuoto($con, $data, $ora_partenza_corsa, $id_staz_partenza_corsa,
                            $posizione_convoglio, $stazioni_map, int $id_corsa_escludi = 0) {
    $DISTANZA_KM   = 54.68;
    $VELOCITA_KMH  = 50;
    $TEMPO_PURO_SEC= round(($DISTANZA_KM / $VELOCITA_KMH) * 3600);
    $MARGINE_SEC   = 5 * 60;

    $staz_reale_id = (int)$posizione_convoglio['stazione_id'];

    if ($staz_reale_id === $id_staz_partenza_corsa) {
        return ['necessaria' => false, 'motivo' => 'Convoglio già in posizione'];
    }

    $ora_libero_ts = ts_time($posizione_convoglio['disponibile_da']);
    $ora_arrivo_desiderata_ts = ts_time($ora_partenza_corsa) - $MARGINE_SEC;
    $ora_partenza_trasf_ts = $ora_arrivo_desiderata_ts - $TEMPO_PURO_SEC;
    if ($ora_partenza_trasf_ts < $ora_libero_ts) {
        $ora_partenza_trasf_ts = $ora_libero_ts;
    }
    $ora_partenza_trasf = date('H:i:s', $ora_partenza_trasf_ts);

    $rit = ritardoTrasferimentoVuoto($con, $data, $ora_partenza_trasf,
                                     $staz_reale_id, $id_staz_partenza_corsa, $stazioni_map, $id_corsa_escludi);

    $ora_arrivo_effettiva_ts = $ora_partenza_trasf_ts + $TEMPO_PURO_SEC + $rit['ritardo_sec'];
    $ora_min_corsa_ts = $ora_arrivo_effettiva_ts + $MARGINE_SEC;

    return [
        'necessaria'         => true,
        'stazione_partenza'  => $stazioni_map[$staz_reale_id]['NOME'],
        'stazione_arrivo'    => $stazioni_map[$id_staz_partenza_corsa]['NOME'],
        'ora_partenza'       => $ora_partenza_trasf,
        'ora_arrivo'         => date('H:i:s', $ora_arrivo_effettiva_ts),
        'ora_min_corsa'      => date('H:i:s', $ora_min_corsa_ts),
        'tempo_puro_min'     => ceil($TEMPO_PURO_SEC / 60),
        'ritardo_soste_min'  => ceil($rit['ritardo_sec'] / 60),
        'tempo_totale_min'   => ceil(($TEMPO_PURO_SEC + $rit['ritardo_sec']) / 60),
        'distanza_km'        => $DISTANZA_KM,
        'soste'              => $rit['soste'],
    ];
}

function pianificaCorsa($con, $data, $ora_originale, $id_convoglio, $direzione,
                        $stazioni_map, bool $check_limiti, int $id_corsa_escludi = 0) {

    $messaggi_posticipo = [];
    $messaggi_soste     = [];
    $messaggi_trasferimento = [];

    $ora = $ora_originale;
    $template = ($direzione === 'Nord') ? invertiTemplate(templateSud()) : templateSud();
    $id_staz_partenza   = $template[0][0];
    $id_staz_successiva = $template[1][0];
    $nome_staz_partenza   = $stazioni_map[$id_staz_partenza]['NOME']   ?? 'stazione iniziale';
    $nome_staz_successiva = $stazioni_map[$id_staz_successiva]['NOME'] ?? 'stazione successiva';

    /* limiti corse / festività */
    $timestamp    = strtotime($data);
    $giorno_sett  = (int)date('N', $timestamp);
    $mese         = (int)date('m', $timestamp);
    $giorno_mese  = date('d-m', $timestamp);
    $anno         = (int)date('Y', $timestamp);
    $festivi_fissi  = ['01-01','06-01','25-04','01-05','02-06','15-08','01-11','08-12','25-12','26-12'];
    $pasqua = easter_date($anno);
    $festivi_mobili = [date('d-m', $pasqua), date('d-m', strtotime('+1 day', $pasqua))];
    $is_festivo = ($giorno_sett == 7) || in_array($giorno_mese, $festivi_fissi) || in_array($giorno_mese, $festivi_mobili);

    if ($check_limiti) {
        $excl = $id_corsa_escludi > 0 ? " AND IDCORSA != $id_corsa_escludi " : "";
        if ($is_festivo) {
            $tot = (int)$con->query("SELECT COUNT(*) AS T FROM SFT_CORSA WHERE DATA='$data' AND STATO NOT IN ('Annullata') $excl")->fetch_assoc()['T'];
            if ($tot >= 8) {
                return ['ok'=>false,'errore'=>"⚠️ Limite raggiunto: festivi max 8 corse/giorno (già $tot)."];
            }
        } else {
            if ($mese < 6 || $mese > 9) {
                return ['ok'=>false,'errore'=>"⚠️ Nei feriali le corse sono previste solo dal 1° giugno al 30 settembre."];
            }
            $tot = (int)$con->query("SELECT COUNT(*) AS T FROM SFT_CORSA WHERE DATA='$data' AND STATO NOT IN ('Annullata') $excl")->fetch_assoc()['T'];
            if ($tot >= 2) {
                return ['ok'=>false,'errore'=>"⚠️ Limite raggiunto: feriali max 1 coppia (2 corse/giorno, già $tot)."];
            }
        }
    }

    $DURATA_CORSA_SEC = 85 * 60;
    $nuova_ini_ts = ts_time($ora);
    $nuova_fin_ts = $nuova_ini_ts + $DURATA_CORSA_SEC;

    $conf = overlapCorsaConvoglio($con, $data, $id_convoglio, $nuova_ini_ts, $nuova_fin_ts, $id_corsa_escludi);
    if ($conf) {
        return ['ok'=>false,'errore'=>
            "⚠️ Il treno '{$conf['NOME_CONV']}' è già impegnato sulla corsa #{$conf['IDCORSA']} "
            . "(in viaggio dalle " . substr($conf['ini'],0,5)
            . " alle " . substr($conf['fin'],0,5) . " verso " . $conf['DIREZIONE'] . "). "
            . "La corsa andrebbe dalle " . substr($ora,0,5)
            . " alle " . date('H:i', $nuova_fin_ts) . ": un convoglio non può "
            . "effettuare due tratte contemporaneamente. "
            . "Scegli un altro treno o un orario dopo le " . substr($conf['fin'],0,5) . "."];
    }

    /* posizione reale convoglio */
    $posizione = trovaPosizioneConvoglio($con, $id_convoglio, $data, $ora, $id_corsa_escludi);
    if ($posizione['in_viaggio']) {
        return ['ok'=>false,'errore'=>
            "⚠️ Convoglio non disponibile: all'orario " . substr($ora,0,5)
            . " sta ancora completando la corsa precedente (arriva a {$posizione['stazione_nome']} alle "
            . substr($posizione['disponibile_da'],0,5) . "). Scegli un orario successivo."];
    }

    /* check corsa successiva */
    $bloccoSucc = bloccoPerCorsaSuccessiva($posizione, $nuova_fin_ts);
    if ($bloccoSucc) {
        return ['ok'=>false,'errore'=>
            "⚠️ Convoglio non disponibile: ha già la corsa #"
            . $bloccoSucc['IDCORSA']
            . " programmata alle " . substr($bloccoSucc['ora_partenza'],0,5)
            . " che partirebbe prima che questa nuova corsa sia terminata. "
            . "Scegliere uno libero o cambiare orario."];
    }

    /* trasferimento a vuoto */
    $corsa_vuoto = calcolaCorsaVuoto($con, $data, $ora, $id_staz_partenza, $posizione, $stazioni_map, $id_corsa_escludi);

    if ($corsa_vuoto['necessaria']) {
        $ora_min_ts = ts_time($corsa_vuoto['ora_min_corsa']);
        $ora_req_ts = ts_time($ora);

        if ($ora_req_ts < $ora_min_ts) {
            $soste_originarie = !empty($corsa_vuoto['soste']) ? $corsa_vuoto['soste'] : [];
            $ora = $corsa_vuoto['ora_min_corsa'];
            $corsa_vuoto = calcolaCorsaVuoto($con, $data, $ora, $id_staz_partenza, $posizione, $stazioni_map, $id_corsa_escludi);

            $msg_post = "Orario posticipato da " . substr($ora_originale,0,5)
                . " a " . substr($ora,0,5) . " per consentire trasferimento a vuoto";
            if (!empty($soste_originarie)) {
                $dettagli = [];
                foreach ($soste_originarie as $s0) {
                    $dettagli[] = $s0['tipo'] . " con corsa #" . $s0['corsa_id']
                        . " presso " . $s0['stazione']
                        . " (tratta verso " . $s0['tratta_verso'] . ")";
                }
                $msg_post .= " — causa sul trasferimento: " . implode("; ", $dettagli);
            }
            $msg_post .= ".";
            $messaggi_posticipo[] = $msg_post;

            $nuova_fin_ts_post = ts_time($ora) + $DURATA_CORSA_SEC;
            $bloccoSucc2 = bloccoPerCorsaSuccessiva($posizione, $nuova_fin_ts_post);
            if ($bloccoSucc2) {
                return ['ok'=>false,'errore'=>
                    "⚠️ Convoglio non disponibile per trasferimento: dopo il posticipo necessario alle "
                    . substr($ora,0,5) . " sforerebbe sulla corsa #" . $bloccoSucc2['IDCORSA']
                    . " delle " . substr($bloccoSucc2['ora_partenza'],0,5) . "."];
            }
        }

        $msgT = "🚂 TRASFERIMENTO A VUOTO: da {$corsa_vuoto['stazione_partenza']} alle "
             . substr($corsa_vuoto['ora_partenza'],0,5)
             . " → {$corsa_vuoto['stazione_arrivo']} alle " . substr($corsa_vuoto['ora_arrivo'],0,5)
             . " ({$corsa_vuoto['distanza_km']} km, {$corsa_vuoto['tempo_puro_min']} min";
        if ($corsa_vuoto['ritardo_soste_min'] > 0) {
            $msgT .= " + {$corsa_vuoto['ritardo_soste_min']} min soste = {$corsa_vuoto['tempo_totale_min']} min";
        }
        $msgT .= ").";
        $messaggi_trasferimento[] = $msgT;
        foreach ($corsa_vuoto['soste'] as $s) {
            $messaggi_trasferimento[] = "⏸️ Sosta {$s['stazione']} (+{$s['minuti']} min, {$s['tipo']} con corsa #{$s['corsa_id']}).";
        }
    }

    /* conflitto stessa direzione */
    $ora_richiesta_ts = ts_time($ora);
    $tempo_prima_tratta_sec = !empty($template[1][2]) ? $template[1][2] : (!empty($template[1][1]) ? $template[1][1] : 300);
    $posticipo_eff = false;
    $iter = 0;
    $excl_corsa = $id_corsa_escludi > 0 ? " AND C.IDCORSA != $id_corsa_escludi " : "";
    while ($iter++ < 10) {
        $trovato = false;
        $nostro_arrivo_ts = $ora_richiesta_ts + $tempo_prima_tratta_sec;
        $r_c = $con->query("
            SELECT CO.NOME AS TRENO, F.ORAA AS ARR
            FROM SFT_CORSA C JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO=CO.IDCONVOGLIO
            JOIN SFT_FERMATA F ON F.IDCORSA=C.IDCORSA
            WHERE C.DATA='$data' AND C.DIREZIONE='$direzione'
            AND F.IDSTAZIONE=$id_staz_successiva AND F.ORAA IS NOT NULL
            AND C.STATO NOT IN ('Annullata')
            $excl_corsa
            ORDER BY F.ORAA ASC
        ");
        while ($conf2 = $r_c->fetch_assoc()) {
            $arr_ts = ts_time($conf2['ARR']);
            if (abs($nostro_arrivo_ts - $arr_ts) < 60 ||
                ($ora_richiesta_ts <= $arr_ts && $nostro_arrivo_ts >= $arr_ts)) {
                $nuovo = $arr_ts + 60;
                if ($nuovo > $ora_richiesta_ts) {
                    $ora_richiesta_ts = $nuovo; $ora = date('H:i:s', $nuovo);
                    $messaggi_posticipo[] = "Corsa '{$conf2['TRENO']}' → posticipo a " . substr($ora,0,5);
                    $trovato = true; $posticipo_eff = true; break;
                }
            }
        }
        if (!$trovato) break;
    }

    /* conflitto materiale rotabile */
    $r_mat = $con->query("
        SELECT COMP.MATRICOLAMAT, MR.NOME AS NOME_M
        FROM SFT_COMPOSTO COMP JOIN SFT_MATERIALEROTABILE MR ON COMP.MATRICOLAMAT=MR.MATRICOLA
        WHERE COMP.IDCONVOGLIO=$id_convoglio
    ");
    while ($mm = $r_mat->fetch_assoc()) {
        $matr = $mm['MATRICOLAMAT']; $nome_m = $mm['NOME_M'];
        $excl_mat = $id_corsa_escludi > 0 ? " AND C.IDCORSA != $id_corsa_escludi " : "";
        $r_cm = $con->query("
            SELECT C.IDCORSA, C.ORA, CO.NOME AS NOME_C
            FROM SFT_CORSA C JOIN SFT_CONVOGLIO CO ON C.IDCONVOGLIO=CO.IDCONVOGLIO
            JOIN SFT_COMPOSTO COMP ON CO.IDCONVOGLIO=COMP.IDCONVOGLIO
            WHERE COMP.MATRICOLAMAT='$matr' AND C.DATA='$data' AND C.STATO NOT IN ('Annullata')
              AND C.IDCONVOGLIO != $id_convoglio
              $excl_mat
        ");
        while ($cm = $r_cm->fetch_assoc()) {
            $idc = $cm['IDCORSA'];
            $rf = $con->query("SELECT ORAP, ORAA FROM SFT_FERMATA WHERE IDCORSA=$idc ORDER BY PROGRESSIVO ASC");
            $f_all = $rf->fetch_all(MYSQLI_ASSOC);
            $pp = null; $ua = null;
            foreach ($f_all as $f) {
                if (!empty($f['ORAP']) && $pp === null) $pp = $f['ORAP'];
                if (!empty($f['ORAA'])) $ua = $f['ORAA'];
            }
            if ($pp && $ua && $ora >= $pp && $ora <= $ua) {
                return ['ok'=>false,'errore'=>
                    "⚠️ Conflitto materiale: '$nome_m' in viaggio con '{$cm['NOME_C']}'. Attendi dopo le " . substr($ua,0,5) . "."];
            }
        }
    }

    /* costruzione fermate corsa */
    $base_ts = ts_time($ora);
    $fermate_mem = costruisciFermateBase($template, $base_ts);

    $excl_alt = $id_corsa_escludi > 0 ? " AND C.IDCORSA != $id_corsa_escludi " : "";
    $r_altre = $con->query("SELECT DISTINCT C.IDCORSA, C.ORA, C.DIREZIONE FROM SFT_CORSA C WHERE C.DATA='$data' AND C.STATO NOT IN ('Annullata') $excl_alt");
    $corse_esistenti = [];
    while ($c = $r_altre->fetch_assoc()) {
        $id_c = $c['IDCORSA'];
        $rf = $con->query("
            SELECT F.PROGRESSIVO, F.ORAP, F.ORAA, S.NOME, S.KMPROGRESSIVO, S.IDSTAZIONE
            FROM SFT_FERMATA F JOIN SFT_STAZIONE S ON F.IDSTAZIONE=S.IDSTAZIONE
            WHERE F.IDCORSA=$id_c ORDER BY F.PROGRESSIVO ASC
        ");
        $c['fermate'] = $rf->fetch_all(MYSQLI_ASSOC);
        $corse_esistenti[] = $c;
    }

    $fermate_nuova = [];
    foreach ($fermate_mem as $f) {
        $fermate_nuova[] = array_merge($f, [
            'NOME'          => $stazioni_map[$f['IDSTAZIONE']]['NOME'],
            'KMPROGRESSIVO' => $stazioni_map[$f['IDSTAZIONE']]['KMPROGRESSIVO'],
        ]);
    }

    $ritardi_dettaglio = [];
    if ($posticipo_eff) {
        $ritardi_dettaglio[$id_staz_partenza] = [
            'minuti'        => ceil((ts_time($ora) - ts_time($ora_originale)) / 60),
            'tipo'          => 'posticipo partenza',
            'corsa_causa'   => 'conflitto stessa direzione',
            'stazione_nome' => $nome_staz_partenza,
            'tratta_verso'  => $nome_staz_successiva
        ];
    }
    $ritardo_acc = 0;

    for ($i = 0; $i < count($fermate_nuova) - 1; $i++) {
        $iter = 0;
        while ($iter++ < 10) {
            $confl = false;
            $da = $fermate_nuova[$i]; $a = $fermate_nuova[$i+1];
            if (empty($da['ORAA']) && empty($da['ORAP'])) break;
            if (empty($a['ORAP']) && empty($a['ORAA']))   break;
            $ini_n = !empty($da['ORAP']) ? ts_time($da['ORAP']) : ts_time($da['ORAA']);
            $fin_n = !empty($a['ORAA'])  ? ts_time($a['ORAA'])  : ts_time($a['ORAP']);
            $km_min = min($da['KMPROGRESSIVO'], $a['KMPROGRESSIVO']);
            $km_max = max($da['KMPROGRESSIVO'], $a['KMPROGRESSIVO']);

            foreach ($corse_esistenti as $altra) {
                $fa_all = $altra['fermate'];
                for ($j = 0; $j < count($fa_all) - 1; $j++) {
                    $fa = $fa_all[$j]; $fb = $fa_all[$j+1];
                    if ((empty($fa['ORAA']) && empty($fa['ORAP'])) || (empty($fb['ORAP']) && empty($fb['ORAA']))) continue;
                    $km_min_a = min($fa['KMPROGRESSIVO'], $fb['KMPROGRESSIVO']);
                    $km_max_a = max($fa['KMPROGRESSIVO'], $fb['KMPROGRESSIVO']);
                    if ($km_min != $km_min_a || $km_max != $km_max_a) continue;
                    $ini_a = !empty($fa['ORAP']) ? ts_time($fa['ORAP']) : ts_time($fa['ORAA']);
                    $fin_a = !empty($fb['ORAA']) ? ts_time($fb['ORAA']) : ts_time($fb['ORAP']);
                    if (!overlap($ini_n, $fin_n, $ini_a, $fin_a)) continue;

                    $rit_sec = ($fin_a + 60) - $ini_n;
                    if ($rit_sec > 0) {
                        $tipo = ($direzione === $altra['DIREZIONE']) ? 'tamponamento' : 'incrocio';
                        applicaSostaPerIncrocio($fermate_mem, $i, $rit_sec);
                        $ritardo_acc += $rit_sec;
                        $id_ss = $da['IDSTAZIONE'];
                        if (isset($ritardi_dettaglio[$id_ss])) {
                            $ritardi_dettaglio[$id_ss]['minuti'] += ceil($rit_sec/60);
                            $ritardi_dettaglio[$id_ss]['tipo']   .= " + $tipo";
                            $ritardi_dettaglio[$id_ss]['corsa_causa'] .= ", " . $altra['IDCORSA'];
                        } else {
                            $ritardi_dettaglio[$id_ss] = [
                                'minuti'=>ceil($rit_sec/60),'tipo'=>$tipo,
                                'corsa_causa'=>$altra['IDCORSA'],
                                'stazione_nome'=>$da['NOME'],'tratta_verso'=>$a['NOME']
                            ];
                        }
                        $messaggi_soste[] = "{$da['NOME']} (+".ceil($rit_sec/60)." min, $tipo con corsa #{$altra['IDCORSA']} → {$a['NOME']})";
                        $fermate_nuova = [];
                        foreach ($fermate_mem as $fm) {
                            $fermate_nuova[] = array_merge($fm, [
                                'NOME' => $stazioni_map[$fm['IDSTAZIONE']]['NOME'],
                                'KMPROGRESSIVO' => $stazioni_map[$fm['IDSTAZIONE']]['KMPROGRESSIVO'],
                            ]);
                        }
                        $confl = true; break 2;
                    }
                }
            }
            if (!$confl) break;
        }
    }
    if (!empty($fermate_mem[0]['ORAP'])) $ora = $fermate_mem[0]['ORAP'];

    $ini_reale_ts = null; $fin_reale_ts = null;
    foreach ($fermate_mem as $fm) {
        if (!empty($fm['ORAP']) && $ini_reale_ts === null) $ini_reale_ts = ts_time($fm['ORAP']);
        if (!empty($fm['ORAA'])) $fin_reale_ts = ts_time($fm['ORAA']);
    }
    if ($ini_reale_ts === null) $ini_reale_ts = ts_time($ora);
    if ($fin_reale_ts === null) $fin_reale_ts = $ini_reale_ts + $DURATA_CORSA_SEC;

    $conf_final = overlapCorsaConvoglio($con, $data, $id_convoglio, $ini_reale_ts, $fin_reale_ts, $id_corsa_escludi);
    if ($conf_final) {
        return ['ok'=>false,'errore'=>
            "⚠️ Conflitto rilevato DOPO i posticipi: la corsa, dopo gli aggiustamenti "
            . "(da " . date('H:i', $ini_reale_ts) . " a " . date('H:i', $fin_reale_ts) . "), "
            . "si sovrappone alla corsa #{$conf_final['IDCORSA']} del treno "
            . "'{$conf_final['NOME_CONV']}' (" . substr($conf_final['ini'],0,5)
            . "–" . substr($conf_final['fin'],0,5) . " verso {$conf_final['DIREZIONE']}). "
            . "Scegli un altro treno o un orario che lasci spazio sufficiente."];
    }

    return [
        'ok'=>true,'errore'=>null,
        'ora'=>$ora,'fermate_mem'=>$fermate_mem,
        'ritardi_dettaglio'=>$ritardi_dettaglio,'ritardo_acc'=>$ritardo_acc,
        'messaggi_trasferimento'=>$messaggi_trasferimento,
        'messaggi_posticipo'=>$messaggi_posticipo,
        'messaggi_soste'=>$messaggi_soste,
        'is_festivo'=>$is_festivo,
        'corsa_vuoto'=>!empty($corsa_vuoto['necessaria']) ? $corsa_vuoto : null,
    ];
}

if (!isset($_SESSION['sft_id']) || $_SESSION['sft_ruolo'] !== 'Esercizio') {
    header("Location: index.php?errore=non_autorizzato"); exit();
}

$azione = $_REQUEST['azione'] ?? '';

if ($azione == 'inserisci') {

    $data           = $con->real_escape_string($_POST['data']);
    $ora_originale  = !empty($_POST['ora_originale_vera'])
        ? date('H:i:s', strtotime('1970-01-01 ' . $_POST['ora_originale_vera']))
        : date('H:i:s', strtotime('1970-01-01 ' . $_POST['ora']));
    $id_convoglio   = intval($_POST['id_convoglio']);

    /* materiale rotabile presente */
    $r_mat_check = $con->query("
        SELECT CO.NOME AS NOME_CONV,
               COUNT(COMP.MATRICOLAMAT) AS N_MAT
        FROM SFT_CONVOGLIO CO
        LEFT JOIN SFT_COMPOSTO COMP ON COMP.IDCONVOGLIO = CO.IDCONVOGLIO
        WHERE CO.IDCONVOGLIO = $id_convoglio
        GROUP BY CO.IDCONVOGLIO, CO.NOME
    ");
    if (!$r_mat_check || $r_mat_check->num_rows === 0) {
        header("Location: admin_esercizio.php?msg=" . urlencode("⚠️ Convoglio #$id_convoglio non trovato.")); exit();
    }
    $mat_info = $r_mat_check->fetch_assoc();
    if ((int)$mat_info['N_MAT'] === 0) {
        header("Location: admin_esercizio.php?msg=" . urlencode(
            "⚠️ Il convoglio '" . $mat_info['NOME_CONV'] . "' non ha materiale rotabile associato "
            . "e non può effettuare corse. Aggiungi almeno una locomotiva/carrozza al convoglio "
            . "prima di programmarne le corse."
        )); exit();
    }

    $direzione      = $con->real_escape_string($_POST['direzione']);
    $conferma_vuoto = !empty($_POST['conferma_vuoto']);

    $r_stazioni = $con->query("SELECT IDSTAZIONE, NOME, KMPROGRESSIVO FROM SFT_STAZIONE");
    $stazioni_map = [];
    while ($s = $r_stazioni->fetch_assoc()) $stazioni_map[(int)$s['IDSTAZIONE']] = $s;

    $res = pianificaCorsa($con, $data, $ora_originale, $id_convoglio, $direzione, $stazioni_map, true, 0);
    if (!$res['ok']) {
        header("Location: admin_esercizio.php?msg=" . urlencode($res['errore'])); exit();
    }

    /* Conferma trasferimento a vuoto se necessario */
    if (!empty($res['corsa_vuoto']) && !$conferma_vuoto) {
        $template = ($direzione === 'Nord') ? invertiTemplate(templateSud()) : templateSud();
        $id_staz_partenza   = $template[0][0];
        $posizione = trovaPosizioneConvoglio($con, $id_convoglio, $data, $res['ora']);
        $_SESSION['trasf_pending'] = [
            'data'               => $data,
            'ora_originale'      => $ora_originale,
            'ora'                => $res['ora'],
            'id_convoglio'       => $id_convoglio,
            'direzione'          => $direzione,
            'posizione'          => $posizione,
            'corsa_vuoto'        => $res['corsa_vuoto'],
            'nome_staz_partenza' => $stazioni_map[$id_staz_partenza]['NOME'] ?? '',
            'messaggi_posticipo' => $res['messaggi_posticipo'],
        ];
        header("Location: conferma_trasferimento.php"); exit();
    }

    //Insert DB
    $ora          = $res['ora'];
    $fermate_mem  = $res['fermate_mem'];
    $is_festivo   = $res['is_festivo'];
    $ritardi_json = !empty($res['ritardi_dettaglio'])
        ? $con->real_escape_string(json_encode($res['ritardi_dettaglio'])) : null;

    $nuovo_id = $con->query("SELECT IFNULL(MAX(IDCORSA),0)+1 AS N FROM SFT_CORSA")->fetch_assoc()['N'];
    $sql = "INSERT INTO SFT_CORSA (IDCORSA, DATA, ORA, IDCONVOGLIO, STATO, DIREZIONE, IS_FESTIVO, RITARDI_DETTAGLIO)
            VALUES ($nuovo_id, '$data', '$ora', $id_convoglio, 'Programmata', '$direzione', "
            . ($is_festivo ? 1 : 0) . ", "
            . ($ritardi_json ? "'$ritardi_json'" : "NULL") . ")";
    if (!$con->query($sql)) {
        header("Location: admin_esercizio.php?msg=Errore DB: " . urlencode($con->error)); exit();
    }
    foreach ($fermate_mem as $f) {
        $orap_sql = $f['ORAP'] ? "'{$f['ORAP']}'" : "NULL";
        $oraa_sql = $f['ORAA'] ? "'{$f['ORAA']}'" : "NULL";
        $con->query("INSERT INTO SFT_FERMATA (IDCORSA, IDSTAZIONE, ORAP, ORAA, PROGRESSIVO)
                     VALUES ($nuovo_id, {$f['IDSTAZIONE']}, $orap_sql, $oraa_sql, {$f['PROGRESSIVO']})");
    }
    unset($_SESSION['trasf_pending']);

    //Messaggio finale 
    $msg_f = [];
    foreach ($res['messaggi_trasferimento'] as $m) $msg_f[] = $m;
    foreach ($res['messaggi_posticipo']    as $m) $msg_f[] = "⚠️ " . $m;
    foreach ($res['messaggi_soste']        as $m) $msg_f[] = $m;
    if ($ora !== $ora_originale || $res['ritardo_acc'] > 0) {
        $info = "";
        if ($ora !== $ora_originale) $info = " Orario: " . substr($ora_originale,0,5) . " → " . substr($ora,0,5) . ".";
        if ($res['ritardo_acc'] > 0) $msg_f[] = "✅ Corsa #$nuovo_id inserita.$info Ritardo in linea: " . round($res['ritardo_acc']/60) . " min.";
        else                         $msg_f[] = "✅ Corsa #$nuovo_id inserita.$info";
    } else {
        $msg_f[] = "✅ Corsa #$nuovo_id inserita alle " . substr($ora,0,5) . ".";
    }
    header("Location: admin_esercizio.php?msg=" . urlencode(implode(" ", $msg_f)));
    exit();

}

elseif ($azione == 'modifica_orario') {

    $id_corsa   = intval($_POST['id_corsa']);
    $nuova_data = $con->real_escape_string($_POST['nuova_data']);
    $nuova_ora_in = date('H:i:s', strtotime('1970-01-01 ' . $_POST['nuova_ora']));

    //Carico corsa attuale 
    $r_old = $con->query("SELECT ORA, DATA, IDCONVOGLIO, DIREZIONE, STATO FROM SFT_CORSA WHERE IDCORSA=$id_corsa");
    if (!$r_old || $r_old->num_rows === 0) {
        header("Location: admin_esercizio.php?msg=" . urlencode("❌ Corsa #$id_corsa non trovata.")); exit();
    }
    $old = $r_old->fetch_assoc();
    $vecchia_data = $old['DATA'];
    $vecchia_ora  = $old['ORA'];
    $id_convoglio = (int)$old['IDCONVOGLIO'];
    $direzione    = $old['DIREZIONE'];
    $stato_old    = $old['STATO'];

    //Solo Programmate, no corso o concluse 
    if ($stato_old !== 'Programmata') {
        header("Location: admin_esercizio.php?msg=" . urlencode(
            "⚠️ Modifica non consentita: la corsa #$id_corsa è in stato '$stato_old'. "
            . "Sono modificabili solo le corse in stato 'Programmata'."
        )); exit();
    }

    //Blocco "in corso o passata" basato su data+ora real
    $chk = $con->query("
                        SELECT
                        (CONCAT(DATA,' ',ORA) <= NOW())                AS gia_iniziata,
                        DATE_FORMAT(CONCAT(DATA,' ',ORA),'%d/%m/%Y %H:%i') AS label
                        FROM SFT_CORSA WHERE IDCORSA=$id_corsa
                    ")->fetch_assoc();
                    if ((int)$chk['gia_iniziata'] === 1) {
                        $start_old_label = $chk['label'];
                        
                        
                        $chk2 = $con->query("SELECT (TIMESTAMP('$nuova_data','$nuova_ora_in') <= NOW()) AS passata,
                            DATE_FORMAT(TIMESTAMP('$nuova_data','$nuova_ora_in'),'%d/%m/%Y %H:%i') AS label
                     ")->fetch_assoc();
                    if ((int)$chk2['passata'] === 1) {
                        header("Location: admin_esercizio.php?msg=" . urlencode(
                        "⚠️ La nuova data/ora (" . $chk2['label'] . ") deve essere futura."
                        )); exit();
}


        header("Location: admin_esercizio.php?msg=" . urlencode(
            "⚠️ Non è possibile modificare la corsa #$id_corsa: risulta già iniziata o conclusa "
            . "(partenza prevista " . $start_old_label . "). "
            . "Solo le corse non ancora partite possono essere riprogrammate."
        )); exit();
    }
    /* La nuova data/ora deve essere nel futuro */
    $start_new_ts = strtotime("$nuova_data $nuova_ora_in");
    if ($start_new_ts === false) {
        header("Location: admin_esercizio.php?msg=" . urlencode("⚠️ Data/ora nuova non valida.")); exit();
    }
   /* if ($start_new_ts <= $now_ts) {
        header("Location: admin_esercizio.php?msg=" . urlencode(
            "⚠️ La nuova data/ora (" . date('d/m/Y H:i', $start_new_ts) . ") deve essere futura."
        )); exit();
    }*/

    //Carico stazioni 
    $r_stazioni = $con->query("SELECT IDSTAZIONE, NOME, KMPROGRESSIVO FROM SFT_STAZIONE");
    $stazioni_map = [];
    while ($s = $r_stazioni->fetch_assoc()) $stazioni_map[(int)$s['IDSTAZIONE']] = $s;

    //Pianifico la nuova corsa, X la corsa stessa dai check
    $res = pianificaCorsa(
        $con, $nuova_data, $nuova_ora_in, $id_convoglio, $direzione,
        $stazioni_map, true, $id_corsa
    );
    if (!$res['ok']) {
        header("Location: admin_esercizio.php?msg=" . urlencode($res['errore'])); exit();
    }

    //Transazione atomica: aggiorno corsa + sostituisco fermate + notifico 
    $ora_nuova    = $res['ora'];
    $fermate_mem  = $res['fermate_mem'];
    $is_festivo   = $res['is_festivo'];
    $ritardi_json = !empty($res['ritardi_dettaglio'])
        ? $con->real_escape_string(json_encode($res['ritardi_dettaglio'])) : null;

    $con->begin_transaction();
    try {
        //aggiorno la corsa 
        $sql_up = "UPDATE SFT_CORSA SET DATA='$nuova_data', ORA='$ora_nuova',
                       IS_FESTIVO=" . ($is_festivo ? 1 : 0) . ",
                       RITARDI_DETTAGLIO=" . ($ritardi_json ? "'$ritardi_json'" : "NULL") . "
                   WHERE IDCORSA=$id_corsa";
        if (!$con->query($sql_up)) throw new Exception("Errore update corsa: " . $con->error);

        //sostituisco le fermate 
        if (!$con->query("DELETE FROM SFT_FERMATA WHERE IDCORSA=$id_corsa"))
            throw new Exception("Errore delete fermate: " . $con->error);
        foreach ($fermate_mem as $f) {
            $orap_sql = $f['ORAP'] ? "'{$f['ORAP']}'" : "NULL";
            $oraa_sql = $f['ORAA'] ? "'{$f['ORAA']}'" : "NULL";
            if (!$con->query("INSERT INTO SFT_FERMATA (IDCORSA, IDSTAZIONE, ORAP, ORAA, PROGRESSIVO)
                              VALUES ($id_corsa, {$f['IDSTAZIONE']}, $orap_sql, $oraa_sql, {$f['PROGRESSIVO']})"))
                throw new Exception("Errore insert fermata: " . $con->error);
        }

        // Notifiche ai passeggeri (se l'orario o la data sono cambiati) 
        $n_avvisi = 0;
        $cambiato = ($nuova_data !== $vecchia_data) || ($ora_nuova !== $vecchia_ora);
        if ($cambiato) {
            // leggo i biglietti pagati di questa corsa 
            $r_bg = $con->query("
                SELECT B.IDBIGLIETTO, B.COSTO, B.STATOPAGAMENTO, B.CODUTENTE,
                       S1.NOME PARTENZA, S2.NOME ARRIVO
                FROM SFT_BIGLIETTO B
                JOIN SFT_STAZIONE S1 ON B.IDSTAZIONEP=S1.IDSTAZIONE
                JOIN SFT_STAZIONE S2 ON B.IDSTAZIONEA=S2.IDSTAZIONE
                WHERE B.IDCORSA=$id_corsa
            ");
            $biglietti_pagati = [];
            if ($r_bg) {
                while ($b = $r_bg->fetch_assoc()) {
                    if ($b['STATOPAGAMENTO'] === 'Pagato') $biglietti_pagati[] = $b;
                }
                $r_bg->free();
            }

            $transazioni_usate = [];
            foreach ($biglietti_pagati as $b) {
                $imp = floatval($b['COSTO']);
                $p   = $con->real_escape_string($b['PARTENZA']);
                $ar  = $con->real_escape_string($b['ARRIVO']);
                $cod_utente_sft = (int)($b['CODUTENTE'] ?? 0);

                $exclude_sql = "";
                if (!empty($transazioni_usate)) {
                    $ids_csv = implode(',', array_map('intval', $transazioni_usate));
                    $exclude_sql = " AND IDTRANSAZIONE NOT IN ($ids_csv) ";
                }
                $rt = $con->query("
                    SELECT IDTRANSAZIONE, IDCONSUMATORE, IDESERCENTE, IMPORTO
                    FROM PAY_TRANSAZIONE
                    WHERE IDTRANSESTERNA LIKE 'SFT-PAY-%'
                      AND STATO IN ('COMPLETED','SUCCESS')
                      AND ABS(IMPORTO - $imp) < 0.01
                      AND DESCRIZIONE LIKE '%$p%'
                      AND DESCRIZIONE LIKE '%$ar%'
                      $exclude_sql
                    ORDER BY DATAORA DESC
                    LIMIT 1
                ");
                if (!$rt || $rt->num_rows === 0) {
                    if ($rt) $rt->free();
                    error_log("Avviso non mappato per biglietto #{$b['IDBIGLIETTO']} (corsa $id_corsa)");
                    continue;
                }
                $t = $rt->fetch_assoc(); $rt->free();
                $id_consumatore_pay = (int)$t['IDCONSUMATORE']; // <- ID PAY, distinto da quello SFT
                $id_esercente_pay   = (int)$t['IDESERCENTE'];

                $transazioni_usate[] = (int)$t['IDTRANSAZIONE'];

                $idref = 'NOTIFY-' . time() . '-' . rand(1000, 9999);
                $desc_raw = 'AVVISO - Cambio corsa #' . $id_corsa
                    . ' - Tratta ' . $b['PARTENZA'] . ' -> ' . $b['ARRIVO']
                    . ' - Da ' . substr($vecchia_data,0,10) . ' ' . substr($vecchia_ora,0,5)
                    . ' a ' . substr($nuova_data,0,10) . ' ' . substr($ora_nuova,0,5)
                    . ' [SFT_UTENTE:' . $cod_utente_sft . ']';
                $desc = $con->real_escape_string($desc_raw);
                if (!$con->query("
                    INSERT INTO PAY_TRANSAZIONE
                        (IMPORTO, DATAORA, STATO, DESCRIZIONE, URLIN, URLOUT, IDTRANSESTERNA, IDCONSUMATORE, IDESERCENTE)
                    VALUES (0, NOW(), 'COMPLETED', '$desc', 'SFT', 'SFT', '$idref', $id_consumatore_pay, $id_esercente_pay)

                ")) throw new Exception("Errore insert avviso: " . $con->error);

                $n_avvisi++;
            }
        }

        $con->commit();

        // messaggio finale
        $msg_f = [];
        foreach ($res['messaggi_trasferimento'] as $m) $msg_f[] = $m;
        foreach ($res['messaggi_posticipo']    as $m) $msg_f[] = "⚠️ " . $m;
        foreach ($res['messaggi_soste']        as $m) $msg_f[] = $m;

        $info_orario = " Orario: "
            . substr($vecchia_data,0,10) . " " . substr($vecchia_ora,0,5)
            . " → " . substr($nuova_data,0,10) . " " . substr($ora_nuova,0,5) . ".";
        $msg_principale = "✅ Corsa #$id_corsa riprogrammata." . $info_orario;
        if ($res['ritardo_acc'] > 0) $msg_principale .= " Ritardo in linea: " . round($res['ritardo_acc']/60) . " min.";
        if ($n_avvisi > 0) $msg_principale .= " Inviati $n_avvisi avvisi ai passeggeri.";
        $msg_f[] = $msg_principale;

        header("Location: admin_esercizio.php?msg=" . urlencode(implode(" ", $msg_f)));
        exit();

    } catch (Exception $e) {
        $con->rollback();
        error_log("Modifica orario corsa #$id_corsa FALLITA: " . $e->getMessage());
        header("Location: admin_esercizio.php?msg=" . urlencode(
            "❌ Modifica fallita: " . $e->getMessage() . ". Nessuna modifica applicata."
        )); exit();
    }

}
elseif ($azione == 'elimina') {
    $id = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) { header("Location: admin_esercizio.php?msg=ID mancante"); exit(); }

    if (empty($_POST['conferma_elimina'])) {
        header("Location: elimina_corsa.php?id=" . $id);
        exit();
    }

    $r_check = $con->query("SELECT DATA FROM SFT_CORSA WHERE IDCORSA=$id");
    if (!$r_check || $r_check->num_rows === 0) {
        header("Location: admin_esercizio.php?msg=" . urlencode("Corsa #$id non trovata"));
        exit();
    }
    $data_c = $r_check->fetch_assoc()['DATA'];
    $r_check->free();

    $n_rimb = 0; $tot_rimb = 0.0;

    $r_altre = $con->query("SELECT IDCORSA, RITARDI_DETTAGLIO FROM SFT_CORSA
                            WHERE DATA='$data_c' AND IDCORSA != $id
                            AND RITARDI_DETTAGLIO IS NOT NULL");
    $aggiornamenti_ritardi = [];
    if ($r_altre) {
        while ($a = $r_altre->fetch_assoc()) {
            $r = json_decode($a['RITARDI_DETTAGLIO'], true);
            if (!$r || !is_array($r)) continue;
            $mod = false; $puliti = [];
            foreach ($r as $k => $v) {
                $cc = $v['corsa_causa'] ?? null;
                if ($cc !== null) {
                    if (is_string($cc) && strpos($cc, ',') !== false) {
                        $ids = array_map('trim', explode(',', $cc));
                        $f = array_filter($ids, fn($x) => intval($x) != $id);
                        if (count($f) < count($ids)) {
                            $mod = true;
                            if (count($f) > 0) { $v['corsa_causa'] = implode(', ', $f); $puliti[$k] = $v; }
                        } else $puliti[$k] = $v;
                    } else {
                        if (intval($cc) == $id) $mod = true;
                        else $puliti[$k] = $v;
                    }
                } else $puliti[$k] = $v;
            }
            if ($mod) $aggiornamenti_ritardi[$a['IDCORSA']] = $puliti;
        }
        $r_altre->free();
    }
    foreach ($aggiornamenti_ritardi as $ia => $puliti) {
        if (empty($puliti)) {
            $con->query("UPDATE SFT_CORSA SET RITARDI_DETTAGLIO=NULL WHERE IDCORSA=$ia");
        } else {
            $nj = $con->real_escape_string(json_encode($puliti));
            $con->query("UPDATE SFT_CORSA SET RITARDI_DETTAGLIO='$nj' WHERE IDCORSA=$ia");
        }
    }

    //rimborsi 
    $r_bg = $con->query("
        SELECT B.IDBIGLIETTO, B.COSTO, B.STATOPAGAMENTO, B.CODUTENTE, S1.NOME PARTENZA, S2.NOME ARRIVO
        FROM SFT_BIGLIETTO B
        JOIN SFT_STAZIONE S1 ON B.IDSTAZIONEP=S1.IDSTAZIONE
        JOIN SFT_STAZIONE S2 ON B.IDSTAZIONEA=S2.IDSTAZIONE
        WHERE B.IDCORSA=$id");
    $biglietti_pagati = [];
    if ($r_bg) {
        while ($b = $r_bg->fetch_assoc()) {
            if ($b['STATOPAGAMENTO'] === 'Pagato') $biglietti_pagati[] = $b;
        }
        $r_bg->free();
    }

    $transazioni_usate = [];
    $rimborsi_da_eseguire = [];

    foreach ($biglietti_pagati as $b) {
        $imp = floatval($b['COSTO']);
        $p   = $con->real_escape_string($b['PARTENZA']);
        $ar  = $con->real_escape_string($b['ARRIVO']);
        $exclude_sql = "";
        if (!empty($transazioni_usate)) {
            $ids_csv = implode(',', array_map('intval', $transazioni_usate));
            $exclude_sql = " AND IDTRANSAZIONE NOT IN ($ids_csv) ";
        }
        $rt = $con->query("
            SELECT IDTRANSAZIONE, IDCONSUMATORE, IDESERCENTE, IMPORTO
            FROM PAY_TRANSAZIONE
            WHERE IDTRANSESTERNA LIKE 'SFT-PAY-%'
              AND STATO IN ('COMPLETED','SUCCESS')
              AND ABS(IMPORTO - $imp) < 0.01
              AND DESCRIZIONE LIKE '%$p%'
              AND DESCRIZIONE LIKE '%$ar%'
              $exclude_sql
            ORDER BY DATAORA DESC
            LIMIT 1
        ");
        if ($rt && $rt->num_rows > 0) {
            $t = $rt->fetch_assoc(); $rt->free();
            $id_trans = (int)$t['IDTRANSAZIONE'];
            $transazioni_usate[] = $id_trans;
            $rimborsi_da_eseguire[] = [
                'id_consumatore' => (int)$t['IDCONSUMATORE'],
                'id_esercente'   => (int)$t['IDESERCENTE'],
                'importo'        => floatval($t['IMPORTO']),
                'partenza'       => $b['PARTENZA'],
                'arrivo'         => $b['ARRIVO'],
                'cod_utente_sft' => (int)($b['CODUTENTE'] ?? 0),
            ];
        } else {
            if ($rt) $rt->free();
            error_log("Rimborso non mappato per biglietto #{$b['IDBIGLIETTO']} (corsa $id)");
        }
    }

    //transazione at
    $con->begin_transaction();
    try {
        foreach ($rimborsi_da_eseguire as $r) {
            $ic = $r['id_consumatore']; $ie = $r['id_esercente']; $ip = $r['importo'];
            if (!$con->query("UPDATE PAY_CONTO SET SALDO = SALDO + $ip WHERE IDUTENTE = $ic"))
                throw new Exception("Errore accredito consumatore: " . $con->error);
            if (!$con->query("UPDATE PAY_CONTO SET SALDO = SALDO - $ip WHERE IDUTENTE = $ie"))
                throw new Exception("Errore addebito esercente: " . $con->error);

            $idref = 'REFUND-' . time() . '-' . rand(1000, 9999);
            $desc_raw = 'RIMBORSO - Corsa soppressa - ' . $r['partenza'] . ' -> ' . $r['arrivo']
                . ' [SFT_UTENTE:' . $r['cod_utente_sft'] . ']';
            $desc = $con->real_escape_string($desc_raw);
            if (!$con->query("
                INSERT INTO PAY_TRANSAZIONE
                    (IMPORTO, DATAORA, STATO, DESCRIZIONE, URLIN, URLOUT, IDTRANSESTERNA, IDCONSUMATORE, IDESERCENTE)
                VALUES ($ip, NOW(), 'REFUNDED', '$desc', 'SFT', 'SFT', '$idref', $ic, $ie)
            ")) throw new Exception("Errore insert rimborso: " . $con->error);

            $n_rimb++; $tot_rimb += $ip;
        }

        if (!$con->query("DELETE FROM SFT_BIGLIETTO WHERE IDCORSA=$id"))
            throw new Exception("Errore cancel biglietti: " . $con->error);
        if (!$con->query("DELETE FROM SFT_FERMATA WHERE IDCORSA=$id"))
            throw new Exception("Errore cancel fermate: " . $con->error);
        if (!$con->query("DELETE FROM SFT_CORSA WHERE IDCORSA=$id"))
            throw new Exception("Errore cancel corsa: " . $con->error);

        $con->commit();
        $m = "✅ Corsa #$id eliminata.";
        if ($n_rimb > 0) $m .= " Rimborsati $n_rimb biglietti per € " . number_format($tot_rimb, 2, ',', '.') . ".";
        header("Location: admin_esercizio.php?msg=" . urlencode($m));
    } catch (Exception $e) {
        $con->rollback();
        error_log("Eliminazione corsa #$id FALLITA: " . $e->getMessage());
        header("Location: admin_esercizio.php?msg=" . urlencode(
            "❌ Eliminazione fallita: " . $e->getMessage() . ". Nessuna modifica applicata."
        ));
    }
    exit();

}

elseif ($azione == 'nuovo_convoglio') {
    $nome = $con->real_escape_string($_POST['nome_convoglio']);
    $nid  = $con->query("SELECT IFNULL(MAX(IDCONVOGLIO),0)+1 AS N FROM SFT_CONVOGLIO")->fetch_assoc()['N'];
    if ($con->query("INSERT INTO SFT_CONVOGLIO (IDCONVOGLIO,NOME) VALUES ($nid,'$nome')")) {
        header("Location: admin_esercizio.php?msg=Convoglio '$nome' creato con successo");
    } else header("Location: admin_esercizio.php?msg=Errore: " . urlencode($con->error));
    exit();

} else {
    header("Location: admin_esercizio.php?msg=Azione non riconosciuta"); exit();
}
?>

