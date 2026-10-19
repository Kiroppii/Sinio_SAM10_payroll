
<?php

require __DIR__ . '/vendor/autoload.php';

use Bramus\Router\Router;
use Dompdf\Dompdf;
use Dompdf\Options;
use Aws\S3\S3Client;

header('Content-Type: application/json');

$router = new Router();

$employeesFile = __DIR__ . '/employees-and-payroll-v3-csv-employees.csv';
$payrollFile = __DIR__ . '/employees-and-payroll-v3-csv-payroll.csv';

function readCsv(string $file): array
{
    if (!file_exists($file)) {
        http_response_code(500);
        echo json_encode(['error' => 'CSV file not found']);
        exit;
    }

    $handle = fopen($file, 'r');
    if ($handle === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Cannot open CSV file']);
        exit;
    }

    $headers = fgetcsv($handle);
    if ($headers === false) {
        fclose($handle);
        return [];
    }

    $rows = [];
    while (($data = fgetcsv($handle)) !== false) {
        if (count($data) !== count($headers)) {
            continue;
        }
        $rows[] = array_combine($headers, $data);
    }

    fclose($handle);
    return $rows;
}

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_PRETTY_PRINT);
}

$router->get('/api/employees', function () use ($employeesFile) {
    respond(readCsv($employeesFile));
});

$router->get('/api/reports/payroll', function () use ($payrollFile) {
    respond(readCsv($payrollFile));
});

$router->get('/api/payslips/(\d+)', function ($id) use ($payrollFile, $employeesFile) {
    $payroll = readCsv($payrollFile);
    $employees = readCsv($employeesFile);

    foreach ($payroll as $record) {
        if ((string)$record['id'] === (string)$id) {
            foreach ($employees as $employee) {
                if ((string)$employee['id'] === (string)$record['employee_id']) {
                    respond([
                        'payroll' => $record,
                        'employee' => $employee
                    ]);
                    return;
                }
            }

            respond(['error' => 'Employee not found for this payroll record'], 404);
            return;
        }
    }

    respond(['error' => 'Payroll record not found'], 404);
});

$router->post('/api/payslips/(\d+)/generate', function ($id) use ($payrollFile, $employeesFile) {
    $payroll = readCsv($payrollFile);
    $employees = readCsv($employeesFile);

    $record = null;
    $employee = null;

    foreach ($payroll as $item) {
        if ((string)$item['id'] === (string)$id) {
            $record = $item;
            break;
        }
    }

    if (!$record) {
        respond(['error' => 'Payroll record not found'], 404);
        return;
    }

    foreach ($employees as $item) {
        if ((string)$item['id'] === (string)$record['employee_id']) {
            $employee = $item;
            break;
        }
    }

    if (!$employee) {
        respond(['error' => 'Employee not found'], 404);
        return;
    }

    $html = '<h1>Payslip</h1>';
    $html .= '<p><strong>Employee:</strong> ' . htmlspecialchars($employee['full_name']) . '</p>';
    $html .= '<p><strong>Email:</strong> ' . htmlspecialchars($employee['email']) . '</p>';
    $html .= '<p><strong>Payroll ID:</strong> ' . htmlspecialchars($record['id']) . '</p>';
    $html .= '<p><strong>Pay period:</strong> ' .
        htmlspecialchars($record['pay_period_start']) . ' to ' .
        htmlspecialchars($record['pay_period_end']) . '</p>';
    $html .= '<p><strong>Gross pay:</strong> $' . htmlspecialchars($record['gross_pay']) . '</p>';
    $html .= '<p><strong>Net pay:</strong> $' . htmlspecialchars($record['net_pay']) . '</p>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $pdf = new Dompdf($options);
    $pdf->loadHtml($html);
    $pdf->setPaper('A4');
    $pdf->render();

    $bucket = getenv('S3_BUCKET');
    if (!$bucket) {
        respond(['error' => 'S3_BUCKET environment variable is not configured'], 500);
        return;
    }

    try {
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
        ]);

        $key = 'payslips/payslip-' . $id . '-' . time() . '.pdf';

        $s3->putObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'Body' => $pdf->output(),
            'ContentType' => 'application/pdf',
        ]);

        respond([
            'message' => 'Payslip generated and uploaded successfully',
            'payroll_id' => $id,
            's3_bucket' => $bucket,
            's3_key' => $key
        ], 201);
    } catch (\Throwable $e) {
        error_log($e->getMessage());
        respond(['error' => 'Payslip generation or S3 upload failed'], 500);
    }
});

$router->set404(function () {
    respond(['error' => 'Endpoint not found'], 404);
});

$router->run();
