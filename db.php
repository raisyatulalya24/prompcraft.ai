<?php
/**
 * db.php
 * -----------------------------------------------------------------------
 * PromptCraft AI - Database Layer
 * Handles the MariaDB/MySQL connection (mysqli) and creates the full
 * schema (based on the project's ERD) automatically on first run.
 *
 * Tables (from ERD): users, categories, prompts, favorites, history,
 * templates, prompt_test, api_logs
 *
 * NOTE: A few columns were added beyond the original ERD purely to
 * support functional requirements from the brief (Settings page needs
 * a place to store a per-user Gemini key / theme / language, and photo
 * is stored as LONGTEXT instead of VARCHAR so a profile picture can be
 * saved as a base64 data URI without needing an extra uploads folder,
 * since the brief asks us to avoid creating additional folders).
 * -----------------------------------------------------------------------
 */

// ---------------------------------------------------------------------
// Connection settings - edit these to match your local MariaDB setup
// ---------------------------------------------------------------------
define('DB_HOST', 'sql211.infinityfree.com');
define('DB_USER', 'if0_42351038');
define('DB_PASS', 'Ycy5pBmdfn');
define('DB_NAME', 'if0_42351038_prompcraft_ai');

// Report mysqli errors as exceptions so we can catch them cleanly
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function getDB(): mysqli
{
    static $conn = null;

    if ($conn !== null) {
        return $conn;
    }

    try {
        // First connect without a database to make sure it exists
        $bootstrap = new mysqli(DB_HOST, DB_USER, DB_PASS);
        $bootstrap->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $bootstrap->close();

        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]));
    }

    installSchema($conn);

    return $conn;
}

function installSchema(mysqli $conn): void
{
    // users -----------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        user_id INT AUTO_INCREMENT PRIMARY KEY,
        fullname VARCHAR(150) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        photo LONGTEXT NULL,
        api_key VARCHAR(255) NULL,
        theme VARCHAR(20) NOT NULL DEFAULT 'dark',
        language VARCHAR(20) NOT NULL DEFAULT 'en',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // categories --------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS categories (
        category_id INT AUTO_INCREMENT PRIMARY KEY,
        category_name VARCHAR(100) NOT NULL,
        description TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // prompts -------------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS prompts (
        prompt_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category_id INT NULL,
        title VARCHAR(200) NOT NULL,
        original_prompt TEXT NULL,
        generated_prompt LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // favorites -----------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS favorites (
        favorite_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        prompt_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fav (user_id, prompt_id),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (prompt_id) REFERENCES prompts(prompt_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // history ---------------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS history (
        history_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        activity VARCHAR(150) NOT NULL,
        activity_detail TEXT NULL,
        activity_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // prompt_test -----------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS prompt_test (
        test_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        prompt_id INT NULL,
        ai_model VARCHAR(100) NOT NULL,
        ai_response LONGTEXT NULL,
        test_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (prompt_id) REFERENCES prompts(prompt_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // templates -----------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS templates (
        template_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        category_id INT NULL,
        template_name VARCHAR(150) NOT NULL,
        template_content LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // api_logs -----------------------------------------------------------
    $conn->query("CREATE TABLE IF NOT EXISTS api_logs (
        log_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        prompt_id INT NULL,
        ai_model VARCHAR(100) NOT NULL,
        token_used INT NOT NULL DEFAULT 0,
        status VARCHAR(50) NOT NULL,
        request_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
        FOREIGN KEY (prompt_id) REFERENCES prompts(prompt_id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Seed default categories only if the table is empty
    $result = $conn->query("SELECT COUNT(*) AS total FROM categories");
    $row = $result->fetch_assoc();
    if ((int)$row['total'] === 0) {
        $defaults = ['Content', 'Marketing', 'Programming', 'Education', 'Business', 'Travel', 'Health'];
        $stmt = $conn->prepare("INSERT INTO categories (category_name, description) VALUES (?, ?)");
        foreach ($defaults as $name) {
            $desc = "Prompts related to $name";
            $stmt->bind_param('ss', $name, $desc);
            $stmt->execute();
        }
        $stmt->close();
    }

    // Seed a couple of starter templates only if the table is empty
    $result = $conn->query("SELECT COUNT(*) AS total FROM templates");
    $row = $result->fetch_assoc();
    if ((int)$row['total'] === 0) {
        $starter = [
            ['Blog Outline Generator', 'Write a detailed blog outline about [TOPIC] targeting [AUDIENCE]. Include an engaging title, 5 subheadings, and a short conclusion.'],
            ['Code Reviewer', 'Review the following [LANGUAGE] code for bugs, readability, and performance. Suggest improvements with explanations:\n\n[CODE]'],
            ['Marketing Ad Copy', 'Create 3 short, persuasive ad copy variations for [PRODUCT] aimed at [TARGET_AUDIENCE] with a [TONE] tone.'],
        ];
        $stmt = $conn->prepare("INSERT INTO templates (user_id, category_id, template_name, template_content) VALUES (NULL, NULL, ?, ?)");
        foreach ($starter as $t) {
            $stmt->bind_param('ss', $t[0], $t[1]);
            $stmt->execute();
        }
        $stmt->close();
    }
}

/**
 * Helper: log a user activity into the history table
 */
function logHistory(mysqli $conn, int $userId, string $activity, string $detail = ''): void
{
    $stmt = $conn->prepare("INSERT INTO history (user_id, activity, activity_detail) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $userId, $activity, $detail);
    $stmt->execute();
    $stmt->close();
}

/**
 * Helper: send a JSON response and stop execution
 */
function jsonResponse(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}