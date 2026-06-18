<?php
/*****************************************************
 * CONFIGURAZIONE DB (PDO)
 *****************************************************/
$host       = "89.46.111.163";
$username   = "Sql1688505";
$password   = "Tira74GL!#";
$dbname     = "Sql1688505_4";

$dsn = "mysql:host=$host;dbname=$dbname";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_INIT_COMMAND =>
        "SET NAMES utf8,
         lc_time_names = 'it_IT',
         block_encryption_mode = 'aes-256-cbc',
         @key_str = SHA2('PartitaIVA22',512),
         @init_vector = RANDOM_BYTES(16)",
    PDO::ATTR_AUTOCOMMIT => false
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    die("Errore connessione DB: " . h($e->getMessage()));
}

/*****************************************************
 * PARAMETRI (GET)
 *****************************************************/
$mode     = strtolower($_GET['mode'] ?? 'interval');
$years    = max(1, (int)($_GET['years'] ?? 1));
$year     = (int)($_GET['year'] ?? date('Y'));
$gestitaP = strtolower($_GET['gestita'] ?? '1'); // 1 | 0 | all

if ($year < 1970 || $year > 2100) {
    $year = (int)date('Y');
}

/*****************************************************
 * COSTRUZIONE FILTRO
 *****************************************************/
$where  = [];
$params = [];

$where[] = "1=1";

/* filtro gestita */
if ($gestitaP !== 'all') {
    $where[] = "m.gestita = :gestita";
    $params['gestita'] = (int)$gestitaP;
}

/* filtro temporale */
if ($mode === 'year') {

    $from = sprintf("%04d-01-01 00:00:00", $year);
    $to   = sprintf("%04d-01-01 00:00:00", $year + 1);

    $where[] = "m.dataora >= :from AND m.dataora < :to";
    $params['from'] = $from;
    $params['to']   = $to;

    $titleFilter = "Anno $year";

} else {

    $stmt = $pdo->query("SELECT DATE_SUB(NOW(), INTERVAL $years YEAR) AS cutoff");
    $cutoff = $stmt->fetch(PDO::FETCH_ASSOC)['cutoff'];

    $where[] = "m.dataora < :cutoff";
    $params['cutoff'] = $cutoff;

    $titleFilter = "Più vecchi di $years anni (prima di $cutoff)";
}

$whereSql = implode(" AND ", $where);

/*****************************************************
 * HTML HEADER
 *****************************************************/
echo "<!doctype html><html><head><meta charset='utf-8'><title>Report Messaggi</title></head><body>";
echo "<h2>Report Messaggi</h2>";

echo "<p><b>Filtro temporale:</b> " . h($titleFilter) . "<br>";
echo "<b>Gestita:</b> " . h($gestitaP) . "</p>";

echo "<p>
<b>Esempi:</b><br>
?mode=interval&years=1&gestita=1<br>
?mode=interval&years=2&gestita=0<br>
?mode=year&year=2024&gestita=all
</p>";

/*****************************************************
 * 1) CONTEGGIO MESSAGGI
 *****************************************************/
$sql = "SELECT COUNT(*) FROM dap10_message m WHERE $whereSql";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

echo "<h3>Messaggi trovati: " . h($total) . "</h3>";

/*****************************************************
 * 2) ELENCO MESSAGGI
 *****************************************************/
$sql = "
SELECT m.id_message, m.dataora, m.gestita
FROM dap10_message m
WHERE $whereSql
ORDER BY m.dataora ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

echo "<table border='1' cellpadding='6' cellspacing='0'>";
echo "<tr><th>ID</th><th>Data</th><th>Gestita</th></tr>";

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "<tr>
            <td>" . h($r['id_message']) . "</td>
            <td>" . h($r['dataora']) . "</td>
            <td>" . h($r['gestita']) . "</td>
          </tr>";
}
echo "</table>";

/*****************************************************
 * 3) CONTEGGI COLLEGATI
 *****************************************************/
$sql = "
SELECT
  (SELECT COUNT(*) FROM dap10_message m WHERE $whereSql) AS messaggi,

  (SELECT COUNT(*)
   FROM dap10_message_reply r
   JOIN dap10_message m ON m.id_message = r.id_message_ini
   WHERE $whereSql) AS risposte,

  (SELECT COUNT(*)
   FROM dap11_attachments a
   JOIN dap10_message m ON m.id_message = a.id_message
   WHERE $whereSql) AS allegati_messaggi,

  (SELECT COUNT(*)
   FROM dap11_attachments a
   JOIN dap10_message_reply r ON r.id_message = a.id_message_reply
   JOIN dap10_message m ON m.id_message = r.id_message_ini
   WHERE $whereSql) AS allegati_risposte,

  (SELECT COUNT(*)
   FROM dap10_message_delete md
   JOIN dap10_message m ON m.id_message = md.id_message
   WHERE $whereSql) AS delete_messaggi,

  (SELECT COUNT(*)
   FROM dap10_message_reply_delete rd
   JOIN dap10_message_reply r ON r.id_message = rd.id_message
   JOIN dap10_message m ON m.id_message = r.id_message_ini
   WHERE $whereSql) AS delete_risposte
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$c = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h3>Riepilogo collegamenti</h3><ul>";
foreach ($c as $k => $v) {
    echo "<li>" . h($k) . ": <b>" . h($v) . "</b></li>";
}
echo "</ul>";

echo "</body></html>";
