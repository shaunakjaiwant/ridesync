<?php
function ridesync_is_valid_email($email) {
    $email = trim((string) $email);

    if ($email === '' || strlen($email) > 190) {
        return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return false;
    }

    $domain = $parts[1];
    if (strpos($domain, '.') === false) {
        return false;
    }

    return true;
}

function ridesync_send_email($toEmail, $subject, $bodyText, $fromName = 'RideSync OTP') {
    $toEmail = trim((string) $toEmail);
    if (!ridesync_is_valid_email($toEmail)) {
        return false;
    }

    $smtpHost = (string) ridesync_env('RIDESYNC_SMTP_HOST', '');
    $smtpPort = (int) ridesync_env('RIDESYNC_SMTP_PORT', 587);
    $smtpUser = (string) ridesync_env('RIDESYNC_SMTP_USER', '');
    $smtpPass = (string) ridesync_env('RIDESYNC_SMTP_PASS', '');

    if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
        // Attempt SMTP socket delivery
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        $transport = ($smtpPort === 465) ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($transport . $smtpHost . ':' . $smtpPort, $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);
        if ($socket) {
            $read = function($sock) {
                $response = '';
                while ($line = fgets($sock, 515)) {
                    $response .= $line;
                    if (substr($line, 3, 1) === ' ') break;
                }
                return $response;
            };

            $read($socket);
            fwrite($socket, "EHLO " . gethostname() . "\r\n");
            $read($socket);

            if ($smtpPort === 587) {
                fwrite($socket, "STARTTLS\r\n");
                $read($socket);
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                fwrite($socket, "EHLO " . gethostname() . "\r\n");
                $read($socket);
            }

            fwrite($socket, "AUTH LOGIN\r\n");
            $read($socket);
            fwrite($socket, base64_encode($smtpUser) . "\r\n");
            $read($socket);
            fwrite($socket, base64_encode($smtpPass) . "\r\n");
            $authRes = $read($socket);

            if (str_starts_with($authRes, '235')) {
                $fromEmail = $smtpUser;
                fwrite($socket, "MAIL FROM: <{$fromEmail}>\r\n");
                $read($socket);
                fwrite($socket, "RCPT TO: <{$toEmail}>\r\n");
                $read($socket);
                fwrite($socket, "DATA\r\n");
                $read($socket);

                $headers = "From: {$fromName} <{$fromEmail}>\r\n";
                $headers .= "To: {$toEmail}\r\n";
                $headers .= "Subject: {$subject}\r\n";
                $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $headers .= "MIME-Version: 1.0\r\n\r\n";

                fwrite($socket, $headers . $bodyText . "\r\n.\r\n");
                $res = $read($socket);
                fwrite($socket, "QUIT\r\n");
                fclose($socket);

                if (str_starts_with($res, '250')) {
                    return true;
                }
            } else {
                fclose($socket);
            }
        }
    }

    // Native PHP mail() fallback
    $headers = "From: " . mb_encode_mimeheader($fromName, "UTF-8") . " <noreply@ridesync.test>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    if (@mail($toEmail, $subject, $bodyText, $headers)) {
        return true;
    }

    // Dev logging fallback for local testing
    $logDir = ridesync_storage_path('logs');
    ridesync_ensure_directory($logDir);
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'email.log';
    $entry = sprintf("[%s] TO: %s | FROM: %s | SUBJECT: %s\nBODY:\n%s\n----------------------------------------\n",
        date('Y-m-d H:i:s'),
        $toEmail,
        $fromName,
        $subject,
        $bodyText
    );
    @file_put_contents($logFile, $entry, FILE_APPEND);

    return true;
}
