<?php
/**
 * Обработчик отправки технического задания на расчет стоимости литья.
 * Совместимость с PHP 7.2.34
 */

// Установка заголовка для ответа в формате JSON
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод запроса не поддерживается. Используйте POST.']);
    exit;
}

// Конфигурация получателя и отправителя
$to = 'jmaier@mail.ru'; // Отладочный email (заменится на MATRIXPLAST@yandex.ru после тестов)
$from = 'admin@matrixplast.ru';

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

// Генерация уникального ID заявки
$request_id = 'MP-' . mt_rand(100000, 999999);

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
$headers = "MIME-Version: 1.0\r\n";
$headers .= "From: МАТРИКС-ПЛАСТ <$from>\r\n";
$headers .= "Reply-To: $client_email\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

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

// Отправка письма
if (mail($to, $subject, $body, $headers)) {
    echo json_encode(['success' => true, 'request_id' => $request_id]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Не удалось отправить письмо через серверную почтовую службу.']);
}
