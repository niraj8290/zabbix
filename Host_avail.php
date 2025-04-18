<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

    // Include PhpSpreadsheet library
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

    require __DIR__ . '/../vendor/autoload.php'; // Make sure you include the autoloader if you're using Composer

    // Zabbix API endpoint and Auth token
    $ZABBIX_URL = 'https://zabbixdemo.goapl.com/api_jsonrpc.php';
    $AUTH_TOKEN = '225e57e579ba9c1a03e79d2a46129a69bab501c8b0611f055f616e30c9d691c5';  // Replace with your actual Auth token
 
    // Function to call the Zabbix API
    function zabbix_api_call($method, $params = []) {
        global $zabbix_url, $auth_token;
    
	$headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $auth_token // Use Bearer token for authentication
    ];

        $data = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => 1,
            
        ];
       
        $ch = curl_init($zabbix_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $response = curl_exec($ch);
        curl_close($ch);
       
        return json_decode($response, true);
    }
    
    // Function to fetch all host groups
    function fetch_all_host_groups() {
        $response = zabbix_api_call('hostgroup.get', [
            'output' => ['groupid', 'name'],
        ]);
    
        // Check if 'result' exists before accessing it
        return isset($response['result']) ? $response['result'] : [];
    }
    
    
    // Function to fetch host availability data for selected host groups
    function fetch_host_availability($hostgroup_ids, $time_from, $time_till) {
        $time_from = $time_from ?: strtotime("2024-08-01");
        $time_till = $time_till ?: strtotime("2024-08-31 23:59:59");
    
        $params = [
            'output' => ['hostid', 'name'],
            'groupids' => $hostgroup_ids,
            'filter' => ['status' => 0],
        ];
    
        $hosts_response = zabbix_api_call('host.get', $params);
    
        $availability_data = [];
    
        foreach ($hosts_response['result'] as $host) {
            $host_id = $host['hostid'];
            $host_name = $host['name'];
    
            $uptime_response = zabbix_api_call('item.get', [
                'output' => ['itemid'],
                'hostids' => $host_id,
                'search' => ['key_' => 'icmpping'],
            ]);
    
            if (!empty($uptime_response['result'])) {
                $item_id = $uptime_response['result'][0]['itemid'];
                $trend_data = zabbix_api_call('trend.get', [
                    'itemids' => $item_id,
                    'time_from' => $time_from,
                    'time_till' => $time_till,
                ]);
    
                if (!empty($trend_data['result'])) {
                    $total_records = count($trend_data['result']);
                    $up_count = 0;
                    $down_count = 0;
    
                    foreach ($trend_data['result'] as $record) {
                        if (floatval($record['value_avg']) > 0) {
                            $up_count++;
                        } else {
                            $down_count++;
                        }
                    }
    
                    $up_percentage = $total_records > 0 ? round(($up_count / $total_records) * 100, 2) : 0;
                    $down_percentage = $total_records > 0 ? round(($down_count / $total_records) * 100, 2) : 0;
    
                    $availability_data[] = [
                        'hostname' => $host_name,
                        'up_percentage' => $up_percentage,
                        'down_percentage' => $down_percentage
                    ];
                }
            }
        }
    
        return $availability_data;
    }
    
    function export_to_excel($all_availabilities, $hostgroup_names, $time_from, $time_till) {
        header("Content-Type: application/vnd.ms-excel");
        header("Content-Disposition: attachment; filename=Host_Availability_Report_" . date('Y-m-d') . ".xls");
    
        echo "Host Group\tHostname\tUp %\tDown %\n";
    
        foreach ($all_availabilities as $hostgroup_id => $availabilities) {
            $hostgroup_name = $hostgroup_names[$hostgroup_id] ?? "Unknown Hostgroup";
            foreach ($availabilities as $data) {
                echo "$hostgroup_name\t{$data['hostname']}\t{$data['up_percentage']}%\t{$data['down_percentage']}%\n";
            }
        }
    
        exit;
    }
    
    function export_to_csv($all_availabilities, $hostgroup_names, $time_from, $time_till) {
        header("Content-Type: text/csv");
        header("Content-Disposition: attachment; filename=Host_Availability_Report_" . date('Y-m-d') . ".csv");
    
        $output = fopen("php://output", "w");
        fputcsv($output, ['No.','Host Group', 'Hostname', 'Up %', 'Down %']);
        $serial_no = 1;
        foreach ($all_availabilities as $hostgroup_id => $availabilities) {
            $hostgroup_name = $hostgroup_names[$hostgroup_id] ?? "Unknown Hostgroup";
            foreach ($availabilities as $data) {
                fputcsv($output, [$serial_no,$hostgroup_name, $data['hostname'], "{$data['up_percentage']}%", "{$data['down_percentage']}%"]);
                $serial_no++;       
        }
        }
    
        fclose($output);
        exit;
    }
    
    
    // Function to generate HTML output for the report
    function generate_html($all_availabilities, $generation_time, $hostgroup_names, $time_from, $time_till) {
        echo "<div class='report-container'>";
    
        // Add the logo in the top right corner and center the title
        echo "<div class='header-section'>";
        echo "<h1 class='widget-header'>Host Availability Report</h1>";
        //echo "<div class='logo-container'><img src='https://www.goapl.com/wp-content/uploads/2022/05/Final-CMYK-logo-JPG.jpg' alt='Logo'></div>";
        echo "</div>";
    
        // Report details (Generated on, Start Date, End Date, Host Groups Selected)
        echo "<div class='report-details'>";
        echo "<div class='left-side'>";
        echo "<p><strong>Generated On:</strong> " . date('Y-m-d H:i:s') . "</p>";
        echo "<p><strong>Start Date:</strong> " . date('Y-m-d', $time_from) . "</p>";
        echo "<p><strong>End Date:</strong> " . date('Y-m-d', $time_till) . "</p>";
        echo "<p><strong>Host Groups Selected:</strong> " . implode(', ', $hostgroup_names) . "</p>";
    // echo "<div class='logo-container'><img src='https://www.goapl.com/wp-content/uploads/2022/05/Final-CMYK-logo-JPG.jpg' alt='Logo'></div>";
        echo "</div>";
        echo "</div>"; // End of report-details
    
        // Table and host availability data
        foreach ($all_availabilities as $hostgroup_id => $availabilities) {
            $hostgroup_name = isset($hostgroup_names[$hostgroup_id]) ? $hostgroup_names[$hostgroup_id] : "Unknown Hostgroup";
        
            echo "<h2 class='section-header'>$hostgroup_name</h2>";
            echo "<table class='availability-table'>
                    <thead>
                        <tr>
                            <th>Hostname</th>
                            <th>Up %</th>
                            <th>Down %</th>
                        </tr>
                    </thead>
                    <tbody>";
        
            foreach ($availabilities as $data) {
                $up_percentage = $data['up_percentage'];
                $down_percentage = $data['down_percentage'];
                $up_color = $up_percentage == 100 ? 'green' : 'black';
                $down_color = $down_percentage > 0 ? 'red' : 'black';
    
                echo "<tr>
                        <td>{$data['hostname']}</td>
                        <td style='color: $up_color;'>$up_percentage%</td>
                        <td style='color: $down_color;'>$down_percentage%</td>
                    </tr>";
            }
    
            echo "</tbody></table><br />";
        }
    
        // Add Back and Print buttons
        echo "<div class='action-btn-container'>
                <button onclick='javascript:history.back()' id='backButton' class='action-btn'>Back</button>
                <button onclick='window.print();' id='printBtn' class='action-btn'>Print</button>
            </div>";
    
        echo "</div>";
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $time_from = isset($_POST['time_from']) ? strtotime($_POST['time_from']) : null;
        $time_till = isset($_POST['time_till']) ? strtotime($_POST['time_till']) : null;
        $hostgroup_ids = isset($_POST['hostgroup_ids']) ? $_POST['hostgroup_ids'] : [];
    
        if (empty($hostgroup_ids)) {
            echo "<p class='error'>Error: At least one host group ID must be selected.</p>";
        } else {
            // Fetch availabilities and host group names
            $all_availabilities = [];
            $hostgroup_names = [];
    
            foreach ($hostgroup_ids as $hostgroup_id) {
                $hostgroup_names[$hostgroup_id] = ''; // Placeholder for host group names
            }
    
            foreach ($hostgroup_ids as $hostgroup_id) {
                $availabilities = fetch_host_availability($hostgroup_id, $time_from, $time_till);
                if ($availabilities) {
                    $all_availabilities[$hostgroup_id] = $availabilities;
                }
            }
    
            $hostgroups = fetch_all_host_groups();
            foreach ($hostgroups as $hostgroup) {
                if (isset($hostgroup_names[$hostgroup['groupid']])) {
                    $hostgroup_names[$hostgroup['groupid']] = $hostgroup['name'];
                }
            }
    
            if (isset($_POST['export_excel'])) {
                export_to_excel($all_availabilities, $hostgroup_names, $time_from, $time_till);
            } elseif (isset($_POST['export_csv'])) {
                export_to_csv($all_availabilities, $hostgroup_names, $time_from, $time_till);
            } else {
                $generation_time = microtime(true);
                generate_html($all_availabilities, $generation_time, $hostgroup_names, $time_from, $time_till);
            }
        }
    }
    else {
        $hostgroups = fetch_all_host_groups();
        ?>
        <div class="widgets-container">
            <div class="widget">
                <h2 class="widget-header">Generate Host Availability Report</h2>
                <form method="POST">
                    <label for="time_from">Start Date:</label><br>
                    <input type="date" id="time_from" name="time_from" value=""><br><br>
    
                    <label for="time_till">End Date:</label><br>
                    <input type="date" id="time_till" name="time_till" value=""><br><br>
    
                    <label for="hostgroup_ids">Select Host Groups:</label><br>
                    <select id="hostgroup_ids" name="hostgroup_ids[]" multiple="multiple" style="width: 100%;" required>
                        <?php foreach ($hostgroups as $hostgroup): ?>
                            <option value="<?php echo $hostgroup['groupid']; ?>"><?php echo $hostgroup['name']; ?></option>
                        <?php endforeach; ?>
                    </select><br><br>
    
                    <input type="submit" name="generate_report" value="Generate" class="generate-btn"> 
                    <input type="submit" name="export_excel" value="Export to Excel" class="generate-btn">
                    <input type="submit" name="export_csv" value="Export to CSV" class="generate-btn">
    
                </form>
            </div>
        </div>
        <script src="js/jquery.min.js"></script>
        <script src="js/select2.min.js"></script>
        <link href="css/select2.min.css" rel="stylesheet" />
        <script>
            $(document).ready(function() {
                $('#hostgroup_ids').select2({
                    placeholder: "Select host groups",
                    allowClear: true
                });
            });
        </script>
    <?php
    }
    ?>
    
    <style>
        body {
            font-family: 'Arial', sans-serif;
            background-color: #f4f6f9;
            margin: 0;
            padding: 20px;
        }
    
        .header-section {
            display: flex;
            justify-content: center;  /* Center the title */
            align-items: center;
            position: relative;
            width: 100%;
            margin-bottom: 20px;
        }
    
        .logo-container {
            position: absolute;
            right: 2px; /* Move the logo to the extreme right */
            top: 0; /* Align logo at the top */
        }
    
        .logo-container img {
            height: 60px; /* Adjust the height of the logo */
            width: auto;
            margin-bottom: 10px; /* Add space below the logo */
        }
    
        .widgets-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
    
        .widget {
            background-color: white;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            width: 350px;  /* Set width */
            max-width: 100%;
            margin: 0 auto;  /* Center the form horizontally */
        }
    
        .widget-header {
            color: midnightblue;
            font-size: 22px;
            font-weight: bold;
            text-align: center;
            margin-bottom: 20px;
            margin-left: -10px;
        }
    
        label {
            display: block;
            margin-bottom: 5px;
            font-size: 16px;
            color: #333;
        }
    
        input[type="date"],
        select {
            width: 100%;
            padding: 10px;
            font-size: 14px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
    
        .generate-btn {
            width: 100%;
            background-color: midnightblue;
            color: white;
            padding: 12px;
            border: none;
            cursor: pointer;
            border-radius: 5px;
            font-size: 16px;
            margin-top: 10px;
        }
    
        .generate-btn:hover {
            background-color: #0056b3;
        }
    
        .report-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin: 20px;
            width: 80%;  /* Reduce width */
            margin: 0 auto;  /* Center the report container horizontally */
        }
    
        .report-details {
            display: flex;
            justify-content: space-between;
            width: 100%;
        }
    
        .left-side {
            text-align: left;
            width: 45%;
        }
    
        .left-side p {
            display: block;
            text-align: left; /* Ensure the text starts from the same position */
            margin: 0;
            padding: 5px 0; /* Adjust vertical spacing */
        }
    
        .left-side strong {
            width: 150px; /* Set a fixed width for labels to align properly */
            display: inline-block; /* Ensures consistent label width */
        }
    
        .availability-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
    
        .availability-table th:first-child, .availability-table td:first-child {
            text-align: left;
            padding-left: 10px;
            width: 60%;  /* Reduced width for hostname */
        }
    
        .availability-table th:nth-child(2), .availability-table th:nth-child(3),
        .availability-table td:nth-child(2), .availability-table td:nth-child(3) {
            width: 20%;  /* Increased width for percentage columns */
            text-align: center;
        }
    
        .availability-table th, .availability-table td {
            padding: 10px;
            border: 1px solid #ddd;
        }
    
        .availability-table th {
            background-color: midnightblue;
            color: white;
        }
    
        .availability-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
    
        .action-btn-container {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
        }
    
        .action-btn {
            padding: 10px 20px;
            background-color: midnightblue;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
        }
    
        .action-btn:hover {
            background-color: #0056b3;
        }
    
        @media print {
            .action-btn-container {
                display: none;
            }
        }
    
        @media (max-width: 480px) {
            .widget {
                padding: 15px;
            }
    
            .widget-header {
                font-size: 18px;
            }
    
            label, input, select, .generate-btn {
                font-size: 14px;
            }
        }
    </style>
    


