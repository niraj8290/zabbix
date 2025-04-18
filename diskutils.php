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

// Fetch enabled hosts from Zabbix
function fetch_enabled_hosts($hostgroup_ids = []) {
    $params = [
        'output' => ['hostid', 'name', 'status', 'groups'],
        'filter' => ['status' => 0], // Only enabled hosts
        'selectGroups' => ['name', 'groupid']   // Include group names and IDs
    ];
    
    if (!empty($hostgroup_ids)) {
        $params['groupids'] = $hostgroup_ids;  // Filter hosts by selected groups
    }
    
    return zabbix_api_call('host.get', $params);
}

// Fetch disk space info for a given host
function fetch_disk_info($hostid) {
    return zabbix_api_call('item.get', [
        'output' => ['itemid', 'key_', 'lastvalue'],
        'hostids' => $hostid,
        'search' => ['key_' => 'vfs.fs.dependent.size'],  // Search for disk space info
        'filter' => ['state' => 0],
    ]);
}

// Convert bytes to GB or TB (for size data) or percentage for used disk space
function convert_size($size_in_bytes) {
    // Validate input
    if (!is_numeric($size_in_bytes) || $size_in_bytes === null) {
        return "Invalid size"; // Handle null or non-numeric input
    }

    // Handle percentages (if size is between 0 and 100)
    if ($size_in_bytes <= 100 && $size_in_bytes >= 0) {
        return round($size_in_bytes, 2) . "%";
    }

    // Convert bytes to GB or TB
    $size_in_gb = $size_in_bytes / (1024 ** 3); // Convert to GB
    if ($size_in_gb >= 1024) {
        return round($size_in_gb / 1024, 2) . " TB"; // Convert to TB if over 1024 GB
    } else {
        return round($size_in_gb, 2) . " GB";
    }
}


function fetch_trend_data($item_id, $time_from, $time_till) {
    $trend_data = zabbix_api_call('trend.get', [
        'output' => ['clock', 'value_min', 'value_avg', 'value_max'],
        'itemids' => $item_id,
        'time_from' => $time_from,
        'time_till' => $time_till,
    ]);

    // Validate response
    if (empty($trend_data)) {
        // Return default values when no trend data is available
        return [
            [
                'value_min' => 0,
                'value_avg' => 0,
                'value_max' => 0,
            ]
        ];
    }

    return $trend_data;
}

// Organize disk data into used, total, pused values, and calculate free space
function organize_disk_data_with_trends($disk_info, $time_from, $time_till) {
    $organized_data = [];

    foreach ($disk_info as $disk) {
        if (isset($disk['key_']) && isset($disk['lastvalue'])) {
            $key = $disk['key_'];
            $value = $disk['lastvalue'];

            // Attempt to extract the mount point using regex
            if (preg_match('/\[(.*?)(?:,.*?)?\]/', $key, $matches)) {
                $disk_name = $matches[1]; // Extract mount point (e.g., "C:", "/")
            } else {
                $disk_name = 'Unknown'; // Fallback if regex fails
            }

            // Log the key if extraction fails (for debugging)
            if ($disk_name === 'Unknown') {
                error_log("Failed to extract disk name from key: $key");
            }

            // Determine the data type (pused, total, or used)
            $type = (strpos($key, 'pused') !== false) ? 'pused' :
                    ((strpos($key, 'total') !== false) ? 'total' :
                    ((strpos($key, 'used') !== false) ? 'used' : ''));

            // Initialize the disk entry if not already set
            if (!isset($organized_data[$disk_name])) {
                $organized_data[$disk_name] = [
                    'used' => null,
                    'total' => null,
                    'pused' => null,
                    'free' => null,
                    'trend_min' => null,
                    'trend_max' => null,
                    'trend_avg' => null
                ];
            }

            // Assign values based on the data type
            if ($type === 'pused') {
                $organized_data[$disk_name]['pused'] = $value;

                // Fetch trend data
                $trend_data = fetch_trend_data($disk['itemid'], $time_from, $time_till);
                $min = PHP_INT_MAX;
                $max = PHP_INT_MIN;
                $sum = 0;
                $count = 0;

                foreach ($trend_data as $trend) {
                    $min = min($min, $trend['value_min']);
                    $max = max($max, $trend['value_max']);
                    $sum += $trend['value_avg'];
                    $count++;
                }

                $organized_data[$disk_name]['trend_min'] = $min === PHP_INT_MAX ? null : $min;
                $organized_data[$disk_name]['trend_max'] = $max === PHP_INT_MIN ? null : $max;
                $organized_data[$disk_name]['trend_avg'] = $count > 0 ? $sum / $count : null;
            } elseif ($type === 'total') {
                $organized_data[$disk_name]['total'] = $value;
            } elseif ($type === 'used') {
                $organized_data[$disk_name]['used'] = $value;
            }
        }
    }

    // Calculate free space (total - used)
    foreach ($organized_data as $disk_name => $data) {
        if (isset($data['total']) && isset($data['used'])) {
            $total_size = floatval($data['total']);
            $used_size = floatval($data['used']);
            $free_size = $total_size - $used_size;

            $organized_data[$disk_name]['free'] = $free_size;
        }
    }

    return $organized_data;
}


// Fetch hostgroups selected by the user
function fetch_selected_hostgroups($hostgroup_ids) {
    return zabbix_api_call('hostgroup.get', [
        'output' => ['groupid', 'name'],
        'groupids' => $hostgroup_ids,
    ]);
}

// Display the form to select hostgroups, date range, and report type
function display_form($hostgroups) {
    echo "<html>";
    echo "<head>";
    echo "<title>Generate Disk Utilization Report</title>";
    echo "<link href='https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css' rel='stylesheet' />";
    echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js'></script>";
    echo "<script src='https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js'></script>";
    echo "<style>
            body {
                font-family: Arial, sans-serif; 
                margin: 0; 
                padding: 0; 
                display: flex; 
                justify-content: center; 
                align-items: flex-start; 
                height: 100vh; 
                background-color: #f5f5f5; 
                overflow: auto;
            }
            .form-container {
                max-width: 25rem; 
                padding: 1.25rem; 
                background-color: #fff; 
                border-radius: 0.625rem; 
                box-shadow: 0 0.125rem 0.625rem rgba(0, 0, 0, 0.1); 
                width: 100%; 
                margin-top: 5rem; 
                box-shadow: 0rem 0.25rem 0.625rem rgba(0, 0, 0, 0.2);
            }
            .form-container h2 {
                color: MidnightBlue; 
                font-size: 1.8rem; 
                text-align: center; 
                margin-bottom: 1.25rem;
            }
            .form-container label {
                font-size: 1rem; 
                margin-bottom: 0.8rem; 
                display: block;
            }
            .form-container input[type='date'], .form-container select {
                width: 100%; 
                padding: 0.625rem; 
                margin: 0.3rem 0 0.9375rem; 
                font-size: 0.875rem; 
                border: 0.0625rem solid #ccc; 
                border-radius: 0.3125rem;
            }
            .form-container button {
                background-color: MidnightBlue; 
                color: white; 
                padding: 0.625rem; 
                border: none; 
                font-size: 0.875rem; 
                cursor: pointer; 
                width: 100%; 
                margin-top: 1.25rem; 
                border-radius: 0.3125rem;
            }
            .form-container button:hover {
                background-color: #4c4a8d;
            }
            #loadingMessage {
                font-size: 1.125rem; 
                color: MidnightBlue; 
                display: none; 
                text-align: center; 
                margin-top: 1.25rem;
            }
          </style>";
    echo "</head>";
    echo "<body>";
    
    echo "<div class='form-container'>";
    echo "<h1>Disk Utilization Report</h1>";
    echo "<form method='POST' action=''>";
    echo "<label for='start_date'>Start Date:</label>";
    echo "<input type='date' id='start_date' name='start_date' required>";
    
    echo "<label for='end_date'>End Date:</label>";
    echo "<input type='date' id='end_date' name='end_date' required>";
    
    echo "<label for='hostgroups'>Select Hostgroups:</label>";
    echo "<select name='hostgroups[]' id='hostgroups' multiple='multiple' required>";
    foreach ($hostgroups as $group) {
        echo "<option value='" . $group['groupid'] . "'>" . $group['name'] . "</option>";
    }
    echo "</select>";
    
    echo "<button type='submit' name='report_type' value='html'>Generate HTML Report</button>";
    echo "<button type='submit' name='report_type' value='excel'>Generate Excel Report</button>";
    echo "<button type='submit' name='report_type' value='csv'>Generate CSV Report</button>";
    
    echo "</form>";
    echo "</div>";
    echo "<script>
            $(document).ready(function() {
                $('#hostgroups').select2();
            });
          </script>";
    
    echo "</body>";
    echo "</html>";
}

function format_utilization($value) {
    // Check for invalid values and replace them with "N/A"
    if ($value === null || $value == 0 || $value == -1) {
        return "N/A";
    }
    return round($value, 2) . "%";
}


// Generate Excel report
function generate_excel_report($hosts_data_by_group, $summary_data) {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Write summary data
    $sheet->setCellValue('A1', 'Generated On')->setCellValue('B1', $summary_data['generated_on']);
    $sheet->setCellValue('A2', 'Start Date')->setCellValue('B2', $summary_data['start_date']);
    $sheet->setCellValue('A3', 'End Date')->setCellValue('B3', $summary_data['end_date']);
    $sheet->setCellValue('A4', 'Time Taken')->setCellValue('B4', $summary_data['time_taken'] . ' seconds');

    $row = 6; // Starting row for data
    foreach ($hosts_data_by_group as $group_name => $hosts) {
        $sheet->setCellValue("A$row", "Host Group: $group_name");
        $row++;

        // Table headers
        $sheet->setCellValue("A$row", "Host Name")
              ->setCellValue("B$row", "Disk")
              ->setCellValue("C$row", "Total Size")
              ->setCellValue("D$row", "Used Size")
              ->setCellValue("E$row", "Free Size")
              ->setCellValue("F$row", "Trend Min (%)")
              ->setCellValue("G$row", "Trend Max (%)")
              ->setCellValue("H$row", "Trend Avg (%)");
        $row++;

        // Table data
        foreach ($hosts as $host) {
            foreach ($host['disk_data'] as $disk => $data) {
                // Replace 0 or -1 values for total, used, and free sizes with "N/A"
                $total_size = (isset($data['total']) && $data['total'] > 0) ? convert_size($data['total']) : "N/A";
                $used_size  = (isset($data['used']) && $data['used'] > 0) ? convert_size($data['used']) : "N/A";
                $free_size  = (isset($data['free']) && $data['free'] > 0) ? convert_size($data['free']) : "N/A";
                

                // Replace 0% and -1% for trend data with "N/A"
                $trend_min = (isset($data['trend_min']) && $data['trend_min'] > 0) ? round($data['trend_min'], 2) . "%" : "N/A";
                $trend_max = (isset($data['trend_max']) && $data['trend_max'] > 0) ? round($data['trend_max'], 2) . "%" : "N/A";
                $trend_avg = (isset($data['trend_avg']) && $data['trend_avg'] > 0) ? round($data['trend_avg'], 2) . "%" : "N/A";
                

                // Add data to the Excel sheet
                $sheet->setCellValue("A$row", $host['name']);
                $sheet->setCellValue("B$row", $disk);
                $sheet->setCellValue("C$row", $total_size);
                $sheet->setCellValue("D$row", $used_size);
                $sheet->setCellValue("E$row", $free_size);
                $sheet->setCellValue("F$row", $trend_min);
                $sheet->setCellValue("G$row", $trend_max);
                $sheet->setCellValue("H$row", $trend_avg);

                $row++;
            }
        }
    }

    // Output as Excel file
    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="DiskUtilizationReport.xlsx"');
    $writer->save('php://output');
    exit;
}



// Generate CSV report
function generate_csv_report($hosts_data_by_group, $summary_data) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="DiskUtilizationReport.csv"');

    $output = fopen('php://output', 'w');

    // Write summary data
    fputcsv($output, ['Generated On', $summary_data['generated_on']]);
    fputcsv($output, ['Start Date', $summary_data['start_date']]);
    fputcsv($output, ['End Date', $summary_data['end_date']]);
    fputcsv($output, ['Time Taken', $summary_data['time_taken'] . ' seconds']);
    fputcsv($output, []); // Blank row

    foreach ($hosts_data_by_group as $group_name => $hosts) {
        fputcsv($output, ["Host Group: $group_name"]);
        fputcsv($output, ["Host Name", "Disk", "Total Size", "Used Size", "Free Size", "Min Utilization (%)", "Max Utilization (%)", "Avg Utilization (%)"]);

        foreach ($hosts as $host) {
            foreach ($host['disk_data'] as $disk => $data) {
                // Replace 0% and -1% with "N/A" and round values
                $trend_min = (isset($data['trend_min']) && $data['trend_min'] > 0) ? round($data['trend_min'], 2) . "%" : "N/A";
                $trend_max = (isset($data['trend_max']) && $data['trend_max'] > 0) ? round($data['trend_max'], 2) . "%" : "N/A";
                $trend_avg = (isset($data['trend_avg']) && $data['trend_avg'] > 0) ? round($data['trend_avg'], 2) . "%" : "N/A";

                // Replace 0 or -1 values for total, used, and free sizes with "N/A"
                $total_size = ($data['total'] > 0) ? convert_size($data['total']) : "N/A";
                $used_size  = ($data['used'] > 0) ? convert_size($data['used']) : "N/A";
                $free_size  = ($data['free'] > 0) ? convert_size($data['free']) : "N/A";

                fputcsv($output, [
                    $host['name'],
                    $disk,
                    $total_size,
                    $used_size,
                    $free_size,
                    $trend_min,
                    $trend_max,
                    $trend_avg
                ]);
            }
        }
        fputcsv($output, []); // Blank row between groups
    }

    fclose($output);
    exit;
}

if (!function_exists('format_utilization')) {
    function format_utilization($value) {
        return (is_numeric($value) && $value > 0) ? round($value, 2) . "%" : "N/A";
    }
}

if (!function_exists('format_size')) {
    function format_size($value) {
        return (isset($value) && $value > 0) ? convert_size($value) : "N/A";
    }
}


// Helper Functions - Avoid Redeclaration
if (!function_exists('format_utilization')) {
    function format_utilization($value) {
        return (is_numeric($value) && $value > 0) ? round($value, 2) . "%" : "N/A";
    }
}

if (!function_exists('format_size')) {
    function format_size($value) {
        return (isset($value) && $value > 0) ? convert_size($value) : "N/A";
    }
}

// Main Function
function display_report($hosts_data, $summary_data) {
    echo "<html>";
    echo "<head>";
    echo "<title>Disk Utilization Report</title>";
    echo "<style>
            body {font-family: Arial, sans-serif; margin: 0; padding: 0; display: flex; justify-content: center; align-items: flex-start; background-color: #f5f5f5; overflow: auto;}
            .report-container {max-width: 1430px; padding: 20px; background-color: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2); width: 100%; margin-top: 50px;}
            h1 {color: MidnightBlue; font-size: 2em; text-align: center;}
            h2 {font-size: 1.0em; font-weight: normal; color: MidnightBlue;}
            h3 {font-size: 1.0em; font-weight: normal; margin: 10px 0;}
            .summary {margin-bottom: 20px; color: #333; font-size: 1.1em;}
            table {width: 100%; border-collapse: collapse; margin-top: 20px; border: 1px solid #ddd;}
            table, th, td {border: 1px solid #ddd;}
            th, td {padding: 10px; text-align: left;}
            th {background-color: MidnightBlue; color: white;}  /* MidnightBlue background for headers */
            .center-buttons {text-align: center; margin-top: 30px;}
            .btn {background-color: MidnightBlue; color: white; padding: 12px 20px; border: none; cursor: pointer; font-size: 16px; border-radius: 5px;}
            .btn:hover {background-color: #4c4a8d;}
            .no-print {margin-right: 10px;}
            .btn-space {margin-right: 20px;}
            @media print { .no-print { display: none; } }
          </style>";
    echo "</head>";
    echo "<body>";

    echo "<div class='report-container'>"; // Report container with shadow
    echo "<h1>Disk Utilization Report</h1>";

    // Summary
    echo "<div class='summary'>";
    echo "<h2>Generated on: " . $summary_data['generated_on'] . "</h2>";
    echo "<h3>Date Range: " . $summary_data['start_date'] . " to " . $summary_data['end_date'] . "</h3>";
    echo "<h3>Report Time: " . $summary_data['time_taken'] . " seconds</h3>";
    echo "</div>";

    // Group hosts by host group and display separate tables for selected groups only
    foreach ($summary_data['hostgroups'] as $group_name => $group_hosts) {
        if (!empty($group_hosts)) { // Display only if the group has hosts
            // Display host group name
            echo "<h1>Host Group: " . htmlspecialchars($group_name) . "</h1>";

            // Display table for each host group
            echo "<table>";
            echo "<tr>
                    <th>Host Name</th>
                    <th>Disk</th>
                    <th>Total Size</th>
                    <th>Used Size</th>
                    <th>Free Size</th>
                    <th>Min Utilization (%)</th>
                    <th>Max Utilization (%)</th>
                    <th>Avg Utilization (%)</th>
                  </tr>";

            foreach ($group_hosts as $host) {
                foreach ($host['disk_data'] as $disk => $data) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($host['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($disk) . "</td>";

                    // Total, Used, and Free sizes with N/A replacement
                    echo "<td>" . format_size($data['total'] ?? 0) . "</td>";
                    echo "<td>" . format_size($data['used'] ?? 0) . "</td>";
                    echo "<td>" . format_size($data['free'] ?? 0) . "</td>";

                    // Min, Max, and Avg Utilization with N/A replacement
                    echo "<td>" . format_utilization($data['trend_min'] ?? 'N/A') . "</td>";
                    echo "<td>" . format_utilization($data['trend_max'] ?? 'N/A') . "</td>";
                    echo "<td>" . format_utilization($data['trend_avg'] ?? 'N/A') . "</td>";
                    echo "</tr>";
                }
            }

            echo "</table><br>";
        } else {
            // No hosts found in this group
            echo "<h2>No data available for Host Group: " . htmlspecialchars($group_name) . "</h2><br>";
        }
    }

    // Footer with buttons
    echo "<div class='center-buttons no-print'>";
    echo "<button class='btn btn-space' onclick='window.history.back()'>Back</button>";
    echo "<button class='btn' onclick='window.print()'>Print</button>";
    echo "</div>";
    echo "</div>";  // Closing the shadowed container

    echo "</body>";
    echo "</html>";
}





// Main execution
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $hostgroup_ids = $_POST['hostgroups'];
    $report_type = $_POST['report_type'];

    if (empty($hostgroup_ids)) {
        echo "<script>alert('Please select at least one host group.');</script>";
    } else {
        $hostgroups = fetch_selected_hostgroups($hostgroup_ids);
        $enabled_hosts = fetch_enabled_hosts($hostgroup_ids);

        $hosts_data_by_group = [];
        foreach ($enabled_hosts as $host) {
            $hostid = $host['hostid'];
            $disk_info = fetch_disk_info($hostid);

            // Use the new function with trends
            $disk_data = organize_disk_data_with_trends($disk_info, strtotime($start_date), strtotime($end_date));

            foreach ($host['groups'] as $group) {
                $group_name = $group['name'];
                if (in_array($group['groupid'], $hostgroup_ids)) {
                    if (!isset($hosts_data_by_group[$group_name])) {
                        $hosts_data_by_group[$group_name] = [];
                    }
                    $hosts_data_by_group[$group_name][] = [
                        'name' => $host['name'],
                        'disk_data' => $disk_data
                    ];
                }
            }
        }

        $summary_data = [
            'generated_on' => date('Y-m-d H:i:s'),
            'start_date' => $start_date,
            'end_date' => $end_date,
            'time_taken' => round(microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"], 2),
            'hostgroups' => $hosts_data_by_group
        ];

        if ($report_type === 'html') {
            display_report($hosts_data_by_group, $summary_data);
        } elseif ($report_type === 'excel') {
            generate_excel_report($hosts_data_by_group, $summary_data);
        } elseif ($report_type === 'csv') {
            generate_csv_report($hosts_data_by_group, $summary_data);
        }
    }
} else {
    $all_hostgroups = zabbix_api_call('hostgroup.get', ['output' => ['groupid', 'name']]);
    display_form($all_hostgroups);
}
?>


