<?php

date_default_timezone_set('Europe/Rome');

$oggi         = date('Y-m-d');
$ieri         = date('Y-m-d', strtotime('-1 day'));
$ora_attuale  = date('H:i:s');

/*Corse today: Programmata --> In Viaggio. 
l'orario di partenza dalla PRIMA fermata e' <= dell'ora attuale*/

$con->query("
    UPDATE SFT_CORSA C
    JOIN SFT_FERMATA F_FIRST
      ON F_FIRST.IDCORSA = C.IDCORSA
     AND F_FIRST.PROGRESSIVO = (
            SELECT MIN(F2.PROGRESSIVO)
            FROM SFT_FERMATA F2
            WHERE F2.IDCORSA = C.IDCORSA
         )
    SET C.STATO = 'In Viaggio'
    WHERE C.DATA  = '$oggi'
      AND C.STATO = 'Programmata'
      AND COALESCE(F_FIRST.ORAP, F_FIRST.ORAA) <= '$ora_attuale'
");

/* Corse di OGGI, NON notturne: In Viaggio --> Conclusa
ORAA ultima fermata >= ORAP prima fermata (stessa giornata) 
e ORAA ultima fermata <= ora attuale*/
$con->query("
    UPDATE SFT_CORSA C
    JOIN SFT_FERMATA F_FIRST
      ON F_FIRST.IDCORSA = C.IDCORSA
     AND F_FIRST.PROGRESSIVO = (
            SELECT MIN(F2.PROGRESSIVO)
            FROM SFT_FERMATA F2
            WHERE F2.IDCORSA = C.IDCORSA
         )
    JOIN SFT_FERMATA F_LAST
      ON F_LAST.IDCORSA = C.IDCORSA
     AND F_LAST.PROGRESSIVO = (
            SELECT MAX(F2.PROGRESSIVO)
            FROM SFT_FERMATA F2
            WHERE F2.IDCORSA = C.IDCORSA
         )
    SET C.STATO = 'Conclusa'
    WHERE C.DATA  = '$oggi'
      AND C.STATO = 'In Viaggio'
      AND COALESCE(F_LAST.ORAA, F_LAST.ORAP) >= COALESCE(F_FIRST.ORAP, F_FIRST.ORAA)
      AND COALESCE(F_LAST.ORAA, F_LAST.ORAP) <= '$ora_attuale'
");

/*Corse NOTTURNE partite IERI (DATA = ieri) e terminate OGGI:
 ORAA ultima fermata < ORAP prima fermata (cross-midnight)
 e ORAA ultima fermata <= ora attuale di oggi*/
$con->query("
    UPDATE SFT_CORSA C
    JOIN SFT_FERMATA F_FIRST
      ON F_FIRST.IDCORSA = C.IDCORSA
     AND F_FIRST.PROGRESSIVO = (
            SELECT MIN(F2.PROGRESSIVO)
            FROM SFT_FERMATA F2
            WHERE F2.IDCORSA = C.IDCORSA
         )
    JOIN SFT_FERMATA F_LAST
      ON F_LAST.IDCORSA = C.IDCORSA
     AND F_LAST.PROGRESSIVO = (
            SELECT MAX(F2.PROGRESSIVO)
            FROM SFT_FERMATA F2
            WHERE F2.IDCORSA = C.IDCORSA
         )
    SET C.STATO = 'Conclusa'
    WHERE C.DATA  = '$ieri'
      AND C.STATO IN ('Programmata','In Viaggio')
      AND COALESCE(F_LAST.ORAA, F_LAST.ORAP) < COALESCE(F_FIRST.ORAP, F_FIRST.ORAA)
      AND COALESCE(F_LAST.ORAA, F_LAST.ORAP) <= '$ora_attuale'
");

// all corse con DATA < ieri ancora aperte vengono chiuse
$con->query("
    UPDATE SFT_CORSA
    SET STATO = 'Conclusa'
    WHERE DATA < '$ieri'
      AND STATO IN ('Programmata','In Viaggio')
");
?>


