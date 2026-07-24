<?php
/**
 * Обработчик отправки технического задания на расчет стоимости литья.
 * Совместимость с PHP 7.2.34
 */

// Установка часового пояса и формата ответа JSON
date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json; charset=utf-8');

/**
 * Вспомогательная функция записи событий в системный лог (logs/mail.log)
 */
function write_log($message, $level = 'INFO', $request_id = '-') {
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/mail.log';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN_IP';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = sprintf("[%s] [%s] [%s] [IP: %s] %s\n", $timestamp, strtoupper($level), $request_id, $ip, $message);
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

// Генерация уникального ID заявки по маске YYYYMMDD-hhmmss
$request_id = date('Ymd-His');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    write_log("Запрос отклонен: неверный метод " . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'), 'WARNING', $request_id);
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод запроса не поддерживается. Используйте POST.']);
    exit;
}

write_log("Новый POST-запрос на отправку формы. User-Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Неизвестно'), 'INFO', $request_id);

// Защита от спам-ботов Honeypot (поле-ловушка)
$bot_trap = trim($_POST['client_midname'] ?? '');
if (!empty($bot_trap)) {
    write_log("Сработала спам-ловушка Honeypot (bot_trap: '$bot_trap'). Запрос скрыто заблокирован.", 'WARNING', $request_id);
    // Имитируем успешную отправку для робота
    echo json_encode(['success' => true, 'request_id' => $request_id]);
    exit;
}

// Конфигурация получателя и отправителя
$to = 'MATRIXPLAST@yandex.ru'; // Рабочий email получателя заявок
$from = 'admin@matrixplast.ru';

// Загрузка локальной конфигурации секретов
$config = [];
if (file_exists(__DIR__ . '/config.php')) {
    $config = include __DIR__ . '/config.php';
}
$smtp_password = $config['smtp_password'] ?? '';

if (empty($smtp_password)) {
    write_log("Ошибка конфигурации: SMTP пароль не задан в config.php", 'ERROR', $request_id);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка конфигурации сервера: SMTP пароль не задан.']);
    exit;
}

// Сбор и очистка текстовых данных из формы
$client_name = htmlspecialchars(trim($_POST['client-name'] ?? ''));
$client_phone = htmlspecialchars(trim($_POST['client-phone'] ?? ''));
$client_email = htmlspecialchars(trim($_POST['client-email'] ?? ''));
$client_message = htmlspecialchars(trim($_POST['client-message'] ?? ''));

// Данные калькулятора (для удобства инженеров)
$calc_material = htmlspecialchars(trim($_POST['calc-material'] ?? 'Не выбран'));
$calc_weight = htmlspecialchars(trim($_POST['calc-weight'] ?? '25 гр'));
$calc_qty = htmlspecialchars(trim($_POST['calc-qty'] ?? '0'));
$price_per_pcs = htmlspecialchars(trim($_POST['price-per-pcs'] ?? '0'));
$price_total = htmlspecialchars(trim($_POST['price-total'] ?? '0'));
$has_mold = htmlspecialchars(trim($_POST['has-mold'] ?? 'Не указано'));

write_log("Данные заявки -> Имя: '$client_name', Тел: '$client_phone', Email: '$client_email', Материал: '$calc_material', Масса: '$calc_weight', Тираж: '$calc_qty', Итого: '$price_total'", 'INFO', $request_id);

// Валидация обязательных полей
if (empty($client_name) || empty($client_phone) || empty($client_email)) {
    write_log("Ошибка валидации: не заполнены обязательные поля (Имя: '$client_name', Тел: '$client_phone', Email: '$client_email')", 'WARNING', $request_id);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Заполните обязательные поля: имя, телефон и email.']);
    exit;
}

// Валидация формата email
if (!filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
    write_log("Ошибка валидации: некорректный формат email '$client_email'", 'WARNING', $request_id);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Указан некорректный адрес электронной почты.']);
    exit;
}

// Обработка прикрепленных файлов (мультизагрузка)
$attachments = [];
$allowed_extensions = ['pdf', 'step', 'stp', 'dwg', 'png', 'jpg', 'jpeg', 'zip', 'rar'];
$max_file_size = 15 * 1024 * 1024; // 15 МБ на один файл
$max_total_size = 25 * 1024 * 1024; // 25 МБ суммарно
$total_files_size = 0;

$file_entries = [];
if (isset($_FILES['client-files']) && is_array($_FILES['client-files']['name'])) {
    $file_count = count($_FILES['client-files']['name']);
    for ($i = 0; $i < $file_count; $i++) {
        if ($_FILES['client-files']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
            $file_entries[] = [
                'name' => $_FILES['client-files']['name'][$i],
                'type' => $_FILES['client-files']['type'][$i],
                'tmp_name' => $_FILES['client-files']['tmp_name'][$i],
                'error' => $_FILES['client-files']['error'][$i],
                'size' => $_FILES['client-files']['size'][$i]
            ];
        }
    }
} elseif (isset($_FILES['client-file']) && $_FILES['client-file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file_entries[] = $_FILES['client-file'];
}

foreach ($file_entries as $file) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $err_msg = 'Ошибка загрузки файла ' . htmlspecialchars($file['name']) . '. Код: ' . $file['error'];
        write_log("Ошибка файла: $err_msg", 'WARNING', $request_id);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $err_msg]);
        exit;
    }

    if ($file['size'] > $max_file_size) {
        $err_msg = 'Файл ' . htmlspecialchars($file['name']) . ' превышает лимит 15 МБ.';
        write_log("Ошибка файла: $err_msg", 'WARNING', $request_id);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $err_msg]);
        exit;
    }

    $total_files_size += $file['size'];
    if ($total_files_size > $max_total_size) {
        $err_msg = 'Общий размер файлов превышает 25 МБ.';
        write_log("Ошибка файла: $err_msg", 'WARNING', $request_id);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $err_msg]);
        exit;
    }

    $file_info = pathinfo($file['name']);
    $extension = strtolower($file_info['extension'] ?? '');

    if (!in_array($extension, $allowed_extensions)) {
        $err_msg = 'Недопустимый тип файла ' . htmlspecialchars($file['name']) . '. Разрешены: PDF, STEP, STP, DWG, изображения и архивы (ZIP, RAR).';
        write_log("Ошибка файла: $err_msg", 'WARNING', $request_id);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $err_msg]);
        exit;
    }

    $file_content = file_get_contents($file['tmp_name']);
    if ($file_content !== false) {
        $attachments[] = [
            'name' => $file['name'],
            'type' => !empty($file['type']) ? $file['type'] : 'application/octet-stream',
            'data' => $file_content
        ];
    }
}

if (!empty($attachments)) {
    write_log("Успешно прикреплено файлов: " . count($attachments) . " (общий размер: " . round($total_files_size / 1024 / 1024, 2) . " МБ)", 'INFO', $request_id);
}

// Формирование темы письма
$subject = "Новая заявка $request_id на расчет литья от $client_name";
$subject_encoded = "=?utf-8?B?" . base64_encode($subject) . "?=";

// Создание разделителя для multipart/mixed
$boundary = md5(uniqid(time()));

// Заголовки письма (ассоциативный массив для SMTP)
$headers_arr = [
    'MIME-Version' => '1.0',
    'From' => "МАТРИКС-ПЛАСТ <$from>",
    'To' => $to,
    'Subject' => $subject_encoded,
    'Reply-To' => $client_email,
    'X-Mailer' => 'PHP/' . phpversion(),
    'Content-Type' => "multipart/mixed; boundary=\"$boundary\""
];

// Текст сообщения
$message_body = "Техническое задание на расчет стоимости литья пластмасс\r\n";
$message_body .= "========================================================\r\n\r\n";
$message_body .= "ID Заявки: $request_id\r\n";
$message_body .= "Имя / Компания: $client_name\r\n";
$message_body .= "Телефон: $client_phone\r\n";
$message_body .= "Email для связи: $client_email\r\n\r\n";

$message_body .= "Данные предварительного расчета в калькуляторе:\r\n";
$message_body .= "--------------------------------------------------------\r\n";
$message_body .= "Материал детали: $calc_material\r\n";
$message_body .= "Масса одного изделия: $calc_weight\r\n";
$message_body .= "Планируемый тираж: $calc_qty шт.\r\n";
$message_body .= "Наличие пресс-формы: " . ($has_mold === 'yes' ? 'Да, есть готовая пресс-форма для мини-ТПА' : 'Нет, требуется изготовление формы') . "\r\n";
$message_body .= "Ориентировочная цена детали: $price_per_pcs\r\n";
$message_body .= "Ориентировочная цена тиража: $price_total\r\n\r\n";

$message_body .= "Технические требования и комментарий клиента:\r\n";
$message_body .= "--------------------------------------------------------\r\n";
$message_body .= (!empty($client_message) ? $client_message : "Комментарий отсутствует.") . "\r\n\r\n";

if (!empty($attachments)) {
    $message_body .= "Прикрепленные файлы (" . count($attachments) . " шт.):\r\n";
    foreach ($attachments as $att) {
        $message_body .= "- " . $att['name'] . "\r\n";
    }
    $message_body .= "\r\n";
}

$message_body .= "========================================================\r\n";
$message_body .= "Письмо отправлено автоматически с сайта matrixplast.ru\r\n";

// Сборка тела письма с boundary
$body = "--$boundary\r\n";
$body .= "Content-Type: text/plain; charset=utf-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $message_body . "\r\n";

// Прикрепление каждого файла
foreach ($attachments as $att) {
    $file_encoded = chunk_split(base64_encode($att['data']));
    $file_name_encoded = "=?utf-8?B?" . base64_encode($att['name']) . "?=";
    
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: {$att['type']}; name=\"$file_name_encoded\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"$file_name_encoded\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= $file_encoded . "\r\n";
}

$body .= "--$boundary--";

// Отправка письма через SMTP с авторизацией
try {
    send_mail_smtp($to, $subject_encoded, $body, $headers_arr, $from, $smtp_password, 'ssl://p1036777.mail.ihc.ru', 465, $request_id);
    write_log("Заявка $request_id успешно отправлена на почту $to", 'SUCCESS', $request_id);
    echo json_encode(['success' => true, 'request_id' => $request_id]);
} catch (Exception $e) {
    write_log("Сбой отправки заявки $request_id через SMTP: " . $e->getMessage(), 'ERROR', $request_id);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка отправки почты через SMTP: ' . $e->getMessage()]);
}

/**
 * Функция отправки почты через SMTP-сервер с SSL шифрованием и авторизацией.
 */
function send_mail_smtp($to, $subject, $body, $headers_arr, $from, $password, $smtp_host = 'ssl://p1036777.mail.ihc.ru', $smtp_port = 465, $request_id = '-') {
    $timeout = 15;
    
    write_log("Попытка открытия сокета SMTP к $smtp_host:$smtp_port ...", 'INFO', $request_id);
    $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, $timeout);
    if (!$socket) {
        $error_msg = "Не удалось подключиться к SMTP-серверу $smtp_host:$smtp_port. Ошибка: $errstr ($errno)";
        write_log($error_msg, 'ERROR', $request_id);
        throw new Exception($error_msg);
    }
    
    // Вспомогательная функция чтения ответов
    $readResponse = function($socket, $expected_code) use ($request_id) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $code = substr($response, 0, 3);
        $clean_response = trim($response);
        
        if ($code !== (string)$expected_code) {
            write_log("SMTP Ответ ОШИБКА (ожидался $expected_code, получен $code): $clean_response", 'ERROR', $request_id);
            throw new Exception("Код SMTP: $code, Ожидался: $expected_code. Ответ: " . $clean_response);
        } else {
            write_log("SMTP Ответ OK ($code): $clean_response", 'INFO', $request_id);
        }
        return $response;
    };
    
    try {
        $readResponse($socket, 220);
        
        $domain = mail_domain($from);
        write_log("SMTP Команда: EHLO $domain", 'INFO', $request_id);
        fwrite($socket, "EHLO " . $domain . "\r\n");
        $readResponse($socket, 250);
        
        write_log("SMTP Команда: AUTH LOGIN", 'INFO', $request_id);
        fwrite($socket, "AUTH LOGIN\r\n");
        $readResponse($socket, 334);
        
        write_log("SMTP Передача логина ($from)", 'INFO', $request_id);
        fwrite($socket, base64_encode($from) . "\r\n");
        $readResponse($socket, 334);
        
        write_log("SMTP Передача пароля [СКРЫТО]", 'INFO', $request_id);
        fwrite($socket, base64_encode($password) . "\r\n");
        $readResponse($socket, 235);
        
        write_log("SMTP Команда: MAIL FROM: <$from>", 'INFO', $request_id);
        fwrite($socket, "MAIL FROM: <$from>\r\n");
        $readResponse($socket, 250);
        
        write_log("SMTP Команда: RCPT TO: <$to>", 'INFO', $request_id);
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $readResponse($socket, 250);
        
        write_log("SMTP Команда: DATA", 'INFO', $request_id);
        fwrite($socket, "DATA\r\n");
        $readResponse($socket, 354);
        
        // Формируем строковые заголовки
        $raw_headers = "";
        foreach ($headers_arr as $k => $v) {
            $raw_headers .= "$k: $v\r\n";
        }
        
        write_log("SMTP Отправка заголовков и тела письма...", 'INFO', $request_id);
        fwrite($socket, $raw_headers . "\r\n" . $body . "\r\n.\r\n");
        $readResponse($socket, 250);
        
        write_log("SMTP Команда: QUIT", 'INFO', $request_id);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    } catch (Exception $e) {
        write_log("Сбой в процессе SMTP-диалога: " . $e->getMessage(), 'ERROR', $request_id);
        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);
        throw $e;
    }
}

/**
 * Получение домена из email для команды EHLO
 */
function mail_domain($email) {
    $parts = explode('@', $email);
    return end($parts);
}
