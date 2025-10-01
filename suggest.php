<?php
header('Content-Type: application/json; charset=utf-8');

// Get the 'name' parameter from the URL
$name = isset($_GET['name']) ? $_GET['name'] : '';

if (empty($name)) {
    echo json_encode(['error' => 'Missing name parameter']);
    exit;
}

// Construct the URL to fetch
$url = "https://www.tutu.ru/station/suggest.php?name=" . urlencode($name);

// Use cURL to fetch the page content
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json, text/javascript, */*; q=0.01',
    'X-Requested-With: XMLHttpRequest',
    'Referer: https://www.tutu.ru/'
]);

$content = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($content === false) {
    echo json_encode(['error' => 'Failed to fetch data from tutu.ru']);
    exit;
}

// Return the response as is
echo $content;
?>