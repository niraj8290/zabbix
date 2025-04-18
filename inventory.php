<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Zabbix API endpoint and Auth token
$ZABBIX_URL = 'https://zabbixdemo.goapl.com/api_jsonrpc.php';
$AUTH_TOKEN = '225e57e579ba9c1a03e79d2a46129a69bab501c8b0611f055f616e30c9d691c5';  // Replace with your actual Auth token

// Function to call the Zabbix API
function zabbix_api_call($method, $params = []) {
    global $ZABBIX_URL, $AUTH_TOKEN;
    
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $AUTH_TOKEN
    ];

    $data = [
        'jsonrpc' => '2.0',
        'method' => $method,
        'params' => $params,
        'id' => 1,
    ];

    $ch = curl_init($ZABBIX_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    
    if ($response === false) {
        die('Error in API request: ' . curl_error($ch));
    }

    curl_close($ch);
    
    return json_decode($response, true);
}

// Get all hosts with interfaces
$host_params = [
    'output' => ['hostid', 'name'],
    'selectInterfaces' => ['ip'],
    'selectGroups' => ['name'],  // Fetch host groups directly
    'selectItems' => ['name', 'lastvalue'],  // Fetch all necessary items in one request
];

$hosts = zabbix_api_call('host.get', $host_params);

if (!isset($hosts['result']) || count($hosts['result']) === 0) {
    die("<div class='alert alert-warning'>No hosts found.</div>");
}

$data = [];
$counter = 1;

foreach ($hosts['result'] as $host) {
    $host_id = $host['hostid'];
    $ip_address = isset($host['interfaces'][0]['ip']) ? $host['interfaces'][0]['ip'] : "N/A";

    // Check if 'groups' exists and is an array before processing it
    $hostgroup = "N/A";  // Default value for host group
    if (isset($host['groups']) && is_array($host['groups'])) {
        $hostgroup_names = array_map(fn($g) => $g['name'], $host['groups']);
        $hostgroup = !empty($hostgroup_names) ? implode(', ', $hostgroup_names) : "N/A";
    }

    // Default values
    $cpu_cores = "N/A";
    $total_memory = "N/A";
    $operating_system = "N/A";

    // Process fetched items instead of making a separate `item.get` request
    foreach ($host['items'] as $item) {
        if ($item['name'] === 'Number of cores') {
            $cpu_cores = $item['lastvalue'];
        } elseif ($item['name'] === 'Total memory') {
            $total_memory = round($item['lastvalue'] / 1073741824, 2) . ' GB';
        } elseif ($item['name'] === 'Operating system') {
            $operating_system = $item['lastvalue'];
        }
    }

    // Add data to the report
    $data[] = [$counter, $host['name'], $hostgroup, $ip_address, $operating_system, $cpu_cores, $total_memory];
    $counter++;
}

// **Handle CSV Download Request**
if (isset($_GET['download_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="Host_Inventory_report.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['No.', 'Host Name', 'Host Group', 'IP Address', 'Operating System', 'CPU Cores', 'Total Memory (GB)']);

    foreach ($data as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Host Inventory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container-fluid mt-4">
    <h2 class="mb-3 text-center">Host Inventory</h2>

    <div class="d-flex align-items-center">
        <input type="text" id="searchInput" class="form-control me-2" placeholder="Search by Host Group">
        <a href="javascript:void(0);" onclick="downloadFilteredCSV();" class="btn btn-success">Download CSV</a>
    </div>

    <div class="mt-3">
        <table class="table table-striped table-bordered" id="reportTable">
            <thead class="table-dark">
                <tr>
                    <th>No.</th>
                    <th>Host Name</th>
                    <th>Host Group</th>
                    <th>IP Address</th>
                    <th>Operating System</th>
                    <th>CPU Cores</th>
                    <th>Total Memory (GB)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row[0]) ?></td>
                        <td><?= htmlspecialchars($row[1]) ?></td>
                        <td><?= htmlspecialchars($row[2]) ?></td>
                        <td><?= htmlspecialchars($row[3]) ?></td>
                        <td><?= htmlspecialchars($row[4]) ?></td>
                        <td><?= htmlspecialchars($row[5]) ?></td>
                        <td><?= htmlspecialchars($row[6]) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
        // Wait until the page loads to attach event listeners
        document.addEventListener("DOMContentLoaded", function () {
            document.getElementById("searchInput").addEventListener("keyup", filterTable);
        });

        function filterTable() {
            let input = document.getElementById("searchInput").value.toLowerCase();
            let rows = document.querySelectorAll("#reportTable tbody tr");

            rows.forEach(row => {
                let hostgroup = row.cells[2].textContent.toLowerCase(); // Only search by Host Group

                if (hostgroup.includes(input)) {
                    row.style.display = "";
                } else {
                    row.style.display = "none";
                }
            });
        }

        function downloadFilteredCSV() {
            let table = document.getElementById("reportTable");
            let rows = table.querySelectorAll("tbody tr");
            let csvData = [];

            // Extract table headers and wrap in quotes
            let headers = ['No.', 'Host Name', 'Host Group', 'IP Address', 'Operating System', 'CPU Cores', 'Total Memory (GB)'];
            csvData.push(headers.map(header => `"${header}"`).join(",")); // Wrap headers in quotes

            // Extract only visible rows (filtered rows)
            rows.forEach(row => {
                if (row.style.display !== "none") {
                    let rowData = [];
                    row.querySelectorAll("td").forEach(cell => {
                        let cellValue = cell.textContent.trim();
                        // Wrap each value in quotes to prevent misalignment in CSV
                        rowData.push(`"${cellValue}"`);
                    });
                    csvData.push(rowData.join(","));
                }
            });

            if (csvData.length === 1) { // Only header row exists
                alert("No matching records found for download!");
                return;
            }

            // Create a Blob for CSV data
            let csvBlob = new Blob([csvData.join("\n")], { type: "text/csv" });
            let csvUrl = URL.createObjectURL(csvBlob);

            // Create a temporary download link and trigger download
            let a = document.createElement("a");
            a.href = csvUrl;
            a.download = "HostInventory_report.csv";
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        }
    </script>
</body>
</html>
