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
 
    // Function to get all host groups
    // Function to get all host groups
function get_all_host_groups() {
    try {
        $response = zabbix_api_call('hostgroup.get', [
            'output' => ['groupid', 'name'],
        ]);

        if (!isset($response['result'])) {
            throw new Exception('Invalid response from Zabbix API.');
        }

        return $response['result'];
    } catch (Exception $e) {
        error_log("Error fetching host groups: " . $e->getMessage());
        // Return an empty array or handle fallback logic
        return [];
    }
}

// Function to get host IDs for a given group
function get_host_ids($group_id) {
    try {
        $response = zabbix_api_call('host.get', [
            'output' => ['hostid', 'name'],
            'groupids' => $group_id,
            'filter' => ['status' => 0],  // Only enabled hosts
        ]);

        if (!isset($response['result'])) {
            throw new Exception("Invalid response from Zabbix API for group ID $group_id.");
        }

        return $response['result'];
    } catch (Exception $e) {
        error_log("Error fetching hosts for group ID $group_id: " . $e->getMessage());
        // Return an empty array or handle fallback logic
        return [];
    }
}

 
    // Function to get CPU cores for a given host

    function get_cpu_cores($hostid) {
        // Call to Zabbix API for CPU core data
        $cpu_cores_response = zabbix_api_call('item.get', [
            'output' => ['lastvalue'],
            'hostids' => $hostid,
            'search' => ['key_' => 'system.cpu.num'],
        ]);
    
        // Debugging - Log the API response
        error_log('CPU cores response: ' . print_r($cpu_cores_response, true));
    
        // Check if the response contains valid CPU core data
        if (!empty($cpu_cores_response['result']) && isset($cpu_cores_response['result'][0]['lastvalue'])) {
            return (int) $cpu_cores_response['result'][0]['lastvalue'];
        }
    
        // Fallback to WMI data if CPU core data is not found
        $wmi_response = zabbix_api_call('item.get', [
            'output' => ['lastvalue'],
            'hostids' => $hostid,
            'search' => ['key_' => 'wmi.get[root/cimv2,"Select NumberOfLogicalProcessors from Win32_ComputerSystem"]'],
        ]);
    
        // Debugging - Log the WMI response
        error_log('WMI response: ' . print_r($wmi_response, true));
    
        // If WMI data is found, return the value, otherwise return "N/A"
        return !empty($wmi_response['result']) && isset($wmi_response['result'][0]['lastvalue']) ? (int) $wmi_response['result'][0]['lastvalue'] : 'N/A';
    }
    
    
 
    // Function to get CPU utilization for a given host
    function get_cpu_utilization($hostid, $time_from, $time_till) {
        try {
            // Fetch the item ID for CPU utilization
            $trend_response = zabbix_api_call('item.get', [
                'output' => ['itemid'],
                'hostids' => $hostid,
                'search' => ['key_' => 'system.cpu.util'],
            ]);
            // echo '<script>console.log(' . json_encode($hostid) . ')</script>';
    
            if (empty($trend_response) || !isset($trend_response['result']) || empty($trend_response['result'])) {
                throw new Exception("No CPU utilization data found for the given host ID.");
            }
    
            $itemid = $trend_response['result'][0]['itemid'];
    
            // Fetch trend data for the specified time range
            $trend_data = zabbix_api_call('trend.get', [
                'itemids' => $itemid,
                'time_from' => $time_from,
                'time_till' => $time_till,
            ]);
    
            // If no trend data exists, handle the case where the host might have been down during the time range
            if (empty($trend_data) || !isset($trend_data['result']) || empty($trend_data['result'])) {
                // No trend data available means agent might be down, still return 'N/A' for this host
                return ['min' => 'N/A', 'avg' => 'N/A', 'max' => 'N/A']; // Return placeholder data if no trend data is available
            }
    
            // Extract values and calculate statistics from the trend data
            $avg_values = array_map(function($data) { return (float) $data['value_avg']; }, $trend_data['result']);
            $min_values = array_map(function($data) { return (float) $data['value_min']; }, $trend_data['result']);
            $max_values = array_map(function($data) { return (float) $data['value_max']; }, $trend_data['result']);
    
            // Handle the case where partial data is available or values are empty
            if (empty($avg_values) || empty($min_values) || empty($max_values)) {
                return ['min' => 'N/A', 'avg' => 'N/A', 'max' => 'N/A']; // Return 'N/A' if the values are empty or incomplete
            }
    
            // Calculate and return the min, avg, and max values
            return [
                'min' => round(min($min_values), 2),
                'avg' => round(array_sum($avg_values) / count($avg_values), 2),
                'max' => round(max($max_values), 2),
            ];
        } catch (Exception $e) {
            // Log the error for debugging purposes
            error_log("Error in get_cpu_utilization: " . $e->getMessage());
    
            // Return default 'N/A' values in case of an error (e.g., if the host is down and no data is available)
            return ['min' => 'N/A', 'avg' => 'N/A', 'max' => 'N/A'];
        }
    }
    
    // Handle form submission and data retrieval
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : '';
$selected_groups = isset($_POST['hostgroups']) ? $_POST['hostgroups'] : [];

// If form is submitted and required parameters are available
// Function to handle the overall report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $start_date && $end_date && !empty($selected_groups)) {
    try {
        // Convert dates to Unix timestamps
        $time_from = strtotime($start_date . ' 00:00:00');
        $time_till = strtotime($end_date . ' 23:59:59');

        if ($time_from === false || $time_till === false) {
            throw new Exception("Invalid date format. Please provide valid start and end dates.");
        }

        // Fetch host groups based on user selection
        $host_groups = array_filter(get_all_host_groups(), function($group) use ($selected_groups) {
            return in_array($group['groupid'], $selected_groups);
        });

        if (empty($host_groups)) {
            throw new Exception("No valid host groups found for the selected IDs.");
        }

        $summary_data = [];
        $invalid_hosts = []; // To collect information about hosts with issues

        foreach ($host_groups as $group) {
            try {
                $group_name = $group['name'];
                $group_id = $group['groupid'];

                // Get hosts in this group
                $host_ids = get_host_ids($group_id);

                if (empty($host_ids)) {
                    throw new Exception("No hosts found in group '$group_name' (ID: $group_id).");
                }

                $group_has_data = false;

                foreach ($host_ids as $host) {
                    try {
                        $hostid = $host['hostid'];
                        $hostname = $host['name'];

                        // Get CPU cores and handle empty response
                        $cpu_cores = get_cpu_cores($hostid);
                        if ($cpu_cores === 'N/A') {
                            $invalid_hosts[] = "Host '$hostname' (ID: $hostid) has invalid CPU core count.";
                            continue;  // Skip this host if CPU core data is invalid
                        }

                        // Get CPU utilization data
                        $cpu_utilization = get_cpu_utilization($hostid, $time_from, $time_till);

                        // Only include hosts with valid data
                        if ($cpu_utilization['min'] > 0 || $cpu_utilization['avg'] > 0 || $cpu_utilization['max'] > 0) {
                            $summary_data[$group_name][] = [
                                'hostname' => $hostname,
                                'cpu_cores' => $cpu_cores,
                                'cpu_utilization' => $cpu_utilization,
                            ];
                            $group_has_data = true;
                        } else {
                            $invalid_hosts[] = "Host '$hostname' (ID: $hostid) has no valid CPU utilization data.";
                        }
                    } catch (Exception $e) {
                        // Collect errors for individual hosts
                        $invalid_hosts[] = "Error processing host '$hostname' (ID: $hostid): " . $e->getMessage();
                    }
                }

                // Skip the group if no valid data
                if (!$group_has_data) {
                    unset($summary_data[$group_name]);
                }
            } catch (Exception $e) {
                // Collect errors for individual groups
                error_log("Error processing group '$group_name' (ID: $group_id): " . $e->getMessage());
            }
        }

        // Handle the case where no valid data is found at all
        if (empty($summary_data)) {
            $error_message = "No valid CPU utilization data found for the selected groups and time range.";
            if (!empty($invalid_hosts)) {
                $error_message .= "<br>Details:<br>" . implode("<br>", $invalid_hosts);
            }
            throw new Exception($error_message);
        }
    } catch (Exception $e) {
        // Log the main error and provide detailed feedback to the user
        error_log("Error in processing CPU utilization summary: " . $e->getMessage());
        echo "<p>Error: " . nl2br(htmlspecialchars($e->getMessage())) . "</p>";
        $summary_data = []; // Ensure summary_data is empty on failure
    }
}
        $count = 0;
        // Export to Excel if requested
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Handle export to Excel
            if (isset($_POST['export_excel'])) {
                exportToExcel($summary_data);
                exit; // Prevent further execution after export
            }
        
            // Handle export to CSV
            if (isset($_POST['export_csv'])) {
                exportToCSV($summary_data);
                exit; // Prevent further execution after export
            }
        
            // Handle report generation (This should be your normal report generation logic)
            if (isset($_POST['generate_report'])) {
                generateReport($summary_data);
            }
        }
        
        function exportToExcel($summary_data) {
            // Include your PHPExcel or PhpSpreadsheet library
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            
            // Set column headers
            $sheet->setCellValue('A1', "No");
            $sheet->setCellValue('B1', 'Hostname');
            $sheet->setCellValue('C1', 'Total CPU');
            $sheet->setCellValue('D1', 'Min CPU Utilization (%)');
            $sheet->setCellValue('E1', 'Avg CPU Utilization (%)');
            $sheet->setCellValue('F1', 'Max CPU Utilization (%)');
            
            $row = 2;
            $serial_no = 1;  // Start serial number from 1
            foreach ($summary_data as $group_name => $hosts) {
                foreach ($hosts as $host) {
                    // Display serial number in column A
                    $sheet->setCellValue('A' . $row, $serial_no);
                    // Write host data in columns B to F
                    $sheet->setCellValue('B' . $row, $host['hostname']);
                    $sheet->setCellValue('C' . $row, $host['cpu_cores']);
                    $sheet->setCellValue('D' . $row, $host['cpu_utilization']['min']);
                    $sheet->setCellValue('E' . $row, $host['cpu_utilization']['avg']);
                    $sheet->setCellValue('F' . $row, $host['cpu_utilization']['max']);
                    
                    $row++;
                    $serial_no++;  // Increment serial number for each row
                }
            }
            
            $writer = new Xlsx($spreadsheet);
            $filename = 'cpu_utilization_report.xlsx';
            
            // Set headers for download
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $writer->save('php://output');
        }
        
        
        function exportToCSV($summary_data) {
            // Open the output stream for CSV download
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="cpu_utilization_report.csv"');
        
            // Open the file pointer for writing the CSV
            $output = fopen('php://output', 'w');
            
            // Add column headers to the CSV
            fputcsv($output, ['No.', 'Hostname', 'Total CPU', 'Min CPU Utilization (%)', 'Avg CPU Utilization (%)', 'Max CPU Utilization (%)']);
            
            // Write the data to CSV
            $serial_no = 1;  // Start serial number from 1
            foreach ($summary_data as $group_name => $hosts) {
                foreach ($hosts as $host) {
                    fputcsv($output, [
                        $serial_no,  // Display serial number in the first column
                        $host['hostname'],
                        $host['cpu_cores'],
                        $host['cpu_utilization']['min'],
                        $host['cpu_utilization']['avg'],
                        $host['cpu_utilization']['max']
                    ]);
                    $serial_no++;  // Increment serial number for each row
                }
            }
        
            // Close the output stream
            fclose($output);
        }
        
        
        function generateReport($summary_data) {
            
        }
        ?>

<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CPU Utilization Report</title>
        <link href="css/select2.min.css" rel="stylesheet" />
        <style>
        /* Base styling for body and overall page */
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f0f4f8;
        color: #333;
        box-sizing: border-box;
        overflow-x: hidden; /* Prevent horizontal overflow */
    }
 
    /* Main container for widgets */
    .widget-container {
        display: flex;
        flex-direction: row;
        justify-content: center; /* Prevents overlapping of content */
        align-items: flex-start;
        gap: 2rem; /* Space between forms */
        padding: 2rem;
        box-sizing: border-box;
        min-height: 100vh; /* Ensures the full viewport height is used */
        flex-wrap: wrap; /* Ensures items wrap on smaller screens */
    }
 
    /* Form container styling */
    .parameter-form,
    .report-form {
        background-color: white;
        padding: 1.5rem;
        box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        justify-content: center;
        border-radius: 0.625rem;
        box-sizing: border-box;
        flex: 1 1 30%; /* Ensures forms adjust width while keeping responsiveness */
        max-width: 35%; /* Prevents forms from becoming too wide */
        min-width: 280px; /* Ensures forms don’t shrink too small */
    }
    .report-form2 {
        background-color: white;
        padding: 1.5rem;
        box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
        border-radius: 0.625rem;
        box-sizing: border-box;
        flex: 1 1 30%; /* Ensures forms adjust width while keeping responsiveness */
        max-width: 235%; /* Prevents forms from becoming too wide */
        min-width: 280px; /* Ensures forms don’t shrink too small */
    }
 
    /* Title styling */
    h1 {
        color: midnightblue;
        font-size: 1.8rem;
        margin-bottom: 0.75rem;
    }
 
    /* Form styling */
    form {
        margin-bottom: 2rem;
    }
 
    label {
        font-size: 1rem;
        color: midnightblue;
        display: block;
        margin-bottom: 0.5rem;
    }
 
    /* Input and select styling */
    input[type="date"],
    select,
    .select2-container--default .select2-selection--single {
        padding: 0.625rem;
        margin-bottom: 1.25rem;
        border-radius: 0.3125rem;
        border: 0.0625rem solid #ddd;
        width: 100%;
        font-size: 1rem;
        box-sizing: border-box;
    }
 
    /* Button styling */
    button {
        padding: 0.9375rem 1.5625rem;
        background-color: midnightblue;
        color: white;
        border: none;
        border-radius: 0.3125rem;
        font-size: 1.125rem;
        cursor: pointer;
        width: 100%;
        margin-top: 1rem;
    }
 
    button:hover {
        background-color: #003366;
    }
 
    /* Styling for Back and Print buttons */
    .action-btn-container {
        display: flex;
        justify-content: center;
        flex-wrap: wrap; /* Wraps buttons on smaller screens */
        gap: 0.625rem;
        margin-top: 2rem;
    }
 
    .action-btn {
        width: auto;
        min-width: 6rem;
        padding: 0.625rem 1rem;
        background-color: midnightblue;
        color: white;
        border: none;
        border-radius: 0.3125rem;
        font-size: 1rem;
        text-align: center;
        cursor: pointer;
    }
 
    .action-btn:hover {
        background-color: #003366;
    }
 
    /* Table styling */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1.25rem;
        overflow: auto; /* Prevents table from overflowing */
    }
 
    th,
    td {
        border: 0.0625rem solid #ddd;
        padding: 0.75rem;
        text-align: left;
    }
 
    th {
        background-color: midnightblue;
        color: white;
    }
 
    /* No print */
    .no-print {
        display: inline-block;
    }
 
    /* Print media query */
    @media print {
        .no-print {
            display: none;
        }
 
        .widget-container {
            flex-direction: column;
            padding: 0;
        }
 
        h1 {
            font-size: 1.5rem;
        }
 
        form,
        #generate-btn {
            display: none;
        }
    }
 
    /* Mobile responsiveness */
    @media (max-width: 768px) {
        .widget-container {
            flex-direction: column; /* Stacks widgets vertically */
            padding: 1rem;
        }
 
        .parameter-form,
        .report-form {
            width: 100%; /* Ensures full width for smaller screens */
            margin-bottom: 1.5rem;
        }
        .report-form2 {
            width: 100%; /* Ensures full width for smaller screens */
            margin-bottom: 1.5rem;
        }
 
        h1 {
            font-size: 1.5rem;
            text-align: center; /* Center-align header on smaller screens */
        }
 
        button {
            width: 100%; /* Makes buttons responsive */
        }
 
        .action-btn-container {
            flex-direction: column;
            gap: 0.5rem;
        }
 
        .action-btn {
            width: 100%; /* Ensures buttons span full width */
        }
    }
 
    /* Additional Title Styling */
    h2 {
        text-align: center;
        color: midnightblue;
        font-weight: bold;
        font-size: 1.5rem;
    }
 
 
               
        </style>
</head>
<body>
<div class="widget-container">
        <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="parameter-form">
            <!-- Form for selecting parameters -->
            <h1> CPU Utilization Report</h1>
           
<form id="report-form" method="POST" action="">
    <label for="start_date">Start Date:</label><br>
    <input type="date" id="start_date" name="start_date" required><br><br>

    <label for="end_date">End Date:</label><br>
    <input type="date" id="end_date" name="end_date" required><br><br>

    <label for="hostgroups">Host Groups:</label><br>
    <select name="hostgroups[]" id="hostgroups" multiple="multiple" required>
        <?php foreach ($all_groups as $group): ?>
            <option value="<?php echo htmlspecialchars($group['groupid']); ?>">
                <?php echo htmlspecialchars($group['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
<br><br>

    <!-- Export to Excel Button -->
    <button type="submit" name="export_excel" value="1">Export to Excel</button> 

    <!-- Export to CSV Button -->
    <button type="submit" name="export_csv" value="1">Export to CSV</button>

    <!-- Generate Report Button -->
    <button type="submit" name="generate_report" value="1">Generate Report</button>
</form>

        </div>
        <?php endif; ?>
 
        <?php if (isset($summary_data) && !empty($summary_data)): ?>
        <div class="report-form2">
            <!-- Display Report if generated -->
            <div class="printable">
                <h2>CPU Utilization Report</h2>
 
           
                <p><strong>Start Date:</strong> <?php echo $start_date; ?></p>
                <p><strong>End Date:</strong> <?php echo $end_date; ?></p>
                <p><strong>Host Groups:</strong> <?php echo implode(', ', array_map(function($group) { return $group['name']; }, $host_groups)); ?></p>
 
                <?php foreach ($summary_data as $group_name => $hosts): ?>
                    <!-- <h2>Host Group: <?php echo $group_name; ?></h3> -->
 
                    <table>
                        <thead>
                            <tr>
                                <th>No.</th>
                                <th>Hostname</th>
                                <th>Cores</th>
                                <th>Min %</th>
                                <th>Avg %</th>
                                <th>Max %</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php $count = 0 ?>
                            <?php foreach ($hosts as $host): ?>
                                <?php $count++ ; ?>
                                <tr>
                                    <td><?php echo $count; ?></td>
                                    <td><?php echo $host['hostname']; ?></td>
                                    <td><?php echo $host['cpu_cores']; ?> </td>
                                    <td><?php echo $host['cpu_utilization']['min']; ?> % </td>
                                    <td><?php echo $host['cpu_utilization']['avg']; ?> % </td>
                                    <td><?php echo $host['cpu_utilization']['max']; ?> % </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
 
                <div class="action-btn-container">
                    <button class="action-btn no-print" onclick="window.history.back();">Back</button>
                    <button class="action-btn no-print" onclick="window.print();">Print</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
 
    <script src="js/jquery-3.6.0.min.js"></script>
    <script src="js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            setTimeout(() => {
                $('#hostgroups').select2({
                    placeholder: 'Select Host Groups',
                    allowClear: true
                });
            }, 500); // Slight delay ensures DOM is ready
        
 
            $('#report-form').submit(function() {
                // Disable button on form submit
                $('#generate-btn').prop('disabled', true);
                $('#generate-btn').text('Generating...');
            });                                                                        
        });
    </script>
 

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>

    </body>
    </html>



