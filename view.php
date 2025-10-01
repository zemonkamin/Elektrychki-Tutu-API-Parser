<?php
header('Content-Type: application/json; charset=utf-8');

// Get the 'np' parameter from the URL
$np = isset($_GET['np']) ? $_GET['np'] : '';

if (empty($np)) {
    echo json_encode(['error' => 'Missing np parameter']);
    exit;
}

// Construct the URL to fetch
$url = "https://www.tutu.ru/view.php?np=" . urlencode($np);

// Use cURL to fetch the page content
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
$content = curl_exec($ch);
curl_close($ch);

if ($content === false) {
    echo json_encode(['error' => 'Failed to fetch data from tutu.ru']);
    exit;
}

// Parse the HTML content
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($content);
libxml_clear_errors();

// Extract train information
$result = [
    'np' => $np,
    'train_number' => '',
    'train_name' => '',
    'route' => '',
    'date' => '',
    'carrier' => '',
    'movement_mode' => '',
    'stations' => []
];

// Extract train number, name and route from the h1 tag
$h1Elements = $dom->getElementsByTagName('h1');
foreach ($h1Elements as $h1) {
    // Look for span with class "comfort" or "ivolga" for train name
    $spanElements = $h1->getElementsByTagName('span');
    foreach ($spanElements as $span) {
        $class = $span->getAttribute('class');
        if ($class == 'comfort' || $class == 'ivolga') {
            $textContent = $span->textContent;
            // Remove quotes if present
            $textContent = trim(str_replace(['"', '"'], '', $textContent));
            $result['train_name'] = $textContent;
            break;
        }
    }
    
    // Look for <b> tag for route
    $bElements = $h1->getElementsByTagName('b');
    if ($bElements->length > 0) {
        $routeText = $bElements->item(0)->textContent;
        $result['route'] = trim($routeText);
    }
    
    // Extract train number - look for the text between the span and <b> tag
    if (empty($result['train_number'])) {
        $h1Text = $h1->textContent;
        // Pattern to match train number between train name and route
        if (preg_match('/электрички\s+"[^"]+"\s+([^\s]+\s*[А-ЯA-Z]?)/u', $h1Text, $matches)) {
            $result['train_number'] = trim($matches[1]);
        } else if (preg_match('/электрички\s+([^\s]+\s*[А-ЯA-Z]?)/u', $h1Text, $matches)) {
            $result['train_number'] = trim($matches[1]);
        }
    }
    
    break; // Only process the first h1
}

// If we still don't have train name, try another approach
if (empty($result['train_name'])) {
    foreach ($h1Elements as $h1) {
        $spanElements = $h1->getElementsByTagName('span');
        foreach ($spanElements as $span) {
            $class = $span->getAttribute('class');
            if ($class == 'comfort' || $class == 'ivolga') {
                $textContent = $span->textContent;
                // Remove quotes if present
                $textContent = trim(str_replace(['"', '"'], '', $textContent));
                $result['train_name'] = $textContent;
                break 2; // Break out of both loops
            }
        }
    }
}

// If we still don't have route, try another approach
if (empty($result['route'])) {
    foreach ($h1Elements as $h1) {
        $bElements = $h1->getElementsByTagName('b');
        if ($bElements->length > 0) {
            $routeText = $bElements->item(0)->textContent;
            $result['route'] = trim($routeText);
            break;
        }
    }
}

// Extract date
$dateElements = $dom->getElementsByTagName('div');
foreach ($dateElements as $element) {
    if ($element->getAttribute('class') == 'center_block date_block') {
        $result['date'] = trim($element->textContent);
        break;
    }
}

// Extract carrier
$carrierElements = $dom->getElementsByTagName('div');
foreach ($carrierElements as $element) {
    if ($element->getAttribute('class') == 'center_block movement_block' && strpos($element->textContent, 'Перевозчик') !== false) {
        $links = $element->getElementsByTagName('a');
        if ($links->length > 0) {
            $result['carrier'] = trim($links->item(0)->textContent);
        }
        break;
    }
}

// Extract movement mode
$movementElements = $dom->getElementsByTagName('div');
foreach ($movementElements as $element) {
    if ($element->getAttribute('class') == 'center_block movement_block' && strpos($element->textContent, 'Режим движения') !== false) {
        $result['movement_mode'] = trim(str_replace('Режим движения:', '', $element->textContent));
        break;
    }
}

// Extract station data from the route table
$table = $dom->getElementById('schedule_table');
if ($table) {
    // Get the tbody element
    $tbodyElements = $table->getElementsByTagName('tbody');
    $tbody = $tbodyElements->length > 0 ? $tbodyElements->item(0) : $table;
    
    $rows = $tbody->getElementsByTagName('tr');
    
    foreach ($rows as $row) {
        // Skip header rows
        if ($row->getElementsByTagName('th')->length > 0) {
            continue;
        }
        
        $cells = $row->getElementsByTagName('td');
        if ($cells->length >= 4) {  // Changed from 5 to 4 since departure time might be missing
            $stationElement = $cells->item(1)->getElementsByTagName('a');
            $station = $stationElement->length > 0 ? trim($stationElement->item(0)->textContent) : '';
            
            // Actual movement time
            $actualTime = '';
            $actualElement = $cells->item(2);
            if ($actualElement) {
                // Check if there's a span with "Нет данных"
                $spanElements = $actualElement->getElementsByTagName('span');
                if ($spanElements->length > 0 && strpos($spanElements->item(0)->textContent, 'Нет данных') !== false) {
                    $actualTime = 'Нет данных';
                } else {
                    // Get the text content, including any span elements
                    $actualTime = trim($actualElement->textContent);
                }
            }
            
            // Scheduled time (arrival/departure)
            $scheduledTime = '';
            $scheduledElement = $cells->item(3);
            if ($scheduledElement) {
                $scheduledTime = trim($scheduledElement->textContent);
            }
            
            // Skip rows with no station name
            if (!empty($station)) {
                $result['stations'][] = [
                    'station' => $station,
                    'actual_time' => $actualTime,
                    'scheduled_time' => $scheduledTime
                ];
            }
        }
    }
}

// If we still don't have train info, try alternative extraction methods
if (empty($result['train_number']) || empty($result['route'])) {
    // Try to get from breadcrumbs
    $breadcrumbsElements = $dom->getElementsByTagName('div');
    foreach ($breadcrumbsElements as $element) {
        if ($element->getAttribute('class') == 'breadcrumbs_top') {
            $breadcrumbText = $element->textContent;
            if (preg_match('/электрички?\s+([^\s]+\s*[А-ЯA-Z]?)/u', $breadcrumbText, $matches)) {
                if (empty($result['train_number'])) {
                    $result['train_number'] = trim($matches[1]);
                }
            }
            break;
        }
    }
    
    // Try to get route from breadcrumbs as well
    $breadcrumbsElements = $dom->getElementsByTagName('div');
    foreach ($breadcrumbsElements as $element) {
        if ($element->getAttribute('class') == 'breadcrumbs_top') {
            $breadcrumbText = $element->textContent;
            if (preg_match('/Расписание электричек ([^-]+) - ([^<\n]+)/u', $breadcrumbText, $matches)) {
                if (empty($result['route'])) {
                    $result['route'] = trim($matches[1]) . ' → ' . trim($matches[2]);
                }
            }
            break;
        }
    }
}

// Clean up the extracted data
$result['train_number'] = trim(preg_replace('/\s+/', ' ', $result['train_number']));
$result['train_name'] = trim(preg_replace('/\s+/', ' ', $result['train_name']));
$result['route'] = trim(preg_replace('/\s+/', ' ', $result['route']));

// Remove any "на сегодня" text from route
$result['route'] = preg_replace('/\s*на сегодня.*$/u', '', $result['route']);
$result['route'] = trim($result['route']);

// Remove any empty stations
$result['stations'] = array_filter($result['stations'], function($station) {
    return !empty($station['station']);
});

// Output the result as JSON
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>