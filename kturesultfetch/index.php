<?php
// index.php

// 1. DISABLE BUFFERING & EXTEND TIME
// This forces PHP to send output immediately to the browser
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);

// Increase timeout for the loop (Free hosts limit this to ~60s usually)
@set_time_limit(120); 

$COOKIE_FILE = tempnam(sys_get_temp_dir(), 'ktu_cookie');
$LOGIN_URL = "https://app.ktu.edu.in/login.htm";
$GRADE_URL = "https://app.ktu.edu.in/eu/res/semesterGradeCardListing.htm";

// --- TERMINAL LOGGING FUNCTION ---
function log_msg($step, $msg, $type="info") {
    $color = "#00ff00"; // Green (Default)
    if ($type == "error") $color = "#ff4444"; // Red
    if ($type == "warn") $color = "#ffbb33";  // Orange
    
    // We pad the string with 4096 spaces to force the browser/server to flush the buffer
    $padding = str_repeat(" ", 4096);
    
    echo "<div style='color: $color;'><strong>[$step]</strong> $msg</div>";
    echo "<script>window.scrollTo(0,document.body.scrollHeight);</script>";
    echo $padding; 
    flush();
    ob_flush();
}

// --- ROBUST REQUEST WITH RETRY LOOP ---
function robust_request($url, $method="GET", $data=[], $step_name="Request", $check_login=false) {
    global $COOKIE_FILE;
    
    $attempt = 1;
    $max_retries = 20; // High retry count for heavy traffic
    
    while ($attempt <= $max_retries) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $COOKIE_FILE);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $COOKIE_FILE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        if ($method == "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        log_msg($step_name, "Attempt $attempt: Connecting...", "info");
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        // 1. Connection Failed (DNS/Network)
        if ($response === false) {
            log_msg($step_name, "⚠️ Connection Error: $err. Retrying in 1s...", "warn");
            sleep(1);
            $attempt++;
            continue;
        }

        // 2. Server Errors (502, 503, 504)
        if (in_array($http_code, [500, 502, 503, 504])) {
            $wait = rand(1, 2);
            log_msg($step_name, "⚠️ Server Error ($http_code). Traffic High. Retrying in {$wait}s...", "warn");
            sleep($wait);
            $attempt++;
            continue;
        }

        // 3. Session Expired Check
        if ($check_login && (strpos($response, 'login.htm') !== false || strpos($response, 'Sign In') !== false)) {
            log_msg($step_name, "❌ Session Expired. Need to re-login.", "error");
            return "SESSION_EXPIRED";
        }

        // Success
        log_msg($step_name, "✅ Success!", "info");
        return $response;
    }
    
    log_msg($step_name, "❌ Max Retries Exceeded.", "error");
    return null;
}

function get_csrf($html, $field_name="CSRF_TOKEN") {
    if (!$html) return null;
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//input[@name='$field_name'] | //input[@id='$field_name']");
    if ($nodes->length > 0) return $nodes->item(0)->getAttribute('value');
    return null;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>KTU Result Bot</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { background: #121212; color: #00ff00; font-family: 'Courier New', monospace; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .input-box { background: #1e1e1e; padding: 20px; border: 1px solid #333; border-radius: 5px; margin-bottom: 20px; }
        input, select { background: #000; color: #fff; border: 1px solid #444; padding: 10px; width: 100%; margin: 5px 0 15px 0; box-sizing: border-box; }
        button { background: #008000; color: white; border: none; padding: 10px 20px; font-weight: bold; cursor: pointer; width: 100%; }
        button:hover { background: #006400; }
        .terminal { background: #000; border: 1px solid #333; padding: 15px; height: 400px; overflow-y: auto; font-size: 14px; line-height: 1.5; white-space: pre-wrap; }
        h2 { border-bottom: 1px solid #333; padding-bottom: 10px; }
        .result-table { width: 100%; border-collapse: collapse; margin-top: 20px; color: white; }
        .result-table th, .result-table td { border: 1px solid #444; padding: 8px; text-align: left; }
        .result-table th { background: #333; }
    </style>
</head>
<body>

<div class="container">
    <h2>🚀 KTU RESULT BOT (WEB TERMINAL)</h2>

    <?php if ($_SERVER["REQUEST_METHOD"] != "POST"): ?>
    <div class="input-box">
        <form method="POST">
            <label>Username:</label>
            <input type="text" name="username" required placeholder="CET24CS...">
            <label>Password:</label>
            <input type="password" name="password" required>
            <label>Semester:</label>
            <select name="semester">
                <?php for($i=1; $i<=8; $i++) echo "<option value='$i'>Semester $i</option>"; ?>
            </select>
            <button type="submit">START ATTACK</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($_SERVER["REQUEST_METHOD"] == "POST"): ?>
    <div class="terminal" id="terminal-window">
        <div>Initializing Sequence...</div>
        <?php
            $user = $_POST['username'];
            $pass = $_POST['password'];
            $sem  = $_POST['semester'];

            // STEP 1: LOGIN PAGE
            $page = robust_request($LOGIN_URL, "GET", [], "Login Init");
            
            if ($page) {
                $csrf = get_csrf($page, "CSRF_TOKEN");
                if ($csrf) {
                    log_msg("Token", "CSRF Token Captured: " . substr($csrf, 0, 10) . "...", "info");

                    // STEP 2: AUTHENTICATE
                    $login_data = ['username' => $user, 'password' => $pass, 'CSRF_TOKEN' => $csrf];
                    $post_login = robust_request($LOGIN_URL, "POST", $login_data, "Login Auth");

                    if ($post_login && strpos($post_login, "logout.htm") !== false) {
                        
                        // STEP 3: FETCH GRADE PAGE
                        $grade_page = robust_request($GRADE_URL, "GET", [], "Fetch Grade Page", true);
                        
                        if ($grade_page && $grade_page != "SESSION_EXPIRED") {
                            $search_csrf = get_csrf($grade_page, "semesterGradeCardListingSearchForm_CSRF_TOKEN");
                            if (!$search_csrf) $search_csrf = $csrf;

                            // STEP 4: SEARCH RESULT
                            $search_data = [
                                'CSRF_TOKEN' => $search_csrf,
                                'form_name' => 'semesterGradeCardListingSearchForm',
                                'semesterId' => $sem,
                                'stdId' => '',
                                'search' => 'Search'
                            ];
                            
                            log_msg("Search", "Requesting S$sem Results...", "info");
                            $final_page = robust_request($GRADE_URL, "POST", $search_data, "Fetch Result", true);

                            if ($final_page && $final_page != "SESSION_EXPIRED") {
                                // EXTRACT DATA
                                $dom = new DOMDocument();
                                @$dom->loadHTML($final_page);
                                $xpath = new DOMXPath($dom);
                                $table = $xpath->query("//table[contains(@class, 'table-bordered')]");

                                if ($table->length > 0) {
                                    log_msg("Success", "RESULT FOUND! Rendering...", "info");
                                    echo "</div>"; // Close terminal
                                    
                                    // Render Table neatly outside terminal
                                    echo "<h3>🎉 Result Captured</h3>";
                                    echo "<style>table.table{width:100%; border-collapse:collapse; color:white;} td,th{border:1px solid #555; padding:8px;} </style>";
                                    echo $dom->saveHTML($table->item(0));
                                    echo "<br><button onclick='window.print()'>Print Result</button>";
                                    echo "<br><br><a href='index.php' style='color:#00ff00'>Back to Home</a>";
                                    exit; // Stop script here
                                } else {
                                    log_msg("Result", "⚠️ Results not published yet for S$sem or no data found.", "warn");
                                }
                            }
                        }
                    } else {
                        log_msg("Login", "❌ Login Failed. Check Password.", "error");
                    }
                } else {
                    log_msg("Login", "❌ Could not find CSRF Token.", "error");
                }
            }
            echo "<br><strong>[Process Finished]</strong> <a href='index.php' style='color:white'>Try Again</a>";
        ?>
    </div>
    <?php endif; ?>
</div>

</body>
</html>