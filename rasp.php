<?php
/**
 * Train Schedule Parser for tutu.ru
 * Accepts st1=название первой станции&st2=название второй станции&st1_num=айди первой станции&st2_num=айди второй станции and date parameters
 * Parameters are passed to https://www.tutu.ru/prigorod/search.php which redirects to the schedule page
 */

// Check if we have either station names or station IDs
$hasStationNames = isset($_GET['st1']) && isset($_GET['st2']) && $_GET['st1'] && $_GET['st2'];
$hasStationIds = isset($_GET['st1_num']) && isset($_GET['st2_num']) && $_GET['st1_num'] && $_GET['st2_num'];

if (!$hasStationNames && !$hasStationIds) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters: st1 and st2, or st1_num and st2_num']);
    exit;
}

// Get parameters
$st1 = isset($_GET['st1']) ? $_GET['st1'] : '';
$st2 = isset($_GET['st2']) ? $_GET['st2'] : '';
$st1_num = isset($_GET['st1_num']) ? $_GET['st1_num'] : '';
$st2_num = isset($_GET['st2_num']) ? $_GET['st2_num'] : '';
$date = isset($_GET['date']) ? $_GET['date'] : '';

// Convert date format if needed
// Tutu.ru typically expects YYYY-MM-DD format
$formattedDate = '';
if ($date) {
    // Check if date is in DD.MM.YYYY format
    if (preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date)) {
        $dateParts = explode('.', $date);
        $formattedDate = $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0]; // YYYY-MM-DD
    } else {
        $formattedDate = $date; // Assume it's already in correct format
    }
}

// Build the search URL with ALL parameters as GET parameters
$searchParams = [];

// Add station names if available
if ($st1) {
    $searchParams['st1'] = $st1;
}
if ($st2) {
    $searchParams['st2'] = $st2;
}

// Add station IDs if available
if ($st1_num) {
    $searchParams['st1_num'] = $st1_num;
}
if ($st2_num) {
    $searchParams['st2_num'] = $st2_num;
}

// Add date if provided
if ($formattedDate) {
    $searchParams['date'] = $formattedDate;
}

// Add other common form fields that Tutu.ru might expect
$searchParams['form_type'] = 'prigorod';
$searchParams['form_version'] = '2.0';

// Build the complete URL with all parameters
$url = "https://www.tutu.ru/prigorod/search.php?" . http_build_query($searchParams);

// Fetch the page content (following redirects)
$htmlContent = fetchPageContent($url);

if ($htmlContent === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch schedule data']);
    exit;
}

// Parse the schedule
$scheduleData = parseTrainSchedule($htmlContent);

// Output as JSON
header('Content-Type: application/json; charset=utf-8');
echo json_encode($scheduleData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

/**
 * Fetch page content from URL
 * 
 * @param string $url
 * @return string|false
 */
function fetchPageContent($url) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_ENCODING => "" // Accept all encodings
    ]);
    
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return false;
    }
    
    return $content;
}

/**
 * Parse train schedule from HTML content
 * 
 * @param string $htmlContent
 * @return array
 */
function parseTrainSchedule($htmlContent) {
    // Create DOMDocument to parse HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Suppress HTML parsing warnings
    $dom->loadHTML($htmlContent);
    libxml_clear_errors();
    
    // Extract page title for route information
    $titleNodes = $dom->getElementsByTagName('title');
    $titleText = '';
    if ($titleNodes->length > 0) {
        $titleText = $titleNodes->item(0)->textContent;
    }
    
    // Extract stations from title as fallback
    $departureStation = "Unknown";
    $arrivalStation = "Unknown";
    if (preg_match('/Расписание электричек\s+(.+?)\s+-\s+(.+?)\s+с изменениями/u', $titleText, $matches)) {
        $departureStation = $matches[1];
        $arrivalStation = $matches[2];
    }
    
    // Initialize result structure
    $result = [
        'route' => [
            'departure_station' => $departureStation,
            'arrival_station' => $arrivalStation
        ],
        'schedule' => []
    ];
    
    // Try to find timetable container
    $timetable = null;
    $timetableDiv = $dom->getElementById('timetable');
    
    if ($timetableDiv) {
        $timetable = $timetableDiv;
    } else {
        // Try to find any table that might contain schedule data
        $tables = $dom->getElementsByTagName('table');
        foreach ($tables as $table) {
            $headers = $table->getElementsByTagName('th');
            $hasTimeHeader = false;
            
            foreach ($headers as $header) {
                $headerText = mb_strtolower($header->textContent);
                if (strpos($headerText, 'время') !== false || strpos($headerText, 'time') !== false) {
                    $hasTimeHeader = true;
                    break;
                }
            }
            
            if ($hasTimeHeader) {
                $timetable = $table;
                break;
            }
        }
    }
    
    // Parse schedule data from timetable
    if ($timetable) {
        // Get all rows
        $rows = $timetable->getElementsByTagName('tr');
        
        foreach ($rows as $row) {
            // Skip header rows
            if ($row->getElementsByTagName('th')->length > 0) {
                continue;
            }
            
            // Extract cells
            $cells = $row->childNodes;
            $cellElements = [];
            
            // Filter out text nodes and only keep element nodes
            foreach ($cells as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE) {
                    $cellElements[] = $cell;
                }
            }
            
            if (count($cellElements) >= 2) {
                // Initialize variables
                $departureTime = "";
                $arrivalTime = "";
                $trainType = "";
                $path = "";
                $trainDepartureStation = "";
                $trainArrivalStation = "";
                $scheduleLink = ""; // New variable to store the schedule link
                $npValue = ""; // New variable to store the np parameter value
                
                // Process each cell to extract information
                $cellTexts = [];
                foreach ($cellElements as $cell) {
                    $cellTexts[] = trim($cell->textContent);
                }
                
                // Look for route information in cells with class desktop__route__37GXG
                foreach ($cellElements as $cell) {
                    // Check if this cell contains route information
                    if ($cell->hasAttribute('class') && strpos($cell->getAttribute('class'), 'desktop__route__37GXG') !== false) {
                        // Extract departure and arrival stations from the route cell
                        $links = $cell->getElementsByTagName('a');
                        if ($links->length >= 2) {
                            $trainDepartureStation = trim($links->item(0)->textContent);
                            $trainArrivalStation = trim($links->item(1)->textContent);
                        }
                    }
                }
                
                // Look for schedule link - check all cells for links with the specific class
                foreach ($cellElements as $cell) {
                    // Get all links in the cell
                    $links = $cell->getElementsByTagName('a');
                    foreach ($links as $link) {
                        // Check if link has the class we're looking for
                        if ($link->hasAttribute('class') && 
                            (strpos($link->getAttribute('class'), 'desktop__depTimeLink__1NA_N') !== false ||
                             strpos($link->getAttribute('class'), 'depTimeLink') !== false)) {
                            $href = $link->getAttribute('href');
                            if (!empty($href)) {
                                $scheduleLink = $href;
                                // Extract np parameter value from the href
                                if (preg_match('/np=([^&]*)/', $href, $npMatches)) {
                                    $npValue = $npMatches[1];
                                }
                                break 2; // Break out of both loops
                            }
                        }
                    }
                }
                
                foreach ($cellTexts as $i => $cellText) {
                    // Look for time patterns in cells
                    if (preg_match('/\d{1,2}:\d{2}/', $cellText)) {
                        // Extract time part (first 5 characters should be time)
                        $timePart = substr($cellText, 0, 5);
                        
                        // Extract additional information after time
                        $extraInfo = trim(substr($cellText, 5));
                        
                        // Extract path information from parentheses
                        if (!$path && preg_match('/\(путь\s*(\d+)\)/u', $cellText, $pathMatch)) {
                            $path = $pathMatch[1];
                        }
                        
                        // Extract train type (text between time and path)
                        if ($extraInfo && !$trainType) {
                            if (preg_match('/^(.*?)(?:\(путь\s*\d+\)|$)/u', $extraInfo, $trainTypeMatch)) {
                                $trainType = trim($trainTypeMatch[1]);
                            }
                        }
                        
                        if (!$departureTime) {
                            $departureTime = $timePart;
                        } elseif (!$arrivalTime) {
                            $arrivalTime = $timePart;
                            break;
                        }
                    }
                }
                
                // If we couldn't find path in individual time extractions, try to find it in any cell
                if (!$path) {
                    $fullText = implode(" ", $cellTexts);
                    if (preg_match('/\(путь\s*(\d+)\)/u', $fullText, $pathMatch)) {
                        $path = $pathMatch[1];
                    }
                }
                
                // If we couldn't find train type in individual time extractions, try to find it in any cell
                if (!$trainType) {
                    $fullText = implode(" ", $cellTexts);
                    if (preg_match('/(\d{1,2}:\d{2})(.*?)(?:\(путь\s*\d+\)|$)/u', $fullText, $trainTypeMatch)) {
                        $trainType = trim($trainTypeMatch[2]);
                    }
                }
                
                // If we found time data, add to schedule
                if ($departureTime && $arrivalTime) {
                    $scheduleEntry = [
                        "departure_time" => $departureTime,
                        "arrival_time" => $arrivalTime
                    ];
                    
                    // Add train type and path if found
                    if ($trainType) {
                        $scheduleEntry["train_type"] = $trainType;
                    }
                    if ($path) {
                        $scheduleEntry["path"] = $path;
                    }
                    
                    // Add specific departure and arrival stations for this train if found
                    if ($trainDepartureStation && $trainArrivalStation) {
                        $scheduleEntry["train_departure_station"] = $trainDepartureStation;
                        $scheduleEntry["train_arrival_station"] = $trainArrivalStation;
                    }
                    
                    // Add np parameter value if found
                    if ($npValue) {
                        $scheduleEntry["np"] = $npValue;
                    }
                    
                    $result["schedule"][] = $scheduleEntry;
                }
            }
        }
    }
    
    // If we still haven't found schedule data, try another approach
    if (empty($result["schedule"])) {
        // Look for any text with time data
        $xpath = new DOMXPath($dom);
        $timeNodes = $xpath->query("//text()[contains(., ':')]");
        
        $times = [];
        foreach ($timeNodes as $node) {
            $text = $node->textContent;
            // Extract time, train type, and path from text
            if (preg_match_all('/(\d{1,2}:\d{2})([^(\n]*?)?(?:\(путь\s*(\d+)\)|$)/u', $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $timePart = $match[1];
                    $trainPart = isset($match[2]) ? trim($match[2]) : "";
                    $pathPart = isset($match[3]) ? $match[3] : "";
                    
                    $times[] = [
                        "time" => $timePart,
                        "train_type" => $trainPart,
                        "path" => $pathPart
                    ];
                }
            }
        }
        
        // Group times in pairs (departure, arrival)
        for ($i = 0; $i < count($times) - 1; $i += 2) {
            $entry = [
                "departure_time" => $times[$i]["time"],
                "arrival_time" => $times[$i + 1]["time"]
            ];
            
            // Add train type and path if available
            if (!empty($times[$i]["train_type"])) {
                $entry["train_type"] = $times[$i]["train_type"];
            }
            if (!empty($times[$i]["path"])) {
                $entry["path"] = $times[$i]["path"];
            } elseif (!empty($times[$i+1]["path"])) {
                $entry["path"] = $times[$i+1]["path"];
            }
            
            $result["schedule"][] = $entry;
        }
    }
    
    return $result;
}
?>