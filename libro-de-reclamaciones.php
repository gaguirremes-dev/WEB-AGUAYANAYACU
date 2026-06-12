<?php
/**
 * Libro de Reclamaciones Virtual - Agua Yana Yacu
 * Conforme a la Ley N° 29571 (Código de Protección y Defensa del Consumidor)
 * y el D.S. N° 011-2011-PCM (modificado por D.S. 101-2021-PCM).
 */

date_default_timezone_set('America/Lima');

// Configuración de la empresa
define('EMPRESA_RAZON_SOCIAL', 'AGUA YANA YACU S.A.C.S.');
define('EMPRESA_RUC', '20615455262');
define('EMPRESA_DIRECCION', 'Calle Pedro Ortega MZ i Lote C11 - Urb. Chacupe, La Victoria, Chiclayo, Lambayeque');
define('EMPRESA_EMAIL', 'info@aguayanayacu.com');

// ── Credenciales SMTP (se cargan desde un archivo externo NO versionado) ──────
// En el servidor, copia config.smtp.example.php a config.smtp.php y coloca ahí
// tus credenciales reales. Ese archivo está excluido del repositorio (.gitignore).
$smtpConfigFile = __DIR__ . '/config.smtp.php';
if (file_exists($smtpConfigFile)) {
    require $smtpConfigFile;
}

// Valores por defecto (no sensibles) por si el archivo de config no los define
if (!defined('SMTP_PORT'))           define('SMTP_PORT', 465);  // 465 = SSL implícito
if (!defined('SMTP_FROM_NAME'))      define('SMTP_FROM_NAME', 'Agua Yana Yacu');
// A dónde llegan las notificaciones internas de nuevos reclamos
if (!defined('EMPRESA_NOTIF_EMAIL')) define('EMPRESA_NOTIF_EMAIL', 'reclamaciones@aguayanayacu.com');

$recordsDir = __DIR__ . '/reclamaciones';
$recordsFile = $recordsDir . '/records.json';
$lockFile = $recordsDir . '/records.lock';

// Crear el directorio si no existe
if (!is_dir($recordsDir)) {
    mkdir($recordsDir, 0755, true);
}

// ── AUTO-INSTALAR FPDF (solo la primera vez) ──────────────────────────────────
$fpdfPath = __DIR__ . '/lib/fpdf.php';
if (!file_exists($fpdfPath)) {
    if (!is_dir(__DIR__ . '/lib')) @mkdir(__DIR__ . '/lib', 0755, true);
    $fpdfSrc = @file_get_contents('https://raw.githubusercontent.com/Setasign/FPDF/master/fpdf.php');
    if ($fpdfSrc) @file_put_contents($fpdfPath, $fpdfSrc);
}
$fpdfAvailable = file_exists($fpdfPath);
if ($fpdfAvailable) require_once $fpdfPath;

// ── FUNCIÓN: Generar PDF de la hoja de reclamación ───────────────────────────
function generarPDFReclamo(array $d): string {
    if (!class_exists('FPDF')) return '';

    // Helper para convertir UTF-8 a Latin-1 (que usa FPDF por defecto)
    $c = function(string $s): string {
        return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    };

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    // ── Cabecera ──────────────────────────────────────────────────────────────
    $pdf->SetFillColor(10, 21, 64);  // #0A1540
    $pdf->Rect(0, 0, 210, 38, 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->SetXY(10, 7);
    $pdf->Cell(0, 8, $c('HOJA DE RECLAMACIÓN VIRTUAL'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY(10, 17);
    $pdf->Cell(0, 6, $c('Conforme a la Ley N 29571 - Codigo de Proteccion y Defensa del Consumidor'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY(10, 26);
    $pdf->Cell(0, 6, $c('Codigo: ' . $d['codigo'] . '     Fecha: ' . $d['fecha']), 0, 1, 'C');

    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(44);

    // ── Datos del proveedor ───────────────────────────────────────────────────
    $pdf->SetFillColor(235, 242, 252);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $c('PROVEEDOR'), 0, 1, 'L', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->MultiCell(0, 5, $c('Razon Social: ' . EMPRESA_RAZON_SOCIAL . '   RUC: ' . EMPRESA_RUC), 0, 'L');
    $pdf->MultiCell(0, 5, $c('Domicilio: ' . EMPRESA_DIRECCION), 0, 'L');
    $pdf->Ln(2);

    // ── Sección 1: Consumidor ─────────────────────────────────────────────────
    $pdf->SetFillColor(10, 21, 64);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $c('1. IDENTIFICACION DEL CONSUMIDOR RECLAMANTE'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', '', 9);

    $col1W = 45; $col2W = 135;
    $rows = [
        ['Nombre completo:', $d['nombres']],
        ['Documento:', $d['doc_tipo'] . ' N ' . $d['doc_nro']],
        ['Domicilio:', $d['direccion'] . ', ' . $d['distrito'] . ' - ' . $d['provincia'] . ' (' . $d['departamento'] . ')'],
        ['Telefono:', $d['telefono'] ?: '-'],
        ['Email:', $d['email']],
    ];
    if ($d['menor_edad']) {
        $rows[] = ['Apoderado:', $d['apoderado_nombres'] . ' (' . $d['apoderado_doc_tipo'] . ' ' . $d['apoderado_doc_nro'] . ')'];
    }
    foreach ($rows as [$label, $val]) {
        $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell($col1W, 5.5, $c($label), 'B', 0, 'L');
        $pdf->SetFont('Arial', '', 8.5);  $pdf->Cell($col2W, 5.5, $c($val),   'B', 1, 'L');
    }
    $pdf->Ln(3);

    // ── Sección 2: Bien contratado ────────────────────────────────────────────
    $pdf->SetFillColor(10, 21, 64);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $c('2. IDENTIFICACION DEL BIEN CONTRATADO'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell($col1W, 5.5, $c('Tipo de bien:'), 'B', 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);  $pdf->Cell($col2W, 5.5, $c(ucfirst($d['bien_tipo'])), 'B', 1, 'L');
    $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell($col1W, 5.5, $c('Monto reclamado:'), 'B', 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);  $pdf->Cell($col2W, 5.5, $c('S/. ' . $d['monto']), 'B', 1, 'L');
    $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell($col1W, 5.5, $c('Descripcion:'), 'B', 0, 'L');
    $pdf->SetFont('Arial', '', 8.5);  $pdf->Cell($col2W, 5.5, $c($d['bien_desc'] ?: '-'), 'B', 1, 'L');
    $pdf->Ln(3);

    // ── Sección 3: Detalle reclamo ────────────────────────────────────────────
    $pdf->SetFillColor(10, 21, 64);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->Cell(0, 6, $c('3. DETALLE DE LA RECLAMACION Y PEDIDO DEL CONSUMIDOR'), 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);

    // Tipo con checkbox
    $pdf->SetFont('Arial', 'B', 9); $pdf->Cell(40, 6, $c('Tipo de incidencia:'), 0, 0);
    $pdf->SetFont('Arial', '', 9);
    $esReclamo = strtolower($d['reclamo_tipo']) === 'reclamo';
    $pdf->Cell(5, 6, $esReclamo ? 'X' : 'O', 1, 0, 'C');
    $pdf->Cell(22, 6, $c(' Reclamo'), 0, 0);
    $pdf->Cell(5, 6, !$esReclamo ? 'X' : 'O', 1, 0, 'C');
    $pdf->Cell(22, 6, $c(' Queja'), 0, 1);
    $pdf->Ln(1);

    $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(0, 5, $c('Detalle del hecho:'), 0, 1);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->SetFillColor(248, 248, 248);
    $pdf->MultiCell(0, 5, $c($d['detalle']), 1, 'L', true);
    $pdf->Ln(2);

    $pdf->SetFont('Arial', 'B', 8.5); $pdf->Cell(0, 5, $c('Pedido del consumidor:'), 0, 1);
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->MultiCell(0, 5, $c($d['pedido']), 1, 'L', true);
    $pdf->Ln(4);

    // ── Firmas ────────────────────────────────────────────────────────────────
    $pdf->SetFont('Arial', '', 8.5);
    $pdf->Cell(90, 5, $c('Firma del Consumidor'), 'T', 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(90, 5, $c('Firma del Proveedor'), 'T', 1, 'C');
    $pdf->Ln(2);

    // ── Pie legal ─────────────────────────────────────────────────────────────
    $pdf->SetFont('Arial', 'I', 7.5);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->MultiCell(0, 4, $c(
        '* La formulacion del reclamo no impide acudir a otras vias de solucion de controversias ni es requisito previo para interponer una denuncia ante el INDECOPI. ' .
        '* El proveedor debe dar respuesta al reclamo o queja en un plazo no mayor a quince (15) dias habiles improrrogables (D.S. 101-2021-PCM).'
    ), 0, 'L');

    return $pdf->Output('', 'S');
}

// ── FUNCIÓN: Enviar correo vía SMTP autenticado (con PDF adjunto opcional) ────
function enviarCorreoSMTP(string $toEmail, string $subject, string $htmlBody, string $pdfB64 = '', string $pdfName = '', string &$err = ''): bool {
    // Sin credenciales no se puede enviar (falta config.smtp.php en el servidor)
    if (!defined('SMTP_HOST') || !defined('SMTP_USER') || !defined('SMTP_PASS')) {
        $err = 'Configuración SMTP no encontrada (falta config.smtp.php).';
        return false;
    }

    // Codificar cabeceras con caracteres UTF-8 (ej. tildes, símbolo °)
    $enc = function (string $s): string {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    };

    $fromEmail = SMTP_USER;

    // ── Construir el mensaje MIME ──────────────────────────────────────────────
    $eol = "\r\n";
    $headers  = 'Date: ' . date('r') . $eol;
    $headers .= 'From: ' . $enc(SMTP_FROM_NAME) . ' <' . $fromEmail . '>' . $eol;
    $headers .= 'To: <' . $toEmail . '>' . $eol;
    $headers .= 'Subject: ' . $enc($subject) . $eol;
    $headers .= 'Reply-To: ' . EMPRESA_EMAIL . $eol;
    $headers .= 'MIME-Version: 1.0' . $eol;

    if ($pdfB64) {
        $boundary = '----=_Part_' . md5(uniqid());
        $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol;

        $body  = '--' . $boundary . $eol;
        $body .= 'Content-Type: text/html; charset=UTF-8' . $eol;
        $body .= 'Content-Transfer-Encoding: 7bit' . $eol . $eol;
        $body .= $htmlBody . $eol;
        $body .= '--' . $boundary . $eol;
        $body .= 'Content-Type: application/pdf; name="' . $pdfName . '"' . $eol;
        $body .= 'Content-Transfer-Encoding: base64' . $eol;
        $body .= 'Content-Disposition: attachment; filename="' . $pdfName . '"' . $eol . $eol;
        $body .= $pdfB64 . $eol;
        $body .= '--' . $boundary . '--';
    } else {
        $headers .= 'Content-Type: text/html; charset=UTF-8' . $eol;
        $body = $htmlBody;
    }

    $message = $headers . $eol . $body;
    // Normalizar saltos de línea a CRLF y aplicar "dot-stuffing"
    $message = str_replace(["\r\n", "\r", "\n"], "\n", $message);
    $message = str_replace("\n", $eol, $message);
    $message = str_replace($eol . '.', $eol . '..', $message);

    // ── Conexión SMTP ──────────────────────────────────────────────────────────
    $transport = (SMTP_PORT == 465 ? 'ssl://' : '') . SMTP_HOST . ':' . SMTP_PORT;
    $ctx = stream_context_create(['ssl' => [
        'verify_peer'       => false,
        'verify_peer_name'  => false,
        'allow_self_signed' => true,
    ]]);
    $fp = @stream_socket_client($transport, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $err = "Conexión SMTP fallida: $errstr ($errno)"; return false; }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            // La última línea de una respuesta multinivel tiene un espacio en la 4ta posición
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $data;
    };
    $cmd = function (string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };
    // Verifica que la respuesta comience con alguno de los códigos esperados
    $ok = function (string $resp, array $codes) use (&$err): bool {
        foreach ($codes as $code) {
            if (strncmp($resp, $code, strlen($code)) === 0) return true;
        }
        $err = trim($resp);
        return false;
    };

    $fail = function () use ($fp) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); return false; };

    if (!$ok($read(), ['220']))                              return $fail();
    if (!$ok($cmd('EHLO ' . SMTP_HOST), ['250']))            return $fail();
    if (!$ok($cmd('AUTH LOGIN'), ['334']))                   return $fail();
    if (!$ok($cmd(base64_encode(SMTP_USER)), ['334']))       return $fail();
    if (!$ok($cmd(base64_encode(SMTP_PASS)), ['235']))       return $fail();
    if (!$ok($cmd('MAIL FROM:<' . $fromEmail . '>'), ['250'])) return $fail();
    if (!$ok($cmd('RCPT TO:<' . $toEmail . '>'), ['250', '251'])) return $fail();
    if (!$ok($cmd('DATA'), ['354']))                         return $fail();
    if (!$ok($cmd($message . "\r\n."), ['250']))             return $fail();

    $cmd('QUIT');
    fclose($fp);
    return true;
}

$success = false;
$errorMsg = '';
$generatedCode = '';
$submittedData = [];

// Procesar el formulario si es una solicitud POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitización básica
    $nombres = filter_input(INPUT_POST, 'nombres', FILTER_SANITIZE_SPECIAL_CHARS);
    $doc_tipo = filter_input(INPUT_POST, 'doc_tipo', FILTER_SANITIZE_SPECIAL_CHARS);
    $doc_nro = filter_input(INPUT_POST, 'doc_nro', FILTER_SANITIZE_SPECIAL_CHARS);
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    $telefono = filter_input(INPUT_POST, 'telefono', FILTER_SANITIZE_SPECIAL_CHARS);
    $direccion = filter_input(INPUT_POST, 'direccion', FILTER_SANITIZE_SPECIAL_CHARS);
    $departamento = filter_input(INPUT_POST, 'departamento', FILTER_SANITIZE_SPECIAL_CHARS);
    $provincia = filter_input(INPUT_POST, 'provincia', FILTER_SANITIZE_SPECIAL_CHARS);
    $distrito = filter_input(INPUT_POST, 'distrito', FILTER_SANITIZE_SPECIAL_CHARS);
    
    $menor_edad = isset($_POST['menor_edad']) ? true : false;
    $apoderado_nombres = filter_input(INPUT_POST, 'apoderado_nombres', FILTER_SANITIZE_SPECIAL_CHARS);
    $apoderado_doc_tipo = filter_input(INPUT_POST, 'apoderado_doc_tipo', FILTER_SANITIZE_SPECIAL_CHARS);
    $apoderado_doc_nro = filter_input(INPUT_POST, 'apoderado_doc_nro', FILTER_SANITIZE_SPECIAL_CHARS);
    
    $bien_tipo = filter_input(INPUT_POST, 'bien_tipo', FILTER_SANITIZE_SPECIAL_CHARS);
    $monto = filter_input(INPUT_POST, 'monto', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $bien_desc = filter_input(INPUT_POST, 'bien_desc', FILTER_SANITIZE_SPECIAL_CHARS);
    
    $reclamo_tipo = filter_input(INPUT_POST, 'reclamo_tipo', FILTER_SANITIZE_SPECIAL_CHARS);
    $detalle = filter_input(INPUT_POST, 'detalle', FILTER_SANITIZE_SPECIAL_CHARS);
    $pedido = filter_input(INPUT_POST, 'pedido', FILTER_SANITIZE_SPECIAL_CHARS);
    
    // Validar campos obligatorios
    if (!$nombres || !$doc_tipo || !$doc_nro || !$email || !$direccion || !$bien_tipo || !$reclamo_tipo || !$detalle || !$pedido) {
        $errorMsg = 'Por favor, rellene todos los campos obligatorios del formulario.';
    } else {
        // Bloquear archivo y guardar registro
        $fp = fopen($lockFile, 'w');
        if ($fp && flock($fp, LOCK_EX)) {
            $year = date('Y');
            $records = [];
            
            if (file_exists($recordsFile)) {
                $records = json_decode(file_get_contents($recordsFile), true) ?: [];
            }
            
            // Contar registros del año actual para el correlativo
            $yearCount = 0;
            foreach ($records as $r) {
                if (isset($r['year']) && $r['year'] == $year) {
                    $yearCount++;
                }
            }
            
            $nextNum = $yearCount + 1;
            $generatedCode = sprintf('REC-%s-%04d', $year, $nextNum);
            
            $submittedData = [
                'codigo' => $generatedCode,
                'year' => $year,
                'fecha' => date('d/m/Y h:i A'),
                'nombres' => $nombres,
                'doc_tipo' => $doc_tipo,
                'doc_nro' => $doc_nro,
                'email' => $email,
                'telefono' => $telefono,
                'direccion' => $direccion,
                'departamento' => $departamento,
                'provincia' => $provincia,
                'distrito' => $distrito,
                'menor_edad' => $menor_edad,
                'apoderado_nombres' => $menor_edad ? $apoderado_nombres : '',
                'apoderado_doc_tipo' => $menor_edad ? $apoderado_doc_tipo : '',
                'apoderado_doc_nro' => $menor_edad ? $apoderado_doc_nro : '',
                'bien_tipo' => $bien_tipo,
                'monto' => $monto ? number_format((float)$monto, 2, '.', '') : '0.00',
                'bien_desc' => $bien_desc,
                'reclamo_tipo' => $reclamo_tipo,
                'detalle' => $detalle,
                'pedido' => $pedido,
                'estado' => 'Pendiente',
                'respuesta' => ''
            ];
            
            $records[] = $submittedData;
            file_put_contents($recordsFile, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            flock($fp, LOCK_UN);
            $success = true;
        } else {
            $errorMsg = 'Error al registrar la reclamación en el servidor. Inténtelo nuevamente.';
        }
        if ($fp) fclose($fp);
        
        // Si se guardó correctamente, enviar los correos
        if ($success) {

            // ── Generar PDF adjunto ───────────────────────────────────────────
            $pdfBytes   = $fpdfAvailable ? generarPDFReclamo($submittedData) : '';
            $pdfB64     = $pdfBytes ? chunk_split(base64_encode($pdfBytes)) : '';
            $pdfName    = 'Hoja_Reclamacion_' . $generatedCode . '.pdf';

            // Guardar copia PDF en el servidor como respaldo
            if ($pdfBytes) {
                @file_put_contents($recordsDir . '/' . $pdfName, $pdfBytes);
            }

            // 1. Preparar correo para el cliente
            $subjectCliente = "Cargo de Hoja de Reclamación N° $generatedCode - Agua Yana Yacu";

            $emailBody = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05);'>
                <div style='background-color: #0A1540; padding: 25px; text-align: center; color: white;'>
                    <h2 style='margin: 0; font-size: 22px; letter-spacing: 1px;'>HOJA DE RECLAMACIÓN VIRTUAL</h2>
                    <p style='margin: 5px 0 0 0; color: #5BB8E8; font-size: 14px; font-weight: bold;'>Código: $generatedCode</p>
                </div>
                <div style='padding: 25px; line-height: 1.6;'>
                    <p>Estimado(a) <strong>$nombres</strong>,</p>
                    <p>Confirmamos la recepción de tu reclamación registrada el <strong>" . date('d/m/Y') . "</strong>. Adjunto a este correo encontrarás el cargo de tu Hoja de Reclamación Virtual.</p>
                    <p>De acuerdo con la legislación vigente en el Perú (Ley N° 29571), daremos respuesta a tu requerimiento en un plazo máximo de **15 días hábiles**.</p>
                    
                    <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                    
                    <h3 style='color: #0A1540; margin-top: 0;'>Detalle de la Reclamación</h3>
                    <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                        <tr>
                            <td style='padding: 6px 0; font-weight: bold; width: 150px;'>Consumidor:</td>
                            <td style='padding: 6px 0;'>$nombres ($doc_tipo $doc_nro)</td>
                        </tr>";
            if ($menor_edad) {
                $emailBody .= "
                        <tr>
                            <td style='padding: 6px 0; font-weight: bold;'>Apoderado:</td>
                            <td style='padding: 6px 0;'>$apoderado_nombres ($apoderado_doc_tipo $apoderado_doc_nro)</td>
                        </tr>";
            }
            $emailBody .= "
                        <tr>
                            <td style='padding: 6px 0; font-weight: bold;'>Bien Contratado:</td>
                            <td style='padding: 6px 0; text-transform: capitalize;'>$bien_tipo (Monto: S/. " . ($monto ?: '0.00') . ")</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; font-weight: bold;'>Tipo de Incidencia:</td>
                            <td style='padding: 6px 0; text-transform: capitalize; font-weight: bold; color: " . ($reclamo_tipo == 'reclamo' ? '#dc2626' : '#d97706') . ";'>$reclamo_tipo</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; font-weight: bold; vertical-align: top;'>Detalle:</td>
                            <td style='padding: 6px 0; background-color: #f9f9f9; padding: 8px; border-radius: 4px;'>$detalle</td>
                        </tr>
                        <tr>
                            <td style='padding: 6px 0; font-weight: bold; vertical-align: top;'>Pedido del Cliente:</td>
                            <td style='padding: 6px 0; background-color: #f9f9f9; padding: 8px; border-radius: 4px;'>$pedido</td>
                        </tr>
                    </table>
                    
                    <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                    
                    <h3 style='color: #0A1540; font-size: 15px;'>Datos del Proveedor</h3>
                    <p style='font-size: 13px; color: #666; margin: 0;'>
                        <strong>" . EMPRESA_RAZON_SOCIAL . "</strong><br>
                        RUC: " . EMPRESA_RUC . "<br>
                        Dirección: " . EMPRESA_DIRECCION . "
                    </p>
                </div>
                <div style='background-color: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #888; border-top: 1px solid #e0e0e0;'>
                    Este correo representa un cargo automático de recepción de tu reclamo. Por favor no responder a este mensaje.
                </div>
            </div>
            ";
            
            // Enviar al cliente (con PDF adjunto) vía SMTP
            enviarCorreoSMTP($email, $subjectCliente, $emailBody, $pdfB64, $pdfName);

            // 2. Preparar y enviar correo a la empresa
            $subjectEmpresa = "NUEVA HOJA DE RECLAMACIÓN N° $generatedCode - $nombres";
            $emailBodyEmpresa = "
            <div style='font-family: Arial, sans-serif; color: #333; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 8px; padding: 25px;'>
                <h2 style='color: #dc2626; margin-top: 0;'>Nuevo Reclamo/Queja Registrado</h2>
                <p>Se ha registrado un nuevo reclamo virtual en la página web con el código correlativo <strong>$generatedCode</strong>.</p>
                <p><strong>Plazo Máximo Legal de Respuesta:</strong> 15 días hábiles a partir de la fecha de hoy.</p>
                
                <h3 style='border-bottom: 2px solid #eee; padding-bottom: 5px; color: #0A1540;'>1. Identificación del Reclamante</h3>
                <p><strong>Nombre:</strong> $nombres<br>
                <strong>Documento:</strong> $doc_tipo $doc_nro<br>
                <strong>Teléfono:</strong> $telefono<br>
                <strong>Email:</strong> <a href='mailto:$email'>$email</a><br>
                <strong>Dirección:</strong> $direccion, $distrito - $provincia ($departamento)</p>";
            
            if ($menor_edad) {
                $emailBodyEmpresa .= "
                <p><strong>Representante Legal (Menor de Edad):</strong><br>
                <strong>Nombre:</strong> $apoderado_nombres<br>
                <strong>Documento:</strong> $apoderado_doc_tipo $apoderado_doc_nro</p>";
            }
            
            $emailBodyEmpresa .= "
                <h3 style='border-bottom: 2px solid #eee; padding-bottom: 5px; color: #0A1540;'>2. Identificación del Bien Contratado</h3>
                <p><strong>Tipo:</strong> " . ucfirst($bien_tipo) . "<br>
                <strong>Monto Reclamado:</strong> S/. " . ($monto ?: '0.00') . "<br>
                <strong>Descripción del bien:</strong> $bien_desc</p>
                
                <h3 style='border-bottom: 2px solid #eee; padding-bottom: 5px; color: #0A1540;'>3. Detalle del Reclamo / Pedido</h3>
                <p><strong>Tipo de Incidencia:</strong> " . strtoupper($reclamo_tipo) . "</p>
                <div style='background: #f7f7f7; padding: 12px; border-left: 4px solid #dc2626; margin-bottom: 10px;'>
                    <strong>Detalle del Reclamo/Queja:</strong><br>
                    " . nl2br($detalle) . "
                </div>
                <div style='background: #f7f7f7; padding: 12px; border-left: 4px solid #3b82f6;'>
                    <strong>Pedido / Solución solicitada:</strong><br>
                    " . nl2br($pedido) . "
                </div>
                
                <p style='margin-top: 25px; font-size: 12px; color: #666;'>
                    Este registro ha sido guardado de manera segura en el archivo central del servidor.
                </p>
            </div>
            ";
            
            // Enviar a la empresa (con PDF adjunto) vía SMTP
            enviarCorreoSMTP(EMPRESA_NOTIF_EMAIL, $subjectEmpresa, $emailBodyEmpresa, $pdfB64, $pdfName);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libro de Reclamaciones - Agua Yana Yacu</title>
    <meta name="description" content="Libro de Reclamaciones Virtual de Agua Yana Yacu. Presenta tus quejas o reclamos conforme al Código de Protección al Consumidor en Perú.">
    <link rel="icon" type="image/png" href="favicon-32x32.png">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        navy: {
                            deep:  '#0A1540',
                            mid:   '#1A2D6E',
                            light: '#2A4090',
                        },
                        sky: {
                            brand: '#5BB8E8',
                            light: '#B8E0F7',
                            pale:  '#E8F5FD',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <style>
        * { box-sizing: border-box; }
        body {
            background-color: #0A1540;
            color: #FFFFFF;
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
        }
        /* Glassmorphism card */
        .glass {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(91,184,232,0.18);
        }
        /* Gradient text */
        .grad-text {
            background: linear-gradient(135deg, #5BB8E8 0%, #B8E0F7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        /* Form inputs styled to match brand */
        .form-input {
            width: 100%;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 10px 14px;
            color: #fff;
            font-size: 0.875rem;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .form-input::placeholder { color: rgba(255,255,255,0.28); }
        .form-input:focus {
            border-color: #5BB8E8;
            box-shadow: 0 0 0 2px rgba(91,184,232,0.18);
        }
        select.form-input { background-color: #1A2D6E; }
        select.form-input option { background: #1A2D6E; color: white; }

        /* Secondary buttons */
        .btn-sec {
            border: 1px solid rgba(91,184,232,0.45);
            color: #5BB8E8;
            background: rgba(91,184,232,0.05);
            transition: all 0.3s;
        }
        .btn-sec:hover {
            background: rgba(91,184,232,0.15);
            transform: translateY(-1px);
        }

        /* Primary button */
        .btn-primary {
            background: linear-gradient(135deg, #5BB8E8, #1A2D6E);
            transition: transform 0.25s, box-shadow 0.25s;
            position: relative;
            overflow: hidden;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(91,184,232,0.38);
        }

        /* Print styles */
        @media print {
            body {
                background: white !important;
                color: black !important;
                font-size: 11px !important;
            }
            .no-print {
                display: none !important;
            }
            .print-only {
                display: block !important;
            }
            .print-card {
                background: white !important;
                border: 1px solid #ddd !important;
                box-shadow: none !important;
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
                color: black !important;
                padding: 15px !important;
                border-radius: 0 !important;
            }
            .print-field {
                border-bottom: 1px solid #ccc !important;
                padding: 4px 0 !important;
                background: transparent !important;
                color: black !important;
                border-radius: 0 !important;
                font-size: 11px !important;
            }
            .print-title {
                color: black !important;
                font-size: 16px !important;
            }
            .print-label {
                color: #555 !important;
                font-weight: bold !important;
                font-size: 10px !important;
            }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- HEADER / BRAND (no-print) -->
    <header class="no-print w-full border-b border-white/10 bg-navy-deep/80 backdrop-blur-md sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="index.html" class="flex items-center gap-3">
                <img src="LOGO - AGUA YANA YACU.jpg" alt="Agua Yana Yacu" class="h-10 w-10 rounded-full object-cover">
                <div>
                    <div class="text-white font-black text-sm tracking-wide">YANA YACU</div>
                    <div class="text-sky-brand text-[9px] tracking-widest">CULTURA DE VIDA</div>
                </div>
            </a>
            <a href="index.html" class="text-white/60 hover:text-white text-xs flex items-center gap-1.5 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Volver a la web
            </a>
        </div>
    </header>

    <!-- MAIN CONTENT CONTAINER -->
    <main class="flex-grow max-w-4xl w-full mx-auto px-4 py-10">

        <!-- SUCCESS SCREEN -->
        <?php if ($success): ?>
            <div class="print-card glass rounded-3xl p-8 sm:p-12 shadow-2xl relative border border-emerald-500/30">
                <!-- Water Orb Deco (no-print) -->
                <div class="no-print absolute -right-20 -top-20 w-80 h-80 bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>

                <div class="no-print text-center mb-8">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-3xl mb-4">✓</div>
                    <h1 class="text-3xl font-black text-white mb-2">¡Reclamación Registrada!</h1>
                    <p class="text-white/60 text-sm max-w-lg mx-auto">
                        Tu reclamo/queja ha sido procesado de forma correcta. Se ha enviado un cargo de recepción al correo <strong class="text-white"><?= htmlspecialchars($submittedData['email']) ?></strong>.
                    </p>
                </div>

                <!-- Printable Sheet -->
                <div class="bg-navy-mid/30 p-6 rounded-2xl border border-white/5 print-card">
                    <!-- Title Block -->
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-white/10 pb-5 mb-6 print-field">
                        <div>
                            <h2 class="text-xl font-black text-white print-title">HOJA DE RECLAMACIÓN VIRTUAL</h2>
                            <p class="text-xs text-white/50 mt-1">Conforme a la Ley N° 29571 / D.S. N° 011-2011-PCM</p>
                        </div>
                        <div class="mt-4 sm:mt-0 text-left sm:text-right">
                            <div class="text-xs text-sky-brand font-black uppercase tracking-wider print-label">CÓDIGO DE RECLAMACIÓN</div>
                            <div class="text-xl font-black text-emerald-400 print-title mt-0.5"><?= htmlspecialchars($submittedData['codigo']) ?></div>
                            <div class="text-[10px] text-white/40 mt-1">Fecha de registro: <?= htmlspecialchars($submittedData['fecha']) ?></div>
                        </div>
                    </div>

                    <!-- Company details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6 text-xs text-white/60 border-b border-white/5 pb-4 print-field">
                        <div>
                            <span class="block font-bold text-white/80 print-label">Proveedor:</span>
                            <?= EMPRESA_RAZON_SOCIAL ?>
                        </div>
                        <div>
                            <span class="block font-bold text-white/80 print-label">RUC:</span>
                            <?= EMPRESA_RUC ?>
                        </div>
                        <div class="md:col-span-2">
                            <span class="block font-bold text-white/80 print-label">Domicilio Fiscal:</span>
                            <?= EMPRESA_DIRECCION ?>
                        </div>
                    </div>

                    <!-- 1. Consumer details -->
                    <h3 class="text-sm font-bold uppercase tracking-wider text-sky-brand mb-3 print-label">1. Identificación del Consumidor Reclamante</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6 text-xs text-white/70 border-b border-white/5 pb-4 print-field">
                        <div>
                            <span class="block text-white/40 print-label">Nombre del consumidor:</span>
                            <strong class="text-white print-title"><?= htmlspecialchars($submittedData['nombres']) ?></strong>
                        </div>
                        <div>
                            <span class="block text-white/40 print-label">Documento:</span>
                            <span class="text-white"><?= htmlspecialchars($submittedData['doc_tipo']) ?> - <?= htmlspecialchars($submittedData['doc_nro']) ?></span>
                        </div>
                        <div>
                            <span class="block text-white/40 print-label">Domicilio:</span>
                            <span class="text-white"><?= htmlspecialchars($submittedData['direccion']) ?>, <?= htmlspecialchars($submittedData['distrito']) ?> - <?= htmlspecialchars($submittedData['provincia']) ?> (<?= htmlspecialchars($submittedData['departamento']) ?>)</span>
                        </div>
                        <div>
                            <span class="block text-white/40 print-label">Contacto:</span>
                            <span class="text-white">Email: <?= htmlspecialchars($submittedData['email']) ?> | Tel: <?= htmlspecialchars($submittedData['telefono'] ?: '-') ?></span>
                        </div>
                        <?php if ($submittedData['menor_edad']): ?>
                            <div class="sm:col-span-2 bg-white/5 p-3 rounded-lg border border-white/5 print-field">
                                <span class="block font-bold text-white/70 print-label">Representante / Apoderado (Menor de edad):</span>
                                <span class="text-white text-xs"><?= htmlspecialchars($submittedData['apoderado_nombres']) ?> (<?= htmlspecialchars($submittedData['apoderado_doc_tipo']) ?> <?= htmlspecialchars($submittedData['apoderado_doc_nro']) ?>)</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- 2. Good contracted -->
                    <h3 class="text-sm font-bold uppercase tracking-wider text-sky-brand mb-3 print-label">2. Identificación del Bien Contratado</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6 text-xs text-white/70 border-b border-white/5 pb-4 print-field">
                        <div>
                            <span class="block text-white/40 print-label">Tipo de Bien:</span>
                            <span class="text-white text-sm capitalize"><?= htmlspecialchars($submittedData['bien_tipo']) ?></span>
                        </div>
                        <div>
                            <span class="block text-white/40 print-label">Monto Reclamado:</span>
                            <span class="text-white text-sm font-bold">S/. <?= htmlspecialchars($submittedData['monto']) ?></span>
                        </div>
                        <div class="sm:col-span-2">
                            <span class="block text-white/40 print-label">Descripción del bien:</span>
                            <span class="text-white"><?= htmlspecialchars($submittedData['bien_desc'] ?: 'No especificado') ?></span>
                        </div>
                    </div>

                    <!-- 3. Claim / Request -->
                    <h3 class="text-sm font-bold uppercase tracking-wider text-sky-brand mb-3 print-label">3. Detalle de la Reclamación y Pedido del Consumidor</h3>
                    <div class="space-y-4 text-xs text-white/70">
                        <div class="flex items-center gap-3">
                            <span class="text-white/40 print-label">Tipo de Incidencia:</span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wider <?= $submittedData['reclamo_tipo'] === 'reclamo' ? 'bg-red-500/20 text-red-300 border border-red-500/30' : 'bg-amber-500/20 text-amber-300 border border-amber-500/30' ?> print-title">
                                <?= htmlspecialchars($submittedData['reclamo_tipo']) ?>
                            </span>
                        </div>
                        <div>
                            <span class="block text-white/40 print-label">Detalle detallado:</span>
                            <div class="bg-white/5 p-3 rounded-lg border border-white/5 whitespace-pre-wrap mt-1 text-white print-field"><?= htmlspecialchars($submittedData['detalle']) ?></div>
                        </div>
                        <div>
                            <span class="block text-white/40 print-label">Pedido concreto:</span>
                            <div class="bg-white/5 p-3 rounded-lg border border-white/5 whitespace-pre-wrap mt-1 text-white print-field"><?= htmlspecialchars($submittedData['pedido']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Action buttons (no-print) -->
                <div class="no-print mt-8 flex flex-col sm:flex-row justify-center gap-4">
                    <button onclick="window.print()" class="btn-primary text-white font-bold px-6 py-3.5 rounded-xl text-sm flex items-center justify-center gap-2 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                        </svg>
                        Descargar / Imprimir Reclamo
                    </button>
                    <a href="index.html" class="btn-sec text-center font-semibold px-6 py-3.5 rounded-xl text-sm flex items-center justify-center gap-2">
                        Volver a la Página de Inicio
                    </a>
                </div>
            </div>

        <!-- FORM SCREEN -->
        <?php else: ?>
            <div class="no-print text-center mb-10">
                <div class="inline-flex items-center gap-2 bg-sky-brand/10 border border-sky-brand/30 rounded-full px-4 py-1.5 mb-4">
                    <span class="w-2 h-2 rounded-full bg-sky-brand"></span>
                    <span class="text-sky-brand text-[10px] font-semibold tracking-widest uppercase">Ley N° 29571</span>
                </div>
                <h1 class="text-4xl font-black mb-2">Libro de Reclamaciones <span class="grad-text">Virtual</span></h1>
                <p class="text-white/60 text-sm max-w-lg mx-auto">
                    Para registrar tu queja o reclamo, completa todos los campos del formulario. Nos comunicaremos contigo en un plazo máximo de 15 días hábiles.
                </p>
            </div>

            <!-- Error message if any -->
            <?php if (!empty($errorMsg)): ?>
                <div class="no-print bg-red-500/10 border border-red-500/30 text-red-200 px-4 py-3 rounded-xl mb-6 text-sm flex items-center gap-2">
                    <span class="text-lg">⚠️</span>
                    <?= $errorMsg ?>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left: Form -->
                <div class="lg:col-span-2">
                    <div class="glass rounded-3xl p-6 sm:p-8 border border-white/10 shadow-xl">
                        <form method="POST" action="libro-de-reclamaciones.php" class="space-y-6">

                            <!-- Section 1 -->
                            <div class="border-b border-white/10 pb-5">
                                <h2 class="text-lg font-black text-white flex items-center gap-2 mb-4">
                                    <span class="w-6 h-6 rounded-lg bg-sky-brand/15 border border-sky-brand/30 flex items-center justify-center text-xs text-sky-brand">1</span>
                                    Identificación del Consumidor
                                </h2>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div class="sm:col-span-2">
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Nombres y Apellidos completos *</label>
                                        <input type="text" name="nombres" required placeholder="Ingresa tus nombres completos" class="form-input" value="<?= isset($_POST['nombres']) ? htmlspecialchars($_POST['nombres']) : '' ?>">
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Tipo Documento *</label>
                                        <select name="doc_tipo" required class="form-input">
                                            <option value="DNI" <?= isset($_POST['doc_tipo']) && $_POST['doc_tipo'] == 'DNI' ? 'selected' : '' ?>>DNI (Perú)</option>
                                            <option value="CE" <?= isset($_POST['doc_tipo']) && $_POST['doc_tipo'] == 'CE' ? 'selected' : '' ?>>Carnet de Extranjería</option>
                                            <option value="PASAPORTE" <?= isset($_POST['doc_tipo']) && $_POST['doc_tipo'] == 'PASAPORTE' ? 'selected' : '' ?>>Pasaporte</option>
                                            <option value="RUC" <?= isset($_POST['doc_tipo']) && $_POST['doc_tipo'] == 'RUC' ? 'selected' : '' ?>>RUC</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Nro Documento *</label>
                                        <input type="text" name="doc_nro" required placeholder="Número de documento" class="form-input" value="<?= isset($_POST['doc_nro']) ? htmlspecialchars($_POST['doc_nro']) : '' ?>">
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Correo Electrónico *</label>
                                        <input type="email" name="email" required placeholder="nombre@correo.com" class="form-input" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Teléfono / Celular</label>
                                        <input type="tel" name="telefono" placeholder="Número de contacto" class="form-input" value="<?= isset($_POST['telefono']) ? htmlspecialchars($_POST['telefono']) : '' ?>">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Dirección Completa *</label>
                                        <input type="text" name="direccion" required placeholder="Av., Calle, Nro., Dpto., Urb." class="form-input" value="<?= isset($_POST['direccion']) ? htmlspecialchars($_POST['direccion']) : '' ?>">
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Departamento *</label>
                                        <input type="text" name="departamento" required placeholder="Ej: Lambayeque" class="form-input" value="<?= isset($_POST['departamento']) ? htmlspecialchars($_POST['departamento']) : 'Lambayeque' ?>">
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Provincia *</label>
                                        <input type="text" name="provincia" required placeholder="Ej: Chiclayo" class="form-input" value="<?= isset($_POST['provincia']) ? htmlspecialchars($_POST['provincia']) : 'Chiclayo' ?>">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Distrito *</label>
                                        <input type="text" name="distrito" required placeholder="Ej: La Victoria" class="form-input" value="<?= isset($_POST['distrito']) ? htmlspecialchars($_POST['distrito']) : '' ?>">
                                    </div>
                                </div>

                                <!-- Menor de edad checkbox -->
                                <div class="mt-4 bg-white/5 p-4 rounded-xl border border-white/5">
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox" id="menor_edad" name="menor_edad" class="w-4 h-4 rounded accent-sky-brand flex-shrink-0 cursor-pointer" onclick="toggleApoderado()" <?= isset($_POST['menor_edad']) ? 'checked' : '' ?>>
                                        <span class="text-white/70 text-xs">Soy menor de edad (se requiere ingresar los datos de un tutor/apoderado).</span>
                                    </label>

                                    <!-- Apoderado fields -->
                                    <div id="apoderado_fields" class="mt-4 pt-4 border-t border-white/5 grid grid-cols-1 sm:grid-cols-2 gap-4 hidden">
                                        <div class="sm:col-span-2">
                                            <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Nombres del Apoderado *</label>
                                            <input type="text" id="apoderado_nombres" name="apoderado_nombres" placeholder="Nombres del padre, madre o apoderado" class="form-input" value="<?= isset($_POST['apoderado_nombres']) ? htmlspecialchars($_POST['apoderado_nombres']) : '' ?>">
                                        </div>
                                        <div>
                                            <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Tipo Doc. Apoderado *</label>
                                            <select id="apoderado_doc_tipo" name="apoderado_doc_tipo" class="form-input">
                                                <option value="DNI" <?= isset($_POST['apoderado_doc_tipo']) && $_POST['apoderado_doc_tipo'] == 'DNI' ? 'selected' : '' ?>>DNI (Perú)</option>
                                                <option value="CE" <?= isset($_POST['apoderado_doc_tipo']) && $_POST['apoderado_doc_tipo'] == 'CE' ? 'selected' : '' ?>>Carnet de Extranjería</option>
                                                <option value="PASAPORTE" <?= isset($_POST['apoderado_doc_tipo']) && $_POST['apoderado_doc_tipo'] == 'PASAPORTE' ? 'selected' : '' ?>>Pasaporte</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Nro Doc. Apoderado *</label>
                                            <input type="text" id="apoderado_doc_nro" name="apoderado_doc_nro" placeholder="Nro de documento" class="form-input" value="<?= isset($_POST['apoderado_doc_nro']) ? htmlspecialchars($_POST['apoderado_doc_nro']) : '' ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 2 -->
                            <div class="border-b border-white/10 pb-5">
                                <h2 class="text-lg font-black text-white flex items-center gap-2 mb-4">
                                    <span class="w-6 h-6 rounded-lg bg-sky-brand/15 border border-sky-brand/30 flex items-center justify-center text-xs text-sky-brand">2</span>
                                    Identificación del Bien Contratado
                                </h2>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Tipo de bien *</label>
                                        <div class="flex items-center gap-6 mt-2">
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio" name="bien_tipo" value="producto" required class="w-4 h-4 accent-sky-brand" <?= !isset($_POST['bien_tipo']) || $_POST['bien_tipo'] == 'producto' ? 'checked' : '' ?>>
                                                <span class="text-white/80 text-sm">Producto (Agua)</span>
                                            </label>
                                            <label class="flex items-center gap-2 cursor-pointer">
                                                <input type="radio" name="bien_tipo" value="servicio" class="w-4 h-4 accent-sky-brand" <?= isset($_POST['bien_tipo']) && $_POST['bien_tipo'] == 'servicio' ? 'checked' : '' ?>>
                                                <span class="text-white/80 text-sm">Servicio (Delivery/Atención)</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Monto Reclamado (S/. opcional)</label>
                                        <input type="number" step="0.01" min="0" name="monto" placeholder="S/. 0.00" class="form-input" value="<?= isset($_POST['monto']) ? htmlspecialchars($_POST['monto']) : '' ?>">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Descripción del bien contratado</label>
                                        <textarea rows="2" name="bien_desc" placeholder="Detalla el producto adquirido, lote, o servicio contratado (ej: Bidón de 20L, pack de 6 botellas)" class="form-input resize-none"><?= isset($_POST['bien_desc']) ? htmlspecialchars($_POST['bien_desc']) : '' ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Section 3 -->
                            <div>
                                <h2 class="text-lg font-black text-white flex items-center gap-2 mb-4">
                                    <span class="w-6 h-6 rounded-lg bg-sky-brand/15 border border-sky-brand/30 flex items-center justify-center text-xs text-sky-brand">3</span>
                                    Detalle del Reclamo y Pedido
                                </h2>
                                
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Tipo de Reclamación *</label>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                                            <label class="flex items-start gap-2.5 p-3 rounded-xl bg-white/5 border border-white/5 cursor-pointer hover:bg-white/10 transition-colors">
                                                <input type="radio" name="reclamo_tipo" value="reclamo" required class="w-4 h-4 mt-0.5 accent-sky-brand" <?= !isset($_POST['reclamo_tipo']) || $_POST['reclamo_tipo'] == 'reclamo' ? 'checked' : '' ?>>
                                                <div>
                                                    <span class="block text-white text-xs font-bold uppercase tracking-wider">Reclamo</span>
                                                    <span class="text-[10px] text-white/50 leading-tight block mt-0.5">Disconformidad relacionada a los productos o servicios contratados.</span>
                                                </div>
                                            </label>
                                            <label class="flex items-start gap-2.5 p-3 rounded-xl bg-white/5 border border-white/5 cursor-pointer hover:bg-white/10 transition-colors">
                                                <input type="radio" name="reclamo_tipo" value="queja" class="w-4 h-4 mt-0.5 accent-sky-brand" <?= isset($_POST['reclamo_tipo']) && $_POST['reclamo_tipo'] == 'queja' ? 'selected' : '' ?>>
                                                <div>
                                                    <span class="block text-white text-xs font-bold uppercase tracking-wider">Queja</span>
                                                    <span class="text-[10px] text-white/50 leading-tight block mt-0.5">Disconformidad no relacionada a los productos. Malestar respecto a la atención.</span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Detalle de tu Queja o Reclamo *</label>
                                        <textarea rows="4" name="detalle" required placeholder="Describe de forma detallada y ordenada lo ocurrido..." class="form-input resize-none"><?= isset($_POST['detalle']) ? htmlspecialchars($_POST['detalle']) : '' ?></textarea>
                                    </div>
                                    <div>
                                        <label class="block text-white/50 text-[10px] uppercase tracking-widest mb-1.5 font-bold">Pedido concreto (¿Qué solicitas?) *</label>
                                        <textarea rows="3" name="pedido" required placeholder="Indica detalladamente tu solicitud (cambio de producto, devolución de dinero, disculpas, etc.)" class="form-input resize-none"><?= isset($_POST['pedido']) ? htmlspecialchars($_POST['pedido']) : '' ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Declaraciones -->
                            <div class="pt-4 border-t border-white/10 space-y-3">
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" required class="w-4 h-4 mt-0.5 rounded accent-sky-brand flex-shrink-0 cursor-pointer">
                                    <span class="text-white/40 text-[10px] leading-relaxed">
                                        Declaro ser el usuario titular y que los datos consignados en esta Hoja de Reclamación son reales y verdaderos.
                                    </span>
                                </label>
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" required class="w-4 h-4 mt-0.5 rounded accent-sky-brand flex-shrink-0 cursor-pointer">
                                    <span class="text-white/40 text-[10px] leading-relaxed">
                                        Acepto el tratamiento de mis datos personales para los fines de responder este reclamo, de acuerdo con la <strong class="text-white/60">Ley N° 29733</strong> (Protección de Datos Personales en el Perú).
                                    </span>
                                </label>
                            </div>

                            <button type="submit" class="btn-primary w-full py-4 rounded-2xl text-white font-black text-sm tracking-wider cursor-pointer">
                                PRESENTAR RECLAMACIÓN
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Right: Sidebar (Notice and info) -->
                <div class="space-y-6">
                    <!-- Provider card -->
                    <div class="glass rounded-3xl p-6 border border-white/10 text-xs space-y-3 shadow-md">
                        <h3 class="text-sm font-black text-white uppercase tracking-wider mb-2">Datos del Proveedor</h3>
                        <div>
                            <span class="block text-white/40 uppercase tracking-widest text-[9px] mb-0.5">Razón Social</span>
                            <strong class="text-white text-sm"><?= EMPRESA_RAZON_SOCIAL ?></strong>
                        </div>
                        <div>
                            <span class="block text-white/40 uppercase tracking-widest text-[9px] mb-0.5">RUC</span>
                            <strong class="text-white text-sm"><?= EMPRESA_RUC ?></strong>
                        </div>
                        <div>
                            <span class="block text-white/40 uppercase tracking-widest text-[9px] mb-0.5">Dirección de Planta</span>
                            <span class="text-white/80"><?= EMPRESA_DIRECCION ?></span>
                        </div>
                    </div>

                    <!-- Indecopi Virtual Notice (Compliance) -->
                    <div class="glass rounded-3xl p-6 border border-white/10 shadow-md">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="text-2xl">📋</span>
                            <h3 class="text-sm font-black text-white uppercase tracking-wider">Aviso Virtual</h3>
                        </div>
                        <p class="text-white/60 text-xs leading-relaxed mb-4">
                            Conforme al Código de Protección y Defensa del Consumidor, contamos con un Libro de Reclamaciones Virtual. Puedes revisar el poster oficial entregado por INDECOPI a continuación:
                        </p>
                        
                        <!-- Mini Preview of the Notice -->
                        <div class="relative rounded-2xl overflow-hidden border border-white/15 bg-black/30 group">
                            <img src="Libro-reclamaciones/AvisoVirtual_page1.png" alt="Aviso Virtual de Libro de Reclamaciones INDECOPI" class="w-full h-auto object-cover opacity-80 group-hover:opacity-100 transition-opacity">
                            <div class="absolute inset-0 bg-navy-deep/40 flex items-center justify-center opacity-100 group-hover:bg-navy-deep/20 transition-all">
                                <button onclick="openNoticeModal()" class="bg-sky-brand text-navy-deep font-bold px-4 py-2 rounded-xl text-xs shadow-lg hover:scale-105 transition-transform cursor-pointer">
                                    Ver a Pantalla Completa
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- FOOTER (no-print) -->
    <footer class="no-print border-t border-white/10 bg-navy-deep py-6 mt-12 text-center text-xs text-white/40">
        <div class="max-w-6xl mx-auto px-4">
            © <?= date('Y') ?> <?= EMPRESA_RAZON_SOCIAL ?> · RUC <?= EMPRESA_RUC ?> · Todos los derechos reservados.<br>
            <span class="text-[10px] text-white/20 mt-1 block">Regulado por INDECOPI y en conformidad con la Ley de Protección de Datos Personales N° 29733.</span>
        </div>
    </footer>

    <!-- AVISO VIRTUAL MODAL (no-print) -->
    <div id="notice-modal" class="no-print fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-black/90 backdrop-blur-sm">
        <div class="absolute inset-0 cursor-pointer" onclick="closeNoticeModal()"></div>
        <div class="relative z-10 max-w-lg w-full bg-white rounded-3xl p-4 shadow-2xl flex flex-col items-center">
            <button onclick="closeNoticeModal()" class="absolute -top-10 right-0 sm:-right-8 text-white hover:text-sky-brand text-3xl font-light cursor-pointer">✕</button>
            <div class="w-full overflow-y-auto max-h-[80vh] border border-gray-200 rounded-2xl">
                <img src="Libro-reclamaciones/AvisoVirtual_page1.png" alt="Aviso Virtual Oficial INDECOPI" class="w-full h-auto">
            </div>
            <p class="text-gray-500 text-[10px] mt-3 text-center">
                Aviso oficial de disponibilidad de Libro de Reclamaciones - INDECOPI.
            </p>
        </div>
    </div>

    <!-- SCRIPTS (no-print) -->
    <script class="no-print">
        // Mostrar / ocultar campos de apoderado si es menor de edad
        function toggleApoderado() {
            const checkbox = document.getElementById('menor_edad');
            const fields = document.getElementById('apoderado_fields');
            const nombresInput = document.getElementById('apoderado_nombres');
            const docNroInput = document.getElementById('apoderado_doc_nro');

            if (checkbox.checked) {
                fields.classList.remove('hidden');
                nombresInput.required = true;
                docNroInput.required = true;
            } else {
                fields.classList.add('hidden');
                nombresInput.required = false;
                docNroInput.required = false;
                nombresInput.value = '';
                docNroInput.value = '';
            }
        }

        // Ejecutar al cargar por si el checkbox quedó marcado tras recargar la página
        window.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('menor_edad')) {
                toggleApoderado();
            }
        });

        // Modales del aviso virtual
        function openNoticeModal() {
            const modal = document.getElementById('notice-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeNoticeModal() {
            const modal = document.getElementById('notice-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Tecla ESC para cerrar modal
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                closeNoticeModal();
            }
        });
    </script>
</body>
</html>
