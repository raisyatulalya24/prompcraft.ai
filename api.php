<?php
/**
 * api.php
 * -----------------------------------------------------------------------
 * PromptCraft AI - Backend API
 * Single entry point for every AJAX call made from script.js.
 * Uses mysqli prepared statements everywhere and returns JSON.
 * -----------------------------------------------------------------------
 */

session_start();
header('Content-Type: application/json');
require_once 'db.php';

// ---------------------------------------------------------------------
// Gemini API key - store your key here as instructed by the brief.
// A logged in user can also set a personal key on the Settings page,
// which takes priority over this default key when present.
// ---------------------------------------------------------------------
define('GROQ_API_KEY', ('GROQ_API_KEY', 'YOUR_GROQ_API_KEY'));
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
define('GROQ_MODEL', 'llama-3.3-70b-versatile');

$conn = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Actions that do NOT require an active session
$publicActions = ['register', 'login'];

if (!in_array($action, $publicActions, true) && empty($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
}

switch ($action) {

    // =====================================================================
    // AUTH
    // =====================================================================
    case 'register':
        $fullname = trim($_POST['fullname'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullname === '' || $email === '' || $password === '') {
            jsonResponse(['success' => false, 'message' => 'All fields are required.']);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.']);
        }
        if (strlen($password) < 6) {
            jsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters.']);
        }

        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            jsonResponse(['success' => false, 'message' => 'An account with this email already exists.']);
        }
        $stmt->close();

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, password) VALUES (?, ?, ?)");
        $stmt->bind_param('sss', $fullname, $email, $hash);
        $stmt->execute();
        $userId = $stmt->insert_id;
        $stmt->close();

        logHistory($conn, $userId, 'Register', 'Account created');
        jsonResponse(['success' => true, 'message' => 'Account created successfully. Please log in.']);
        break;

    case 'login':
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $conn->prepare("SELECT user_id, fullname, email, password, photo FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($password, $user['password'])) {
            jsonResponse(['success' => false, 'message' => 'Invalid email or password.']);
        }

        $_SESSION['user_id']  = $user['user_id'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['email']    = $user['email'];

        logHistory($conn, $user['user_id'], 'Login', 'User logged in');
        jsonResponse([
            'success' => true,
            'user' => [
                'fullname' => $user['fullname'],
                'email'    => $user['email'],
                'photo'    => $user['photo'],
            ],
        ]);
        break;

    case 'logout':
        if (!empty($_SESSION['user_id'])) {
            logHistory($conn, $_SESSION['user_id'], 'Logout', 'User logged out');
        }
        $_SESSION = [];
        session_destroy();
        jsonResponse(['success' => true]);
        break;

    // =====================================================================
    // DASHBOARD
    // =====================================================================
    case 'get_dashboard_stats':
        $uid = $_SESSION['user_id'];

        $totalPrompts = scalarQuery($conn, "SELECT COUNT(*) c FROM prompts WHERE user_id = ?", $uid);
        $totalFavs    = scalarQuery($conn, "SELECT COUNT(*) c FROM favorites WHERE user_id = ?", $uid);
        $totalTests   = scalarQuery($conn, "SELECT COUNT(*) c FROM prompt_test WHERE user_id = ?", $uid);
        $totalTemplates = scalarQuery($conn, "SELECT COUNT(*) c FROM templates WHERE user_id = ? OR user_id IS NULL", $uid);

        $stmt = $conn->prepare("SELECT prompt_id, title, generated_prompt, created_at FROM prompts WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $recentPrompts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $stmt = $conn->prepare("SELECT activity, activity_detail, activity_date FROM history WHERE user_id = ? ORDER BY activity_date DESC LIMIT 8");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $recentActivity = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        jsonResponse([
            'success' => true,
            'stats' => [
                'total_prompts'   => $totalPrompts,
                'total_favorites' => $totalFavs,
                'total_tests'     => $totalTests,
                'total_templates' => $totalTemplates,
            ],
            'recent_prompts'  => $recentPrompts,
            'recent_activity' => $recentActivity,
        ]);
        break;

    case 'get_analytics':
        $uid = $_SESSION['user_id'];

        // Prompts created per day for the last 7 days
        $stmt = $conn->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM prompts WHERE user_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY d ASC");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $byDay = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Prompts by category
        $stmt = $conn->prepare("SELECT c.category_name, COUNT(p.prompt_id) total FROM prompts p LEFT JOIN categories c ON p.category_id = c.category_id WHERE p.user_id = ? GROUP BY c.category_name");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $byCategory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        jsonResponse(['success' => true, 'by_day' => $byDay, 'by_category' => $byCategory]);
        break;

    // =====================================================================
    // CATEGORIES
    // =====================================================================
    case 'get_categories':
        $result = $conn->query("SELECT category_id, category_name, description FROM categories ORDER BY category_name ASC");
        jsonResponse(['success' => true, 'categories' => $result->fetch_all(MYSQLI_ASSOC)]);
        break;

    // =====================================================================
    // GEMINI AI ACTIONS
    // =====================================================================
    case 'generate_prompt':
        $uid = $_SESSION['user_id'];
        $topic     = trim($_POST['topic'] ?? '');
        $category  = $_POST['category'] ?? 'Content';
        $language  = $_POST['language'] ?? 'English';
        $style     = $_POST['style'] ?? 'Professional';
        $length    = $_POST['length'] ?? 'Medium';
        $categoryId = $_POST['category_id'] ?? null;

        if ($topic === '') {
            jsonResponse(['success' => false, 'message' => 'Please describe what you need a prompt for.']);
        }

        $instruction = "You are an expert prompt engineer. Create a highly effective AI prompt for the category '{$category}'. "
            . "The final prompt must be written in {$language}, use a {$style} writing style, and be {$length} in length. "
            . "The user's request/topic is: \"{$topic}\". "
            . "Only return the final crafted prompt text, ready to be used directly with an AI model. Do not add explanations before or after it.";

        [$ok, $text, $tokens] = callGrokAPI ($instruction, $uid);

        if (!$ok) {
            logApi($conn, $uid, null, $tokens, 'failed');
            jsonResponse(['success' => false, 'message' => $text]);
        }

        $stmt = $conn->prepare("INSERT INTO prompts (user_id, category_id, title, original_prompt, generated_prompt) VALUES (?, ?, ?, ?, ?)");
        $title = mb_substr($topic, 0, 60);
        $stmt->bind_param('iisss', $uid, $categoryId, $title, $topic, $text);
        $stmt->execute();
        $promptId = $stmt->insert_id;
        $stmt->close();

        logApi($conn, $uid, $promptId, $tokens, 'success');
        logHistory($conn, $uid, 'Generate Prompt', "Generated prompt: \"$title\"");

        jsonResponse(['success' => true, 'result' => $text, 'prompt_id' => $promptId]);
        break;

    case 'improve_prompt':
        $uid = $_SESSION['user_id'];
        $original = trim($_POST['original'] ?? '');

        if ($original === '') {
            jsonResponse(['success' => false, 'message' => 'Please enter a prompt to improve.']);
        }

        $instruction = "You are an expert prompt engineer. Rewrite and improve the following AI prompt so it is more specific, "
            . "clear, and effective, while preserving its original intent. Only return the improved prompt text, nothing else.\n\n"
            . "Original prompt:\n\"{$original}\"";

        [$ok, $text, $tokens] = callGrokAPI($instruction, $uid);

        if (!$ok) {
            logApi($conn, $uid, null, $tokens, 'failed');
            jsonResponse(['success' => false, 'message' => $text]);
        }

        logApi($conn, $uid, null, $tokens, 'success');
        logHistory($conn, $uid, 'Improve Prompt', 'Improved an existing prompt');

        jsonResponse(['success' => true, 'result' => $text]);
        break;

    case 'test_prompt':
        $uid = $_SESSION['user_id'];
        $prompt   = trim($_POST['prompt'] ?? '');
        $promptId = !empty($_POST['prompt_id']) ? (int)$_POST['prompt_id'] : null;

        if ($prompt === '') {
            jsonResponse(['success' => false, 'message' => 'Please enter a prompt to test.']);
        }

        [$ok, $text, $tokens] = callGrokAPI($prompt, $uid);

        if (!$ok) {
            logApi($conn, $uid, $promptId, $tokens, 'failed');
            jsonResponse(['success' => false, 'message' => $text]);
        }

        $stmt = $conn->prepare("INSERT INTO prompt_test (user_id, prompt_id, ai_model, ai_response) VALUES (?, ?, ?, ?)");
        $model = GROQ_MODEL;
        $stmt->bind_param('iiss', $uid, $promptId, $model, $text);
        $stmt->execute();
        $stmt->close();

        logApi($conn, $uid, $promptId, $tokens, 'success');
        logHistory($conn, $uid, 'Test Prompt', 'Tested a prompt against Grok');

        jsonResponse(['success' => true, 'result' => $text]);
        break;

    // =====================================================================
    // PROMPT LIBRARY (CRUD)
    // =====================================================================
    case 'save_prompt':
        $uid = $_SESSION['user_id'];
        $title      = trim($_POST['title'] ?? 'Untitled Prompt');
        $original   = trim($_POST['original'] ?? '');
        $generated  = trim($_POST['generated'] ?? '');
        $categoryId = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;

        $stmt = $conn->prepare("INSERT INTO prompts (user_id, category_id, title, original_prompt, generated_prompt) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iisss', $uid, $categoryId, $title, $original, $generated);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        logHistory($conn, $uid, 'Save Prompt', "Saved prompt: \"$title\"");
        jsonResponse(['success' => true, 'prompt_id' => $id]);
        break;

    case 'get_prompts':
        $uid = $_SESSION['user_id'];
        $search   = '%' . trim($_POST['search'] ?? '') . '%';
        $category = trim($_POST['category'] ?? '');

        $sql = "SELECT p.prompt_id, p.title, p.original_prompt, p.generated_prompt, p.created_at, c.category_name,
                       EXISTS(SELECT 1 FROM favorites f WHERE f.prompt_id = p.prompt_id AND f.user_id = p.user_id) AS is_favorite
                FROM prompts p
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE p.user_id = ? AND p.title LIKE ?";
        $types = 'is';
        $params = [$uid, $search];

        if ($category !== '' && $category !== 'All') {
            $sql .= " AND c.category_name = ?";
            $types .= 's';
            $params[] = $category;
        }
        $sql .= " ORDER BY p.created_at DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $prompts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        jsonResponse(['success' => true, 'prompts' => $prompts]);
        break;

    case 'update_prompt':
        $uid = $_SESSION['user_id'];
        $id    = (int)($_POST['prompt_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $generated = trim($_POST['generated'] ?? '');

        $stmt = $conn->prepare("UPDATE prompts SET title = ?, generated_prompt = ? WHERE prompt_id = ? AND user_id = ?");
        $stmt->bind_param('ssii', $title, $generated, $id, $uid);
        $stmt->execute();
        $stmt->close();

        logHistory($conn, $uid, 'Edit Prompt', "Edited prompt: \"$title\"");
        jsonResponse(['success' => true]);
        break;

    case 'delete_prompt':
        $uid = $_SESSION['user_id'];
        $id  = (int)($_POST['prompt_id'] ?? 0);

        $stmt = $conn->prepare("DELETE FROM prompts WHERE prompt_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $id, $uid);
        $stmt->execute();
        $stmt->close();

        logHistory($conn, $uid, 'Delete Prompt', "Deleted prompt #$id");
        jsonResponse(['success' => true]);
        break;

    // =====================================================================
    // FAVORITES
    // =====================================================================
    case 'toggle_favorite':
        $uid = $_SESSION['user_id'];
        $promptId = (int)($_POST['prompt_id'] ?? 0);

        $stmt = $conn->prepare("SELECT favorite_id FROM favorites WHERE user_id = ? AND prompt_id = ?");
        $stmt->bind_param('ii', $uid, $promptId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $stmt = $conn->prepare("DELETE FROM favorites WHERE favorite_id = ?");
            $stmt->bind_param('i', $existing['favorite_id']);
            $stmt->execute();
            $stmt->close();
            jsonResponse(['success' => true, 'favorited' => false]);
        } else {
            $stmt = $conn->prepare("INSERT INTO favorites (user_id, prompt_id) VALUES (?, ?)");
            $stmt->bind_param('ii', $uid, $promptId);
            $stmt->execute();
            $stmt->close();
            logHistory($conn, $uid, 'Favorite Prompt', "Marked prompt #$promptId as favorite");
            jsonResponse(['success' => true, 'favorited' => true]);
        }
        break;

    case 'get_favorites':
        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT p.prompt_id, p.title, p.generated_prompt, c.category_name, f.created_at
                                 FROM favorites f
                                 JOIN prompts p ON f.prompt_id = p.prompt_id
                                 LEFT JOIN categories c ON p.category_id = c.category_id
                                 WHERE f.user_id = ? ORDER BY f.created_at DESC");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $favorites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        jsonResponse(['success' => true, 'favorites' => $favorites]);
        break;

    // =====================================================================
    // HISTORY
    // =====================================================================
    case 'get_history':
        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT activity, activity_detail, activity_date FROM history WHERE user_id = ? ORDER BY activity_date DESC LIMIT 100");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        jsonResponse(['success' => true, 'history' => $history]);
        break;

    // =====================================================================
    // TEMPLATES
    // =====================================================================
    case 'get_templates':
        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT t.template_id, t.template_name, t.template_content, c.category_name
                                 FROM templates t
                                 LEFT JOIN categories c ON t.category_id = c.category_id
                                 WHERE t.user_id = ? OR t.user_id IS NULL
                                 ORDER BY t.created_at DESC");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        jsonResponse(['success' => true, 'templates' => $templates]);
        break;

    // =====================================================================
    // PROFILE
    // =====================================================================
    case 'get_profile':
        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT fullname, email, photo, api_key, theme, language FROM users WHERE user_id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        jsonResponse(['success' => true, 'user' => $user]);
        break;

    case 'update_profile':
        $uid = $_SESSION['user_id'];
        $fullname = trim($_POST['fullname'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $photo    = $_POST['photo'] ?? null; // base64 data URI or null (unchanged)

        if ($fullname === '' || $email === '') {
            jsonResponse(['success' => false, 'message' => 'Name and email are required.']);
        }

        if ($photo) {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, password = ?, photo = ? WHERE user_id = ?");
                $stmt->bind_param('ssssi', $fullname, $email, $hash, $photo, $uid);
            } else {
                $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, photo = ? WHERE user_id = ?");
                $stmt->bind_param('sssi', $fullname, $email, $photo, $uid);
            }
        } else {
            if ($password !== '') {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, password = ? WHERE user_id = ?");
                $stmt->bind_param('sssi', $fullname, $email, $hash, $uid);
            } else {
                $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ? WHERE user_id = ?");
                $stmt->bind_param('ssi', $fullname, $email, $uid);
            }
        }
        $stmt->execute();
        $stmt->close();

        $_SESSION['fullname'] = $fullname;
        $_SESSION['email'] = $email;

        logHistory($conn, $uid, 'Update Profile', 'Profile information updated');
        jsonResponse(['success' => true]);
        break;

    // =====================================================================
    // SETTINGS
    // =====================================================================
    case 'update_settings':
        $uid = $_SESSION['user_id'];
        $apiKey   = trim($_POST['api_key'] ?? '');
        $theme    = trim($_POST['theme'] ?? 'dark');
        $language = trim($_POST['language'] ?? 'en');

        $stmt = $conn->prepare("UPDATE users SET api_key = ?, theme = ?, language = ? WHERE user_id = ?");
        $stmt->bind_param('sssi', $apiKey, $theme, $language, $uid);
        $stmt->execute();
        $stmt->close();

        logHistory($conn, $uid, 'Update Settings', 'Settings updated');
        jsonResponse(['success' => true]);
        break;

    default:
        jsonResponse(['success' => false, 'message' => 'Unknown action.']);
}

// =========================================================================
// HELPER FUNCTIONS
// =========================================================================

/**
 * Runs a scalar (single value) COUNT-style query bound to a user id.
 */
function scalarQuery(mysqli $conn, string $sql, int $uid): int
{
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['c'];
}

/**
 * Writes an entry into api_logs.
 */
function logApi(mysqli $conn, int $uid, ?int $promptId, int $tokens, string $status): void
{
    $model = GROQ_MODEL;
    $stmt = $conn->prepare("INSERT INTO api_logs (user_id, prompt_id, ai_model, token_used, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('iisis', $uid, $promptId, $model, $tokens, $status);
    $stmt->execute();
    $stmt->close();
}

/**
 * Calls the Gemini API with the given instruction/prompt text.
 * Uses the logged-in user's personal API key if they saved one in
 * Settings, otherwise falls back to the GEMINI_API_KEY constant above.
 *
 * @return array [bool success, string textOrErrorMessage, int tokensUsed]
 */
function callGrokAPI(string $promptText, int $uid): array
{
    global $conn;

    $apiKey = GROQ_API_KEY;

    $stmt = $conn->prepare("SELECT api_key FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $uid);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($row['api_key'])) {
        $apiKey = $row['api_key'];
    }

    if (empty($apiKey)) {
        return [false, "No Grok API Key configured.", 0];
    }

    $payload = [
        "model" => GROQ_MODEL,
        "messages" => [
            [
                "role" => "user",
                "content" => $promptText
            ]
        ],
        "temperature" => 0.7,
        "max_tokens" => 2048
    ];

    $ch = curl_init(GROQ_API_URL);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        return [false, $curlError, 0];
    }

    $data = json_decode($response, true);

        if ($httpCode != 200) {
    die(
        "<pre>" .
        "HTTP Code: " . $httpCode . "\n\n" .
        $response .
        "</pre>"
    );
}

    $text = $data["choices"][0]["message"]["content"] ?? "";

    $tokens = $data["usage"]["total_tokens"] ?? 0;

    return [true, trim($text), $tokens];
}