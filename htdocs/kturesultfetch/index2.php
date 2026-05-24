<?php
// index.php

// 1. CONFIGURATION
$COOKIE_FILE = tempnam(sys_get_temp_dir(), 'ktu_cookie');
$LOGIN_URL = "https://app.ktu.edu.in/login.htm";
$GRADE_URL = "https://app.ktu.edu.in/eu/res/semesterGradeCardListing.htm";

// 2. HELPER FUNCTIONS
function make_request($url, $method="GET", $data=[], $check_login=false) {
    global $COOKIE_FILE;
    $ch = curl_init();
    
    // cURL Options
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $COOKIE_FILE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Like verify=False in Python
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($method == "POST") {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }

    $response = curl_exec($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    // Check if session expired (redirected back to login)
    if ($check_login && (strpos($response, 'login.htm') !== false || strpos($response, 'Sign In') !== false)) {
        return null; 
    }
    return $response;
}

function get_csrf($html, $field_name="CSRF_TOKEN") {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    // Try finding by name or ID (KTU uses both)
    $nodes = $xpath->query("//input[@name='$field_name'] | //input[@id='$field_name']");
    if ($nodes->length > 0) {
        return $nodes->item(0)->getAttribute('value');
    }
    return null;
}

// 3. MAIN LOGIC
$result_html = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user = $_POST['username'];
    $pass = $_POST['password'];
    $sem  = $_POST['semester'];

    // STEP A: Init Login Page
    $page = make_request($LOGIN_URL);
    $csrf = get_csrf($page, "CSRF_TOKEN");

    if ($csrf) {
        // STEP B: Perform Login
        $login_data = [
            'username' => $user,
            'password' => $pass,
            'CSRF_TOKEN' => $csrf
        ];
        $post_login = make_request($LOGIN_URL, "POST", $login_data);

        if (strpos($post_login, "logout.htm") !== false) {
            // STEP C: Get Grade Page
            $grade_page = make_request($GRADE_URL, "GET", [], true);
            
            // KTU uses a specific ID for the search CSRF
            $search_csrf = get_csrf($grade_page, "semesterGradeCardListingSearchForm_CSRF_TOKEN");
            if (!$search_csrf) $search_csrf = $csrf; // Fallback

            // STEP D: Search Result
            $search_data = [
                'CSRF_TOKEN' => $search_csrf,
                'form_name' => 'semesterGradeCardListingSearchForm',
                'semesterId' => $sem,
                'stdId' => '',
                'search' => 'Search'
            ];
            $final_page = make_request($GRADE_URL, "POST", $search_data, true);

            // Extract Table
            if ($final_page) {
                $dom = new DOMDocument();
                @$dom->loadHTML($final_page);
                $xpath = new DOMXPath($dom);
                
                // Get Student Info
                $info_nodes = $xpath->query("//li[contains(@class, 'list-group-item')]");
                $student_info = [];
                foreach ($info_nodes as $node) {
                    $text = trim($node->textContent);
                    if (strpos($text, "Name") !== false && strpos($text, "College") === false) $student_info['Name'] = explode("|", $text)[1];
                    if (strpos($text, "Register Number") !== false) $student_info['RegNo'] = explode("|", $text)[1];
                }

                // Get Table
                $table = $xpath->query("//table[contains(@class, 'table-bordered')]");
                
                if ($table->length > 0) {
                    // Success! We found the table. Let's render it.
                    // We extract the raw HTML of the table to display it easily
                    $result_html = $dom->saveHTML($table->item(0));
                } else {
                    $error_msg = "No results found for Semester $sem.";
                }
            } else {
                $error_msg = "Session expired while fetching results.";
            }
        } else {
            $error_msg = "Login failed. Check username/password.";
        }
    } else {
        $error_msg = "Could not connect to KTU server.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>KTU Result Viewer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: sans-serif; background: #f4f4f4; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        input, select { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #28a745; color: white; border: none; font-size: 16px; cursor: pointer; }
        button:hover { background: #218838; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .print-btn { background: #007bff; margin-top: 20px; }
        .error { color: red; text-align: center; }
        @media print {
            .no-print { display: none; }
            body { background: white; }
            .container { box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="container">
    <h2 style="text-align:center">KTU Result Fetcher</h2>

    <?php if ($error_msg): ?>
        <p class="error"><?php echo $error_msg; ?></p>
    <?php endif; ?>

    <div class="no-print">
        <form method="POST">
            <label>Username (KTU ID):</label>
            <input type="text" name="username" required placeholder="CET24CS...">
            
            <label>Password:</label>
            <input type="password" name="password" required>
            
            <label>Semester:</label>
            <select name="semester">
                <?php for($i=1; $i<=8; $i++) echo "<option value='$i'>Semester $i</option>"; ?>
            </select>
            
            <button type="submit">Get Result</button>
        </form>
    </div>

    <?php if ($result_html): ?>
        <hr>
        <h3>Result for <?php echo @$student_info['Name']; ?> (<?php echo @$student_info['RegNo']; ?>)</h3>
        <?php echo $result_html; ?>
        
        <button class="print-btn no-print" onclick="window.print()">Save as PDF / Print</button>
    <?php endif; ?>

</div>

</body>
</html>