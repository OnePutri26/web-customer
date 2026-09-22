<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . "/config/database.php";

echo "<h2>CEK DATABASE PHP</h2>";

$db = $conn->query("SELECT DATABASE()")->fetch_row()[0];
$host = $conn->query("SELECT @@hostname")->fetch_row()[0];

echo "<b>Database:</b> " . htmlspecialchars($db) . "<br>";
echo "<b>Host MySQL:</b> " . htmlspecialchars($host) . "<br><br>";

echo "<h3>Struktur users yang dibaca PHP:</h3>";

$result = $conn->query("DESCRIBE users");

echo "<table border='1' cellpadding='8' cellspacing='0'>";
echo "<tr>
        <th>Field</th>
        <th>Type</th>
        <th>Null</th>
        <th>Key</th>
        <th>Default</th>
      </tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
    echo "</tr>";
}

echo "</table>";