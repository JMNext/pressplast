<?php
/**
 * Обработчик отправки технического задания на расчет стоимости литья.
 * Совместимость с PHP 7.2.34
 */

// Установка часового пояса и формата ответа JSON
date_default_timezone_set('Europe/Moscow');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод запроса не поддерживается. Используйте POST.']);
    exit;
}

// Защита от спам-ботов Honeypot (поле-ловушка)
$bot_trap = trim($_POST['client_midname'] ?? '');
if (!empty($bot_trap)) {
    // Имитируем успешную отправку для робота
    echo json_encode(['success' => true, 'request_id' => date('Ymd-His')]);
    exit;
}

// Конфигурация получателя и отправителя
//$to = 'jmaier@mail.ru'; // Отладочный email получателя заявок
$to = 'MATRIXPLAST@yandex.ru'; // Рабочий email получателя заявок
$from = 'admin@matrixplast.ru';

// Загрузка локальной конфигурации секретов
$config = [];
if (file_exists(__DIR__ . '/config.php')) {
    $config = include __DIR__ . '/config.php';
}
$smtp_password = $config['smtp_password'] ?? '';

if (empty($smtp_password)) {
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
$calc_qty = htmlspecialchars(trim($_POST['calc-qty'] ?? '0'));
$price_per_pcs = htmlspecialchars(trim($_POST['price-per-pcs'] ?? '0'));
$price_total = htmlspecialchars(trim($_POST['price-total'] ?? '0'));
$has_mold = htmlspecialchars(trim($_POST['has-mold'] ?? 'Не указано'));

// Валидация обязательных полей
if (empty($client_name) || empty($client_phone) || empty($client_email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Заполните обязательные поля: имя, телефон и email.']);
    exit;
}

// Валидация формата email
if (!filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Указан некорректный адрес электронной почты.']);
    exit;
}

// Генерация уникального ID заявки по маске YYYYMMDD-hhmmss
$request_id = date('Ymd-His');

// Обработка прикрепленного файла
$file_attached = false;
$file_data = null;
$file_name = '';
$file_type = '';

if (isset($_FILES['client-file']) && $_FILES['client-file']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['client-file'];
    
    // Проверка ошибок загрузки
    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ошибка при загрузке файла. Код ошибки: ' . $file['error']]);
        exit;
    }
    
    // Ограничение размера файла (15 МБ = 15728640 байт)
    $max_file_size = 15 * 1024 * 1024;
    if ($file['size'] > $max_file_size) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Файл слишком большой. Максимальный размер: 15 МБ.']);
        exit;
    }
    
    // Проверка разрешенных расширений
    $allowed_extensions = ['pdf', 'step', 'stp', 'dwg', 'png', 'jpg', 'jpeg', 'zip', 'rar'];
    $file_info = pathinfo($file['name']);
    $extension = strtolower($file_info['extension'] ?? '');
    
    if (!in_array($extension, $allowed_extensions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Недопустимый тип файла. Разрешены: PDF, STEP, STP, DWG, изображения и архивы (ZIP, RAR).']);
        exit;
    }
    
    $file_attached = true;
    $file_name = $file['name'];
    $file_type = $file['type'];
    $file_data = file_get_contents($file['tmp_name']);
}

// Формирование темы письма
$subject = "Новая заявка $request_id на расчет литья от $client_name";
// Кодирование темы в Base64 для корректного отображения кириллицы в почтовых клиентах
$subject = "=?utf-8?B?" . base64_encode($subject) . "?=";

// Создание разделителя для multipart/mixed
$boundary = md5(uniqid(time()));

// Заголовки письма
// Заголовки письма (ассоциативный массив для SMTP)
$headers_arr = [
    'MIME-Version' => '1.0',
    'From' => "МАТРИКС-ПЛАСТ <$from>",
    'To' => $to,
    'Subject' => $subject,
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
$message_body .= "Планируемый тираж: $calc_qty шт.\r\n";
$message_body .= "Наличие пресс-формы: " . ($has_mold === 'yes' ? 'Да, есть готовая пресс-форма' : 'Нет, требуется изготовление формы') . "\r\n";
$message_body .= "Ориентировочная цена детали: $price_per_pcs\r\n";
$message_body .= "Ориентировочная цена тиража: $price_total\r\n\r\n";

$message_body .= "Технические требования и комментарий клиента:\r\n";
$message_body .= "--------------------------------------------------------\r\n";
$message_body .= (!empty($client_message) ? $client_message : "Комментарий отсутствует.") . "\r\n\r\n";

$message_body .= "========================================================\r\n";
$message_body .= "Письмо отправлено автоматически с сайта matrixplast.ru\r\n";

// Сборка тела письма с boundary
$body = "--$boundary\r\n";
$body .= "Content-Type: text/plain; charset=utf-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $message_body . "\r\n";

// Если прикреплен файл, добавляем его в тело письма
if ($file_attached && $file_data !== null) {
    $file_encoded = chunk_split(base64_encode($file_data));
    
    // Защита имени файла от неверной кодировки в почтовых клиентах
    $file_name_encoded = "=?utf-8?B?" . base64_encode($file_name) . "?=";
    
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: $file_type; name=\"$file_name_encoded\"\r\n";
    $body .= "Content-Disposition: attachment; filename=\"$file_name_encoded\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= $file_encoded . "\r\n";
}

$body .= "--$boundary--";

// Отправка письма через SMTP с авторизацией
try {
    send_mail_smtp($to, $subject, $body, $headers_arr, $from, $smtp_password);
    echo json_encode(['success' => true, 'request_id' => $request_id]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка отправки почты через SMTP: ' . $e->getMessage()]);
}

/**
 * Функция отправки почты через SMTP-сервер с SSL шифрованием и авторизацией.
 */
function send_mail_smtp($to, $subject, $body, $headers_arr, $from, $password, $smtp_host = 'ssl://p1036777.mail.ihc.ru', $smtp_port = 465) {
    $timeout = 15;
    
    // Открываем сокетное соединение к SMTP серверу
    $socket = @fsockopen($smtp_host, $smtp_port, $errno, $errstr, $timeout);
    if (!$socket) {
        throw new Exception("Не удалось подключиться к SMTP-серверу $smtp_host:$smtp_port. Ошибка: $errstr ($errno)");
    }
    
    // Вспомогательная функция чтения ответов
    $readResponse = function($socket, $expected_code) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $code = substr($response, 0, 3);
        if ($code !== (string)$expected_code) {
            throw new Exception("Код SMTP: $code, Ожидался: $expected_code. Ответ: " . trim($response));
        }
        return $response;
    };
    
    try {
        $readResponse($socket, 220);
        
        fwrite($socket, "EHLO " . mail_domain($from) . "\r\n");
        $readResponse($socket, 250);
        
        fwrite($socket, "AUTH LOGIN\r\n");
        $readResponse($socket, 334);
        
        fwrite($socket, base64_encode($from) . "\r\n");
        $readResponse($socket, 334);
        
        fwrite($socket, base64_encode($password) . "\r\n");
        $readResponse($socket, 235);
        
        fwrite($socket, "MAIL FROM: <$from>\r\n");
        $readResponse($socket, 250);
        
        fwrite($socket, "RCPT TO: <$to>\r\n");
        $readResponse($socket, 250);
        
        fwrite($socket, "DATA\r\n");
        $readResponse($socket, 354);
        
        // Формируем строковые заголовки
        $raw_headers = "";
        foreach ($headers_arr as $k => $v) {
            $raw_headers .= "$k: $v\r\n";
        }
        
        // Отправка заголовков и тела письма
        fwrite($socket, $raw_headers . "\r\n" . $body . "\r\n.\r\n");
        $readResponse($socket, 250);
        
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    } catch (Exception $e) {
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
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
